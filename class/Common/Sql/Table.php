<?php

namespace DeliciousBrains\WPMDB\Common\Sql;

use DeliciousBrains\WPMDB\Common\Error\ErrorLog;
use DeliciousBrains\WPMDB\Common\Filesystem\Filesystem;
use DeliciousBrains\WPMDB\Common\FormData\FormData;
use DeliciousBrains\WPMDB\Common\Http\Helper;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Http\RemotePost;
use DeliciousBrains\WPMDB\Common\MigrationState\MigrationState;
use DeliciousBrains\WPMDB\Common\MigrationState\MigrationStateManager;
use DeliciousBrains\WPMDB\Common\Multisite\Multisite;
use DeliciousBrains\WPMDB\Common\Properties\DynamicProperties;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Replace;
use DeliciousBrains\WPMDB\Common\Util\Util;

class Table {

	/**
	 * @var int
	 */
	public $max_insert_string_len = 50000;
	/**
	 * @var mixed|void
	 */
	public $rows_per_segment;
	/**
	 * @var
	 */
	public $create_alter_table_query;
	/**
	 * @var Filesystem
	 */
	public $filesystem;
	/**
	 * @var
	 */
	public $form_data_container;
	/**
	 * @var FormData
	 */
	public $form_data;
	/**
	 * @var
	 */
	public $query_template;
	/**
	 * @var
	 */
	public $primary_keys;
	/**
	 * @var
	 */
	public $row_tracker;
	/**
	 * @var
	 */
	public $query_buffer;
	/**
	 * @var
	 */
	public $current_chunk;
	/**
	 * @var Properties
	 */
	public $props;
	/**
	 * @var
	 */
	public $state_data;
	/**
	 * @var
	 */
	public $first_select;
	/**
	 * @var
	 */
	public $wpdb;
	/**
	 * @var DynamicProperties
	 */
	public $dynamic_props;
	/**
	 * @var Replace
	 */
	public $replace;
	/**
	 * @var Util
	 */
	private $util;
	/**
	 * @var ErrorLog
	 */
	private $error_log;
	/**
	 * @var MigrationStateManager
	 */
	private $migration_state_manager;
	/**
	 * @var TableHelper
	 */
	private $table_helper;
	/**
	 * @var Multisite
	 */
	private $multisite;
	/**
	 * @var Http
	 */
	private $http;
	/**
	 * @var Helper
	 */
	private $http_helper;
	/**
	 * @var RemotePost
	 */
	private $remote_post;
	/**
	 * @var
	 */
	private $query_size;

	/**
	 * Table constructor.
	 *
	 * @param Filesystem            $filesystem
	 * @param Util                  $util
	 * @param ErrorLog              $error_log
	 * @param MigrationStateManager $migration_state_manager
	 * @param FormData              $form_data
	 * @param TableHelper           $table_helper
	 * @param Multisite             $multisite
	 * @param Http                  $http
	 * @param Helper                $http_helper
	 * @param RemotePost            $remote_post
	 * @param Properties            $properties
	 * @param Replace               $replace
	 */
	public function __construct(
		Filesystem $filesystem,
		Util $util,
		ErrorLog $error_log,
		MigrationStateManager $migration_state_manager,
		FormData $form_data,
		TableHelper $table_helper,
		Multisite $multisite,
		Http $http,
		Helper $http_helper,
		RemotePost $remote_post,
		Properties $properties,
		Replace $replace
	) {

		$this->rows_per_segment = apply_filters( 'wpmdb_rows_per_segment', 100 );

		$this->dynamic_props           = DynamicProperties::getInstance();
		$this->form_data               = $form_data;
		$this->form_data_arr           = $form_data->getFormData();
		$this->props                   = $properties;
		$this->filesystem              = $filesystem;
		$this->util                    = $util;
		$this->error_log               = $error_log;
		$this->migration_state_manager = $migration_state_manager;
		$this->table_helper            = $table_helper;
		$this->multisite               = $multisite;
		$this->http                    = $http;
		$this->http_helper             = $http_helper;
		$this->remote_post             = $remote_post;
		$this->replace                 = $replace;
	}

	/**
	 * Returns an array of table names with associated size in kilobytes.
	 *
	 * @return mixed
	 *
	 * NOTE: Returned array may have been altered by wpmdb_table_sizes filter.
	 */
	function get_table_sizes() {
		global $wpdb;

		static $return;

		if ( ! empty( $return ) ) {
			return $return;
		}

		$return = array();

		$sql = $wpdb->prepare(
			"SELECT TABLE_NAME AS 'table',
			ROUND( ( data_length + index_length ) / 1024, 0 ) AS 'size'
			FROM INFORMATION_SCHEMA.TABLES
			WHERE table_schema = %s
			AND table_type = %s
			ORDER BY TABLE_NAME",
			DB_NAME,
			'BASE TABLE'
		);

		$results = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! empty( $results ) ) {
			foreach ( $results as $result ) {
				if ( $this->get_legacy_alter_table_name() == $result['table'] ) {
					continue;
				}
				$return[ $result['table'] ] = $result['size'];
			}
		}

		// "regular" is passed to the filter as the scope for backwards compatibility (a possible but never used scope was "temp").
		return apply_filters( 'wpmdb_table_sizes', $return, 'regular' );
	}

	/**
	 * Returns the table name where the alter statements are held during the migration (old "wp_" prefixed style).
	 *
	 * @return string
	 */
	function get_legacy_alter_table_name() {
		static $alter_table_name;

		if ( ! empty( $alter_table_name ) ) {
			return $alter_table_name;
		}

		global $wpdb;
		$alter_table_name = apply_filters( 'wpmdb_alter_table_name', $wpdb->base_prefix . 'wpmdb_alter_statements' );

		return $alter_table_name;
	}

	/**
	 * Returns an array of table names with their associated row counts.
	 *
	 * @return array
	 */
	function get_table_row_count() {
		global $wpdb;

		$sql     = $wpdb->prepare( 'SELECT TABLE_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s ORDER BY TABLE_NAME', DB_NAME );
		$results = $wpdb->get_results( $sql, ARRAY_A );

		$return = array();

		foreach ( $results as $result ) {
			if ( $this->get_legacy_alter_table_name() == $result['TABLE_NAME'] ) {
				continue;
			}
			$return[ $result['TABLE_NAME'] ] = ( $result['TABLE_ROWS'] == 0 ? 1 : $result['TABLE_ROWS'] );
		}

		return $return;
	}

	function format_table_sizes( $size ) {
		$size *= 1024;

		return size_format( $size );
	}

	function get_lower_case_table_names_setting() {
		global $wpdb;

		$setting = $wpdb->get_var( "SHOW VARIABLES LIKE 'lower_case_table_names'", 1 );

		return empty( $setting ) ? '-1' : $setting;
	}

	function get_sql_dump_info( $migration_type, $info_type ) {
		$session_salt = strtolower( wp_generate_password( 5, false, false ) );

		$datetime  = date( 'YmdHis' );
		$ds        = ( $info_type == 'path' ? DIRECTORY_SEPARATOR : '/' );
		$dump_info = sprintf( '%s%s%s-%s-%s-%s.sql', $this->filesystem->get_upload_info( $info_type ), $ds, sanitize_title_with_dashes( DB_NAME ), $migration_type, $datetime, $session_salt );

		return ( $info_type == 'path' ? $this->filesystem->slash_one_direction( $dump_info ) : $dump_info );
	}

	/**
	 * Returns SQL queries used to preserve options in the
	 * wp_options or wp_sitemeta tables during a migration.
	 *
	 * @param array  $temp_tables
	 * @param string $intent
	 *
	 * @return string DELETE and INSERT SQL queries separated by a newline character (\n).
	 */
	function get_preserved_options_queries( $temp_tables, $intent = '' ) {
		// @TODO - Come back to usage of `set_post_data()` in every method and optimize
		$state_data = $this->migration_state_manager->set_post_data();
		$form_data  = $this->form_data->getFormData();
		global $wpdb;

		$sql                 = '';
		$sitemeta_table_name = '';
		$options_table_names = array();

		$temp_prefix  = isset( $state_data['temp_prefix'] ) ? $state_data['temp_prefix'] : $this->props->temp_prefix;
		$table_prefix = isset( $state_data['prefix'] ) ? $state_data['prefix'] : $wpdb->base_prefix;
		$prefix       = esc_sql( $temp_prefix . $table_prefix );

		foreach ( $temp_tables as $temp_table ) {
			$table = $wpdb->base_prefix . str_replace( $prefix, '', $temp_table );

			// Get sitemeta table
			if ( is_multisite() && $this->table_helper->table_is( 'sitemeta', $table ) ) {
				$sitemeta_table_name = $temp_table;
			}

			// Get array of options tables
			if ( $this->table_helper->table_is( 'options', $table ) ) {
				$options_table_names[] = $temp_table;
			}
		}

		// Return if multisite but sitemeta and option tables not in migration scope
		if ( is_multisite() && true === empty( $sitemeta_table_name ) && true === empty( $options_table_names ) ) {
			return $sql;
		}

		// Return if options tables not in migration scope for non-multisite.
		if ( ! is_multisite() && true === empty( $options_table_names ) ) {
			return $sql;
		}

		$preserved_options = array(
			'wpmdb_settings',
			'wpmdb_error_log',
			'wpmdb_file_ignores',
			'wpmdb_schema_version',
			'upload_path',
			'upload_url_path',
			'blog_public',
		);

		$preserved_sitemeta_options = $preserved_options;

		if ( false === empty( $form_data['keep_active_plugins'] ) ) {
			$preserved_options[]          = 'active_plugins';
			$preserved_sitemeta_options[] = 'active_sitewide_plugins';
		}

		if ( is_multisite() ) {
			// Get preserved data in site meta table if being replaced.
			if ( ! empty( $sitemeta_table_name ) ) {
				$table = $wpdb->base_prefix . str_replace( $prefix, '', $sitemeta_table_name );

				$preserved_migration_state_options = $wpdb->get_results(
					"SELECT `meta_key` FROM `{$table}` WHERE `meta_key` LIKE '" . MigrationState::OPTION_PREFIX . "%'",
					OBJECT_K
				);

				if ( ! empty( $preserved_migration_state_options ) ) {
					$preserved_sitemeta_options = array_merge( $preserved_sitemeta_options, array_keys( $preserved_migration_state_options ) );
				}

				$preserved_sitemeta_options         = apply_filters( 'wpmdb_preserved_sitemeta_options', $preserved_sitemeta_options, $intent );
				$preserved_sitemeta_options_escaped = esc_sql( $preserved_sitemeta_options );

				$preserved_sitemeta_options_data = $wpdb->get_results(
					sprintf(
						"SELECT * FROM `{$table}` WHERE `meta_key` IN ('%s')",
						implode( "','", $preserved_sitemeta_options_escaped )
					),
					ARRAY_A
				);

				$preserved_sitemeta_options_data = apply_filters( 'wpmdb_preserved_sitemeta_options_data', $preserved_sitemeta_options_data, $intent );

				// Create preserved data queries for site meta table
				foreach ( $preserved_sitemeta_options_data as $option ) {
					$sql .= $wpdb->prepare( "DELETE FROM `{$sitemeta_table_name}` WHERE `meta_key` = %s;\n", $option['meta_key'] );
					$sql .= $wpdb->prepare(
						"INSERT INTO `{$sitemeta_table_name}` ( `meta_id`, `site_id`, `meta_key`, `meta_value` ) VALUES ( NULL , %s, %s, %s );\n",
						$option['site_id'],
						$option['meta_key'],
						$option['meta_value']
					);
				}
			}
		} else {
			$preserved_migration_state_options = $wpdb->get_results(
				"SELECT `option_name` FROM `{$wpdb->options}` WHERE `option_name` LIKE '" . MigrationState::OPTION_PREFIX . "%'",
				OBJECT_K
			);

			if ( ! empty( $preserved_migration_state_options ) ) {
				$preserved_options = array_merge( $preserved_options, array_keys( $preserved_migration_state_options ) );
			}
		}

		// Get preserved data in options tables if being replaced.
		if ( ! empty( $options_table_names ) ) {
			$preserved_options         = apply_filters( 'wpmdb_preserved_options', $preserved_options, $intent );
			$preserved_options_escaped = esc_sql( $preserved_options );

			$preserved_options_data = array();

			// Get preserved data in options tables
			foreach ( $options_table_names as $option_table ) {
				$table = $wpdb->base_prefix . str_replace( $prefix, '', $option_table );

				$preserved_options_data[ $option_table ] = $wpdb->get_results(
					sprintf(
						"SELECT * FROM `{$table}` WHERE `option_name` IN ('%s')",
						implode( "','", $preserved_options_escaped )
					),
					ARRAY_A
				);
			}

			$preserved_options_data = apply_filters( 'wpmdb_preserved_options_data', $preserved_options_data, $intent );

			// Create preserved data queries for options tables
			foreach ( $preserved_options_data as $key => $value ) {
				if ( false === empty( $value ) ) {
					foreach ( $value as $option ) {
						$sql .= $wpdb->prepare(
							"DELETE FROM `{$key}` WHERE `option_name` = %s;\n",
							$option['option_name']
						);

						$sql .= $wpdb->prepare(
							"INSERT INTO `{$key}` ( `option_id`, `option_name`, `option_value`, `autoload` ) VALUES ( NULL , %s, %s, %s );\n",
							$option['option_name'],
							$option['option_value'],
							$option['autoload']
						);
					}
				}
			}
		}

		return $sql;
	}

	/**
	 * Preserves the active_plugins option.
	 *
	 * @param array $preserved_options
	 *
	 * @return array
	 */
	function preserve_active_plugins_option( $preserved_options ) {
		$state_data          = $this->migration_state_manager->set_post_data();
		$keep_active_plugins = $this->util->profile_value( 'keep_active_plugins', $this->form_data->parse_migration_form_data( $state_data['form_data'] ) );

		if ( empty( $keep_active_plugins ) ) {
			$preserved_options[] = 'active_plugins';
		}

		return $preserved_options;
	}

	/**
	 * Preserves WPMDB plugins if the "Keep active plugins" option isn't checked.
	 *
	 * @param array $preserved_options_data
	 *
	 * @return array
	 */
	function preserve_wpmdb_plugins( $preserved_options_data ) {
		$state_data          = $this->migration_state_manager->set_post_data();
		$keep_active_plugins = $this->util->profile_value( 'keep_active_plugins', $this->form_data->parse_migration_form_data( $state_data['form_data'] ) );

		if ( ! empty( $keep_active_plugins ) || empty( $preserved_options_data ) ) {
			return $preserved_options_data;
		}

		foreach ( $preserved_options_data as $table => $data ) {
			foreach ( $data as $key => $option ) {
				if ( 'active_plugins' === $option['option_name'] ) {
					global $wpdb;

					$table_name       = esc_sql( $table );
					$option_value     = Util::unserialize( $option['option_value'] );
					$migrated_plugins = array();
					$wpmdb_plugins    = array();

					if ( $result = $wpdb->get_var( "SELECT option_value FROM $table_name WHERE option_name = 'active_plugins'" ) ) {
						$unserialized = Util::unserialize( $result );
						if ( is_array( $unserialized ) ) {
							$migrated_plugins = $unserialized;
						}
					}

					foreach ( $option_value as $plugin_key => $plugin ) {
						if ( 0 === strpos( $plugin, 'wp-migrate-db' ) ) {
							$wpmdb_plugins[] = $plugin;
						}
					}

					$merged_plugins                           = array_unique( array_merge( $wpmdb_plugins, $migrated_plugins ) );
					$option['option_value']                   = serialize( $merged_plugins );
					$preserved_options_data[ $table ][ $key ] = $option;
					break;
				}
			}
		}

		return $preserved_options_data;
	}

	/**
	 * Loops over data in the provided table to perform a migration.
	 *
	 * @param string $table
	 *
	 * @return mixed
	 */
	function process_table( $table, $fp = null ) {
		global $wpdb;

		$state_data = $state_data = $this->migration_state_manager->set_post_data();

		// Setup form data
		$this->form_data->setup_form_data();
		$form_data = $this->form_data->getFormData();

		$temp_prefix       = ( isset( $state_data['temp_prefix'] ) ? $state_data['temp_prefix'] : $this->props->temp_prefix );
		$site_details      = empty( $state_data['site_details'] ) ? array() : $state_data['site_details'];
		$target_table_name = apply_filters( 'wpmdb_target_table_name', $table, $form_data['action'], $state_data['stage'], $site_details );
		$temp_table_name   = $state_data["intent"] === 'import' ? $target_table_name : $temp_prefix . $target_table_name;
		$structure_info    = $this->get_structure_info( $table );
		$row_start         = $this->get_current_row();
		$this->row_tracker = $row_start;

		if ( ! is_array( $structure_info ) ) {
			return $structure_info;
		}

		$this->pre_process_data( $table, $target_table_name, $temp_table_name, $fp );

		do {
			// Build and run the query
			$select_sql = $this->build_select_query( $table, $row_start, $structure_info );
			$table_data = $wpdb->get_results( $select_sql );

			if ( ! is_array( $table_data ) ) {
				continue;
			}

			$to_search  = isset( $this->dynamic_props->find_replace_pairs['replace_old'] ) ? $this->dynamic_props->find_replace_pairs['replace_old'] : '';
			$to_replace = isset( $this->dynamic_props->find_replace_pairs['replace_new'] ) ? $this->dynamic_props->find_replace_pairs['replace_new'] : '';
			$replacer   = $this->replace->register( array(
				'table'        => ( 'find_replace' === $state_data['stage'] ) ? $temp_table_name : $table,
				'search'       => $to_search,
				'replace'      => $to_replace,
				'intent'       => $state_data['intent'],
				'base_domain'  => $this->multisite->get_domain_replace(),
				'site_domain'  => $this->multisite->get_domain_current_site(),
				'wpmdb'        => $this,
				'site_details' => $site_details,
			) );

			$this->start_query_buffer( $target_table_name, $temp_table_name, $structure_info );

			// Loop over the results
			foreach ( $table_data as $row ) {
				$result = $this->process_row( $table, $replacer, $row, $structure_info, $fp );
				if ( ! is_bool( $result ) ) {
					return $result;
				}
			}

			$this->stow_query_buffer( $fp );
			$row_start += $this->rows_per_segment;

		} while ( count( $table_data ) > 0 );

		// Finalize and return.
		$this->post_process_data( $table, $target_table_name, $fp );

		return $this->transfer_chunk( $fp );
	}

	/**
	 * Parses the provided table structure.
	 *
	 * @param array $table_structure
	 *
	 * @return array
	 */
	function get_structure_info( $table, $table_structure = array() ) {
		$state_data = $state_data = $this->migration_state_manager->set_post_data();

		if ( empty( $table_structure ) ) {
			$table_structure = $this->get_table_structure( $table );
		}

		if ( ! is_array( $table_structure ) ) {
			$this->error_log->log_error( $this->error_log->getError() );
			$return = array( 'wpmdb_error' => 1, 'body' => $this->error_log->getError() );
			$result = $this->http->end_ajax( json_encode( $return ) );

			return $result;
		}

		// $defs = mysql defaults, looks up the default for that particular column, used later on to prevent empty inserts values for that column
		// $ints = holds a list of the possible integer types so as to not wrap them in quotation marks later in the insert statements
		$defs               = array();
		$ints               = array();
		$bins               = array();
		$bits               = array();
		$field_set          = array();
		$this->primary_keys = array();
		$use_primary_keys   = true;

		foreach ( $table_structure as $struct ) {
			if ( ( 0 === strpos( $struct->Type, 'tinyint' ) ) ||
			     ( 0 === strpos( strtolower( $struct->Type ), 'smallint' ) ) ||
			     ( 0 === strpos( strtolower( $struct->Type ), 'mediumint' ) ) ||
			     ( 0 === strpos( strtolower( $struct->Type ), 'int' ) ) ||
			     ( 0 === strpos( strtolower( $struct->Type ), 'bigint' ) )
			) {
				$defs[ strtolower( $struct->Field ) ] = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
				$ints[ strtolower( $struct->Field ) ] = '1';
			} elseif ( 0 === strpos( $struct->Type, 'binary' ) || apply_filters( 'wpmdb_process_column_as_binary', false, $struct ) ) {
				$bins[ strtolower( $struct->Field ) ] = '1';
			} elseif ( 0 === strpos( $struct->Type, 'bit' ) || apply_filters( 'wpmdb_process_column_as_bit', false, $struct ) ) {
				$bits[ strtolower( $struct->Field ) ] = '1';
			}

			$field_set[] = $this->table_helper->backquote( $struct->Field );

			if ( 'PRI' === $struct->Key && true === $use_primary_keys ) {
				if ( false === strpos( $struct->Type, 'int' ) ) {
					$use_primary_keys   = false;
					$this->primary_keys = array();
					continue;
				}
				$this->primary_keys[ $struct->Field ] = 0;
			}
		}

		if ( ! empty( $state_data['primary_keys'] ) ) {
			$state_data['primary_keys'] = trim( $state_data['primary_keys'] );
			$this->primary_keys         = Util::unserialize( stripslashes( $state_data['primary_keys'] ), __METHOD__ );
			if ( false !== $this->primary_keys && ! empty( $state_data['primary_keys'] ) ) {
				$this->first_select = false;
			}
		}

		$return = array(
			'defs'      => $defs,
			'ints'      => $ints,
			'bins'      => $bins,
			'bits'      => $bits,
			'field_set' => $field_set,
		);

		return $return;
	}

	/**
	 * Returns the table structure for the provided table.
	 *
	 * @param string $table
	 *
	 * @return array|bool
	 */
	function get_table_structure( $table ) {
		global $wpdb;

		$table_structure = false;

		if ( $this->table_exists( $table ) ) {
			$table_structure = $wpdb->get_results( 'DESCRIBE ' . $this->table_helper->backquote( $table ) );
		}

		if ( ! $table_structure ) {
			$this->error_log->setError( sprintf( __( 'Failed to retrieve table structure for table \'%s\', please ensure your database is online. (#125)', 'wp-migrate-db' ), $table ) );

			return false;
		}

		return $table_structure;
	}

	/**
	 * Checks if a given table exists.
	 *
	 * @param $table
	 *
	 * @return bool
	 */
	function table_exists( $table ) {
		global $wpdb;

		$table = esc_sql( $table );

		if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Returns the current row, checking the state data.
	 *
	 * @return int
	 */
	function get_current_row() {
		$state_data = $state_data = $this->migration_state_manager->set_post_data();

		$current_row = 0;

		if ( ! empty( $state_data['current_row'] ) ) {
			$temp_current_row = trim( $state_data['current_row'] );
			if ( ! empty( $temp_current_row ) ) {
				$current_row = (int) $temp_current_row;
			}
		}

		$current_row = ( 0 > $current_row ) ? 0 : $current_row;

		return $current_row;
	}

	/**
	 * Runs before processing the data in a table.
	 *
	 * @param string $table
	 * @param string $target_table_name
	 * @param string $temp_table_name
	 */
	function pre_process_data( $table, $target_table_name, $temp_table_name, $fp ) {
		$state_data = $state_data = $this->migration_state_manager->set_post_data();
		$form_data  = $this->form_data->getFormData();
		if ( 0 !== $this->row_tracker ) {
			return;
		}

		if ( in_array( $form_data['action'], array( 'find_replace', 'import' ) ) ) {
			if ( 'backup' === $state_data['stage'] ) {
				$this->build_table_header( $table, $target_table_name, $temp_table_name, $fp );
			} else if ( 'find_replace' === $form_data['action'] ) {
				$create = $this->create_temp_table( $table );

				if ( true !== $create ) {
					$message = sprintf( __( 'Error creating temporary table. Table "%s" does not exist.', 'wp-migrate-db' ), esc_html( $table ) );

					return $this->http->end_ajax( json_encode( array( 'wpmdb_error', 1, 'body' => $message ) ) );
				}
			}
		} else {
			$this->build_table_header( $table, $target_table_name, $temp_table_name, $fp );
		}

		/**
		 * Fires just before processing the data for a table.
		 *
		 * @param string $table
		 * @param string $target_table_name
		 * @param string $temp_table_name
		 */
		do_action( 'wpmdb_pre_process_table_data', $table, $target_table_name, $temp_table_name );
	}

	/**
	 * Creates the header for a table in a SQL file.
	 *
	 * @param string $table
	 * @param string $target_table_name
	 * @param string $temp_table_name
	 *
	 * @return null|bool
	 */
	function build_table_header( $table, $target_table_name, $temp_table_name, $fp ) {
		global $wpdb;
		$state_data = $state_data = $this->migration_state_manager->set_post_data();
		$form_data  = $this->form_data->getFormData();

		// Don't stow data until after `wpmdb_create_table_query` filter is applied as mysql_compat_filter() can return an error
		$stow          = '';
		$is_backup     = false;
		$table_to_stow = $temp_table_name;

		if ( 'savefile' === $form_data['action'] || 'backup' === $state_data['stage'] ) {
			$is_backup     = true;
			$table_to_stow = $target_table_name;
		}

		// Add SQL statement to drop existing table
		if ( $is_backup ) {
			$stow .= ( "\n\n" );
			$stow .= ( "#\n" );
			$stow .= ( '# ' . sprintf( __( 'Delete any existing table %s', 'wp-migrate-db' ), $this->table_helper->backquote( $table_to_stow ) ) . "\n" );
			$stow .= ( "#\n" );
			$stow .= ( "\n" );
		}
		$stow .= ( 'DROP TABLE IF EXISTS ' . $this->table_helper->backquote( $table_to_stow ) . ";\n" );

		// Table structure
		// Comment in SQL-file
		if ( $is_backup ) {
			$stow .= ( "\n\n" );
			$stow .= ( "#\n" );
			$stow .= ( '# ' . sprintf( __( 'Table structure of table %s', 'wp-migrate-db' ), $this->table_helper->backquote( $table_to_stow ) ) . "\n" );
			$stow .= ( "#\n" );
			$stow .= ( "\n" );
		}

		$create_table = $wpdb->get_results( 'SHOW CREATE TABLE ' . $this->table_helper->backquote( $table ), ARRAY_N );

		if ( false === $create_table ) {
			$this->error_log->setError( __( 'Failed to generate the create table query, please ensure your database is online. (#126)', 'wp-migrate-db' ) );

			return false;
		}
		//Replaces ANSI quotes with backticks
		$create_table[0][1] = $this->remove_ansi_quotes( $create_table[0][1] );
		$create_table[0][1] = str_replace( 'CREATE TABLE `' . $table . '`', 'CREATE TABLE `' . $table_to_stow . '`', $create_table[0][1] );
		$create_table[0][1] = str_replace( 'TYPE=', 'ENGINE=', $create_table[0][1] );

		$alter_table_query  = '';
		$create_table[0][1] = $this->process_sql_constraint( $create_table[0][1], $target_table_name, $alter_table_query );

		$create_table[0][1] = apply_filters( 'wpmdb_create_table_query', $create_table[0][1], $table_to_stow, $this->dynamic_props->target_db_version, $form_data['action'], $state_data['stage'] );
		$stow               .= ( $create_table[0][1] . ";\n" );

		$this->stow( $stow, false, $fp );

		if ( ! empty( $alter_table_query ) ) {
			$alter_table_name = $this->get_alter_table_name();
			$insert           = sprintf( "INSERT INTO %s ( `query` ) VALUES ( '%s' );\n", $this->table_helper->backquote( $alter_table_name ), esc_sql( $alter_table_query ) );

			if ( $is_backup ) {
				$process_chunk_result = $this->process_chunk( $insert );
				if ( true !== $process_chunk_result ) {
					$result = $this->http->end_ajax( $process_chunk_result );

					return $result;
				}
			} else {
				$this->stow( $insert, false, $fp );
			}
		}

		$alter_data_queries = array();
		$alter_data_queries = apply_filters( 'wpmdb_alter_data_queries', $alter_data_queries, $table_to_stow, $form_data['action'], $state_data['stage'] );

		if ( ! empty( $alter_data_queries ) ) {
			$alter_table_name = $this->get_alter_table_name();
			$insert           = '';
			foreach ( $alter_data_queries as $alter_data_query ) {
				$insert .= sprintf( "INSERT INTO %s ( `query` ) VALUES ( '%s' );\n", $this->table_helper->backquote( $alter_table_name ), esc_sql( $alter_data_query ) );
			}
			if ( $is_backup ) {
				$process_chunk_result = $this->process_chunk( $insert );
				if ( true !== $process_chunk_result ) {
					$result = $this->http->end_ajax( $process_chunk_result );

					return $result;
				}
			} else {
				$this->stow( $insert, false, $fp );
			}
		}

		// Comment in SQL-file
		if ( $is_backup ) {
			$this->stow( "\n\n", false, $fp );
			$this->stow( "#\n", false, $fp );
			$this->stow( '# ' . sprintf( __( 'Data contents of table %s', 'wp-migrate-db' ), $this->table_helper->backquote( $table_to_stow ) ) . "\n", false, $fp );
			$this->stow( "#\n", false, $fp );
		}
	}

	function process_sql_constraint( $create_query, $table, &$alter_table_query ) {
		if ( preg_match( '@CONSTRAINT|FOREIGN[\s]+KEY@', $create_query ) ) {
			$sql_constraints_query = '';

			$nl_nix = "\n";
			$nl_win = "\r\n";
			$nl_mac = "\r";

			if ( strpos( $create_query, $nl_win ) !== false ) {
				$crlf = $nl_win;
			} elseif ( strpos( $create_query, $nl_mac ) !== false ) {
				$crlf = $nl_mac;
			} else {
				$crlf = $nl_nix;
			}

			// Split the query into lines, so we can easily handle it.
			// We know lines are separated by $crlf (done few lines above).
			$sql_lines = explode( $crlf, $create_query );
			$sql_count = count( $sql_lines );

			// lets find first line with constraints
			for ( $i = 0; $i < $sql_count; $i ++ ) {
				if ( preg_match(
					'@^[\s]*(CONSTRAINT|FOREIGN[\s]+KEY)@',
					$sql_lines[ $i ]
				) ) {
					break;
				}
			}

			// If we really found a constraint
			if ( $i != $sql_count ) {
				// remove, from the end of create statement
				$sql_lines[ $i - 1 ] = preg_replace(
					'@,$@',
					'',
					$sql_lines[ $i - 1 ]
				);

				// let's do the work
				$sql_constraints_query .= 'ALTER TABLE ' . $this->table_helper->backquote( $table ) . $crlf;

				$first = true;
				for ( $j = $i; $j < $sql_count; $j ++ ) {
					if ( preg_match(
						'@CONSTRAINT|FOREIGN[\s]+KEY@',
						$sql_lines[ $j ]
					) ) {
						if ( strpos( $sql_lines[ $j ], 'CONSTRAINT' ) === false ) {
							$tmp_str               = preg_replace(
								'/(FOREIGN[\s]+KEY)/',
								'ADD \1',
								$sql_lines[ $j ]
							);
							$sql_constraints_query .= $tmp_str;
						} else {
							$tmp_str               = preg_replace(
								'/(CONSTRAINT)/',
								'ADD \1',
								$sql_lines[ $j ]
							);
							$sql_constraints_query .= $tmp_str;
							preg_match(
								'/(CONSTRAINT)([\s])([\S]*)([\s])/',
								$sql_lines[ $j ],
								$matches
							);
						}
						$first = false;
					} else {
						break;
					}
				}

				$sql_constraints_query .= ";\n";

				$create_query = implode(
					                $crlf,
					                array_slice( $sql_lines, 0, $i )
				                )
				                . $crlf
				                . implode(
					                $crlf,
					                array_slice( $sql_lines, $j, $sql_count - 1 )
				                );
				unset( $sql_lines );

				$alter_table_query = $sql_constraints_query;

				return $create_query;
			}
		}

		return $create_query;
	}

	/**
	 * Write query line to chunk, file pointer, or buffer depending on migration stage/action.
	 *
	 * @param string $query_line
	 * @param bool   $replace
	 *
	 * @return bool
	 */
	function stow( $query_line, $replace = true, $fp = null ) {
		$state_data = $state_data = $this->migration_state_manager->set_post_data();
		$form_data  = $this->form_data->getFormData();

		$this->migration_state_manager->set_post_data();
		$this->current_chunk .= $query_line;

		if ( 0 === strlen( $query_line ) ) {
			return true;
		}

		if ( 'savefile' === $form_data['action'] || in_array( $state_data['stage'], array( 'backup', 'import' ) ) ) {
			if ( Util::gzip() && isset( $form_data['gzip_file'] ) && $form_data['gzip_file'] ) {
				if ( ! gzwrite( $fp, $query_line ) ) {
					$this->error_log->setError( __( 'Failed to write the gzipped SQL data to the file. (#127)', 'wp-migrate-db' ) );

					return false;
				}
			} else {
				// TODO: Use WP_Filesystem API.
				if ( false === @fwrite( $fp, $query_line ) ) {
					$this->error_log->setError( __( 'Failed to write the SQL data to the file. (#128)', 'wp-migrate-db' ) );

					return false;
				}
			}
		} elseif ( $state_data['intent'] == 'pull' ) {
			echo apply_filters( 'wpmdb_before_response', $query_line );
		} elseif ( 'find_replace' === $state_data['stage'] ) {
			return $this->process_chunk( $query_line );
		}
	}

	function process_chunk( $chunk ) {
		// prepare db
		global $wpdb;
		$this->util->set_time_limit();

		$queries = array_filter( explode( ";\n", $chunk ) );
		array_unshift( $queries, "SET sql_mode='NO_AUTO_VALUE_ON_ZERO';" );

		ob_start();
		$wpdb->show_errors();
		if ( empty( $wpdb->charset ) ) {
			$charset       = ( defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8' );
			$wpdb->charset = $charset;
			$wpdb->set_charset( $wpdb->dbh, $wpdb->charset );
		}

		foreach ( $queries as $query ) {
			if ( false === $wpdb->query( $query ) ) {
				$return = ob_get_clean();
				$return = array( 'wpmdb_error' => 1, 'body' => $return );

				$invalid_text = $this->maybe_strip_invalid_text_and_retry( $query );
				if ( false !== $invalid_text ) {
					$return = $invalid_text;
				}

				if ( true !== $return ) {
					$result = $this->http->end_ajax( json_encode( $return ) );

					return $result;
				}
			}
		}

		return true;
	}

	/**
	 * Check if query failed due to invalid text and retry stripped query if WPMDB_STRIP_INVALID is defined as true
	 *
	 * @param string $query
	 * @param string $context
	 *
	 * @return array|bool|WP_Error
	 */
	function maybe_strip_invalid_text_and_retry( $query, $context = 'default' ) {
		global $wpdb;
		$return = true;
		// For insert/update queries, check if it's due to invalid text
		if ( ! $wpdb->last_error && ( strstr( $query, 'INSERT' ) || strstr( $query, 'UPDATE' ) ) ) {
			// Only instantiate WPMDB_WPDB if needed
			if ( ! $this->wpdb ) {
				$this->wpdb = Util::make_wpmdb_wpdb_instance();
			}
			if ( $this->wpdb->query_has_invalid_text( $query ) ) {
				if ( ! ( defined( 'WPMDB_STRIP_INVALID_TEXT' ) && WPMDB_STRIP_INVALID_TEXT ) ) {
					$table = $this->wpdb->get_table_from_query( $query );
					$table = str_replace( $this->props->temp_prefix, '', $table );

					if ( 'import' === $context ) {
						$message = sprintf( __( 'The imported table `%1s` contains characters which are invalid in the target schema.<br><br>If this is a WP Migrate DB Pro export file, ensure that the `Compatible with older versions of MySQL` setting under `Advanced Options` is unchecked and try exporting again.<br><br> See&nbsp;<a href="%2s">our documentation</a> for more information.', 'wp-migrate-db' ), $table, 'https://deliciousbrains.com/wp-migrate-db-pro/doc/invalid-text/#imports' );
						$return  = new \WP_Error( 'import_sql_execution_failed', $message );
					} else {
						$message = sprintf( __( 'The table `%1s` contains characters which are invalid in the target database. See&nbsp;<a href="%2s">our documentation</a> for more information.', 'wp-migrate-db' ), $table, 'https://deliciousbrains.com/wp-migrate-db-pro/doc/invalid-text/' );
						$return  = array(
							'wpmdb_error' => 1,
							'body'        => $message,
						);
					}

					$this->error_log->log_error( $message );
					error_log( $message . ":\n" . $query );

				} else {
					if ( false === $wpdb->query( $this->wpdb->last_stripped_query ) ) {
						$error = ob_get_clean();

						$return = new \WP_Error( 'strip_invalid_text_query_failed', 'Failed to import the stripped SQL query: ' . $error );
					} else {
						$return = true;
					}
				}
			}
		}

		return $return;
	}

	/**
	 * Returns the table name where the alter statements are held during the migration.
	 *
	 * @return string
	 */
	function get_alter_table_name() {
		static $alter_table_name;

		if ( ! empty( $alter_table_name ) ) {
			return $alter_table_name;
		}

		$alter_table_name = apply_filters( 'wpmdb_alter_table_name', $this->props->temp_prefix . 'wpmdb_alter_statements' );

		return $alter_table_name;
	}

	/**
	 * Creates a temporary table with a copy of the existing table's data.
	 *
	 * @param $table
	 *
	 * @return bool|mixed
	 */
	function create_temp_table( $table ) {
		if ( $this->table_exists( $table ) ) {
			$src_table  = $this->table_helper->backquote( $table );
			$temp_table = $this->table_helper->backquote( $this->props->temp_prefix . $table );
			$query      = "DROP TABLE IF EXISTS {$temp_table};\n";
			$query      .= "CREATE TABLE {$temp_table} LIKE {$src_table};\n";
			$query      .= "INSERT INTO {$temp_table} SELECT * FROM {$src_table};";

			return $this->process_chunk( $query );
		}

		return false;
	}

	/**
	 * Builds the SELECT query to get data to migrate.
	 *
	 * @param string $table
	 * @param int    $row_start
	 * @param array  $structure_info
	 *
	 * @return string
	 */
	function build_select_query( $table, $row_start, $structure_info ) {
		$state_data = $this->migration_state_manager->set_post_data();
		$form_data  = $this->form_data->getFormData();

		global $wpdb;

		$join     = array();
		$where    = 'WHERE 1=1';
		$order_by = '';
		$prefix   = ( 'import' === $state_data['intent'] ) ? $this->props->temp_prefix . $wpdb->base_prefix : '';

		// We need ORDER BY here because with LIMIT, sometimes it will return
		// the same results from the previous query and we'll have duplicate insert statements
		if ( 'import' !== $state_data['intent'] && 'backup' != $state_data['stage'] && false === empty( $form_data['exclude_spam'] ) ) {
			if ( $this->table_helper->table_is( 'comments', $table, 'table', $prefix ) ) {
				$where .= ' AND comment_approved != \'spam\'';
			} elseif ( $this->table_helper->table_is( 'commentmeta', $table, 'table', $prefix ) ) {
				$tables = $this->get_ms_compat_table_names( array( 'commentmeta', 'comments' ), $table );
				$join[] = sprintf( 'INNER JOIN %1$s ON %1$s.comment_ID = %2$s.comment_id', $this->table_helper->backquote( $tables['comments_table'] ), $this->table_helper->backquote( $tables['commentmeta_table'] ) );
				$where  .= sprintf( ' AND %1$s.comment_approved != \'spam\'', $this->table_helper->backquote( $tables['comments_table'] ) );
			}
		}

		if ( 'import' !== $state_data['intent'] && 'backup' != $state_data['stage'] && isset( $form_data['exclude_post_types'] ) && ! empty( $form_data['select_post_types'] ) ) {
			$post_types = '\'' . implode( '\', \'', $form_data['select_post_types'] ) . '\'';
			if ( $this->table_helper->table_is( 'posts', $table, 'table', $prefix ) ) {
				$where .= ' AND `post_type` NOT IN ( ' . $post_types . ' )';
			} elseif ( $this->table_helper->table_is( 'postmeta', $table, 'table', $prefix ) ) {
				$tables = $this->get_ms_compat_table_names( array( 'postmeta', 'posts' ), $table );
				$join[] = sprintf( 'INNER JOIN %1$s ON %1$s.ID = %2$s.post_id', $this->table_helper->backquote( $tables['posts_table'] ), $this->table_helper->backquote( $tables['postmeta_table'] ) );
				$where  .= sprintf( ' AND %1$s.post_type NOT IN ( ' . $post_types . ' )', $this->table_helper->backquote( $tables['posts_table'] ) );
			} elseif ( $this->table_helper->table_is( 'comments', $table, 'table', $prefix ) ) {
				$tables = $this->get_ms_compat_table_names( array( 'comments', 'posts' ), $table );
				$join[] = sprintf( 'INNER JOIN %1$s ON %1$s.ID = %2$s.comment_post_ID', $this->table_helper->backquote( $tables['posts_table'] ), $this->table_helper->backquote( $tables['comments_table'] ) );
				$where  .= sprintf( ' AND %1$s.post_type NOT IN ( ' . $post_types . ' )', $this->table_helper->backquote( $tables['posts_table'] ) );
			} elseif ( $this->table_helper->table_is( 'commentmeta', $table, 'table', $prefix ) ) {
				$tables = $this->get_ms_compat_table_names( array( 'commentmeta', 'posts', 'comments' ), $table );
				$join[] = sprintf( 'INNER JOIN %1$s ON %1$s.comment_ID = %2$s.comment_id', $this->table_helper->backquote( $tables['comments_table'] ), $this->table_helper->backquote( $tables['commentmeta_table'] ) );
				$join[] = sprintf( 'INNER JOIN %2$s ON %2$s.ID = %1$s.comment_post_ID', $this->table_helper->backquote( $tables['comments_table'] ), $this->table_helper->backquote( $tables['posts_table'] ) );
				$where  .= sprintf( ' AND %1$s.post_type NOT IN ( ' . $post_types . ' )', $this->table_helper->backquote( $tables['posts_table'] ) );
			}
		}

		if ( 'import' !== $state_data['intent'] && 'backup' != $state_data['stage'] && true === apply_filters( 'wpmdb_exclude_transients', true ) && isset( $form_data['exclude_transients'] ) && '1' === $form_data['exclude_transients'] && ( $this->table_helper->table_is( 'options', $table, 'table', $prefix ) || ( isset( $wpdb->sitemeta ) && $wpdb->sitemeta == $table ) ) ) {
			$col_name = 'option_name';

			if ( isset( $wpdb->sitemeta ) && $wpdb->sitemeta == $table ) {
				$col_name = 'meta_key';
			}

			$where .= " AND `{$col_name}` NOT LIKE '\_transient\_%' AND `{$col_name}` NOT LIKE '\_site\_transient\_%'";
		}

		// don't export/migrate wpmdb specific option rows unless we're performing a backup
		if ( 'backup' != $state_data['stage'] && ( $this->table_helper->table_is( 'options', $table, 'table', $prefix ) || ( isset( $wpdb->sitemeta ) && $wpdb->sitemeta == $table ) ) ) {
			$col_name = 'option_name';

			if ( isset( $wpdb->sitemeta ) && $wpdb->sitemeta == $table ) {
				$col_name = 'meta_key';
			}

			$where .= " AND `{$col_name}` != 'wpmdb_settings'";
			$where .= " AND `{$col_name}` != 'wpmdb_error_log'";
			$where .= " AND `{$col_name}` != 'wpmdb_file_ignores'";
			$where .= " AND `{$col_name}` != 'wpmdb_schema_version'";
			$where .= " AND `{$col_name}` NOT LIKE 'wpmdb_state_%'";
		}

		$limit = "LIMIT {$row_start}, {$this->rows_per_segment}";

		if ( ! empty( $this->primary_keys ) ) {
			$primary_keys_keys = array_keys( $this->primary_keys );
			$primary_keys_keys = array_map( array( $this->table_helper, 'backquote' ), $primary_keys_keys );

			$order_by = 'ORDER BY ' . implode( ',', $primary_keys_keys );
			$limit    = "LIMIT {$this->rows_per_segment}";

			if ( false === $this->first_select ) {
				$where .= ' AND ';

				$temp_primary_keys = $this->primary_keys;
				$primary_key_count = count( $temp_primary_keys );

				// build a list of clauses, iteratively reducing the number of fields compared in the compound key
				// e.g. (a = 1 AND b = 2 AND c > 3) OR (a = 1 AND b > 2) OR (a > 1)
				$clauses = array();
				for ( $j = 0; $j < $primary_key_count; $j ++ ) {
					// build a subclause for each field in the compound index
					$subclauses = array();
					$i          = 0;
					foreach ( $temp_primary_keys as $primary_key => $value ) {
						// only the last field in the key should be different in this subclause
						$operator     = ( count( $temp_primary_keys ) - 1 == $i ? '>' : '=' );
						$subclauses[] = sprintf( '%s %s %s', $this->table_helper->backquote( $primary_key ), $operator, $wpdb->prepare( '%s', $value ) );
						++ $i;
					}

					// remove last field from array to reduce fields in next clause
					array_pop( $temp_primary_keys );

					// join subclauses into a single clause
					// NB: AND needs to be wrapped in () as it has higher precedence than OR
					$clauses[] = '( ' . implode( ' AND ', $subclauses ) . ' )';
				}
				// join clauses into a single clause
				// NB: OR needs to be wrapped in () as it has lower precedence than AND
				$where .= '( ' . implode( ' OR ', $clauses ) . ' )';
			}

			$this->first_select = false;
		}

		$sel = $this->table_helper->backquote( $table ) . '.*';
		if ( ! empty( $structure_info['bins'] ) ) {
			foreach ( $structure_info['bins'] as $key => $bin ) {
				$hex_key = strtolower( $key ) . '__hex';
				$sel     .= ', HEX(' . $this->table_helper->backquote( $key ) . ') as ' . $this->table_helper->backquote( $hex_key );
			}
		}
		if ( ! empty( $structure_info['bits'] ) ) {
			foreach ( $structure_info['bits'] as $key => $bit ) {
				$bit_key = strtolower( $key ) . '__bit';
				$sel     .= ', ' . $this->table_helper->backquote( $key ) . '+0 as ' . $this->table_helper->backquote( $bit_key );
			}
		}
		$join     = implode( ' ', array_unique( $join ) );
		$join     = apply_filters( 'wpmdb_rows_join', $join, $table );
		$where    = apply_filters( 'wpmdb_rows_where', $where, $table );
		$order_by = apply_filters( 'wpmdb_rows_order_by', $order_by, $table );
		$limit    = apply_filters( 'wpmdb_rows_limit', $limit, $table );

		$sql = 'SELECT ' . $sel . ' FROM ' . $this->table_helper->backquote( $table ) . " $join $where $order_by $limit";
		$sql = apply_filters( 'wpmdb_rows_sql', $sql, $table );

		return $sql;
	}

	/**
	 * Return multisite-compatible names for requested
	 * tables, based on queried table name
	 *
	 * @param array  $tables        List of table names required
	 * @param string $queried_table Name of table from which to derive the blog ID
	 *
	 * @return array List of table names altered for multisite compatibility
	 */
	function get_ms_compat_table_names( $tables, $queried_table ) {
		$state_data = $this->migration_state_manager->set_post_data();
		global $wpdb;

		$temp_prefix    = ( 'import' === $state_data['intent'] ) ? $this->props->temp_prefix : '';
		$prefix         = $temp_prefix . $wpdb->base_prefix;
		$prefix_escaped = preg_quote( $prefix, '/' );

		// if multisite, extract blog ID from queried table name and add to prefix
		// won't match for primary blog because it uses standard table names, i.e. blog_id will never be 1
		if ( is_multisite() && preg_match( '/^' . $prefix_escaped . '([0-9]+)_/', $queried_table, $matches ) ) {
			$blog_id = $matches[1];
			$prefix  .= $blog_id . '_';
		}

		// build table names
		$ms_compat_table_names = array();

		foreach ( $tables as $table ) {
			$ms_compat_table_names[ $table . '_table' ] = $prefix . $table;
		}

		return $ms_compat_table_names;
	}

	/**
	 * Initializes the query buffer and template.
	 *
	 * @param $target_table_name
	 * @param $temp_table_name
	 * @param $structure_info
	 *
	 * @return null
	 */
	function start_query_buffer( $target_table_name, $temp_table_name, $structure_info ) {
		$state_data = $state_data = $this->migration_state_manager->set_post_data();
		$form_data  = $this->form_data->getFormData();

		if ( 'find_replace' !== $state_data['stage'] ) {
			$fields          = implode( ', ', $structure_info['field_set'] );
			$table_to_insert = $temp_table_name;

			if ( 'savefile' === $form_data['action'] || 'backup' === $state_data['stage'] ) {
				$table_to_insert = $target_table_name;
			}

			$this->query_template = 'INSERT INTO ' . $this->table_helper->backquote( $table_to_insert ) . ' ( ' . $fields . ") VALUES\n";
		} else {
			$this->query_template = '';
		}

		$this->query_buffer = $this->query_template;
	}

	/**
	 * Processes the data in a given row.
	 *
	 * @param string $table
	 * @param object $replacer
	 * @param array  $row
	 * @param array  $structure_info
	 *
	 * @return array|void
	 */
	function process_row( $table, $replacer, $row, $structure_info, $fp ) {
		$state_data = $state_data = $this->migration_state_manager->set_post_data();
		$form_data  = $this->form_data->getFormData();

		global $wpdb;

		$skip_row        = false;
		$updates_pending = false;
		$update_sql      = array();
		$where_sql       = array();
		$values          = array();
		$query           = '';

		if ( ! apply_filters( 'wpmdb_table_row', $row, $table, $form_data['action'], $state_data['stage'] ) ) {
			$skip_row = true;
		}

		if ( ! $skip_row ) {

			$replacer->set_row( $row );

			foreach ( $row as $key => $value ) {
				$data_to_fix = $value;

				if ( 'find_replace' === $state_data['stage'] && in_array( $key, array_keys( $this->primary_keys ) ) ) {
					$where_sql[] = $this->table_helper->backquote( $key ) . ' = "' . $this->mysql_escape_mimic( $data_to_fix ) . '"';
					continue;
				}

				$replacer->set_column( $key );

				if ( isset( $structure_info['ints'][ strtolower( $key ) ] ) && $structure_info['ints'][ strtolower( $key ) ] ) {
					// make sure there are no blank spots in the insert syntax,
					// yet try to avoid quotation marks around integers
					$value    = ( null === $value || '' === $value ) ? $structure_info['defs'][ strtolower( $key ) ] : $value;
					$values[] = ( '' === $value ) ? "''" : $value;
					continue;
				}

				$test_bit_key = strtolower( $key ) . '__bit';
				// Correct null values IF we're not working with a BIT type field, they're handled separately below
				if ( null === $value && ! property_exists( $row, $test_bit_key  ) ) {
					$values[] = 'NULL';
					continue;
				}

				// If we have binary data, substitute in hex encoded version and remove hex encoded version from row.
				$hex_key = strtolower( $key ) . '__hex';
				if ( isset( $structure_info['bins'][ strtolower( $key ) ] ) && $structure_info['bins'][ strtolower( $key ) ] && isset( $row->$hex_key ) ) {
					$value    = "UNHEX('" . $row->$hex_key . "')";
					$values[] = $value;
					unset( $row->$hex_key );
					continue;
				}

				// If we have bit data, substitute in properly bit encoded version.
				$bit_key = strtolower( $key ) . '__bit';
				if ( isset( $structure_info['bits'][ strtolower( $key ) ] ) && $structure_info['bits'][ strtolower( $key ) ] && ( isset( $row->$bit_key ) || null === $row->$bit_key ) ) {
					$value    = null === $row->$bit_key ? 'NULL' : "b'" . $row->$bit_key . "'";
					$values[] = $value;
					unset( $row->$bit_key );
					continue;
				}

				if ( is_multisite() && in_array( $table, array( $wpdb->site, $wpdb->blogs, $this->props->temp_prefix . $wpdb->blogs, $this->props->temp_prefix . $wpdb->site ) ) ) {

					if ( 'backup' !== $state_data['stage'] ) {

						if ( 'path' == $key ) {
							$old_path_current_site = $this->util->get_path_current_site();
							$new_path_current_site = '';

							if ( ! empty( $state_data['path_current_site'] ) ) {
								$new_path_current_site = $state_data['path_current_site'];
							} elseif ( 'find_replace' === $state_data['stage'] ) {
								$new_path_current_site = $this->util->get_path_current_site();
							} elseif ( ! empty ( $form_data['replace_new'][1] ) ) {
								$new_path_current_site = $this->util->get_path_from_url( $form_data['replace_new'][1] );
							}

							$new_path_current_site = apply_filters( 'wpmdb_new_path_current_site', $new_path_current_site );

							if ( ! empty( $new_path_current_site ) && $old_path_current_site != $new_path_current_site ) {
								$pos   = strpos( $value, $old_path_current_site );
								$value = substr_replace( $value, $new_path_current_site, $pos, strlen( $old_path_current_site ) );
							}
						}

						if ( 'domain' == $key ) { // wp_blogs and wp_sites tables
							if ( ! empty( $state_data['domain_current_site'] ) ) {
								$main_domain_replace = $state_data['domain_current_site'];
							} elseif ( 'find_replace' === $state_data['stage'] || 'savefile' === $state_data['intent'] ) {
								$main_domain_replace = $this->multisite->get_domain_replace() ? $this->multisite->get_domain_replace() : $this->multisite->get_domain_current_site();
							} elseif ( ! empty ( $form_data['replace_new'][1] ) ) {
								$url                 = Util::parse_url( $form_data['replace_new'][1] );
								$main_domain_replace = $url['host'];
							}

							$domain_replaces  = array();
							$main_domain_find = $this->multisite->get_domain_current_site();

							if ( 'find_replace' === $state_data['stage'] ) {
								// Check if the domain field in the DB is being searched for in the find & replace
								$old_domain_find = sprintf( '/^(\/\/|http:\/\/|https:\/\/|)%s/', $data_to_fix );

								if ( preg_grep( $old_domain_find, $this->dynamic_props->find_replace_pairs['replace_old'] ) ) {
									$main_domain_find = $data_to_fix;
								}
							}

							$main_domain_find = sprintf( '/%s/', preg_quote( $main_domain_find, '/' ) );
							if ( isset( $main_domain_replace ) ) {
								$domain_replaces[ $main_domain_find ] = $main_domain_replace;
							}

							$domain_replaces = apply_filters( 'wpmdb_domain_replaces', $domain_replaces );

							$value = preg_replace( array_keys( $domain_replaces ), array_values( $domain_replaces ), $value );
						}
					}
				}

				if ( 'guid' != $key || ( false === empty( $form_data['replace_guids'] ) && $this->table_helper->table_is( 'posts', $table ) ) ) {
					if ( $state_data['stage'] != 'backup' ) {
						$value = $replacer->recursive_unserialize_replace( $value );
					}
				}

				if ( 'find_replace' === $state_data['stage'] ) {
					$value       = $this->mysql_escape_mimic( $value );
					$data_to_fix = $this->mysql_escape_mimic( $data_to_fix );

					if ( $value !== $data_to_fix ) {
						$update_sql[]    = $this->table_helper->backquote( $key ) . ' = "' . $value . '"';
						$updates_pending = true;
					}
				} else {
					// \x08\\x09, not required
					$multibyte_search  = array( "\x00", "\x0a", "\x0d", "\x1a" );
					$multibyte_replace = array( '\0', '\n', '\r', '\Z' );

					$value = $this->table_helper->sql_addslashes( $value );
					$value = str_replace( $multibyte_search, $multibyte_replace, $value );
				}

				$values[] = "'" . $value . "'";
			}

			// Determine what to do with updates.
			if ( 'find_replace' === $state_data['stage'] ) {
				if ( $updates_pending && ! empty( $where_sql ) ) {
					$table_to_update = $table;

					if ( 'import' !== $form_data['action'] ) {
						$table_to_update = $this->table_helper->backquote( $this->props->temp_prefix . $table );
					}

					$query .= 'UPDATE ' . $table_to_update . ' SET ' . implode( ', ', $update_sql ) . ' WHERE ' . implode( ' AND ', array_filter( $where_sql ) ) . ";\n";
				}
			} else {
				$query .= '(' . implode( ', ', $values ) . '),' . "\n";
			}
		}

		if ( ( strlen( $this->current_chunk ) + strlen( $query ) + strlen( $this->query_buffer ) + 30 ) > $this->dynamic_props->maximum_chunk_size ) {
			if ( $this->query_buffer == $this->query_template ) {
				$this->query_buffer .= $query;

				++ $this->row_tracker;

				if ( ! empty( $this->primary_keys ) ) {
					foreach ( $this->primary_keys as $primary_key => $value ) {
						$this->primary_keys[ $primary_key ] = $row->$primary_key;
					}
				}
			}

			$this->stow_query_buffer( $fp );

			return $this->transfer_chunk( $fp );
		}

		if ( ( $this->query_size + strlen( $query ) ) > $this->max_insert_string_len ) {
			$this->stow_query_buffer( $fp );
		}

		$this->query_buffer .= $query;
		$this->query_size   += strlen( $query );

		++ $this->row_tracker;

		if ( ! empty( $this->primary_keys ) ) {
			foreach ( $this->primary_keys as $primary_key => $value ) {
				$this->primary_keys[ $primary_key ] = $row->$primary_key;
			}
		}

		return true;
	}

	/**
	 * Mimics the mysql_real_escape_string function. Adapted from a post by 'feedr' on php.net.
	 *
	 * @link   http://php.net/manual/en/function.mysql-real-escape-string.php#101248
	 *
	 * @param  string $input The string to escape.
	 *
	 * @return string
	 */
	function mysql_escape_mimic( $input ) {
		if ( is_array( $input ) ) {
			return array_map( __METHOD__, $input );
		}
		if ( ! empty( $input ) && is_string( $input ) ) {
			return str_replace( array( '\\', "\0", "\n", "\r", "'", '"', "\x1a" ), array( '\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z' ), $input );
		}

		return $input;
	}

	/**
	 * Responsible for stowing a chunk of processed data.
	 */
	function stow_query_buffer( $fp ) {
		if ( $this->query_buffer !== $this->query_template ) {
			$this->query_buffer = rtrim( $this->query_buffer, "\n," );
			$this->query_buffer .= " ;\n";
			$this->stow( $this->query_buffer, false, $fp );
			$this->query_buffer = $this->query_template;
			$this->query_size   = 0;
		}
	}

	/**
	 * Called once our chunk buffer is full, will transfer the SQL to the remote server for importing
	 *
	 * @return array|null
	 */
	function transfer_chunk( $fp ) {
		$state_data = $state_data = $this->migration_state_manager->set_post_data();
		$this->migration_state_manager->set_post_data();

		if ( in_array( $state_data['intent'], array( 'savefile', 'find_replace', 'import' ) ) || 'backup' == $state_data['stage'] ) {

			if ( 'find_replace' === $state_data['stage'] ) {
				$this->process_chunk( $this->query_buffer );
			} else {
				$this->filesystem->close( $fp );
			}

			$result = array(
				'current_row'  => $this->row_tracker,
				'primary_keys' => serialize( $this->primary_keys ),
			);

			if ( $state_data['intent'] == 'savefile' && $state_data['last_table'] == '1' ) {
				$result['dump_filename'] = $state_data['dump_filename'];
				$result['dump_path']     = $state_data['dump_path'];
			}

			$result = $this->http->end_ajax( json_encode( $result ) );

			return $result;
		}

		if ( $state_data['intent'] == 'pull' ) {
			$result = $this->http->end_ajax( $this->row_tracker . ',' . serialize( $this->primary_keys ) );

			return $result;
		}

		$chunk_gzipped = '0';
		if ( isset( $state_data['gzip'] ) && $state_data['gzip'] == '1' && Util::gzip() ) {
			$this->current_chunk = gzcompress( $this->current_chunk );
			$chunk_gzipped       = '1';
		}

		$data = array(
			'action'          => 'wpmdb_process_chunk',
			'remote_state_id' => $state_data['remote_state_id'],
			'table'           => $state_data['table'],
			'chunk_gzipped'   => $chunk_gzipped,
			'chunk'           => $this->current_chunk,
			// NEEDS TO BE the last element in this array because of adding it back into the array in ajax_process_chunk()
		);

		$data['sig'] = $this->http_helper->create_signature( $data, $state_data['key'] );

		$ajax_url = $this->util->ajax_url();
		$response = $this->remote_post->post( $ajax_url, $data, __FUNCTION__ );

		ob_start();
		$this->util->display_errors();
		$maybe_errors = trim( ob_get_clean() );

		if ( false === empty( $maybe_errors ) ) {
			$maybe_errors = array( 'wpmdb_error' => 1, 'body' => $maybe_errors );
			$result       = $this->http->end_ajax( json_encode( $maybe_errors ) );

			return $result;
		}

		if ( '1' !== $response ) {
			$decoded_response = json_decode( $response, 1 );
			if ( $decoded_response && isset( $decoded_response['wpmdb_error'] ) && isset( $decoded_response['body'] ) ) {
				// $response is already json_encoded wpmdb_error object
				$this->error_log->log_error( 'transfer_chunk received error response: ' . $decoded_response['body'] );

				return $this->http->end_ajax( $response );
			}
			$return = array( 'wpmdb_error' => 1, 'body' => $response );

			return $this->http->end_ajax( json_encode( $return ) );
		}

		$result = $this->http->end_ajax( json_encode(
			array(
				'current_row'  => $this->row_tracker,
				'primary_keys' => serialize( $this->primary_keys ),
			)
		) );

		return $result;
	}

	/**
	 * Runs after processing data in a table.
	 *
	 * @param string $table
	 * @param string $target_table_name
	 */
	function post_process_data( $table, $target_table_name, $fp ) {
		$state_data = $state_data = $this->migration_state_manager->set_post_data();
		$form_data  = $this->form_data->getFormData();

		if ( 'savefile' == $form_data['action'] || 'backup' == $state_data['stage'] ) {
			$this->build_table_footer( $table, $target_table_name, $fp );
		}

		/**
		 * Fires just after processing the data for a table.
		 *
		 * @param string $table
		 * @param string $target_table_name
		 */
		do_action( 'wpmdb_post_process_table_data', $table, $target_table_name );

		$this->row_tracker = - 1;
	}

	/**
	 * Creates the footer for a table in a SQL file.
	 *
	 * @param $table
	 * @param $target_table_name
	 *
	 * @return null
	 */
	function build_table_footer( $table, $target_table_name, $fp ) {
		$state_data = $this->migration_state_manager->set_post_data();
		global $wpdb;

		$stow = "\n";
		$stow .= "#\n";
		$stow .= '# ' . sprintf( __( 'End of data contents of table %s', 'wp-migrate-db' ), $this->table_helper->backquote( $target_table_name ) ) . "\n";
		$stow .= "# --------------------------------------------------------\n";
		$stow .= "\n";
		$this->stow( $stow, false, $fp );


		if ( $state_data['last_table'] == '1' ) {
			$stow = "#\n";
			$stow .= "# Add constraints back in and apply any alter data queries.\n";
			$stow .= "#\n\n";
			$stow .= $this->get_alter_queries();
			$this->stow( $stow, false, $fp );

			$alter_table_name = $this->get_alter_table_name();

			$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->table_helper->backquote( $alter_table_name ) . ';' );

			if ( 'backup' == $state_data['stage'] ) {
				// Re-create our table to store 'ALTER' queries so we don't get duplicates.
				$create_alter_table_query = $this->get_create_alter_table_query();
				$process_chunk_result     = $this->process_chunk( $create_alter_table_query );
				if ( true !== $process_chunk_result ) {
					$result = $this->http->end_ajax( $process_chunk_result );

					return $result;
				}
			}
		}
	}

	function get_alter_queries() {
		global $wpdb;

		$alter_table_name = $this->get_alter_table_name();
		$alter_queries    = array();
		$sql              = '';

		if ( $alter_table_name === $wpdb->get_var( "SHOW TABLES LIKE '$alter_table_name'" ) ) {
			$alter_queries = $wpdb->get_results( "SELECT * FROM `{$alter_table_name}`", ARRAY_A );
			$alter_queries = apply_filters( 'wpmdb_get_alter_queries', $alter_queries );
		}

		if ( ! empty( $alter_queries ) ) {
			foreach ( $alter_queries as $alter_query ) {
				$sql .= $alter_query['query'] . "\n";
			}
		}

		return $sql;
	}

	/**
	 * Returns a fragment of SQL for creating the table where the alter statements are held during the migration.
	 *
	 * @return string
	 */
	function get_create_alter_table_query() {
		if ( ! is_null( $this->create_alter_table_query ) ) {
			return $this->create_alter_table_query;
		}

		$legacy_alter_table_name        = $this->get_legacy_alter_table_name();
		$this->create_alter_table_query = sprintf( "DROP TABLE IF EXISTS `%s`;\n", $legacy_alter_table_name );

		$alter_table_name               = $this->get_alter_table_name();
		$this->create_alter_table_query .= sprintf( "DROP TABLE IF EXISTS `%s`;\n", $alter_table_name );
		$this->create_alter_table_query .= sprintf( "CREATE TABLE `%s` ( `query` LONGTEXT NOT NULL );\n", $alter_table_name );
		$this->create_alter_table_query = apply_filters( 'wpmdb_create_alter_table_query', $this->create_alter_table_query );

		return $this->create_alter_table_query;
	}

	function delete_temporary_tables( $prefix ) {
		$tables         = $this->get_tables();
		$delete_queries = '';

		foreach ( $tables as $table ) {
			if ( 0 !== strpos( $table, $prefix ) ) {
				continue;
			}
			$delete_queries .= sprintf( "DROP TABLE %s;\n", $this->table_helper->backquote( $table ) );
		}

		$this->process_chunk( $delete_queries );
	}

	/**
	 * Get only the tables beginning with our DB prefix or temporary prefix, also skip views and legacy wpmdb_alter_statements table.
	 *
	 * @param string $scope
	 *
	 * @return array
	 */
	function get_tables( $scope = 'regular' ) {
		global $wpdb;
		$prefix       = ( $scope == 'temp' ? $this->props->temp_prefix : $wpdb->base_prefix );
		$tables       = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );
		$clean_tables = array();

		foreach ( $tables as $table ) {
			if ( ( ( $scope == 'temp' || $scope == 'prefix' ) && 0 !== strpos( $table[0], $prefix ) ) || $table[1] == 'VIEW' ) {
				continue;
			}
			if ( $this->get_legacy_alter_table_name() == $table[0] ) {
				continue;
			}
			$clean_tables[] = $table[0];
		}

		return apply_filters( 'wpmdb_tables', $clean_tables, $scope );
	}

	function db_backup_header( $fp ) {
		$state_data = $state_data = $this->migration_state_manager->set_post_data();
		$form_data  = $this->form_data->getFormData();
		global $wpdb;

		$charset = ( defined( 'DB_CHARSET' ) ? DB_CHARSET : 'utf8' );
		$this->stow( '# ' . __( 'WordPress MySQL database migration', 'wp-migrate-db' ) . "\n", false, $fp );
		$this->stow( "#\n", false, $fp );
		$this->stow( '# ' . sprintf( __( 'Generated: %s', 'wp-migrate-db' ), date( 'l j. F Y H:i T' ) ) . "\n", false, $fp );
		$this->stow( '# ' . sprintf( __( 'Hostname: %s', 'wp-migrate-db' ), DB_HOST ) . "\n", false, $fp );
		$this->stow( '# ' . sprintf( __( 'Database: %s', 'wp-migrate-db' ), $this->table_helper->backquote( DB_NAME ) ) . "\n", false, $fp );

		$home_url = apply_filters( 'wpmdb_backup_header_url', home_url() );
		$url      = preg_replace( '(^https?:)', '', $home_url, 1 );
		$key      = array_search( $url, $form_data['replace_old'] );

		if ( false !== $key ) {
			$url = $form_data['replace_new'][ $key ];
		} else {
			// Protocol might have been added in
			$key = array_search( $home_url, $form_data['replace_old'] );

			if ( false !== $key ) {
				$url = $form_data['replace_new'][ $key ];
			}
		}

		$this->stow( '# URL: ' . esc_html( addslashes( $url ) ) . "\n", false, $fp );

		$path = $this->util->get_absolute_root_file_path();
		$key  = array_search( $path, $form_data['replace_old'] );

		if ( false !== $key ) {
			$path = $form_data['replace_new'][ $key ];
		}

		$this->stow( '# Path: ' . esc_html( addslashes( $path ) ) . "\n", false, $fp );

		$included_tables = $this->get_tables( 'prefix' );

		if ( 'savefile' === $state_data['intent'] && isset( $form_data['table_migrate_option'] ) && 'migrate_select' === $form_data['table_migrate_option'] ) {
			$included_tables = $form_data['select_tables'];
		}

		$included_tables = apply_filters( 'wpmdb_backup_header_included_tables', $included_tables );

		$this->stow( '# Tables: ' . implode( ', ', $included_tables ) . "\n", false, $fp );
		$this->stow( '# Table Prefix: ' . $wpdb->base_prefix . "\n", false, $fp );
		$this->stow( '# Post Types: ' . implode( ', ', $this->get_post_types() ) . "\n", false, $fp );

		$protocol = 'http';
		if ( 'https' === substr( $home_url, 0, 5 ) ) {
			$protocol = 'https';
		}

		$this->stow( '# Protocol: ' . $protocol . "\n", false, $fp );

		$is_multisite = is_multisite() ? 'true' : 'false';
		$this->stow( '# Multisite: ' . $is_multisite . "\n", false, $fp );

		$is_subsite_export = apply_filters( 'wpmdb_backup_header_is_subsite_export', 'false' );
		$this->stow( '# Subsite Export: ' . $is_subsite_export . "\n", false, $fp );

		$this->stow( "# --------------------------------------------------------\n\n", false, $fp );
		$this->stow( "/*!40101 SET NAMES $charset */;\n\n", false, $fp );
		$this->stow( "SET sql_mode='NO_AUTO_VALUE_ON_ZERO';\n\n", false, $fp );
	}

	/**
	 * Return array of post type slugs stored within DB.
	 *
	 * @return array List of post types
	 */
	function get_post_types() {
		global $wpdb;

		if ( is_multisite() ) {
			$tables         = $this->get_tables( 'prefix' );
			$sql            = "SELECT DISTINCT `post_type` FROM `{$wpdb->base_prefix}posts` ;";
			$post_types     = $wpdb->get_results( $sql, ARRAY_A );
			$prefix_escaped = preg_quote( $wpdb->base_prefix, '/' );

			foreach ( $tables as $table ) {
				if ( 0 == preg_match( '/' . $prefix_escaped . '[0-9]+_posts/', $table ) ) {
					continue;
				}
				$blog_id         = str_replace( array( $wpdb->base_prefix, '_posts' ), array( '', '' ), $table );
				$sql             = "SELECT DISTINCT `post_type` FROM `{$wpdb->base_prefix}" . $blog_id . '_posts` ;';
				$site_post_types = $wpdb->get_results( $sql, ARRAY_A );
				if ( is_array( $site_post_types ) ) {
					$post_types = array_merge( $post_types, $site_post_types );
				}
			}
		} else {
			$post_types = $wpdb->get_results(
				"SELECT DISTINCT `post_type`
				FROM `{$wpdb->base_prefix}posts`
				WHERE 1;",
				ARRAY_A
			);
		}

		$return = array( 'revision' );

		foreach ( $post_types as $post_type ) {
			$return[] = $post_type['post_type'];
		}

		return apply_filters( 'wpmdb_post_types', array_unique( $return ) );
	}

	function empty_current_chunk() {
		$this->current_chunk = '';
	}


	/**
	 * Removes ANSI quotes from a given string and replaces it with backticks `
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	public function remove_ansi_quotes( $string ) {
		if ( ! is_string( $string ) ) {

			return $string;
		}
		return str_replace( '"', '`', $string );
	}
}
