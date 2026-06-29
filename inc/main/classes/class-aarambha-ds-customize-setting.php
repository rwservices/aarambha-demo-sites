<?php
/**
 * Aarambha_DS_Customize_Setting
 *
 * NOTE: This class is kept for backwards compatibility only.
 * All new code should use Aarambha_DS_Customize_Importer_Setting instead.
 *
 * A class that extends WP_Customize_Setting so we can access
 * the protected update() method when importing options.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_Customize_Setting', false ) ) {
	require_once ABSPATH . WPINC . '/class-wp-customize-setting.php';
}

if ( ! class_exists( 'Aarambha_DS_Customize_Importer_Setting', false ) ) {
	require_once dirname( __FILE__ ) . '/class-aarambha-ds-customize-importer-setting.php';
}

/**
 * Alias of Aarambha_DS_Customize_Importer_Setting.
 *
 * @deprecated Use Aarambha_DS_Customize_Importer_Setting directly.
 * @see Aarambha_DS_Customize_Importer_Setting
 */
class Aarambha_DS_Customize_Setting extends Aarambha_DS_Customize_Importer_Setting {
	// Intentionally empty — inherits import() from Aarambha_DS_Customize_Importer_Setting.
}