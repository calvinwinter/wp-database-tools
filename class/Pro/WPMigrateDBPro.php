<?php

namespace DeliciousBrains\WPMDB\Pro;

use DeliciousBrains\WPMDB\WPMigrateDB;

/**
 * Class WPMigrateDBPro
 *
 * Base class for setting up Pro plugin
 *
 * @package DeliciousBrains\WPMDB\Pro
 */
class WPMigrateDBPro extends WPMigrateDB {

	public function __construct( $pro = false ) {
		parent::__construct( $pro );
	}

	/**
	 * Register WordPress hooks here
	 */
	public function register() {
		parent::register();
		$register_pro = new RegisterPro();
		$register_pro->register();
	}
}
