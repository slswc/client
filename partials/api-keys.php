<?php
/**
 * Show the api keys settings page
 *
 * @version     1.0.0
 * @package SLSWC_Updater/Partials
 */

use SLSWC\Client\Helper;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<h2><?php esc_html_e( 'Downloads API Settings', 'slswc-client' ); ?></h2>
<?php if ( empty( $keys ) && ! Helper::is_connected() ): ?>
    <?php
    $username        = isset( $keys['username'] ) ? $keys['username'] : '';
    $consumer_key    = isset( $keys['consumer_key'] ) ? $keys['consumer_key'] : '';
    $consumer_secret = isset( $keys['consumer_secret'] ) ? $keys['consumer_secret'] : '';
    ?>
<p>
    <?php esc_attr_e( 'The Downloads API allows you to install plugins directly from the Updates Server into your website instead of downloading and uploading manually.', 'slswc-client' ); ?>
</p>
<p class="about-text">
    <?php esc_attr_e( 'Enter API details then save to proceed to the next step to connect', 'slswc-client' ); ?>
</p>
<form name="api-keys" method="post" action="">
    <?php wp_nonce_field( 'save_api_keys', 'save_api_keys_nonce' ); ?>
    <input type="hidden" name="save_api_keys_check" value="1" />
    <table class="form-table">
        <tbody>
            <tr>
                <th><?php esc_html_e( 'Username', 'slswc-client' ); ?></th>
                <td>
                    <input type="text"
                            name="username"
                            value="<?php echo esc_attr( $username ); ?>"
                    />
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Consumer Key', 'slswc-client' ); ?></th>
                <td>
                    <input
                        type="password"
                        name="consumer_key"
                        value="<?php echo esc_attr( $consumer_key ); ?>"
                    />
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Consumer Secret', 'slswc' ); ?></th>
                <td>
                    <input
                        type="password"
                        name="consumer_secret"
                        value="<?php echo esc_attr( $consumer_secret ); ?>"
                    />
                </td>
            </tr>
            <tfoot>
                <tr>
                    <th></th>
                    <td>
                        <input type="submit"
                                id="save-api-keys"
                                class="button button-primary"
                                value="Save API Keys"
                        />
                    </td>
                </tr>
            </tfoot>
        </tbody>
    </table>
</form>
<?php elseif ( ! empty( $keys ) && ! Helper::is_connected() ): ?>
    <form name="connect" method="post" action="">
        <?php wp_nonce_field( 'connect', 'connect_nonce' ); ?>
        <p><?php esc_html_e( 'Click on the button to connect your account now.', 'slswc-client' ); ?></p>
        <input type="submit"
            id="connect"
            class="button button-primary"
            value="<?php esc_attr_e( 'Connect Account Now', 'slswc-client' ); ?>"
        />
    </form>

    <form name="reset_api_settings" method="post" action="">
        <?php wp_nonce_field( 'reset_api_settings', 'reset_api_settings_nonce' ); ?>
        <p></p>
        <input type="submit"
                id="reset_api_settings"
                class="button"
                value="<?php esc_attr_e( 'Reset API Keys', 'slswc-client' ); ?>"
        />
    </form>

<?php else: ?>
    <p><?php esc_html_e( 'Your account is connected.', 'slswc-client' ); ?></p>
    <p><?php esc_html_e( 'You should be able to see a list of your purchased products and get convenient automatic updates.', 'slswc-client' ); ?></p>
    <form name="disconnect" method="post" action="">
        <?php wp_nonce_field( 'disconnect', 'disconnect_nonce' ); ?>
        <input
            type="submit"
            id="disconnect"
            class="button button-primary"
            value="<?php esc_attr_e( 'Disconnect', 'slswc-client' ); ?>"
        />
    </form>
<?php endif; ?>
