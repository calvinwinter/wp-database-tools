<?php

namespace DeliciousBrains\WPMDB\Common;

use DeliciousBrains\WPMDB\Common\Error\ErrorLog;
use DeliciousBrains\WPMDB\Common\MigrationState\MigrationStateManager;
use DeliciousBrains\WPMDB\Common\Sql\TableHelper;
use DeliciousBrains\WPMDB\Common\Util\Util;

class Replace {

	/**
	 * @var
	 */
	protected $search;
	/**
	 * @var
	 */
	protected $replace;
	/**
	 * @var
	 */
	protected $subdomain_replaces_on;
	/**
	 * @var
	 */
	protected $intent;
	/**
	 * @var
	 */
	protected $base_domain;
	/**
	 * @var
	 */
	protected $site_domain;
	/**
	 * @var
	 */
	protected $site_details;
	/**
	 * @var
	 */
	protected $source_protocol;
	/**
	 * @var
	 */
	protected $destination_protocol;
	/**
	 * @var
	 */
	protected $destination_url;
	/**
	 * @var bool
	 */
	protected $is_protocol_mismatch = false;

	/**
	 * @var
	 */
	public $state_data;
	/**
	 * @var TableHelper
	 */
	public $table_helper;
	/**
	 * @var MigrationStateManager
	 */
	public $migration_state_manager;

	/**
	 * @var
	 */
	protected $table;
	/**
	 * @var
	 */
	protected $column;
	/**
	 * @var
	 */
	protected $row;
	/**
	 * @var ErrorLog
	 */
	protected $error_log;
	/**
	 * @var Util\Util
	 */
	protected $util;
	/**
	 * @var array
	 */
	protected $json_search;
	/**
	 * @var array
	 */
	protected $json_replace;
	/**
	 * @var array
	 */
	protected $json_replace_tables;
	/**
	 * @var array
	 */
	protected $json_replace_columns;

	protected $json_merged;

	function __construct(
		MigrationStateManager $migration_state_manager,
		TableHelper $table_helper,
		ErrorLog $error_log,
		Util $util
	) {
		$this->migration_state_manager = $migration_state_manager;
		$this->table_helper            = $table_helper;
		$this->error_log               = $error_log;
		$this->util                    = $util;
	}

	public function get($prop){
		return $this->$prop;
	}

	public function set($prop, $value){
		return $this->$prop = $value;
	}

	public function register( $args ) {
		$keys = array(
			'table',
			'search',
			'replace',
			'intent',
			'base_domain',
			'site_domain',
			'wpmdb',
			'site_details',
		);

		if ( ! is_array( $args ) ) {
			throw new \InvalidArgumentException( 'WPMDB_Replace constructor expects the argument to be an array' );
		}

		foreach ( $keys as $key ) {
			if ( ! isset( $args[ $key ] ) ) {
				throw new \InvalidArgumentException( "WPMDB_Replace constructor expects '$key' key to be present in the array argument" );
			}
		}

		$this->table                = $args['table'];
		$this->search               = $args['search'];
		$this->replace              = $args['replace'];
		$this->intent               = $args['intent'];
		$this->base_domain          = $args['base_domain'];
		$this->site_domain          = $args['site_domain'];
		$this->site_details         = $args['site_details'];
		$this->json_search          = '';
		$this->json_replace         = '';
		$this->json_replace_tables  = '';
		$this->json_replace_columns = '';
		$this->json_merged          = false;

		global $wpdb;

		$prefix = $wpdb->base_prefix;

		$this->json_replaces( $prefix );

		// Detect a protocol mismatch between the remote and local sites involved in the migration
		$this->detect_protocol_mismatch();

		return $this;
	}

	/**
	 * Determine whether to apply a subdomain replace over each value in the database.
	 *
	 * @return bool
	 */
	function is_subdomain_replaces_on() {
		if ( ! isset( $this->subdomain_replaces_on ) ) {
			$this->subdomain_replaces_on = ( is_multisite() && is_subdomain_install() && ! $this->has_same_base_domain() && apply_filters( 'wpmdb_subdomain_replace', true ) );
		}

		return $this->subdomain_replaces_on;
	}


	/**
	 * Determine if the replacement has the same base domain as the search. Produces doubled replacement strings
	 * otherwise.
	 *
	 * @return bool
	 */
	function has_same_base_domain() {
		if ( 'push' !== $this->intent || 'pull' !== $this->intent ) {
			$destination_url = $this->base_domain;
		} else {
			$destination_url = isset( $this->destination_url ) ? $this->destination_url : $this->site_details['local']['site_url'];
		}

		if ( stripos( $destination_url, $this->site_domain ) ) {
			return true;
		}

		return false;
	}


	/**
	 * Automatically replace URLs for subdomain based multisite installations
	 * e.g. //site1.example.com -> //site1.example.local for site with domain example.com
	 * NB: only handles the current network site, does not work for additional networks / mapped domains
	 *
	 * @param $new
	 *
	 * @return mixed
	 */
	function subdomain_replaces( $new ) {
		if ( empty( $this->base_domain ) ) {
			return $new;
		}

		$pattern     = '|//(.*?)\\.' . preg_quote( $this->site_domain, '|' ) . '|';
		$replacement = '//$1.' . trim( $this->base_domain );
		$new         = preg_replace( $pattern, $replacement, $new );

		return $new;
	}

	/**
	 * Detect a protocol mismatch between the remote and local sites involved in the migration
	 *
	 * @return bool
	 */
	function detect_protocol_mismatch() {
		if ( ! isset( $this->site_details['remote'] ) && 'import' !== $this->intent ) {
			return false;
		}

		$wpmdb_home_urls = array(
			// TODO: rewrite unit tests that only pass site_url so that we can rely on home_url's existence
			'local' => isset( $this->site_details['local']['home_url'] ) ? $this->site_details['local']['home_url'] : $this->site_details['local']['site_url'],
		);

		if ( 'import' !== $this->intent ) {
			$wpmdb_home_urls['remote'] = isset( $this->site_details['remote']['home_url'] ) ? $this->site_details['remote']['home_url'] : $this->site_details['remote']['site_url'];
		} else {
			$this->state_data = $this->migration_state_manager->set_post_data();

			if ( ! isset( $this->state_data['import_info'] ) || ! isset( $this->state_data['import_info']['protocol'] ) ) {
				return false;
			}
			$wpmdb_home_urls['remote'] = $this->state_data['import_info']['protocol'] . ':' . $this->state_data['import_info']['URL'];
		}

		/**
		 * Filters the site_urls used to check if there is a protocol mismatch.
		 *
		 * @param array
		 */
		$wpmdb_home_urls = apply_filters( 'wpmdb_replace_site_urls', $wpmdb_home_urls );

		$local_url_is_https  = false === stripos( $wpmdb_home_urls['local'], 'https' ) ? false : true;
		$remote_url_is_https = false === stripos( $wpmdb_home_urls['remote'], 'https' ) ? false : true;
		$local_protocol      = $local_url_is_https ? 'https' : 'http';
		$remote_protocol     = $remote_url_is_https ? 'https' : 'http';

		if ( ( $local_url_is_https && ! $remote_url_is_https ) || ( ! $local_url_is_https && $remote_url_is_https ) ) {
			$this->is_protocol_mismatch = true;
		}

		if ( 'push' === $this->intent ) {
			$this->destination_protocol = $remote_protocol;
			$this->source_protocol      = $local_protocol;
			$this->destination_url      = $wpmdb_home_urls['remote'];
		} else {
			$this->destination_protocol = $local_protocol;
			$this->source_protocol      = $remote_protocol;
			$this->destination_url      = $wpmdb_home_urls['local'];
		}

		return $this->is_protocol_mismatch;
	}

	/**
	 *
	 * Handles replacing the protocol if the local and destination don't have matching protocols (http > https and
	 * vice-versa).
	 *
	 * Can be filtered to disable entirely.
	 *
	 * @param string $new
	 * @param string $destination_url
	 *
	 * @return mixed
	 */
	function do_protocol_replace( $new, $destination_url ) {
		/**
		 * Filters $do_protocol_replace, return false to prevent protocol replacement.
		 *
		 * @param bool true                   If the replace should be skipped.
		 * @param string $destination_url The URL of the target site.
		 */
		$do_protocol_replace = apply_filters( 'wpmdb_replace_destination_protocol', true, $destination_url );

		if ( true !== $do_protocol_replace ) {
			return $new;
		}

		$parsed_destination = Util::parse_url( $destination_url );
		unset( $parsed_destination['scheme'] );

		if ( isset( $parsed_destination['port'] ) ) {
			$parsed_destination['port'] = ':' . $parsed_destination['port'];
		}

		$protocol_search  = $this->source_protocol . '://' . implode( '', $parsed_destination );
		$protocol_replace = $destination_url;

		// JSON search & replace
		if ( in_array( $this->table, $this->json_replace_tables )
		     && in_array( $this->column, $this->json_replace_columns )
		) {
			$protocol_search  = [ $protocol_search, Util::json_encode_trim( $protocol_search ) ];
			$protocol_replace = [ $protocol_replace, Util::json_encode_trim( $protocol_replace ) ];
		}

		$new = str_ireplace( $protocol_search, $protocol_replace, $new, $count );

		return $new;
	}


	public function maybe_merge_json_replaces()
	{
		if ( $this->json_merged ) {
			return false;
		}

		if ( !in_array( $this->table, $this->json_replace_tables ) ||
		     !in_array( $this->column, $this->json_replace_columns ) ) {
			return false;
		}

		if ( empty( $this->search ) && empty( $this->replace ) ) {
			return false;
		}

		if ( !is_array( $this->json_search ) || !is_array( $this->json_replace ) ) {
			return false;
		}

		//Only add json replacements once
		$this->search      = array_merge( $this->search, $this->json_search );
		$this->replace     = array_merge( $this->replace, $this->json_replace );
		$this->json_merged = true;

		return true;
	}

	/**
	 * Applies find/replace pairs to a given string.
	 *
	 * @param string $subject
	 *
	 * @return string
	 */
	public function apply_replaces( $subject )
	{
		if ( empty( $this->search ) && empty( $this->replace ) ) {
			return $subject;
		}

		$this->maybe_merge_json_replaces(); // Maybe merge in json_encoded find/replace values
		$new = str_ireplace( $this->search, $this->replace, $subject, $count );

		if ( $this->is_subdomain_replaces_on() ) {
			$new = $this->subdomain_replaces( $new );
		}

		if ( true === $this->is_protocol_mismatch ) {
			$new = $this->do_protocol_replace( $new, $this->destination_url );
		}

		return $new;
	}

	/**
	 * Take a serialized array and unserialize it replacing elements as needed and
	 * unserialising any subordinate arrays and performing the replace on those too.
	 *
	 * Mostly from https://github.com/interconnectit/Search-Replace-DB
	 *
	 * @param mixed $data              Used to pass any subordinate arrays back to in.
	 * @param bool  $serialized        Does the array passed via $data need serialising.
	 * @param bool  $parent_serialized Passes whether the original data passed in was serialized
	 * @param bool  $filtered          Should we apply before and after filters successively
	 *
	 * @return mixed    The original array with all elements replaced as needed.
	 */
	function recursive_unserialize_replace( $data, $serialized = false, $parent_serialized = false, $filtered = true ) {
		$pre = apply_filters( 'wpmdb_pre_recursive_unserialize_replace', false, $data, $this );
		if ( false !== $pre ) {
			return $pre;
		}

		// Some options contain serialized self-references which leads to memory exhaustion. Skip these.
		if ( $this->table_is( 'options' ) && 'option_value' === $this->get_column() && is_serialized( $data ) ) {
			if ( preg_match( '/r\:\d+/i', $data ) ) {
				return $data;
			}
		}

		$is_json           = false;
		$before_fired      = false;
		$successive_filter = $filtered;

		if ( true === $filtered ) {
			list( $data, $before_fired, $successive_filter ) = apply_filters( 'wpmdb_before_replace_custom_data', array(
				$data,
				$before_fired,
				$successive_filter,
			), $this );
		}

		// some unserialized data cannot be re-serialized eg. SimpleXMLElements
		try {
			if ( is_string( $data ) && ( $unserialized = Util::unserialize( $data, __METHOD__ ) ) !== false ) {
				// PHP currently has a bug that doesn't allow you to clone the DateInterval / DatePeriod classes.
				// We skip them here as they probably won't need data to be replaced anyway
				if ( 'object' == gettype( $unserialized ) ) {
					if ( $unserialized instanceof \DateInterval || $unserialized instanceof \DatePeriod ) {
						return $data;
					}
					if ( $unserialized instanceof \__PHP_Incomplete_Class && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
						$objectName = array();
						preg_match( '/O:\d+:\"([^\"]+)\"/', $data, $objectName );
						$objectName = $objectName[1] ? $objectName[1] : $data;
						$error      = sprintf( __( "WP Migrate DB - Failed to instantiate object for replacement. If the serialized object's class is defined by a plugin, you should enable that plugin for migration requests. \nClass Name: %s", 'wp-migrate-db' ), $objectName );
						error_log( $error );

						return $data;
					}
				}
				$data = $this->recursive_unserialize_replace( $unserialized, true, true, $successive_filter );
			} elseif ( is_array( $data ) ) {
				$_tmp = array();
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_replace( $value, false, $parent_serialized, $successive_filter );
				}

				$data = $_tmp;
				unset( $_tmp );
			} elseif ( is_object( $data ) ) { // Submitted by Tina Matter
				$_tmp = clone $data;
				foreach ( $data as $key => $value ) {
					// Integer properties are crazy and the best thing we can do is to just ignore them.
					// see http://stackoverflow.com/a/10333200 and https://github.com/deliciousbrains/wp-migrate-db-pro/issues/853
					if ( is_int( $key ) ) {
						continue;
					}
					$_tmp->$key = $this->recursive_unserialize_replace( $value, false, $parent_serialized, $successive_filter );
				}

				$data = $_tmp;
				unset( $_tmp );
			} elseif ( Util::is_json( $data, true ) ) {
				$_tmp = array();
				$data = json_decode( $data, true );

				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_replace( $value, false, $parent_serialized, $successive_filter );
				}

				$data = $_tmp;
				unset( $_tmp );
				$is_json = true;
			} elseif ( is_string( $data ) ) {
				list( $data, $do_replace ) = apply_filters( 'wpmdb_replace_custom_data', array( $data, true ), $this );

				if ( $do_replace ) {
					$data = $this->apply_replaces( $data );
				}
			}

			if ( $is_json ) {
				$data = json_encode( $data );
			}

			if ( $serialized ) {
				$data = serialize( $data );
			}
		} catch ( \Exception $error ) {
			$error_msg     = __( 'Failed attempting to do the recursive unserialize replace. Please contact support.', 'wp-migrate-db' );
			$error_details = $error->getMessage() . "\n\n";
			$error_details .= var_export( $data, true );
			$this->error_log->log_error( $error_msg, $error_details );
		}

		if ( true === $filtered ) {
			$data = apply_filters( 'wpmdb_after_replace_custom_data', $data, $before_fired, $this );
		}

		return $data;
	}

	/**
	 * Getter for the $table class property.
	 *
	 * @return string Name of the table currently being processed in the migration.
	 */
	public function get_table() {
		return $this->table;
	}

	/**
	 * Getter for the $column class property.
	 *
	 * @return string Name of the column currently being processed in the migration.
	 */
	public function get_column() {
		return $this->column;
	}

	/**
	 * Getter for the $row class property.
	 *
	 * @return string Name of the row currently being processed in the migration.
	 */
	public function get_row() {
		return $this->row;
	}

	/**
	 * Setter for the $column class property.
	 *
	 * @param string $column Name of the column currently being processed in the migration.
	 */
	public function set_column( $column ) {
		$this->column = $column;
	}

	/**
	 * Setter for the $row class property.
	 *
	 * @param string $row Name of the row currently being processed in the migration.
	 */
	public function set_row( $row ) {
		$this->row = $row;
	}

	/**
	 * Multsite safe way of comparing the table currently being processed in the migration against a desired table.
	 *
	 * The table prefix should be omitted, example:
	 *
	 * $is_posts = $this->table_is( 'posts' );
	 *
	 * @param  string $desired_table Name of the desired table, table prefix omitted.
	 *
	 * @return boolean                Whether or not the desired table is the table currently being processed.
	 */
	public function table_is( $desired_table ) {
		return $this->table_helper->table_is( $desired_table, $this->table );
	}

	/**
	 * Intent of the current replace migration.
	 *
	 * Helpful for hookers who need to know what intent they are working on.
	 *
	 * @return string Intent of the current migration
	 */
	public function get_intent() {
		return $this->intent;
	}

	/**
	 * @param string $prefix
	 */
	protected function json_replaces( $prefix )
	{
		$default_tables = [
			"${prefix}posts",
		];

		if ( in_array( $this->intent, ['find_replace', 'import'] ) ) {
			$default_tables = [
				"_mig_${prefix}posts",
			];
		}

		$this->json_replace_tables = apply_filters( 'wpmdb_json_replace_tables', $default_tables );

		$this->json_replace_columns = apply_filters( 'wpmdb_json_replace_columns', [
			'post_content',
			'post_content_filtered',
		] );

		if ( empty( $this->search ) && empty( $this->replace ) ) {
			return;
		}

		if ( is_array( $this->search ) ) {
			$this->json_search = array_map( function ( $item ) {
				return Util::json_encode_trim( $item );
			}, $this->search );
		}

		if ( is_array( $this->replace ) ) {
			$this->json_replace = array_map( function ( $item ) {
				return Util::json_encode_trim( $item );
			}, $this->replace );
		}
	}
}
