<?php
/**
 * Define the license details class
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @package SLSWC_CLient
 */

namespace SLSWC\Client;

/**
 * License details class.
 *
 * @version 1.0.0
 * @since   1.0.0
 */
class LicenseDetails {
    /**
     * License server URL
     *
     * @var string
     * @version 1.0.0
     * @since   1.0.0
     */
    public $license_server_url;

    /**
     * The option key for saving license details.
     *
     * @var string
     * @version 1.0.0
     * @since   1.0.0
     */
    public $option_name = '';

    /**
     * The Client object.
     *
     * @var ApiClient
     * @version 1.0.0
     * @since   1.0.0
     */
    public $client;

    /**
     * Plugin file
     *
     * @var string
     * @version 1.0.0
     * @since   1.0.0
     */
    public $plugin_file;

    /**
     * The license details
     *
     * @var array
     * @version 1.0.0
     * @since   1.0.0
     */
    public $license_details = array();

    /**
     * Construct the instance of the class
     *
     * @param string $license_server_url The license server url.
     * @param string $plugin_file        The plugin file.
     * @param array  $license_details    The plugin details.
     * @version 1.0.0
     * @since   1.0.0
     */
    public function __construct( $license_server_url, $plugin_file, $license_details = array() ) {
        $this->plugin_file        = $plugin_file;
        $this->license_server_url = $license_server_url;

        $license_details = Helper::recursive_parse_args(
            $license_details,
            $this->get_default_license_details()
        );

        $text_domain = isset( $license_details['text_domain'] ) ? $license_details['text_domain'] : basename( $plugin_file, '.php' );
        $this->set_option_name( sanitize_key( $text_domain ) . '_license_details' );

        $saved_license_details = get_option( $this->get_option_name(), $license_details );

        $license_details = Helper::recursive_parse_args(
            $saved_license_details,
            $license_details
        );

        $this->set_license_details( $license_details );

        Helper::log( 'LicenseDetails::__construct(); ' . print_r( $this->get_license_details(), true ) ); // phpcs:ignore

        $this->client = new ApiClient(
            $this->license_server_url,
            isset( $license_details['text_domain'] ) ? $license_details['text_domain'] : basename( $plugin_file, '.php' )
        );
    }

    /**
     * --------------------------------------------------------------------------
     * Getters
     * --------------------------------------------------------------------------
     *
     * Methods for getting object properties.
     */
    /**
     * Get default license options.
     *
     * The `grace_soft_days` and `grace_lock_days` keys default to an empty
     * string so DRM can distinguish "the server has never told us a value"
     * from "the server told us 0". Until populated via
     * {@see merge_grace_from_response()}, DRM falls back to its own defaults.
     *
     * @since 1.0.0
     *
     * @param array $args Options to override the defaults.
     *
     * @return array
     */
    public function get_default_license_details( $args = array() ) {
        $default_options = array(
            'text_domain'     => basename( $this->plugin_file, '.php' ),
            'domain'          => site_url(),
            'license_status'  => 'inactive',
            'license_key'     => '',
            'license_expires' => '',
            'current_version' => '',
            'grace_soft_days' => '',
            'grace_lock_days' => '',
        );

        if ( ! empty( $args ) ) {
            $default_options = wp_parse_args( $args, $default_options );
        }

        return apply_filters( 'slswc_client_default_license_options', $default_options );
    }

    /**
     * Set the license details
     *
     * Tolerates partial input arrays — get_default_license_details() returns
     * `current_version` (not `version`), and callers occasionally pass a subset
     * of keys, so each lookup falls back to an empty string instead of raising
     * "undefined array key" warnings under PHP 8.
     *
     * @since   1.0.0 Tolerate partial input arrays.
     *
     * @param array $license_details The license details.
     *
     * @return void
     */
    public function set_license_details( $license_details ) {
        $this->set_domain( $license_details['domain'] ?? '' );
        $this->set_license_status( $license_details['license_status'] ?? '' );
        $this->set_license_key( $license_details['license_key'] ?? '' );
        $this->set_license_expires( $license_details['license_expires'] ?? '' );
        $this->set_current_version( $license_details['version'] ?? '' );

        if ( isset( $license_details['grace_soft_days'] ) ) {
            $this->set_grace_soft_days( $license_details['grace_soft_days'] );
        }

        if ( isset( $license_details['grace_lock_days'] ) ) {
            $this->set_grace_lock_days( $license_details['grace_lock_days'] );
        }
    }

    /**
     * Get the option name.
     *
     * @return array
     * @version 1.0.0
     * @since   1.0.0
     */
    public function get_option_name() {
        return apply_filters(
            'slswc_client_license_option_name',
            $this->option_name
        );
    }

    /**
     * Get the domain
     *
     * @return string
     * @version 1.0.0
     * @since   1.0.0
     */
    public function get_domain() {
        return $this->license_details['domain'];
    }

    /**
     * Get the license status.
     *
     * @since 1.0.0
     * @version 1.0.0
     */
    public function get_license_status() {
        return $this->license_details['license_status'];
    }

    /**
     * Get the license key
     *
     * @since 1.0.0
     * @version 1.0.0
     */
    public function get_license_key() {
        return $this->license_details['license_key'];
    }


    /**
     * Get the license expiry
     *
     * @since 1.0.0
     * @version 1.0.0
     */
    public function get_license_expires() {
        return $this->license_details['license_expires'];
    }

    /**
     * Get the current version
     *
     * @return string
     * @version 1.0.0
     * @since   1.0.0
     */
    public function get_current_version() {
        return $this->license_details['version'];
    }

    /**
     * Get the license details.
     *
     * @return array
     * @version 1.0.0
     * @since   1.0.0
     */
    public function get_license_details() {
        return $this->license_details;
    }

    /**
     * --------------------------------------------------------------------------
     * Setters
     * --------------------------------------------------------------------------
     *
     * Methods for setting object properties.
     */

    /**
     * Set the domain
     *
     * @param string $domain The domain to set.
     * @return void
     * @version 1.0.0
     * @since   1.0.0
     */
    public function set_domain( $domain ) {
        $this->license_details['domain'] = $domain;
    }

    /**
     * Set the license status
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param string $license_status license status.
     */
    public function set_license_status( $license_status ) {
        $this->license_details['license_status'] = $license_status;
    }

    /**
     * Set the license key
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param string $license_key License key.
     */
    public function set_license_key( $license_key ) {
        $this->license_details['license_key'] = $license_key;
    }

    /**
     * Set the license expires.
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param string $license_expires License expiry date.
     */
    public function set_license_expires( $license_expires ) {
        $this->license_details['license_expires'] = $license_expires;
    }

    /**
     * Set the current version
     *
     * @param string $version The version to set.
     * @return void
     * @version 1.0.0
     * @since   1.0.0
     */
    public function set_current_version( $version ) {
        $this->license_details['version'] = $version;
    }

    /**
     * Set the server-supplied grace_soft_days value.
     *
     * Stored as an integer — empty / non-numeric values are coerced to an
     * empty string so {@see DRM::__construct()} treats them as "not set".
     *
     * @since 1.0.0
     *
     * @param mixed $days Number of days, or empty/non-numeric to clear.
     *
     * @return void
     */
    public function set_grace_soft_days( $days ) {
        $this->license_details['grace_soft_days'] = is_numeric( $days ) ? (int) $days : '';
    }

    /**
     * Set the server-supplied grace_lock_days value.
     *
     * @since 1.0.0
     *
     * @param mixed $days Number of days, or empty/non-numeric to clear.
     *
     * @return void
     */
    public function set_grace_lock_days( $days ) {
        $this->license_details['grace_lock_days'] = is_numeric( $days ) ? (int) $days : '';
    }

    /**
     * Get the server-supplied grace_soft_days value.
     *
     * @since 1.0.0
     *
     * @return int|string Integer when the server has supplied a value,
     *                    empty string when no value has been received yet.
     */
    public function get_grace_soft_days() {
        return $this->license_details['grace_soft_days'] ?? '';
    }

    /**
     * Get the server-supplied grace_lock_days value.
     *
     * @since 1.0.0
     *
     * @return int|string Integer when the server has supplied a value,
     *                    empty string when no value has been received yet.
     */
    public function get_grace_lock_days() {
        return $this->license_details['grace_lock_days'] ?? '';
    }

    /**
     * Persist DRM grace values from a license-server response payload.
     *
     * Tolerates both object and array shapes (the SDK consumes both: cron
     * checks pass decoded objects, the form-submission flow occasionally
     * receives arrays). Unknown / non-numeric values are ignored — the
     * existing persisted value is preserved.
     *
     * Callers are responsible for invoking save() afterwards.
     *
     * @since 1.0.0
     *
     * @param mixed $response Decoded response from the license server.
     *
     * @return void
     */
    public function merge_grace_from_response( $response ) {
        if ( empty( $response ) ) {
            return;
        }

        foreach ( array( 'grace_soft_days', 'grace_lock_days' ) as $key ) {
            $value = null;
            if ( is_object( $response ) && isset( $response->$key ) ) {
                $value = $response->$key;
            } elseif ( is_array( $response ) && isset( $response[ $key ] ) ) {
                $value = $response[ $key ];
            }

            if ( ! is_numeric( $value ) ) {
                continue;
            }

            if ( 'grace_soft_days' === $key ) {
                $this->set_grace_soft_days( $value );
            } else {
                $this->set_grace_lock_days( $value );
            }
        }
    }

    /**
     * Set the option name.
     *
     * @param string $option_name The name of the option.
     * @return void
     * @version 1.0.0
     * @since   1.0.0
     */
    public function set_option_name( $option_name ) {
        $this->option_name = $option_name;
    }

    /**
     * Save the license details.
     *
     * @return void
     * @version 1.0.0
     * @since   1.0.0
     */
    public function save() {
        update_option( $this->get_option_name(), $this->license_details );
    }

    /**
     * =========================================================================================
     * Functions used for interacting with licenses
     * =========================================================================================
     */

    /**
     * Validate the license is active and if not, set the status and return false
     *
     * @since 1.0.0
     * @param object $response_body Response body.
     */
    public function check_license( $response_body ) {
        $status = is_array( $response_body )
            ? ( $response_body['status'] ?? '' )
            : ( isset( $response_body->status ) ? $response_body->status : '' );

        if ( 'active' === $status || 'expiring' === $status ) {
            return true;
        }

        if ( ! is_numeric( $status ) ) {
            $this->set_license_status( $status );
            $expires = is_array( $response_body )
                ? ( $response_body['expires'] ?? '' )
                : ( isset( $response_body->expires ) ? $response_body->expires : '' );
            $this->set_license_expires( $expires );
            $this->save();
        }

        return false;
    }

    /**
     * Validate the license key information sent from the form.
     *
     * @since   1.0.0
     * @version 1.0.0
     * @param array $input the input passed from the request.
     */
    public function validate_license( $input = array() ) {
        $license = $this->get_license_details();
        $message = null;

        // Reset the license data if the license key has changed.
        if ( isset( $input['license_key'] ) && $license['license_key'] !== $input['license_key'] ) {
            $license               = $this->get_default_license_details();
            $this->license_details = $license;
        }

        $this->license_details['license_key'] = isset( $input['license_key'] )
            ? $input['license_key']
            : $this->get_license_key();
        $license                              = wp_parse_args( $input, $license );

        Helper::log( "Validate license:: key={$license['license_key']}" );

        $response = null;
        $action   = array_key_exists( 'deactivate_license', $input ) ? 'deactivate' : 'activate';

        $this->set_license_details( $license );

        switch ( $action ) {
            case 'activate':
                Helper::log( 'Activating. current status is: ' . $this->get_license_status() );
                $response = $this->client->request( 'activate', $this->get_license_details() );
                break;
            case 'deactivate':
                Helper::log( 'Deactivating license. current status is: ' . $this->get_license_status() );
                $response = $this->client->request( 'deactivate', $this->get_license_details() );
                break;
            default:
                $response = $this->client->request( 'check_license', $this->get_license_details() );
                break;
        }

        if ( is_null( $response ) ) {
            $message = __(
                'Error: Your license might be invalid or there was an unknown error on the license server. Please try again and contact support if this issue persists.',
                'slswc-client'
            );

            $this->set_license_status( 'invalid' );
            $this->save();

            return array(
                'status'   => 'bad_request',
                'message'  => $message,
                'response' => $response,
            );
        }

		// phpcs:ignore
		if ( ! $this->client->check_response_status( $response ) ) {

            $this->set_license_status( 'invalid' );
            $this->save();

            return array(
                'status'   => 'invalid',
                'message'  => is_array( $response ) ? $response['response'] : $response->response,
                'response' => $response,
            );
        }

        $_license_key = isset( $input['license_key'] ) ? $input['license_key'] : $this->get_license_key();

        $this->set_license_key( $_license_key );
        $this->set_license_status( $response->domain->status );
        $this->set_domain( is_object( $response->domain ) ? $response->domain->domain : $response->domain );
        $this->set_license_expires( $response->expires );
        $this->merge_grace_from_response( $response );

        $domain_status = $response->domain->status;

        $message = $this->get_status_message( $domain_status, $action, $response->status );

        Helper::log( $message );

        $this->save();

        Helper::log( $license );

        return array(
            'message'  => $message,
            'options'  => $this->get_license_details(),
            'status'   => $domain_status,
            'response' => $response,
        );
    }

    /**
     * Get the status message.
     *
     * @param string $domain_status   The activation status.
     * @param string $action          The action taken. activate or deactivate.
     * @param string $response_status The response status.
     * @return string
     * @version 1.0.0
     * @since   1.0.0
     */
    public function get_status_message( $domain_status, $action, $response_status ) {
        $messages = Helper::license_status_types();

        switch ( $action ) {
            case 'activate':
                return 'active' === $domain_status
                    ? __( 'License activated.', 'slswc-client' )
                    : sprintf(
                        // translators: %s is the license error message.
                        __( 'Failed to activate license. %s', 'slswc-client' ),
                        $messages[ $domain_status ]
                    );
            case 'deactivate':
                return 'deactivated' === $domain_status
                    ? __( 'License Deactivated', 'slswc-client' )
                    : sprintf(
                        // translators: %s - The message describing the license status.
                        __( 'Unable to deactivate license. Please deactivate on the store. %s', 'slswc-client' ),
                        $messages[ $domain_status ]
                    );
            default:
                return $messages[ $response_status ];
        }
    }
}
