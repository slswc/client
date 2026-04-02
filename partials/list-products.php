<?php
/**
 * List of products.
 *
 * @package SLSWC Client
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use SLSWC\Client\Helper;

?>
<h2 class="screen-reader-text">
    <?php esc_html_e( 'Plugins List', 'slswc-client' ); ?>
</h2>
<div id="the-list">
    <?php foreach ( $products as $product ): ?>
        <?php

        $product = is_array( $product ) ? $product : (array) $product;

        if ( array_key_exists( $product['slug'], $remote_products ) ) {
            $product = Helper::recursive_parse_args( (array) $remote_products[ $product['slug'] ], $product );
        }

        $installed = file_exists( $product['file'] ) || is_dir( $product['file'] ) ? true : false;

        $name_version = esc_attr( $product['name'] ) . ' ' . esc_attr( $product['version'] );
        $action_class = $installed ? 'update' : 'install';
        $action_label = $installed ? __( 'Update Now', 'slswc-client' ) : __( 'Install Now', 'slswc-client' );

        $plugin_url = isset( $product['plugin_url'] ) ? $product['plugin_url'] : '';
        $author_uri = isset( $product['author_uri'] ) ? $product['author_uri'] : '';

        $manual_download_url = $plugin_url ? $plugin_url : $author_uri;

        do_action( 'slswc_before_products_list', $products );

        $thumb_class = 'theme' === $product['type'] ? 'appearance' : 'plugins';

        $information_url = admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $product['slug'] . '&section=changelog&TB_iframe=true&width=600&height=800' );
        ?>
    <div class="plugin-card plugin-card-<?php echo esc_attr( $product['slug'] ); ?>">
        <div class="plugin-card-top">
            <div class="name column-name">
                <h3>
                    <a href="<?php echo esc_url( $information_url ); ?>"
                        class="thickbox open-plugin-details-modal">
                        <?php echo esc_attr( $product['name'] ); ?>
                        <?php if ( '' === $product['thumbnail'] ): ?>
                            <i class="dashicons dashicons-admin-<?php echo esc_attr( $thumb_class ); ?> plugin-icon slswc-product-thumbnail"></i>
                        <?php else: ?>
                            <img src="<?php echo esc_url_raw( $product['thumbnail'] ); ?>" class="plugin-icon" alt="<?php echo esc_attr( $name_version ); ?>">
                        <?php endif; ?>
                    </a>
                </h3>
            </div>
            <div class="action-links">
                <ul class="plugin-action-buttons">
                    <li>
                        <?php if ( empty( $product['download_url'] ) ): ?>
                            <?php if ( '' !== $manual_download_url ): ?>
                                <a href="<?php echo esc_url( $manual_download_url ); ?>" target="__blank" class="button secondary">
                                    <?php esc_html_e( 'Download', 'slswc-client' ); ?>
                                </a>
                            <?php else: ?>
                                <?php esc_html_e( 'Download Not Available', 'slswc-client' ); ?>
                            <?php endif; ?>
                        <?php else: ?>
                        <a class="slswc-<?php echo esc_attr( $action_class ); ?>-now <?php echo esc_attr( $action_class ); ?>-now button aria-button-if-js"
                            data-download_url="<?php echo esc_url_raw( $product['download_url'] ); ?>"
                            data-slug="<?php echo esc_attr( $product['slug'] ); ?>"
                            href="#"
                            <?php // translators: %s - The license name and version. ?>
                            aria-label="<?php echo esc_attr( sprintf( __( 'Update %s now', 'slswc-client' ), esc_attr( $name_version ) ) ); ?>"
                            data-name="<?php echo esc_attr( $name_version ); ?>"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'slswc_client_install_' . $product['slug'] ) ); ?>"
                            role="button"
                            data-type="<?php echo esc_attr( $product['type'] ); ?>">
                            <?php echo esc_attr( $action_label ); ?>
                        </a>
                        <?php endif; ?>
                    </li>
                    <li>
                        <a href="<?php echo esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . $product['slug'] . '&TB_iframe=true&width=772&height=840' ) ); ?>"
                            class="thickbox open-plugin-details-modal"
                            <?php // translators: %s - Product name. ?>
                            aria-label="<?php echo esc_attr( sprintf( __( 'More information about %s', 'slswc-client' ), esc_attr( $name_version ) ) ); ?>"
                            data-title="<?php echo esc_attr( $name_version ); ?>">
                            <?php echo esc_attr( __( 'More Details', 'slswc-client' ) ); ?>
                        </a>
                    </li>
                </ul>
            </div>
            <div class="desc column-description">
                <p><?php echo esc_html( substr( $product['description'], 0, 110 ) ); ?></p>
                <p class="authors">
                    <cite>
                        <?php esc_html_e( 'By', 'slswc-client' ); ?>&nbsp;
                        <a href="<?php echo esc_url_raw( $product['author_uri'] ); ?>">
                            <?php echo esc_html( $product['author'] ); ?>
                        </a>
                    </cite>
                </p>
            </div>
        </div>
        <div class="plugin-card-bottom slswc-plugin-card-bottom">
            <?php if ( isset( $product['updated'] ) ): ?>              
            <div class="column-updated">
                <strong><?php esc_html_e( 'Last Updated:', 'slswc-client' ); ?>&nbsp;</strong>
                <?php echo esc_attr( human_time_diff( Helper::date_to_time( $product['updated'] ) ) ); ?> ago.
            </div>
            <?php endif; ?>
            <div class="column-compatibility">
                <?php $this->show_compatible( $product['compatible_to'] ); ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php do_action( 'slswc_after_list_products', $products ); ?>
</div>
