<?php

namespace DeliciousBrains\WPMDB\Pro\Backups;

use DeliciousBrains\WPMDB\Common\Filesystem\Filesystem;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Util\Util;

class BackupsManager {

	/**
	 * @var Http
	 */
	private $http;
	/**
	 * @var Filesystem
	 */
	private $filesystem;

	/**
	 * BackupsManager constructor.
	 *
	 * @param Http       $http
	 * @param Filesystem $filesystem
	 */
	public function __construct(
		Http $http,
		Filesystem $filesystem
	) {
		$this->http       = $http;
		$this->filesystem = $filesystem;
	}

	public function trigger_download() {
		if ( ! isset( $_GET['wpmdb-download-backup'] ) ) {
			return false;
		}

		$backup = filter_input( INPUT_GET, 'wpmdb-download-backup', FILTER_SANITIZE_STRING );
		if ( empty( $backup ) ) {
			wp_die( __( 'Backup not found.', 'wp-migrate-db' ) );
		}

		$this->download_backup( $backup );
	}

	public function register() {
		add_action( 'wp_ajax_wpmdb_get_backups', [ $this, 'ajax_get_backups' ] );
		add_action( 'wp_ajax_wpmdb_get_backup', [ $this, 'ajax_get_backup' ] );
		add_action( 'wp_ajax_wpmdb_delete_backup', [ $this, 'ajax_delete_backup' ] );
		add_action( 'admin_init', [ $this, 'trigger_download' ] );

		add_filter( 'wpmdb_nonces', [ $this, 'add_nonces' ] );
		add_filter( 'wpmdb_js_strings', [ $this, 'add_js_strings' ] );
	}

	public function add_js_strings( $strings ) {
		$backup_strings = [
			'error_getting_backups'    => __( 'Error loading backups', 'wp-migrate-db' ),
			'loading_backups'          => __( 'Loading backups...', 'wp-migrate-db' ),
			'error_downloading_backup' => __( 'Error downloading backup', 'wp-migrate-db' ),
			'error_deleting_backup'    => __( 'Error deleting backup', 'wp-migrate-db' ),
			'confirm_delete_backup'    => __( 'Are you sure you want to delete this backup?', 'wp-migrate-db' ),
			'download'                 => __( 'Download', 'wp-migrate-db' ),
		];

		return $strings + $backup_strings;
	}

	public function add_nonces( $nonces ) {
		$backups_nonces = [
			'get_backups'   => Util::create_nonce( 'get-backups' ),
			'get_backup'    => Util::create_nonce( 'get-backup' ),
			'delete_backup' => Util::create_nonce( 'delete-backup' ),
		];

		return $nonces + $backups_nonces;
	}

	public function download_backup( $backup ) {
		$backup_dir = $this->filesystem->get_upload_info( 'path' ) . DIRECTORY_SEPARATOR;
		$ext        = '.sql';
		$diskfile   = $backup_dir . $backup;

		$diskfile .= $ext;

		if ( ! file_exists( $diskfile ) ) {
			wp_die( __( 'Could not find backup file to download:', 'wp-migrate-db' ) . '<br>' . esc_html( $diskfile ) );
		}

		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . $this->filesystem->filesize( $diskfile ) );
		header( 'Content-Disposition: attachment; filename=' . $backup . $ext );
		readfile( $diskfile );
		exit;
	}

	public function ajax_get_backups() {
		$this->http->check_ajax_referer( 'get-backups' );
		$backups = $this->filesystem->get_backups();

		if ( false === $backups ) {
			$this->http->end_ajax( json_encode( [
				'status' => __( 'No backups found.' ),
			] ) );
		}

		$this->http->end_ajax( json_encode( $backups ) );
	}

	public function ajax_get_backup() {
		$this->http->check_ajax_referer( 'get-backup' );
		$path = filter_input( INPUT_POST, 'path', FILTER_SANITIZE_URL );

		$backup_dir = $this->filesystem->get_upload_info( 'path' ) . DIRECTORY_SEPARATOR;
		$file_path  = $backup_dir . $path . '.sql';

		if ( ! file_exists( $file_path ) ) {
			$this->trigger_error( __( 'File does not exist &mdash; ' ), $file_path );
		}

		$redirect_query = array(
			'page'            => 'wp-migrate-db-pro',
			'wpmdb-download-backup' => $path,
		);

		$path = is_multisite() ? 'settings.php' : 'tools.php';

		$redirect = add_query_arg( $redirect_query, network_admin_url( $path ) );

		$this->http->end_ajax( json_encode( [ 'status' => 'success', 'redirect' => $redirect ] ) );
	}

	public function ajax_delete_backup() {
		$this->http->check_ajax_referer( 'delete-backup' );

		$path       = filter_input( INPUT_POST, 'path', FILTER_SANITIZE_URL );
		$backup_dir = $this->filesystem->get_upload_info( 'path' ) . DIRECTORY_SEPARATOR;
		$file_path  = $backup_dir . $path . '.sql';

		if ( ! file_exists( $file_path ) ) {
			$this->trigger_error( __( "File does not exist &mdash; " ), $file_path );
		}

		$deleted = $this->filesystem->unlink( $file_path );

		if ( ! $deleted ) {
			$this->trigger_error( __( "Unable to delete file &mdash; " ), $file_path );
		}

		$this->http->end_ajax( json_encode( [
			'status' => 'deleted',
		] ) );
	}

	/**
	 * @param $message
	 * @param $file_path
	 */
	public function trigger_error( $message, $file_path ) {
		$this->http->end_ajax( json_encode( [
			'wpmdb_error' => 1,
			'error_msg'   => $message . $file_path,
		] ) );
	}
}
