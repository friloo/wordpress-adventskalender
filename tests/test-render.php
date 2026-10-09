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

echo "\n== Vorschau: erstes Bild aus dem Beitrag ==\n";
$bild_post = new WP_Post( array( 'ID' => 500, 'post_title' => 'Mit Bild', 'post_content' =>
	'<p>Text davor.</p><figure class="wp-block-image"><img src="https://example.test/x.jpg" class="wp-image-11" alt=""/></figure>' ) );
check( 'Mediathek-Bild über wp-image erkannt', 11 === Content::first_content_image( $bild_post )['id'] );

$extern_post = new WP_Post( array( 'ID' => 501, 'post_title' => 'Extern', 'post_content' =>
	'<p>Text</p><img src="https://cdn.example.test/foto.jpg" alt="" />' ) );
$extern = Content::first_content_image( $extern_post );
check( 'externes Bild über die Adresse erkannt', 0 === $extern['id'] && 'https://cdn.example.test/foto.jpg' === $extern['url'] );

$ohne_post = new WP_Post( array( 'ID' => 502, 'post_title' => 'Ohne', 'post_content' => '<p>Nur Text.</p>' ) );
check( 'ohne Bild leer', 0 === Content::first_content_image( $ohne_post )['id'] && '' === Content::first_content_image( $ohne_post )['url'] );
check( 'leerer Inhalt leer', 0 === Content::first_content_image( new WP_Post( array( 'ID' => 503 ) ) )['id'] );

// Die Vorschau greift auf das Textbild zurück.
$GLOBALS['ak_meta'][500] = array( '_ak_day' => 1, '_ak_year' => 2026, '_ak_media_type' => 'none' );
check( 'Vorschau nutzt das Textbild', 11 === Content::preview( $bild_post )['image'] );
$GLOBALS['ak_meta'][501] = array( '_ak_day' => 1, '_ak_year' => 2026, '_ak_media_type' => 'none' );
check( 'Vorschau nutzt externe Adresse', 'https://cdn.example.test/foto.jpg' === Content::preview( $extern_post )['url'] );
$vorrang_post = new WP_Post( array( 'ID' => 504, 'post_content' => '<img class="wp-image-11" src="x">' ) );
$GLOBALS['ak_meta'][504] = array( '_ak_day' => 1, '_ak_year' => 2026, '_ak_media_type' => 'none', '_ak_preview_image' => 12 );
check( 'eigenes Vorschaubild hat Vorrang', 12 === Content::preview( $vorrang_post )['image'] );

echo "\n== Vorschau: Videos erkennbar machen ==\n";
$video_post = new WP_Post( array( 'ID' => 510, 'post_title' => 'Video', 'post_content' =>
	'<figure class="wp-block-embed is-type-video wp-block-embed-youtube"><div class="wp-block-embed__wrapper">https://youtu.be/abc</div></figure>' ) );
$GLOBALS['ak_meta'][510] = array( '_ak_day' => 1, '_ak_year' => 2026, '_ak_media_type' => 'none' );
check( 'Video im Text erkannt', true === Content::content_has_video( $video_post ) );
check( 'Vorschau meldet Video', true === Content::preview( $video_post )['video'] );
check( 'Text ohne Video meldet kein Video', false === Content::content_has_video( $ohne_post ) );

$markup = Renderer::render_preview( $video_post );
check( 'Abspielsymbol gerendert', false !== strpos( $markup, 'ak-door__play' ) );
check( 'ohne Bild eigene Fläche', false !== strpos( $markup, 'ak-door__preview--plain' ) );

$GLOBALS['ak_meta'][500]['_ak_media_type'] = 'none';
$mit_bild = Renderer::render_preview( $bild_post );
check( 'mit Bild keine Ersatzfläche', false === strpos( $mit_bild, 'ak-door__preview--plain' ) );
check( 'mit Bild kein Abspielsymbol', false === strpos( $mit_bild, 'ak-door__play' ) );
$extern_markup = Renderer::render_preview( $extern_post );
check( 'externe Adresse wird ausgegeben', false !== strpos( $extern_markup, 'cdn.example.test/foto.jpg' ) );

echo "\n== Teaser aus dem Inhalt ==\n";
$url_post = new WP_Post( array( 'ID' => 520, 'post_content' =>
	"<!-- wp:embed {\"url\":\"https://youtu.be/abc\"} -->\n"
	. '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://youtu.be/abc</div></figure>'
	. "\n<!-- /wp:embed -->" ) );
check( 'nackte Adresse wird kein Teaser', '' === Content::teaser_from_content( $url_post ) );
check( 'Blockkommentare landen nicht im Teaser', false === strpos( Content::teaser_from_content( $url_post ), 'wp:embed' ) );

$misch_post = new WP_Post( array( 'ID' => 521, 'post_content' =>
	"<!-- wp:paragraph --><p>Ein echter Satz.</p><!-- /wp:paragraph -->"
	. '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://youtu.be/abc</div></figure>' ) );
check( 'echter Text bleibt Teaser', 'Ein echter Satz.' === Content::teaser_from_content( $misch_post ) );
check( 'Adresse aus dem Teaser entfernt', false === strpos( Content::teaser_from_content( $misch_post ), 'youtu.be' ) );
check( 'leerer Inhalt -> leerer Teaser', '' === Content::teaser_from_content( new WP_Post( array( 'ID' => 522 ) ) ) );

$GLOBALS['ak_meta'][520] = array( '_ak_day' => 1, '_ak_year' => 2026, '_ak_media_type' => 'none' );
$url_markup = Renderer::render_preview( $url_post );
check( 'keine Adresse in der Vorschau', false === strpos( $url_markup, 'youtu.be' ) );
check( 'stattdessen Abspielsymbol', false !== strpos( $url_markup, 'ak-door__play' ) );

echo "\n== Videoadresse für das automatische Vorschaubild ==\n";
check( 'YouTube im Text gefunden', 'https://youtu.be/abc' === Content::first_content_video_url( $url_post ) );
check( 'Vimeo im Text gefunden', 'https://vimeo.com/12345' === Content::first_content_video_url(
	new WP_Post( array( 'ID' => 523, 'post_content' => '<p>Text</p><p>https://vimeo.com/12345</p>' ) ) ) );
check( 'ohne Video leer', '' === Content::first_content_video_url( new WP_Post( array( 'ID' => 524, 'post_content' => '<p>Nur Text</p>' ) ) ) );

$GLOBALS['ak_meta'][530] = array(
	'_ak_day' => 1, '_ak_year' => 2026, '_ak_media_type' => 'video',
	'_ak_video_source' => 'embed', '_ak_video_url' => 'https://youtu.be/xyz',
);
$video_typ = new WP_Post( array( 'ID' => 530, 'post_content' => 'Text mit https://vimeo.com/999 darin' ) );
check( 'Medientyp Video hat Vorrang', 'https://youtu.be/xyz' === \Adventskalender\Poster::source_url( $video_typ ) );
$GLOBALS['ak_meta'][530]['_ak_video_source'] = 'file';
check( 'Mediathek-Video braucht keine Abfrage', '' === \Adventskalender\Poster::source_url( $video_typ ) );
$GLOBALS['ak_meta'][520]['_ak_media_type'] = 'none';
check( 'sonst aus dem Text', 'https://youtu.be/abc' === \Adventskalender\Poster::source_url( $url_post ) );

// Das automatisch geholte Bild greift erst nach den eigenen Angaben.
$GLOBALS['ak_meta'][520]['_ak_auto_poster'] = 13;
check( 'automatisches Bild wird genutzt', 13 === Content::preview( $url_post )['image'] );
$GLOBALS['ak_meta'][520]['_ak_preview_image'] = 11;
check( 'eigenes Bild bleibt vorrangig', 11 === Content::preview( $url_post )['image'] );
unset( $GLOBALS['ak_meta'][520]['_ak_preview_image'], $GLOBALS['ak_meta'][520]['_ak_auto_poster'] );

echo "\n== Schrift der Zahlen ==\n";
check( 'Voreinstellung Serif', 'serif' === Settings::defaults()['number_font'] );
check( 'ungültiger Wert -> Serif', 'serif' === Settings::sanitize( array( 'number_font' => 'comic' ) )['number_font'] );
check( 'gültiger Wert bleibt', 'mono' === Settings::sanitize( array( 'number_font' => 'mono' ) )['number_font'] );
check( 'nur Systemschriften', 0 === count( array_filter( Settings::number_fonts(), function ( $f ) {
	return (bool) preg_match( '#https?://|@import|url\(#i', $f['stack'] );
} ) ) );
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'number_font' => 'mono' ) ) );
$font_html = Renderer::shortcode( array() );
check( 'Schrift als CSS-Variable', false !== strpos( $font_html, '--ak-door-number-font:ui-monospace' ) );
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'number_font' => 'serif' ) ) );

echo "\n== Bildgrößen für Mosaik und Hintergrund ==\n";
// Ein großes Foto mit den üblichen WordPress-Zwischengrößen.
$GLOBALS['ak_attachment_meta'][11] = array(
	'width'  => 4032,
	'height' => 3024,
	'sizes'  => array(
		'thumbnail'   => array( 'width' => 150 ),
		'medium'      => array( 'width' => 300 ),
		'medium_large' => array( 'width' => 768 ),
		'large'       => array( 'width' => 1024 ),
		'1536x1536'   => array( 'width' => 1536 ),
		'2048x2048'   => array( 'width' => 2048 ),
	),
);
check( 'kleinste ausreichende Größe gewählt', false !== strpos( Renderer::image_url_for_width( 11, 1800 ), 'bild-11' ) );
$GLOBALS['ak_image_url_cb'] = function ( $id, $size ) { return 'https://example.test/' . $size . '.jpg'; };
check( 'nimmt 2048, nicht das Original', 'https://example.test/2048x2048.jpg' === Renderer::image_url_for_width( 11, 1800 ) );
check( 'nimmt large bei kleinerem Ziel',  'https://example.test/large.jpg' === Renderer::image_url_for_width( 11, 900 ) );
check( 'nimmt thumbnail bei winzigem Ziel', 'https://example.test/thumbnail.jpg' === Renderer::image_url_for_width( 11, 100 ) );
check( 'Original, wenn nichts groß genug', 'https://example.test/full.jpg' === Renderer::image_url_for_width( 11, 5000 ) );

// Bild ohne erzeugte Zwischengrößen.
$GLOBALS['ak_attachment_meta'][12] = array( 'width' => 900, 'height' => 600, 'sizes' => array() );
check( 'ohne Zwischengrößen das Original', 'https://example.test/full.jpg' === Renderer::image_url_for_width( 12, 1800 ) );
check( 'ohne Metadaten das Original',      'https://example.test/full.jpg' === Renderer::image_url_for_width( 13, 1800 ) );
check( 'ungültige ID -> leer',             '' === Renderer::image_url_for_width( 999, 1800 ) );
check( 'ID 0 -> leer',                     '' === Renderer::image_url_for_width( 0, 1800 ) );

// Im Mosaik wird die gewählte Größe auch tatsächlich benutzt.
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'layout' => 'mosaic', 'mosaic_image' => 11 ) ) );
$mosaic_sized = Renderer::shortcode( array() );
check( 'Mosaik nutzt nicht das Original', false === strpos( $mosaic_sized, 'full.jpg' ) );
check( 'Mosaik nutzt die passende Größe', false !== strpos( $mosaic_sized, '2048x2048.jpg' ) );
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'layout' => 'classic', 'mosaic_image' => 0 ) ) );
$GLOBALS['ak_image_url_cb'] = null;

echo "\n== Bericht-Layout (Titelbild, Text, Bilder im Text) ==\n";
check( 'Bericht ist Voreinstellung', 'report' === \Adventskalender\Doors::DEFAULT_LAYOUT );
check( 'unbekanntes Layout -> Bericht', 'report' === \Adventskalender\Doors::sanitize_layout( 'quatsch' ) );

$GLOBALS['ak_meta'][101]['_ak_layout'] = 'report';
$GLOBALS['ak_meta'][101]['_ak_media_type'] = 'image';
$GLOBALS['ak_meta'][101]['_ak_image'] = 11;
$GLOBALS['ak_doors'][1]->post_content = 'Ein kurzer Bericht über den ersten Dezember.';
$bericht = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'Bericht-Klasse gesetzt', false !== strpos( $bericht, 'ak-lightbox__body--report' ) );
check( 'Titelbild vor dem Text', strpos( $bericht, 'ak-lightbox__media' ) < strpos( $bericht, 'ak-lightbox__text' ) );
check( 'kein is-without-lead mit Titelbild', false === strpos( $bericht, 'is-without-lead' ) );

$GLOBALS['ak_meta'][101]['_ak_media_type'] = 'none';
$ohne_bild = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'Bericht ohne Titelbild markiert', false !== strpos( $ohne_bild, 'is-without-lead' ) );
check( 'Text trotzdem vorhanden', false !== strpos( $ohne_bild, 'ak-lightbox__text' ) );
$GLOBALS['ak_meta'][101]['_ak_media_type'] = 'image';

echo "\n== Bilder im Fließtext ==\n";
$GLOBALS['ak_doors'][1]->post_content =
	'<p>Erster Absatz.</p>'
	. '<figure id="attachment_21" class="wp-caption alignright" style="width: 300px">'
	. '<img class="size-medium wp-image-21" src="https://example.test/bild.jpg" width="300" height="200" />'
	. '<figcaption class="wp-caption-text">Eine Bildunterschrift</figcaption></figure>'
	. '<p>Zweiter Absatz, der das Bild umfließt.</p>';
$mit_bildern = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'Ausrichtungsklasse bleibt erhalten', false !== strpos( $mit_bildern, 'alignright' ) );
check( 'Bildunterschrift bleibt erhalten', false !== strpos( $mit_bildern, 'Eine Bildunterschrift' ) );
check( 'figure bleibt erhalten', false !== strpos( $mit_bildern, '<figure' ) );
check( 'Absätze bleiben erhalten', 2 === substr_count( $mit_bildern, '<p>' ) );

echo "\n== Block-Inhalte werden nicht zerlegt ==\n";
$GLOBALS['ak_doors'][1]->post_content =
	"<!-- wp:paragraph -->\n<p>Ein Absatz aus dem Block-Editor.</p>\n<!-- /wp:paragraph -->\n\n"
	. "<!-- wp:image {\"align\":\"left\"} -->\n"
	. '<figure class="wp-block-image alignleft"><img src="https://example.test/b.jpg" alt=""/>'
	. "<figcaption class=\"wp-element-caption\">Unterschrift</figcaption></figure>\n<!-- /wp:image -->";
$blocks = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'Blockkommentare entfernt', false === strpos( $blocks, '<!-- wp:' ) );
check( 'Blockmarkup erhalten', false !== strpos( $blocks, 'wp-block-image alignleft' ) );
check( 'kein wpautop um Blöcke', false === strpos( $blocks, '<p><figure' ) && false === strpos( $blocks, '<p></p>' ) );
check( 'Blockunterschrift erhalten', false !== strpos( $blocks, 'wp-element-caption' ) );

echo "\n== Einbettungen im Inhalt ==\n";
// Genau das Markup, das Gutenberg für einen YouTube-Block speichert:
// die nackte URL steht im Wrapper und wird erst durch autoembed() zum
// Player. Ohne diesen Schritt stand die Adresse als Text in der Lightbox.
$GLOBALS['ak_doors'][1]->post_content =
	"<!-- wp:embed {\"url\":\"https://youtu.be/I8v489Y7g4o\",\"type\":\"video\",\"providerNameSlug\":\"youtube\"} -->\n"
	. '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio">'
	. "<div class=\"wp-block-embed__wrapper\">\nhttps://youtu.be/I8v489Y7g4o\n</div></figure>\n<!-- /wp:embed -->";
$embed = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'URL wurde zum Player', false !== strpos( $embed, '<iframe' ) );
check( 'keine nackte Adresse mehr im Text', false === strpos( $embed, ">\nhttps://youtu.be" ) );
check( 'Blockhülle bleibt erhalten', false !== strpos( $embed, 'wp-block-embed__wrapper' ) );
check( 'Seitenverhältnis-Klasse bleibt', false !== strpos( $embed, 'wp-embed-aspect-16-9' ) );

// [embed]-Shortcode aus dem klassischen Editor.
$GLOBALS['ak_doors'][1]->post_content = "Vorher\n\n[embed]https://vimeo.com/123456[/embed]\n\nNachher";
$shortcode_embed = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( '[embed]-Shortcode aufgelöst', false !== strpos( $shortcode_embed, '<iframe' ) );
check( 'Text drumherum bleibt', false !== strpos( $shortcode_embed, 'Vorher' ) && false !== strpos( $shortcode_embed, 'Nachher' ) );

// Der globale Beitragskontext darf nicht hängen bleiben.
$GLOBALS['post'] = 'unberuehrt';
Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'globaler Beitrag wiederhergestellt', 'unberuehrt' === $GLOBALS['post'] );
unset( $GLOBALS['post'] );

// Klassischer Inhalt bekommt weiterhin Absätze.
$GLOBALS['ak_doors'][1]->post_content = "Zeile eins.\n\nZeile zwei.";
$klassisch = Content::render( $GLOBALS['ak_doors'][1], 1 );
check( 'klassischer Text bekommt Absätze', false !== strpos( $klassisch, '<p>' ) );

$GLOBALS['ak_doors'][1]->post_content = 'Fließtext für Tag 1.';
$GLOBALS['ak_meta'][101]['_ak_layout'] = 'media_top';

echo "\n== Farbwelt „Markenfarbe“ ==\n";
update_option( Settings::OPTION, array_merge( Settings::get(), array(
	'theme' => 'brand', 'brand_color' => '#0057b8', 'brand_scheme' => 'light',
) ) );
$brand = Renderer::shortcode( array() );
check( 'Theme-Klasse gesetzt', false !== strpos( $brand, 'ak-calendar--theme-brand' ) );
check( 'Schema als Datenattribut', false !== strpos( $brand, 'data-scheme="light"' ) );
check( 'Markenfarbe als Variable', false !== strpos( $brand, '--ak-brand:#0057b8' ) );
check( 'Türchenverlauf abgeleitet', false !== strpos( $brand, '--ak-door-face:linear-gradient(' ) );
check( 'Zahlfarbe abgeleitet', false !== strpos( $brand, '--ak-number:#' ) );
check( 'Rückseite abgeleitet', false !== strpos( $brand, '--ak-door-back:linear-gradient(' ) );

$dom_brand = new DOMDocument();
libxml_use_internal_errors( true );
$dom_brand->loadHTML( '<!doctype html><html><body>' . $brand . '</body></html>' );
$brand_errors = array_filter( libxml_get_errors(), function ( $e ) {
	if ( $e->level < LIBXML_ERR_ERROR ) { return false; }
	return ! preg_match( '/Tag (svg|rect|path) invalid/', $e->message );
} );
libxml_clear_errors();
check( 'HTML bleibt gültig', empty( $brand_errors ), count( $brand_errors ) . ' Fehler' );

// Der Shortcode darf die Farbwelt aktivieren – aber nur mit gültigem Hex.
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'theme' => 'nordic' ) ) );
$inline = Renderer::shortcode( array( 'color' => '#c8102e' ) );
check( 'color-Attribut aktiviert die Markenwelt', false !== strpos( $inline, 'ak-calendar--theme-brand' ) );
check( 'color-Attribut wird übernommen', false !== strpos( $inline, '--ak-brand:#c8102e' ) );

$evil_color = Renderer::shortcode( array( 'color' => 'red;}</style><script>alert(1)</script>' ) );
check( 'Farbinjektion abgewehrt', false === strpos( $evil_color, '<script' ) && false === strpos( $evil_color, '</style>' ) );
check( 'ungültige Farbe -> Einstellung', false !== strpos( $evil_color, '--ak-brand:#' ) );

$dark_brand = Renderer::shortcode( array( 'color' => '#0057b8', 'scheme' => 'dark' ) );
check( 'dunkles Schema wählbar', false !== strpos( $dark_brand, 'data-scheme="dark"' ) );
$bad_scheme = Renderer::shortcode( array( 'color' => '#0057b8', 'scheme' => 'neon' ) );
check( 'unbekanntes Schema -> Einstellung', false !== strpos( $bad_scheme, 'data-scheme="light"' ) );
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'theme' => 'elegant' ) ) );

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

echo "\n== Laufzeitdaten für das Frontend ==\n";
$inline = $GLOBALS['ak_inline_scripts'][0] ?? '';
check( 'Daten werden eingebunden', 1 === count( $GLOBALS['ak_inline_scripts'] ), count( $GLOBALS['ak_inline_scripts'] ) . ' Einbindungen' );
preg_match( '/window\.AdventskalenderData = (.*);$/', $inline, $m );
$runtime = json_decode( $m[1] ?? '{}', true );
check( 'gültiges JSON', is_array( $runtime ) && ! empty( $runtime ) );
check( 'REST-Basis enthalten', isset( $runtime['restUrl'] ) && false !== strpos( $runtime['restUrl'], 'adventskalender/v1/' ) );
check( 'kein Nonce für Abgemeldete', '' === ( $runtime['nonce'] ?? 'x' ) );
check( 'URL-Parameter enthalten', 'tuerchen' === ( $runtime['param'] ?? '' ) );
check( 'Übersetzungen enthalten', isset( $runtime['i18n']['openDoor'], $runtime['i18n']['lockedDoor'], $runtime['i18n']['alreadyOpen'] ) );
check( 'keine Inhalte in den Laufzeitdaten', false === strpos( $inline, 'GEHEIMNIS' ) && false === strpos( $inline, 'Fließtext' ) );

printf( "\n%d bestanden, %d fehlgeschlagen\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
