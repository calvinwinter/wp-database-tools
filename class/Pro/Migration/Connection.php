<?php

namespace DeliciousBrains\WPMDB\Pro\Migration;

use DeliciousBrains\WPMDB\Common\Error\ErrorLog;
use DeliciousBrains\WPMDB\Common\Filesystem\Filesystem;
use DeliciousBrains\WPMDB\Common\FormData\FormData;
use DeliciousBrains\WPMDB\Common\Http\Helper;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Http\RemotePost;
use DeliciousBrains\WPMDB\Common\Http\Scramble;
use DeliciousBrains\WPMDB\Common\MigrationState\MigrationState;
use DeliciousBrains\WPMDB\Common\MigrationState\MigrationStateManager;
use DeliciousBrains\WPMDB\Common\Multisite\Multisite;
use DeliciousBrains\WPMDB\Common\Properties\DynamicProperties;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Settings\Settings;
use DeliciousBrains\WPMDB\Common\Sql\Table;
use DeliciousBrains\WPMDB\Common\Sql\TableHelper;
use DeliciousBrains\WPMDB\Common\Util\Util;
use DeliciousBrains\WPMDB\Pro\License;
use DeliciousBrains\WPMDB\Pro\UsageTracking;

class Connection {

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
	 * @var FormData
	 */
	private $form_data;
	/**
	 * @var Settings
	 */
	private $settings;
	/**
	 * @var License
	 */
	private $license;
	/**
	 * @var RemotePost
	 */
	private $remote_post;
	/**
	 * @var Util
	 */
	private $util;
	/**
	 * @var Table
	 */
	private $table;
	/**
	 * @var Filesystem
	 */
	private $filesystem;
	/**
	 * @var DynamicProperties
	 */
	private $dynamic_props;
	/**
	 * @var MigrationState
	 */
	private $migration_state;
	/**
	 * @var Multisite
	 */
	private $multisite;
	/**
	 * @var TableHelper
	 */
	private $table_helper;

	public function __construct(
		Scramble $scrambler,
		MigrationStateManager $migration_state_manager,
		Http $http,
		Helper $http_helper,
		Properties $props,
		ErrorLog $error_log,
		License $license,
		RemotePost $remote_post,
		Util $util,
		Table $table,
		FormData $form_data,
		Settings $settings,
		Filesystem $filesystem,
		MigrationState $migration_state,
		Multisite $multisite,
		TableHelper $table_helper
	) {
		$this->scrambler               = $scrambler;
		$this->migration_state_manager = $migration_state_manager;
		$this->http                    = $http;
		$this->http_helper             = $http_helper;
		$this->props                   = $props;
		$this->error_log               = $error_log;
		$this->form_data               = $form_data;
		$this->settings                = $settings->get_settings();
		$this->license                 = $license;
		$this->remote_post             = $remote_post;
		$this->util                    = $util;
		$this->table                   = $table;
		$this->filesystem              = $filesystem;
		$this->dynamic_props           = DynamicProperties::getInstance();
		$this->migration_state         = $migration_state;
		$this->multisite               = $multisite;
		$this->table_helper            = $table_helper;
	}

	/**
	 * AJAX endpoint for the wpmdb_verify_connection_to_remote_site action.
	 * Verifies that the local site has a valid licence.
	 * Sends a request to the remote site to collect additional information required to complete the migration.
	 *
	 * @return mixed
	 */
	function ajax_verify_connection_to_remote_site() {
		$this->http->check_ajax_referer( 'verify-connection-to-remote-site' );

		$key_rules = apply_filters(
			'wpmdb_key_rules',
			array(
				'action'                      => 'key',
				'url'                         => 'url',
				'key'                         => 'string',
				'intent'                      => 'key',
				'nonce'                       => 'key',
				'convert_post_type_selection' => 'numeric',
				'profile'                     => 'numeric',
			),
			__FUNCTION__
		);

		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		if ( ! $this->license->is_valid_licence() ) {
			$message = __( 'Please activate your license before attempting a pull or push migration.', 'wp-migrate-db' );
			$return  = array( 'wpmdb_error' => 1, 'body' => $message );
			$result  = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		$data = array(
			'action'  => 'wpmdb_verify_connection_to_remote_site',
			'intent'  => $state_data['intent'],
			'referer' => $this->util->get_short_home_address_from_url( home_url() ),
			'version' => $this->props->plugin_version,
		);
		$data = apply_filters( 'wpmdb_verify_connection_to_remote_site_args', $data, $state_data );

		$data['sig']         = $this->http_helper->create_signature( $data, $state_data['key'] );
		$ajax_url            = $this->util->ajax_url();
		$timeout             = apply_filters( 'wpmdb_prepare_remote_connection_timeout', 30 );
		$serialized_response = $this->remote_post->post( $ajax_url, $data, __FUNCTION__, compact( 'timeout' ), true );
		$url_bits            = Util::parse_url( $this->dynamic_props->attempting_to_connect_to );

		if ( false === $serialized_response ) {
			$return = array(
				'wpmdb_error' => 1,
				'body'        => $this->error_log->getError(),
				'scheme'      => $url_bits['scheme'],
			);
			$result = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		$response = Util::unserialize( $serialized_response, __METHOD__ );

		if ( false === $response ) {
			$error_msg = __( 'Failed attempting to unserialize the response from the remote server. Please contact support.', 'wp-migrate-db' );
			$return    = array(
				'wpmdb_error' => 1,
				'body'        => $error_msg,
				'scheme'      => $url_bits['scheme'],
			);
			$this->error_log->log_error( $error_msg, $serialized_response );
			$result = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		if ( isset( $response['error'] ) && $response['error'] == 1 ) {
			$return = array(
				'wpmdb_error' => 1,
				'body'        => $response['message'],
				'scheme'      => $url_bits['scheme'],
			);

			if ( isset( $response['error_id'] ) ) {
				if ( 'version_mismatch' === $response['error_id'] ) {
					$return['body'] = str_replace( '%%plugins_url%%', network_admin_url( 'plugins.php' ), $return['body'] );
				}
			}

			$this->error_log->log_error( $return['body'], $response );
			$result = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		if ( isset( $state_data['convert_post_type_selection'] ) && '1' == $state_data['convert_post_type_selection'] ) {
			$profile = (int) $state_data['profile'];
			unset( $this->settings['profiles'][ $profile ]['post_type_migrate_option'] );
			$this->settings['profiles'][ $profile ]['exclude_post_types'] = '1';
			$this->settings['profiles'][ $profile ]['select_post_types']  = array_values( array_diff( $response['post_types'], $this->settings['profiles'][ $profile ]['select_post_types'] ) );
			$response['select_post_types']                                = $this->settings['profiles'][ $profile ]['select_post_types'];
			update_site_option( 'wpmdb_settings', $this->settings );
		}

		$response['scheme'] = $url_bits['scheme'];
		$return             = json_encode( $response );

		$result = $this->http->end_ajax( $return );

		return $result;
	}

	/**
	 * No privileges AJAX endpoint for the wpmdb_verify_connection_to_remote_site action.
	 * Verifies that the connecting site is using the same version of WP Migrate DB as the local site.
	 * Verifies that the request is originating from a trusted source by verifying the request signature.
	 * Verifies that the local site has a valid licence.
	 * Verifies that the local site is allowed to perform a pull / push migration.
	 * If all is successful, returns an array of local site information used to complete the migration.
	 *
	 * @return mixed
	 */
	function respond_to_verify_connection_to_remote_site() {
		$key_rules  = apply_filters(
			'wpmdb_key_rules',
			array(
				'action'  => 'key',
				'intent'  => 'key',
				'referer' => 'string',
				'version' => 'string',
				'sig'     => 'string',
			),
			__FUNCTION__
		);
		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		$return = array();

		unset( $key_rules['sig'] );
		$filtered_post = $this->http_helper->filter_post_elements( $state_data, array_keys( $key_rules ) );

		// Only scramble response once we know it can be handled.
		add_filter( 'wpmdb_before_response', array( $this->scrambler, 'scramble' ) );

		if ( ! $this->http_helper->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$return['error']   = 1;
			$return['message'] = $this->props->invalid_content_verification_error . ' (#120) <a href="#" class="try-again js-action-link">' . _x( 'Try again?', 'Asking to try and connect to remote server after verification error', 'wp-migrate-db' ) . '</a>';
			$this->error_log->log_error( $this->props->invalid_content_verification_error . ' (#120)', $filtered_post );
			$result = $this->http->end_ajax( serialize( $return ) );

			return $result;
		}

		if ( ! isset( $filtered_post['version'] ) || version_compare( $filtered_post['version'], $this->props->plugin_version, '!=' ) ) {
			$return['error']    = 1;
			$return['error_id'] = 'version_mismatch';

			if ( ! isset( $filtered_post['version'] ) ) {
				$return['message'] = sprintf( __( '<b>Version Mismatch</b> &mdash; We\'ve detected you have version %1$s of WP Migrate DB Pro at %2$s but are using an outdated version here. Please go to the Plugins page on both installs and check for updates.', 'wp-migrate-db' ), $GLOBALS['wpmdb_meta'][ $this->props->plugin_slug ]['version'], $this->util->get_short_home_address_from_url( home_url() ) );
			} else {
				$return['message'] = sprintf( __( '<b>Version Mismatch</b> &mdash; We\'ve detected you have version %1$s of WP Migrate DB Pro at %2$s but are using %3$s here. Please go to the <a href="%4$s">Plugins page</a> on both installs and check for updates.', 'wp-migrate-db' ), $GLOBALS['wpmdb_meta'][ $this->props->plugin_slug ]['version'], $this->util->get_short_home_address_from_url( home_url() ), $filtered_post['version'], '%%plugins_url%%' );
			}

			remove_filter( 'wpmdb_before_response', array( $this->scrambler, 'scramble' ) );

			$this->error_log->log_error( $return['message'], $filtered_post );
			$result = $this->http->end_ajax( serialize( $return ) );

			return $result;
		}

		if ( ! $this->license->is_valid_licence() ) {
			$local_host  = $this->util->get_short_home_address_from_url( home_url() );
			$remote_host = $state_data['referer'];

			$return['error'] = 1;

			$return['message'] = sprintf( __( "Activate remote license &mdash; Looks like you don't have a WP Migrate DB Pro license active at %s.", 'wp-migrate-db' ), $local_host );
			$return['message'] .= ' <a href="#" class="js-action-link copy-licence-to-remote-site">';
			$return['message'] .= sprintf( __( 'Copy %1$s license key to %2$s and activate it', 'wp-migrate-db' ), $remote_host, $local_host );
			$return['message'] .= '</a>';
			$result            = $this->http->end_ajax( serialize( $return ) );

			return $result;
		}

		if ( ! isset( $this->settings[ 'allow_' . $state_data['intent'] ] ) || $this->settings[ 'allow_' . $state_data['intent'] ] != true ) {
			$return['error'] = 1;

			if ( $state_data['intent'] == 'pull' ) {
				$message = __( 'The connection succeeded but the remote site is configured to reject pull connections. You can change this in the "settings" tab on the remote site. (#122)', 'wp-migrate-db' );
			} else {
				$message = __( 'The connection succeeded but the remote site is configured to reject push connections. You can change this in the "settings" tab on the remote site. (#122)', 'wp-migrate-db' );
			}
			$return['message'] = $message . sprintf( ' <a href="#" class="try-again js-action-link">%s</a>', _x( 'Try again?', 'Attempt to connect to the remote server again', 'wp-migrate-db' ) );
			$result            = $this->http->end_ajax( serialize( $return ) );

			return $result;
		}

		$site_details = $this->util->site_details();

		$return['tables']                 = $this->table->get_tables();
		$return['prefixed_tables']        = $this->table->get_tables( 'prefix' );
		$return['table_sizes']            = $this->table->get_table_sizes();
		$return['table_rows']             = $this->table->get_table_row_count();
		$return['table_sizes_hr']         = array_map( array( $this->table, 'format_table_sizes' ), $this->table->get_table_sizes() );
		$return['path']                   = $this->util->get_absolute_root_file_path();
		$return['url']                    = home_url();
		$return['prefix']                 = $site_details['prefix']; // TODO: Remove backwards compatibility.
		$return['bottleneck']             = $this->util->get_bottleneck();
		$return['delay_between_requests'] = $this->settings['delay_between_requests'];
		$return['error']                  = 0;
		$return['plugin_version']         = $this->props->plugin_version;
		$return['domain']                 = $this->multisite->get_domain_current_site();
		$return['path_current_site']      = $this->util->get_path_current_site();
		$return['uploads_dir']            = $site_details['uploads_dir']; // TODO: Remove backwards compatibility.
		$return['gzip']                   = ( Util::gzip() ? '1' : '0' );
		$return['post_types']             = $this->table->get_post_types();
		// TODO: Use WP_Filesystem API.
		$return['write_permissions']      = ( is_writeable( $this->filesystem->get_upload_info( 'path' ) ) ? 'true' : 'false' );
		$return['upload_dir_long']        = $this->filesystem->get_upload_info( 'path' );
		$return['temp_prefix']            = $this->props->temp_prefix;
		$return['lower_case_table_names'] = $this->table->get_lower_case_table_names_setting();
		$return['subsites']               = $site_details['subsites']; // TODO: Remove backwards compatibility.
		$return['site_details']           = $this->util->site_details();
		$return                           = apply_filters( 'wpmdb_establish_remote_connection_data', $return );
		$result                           = $this->http->end_ajax( serialize( $return ) );

		return $result;
	}

	/**
	 * Exports table data from remote site during a Pull migration.
	 *
	 * @return string
	 */
	function respond_to_process_pull_request() {
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
			'current_row'         => 'int',
			'last_table'          => 'positive_int',
			'gzip'                => 'positive_int',
			'primary_keys'        => 'serialized',
			'site_url'            => 'url',
			'find_replace_pairs'  => 'serialized',
			'pull_limit'          => 'positive_int',
			'db_version'          => 'string',
			'path_current_site'   => 'string',
			'domain_current_site' => 'text',
			'prefix'              => 'string',
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
				'current_row',
				'last_table',
				'gzip',
				'primary_keys',
				'site_url',
				'find_replace_pairs',
				'pull_limit',
				'db_version',
				'path_current_site',
				'domain_current_site',
				'prefix',
			)
		);

		$filtered_post['primary_keys']       = stripslashes( $filtered_post['primary_keys'] );
		$filtered_post['find_replace_pairs'] = stripslashes( $filtered_post['find_replace_pairs'] );

		if ( ! $this->http_helper->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			$error_msg = $this->props->invalid_content_verification_error . ' (#124)';
			$this->error_log->log_error( $error_msg, $filtered_post );
			$result = $this->http->end_ajax( $error_msg );

			return $result;
		}

		if ( $this->settings['allow_pull'] != true ) {
			$return = __( 'The connection succeeded but the remote site is configured to reject pull connections. You can change this in the "settings" tab on the remote site. (#141)', 'wp-migrate-db' );
			$return = array( 'wpmdb_error' => 1, 'body' => $return );
			$result = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		if ( ! empty( $filtered_post['db_version'] ) ) {
			$this->dynamic_props->target_db_version = $filtered_post['db_version'];
			add_filter( 'wpmdb_create_table_query', array( $this->table_helper, 'mysql_compat_filter' ), 10, 5 );
		}

		$this->dynamic_props->find_replace_pairs = Util::unserialize( $filtered_post['find_replace_pairs'], __METHOD__ );

		$this->dynamic_props->maximum_chunk_size = $state_data['pull_limit'];
		$this->table->process_table( $state_data['table'] );
		ob_start();
		$this->util->display_errors();
		$return = ob_get_clean();
		$result = $this->http->end_ajax( $return );

		return $result;
	}

	/**
	 * Validates migration request as the remote site and sets up anything that may be needed before the migration starts.
	 *
	 * @return array
	 */
	function respond_to_remote_initiate_migration() {
		add_filter( 'wpmdb_before_response', array( $this->scrambler, 'scramble' ) );

		$key_rules  = array(
			'action'       => 'key',
			'intent'       => 'key',
			'form_data'    => 'string',
			'sig'          => 'string',
			'site_details' => 'serialized',
		);
		$state_data = $this->migration_state_manager->set_post_data( $key_rules );

		global $wpdb;

		$return        = array();
		$filtered_post = $this->http_helper->filter_post_elements(
			$state_data,
			array(
				'action',
				'intent',
				'form_data',
				'site_details',
			)
		);

		$filtered_post['site_details'] = stripslashes( $filtered_post['site_details'] );

		if ( $this->http_helper->verify_signature( $filtered_post, $this->settings['key'] ) ) {
			if ( isset( $this->settings[ 'allow_' . $state_data['intent'] ] ) && ( true === $this->settings[ 'allow_' . $state_data['intent'] ] || 1 === $this->settings[ 'allow_' . $state_data['intent'] ] ) ) {
				$return['error'] = 0;
			} else {
				$return['error'] = 1;
				if ( $state_data['intent'] == 'pull' ) {
					$return['message'] = __( 'The connection succeeded but the remote site is configured to reject pull connections. You can change this in the "settings" tab on the remote site. (#110)', 'wp-migrate-db' );
				} else {
					$return['message'] = __( 'The connection succeeded but the remote site is configured to reject push connections. You can change this in the "settings" tab on the remote site. (#110)', 'wp-migrate-db' );
				}
			}
		} else {
			$return['error'] = 1;
			$error_msg       = $this->props->invalid_content_verification_error . ' (#111)';
			$this->error_log->log_error( $error_msg, $filtered_post );
			$return['message'] = $error_msg;
		}

		// If there is an error, no need to parse args or create migration state.
		if ( ! empty( $return['error'] ) ) {
			$result = $this->http->end_ajax( serialize( $return ) );

			return $result;
		}

		UsageTracking::log_usage( $state_data['intent'] . '-remote' );

		$state_data['site_details'] = Util::unserialize( $filtered_post['site_details'], __METHOD__ );

		$this->form_data = $this->form_data->parse_migration_form_data( $state_data['form_data'] );

		if ( ! empty( $this->form_data['create_backup'] ) && $state_data['intent'] == 'push' ) {
			$return['dump_filename'] = basename( $this->table->get_sql_dump_info( 'backup', 'path' ) );
			$return['dump_filename'] = substr( $return['dump_filename'], 0, - 4 );
			$return['dump_url']      = $this->table->get_sql_dump_info( 'backup', 'url' );
		}

		if ( $state_data['intent'] == 'push' ) {
			// sets up our table to store 'ALTER' queries
			$create_alter_table_query = $this->table->get_create_alter_table_query();
			$process_chunk_result     = $this->table->process_chunk( $create_alter_table_query );
			if ( true !== $process_chunk_result ) {
				$result = $this->http->end_ajax( $process_chunk_result );

				return $result;
			}
			$return['db_version'] = $wpdb->db_version();
			$return['site_url']   = site_url();
		}

		// Store current migration state and return its id.
		$state = array_merge( $state_data, $return );

		$migration_id              = $this->migration_state->id();
		$return['remote_state_id'] = $migration_id;
		$return                    = $this->migration_state_manager->save_migration_state( $state, $return, $migration_id );

		$result = $this->http->end_ajax( serialize( $return ) );

		return $result;
	}
}
