<?php
/**
 * Define the license details class
 *
 * @version 1.0.2
 * @since   1.0.2
 *
 * @package SLSWC_CLient
 */

namespace SLSWC\Client;

/**
 * License details class.
 *
 * @version 1.0.2
 * @since   1.0.2
 */
class LicenseDetails {
    /**
     * License server URL
     *
     * @var string
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $license_server_url;

    /**
     * The option key for saving license details.
     *
     * @var string
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $option_name = '';

    /**
     * The Client object.
     *
     * @var ApiClient
     * @version 1.0.2
     * @since   1.0.2
     */
    public $client;

    /**
     * Plugin file
     *
     * @var string
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $plugin_file;

    /**
     * The license details
     *
     * @var array
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public $license_details = array();

    /**
     * Construct the instance of the class
     *
     * @param string $license_server_url The license server url.
     * @param string $plugin_file        The plugin file.
     * @param array  $license_details    The plugin details.
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
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
     * Check if staging activated
     *
     * @param string $environment environment to get status.
     * @return boolean
     */
    public function get_active_status( $environment ) {
        $options = $this->license_details;
        if ( ! isset( $options['active_status'] ) ) {
            $options['active_status'] = array(
                'live'    => false,
                'staging' => false,
            );
        }

        if ( ! $environment ) {
            return false;
        }

        $active_status = $options['active_status'][ $environment ];

        return is_bool( $active_status )
            ? $active_status
            : (
                'yes' === strtolower( $active_status )
                || 1 === $active_status
                || 'true' === strtolower( $active_status )
                || '1' === $active_status
            );
    }

    /**
     * Get default license options.
     *
     * @param array $args Options to override the defaults.
     * @return  array
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     * @version 1.1.0
     */
    public function get_default_license_details( $args = array() ) {
        $default_options = array(
            'text_domain'     => basename( $this->plugin_file, '.php' ),
            'domain'          => site_url(),
            'license_status'  => 'inactive',
            'license_key'     => '',
            'license_expires' => '',
            'current_version' => '',
            'environment'     => '',
            'active_status'   => array(
                'live'    => 'no',
                'staging' => 'no',
            ),
        );

        if ( ! empty( $args ) ) {
            $default_options = wp_parse_args( $args, $default_options );
        }

        return apply_filters( 'slswc_client_default_license_options', $default_options );
    }

    /**
     * Set the license details
     *
     * @param array $license_details The license details.
     *
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function set_license_details( $license_details ) {
        $this->set_domain( $license_details['domain'] );
        $this->set_license_status( $license_details['license_status'] );
        $this->set_license_key( $license_details['license_key'] );
        $this->set_license_expires( $license_details['license_expires'] );
        $this->set_current_version( $license_details['version'] );
        $this->set_active_status( $license_details['active_status'] );
    }

    /**
     * Get the option name.
     *
     * @return array
     * @version 1.1.0
     * @since   1.1.0
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
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function get_domain() {
        return $this->license_details['domain'];
    }

    /**
     * Get the license status.
     *
     * @since 1.1.0
     * @version 1.1.0
     */
    public function get_license_status() {
        return $this->license_details['license_status'];
    }

    /**
     * Get the license key
     *
     * @since 1.1.0
     * @version 1.1.0
     */
    public function get_license_key() {
        return $this->license_details['license_key'];
    }


    /**
     * Get the license expiry
     *
     * @since 1.1.0
     * @version 1.1.0
     */
    public function get_license_expires() {
        return $this->license_details['license_expires'];
    }

    /**
     * Get the environment
     *
     * @return string
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function get_environment() {
        return $this->license_details['environment'];
    }

    /**
     * Get the current version
     *
     * @return string
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function get_current_version() {
        return $this->license_details['version'];
    }

    /**
     * Get the license details.
     *
     * @return array
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
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
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function set_domain( $domain ) {
        $this->license_details['domain'] = $domain;
    }

    /**
     * Set the license status
     *
     * @since 1.1.0
     * @version 1.1.0
     * @param string $license_status license status.
     */
    public function set_license_status( $license_status ) {
        $this->license_details['license_status'] = $license_status;
    }

    /**
     * Set the license key
     *
     * @since 1.1.0
     * @version 1.1.0
     * @param string $license_key License key.
     */
    public function set_license_key( $license_key ) {
        $this->license_details['license_key'] = $license_key;
    }

    /**
     * Set the license expires.
     *
     * @since 1.1.0
     * @version 1.1.0
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
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function set_current_version( $version ) {
        $this->license_details['version'] = $version;
    }

    /**
     * Set the environment
     *
     * @param string $environment The environment to set.
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function set_environment( $environment ) {
        $this->license_details['environment'] = $environment;
    }

    /**
     * Set the option name.
     *
     * @param string $option_name The name of the option.
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Added.
     */
    public function set_option_name( $option_name ) {
        $this->option_name = $option_name;
    }

    /**
     * Set the active status
     *
     * @param array $active_status The active status to set.
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     */
    public function set_active_status( $active_status ) {
        $this->license_details['active_status'] = $active_status;
    }

    /**
     * Save the license details.
     *
     * @return void
     * @version 1.1.0
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
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
     * @since 1.1.0
     * @param object $response_body Response body.
     */
    public function check_license( $response_body ) {
        $status = is_array( $response_body ) ? $response_body['status'] : $response_body->status;

        if ( 'active' === $status || 'expiring' === $status ) {
            return true;
        }

        if ( ! is_numeric( $status ) ) {
            $this->set_license_status( $status );
            $this->set_license_expires( $response_body->expires );
            $this->save();
        }

        return false;
    }

    /**
     * The available license status types.
     *
     * @since 1.1.0
     * @version 1.1.0
     */
    public function license_status_types() {
        return apply_filters(
            'slswc_license_status_types',
            array(
                'valid'           => __( 'Valid', 'slswcclient' ),
                'deactivated'     => __( 'Deactivated', 'slswcclient' ),
                'max_activations' => __( 'Max Activations reached', 'slswcclient' ),
                'invalid'         => __( 'Invalid', 'slswcclient' ),
                'inactive'        => __( 'Inactive', 'slswcclient' ),
                'active'          => __( 'Active', 'slswcclient' ),
                'expiring'        => __( 'Expiring', 'slswcclient' ),
                'expired'         => __( 'Expired', 'slswcclient' ),
            )
        );
    }

    /**
     * Validate the license key information sent from the form.
     *
     * @since   1.1.0 - Refactored into classes and converted into a composer package.
     * @version 1.0.2
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

        $environment   = isset( $license['environment'] ) ? $license['environment'] : '';
        $active_status = $this->get_active_status( $environment );

        $this->license_details['license_key'] = isset( $input['license_key'] )
            ? $input['license_key']
            : $this->get_license_key();
        $license                              = wp_parse_args( $input, $license );

        Helper::log( "Validate license:: key={$license['license_key']}, environment=$environment, status=$active_status" );

        $response = null;
        $action   = array_key_exists( 'deactivate_license', $input ) ? 'deactivate' : 'activate';

        if ( $active_status && 'activate' === $action ) {
            $this->set_license_status( 'active' );
        }

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
                'slswcclient'
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

        $is_activating = 'activate' === $action;
        $is_active     = 'active' === $response->domain->status;

        $_active_status = $license['active_status'];

        $_active_status[ $environment ] = $is_activating && $is_active ? 'yes' : 'no';

        $this->set_license_key( $_license_key );
        $this->set_license_status( $response->domain->status );
        $this->set_domain( $response->domain );
        $this->set_license_expires( $response->expires );
        $this->set_active_status( $_active_status );

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
     * @version 1.1.0
     * @since   1.1.0
     */
    public function get_status_message( $domain_status, $action, $response_status ) {
        $messages = $this->license_status_types();

        switch ( $action ) {
            case 'activate':
                return 'active' === $domain_status
                    ? __( 'License activated.', 'slswcclient' )
                    : sprintf(
                        // translators: %s is the license error message.
                        __( 'Failed to activate license. %s', 'slswcclient' ),
                        $messages[ $domain_status ]
                    );
            case 'deactivate':
                return 'deactivated' === $domain_status
                    ? __( 'License Deactivated', 'slswcclient' )
                    : sprintf(
                        // translators: %s - The message describing the license status.
                        __( 'Unable to deactivate license. Please deactivate on the store. %s', 'slswcclient' ),
                        $messages[ $domain_status ]
                    );
            default:
                return $messages[ $response_status ];
        }
    }
}
