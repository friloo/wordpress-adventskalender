<?php
require __DIR__ . '/wp-stubs.php';

use Adventskalender\Settings;
use Adventskalender\Availability;

$pass = 0; $fail = 0;
function check( $label, $actual, $expected ) {
	global $pass, $fail;
	$ok = $actual === $expected;
	$ok ? $pass++ : $fail++;
	printf( "%s  %s%s\n", $ok ? '  OK ' : 'FAIL', $label,
		$ok ? '' : sprintf( "  (erwartet: %s, erhalten: %s)", var_export( $expected, true ), var_export( $actual, true ) ) );
}

echo "== Verfügbarkeit: 5. Dezember 2026, 08:30 Uhr (Europe/Berlin) ==\n";
update_option( Settings::OPTION, array( 'year' => 2026, 'door_count' => 24 ) );
check( 'Tag 1 offen',            Availability::is_unlocked( 1 ), true );
check( 'Tag 5 offen (heute)',    Availability::is_unlocked( 5 ), true );
check( 'Tag 6 gesperrt',         Availability::is_unlocked( 6 ), false );
check( 'Tag 24 gesperrt',        Availability::is_unlocked( 24 ), false );
check( 'Tag 25 ungültig',        Availability::is_unlocked( 25 ), false );
check( 'Tag 0 ungültig',         Availability::is_unlocked( 0 ), false );
check( 'offene Tage',            Availability::unlocked_days(), array( 1, 2, 3, 4, 5 ) );
check( 'Grund für Tag 5',        Availability::reason( 5 ), Availability::REASON_DATE );
check( 'Grund für Tag 6',        Availability::reason( 6 ), Availability::REASON_LOCKED );

echo "\n== Mitternachtsgrenze ==\n";
$GLOBALS['ak_now'] = '2026-12-06 00:00:00';
check( 'Tag 6 genau um 00:00 offen', Availability::is_unlocked( 6 ), true );
$GLOBALS['ak_now'] = '2026-12-05 23:59:59';
check( 'Tag 6 um 23:59:59 gesperrt', Availability::is_unlocked( 6 ), false );

echo "\n== Zeitzone wirkt ==\n";
$GLOBALS['ak_now'] = '2026-12-06 00:30:00';
$GLOBALS['ak_tz']  = 'Pacific/Kiritimati'; // UTC+14
check( 'Tag 6 in UTC+14 offen', Availability::is_unlocked( 6 ), true );
$GLOBALS['ak_tz']  = 'Europe/Berlin';

echo "\n== Vor dem 1. Dezember ==\n";
$GLOBALS['ak_now'] = '2026-11-30 23:00:00';
check( 'Tag 1 noch gesperrt', Availability::is_unlocked( 1 ), false );
check( 'keine offenen Tage',  Availability::unlocked_days(), array() );

echo "\n== Nach dem 24. Dezember ==\n";
$GLOBALS['ak_now'] = '2026-12-27 10:00:00';
check( 'alle 24 offen', count( Availability::unlocked_days() ), 24 );

echo "\n== Zukünftiges Jahr bleibt dicht ==\n";
$GLOBALS['ak_now'] = '2026-12-27 10:00:00';
update_option( Settings::OPTION, array( 'year' => 2027, 'door_count' => 24 ) );
check( 'Tag 1 von 2027 gesperrt', Availability::is_unlocked( 1 ), false );

echo "\n== Testmodus ==\n";
update_option( Settings::OPTION, array( 'year' => 2027, 'door_count' => 24, 'test_mode' => 1 ) );
check( 'Testmodus öffnet alles', count( Availability::unlocked_days() ), 24 );
check( 'Grund = test_mode',      Availability::reason( 24 ), Availability::REASON_TEST );
check( 'Tag 25 bleibt ungültig', Availability::is_unlocked( 25 ), false );

echo "\n== Redaktionsvorschau ==\n";
update_option( Settings::OPTION, array( 'year' => 2027, 'door_count' => 24, 'editor_preview' => 1 ) );
$GLOBALS['ak_logged_in'] = true; $GLOBALS['ak_user_can'] = true;
check( 'Redakteur sieht alles',  count( Availability::unlocked_days() ), 24 );
check( 'Grund = preview',        Availability::reason( 24 ), Availability::REASON_PREVIEW );
$GLOBALS['ak_logged_in'] = false; $GLOBALS['ak_user_can'] = false;
check( 'Besucher sieht nichts',  count( Availability::unlocked_days() ), 0 );

echo "\n== Vorschau abgeschaltet ==\n";
update_option( Settings::OPTION, array( 'year' => 2027, 'door_count' => 24, 'editor_preview' => 0 ) );
$GLOBALS['ak_logged_in'] = true; $GLOBALS['ak_user_can'] = true;
check( 'Redakteur sieht nichts', count( Availability::unlocked_days() ), 0 );
$GLOBALS['ak_logged_in'] = false; $GLOBALS['ak_user_can'] = false;

echo "\n== Anzahl Türchen konfigurierbar ==\n";
$GLOBALS['ak_now'] = '2026-12-31 12:00:00';
update_option( Settings::OPTION, array( 'year' => 2026, 'door_count' => 31 ) );
check( '31 Türchen offen', count( Availability::unlocked_days() ), 31 );

echo "\n== Hinweistext ==\n";
update_option( Settings::OPTION, array( 'year' => 2026, 'locked_notice' => 'Öffnet am {datum} (Tag {tag}).' ) );
check( 'Platzhalter ersetzt', Availability::locked_notice( 6, 2026 ), 'Öffnet am 6. Dezember 2026 (Tag 6).' );

echo "\n== Einstellungen bereinigen ==\n";
update_option( Settings::OPTION, Settings::defaults() );
$in = array(
	'year' => '2026', 'door_count' => '99', 'columns' => '99',
	'layout' => '../../evil', 'theme' => '<script>', 'test_mode' => '1',
	'heading' => "  Mein <b>Kalender</b>  ", 'locked_notice' => '   ',
	'mosaic_image' => '11', 'background_image' => '999',
);
$out = Settings::sanitize( $in );
check( 'Jahr übernommen',         $out['year'], 2026 );
check( 'Türchenzahl gekappt',     $out['door_count'], 24 );
check( 'Spalten gekappt',         $out['columns'], 6 );
check( 'Layout auf Allowlist',    $out['layout'], 'classic' );
check( 'Theme auf Allowlist',     $out['theme'], 'nordic' );
check( 'Testmodus gesetzt',       $out['test_mode'], 1 );
check( 'Checkbox fehlt = 0',      $out['snow'], 0 );
check( 'Überschrift bereinigt',   $out['heading'], 'Mein Kalender' );
check( 'Leerer Hinweis -> Default', $out['locked_notice'], Settings::defaults()['locked_notice'] );
check( 'echte Anhang-ID bleibt',  $out['mosaic_image'], 11 );
check( 'erfundene ID verworfen',  $out['background_image'], 0 );
check( 'nicht-Array -> unverändert', Settings::sanitize( 'kaputt' ), Settings::get() );

printf( "\n%d bestanden, %d fehlgeschlagen\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
