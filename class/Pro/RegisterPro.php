<?php

namespace DeliciousBrains\WPMDB\Pro;

use DeliciousBrains\WPMDB\Common\Plugin\Menu;
use DeliciousBrains\WPMDB\Container;
use DeliciousBrains\WPMDB\Pro\Cli\Export;

class RegisterPro {

	/**
	 * @var
	 */
	private $pro_migration_manager;
	/**
	 * @var
	 */
	private $migration_manager;
	/**
	 * @var
	 */
	private $usage_tracking;
	/**
	 * @var
	 */
	private $template;
	/**
	 * @var
	 */
	private $license;
	/**
	 * @var
	 */
	private $import;
	/**
	 * @var
	 */
	private $addon;
	/**
	 * @var
	 */
	private $beta_manager;
	/**
	 * @var
	 */
	private $pro_plugin_manager;
	/**
	 * @var
	 */
	private $menu;
	/**
	 * @var mixed|object
	 */
	private $backups_manager;
	/**
	 * @var Export
	 */
	private $cli_export;

	public function register() {
		$container = Container::getInstance();

		//Menu
		$this->menu = $container->addClass( 'menu', new Menu(
				$container->get( 'properties' ),
				$container->get( 'plugin_manager_base' ),
				$container->get( 'assets' )
			)
		);

		$filesystem = $container->get( 'filesystem' );
		$filesystem->register();

		$this->pro_migration_manager = $container->get( 'pro_migration_manager' );
		$this->migration_manager     = $container->get( 'migration_manager' );
		$this->template              = $container->get( 'template' );
		$this->license               = $container->get( 'license' );
		$this->import                = $container->get( 'import' );
		$this->addon                 = $container->get( 'addon' );
		$this->beta_manager          = $container->get( 'beta_manager' );
		$this->pro_plugin_manager    = $container->get( 'pro_plugin_manager' );
		$this->menu                  = $container->get( 'menu' );
		$this->usage_tracking        = $container->get( 'usage_tracking' );
		$this->backups_manager       = $container->get( 'backups_manager' );
		$this->cli_export            = $container->get( 'cli_export' );

		// Register other class actions and filters
		$this->pro_migration_manager->register();
		$this->migration_manager->register();
		$this->template->register();
		$this->license->register();
		$this->import->register();
		$this->addon->register();
		$this->beta_manager->register();
		$this->pro_plugin_manager->register();
		$this->menu->register();
		$this->usage_tracking->register();
		$this->backups_manager->register();

		if ( ! class_exists( '\DeliciousBrains\WPMDBCli\Cli' ) ) {
			$this->cli_export->register();
		}
	}

	// @TODO remove once enough users off of 1.9.* branch
	public function loadContainer() { }

	public function loadTransfersContainer() { }
}
