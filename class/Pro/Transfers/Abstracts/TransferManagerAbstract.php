<?php

namespace DeliciousBrains\WPMDB\Pro\Transfers\Abstracts;

use DeliciousBrains\WPMDB\Pro\Queue\Manager;
use DeliciousBrains\WPMDB\Pro\Transfers\Files\Payload;
use DeliciousBrains\WPMDB\Pro\Transfers\Files\Util;

/**
 * Class TransferManagerAbstract
 *
 * @package DeliciousBrains\WPMDB\Pro\Transfers\Abstracts
 */
abstract class TransferManagerAbstract {

	/**
	 * TransferManager constructor.
	 *
	 * @param $wpmdb
	 */

	public $queueManager;
	public $payload;
	public $util;

	public function __construct( Manager $manager, Payload $payload, Util $util ) {
		$this->queueManager = $manager;
		$this->payload      = $payload;
		$this->util         = $util;
	}

	public function manage_transfer() {
	}

	public function post( $payload, $state_data, $action, $remote_url ) {
	}

	public function request( $file, $state_data, $action, $remote_url ) {
	}

	public function handle_push( $processed, $state_data, $remote_url ) {
	}

	public function handle_pull( $processed, $state_data, $remote_url ) {
	}

}
