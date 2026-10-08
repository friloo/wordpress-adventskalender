<?php
/**
 * Aufräumen beim Löschen des Plugins.
 *
 * Inhalte werden nur entfernt, wenn das in den Einstellungen
 * ausdrücklich gewünscht ist.
 *
 * @package Adventskalender
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ak_option   = 'adventskalender_settings';
$ak_settings = get_option( $ak_option, array() );

if ( ! is_array( $ak_settings ) || empty( $ak_settings['delete_data'] ) ) {
	return;
}

// Alle Türchen endgültig löschen.
$ak_posts = get_posts(
	array(
		'post_type'              => 'ak_door',
		'post_status'            => 'any',
		'numberposts'            => -1,
		'fields'                 => 'ids',
		'suppress_filters'       => true,
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
	)
);

foreach ( $ak_posts as $ak_post_id ) {
	wp_delete_post( (int) $ak_post_id, true );
}

delete_option( $ak_option );
delete_site_option( $ak_option );
