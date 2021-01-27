<?php

namespace DeliciousBrains\WPMDB\Pro\UI;

use DeliciousBrains\WPMDB\Common\Filesystem\Filesystem;
use DeliciousBrains\WPMDB\Common\FormData\FormData;
use DeliciousBrains\WPMDB\Common\Profile\ProfileManager;
use DeliciousBrains\WPMDB\Common\Properties\DynamicProperties;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Common\Settings\Settings;
use DeliciousBrains\WPMDB\Common\Sql\Table;
use DeliciousBrains\WPMDB\Common\UI\Notice;
use DeliciousBrains\WPMDB\Common\Util\Util;
use DeliciousBrains\WPMDB\Pro\Addon\Addon;
use DeliciousBrains\WPMDB\Pro\License;
use DeliciousBrains\WPMDB\Pro\Plugin\ProPluginManager;

class Template extends \DeliciousBrains\WPMDB\Common\UI\TemplateBase {

	/**
	 * @var Notice
	 */
	public $notice;
	/**
	 * @var FormData
	 */
	public $form_data;
	/**
	 * @var DynamicProperties
	 */
	public $dynamic_props;
	/**
	 * @var
	 */
	public $addons;
	/**
	 * @var License
	 */
	public $license;
	/**
	 * @var Addon
	 */
	public $addon;
	/**
	 * @var ProPluginManager
	 */
	public $plugin_manager;

	public function __construct(
		Settings $settings,
		Util $util,
		ProfileManager $profile,
		Filesystem $filesystem,
		Table $table,
		Notice $notice,
		FormData $form_data,
		Addon $addon,
		License $license,
		Properties $properties,
		ProPluginManager $plugin_manager
	) {
		parent::__construct( $settings, $util, $profile, $filesystem, $table, $properties );
		$this->notice    = $notice;
		$this->form_data = $form_data;
		$this->license   = $license;

		$this->dynamic_props  = DynamicProperties::getInstance();
		$this->addon          = $addon;
		$this->plugin_manager = $plugin_manager;

		// Insert backups tab into plugin_tabs array
		array_splice( $this->plugin_tabs, 1, 0, [
			[
				'slug'  => 'backups',
				'title' => _x( 'Backups', 'Get backups', 'wp-migrate-db' ),
			],
		] );
	}

	public function register() {
		// templating actions
		add_action( 'wpmdb_notices', [ $this, 'template_outdated_addons_warning' ] );
		add_action( 'wpmdb_notices', [ $this, 'template_secret_key_warning' ] );
		add_action( 'wpmdb_notices', [ $this, 'template_block_external_warning' ] );

		$accepted_fields = $this->form_data->get_accepted_fields();
		$accepted_fields = array_diff( $accepted_fields, [ 'exclude_post_revisions' ] );
		$this->form_data->set_accepted_fields( $accepted_fields );

		remove_action( 'wpmdb_advanced_options', [ $this, 'template_exclude_post_revisions' ] );

		add_action( 'admin_init', [ $this, 'toggle_locked_row' ] );
	}

	public function toggle_locked_row() {
		if ( isset( $_GET['wpmdb-profile'] )
		     && ( isset( $_GET['page'] ) && in_array( $_GET['page'], [ 'wp-migrate-db-pro', 'wp-migrate-db' ] ) ) ) {
			$loaded_profile = $this->profile->get_profile( (int) $_GET['wpmdb-profile'] );
		} else {
			$loaded_profile = $this->profile->default_profile;
		}

		if ( isset( $loaded_profile['mst_select_subsite'] ) ) {
			add_filter( 'wpmdb_lock_find_replace_row', function () {
				return true;
			} );
		}
	}

	function template_import_radio_button( $loaded_profile ) {
		$args = array(
			'loaded_profile' => $loaded_profile,
		);
		$this->template( 'import-radio-button', 'pro', $args );
	}

	function template_pull_push_radio_buttons( $loaded_profile ) {
		$args = array(
			'loaded_profile' => $loaded_profile,
		);
		$this->template( 'pull-push-radio-buttons', 'pro', $args );
	}

	function template_select_tables( $loaded_profile ) {
		$args = array(
			'loaded_profile' => $loaded_profile,
		);
		$this->template( 'select-tables', 'pro', $args );
	}

	function template_import_file_status() {
		$this->template( 'import-file-status', 'pro' );
	}

	function template_unrecognized_import_file() {
		$this->template( 'unrecognized-import-file', 'pro' );
	}

	function template_mst_required() {
		$this->template( 'mst-required', 'pro' );
	}

	function template_import_find_replace_option( $loaded_profile ) {
		$args = array(
			'loaded_profile' => $loaded_profile,
		);
		$this->template( 'import-find-replace-option', 'pro', $args );
	}

	function template_find_replace_options( $loaded_profile ) {
		$this->template( 'find-replace-options', 'pro' );
	}

	function template_import_active_plugins_option( $loaded_profile ) {
		$args = array(
			'loaded_profile' => $loaded_profile,
		);
		$this->template( 'import-active-plugins-option', 'pro', $args );
	}

	function template_exclude_post_types( $loaded_profile ) {
		$args = array(
			'loaded_profile' => $loaded_profile,
		);
		$this->template( 'exclude-post-types', 'pro', $args );
	}

	function template_toggle_remote_requests() {
		$this->template( 'toggle-remote-requests', 'pro' );
	}

	function template_request_settings() {
		$this->template( 'request-settings', 'pro' );
	}

	function template_connection_info() {
		$args = array(
			'connection_info' => sprintf( "%s\r%s", site_url( '', 'https' ), $this->settings['key'] ),
		);
		$this->template( 'connection-info', 'pro', $args );
	}

	function template_delay_between_requests() {
		$this->template( 'delay-between-requests', 'pro' );
	}

	function template_licence() {
		$args = array(
			'licence' => $this->license->get_licence_key(),
		);
		$this->template( 'licence', 'pro', $args );
	}

	function template_addon_tab() {
		$this->template( 'addon-tab', 'pro' );
	}

	function template_licence_info() {
		$args = array(
			'licence' => $this->license->get_licence_key(),
		);
		$this->template( 'licence-info', 'pro', $args );
	}

	/**
	 * Shows all the videos on the Help tab.
	 *
	 * @return void
	 */
	function template_videos() {
		$args = array(
			'videos' => array(
				'u7jFkwwfeJc' => array(
					'title' => __( 'UI Walkthrough', 'wp-migrate-db' ),
					'desc'  => __( 'A brief walkthrough of the WP Migrate DB plugin showing all of the different options and explaining them.', 'wp-migrate-db' ),
				),
				'8u_kX5d78Bs' => array(
					'title' => __( 'Pulling Live Data Into Your Local Development&nbsp;Environment', 'wp-migrate-db' ),
					'desc'  => __( 'This screencast demonstrates how you can pull data from a remote, live WordPress install and update the data in your local development environment.', 'wp-migrate-db' ),
				),
				'sImZW_sB47g' => array(
					'title' => __( 'Pushing Local Development Data to a Staging&nbsp;Environment', 'wp-migrate-db' ),
					'desc'  => __( 'This screencast demonstrates how you can push a local WordPress database you\'ve been using for development to a staging environment.', 'wp-migrate-db' ),
				),
				'jjqc5dBX9DY' => array(
					'title' => __( 'WP Migrate DB Pro Media Files Addon 1.3 and CLI Addon 1.1', 'wp-migrate-db' ),
					'desc'  => __( 'A demonstration of what\'s new in WP Migrate DB Pro Media Files Addon 1.3 and CLI Addon 1.1.', 'wp-migrate-db' ),
				),
			),
		);
		$this->template( 'videos', 'pro', $args );
	}

	function template_outdated_addons_warning() {
		if ( ! $this->notice->check_notice( 'outdated_addons_warning' ) ) {
			return;
		};
		$this->template( 'outdated-addons-warning', 'pro' );
	}

	function template_secret_key_warning() {
		if ( ! ( $notice_links = $this->notice->check_notice( 'secret_key_warning', true, 604800 ) ) ) {
			return;
		};
		// Only show the warning if the key is 32 characters in length
		if ( strlen( $this->settings['key'] ) > 32 ) {
			return;
		}

		$this->template( 'secret-key-warning', 'pro', $notice_links );
	}

	function template_block_external_warning() {
		if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL ) {
			return;
		}
		if ( ! ( $notice_links = $this->notice->check_notice( 'block_external_warning', true, 604800 ) ) ) {
			return;
		}

		$this->template( 'block-external-warning', 'pro', $notice_links );
	}

	function template_invalid_licence_warning() {
		if ( ! $this->license->is_valid_licence() ) {
			$this->template( 'invalid-licence-warning', 'pro' );
		}
	}

	function template_backup( $loaded_profile ) {
		$args = array(
			'loaded_profile' => $loaded_profile,
		);
		$this->template( 'backup', 'pro', $args );
	}
}
