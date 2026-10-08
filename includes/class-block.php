<?php
/**
 * Gutenberg-Block „Adventskalender“.
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamischer Block ohne Build-Schritt.
 */
class Block {

	/**
	 * Block-Name.
	 */
	const NAME = 'adventskalender/calendar';

	/**
	 * Registriert die Hooks.
	 */
	public static function hooks(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
	}

	/**
	 * Registriert Skript und Block.
	 */
	public static function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'adventskalender-block',
			plugin_url_base() . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-server-side-render' ),
			VERSION,
			true
		);

		wp_localize_script(
			'adventskalender-block',
			'AdventskalenderBlock',
			array(
				'layouts' => Settings::layouts(),
				'themes'  => Settings::themes(),
				'i18n'    => array(
					'title'       => __( 'Adventskalender', 'adventskalender' ),
					'description' => __( 'Zeigt den Adventskalender mit allen Türchen an.', 'adventskalender' ),
					'settings'    => __( 'Kalender-Einstellungen', 'adventskalender' ),
					'year'        => __( 'Kalenderjahr', 'adventskalender' ),
					'layout'      => __( 'Layout der Türchen', 'adventskalender' ),
					'theme'       => __( 'Farbwelt', 'adventskalender' ),
					'columns'     => __( 'Spalten', 'adventskalender' ),
					'shuffle'     => __( 'Zufällig anordnen', 'adventskalender' ),
					'snow'        => __( 'Schneefall', 'adventskalender' ),
					'heading'     => __( 'Überschrift', 'adventskalender' ),
					'inherit'     => __( 'Aus den Einstellungen übernehmen', 'adventskalender' ),
				),
			)
		);

		register_block_type(
			self::NAME,
			array(
				'api_version'           => 3,
				'title'                 => __( 'Adventskalender', 'adventskalender' ),
				'category'              => 'widgets',
				'icon'                  => 'calendar-alt',
				'description'           => __( 'Zeigt den Adventskalender mit allen Türchen an.', 'adventskalender' ),
				'editor_script_handles' => array( 'adventskalender-block' ),
				'style_handles'         => array( 'adventskalender' ),
				'supports'              => array(
					'html'      => false,
					'multiple'  => false,
					'align'     => array( 'wide', 'full' ),
					'spacing'   => array( 'margin' => true ),
				),
				'attributes'            => array(
					'year'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'layout'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'theme'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'columns' => array(
						'type'    => 'string',
						'default' => '',
					),
					'shuffle' => array(
						'type'    => 'string',
						'default' => '',
					),
					'snow'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'heading' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback'       => array( __CLASS__, 'render' ),
			)
		);
	}

	/**
	 * Rendert den Block serverseitig.
	 *
	 * @param array<string,mixed> $attributes Block-Attribute.
	 */
	public static function render( $attributes = array() ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();

		$atts = array(
			'year'    => isset( $attributes['year'] ) ? sanitize_text_field( (string) $attributes['year'] ) : '',
			'layout'  => isset( $attributes['layout'] ) ? sanitize_key( (string) $attributes['layout'] ) : '',
			'theme'   => isset( $attributes['theme'] ) ? sanitize_key( (string) $attributes['theme'] ) : '',
			'columns' => isset( $attributes['columns'] ) ? sanitize_text_field( (string) $attributes['columns'] ) : '',
			'shuffle' => isset( $attributes['shuffle'] ) ? sanitize_text_field( (string) $attributes['shuffle'] ) : '',
			'snow'    => isset( $attributes['snow'] ) ? sanitize_text_field( (string) $attributes['snow'] ) : '',
			'heading' => isset( $attributes['heading'] ) ? sanitize_text_field( (string) $attributes['heading'] ) : '',
		);

		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : '';

		return '<div ' . $wrapper . '>' . Renderer::render( $atts ) . '</div>';
	}
}
