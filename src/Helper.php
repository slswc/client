<?php
/**
 * Defines the helper class for client
 *
 * @version     1.0.0
 * @since       1.0.0
 * @package     Client
 * @link        https://licenseserver.io/
 */

namespace SLSWC\Client;

use Exception;
use WC_DateTime;
use DateTimeZone;

/**
 * Helper class with static helper methods
 *
 * @version 1.0.0
 * @since   1.0.0
 */
class Helper {
    /**
     * Get file information
     *
     * @param string $base_file     The base file.
     * @param array  $args          Default details
     * @param string $software_type The type of software. plugin|theme
     * @return array
     * @version 1.0.0
     * @since   1.0.0
     */
    public static function get_file_details( $base_file, $args = array(), $software_type = 'plugin' ) {
        return self::recursive_parse_args(
            $args,
            self::get_file_information( $base_file, $software_type )
        );
    }

    /**
     * Get theme or plugin information from file.
     *
     * @param   string $base_file - Plugin file or theme slug.
     * @param   string $type - Product type. plugin|theme.
     * @return  array
     * @since   1.0.0
     * @version 1.0.0
     */
    public static function get_file_information( $base_file, $type = 'plugin' ) {
        static $cache = array();

        $cache_key = $type . '::' . $base_file;

        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        $data = array();
        if ( 'plugin' === $type ) {
            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $plugin = get_plugin_data( $base_file, false );

            $data = self::format_plugin_data( $plugin, $base_file );
        } elseif ( 'theme' === $type ) {
            if ( ! function_exists( 'wp_get_theme' ) ) {
                require_once ABSPATH . 'wp-includes/theme.php';
            }
            $theme = wp_get_theme( basename( $base_file ) );

            $data = self::format_theme_data( $theme, $base_file );
        }

        $cache[ $cache_key ] = $data;

        return $data;
    }

    /**
     * Format plugin data
     *
     * @param array  $data The data to format.
     * @param string $file The plugin file.
     * @return array
     * @version 1.0.0
     * @since   1.0.0
     */
    public static function format_plugin_data( $data, $file = '' ) {
        $formatted_data = array(
            'name'              => $data['Name'],
            'title'             => $data['Title'],
            'description'       => $data['Description'],
            'author'            => $data['Author'],
            'author_uri'        => $data['AuthorURI'],
            'version'           => $data['Version'],
            'plugin_url'        => $data['PluginURI'],
            'text_domain'       => $data['TextDomain'],
            'domain_path'       => $data['DomainPath'],
            'network'           => $data['Network'],
            'requires_wp'       => ! empty( $data['RequiresWP'] ) ? $data['RequiresWP'] : '',

            // SLSWC Headers.
            'slswc'             => ! empty( $data['SLSWC'] ) ? $data['SLSWC'] : '',
            'updated'           => ! empty( $data['SLSWC Updated'] ) ? $data['SLSWC Updated'] : '',
            'compatible_to'     => ! empty( $data['SLSWC Compatible To'] ) ? $data['SLSWC Compatible To'] : '',
            'documentation_url' => ! empty( $data['SLSWC Documentation URL'] ) ? $data['SLSWC Documentation URL'] : '',
            'type'              => 'plugin',
        );

        if ( '' !== $file ) {
            $formatted_data['file'] = WP_CONTENT_DIR . "/plugins/{$file}";
        }

        return apply_filters( 'slswc_client_formatted_plugin_data', $formatted_data, $data, $file );
    }

    /**
     * Map a remote product with local product properties.
     *
     * @param array $local Local product data.
     * @param array $remote Remote product data.
     * @return array
     * @version 1.0.0
     * @since   1.0.0
     */
    public static function map_remote_product( $local, $remote ) {
        $remote = (array) $remote;

        foreach ( $remote as $key => $value ) {
            if ( array_key_exists( $key, $local ) ) {
                $local[ $key ] = $remote[ $key ];
            }
        }

        $local['download_url'] = $remote['update_file']->file;

        return apply_filters( 'slswc_client_mapped_remote_product', $local, $remote );
    }

    /**
     * Format theme data
     *
     * @param object $theme The theme object.
     * @param string $theme_file The theme file.
     * @return array
     * @version 1.0.0
     * @since   1.0.0
     */
    public static function format_theme_data( $theme, $theme_file ) {
        $formatted_data = array(
            'file'              => WP_CONTENT_DIR . "/themes/{$theme_file}",
            'name'              => $theme->get( 'Name' ),
            'theme_url'         => $theme->get( 'ThemeURI' ),
            'description'       => $theme->get( 'Description' ),
            'author'            => $theme->get( 'Author' ),
            'author_uri'        => $theme->get( 'AuthorURI' ),
            'version'           => $theme->get( 'Version' ),
            'template'          => $theme->get( 'Template' ),
            'status'            => $theme->get( 'Status' ),
            'tags'              => $theme->get( 'Tags' ),
            'text_domain'       => $theme->get( 'TextDomain' ),
            'domain_path'       => $theme->get( 'DomainPath' ),
            'requires_wp'       => ! empty( $theme->get( 'RequiresWP' ) )
                ? $theme->get( 'RequiresWP' ) : '',
            // SLSWC Headers.
            'slswc'             => ! empty( $theme->get( 'SLSWC' ) ) ? $theme->get( 'SLSWC' ) : '',
            'updated'           => ! empty( $theme->get( 'SLSWCUpdated' ) ) ? $theme->get( 'SLSWCUpdated' ) : '',
            'compatible_to'     => ! empty( $theme->get( 'SLSWCCompatibleTo' ) )
                ? $theme->get( 'SLSWCCompatibleTo' )
                : '',
            'documentation_url' => ! empty( $theme->get( 'SLSWCDocumentationURL' ) )
                ? $theme->get( 'SLSWCDocumentationURL' )
                : '',
            'type'              => 'theme',
        );

        return apply_filters( 'slswc_client_formatted_theme_data', $formatted_data, $theme, $theme_file );
    }

    /**
     * Recursively merge two arrays.
     *
     * @param  array $args User defined args.
     * @param  array $defaults Default args.
     * @return array $new_args The two array merged into one.
     */
    public static function recursive_parse_args( $args, $defaults ) {
        $args     = (array) $args;
        $new_args = (array) $defaults;
        foreach ( $args as $key => $value ) {
            if ( is_array( $value ) && isset( $new_args[ $key ] ) ) {
                $new_args[ $key ] = self::recursive_parse_args( $value, $new_args[ $key ] );
            } else {
                $new_args[ $key ] = $value;
            }
        }
        return $new_args;
    }

    /**
     * Install a product.
     *
     * @param string $slug    Product slug.
     * @param string $download_url The product download url.
     *
     * @since   1.0.0
     * @version 1.0.0
     */
    public static function product_background_installer( $slug = '', $download_url = '' ) {
        global $wp_filesystem;

        $slug = isset( $_REQUEST['text_domain'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['text_domain'] ) ) : '';
        if ( ! array_key_exists( 'nonce', $_REQUEST )
            || ! empty( $_REQUEST ) && array_key_exists( 'nonce', $_REQUEST )
            && isset( $_REQUEST ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ), 'slswc_client_install_' . $slug ) ) {
            wp_send_json_error(
                array(
                    'message' => esc_attr__( 'Failed to install product. Security token invalid.', 'slswc-client' ),
                )
            );
        }

        $download_link = isset( $_POST['package'] ) ? esc_url_raw( wp_unslash( $_POST['package'] ) ) : '';
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
        }

        wp_send_json(
            array(
                'message' => __( 'Failed to install product. Download link not provided or is invalid.', 'slswc-client' ),
            )
        );
    }

    /**
     * The available license status types
     *
     * @since   1.0.0
     * @version 1.0.0
     */
    public static function license_status_types() {
        return apply_filters(
            'slswc_license_status_types',
            array(
                'valid'           => __( 'Valid', 'slswc-client' ),
                'deactivated'     => __( 'Deactivated', 'slswc-client' ),
                'max_activations' => __( 'Max Activations reached', 'slswc-client' ),
                'invalid'         => __( 'Invalid', 'slswc-client' ),
                'inactive'        => __( 'Inactive', 'slswc-client' ),
                'active'          => __( 'Active', 'slswc-client' ),
                'expiring'        => __( 'Expiring', 'slswc-client' ),
                'expired'         => __( 'Expired', 'slswc-client' ),
                'disabled'        => __( 'Disabled', 'slswc-client' ),
                'failed'          => __( 'Failed', 'slswc-client' ),
            )
        );
    }

    /**
     * Class logger so that we can keep our debug and logging information cleaner
     *
     * @version 1.0.0
     * @since   1.0.0
     * @param mixed $data - The data to go to the error log.
     */
    public static function log( $data ) {
        $logging_enabled = defined( 'SLSWC_CLIENT_LOGGING' ) && SLSWC_CLIENT_LOGGING ? true : false;
        if ( ! apply_filters( 'slswc_client_logging', $logging_enabled ) ) {
            return;
        }
        //phpcs:disable
        if ( is_array( $data ) || is_object( $data ) ) {
            error_log( __CLASS__ . ' : ' . print_r( $data, true ) );
        } else {
            error_log( __CLASS__ . ' : ' . $data );
        }
        //phpcs:enable
    }

    /**
     * Add extra theme headers.
     *
     * @param   array $headers The extra theme/plugin headers.
     * @return  array
     * @since   1.0.0
     * @version 1.0.0
     */
    public static function extra_headers( $headers ) {

        if ( ! in_array( 'SLSWC', $headers, true ) ) {
            $headers[] = 'SLSWC';
        }

        if ( ! in_array( 'SLSWC Updated', $headers, true ) ) {
            $headers[] = 'SLSWC Updated';
        }

        if ( ! in_array( 'Author', $headers, true ) ) {
            $headers[] = 'Author';
        }

        if ( ! in_array( 'Requires at least', $headers, true ) ) {
            $headers[] = 'Requires at least';
        }

        if ( ! in_array( 'SLSWC Compatible To', $headers, true ) ) {
            $headers[] = 'SLSWC Compatible To';
        }

        if ( ! in_array( 'SLSWC Documentation URL', $headers, true ) ) {
            $headers[] = 'SLSWC Documentation URL';
        }

        return $headers;
    }

    /**
     * Convert a date string to time
     *
     * @param string $date_string The date string.
     * @return int $timestamp The timestamp of the given date string.
     * @version 1.0.0
     */
    public static function date_to_time( $date_string ) {

        if ( empty( $date_string ) ) {
            return 0;
        }

        try {
            $date_time = class_exists( 'WC_DateTime' )
                ? new WC_DateTime( $date_string, new DateTimeZone( 'UTC' ) )
                : new \DateTime( $date_string, new DateTimeZone( 'UTC' ) );

            return intval( $date_time->getTimestamp() );
        } catch ( Exception $e ) {
            return 0;
        }
    }
}
