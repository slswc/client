<?php
/**
 * Defines the plugin updater class
 *
 * @version     1.1.0
 * @since       1.1.0
 * @package     Client
 * @link        https://licenseserver.io/
 */

namespace Digitalduz\Slswc\Client;

/**
 * Plugin updater class
 *
 * @version 1.1.0
 * @since   1.1.0
 */
class Plugin extends GenericSoftwareUpdater implements SoftwareUpdaterInterface {

    /**
     * The plugin file
     *
     * @var string
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $plugin_file;

    /**
     * The dir and file of the plugin
     *
     * @var string
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $plugin_dir_file;

    /**
     * DRM configuration array (if DRM is enabled).
     *
     * @var array|null
     * @since 1.2.0
     */
    private $drm_config = null;

    /**
     * DRM enforcer instance.
     *
     * @var DRM|null
     * @since 1.2.0
     */
    public $drm = null;

    /**
     * Get an instance of this class..
     *
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     * @version 1.1.0
     * @param   string $license_server_url - The base url to your WooCommerce shop.
     * @param   string $base_file          - path to the plugin file or directory, relative to the plugins directory.
     * @param   array  $args               - array of additional arguments to override default ones.
     */
    public static function get_instance( $license_server_url, $base_file, $args ) {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self( $license_server_url, $base_file, $args );
        }

        return self::$instance;
    }

    /**
     * Initialize the class.
     *
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     * @version 1.1.0
     * @param   string $license_server_url - The base url to your WooCommerce shop.
     * @param   string $plugin_file - path to the plugin file or directory, relative to the plugins directory.
     * @param   array  $args        - array of additional arguments to override default ones.
     *
     * @return void
     *
     * @example
     *   $plugin = Plugin::get_instance(
     *     'https://example.com',
     *      __FILE__,
     *      array(
     *        'version'     => '1.1.0',
     *        'license_key' => 'LICENSE_KEY',
     *        'domain'      => 'example.com',
     *      )
     *   );
     */
    public function __construct( $license_server_url, $plugin_file, $args = array() ) {
        $this->plugin_file = $plugin_file;

        $args = Helper::get_file_details( $this->plugin_file, $args );

        $this->plugin_dir_file    = $this->plugin_dir_file();
        $this->license_server_url = $license_server_url;

        // Store DRM config if provided.
        if ( isset( $args['drm'] ) && is_array( $args['drm'] ) ) {
            $this->drm_config = $args['drm'];
        }

        parent::__construct( $license_server_url, $plugin_file, $args );
    }

    /**
     * Initialize Hooks
     *
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function init_hooks() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update_check' ) );
        add_filter( 'plugins_api', array( $this, 'add_plugin_info' ), 10, 3 );
        add_filter( 'plugin_row_meta', array( $this, 'check_for_update_link' ), 10, 2 );
        add_filter( 'extra_plugin_headers', array( $this, 'extra_headers' ) );
        add_filter( 'site_transient_update_plugins', array( $this, 'change_update_information' ) );
        add_filter( 'transient_update_plugins', array( $this, 'change_update_information' ) );
        add_action( 'in_plugin_update_message-' . $this->plugin_dir_file, array( $this, 'need_license_message' ), 10, 2 );
        add_filter( 'http_request_host_is_external', array( $this, 'fix_update_host' ), 10, 2 );

        // Initialize DRM if configured.
        if ( ! empty( $this->drm_config ) ) {
            $this->drm = new DRM( $this->drm_config, $this );
            $this->drm->run();
        }
    }

    /**
     * Get the DRM enforcer instance.
     *
     * @since  1.2.0
     * @return DRM|null
     */
    public function get_drm() {
        return $this->drm;
    }

    /**
     * This is for internal purposes to ensure that during development the HTTP requests go through.
     * This is due to security features in the WordPress HTTP API.
     *
     * Source for this solution: Plugin Update Checker Library 3387.1 by Janis Elsts.
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param bool   $allow Whether to allow or not.
     * @param string $host  The host name.
     * @return bool
     */
    public function fix_update_host( $allow, $host ) {
        $license_server_host = @wp_parse_url( $this->license_server_url, PHP_URL_HOST );

        if ( strtolower( $host ) === strtolower( $license_server_host ) ) {
            return true;
        }
        return $allow;
    }

    /**
     * Get the plugin folder and base name based on the file path
     *
     * @return string
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function plugin_dir_file() {
        $plugin_folder = '';
        $plugin_file   = '';

        // Normalize the file path by removing any trailing slashes
        $file_path = rtrim( $this->plugin_file, '/' );

        // Extract the folder name and file name from the file path
        $file_parts = explode( '/', $file_path );
        $file_count = count( $file_parts );

        if ( $file_count >= 2 ) {
            $plugin_folder = $file_parts[ $file_count - 2 ];
            $plugin_file   = $file_parts[ $file_count - 1 ];
        }

        // Return the plugin folder and file name as a string
        return $plugin_folder . '/' . $plugin_file;
    }

    /**
     * Check for updates with the license server.
     *
     * @since  1.1.0
     * @param  object $transient object from the update api.
     * @return object $transient object possibly modified.
     */
    public function update_check( $transient ) {
        Helper::log( 'Update check: ' . print_r( $this->get_license_details(), true ) );

        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $response = $this->client->request(
            'check_update',
            $this->get_license_details()
        );

        if ( ! $this->license->check_license( $response ) ) {
            Helper::log( 'License check failed' );
            return $transient;
        }

        Helper::log( 'The license is valid. Check software details' );

        if ( isset( $response ) && is_object( $response->software_details ) ) {
            $plugin_update_info = $response->software_details;

            if ( ! isset( $plugin_update_info->new_version ) ) {
                return $transient;
            }

            if ( version_compare( $plugin_update_info->new_version, $this->get_version(), '>' ) ) {
                $plugin_update_info->plugin = $this->plugin_dir_file;

                // Required to cast as array due to how object is returned from api.
                $plugin_update_info->sections                  = (array) $plugin_update_info->sections;
                $plugin_update_info->banners                   = (array) $plugin_update_info->banners;
                $transient->response[ $this->plugin_dir_file ] = $plugin_update_info;

                unset( $transient->no_update[ $this->plugin_dir_file ] );
            } else {
                unset( $transient->response[ $this->plugin_dir_file ] );
                $transient->no_update[ $this->plugin_dir_file ] = $plugin_update_info;
            }
        }

        return $transient;
    }

    /**
     * Add the plugin information to the WordPress Update API.
     *
     * @since  1.1.0
     * @param  bool|object $result The result object. Default false.
     * @param  string      $action The type of information being requested from the Plugin Install API.
     * @param  object      $args Plugin API arguments.
     * @return object
     */
    public function add_plugin_info( $result, $action = null, $args = null ) {
        // Is this about our plugin?
        if ( isset( $args->slug ) ) {
            if ( $args->slug !== $this->get_text_domain() ) {
                return $result;
            }
        } else {
            return $result;
        }

        $server_response = $this->client->request( 'check_update', $this->get_license_details() );

        if ( ! isset( $server_response->software_details ) || is_null( $server_response->software_details ) ) {
            return $result;
        }

        $plugin_update_info = $server_response->software_details;

        $plugin_update_info->plugin = $this->plugin_dir_file;

        // Required to cast as array due to how object is returned from api.
        $plugin_update_info->sections = (array) $plugin_update_info->sections;
        $plugin_update_info->banners  = (array) $plugin_update_info->banners;
        $plugin_update_info->ratings  = (array) $plugin_update_info->ratings;
        if ( isset( $plugin_update_info ) && is_object( $plugin_update_info ) && false !== $plugin_update_info ) {
            Helper::log( 'Plugin update info: ' . print_r( $plugin_update_info, true ) );
            return $plugin_update_info;
        }

        return $result;
    }

    /**
     * Add a check for update link on the plugins page. You can change the link with the supplied filter.
     * returning an empty string will disable this link
     *
     * @since 1.1.0
     * @param array  $links The array having default links for the plugin.
     * @param string $file The name of the plugin file.
     */
    public function check_for_update_link( $links, $file ) {
        // Only modify the plugin meta for our plugin.
        if ( stripos( $this->plugin_file, $file ) && current_user_can( 'update_plugins' ) ) {
            $update_link_url = wp_nonce_url(
                add_query_arg(
                    array(
                        'slswc_check_for_update' => 1,
                        'slswc_slug'             => $this->get_text_domain(),
                    ),
                    self_admin_url( 'plugins.php' )
                ),
                'slswc_check_for_update'
            );

            $update_link_text = apply_filters(
                'slswc_update_link_text_' . $this->get_text_domain(),
                __( 'Check for updates', 'slswcclient' )
            );

            if ( ! empty( $update_link_text ) ) {
                $links[] = sprintf( '<a href="%s">%s</a>', esc_attr( $update_link_url ), $update_link_text );
            }
        }

        return $links;
    }

    /**
     * Change update information
     *
     * @param object $transient The transient object.
     * @return object $transient The possibly modified transient object.
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function change_update_information( $transient ) {
        // If we are on the update core page, change the update message for unlicensed products
        global $pagenow;
        $update_core = ( 'update-core.php' === $pagenow ) ? true : false;

        if ( $update_core && $transient && isset( $transient->response ) && ! isset( $_GET['action'] ) ) {
            Helper::log( 'Change plugin update information. Current transient: ' . print_r( $transient, true ) );

            $notice_text = __(
                'To enable this update please activate your license in Settings > License Manager page.',
                'slswcclient'
            );

            if ( ! isset( $transient->response[ $this->plugin_dir_file ] ) ) {
                Helper::log( 'No update for ' . $this->plugin_dir_file );
                return $transient;
            }

            $plugin_response = $transient->response[ $this->plugin_dir_file ];

            $plugin_response->plugin = $this->plugin_dir_file;

            $plugin_has_update = isset( $plugin_response ) && isset( $plugin_response->package ) ? true : false;

            Helper::log( 'Plugin response: ' . print_r( $plugin_response, true ) );

            // $upgrade_notice = ( FALSE === stristr( $plugin_response->upgrade_notice, $notice_text ) );

            $has_upgrade_notice = isset( $plugin_response->upgrade_notice ) && ! empty( $plugin_response->upgrade_notice );

            if ( $plugin_has_update && '' === $plugin_response->package && ! $has_upgrade_notice ) {
                Helper::log( 'Update package: ' . $plugin_response->package . ', upgrade notice: ' . $notice_text );
                $message                         = '<div class="slswcclient-plugin-upgrade-notice">' . $notice_text . '</div>';
                $plugin_response->upgrade_notice = $message;
            }
        }

        return $transient;
    }

    /**
     * Add action for queued products to display message for unlicensed products.
     *
     * @param array  $plugin_data The update data.
     * @param object $update      The update object.
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function need_license_message( $plugin_data, $update ) {
        if ( ! empty( $update->package ) ) {
            return;
        }

        echo wp_kses_post(
            sprintf(
                '<div class="slswcclient-plugin-upgrade-notice">%s</div>',
                __( 'To enable this update please activate your license', 'slswcclient' )
            )
        );
    }
}
