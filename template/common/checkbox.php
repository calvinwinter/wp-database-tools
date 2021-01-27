<?php
$default  = isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : false;
$value    = ( isset( $value ) ) ? $value : $default;
$class    = ( isset( $class ) ) ? 'class="' . $class . '"' : '';
$disabled = ( isset( $disabled ) && $disabled ) ? ' disabled' : '';
?>
<div id="<?php echo $key; ?>-wrap" data-checkbox="<?php echo $key; ?>" class="wpmdb-switch<?php echo $disabled . $value ? ' on' : ''; echo $disabled; ?>">
	<span class="off <?php echo ! $value ? 'checked' : ''; ?>">OFF</span>
	<span class="on <?php echo $value ? 'checked' : ''; ?>">ON</span>
	<input type="hidden" name="<?php echo $key; ?>" value="0" />
	<input type="checkbox" name="<?php echo $key; ?>" value="1" id="<?php echo $key; ?>" <?php checked( $value ); ?> <?php echo $class ?>/>
</div>
