<?php
/**
 * Baut originalgetreue wp-admin-Seiten mit dem echten Plugin-Markup.
 *
 * Nutzung:  php tools/admin-preview.php  →  tools/admin/*.html im Browser
 *
 * Entwicklungswerkzeug: es rendert Übersicht, Einstellungen und die
 * Türchen-Bearbeitung gegen die Test-Attrappen und legt sie in das echte
 * wp-admin-Gerüst. Beim ersten Lauf werden dafür die Original-Stylesheets
 * von WordPress geladen (einmalig, danach lokal zwischengespeichert) –
 * nur so zeigt die Vorschau wirklich, was im Backend ankommt.
 */
require __DIR__ . '/../tests/render-stubs.php';

function admin_url( $p = '' ) { return '?page=' . ltrim( $p, '/' ); }
function add_query_arg( $a, $u = '' ) { return is_array( $a ) ? $u . '?' . http_build_query( $a ) : $u; }
function get_edit_post_link( $i ) { return '#edit-' . (int) $i; }
function selected( $a, $b, $e = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $e ) { echo $r; } return $r; }
function checked( $a, $b, $e = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $e ) { echo $r; } return $r; }
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) {
	$f = '<input type="hidden" name="' . htmlspecialchars( (string) $n ) . '" value="demo" />';
	if ( $e ) { echo $f; } return $f;
}
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . htmlspecialchars( $g ) . '" />'; }
function submit_button( $t = null ) {
	echo '<p class="submit"><button type="button" class="button button-primary">' . htmlspecialchars( (string) $t ) . '</button></p>';
}
function wp_die( $m = '' ) { throw new RuntimeException( $m ); }
function get_current_screen() { return null; }
function wp_enqueue_media() {} function wp_localize_script() {}
function get_post( $id ) { foreach ( $GLOBALS['ak_doors'] as $p ) { if ( (int) $p->ID === (int) $id ) { return $p; } } return null; }
class AK_Fake_WPDB { public $postmeta = 'pm'; public $posts = 'p';
	public function prepare( $s, ...$a ) { return $s; }
	public function get_col( $s ) { return array( '2026', '2025' ); } }
$GLOBALS['wpdb'] = new AK_Fake_WPDB();

$base = __DIR__ . '/../includes/';
require_once $base . 'class-availability.php';
require_once $base . 'class-admin.php';
require_once $base . 'class-metabox.php';

use Adventskalender\Settings;
use Adventskalender\Admin;
use Adventskalender\Metabox;
use Adventskalender\Doors;

$ROOT = __DIR__ . '/..';
$GLOBALS['ak_user_can']  = true;
$GLOBALS['ak_logged_in'] = true;
$GLOBALS['ak_attachment_ids'] = range( 1, 40 );
$GLOBALS['ak_now'] = '2026-12-09 10:00:00';

$GLOBALS['ak_image_url_cb'] = function ( $id ) {
	$palettes = array(
		array( '#1f5f46', '#c8a25a' ), array( '#2f6f8f', '#e8d6a8' ), array( '#a8322c', '#f3cfa0' ),
		array( '#37474f', '#37d6a8' ), array( '#6b4a8f', '#f3b8d2' ), array( '#8a5a2b', '#ffe9c0' ),
	);
	$p = $palettes[ $id % count( $palettes ) ];
	$svg = sprintf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="640" height="480" viewBox="0 0 640 480">'
		. '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="%s"/>'
		. '<stop offset="1" stop-color="%s"/></linearGradient></defs><rect width="100%%" height="100%%" fill="url(#g)"/>'
		. '<circle cx="190" cy="170" r="130" fill="rgba(255,255,255,.22)"/>'
		. '<circle cx="470" cy="340" r="105" fill="rgba(0,0,0,.16)"/></svg>',
		$p[0], $p[1]
	);
	return 'data:image/svg+xml;base64,' . base64_encode( $svg );
};

// --- Testinhalte: realistische Mischung aus fertig, Entwurf und fehlend ----
$titel = array(
	1 => 'Ein Gedicht zum Advent', 2 => 'Unser Lieblingsrezept', 3 => 'Rückblick in Bildern',
	4 => 'Der kleine Film', 5 => 'Grüße aus dem Team', 6 => 'Drei Tipps für Dezember',
	7 => 'Ein Lied zum Mitsingen', 8 => 'Das Weihnachtsrätsel', 9 => 'Unsere Lieblingsorte',
	11 => 'Backen mit Kindern', 12 => 'Die Geschichte dahinter', 13 => 'Winterspaziergang',
	14 => 'Sternstunden', 16 => 'Lichterglanz', 17 => 'Kleine Pause', 19 => 'Der Wunschzettel',
	21 => 'Eine Überraschung', 24 => 'Frohe Weihnachten!',
);
$typen = array( 1 => 'text', 2 => 'image', 3 => 'gallery', 4 => 'video', 5 => 'image', 6 => 'text' );

$GLOBALS['ak_doors'] = array();
foreach ( $titel as $d => $t ) {
	$status = in_array( $d, array( 16, 21 ), true ) ? 'draft' : 'publish';
	$GLOBALS['ak_doors'][ $d ] = new WP_Post( array(
		'ID' => 100 + $d, 'post_title' => $t, 'post_status' => $status,
		'post_content' => 9 === $d ? '' : "Hinter diesem Türchen wartet Tag $d.",
	) );
	$GLOBALS['ak_meta'][ 100 + $d ] = array(
		'_ak_day' => $d, '_ak_year' => 2026,
		'_ak_media_type'    => $typen[ $d ] ?? ( 0 === $d % 3 ? 'image' : 'text' ),
		'_ak_image'         => 10 + $d,
		'_ak_preview_image' => in_array( $d, array( 1, 6, 17 ), true ) ? 0 : 10 + $d,
		'_ak_preview_text'  => 'Kleiner Teaser für Tag ' . $d,
		'_ak_layout'        => 'media_top',
		'_ak_video_source'  => 'embed',
		'_ak_video_url'     => 4 === $d ? 'https://vimeo.com/123456' : '',
		'_ak_link_url'      => 2 === $d ? 'https://example.test/rezept' : '',
		'_ak_link_label'    => 2 === $d ? 'Zum Rezept' : '',
		'_ak_gallery'       => 3 === $d ? '21,22,23' : '',
	);
}
// Tag 9 ist veröffentlicht, aber leer – soll als Problem auffallen.
$GLOBALS['ak_meta'][109]['_ak_media_type'] = 'none';

update_option( Settings::OPTION, array_merge( Settings::defaults(), array(
	'year' => 2026, 'door_count' => 24, 'columns' => 6, 'layout' => 'mosaic',
	'theme' => 'brand', 'brand_color' => '#0057b8', 'brand_scheme' => 'light',
	// Das Gerüst bildet den klassischen Editor nach; Gutenberg lässt sich
	// ohne WordPress-Installation nicht sinnvoll nachstellen.
	'editor' => 'classic',
	'mosaic_image' => 7, 'heading' => 'Unser Adventskalender',
	'intro' => 'Jeden Tag ein kleines Stück Vorfreude.',
) ) );

/** Nachbau des WordPress-Farbschemas „fresh“ (wird sonst beim Build erzeugt). */
function fresh_color_scheme(): string {
	return <<<CSS
#adminmenuback, #adminmenuwrap, #adminmenu { background: #1d2327; }
#adminmenu a { color: #f0f0f1; }
#adminmenu li.menu-top > a.menu-top { color: #f0f0f1; }
#adminmenu .wp-submenu a { color: rgba(240,246,252,.7); }
#adminmenu .wp-submenu a:hover, #adminmenu a:hover { color: #72aee6; }
#adminmenu li.menu-top:hover > a.menu-top { background: #2c3338; color: #72aee6; }
#adminmenu li.wp-has-current-submenu > a.menu-top,
#adminmenu li.current > a.menu-top,
#adminmenu .wp-menu-arrow { background: #2271b1; color: #fff; }
#adminmenu .wp-submenu, #adminmenu .wp-has-current-submenu .wp-submenu { background: #2c3338; }
#adminmenu .wp-submenu li.current a { color: #fff; font-weight: 600; }
#adminmenu div.wp-menu-image:before { color: rgba(240,246,252,.6); }
#adminmenu li.current div.wp-menu-image:before,
#adminmenu li.wp-has-current-submenu div.wp-menu-image:before { color: #fff; }
#wpadminbar { background: #1d2327; color: #f0f0f1; }
.wp-core-ui .button-primary { background: #2271b1; border-color: #2271b1; color: #fff; }
.wp-core-ui .button-primary:hover { background: #135e96; border-color: #135e96; }

/* Angekreuzte Felder: forms.css zeichnet ein weißes Häkchen, die blaue
   Füllung kommt aus dem Farbschema. Ohne sie bleibt der Haken unsichtbar. */
.wp-core-ui input[type="checkbox"]:checked,
.wp-core-ui input[type="radio"]:checked {
	background: #3582c4;
	border-color: #3582c4;
}
.wp-core-ui input[type="radio"]:checked::before {
	content: "";
	display: block;
	width: 6px;
	height: 6px;
	margin: 4px;
	border-radius: 50%;
	background-color: #fff;
}
.wp-core-ui input[type="checkbox"]:focus,
.wp-core-ui input[type="radio"]:focus {
	border-color: #3582c4;
	box-shadow: 0 0 0 1px #3582c4;
}
CSS;
}

/** Umschließt Inhalt mit dem echten wp-admin-Gerüst. */
function shell( string $title, string $content, string $menu_current, string $extra_head = '' ): string {
	global $ROOT;

	$menu = array(
		array( 'dashicons-dashboard', 'Dashboard', array() ),
		array( 'dashicons-admin-post', 'Beiträge', array() ),
		array( 'dashicons-admin-media', 'Medien', array() ),
		array( 'dashicons-admin-page', 'Seiten', array() ),
		array( 'dashicons-calendar-alt', 'Adventskalender', array( 'Übersicht', 'Alle Türchen', 'Türchen hinzufügen', 'Einstellungen' ) ),
		array( 'dashicons-admin-appearance', 'Design', array() ),
		array( 'dashicons-admin-plugins', 'Plugins', array() ),
		array( 'dashicons-admin-settings', 'Einstellungen', array() ),
	);

	$items = '';
	foreach ( $menu as $entry ) {
		$is_ak = 'Adventskalender' === $entry[1];
		$classes = 'wp-has-submenu menu-top';
		if ( $is_ak ) {
			$classes .= ' wp-has-current-submenu wp-menu-open menu-top-first';
		}
		$items .= '<li class="' . $classes . '">';
		$items .= '<a href="#" class="' . ( $is_ak ? 'wp-has-submenu wp-has-current-submenu wp-menu-open ' : '' ) . 'menu-top">';
		$items .= '<div class="wp-menu-arrow"><div></div></div>';
		$items .= '<div class="wp-menu-image dashicons-before ' . $entry[0] . '"><br></div>';
		$items .= '<div class="wp-menu-name">' . htmlspecialchars( $entry[1] ) . '</div></a>';
		if ( $entry[2] ) {
			$items .= '<ul class="wp-submenu wp-submenu-wrap"><li class="wp-submenu-head">' . htmlspecialchars( $entry[1] ) . '</li>';
			foreach ( $entry[2] as $sub ) {
				$cur = $sub === $menu_current ? ' class="current"' : '';
				$items .= '<li' . $cur . '><a href="#"' . $cur . '>' . htmlspecialchars( $sub ) . '</a></li>';
			}
			$items .= '</ul>';
		}
		$items .= '</li>';
	}

	$css = '';
	foreach ( array( 'css-dashicons.css', 'css-buttons.css', 'css-common.css', 'css-forms.css', 'css-admin-menu.css', 'css-edit.css', 'css-list-tables.css', 'colors-fresh.css' ) as $file ) {
		$css .= '<link rel="stylesheet" href="wpcss/' . $file . '">' . "\n";
	}

	return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>' . htmlspecialchars( $title ) . '</title>'
		. $css
		. '<link rel="stylesheet" href="ak-frontend.css">'
		. '<link rel="stylesheet" href="ak-admin.css">'
		. '<style>html{background:#f0f0f1}#wpadminbar{position:fixed;top:0;left:0;right:0;height:32px;z-index:99999;font:13px/32px -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
		. '#wpadminbar .ab-items{display:flex;gap:18px;padding:0 12px;margin:0;list-style:none}'
		. '#wpadminbar .ab-items li{display:flex;align-items:center;gap:6px}'
		. '#wpcontent{margin-left:160px;padding-left:20px}#wpbody-content{padding-bottom:65px}'
		. 'html.wp-toolbar{padding-top:32px}#adminmenuwrap{position:relative}'
		. '#adminmenu .wp-submenu{position:static!important;box-shadow:none!important;width:auto!important;min-width:0!important}'
		. '</style>'
		. $extra_head
		. '</head><body class="wp-admin wp-core-ui js admin-color-fresh auto-fold" style="padding-top:32px">'
		. '<div id="wpadminbar"><ul class="ab-items"><li><span class="dashicons dashicons-wordpress"></span></li>'
		. '<li><span class="dashicons dashicons-admin-home"></span> Beispiel GmbH</li>'
		. '<li style="margin-left:auto"><span class="dashicons dashicons-admin-users"></span> Hallo, Friederich</li></ul></div>'
		. '<div id="wpwrap"><div id="adminmenumain" role="navigation"><div id="adminmenuback"></div>'
		. '<div id="adminmenuwrap"><ul id="adminmenu">' . $items . '</ul></div></div>'
		. '<div id="wpcontent"><div id="wpbody"><div id="wpbody-content">' . $content . '</div></div></div></div>'
		. '<script>window.AdventskalenderAdmin={restUrl:"/mock/",nonce:"n",i18n:{copied:"Kopiert!"}};</script>'
		. '<script src="ak-admin.js"></script>'
		. '</body></html>';
}

/**
 * Lädt die Original-Stylesheets von WordPress, falls noch nicht vorhanden.
 */
function ensure_wordpress_css( string $dir ): bool {
	$sources = array(
		'css-dashicons.css'  => 'wp-includes/css/dashicons.css',
		'css-buttons.css'    => 'wp-includes/css/buttons.css',
		'css-common.css'     => 'wp-admin/css/common.css',
		'css-forms.css'      => 'wp-admin/css/forms.css',
		'css-admin-menu.css' => 'wp-admin/css/admin-menu.css',
		'css-edit.css'       => 'wp-admin/css/edit.css',
		'css-list-tables.css' => 'wp-admin/css/list-tables.css',
	);

	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}

	$missing = array();
	foreach ( $sources as $local => $remote ) {
		if ( ! file_exists( $dir . '/' . $local ) ) {
			$missing[ $local ] = $remote;
		}
	}
	$need_font = ! file_exists( dirname( $dir ) . '/fonts/dashicons.woff2' );

	if ( ! $missing && ! $need_font ) {
		return true;
	}

	echo "Lade Original-Stylesheets von WordPress …\n";
	$base = 'https://raw.githubusercontent.com/WordPress/WordPress/master/';

	foreach ( $missing as $local => $remote ) {
		$data = @file_get_contents( $base . $remote );
		if ( false === $data ) {
			fwrite( STDERR, "Konnte $remote nicht laden. Ohne Netzzugang bitte die Dateien\n"
				. "aus einer WordPress-Installation nach " . $dir . " kopieren.\n" );
			return false;
		}
		file_put_contents( $dir . '/' . $local, $data );
	}

	if ( $need_font ) {
		$font = @file_get_contents( $base . 'wp-includes/fonts/dashicons.woff2' );
		if ( false !== $font ) {
			if ( ! is_dir( dirname( $dir ) . '/fonts' ) ) {
				mkdir( dirname( $dir ) . '/fonts', 0777, true );
			}
			file_put_contents( dirname( $dir ) . '/fonts/dashicons.woff2', $font );
		}
	}

	return true;
}

if ( ! ensure_wordpress_css( __DIR__ . '/admin/wpcss' ) ) {
	exit( 1 );
}

@mkdir( __DIR__ . '/admin', 0777, true );
copy( $ROOT . '/assets/css/admin.css', __DIR__ . '/admin/ak-admin.css' );
copy( $ROOT . '/assets/css/frontend.css', __DIR__ . '/admin/ak-frontend.css' );
copy( $ROOT . '/assets/js/admin.js', __DIR__ . '/admin/ak-admin.js' );
file_put_contents( __DIR__ . '/admin/wpcss/colors-fresh.css', fresh_color_scheme() );

// --- Seite 1: Übersicht ---------------------------------------------------
ob_start();
Admin::render_overview();
file_put_contents( __DIR__ . '/admin/01-uebersicht.html', shell( 'Adventskalender', ob_get_clean(), 'Übersicht' ) );

// --- Seite 2: Einstellungen ----------------------------------------------
ob_start();
Admin::render_settings();
file_put_contents( __DIR__ . '/admin/02-einstellungen.html', shell( 'Einstellungen', ob_get_clean(), 'Einstellungen' ) );

// --- Seite 3: Türchen bearbeiten -----------------------------------------
$post = $GLOBALS['ak_doors'][2];

function postbox( string $id, string $title, string $inside ): string {
	return '<div id="' . $id . '" class="postbox">'
		. '<div class="postbox-header"><h2 class="hndle"><span>' . htmlspecialchars( $title ) . '</span></h2>'
		. '<div class="handle-actions hide-if-no-js"><button type="button" class="handlediv" aria-expanded="true">'
		. '<span class="screen-reader-text">Umschalten</span><span class="toggle-indicator" aria-hidden="true"></span>'
		. '</button></div></div><div class="inside">' . $inside . '</div></div>';
}

ob_start(); Metabox::render_schedule( $post ); $box_schedule = ob_get_clean();
ob_start(); Metabox::render_media( $post );    $box_media    = ob_get_clean();
ob_start(); Metabox::render_preview( $post );  $box_preview  = ob_get_clean();
ob_start(); Metabox::editor_hint( $post );     $hint         = ob_get_clean();

$publish = '<div class="submitbox" id="submitpost"><div id="misc-publishing-actions">'
	. '<div class="misc-pub-section"><span class="dashicons dashicons-admin-post"></span> Status: <strong>Veröffentlicht</strong></div>'
	. '<div class="misc-pub-section"><span class="dashicons dashicons-visibility"></span> Sichtbarkeit: <strong>Öffentlich</strong></div>'
	. '</div><div id="major-publishing-actions"><div id="delete-action"><a class="submitdelete deletion" href="#">In den Papierkorb</a></div>'
	. '<div id="publishing-action"><button type="button" class="button button-primary button-large">Aktualisieren</button></div>'
	. '<div class="clear"></div></div></div>';

$editor = '<div id="postdivrich" class="postarea wp-editor-expand">'
	. '<div class="wp-editor-container" style="border:1px solid #dcdcde;border-radius:4px;overflow:hidden">'
	. '<div style="background:#f6f7f7;border-bottom:1px solid #dcdcde;padding:6px 10px;font-size:13px;color:#50575e">'
	. '<span class="dashicons dashicons-editor-bold"></span> <span class="dashicons dashicons-editor-italic"></span> '
	. '<span class="dashicons dashicons-editor-ul"></span> <span class="dashicons dashicons-editor-ol"></span> '
	. '<span class="dashicons dashicons-admin-links"></span></div>'
	. '<div style="padding:14px;min-height:170px;font-size:15px;line-height:1.7;color:#2c3338">'
	. 'Unser Lieblingsrezept für Zimtsterne – schnell gemacht und im Nu verschwunden.<br><br>'
	. 'Zutaten: 200 g gemahlene Mandeln, 150 g Puderzucker, 2 Eiweiß, 1 TL Zimt.</div></div></div>';

$content = '<div class="wrap"><h1 class="wp-heading-inline">Türchen bearbeiten</h1>'
	. '<a href="#" class="page-title-action">Türchen hinzufügen</a><hr class="wp-header-end">'
	. '<form id="post" method="post"><div id="poststuff"><div id="post-body" class="metabox-holder columns-2">'
	. '<div id="post-body-content"><div id="titlediv"><div id="titlewrap">'
	. '<label class="screen-reader-text" for="title">Titel des Türchens</label>'
	. '<input type="text" name="post_title" size="30" value="' . htmlspecialchars( $post->post_title ) . '" id="title" spellcheck="true" autocomplete="off">'
	. '</div></div>' . $hint . $editor . '</div>'
	. '<div id="postbox-container-1" class="postbox-container">'
	. postbox( 'submitdiv', 'Veröffentlichen', $publish )
	. postbox( 'ak_schedule', 'Zeitfenster', $box_schedule )
	. '</div>'
	. '<div id="postbox-container-2" class="postbox-container">'
	. postbox( 'ak_media', 'Inhalt des Türchens', $box_media )
	. postbox( 'ak_preview', 'Vorschau hinter dem Türchen', $box_preview )
	. '</div></div></div></form></div>';

file_put_contents( __DIR__ . '/admin/03-tuerchen.html', shell( 'Türchen bearbeiten', $content, 'Alle Türchen' ) );

echo "admin/01-uebersicht.html, 02-einstellungen.html, 03-tuerchen.html geschrieben\n";
