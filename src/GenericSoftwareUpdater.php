<?php
/**
 * Defines the abstract software updater class
 *
 * @version     1.0.2
 * @since       1.0.2
 * @package     Client
 * @link        https://licenseserver.io/
 */

namespace SLSWC\Client;

/**
 * Abstract software updater class
 *
 * @version 1.1.0
 * @since   1.1.0 - Refactored into classes and converted into a composer package.
 */
class GenericSoftwareUpdater {
    /**
     * The instance of this class.
     *
     * @var plugin
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public static $instance = null;

    /**
     * The instance of the ApiClient class.
     *
     * @var ApiClient
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $client;

    /**
     * The license details class.
     *
     * @var LicenseDetails
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $license;

    /**
     * License details
     *
     * @var array
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $license_details = array();

    /**
     * The license server url.
     *
     * @var [type]
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $license_server_url;

    /**
     * Get an instance of this class..
     *
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     * @version 1.1.0
     * @param   string $license_server_url - The base url to your WooCommerce shop.
     * @param   string $base_file          - path to the plugin file or directory, relative to the plugins directory.
     * @param   array  $args               - array of additional arguments to override default ones.
     */
    public function __construct( $license_server_url, $base_file, $args ) {
        $this->license_details_from_file_data( $args, $base_file );

        $this->client = ApiClient::get_instance(
            $this->license_server_url,
            $this->get_text_domain()
        );

        $this->license = new LicenseDetails(
            $license_server_url,
            $base_file,
            $this->get_license_details()
        );
    }

    /**
     * Get license details from plugin details.
     *
     * @param array  $file_data The plugin details.
     * @param string $base_file The base file.
     * @return array
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function license_details_from_file_data( $file_data, $base_file ) {
        $license_details = array(
            'license_key' => isset( $file_data['license_key'] ) ? esc_attr( $file_data['license_key'] ) : '',
            'text_domain' => isset( $file_data['text_domain'] ) ? esc_attr( $file_data['text_domain'] ) : basename( $base_file, '.php' ),
            'version'     => isset( $file_data['version'] ) ? esc_attr( $file_data['version'] ) : '0',
            'domain'      => isset( $file_data['domain'] ) ? esc_attr( $file_data['domain'] ) : home_url(),
        );

        $this->set_license_details( $license_details );

        return $license_details;
    }

    /**
     * Extra plugin headers.
     *
     * @param array $headers The array of headers.
     * @return array
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function extra_headers( $headers ) {
        return Helper::extra_headers( $headers );
    }

    /**
     * Getters
     *
     * Define getters to get the plugin version and slug.
     */

    /**
     * Get the plugin version.
     *
     * @return string
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function get_version() {
        return $this->license_details['version'];
    }

    /**
     * Get the text domain.
     *
     * @return string
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function get_text_domain() {
        return $this->license_details['text_domain'];
    }

    /**
     * Get domain.
     *
     * @return string $domain The domain.
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function get_domain() {
        return $this->license_details['domain'];
    }

    /**
     * Get license details.
     *
     * @return array $license_details The license details.
     * @version 1.0.0
     * @since   1.0.0
     */
    public function get_license_details() {
        return $this->license_details;
    }

    /**
     * Setters
     *
     * Define getters to get the plugin version and slug.
     */

    /**
     * Set the text domain.
     *
     * @param string $text_domain The text domain.
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function set_text_domain( $text_domain ) {
        $this->license_details['text_domain'] = $text_domain;
    }

    /**
     * Set the plugin version
     *
     * @param string $version The plugin version.
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function set_version( $version ) {
        $this->license_details['version'] = $version;
    }

    /**
     * Set the domain
     *
     * @param string $domain Set the domain.
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function set_domain( $domain ) {
        $this->license_details['domain'] = $domain;
    }

    /**
     * Set the license details.
     *
     * @param array $license_details The license details.
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function set_license_details( $license_details ) {
        $this->license_details = $license_details;
    }
}
