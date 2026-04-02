<?php
/**
 * The plugin bootstrap file.
 *
 * @version     1.0.0
 * @since       1.0.0
 * @package     SLSWC_Updater
 * @link        https://licenseserver.io/
 */

namespace SLSWC\Client\Updater;

/**
 * Plugin bootstrap class.
 *
 * @version 1.0.0
 * @since   1.0.0
 */
class PluginBootstrap {
    /**
     * Instance of the class.
     *
     * @var PluginBootstrap
     * @version 1.0.0
     * @since   1.0.0
     */
    public static $instance = null;

    /**
     * Get instance of the class.
     *
     * @return PluginBootstrap
     * @version 1.0.0
     * @since   1.0.0
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Construct instance of the class.
     *
     * @version 1.0.0
     * @since   1.0.0
     */
    private function __construct() {
    }

    /**
     * Run the plugin.
     *
     * @return void
     * @version 1.0.0
     * @since   1.0.0
     */
    public function run() {
        $this->load();
        $this->init_hooks();
    }

    /**
     * Load internal classes and dependencies.
     *
     * @return void
     * @version 1.0.0
     * @since   1.0.0
     */
    public function load() {
        require_once SLSWC_CLIENT_PATH . 'src/Updater/Updater.php';
    }

    /**
     * Initialize hooks.
     *
     * @return void
     * @version 1.0.0
     * @since   1.0.0
     */
    public function init_hooks() {
        add_action( 'init', array( $this, 'text_domain' ) );
        add_action( 'plugins_loaded', array( $this, 'updater' ) );
    }

    /**
     * Load the plugin text domain for translation.
     *
     * @since   1.0.0
     * @version 1.0.0
     */
    public function text_domain() {
        $locale = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();

        /**
         * Filter plugin locale.
         *
         * Allows to change the plugin locale.
         *
         * @version 1.0.0
         * @since   1.0.0
         *
         * @param string $locale The plugin locale.
         */
        $locale = apply_filters( 'plugin_locale', $locale, 'slswc-client' );

        // Place your custom translations into wp-content/languages/slswc to be upgrade safe.
        load_textdomain( 'slswc-client', trailingslashit( WP_LANG_DIR ) . 'slswc-client/slswc-client-' . $locale . '.mo' );

        // Load the plugins shipped language files.
        load_plugin_textdomain( 'slswc-client', false, SLSWC_CLIENT_PATH . '/languages/' );
    }

    /**
     * Function to initialize the updater.
     *
     * @return Updater|null
     * @version 1.0.0
     * @since   1.0.0
     */
    public function updater() {

        if ( ! is_admin() ) {
            return null;
        }

        $slswc_updater = Updater::get_instance( SLSWC_CLIENT_SERVER_URL );
        $slswc_updater->init_hooks();

        return $slswc_updater;
    }
}
