<?php

namespace DeliciousBrains\WPMDB\Pro\Addon;

use DeliciousBrains\WPMDB\Common\Error\ErrorLog;
use DeliciousBrains\WPMDB\Common\BackupExport;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Settings\Settings;
use DeliciousBrains\WPMDB\Pro\Api;
use DeliciousBrains\WPMDB\Pro\Beta\BetaManager;
use DeliciousBrains\WPMDB\Pro\Download;

/**
 * Class Addon
 *
 * Manages addon compatibility and versioning/downloading addons
 *
 * @package DeliciousBrains\WPMDB\Pro
 */
class Addon {
	/**
	 * @var Api
	 */
	private $api;
	/**
	 * @var BackupExport
	 */
	private $download;
	/**
	 * @var ErrorLog
	 */
	private $log;
	/**
	 * @var Settings
	 */
	private $settings;
	/**
	 * @var $addons
	 */
	public $addons;

	public function __construct(
		Api $api,
		Download $download,
		ErrorLog $log,
		Settings $settings,
		Properties $properties
	) {

		$this->api      = $api;
		$this->props    = $properties;
		$this->download = $download;
		$this->log      = $log;
		$this->settings = $settings;

		$this->setAddons();
	}

	function get_required_version( $slug ) {
		$plugin_file = sprintf( '%1$s/%1$s.php', $slug );

		if ( isset( $this->addons[ $plugin_file ]['required_version'] ) ) {
			return $this->addons[ $plugin_file ]['required_version'];
		} else {
			return 0;
		}
	}

	public function getAddons() {
		return $this->addons;
	}

	/**
	 * Set versions of Addons required for this version of WP Migrate DB Pro
	 */
	public function setAddons() {
		$this->addons = array(
			'wp-migrate-db-pro-media-files/wp-migrate-db-pro-media-files.php'               => array(
				'name'             => 'Media Files',
				'required_version' => '1.4.13',
			),
			'wp-migrate-db-pro-cli/wp-migrate-db-pro-cli.php'                               => array(
				'name'             => 'CLI',
				'required_version' => '1.3.5',
			),
			'wp-migrate-db-pro-multisite-tools/wp-migrate-db-pro-multisite-tools.php'       => array(
				'name'             => 'Multisite Tools',
				'required_version' => '1.2.5',
			),
			'wp-migrate-db-pro-theme-plugin-files/wp-migrate-db-pro-theme-plugin-files.php' => array(
				'name'             => 'Theme & Plugin Files',
				'required_version' => '1.0.5',
			),
		);
	}

	public function register() {
		$this->setAddons();
		$this->api->dbrains_api_url = $this->api->get_dbrains_api_base() . '/?wc-api=delicious-brains';

		// allow developers to change the temporary prefix applied to the tables
		$this->props->temp_prefix = apply_filters( 'wpmdb_temporary_prefix', $this->props->temp_prefix );

		// Adds a custom error message to the plugin install page if required (licence expired / invalid)
		add_filter( 'http_response', array( $this->download, 'verify_download' ), 10, 3 );
		add_action( 'wpmdb_notices', array( $this, 'version_update_notice' ) );
	}

	function version_update_notice() {
		// We don't want to show both the "Update Required" and "Update Available" messages at the same time
		if ( isset( $this->addons[ $this->props->plugin_basename ] ) && true == $this->is_addon_outdated( $this->props->plugin_basename ) ) {
			return;
		}

		// To reduce UI clutter we hide addon update notices if the core plugin has updates available
		if ( isset( $this->addons[ $this->props->plugin_basename ] ) ) {
			$core_installed_version = $GLOBALS['wpmdb_meta'][ $this->props->core_slug ]['version'];
			$core_latest_version    = $this->get_latest_version( $this->props->core_slug );
			// Core update is available, don't show update notices for addons until core is updated
			if ( version_compare( $core_installed_version, $core_latest_version, '<' ) ) {
				return;
			}
		}

		$update_url = wp_nonce_url( network_admin_url( 'update.php?action=upgrade-plugin&plugin=' . urlencode( $this->props->plugin_basename ) ), 'upgrade-plugin_' . $this->props->plugin_basename );

		// If pre-1.1.2 version of Media Files addon, don't bother getting the versions
		if ( ! isset( $GLOBALS['wpmdb_meta'][ $this->props->plugin_slug ]['version'] ) ) {
			?>
			<div style="display: block;" class="updated warning inline-message">
				<strong><?php _ex( 'Update Available', 'A new version of the plugin is available', 'wp-migrate-db' ); ?></strong> &mdash;
				<?php printf( __( 'A new version of %1$s is now available. %2$s', 'wp-migrate-db' ), $this->props->plugin_title, sprintf( '<a href="%s">%s</a>', $update_url, _x( 'Update Now', 'Download and install a new version of the plugin', 'wp-migrate-db' ) ) ); ?>
			</div>
			<?php
		} else {
			$installed_version = $GLOBALS['wpmdb_meta'][ $this->props->plugin_slug ]['version'];
			$latest_version    = $this->get_latest_version( $this->props->plugin_slug );

			if ( version_compare( $installed_version, $latest_version, '<' ) ) { ?>
				<div style="display: block;" class="updated warning inline-message">
					<?php if ( BetaManager::is_beta_version( $latest_version ) ) : ?>
						<strong><?php _ex( 'Beta Update Available', 'A new version of the plugin is available', 'wp-migrate-db' ); ?></strong> &mdash;
					<?php else: ?>
						<strong><?php _ex( 'Update Available', 'A new version of the plugin is available', 'wp-migrate-db' ); ?></strong> &mdash;
					<?php endif; ?>
					<?php printf( __( '%1$s %2$s is now available. You currently have %3$s installed. <a href="%4$s">%5$s</a>', 'wp-migrate-db' ), $this->props->plugin_title, $latest_version, $installed_version, $update_url, _x( 'Update Now', 'Download and install a new version of the plugin', 'wp-migrate-db' ) ); ?>
				</div>
				<?php
			}
		}
	}

	public function is_addon_outdated( $addon_basename ) {
		$addon_slug = current( explode( '/', $addon_basename ) );

		// If pre-1.1.2 version of Media Files addon, then it is outdated
		if ( ! isset( $GLOBALS['wpmdb_meta'][ $addon_slug ]['version'] ) ) {
			return true;
		}

		$installed_version = $GLOBALS['wpmdb_meta'][ $addon_slug ]['version'];
		$required_version  = $this->addons[ $addon_basename ]['required_version'];

		return version_compare( $installed_version, $required_version, '<' );
	}

	public function get_plugin_name( $plugin = false ) {
		if ( ! is_admin() ) {
			return false;
		}

		$plugin_basename = ( false !== $plugin ? $plugin : $this->props->plugin_basename );

		$plugins = get_plugins();

		if ( ! isset( $plugins[ $plugin_basename ]['Name'] ) ) {
			return false;
		}

		return $plugins[ $plugin_basename ]['Name'];
	}

	public function get_latest_version( $slug ) {
		$data = $this->get_upgrade_data();

		if ( ! isset( $data[ $slug ] ) ) {
			return false;
		}

		$latest_version = empty ( $data[ $slug ]['version'] ) ? false : $data[ $slug ]['version'];

		if ( ! isset( $data[ $slug ]['beta_version'] ) ) {
			// No beta version available
			return $latest_version;
		}

		if ( version_compare( $data[ $slug ]['version'], $data[ $slug ]['beta_version'], '>' ) ) {
			// Stable version greater than the beta
			return $latest_version;
		}

		if ( BetaManager::is_rolling_back_plugins() ) {
			// We are in the process of rolling back to stable versions
			return $latest_version;
		}

		if ( ! BetaManager::has_beta_optin( $this->settings->get_settings() ) ) {
			// Not opted in to beta updates
			// The required version isn't a beta version
			return $latest_version;
		}

		return $data[ $slug ]['beta_version'];
	}

	public function get_upgrade_data() {
		$info = get_site_transient( 'wpmdb_upgrade_data' );

		if ( isset( $info['version'] ) ) {
			delete_site_transient( 'wpmdb_licence_response' );
			delete_site_transient( 'wpmdb_upgrade_data' );
			$info = false;
		}

		if ( $info ) {
			return $info;
		}

		$data = $this->api->dbrains_api_request( 'upgrade_data' );

		$data = json_decode( $data, true );

		/*
		We need to set the transient even when there's an error,
		otherwise we'll end up making API requests over and over again
		and slowing things down big time.
		*/
		$default_upgrade_data = array( 'wp-migrate-db-pro' => array( 'version' => $GLOBALS['wpmdb_meta'][ $this->props->core_slug ]['version'] ) );

		if ( ! $data ) {
			set_site_transient( 'wpmdb_upgrade_data', $default_upgrade_data, $this->props->transient_retry_timeout );
			$this->log->log_error( 'Error trying to decode JSON upgrade data.' );

			return false;
		}

		if ( isset( $data['errors'] ) ) {
			set_site_transient( 'wpmdb_upgrade_data', $default_upgrade_data, $this->props->transient_retry_timeout );
			$this->log->log_error( 'Error trying to get upgrade data.', $data['errors'] );

			return false;
		}

		set_site_transient( 'wpmdb_upgrade_data', $data, $this->props->transient_timeout );

		return $data;
	}

}
