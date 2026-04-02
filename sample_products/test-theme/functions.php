<?php
/**
 * Software License Server for WooCommerce
 *
 * @package Test_Theme
 * @author  License Server
 * @version 1.0.0
 * @since   1.0.0
 */

require_once __DIR__ . '/vendor/autoload.php';

use SLSWC\Client\Theme;

/**
 * Initialize License CLient
 *
 * @return void
 */
function theme_slswc_client() {

    $license_details = array(
        'license_key' => 'REPLACE_THIS_WITH_LICENSE_KEY',
        'domain'      => site_url(),
        'slug'        => 'test-theme',
    );

    $theme = Theme::get_instance(
        'http://example.com',
        WP_CONTENT_DIR . '/themes/test-theme',
        $license_details
    );

    $theme->init_hooks();
}

add_action( 'wp_loaded', 'theme_slswc_client', 11 );
