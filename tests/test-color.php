<?php
/**
 * Tests der Farbmathematik und der abgeleiteten Markenpalette.
 */

require __DIR__ . '/wp-stubs.php';

use Adventskalender\Color;

$pass = 0; $fail = 0;
function check( $label, $ok, $info = '' ) {
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	printf( "%s  %s%s\n", $ok ? '  OK ' : 'FAIL', $label, $ok ? '' : "  $info" );
}

echo "== Hex-Eingaben bereinigen ==\n";
check( 'mit Raute',            Color::sanitize_hex( '#A1B2C3' ), '#a1b2c3' );
check( 'ohne Raute',           Color::sanitize_hex( 'A1B2C3' ), '#a1b2c3' );
check( 'Kurzform',             Color::sanitize_hex( '#0af' ), '#00aaff' );
check( 'mit Leerzeichen',      Color::sanitize_hex( '  #0AF  ' ), '#00aaff' );
check( 'ungültig -> Rückfall', Color::sanitize_hex( 'rot' ), Color::FALLBACK );
check( 'zu kurz -> Rückfall',  Color::sanitize_hex( '#12345' ), Color::FALLBACK );
check( 'Injektion -> Rückfall', Color::sanitize_hex( '#fff;background:url(x)' ), Color::FALLBACK );
check( 'Array -> Rückfall',    Color::sanitize_hex( array( '#fff' ) ), Color::FALLBACK );
check( 'null -> Rückfall',     Color::sanitize_hex( null ), Color::FALLBACK );
check( 'eigener Rückfall',     Color::sanitize_hex( 'quatsch', '#123456' ), '#123456' );

echo "\n== Umrechnungen ==\n";
check( 'Weiß -> RGB',   Color::to_rgb( '#ffffff' ), array( 255, 255, 255 ) );
check( 'Schwarz -> RGB', Color::to_rgb( '#000000' ), array( 0, 0, 0 ) );
list( $h, $s, $l ) = Color::to_hsl( '#ff0000' );
check( 'Rot: Farbton 0',      0.0 === round( $h, 4 ) );
check( 'Rot: volle Sättigung', 1.0 === round( $s, 4 ) );
check( 'Rot: Helligkeit 0,5',  0.5 === round( $l, 4 ) );
list( $h2 ) = Color::to_hsl( '#00ff00' );
check( 'Grün: Farbton 120', 120.0 === round( $h2, 4 ) );
list( , $sg ) = Color::to_hsl( '#808080' );
check( 'Grau: Sättigung 0', 0.0 === round( $sg, 4 ) );

$roundtrip_errors = array();
foreach ( array( '#1f5f46', '#0057b8', '#ff6600', '#123456', '#abcdef', '#000000', '#ffffff', '#808080' ) as $hex ) {
	list( $hh, $ss, $ll ) = Color::to_hsl( $hex );
	$back = Color::from_hsl( $hh, $ss, $ll );
	if ( $back !== $hex ) {
		$roundtrip_errors[] = "$hex -> $back";
	}
}
check( 'HSL-Hin-und-Rückweg verlustfrei', empty( $roundtrip_errors ), implode( ', ', $roundtrip_errors ) );

echo "\n== Kontrastberechnung (WCAG) ==\n";
check( 'Schwarz/Weiß = 21:1',    21.0 === round( Color::contrast( '#000000', '#ffffff' ), 2 ) );
check( 'gleiche Farbe = 1:1',     1.0 === round( Color::contrast( '#3a7bd5', '#3a7bd5' ), 2 ) );
check( 'symmetrisch',             round( Color::contrast( '#123456', '#eeeeee' ), 6 ) === round( Color::contrast( '#eeeeee', '#123456' ), 6 ) );
check( '#767676 auf Weiß >= 4,5', Color::contrast( '#767676', '#ffffff' ) >= 4.5 );
check( '#777777 auf Weiß < 4,5',  Color::contrast( '#777777', '#ffffff' ) < 4.5 );

echo "\n== Kontrast erzwingen ==\n";
$fixed = Color::ensure_contrast( '#ffe000', '#ffffff', 4.5 );
check( 'helles Gelb auf Weiß wird abgedunkelt', Color::contrast( $fixed, '#ffffff' ) >= 4.5, 'erreicht: ' . round( Color::contrast( $fixed, '#ffffff' ), 2 ) );
list( $fh ) = Color::to_hsl( $fixed );
list( $oh ) = Color::to_hsl( '#ffe000' );
check( 'Farbton bleibt erhalten', abs( $fh - $oh ) < 1.0, "vorher $oh, nachher $fh" );
$already = Color::ensure_contrast( '#000000', '#ffffff', 4.5 );
check( 'ausreichender Kontrast bleibt unverändert', '#000000' === $already );
$dark_bg = Color::ensure_contrast( '#222222', '#111111', 4.5 );
check( 'auf dunklem Grund wird aufgehellt', Color::contrast( $dark_bg, '#111111' ) >= 4.5 );

echo "\n== Palette: Aufbau ==\n";
$palette = Color::palette( '#0057b8', 'light' );
$expected_keys = array(
	'--ak-brand', '--ak-canvas', '--ak-canvas-flat', '--ak-canvas-text', '--ak-door-face',
	'--ak-door-base', '--ak-door-edge', '--ak-door-line', '--ak-door-back', '--ak-number',
	'--ak-accent', '--ak-knob', '--ak-inside', '--ak-inside-text',
);
check( 'alle Variablen vorhanden', empty( array_diff( $expected_keys, array_keys( $palette ) ) ), implode( ',', array_diff( $expected_keys, array_keys( $palette ) ) ) );
check( 'Markenfarbe unverändert enthalten', '#0057b8' === $palette['--ak-brand'] );
check( 'Türchenfläche ist ein Verlauf', 0 === strpos( $palette['--ak-door-face'], 'linear-gradient(' ) );
check( 'Fläche ist ein Verlauf', 0 === strpos( $palette['--ak-canvas'], 'linear-gradient(' ) );

$unsafe = array();
foreach ( $palette as $key => $value ) {
	if ( preg_match( '/[<>"\'{}\\\\]|;\s*[a-z-]+\s*:/i', $value ) ) {
		$unsafe[] = "$key = $value";
	}
}
check( 'keine Werte, die aus dem style-Attribut ausbrechen', empty( $unsafe ), implode( ' | ', $unsafe ) );

$hue_kept = array();
foreach ( array( '#0057b8', '#c8102e', '#ffb81c', '#00a19a' ) as $brand ) {
	list( $bh ) = Color::to_hsl( $brand );
	list( $dh ) = Color::to_hsl( Color::palette( $brand )['--ak-door-base'] );
	if ( abs( $bh - $dh ) > 1.0 ) {
		$hue_kept[] = $brand;
	}
}
check( 'Türchen behalten den Markenfarbton', empty( $hue_kept ), implode( ', ', $hue_kept ) );

echo "\n== Palette: Lesbarkeit über das gesamte Farbspektrum ==\n";
// Jede denkbare Markenfarbe durchspielen: Farbton, Sättigung, Helligkeit.
$worst = array(
	'number'  => array( 99.0, '' ),
	'text'    => array( 99.0, '' ),
	'accent'  => array( 99.0, '' ),
	'knob'    => array( 99.0, '' ),
	'teaser'  => array( 99.0, '' ),
);
$tested = 0;
$fails  = array( 'number' => array(), 'text' => array(), 'accent' => array(), 'knob' => array(), 'teaser' => array() );

foreach ( array( 'light', 'dark' ) as $scheme ) {
	for ( $hue = 0; $hue < 360; $hue += 10 ) {
		foreach ( array( 0.0, 0.25, 0.55, 0.85, 1.0 ) as $sat ) {
			foreach ( array( 0.05, 0.18, 0.32, 0.5, 0.68, 0.85, 0.97 ) as $lum ) {
				$brand = Color::from_hsl( $hue, $sat, $lum );
				$p     = Color::palette( $brand, $scheme );
				++$tested;

				$measures = array(
					'number' => array( Color::contrast( $p['--ak-number'], $p['--ak-door-base'] ), 4.5 ),
					'text'   => array( Color::contrast( $p['--ak-canvas-text'], $p['--ak-canvas-flat'] ), 4.5 ),
					'accent' => array( Color::contrast( $p['--ak-accent'], $p['--ak-canvas-flat'] ), 3.0 ),
					'knob'   => array( Color::contrast( $p['--ak-knob'], $p['--ak-door-base'] ), 3.0 ),
					'teaser' => array( Color::contrast( $p['--ak-inside-text'], $p['--ak-inside'] ), 4.5 ),
				);

				foreach ( $measures as $name => $m ) {
					if ( $m[0] < $worst[ $name ][0] ) {
						$worst[ $name ] = array( $m[0], $brand . '/' . $scheme );
					}
					if ( $m[0] + 0.005 < $m[1] ) {
						$fails[ $name ][] = $brand . '/' . $scheme . ' = ' . round( $m[0], 2 );
					}
				}
			}
		}
	}
}

printf( "  Info: %d Markenfarben geprüft\n", $tested );
foreach ( array(
	'number' => 'Tagesnummer auf dem Türchen (>= 4,5:1)',
	'text'   => 'Text auf der Kalenderfläche (>= 4,5:1)',
	'accent' => 'Akzent/Fokusring auf der Fläche (>= 3:1)',
	'knob'   => 'Türknauf auf dem Türchen (>= 3:1)',
	'teaser' => 'Vorschautext hinter dem Türchen (>= 4,5:1)',
) as $name => $label ) {
	printf( "  Info: schlechtester Wert %s: %.2f:1 (%s)\n", $name, $worst[ $name ][0], $worst[ $name ][1] );
	check(
		$label,
		empty( $fails[ $name ] ),
		count( $fails[ $name ] ) . ' Verstöße, z. B. ' . implode( '; ', array_slice( $fails[ $name ], 0, 3 ) )
	);
}

echo "\n== Palette: Helligkeitsschemata ==\n";
$light = Color::palette( '#0057b8', 'light' );
$dark  = Color::palette( '#0057b8', 'dark' );
check( 'helles Schema hat helle Fläche', Color::luminance( $light['--ak-canvas-flat'] ) > 0.6 );
check( 'dunkles Schema hat dunkle Fläche', Color::luminance( $dark['--ak-canvas-flat'] ) < 0.1 );
check( 'unbekanntes Schema -> hell', Color::palette( '#0057b8', 'quatsch' ) === $light );
check( 'ungültige Farbe -> Rückfallpalette', Color::palette( 'unsinn' ) === Color::palette( Color::FALLBACK ) );

echo "\n== Bericht für die Adminvorschau ==\n";
$report = Color::report( '#0057b8', 'light' );
check( 'Farbe normalisiert',   '#0057b8' === $report['color'] );
check( 'Schema übernommen',    'light' === $report['scheme'] );
check( 'fünf Kontrastprüfungen', 5 === count( $report['contrast'] ) );
check( 'alle bestehen',        5 === count( array_filter( $report['contrast'], function ( $c ) { return $c['passes']; } ) ) );
check( 'Verhältnis gerundet',  $report['contrast']['number_on_door']['ratio'] === round( $report['contrast']['number_on_door']['ratio'], 2 ) );
$report_dark = Color::report( '#ffe000', 'dark' );
check( 'auch gelb/dunkel besteht', 5 === count( array_filter( $report_dark['contrast'], function ( $c ) { return $c['passes']; } ) ) );

printf( "\n%d bestanden, %d fehlgeschlagen\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
