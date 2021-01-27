<?php

namespace DeliciousBrains\WPMDB\Pro\Queue\Jobs;

use DeliciousBrains\WPMDB\Pro\Queue\Job;
/**
 * Class WPMDB_Job
 *
 * @package WPMDB\Queue\Jobs
 */
class WPMDB_Job extends Job {

	public $file;

	public function __construct( $file ) {
		$this->file = $file;
	}

	/**
	 * Handle job logic.
	 */
	public function handle() {
		return true;
	}

}
