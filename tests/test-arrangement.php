<?php
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/../includes/class-renderer.php';

use Adventskalender\Renderer;

$pass = 0; $fail = 0;
function check( $label, $actual, $expected ) {
	global $pass, $fail;
	$ok = $actual === $expected;
	$ok ? $pass++ : $fail++;
	printf( "%s  %s%s\n", $ok ? '  OK ' : 'FAIL', $label,
		$ok ? '' : sprintf( '  (erwartet %s, erhalten %s)', var_export( $expected, true ), var_export( $actual, true ) ) );
}

echo "== Anordnung ==\n";
$a = Renderer::arrangement( 24, 2026, true, 1234 );
$sorted = $a; sort( $sorted );
check( 'genau 24 Türchen',          count( $a ), 24 );
check( 'vollständige Permutation',  $sorted, range( 1, 24 ) );
check( 'keine Duplikate',           count( array_unique( $a ) ), 24 );
check( 'tatsächlich gemischt',      $a === range( 1, 24 ), false );

check( 'stabil bei gleichem Seed',  Renderer::arrangement( 24, 2026, true, 1234 ), $a );
check( 'anders bei anderem Seed',   Renderer::arrangement( 24, 2026, true, 999 ) === $a, false );
check( 'anders bei anderem Jahr',   Renderer::arrangement( 24, 2027, true, 1234 ) === $a, false );
check( 'ohne Mischen sortiert',     Renderer::arrangement( 24, 2026, false, 1234 ), range( 1, 24 ) );

echo "\n== Randfälle ==\n";
check( '1 Türchen',  Renderer::arrangement( 1, 2026, true, 7 ), array( 1 ) );
check( '0 -> 1 Türchen', Renderer::arrangement( 0, 2026, true, 7 ), array( 1 ) );
$b = Renderer::arrangement( 31, 2026, true, 42 );
$bs = $b; sort( $bs );
check( '31 Türchen vollständig', $bs, range( 1, 31 ) );

echo "\n== Verteilungsqualität über viele Seeds ==\n";
// Kein Tag darf durch den Generator systematisch auf derselben Position landen.
$positions = array_fill( 1, 24, array() );
for ( $seed = 1; $seed <= 400; $seed++ ) {
	foreach ( Renderer::arrangement( 24, 2026, true, $seed ) as $pos => $day ) {
		$positions[ $day ][] = $pos;
	}
}
$worst_unique = 24;
$fixed = 0;
foreach ( $positions as $day => $list ) {
	$unique = count( array_unique( $list ) );
	$worst_unique = min( $worst_unique, $unique );
	if ( 1 === $unique ) { $fixed++; }
}
printf( "  Info: geringste Positionsvielfalt eines Tages über 400 Seeds: %d\n", $worst_unique );
check( 'kein Tag klebt an einer Position', $fixed, 0 );
check( 'gute Streuung (>=15 Positionen)',  $worst_unique >= 15, true );

// Seed 0 darf nicht in einer Endlosschleife oder trivialen Folge enden.
$z = Renderer::arrangement( 24, 2026, true, 0 );
$zs = $z; sort( $zs );
check( 'Seed 0 bleibt gültig', $zs, range( 1, 24 ) );

printf( "\n%d bestanden, %d fehlgeschlagen\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
