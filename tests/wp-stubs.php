<?php
/** Minimale WordPress-Attrappen, um die Plugin-Logik ohne WP zu prüfen. */
define( 'ABSPATH', '/tmp/fake-wp/' );

$GLOBALS['ak_options']   = array();
$GLOBALS['ak_now']       = '2026-12-05 08:30:00';
$GLOBALS['ak_tz']        = 'Europe/Berlin';
$GLOBALS['ak_user_can']  = false;
$GLOBALS['ak_logged_in'] = false;

function __( $t, $d = null ) { return $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function esc_html__( $t, $d = null ) { return $t; }
function esc_attr__( $t, $d = null ) { return $t; }
function apply_filters( $hook, $value ) { return $value; }
function absint( $v ) { return abs( (int) $v ); }
function wp_rand( $min = 0, $max = 0 ) { return random_int( $min, $max ); }
function wp_timezone() { return new DateTimeZone( $GLOBALS['ak_tz'] ); }
function current_datetime() { return new DateTimeImmutable( $GLOBALS['ak_now'], wp_timezone() ); }
function current_time( $format ) { return current_datetime()->format( $format ); }
function wp_date( $format, $ts = null ) {
	// Wie WordPress: Monats- und Tagesnamen kommen aus der Locale,
	// nicht aus PHPs date(). Sonst stünde im Deutschen „Dec“ statt „Dez“.
	$d      = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() );
	$month  = (int) $d->format( 'n' );
	$names  = array( 1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' );
	$abbrev = array( 1 => 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez' );
	$days   = array( 'Sunday' => 'Sonntag', 'Monday' => 'Montag', 'Tuesday' => 'Dienstag', 'Wednesday' => 'Mittwoch', 'Thursday' => 'Donnerstag', 'Friday' => 'Freitag', 'Saturday' => 'Samstag' );

	$out = '';
	$len = strlen( $format );
	for ( $i = 0; $i < $len; $i++ ) {
		$char = $format[ $i ];
		if ( '\\' === $char && $i + 1 < $len ) {
			$out .= $format[ ++$i ];
			continue;
		}
		switch ( $char ) {
			case 'F': $out .= $names[ $month ]; break;
			case 'M': $out .= $abbrev[ $month ]; break;
			case 'l': $out .= $days[ $d->format( 'l' ) ]; break;
			case 'D': $out .= mb_substr( $days[ $d->format( 'l' ) ], 0, 2 ); break;
			default:  $out .= $d->format( $char );
		}
	}

	return $out;
}
function get_option( $k, $d = false ) { return $GLOBALS['ak_options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['ak_options'][ $k ] = $v; return true; }
function add_option( $k, $v ) { return update_option( $k, $v ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_kses_post( $v ) { return (string) $v; }
function esc_url_raw( $v ) { return (string) $v; }
$GLOBALS['ak_attachment_ids'] = array( 11, 12, 13 );
function get_post_type( $id ) { return in_array( (int) $id, $GLOBALS['ak_attachment_ids'], true ) ? 'attachment' : 'page'; }
function is_user_logged_in() { return (bool) $GLOBALS['ak_logged_in']; }
function current_user_can( $cap ) { return (bool) $GLOBALS['ak_user_can']; }
function register_setting() {}
function number_format_i18n( $n ) { return (string) $n; }

require_once __DIR__ . '/ns-stubs.php';
require_once __DIR__ . '/../includes/class-color.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-availability.php';
