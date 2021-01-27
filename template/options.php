<div class="wrap wpmdb">

	<?php /* This is a hack to get sitewide notices to appear above the visible title. https://github.com/deliciousbrains/wp-migrate-db-pro/issues/1436 */ ?>
	<h1 style="display:none;"></h1>

	<div id="icon-tools" class="icon32"><br/></div>
	<h1><?php _e( 'Migrate DB', 'wp-migrate-db' ); ?></h1>

	<h2 class="nav-tab-wrapper">
		<?php $this->plugin_tabs() ?>
	</h2>

	<?php do_action( 'wpmdb_notices' ); ?>

	<div class="updated warning ie-warning inline-message" style="display: none;">
		<?php _e( "<strong>Internet Explorer Not Supported</strong> &mdash; Less than 2% of our customers use IE, so we've decided not to spend time supporting it. We ask that you use Firefox or a Webkit-based browser like Chrome or Safari instead. If this is a problem for you, please let us know.", 'wp-migrate-db' ); ?>
	</div>

	<div class="updated warning edge-warning inline-message" style="display: none;">
		<?php _e( "<strong>Microsoft Edge Not Supported</strong> &mdash; Less than 2% of our customers use Microsoft Edge, so we've decided not to spend time supporting it. We ask that you use Firefox or a Webkit-based browser like Chrome or Safari instead. If this is a problem for you, please let us know.", 'wp-migrate-db' ); ?>
	</div>

	<?php
	$hide_warning = apply_filters( 'wpmdb_hide_set_time_limit_warning', false );
	if ( false == $this->util->set_time_limit_available() && ! $hide_warning ) {
		?>
		<div class="updated warning inline-message">
			<?php
			_e( "<strong>PHP Function Disabled</strong> &mdash; The <code>set_time_limit()</code> function is currently disabled on your server. We use this function to ensure that the migration doesn't time out. We haven't disabled the plugin however, so you're free to cross your fingers and hope for the best. You may want to contact your web host to enable this function.", 'wp-migrate-db' );
			if ( function_exists( 'ini_get' ) ) {
				printf( __( 'Your current PHP run time limit is set to %s seconds.', 'wp-migrate-db' ), ini_get( 'max_execution_time' ) );
			} ?>
		</div>
	<?php
	}
	?>

	<div id="wpmdb-main">

		<?php
		// select profile if more than > 1 profile saved
		if ( ! empty( $this->settings['profiles'] ) && ! isset( $_GET['wpmdb-profile'] ) ) {
			$this->template( 'profile' );
		} else {
			$this->template( 'migrate' );
		}

		if( $this->props->is_pro ){
			$this->template( 'backups', 'pro' );
		}

		$this->template( 'settings' );
		$this->template( 'addons' );
		$this->template( 'help' );

		$this->template_part( array( 'sidebar' ) );
		?>

	</div>
	<!-- end #wpmdb-main -->

</div> <!-- end .wrap -->
