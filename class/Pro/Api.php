<?php

namespace DeliciousBrains\WPMDB\Pro;

use DeliciousBrains\WPMDB\Common\Error\ErrorLog;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Settings\Settings;
use DeliciousBrains\WPMDB\Common\Util\Util;
use DeliciousBrains\WPMDB\Container;

class Api {

	/**
	 * @var string
	 */
	public $dbrains_api_url;
	/**
	 * @var Util
	 */
	public $util;
	/**
	 * @var Properties
	 */
	public $props;
	/**
	 * @var
	 */
	public  $usage_tracker;
	/**
	 * @var
	 */
	public $settings;
	/**
	 * @var ErrorLog
	 */
	public $error_log;
	/**
	 * @var
	 */
	public static $api_url;

	/**
	 *
	 */
	const DBRAINS_API_BASE = 'https://api.deliciousbrains.com';

	public function __construct(
		Util $util,
		Settings $settings,
		ErrorLog $error_log,
		Properties $properties
	) {
		$this->util            = $util;
		$this->props           = $properties;
		$this->settings        = $settings->get_settings();
		$this->error_log       = $error_log;
		$this->dbrains_api_url = self::$api_url = $this->get_dbrains_api_base() . '/?wc-api=delicious-brains';
	}

	public static function get_api_url() {
		return self::$api_url;
	}

	function get_dbrains_api_url( $request, $args = array() ) {
		$url                       = $this->dbrains_api_url;
		$args['request']           = $request;
		$args['version']           = $GLOBALS['wpmdb_meta'][ $this->props->core_slug ]['version'];
		$args['php_version']       = urlencode( PHP_VERSION );
		$args['locale']            = urlencode( get_locale() );
		$args['wordpress_version'] = urlencode( get_bloginfo( 'version' ) );

		if ( 'check_support_access' === $request || 'activate_licence' === $request ) {
			//@TODO refactor usage of Container here
			$args['last_used'] = urlencode( Container::getInstance()->get( 'usage_tracking' )->get_last_usage_time() );
		}

		$url = add_query_arg( $args, $url );
		if ( false !== get_site_transient( 'wpmdb_temporarily_disable_ssl' ) && 0 === strpos( $this->dbrains_api_url, 'https://' ) ) {
			$url = substr_replace( $url, 'http', 0, 5 );
		}

		return $url;
	}

	/**
	 * Main function for communicating with the Delicious Brains API.
	 *
	 * @param string $request
	 * @param array  $args
	 *
	 * @return mixed
	 */
	function dbrains_api_request( $request, $args = array() ) {
		$trans = get_site_transient( 'wpmdb_dbrains_api_down' );

		if ( false !== $trans ) {
			$api_down_message = sprintf( '<div class="updated warning inline-message">%s</div>', $trans );

			return json_encode( array( 'dbrains_api_down' => $api_down_message ) );
		}

		$sslverify = ( $this->settings['verify_ssl'] == 1 ? true : false );

		$url      = $this->get_dbrains_api_url( $request, $args );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 30,
				'blocking'  => true,
				'sslverify' => $sslverify,
			)
		);

		if ( is_wp_error( $response ) || (int) $response['response']['code'] < 200 || (int) $response['response']['code'] > 399 ) {
			$this->error_log->log_error( print_r( $response, true ) );

			if ( true === $this->dbrains_api_down() ) {
				$trans = get_site_transient( 'wpmdb_dbrains_api_down' );

				if ( false !== $trans ) {
					$api_down_message = sprintf( '<div class="updated warning inline-message">%s</div>', $trans );

					return json_encode( array( 'dbrains_api_down' => $api_down_message ) );
				}
			}

			$disable_ssl_url           = network_admin_url( $this->props->plugin_base . '&nonce=' . Util::create_nonce( 'wpmdb-disable-ssl' ) . '&wpmdb-disable-ssl=1' );
			$connection_failed_message = '<div class="updated warning inline-message">';
			$connection_failed_message .= sprintf( __( '<strong>Could not connect to api.deliciousbrains.com</strong> &mdash; You will not receive update notifications or be able to activate your license until this is fixed. This issue is often caused by an improperly configured SSL server (https). We recommend <a href="%1$s" target="_blank">fixing the SSL configuration on your server</a>, but if you need a quick fix you can:%2$s', 'wp-migrate-db' ), 'https://deliciousbrains.com/wp-migrate-db-pro/doc/could-not-connect-deliciousbrains-com/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin', sprintf( '<p><a href="%1$s" class="temporarily-disable-ssl button">%2$s</a></p>', $disable_ssl_url, __( 'Temporarily disable SSL for connections to api.deliciousbrains.com', 'wp-migrate-db' ) ) );
			$connection_failed_message .= '</div>';

			if ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) {
				$url_parts = Util::parse_url( $url );
				$host      = $url_parts['host'];
				if ( ! defined( 'WP_ACCESSIBLE_HOSTS' ) || strpos( WP_ACCESSIBLE_HOSTS, $host ) === false ) {
					$connection_failed_message = '<div class="updated warning inline-message">';
					$connection_failed_message .= sprintf( __( 'We\'ve detected that <code>WP_HTTP_BLOCK_EXTERNAL</code> is enabled and the host <strong>%1$s</strong> has not been added to <code>WP_ACCESSIBLE_HOSTS</code>. Please disable <code>WP_HTTP_BLOCK_EXTERNAL</code> or add <strong>%1$s</strong> to <code>WP_ACCESSIBLE_HOSTS</code> to continue. <a href="%2$s" target="_blank">More information</a>.', 'wp-migrate-db' ), esc_attr( $host ), 'https://deliciousbrains.com/wp-migrate-db-pro/doc/wp_http_block_external/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin' );
					$connection_failed_message .= '</div>';
				}
			}

			// Don't cache the license response so we can try again
			delete_site_transient( 'wpmdb_licence_response' );

			return json_encode( array( 'errors' => array( 'connection_failed' => $connection_failed_message ) ) );
		}

		return $response['body'];
	}

	/**
	 * Is the Delicious Brains API down?
	 *
	 * If not available then a 'wpmdb_dbrains_api_down' transient will be set with an appropriate message.
	 *
	 * @return bool
	 */
	function dbrains_api_down() {
		if ( false !== get_site_transient( 'wpmdb_dbrains_api_down' ) ) {
			return true;
		}

		$response = wp_remote_get( $this->props->dbrains_api_status_url, array( 'timeout' => 30 ) );

		// Can't get to api status url so fall back to normal failure handling.
		if ( is_wp_error( $response ) || 200 !== (int) $response['response']['code'] || empty( $response['body'] ) ) {
			return false;
		}

		$json = json_decode( $response['body'], true );

		// Can't decode json so fall back to normal failure handling.
		if ( ! $json ) {
			return false;
		}

		// JSON doesn't seem to have the format we expect or is not down, so fall back to normal failure handling.
		if ( ! isset( $json['api']['status'] ) || 'down' !== $json['api']['status'] ) {
			return false;
		}

		$message = __( "<strong>Delicious Brains API is Down — </strong>Unfortunately we're experiencing some problems with our server.", 'wp-migrate-db' );

		if ( ! empty( $json['api']['updated'] ) ) {
			$updated     = $json['api']['updated'];
			$updated_ago = sprintf( _x( '%s ago', 'ex. 2 hours ago', 'wp-migrate-db' ), human_time_diff( strtotime( $updated ) ) );
		}

		if ( ! empty( $json['api']['message'] ) ) {
			$message .= '<br />';
			$message .= __( "Here's the most recent update on its status", 'wp-migrate-db' );
			if ( ! empty( $updated_ago ) ) {
				$message .= ' (' . $updated_ago . ')';
			}
			$message .= ': <em>' . $json['api']['message'] . '</em>';
		}

		set_site_transient( 'wpmdb_dbrains_api_down', $message, $this->props->transient_retry_timeout );

		return true;
	}

	public function get_dbrains_api_base() {
		$dbrains_api_base = self::DBRAINS_API_BASE;

		if ( defined( 'DBRAINS_API_BASE' ) ) {
			$dbrains_api_base = DBRAINS_API_BASE;
		}

		if ( false === $this->util->open_ssl_enabled() ) {
			$dbrains_api_base = str_replace( 'https://', 'http://', $dbrains_api_base );
		}

		return $dbrains_api_base;
	}
}
