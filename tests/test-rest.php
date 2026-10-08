<?php
require __DIR__ . '/render-stubs.php';

class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code = $code; $this->message = $message; $this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
	private $data; private $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}
class WP_REST_Request {
	private $params;
	public function __construct( $params = array() ) { $this->params = $params; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
}
class WP_REST_Server { const READABLE = 'GET'; }
function nocache_headers() {}

use Adventskalender\Settings;
use Adventskalender\Rest;

$pass = 0; $fail = 0;
function check( $label, $ok, $info = '' ) {
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	printf( "%s  %s%s\n", $ok ? '  OK ' : 'FAIL', $label, $ok ? '' : "  $info" );
}

$GLOBALS['ak_doors'] = array();
for ( $d = 1; $d <= 24; $d++ ) {
	$GLOBALS['ak_doors'][ $d ] = new WP_Post( array( 'ID' => 100 + $d, 'post_title' => "Titel $d", 'post_content' => "GEHEIMTEXT-$d" ) );
	$GLOBALS['ak_meta'][ 100 + $d ] = array(
		'_ak_day' => $d, '_ak_year' => 2026, '_ak_media_type' => 'image',
		'_ak_image' => 11, '_ak_preview_text' => "VORSCHAU-$d", '_ak_layout' => 'media_top',
	);
}
$GLOBALS['ak_now'] = '2026-12-05 10:00:00';
update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'year' => 2026, 'door_count' => 24 ) ) );

function door( $day, $year = null ) {
	$params = array( 'day' => $day );
	if ( null !== $year ) { $params['year'] = $year; }
	return Rest::get_door( new WP_REST_Request( $params ) );
}

echo "== Türchen abrufen ==\n";
$r = door( 5 );
check( 'Tag 5 liefert Antwort', $r instanceof WP_REST_Response );
check( 'Status 200', 200 === $r->get_status() );
check( 'Titel enthalten', 'Titel 5' === $r->get_data()['title'] );
check( 'Inhalt enthalten', false !== strpos( $r->get_data()['html'], 'GEHEIMTEXT-5' ) );

echo "\n== SICHERHEIT: gesperrte Tage ==\n";
foreach ( array( 6, 12, 24 ) as $day ) {
	$e = door( $day );
	check( "Tag $day -> WP_Error", $e instanceof WP_Error, get_class( $e ) );
	check( "Tag $day -> Status 403", 403 === ( $e->get_error_data()['status'] ?? 0 ) );
	check( "Tag $day -> Fehlercode", 'adventskalender_locked' === $e->get_error_code() );
	$dump = print_r( $e, true );
	check( "Tag $day -> kein Inhalt im Fehler", false === strpos( $dump, "GEHEIMTEXT-$day" ) );
}

echo "\n== Ungültige Eingaben ==\n";
check( 'Tag 25 -> 404', ( door( 25 ) instanceof WP_Error ) && 'adventskalender_invalid_day' === door( 25 )->get_error_code() );
check( 'Tag 0 -> 404',  ( door( 0 ) instanceof WP_Error ) && 'adventskalender_invalid_day' === door( 0 )->get_error_code() );
check( 'Tag "5; DROP" -> wird zu 5', door( '5; DROP TABLE' ) instanceof WP_REST_Response );
// Die Route erlaubt per Regex nur [0-9]{1,2}; ein negativer Wert erreicht
// den Callback also nie. Käme er doch an, normalisiert absint() ihn und es
// greift dieselbe Datumsprüfung – kein Umweg um die Sperre.
check( 'negativer Tag unterliegt der Sperre', door( -24 ) instanceof WP_Error );
check( 'Validator lehnt 0 ab', false === Rest::validate_day( 0 ) );
check( 'Validator lehnt 32 ab', false === Rest::validate_day( 32 ) );
check( 'Validator nimmt 24', true === Rest::validate_day( 24 ) );
check( 'Jahr-Validator lehnt 1999 ab', false === Rest::validate_year( 1999 ) );
check( 'Jahr-Validator nimmt 2026', true === Rest::validate_year( 2026 ) );

echo "\n== Jahr-Parameter kann die Sperre nicht umgehen ==\n";
$r = door( 24, 2020 ); // Vergangenes Jahr: dort wäre Tag 24 offen – aber es gibt keine Türchen.
check( '2020 liefert kein Türchen von 2026', $r instanceof WP_REST_Response && false === strpos( $r->get_data()['html'], 'GEHEIMTEXT-24' ) );
$r = door( 24, 2099 );
check( 'Zukunftsjahr bleibt gesperrt', $r instanceof WP_Error );
$r = door( 24, 1 ); // Ungültiges Jahr -> fällt auf Einstellung (2026) zurück
check( 'ungültiges Jahr -> Einstellung, gesperrt', $r instanceof WP_Error );

echo "\n== Zustands-Endpunkt ==\n";
$state = Rest::get_state( new WP_REST_Request( array() ) )->get_data();
check( '24 Einträge', 24 === count( $state['days'] ) );
check( 'Jahr korrekt', 2026 === $state['year'] );
check( 'heute korrekt', '2026-12-05' === $state['today'] );
check( 'Testmodus false', false === $state['test_mode'] );
$open = array_values( array_filter( $state['days'], function ( $d ) { return $d['unlocked']; } ) );
check( '5 Tage offen', 5 === count( $open ) );
check( 'offener Tag hat Vorschau', false !== strpos( $open[0]['preview'], 'VORSCHAU-1' ) );
$locked = array_values( array_filter( $state['days'], function ( $d ) { return ! $d['unlocked']; } ) );
check( 'gesperrter Tag ohne Vorschau-Schlüssel', ! isset( $locked[0]['preview'] ) );
check( 'gesperrter Tag mit Hinweis', false !== strpos( $locked[0]['notice'], '6. Dezember 2026' ) );
$state_dump = print_r( $state, true );
$leak = array();
for ( $d = 6; $d <= 24; $d++ ) {
	if ( false !== strpos( $state_dump, "VORSCHAU-$d" ) || false !== strpos( $state_dump, "GEHEIMTEXT-$d" ) ) { $leak[] = $d; }
}
check( 'Zustand leakt keine gesperrten Inhalte', empty( $leak ), 'durchgesickert: ' . implode( ',', $leak ) );

echo "\n== Leeres Türchen über REST ==\n";
$GLOBALS['ak_doors'][4]->post_content = '';
$GLOBALS['ak_meta'][104]['_ak_media_type'] = 'none';
$r = door( 4 );
check( 'Status 200 mit Hinweis', $r instanceof WP_REST_Response && true === $r->get_data()['empty'] );
check( 'freundlicher Text', false !== strpos( $r->get_data()['html'], 'noch kein Inhalt' ) );

echo "\n== Testmodus öffnet REST ==\n";
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'test_mode' => 1 ) ) );
check( 'Tag 24 jetzt abrufbar', door( 24 ) instanceof WP_REST_Response );
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'test_mode' => 0 ) ) );
check( 'Tag 24 wieder gesperrt', door( 24 ) instanceof WP_Error );

echo "\n== Redaktionsvorschau über REST ==\n";
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'editor_preview' => 1 ) ) );
$GLOBALS['ak_logged_in'] = true; $GLOBALS['ak_user_can'] = true;
check( 'Redakteur darf Tag 24 laden', door( 24 ) instanceof WP_REST_Response );
$GLOBALS['ak_logged_in'] = false; $GLOBALS['ak_user_can'] = false;
check( 'Abgemeldeter darf nicht', door( 24 ) instanceof WP_Error );

echo "\n== Block-Editor: Absicherung der Standardrouten ==\n";

// Minimale Attrappe des WordPress-Controllers.
class WP_REST_Posts_Controller {
	protected $post_type;
	public function __construct( $post_type = '' ) { $this->post_type = $post_type; }
	public function get_items_permissions_check( $request ) { return true; }
	public function get_item_permissions_check( $request ) { return true; }
}
function rest_authorization_required_code() { return is_user_logged_in() ? 403 : 401; }
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

require __DIR__ . '/../includes/class-doors-rest-controller.php';

use Adventskalender\Doors_Rest_Controller;
use Adventskalender\Doors;

$controller = new Doors_Rest_Controller( Doors::POST_TYPE );
$req        = new WP_REST_Request( array() );

$GLOBALS['ak_logged_in'] = false; $GLOBALS['ak_user_can'] = false;
check( 'Liste: abgemeldet verweigert', $controller->get_items_permissions_check( $req ) instanceof WP_Error );
check( 'Einzeln: abgemeldet verweigert', $controller->get_item_permissions_check( $req ) instanceof WP_Error );
check( 'Status 401 für Abgemeldete', 401 === $controller->get_items_permissions_check( $req )->get_error_data()['status'] );

$GLOBALS['ak_logged_in'] = true; $GLOBALS['ak_user_can'] = false;
check( 'Angemeldet ohne Rechte verweigert', $controller->get_items_permissions_check( $req ) instanceof WP_Error );
check( 'Status 403 für Angemeldete', 403 === $controller->get_items_permissions_check( $req )->get_error_data()['status'] );

$GLOBALS['ak_user_can'] = true;
check( 'Redaktion darf Liste lesen', true === $controller->get_items_permissions_check( $req ) );
check( 'Redaktion darf Einzeleintrag lesen', true === $controller->get_item_permissions_check( $req ) );
$GLOBALS['ak_logged_in'] = false; $GLOBALS['ak_user_can'] = false;

echo "\n== Zweite Sperre (rest_pre_dispatch) ==\n";

class AK_Route_Request extends WP_REST_Request {
	private $route;
	public function __construct( $route ) { parent::__construct( array() ); $this->route = $route; }
	public function get_route() { return $this->route; }
}

$guard = function ( $route ) { return Rest::guard_door_routes( null, null, new AK_Route_Request( $route ) ); };

check( 'Türchen-Liste gesperrt',        $guard( '/wp/v2/ak_door' ) instanceof WP_Error );
check( 'Einzelnes Türchen gesperrt',    $guard( '/wp/v2/ak_door/123' ) instanceof WP_Error );
check( 'Revisionen gesperrt',           $guard( '/wp/v2/ak_door/123/revisions' ) instanceof WP_Error );
check( 'Autosaves gesperrt',            $guard( '/wp/v2/ak_door/123/autosaves' ) instanceof WP_Error );
check( 'Beiträge nicht betroffen',      null === $guard( '/wp/v2/posts' ) );
check( 'Medien nicht betroffen',        null === $guard( '/wp/v2/media' ) );
check( 'ähnlicher Name nicht betroffen', null === $guard( '/wp/v2/ak_doors_public' ) );
check( 'eigene Route nicht betroffen',  null === $guard( '/adventskalender/v1/state' ) );

$GLOBALS['ak_user_can'] = true;
check( 'Redaktion kommt durch', null === $guard( '/wp/v2/ak_door' ) );
$GLOBALS['ak_user_can'] = false;

// Vorberechnetes Ergebnis darf nicht überschrieben werden.
$vorher = new WP_REST_Response( array( 'x' => 1 ), 200 );
check( 'vorhandenes Ergebnis bleibt', $vorher === Rest::guard_door_routes( $vorher, null, new AK_Route_Request( '/wp/v2/ak_door' ) ) );

echo "\n== Editorwahl entscheidet über die REST-Anmeldung ==\n";
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'editor' => 'classic' ) ) );
check( 'klassischer Editor: kein REST', false === Doors::uses_block_editor() );
update_option( Settings::OPTION, array_merge( Settings::get(), array( 'editor' => 'block' ) ) );
check( 'Block-Editor: REST aktiv', true === Doors::uses_block_editor() );
check( 'ungültiger Wert -> Voreinstellung', 'block' === Settings::sanitize( array( 'editor' => 'quatsch' ) )['editor'] );

echo "\n== Routenregistrierung und Berechtigungen ==\n";
$GLOBALS['ak_routes'] = array();
function register_rest_route( $namespace, $route, $args = array() ) {
	$GLOBALS['ak_routes'][ $namespace . $route ] = $args;
	return true;
}
Rest::register_routes();
$routes = $GLOBALS['ak_routes'];

check( 'Türchen-Route registriert', isset( $routes['adventskalender/v1/door/(?P<day>[0-9]{1,2})'] ) );
check( 'Zustands-Route registriert', isset( $routes['adventskalender/v1/state'] ) );
check( 'Paletten-Route registriert', isset( $routes['adventskalender/v1/palette'] ) );

$public = array( 'adventskalender/v1/door/(?P<day>[0-9]{1,2})', 'adventskalender/v1/state' );
foreach ( $public as $route ) {
	check( "öffentlich lesbar: $route", '__return_true' === $routes[ $route ]['permission_callback'] );
	check( "nur lesend: $route", 'GET' === $routes[ $route ]['methods'] );
}

$palette_permission = $routes['adventskalender/v1/palette']['permission_callback'];
check( 'Palette nicht öffentlich', '__return_true' !== $palette_permission );
check( 'Palette prüft Berechtigung', is_callable( $palette_permission ) );
$GLOBALS['ak_user_can'] = false;
check( 'ohne manage_options verweigert', false === (bool) call_user_func( $palette_permission ) );
$GLOBALS['ak_user_can'] = true;
check( 'mit manage_options erlaubt', true === (bool) call_user_func( $palette_permission ) );
$GLOBALS['ak_user_can'] = false;

echo "\n== Paletten-Antwort ==\n";
$palette = Rest::get_palette( new WP_REST_Request( array( 'color' => '#0057b8', 'scheme' => 'dark' ) ) )->get_data();
check( 'Farbe normalisiert', '#0057b8' === $palette['color'] );
check( 'Schema übernommen', 'dark' === $palette['scheme'] );
check( 'Palette enthalten', isset( $palette['palette']['--ak-door-face'] ) );
check( 'Kontraste enthalten', isset( $palette['contrast']['number_on_door']['ratio'] ) );
$bad = Rest::get_palette( new WP_REST_Request( array( 'color' => '</style><script>' ) ) )->get_data();
check( 'ungültige Farbe -> Rückfall', '#1f5f46' === $bad['color'] );
check( 'kein Markup in der Antwort', false === strpos( wp_json_encode( $bad ), '<script' ) );

printf( "\n%d bestanden, %d fehlgeschlagen\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
