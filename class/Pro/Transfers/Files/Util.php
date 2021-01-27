<?php

namespace DeliciousBrains\WPMDB\Pro\Transfers\Files;

use DeliciousBrains\WPMDB\Common\Error\ErrorLog;
use DeliciousBrains\WPMDB\Common\Filesystem\Filesystem;
use DeliciousBrains\WPMDB\Common\Http\Helper;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Http\RemotePost;
use DeliciousBrains\WPMDB\Common\MigrationState\MigrationStateManager;
use DeliciousBrains\WPMDB\Common\Settings\Settings;
use DeliciousBrains\WPMDB\Pro\Transfers\Receiver;

class Util {

	public $filesystem;
	/**
	 * @var Http
	 */
	private $http;
	/**
	 * @var ErrorLog
	 */
	private $error_log;
	/**
	 * @var Helper
	 */
	private $http_helper;
	/**
	 * @var RemotePost
	 */
	private $remote_post;
	/**
	 * @var Settings
	 */
	private $settings;
	/**
	 * @var MigrationStateManager
	 */
	private $migration_state_manager;


	public function __construct(
		Filesystem $filesystem,
		Http $http,
		ErrorLog $error_log,
		Helper $http_helper,
		RemotePost $remote_post,
		Settings $settings,
		MigrationStateManager $migration_state_manager
	) {
		$this->filesystem              = $filesystem;
		$this->http                    = $http;
		$this->error_log               = $error_log;
		$this->http_helper             = $http_helper;
		$this->remote_post             = $remote_post;
		$this->settings                = $settings->get_settings();
		$this->migration_state_manager = $migration_state_manager;
	}

	public function get_remote_files( array $directories, $action, $excludes ) {

		// POST to remote to get list of files
		$state_data = $this->migration_state_manager->set_post_data();

		$data                    = array();
		$data['action']          = $action;
		$data['remote_state_id'] = $state_data['remote_state_id'];
		$data['intent']          = $state_data['intent'];
		$data['folders']         = serialize( $directories );
		$data['excludes']        = serialize( $excludes );
		$data['stage']           = $state_data['stage'];

		$data['sig'] = $this->http_helper->create_signature( $data, $state_data['key'] );

		$ajax_url = trailingslashit( $state_data['url'] ) . 'wp-admin/admin-ajax.php';
		$response = $this->remote_post->post( $ajax_url, $data, __FUNCTION__ );
		$response = $this->remote_post->verify_remote_post_response( $response );

		if ( isset( $response['wpmdb_error'] ) ) {
			return $response;
		}

		return $response;
	}

	/**
	 * @param array  $queue_status
	 * @param string $action
	 *
	 * @return array|bool|mixed|object|string
	 * @throws \Exception
	 */
	public function save_queue_status_to_remote( array $queue_status, $action ) {
		$state_data = $this->migration_state_manager->set_post_data();

		$data                    = array();
		$data['action']          = $action;
		$data['remote_state_id'] = $state_data['remote_state_id'];
		$data['intent']          = $state_data['intent'];
		$data['stage']           = $state_data['stage'];
		$data['sig']             = $this->http_helper->create_signature( $data, $state_data['key'] );

		$data['queue_status'] = base64_encode( gzencode( serialize( $queue_status ) ) );

		$ajax_url = trailingslashit( $state_data['url'] ) . 'wp-admin/admin-ajax.php';
		$response = $this->remote_post_and_verify( $ajax_url, $data );

		return $response;
	}

	/**
	 * Fire POST at remote and check for the 'wpmdb_error' key in response
	 *
	 * @param string $ajax_url
	 * @param array  $data
	 *
	 * @return array|bool|mixed|object|string
	 * @throws \Exception
	 */
	public function remote_post_and_verify( $ajax_url, $data, $headers = array() ) {
		$requests_options = $this->get_requests_options();
		$response         = null;

		try {
			$response = \Requests::post( $ajax_url, $headers, $data, $requests_options );
		} catch ( \Exception $e ) {
			$this->catch_general_error( $e->getMessage() );
		}

		$response_body = json_decode( $response->body, true );

		if ( isset( $response_body['wpmdb_error'] ) ) {
			throw new \Exception( $response_body['body'] );
		}

		return $response;
	}

	/**
	 * @param       $msg
	 * @param array $data
	 *
	 * @return mixed
	 */
	public function ajax_error( $msg, $data = array() ) {

		$return = array(
			'wpmdb_error' => 1,
			'body'        => $msg,
		);

		$this->error_log->log_error( $msg, $data );
		$return = json_encode( $return );

		return $this->http->end_ajax( $return );
	}

	/**
	 *
	 * Handles individual file transfer errors
	 *
	 * @param string $message
	 *
	 * @return array
	 */
	public function fire_transfer_errors( $message ) {
		error_log( $message );
		$this->error_log->log_error( $message );

		return [
			'error'   => true,
			'message' => $message,
		];
	}

	/**
	 *
	 *
	 * @param $message
	 *
	 * @return null
	 */
	public function catch_general_error( $message ) {
		$return = [
			'wpmdb_error' => true,
			'msg'         => $message,
		];

		$this->error_log->log_error( $message );
		$this->http->end_ajax( json_encode( $return ) );
	}

	/**
	 * @return array
	 */
	public function get_requests_options() {

		// Listen to SSL verify setting
		$wpmdb_settings   = $this->settings;
		$sslverify        = 1 === $wpmdb_settings['verify_ssl'];
		$requests_options = [];

		// Make Requests cURL transport wait 45s for timeouts
		$hooks = new \Requests_Hooks();
		$hooks->register( 'curl.before_send', function ( $handle ) {
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 45 );
			curl_setopt( $handle, CURLOPT_TIMEOUT, 45 );
			curl_setopt( $handle, CURLOPT_ENCODING, 'gzip,deflate' );
		} );

		$requests_options['hooks'] = $hooks;

		if ( ! $sslverify ) {
			$requests_options['verify'] = false;
		}

		return $requests_options;
	}

	/**
	 * Check's that files migrated match the .manifest file. Always fires at the migration destination
	 *
	 * @param array  $files
	 * @param string $stage
	 *
	 * @throws \Exception
	 */
	public function check_manifest( $files, $stage ) {
		$failures = [];

		foreach ( $files as $file ) {
			$file_path = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . $stage . DIRECTORY_SEPARATOR . 'tmp' . $this->filesystem->slash_one_direction( $file );

			if ( ! file_exists( $file_path ) ) {
				$failures[] = $file_path;
			}
		}

		if ( ! empty( $failures ) ) {
			throw new \Exception( sprintf( __( 'The following files failed to transfer: <br> %s', 'wp-migrate-db' ), implode( '<br>', $failures ) ) );
		}
	}

	/**
	 * @param array $data
	 * @param       $stage
	 *
	 * @return bool|int
	 * @throws \Exception
	 */
	public function save_queue_status( array $data, $stage, $migration_state_id ) {
		$tmp_path = Receiver::get_temp_dir() . $stage . DIRECTORY_SEPARATOR . 'tmp';

		if ( ! $this->filesystem->mkdir( $tmp_path ) ) {
			throw new \Exception( sprintf( __( 'Unable to create folder for file transfers: %s' ), $tmp_path ) );
		}

		$filename = '.' . $migration_state_id . '-manifest';

		$manifest = file_put_contents( $tmp_path . DIRECTORY_SEPARATOR . $filename, serialize( $data ) );

		if ( ! $manifest ) {
			throw new \Exception( sprintf( __( 'Unable to create the transfer manifest file. Verify the web server can write to this folder: %s' ), $tmp_path . DIRECTORY_SEPARATOR . '.manifest' ) );
		}

		return $manifest;
	}

	/**
	 * Will look for a tmp folder to remove based on the $stage param (themes, plugins)
	 *
	 * @param $stage
	 *
	 * @return bool
	 */
	public function remove_tmp_folder( $stage ) {
		$fs         = $this->filesystem;
		$tmp_folder = Receiver::get_temp_dir() . $stage . '/tmp/';

		if ( $fs->file_exists( $tmp_folder ) ) {
			if ( $fs->is_dir( $tmp_folder ) ) {
				return $fs->rmdir( $tmp_folder, true );
			}
		}

		return true;
	}

	/**
	 *
	 * Verify a file is the correct size
	 *
	 * @param string $filepath
	 * @param int    $expected_size
	 *
	 * @return bool
	 */
	public function verify_file( $filepath, $expected_size ) {
		if ( ! file_exists( $filepath ) ) {
			return false;
		}

		$filesystem_size = filesize( $filepath );
		if ( $filesystem_size !== (int) $expected_size ) {
			return false;
		}

		return true;
	}

	public function enqueue_files( $files, $queue_manager ) {
		foreach ( $files as $file ) {
			$queue_manager->enqueue_file( $file );
		}
	}

	/**
	 * Determine folder tranferred numbers for client.
	 *
	 * @param array $data
	 * @param int   $bytes_transferred
	 * @param array $state_data
	 *
	 * @return array
	 */
	public function process_queue_data( $data, $state_data, $bytes_transferred = 0 ) {
		$result_set = [];

		if ( empty( $data ) ) {
			return array( $result_set, 0 );
		}

		// Could be empty - stores progress of folder migrations between requests. Generally, the size of batch is 100 files and each file could be from a separate folder
		$folder_transfer_status = get_site_option( 'wpmdb_folder_transfers_' . $state_data['migration_state_id'] );
		$total_transferred      = 0;
		$batch_size             = 0;

		foreach ( $data as $key => $record ) {
			$is_chunked = isset( $record['chunked'] ) && $record['chunked'];
			$dirname    = $record['folder_name'];
			$keys       = array_keys( $result_set );

			// This method is called in WPMDBPro_Theme_Plugin_Files_Local::ajax_initiate_file_migration()
			// $bytes_transferred = 0 and we don't need to iterate over _all_ the files
			if ( 0 === $bytes_transferred && \in_array( $dirname, $keys ) ) {
				continue;
			}

			if ( 0 !== $bytes_transferred ) {
				if ( ! isset( $folder_transfer_status[ $dirname ] ) ) {
					$batch_size = 0;

					$folder_transfer_status[ $dirname ] = [
						'folder_transferred'         => 0,
						'folder_percent_transferred' => 0,
					];
				}

				$item_size = $record['size'];

				if ( $is_chunked ) {
					$item_size = $record['chunk_size'];
				}

				$folder_transfer_status[ $dirname ]['folder_transferred'] += $item_size;

				if ( ! $is_chunked ) {
					$batch_size += $item_size;
				} else {
					$batch_size = $item_size;
				}

				$folder_transfer_status[ $dirname ]['folder_percent_transferred'] = $folder_transfer_status[ $dirname ]['folder_transferred'] / $record['folder_size'];
			}

			$result_set[ $dirname ] = [
				'nice_name'                  => $record['nice_name'],
				'relative_path'              => DIRECTORY_SEPARATOR . $dirname,
				'absolute_path'              => $record['folder_abs_path'],
				'size'                       => $record['folder_size'],
				'batch_size'                 => $batch_size,
				'folder_transferred'         => $folder_transfer_status[ $dirname ]['folder_transferred'],
				'folder_percent_transferred' => $folder_transfer_status[ $dirname ]['folder_percent_transferred'],
				'total_transferred'          => $bytes_transferred,
			];
		}

		$this->update_folder_status( $state_data, $result_set, $bytes_transferred );

		// Maybe compute folder percent transferred here?
		return $result_set;
	}

	/**
	 * @param array $state_data
	 * @param array $result_set
	 * @param int   $bytes_transferred
	 *
	 * @return bool
	 */
	public function update_folder_status( $state_data, $result_set, $bytes_transferred ) {
		if ( 0 === $bytes_transferred ) {
			return false;
		}

		$folders_in_progress = [];

		foreach ( $result_set as $key => $folder ) {
			if ( $folder['folder_transferred'] < $folder['size'] ) {
				$folders_in_progress[ $key ] = $folder;
			}
		}

		if ( empty( $folders_in_progress ) && 0 !== $bytes_transferred ) {
			delete_site_option( 'wpmdb_folder_transfers_' . $state_data['migration_state_id'] );
		} else {
			update_site_option( 'wpmdb_folder_transfers_' . $state_data['migration_state_id'], $folders_in_progress );
		}

		return true;
	}

}
