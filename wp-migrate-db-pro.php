<?php
/*
Plugin Name: WP Database Tools
Plugin URI: https://github.com/calvinwinter/wp-database-tools
Description: Find & replace, export, import, push, and pull your WordPress databases.
Author: Calvin Winter
Version: 1.9.14
Author URI: https://calvinwinter.ca
Network: True
Text Domain: wp-database-tools
Domain Path: /languages/
GitHub Plugin URI: https://github.com/calvinwinter/wp-database-tools
*/

// Copyright (c) 2021 Calvin Winter. All rights reserved.
//
// Released under the GPL license
// http://www.opensource.org/licenses/gpl-license.php
//
// **********************************************************************
// This program is distributed in the hope that it will be useful, but
// WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// **********************************************************************
$wpmdb_base_path                                       = dirname( __FILE__ );
$GLOBALS['wpmdb_meta']['wp-migrate-db-pro']['version'] = '1.9.14';
$GLOBALS['wpmdb_meta']['wp-migrate-db-pro']['folder']  = basename( plugin_dir_path( __FILE__ ) );
$GLOBALS['wpmdb_meta']['wp-migrate-db-pro']['abspath'] = $wpmdb_base_path;

if ( ! defined( 'WPMDB_MINIMUM_PHP_VERSION' ) ) {
	define( 'WPMDB_MINIMUM_PHP_VERSION', '5.4' );
}

if ( version_compare( PHP_VERSION, WPMDB_MINIMUM_PHP_VERSION, '>=' ) ) {
	require_once $wpmdb_base_path . '/class/autoload.php';
	require_once $wpmdb_base_path . '/setup-mdb-pro.php';
}

if ( ! function_exists( 'wpmdb_deactivate_other_instances' ) ) {
	require_once $wpmdb_base_path . '/class/deactivate.php';
}

add_action( 'activated_plugin', 'wpmdb_deactivate_other_instances' );

if ( ! class_exists( 'WPMDB_PHP_Checker' ) ) {
	require_once $wpmdb_base_path . '/php-checker.php';
}

$php_checker = new WPMDB_PHP_Checker( __FILE__, WPMDB_MINIMUM_PHP_VERSION );
if ( ! $php_checker->is_compatible_check() ) {
	register_activation_hook( __FILE__, array( 'WPMDB_PHP_Checker', 'wpmdb_pro_php_version_too_low' ) );
}

function wpmdb_pro_remove_mu_plugin() {
	do_action( 'wp_migrate_db_remove_compatibility_plugin' );
}

