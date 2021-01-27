<?php


/**
 * Populate the $wpmdbpro global with an instance of the WPMDBPro class and return it.
 *
 * @return WPMigrateDBPro|$wpmdbpro The one true global instance of the WPMDBPro class.
 */
function wp_migrate_db_pro() {
	// @TODO don't use globals to store instances of plugins
	global $wpmdbpro;

	if ( ! is_null( $wpmdbpro ) ) {
		return $wpmdbpro;
	}

	$wpmdbpro = new DeliciousBrains\WPMDB\Pro\WPMigrateDBPro( true );
	$wpmdbpro->register();

	return $wpmdbpro;
}

function wpmdb_pro_cli_loaded() {
	// register with wp-cli if it's running, and command hasn't already been defined elsewhere
	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\DeliciousBrains\WPMDB\Pro\Cli\Command' ) && ! class_exists( '\DeliciousBrains\WPMDBCli\Command' ) ) {
		\DeliciousBrains\WPMDB\Pro\Cli\Command::register();
	}
}

add_action( 'plugins_loaded', 'wpmdb_pro_cli_loaded', 20 );

function wpmdb_pro_cli() {
	global $wpmdbpro_cli;

	if ( ! is_null( $wpmdbpro_cli ) ) {
		return $wpmdbpro_cli;
	}

	do_action( 'wp_migrate_db_pro_cli_before_load' );

	$wpmdbpro_cli = \DeliciousBrains\WPMDB\Container::getInstance()->get( 'cli_export' );

	do_action( 'wp_migrate_db_pro_cli_after_load' );

	return $wpmdbpro_cli;
}

function wpmdbpro_is_ajax() {
	// must be doing AJAX the WordPress way
	if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
		return false;
	}

	// must be one of our actions -- e.g. core plugin (wpmdb_*), media files (wpmdbmf_*)
	if ( ! isset( $_POST['action'] ) || 0 !== strpos( $_POST['action'], 'wpmdb' ) ) {
		return false;
	}

	// must be on blog #1 (first site) if multisite
	if ( is_multisite() && 1 != get_current_site()->id ) {
		return false;
	}

	return true;
}

/**
 * once all plugins are loaded, load up the rest of this plugin
 *
 * @return boolean
 */
function wp_migrate_db_pro_loaded() {

	if ( ! function_exists( 'wp_migrate_db_pro' ) ) {
		return false;
	}

	// load if it is wp-cli, so that version update will show in wp-cli
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		wp_migrate_db_pro();

		return true;
	}

	// exit quickly unless: standalone admin; one of our AJAX calls
	if ( ! is_admin() || ( is_multisite() && ! current_user_can( 'manage_network_options' ) && ! wpmdbpro_is_ajax() ) ) {
		return false;
	}
	// Remove the compatibility plugin when the plugin is deactivated
	register_deactivation_hook( dirname( __FILE__) . '/wp-migrate-db-pro.php', 'wpmdb_pro_remove_mu_plugin' );
	wp_migrate_db_pro();

	return true;
}

add_action( 'plugins_loaded', 'wp_migrate_db_pro_loaded' );

