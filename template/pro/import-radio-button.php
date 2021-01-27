<li class="import-list">
	<label for="import"<?php echo ( $this->license->is_valid_licence() ) ? '' : ' class="disabled"'; ?>>
		<input id="import" type="radio" value="import" name="action" <?php echo ( $loaded_profile['action'] == 'import' && $this->props->is_pro ) ? ' checked="checked"' : ''; ?><?php echo ( $this->license->is_valid_licence() ) ? '' : ' disabled="disabled"'; ?> />
		<?php _ex( 'Import', 'Import data from a SQL file', 'wp-migrate-db' ); ?>
	</label>
	<ul>
		<li>
			<input id="import-file" type="file" name="import_file" />
		</li>
	</ul>
</li>
