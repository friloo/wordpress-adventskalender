<?php
/**
 * Einstellungen des Adventskalenders.
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kapselt Lesen, Schreiben und Validieren der Plugin-Optionen.
 */
class Settings {

	/**
	 * Name der Option in wp_options.
	 */
	const OPTION = 'adventskalender_settings';

	/**
	 * Erlaubte Layout-Modi für die geschlossenen Türchen.
	 *
	 * @return array<string,string>
	 */
	public static function layouts(): array {
		return array(
			'classic'    => __( 'Klassisch – elegante Türchen mit großer Zahl (kein Bild nötig)', 'adventskalender' ),
			'mosaic'     => __( 'Mosaik – ein großes Bild, jedes Türchen zeigt seinen Ausschnitt', 'adventskalender' ),
			'individual' => __( 'Einzelbilder – jedes Türchen hat sein eigenes Motiv', 'adventskalender' ),
		);
	}

	/**
	 * Erlaubte Farbwelten.
	 *
	 * @return array<string,string>
	 */
	public static function themes(): array {
		return array(
			'nordic'  => __( 'Nordisch – Tannengrün & Schnee', 'adventskalender' ),
			'elegant' => __( 'Elegant – Dunkelblau & Gold', 'adventskalender' ),
			'warm'    => __( 'Warm – Rot, Kupfer & Creme', 'adventskalender' ),
			'modern'  => __( 'Modern – Graphit & Mint', 'adventskalender' ),
			'candy'   => __( 'Candy – Pastelltöne', 'adventskalender' ),
			'brand'   => __( 'Markenfarbe – Palette aus deiner Firmenfarbe', 'adventskalender' ),
		);
	}

	/**
	 * Benötigte Berechtigung für die Türchen-Verwaltung.
	 *
	 * Bewusst hier und nicht in der Adminklasse: auch der REST-Controller
	 * fragt sie ab, und der läuft ohne Adminoberfläche.
	 */
	public static function manage_capability(): string {
		/**
		 * Filtert die Capability zur Verwaltung der Türchen.
		 *
		 * @param string $capability Capability.
		 */
		return (string) apply_filters( 'adventskalender_manage_capability', 'edit_posts' );
	}

	/**
	 * Verfügbare Editoren für die Türchen-Bearbeitung.
	 *
	 * @return array<string,string>
	 */
	public static function editors(): array {
		return array(
			'block'   => __( 'Block-Editor (Gutenberg) – Bilder, Galerien und Spalten als Blöcke', 'adventskalender' ),
			'classic' => __( 'Klassischer Editor – alle Felder des Plugins auf einen Blick', 'adventskalender' ),
		);
	}

	/**
	 * Standardwerte aller Optionen.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'year'              => (int) current_time( 'Y' ),
			'test_mode'         => 0,
			'editor_preview'    => 1,
			'layout'            => 'classic',
			'theme'             => 'nordic',
			'brand_color'       => '#1f5f46',
			'brand_scheme'      => 'light',
			'editor'            => 'block',
			'columns'           => 6,
			'shuffle'           => 1,
			'shuffle_seed'      => 1,
			'mosaic_image'      => 0,
			'background_image'  => 0,
			'snow'              => 0,
			'heading'           => '',
			'intro'             => '',
			'locked_notice'     => __( 'Dieses Türchen öffnet erst am {datum}.', 'adventskalender' ),
			'test_mode_notice'  => 1,
			'remember_opened'   => 1,
			'delete_data'       => 0,
			'door_count'        => 24,
		);
	}

	/**
	 * Liest die Optionen (mit Defaults zusammengeführt).
	 *
	 * @param string|null $key     Optionaler Einzelschlüssel.
	 * @param mixed       $default Rückfallwert, wenn der Schlüssel unbekannt ist.
	 * @return mixed
	 */
	public static function get( ?string $key = null, $default = null ) {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings = wp_parse_args( $stored, self::defaults() );

		if ( null === $key ) {
			return $settings;
		}

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Registriert die Option inklusive Sanitize-Callback (Settings API).
	 */
	public static function register(): void {
		register_setting(
			'adventskalender_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Validiert und bereinigt eingehende Formulardaten.
	 *
	 * @param mixed $input Rohdaten aus dem Formular.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$current  = self::get();
		$defaults = self::defaults();

		if ( ! is_array( $input ) ) {
			return $current;
		}

		$out = $current;

		// Jahr: sinnvoller Korridor rund um die Gegenwart.
		if ( isset( $input['year'] ) ) {
			$year        = absint( $input['year'] );
			$out['year'] = ( $year >= 2000 && $year <= 2100 ) ? $year : $defaults['year'];
		}

		// Anzahl der Türchen.
		if ( isset( $input['door_count'] ) ) {
			$count             = absint( $input['door_count'] );
			$out['door_count'] = ( $count >= 1 && $count <= 31 ) ? $count : 24;
		}

		// Spalten.
		if ( isset( $input['columns'] ) ) {
			$cols           = absint( $input['columns'] );
			$out['columns'] = ( $cols >= 2 && $cols <= 8 ) ? $cols : 6;
		}

		// Auswahllisten gegen Allowlist prüfen.
		$out['layout'] = isset( $input['layout'] ) && array_key_exists( $input['layout'], self::layouts() )
			? $input['layout']
			: $defaults['layout'];

		$out['theme'] = isset( $input['theme'] ) && array_key_exists( $input['theme'], self::themes() )
			? $input['theme']
			: $defaults['theme'];

		// Checkboxen.
		foreach ( array( 'test_mode', 'editor_preview', 'shuffle', 'snow', 'test_mode_notice', 'remember_opened', 'delete_data' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		// Medien-IDs: müssen existierende Anhänge sein.
		foreach ( array( 'mosaic_image', 'background_image' ) as $media_key ) {
			$out[ $media_key ] = isset( $input[ $media_key ] ) ? self::sanitize_attachment_id( $input[ $media_key ] ) : 0;
		}

		$out['editor'] = isset( $input['editor'] ) && array_key_exists( $input['editor'], self::editors() )
			? $input['editor']
			: $defaults['editor'];

		// Markenfarbe: nur gültige Hex-Werte, sonst der bisherige Wert.
		$out['brand_color'] = isset( $input['brand_color'] )
			? Color::sanitize_hex( $input['brand_color'], (string) $current['brand_color'] )
			: (string) $current['brand_color'];

		$out['brand_scheme'] = isset( $input['brand_scheme'] ) && array_key_exists( $input['brand_scheme'], Color::schemes() )
			? $input['brand_scheme']
			: $defaults['brand_scheme'];

		// Texte.
		$out['heading']       = isset( $input['heading'] ) ? sanitize_text_field( (string) $input['heading'] ) : '';
		$out['intro']         = isset( $input['intro'] ) ? wp_kses_post( (string) $input['intro'] ) : '';
		$out['locked_notice'] = isset( $input['locked_notice'] ) && '' !== trim( (string) $input['locked_notice'] )
			? sanitize_text_field( (string) $input['locked_notice'] )
			: $defaults['locked_notice'];

		// Neuer Zufalls-Seed auf Wunsch.
		$out['shuffle_seed'] = absint( $current['shuffle_seed'] ) > 0 ? absint( $current['shuffle_seed'] ) : 1;
		if ( ! empty( $input['reshuffle'] ) ) {
			$out['shuffle_seed'] = wp_rand( 1, 999999 );
		}

		return $out;
	}

	/**
	 * Prüft, ob eine ID zu einem existierenden Anhang gehört.
	 *
	 * @param mixed $value Rohwert.
	 * @return int Gültige Anhang-ID oder 0.
	 */
	public static function sanitize_attachment_id( $value ): int {
		$id = absint( $value );
		if ( $id <= 0 ) {
			return 0;
		}

		return 'attachment' === get_post_type( $id ) ? $id : 0;
	}
}
