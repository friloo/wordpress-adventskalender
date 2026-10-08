<?php
/**
 * Testrunner für die Adventskalender-Logik.
 *
 * Nutzung:  php tests/run.php
 *
 * Die Tests laufen ohne WordPress-Installation gegen schlanke Attrappen
 * (tests/wp-stubs.php, tests/render-stubs.php). Geprüft werden vor allem
 * Datumslogik, Anordnung, Ausgabe-Escaping und die Zusage, dass Inhalte
 * gesperrter Türchen den Server nicht verlassen.
 */

$suites = array(
	'Logik & Einstellungen' => 'test-logic.php',
	'Anordnung der Türchen' => 'test-arrangement.php',
	'Frontend-Ausgabe'      => 'test-render.php',
	'REST-Schnittstelle'    => 'test-rest.php',
);

$php     = defined( 'PHP_BINARY' ) && PHP_BINARY ? PHP_BINARY : 'php';
$total   = 0;
$failed  = 0;
$broken  = array();

foreach ( $suites as $label => $file ) {
	printf( "\n\033[1m=== %s (%s) ===\033[0m\n", $label, $file );

	$output = array();
	$status = 0;
	exec( escapeshellarg( $php ) . ' ' . escapeshellarg( __DIR__ . '/' . $file ) . ' 2>&1', $output, $status );

	$text = implode( "\n", $output );
	echo $text . "\n";

	if ( preg_match( '/(\d+) bestanden, (\d+) fehlgeschlagen/', $text, $m ) ) {
		$total  += (int) $m[1] + (int) $m[2];
		$failed += (int) $m[2];
	}
	if ( 0 !== $status ) {
		$broken[] = $file;
	}
}

printf( "\n\033[1m=== Gesamt: %d Tests, %d fehlgeschlagen ===\033[0m\n", $total, $failed );
if ( $broken ) {
	printf( "Fehlerhafte Suiten: %s\n", implode( ', ', $broken ) );
}

exit( ( $failed > 0 || $broken ) ? 1 : 0 );
