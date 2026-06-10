<?php
/**
 * Customize API: Aarambha_DS_Customize_Importer_Setting class
 *
 * @package Customizer_Importer_Setting
 * @version 1.1.6
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Customize_Setting', false ) ) {
	require_once ABSPATH . WPINC . '/class-wp-customize-setting.php';
}

/**
 * Customizer Demo Importer Setting class.
 *
 * Extends WP_Customize_Setting to expose the protected update() method
 * so we can write option values during a demo import outside a live
 * Customizer request.
 *
 * @see WP_Customize_Setting
 */
final class Aarambha_DS_Customize_Importer_Setting extends WP_Customize_Setting {

	/**
	 * Import an option value for this setting.
	 *
	 * @param mixed $value The value to update.
	 * @return void
	 */
	public function import( $value ) {
		$this->update( $value );
	}
}