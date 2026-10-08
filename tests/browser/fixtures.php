<?php
/**
 * Erzeugt die HTML-Fixtures für die Browser-Tests.
 *
 * Gerendert wird mit dem echten Renderer gegen die Test-Attrappen; die
 * REST-Antworten liegen als Mock im Dokument. Dadurch prüfen die Tests
 * genau das ausgelieferte Markup, CSS und JavaScript – ohne WordPress.
 *
 * Nutzung:  php tests/browser/fixtures.php
 * Wird von tests/browser/run.js automatisch aufgerufen.
 *
 * @package Adventskalender
 */

require __DIR__ . '/../render-stubs.php';

use Adventskalender\Settings;
use Adventskalender\Renderer;
use Adventskalender\Content;
use Adventskalender\Rest;

// Minimale REST-Attrappen, um /state serverseitig zu erzeugen.
class WP_REST_Response {
	private $data;
	public function __construct( $data = null, $status = 200 ) {
		$this->data = $data;
	}
	public function get_data() {
		return $this->data;
	}
}
class WP_REST_Request {
	private $params;
	public function __construct( $params = array() ) {
		$this->params = $params;
	}
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}
class WP_REST_Server {
	const READABLE = 'GET';
}
class WP_Error {
	public $message;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function nocache_headers() {}

$root = dirname( __DIR__, 2 );
$out  = __DIR__ . '/fixtures';

if ( ! is_dir( $out ) ) {
	mkdir( $out, 0777, true );
}

// Ein Bild für den Mosaik-Test – als Datei, damit der Browser es wie ein
// echtes Bild lädt (inklusive naturalWidth für die Mosaikrechnung).
file_put_contents(
	$out . '/mosaik.svg',
	'<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800" viewBox="0 0 1200 800">'
	. '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
	. '<stop offset="0" stop-color="#2f6f8f"/><stop offset="1" stop-color="#e8d6a8"/></linearGradient></defs>'
	. '<rect width="100%" height="100%" fill="url(#g)"/>'
	. '<circle cx="360" cy="280" r="220" fill="rgba(255,255,255,.25)"/></svg>'
);

$GLOBALS['ak_attachment_ids'] = array( 1 );
$GLOBALS['ak_image_url_cb']   = function () {
	return 'mosaik.svg';
};
$GLOBALS['ak_attachment_meta'][1] = array(
	'width'  => 1200,
	'height' => 800,
	'sizes'  => array( 'large' => array( 'width' => 1024 ) ),
);

/**
 * Legt 24 Türchen mit erkennbaren Inhalten an.
 */
function seed_doors(): void {
	$GLOBALS['ak_doors'] = array();
	for ( $day = 1; $day <= 24; $day++ ) {
		$GLOBALS['ak_doors'][ $day ] = new WP_Post(
			array(
				'ID'           => 100 + $day,
				'post_title'   => 'Titel ' . $day,
				'post_content' => 'Inhalt von Tag ' . $day . '.',
			)
		);
		$GLOBALS['ak_meta'][ 100 + $day ] = array(
			'_ak_day'          => $day,
			'_ak_year'         => 2026,
			'_ak_media_type'   => 'none',
			'_ak_layout'       => 'text_only',
			'_ak_preview_text' => 'VORSCHAU-' . $day,
		);
	}
}

/**
 * Baut eine Fixture-Seite.
 *
 * @param string $file        Dateiname.
 * @param string $render_date Zeitpunkt, zu dem das HTML entsteht.
 * @param string $state_date  Zeitpunkt, den /state meldet.
 * @param array  $settings    Abweichende Einstellungen.
 */
function build( string $file, string $render_date, string $state_date, array $settings = array() ): void {
	global $root, $out;

	seed_doors();
	update_option(
		Settings::OPTION,
		array_merge(
			Settings::defaults(),
			array(
				'year'         => 2026,
				'columns'      => 6,
				'layout'       => 'classic',
				'shuffle'      => 0,
				'shuffle_seed' => 1,
			),
			$settings
		)
	);

	$GLOBALS['ak_now'] = $render_date;
	$calendar          = Renderer::shortcode( array() );

	// Serverantworten aus Sicht des späteren Zeitpunkts.
	$GLOBALS['ak_now'] = $state_date;
	$state             = Rest::get_state( new WP_REST_Request( array() ) )->get_data();

	$doors  = array();
	$locked = array();
	foreach ( $state['days'] as $entry ) {
		$day = (int) $entry['day'];
		if ( $entry['unlocked'] ) {
			$doors[ $day ] = array(
				'day'   => $day,
				'title' => 'Titel ' . $day,
				'html'  => Content::render( $GLOBALS['ak_doors'][ $day ], $day ),
				'empty' => false,
			);
		} else {
			$locked[ $day ] = $entry['notice'];
		}
	}

	$data = array(
		'restUrl' => '/mock/',
		'nonce'   => '',
		'i18n'    => array(
			'loading'     => 'Türchen wird geöffnet …',
			'error'       => 'Der Inhalt konnte nicht geladen werden.',
			'doorLabel'   => 'Türchen %d',
			'openDoor'    => 'Türchen %d öffnen',
			'lockedDoor'  => 'Türchen %1$d – %2$s',
			'alreadyOpen' => 'Türchen %d erneut ansehen',
		),
	);

	$page = '<!doctype html><html lang="de"><head><meta charset="utf-8">'
		. '<meta name="viewport" content="width=device-width, initial-scale=1">'
		// Verhindert die Favicon-Anfrage; sonst meldet der Browser einen
		// 404 und die strenge Fehlerprüfung der Tests schlägt an.
		. '<link rel="icon" href="data:,">'
		. '<title>Fixture ' . htmlspecialchars( $file ) . '</title>'
		. '<style>body{margin:0;background:#fff;font-family:system-ui,sans-serif}'
		. '.page{max-width:1120px;margin:0 auto;padding:20px}</style>'
		. '<style>' . file_get_contents( $root . '/assets/css/frontend.css' ) . '</style>'
		. '</head><body><div class="page">' . $calendar . '</div>'
		. '<script>window.AdventskalenderData=' . wp_json_encode( $data ) . ';'
		. 'window.__state=' . wp_json_encode( $state ) . ';'
		. 'window.__doors=' . wp_json_encode( $doors ) . ';'
		. 'window.__locked=' . wp_json_encode( $locked ) . ';'
		. 'window.__requests=[];'
		. 'window.fetch=function(u){u=String(u);window.__requests.push(u);'
		. 'if(u.indexOf("state")>-1){return Promise.resolve({ok:true,status:200,json:function(){return Promise.resolve(window.__state)}})}'
		. 'var m=u.match(/door\/(\d+)/);'
		. 'if(m){var d=parseInt(m[1],10);'
		. 'if(window.__locked[d]!==undefined){return Promise.resolve({ok:false,status:403,json:function(){return Promise.resolve({message:window.__locked[d]})}})}'
		. 'return Promise.resolve({ok:true,status:200,json:function(){return Promise.resolve(window.__doors[d])}})}'
		. 'return Promise.resolve({ok:false,status:404,json:function(){return Promise.resolve({})}})};'
		. '</script>'
		. '<script>' . file_get_contents( $root . '/assets/js/frontend.js' ) . '</script>'
		. '</body></html>';

	file_put_contents( $out . '/' . $file, $page );
	echo "  $file\n";
}

echo "Fixtures:\n";
build( 'kalender.html', '2026-12-05 10:00:00', '2026-12-05 10:00:00' );
build( 'veraltet.html', '2026-12-01 09:00:00', '2026-12-12 09:00:00' );
build( 'mosaik.html', '2026-12-05 10:00:00', '2026-12-05 10:00:00', array( 'layout' => 'mosaic', 'mosaic_image' => 1 ) );
