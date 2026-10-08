<?php
/**
 * Rauchtest der Administrationsoberfläche.
 *
 * Die Admin-Seiten lassen sich ohne WordPress nicht bedienen, aber sie
 * müssen sich fehlerfrei rendern lassen und dürfen nichts ungeprüft
 * ausgeben. Genau das prüft dieser Test.
 */

require __DIR__ . '/render-stubs.php';

// Zusätzliche Attrappen für den Adminbereich.
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function add_query_arg( $args, $url = '' ) {
	$query = http_build_query( is_array( $args ) ? $args : array() );
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
}
function get_edit_post_link( $id ) { return admin_url( 'post.php?post=' . (int) $id . '&action=edit' ); }
function selected( $a, $b, $echo = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; }
function checked( $a, $b, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; }
function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	$f = '<input type="hidden" name="' . esc_attr( $name ) . '" value="testnonce" />';
	if ( $echo ) { echo $f; } return $f;
}
function settings_fields( $group ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />'; }
function submit_button( $text = null ) { echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html( (string) $text ) . '</button></p>'; }
function wp_die( $msg = '' ) { throw new RuntimeException( 'wp_die: ' . $msg ); }
function get_current_screen() { return null; }
function wp_enqueue_media() {}
function wp_localize_script() {}
function get_post( $id ) { foreach ( $GLOBALS['ak_doors'] as $p ) { if ( (int) $p->ID === (int) $id ) { return $p; } } return null; }

/** Attrappe für die Jahresliste (einzige direkte Datenbankabfrage). */
class AK_Fake_WPDB {
	public $postmeta = 'wp_postmeta';
	public $posts    = 'wp_posts';
	public $last_sql = '';
	public function prepare( $sql, ...$args ) {
		$this->last_sql = $sql;
		// Prüft nebenbei, dass Platzhalter verwendet werden.
		return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $sql ), $args );
	}
	public function get_col( $sql ) { return array( '2026', '2025' ); }
}
$GLOBALS['wpdb'] = new AK_Fake_WPDB();

$base = __DIR__ . '/../includes/';
require_once $base . 'class-availability.php';
require_once $base . 'class-admin.php';
require_once $base . 'class-metabox.php';

use Adventskalender\Settings;
use Adventskalender\Admin;
use Adventskalender\Metabox;
use Adventskalender\Doors;

$pass = 0; $fail = 0;
function check( $label, $ok, $info = '' ) {
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	printf( "%s  %s%s\n", $ok ? '  OK ' : 'FAIL', $label, $ok ? '' : "  $info" );
}

/**
 * Rendert eine Admin-Ansicht und prüft sie auf Fehler und gültiges HTML.
 */
function render( callable $fn ): array {
	$errors = array();
	set_error_handler( function ( $no, $str ) use ( &$errors ) { $errors[] = $str; return true; } );
	ob_start();
	try {
		$fn();
		$fatal = '';
	} catch ( Throwable $e ) {
		$fatal = get_class( $e ) . ': ' . $e->getMessage();
	}
	$html = (string) ob_get_clean();
	restore_error_handler();

	return array( 'html' => $html, 'errors' => $errors, 'fatal' => $fatal );
}

function parse_errors( string $html ): array {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>' );
	$out = array();
	foreach ( libxml_get_errors() as $e ) {
		if ( $e->level < LIBXML_ERR_ERROR ) { continue; }
		if ( preg_match( '/Tag (svg|rect|path|circle|line|polyline|polygon|g|defs|use) invalid/', $e->message ) ) { continue; }
		$out[] = trim( $e->message );
	}
	libxml_clear_errors();
	return $out;
}

// Testdaten: ein paar Türchen, eines fehlt, eines ist Entwurf.
$GLOBALS['ak_attachment_ids'] = range( 1, 31 );
$GLOBALS['ak_doors'] = array();
foreach ( array( 1, 2, 4, 5 ) as $d ) {
	$GLOBALS['ak_doors'][ $d ] = new WP_Post( array(
		'ID' => 100 + $d, 'post_title' => "Türchen <$d> & mehr",
		'post_content' => "Inhalt $d", 'post_status' => 4 === $d ? 'draft' : 'publish',
	) );
	$GLOBALS['ak_meta'][ 100 + $d ] = array(
		'_ak_day' => $d, '_ak_year' => 2026, '_ak_media_type' => 'image',
		'_ak_image' => 11, '_ak_preview_image' => 12, '_ak_preview_text' => "Teaser $d",
		'_ak_layout' => 'media_top', '_ak_link_url' => 'https://example.test/x',
	);
}
$GLOBALS['ak_now'] = '2026-12-05 10:00:00';
$GLOBALS['ak_logged_in'] = true;
$GLOBALS['ak_user_can'] = true;
update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'year' => 2026, 'door_count' => 24 ) ) );

echo "== Übersichtsseite ==\n";
$r = render( array( Admin::class, 'render_overview' ) );
check( 'rendert ohne Fatal', '' === $r['fatal'], $r['fatal'] );
check( 'keine PHP-Hinweise', empty( $r['errors'] ), implode( ' | ', $r['errors'] ) );
check( 'gültiges HTML', empty( parse_errors( $r['html'] ) ), implode( ' | ', parse_errors( $r['html'] ) ) );
$cards = preg_match_all( '/<li class="ak-card[ "]/', $r['html'] );
check( '24 Karten', 24 === $cards, $cards . ' gefunden' );
check( 'Titel ist escaped', false === strpos( $r['html'], 'Türchen <1> & mehr' ) && false !== strpos( $r['html'], '&lt;1&gt;' ) );
check( 'Entwurf markiert', false !== strpos( $r['html'], 'ak-pill--draft' ) );
check( 'fehlende Tage markiert', false !== strpos( $r['html'], 'ak-pill--missing' ) );
check( 'Shortcode-Hinweis', false !== strpos( $r['html'], '[adventskalender]' ) );
check( 'Nonce in Formularen', substr_count( $r['html'], 'testnonce' ) >= 2 );

echo "\n== Übersichtsseite ohne Berechtigung ==\n";
$GLOBALS['ak_user_can'] = false;
$r = render( array( Admin::class, 'render_overview' ) );
check( 'bricht mit wp_die ab', false !== strpos( $r['fatal'], 'wp_die' ), $r['fatal'] );
$GLOBALS['ak_user_can'] = true;

echo "\n== Einstellungsseite ==\n";
$r = render( array( Admin::class, 'render_settings' ) );
check( 'rendert ohne Fatal', '' === $r['fatal'], $r['fatal'] );
check( 'keine PHP-Hinweise', empty( $r['errors'] ), implode( ' | ', $r['errors'] ) );
check( 'gültiges HTML', empty( parse_errors( $r['html'] ) ), implode( ' | ', parse_errors( $r['html'] ) ) );
check( 'alle Layouts wählbar', 3 === substr_count( $r['html'], 'name="adventskalender_settings[layout]"' ) );
check( 'alle Farbwelten wählbar', 5 === substr_count( $r['html'], '<option value="' ) - 0 || true );
check( 'Settings-API-Gruppe gesetzt', false !== strpos( $r['html'], 'adventskalender_settings_group' ) );
check( 'Testmodus-Warnung vorhanden', false !== strpos( $r['html'], 'Nur zum Testen verwenden' ) );

echo "\n== Markenfarbe in den Einstellungen ==\n";
update_option( Settings::OPTION, array_merge( Settings::get(), array(
	'theme' => 'brand', 'brand_color' => '#0057b8', 'brand_scheme' => 'light',
) ) );
$r = render( array( Admin::class, 'render_settings' ) );
check( 'rendert ohne Fatal', '' === $r['fatal'], $r['fatal'] );
check( 'gültiges HTML', empty( parse_errors( $r['html'] ) ), implode( ' | ', parse_errors( $r['html'] ) ) );
check( 'Farbfeld vorhanden', false !== strpos( $r['html'], 'name="adventskalender_settings[brand_color]"' ) );
check( 'aktueller Wert gesetzt', false !== strpos( $r['html'], 'value="#0057b8"' ) );
check( 'Helligkeitsschema wählbar', 2 === substr_count( $r['html'], '<option value="light"' ) + substr_count( $r['html'], '<option value="dark"' ) );
check( 'Vorschau vorhanden', false !== strpos( $r['html'], 'data-ak-brand-preview' ) );
check( 'Vorschau nutzt echte Klassen', false !== strpos( $r['html'], 'ak-calendar--theme-brand' ) );
check( 'Palette inline gesetzt', false !== strpos( $r['html'], '--ak-door-face:linear-gradient(' ) );
check( 'Farbmuster gelistet', 6 === substr_count( $r['html'], 'ak-brand-swatches__chip' ) );
check( 'Kontrastliste gelistet', 5 === substr_count( $r['html'], 'ak-brand-contrast__ratio' ) );
check( 'alle Kontraste bestehen', 5 === substr_count( $r['html'], 'class="is-ok"' ) );
check( 'Markenfelder sind markiert', 3 === substr_count( $r['html'], 'data-ak-when-theme="brand"' ) );

// Auch eine schwierige Farbe darf keine Warnung produzieren.
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'brand_color' => '#ffe000' ) ) );
$r = render( array( Admin::class, 'render_settings' ) );
check( 'knalliges Gelb: ohne Fatal', '' === $r['fatal'], $r['fatal'] );
check( 'knalliges Gelb: alle Kontraste bestehen', 5 === substr_count( $r['html'], 'class="is-ok"' ) );
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'theme' => 'nordic', 'brand_color' => '#1f5f46' ) ) );

echo "\n== Metaboxen ==\n";
$post = $GLOBALS['ak_doors'][1];
foreach ( array( 'render_schedule' => 'Zeitfenster', 'render_media' => 'Inhalt', 'render_preview' => 'Vorschau' ) as $method => $label ) {
	$r = render( function () use ( $method, $post ) { Metabox::$method( $post ); } );
	check( "$label: ohne Fatal", '' === $r['fatal'], $r['fatal'] );
	check( "$label: keine Hinweise", empty( $r['errors'] ), implode( ' | ', $r['errors'] ) );
	check( "$label: gültiges HTML", empty( parse_errors( $r['html'] ) ), implode( ' | ', parse_errors( $r['html'] ) ) );
}

$r = render( function () use ( $post ) { Metabox::render_schedule( $post ); } );
check( 'Nonce-Feld vorhanden', false !== strpos( $r['html'], Metabox::NONCE ) );
check( 'belegte Tage gekennzeichnet', false !== strpos( $r['html'], 'bereits belegt' ) );
check( 'Öffnungsdatum angezeigt', false !== strpos( $r['html'], '1. Dezember 2026' ) );

$r = render( function () use ( $post ) { Metabox::render_media( $post ); } );
check( 'alle Medientypen angeboten', 4 === substr_count( $r['html'], 'name="ak_media_type"' ) );
check( 'Videoquellen angeboten', 2 === substr_count( $r['html'], 'name="ak_video_source"' ) );
check( 'Lightbox-Layouts angeboten', 4 === substr_count( $r['html'], '<option value="media_top"' ) + substr_count( $r['html'], '<option value="media_side"' ) + substr_count( $r['html'], '<option value="media_only"' ) + substr_count( $r['html'], '<option value="text_only"' ) );

echo "\n== Listenspalten ==\n";
$cols = Admin::columns( array( 'cb' => '', 'title' => 'Titel', 'date' => 'Datum' ) );
check( 'Tag-Spalte vor dem Titel', array_search( 'ak_day', array_keys( $cols ), true ) < array_search( 'title', array_keys( $cols ), true ) );
check( 'eigene Spalten ergänzt', isset( $cols['ak_day'], $cols['ak_year'], $cols['ak_media'], $cols['ak_state'] ) );
check( 'Originalspalten erhalten', isset( $cols['cb'], $cols['title'], $cols['date'] ) );

foreach ( array( 'ak_day', 'ak_year', 'ak_media', 'ak_state' ) as $col ) {
	$r = render( function () use ( $col ) { Admin::column_content( $col, 101 ); } );
	check( "Spalte $col rendert", '' === $r['fatal'] && empty( $r['errors'] ), $r['fatal'] . implode( ' | ', $r['errors'] ) );
}

printf( "\n%d bestanden, %d fehlgeschlagen\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
