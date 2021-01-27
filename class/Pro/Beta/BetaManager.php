<?php

namespace DeliciousBrains\WPMDB\Pro\Beta;

use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Settings\Settings;
use DeliciousBrains\WPMDB\Common\Util\Util;
use DeliciousBrains\WPMDB\Pro\Addon\Addon;
use DeliciousBrains\WPMDB\Pro\Api;
use DeliciousBrains\WPMDB\Pro\Download;
use DeliciousBrains\WPMDB\Pro\UI\Template;

/**
 * Class BetaManager
 *
 * Class to handle opting in and installing beta releases of the plugin and addons
 *
 */
class BetaManager {

	/**
	 * @var Properties
	 */
	public $props;
	/**
	 * @var
	 */
	public $settings;
	/**
	 * @var
	 */
	public $assets;
	/**
	 * @var Util
	 */
	public $util;
	/**
	 * @var
	 */
	public $tables;
	/**
	 * @var
	 */
	public $http;
	/**
	 * @var Addon
	 */
	public $addon;
	/**
	 * @var Api
	 */
	public $api;
	/**
	 * @var Template
	 */
	public $template;
	/**
	 * @var Download
	 */
	public $download;
	/**
	 * @var
	 */
	public static $static_settings;

	public function __construct(
		Util $util,
		Addon $addon,
		Api $api,
		Settings $settings,
		Template $template,
		Download $download,
		Properties $properties
	) {
		$this->util            = $util;
		$this->props           = $properties;
		$this->addon           = $addon;
		$this->api             = $api;
		$this->settings        = $settings->get_settings();
		$this->template        = $template;
		$this->download        = $download;

		// Hack to access settings in static method
		self::$static_settings = $this->settings;
	}

	/**
	 * Register action and filter hooks
	 */
	public function register() {
		add_action( 'wpmdb_additional_settings_advanced', array( $this, 'template_beta_optin' ) );
		add_filter( 'wpmdb_js_strings', array( $this, 'add_js_strings' ) );
		add_filter( 'wpmdb_data', array( $this, 'add_js_data' ) );
		add_action( 'admin_init', array( $this, 'handle_redirect_to_rollback_url' ) );
		add_filter( 'admin_init', array( $this, 'handle_maybe_set_rolling_back_flag' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'maybe_inject_stable_version_plugin_data' ), 11 );
		add_action( 'shutdown', array( $this, 'handle_maybe_clear_rolling_back_flag' ) );

		if ( self::is_beta_version( $this->props->plugin_version ) ) {
			add_action( 'wpmdb_notices', array( $this, 'template_beta_feedback_ask' ) );
		}

		add_action( 'wpmdb_before_schema_update', [ $this, 'schema_update' ] );
	}

	/**
	 * Add to the strings passed to the JS.
	 *
	 * @param array $strings
	 *
	 * @return array
	 */
	public function add_js_strings( $strings ) {
		$strings['rollback_beta_to_stable'] = __( 'Would you like to rollback WP Migrate DB Pro and its addons to the latest stable release now?', 'wp-migrate-db' );

		return $strings;
	}

	/**
	 * Add JS object data for plugin rollback.
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public function add_js_data( $data ) {
		$beta_plugins = $this->get_installed_beta_plugins();

		$data['is_beta_plugins_installed'] = ! empty( $beta_plugins );
		$data['rollback_to_stable_url']    = $this->get_redirect_to_rollback_url();

		return $data;
	}

	/**
	 * Get an array of beta plugins installed
	 *
	 * @return array Associative array of string basenames.
	 */
	protected function get_installed_beta_plugins() {
		$plugins      = get_plugins();
		$beta_plugins = array();
		foreach ( $plugins as $plugin => $data ) {
			if ( $plugin !== $this->get_plugin_basename() && ! in_array( $plugin, $this->get_addon_basenames() ) ) {
				continue;
			}

			if ( self::is_beta_version( $data['Version'] ) ) {
				$beta_plugins[] = $plugin;
			}
		}

		return $beta_plugins;
	}

	/**
	 * Is the rollback process in motion?
	 *
	 * @return bool
	 */
	public static function is_rolling_back_plugins() {
		if ( ! isset( $_GET['action'] ) || 'update-selected' !== $_GET['action'] ) {
			return false;
		}

		$rolling_back = get_user_meta( get_current_user_id(), 'wpmdb-rollback-beta-plugins', true );

		return (bool) $rolling_back;
	}

	/**
	 * Set a flag against the user so we know we are processing the rollback.
	 * We need this level of persistence as we can't send query args across the internal WP update URLs
	 * as the update mechanism uses an iframe and doesn't have friendly hooks.
	 */
	public function handle_maybe_set_rolling_back_flag() {
		if ( ! isset( $_GET['action'] ) || 'do-plugin-upgrade' !== $_GET['action'] ) {
			return;
		}

		if ( ! isset( $_GET['wpmdb-latest-version'] ) ) {
			return;
		}

		update_user_meta( get_current_user_id(), 'wpmdb-rollback-beta-plugins', true );
	}

	/**
	 * Clear the rolling back flag on shutdown for the WP core update request.
	 */
	public function handle_maybe_clear_rolling_back_flag() {
		if ( ! isset( $_GET['action'] ) || 'update-selected' !== $_GET['action'] ) {
			return;
		}

		delete_user_meta( get_current_user_id(), 'wpmdb-rollback-beta-plugins' );
	}

	/**
	 * Generate URL to kick off the process of rolling back plugins.
	 * This extra redirect is needed so the plugins in scope will always be current at the time of clicking the link.
	 *
	 * @return string
	 */
	protected function get_redirect_to_rollback_url() {
		$url = add_query_arg( array(
			'page'           => 'wp-migrate-db-pro',
			'wpmdb-rollback' => 1,
		), network_admin_url( 'tools.php' ) );

		return wp_nonce_url( $url, 'wpmdb-beta-rollback-redirect' );
	}

	/**
	 * Respond to the rollback redirection and pass off to the WordPress core plugin upgrade screen.
	 */
	public function handle_redirect_to_rollback_url() {
		if ( ! isset( $_GET['page'] ) || 'wp-migrate-db-pro' !== $_GET['page'] ) {
			return;
		}

		if ( ! isset( $_GET['wpmdb-rollback'] ) || 1 !== (int) $_GET['wpmdb-rollback'] ) {
			return;
		}

		check_admin_referer( 'wpmdb-beta-rollback-redirect' );

		$url = $this->get_bulk_plugins_upgrade_url();

		if ( ! $url ) {
			return;
		}

		wp_redirect( $url );
		exit;
	}

	/**
	 * Generate a URL to the WordPress updates page with the beta plugins needed to be rolled back.
	 *
	 * @return bool|string
	 */
	protected function get_bulk_plugins_upgrade_url() {
		// get all beta plugins
		$plugins = $this->get_installed_beta_plugins();

		if ( empty( $plugins ) ) {
			return false;
		}

		$url = add_query_arg( array(
			'action'               => 'do-plugin-upgrade',
			'plugins'              => htmlentities( implode( ',', $plugins ) ),
			'wpmdb-latest-version' => 1,
		), network_admin_url( 'update-core.php' ) );

		$url = add_query_arg( '_wpnonce', wp_create_nonce( 'upgrade-core' ), $url );

		return $url;
	}

	/**
	 * Return stable versions of installed beta plugins
	 * if we are doing a bulk update of plugins for a rollback
	 *
	 * @param object $trans
	 *
	 * @return object
	 */
	public function maybe_inject_stable_version_plugin_data( $trans ) {
		if ( ! isset( $_GET['action'] ) || 'update-selected' !== $_GET['action'] ) {
			return $trans;
		}

		if ( ! Util::has_method_been_called( 'bulk_upgrade' ) ) {
			return $trans;
		}

		$beta_plugins = $this->get_installed_beta_plugins();

		return $this->inject_stable_version_plugin_data( $trans, $beta_plugins );
	}

	/**
	 * Inject the stable versions of specific plugins to the 'update_plugins' transient
	 *
	 * @param object $trans
	 *
	 * @return object
	 */
	protected function inject_stable_version_plugin_data( $trans, $plugins ) {

		$plugin_upgrade_data = $this->addon->get_upgrade_data();

		if ( false === $plugin_upgrade_data || ! isset( $plugin_upgrade_data['wp-migrate-db-pro'] ) ) {
			return $trans;
		}

		foreach ( $plugin_upgrade_data as $slug => $upgrade_data ) {
			$plugin_folder   = $this->util->get_plugin_folder( $slug );
			$plugin_basename = sprintf( '%s/%s.php', $plugin_folder, $slug );

			if ( ! in_array( $plugin_basename, $plugins ) ) {
				// We don't need to rollback this plugin
				continue;
			}

			if ( ! isset( $plugin_upgrade_data[ $slug ]['version'] ) || empty( $plugin_upgrade_data[ $slug ]['version'] ) ) {
				// Plugin doesn't have a stable version to roll back to
				continue;
			}

			$trans->response[ $plugin_basename ]              = new \stdClass();
			$trans->response[ $plugin_basename ]->url         = $this->api->get_dbrains_api_base();
			$trans->response[ $plugin_basename ]->slug        = $slug;
			$trans->response[ $plugin_basename ]->package     = $this->download->get_plugin_update_download_url( $slug );
			$trans->response[ $plugin_basename ]->new_version = $plugin_upgrade_data[ $slug ]['version'];
			$trans->response[ $plugin_basename ]->id          = '0';
			$trans->response[ $plugin_basename ]->plugin      = $plugin_basename;
		}

		return $trans;
	}

	/**
	 * Register the beta optin setting on the settings tab
	 */
	public function template_beta_optin() {
		$this->template->template( 'beta-optin', 'pro' );
	}

	/**
	 * Register the beta feedback notice
	 */
	public function template_beta_feedback_ask() {
		global $current_user;

		// allow rerun for each new beta version
		$version_slug = str_replace( '.', '', $this->props->plugin_version );

		$welcome_notice_name  = 'beta_welcome' . $version_slug;
		$reminder_notice_name = 'beta_feedback_reminder_' . $version_slug;

		// welcome notice
		if ( $welcome_links = $this->template->notice->check_notice( $welcome_notice_name, 'SHOW_ONCE' ) ) {
			$this->template->template( 'beta-welcome', 'pro', $welcome_links );

			// Add 5-day sleep for reminder notification
			$reminder_key = 'wpmdb_reminder_' . $reminder_notice_name;
			if ( ! get_user_meta( $current_user->ID, $reminder_key ) && ! get_user_meta( $current_user->ID, 'wpmdb_dismiss_' . $reminder_notice_name ) ) {
				update_user_meta( $current_user->ID, $reminder_key, time() + ( DAY_IN_SECONDS * 5 ) );
			}

			return;
		}

		// reminder notice
		if ( $reminder_links = $this->template->notice->check_notice( $reminder_notice_name, true, ( DAY_IN_SECONDS * 5 ) ) ) {
			$this->template->template( 'beta-feedback-reminder', 'pro', $reminder_links );
		}
	}

	/**
	 * Is the version a beta version?
	 *
	 * @param string $ver
	 *
	 * @return bool
	 */
	public static function is_beta_version( $ver ) {
		if ( preg_match( '@b[0-9]+$@', $ver ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Has tbe beta optin been turned on?
	 *
	 * @return bool
	 */
	public static function has_beta_optin( $settings ) {
		if ( ! isset( $settings['beta_optin'] ) ) {
			return false;
		}

		return (bool) $settings['beta_optin'];
	}

	/**
	 * Sets the value of the beta optin setting
	 *
	 * @param bool $value
	 */
	public static function set_beta_optin( $value = true ) {
		self::$static_settings['beta_optin'] = $value;
		update_site_option( 'wpmdb_settings', self::$static_settings );
	}

	public function schema_update( $schema_version ) {
		if ( $schema_version >= 2 ) {
			return;
		}

		if ( self::is_beta_version( $this->props->plugin_version ) ) {
			// If the current installed version is a beta version then turn on the beta optin
			self::set_beta_optin();
			// Dismiss the notice also, so it won't keep coming back
			update_user_meta( get_current_user_id(), 'wpmdb_dismiss_beta_optin', true );
		}
	}

	/**
	 * Get an array of the addon basenames
	 *
	 * @return array
	 */
	public function get_addon_basenames() {
		return array_keys( $this->addon->getAddons() );
	}

	/**
	 * Get basename of the plugin
	 */
	public function get_plugin_basename() {
		return $this->props->plugin_basename;
	}
}
