<?php

namespace DeliciousBrains\WPMDB\Pro;

use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Settings\Settings;

class Download {

	/**
	 * @var Properties
	 */
	public $props;
	/**
	 * @var
	 */
	public $api;
	/**
	 * @var
	 */
	public $license;
	/**
	 * @var Settings
	 */
	public $settings;

	public function __construct(
		Properties $props,
		Settings $settings
	) {
		$this->props    = $props;
		$this->settings = $settings;
	}

	/**
	 * @param      $plugin_slug
	 * @param bool $is_beta
	 *
	 * @return string
	 */
	function get_plugin_update_download_url( $plugin_slug, $is_beta = false ) {
		$licence = License::get_license();

		$query_args = array(
			'request'     => 'download',
			'licence_key' => $licence,
			'slug'        => $plugin_slug,
			'site_url'    => home_url( '', 'http' ),
		);

		if ( $is_beta ) {
			$query_args['beta'] = '1';
		}

		return add_query_arg( $query_args, Api::get_api_url() );
	}

	/**
	 * @param $response
	 * @param $args
	 * @param $url
	 *
	 * @return \WP_Error
	 */
	function verify_download( $response, $args, $url ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$download_url = $this->get_plugin_update_download_url( $this->props->plugin_slug );

		if ( false === strpos( $url, $download_url ) || 402 != $response['response']['code'] ) {
			return $response;
		}

		// The $response['body'] is blank but output is actually saved to a file in this case
		$data = @file_get_contents( $response['filename'] );

		if ( ! $data ) {
			return new \WP_Error( 'wpmdbpro_download_error_empty', sprintf( __( 'Error retrieving download from deliciousbrains.com. Please try again or download manually from <a href="%1$s">%2$s</a>.', 'wp-migrate-db' ), 'https://deliciousbrains.com/my-account/?utm_campaign=error%2Bmessages&utm_source=MDB%2BPaid&utm_medium=insideplugin', _x( 'My Account', 'Delicious Brains account', 'wp-migrate-db' ) ) );
		}

		$decoded_data = json_decode( $data, true );

		// Can't decode the JSON errors, so just barf it all out
		if ( ! isset( $decoded_data['errors'] ) || ! $decoded_data['errors'] ) {
			return new \WP_Error( 'wpmdbpro_download_error_raw', $data );
		}

		foreach ( $decoded_data['errors'] as $key => $msg ) {
			return new \WP_Error( 'wpmdbpro_' . $key, $msg );
		}
	}

}
