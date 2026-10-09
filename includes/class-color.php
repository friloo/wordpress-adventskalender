<?php
/**
 * Farbmathematik für die Farbwelt „Markenfarbe“.
 *
 * Aus einer einzigen Hex-Farbe wird eine vollständige, lesbare Palette
 * abgeleitet. Kontraste werden nach WCAG 2.1 berechnet, damit die
 * Tagesnummern auf jeder denkbaren Markenfarbe lesbar bleiben.
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Umrechnungen, Kontrastprüfung und Palettenableitung.
 */
class Color {

	/**
	 * Rückfallfarbe, wenn keine gültige Eingabe vorliegt.
	 */
	const FALLBACK = '#1f5f46';

	/**
	 * Mindestkontrast für Text (WCAG AA, normaler Text).
	 */
	const CONTRAST_TEXT = 4.5;

	/**
	 * Mindestkontrast für Bedienelemente und Fokusringe (WCAG AA).
	 */
	const CONTRAST_UI = 3.0;

	/**
	 * Normalisiert eine Farbeingabe zu „#rrggbb“.
	 *
	 * Akzeptiert „#abc“, „abc“, „#aabbcc“ und „aabbcc“.
	 *
	 * @param mixed  $value    Rohwert.
	 * @param string $fallback Rückfallfarbe.
	 */
	public static function sanitize_hex( $value, string $fallback = self::FALLBACK ): string {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return $fallback;
		}

		$hex = strtolower( trim( (string) $value ) );
		$hex = ltrim( $hex, '#' );

		if ( preg_match( '/^[0-9a-f]{3}$/', $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if ( ! preg_match( '/^[0-9a-f]{6}$/', $hex ) ) {
			return $fallback;
		}

		return '#' . $hex;
	}

	/**
	 * Zerlegt eine Hex-Farbe in RGB-Anteile (0–255).
	 *
	 * @param string $hex Hex-Farbe.
	 * @return array{0:int,1:int,2:int}
	 */
	public static function to_rgb( string $hex ): array {
		$hex = self::sanitize_hex( $hex );

		return array(
			(int) hexdec( substr( $hex, 1, 2 ) ),
			(int) hexdec( substr( $hex, 3, 2 ) ),
			(int) hexdec( substr( $hex, 5, 2 ) ),
		);
	}

	/**
	 * Wandelt eine Hex-Farbe in HSL um.
	 *
	 * @param string $hex Hex-Farbe.
	 * @return array{0:float,1:float,2:float} Farbton 0–360, Sättigung 0–1, Helligkeit 0–1.
	 */
	public static function to_hsl( string $hex ): array {
		list( $r, $g, $b ) = self::to_rgb( $hex );

		$r /= 255;
		$g /= 255;
		$b /= 255;

		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;

		if ( abs( $max - $min ) < 0.000001 ) {
			return array( 0.0, 0.0, $l );
		}

		$delta = $max - $min;
		$s     = $l > 0.5 ? $delta / ( 2 - $max - $min ) : $delta / ( $max + $min );

		if ( $max === $r ) {
			$h = ( ( $g - $b ) / $delta ) + ( $g < $b ? 6 : 0 );
		} elseif ( $max === $g ) {
			$h = ( ( $b - $r ) / $delta ) + 2;
		} else {
			$h = ( ( $r - $g ) / $delta ) + 4;
		}

		return array( $h * 60, $s, $l );
	}

	/**
	 * Baut eine Hex-Farbe aus HSL.
	 *
	 * @param float $h Farbton 0–360.
	 * @param float $s Sättigung 0–1.
	 * @param float $l Helligkeit 0–1.
	 */
	public static function from_hsl( float $h, float $s, float $l ): string {
		$h = fmod( fmod( $h, 360 ) + 360, 360 ) / 360;
		$s = max( 0.0, min( 1.0, $s ) );
		$l = max( 0.0, min( 1.0, $l ) );

		if ( $s < 0.000001 ) {
			$value = (int) round( $l * 255 );

			return sprintf( '#%02x%02x%02x', $value, $value, $value );
		}

		$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - ( $l * $s );
		$p = ( 2 * $l ) - $q;

		$channel = static function ( float $t ) use ( $p, $q ): int {
			if ( $t < 0 ) {
				++$t;
			}
			if ( $t > 1 ) {
				--$t;
			}
			if ( $t < 1 / 6 ) {
				return (int) round( ( $p + ( ( $q - $p ) * 6 * $t ) ) * 255 );
			}
			if ( $t < 1 / 2 ) {
				return (int) round( $q * 255 );
			}
			if ( $t < 2 / 3 ) {
				return (int) round( ( $p + ( ( $q - $p ) * ( ( 2 / 3 ) - $t ) * 6 ) ) * 255 );
			}

			return (int) round( $p * 255 );
		};

		return sprintf(
			'#%02x%02x%02x',
			$channel( ( 1 / 3 ) + $h ),
			$channel( $h ),
			$channel( $h - ( 1 / 3 ) )
		);
	}

	/**
	 * Setzt die Helligkeit einer Farbe neu.
	 *
	 * @param string $hex       Hex-Farbe.
	 * @param float  $lightness Neue Helligkeit 0–1.
	 */
	public static function with_lightness( string $hex, float $lightness ): string {
		list( $h, $s ) = self::to_hsl( $hex );

		return self::from_hsl( $h, $s, $lightness );
	}

	/**
	 * Verschiebt die Helligkeit relativ.
	 *
	 * @param string $hex   Hex-Farbe.
	 * @param float  $delta Änderung (positiv = heller).
	 */
	public static function shift_lightness( string $hex, float $delta ): string {
		list( $h, $s, $l ) = self::to_hsl( $hex );

		return self::from_hsl( $h, $s, $l + $delta );
	}

	/**
	 * Skaliert die Sättigung.
	 *
	 * @param string $hex    Hex-Farbe.
	 * @param float  $factor Faktor (0 = grau, 1 = unverändert).
	 */
	public static function scale_saturation( string $hex, float $factor ): string {
		list( $h, $s, $l ) = self::to_hsl( $hex );

		return self::from_hsl( $h, $s * $factor, $l );
	}

	/**
	 * Setzt Sättigung und Helligkeit gleichzeitig, behält den Farbton.
	 *
	 * @param string $hex       Hex-Farbe.
	 * @param float  $factor    Sättigungsfaktor.
	 * @param float  $lightness Zielhelligkeit.
	 */
	public static function tint( string $hex, float $factor, float $lightness ): string {
		list( $h, $s ) = self::to_hsl( $hex );

		return self::from_hsl( $h, $s * $factor, $lightness );
	}

	/**
	 * Relative Leuchtdichte nach WCAG 2.1.
	 *
	 * @param string $hex Hex-Farbe.
	 */
	public static function luminance( string $hex ): float {
		$channels = array();
		foreach ( self::to_rgb( $hex ) as $value ) {
			$value      = $value / 255;
			$channels[] = $value <= 0.03928
				? $value / 12.92
				: pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}

		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}

	/**
	 * Kontrastverhältnis zweier Farben (1–21).
	 *
	 * @param string $a Erste Farbe.
	 * @param string $b Zweite Farbe.
	 */
	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );

		$light = max( $la, $lb );
		$dark  = min( $la, $lb );

		return ( $light + 0.05 ) / ( $dark + 0.05 );
	}

	/**
	 * Wählt zwischen heller und dunkler Variante die kontrastreichere.
	 *
	 * @param string $background Hintergrundfarbe.
	 * @param string $light      Helle Kandidatin.
	 * @param string $dark       Dunkle Kandidatin.
	 */
	public static function readable_on( string $background, string $light, string $dark ): string {
		return self::contrast( $background, $light ) >= self::contrast( $background, $dark )
			? $light
			: $dark;
	}

	/**
	 * Schiebt eine Farbe so lange heller oder dunkler, bis der geforderte
	 * Kontrast zum Hintergrund erreicht ist.
	 *
	 * Der Farbton bleibt dabei erhalten – die Marke bleibt erkennbar.
	 *
	 * @param string $color      Ausgangsfarbe.
	 * @param string $background Hintergrundfarbe.
	 * @param float  $target     Gewünschtes Kontrastverhältnis.
	 */
	public static function ensure_contrast( string $color, string $background, float $target ): string {
		if ( self::contrast( $color, $background ) >= $target ) {
			return $color;
		}

		list( $h, $s, $l ) = self::to_hsl( $color );

		// In die Richtung schieben, die vom Hintergrund wegführt.
		$direction = self::luminance( $background ) > 0.45 ? -1 : 1;
		$best      = self::from_hsl( $h, $s, $l );
		$best_rate = self::contrast( $best, $background );

		for ( $step = 1; $step <= 50; $step++ ) {
			$candidate = self::from_hsl( $h, $s, $l + ( $direction * 0.02 * $step ) );
			$rate      = self::contrast( $candidate, $background );

			if ( $rate > $best_rate ) {
				$best      = $candidate;
				$best_rate = $rate;
			}

			if ( $rate >= $target ) {
				return $candidate;
			}
		}

		// Kontrast nicht erreichbar: die kontrastreichste Variante nehmen,
		// notfalls Schwarz oder Weiß.
		foreach ( array( '#ffffff', '#000000' ) as $extreme ) {
			if ( self::contrast( $extreme, $background ) > $best_rate ) {
				$best      = $extreme;
				$best_rate = self::contrast( $extreme, $background );
			}
		}

		return $best;
	}

	/**
	 * Hex-Farbe als rgba()-Zeichenkette.
	 *
	 * @param string $hex   Hex-Farbe.
	 * @param float  $alpha Deckkraft 0–1.
	 */
	public static function rgba( string $hex, float $alpha ): string {
		list( $r, $g, $b ) = self::to_rgb( $hex );

		return sprintf( 'rgba(%d, %d, %d, %s)', $r, $g, $b, rtrim( rtrim( number_format( $alpha, 3, '.', '' ), '0' ), '.' ) );
	}

	/**
	 * Erlaubte Helligkeitsschemata der Markenfarbwelt.
	 *
	 * @return array<string,string>
	 */
	public static function schemes(): array {
		return array(
			'light' => __( 'Hell – heller Kalenderhintergrund', 'adventskalender' ),
			'dark'  => __( 'Dunkel – dunkler Kalenderhintergrund', 'adventskalender' ),
		);
	}

	/**
	 * Leitet die vollständige Palette aus einer Markenfarbe ab.
	 *
	 * Rückgabe sind fertige Werte für die CSS-Variablen des Kalenders.
	 *
	 * @param string $brand  Markenfarbe als Hex.
	 * @param string $scheme „light“ oder „dark“.
	 * @return array<string,string>
	 */
	public static function palette( string $brand, string $scheme = 'light' ): array {
		$brand  = self::sanitize_hex( $brand );
		$scheme = array_key_exists( $scheme, self::schemes() ) ? $scheme : 'light';
		$dark   = 'dark' === $scheme;

		list( $hue, $sat, $light ) = self::to_hsl( $brand );

		// Die Türchen sollen als Objekte wirken: sehr helle oder sehr dunkle
		// Marken werden in ein brauchbares Helligkeitsband geholt. Der
		// Farbton bleibt exakt erhalten, die Marke also erkennbar.
		$door_light = $dark
			? max( 0.20, min( 0.50, $light ) )
			: max( 0.24, min( 0.60, $light ) );

		$door_base = self::from_hsl( $hue, $sat, $door_light );
		$door_top  = self::from_hsl( $hue, $sat, min( 0.96, $door_light + 0.07 ) );
		$door_foot = self::from_hsl( $hue, $sat, max( 0.04, $door_light - 0.13 ) );

		// Kalenderfläche: sehr heller bzw. sehr dunkler Hauch der Marke.
		if ( $dark ) {
			$canvas_a = self::tint( $brand, 0.55, 0.12 );
			$canvas_b = self::tint( $brand, 0.60, 0.07 );
			$canvas   = self::tint( $brand, 0.58, 0.095 );
			$text     = self::tint( $brand, 0.20, 0.94 );
			$inside   = self::tint( $brand, 0.70, 0.05 );
		} else {
			$canvas_a = self::tint( $brand, 0.30, 0.965 );
			$canvas_b = self::tint( $brand, 0.38, 0.905 );
			$canvas   = self::tint( $brand, 0.34, 0.935 );
			$text     = self::tint( $brand, 0.45, 0.17 );
			$inside   = self::tint( $brand, 0.65, 0.10 );
		}

		// Hellere Variante für Vorschauflächen ohne Bild.
		$inside_soft = self::tint( $brand, 0.50, $dark ? 0.22 : 0.27 );

		// Lesbarkeit erzwingen statt hoffen.
		$text   = self::ensure_contrast( $text, $canvas, 7.0 );
		$number = self::readable_on(
			$door_base,
			self::tint( $brand, 0.20, 0.97 ),
			self::tint( $brand, 0.55, 0.11 )
		);
		$number = self::ensure_contrast( $number, $door_base, self::CONTRAST_TEXT );

		// Akzent (Knauf, Fokusring, Zitatstrich): die Marke selbst, nur so
		// weit angepasst, dass sie auf der Fläche sichtbar bleibt.
		$accent = self::ensure_contrast( $brand, $canvas, self::CONTRAST_UI );

		// Gegen die hellere Fläche prüfen – dort ist der Kontrast knapper
		// als auf dem dunklen Innenraum.
		$inside_text = self::ensure_contrast(
			self::tint( $brand, 0.18, 0.96 ),
			$inside_soft,
			self::CONTRAST_TEXT
		);

		// Lichtkante der Klappe: auf hellen Türchen dunkel, auf dunklen hell.
		$door_is_light = self::luminance( $door_base ) > 0.35;

		// Der Türknauf sitzt auf dem Türchen, nicht auf der Fläche – sein
		// Kontrast muss deshalb gegen die Türfarbe geprüft werden, sonst
		// verschwindet er in einfarbigen Markenpaletten.
		$knob = self::ensure_contrast(
			self::tint( $brand, 0.45, $door_is_light ? 0.16 : 0.82 ),
			$door_base,
			self::CONTRAST_UI
		);

		// Rückseite der geöffneten Klappe – gedämpfter Ton derselben Marke.
		$back = $dark
			? sprintf(
				'linear-gradient(155deg, %s, %s)',
				self::tint( $brand, 0.30, 0.31 ),
				self::tint( $brand, 0.33, 0.22 )
			)
			: sprintf(
				'linear-gradient(155deg, %s, %s)',
				self::tint( $brand, 0.22, 0.84 ),
				self::tint( $brand, 0.26, 0.71 )
			);

		return array(
			'--ak-brand'       => $brand,
			'--ak-canvas'      => sprintf( 'linear-gradient(180deg, %s 0%%, %s 100%%)', $canvas_a, $canvas_b ),
			'--ak-canvas-flat' => $canvas,
			'--ak-canvas-text' => $text,
			'--ak-door-face'   => sprintf( 'linear-gradient(155deg, %s 0%%, %s 58%%, %s 100%%)', $door_top, $door_base, $door_foot ),
			'--ak-door-base'   => $door_base,
			'--ak-door-edge'   => $door_is_light ? 'rgba(0, 0, 0, .16)' : 'rgba(255, 255, 255, .24)',
			'--ak-door-line'   => self::rgba( self::from_hsl( $hue, $sat, 0.08 ), 0.28 ),
			'--ak-door-back'   => $back,
			'--ak-number'      => $number,
			'--ak-accent'      => $accent,
			'--ak-knob'        => $knob,
			'--ak-inside'      => $inside,
			'--ak-inside-soft' => $inside_soft,
			'--ak-inside-text' => $inside_text,
		);
	}

	/**
	 * Palette plus gemessene Kontrastwerte – Grundlage der Admin-Vorschau.
	 *
	 * @param string $brand  Markenfarbe.
	 * @param string $scheme Helligkeitsschema.
	 * @return array<string,mixed>
	 */
	public static function report( string $brand, string $scheme = 'light' ): array {
		$palette = self::palette( $brand, $scheme );

		$checks = array(
			'number_on_door'   => array(
				'label'  => __( 'Tagesnummer auf dem Türchen', 'adventskalender' ),
				'ratio'  => self::contrast( $palette['--ak-number'], $palette['--ak-door-base'] ),
				'target' => self::CONTRAST_TEXT,
			),
			'text_on_canvas'   => array(
				'label'  => __( 'Überschrift auf der Kalenderfläche', 'adventskalender' ),
				'ratio'  => self::contrast( $palette['--ak-canvas-text'], $palette['--ak-canvas-flat'] ),
				'target' => self::CONTRAST_TEXT,
			),
			'accent_on_canvas' => array(
				'label'  => __( 'Akzent und Fokusring auf der Fläche', 'adventskalender' ),
				'ratio'  => self::contrast( $palette['--ak-accent'], $palette['--ak-canvas-flat'] ),
				'target' => self::CONTRAST_UI,
			),
			'knob_on_door'     => array(
				'label'  => __( 'Türknauf auf dem Türchen', 'adventskalender' ),
				'ratio'  => self::contrast( $palette['--ak-knob'], $palette['--ak-door-base'] ),
				'target' => self::CONTRAST_UI,
			),
			'teaser_on_inside' => array(
				'label'  => __( 'Vorschautext hinter dem Türchen', 'adventskalender' ),
				'ratio'  => min(
					self::contrast( $palette['--ak-inside-text'], $palette['--ak-inside'] ),
					self::contrast( $palette['--ak-inside-text'], $palette['--ak-inside-soft'] )
				),
				'target' => self::CONTRAST_TEXT,
			),
		);

		foreach ( $checks as $key => $check ) {
			$checks[ $key ]['ratio']  = round( $check['ratio'], 2 );
			$checks[ $key ]['passes'] = $checks[ $key ]['ratio'] + 0.005 >= $check['target'];
		}

		return array(
			'color'    => self::sanitize_hex( $brand ),
			'scheme'   => array_key_exists( $scheme, self::schemes() ) ? $scheme : 'light',
			'palette'  => $palette,
			'contrast' => $checks,
		);
	}

	/**
	 * Gibt die Palette als CSS-Deklarationen aus (für das style-Attribut).
	 *
	 * @param string $brand  Markenfarbe.
	 * @param string $scheme Helligkeitsschema.
	 * @return string[]
	 */
	public static function palette_declarations( string $brand, string $scheme = 'light' ): array {
		$declarations = array();
		foreach ( self::palette( $brand, $scheme ) as $property => $value ) {
			$declarations[] = $property . ':' . $value;
		}

		return $declarations;
	}
}
