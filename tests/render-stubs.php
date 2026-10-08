<?php
require __DIR__ . '/wp-stubs.php';

class WP_Post {
	public $ID = 0;
	public $post_title = '';
	public $post_content = '';
	public $post_status = 'publish';
	public $post_type = 'ak_door';
	public function __construct( $data = array() ) {
		foreach ( $data as $k => $v ) { $this->$k = $v; }
	}
}

$GLOBALS['ak_doors']      = array();
$GLOBALS['ak_meta']       = array();
$GLOBALS['ak_doors_year'] = 2026;

function wp_cache_get( $key, $group = '' ) {
	if ( 'adventskalender' === $group && 0 === strpos( (string) $key, 'doors_' ) ) {
		// Wie das echte meta_query: nur Türchen des angefragten Jahres.
		return false !== strpos( (string) $key, '_' . $GLOBALS['ak_doors_year'] . '_' )
			? $GLOBALS['ak_doors']
			: array();
	}
	return 'version' === $key ? 1 : false;
}
function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) { return true; }
function wp_cache_delete( $key, $group = '' ) { return true; }

function get_post_meta( $post_id, $key, $single = false ) { return $GLOBALS['ak_meta'][ $post_id ][ $key ] ?? ''; }
function get_the_title( $post ) { return is_object( $post ) ? $post->post_title : ''; }
function wp_trim_words( $text, $num = 55, $more = '…' ) {
	$words = preg_split( '/\s+/', trim( (string) $text ) );
	return count( $words ) <= $num ? implode( ' ', $words ) : implode( ' ', array_slice( $words, 0, $num ) ) . $more;
}
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }
function strip_shortcodes( $t ) { return (string) $t; }
function wp_get_attachment_image_url( $id, $size = 'thumbnail' ) { return 'https://example.test/wp-content/uploads/bild-' . (int) $id . '.jpg'; }
function wp_get_attachment_image( $id, $size = 'thumbnail', $icon = false, $attr = array() ) {
	$class = isset( $attr['class'] ) ? $attr['class'] : '';
	return sprintf( '<img src="%s" class="%s" alt="" />', wp_get_attachment_image_url( $id, $size ), htmlspecialchars( $class, ENT_QUOTES ) );
}
function wp_get_attachment_caption( $id ) { return ''; }
function wp_oembed_get( $url, $args = array() ) { return '<iframe src="https://player.example.test/embed"></iframe>'; }
function wp_http_validate_url( $url ) { return (bool) filter_var( $url, FILTER_VALIDATE_URL ); }
function home_url() { return 'https://example.test'; }
function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( $url ) : parse_url( $url, $component ); }
function do_blocks( $c ) { return (string) $c; }
function wptexturize( $c ) { return (string) $c; }
function convert_smilies( $c ) { return (string) $c; }
function wpautop( $c ) { return '<p>' . (string) $c . '</p>'; }
function shortcode_unautop( $c ) { return (string) $c; }
function do_shortcode( $c ) { return (string) $c; }
function wp_filter_content_tags( $c, $ctx = '' ) { return (string) $c; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $t, $d = null ) { echo esc_html( $t ); }
function esc_attr_e( $t, $d = null ) { echo esc_attr( $t ); }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts; $out = array();
	foreach ( $pairs as $name => $default ) { $out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default; }
	return $out;
}
function wp_register_style() {} function wp_register_script() {}
function wp_enqueue_style() {} function wp_enqueue_script() {}
function wp_add_inline_script() {} function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( $path, '/' ); }
function wp_create_nonce( $a = -1 ) { return 'testnonce'; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/adventskalender/'; }

$base = __DIR__ . '/../includes/';
require_once $base . 'class-doors.php';
require_once $base . 'class-content.php';
require_once $base . 'class-renderer.php';
require_once $base . 'class-rest.php';
