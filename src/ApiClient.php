<?php
/**
 * The ApiClient class
 *
 * @version     1.0.2
 * @since       1.0.2
 * @package     SLSWC_Client
 * @link        https://licenseserver.io/
 */

namespace SLSWC\Client;

use SLSWC\Client\Helper;

use WP_Error;

/**
 * Class to manage products relying on the Software License Server for WooCommerce.
 *
 * @since   1.1.0 - Refactored into classes and converted into a composer package.
 * @version 1.1.0
 */
class ApiClient {

    /**
     * Instance of this class.
     *
     * @var ApiClient
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public static $instance = null;

    /**
     * The plugin updater client
     *
     * @var ApiClient
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $client;

    /**
     * The url of the server where updates are requested from.
     *
     * @var string
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $license_server_url;

    /**
     * The text domain of the product using the client (used for local option keys and hooks only).
     *
     * @var string
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $text_domain;

    /**
     * Single instance of the plugin.
     *
     * @param string $license_server_url The license server URL.
     * @param string $text_domain        The product text domain.
     * @return ApiClient
     * @version 1.0.0
     * @since   1.0.0
     */
    public static function get_instance( $license_server_url, $text_domain ) {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self( $license_server_url, $text_domain );
        }

        return self::$instance;
    }

    /**
     * Construct a new instance of this class
     *
     * @param string $license_server_url The license server url.
     * @param string $text_domain        The software text domain.
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function __construct( $license_server_url, $text_domain ) {
        $this->license_server_url = $license_server_url;
        $this->text_domain        = $text_domain;
    }

    /**
     * Set the software text domain.
     *
     * @param string $text_domain The product text domain.
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function set_text_domain( $text_domain ) {
        $this->text_domain = $text_domain;
    }

    /**
     * Set the license server url.
     *
     * @param string $url The license server url.
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function set_license_server_url( $url ) {
        $this->license_server_url = $url;
    }

    /**
     * Connect to the api server using API keys
     *
     * @return  boolean
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     * @version 1.1.0
     */
    public function connect() {
        $keys       = $this->get_api_keys();
        $connection = $this->request( 'connect', $keys );

        Helper::log( 'Connecting...' );

        if ( $connection && $connection->connected && 'ok' === $connection->status ) {
            update_option( 'slswc_api_connected_' . esc_attr( $this->text_domain ), apply_filters( 'slswc_api_connected_' . esc_attr( $this->text_domain ), 'yes' ) );
            update_option( 'slswc_api_auth_user_' . esc_attr( $this->text_domain ), apply_filters( 'slswc_api_auth_user_' . esc_attr( $this->text_domain ), $connection->auth_user ) );

            return true;
        }

        return false;
    }

    /**
     * Get API Keys
     *
     * @return array
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function get_api_keys() {
        return array_filter(
            array(
                'username'        => get_option( 'slswc_api_username_' . esc_attr( $this->text_domain ), '' ),
                'consumer_key'    => get_option( 'slswc_consumer_key_' . esc_attr( $this->text_domain ), '' ),
                'consumer_secret' => get_option( 'slswc_consumer_secret_' . esc_attr( $this->text_domain ), '' ),
            )
        );
    }

    /**
     * Save API Keys
     *
     * @param string $username The API username.
     * @param string $consumer_key The consumer key.
     * @param string $consumer_secret The consumer secret.
     * @return boolean
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function save_api_keys( $username, $consumer_key, $consumer_secret ) {
        $keys = array(
            'username'        => $username,
            'consumer_key'    => $consumer_key,
            'consumer_secret' => $consumer_secret,
        );
        return update_option(
            'slswc_api_keys_' . esc_attr( $this->text_domain ),
            $keys
        );
    }

    /**
     * Send a request to the server.
     *
     * @param   string $action activate|deactivate|check_update.
     * @param   array  $request_info The data to be sent to the server.

     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     * @version 1.1.0
     *
     * @return object The response from the server.
     */
    public function request( $action = 'check_update', $request_info = array() ) {
        $domain = $this->license_server_url;

        // Allow filtering the request info for plugins.
        $request_info = apply_filters( 'slswc_request_info_' . $this->text_domain, $request_info );

        // Build the server url api end point fix url build to support the WordPress API.
        $server_request_url = esc_url_raw( $domain . 'wp-json/slswc/v1/' . $action . '?' . http_build_query( $request_info ) );

        // Options to parse the wp_safe_remote_get() call.
        $request_options = array( 'timeout' => 30 );

        // Allow filtering the request options.
        $request_options = apply_filters( 'slswc_request_options_' . $this->text_domain, $request_options );

        // Query the license server.
        $endpoint_get_actions = apply_filters( 'slswc_client_get_actions', array( 'product', 'products' ) );
        if ( in_array( $action, $endpoint_get_actions, true ) ) {
            $response = wp_safe_remote_get( $server_request_url, $request_options );
        } else {
            $response = wp_safe_remote_post( $server_request_url, $request_options );
        }

        // Validate that the response is valid not what the response is.
        $result = $this->validate_response( $response );

        // Check if there is an error and display it if there is one, otherwise process the response.
        if ( ! is_wp_error( $result ) ) {
            $response_body = json_decode( wp_remote_retrieve_body( $response ) );

            // Check the status of the response.
            $continue = $this->check_response_status( $response_body );

            if ( $continue ) {
                return $response_body;
            }
        }

        Helper::log( 'There was an error executing this request, please check the errors below.' );
		// phpcs:disable
		Helper::log( 'The response body: ' . print_r( wp_remote_retrieve_body( $response ), true ) );
		// phpcs:enable

        // Return null to halt the execution.
        return (object) array(
            'status'   => is_wp_error( $response ) ? $response->get_error_code() : $response['response']['code'],
            'response' => is_wp_error( $response ) ? $response->get_error_message() : $response['response']['message'],
        );
    }

    /**
     * Validate the license server response to ensure its valid response not what the response is.
     *
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     * @version 1.1.0
     * @param WP_Error|array $response The response or WP_Error.
     */
    public function validate_response( $response ) {
        if ( ! empty( $response ) ) {

            // Can't talk to the server at all, output the error.
            if ( is_wp_error( $response ) ) {
                return new WP_Error(
                    $response->get_error_code(),
                    sprintf(
                        // translators: 1. Error message.
                        __( 'HTTP Error: %s', 'slswcclient' ),
                        $response->get_error_message()
                    )
                );
            }

            // There was a problem with the initial request.
            if ( ! isset( $response['response']['code'] ) ) {
                return new WP_Error(
                    'slswc_no_response_code',
                    __( 'wp_safe_remote_get() returned an unexpected result.', 'slswcclient' )
                );
            }

            // There is a validation error on the server side, output the problem.
            if ( 400 === $response['response']['code'] ) {
                $body = json_decode( $response['body'] );

                $response_message = '';

                foreach ( $body->data->params as $param => $message ) {
                    $response_message .= $message;
                }

                return new WP_Error(
                    'slswc_validation_failed',
                    sprintf(
                        // translators: %s: Error/response message.
                        __( 'There was a problem with your license: %s', 'slswcclient' ),
                        $response_message
                    )
                );
            }

            // The server is broken.
            if ( 500 === $response['response']['code'] ) {
                return new WP_Error(
                    'slswc_internal_server_error',
                    sprintf(
                        // translators: %s: the http response code from the server.
                        __( 'There was a problem with the license server: HTTP response code is : %s', 'slswcclient' ),
                        $response['response']['code']
                    )
                );
            }

            if ( 200 !== $response['response']['code'] ) {
                return new WP_Error(
                    'slswc_unexpected_response_code',
                    sprintf(
                        __( 'HTTP response code is : % s, expecting ( 200 )', 'slswcclient' ),
                        $response['response']['code']
                    )
                );
            }

            if ( empty( $response['body'] ) ) {
                return new WP_Error(
                    'slswc_no_response',
                    __( 'The server returned no response.', 'slswcclient' )
                );
            }

            return true;
        }
    }

    /**
     * Validate the license server response to ensure its valid response not what the response is.
     *
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     * @version 1.1.0
     * @param   object $response_body The data returned.
     */
    public function check_response_status( $response_body ) {
        Helper::log( 'Check response' );
        Helper::log( $response_body );

        if ( is_object( $response_body ) && ! empty( $response_body ) ) {
            $license_status_types = Helper::license_status_types();
            $status               = $response_body->status;

            return ( array_key_exists( $status, $license_status_types ) || 'ok' === $status ) ? true : false;
        }

        return false;
    }
}
