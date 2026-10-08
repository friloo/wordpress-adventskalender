<?php
require __DIR__ . '/render-stubs.php';

use Adventskalender\Settings;
use Adventskalender\Renderer;
use Adventskalender\Content;

$pass = 0; $fail = 0;
function check( $label, $ok, $info = '' ) {
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	printf( "%s  %s%s\n", $ok ? '  OK ' : 'FAIL', $label, $ok ? '' : "  $info" );
}

// --- Testdaten: 24 Türchen mit sprechenden Geheimnissen -------------------
$GLOBALS['ak_doors'] = array();
for ( $d = 1; $d <= 24; $d++ ) {
	$post = new WP_Post( array(
		'ID'           => 100 + $d,
		'post_title'   => "Titel-Tag-$d",
		'post_content' => "Fließtext für Tag $d.",
	) );
	$GLOBALS['ak_doors'][ $d ] = $post;
	$GLOBALS['ak_meta'][ 100 + $d ] = array(
		'_ak_day'           => $d,
		'_ak_year'          => 2026,
		'_ak_media_type'    => 'image',
		'_ak_image'         => 11,
		'_ak_preview_text'  => "GEHEIMNIS-$d",
		'_ak_preview_image' => 12,
		'_ak_layout'        => 'media_top',
	);
}

$GLOBALS['ak_now'] = '2026-12-05 10:00:00';
update_option( Settings::OPTION, array_merge( Settings::defaults(), array(
	'year' => 2026, 'door_count' => 24, 'columns' => 6, 'layout' => 'classic',
	'theme' => 'elegant', 'shuffle' => 1, 'shuffle_seed' => 4242,
) ) );

$html = Renderer::shortcode( array() );

echo "== Struktur ==\n";
check( '24 Türchen gerendert', 24 === substr_count( $html, 'class="ak-door ' ), substr_count( $html, 'class="ak-door ' ) . ' gefunden' );
check( 'Raster-Container vorhanden', false !== strpos( $html, 'ak-grid-wrap' ) );
check( 'Lightbox vorhanden', false !== strpos( $html, 'data-ak-lightbox' ) );
check( 'Toast vorhanden', false !== strpos( $html, 'data-ak-toast' ) );
check( 'aria-modal gesetzt', false !== strpos( $html, 'aria-modal="true"' ) );
check( 'aria-haspopup gesetzt', 24 === substr_count( $html, 'aria-haspopup="dialog"' ) );
check( 'Theme-Klasse gesetzt', false !== strpos( $html, 'ak-calendar--theme-elegant' ) );
check( 'Spalten als CSS-Variable', false !== strpos( $html, '--ak-cols:6' ) );

$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
// libxml kennt als HTML4-Parser keine SVG-Elemente – das ist kein Fehler.
$errors = array_filter( libxml_get_errors(), function ( $e ) {
	if ( $e->level < LIBXML_ERR_ERROR ) { return false; }
	return ! preg_match( '/Tag (svg|rect|path|circle|line|polyline|polygon|g|defs|use) invalid/', $e->message );
} );
libxml_clear_errors();
check( 'HTML parst ohne Fehler', empty( $errors ), count( $errors ) . ' Fehler' );

echo "\n== SICHERHEIT: gesperrte Inhalte dürfen nicht im HTML stehen ==\n";
for ( $d = 1; $d <= 5; $d++ ) {
	check( "Tag $d (offen) zeigt Vorschau", false !== strpos( $html, "GEHEIMNIS-$d" ) );
}
$leaked = array();
for ( $d = 6; $d <= 24; $d++ ) {
	if ( false !== strpos( $html, "GEHEIMNIS-$d" ) ) { $leaked[] = $d; }
}
check( 'kein Vorschautext gesperrter Tage im HTML', empty( $leaked ), 'durchgesickert: ' . implode( ',', $leaked ) );

$leaked_titles = array();
for ( $d = 1; $d <= 24; $d++ ) {
	if ( false !== strpos( $html, "Titel-Tag-$d" ) ) { $leaked_titles[] = $d; }
}
check( 'keine Titel im Raster-HTML', empty( $leaked_titles ), 'durchgesickert: ' . implode( ',', $leaked_titles ) );

$leaked_body = array();
for ( $d = 1; $d <= 24; $d++ ) {
	if ( false !== strpos( $html, "Fließtext für Tag $d." ) ) { $leaked_body[] = $d; }
}
check( 'keine Fließtexte im Raster-HTML', empty( $leaked_body ), 'durchgesickert: ' . implode( ',', $leaked_body ) );

echo "\n== Zustände ==\n";
check( '5 offene Türchen', 5 === substr_count( $html, 'data-state="closed"' ), substr_count( $html, 'data-state="closed"' ) . ' gefunden' );
check( '19 gesperrte Türchen', 19 === substr_count( $html, 'data-state="locked"' ), substr_count( $html, 'data-state="locked"' ) . ' gefunden' );
check( 'Schloss-Symbol bei gesperrten', 19 === substr_count( $html, 'ak-door__lock' ) );
check( 'Hinweis am gesperrten Türchen', false !== strpos( $html, 'data-notice="Dieses Türchen öffnet erst am 6. Dezember 2026."' ) );
check( 'aria-disabled bei gesperrten', 19 === substr_count( $html, 'aria-disabled="true"' ) );

echo "\n== Leeres Türchen ==\n";
$GLOBALS['ak_meta'][103]['_ak_media_type'] = 'none';
$GLOBALS['ak_doors'][3]->post_content = '';
$html2 = Renderer::shortcode( array() );
check( 'leerer Tag erhält Status empty', false !== strpos( $html2, 'data-state="empty"' ) );
$GLOBALS['ak_meta'][103]['_ak_media_type'] = 'image';
$GLOBALS['ak_doors'][3]->post_content = 'Fließtext für Tag 3.';

echo "\n== Fehlendes Türchen (Tag nicht angelegt) ==\n";
$backup = $GLOBALS['ak_doors'][2];
unset( $GLOBALS['ak_doors'][2] );
$html3 = Renderer::shortcode( array() );
check( '24 Türchen bleiben im Raster', 24 === substr_count( $html3, 'class="ak-door ' ) );
check( 'fehlender Tag ist empty', false !== strpos( $html3, 'data-state="empty"' ) );
$GLOBALS['ak_doors'][2] = $backup;

echo "\n== Mosaik-Layout ==\n";
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'layout' => 'mosaic', 'mosaic_image' => 11 ) ) );
$mosaic = Renderer::shortcode( array() );
check( 'Mosaik-Bild am Wrapper', false !== strpos( $mosaic, 'data-mosaic=' ) );
check( 'background-size berechnet', false !== strpos( $mosaic, 'background-size:600% 400%' ) );
check( 'linke obere Ecke bei 0% 0%', false !== strpos( $mosaic, 'background-position:0% 0%' ) );
check( 'rechte untere Ecke bei 100% 100%', false !== strpos( $mosaic, 'background-position:100% 100%' ) );

echo "\n== Mosaik ohne Bild fällt zurück ==\n";
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'layout' => 'mosaic', 'mosaic_image' => 0 ) ) );
$fallback = Renderer::shortcode( array() );
check( 'Rückfall auf classic', false !== strpos( $fallback, 'ak-calendar--classic' ) );
check( 'kein data-mosaic', false === strpos( $fallback, 'data-mosaic=' ) );

echo "\n== Testmodus ==\n";
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'layout' => 'classic', 'test_mode' => 1 ) ) );
$test = Renderer::shortcode( array() );
check( 'alle 24 offen', 24 === substr_count( $test, 'data-state="closed"' ), substr_count( $test, 'data-state="closed"' ) . ' gefunden' );
check( 'Hinweisbanner sichtbar', false !== strpos( $test, 'ak-notice--test' ) );
check( 'alle Vorschauen vorhanden', false !== strpos( $test, 'GEHEIMNIS-24' ) );

echo "\n== Shortcode-Attribute werden geprüft ==\n";
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'test_mode' => 0 ) ) );
$evil = Renderer::shortcode( array( 'layout' => '"><script>alert(1)</script>', 'theme' => 'nordic"', 'columns' => '999', 'year' => '1' ) );
check( 'kein <script> in der Ausgabe', false === strpos( $evil, '<script' ) );
check( 'ungültiges Layout verworfen', false !== strpos( $evil, 'ak-calendar--classic' ) );
check( 'ungültiges Theme verworfen', false === strpos( $evil, 'nordic&quot;' ) && false !== strpos( $evil, 'ak-calendar--theme-elegant' ) );
check( 'Spalten auf Einstellung zurück', false !== strpos( $evil, '--ak-cols:6' ) );

echo "\n== XSS in Inhalten ==\n";
$GLOBALS['ak_meta'][101]['_ak_preview_text'] = '<img src=x onerror=alert(1)>"><b>';
$xss = Renderer::shortcode( array() );
check( 'Vorschautext escaped', false === strpos( $xss, '<img src=x onerror' ) );
check( 'escaped als Entities', false !== strpos( $xss, '&lt;img src=x onerror' ) );

echo "\n== Lightbox-Inhalt (nur serverseitig nach Prüfung) ==\n";
$GLOBALS['ak_meta'][101]['_ak_preview_text'] = 'GEHEIMNIS-1';
$body = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'Medium enthalten', false !== strpos( $body, 'ak-lightbox__media' ) );
check( 'Text enthalten', false !== strpos( $body, 'Fließtext für Tag 1.' ) );
check( 'Layout-Klasse gesetzt', false !== strpos( $body, 'ak-lightbox__body--media_top' ) );

$GLOBALS['ak_meta'][101]['_ak_layout'] = 'text_only';
$text_only = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'text_only ohne Medium', false === strpos( $text_only, 'ak-lightbox__media' ) );

$GLOBALS['ak_meta'][101]['_ak_layout'] = 'media_only';
$media_only = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'media_only ohne Text', false === strpos( $media_only, 'ak-lightbox__text' ) );

$GLOBALS['ak_meta'][101]['_ak_layout'] = 'media_top';
$GLOBALS['ak_meta'][101]['_ak_media_type'] = 'video';
$GLOBALS['ak_meta'][101]['_ak_video_source'] = 'embed';
$GLOBALS['ak_meta'][101]['_ak_video_url'] = 'https://www.youtube.com/watch?v=abc';
$video = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'Video als responsive Einbettung', false !== strpos( $video, 'ak-embed' ) );

$GLOBALS['ak_meta'][101]['_ak_video_url'] = 'javascript:alert(1)';
$bad_video = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'javascript: URL abgelehnt', false === strpos( $bad_video, 'javascript:' ) );

$GLOBALS['ak_meta'][101]['_ak_video_url'] = 'https://cdn.example.test/film.mp4';
$file_video = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'MP4-URL als <video>', false !== strpos( $file_video, '<video class="ak-media__video"' ) );

$GLOBALS['ak_meta'][101]['_ak_media_type'] = 'image';
$GLOBALS['ak_meta'][101]['_ak_link_url'] = 'https://fremde-seite.test/aktion';
$GLOBALS['ak_meta'][101]['_ak_link_label'] = 'Zur Aktion';
$cta = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'externer Link mit noopener', false !== strpos( $cta, 'rel="noopener noreferrer"' ) );
$GLOBALS['ak_meta'][101]['_ak_link_url'] = 'https://example.test/intern';
$cta2 = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'interner Link ohne target', false === strpos( $cta2, 'target="_blank"' ) );

printf( "\n%d bestanden, %d fehlgeschlagen\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
