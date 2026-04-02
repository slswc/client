<?php
/**
 * Software License Server for WooCommerce
 *
 * @package Test_Plugin
 * @author  License Server
 * @version 1.0.0
 * @since   1.0.0
 */

/**
 * Plugin Name:       Test Plugin
 * Plugin URI:        https://testplugin.com/plugins
 * Description:       Basic WordPress plugin to test Software License Server for WooCommerce
 * Text Domain:       test-plugin
 * Author URI:        https://licenseserver.io
 * License:           https://www.gnu.org/licenses/gpl-2.0.html
 * Version:           1.0.0
 * Author:            License Server
 * Domain Path:       /languages
 * Requires at least: 5.7
 *
 * SLSWC:                   plugin
 * SLSWC Slug:              test-plugin
 * SLSWC Documentation URL: https://www.gnu.org/licenses/gpl-2.0.html
 * SLSWC Compatible To:     5.8.1
 * SLSWC Updated:           1/2/2023
 */

require_once __DIR__ . '/vendor/autoload.php';

use SLSWC\Client\Helper;
use SLSWC\Client\Plugin;

/**
 * Initialize license server client.
 *
 * @return Plugin
 * @version 1.0.0
 * @since   1.0.0
 */
function your_prefix_slswc_client() {

    $license_details = array(
        'license_key' => 'REPLACE_THIS_WITH_LICENSE_KEY',
        'domain'      => site_url(),
        'slug'        => 'test-plugin',
    );

    return Plugin::get_instance( 'http://example.com/', __FILE__, $license_details );
}
add_action( 'plugins_loaded', 'slswc_client', 11 );

function activate_plugin_license() {
    $plugin = your_prefix_slswc_client();

    // Example of how to update the plugin. Run this on a hook.
    if ( $plugin->license->get_license_status() !== 'active' ) {
        $plugin->license->validate_license();
    }
}

add_action( 'init', 'activate_plugin_license' );

/**
 * Extra plugin headers
 */
add_filter( 'extra_plugin_headers', 'slswc_client_extra_headers' );
add_filter( 'extra_theme_headers', 'slswc_client_extra_headers' );

if ( ! function_exists( 'slswc_client_extra_headers' ) ) {
    /**
     * Extra theme and plugin headers.
     *
     * @param array $headers The current WordPress plugin and theme headers.
     * @return array $headers The modified headers.
     * @version 1.1.0
     * @since   1.1.0
     */
    function slswc_client_extra_headers( $headers ) {
        return Helper::extra_headers( $headers );
    }
}
