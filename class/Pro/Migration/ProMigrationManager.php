<?php

namespace DeliciousBrains\WPMDB\Pro\Migration;

use DeliciousBrains\WPMDB\Common\BackupExport;
use DeliciousBrains\WPMDB\Common\Error\ErrorLog;
use DeliciousBrains\WPMDB\Common\FormData\FormData;
use DeliciousBrains\WPMDB\Common\Http\Helper;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Http\Scramble;
use DeliciousBrains\WPMDB\Common\Migration\MigrationManager;
use DeliciousBrains\WPMDB\Common\MigrationState\MigrationStateManager;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Settings\Settings;
use DeliciousBrains\WPMDB\Common\Sql\Table;

/**
 * Class MigrationManager
 *
 * Handle general migration AJAX actions and filters
 *
 * @package DeliciousBrains\WPMDB\Pro\Migration
 */
class ProMigrationManager {

	protected $settings;
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

	private $scrambler;
	/**
	 * @var ErrorLog
	 */
	private $error_log;
	/**
	 * @var Properties
	 */
	private $props;
	/**
	 * @var FormData
	 */
	private $form_data;
	/**
	 * @var MigrationManager
	 */
	private $migration_manager;
	/**
	 * @var Table
	 */
	private $table;
	/**
	 * @var BackupExport
	 */
	private $backup_export;
	/**
	 * @var Connection
	 */
	private $connection;
	/**
	 * @var FinalizeComplete
	 */
	private $finalize_complete;

	public function __construct(
		Scramble $scrambler,
		Settings $settings,
		MigrationStateManager $migration_state_manager,
		Http $http,
		Helper $http_helper,
		ErrorLog $error_log,
		Properties $props,
		FormData $form_data,
		MigrationManager $migration_manager,
		Table $table,
		BackupExport $backup_export,
		Connection $connection,
		FinalizeComplete $finalize_complete
	) {
		$this->scrambler               = $scrambler;
		$this->settings                = $settings->get_settings();
		$this->migration_state_manager = $migration_state_manager;
		$this->http                    = $http;
		$this->http_helper             = $http_helper;
		$this->error_log               = $error_log;
		$this->props                   = $props;
		$this->form_data               = $form_data;
		$this->migration_manager       = $migration_manager;
		$this->table                   = $table;
		$this->backup_export           = $backup_export;
		$this->connection              = $connection;
		$this->finalize_complete       = $finalize_complete;
	}

	public function register() {
		// Internal AJAX handlers
		add_action( 'wp_ajax_wpmdb_verify_connection_to_remote_site', array( $this->connection, 'ajax_verify_connection_to_remote_site' ) );
		add_action( 'wp_ajax_wpmdb_fire_migration_complete', array( $this->finalize_complete, 'fire_migration_complete' ) );

		// external AJAX handlers
		add_action( 'wp_ajax_nopriv_wpmdb_verify_connection_to_remote_site', array( $this->connection, 'respond_to_verify_connection_to_remote_site' ) );
		add_action( 'wp_ajax_nopriv_wpmdb_remote_initiate_migration', array( $this->connection, 'respond_to_remote_initiate_migration' ) );
		add_action( 'wp_ajax_nopriv_wpmdb_process_chunk', array( $this, 'respond_to_process_chunk' ) );
		add_action( 'wp_ajax_nopriv_wpmdb_process_pull_request', array( $this->connection, 'respond_to_process_pull_request' ) );
		add_action( 'wp_ajax_nopriv_wpmdb_fire_migration_complete', array( $this->finalize_complete, 'fire_migration_complete' ) );
		add_action( 'wp_ajax_nopriv_wpmdb_backup_remote_table', array( $this, 'respond_to_backup_remote_table' ) );
		add_action( 'wp_ajax_nopriv_wpmdb_remote_finalize_migration', array( $this->finalize_complete, 'respond_to_remote_finalize_migration' ) );
		add_action( 'wp_ajax_nopriv_wpmdb_remote_flush', array( $this->finalize_complete, 'respond_to_remote_flush' ) );
		add_action( 'wp_ajax_nopriv_wpmdb_process_push_migration_cancellation', array( $this, 'respond_to_process_push_migration_cancellation' ) );
	}

	/**
	 * Handler for the ajax request to process a chunk of data (e.g. SQL inserts).
	 *
	 * @return bool|null
	 */
	function respond_to_process_chunk() {
		add_filter( 'wpmdb_before_response', array( $this->scrambler, 'scramble' ) );

		$key_rules = array(
			'action'          => 'key',
			'remote_state_id' => 'key',
			'table'           => 'string',
			'chunk_gzipped'   => 'positive_int',
			'sig'             => 'string',
		);

		$state_data = $this->migration_state_manager->set_post_data( $key_rules, 'remote_state_id' );

		$filtered_post = $this->http_helper->filter_post_elements( $state_data, array(
				'action',
				'remote_state_id',
				'table',
				'chunk_gzipped',
			)
		);

		$gzip = ( isset( $state_data['chunk_gzipped'] ) && $state_data['chunk_gzipped'] );

		$tmp_file_name = 'chunk.txt';

		if ( $gzip ) {
			$tmp_file_name .= '.gz';
		}

		$tmp_file_path = wp_tempnam( $tmp_file_name );

		if ( ! isset( $_FILES['chunk']['tmp_name'] ) || ! move_uploaded_file( $_FILES['chunk']['tmp_name'], $tmp_file_path ) ) {
			$result = $this->http->end_ajax( __( 'Could not upload the SQL to the server. (#135)', 'wp-migrate-db' ) );

			return $result;
		}

		if ( false === ( $chunk = file_get_contents( $tmp_file_path ) ) ) {
			$result = $this->http->end_ajax( __( 'Could not read the SQL file we uploaded to the server. (#136)', 'wp-migrate-db' ) );

			return $result;
		}

		// TODO: Use WP_Filesystem API.
		@unlink( $tmp_file_path );

		$filtered_post['chunk'] = $chunk;

		if ( ! $this->http_helper->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$error_msg = $this->props->invalid_content_verification_error . ' (#130)';
			$this->error_log->log_error( $error_msg, $filtered_post );
			$result = $this->http->end_ajax( $error_msg );

			return $result;
		}

		if ( $this->settings['allow_push'] != true ) {
			$result = $this->http->end_ajax( __( 'The connection succeeded but the remote site is configured to reject push connections. You can change this in the "settings" tab on the remote site. (#139)', 'wp-migrate-db' ) );

			return $result;
		}

		if ( $gzip ) {
			$filtered_post['chunk'] = gzuncompress( $filtered_post['chunk'] );
		}

		$process_chunk_result = $this->table->process_chunk( $filtered_post['chunk'] );
		$result               = $this->http->end_ajax( $process_chunk_result );

		return $result;
	}

	/**
	 * The remote's handler for requests to backup a table.
	 *
	 * @return bool|mixed|null
	 */
	function respond_to_backup_remote_table() {
		add_filter( 'wpmdb_before_response', array( $this->scrambler, 'scramble' ) );

		$key_rules  = array(
			'action'              => 'key',
			'remote_state_id'     => 'key',
			'intent'              => 'key',
			'url'                 => 'url',
			'table'               => 'string',
			'form_data'           => 'string',
			'stage'               => 'key',
			'bottleneck'          => 'positive_int',
			'prefix'              => 'string',
			'current_row'         => 'int',
			'last_table'          => 'positive_int',
			'gzip'                => 'positive_int',
			'primary_keys'        => 'serialized',
			'path_current_site'   => 'string',
			'domain_current_site' => 'text',
			'sig'                 => 'string',
		);
		$state_data = $this->migration_state_manager->set_post_data( $key_rules, 'remote_state_id' );

		$filtered_post = $this->http_helper->filter_post_elements(
			$state_data,
			array(
				'action',
				'remote_state_id',
				'intent',
				'url',
				'table',
				'form_data',
				'stage',
				'bottleneck',
				'prefix',
				'current_row',
				'last_table',
				'gzip',
				'primary_keys',
				'path_current_site',
				'domain_current_site',
			)
		);

		$filtered_post['primary_keys'] = stripslashes( $filtered_post['primary_keys'] );

		if ( ! $this->http_helper->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$error_msg = $this->props->invalid_content_verification_error . ' (#137)';
			$this->error_log->log_error( $error_msg, $filtered_post );
			$result = $this->http->end_ajax( $error_msg );

			return $result;
		}

		$this->form_data = $this->form_data->parse_migration_form_data( $state_data['form_data'] );
		$result          = $this->migration_manager->handle_table_backup();

		return $result;
	}

	/**
	 * Handler for a request to the remote to cancel a migration.
	 *
	 * @return bool|string
	 */
	function respond_to_process_push_migration_cancellation() {
		add_filter( 'wpmdb_before_response', array( $this->scrambler, 'scramble' ) );

		$key_rules  = array(
			'action'          => 'key',
			'remote_state_id' => 'key',
			'intent'          => 'key',
			'url'             => 'url',
			'form_data'       => 'string',
			'temp_prefix'     => 'string',
			'stage'           => 'key',
			'dump_filename'   => 'string',
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
				'temp_prefix',
				'stage',
				'dump_filename',
			)
		);

		if ( ! $this->http_helper->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$result = $this->http->end_ajax( esc_html( $this->props->invalid_content_verification_error ) );

			return $result;
		}

		$this->form_data = $this->form_data->parse_migration_form_data( $filtered_post['form_data'] );

		if ( $filtered_post['stage'] == 'backup' && ! empty( $state_data['dumpfile_created'] ) ) {
			$this->backup_export->delete_export_file( $filtered_post['dump_filename'], true );
		} else {
			$this->table->delete_temporary_tables( $filtered_post['temp_prefix'] );
		}

		do_action( 'wpmdb_respond_to_push_cancellation' );

		$result = $this->http->end_ajax( true );

		return $result;
	}
}
