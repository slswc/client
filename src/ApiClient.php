<?php
/**
 * The ApiClient class
 *
 * @version     1.0.0
 * @since       1.0.0
 * @package     SLSWC\Client
 * @link        https://licenseserver.io/
 */

namespace SLSWC\Client;

use SLSWC\Client\Helper;

use WP_Error;

/**
 * Class to manage products relying on the Software License Server for WooCommerce.
 *
 * @since   1.0.0
 * @version 1.0.0
 */
class ApiClient {

    /**
     * Per-consumer instance registry keyed by `text_domain`.
     *
     * Each SDK consumer gets its own ApiClient so request signing,
     * `text_domain` headers, and per-product log lines stay distinct
     * when multiple plugins or themes run on the same site. A shared
     * singleton would silently route the second consumer's API calls
     * through the FIRST consumer's text_domain, contaminating license
     * checks across products.
     *
     * @var array<string, ApiClient>
     * @since 1.0.0
     */
    private static $instances = array();

    /**
     * The plugin updater client
     *
     * @var ApiClient
     * @version 1.0.0
     * @since   1.0.0
     */
    public $client;

    /**
     * The url of the server where updates are requested from.
     *
     * @var string
     * @version 1.0.0
     * @since   1.0.0
     */
    public $license_server_url;

    /**
     * The text domain of the product using the client (used for local option keys and hooks only).
     *
     * @var string
     * @version 1.0.0
     * @since   1.0.0
     */
    public $text_domain;

    /**
     * Get the ApiClient instance for a specific consumer.
     *
     * Keyed by `$text_domain` so each consuming plugin or theme gets
     * its own ApiClient — distinct API request signing, distinct
     * `text_domain` header on outbound calls, distinct log lines.
     * Sharing a singleton would route the second consumer's API calls
     * through the first consumer's text_domain, contaminating license
     * checks across products.
     *
     * @since   1.0.0
     * @version 1.0.0
     *
     * @param string $license_server_url The license server URL.
     * @param string $text_domain        The product text domain.
     *
     * @return ApiClient
     */
    public static function get_instance( $license_server_url, $text_domain ) {
        $key = (string) $text_domain;

        if ( '' === $key ) {
            // Empty text_domain would collapse every consumer onto the same
            // registry slot, route their API calls under no identifier, and
            // (since LicenseDetails sanitizes text_domain into its option
            // key) write to a collision-prone `_license_details` row.
            throw new \InvalidArgumentException( '$text_domain is required and must be a non-empty string.' );
        }

        if ( isset( self::$instances[ $key ] ) ) {
            // Two consumers that legitimately share a text_domain (e.g. a
            // plugin and a tightly-coupled add-on that intentionally pool
            // their license) must agree on the server URL. Surface a warning
            // when they don't so cross-server contamination doesn't ship
            // silently.
            $cached_url = self::$instances[ $key ]->license_server_url;
            if ( $cached_url !== $license_server_url ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(
                    sprintf(
                        '[SLSWC Client] ApiClient::get_instance called for text_domain=%s with license_server_url=%s but a prior call cached %s — using the cached instance.',
                        $key,
                        $license_server_url,
                        $cached_url
                    )
                );
            }
            return self::$instances[ $key ];
        }

        self::$instances[ $key ] = new self( $license_server_url, $text_domain );
        return self::$instances[ $key ];
    }

    /**
     * Construct a new instance of this class
     *
     * @param string $license_server_url The license server url.
     * @param string $text_domain        The software text domain.
     * @version 1.0.0
     * @since   1.0.0
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
     * @version 1.0.0
     * @since   1.0.0
     */
    public function set_text_domain( $text_domain ) {
        $this->text_domain = $text_domain;
    }

    /**
     * Set the slug (deprecated).
     *
     * @deprecated 1.0.0 Use set_text_domain() instead.
     * @param string $slug The product slug.
     * @return void
     */
    public function set_slug( $slug ) {
        $this->set_text_domain( $slug );
    }

    /**
     * Set the license server url.
     *
     * @param string $url The license server url.
     * @return void
     * @version 1.0.0
     * @since   1.0.0
     */
    public function set_license_server_url( $url ) {
        $this->license_server_url = $url;
    }

    /**
     * Connect to the api server using API keys
     *
     * @return  boolean
     * @since   1.0.0
     * @version 1.0.0
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
     * @version 1.0.0
     * @since   1.0.0
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
     * @version 1.0.0
     * @since   1.0.0
     */
    public function save_api_keys( $username, $consumer_key, $consumer_secret ) {
        $td = esc_attr( $this->text_domain );
        update_option( 'slswc_api_username_' . $td, sanitize_text_field( $username ) );
        update_option( 'slswc_consumer_key_' . $td, sanitize_text_field( $consumer_key ) );
        update_option( 'slswc_consumer_secret_' . $td, sanitize_text_field( $consumer_secret ) );
        return true;
    }

    /**
     * Send a request to the server.
     *
     * On failure, the returned stub's `response` field carries the most
     * specific human-readable message available — typically the validation
     * error returned by the SLSWC server (e.g. "License Key not found"),
     * not the generic HTTP status text ("Bad Request"). Falling back to
     * raw HTTP status text wiped out the helpful error every consumer
     * needed to surface in its UI.
     *
     * @since   1.0.0 Surface the validate_response() / response-body message
     *                in the failure stub so callers can display a useful
     *                error rather than the bare HTTP status text.
     *
     * @param   string $action       activate|deactivate|check_update.
     * @param   array  $request_info The data to be sent to the server.
     *
     * @return  object The response from the server.
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
        $endpoint_get_actions = apply_filters( 'slswc_client_get_actions', array( 'product', 'products', 'info' ) );
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

        return (object) array(
            'status'   => is_wp_error( $response ) ? $response->get_error_code() : $response['response']['code'],
            'response' => $this->extract_error_message( $response, $result ),
        );
    }

    /**
     * Extract the most informative error message from a failed remote request.
     *
     * Prefers, in order:
     *
     * 1. The WP_Error message produced by validate_response() (it already
     *    knows how to unwrap server-side validation `data.params` payloads
     *    and 500-level "license server is broken" responses).
     * 2. The transport-level WP_Error message (DNS, timeout, SSL).
     * 3. The decoded JSON body's `message` field (REST `WP_Error::message`).
     * 4. The raw HTTP status text as a last resort.
     *
     * @since   1.0.0
     *
     * @param   array|\WP_Error $response Original wp_remote_* response.
     * @param   true|\WP_Error  $result   Outcome of {@see self::validate_response()}.
     *
     * @return  string
     */
    private function extract_error_message( $response, $result ) {
        if ( is_wp_error( $result ) ) {
            $message = (string) $result->get_error_message();
            if ( '' !== $message ) {
                return $message;
            }
        }

        if ( is_wp_error( $response ) ) {
            return (string) $response->get_error_message();
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        if ( is_object( $body ) && ! empty( $body->message ) ) {
            return (string) $body->message;
        }

        return isset( $response['response']['message'] ) ? (string) $response['response']['message'] : '';
    }

    /**
     * Validate the license server response to ensure its valid response not what the response is.
     *
     * @since   1.0.0
     * @version 1.0.0
     * @param WP_Error|array $response The response or WP_Error.
     */
    public function validate_response( $response ) {
        if ( ! empty( $response ) ) {

            // Can't talk to the server at all, output the error.
            if ( is_wp_error( $response ) ) {
                return new WP_Error(
                    $response->get_error_code(),
                    sprintf(
                        // translators: 1. Product slug/name, 2. Error message.
                        __( 'HTTP Error for %1$s: %2$s', 'slswc-client' ),
                        $this->text_domain,
                        $response->get_error_message()
                    )
                );
            }

            // There was a problem with the initial request.
            if ( ! isset( $response['response']['code'] ) ) {
                return new WP_Error(
                    'slswc_no_response_code',
                    __( 'wp_safe_remote_get() returned an unexpected result.', 'slswc-client' )
                );
            }

            // There is a validation error on the server side, output the problem.
            if ( 400 === $response['response']['code'] ) {
                $body = json_decode( $response['body'] );

                $response_message = '';

                if ( isset( $body->data->params ) ) {
                    foreach ( $body->data->params as $param => $message ) {
                        $response_message .= $message;
                    }
                } elseif ( isset( $body->message ) ) {
                    $response_message = $body->message;
                }

                return new WP_Error(
                    'slswc_validation_failed',
                    sprintf(
                        // translators: 1. Product slug/name, 2. Error/response message.
                        __( 'There was a problem with your %1$s license: %2$s', 'slswc-client' ),
                        $this->text_domain,
                        $response_message
                    )
                );
            }

            // The server is broken.
            if ( 500 === $response['response']['code'] ) {
                return new WP_Error(
                    'slswc_internal_server_error',
                    sprintf(
                        // translators: 1. Product slug/name, 2. HTTP response code.
                        __( 'There was a problem with the license server for %1$s: HTTP response code %2$s', 'slswc-client' ),
                        $this->text_domain,
                        $response['response']['code']
                    )
                );
            }

            if ( 200 !== $response['response']['code'] ) {
                return new WP_Error(
                    'slswc_unexpected_response_code',
                    sprintf(
                        // translators: 1. HTTP response code, 2. Product slug/name.
                        __( 'Unexpected HTTP response code %1$s for %2$s, expected 200.', 'slswc-client' ),
                        $response['response']['code'],
                        $this->text_domain
                    )
                );
            }

            if ( empty( $response['body'] ) ) {
                return new WP_Error(
                    'slswc_no_response',
                    __( 'The server returned no response.', 'slswc-client' )
                );
            }

            return true;
        }
    }

    /**
     * Validate the license server response to ensure its valid response not what the response is.
     *
     * @since   1.0.0
     * @version 1.0.0
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
