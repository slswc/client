<?php
/**
 * DRM grace-period enforcer for SLSWC client plugins.
 *
 * Implements escalating admin notices and a full-page interstitial lock
 * after a configurable number of days without a valid license.
 * Front-end functionality is never affected.
 *
 * @since   1.2.0
 * @package SLSWC_Client
 * @link    https://licenseserver.io/
 */

namespace SLSWC\Client;

/**
 * Class DRM
 *
 * Grace-period state machine, escalating notices,
 * interstitial lock, and scheduled license-check callback.
 *
 * @since 1.2.0
 */
class DRM {

    /**
     * DRM configuration.
     *
     * @var array
     */
    private $config;

    /**
     * The parent Plugin/Theme updater instance.
     *
     * @var GenericSoftwareUpdater
     */
    private $updater;

    /**
     * Cached DRM state for the current request.
     *
     * @var string|null
     */
    private $drm_state = null;

    /**
     * Default configuration values.
     *
     * @var array
     */
    private static $defaults = array(
        'enabled'           => true,
        'identifier'        => '',
        'product_name'      => '',
        'parent_menu_slug'  => '',
        'page_hook_prefix'  => '',  // WordPress sanitized menu title used in load- hooks (e.g. 'license-server').
        'submenu_slugs'     => array(),
        'license_page_url'  => '',
        'purchase_url'      => '',
        'logo_url'          => '',
        'grace_soft_days'   => 2,
        'grace_lock_days'   => 7,
        'text_domain'       => 'slswcclient',
    );

    /**
     * Constructor.
     *
     * @since 1.2.0
     * @param array                 $config  DRM configuration.
     * @param GenericSoftwareUpdater $updater Parent updater instance.
     */
    public function __construct( array $config, GenericSoftwareUpdater $updater ) {
        $this->updater = $updater;
        $this->config  = wp_parse_args( $config, self::$defaults );

        // Auto-set identifier from updater if not provided.
        if ( empty( $this->config['identifier'] ) ) {
            $this->config['identifier'] = $updater->get_text_domain();
        }

        // Allow filtering the config.
        $this->config = apply_filters( 'slswc_drm_config_' . $this->config['identifier'], $this->config );
    }

    /**
     * Register all hooks.
     *
     * @since 1.2.0
     */
    public function run() {
        if ( ! $this->config['enabled'] || ! is_admin() ) {
            return;
        }

        $slug = $this->config['identifier'];

        // Admin notices.
        add_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );

        // Interstitial: intercept submenu page loads when locked.
        // WordPress uses the sanitized menu title (page_hook_prefix) for submenu hooks,
        // and 'toplevel_page_{slug}' for the parent page.
        $prefix = ! empty( $this->config['page_hook_prefix'] )
            ? $this->config['page_hook_prefix']
            : $this->config['parent_menu_slug'];
        $parent = $this->config['parent_menu_slug'];

        foreach ( $this->config['submenu_slugs'] as $sub_slug ) {
            add_action( 'load-' . $prefix . '_page_' . $sub_slug, array( $this, 'intercept_admin_page' ) );
        }
        // Also intercept the top-level parent page itself.
        add_action( 'load-toplevel_page_' . $parent, array( $this, 'intercept_admin_page' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_slswc_drm_dismiss_notice_' . $slug, array( $this, 'ajax_dismiss_notice' ) );
        add_action( 'wp_ajax_slswc_drm_refresh_status_' . $slug, array( $this, 'ajax_refresh_license_status' ) );

        // Scheduled license check (wp-cron).
        add_action( 'slswc_drm_license_check_' . $slug, array( $this, 'run_license_check' ) );
        $this->maybe_schedule_license_check();
    }

    // -------------------------------------------------------------------------
    // State Machine
    // -------------------------------------------------------------------------

    /**
     * Whether the license is currently activated.
     *
     * @since  1.2.0
     * @return bool
     */
    public function is_license_activated() {
        $value = $this->get_drm_option( $this->option_key( 'license_activated' ), 'no' );
        return in_array( $value, array( 'yes', '1', true, 1 ), true ) || 'yes' === strtolower( (string) $value );
    }

    /**
     * Number of whole days since the last successful license-server response.
     *
     * Initialises the timestamp on first call so the grace clock starts
     * from when DRM is first active, not from epoch zero.
     *
     * @since  1.2.0
     * @return int
     */
    public function get_days_without_license() {
        $last_check = $this->get_drm_option( $this->option_key( 'last_license_check' ) );

        if ( ! $last_check ) {
            $this->update_drm_option( $this->option_key( 'last_license_check' ), time() );
            return 0;
        }

        return (int) floor( ( time() - (int) $last_check ) / DAY_IN_SECONDS );
    }

    /**
     * Current DRM state for this site.
     *
     * Cached for the lifetime of the request.
     *
     * @since  1.2.0
     * @return string 'ok' | 'grace_soft' | 'grace_hard' | 'locked'
     */
    public function get_drm_state() {
        if ( null !== $this->drm_state ) {
            return $this->drm_state;
        }

        if ( $this->is_license_activated() ) {
            $this->drm_state = 'ok';
            return $this->drm_state;
        }

        $days = $this->get_days_without_license();

        if ( $days >= $this->config['grace_lock_days'] ) {
            $this->drm_state = 'locked';
        } elseif ( $days >= $this->config['grace_soft_days'] ) {
            $this->drm_state = 'grace_hard';
        } else {
            $this->drm_state = 'grace_soft';
        }

        $this->drm_state = apply_filters( 'slswc_drm_state_' . $this->config['identifier'], $this->drm_state, $this );

        return $this->drm_state;
    }

    // -------------------------------------------------------------------------
    // Admin Notices
    // -------------------------------------------------------------------------

    /**
     * Output the appropriate admin notice for the current DRM state.
     *
     * Notice logic:
     * - 'active' status  → no notice (license is valid and working)
     * - 'expiring'       → pre-expiry warning (license works but will expire soon)
     * - 'expired'        → expired notice (license no longer valid)
     * - 'disabled'       → disabled notice (license was revoked)
     * - anything else    → no-license notice (grace period countdown)
     *
     * @since 1.2.0
     */
    public function maybe_show_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $slug           = $this->config['identifier'];
        $license_status = $this->get_license_status();
        $state          = $this->get_drm_state();
        $nonce          = wp_create_nonce( 'slswc_drm_' . $slug );

        $notice_html = '';

        // Active license — no DRM notices needed.
        if ( 'active' === $license_status && 'ok' === $state ) {
            return;
        }

        // Status-specific notices (shown regardless of grace period state).
        if ( 'expiring' === $license_status ) {
            if ( $this->should_show_notice( 'show_pre_expiry_notice' ) ) {
                $notice_html = $this->render_notice_pre_expiry( $nonce );
            }
        } elseif ( 'expired' === $license_status ) {
            if ( $this->should_show_notice( 'show_expired_notice' ) ) {
                $notice_html = $this->render_notice_expired( $nonce );
            }
        } elseif ( 'disabled' === $license_status ) {
            if ( $this->should_show_notice( 'show_disabled_notice' ) ) {
                $notice_html = $this->render_notice_disabled( $nonce );
            }
        } elseif ( 'ok' !== $state && $this->should_show_notice( 'show_no_license_notice' ) ) {
            // No license / inactive / invalid — show grace period notice.
            $notice_html = $this->render_notice_no_license( $state, $nonce );
        }

        $notice_html = apply_filters( 'slswc_drm_notice_html_' . $slug, $notice_html, $state, $license_status, $this );

        if ( ! empty( $notice_html ) ) {
            echo $notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML built with proper escaping below.
            $this->output_dismiss_script();
        }
    }

    /**
     * Whether a notice should currently be visible.
     *
     * @since  1.2.0
     * @param  string $flag_key Option key suffix.
     * @return bool
     */
    private function should_show_notice( $flag_key ) {
        $show_until = (int) get_option( $this->option_key( $flag_key ), 0 );
        return time() > $show_until;
    }

    // -------------------------------------------------------------------------
    // Interstitial Lock
    // -------------------------------------------------------------------------

    /**
     * Intercept admin page loads when locked.
     *
     * @since 1.2.0
     */
    public function intercept_admin_page() {
        if ( 'locked' !== $this->get_drm_state() ) {
            return;
        }

        $should_lock = apply_filters( 'slswc_drm_should_lock_page_' . $this->config['identifier'], true, $this );
        if ( ! $should_lock ) {
            return;
        }

        // Remove our own notice so it doesn't double-up on the interstitial.
        remove_action( 'admin_notices', array( $this, 'maybe_show_notices' ) );

        require_once ABSPATH . 'wp-admin/admin-header.php';

        $html = $this->render_interstitial();
        $html = apply_filters( 'slswc_drm_interstitial_html_' . $this->config['identifier'], $html, $this );

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        require_once ABSPATH . 'wp-admin/admin-footer.php';
        exit;
    }

    // -------------------------------------------------------------------------
    // Scheduled License Check
    // -------------------------------------------------------------------------

    /**
     * Schedule the recurring license check via wp-cron if not already scheduled.
     *
     * @since 1.2.0
     */
    private function maybe_schedule_license_check() {
        $hook = 'slswc_drm_license_check_' . $this->config['identifier'];

        // Initialise the last-check timestamp if this is a fresh install.
        $last_check = $this->get_drm_option( $this->option_key( 'last_license_check' ) );
        if ( ! $last_check ) {
            $this->update_drm_option( $this->option_key( 'last_license_check' ), time() );
        }

        if ( ! wp_next_scheduled( $hook ) ) {
            wp_schedule_event( time() + wp_rand( 0, DAY_IN_SECONDS ), 'daily', $hook );
        }
    }

    /**
     * Re-validate the license against the server.
     *
     * Runs as a wp-cron job — there may be no current user in this context.
     *
     * @since 1.2.0
     */
    public function run_license_check() {
        $license_details = $this->updater->get_license_details();

        if ( empty( $license_details['license_key'] ) ) {
            $this->update_license_activated( false );
            return;
        }

        $response = $this->updater->client->request( 'check_license', $license_details );

        // On network error: keep current state, do not advance the clock.
        if ( is_null( $response ) || is_wp_error( $response ) ) {
            return;
        }

        $status = '';
        if ( is_object( $response ) && isset( $response->status ) ) {
            $status = $response->status;
        } elseif ( is_array( $response ) && isset( $response['status'] ) ) {
            $status = $response['status'];
        }

        if ( empty( $status ) ) {
            return;
        }

        // Update the license details object.
        $this->updater->license->set_license_status( $status );
        if ( isset( $response->expires ) ) {
            $this->updater->license->set_license_expires( $response->expires );
        }
        $this->updater->license->save();

        $this->update_license_activated( 'active' === $status );
    }

    /**
     * Persist the activated flag and advance the last-check timestamp.
     *
     * @since 1.2.0
     * @param bool $is_active Whether the license is currently active.
     */
    public function update_license_activated( $is_active ) {
        $this->update_drm_option( $this->option_key( 'license_activated' ), $is_active ? 'yes' : 'no' );

        if ( $is_active ) {
            // Advance the last-check clock.
            $this->update_drm_option( $this->option_key( 'last_license_check' ), time() );

            // Clear all notice dismiss timestamps so they don't linger.
            delete_option( $this->option_key( 'show_no_license_notice' ) );
            delete_option( $this->option_key( 'show_expired_notice' ) );
            delete_option( $this->option_key( 'show_disabled_notice' ) );
            delete_option( $this->option_key( 'show_pre_expiry_notice' ) );
        }

        // Reset the cached state so subsequent calls reflect the change.
        $this->drm_state = null;
    }

    // -------------------------------------------------------------------------
    // AJAX Handlers
    // -------------------------------------------------------------------------

    /**
     * Dismiss a single DRM notice for 24 hours.
     *
     * @since 1.2.0
     */
    public function ajax_dismiss_notice() {
        check_ajax_referer( 'slswc_drm_' . $this->config['identifier'], 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
        }

        $notice_flag = isset( $_POST['notice_flag'] ) ? sanitize_key( wp_unslash( $_POST['notice_flag'] ) ) : '';

        $valid_flags = array(
            $this->option_key( 'show_no_license_notice' ),
            $this->option_key( 'show_pre_expiry_notice' ),
            $this->option_key( 'show_expired_notice' ),
            $this->option_key( 'show_disabled_notice' ),
        );

        if ( ! in_array( $notice_flag, $valid_flags, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid notice flag.' ) );
        }

        update_option( $notice_flag, time() + DAY_IN_SECONDS, false );

        wp_send_json_success();
    }

    /**
     * Re-run a live license check and return the new DRM state.
     *
     * @since 1.2.0
     */
    public function ajax_refresh_license_status() {
        check_ajax_referer( 'slswc_drm_' . $this->config['identifier'], 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
        }

        $this->run_license_check();

        // Reset state cache.
        $this->drm_state = null;

        wp_send_json_success( array( 'state' => $this->get_drm_state() ) );
    }

    // -------------------------------------------------------------------------
    // Rendering: Notices
    // -------------------------------------------------------------------------

    /**
     * Render shared notice styles (output once per page load).
     *
     * @since  1.2.0
     * @return string
     */
    private function render_notice_styles() {
        static $output = false;
        if ( $output ) {
            return '';
        }
        $output = true;

        return '<style>
.slswc-drm-notice.notice {
    padding: 0 !important;
    border-left: none !important;
    position: relative !important;
}
.slswc-drm-notice__inner {
    display: flex;
    align-items: flex-start;
    gap: 13px;
    padding: 14px 48px 14px 16px;
    border-left: 4px solid var(--slswc-n-color, #2271b1);
}
.slswc-drm-notice__icon {
    flex-shrink: 0;
    margin-top: 1px;
    color: var(--slswc-n-color, #2271b1);
    opacity: 0.85;
}
.slswc-drm-notice__content { flex: 1; min-width: 0; }
.slswc-drm-notice__alert { color: var(--slswc-n-color, #2271b1); }
.slswc-drm-notice__title {
    font-size: 13.5px;
    font-weight: 700;
    color: #1d2327;
    margin: 0 0 3px !important;
    padding: 0 !important;
    line-height: 1.4;
}
.slswc-drm-notice__desc {
    font-size: 13px;
    color: #50575e;
    margin: 0 0 10px !important;
    padding: 0 !important;
    line-height: 1.5;
}
.slswc-drm-notice__actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px 14px;
}
</style>';
    }

    /**
     * Render the "no license" notice.
     *
     * @since  1.2.0
     * @param  string $state Current DRM state.
     * @param  string $nonce Nonce value.
     * @return string
     */
    private function render_notice_no_license( $state, $nonce ) {
        $flag         = $this->option_key( 'show_no_license_notice' );
        $notice_class = ( 'grace_soft' === $state ) ? 'notice-info' : 'notice-error';
        $accent_color = ( 'grace_soft' === $state ) ? '#1d4ed8' : '#b91c1c';
        $product_name = esc_html( $this->config['product_name'] );
        $license_url  = esc_url( $this->config['license_page_url'] );
        $purchase_url = esc_url( $this->config['purchase_url'] );

        $icon = ( 'grace_soft' === $state )
            ? '<svg class="slswc-drm-notice__icon" width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><circle cx="9" cy="9" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M9 8v5M9 5.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>'
            : '<svg class="slswc-drm-notice__icon" width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><rect x="3" y="8" width="12" height="9" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M6 8V6a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="9" cy="12.5" r="1.25" fill="currentColor" opacity="0.7"/></svg>';

        $title = ( 'grace_soft' === $state )
            /* translators: %s: product name */
            ? sprintf( esc_html__( 'Did you forget to enter your %s license key?', 'slswcclient' ), $product_name )
            /* translators: %s: product name */
            : '<span class="slswc-drm-notice__alert">' . esc_html__( 'Action required!', 'slswcclient' ) . '</span> ' . sprintf( esc_html__( 'Enter your %s license key to continue.', 'slswcclient' ), $product_name );

        $desc = '';
        if ( 'grace_soft' !== $state ) {
            $desc = '<p class="slswc-drm-notice__desc">'
                . esc_html__( "Don't worry, your data is completely safe and premium features are still working. But you will need to enter a license key to continue using this product.", 'slswcclient' )
                . '</p>';
        }

        return $this->render_notice_styles() . '
<div class="slswc-drm-notice notice ' . esc_attr( $notice_class ) . '"
    style="--slswc-n-color: ' . esc_attr( $accent_color ) . '"
    data-notice-flag="' . esc_attr( $flag ) . '">
    <div class="slswc-drm-notice__inner">
        ' . $icon . '
        <div class="slswc-drm-notice__content">
            <p class="slswc-drm-notice__title">' . $title . '</p>
            ' . $desc . '
            <div class="slswc-drm-notice__actions">
                <a href="' . $license_url . '" class="button button-primary">' . esc_html__( 'Enter License Key', 'slswcclient' ) . '</a>
                <a href="' . $purchase_url . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Purchase a License', 'slswcclient' ) . '</a>
            </div>
        </div>
    </div>
    <button type="button" class="notice-dismiss slswc-drm-dismiss"
        data-notice-flag="' . esc_attr( $flag ) . '"
        data-nonce="' . esc_attr( $nonce ) . '"
        data-identifier="' . esc_attr( $this->config['identifier'] ) . '">
        <span class="screen-reader-text">' . esc_html__( 'Dismiss this notice for 24 hours.', 'slswcclient' ) . '</span>
    </button>
</div>';
    }

    /**
     * Render the "license expired" notice.
     *
     * @since  1.2.0
     * @param  string $nonce Nonce value.
     * @return string
     */
    private function render_notice_expired( $nonce ) {
        $flag         = $this->option_key( 'show_expired_notice' );
        $product_name = esc_html( $this->config['product_name'] );
        $license_url  = esc_url( $this->config['license_page_url'] );
        $purchase_url = esc_url( $this->config['purchase_url'] );

        return $this->render_notice_styles() . '
<div class="slswc-drm-notice notice notice-error" style="--slswc-n-color: #b91c1c" data-notice-flag="' . esc_attr( $flag ) . '">
    <div class="slswc-drm-notice__inner">
        <svg class="slswc-drm-notice__icon" width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><rect x="3" y="8" width="12" height="9" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M6 8V6a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="9" cy="12.5" r="1.25" fill="currentColor" opacity="0.7"/></svg>
        <div class="slswc-drm-notice__content">
            <p class="slswc-drm-notice__title">' . sprintf( esc_html__( 'Your %s license has expired.', 'slswcclient' ), $product_name ) . '</p>
            <p class="slswc-drm-notice__desc">' . esc_html__( 'Your data is safe and premium features continue to work. Renew your license to keep receiving automatic updates and priority support.', 'slswcclient' ) . '</p>
            <div class="slswc-drm-notice__actions">
                <a href="' . $license_url . '" class="button button-primary">' . esc_html__( 'Enter License Now', 'slswcclient' ) . '</a>
                <a href="' . $purchase_url . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Renew License', 'slswcclient' ) . '</a>
            </div>
        </div>
    </div>
    <button type="button" class="notice-dismiss slswc-drm-dismiss"
        data-notice-flag="' . esc_attr( $flag ) . '"
        data-nonce="' . esc_attr( $nonce ) . '"
        data-identifier="' . esc_attr( $this->config['identifier'] ) . '">
        <span class="screen-reader-text">' . esc_html__( 'Dismiss this notice for 24 hours.', 'slswcclient' ) . '</span>
    </button>
</div>';
    }

    /**
     * Render the "license disabled" notice.
     *
     * @since  1.2.0
     * @param  string $nonce Nonce value.
     * @return string
     */
    private function render_notice_disabled( $nonce ) {
        $flag         = $this->option_key( 'show_disabled_notice' );
        $product_name = esc_html( $this->config['product_name'] );
        $license_url  = esc_url( $this->config['license_page_url'] );
        $purchase_url = esc_url( $this->config['purchase_url'] );

        return $this->render_notice_styles() . '
<div class="slswc-drm-notice notice notice-error" style="--slswc-n-color: #b91c1c" data-notice-flag="' . esc_attr( $flag ) . '">
    <div class="slswc-drm-notice__inner">
        <svg class="slswc-drm-notice__icon" width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><rect x="3" y="8" width="12" height="9" rx="2" stroke="currentColor" stroke-width="1.5"/><path d="M6 8V6a3 3 0 0 1 6 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="9" cy="12.5" r="1.25" fill="currentColor" opacity="0.7"/></svg>
        <div class="slswc-drm-notice__content">
            <p class="slswc-drm-notice__title">' . sprintf( esc_html__( 'Your %s license has been disabled.', 'slswcclient' ), $product_name ) . '</p>
            <p class="slswc-drm-notice__desc">' . esc_html__( 'Please contact support or purchase a new license to re-enable updates and support.', 'slswcclient' ) . '</p>
            <div class="slswc-drm-notice__actions">
                <a href="' . $license_url . '" class="button button-primary">' . esc_html__( 'Enter License Now', 'slswcclient' ) . '</a>
                <a href="' . $purchase_url . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Purchase a License', 'slswcclient' ) . '</a>
            </div>
        </div>
    </div>
    <button type="button" class="notice-dismiss slswc-drm-dismiss"
        data-notice-flag="' . esc_attr( $flag ) . '"
        data-nonce="' . esc_attr( $nonce ) . '"
        data-identifier="' . esc_attr( $this->config['identifier'] ) . '">
        <span class="screen-reader-text">' . esc_html__( 'Dismiss this notice for 24 hours.', 'slswcclient' ) . '</span>
    </button>
</div>';
    }

    /**
     * Render the "license expiring soon" notice.
     *
     * @since  1.2.0
     * @param  string $nonce Nonce value.
     * @return string
     */
    private function render_notice_pre_expiry( $nonce ) {
        $flag         = $this->option_key( 'show_pre_expiry_notice' );
        $product_name = esc_html( $this->config['product_name'] );
        $license_url  = esc_url( $this->config['license_page_url'] );
        $purchase_url = esc_url( $this->config['purchase_url'] );

        return $this->render_notice_styles() . '
<div class="slswc-drm-notice notice notice-warning" style="--slswc-n-color: #92400e" data-notice-flag="' . esc_attr( $flag ) . '">
    <div class="slswc-drm-notice__inner">
        <svg class="slswc-drm-notice__icon" width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><circle cx="9" cy="9" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M9 5v4l2.5 2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <div class="slswc-drm-notice__content">
            <p class="slswc-drm-notice__title">' . sprintf( esc_html__( 'Your %s license is expiring soon.', 'slswcclient' ), $product_name ) . '</p>
            <p class="slswc-drm-notice__desc">' . esc_html__( 'Renew your license to continue receiving automatic updates and priority support.', 'slswcclient' ) . '</p>
            <div class="slswc-drm-notice__actions">
                <a href="' . $license_url . '" class="button button-primary">' . esc_html__( 'View License', 'slswcclient' ) . '</a>
                <a href="' . $purchase_url . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Renew License', 'slswcclient' ) . '</a>
            </div>
        </div>
    </div>
    <button type="button" class="notice-dismiss slswc-drm-dismiss"
        data-notice-flag="' . esc_attr( $flag ) . '"
        data-nonce="' . esc_attr( $nonce ) . '"
        data-identifier="' . esc_attr( $this->config['identifier'] ) . '">
        <span class="screen-reader-text">' . esc_html__( 'Dismiss this notice for 24 hours.', 'slswcclient' ) . '</span>
    </button>
</div>';
    }

    /**
     * Output the shared dismiss script for all DRM notices.
     *
     * @since 1.2.0
     */
    private function output_dismiss_script() {
        static $output = false;
        if ( $output ) {
            return;
        }
        $output = true;
        ?>
        <script>
        jQuery(function($){
            $(document).on('click', '.slswc-drm-dismiss', function(){
                var btn = $(this);
                $.post(ajaxurl, {
                    action: 'slswc_drm_dismiss_notice_' + btn.data('identifier'),
                    nonce: btn.data('nonce'),
                    notice_flag: btn.data('notice-flag')
                });
                btn.closest('.slswc-drm-notice').fadeOut(200);
            });
        });
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Rendering: Interstitial
    // -------------------------------------------------------------------------

    /**
     * Render the full-page interstitial lock.
     *
     * @since  1.2.0
     * @return string
     */
    private function render_interstitial() {
        $slug           = $this->config['identifier'];
        $product_name   = esc_html( $this->config['product_name'] );
        $license_status = $this->get_license_status();
        $license_url    = esc_url( $this->config['license_page_url'] );
        $purchase_url   = esc_url( $this->config['purchase_url'] );
        $logo_url       = esc_url( $this->config['logo_url'] );
        $nonce          = esc_attr( wp_create_nonce( 'slswc_drm_' . $slug ) );

        $status_labels = array(
            'expired'                        => __( 'expired', 'slswcclient' ),
            'disabled'                       => __( 'disabled', 'slswcclient' ),
            'inactive'                       => __( 'inactive', 'slswcclient' ),
            'invalid'                        => __( 'invalid', 'slswcclient' ),
            'max_activations'                => __( 'at its activation limit', 'slswcclient' ),
            'max_staging_activations_reached' => __( 'at its staging limit', 'slswcclient' ),
        );

        $status_label = isset( $status_labels[ $license_status ] )
            ? esc_html( $status_labels[ $license_status ] )
            : esc_html__( 'missing', 'slswcclient' );

        $logo_html = '';
        if ( ! empty( $this->config['logo_url'] ) ) {
            $logo_html = '<img src="' . $logo_url . '" alt="' . $product_name . '" class="slswc-drm-card__logo">';
        }

        return '<style>
.slswc-drm-card {
    margin: 48px auto;
    max-width: 640px;
    width: calc(100% - 40px);
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.07), 0 12px 40px rgba(0,0,0,.10);
}
.slswc-drm-card__inner {
    padding: 44px 56px 40px;
    text-align: center;
}
.slswc-drm-card__logo {
    display: block;
    height: 48px;
    width: auto;
    margin: 0 auto 28px;
}
.slswc-drm-card__heading {
    font-size: 22px;
    font-weight: 700;
    color: #1d2327;
    margin: 0 0 14px;
    line-height: 1.35;
}
.slswc-drm-card__heading-urgent { color: #dc2626; }
.slswc-drm-card__body {
    font-size: 14px;
    color: #50575e;
    line-height: 1.7;
    margin: 0 0 32px;
    max-width: 480px;
    margin-left: auto;
    margin-right: auto;
}
.slswc-drm-card__illustration {
    margin: 0 auto 36px;
    display: block;
}
.slswc-drm-card__cta {
    display: block;
    width: 100%;
    box-sizing: border-box;
    text-align: center;
    padding: 13px 24px;
    font-size: 15px;
    font-weight: 600;
    color: #fff !important;
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    border: none;
    border-radius: 8px;
    text-decoration: none !important;
    cursor: pointer;
    transition: opacity .15s, box-shadow .15s;
    box-shadow: 0 2px 8px rgba(37,99,235,.35);
    margin-bottom: 20px;
}
.slswc-drm-card__cta:hover {
    opacity: .92;
    box-shadow: 0 4px 16px rgba(37,99,235,.45);
}
.slswc-drm-card__purchase {
    display: block;
    color: #2271b1;
    font-size: 13px;
    text-decoration: underline;
    margin-bottom: 2px;
}
.slswc-drm-card__purchase:hover { color: #135e96; }
.slswc-drm-card__refresh {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 14px;
    color: #8c8f94;
    font-size: 12px;
    cursor: pointer;
    background: none;
    border: none;
    padding: 0;
    transition: color .15s;
}
.slswc-drm-card__refresh:hover { color: #50575e; text-decoration: underline; }
#slswc-drm-refresh-msg {
    margin-top: 10px;
    font-size: 13px;
    font-weight: 500;
    display: none;
}
@media (max-width: 600px) {
    .slswc-drm-card__inner { padding: 32px 24px 28px; }
}
</style>

<div class="slswc-drm-card">
    <div class="slswc-drm-card__inner">
        ' . $logo_html . '
        <h2 class="slswc-drm-card__heading">
            <span class="slswc-drm-card__heading-urgent">' . esc_html__( 'Urgent!', 'slswcclient' ) . '</span>
            ' . sprintf(
                /* translators: 1: product name, 2: license status label */
                esc_html__( ' Your %1$s license is %2$s!', 'slswcclient' ),
                $product_name,
                $status_label
            ) . '
        </h2>

        <p class="slswc-drm-card__body">
            ' . sprintf(
                /* translators: %s: product name */
                esc_html__( 'Without an active license, your site will continue to operate normally, but access to %s settings and admin pages has been disabled until a valid license is entered.', 'slswcclient' ),
                $product_name
            ) . '
        </p>

        <svg class="slswc-drm-card__illustration" xmlns="http://www.w3.org/2000/svg"
            width="200" height="160" viewBox="0 0 200 160" fill="none" aria-hidden="true">
            <ellipse cx="36" cy="38" rx="22" ry="10" fill="#e0f2fe" opacity=".7"/>
            <ellipse cx="52" cy="32" rx="16" ry="10" fill="#e0f2fe" opacity=".7"/>
            <ellipse cx="156" cy="28" rx="18" ry="8" fill="#e0f2fe" opacity=".6"/>
            <ellipse cx="170" cy="22" rx="13" ry="8" fill="#e0f2fe" opacity=".6"/>
            <ellipse cx="30" cy="120" rx="12" ry="24" fill="#a855f7" opacity=".5" transform="rotate(-30 30 120)"/>
            <ellipse cx="18" cy="130" rx="9" ry="18" fill="#7c3aed" opacity=".4" transform="rotate(-20 18 130)"/>
            <ellipse cx="170" cy="118" rx="12" ry="24" fill="#a855f7" opacity=".5" transform="rotate(30 170 118)"/>
            <ellipse cx="182" cy="128" rx="9" ry="18" fill="#7c3aed" opacity=".4" transform="rotate(20 182 128)"/>
            <rect x="58" y="92" width="92" height="56" rx="5" fill="#1e3a5f"/>
            <rect x="62" y="96" width="84" height="46" rx="3" fill="#0f172a"/>
            <rect x="72" y="104" width="64" height="10" rx="3" fill="#1e40af" opacity=".6"/>
            <circle cx="82" cy="109" r="2.5" fill="#93c5fd"/>
            <circle cx="90" cy="109" r="2.5" fill="#93c5fd"/>
            <circle cx="98" cy="109" r="2.5" fill="#93c5fd"/>
            <circle cx="106" cy="109" r="2.5" fill="#93c5fd"/>
            <circle cx="114" cy="109" r="2.5" fill="#93c5fd"/>
            <rect x="72" y="120" width="48" height="3" rx="1.5" fill="#334155" opacity=".8"/>
            <rect x="72" y="127" width="36" height="3" rx="1.5" fill="#334155" opacity=".6"/>
            <rect x="72" y="134" width="56" height="3" rx="1.5" fill="#334155" opacity=".5"/>
            <rect x="48" y="148" width="112" height="6" rx="3" fill="#334155"/>
            <path d="M128 54 L152 62 L152 88 C152 102 140 112 128 116 C116 112 104 102 104 88 L104 62 Z"
                fill="url(#slswcShieldGrad)" stroke="#1d4ed8" stroke-width="1.5"/>
            <path d="M128 58 L148 65 L148 88 C148 100 138 109 128 113"
                stroke="#93c5fd" stroke-width="1" opacity=".4" stroke-linecap="round" fill="none"/>
            <rect x="120" y="82" width="16" height="12" rx="3" fill="#fff" opacity=".9"/>
            <path d="M123 82 L123 78 C123 74.7 133 74.7 133 78 L133 82"
                stroke="#fff" stroke-width="2" fill="none" stroke-linecap="round" opacity=".9"/>
            <circle cx="128" cy="87" r="2.5" fill="#2563eb"/>
            <rect x="126.5" y="88" width="3" height="3" rx="1.5" fill="#2563eb" opacity=".7"/>
            <defs>
                <linearGradient id="slswcShieldGrad" x1="104" y1="54" x2="152" y2="116" gradientUnits="userSpaceOnUse">
                    <stop offset="0%" stop-color="#38bdf8"/>
                    <stop offset="100%" stop-color="#1d4ed8"/>
                </linearGradient>
            </defs>
        </svg>

        <a href="' . $license_url . '" class="slswc-drm-card__cta">
            ' . esc_html__( 'Enter License Now', 'slswcclient' ) . '
        </a>

        <a href="' . $purchase_url . '" target="_blank" rel="noopener noreferrer" class="slswc-drm-card__purchase">
            ' . esc_html__( "Don't have a license yet? Purchase here.", 'slswcclient' ) . '
        </a>

        <button type="button" id="slswc-drm-refresh" class="slswc-drm-card__refresh"
            data-nonce="' . $nonce . '" data-identifier="' . esc_attr( $slug ) . '">
            <span id="slswc-drm-refresh-label">' . esc_html__( 'I already entered my license - refresh status', 'slswcclient' ) . '</span>
        </button>

        <p id="slswc-drm-refresh-msg"></p>
    </div>
</div>

<script>
jQuery(function($){
    $("#slswc-drm-refresh").on("click", function(){
        var $label = $("#slswc-drm-refresh-label");
        $label.text("' . esc_js( __( 'Checking...', 'slswcclient' ) ) . '");
        $.post(ajaxurl, {
            action: "slswc_drm_refresh_status_" + $(this).data("identifier"),
            nonce: $(this).data("nonce")
        }, function(response){
            if (response.success && "ok" === response.data.state) {
                $("#slswc-drm-refresh-msg")
                    .css("color", "#15803d")
                    .text("' . esc_js( __( 'License activated! Reloading...', 'slswcclient' ) ) . '")
                    .show();
                setTimeout(function(){ window.location.reload(); }, 1500);
            } else {
                $label.text("' . esc_js( __( 'I already entered my license - refresh status', 'slswcclient' ) ) . '");
                $("#slswc-drm-refresh-msg")
                    .css("color", "#b91c1c")
                    .text("' . esc_js( __( 'License still invalid. Please enter a valid license key.', 'slswcclient' ) ) . '")
                    .show();
            }
        });
    });
});
</script>';
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the current license status from the updater's license details.
     *
     * @since  1.2.0
     * @return string
     */
    private function get_license_status() {
        return $this->updater->license->get_license_status();
    }

    /**
     * Build the option key for a DRM-specific option.
     *
     * @since  1.2.0
     * @param  string $suffix Option name suffix.
     * @return string
     */
    private function option_key( $suffix ) {
        return $this->config['identifier'] . '_drm_' . $suffix;
    }

    /**
     * Multisite-aware option getter.
     *
     * @since  1.2.0
     * @param  string $option   Option name.
     * @param  mixed  $fallback Default value.
     * @return mixed
     */
    private function get_drm_option( $option, $fallback = false ) {
        return is_multisite()
            ? get_site_option( $option, $fallback )
            : get_option( $option, $fallback );
    }

    /**
     * Multisite-aware option setter.
     *
     * @since  1.2.0
     * @param  string $option Option name.
     * @param  mixed  $value  Value to store.
     */
    private function update_drm_option( $option, $value ) {
        if ( is_multisite() ) {
            update_site_option( $option, $value );
        } else {
            update_option( $option, $value, false );
        }
    }

    /**
     * Get the DRM config.
     *
     * @since  1.2.0
     * @return array
     */
    public function get_config() {
        return $this->config;
    }
}
