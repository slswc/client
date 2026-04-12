<?php
/**
 * Define the SoftwareUpdateInterface
 *
 * @version 1.0.0
 * @since   1.0.0
 * @package Slswc_Client
 */

namespace SLSWC\Client;

/**
 * Software Updater Interface
 *
 * @version 1.0.0
 * @since   1.0.0
 */
interface SoftwareUpdaterInterface {
    /**
     * Get an instance of this class..
     *
     * @since   1.0.0
     * @version 1.0.0
     * @param   string $license_server_url - The base url to your WooCommerce shop.
     * @param   string $base_file          - path to the plugin file or directory, relative to the plugins directory.
     * @param   array  $args               - array of additional arguments to override default ones.
     */
    public static function get_instance( $license_server_url, $base_file, $args );

    /**
     * Get license details.
     *
     * @return array
     * @version 1.0.0
     * @since   1.0.0
     */
    public function get_license_details();

    /**
     * Initialize actions and filters.
     *
     * @return void
     * @version 1.0.0
     * @since   1.0.0
     */
    public function init_hooks();

    /**
     * Check if there are updates for themes.
     *
     * @param   mixed $transient transient object from update api.
     * @return  mixed $transient transient object from update api.
     * @since   1.0.0
     * @version 1.0.0
     */
    public function update_check( $transient );
}
