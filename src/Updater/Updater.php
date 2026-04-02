<?php
/**
 * The updater class.
 *
 * @version     1.0.0
 * @since       1.0.0
 * @package     Client
 * @link        https://licenseserver.io/
 */

namespace SLSWC\Client\Updater;

use SLSWC\Client\ApiClient;
use SLSWC\Client\Plugin;
use SLSWC\Client\Helper;
use SLSWC\Client\Theme;

use Exception;

/**
 * Class to manage products relying on the Software License Server for WooCommerce.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
class Updater {
    /**
     * Instance of this class.
     *
     * @since 1.0.0
     * @var object
     */
    private static $instance = null;

    /**
     * Version - current plugin version.
     *
     * @since 1.0.0
     * @var string
     */
    public $version;

    /**
     * Plugin text domain.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public $text_domain;

    /**
     * Holds a list of plugins
     *
     * @var array
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public $plugins = array();

    /**
     * Holds a list of themes
     *
     * @var array
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public $themes = array();

    /**
     * List of products
     *
     * @var     array
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     */
    public $products;

    /**
     * Status update messages
     *
     * @var array
     * @version 1.0.1
     * @since   1.0.1
     */
    public $messages = array();

    /**
     * The localization strings
     *
     * @var array
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public $localization = array();

    /**
     * The url of the license server
     *
     * @var string
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public $server_url;

    /**
     * The ApiClient instance
     *
     * @var ApiClient
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public $client;

    /**
     * The updater for each plugin/theme.
     *
     * @var array[Plugin|Theme]
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public $updaters = array();


    /**
     * Return instance of this class
     *
     * @param   string $server_url The url to the license server.
     * @return  object
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     */
    public static function get_instance( $server_url ) {
        if ( null === self::$instance ) {
            self::$instance = new self( $server_url );
        }

        return self::$instance;
    } // get_instance

    /**
     * Initialize the class actions
     *
     * @param string $server_url The url to the license server.
     *
     * @since 1.0.0
     * @version 1.0.0
     */
    private function __construct( $server_url ) {
        $this->server_url = $server_url;

        $this->client = new ApiClient( $this->server_url, 'slswc-client' );
    }

    /**
     * Initialize hooks
     *
     * @return void
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'wp_ajax_slswc_install_product', array( $this, 'product_background_installer' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
        add_action( 'init', array( $this, 'init_products' ), 1 );
        add_action( 'wp_ajax_slswc_activate_license', array( $this, 'activate_license' ) );
    }

    /**
     * Initialize products
     *
     * @return void
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function init_products() {
        $this->plugins = $this->get_local_plugins();
        $this->themes  = $this->get_local_themes();

        $this->products = array(
            'plugins' => $this->get_plugins(),
            'themes'  => $this->get_themes(),
        );

        foreach ( $this->get_plugins() as $plugin ) {
            $new_updater = Plugin::get_instance( $this->server_url, $plugin['file'], $plugin );
            $new_updater->init_hooks();

            $this->updaters[ $plugin['text_domain'] ] = $new_updater;
        }

        foreach ( $this->get_themes() as $theme ) {
            $new_updater = Theme::get_instance( $this->server_url, $theme['file'], $theme );
            $new_updater->init_hooks();

            $this->updaters[ $theme['text_domain'] ] = $new_updater;
        }
    }

    /**
     * Get local themes.
     *
     * Get locally installed themes that have SLSWC file headers.
     *
     * @return  array $installed_themes List of plugins.
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function get_local_themes() {
        if ( ! function_exists( 'wp_get_themes' ) ) {
            return array();
        }

        $themes = wp_cache_get( 'slswc_themes', 'slswc' );

        if ( ! is_array( $themes ) ) {
            $wp_themes = wp_get_themes();
            $themes    = array();

            foreach ( $wp_themes as $theme_file => $theme_details ) {
                if ( ! $theme_details->get( 'SLSWC' ) || 'theme' !== $theme_details->get( 'SLSWC' ) ) {
                    continue;
                }
                $theme_data                              = Helper::format_theme_data( $theme_details, $theme_file );
                $themes[ $theme_details->get( 'Slug' ) ] = wp_parse_args( $theme_data, $this->default_remote_product() );
            }
        }

        $this->set_themes( $themes );

        wp_cache_add(
            'slswc_themes',
            $themes,
            'slswc',
            apply_filters( 'slswc_themes_cache_expiry', HOUR_IN_SECONDS * 2 )
        );

        return $themes;
    }

    /**
     * Get local plugins.
     *
     * Get locally installed plugins that have SLSWC file headers.
     *
     * @return  array $installed_plugins List of plugins.
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function get_local_plugins() {
        if ( ! function_exists( 'get_plugins' ) ) {
            return array();
        }

        $plugins = wp_cache_get( 'slswc_plugins', 'slswc' );

        if ( ! is_array( $plugins ) ) {
            $plugins    = array();
            $wp_plugins = get_plugins();

            foreach ( $wp_plugins as $plugin_file => $plugin_details ) {
                if ( ! isset( $plugin_details['SLSWC'] ) || 'plugin' !== $plugin_details['SLSWC'] ) {
                    continue;
                }

                $plugin_data                             = Helper::format_plugin_data( $plugin_details, $plugin_file, 'plugin' );
                $plugins[ $plugin_data['text_domain'] ] = wp_parse_args( $plugin_data, $this->default_remote_product() );
            }

            wp_cache_add(
                'slswc_plugins',
                $plugins,
                'slswc',
                apply_filters( 'slswc_plugins_cache_expiry', HOUR_IN_SECONDS * 2 )
            );
        }

        $this->set_plugins( $plugins );

        return $plugins;
    }

    /**
     * Get default remote product data
     *
     * @param   string $type The software type. Expects plugin, theme or other. Default plugin.
     * @return  array $default_data The default product data.
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function default_remote_product( $type = 'plugin' ) {
        $default_data = array(
            'thumbnail'      => '',
            'updated'        => gmdate( 'd-m-Y' ),
            'reviews_count'  => 0,
            'average_rating' => 0,
            'activations'    => 0,
            'type'           => $type,
            'download_url'   => '',
        );

        return $default_data;
    }

    /**
     * Activate the updater
     *
     * @return void
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function activate() {
        update_option( 'slswc_update_client_version', $this->version );
    }

    /**
     * Enqueue scripts.
     *
     * @return  void
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function admin_enqueue_scripts() {
        $localization = array(
            'ajax_url'        => esc_url( admin_url( 'admin-ajax.php' ) ),
            'loader_url'      => esc_url( admin_url( 'images/loading.gif' ) ),
            'text_activate'   => esc_attr( __( 'Activate', 'slswc-client' ) ),
            'text_deactivate' => esc_attr( __( 'Deactivate', 'slswc-client' ) ),
            'text_done'       => esc_attr( __( 'Done', 'slswc-client' ) ),
            'text_processing' => esc_attr( __( 'Processing', 'slswc-client' ) ),
        );

        if ( $this->is_products_page() ) {
            wp_register_script(
                'slswc-client-products',
                SLSWC_CLIENT_ASSETS_URL . 'js/products.js',
                array( 'jquery', 'thickbox' ),
                $this->version,
                true
            );

            $localization['nonce'] = wp_create_nonce( 'slswc_install_product' );

            wp_localize_script(
                'slswc-client-products',
                'slswc_updater_products',
                $localization
            );

            wp_enqueue_script( 'slswc-client-products' );
            wp_enqueue_script( 'thickbox' );
            wp_enqueue_style( 'thickbox' );
        }

        if ( $this->is_licenses_tab() ) {
            wp_register_script(
                'slswc-client-licenses',
                SLSWC_CLIENT_ASSETS_URL . 'js/licenses.js',
                array( 'jquery' ),
                $this->version,
                true
            );

            $localization['nonce'] = wp_create_nonce( 'slswc_activate_license' );

            wp_localize_script(
                'slswc-client-licenses',
                'slswc_updater_licenses',
                $localization
            );
            wp_enqueue_script( 'slswc-client-licenses' );
        }

        if ( $this->is_products_page() || $this->is_licenses_tab() ) {
            wp_register_style(
                'slswc-client',
                SLSWC_CLIENT_ASSETS_URL . 'css/slswc-client.css',
                array(),
                $this->version,
                'all'
            );

            wp_enqueue_style( 'slswc-client' );
        }
    }

    /**
     * Check if the current page is license page.
     *
     * @return  boolean
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function is_licenses_page() {
        $page = 'slswc_license_manager';
		// phpcs:disable
		$is_page = isset( $_GET['page'] ) && $page === $_GET['page'] ? true : false;
		$is_tab  = isset( $_GET['tab'] ) && $_GET['tab'] === '' ? true : false;
		// phpcs:enable
        if ( is_admin() && $is_page && $is_tab ) {
            return true;
        }

        return false;
    }

    /**
     * Check if the current page is a product list page.
     *
     * @return  boolean
     * @version 1.0.3
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function is_products_page() {
        $tabs = array( 'plugins', 'themes' );
        $page = 'slswc_license_manager';
		// phpcs:disable
		$is_page = isset( $_GET['page'] ) && $page === $_GET['page'] ? true : false;
		$is_tab  = isset( $_GET['tab'] ) && in_array( wp_unslash( $_GET['tab'] ), $tabs, true ) ? true : false;
		// phpcs:enable
        if ( is_admin() && $is_page && $is_tab ) {
            return true;
        }

        return false;
    }

    /**
     * Check if we are on the licenses tab on the license manager page.
     *
     * @return boolean
     * @version 1.0.3
     * @since   1.0.3
     */
    public function is_licenses_tab() {
        $tab  = $this->get_tab();
        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore

        return is_admin() && 'licenses' === $tab && 'slswc_license_manager' === $page;
    }

    /**
     * ------------------------------------------------------------------
     * Output Functions
     * ------------------------------------------------------------------
     */

    /**
     * Add the admin menu to the dashboard
     *
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     */
    public function add_admin_menu() {
        add_options_page(
            __( 'License Manager', 'slswc-client' ),
            __( 'License Manager', 'slswc-client' ),
            'manage_options',
            'slswc_license_manager',
            array( $this, 'show_installed_products' )
        );
    }

    /**
     * List all products installed on this server.
     *
     * @return  void
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     */
    public function show_installed_products() {
		// phpcs:ignore
		$tab = $this->get_tab();
        $license_admin_url = admin_url( 'options-general.php?page=slswc_license_manager' );

        ?>
        <div class="wrap plugin-install-tab">
            <div id="slswc-product-install-message" class="notice inline hidden"><p></p></div>
            <h1><?php esc_html_e( 'Licensed Plugins and Themes', 'slswc-client' ); ?></h1>
            <?php

            if ( isset( $_POST['save_api_keys_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['save_api_keys_nonce'] ) ), 'save_api_keys' ) ) {
                $username        = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
                $consumer_key    = isset( $_POST['consumer_key'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_key'] ) ) : '';
                $consumer_secret = isset( $_POST['consumer_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['consumer_secret'] ) ) : '';

                $save_username        = update_option( 'slswc_api_username', $username );
                $save_consumer_key    = update_option( 'slswc_consumer_key', $consumer_key );
                $save_consumer_secret = update_option( 'slswc_consumer_secret', $consumer_secret );

                if ( $save_username && $save_consumer_key && $save_consumer_secret ) {
                    ?>
                    <div class="updated"><p><?php esc_html_e( 'API Settings saved', 'slswc-client' ); ?></p></div>
                    <?php
                }
            }

            if ( ! empty( $_POST['connect_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['connect_nonce'] ) ), 'connect' ) ) {
                $connected = $this->connect();
                if ( $connected ) {
                    ?>
                    <div class="updated">
                        <p><?php esc_html_e( 'API Connected successfully.', 'slswc-client' ); ?></p>
                    </div>
                    <?php
                } else {
                    ?>
                    <div class="error notice is-dismissible">
                        <p><?php esc_html_e( 'API connection failed. Please check your keys and try again.', 'slswc-client' ); ?></p>
                    </div>
                    <?php
                }
            }

            if ( ! empty( $_POST['reset_api_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['reset_api_settings_nonce'] ) ), 'reset_api_settings' ) ) {
                $deleted_username        = delete_option( 'slswc_api_username' );
                $deleted_consumer_key    = delete_option( 'slswc_consumer_key' );
                $deleted_consumer_secret = delete_option( 'slswc_consumer_secret' );

                if ( $deleted_username && $deleted_consumer_key && $deleted_consumer_secret ) {
                    ?>
                    <p class="updated">
                    <?php
                    esc_attr_e( 'API Keys successfully.', 'slswc-client' );
                    ?>
                    </p>
                    <?php
                } else {
                    ?>
                    <p class="updated">
                    <?php
                    esc_attr_e( 'API Keys not reset.', 'slswc-client' );
                    ?>
                    </p>
                    <?php
                }
            }

            if ( ! empty( $_POST['disconnect_nonce'] )
                && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['disconnect_nonce'] ) ), 'disconnect' )
            ) {
                update_option( 'slswc_api_connected', 'no' );
            }

            foreach ( $this->products as $product ) {
                if ( ! isset( $product['text_domain'] ) ) {
                    continue;
                }

                $option_name = esc_attr( $product['text_domain'] ) . '_license_manager';

                settings_errors( $option_name );
            }

            require_once SLSWC_CLIENT_PARTIALS_DIR . 'panels.php';
            ?>
        <div>
        <?php
    }

    /**
     * Output licenses form
     *
     * @return  void
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     */
    public function licenses_form() {
        if (
            ! empty( $_POST['licenses'] )
            && ! empty( $_POST['save_licenses_nonce'] )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['save_licenses_nonce'] ) ), 'save_licenses' )
        ) {
			// phpcs:ignore
			$post_licenses = isset( $_POST['licenses'] ) ? wp_unslash( $_POST['licenses'] ) : array();

            if ( ! empty( $post_licenses ) ) {
                foreach ( $post_licenses as $slug => $license_details ) {
                    $license_details = Helper::recursive_parse_args(
                        $license_details,
                        array(
                            'license_status'  => 'inactive',
                            'license_key'     => '',
                            'license_expires' => '',
                            'current_version' => $this->version,
                            'environment'     => '',
                        )
                    );

                    do_action( "slswc_save_license_{$slug}", $license_details );
                }
            }
        }

        $this->display_messages();
        ?>
        <h2 class="screen-reader-text">
            <?php esc_html_e( 'Licenses', 'slswc-client' ); ?>
        </h2>
        <div id="license-action-response-message"></div>
        <form name="licenses-form" action="" method="post">
            <?php wp_nonce_field( 'save_licenses', 'save_licenses_nonce' ); ?>
            <div id="the-list">
                <?php
                if ( ! empty( $this->plugins ) ):
                    $this->licenses_rows( $this->plugins );
                    do_action( 'slswc_after_plugins_licenses_rows' );
                endif;

                if ( ! empty( $this->themes ) ):
                    $this->licenses_rows( $this->themes );
                    do_action( 'slswc_after_themes_licenses_list' );
                    endif;
                ?>
                <?php do_action( 'slswc_after_products_licenses_list', $this->plugins, $this->themes ); ?>
            </div>
        </form>
        <?php
    }

    /**
     * Licenses rows output
     *
     * @param   array $products The list of software products.
     * @return  void
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     */
    public function licenses_rows( $products ) {
        foreach ( $products as $product ):
            $text_domain = esc_attr( $product['text_domain'] );

            $updater = $this->updaters[ $text_domain ];

            $license_info = $updater->license->get_license_details();
            $product_name = ! empty( $product['name'] ) ? $product['name'] : $product['title'];

            $has_license_info    = empty( $license_info ) ? false : true;
            $license_key         = $has_license_info ? esc_attr( $license_info['license_key'] ) : '';
            $current_version     = $has_license_info ? esc_attr( $license_info['version'] ) : '';
            $license_status      = $has_license_info ? esc_attr( $license_info['license_status'] ) : '';
            $license_expires     = $has_license_info ? esc_attr( $license_info['license_expires'] ) : '';
            $license_environment = $has_license_info && isset( $license_info['environment'] ) ? esc_attr( $license_info['environment'] ) : '';

            $active_status = $has_license_info ? ( array_key_exists( 'active_status', $license_info ) && isset( $license_info['active_status'][ $license_environment ] ) && 'yes' === $license_info['active_status'][ $license_environment ] ? true : false ) : false;

            $is_active = wc_string_to_bool( $active_status );

            $chip_class = $is_active ? 'active' : 'inactive';

            require_once SLSWC_CLIENT_PARTIALS_DIR . 'license-row.php';
        endforeach;
    }

    /**
     * Output a list of products.
     *
     * @param   array $products The list of products.
     * @return  void
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     */
    public function list_products( $products ) {
        $products = is_array( $products ) ? $products : (array) $products;

        $type = $this->get_tab();

        if ( in_array( $type, array( 'plugins', 'themes' ), true ) ) {
            $slugs    = array();
            $licenses = array();
            foreach ( $products as $slug => $details ) {
                $slugs[]           = $slug;
                $licenses[ $slug ] = $details;
            }
            $args            = array( 'post_name__in' => $slugs );
            $remote_products = (array) $this->get_remote_products( $type, $args );
        } else {
            $remote_products = array();
        }

        Helper::log( 'Local products.' );
        Helper::log( $products );
        Helper::log( 'Remote Products' );
        Helper::log( $remote_products );

        if ( ! empty( $remote_products ) ) {
            foreach ( $remote_products as $slug => $product ) {
                if ( ! array_key_exists( $slug, $products ) ) {
                    continue;
                }
                $products[ $slug ] = Helper::map_remote_product( $products[ $slug ], (array) $product );
            }
        }

        if ( ! empty( $products ) && count( $products ) > 0 ):
            require_once SLSWC_CLIENT_PARTIALS_DIR . 'list-products.php';
        else:
            require_once SLSWC_CLIENT_PARTIALS_DIR . 'no-products.php';
        endif;
    }

    /**
     * Output API Settings form
     *
     * @return void
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     */
    public function api_form() {
        $keys = Helper::get_api_keys();
        require_once SLSWC_CLIENT_PARTIALS_DIR . 'api-keys.php';
    }

    /**
     * Output the product ratings
     *
     * @return void
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     *
     * @param array $args The options for the rating.
     */
    public function output_ratings( $args ) {
        wp_star_rating( $args );
        ?>
        <span class="num-ratings" aria-hidden="true">(<?php echo esc_attr( $args['number'] ); ?>)</span>
        <?php
    }

    /**
     * Show compatibility message
     *
     * @param   string $version - The version to compare with installed WordPress version.
     * @return  void
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     */
    public function show_compatible( $version ) {
        global $wp_version;
        $compatible = version_compare( $version, $wp_version ) >= 0 ? true : false;

        if ( $compatible ) {
            $compatibility_label = __( 'Compatible', 'slswc-client' );
            $compatibility_class = 'compatible';
        } else {
            $compatibility_label = __( 'Not compatible', 'slswc-client' );
            $compatibility_class = 'incompatible';
        }
        ?>
        <span class="compatibility-<?php echo esc_attr( $compatibility_class ); ?>">
            <strong><?php echo esc_html( $compatibility_label ); ?></strong>
            <?php esc_html_e( ' with WordPress', 'slswc-client' ); ?>&nbsp;<strong><?php echo esc_html( $wp_version ); ?></strong>
        </span>
        <?php
    }

    /**
     * Show tested version
     *
     * @param string $version The current version of the plugin.
     * @return void
     * @version 1.1.0
     * @since   1.1.0
     */
    public function show_tested_version( $version ) {
        global $wp_version;

        $tested = version_compare( $wp_version, $version ) > 0 ? true : false;

        if ( $tested ) {
            $tested_label = __( 'Tested', 'slswc-client' );
            $tested_class = 'compatible';
        } else {
            $tested_label = __( 'Not tested', 'slswc-client' );
            $tested_class = 'incompatible';
        }
        ?>
        <span class="compatibility-<?php echo esc_attr( $tested_class ); ?>">
            <strong><?php echo esc_html( $tested_label ); ?></strong><br />
            <?php esc_html_e( ' with WordPress', 'slswc-client' ); ?>&nbsp;<strong><?php echo esc_html( $wp_version ); ?></strong>
        </span>
        <?php
    }

    /**
     * License activated field.
     *
     * @since 1.0.0
     * @since 1.0.1
     * @version 1.0.0
     *
     * @param string $status The license status.
     */
    public function license_status_field( $status ) {
        $license_labels = Helper::license_status_types();

        echo empty( $status ) || ! isset( $license_labels[ $status ] ) ? 'Inactive' : esc_attr( $license_labels[ $status ] );
    }

    /**
     * Connect to the api server using API keys
     *
     * @return  boolean
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     */
    public function connect() {
        $keys       = Helper::get_api_keys();
        $connection = $this->client->request( 'connect', $keys );

        Helper::log( 'Connecting...' );

        if ( $connection && $connection->connected && 'ok' === $connection->status ) {
            update_option( 'slswc_updater_api_connected', apply_filters( 'slswc_api_connected', 'yes' ) );
            update_option( 'slswc_updater_api_auth_user', apply_filters( 'slswc_api_auth_user', $connection->auth_user ) );

            return true;
        }

        return false;
    }

    /**
     * Get more details about the product from the license server.
     *
     * @param   string $slug The software slug.
     * @param   string $type The type of software. Expects plugin/theme, default 'plugin'.
     * @return  array
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     */
    public function get_remote_product( $slug, $type = 'plugin' ) {
        $request_info = array(
            'text_domain' => $slug,
            'type'        => $type,
        );

        $license_data = get_option( $slug . '_license_manager', null );

        if ( Helper::is_connected() ) {
            $request_info = array_merge( $request_info, Helper::get_api_keys() );
        } elseif ( null !== $license_data && ! empty( $license_data['license_key'] ) ) {
            $request_info['license_key'] = trim( $license_data['license_key'] );
        }

        $response = $this->client->request( 'product', $request_info );

        if ( is_object( $response ) && 'ok' === $response->status ) {
            return $response->product;
        }

        Helper::log( 'Get remote product' );
        Helper::log( $response->product );

        return array();
    }

    /**
     * Get a user's purchased products.
     *
     * @param   string $type The type of products. Expects plugins|themes, default 'plugins'.
     * @param   array  $args The arguments to form the query to search for the products.
     * @return  array
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.0
     */
    public function get_remote_products( $type = 'plugins', $args = array() ) {
        $licensed_products = array();
        $request_info      = array();
        $slugs             = array();

        $request_info['type'] = $type;

        $licenses_data = $this->get_license_data_for_all( $type );

        foreach ( $licenses_data as $slug => $_license_data ) {
            if ( empty( $_license_data ) ) {
                continue;
            }

            if ( ! $this->ignore_status( $_license_data['license_status'] ) ) {
                $_license_data['domain']    = untrailingslashit( str_ireplace( array( 'http://', 'https://' ), '', home_url() ) );
                $slugs[]                    = $slug;
                $licensed_products[ $slug ] = $_license_data;
            }
        }

        if ( ! empty( $licensed_products ) ) {
            $request_info['licensed_products'] = $licensed_products;
        }

        $request_info['query_args'] = wp_parse_args(
            $args,
            array(
                'post_name__in' => $slugs,
            )
        );

        if ( Helper::is_connected() ) {
            $request_info['api_keys'] = Helper::get_api_keys();
        }

        $response = $this->client->request( 'products', $request_info );

        Helper::log( 'Getting remote products' );
        Helper::log( $response );

        if ( is_object( $response ) && 'ok' === $response->status ) {
            return $response->products;
        }

        return array();
    }

    /**
     * Get license data for all locally installed
     *
     * @param   string $type The type of products to return license details for. Expects plugins/themes, default empty.
     * @return  array
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function get_license_data_for_all( $type = '' ) {
        $all_products  = array();
        $licenses_data = array();

        if ( $this->valid_type( $type ) ) {
            $function              = "get_local_{$type}";
            $all_products[ $type ] = $this->$function();
        } else {
            $all_products['themes']  = $this->get_local_themes();
            $all_products['plugins'] = $this->get_local_plugins();
        }

        foreach ( $all_products as $type => $products ) {
            foreach ( $products as $slug => $product ) {
                $_license_data          = get_option( $slug . '_license_manager', array() );
                $licenses_data[ $slug ] = $_license_data;
            }
        }

        $maybe_type_key = '' !== $type ? $type : '';
        return apply_filters( 'slswc_updater_license_data_for_all' . $maybe_type_key, $licenses_data );
    }

    /**
     * Check if valid product type.
     *
     * @param   string $type The plural product type plugins|themes.
     * @return  bool
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function valid_type( $type ) {
        return in_array( $type, array( 'themes', 'plugins' ), true );
    }

    /**
     * Check if status should be ignored
     *
     * @param   string $status The status tp check.
     * @return  bool
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function ignore_status( $status ) {
        $ignored_statuses = array( 'expired', 'max_activations', 'failed' );
        return in_array( $status, $ignored_statuses, true );
    }

    /**
     * Get the current tab.
     *
     * @return  string
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     */
    public function get_tab() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
        return isset( $_GET['tab'] ) && ! empty( $_GET['tab'] )
            ? esc_attr( sanitize_text_field( wp_unslash( $_GET['tab'] ) ) )
            : 'licenses';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Save a list of products to the database.
     *
     * @param array $products List of products to save.
     * @return void
     */
    public function save_products( $products = array() ) {
        if ( empty( $products ) ) {
            $this->products = $products;
        }
        Helper::log( 'Saving products...' );
        Helper::log( $products );
        update_option( 'slswc_products', $products );
    }

    /**
     * Recursively merge two arrays.
     *
     * @param array $args User defined args.
     * @param array $defaults Default args.
     * @return array $new_args The two array merged into one.
     */
    public function recursive_parse_args( $args, $defaults ) {
        $args     = (array) $args;
        $new_args = (array) $defaults;
        foreach ( $args as $key => $value ) {
            if ( is_array( $value ) && isset( $new_args[ $key ] ) ) {
                $new_args[ $key ] = Helper::recursive_parse_args( $value, $new_args[ $key ] );
            } else {
                $new_args[ $key ] = $value;
            }
        }
        return $new_args;
    }

    /**
     * Get all messages to be added to admin notices.
     *
     * @return array
     * @version 1.0.0
     * @since   1.0.0
     */
    public function get_messages() {
        return $this->messages;
    }

    /**
     * Add a message to be shown to admin
     *
     * @param string $key     The array key of the message.
     * @param string $message The message to be added.
     * @param string $type    The type of message to be added.
     * @return void
     * @version 1.0.0
     * @since   1.0.0
     */
    public function add_message( $key, $message, $type = 'success' ) {
        $this->messages[] = array(
            'key'     => $key,
            'message' => $message,
            'type'    => $type,
        );
    }

    /**
     * Display license update messages.
     *
     * @return void
     * @version 1.0.0
     * @since   1.0.0
     */
    public function display_messages() {
        if ( empty( $this->messages ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        foreach ( $this->messages as $message ) {
            printf(
                '<div class="%1$s notice is-dismissible"><p>%2$s</p></div>',
                esc_attr( $message['type'] ),
                wp_kses_post( $message['message'] )
            );
        }
    }

    /**
     * Get a list of plugins
     *
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @return array
     */
    public function get_plugins() {
        return $this->plugins;
    }

    /**
     * Set a list of all plugins
     *
     * @param array $plugins The list of plugins.
     *
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @return object
     */
    public function set_plugins( $plugins ) {
        $this->plugins = $plugins;

        return $this;
    }

    /**
     * Get a list of all themes
     *
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @return array
     */
    public function get_themes() {
        return $this->themes;
    }

    /**
     * Set a list of themes
     *
     * @param array $themes The list of themes.
     * @version 1.0.0
     * @since   1.0.0 - Refactored into classes and converted into a composer package.
     * @return object
     */
    public function set_themes( $themes ) {
        $this->themes = $themes;

        return $this;
    }

    /**
     * Activate a license
     *
     * @return void
     * @version 1.0.0
     * @since   1.0.0
     */
    public function activate_license() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'slswc_activate_license' ) ) {
            wp_send_json_error(
                array(
                    'message' => __(
                        'Failed to activate license. Security verification failed.',
                        'slswc-client'
                    ),
                )
            );
        }

        $request_args = array(
            'text_domain' => isset( $_POST['text_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['text_domain'] ) ) : '',
            'license_key' => isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '',
            'domain'      => isset( $_POST['domain'] ) ? sanitize_url( wp_unslash( $_POST['domain'] ) ) : '',
            'version'     => isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '',
            'environment' => isset( $_POST['environment'] ) ? sanitize_text_field( wp_unslash( $_POST['environment'] ) ) : '',
        );

        $empty_args = array();
        $has_empty  = false;

        foreach ( $request_args as $key => $value ) {
            if ( '' === $value && 'environment' !== $key ) {
                $has_empty    = true;
                $empty_args[] = $key;
            }
        }

        if ( ! empty( $empty_args ) && $has_empty ) {
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        // translators:
                        __(
                            'Missing required parameter. The following args are required but not included in your request: %s',
                            'slswclient'
                        ),
                        implode( ',', $empty_args )
                    ),
                )
            );
        }

        $software_updater = $this->updaters[ $request_args['text_domain'] ];

        $response = $software_updater->license->validate_license( $request_args );

        wp_send_json( $response );
    }

    /**
     * Install a product.
     *
     * @since   1.0.0
     * @version 1.0.0
     */
    public static function product_background_installer() {
        global $wp_filesystem;

        $slug = isset( $_REQUEST['text_domain'] ) ? wp_unslash( sanitize_text_field( wp_unslash( $_REQUEST['text_domain'] ) ) ) : '';
        if ( ! array_key_exists( 'nonce', $_REQUEST )
            || ! empty( $_REQUEST ) && array_key_exists( 'nonce', $_REQUEST )
            && isset( $_REQUEST ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'slswc_client_install_' . $slug ) ) {
            wp_send_json_error(
                array(
                    'message' => esc_attr__( 'Failed to install product. Security token invalid.', 'slswc-client' ),
                )
            );
        }

        $download_link = isset( $_POST['package'] ) ? sanitize_text_field( wp_unslash( $_POST['package'] ) ) : '';
        $name          = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $product_type  = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';

        if ( ! empty( $download_link ) ) {
            // Suppress feedback.
            ob_start();

            try {
                $temp_file = download_url( $download_link, 60 );

                if ( ! is_wp_error( $temp_file ) ) {
                    require_once ABSPATH . '/wp-admin/includes/file.php';
                    WP_Filesystem();

                    if ( 'plugin' === $product_type ) {
                        $destination_dir = WP_CONTENT_DIR . '/plugins';
                    } elseif ( 'theme' === $product_type ) {
                        $destination_dir = WP_CONTENT_DIR . '/themes';
                    } else {
                        $destination_dir = WP_CONTENT_DIR;
                    }

                    $temp_dir = WP_CONTENT_DIR . '/slswcclient_temp_downloads';
                    if ( ! $wp_filesystem->is_dir( $temp_dir ) ) {
                        $wp_filesystem->mkdir( $temp_dir, FS_CHMOD_DIR );
                    }

                    $file_name   = $slug . '.zip';
                    $destination = $temp_dir . $file_name;

                    if ( $wp_filesystem->exists( $temp_file ) ) {
                        $wp_filesystem->move( $temp_file, $destination, true );
                    }

                    if ( $wp_filesystem->exists( $destination ) ) {
                        $unzipfile = unzip_file( $destination, $destination_dir );

                        if ( $unzipfile ) {
                            $deleted = $wp_filesystem->delete( $destination );
                            wp_send_json_success(
                                array(
                                    'message' => sprintf(
                                        // translators: %s - the name of the plugin/theme.
                                        __( 'Successfully installed new version of %s', 'slswc-client' ),
                                        $name
                                    ),
                                )
                            );
                        } else {
                            wp_send_json_error(
                                array(
                                    'slug'    => $slug,
                                    'message' => __( 'Installation failed. There was an error extracting the downloaded file.', 'slswc-client' ),
                                )
                            );
                        }
                    }
                }
            } catch ( Exception $e ) {
                wp_send_json_error(
                    array(
                        'slug'    => $slug . '_install_error',
                        'message' => sprintf(
                            // translators: 1: theme slug, 2: error message, 3: URL to install theme manually.
                            __( '%1$s could not be installed (%2$s). <a href="%3$s">Please install it manually by clicking here.</a>', 'slswc-client' ),
                            $slug,
                            $e->getMessage(),
                            esc_url( admin_url( 'update.php?action=install-' . $product_type . '&' . $product_type . '=' . $slug . '&_wpnonce=' . wp_create_nonce( 'install-' . $product_type . '_' . $slug ) ) )
                        ),
                    )
                );
            }

            wp_send_json_error( array( 'message' => __( 'No action taken.', 'slswc-client' ) ) );

            // Discard feedback.
            ob_end_clean();
        }

        wp_send_json(
            array(
                'message' => __( 'Failed to install product. Download link not provided or is invalid.', 'slswc-client' ),
            )
        );
    }
}
