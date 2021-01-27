<?php

namespace DeliciousBrains\WPMDB\Pro\Plugin;

use DeliciousBrains\WPMDB\Common\Plugin\Assets;
use DeliciousBrains\WPMDB\Common\Plugin\PluginManagerBase;
use DeliciousBrains\WPMDB\Common\Properties\Properties;
use DeliciousBrains\WPMDB\Container;
use DeliciousBrains\WPMDB\Common\Filesystem\Filesystem;
use DeliciousBrains\WPMDB\Common\Http\Http;
use DeliciousBrains\WPMDB\Common\Multisite\Multisite;
use DeliciousBrains\WPMDB\Common\Settings\Settings;
use DeliciousBrains\WPMDB\Common\Sql\Table;
use DeliciousBrains\WPMDB\Common\Util\Util;
use DeliciousBrains\WPMDB\Pro\Addon\Addon;
use DeliciousBrains\WPMDB\Pro\Api;
use DeliciousBrains\WPMDB\Pro\Beta\BetaManager;
use DeliciousBrains\WPMDB\Pro\Download;
use DeliciousBrains\WPMDB\Pro\License;

class ProPluginManager extends PluginManagerBase {

	public function __construct(
		Settings $settings,
		Assets $assets,
		Util $util,
		Table $table,
		Http $http,
		Filesystem $filesystem,
		Multisite $multisite,
		License $license,
		Api $api,
		Addon $addon,
		Download $download,
		Properties $properties
	) {
		parent::__construct( $settings,
			$assets,
			$util,
			$table,
			$http,
			$filesystem,
			$multisite,
			$properties
		);

		$this->license  = $license;
		$this->api      = $api;
		$this->addon    = $addon;
		$this->download = $download;
	}

	public function register() {
		parent::register();

		add_filter( 'wpmdb_data', function ( $data ) {
			$data['valid_licence'] = $this->license->is_valid_licence() ? '1' : '0';
			$data['has_licence']   = esc_html( $this->license->get_licence_key() == '' ? '0' : '1' );

			return $data;
		} );

		// Remove licence from the database if constant is set
		if ( defined( 'WPMDB_LICENCE' ) && ! empty( $this->settings['licence'] ) ) {
			$this->settings['licence'] = '';
			update_site_option( 'wpmdb_settings', $this->settings );
		}

		// Add after_plugin_row... action for pro plugin and all addons
		add_action( 'after_plugin_row_wp-migrate-db-pro/wp-migrate-db-pro.php', array( $this, 'plugin_row' ), 11, 2 );
		add_action( 'after_plugin_row_wp-migrate-db-pro-cli/wp-migrate-db-pro-cli.php', array( $this, 'plugin_row' ), 11, 2 );
		add_action( 'after_plugin_row_wp-migrate-db-pro-media-files/wp-migrate-db-pro-media-files.php', array( $this, 'plugin_row' ), 11, 2 );
		add_action( 'after_plugin_row_wp-migrate-db-pro-multisite-tools/wp-migrate-db-pro-multisite-tools.php', array( $this, 'plugin_row' ), 11, 2 );

		// Seen when the user clicks "view details" on the plugin listing page
		add_action( 'install_plugins_pre_plugin-information', array( $this, 'plugin_update_popup' ) );

		add_filter( 'plugin_action_links_' . $this->props->plugin_basename, array( $this, 'plugin_action_links' ) );
		add_filter( 'network_admin_plugin_action_links_' . $this->props->plugin_basename, array( $this, 'plugin_action_links' ) );

		// Short circuit the HTTP request to WordPress.org for plugin information
		add_filter( 'plugins_api', array( $this, 'short_circuit_wordpress_org_plugin_info_request' ), 10, 3 );

		// Take over the update check
		add_filter( 'site_transient_update_plugins', array( $this, 'site_transient_update_plugins' ) );

		//Add some custom JS into the WP admin pages
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_plugin_update_script' ) );

		// Add some custom CSS into the WP admin pages
		add_action( 'admin_head-plugins.php', array( $this, 'add_plugin_update_styles' ) );

		// Hook into the plugin install process, inject addon download url
		add_filter( 'plugins_api', array( $this, 'inject_addon_install_resource' ), 10, 3 );

		// Clear update transients when the user clicks the "Check Again" button from the update screen
		add_action( 'current_screen', array( $this, 'check_again_clear_transients' ) );
	}

	/**
	 * Shows a message below the plugin on the plugins page when:
	 * 1. the license hasn't been activated
	 * 2. when there's an update available but the license is expired
	 *
	 * @param   string $plugin_path Path of current plugin listing relative to plugins directory
	 *
	 * @return  void
	 */
	function plugin_row( $plugin_path, $plugin_data ) {
		$plugin_title       = $plugin_data['Name'];
		$plugin_slug        = sanitize_title( $plugin_title );
		$licence            = $this->license->get_licence_key();
		$licence_response   = $this->license->is_licence_expired();
		$licence_problem    = isset( $licence_response['errors'] );
		$active             = is_plugin_active( $plugin_path ) ? 'active' : '';
		$shiny_updates      = version_compare( get_bloginfo( 'version' ), '4.6-beta1-37926', '>=' );
		$update_msg_classes = $shiny_updates ? 'notice inline notice-warning notice-alt post-shiny-updates' : 'pre-shiny-updates';

		if ( ! isset( $GLOBALS['wpmdb_meta'][ $plugin_slug ]['version'] ) ) {
			$installed_version = '0';
		} else {
			$installed_version = $GLOBALS['wpmdb_meta'][ $plugin_slug ]['version'];
		}

		$latest_version = $this->addon->get_latest_version( $plugin_slug );

		$new_version = '';
		if ( version_compare( $installed_version, $latest_version, '<' ) ) {
			$new_version = sprintf( __( 'There is a new version of %s available.', 'wp-migrate-db' ), $plugin_title );
			$new_version .= ' <a class="thickbox" title="' . $plugin_title . '" href="plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( $plugin_slug ) . '&TB_iframe=true&width=640&height=808">';
			$new_version .= sprintf( __( 'View version %s details', 'wp-migrate-db' ), $latest_version ) . '</a>.';
		}

		if ( ! $new_version && ! empty( $licence ) ) {
			return;
		}

		if ( empty( $licence ) ) {
			$settings_link = sprintf( '<a href="%s">%s</a>', network_admin_url( $this->props->plugin_base ) . '#settings', _x( 'Settings', 'Plugin configuration and preferences', 'wp-migrate-db' ) );
			if ( $new_version ) {
				$message = sprintf( __( 'To update, go to %1$s and enter your license key. If you don\'t have a license key, you may <a href="%2$s">purchase one</a>.', 'wp-migrate-db' ), $settings_link, 'http://deliciousbrains.com/wp-migrate-db-pro/pricing/' );
			} else {
				$message = sprintf( __( 'To finish activating %1$s, please go to %2$s and enter your license key. If you don\'t have a license key, you may <a href="%3$s">purchase one</a>.', 'wp-migrate-db' ), $this->props->plugin_title, $settings_link, 'http://deliciousbrains.com/wp-migrate-db-pro/pricing/' );
			}
		} elseif ( $licence_problem ) {
			$message = array_shift( $licence_response['errors'] ) . sprintf( ' <a href="#" class="check-my-licence-again">%s</a>', __( 'Check my license again', 'wp-migrate-db' ) );
		} else {
			return;
		} ?>

		<tr class="plugin-update-tr <?php echo $active; ?> wpmdbpro-custom">
			<td colspan="3" class="plugin-update">
				<div class="update-message <?php echo $update_msg_classes; ?>">
					<p>
						<span class="wpmdb-new-version-notice"><?php echo $new_version; ?></span>
						<span class="wpmdb-licence-error-notice"><?php echo $this->license->get_licence_status_message( null, 'update' ); ?></span>
					</p>
				</div>
			</td>
		</tr>

		<?php if ( $new_version ) { // removes the built-in plugin update message ?>
			<script type="text/javascript">
				(function( $ ) {
					var wpmdb_row = jQuery( '[data-slug=<?php echo $plugin_slug; ?>]:first' );

					// Fallback for earlier versions of WordPress.
					if ( !wpmdb_row.length ) {
						wpmdb_row = jQuery( '#<?php echo $plugin_slug; ?>' );
					}

					var next_row = wpmdb_row.next();

					// If there's a plugin update row - need to keep the original update row available so we can switch it out
					// if the user has a successful response from the 'check my license again' link
					if ( next_row.hasClass( 'plugin-update-tr' ) && !next_row.hasClass( 'wpmdbpro-custom' ) ) {
						var original = next_row.clone();
						original.add;
						next_row.html( next_row.next().html() ).addClass( 'wpmdbpro-custom-visible' );
						next_row.next().remove();
						next_row.after( original );
						original.addClass( 'wpmdb-original-update-row' );
					}
				})( jQuery );
			</script>
			<?php
		}
	}

	/**
	 * Override the standard plugin information popup for each pro addon
	 *
	 * @return  void
	 */
	function plugin_update_popup() {
		if ( 'wp-migrate-db-pro' == $_GET['plugin'] ) {
			$plugin_slug = 'wp-migrate-db-pro';
		} elseif ( 'wp-migrate-db-pro-cli' === $_GET['plugin'] ) {
			$plugin_slug = 'wp-migrate-db-pro-cli';
		} elseif ( 'wp-migrate-db-pro-media-files' === $_GET['plugin'] ) {
			$plugin_slug = 'wp-migrate-db-pro-media-files';
		} elseif ( 'wp-migrate-db-pro-multisite-tools' === $_GET['plugin'] ) {
			$plugin_slug = 'wp-migrate-db-pro-multisite-tools';
		} else {
			return;
		}

		$error_msg      = sprintf( '<p>%s</p>', __( 'Could not retrieve version details. Please try again.', 'wp-migrate-db' ) );
		$latest_version = $this->addon->get_latest_version( $plugin_slug );

		if ( false === $latest_version ) {
			echo $error_msg;
			exit;
		}

		$data = $this->get_changelog( $plugin_slug, BetaManager::is_beta_version( $latest_version ) );

		if ( is_wp_error( $data ) || empty( $data ) ) {
			echo '<p>' . __( 'Could not retrieve version details. Please try again.', 'wp-migrate-db' ) . '</p>';
		} else {
			echo $data;
		}

		exit;
	}

	//@TODO Move to Pro/PluginManager class
	function inject_addon_install_resource( $res, $action, $args ) {
		if ( 'plugin_information' != $action || empty( $args->slug ) ) {
			return $res;
		}

		$addons = get_site_transient( 'wpmdb_addons' );

		if ( ! isset( $addons[ $args->slug ] ) ) {
			return $res;
		}

		$addon   = $addons[ $args->slug ];
		$is_beta = ! empty( $addon['beta_version'] ) && BetaManager::has_beta_optin( $this->settings );

		$res                = new \stdClass();
		$res->name          = 'WP Migrate DB Pro ' . $addon['name'];
		$res->version       = $is_beta ? $addon['beta_version'] : $addon['version'];
		$res->download_link = $this->download->get_plugin_update_download_url( $args->slug, $is_beta );
		$res->tested        = isset( $addon['tested'] ) ? $addon['tested'] : false;

		return $res;
	}


	function site_transient_update_plugins( $trans ) {
		if ( !$trans ) {
			return $trans;
		}

		$plugin_upgrade_data = $this->addon->get_upgrade_data();

		if ( false === $plugin_upgrade_data || ! isset( $plugin_upgrade_data['wp-migrate-db-pro'] ) ) {
			return $trans;
		}

		foreach ( $plugin_upgrade_data as $plugin_slug => $upgrade_data ) {
			$plugin_folder = $this->util->get_plugin_folder( $plugin_slug );

			$plugin_basename = sprintf( '%s/%s.php', $plugin_folder, $plugin_slug );
			$latest_version  = $this->addon->get_latest_version( $plugin_slug );

			if ( ! isset( $GLOBALS['wpmdb_meta'][ $plugin_slug ]['version'] ) ) {
				$version_file = sprintf( '%s%s/version.php', $this->plugins_dir(), $plugin_folder );

				if ( file_exists( $version_file ) ) {
					include_once( $version_file );
					$installed_version = $GLOBALS['wpmdb_meta'][ $plugin_slug ]['version'];
				} else {
					$addon_file = sprintf( '%s%s/%s.php', $this->plugins_dir(), $plugin_folder, $plugin_slug );
					// No addon plugin file or version.php file, bail and move on to the next addon
					if ( ! file_exists( $addon_file ) ) {
						continue;
					}
					/*
					 * The addon's plugin file exists but a version.php file doesn't
					 * We're now assuming that the addon is outdated and provide an arbitrary out-of-date version number
					 * This will trigger a update notice
					 */
					$installed_version = $GLOBALS['wpmdb_meta'][ $plugin_slug ]['version'] = '0.1';
				}
			} else {
				$installed_version = $GLOBALS['wpmdb_meta'][ $plugin_slug ]['version'];
			}

			if ( isset( $installed_version ) && version_compare( $installed_version, $latest_version, '<' ) ) {

				$is_beta = BetaManager::is_beta_version( $latest_version );

				$trans->response[ $plugin_basename ]              = new \stdClass();
				$trans->response[ $plugin_basename ]->url         = $this->api->get_dbrains_api_base();
				$trans->response[ $plugin_basename ]->slug        = $plugin_slug;
				$trans->response[ $plugin_basename ]->package     = $this->download->get_plugin_update_download_url( $plugin_slug, $is_beta );
				$trans->response[ $plugin_basename ]->new_version = $latest_version;
				$trans->response[ $plugin_basename ]->id          = '0';
				$trans->response[ $plugin_basename ]->plugin      = $plugin_basename;
			}
		}

		return $trans;
	}

	/**
	 * Short circuits the HTTP request to WordPress.org servers to retrieve plugin information.
	 * Will only fire on the update-core.php admin page.
	 *
	 * @param  object|bool $res    Plugin resource object or boolean false.
	 * @param  string      $action The API call being performed.
	 * @param  object      $args   Arguments for the API call being performed.
	 *
	 * @return object|bool Plugin resource object or boolean false.
	 */
	function short_circuit_wordpress_org_plugin_info_request( $res, $action, $args ) {
		if ( 'plugin_information' != $action || empty( $args->slug ) || 'wp-migrate-db-pro' != $args->slug ) {
			return $res;
		}

		$screen = get_current_screen();

		// Only fire on the update-core.php admin page
		if ( empty( $screen->id ) || ( 'update-core' !== $screen->id && 'update-core-network' !== $screen->id ) ) {
			return $res;
		}

		$res         = new \stdClass();
		$plugin_info = $this->addon->get_upgrade_data();

		if ( isset( $plugin_info['wp-migrate-db-pro']['tested'] ) ) {
			$res->tested = $plugin_info['wp-migrate-db-pro']['tested'];
		} else {
			$res->tested = false;
		}

		return $res;
	}

	/**
	 * Adds settings link to plugin page
	 *
	 * @param  array $links
	 *
	 * @return array $links
	 */
	function plugin_action_links( $links ) {
		$link = sprintf( '<a href="%s">%s</a>', network_admin_url( $this->props->plugin_base ) . '#settings', _x( 'Settings', 'Plugin configuration and preferences', 'wp-migrate-db' ) );
		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Get changelog contents for the given plugin slug.
	 *
	 * @param string $slug
	 * @param bool   $beta
	 *
	 * @return bool|string
	 */
	function get_changelog( $slug, $beta = false ) {
		if ( true === $beta ) {
			$slug .= '-beta';
		}

		$args = array(
			'slug' => $slug,
		);

		$response = $this->api->dbrains_api_request( 'changelog', $args );

		return $response;
	}

	function enqueue_plugin_update_script( $hook ) {
		if ( 'plugins.php' != $hook ) {
			return;
		}
		$ver_string = '-' . str_replace( '.', '', $this->props->plugin_version );

		$src = plugins_url( "asset/build/js/plugin-update{$ver_string}.js", $GLOBALS['wpmdb_meta']['wp-migrate-db-pro']['abspath'] . '/wp-migrate-db-pro' );
		wp_enqueue_script( 'wp-migrate-db-pro-plugin-update-script', $src, array( 'jquery' ), false, true );

		wp_localize_script( 'wp-migrate-db-pro-plugin-update-script', 'wpmdb_nonces', array( 'check_licence' => Util::create_nonce( 'check-licence' ), 'process_notice_link' => Util::create_nonce( 'process-notice-link' ), ) );
		wp_localize_script( 'wp-migrate-db-pro-plugin-update-script', 'wpmdb_update_strings', array( 'check_license_again' => __( 'Check my license again', 'wp-migrate-db' ), 'license_check_problem' => __( 'A problem occurred when trying to check the license, please try again.', 'wp-migrate-db' ), ) );
	}

	function add_plugin_update_styles() {
		$version     = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? time() : $this->props->plugin_version;
		$plugins_url = trailingslashit( plugins_url() ) . trailingslashit( $this->props->plugin_folder_name );
		$src         = $plugins_url . 'asset/build/css/plugin-update-styles.css';
		wp_enqueue_style( 'plugin-update-styles', $src, array(), $version );
	}


	/**
	 * Clear update transients when the user clicks the "Check Again" button from the update screen.
	 *
	 * @param object $current_screen
	 */
	function check_again_clear_transients( $current_screen ) {
		if ( ! isset( $current_screen->id ) || strpos( $current_screen->id, 'update-core' ) === false || ! isset( $_GET['force-check'] ) ) {
			return;
		}

		delete_site_transient( 'wpmdb_upgrade_data' );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'wpmdb_licence_response' );
		delete_site_transient( 'wpmdb_dbrains_api_down' );
	}

	public function get_plugin_title() {
		return __( 'Migrate DB Pro', 'wp-migrate-db' );
	}
}
