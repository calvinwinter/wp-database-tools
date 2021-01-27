<?php

namespace DeliciousBrains\WPMDB\Pro\Migration;

use DeliciousBrains\WPMDB\Common\Error\ErrorLog;
use DeliciousBrains\WPMDB\Common\FormData\FormData;
use DeliciousBrains\WPMDB\Common\Http\Helper;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Http\Scramble;
use DeliciousBrains\WPMDB\Common\Migration\FinalizeMigration;
use DeliciousBrains\WPMDB\Common\Migration\MigrationManager;
use DeliciousBrains\WPMDB\Common\MigrationState\MigrationStateManager;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Settings\Settings;

class FinalizeComplete {

	/**
	 * @var Scramble
	 */
	private $scrambler;
	/**
	 * @var MigrationStateManager
	 */
	private $migration_state_manager;
	/**
	 * @var Http
	 */
	private $http;
	/**
	 * @var Helper
	 */
	private $http_helper;
	/**
	 * @var Properties
	 */
	private $props;
	/**
	 * @var ErrorLog
	 */
	private $error_log;
	/**
	 * @var MigrationManager
	 */
	private $migration_manager;
	/**
	 * @var FormData
	 */
	private $form_data;
	/**
	 * @var FinalizeMigration
	 */
	private $finalize;
	/**
	 * @var Settings
	 */
	private $settings;

	public function __construct(
		Scramble $scrambler,
		MigrationStateManager $migration_state_manager,
		Http $http,
		Helper $http_helper,
		Properties $props,
		ErrorLog $error_log,
		MigrationManager $migration_manager,
		FormData $form_data,
		FinalizeMigration $finalize,
		Settings $settings
	) {
		$this->scrambler               = $scrambler;
		$this->migration_state_manager = $migration_state_manager;
		$this->http                    = $http;
		$this->http_helper             = $http_helper;
		$this->props                   = $props;
		$this->error_log               = $error_log;
		$this->migration_manager       = $migration_manager;
		$this->form_data               = $form_data;
		$this->finalize                = $finalize;
		$this->settings                = $settings->get_settings();
	}

	/**
	 * Perform flushes on remote.
	 *
	 * @return bool|null
	 */
	function respond_to_remote_flush() {
		add_filter( 'wpmdb_before_response', array( $this->scrambler, 'scramble' ) );

		$key_rules  = array(
			'action' => 'key',
			'sig'    => 'string',
		);
		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		$filtered_post = $this->http_helper->filter_post_elements( $state_data, array( 'action' ) );

		if ( ! $this->http_helper->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$error_msg = $this->props->invalid_content_verification_error . ' (#123)';
			$this->error_log->log_error( $error_msg, $filtered_post );
			$result = $this->http->end_ajax( $error_msg );

			return $result;
		}

		$return = $this->migration_manager->flush();
		$result = $this->http->end_ajax( $return );

		return $result;
	}

	/**
	 * The remote's handler for a request to finalize a migration.
	 *
	 * @return bool|null
	 */
	function respond_to_remote_finalize_migration() {
		add_filter( 'wpmdb_before_response', array( $this->scrambler, 'scramble' ) );

		$key_rules = array(
			'action'          => 'key',
			'remote_state_id' => 'key',
			'intent'          => 'key',
			'url'             => 'url',
			'form_data'       => 'string',
			'tables'          => 'string',
			'temp_prefix'     => 'string',
			'prefix'          => 'string',
			'type'            => 'key',
			'location'        => 'url',
			'sig'             => 'string',
		);

		$state_data = $this->migration_state_manager->set_post_data( $key_rules, 'remote_state_id' );

		$filtered_post = $this->http_helper->filter_post_elements(
			$state_data,
			array(
				'action',
				'remote_state_id',
				'intent',
				'url',
				'form_data',
				'tables',
				'temp_prefix',
				'prefix',
				'type',
				'location',
			)
		);

		if ( ! $this->http_helper->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$error_msg = $this->props->invalid_content_verification_error . ' (#123)';
			$this->error_log->log_error( $error_msg, $filtered_post );
			$result = $this->http->end_ajax( $error_msg );

			return $result;
		}

		$this->form_data = $this->form_data->parse_migration_form_data( $state_data['form_data'] );

		$return = $this->finalize->finalize_migration();
		$result = $this->http->end_ajax( $return );

		return $result;
	}

	/**
	 * Triggers the wpmdb_migration_complete action once the migration is complete.
	 *
	 * @return bool|null
	 */
	function fire_migration_complete() {
		$state_data    = $this->migration_state_manager->set_post_data(
			[
				'action' => 'string',
				'url' => 'string',
				'sig' => 'string'
			]
		);
		$filtered_post = $this->http_helper->filter_post_elements( $state_data, array( 'action', 'url' ) );

		if ( ! $this->http_helper->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$error_msg = $this->props->invalid_content_verification_error . ' (#138)';
			$this->error_log->log_error( $error_msg, $filtered_post );
			$result = $this->http->end_ajax( $error_msg );

			return $result;
		}

		do_action( 'wpmdb_migration_complete', 'pull', $state_data['url'] );
		$result = $this->http->end_ajax( true );

		return $result;
	}
}
