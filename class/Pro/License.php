<?php

namespace DeliciousBrains\WPMDB\Pro;

use DeliciousBrains\WPMDB\Common\Error\ErrorLog;
use DeliciousBrains\WPMDB\Common\Http\Helper;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Http\RemotePost;
use DeliciousBrains\WPMDB\Common\Http\Scramble;
use DeliciousBrains\WPMDB\Common\MigrationState\MigrationStateManager;
use DeliciousBrains\WPMDB\Common\Properties\DynamicProperties;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Sanitize;
use DeliciousBrains\WPMDB\Common\Settings\Settings;
use DeliciousBrains\WPMDB\Common\Util\Util;
use DeliciousBrains\WPMDB\Pro\Beta\BetaManager;

class License {

	public $props, $api, $settings, $license_response_messages, $util;
	/**
	 * @var MigrationStateManager
	 */
	private $migration_state_manager;
	/**
	 * @var Http
	 */
	private $http;
	/**
	 * @var static $license_key
	 */
	private static $license_key;
	/**
	 * @var ErrorLog
	 */
	private $error_log;
	/**
	 * @var Helper
	 */
	private $http_helper;
	/**
	 * @var Scramble
	 */
	private $scrambler;
	/**
	 * @var RemotePost
	 */
	private $remote_post;
	/**
	 * @var DynamicProperties
	 */
	private $dynamic_props;
	/**
	 * @var static $static_settings
	 */
	private static $static_settings;

	public function __construct(
		Api $api,
		Settings $settings,
		Util $util,
		MigrationStateManager $migration_state_manager,
		Download $download,
		Http $http,
		ErrorLog $error_log,
		Helper $http_helper,
		Scramble $scrambler,
		RemotePost $remote_post,
		Properties $properties
	) {
		$this->props                   = $properties;
		$this->api                     = $api;
		$this->settings                = $settings->get_settings();
		$this->util                    = $util;
		$this->dynamic_props           = DynamicProperties::getInstance();
		$this->migration_state_manager = $migration_state_manager;
		$this->download                = $download;
		$this->http                    = $http;
		$this->error_log               = $error_log;
		$this->http_helper             = $http_helper;
		$this->scrambler               = $scrambler;
		$this->remote_post             = $remote_post;

		self::$license_key    = $this->settings['licence'];
		self::$static_settings = $this->settings;
	}

	public function register() {
		$this->http_remove_license();
		$this->http_disable_ssl();
		$this->http_refresh_licence();

		// Required for Pull if user tables being updated.
		add_action( 'wp_ajax_wpmdb_reset_api_key', array( $this, 'ajax_reset_api_key' ) );
		add_action( 'wp_ajax_wpmdb_activate_licence', array( $this, 'ajax_activate_licence' ) );
		add_action( 'wp_ajax_wpmdb_check_licence', array( $this, 'ajax_check_licence' ) );
		add_action( 'wp_ajax_wpmdb_copy_licence_to_remote_site', array( $this, 'ajax_copy_licence_to_remote_site' ) );
		add_action( 'wp_ajax_wpmdb_reactivate_licence', array( $this, 'ajax_reactivate_licence' ) );
		add_action( 'wp_ajax_nopriv_wpmdb_copy_licence_to_remote_site', array( $this, 'respond_to_copy_licence_to_remote_site' ) );

		$this->license_response_messages = $this->setup_license_responses( $this->props->plugin_base );
	}

	/**
	 * AJAX handler for activating a licence.
	 *
	 * @return string (JSON)
	 */
	function ajax_activate_licence() {
		$this->http->check_ajax_referer( 'activate-licence' );

		$key_rules  = array(
			'action'      => 'key',
			'licence_key' => 'string',
			'context'     => 'key',
			'nonce'       => 'key',
		);
		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		$args = array(
			'licence_key' => urlencode( $state_data['licence_key'] ),
			'site_url'    => urlencode( untrailingslashit( network_home_url( '', 'http' ) ) ),
		);

		$response         = $this->api->dbrains_api_request( 'activate_licence', $args );
		$decoded_response = json_decode( $response, true );

		if ( empty( $decoded_response['errors'] ) && empty( $decoded_response['dbrains_api_down'] ) ) {
			$this->set_licence_key( $state_data['licence_key'] );
			$decoded_response['masked_licence'] = $this->get_formatted_masked_licence();
		} else {
			if ( isset( $decoded_response['errors']['activation_deactivated'] ) ) {
				$this->set_licence_key( $state_data['licence_key'] );
			} elseif ( isset( $decoded_response['errors']['subscription_expired'] ) || isset( $decoded_response['dbrains_api_down'] ) ) {
				$this->set_licence_key( $state_data['licence_key'] );
				$decoded_response['masked_licence'] = $this->get_formatted_masked_licence();
			}

			set_site_transient( 'wpmdb_licence_response', $response, $this->props->transient_timeout );
			if ( true === $this->dynamic_props->doing_cli_migration ) {
				$decoded_response['errors'] = array(
					$this->get_licence_status_message( $decoded_response, $state_data['context'] ),
				);
			} else {
				$decoded_response['errors'] = array(
					sprintf( '<div class="notification-message warning-notice inline-message invalid-licence">%s</div>', $this->get_licence_status_message( $decoded_response, $state_data['context'] ) ),
				);
			}

			if ( isset( $decoded_response['dbrains_api_down'] ) ) {
				$decoded_response['errors'][] = $decoded_response['dbrains_api_down'];
			}
		}

		$result = $this->http->end_ajax( json_encode( $decoded_response ) );

		return $result;
	}

	/**
	 * Stores and attempts to activate the licence key received via a remote machine, returns errors if applicable.
	 *
	 * @return array Empty array or an array containing an error message.
	 */
	function respond_to_copy_licence_to_remote_site() {
		add_filter( 'wpmdb_before_response', array( $this->scrambler, 'scramble' ) );

		$key_rules  = array(
			'action'  => 'key',
			'licence' => 'string',
			'sig'     => 'string',
		);
		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		$filtered_post = $this->http_helper->filter_post_elements( $state_data, array( 'action', 'licence' ) );

		$return = array();

		if ( ! $this->http_helper->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$return['error']   = 1;
			$return['message'] = $this->props->invalid_content_verification_error . ' (#142)';
			$this->error_log->log_error( $return['message'], $filtered_post );
			$result = $this->http->end_ajax( serialize( $return ) );

			return $result;
		}

		$this->set_licence_key( trim( $state_data['licence'] ) );
		$licence        = $this->get_licence_key();
		$licence_status = json_decode( $this->check_licence( $licence ), true );

		if ( isset( $licence_status['errors'] ) && ! isset( $licence_status['errors']['subscription_expired'] ) ) {
			$return['error']   = 1;
			$return['message'] = reset( $licence_status['errors'] );
			$this->error_log->log_error( $return['message'], $licence_status );
			$result = $this->http->end_ajax( serialize( $return ) );

			return $result;
		}

		$result = $this->http->end_ajax( serialize( $return ) );

		return $result;
	}


	public static function get_license() {
		$settings = self::$static_settings;
		$license  = defined( 'WPMDB_LICENCE' ) ? WPMDB_LICENCE : $settings['licence'];

		return $license;
	}

	public function setup_license_responses( $plugin_base ) {
		$disable_ssl_url         = network_admin_url( $plugin_base . '&nonce=' . Util::create_nonce( 'wpmdb-disable-ssl' ) . '&wpmdb-disable-ssl=1' );
		$check_licence_again_url = network_admin_url( $plugin_base . '&nonce=' . Util::create_nonce( 'wpmdb-check-licence' ) . '&wpmdb-check-licence=1' );

		// List of potential license responses. Keys must must exist in both arrays, otherwise the default error message will be shown.
		$this->license_response_messages = array(
			'connection_failed'            => array(
				'ui'  => sprintf( __( '<strong>Could not connect to api.deliciousbrains.com</strong> &mdash; You will not receive update notifications or be able to activate your license until this is fixed. This issue is often caused by an improperly configured SSL server (https). We recommend <a href="%1$s" target="_blank">fixing the SSL configuration on your server</a>, but if you need a quick fix you can:%2$s', 'wp-migrate-db' ), 'https://deliciousbrains.com/wp-migrate-db-pro/doc/could-not-connect-deliciousbrains-com/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin', sprintf( '<p><a href="%1$s" class="temporarily-disable-ssl button">%2$s</a></p>', $disable_ssl_url, __( 'Temporarily disable SSL for connections to api.deliciousbrains.com', 'wp-migrate-db' ) ) ),
				'cli' => __( 'Could not connect to api.deliciousbrains.com - You will not receive update notifications or be able to activate your license until this is fixed. This issue is often caused by an improperly configured SSL server (https). We recommend fixing the SSL configuration on your server, but if you need a quick fix you can temporarily disable SSL for connections to api.deliciousbrains.com by adding `define( \'DBRAINS_API_BASE\', \'http://api.deliciousbrains.com\' );` to your wp-config.php file.', 'wp-migrate-db' ),
			),
			'http_block_external'          => array(
				'ui'  => __( 'We\'ve detected that <code>WP_HTTP_BLOCK_EXTERNAL</code> is enabled and the host <strong>%1$s</strong> has not been added to <code>WP_ACCESSIBLE_HOSTS</code>. Please disable <code>WP_HTTP_BLOCK_EXTERNAL</code> or add <strong>%1$s</strong> to <code>WP_ACCESSIBLE_HOSTS</code> to continue. <a href="%2$s" target="_blank">More information</a>.', 'wp-migrate-db' ),
				'cli' => __( 'We\'ve detected that WP_HTTP_BLOCK_EXTERNAL is enabled and the host %1$s has not been added to WP_ACCESSIBLE_HOSTS. Please disable WP_HTTP_BLOCK_EXTERNAL or add %1$s to WP_ACCESSIBLE_HOSTS to continue.', 'wp-migrate-db' ),
			),
			'subscription_cancelled'       => array(
				'ui'  => sprintf( __( '<strong>Your License Was Cancelled</strong> &mdash; Please visit <a href="%s" target="_blank">My Account</a> to renew or upgrade your license and enable push and pull. <br /><a href="%s" class="check-my-licence-again" >%s</a>', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin', $check_licence_again_url, __( 'Check my license again', 'wp-migrate-db' ) ),
				'cli' => sprintf( __( 'Your License Was Cancelled - Please login to your account (%s) to renew or upgrade your license and enable push and pull.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
			),
			'subscription_expired_base'    => array(
				'ui'  => sprintf( '<strong>%s</strong> &mdash; ', __( 'Your License Has Expired', 'wp-migrate-db' ) ),
				'cli' => sprintf( '%s - ', __( 'Your License Has Expired', 'wp-migrate-db' ) ),
			),
			'subscription_expired_end'     => array(
				'ui'  => sprintf( __( 'Login to <a href="%s">My Account</a> to renew. <a href="%s" class="check-my-licence-again">%s</a>', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin', $check_licence_again_url, __( 'Check my license again', 'wp-migrate-db' ) ),
				'cli' => sprintf( __( 'Login to your account to renew (%s)', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/' ),
			),
			'no_activations_left'          => array(
				'ui'  => sprintf( __( '<strong>No Activations Left</strong> &mdash; Please visit <a href="%s" target="_blank">My Account</a> to upgrade your license or deactivate a previous activation and enable push and pull.  <a href="%s" class="check-my-licence-again">%s</a>', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin', $check_licence_again_url, __( 'Check my license again', 'wp-migrate-db' ) ),
				'cli' => sprintf( __( 'No Activations Left - Please visit your account (%s) to upgrade your license or deactivate a previous activation and enable push and pull.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin' ),
			),
			'licence_not_found_api_failed' => array(
				'ui'  => sprintf( __( '<strong>Your License Was Not Found</strong> &mdash; Perhaps you made a typo when defining your WPMDB_LICENCE constant in your wp-config.php? Please visit <a href="%s" target="_blank">My Account</a> to double check your license key. <a href="%s" class="check-my-licence-again">%s</a>', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin', $check_licence_again_url, __( 'Check my license again', 'wp-migrate-db' ) ),
				'cli' => sprintf( __( 'Your License Was Not Found - Perhaps you made a typo when defining your WPMDB_LICENCE constant in your wp-config.php? Please visit your account (%s) to double check your license key.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/' ),
			),
			'licence_not_found_api'        => array(
				'ui'  => __( '<strong>Your License Was Not Found</strong> &mdash; %s', 'wp-migrate-db' ),
				'cli' => __( 'Your License Was Not Found - %s', 'wp-migrate-db' ),
			),
			'activation_deactivated'       => array(
				'ui'  => sprintf( '<strong>%s</strong> &mdash; %s <a href="#" class="js-action-link reactivate-licence">%s</a>', __( 'Your License Is Inactive', 'wp-migrate-db' ), __( 'Your license has been deactivated for this install.', 'wp-migrate-db' ), __( 'Reactivate your license', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account' ),
				'cli' => sprintf( '%s - %s %s at %s', __( 'Your License Is Inactive', 'wp-migrate-db' ), __( 'Your license has been deactivated for this install.', 'wp-migrate-db' ), __( 'Reactivate your license', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account' ),
			),
			'default'                      => array(
				'ui'  => __( '<strong>An Unexpected Error Occurred</strong> &mdash; Please contact us at <a href="%1$s">%2$s</a> and quote the following: <p>%3$s</p>', 'wp-migrate-db' ),
				'cli' => __( 'An Unexpected Error Occurred - Please contact us at %2$s and quote the following: %3$s', 'wp-migrate-db' ),
			),
		);

		return $this->license_response_messages;
	}


	function is_licence_constant() {
		return defined( 'WPMDB_LICENCE' );
	}

	public function get_licence_key() {
		return $this->is_licence_constant() ? WPMDB_LICENCE : $this->settings['licence'];
	}

	/**
	 * Sets the licence index in the $settings array class property and updates the wpmdb_settings option.
	 *
	 * @param string $key
	 */
	function set_licence_key( $key ) {
		$this->settings['licence'] = $key;
		update_site_option( 'wpmdb_settings', $this->settings );
	}

	/**
	 * Checks whether the saved licence has expired or not.
	 *
	 * @param bool $skip_transient_check
	 *
	 * @return bool
	 */
	function is_valid_licence( $skip_transient_check = false ) {
		$response = $this->is_licence_expired( $skip_transient_check );

		if ( isset( $response['dbrains_api_down'] ) ) {
			return true;
		}

		// Don't cripple the plugin's functionality if the user's licence is expired
		if ( isset( $response['errors']['subscription_expired'] ) && 1 === count( $response['errors'] ) ) {
			return true;
		}

		return ( isset( $response['errors'] ) ) ? false : true;
	}

	function is_licence_expired( $skip_transient_check = false ) {
		$licence = $this->get_licence_key();

		if ( empty( $licence ) ) {
			$settings_link = sprintf( '<a href="%s">%s</a>', network_admin_url( $this->props->plugin_base ) . '#settings', _x( 'Settings', 'Plugin configuration and preferences', 'wp-migrate-db' ) );
			$message       = sprintf( __( 'To finish activating WP Migrate DB Pro, please go to %1$s and enter your license key. If you don\'t have a license key, you may <a href="%2$s">purchase one</a>.', 'wp-migrate-db' ), $settings_link, 'http://deliciousbrains.com/wp-migrate-db-pro/pricing/' );

			return array( 'errors' => array( 'no_licence' => $message ) );
		}

		if ( ! $skip_transient_check ) {
			$trans = get_site_transient( 'wpmdb_licence_response' );
			if ( false !== $trans ) {
				return json_decode( $trans, true );
			}
		}

		return json_decode( $this->check_licence( $licence ), true );
	}

	function check_licence( $licence_key ) {
		if ( empty( $licence_key ) ) {
			return false;
		}

		$args = array(
			'licence_key' => urlencode( $licence_key ),
			'site_url'    => urlencode( untrailingslashit( network_home_url( '', 'http' ) ) ),
		);

		$response = $this->api->dbrains_api_request( 'check_support_access', $args );

		set_site_transient( 'wpmdb_licence_response', $response, $this->props->transient_timeout );

		return $response;
	}


	/**
	 *
	 * Get a message from the $messages array parameter based on a context
	 *
	 * Assumes the $messages array exists in the format of a nested array.
	 *
	 * Also assumes the nested array of strings has a key of 'default'
	 *
	 *  Ex:
	 *
	 *  array(
	 *      'key1' => array(
	 *          'ui'   => 'Some message',
	 *          'cli'   => 'Another message',
	 *          ...
	 *       ),
	 *
	 *      'key2' => array(
	 *          'ui'   => 'Some message',
	 *          'cli'   => 'Another message',
	 *          ...
	 *       ),
	 *
	 *      'default' => array(
	 *          'ui'   => 'Some message',
	 *          'cli'   => 'Another message',
	 *          ...
	 *       ),
	 *  )
	 *
	 * @param array  $messages
	 * @param        $key
	 * @param string $context
	 *
	 * @return mixed
	 */
	function get_contextual_message_string( $messages, $key, $context = 'ui' ) {
		$message = $messages[ $key ];

		if ( isset( $message[ $context ] ) ) {
			return $message[ $context ];
		} else if ( isset( $message['default'] ) ) {
			return $message['default'];
		}

		return '';
	}

	/**
	 * Returns a formatted message dependant on the status of the licence.
	 *
	 * @param bool   $trans
	 * @param string $context
	 *
	 * @return array|mixed|string
	 */
	function get_licence_status_message( $trans = false, $context = null ) {
		$this->setup_license_responses( $this->props->plugin_base );

		$licence               = $this->get_licence_key();
		$api_response_provided = true;
		$message_context       = 'ui';
		$messages              = $this->license_response_messages;
		$message               = '';

		if ( $this->dynamic_props->doing_cli_migration ) {
			$message_context = 'cli';
		}

		if ( empty( $licence ) && ! $trans ) {
			$message = sprintf( __( '<strong>Activate Your License</strong> &mdash; Please <a href="%s" class="%s">enter your license key</a> to enable push and pull functionality, priority support and plugin updates.', 'wp-migrate-db' ), network_admin_url( $this->props->plugin_base . '#settings' ), 'js-action-link enter-licence' );

			return $message;
		}

		if ( ! $trans ) {
			$trans = get_site_transient( 'wpmdb_licence_response' );

			if ( false === $trans ) {
				$trans = $this->check_licence( $licence );
			}

			$trans                 = json_decode( $trans, true );
			$api_response_provided = false;
		}

		if ( isset( $trans['dbrains_api_down'] ) ) {
			return __( "<strong>We've temporarily activated your license and will complete the activation once the Delicious Brains API is available again.</strong>", 'wp-migrate-db' );
		}

		$errors = $trans['errors'];

		if ( isset( $errors['connection_failed'] ) ) {
			$message = $this->get_contextual_message_string( $messages, 'connection_failed', $message_context );

			if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
				$url_parts = Util::parse_url( $this->api->get_dbrains_api_base() );
				$host      = $url_parts['host'];
				if ( ! defined( 'WP_ACCESSIBLE_HOSTS' ) || strpos( WP_ACCESSIBLE_HOSTS, $host ) === false ) {
					$message = sprintf( $this->get_contextual_message_string( $messages, 'http_block_external', $message_context ), esc_attr( $host ), 'https://deliciousbrains.com/wp-migrate-db-pro/doc/wp_http_block_external/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin' );
				}
			}

			// Don't cache the license response so we can try again
			delete_site_transient( 'wpmdb_licence_response' );
		} elseif ( isset( $errors['subscription_cancelled'] ) ) {

			$message = $this->get_contextual_message_string( $messages, 'subscription_cancelled', $message_context );

		} elseif ( isset( $errors['subscription_expired'] ) ) {

			$message_base = $this->get_contextual_message_string( $messages, 'subscription_expired_base', $message_context );
			$message_end  = $this->get_contextual_message_string( $messages, 'subscription_expired_end', $message_context );

			$contextual_messages = array(
				'default' => $message_base . $message_end,
				'update'  => $message_base . __( 'Updates are only available to those with an active license. ', 'wp-migrate-db' ) . $message_end,
				'addons'  => $message_base . __( 'Only active licenses can download and install addons. ', 'wp-migrate-db' ) . $message_end,
				'support' => $message_base . __( 'Only active licenses can submit support requests. ', 'wp-migrate-db' ) . $message_end,
				'licence' => $message_base . __( "All features will continue to work, but you won't be able to receive updates or email support. ", 'wp-migrate-db' ) . $message_end,
			);

			if ( empty( $context ) ) {
				$context = 'default';
			}
			if ( ! empty( $contextual_messages[ $context ] ) ) {
				$message = $contextual_messages[ $context ];
			} elseif ( 'all' === $context ) {
				$message = $contextual_messages;
			}

		} elseif ( isset( $errors['no_activations_left'] ) ) {

			$message = $this->get_contextual_message_string( $messages, 'no_activations_left', $message_context );

		} elseif ( isset( $errors['licence_not_found'] ) ) {

			if ( ! $api_response_provided ) {
				$message = $this->get_contextual_message_string( $messages, 'licence_not_found_api_failed', $message_context );
			} else {
				$error   = reset( $errors );
				$message = sprintf( $this->get_contextual_message_string( $messages, 'licence_not_found_api', $message_context ), $error );
			}

		} elseif ( isset( $errors['activation_deactivated'] ) ) {
			$message = $this->get_contextual_message_string( $messages, 'activation_deactivated', $message_context );

		} else {
			$error   = reset( $errors );
			$message = sprintf( $this->get_contextual_message_string( $messages, 'default', $message_context ), 'mailto:nom@deliciousbrains.com', 'nom@deliciousbrains.com', $error );
		}

		return $message;
	}

	/**
	 * Check for wpmdb-remove-licence and related nonce
	 * if found cleanup routines related to licenced product
	 *
	 * @return void
	 */
	function http_remove_license() {
		if ( isset( $_GET['wpmdb-remove-licence'] ) && wp_verify_nonce( $_GET['nonce'], 'wpmdb-remove-licence' ) ) {
			$this->settings['licence'] = '';
			update_site_option( 'wpmdb_settings', $this->settings );
			// delete these transients as they contain information only valid for authenticated licence holders
			delete_site_transient( 'update_plugins' );
			delete_site_transient( 'wpmdb_upgrade_data' );
			delete_site_transient( 'wpmdb_licence_response' );
			// redirecting here because we don't want to keep the query string in the web browsers address bar
			wp_redirect( network_admin_url( $this->props->plugin_base . '#settings' ) );
			exit;
		}
	}

	/**
	 * Check for wpmdb-disable-ssl and related nonce
	 * if found temporaily disable ssl via transient
	 *
	 * @return void
	 */
	function http_disable_ssl() {
		if ( isset( $_GET['wpmdb-disable-ssl'] ) && wp_verify_nonce( $_GET['nonce'], 'wpmdb-disable-ssl' ) ) {
			set_site_transient( 'wpmdb_temporarily_disable_ssl', '1', 60 * 60 * 24 * 30 ); // 30 days
			$hash = ( isset( $_GET['hash'] ) ) ? '#' . sanitize_title( $_GET['hash'] ) : '';
			// delete the licence transient as we want to attempt to fetch the licence details again
			delete_site_transient( 'wpmdb_licence_response' );
			// redirecting here because we don't want to keep the query string in the web browsers address bar
			wp_redirect( network_admin_url( $this->props->plugin_base . $hash ) );
			exit;
		}
	}

	/**
	 * Check for wpmdb-check-licence and related nonce
	 * if found refresh licence details
	 *
	 * @return void
	 */
	function http_refresh_licence() {
		if ( isset( $_GET['wpmdb-check-licence'] ) && wp_verify_nonce( $_GET['nonce'], 'wpmdb-check-licence' ) ) {
			$hash = ( isset( $_GET['hash'] ) ) ? '#' . sanitize_title( $_GET['hash'] ) : '';
			// delete the licence transient as we want to attempt to fetch the licence details again
			delete_site_transient( 'wpmdb_licence_response' );
			// redirecting here because we don't want to keep the query string in the web browsers address bar
			wp_redirect( network_admin_url( $this->props->plugin_base . $hash ) );
			exit;
		}
	}

	function mask_licence( $licence ) {
		$licence_parts  = explode( '-', $licence );
		$i              = count( $licence_parts ) - 1;
		$masked_licence = '';

		foreach ( $licence_parts as $licence_part ) {
			if ( $i == 0 ) {
				$masked_licence .= $licence_part;
				continue;
			}

			$masked_licence .= '<span class="bull">';
			$masked_licence .= str_repeat( '&bull;', strlen( $licence_part ) ) . '</span>&ndash;';
			-- $i;
		}

		return $masked_licence;
	}

	function get_formatted_masked_licence() {
		return sprintf(
			'<p class="masked-licence">%s <a href="%s">%s</a></p>',
			$this->mask_licence( $this->settings['licence'] ),
			network_admin_url( $this->props->plugin_base . '&nonce=' . Util::create_nonce( 'wpmdb-remove-licence' ) . '&wpmdb-remove-licence=1#settings' ),
			_x( 'Remove', 'Delete license', 'wp-migrate-db' )
		);
	}

	/**
	 * AJAX handler for checking a licence.
	 *
	 * @return string (JSON)
	 */
	function ajax_check_licence() {
		$this->http->check_ajax_referer( 'check-licence' );

		$key_rules  = array(
			'action'  => 'key',
			'licence' => 'string',
			'context' => 'key',
			'nonce'   => 'key',
		);
		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		$licence          = ( empty( $state_data['licence'] ) ? $this->get_licence_key() : $state_data['licence'] );
		$response         = $this->check_licence( $licence );
		$decoded_response = json_decode( $response, ARRAY_A );
		$context          = ( empty( $state_data['context'] ) ? null : $state_data['context'] );

		if ( false == $licence ) {
			$decoded_response           = array( 'errors' => array() );
			$decoded_response['errors'] = array( sprintf( '<div class="notification-message warning-notice inline-message invalid-licence">%s</div>', $this->get_licence_status_message() ) );
		} else if ( ! empty( $decoded_response['dbrains_api_down'] ) ) {
			$help_message = get_site_transient( 'wpmdb_help_message' );

			if ( ! $help_message ) {
				ob_start();
				?>
				<p><?php _e( 'If you have an <strong>active license</strong>, you may send an email to the following address.', 'wp-migrate-db' ); ?></p>
				<p>
					<strong><?php _e( 'Please copy the Diagnostic Info &amp; Error Log info below into a text file and attach it to your email. Do the same for any other site involved in your email.', 'wp-migrate-db' ); ?></strong>
				</p>
				<p class="email"><a class="button" href="mailto:wpmdb@deliciousbrains.com">wpmdb@deliciousbrains.com</a></p>
				<?php
				$help_message = ob_get_clean();
			}

			$decoded_response['message'] = $help_message;
		} elseif ( ! empty( $decoded_response['errors'] ) ) {
			if ( 'all' === $context && ! empty( $decoded_response['errors']['subscription_expired'] ) ) {
				$decoded_response['errors']['subscription_expired'] = array();
				$licence_status_messages                            = $this->get_licence_status_message( null, $context );
				foreach ( $licence_status_messages as $frontend_context => $status_message ) {
					$decoded_response['errors']['subscription_expired'][ $frontend_context ] = sprintf( '<div class="notification-message warning-notice inline-message invalid-licence">%s</div>', $status_message );
				}
			} else {
				$decoded_response['errors'] = array( sprintf( '<div class="notification-message warning-notice inline-message invalid-licence">%s</div>', $this->get_licence_status_message( null, $context ) ) );
			}
		} elseif ( ! empty( $decoded_response['message'] ) && ! get_site_transient( 'wpmdb_help_message' ) ) {
			set_site_transient( 'wpmdb_help_message', $decoded_response['message'], $this->props->transient_timeout );
		}

		if ( isset( $decoded_response['addon_list'] ) ) {
			ob_start();

			if ( empty( $decoded_response['errors'] ) ) {
				$addons_available = ( $decoded_response['addons_available'] == '1' );

				if ( ! $addons_available ) {
					?>
					<p class="inline-message warning">
						<strong><?php _ex( 'Addons Unavailable', 'License does not allow use of addons', 'wp-migrate-db' ); ?></strong> &ndash; <?php printf( __( 'Addons are not included with the Personal license. Visit <a href="%s" target="_blank">My Account</a> to upgrade in just a few clicks.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=support%2Bdocs&utm_source=MDB%2BPaid&utm_medium=insideplugin' ); ?>
					</p>
					<?php
				}
			}

			// Save the addons list for use when installing
			// Don't really need to expire it ever, but let's clean it up after 60 days
			set_site_transient( 'wpmdb_addons', $decoded_response['addon_list'], HOUR_IN_SECONDS * 24 * 60 );

			foreach ( $decoded_response['addon_list'] as $key => $addon ) {
				$plugin_file = sprintf( '%1$s/%1$s.php', $key );
				$plugin_ids  = array_keys( get_plugins() );

				if ( in_array( $plugin_file, $plugin_ids ) ) {
					$actions = '<span class="status">' . _x( 'Installed', 'Installed on website but not activated', 'wp-migrate-db' );
					if ( is_plugin_active( $plugin_file ) ) {
						$actions .= ' &amp; ' . _x( 'Activated', 'Installed and activated on website', 'wp-migrate-db' ) . '</span>';
					} else {
						$activate_url = wp_nonce_url( network_admin_url( 'plugins.php?action=activate&amp;plugin=' . $plugin_file ), 'activate-plugin_' . $plugin_file );
						$actions      .= sprintf( '</span> <a class="action" href="%s">%s</a>', $activate_url, _x( 'Activate', 'Enable addon so it may be used', 'wp-migrate-db' ) );
					}
				} else {
					$install_url = wp_nonce_url( network_admin_url( 'update.php?action=install-plugin&plugin=' . $key ), 'install-plugin_' . $key );
					$actions     = sprintf( '<a class="action" href="%s">%s</a>', $install_url, _x( 'Install', 'Download and activate addon', 'wp-migrate-db' ) );
				}

				$is_beta      = ! empty( $addon['beta_version'] ) && BetaManager::has_beta_optin( $this->settings );
				$download_url = $this->download->get_plugin_update_download_url( $key, $is_beta );
				$actions      .= sprintf( '<a class="action" href="%s">%s</a>', $download_url, _x( 'Download', 'Download to your computer', 'wp-migrate-db' ) ); ?>

				<article class="addon <?php echo esc_attr( $key ); ?>">
					<div class="desc">
						<?php if ( isset( $addons_available ) && $addons_available ) : ?>
							<div class="actions"><?php echo $actions; ?></div>
						<?php endif; ?>

						<h1><?php echo $addon['name']; ?></h1>

						<p><?php echo $addon['desc']; ?></p>
					</div>
				</article> <?php
			}
			$addon_content                     = ob_get_clean();
			$decoded_response['addon_content'] = $addon_content;
		}

		$response = json_encode( $decoded_response );

		$result = $this->http->end_ajax( $response );

		return $result;
	}

	/**
	 * Sends the local WP Migrate DB Pro licence to the remote machine and activates it, returns errors if applicable.
	 *
	 * @return array Empty array or an array containing an error message.
	 */
	function ajax_copy_licence_to_remote_site() {
		$this->http->check_ajax_referer( 'copy-licence-to-remote-site' );

		$key_rules  = array(
			'action' => 'key',
			'url'    => 'url',
			'key'    => 'string',
			'nonce'  => 'key',
		);
		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		$return = array();

		$data = array(
			'action'  => 'wpmdb_copy_licence_to_remote_site',
			'licence' => $this->get_licence_key(),
		);

		$data['sig'] = $this->http_helper->create_signature( $data, $state_data['key'] );
		$ajax_url    = $this->util->ajax_url();


		$serialized_response = $this->remote_post->post( $ajax_url, $data, __FUNCTION__, array(), true );

		if ( false === $serialized_response ) {
			$return = array( 'wpmdb_error' => 1, 'body' => $this->error_log->getError() );
			$result = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		$response = Util::unserialize( $serialized_response, __METHOD__ );

		if ( false === $response ) {
			$error_msg = __( 'Failed attempting to unserialize the response from the remote server. Please contact support.', 'wp-migrate-db' );
			$return    = array( 'wpmdb_error' => 1, 'body' => $error_msg );
			$this->error_log->log_error( $error_msg, $serialized_response );
			$result = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		if ( isset( $response['error'] ) && $response['error'] == 1 ) {
			$return = array( 'wpmdb_error' => 1, 'body' => $response['message'] );
			$this->error_log->log_error( $response['message'], $response );
			$result = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		$result = $this->http->end_ajax( json_encode( $return ) );

		return $result;
	}

	/**
	 * Handler for ajax request to reset the secret key.
	 *
	 * @return bool|null
	 */
	function ajax_reset_api_key() {
		$this->http->check_ajax_referer( 'reset-api-key' );

		$key_rules = array(
			'action' => 'key',
			'nonce'  => 'key',
		);

		$_POST = Sanitize::sanitize_data( $_POST, $key_rules, __METHOD__ );

		if ( is_wp_error( $_POST ) ) {
			exit;
		}

		$this->settings['key'] = $this->util->generate_key();
		update_site_option( 'wpmdb_settings', $this->settings );
		$result = $this->http->end_ajax( sprintf( "%s\n%s", site_url( '', 'https' ), $this->settings['key'] ) );

		return $result;
	}

	/**
	 * Attempts to reactivate this instance via the Delicious Brains API.
	 *
	 * @return array Empty array or an array containing an error message.
	 */
	function ajax_reactivate_licence() {
		$this->http->check_ajax_referer( 'reactivate-licence' );

		$key_rules  = array(
			'action' => 'key',
			'nonce'  => 'key',
		);
		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		$filtered_post = $this->http_helper->filter_post_elements( $state_data, array( 'action', 'nonce' ) );
		$return        = array();

		$args = array(
			'licence_key' => urlencode( $this->get_licence_key() ),
			'site_url'    => urlencode( untrailingslashit( network_home_url( '', 'http' ) ) ),
		);

		$response         = $this->api->dbrains_api_request( 'reactivate_licence', $args );
		$decoded_response = json_decode( $response, true );

		if ( isset( $decoded_response['dbrains_api_down'] ) ) {
			$return['wpmdb_dbrains_api_down'] = 1;
			$return['body']                   = $decoded_response['dbrains_api_down'];
			$result                           = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		if ( isset( $decoded_response['errors'] ) ) {
			$return['wpmdb_error'] = 1;
			$return['body']        = reset( $decoded_response['errors'] );
			$this->error_log->log_error( $return['body'], $decoded_response );
			$result = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		delete_site_transient( 'wpmdb_upgrade_data' );
		delete_site_transient( 'wpmdb_licence_response' );

		$result = $this->http->end_ajax( json_encode( array() ) );

		return $result;
	}
}
