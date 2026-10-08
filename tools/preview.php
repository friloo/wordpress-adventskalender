<?php
/**
 * Erzeugt statische Vorschauseiten aus dem echten Renderer.
 *
 * Nutzung:  php tools/preview.php  →  tools/preview/*.html im Browser öffnen
 *
 * Nur ein Entwicklungswerkzeug: es läuft gegen die Test-Attrappen und
 * erlaubt, Layouts und Farbwelten ohne WordPress-Installation zu beurteilen.
 * Die Bilder sind lokal erzeugte SVG-Platzhalter, es wird nichts geladen.
 */
require __DIR__ . '/../tests/render-stubs.php';

use Adventskalender\Settings;
use Adventskalender\Renderer;
use Adventskalender\Content;

$ROOT = __DIR__ . '/..';

// Platzhalterbilder (lokal erzeugte SVGs als data-URI, damit offline alles lädt).
function placeholder( $seed, $w = 800, $h = 600 ) {
	$palettes = array(
		array( '#1f5f46', '#c8a25a' ), array( '#2f6f8f', '#e8d6a8' ),
		array( '#a8322c', '#f3cfa0' ), array( '#37474f', '#37d6a8' ),
		array( '#6b4a8f', '#f3b8d2' ), array( '#8a5a2b', '#ffe9c0' ),
	);
	$p = $palettes[ $seed % count( $palettes ) ];
	$svg = sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d">'
		. '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
		. '<stop offset="0" stop-color="%3$s"/><stop offset="1" stop-color="%4$s"/></linearGradient></defs>'
		. '<rect width="100%%" height="100%%" fill="url(#g)"/>'
		. '<circle cx="%5$d" cy="%6$d" r="%7$d" fill="rgba(255,255,255,.22)"/>'
		. '<circle cx="%8$d" cy="%9$d" r="%10$d" fill="rgba(0,0,0,.16)"/>'
		. '</svg>',
		$w, $h, $p[0], $p[1],
		(int) ( $w * 0.3 ), (int) ( $h * 0.35 ), (int) ( $h * 0.28 ),
		(int) ( $w * 0.75 ), (int) ( $h * 0.7 ), (int) ( $h * 0.22 )
	);
	return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}

$GLOBALS['ak_image_url_cb'] = function ( $id, $size ) { return placeholder( (int) $id ); };

$titles = array(
	'Ein Gedicht zum Advent', 'Unser Lieblingsrezept', 'Rückblick in Bildern',
	'Der kleine Film', 'Grüße aus dem Team', 'Drei Tipps für Dezember',
	'Ein Lied', 'Das Weihnachtsrätsel', 'Unsere Lieblingsorte', 'Ein Dankeschön',
	'Backen mit Kindern', 'Die Geschichte dahinter', 'Winterspaziergang',
	'Sternstunden', 'Ein Rezept aus Omas Buch', 'Lichterglanz', 'Kleine Pause',
	'Zeit zum Lesen', 'Der Wunschzettel', 'Musik für den Abend',
	'Eine Überraschung', 'Fast soweit', 'Heiligabend naht', 'Frohe Weihnachten!'
);
$teaser = array(
	'Vier Zeilen, die wärmen', 'Mit Zimt und Kardamom', 'Zwölf Momente',
	'90 Sekunden', 'Wir sagen Danke', 'Kleine Rituale', 'Zum Mitsingen',
	'Wer findet die Lösung?', 'Sechs Plätze', 'Für euch', 'Teig, Mehl, Freude',
	'Wie alles begann', 'Raus in den Schnee', 'Blick nach oben', 'Handschriftlich',
	'Kerzen an', 'Tief durchatmen', 'Drei Buchtipps', 'Was wünschst du dir?',
	'Unsere Playlist', 'Schaut rein', 'Zwei Tage noch', 'Morgen ist es da',
	'Danke für dieses Jahr'
);

$GLOBALS['ak_attachment_ids'] = range( 1, 31 );
$GLOBALS['ak_doors'] = array();
for ( $d = 1; $d <= 24; $d++ ) {
	$GLOBALS['ak_doors'][ $d ] = new WP_Post( array(
		'ID'           => 100 + $d,
		'post_title'   => $titles[ $d - 1 ],
		'post_content' => "Hinter diesem Türchen wartet Tag $d. Ein kurzer Text, der zeigt, wie der Inhalt in der Lightbox aussieht – mit angenehmer Zeilenlänge und ruhiger Typografie.",
	) );
	$GLOBALS['ak_meta'][ 100 + $d ] = array(
		'_ak_day'           => $d,
		'_ak_year'          => 2026,
		'_ak_media_type'    => 'image',
		'_ak_image'         => $d,
		'_ak_preview_text'  => $teaser[ $d - 1 ],
		'_ak_preview_image' => $d,
		'_ak_door_image'    => $d,
		'_ak_layout'        => 'media_top',
	);
}

$css = file_get_contents( $ROOT . '/assets/css/frontend.css' );
$js  = file_get_contents( $ROOT . '/assets/js/frontend.js' );

// Inhalte der Lightbox vorab rendern und als Mock-Antworten einbetten.
function build_page( $label, $settings, $now, $extra_css = '' ) {
	global $css, $js, $ROOT;

	$GLOBALS['ak_now'] = $now;
	update_option( Settings::OPTION, array_merge( Settings::defaults(), $settings ) );

	$calendar = Renderer::shortcode( array() );

	$mock = array();
	foreach ( $GLOBALS['ak_doors'] as $day => $post ) {
		$mock[ $day ] = array(
			'day'   => $day,
			'title' => $post->post_title,
			'html'  => Content::render( $post, $day ),
			'empty' => false,
		);
	}

	$data = array(
		'restUrl' => '/mock/',
		'nonce'   => '',
		'i18n'    => array(
			'loading'   => 'Türchen wird geöffnet …',
			'error'     => 'Der Inhalt konnte nicht geladen werden.',
			'empty'     => 'Noch kein Inhalt hinterlegt.',
			'close'     => 'Schließen',
			'doorLabel' => 'Türchen %d',
			'openDoor'  => 'Türchen %d öffnen',
			'lockedDoor' => 'Türchen %1$d – %2$s',
		),
	);

	return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1">'
		. '<title>' . htmlspecialchars( $label ) . '</title>'
		. '<style>body{margin:0;background:#fff;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d2733}'
		. '.page{max-width:1120px;margin:0 auto;padding:28px 18px 60px}'
		. 'h1.demo{font:600 1.1rem/1.3 system-ui;color:#6b7280;margin:0 0 16px;letter-spacing:.02em}</style>'
		. '<style>' . $css . '</style>' . $extra_css
		. '</head><body><div class="page"><h1 class="demo">' . htmlspecialchars( $label ) . '</h1>'
		. $calendar . '</div>'
		. '<script>window.AdventskalenderData=' . json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
		. 'window.__mock=' . json_encode( $mock, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';'
		. 'window.fetch=function(url){'
		. 'var m=String(url).match(/door\/(\d+)/);'
		. 'if(m){var d=window.__mock[m[1]];'
		. 'return Promise.resolve({ok:!!d,status:d?200:403,json:function(){return Promise.resolve(d||{message:"Gesperrt"})}})}'
		. 'return Promise.resolve({ok:false,status:404,json:function(){return Promise.resolve({})}})};'
		. '</script>'
		. '<script>' . $js . '</script></body></html>';
}

$pages = array(
	'01-klassisch.html' => array( 'Klassisch · Nordisch · 5. Dezember', array( 'year' => 2026, 'layout' => 'classic', 'theme' => 'nordic', 'columns' => 6, 'shuffle_seed' => 4242 ), '2026-12-05 10:00:00' ),
	'02-mosaik.html'    => array( 'Mosaik · Elegant · 12. Dezember',   array( 'year' => 2026, 'layout' => 'mosaic', 'mosaic_image' => 2, 'theme' => 'elegant', 'columns' => 6, 'shuffle_seed' => 4242, 'snow' => 1 ), '2026-12-12 10:00:00' ),
	'03-einzelbilder.html' => array( 'Einzelbilder · Warm · 18. Dezember', array( 'year' => 2026, 'layout' => 'individual', 'theme' => 'warm', 'columns' => 6, 'shuffle_seed' => 77 ), '2026-12-18 10:00:00' ),
	'04-testmodus.html' => array( 'Testmodus · Modern · alle offen',    array( 'year' => 2026, 'layout' => 'classic', 'theme' => 'modern', 'columns' => 6, 'test_mode' => 1, 'shuffle_seed' => 4242, 'heading' => 'Unser Adventskalender', 'intro' => 'Jeden Tag ein kleines Stück Vorfreude.' ), '2026-11-20 10:00:00' ),
	'05-candy.html'     => array( 'Klassisch · Candy · 24. Dezember',   array( 'year' => 2026, 'layout' => 'classic', 'theme' => 'candy', 'columns' => 6, 'shuffle_seed' => 9 ), '2026-12-24 12:00:00' ),
	'06-hintergrund.html' => array( 'Hintergrundbild · Elegant',        array( 'year' => 2026, 'layout' => 'classic', 'theme' => 'elegant', 'columns' => 6, 'background_image' => 4, 'shuffle_seed' => 4242, 'snow' => 1, 'heading' => 'Adventskalender 2026' ), '2026-12-10 10:00:00' ),
);

$out_dir = __DIR__ . '/preview';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0777, true );
}

foreach ( $pages as $file => $cfg ) {
	file_put_contents( $out_dir . '/' . $file, build_page( $cfg[0], $cfg[1], $cfg[2] ) );
	echo "geschrieben: preview/$file\n";
}
