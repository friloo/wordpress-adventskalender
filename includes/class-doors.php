<?php
/**
 * Datenmodell der Türchen (Custom Post Type + Meta).
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registrierung und Datenzugriff für Türchen.
 */
class Doors {

	/**
	 * Post-Type-Slug.
	 */
	const POST_TYPE = 'ak_door';

	/**
	 * Meta-Schlüssel.
	 */
	const META_DAY           = '_ak_day';
	const META_YEAR          = '_ak_year';
	const META_LAYOUT        = '_ak_layout';
	const META_MEDIA_TYPE    = '_ak_media_type';
	const META_IMAGE         = '_ak_image';
	const META_GALLERY       = '_ak_gallery';
	const META_VIDEO_SOURCE  = '_ak_video_source';
	const META_VIDEO_URL     = '_ak_video_url';
	const META_VIDEO_FILE    = '_ak_video_file';
	const META_VIDEO_POSTER  = '_ak_video_poster';
	const META_PREVIEW_TEXT  = '_ak_preview_text';
	const META_PREVIEW_IMAGE = '_ak_preview_image';
	const META_DOOR_IMAGE    = '_ak_door_image';
	const META_LINK_URL      = '_ak_link_url';
	const META_LINK_LABEL    = '_ak_link_label';

	/**
	 * Voreingestellte Anordnung in der Lightbox.
	 */
	const DEFAULT_LAYOUT = 'report';

	/**
	 * Basis der REST-Route, wenn der Block-Editor aktiv ist.
	 */
	const REST_BASE = 'ak_door';

	/**
	 * Inhalts-Layouts innerhalb der Lightbox.
	 *
	 * @return array<string,string>
	 */
	public static function layouts(): array {
		return array(
			'report'     => __( 'Bericht – Titelbild über die volle Breite, darunter der Text', 'adventskalender' ),
			'media_top'  => __( 'Medium oben, Text darunter', 'adventskalender' ),
			'media_side' => __( 'Medium links, Text rechts', 'adventskalender' ),
			'media_only' => __( 'Nur Medium (ohne Text)', 'adventskalender' ),
			'text_only'  => __( 'Nur Text', 'adventskalender' ),
		);
	}

	/**
	 * Medientypen.
	 *
	 * @return array<string,string>
	 */
	public static function media_types(): array {
		return array(
			'none'    => __( 'Kein Medium', 'adventskalender' ),
			'image'   => __( 'Titelbild', 'adventskalender' ),
			'gallery' => __( 'Bildergalerie', 'adventskalender' ),
			'video'   => __( 'Video', 'adventskalender' ),
		);
	}

	/**
	 * Videoquellen.
	 *
	 * @return array<string,string>
	 */
	public static function video_sources(): array {
		return array(
			'embed' => __( 'Externe URL (YouTube, Vimeo …)', 'adventskalender' ),
			'file'  => __( 'Videodatei aus der Mediathek', 'adventskalender' ),
		);
	}

	/**
	 * Soll der Block-Editor verwendet werden?
	 *
	 * Er setzt voraus, dass der Inhaltstyp in der REST-API registriert ist.
	 * Das geschieht nur zusammen mit dem abgeschotteten Controller – fehlt
	 * der, bleibt es beim klassischen Editor, statt die Türchen ungeschützt
	 * auszuliefern.
	 */
	public static function uses_block_editor(): bool {
		if ( 'block' !== Settings::get( 'editor' ) ) {
			return false;
		}

		return class_exists( __NAMESPACE__ . '\\Doors_Rest_Controller' );
	}

	/**
	 * Registriert den Post Type.
	 */
	public static function register_post_type(): void {
		$block_editor = self::uses_block_editor();

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Türchen', 'adventskalender' ),
					'singular_name'      => __( 'Türchen', 'adventskalender' ),
					'add_new'            => __( 'Türchen hinzufügen', 'adventskalender' ),
					'add_new_item'       => __( 'Neues Türchen', 'adventskalender' ),
					'edit_item'          => __( 'Türchen bearbeiten', 'adventskalender' ),
					'new_item'           => __( 'Neues Türchen', 'adventskalender' ),
					'view_item'          => __( 'Türchen ansehen', 'adventskalender' ),
					'search_items'       => __( 'Türchen suchen', 'adventskalender' ),
					'not_found'          => __( 'Keine Türchen gefunden.', 'adventskalender' ),
					'not_found_in_trash' => __( 'Keine Türchen im Papierkorb.', 'adventskalender' ),
					'all_items'          => __( 'Alle Türchen', 'adventskalender' ),
					'menu_name'          => __( 'Adventskalender', 'adventskalender' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				// Nur für den Block-Editor – und dann ausschließlich mit
				// dem abgeschotteten Controller (siehe oben).
				'show_in_rest'           => $block_editor,
				'rest_base'              => self::REST_BASE,
				'rest_controller_class'  => $block_editor ? Doors_Rest_Controller::class : \WP_REST_Posts_Controller::class,
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'menu_icon'           => 'dashicons-calendar-alt',
				'delete_with_user'    => false,
			)
		);
	}

	/**
	 * Registriert alle Meta-Felder mit Sanitizing und Berechtigungsprüfung.
	 */
	public static function register_meta(): void {
		$auth = static function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};

		$fields = array(
			self::META_DAY           => array( 'integer', 'absint' ),
			self::META_YEAR          => array( 'integer', 'absint' ),
			self::META_LAYOUT        => array( 'string', array( __CLASS__, 'sanitize_layout' ) ),
			self::META_MEDIA_TYPE    => array( 'string', array( __CLASS__, 'sanitize_media_type' ) ),
			self::META_IMAGE         => array( 'integer', array( Settings::class, 'sanitize_attachment_id' ) ),
			self::META_GALLERY       => array( 'string', array( __CLASS__, 'sanitize_id_list' ) ),
			self::META_VIDEO_SOURCE  => array( 'string', array( __CLASS__, 'sanitize_video_source' ) ),
			self::META_VIDEO_URL     => array( 'string', 'esc_url_raw' ),
			self::META_VIDEO_FILE    => array( 'integer', array( Settings::class, 'sanitize_attachment_id' ) ),
			self::META_VIDEO_POSTER  => array( 'integer', array( Settings::class, 'sanitize_attachment_id' ) ),
			self::META_PREVIEW_TEXT  => array( 'string', 'sanitize_text_field' ),
			self::META_PREVIEW_IMAGE => array( 'integer', array( Settings::class, 'sanitize_attachment_id' ) ),
			self::META_DOOR_IMAGE    => array( 'integer', array( Settings::class, 'sanitize_attachment_id' ) ),
			self::META_LINK_URL      => array( 'string', 'esc_url_raw' ),
			self::META_LINK_LABEL    => array( 'string', 'sanitize_text_field' ),
		);

		foreach ( $fields as $key => $config ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => $config[0],
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => $config[1],
					'auth_callback'     => $auth,
				)
			);
		}
	}

	/**
	 * Sanitize-Callback für das Inhalts-Layout.
	 *
	 * @param mixed $value Rohwert.
	 */
	public static function sanitize_layout( $value ): string {
		$value = is_string( $value ) ? $value : '';

		return array_key_exists( $value, self::layouts() ) ? $value : self::DEFAULT_LAYOUT;
	}

	/**
	 * Sanitize-Callback für den Medientyp.
	 *
	 * @param mixed $value Rohwert.
	 */
	public static function sanitize_media_type( $value ): string {
		$value = is_string( $value ) ? $value : '';

		return array_key_exists( $value, self::media_types() ) ? $value : 'none';
	}

	/**
	 * Sanitize-Callback für die Videoquelle.
	 *
	 * @param mixed $value Rohwert.
	 */
	public static function sanitize_video_source( $value ): string {
		$value = is_string( $value ) ? $value : '';

		return array_key_exists( $value, self::video_sources() ) ? $value : 'embed';
	}

	/**
	 * Normalisiert eine kommaseparierte Liste von Anhang-IDs.
	 *
	 * @param mixed $value Rohwert.
	 */
	public static function sanitize_id_list( $value ): string {
		if ( is_array( $value ) ) {
			$parts = $value;
		} else {
			$parts = explode( ',', (string) $value );
		}

		$ids = array();
		foreach ( $parts as $part ) {
			$id = Settings::sanitize_attachment_id( $part );
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return implode( ',', $ids );
	}

	/**
	 * Holt die Türchen eines Jahres, indiziert nach Tag.
	 *
	 * @param int      $year     Kalenderjahr.
	 * @param string[] $statuses Zu berücksichtigende Beitragsstatus.
	 * @return array<int,\WP_Post>
	 */
	public static function get_for_year( int $year, array $statuses = array( 'publish' ) ): array {
		$statuses = array_values( array_intersect( $statuses, array( 'publish', 'draft', 'pending', 'future', 'private' ) ) );
		if ( empty( $statuses ) ) {
			$statuses = array( 'publish' );
		}
		sort( $statuses );

		$cache_key = 'doors_v' . self::cache_version() . '_' . $year . '_' . implode( '-', $statuses );
		$cached    = wp_cache_get( $cache_key, 'adventskalender' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => $statuses,
				'posts_per_page'         => 100,
				'orderby'                => array( 'meta_value_num' => 'ASC' ),
				'meta_key'               => self::META_DAY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_YEAR,
						'value'   => $year,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		$doors = array();
		foreach ( $query->posts as $post ) {
			$day = (int) get_post_meta( $post->ID, self::META_DAY, true );
			if ( $day < 1 || $day > 31 ) {
				continue;
			}
			// Bei Duplikaten gewinnt das zuletzt aktualisierte Türchen.
			if ( isset( $doors[ $day ] ) ) {
				continue;
			}
			$doors[ $day ] = $post;
		}

		ksort( $doors );
		wp_cache_set( $cache_key, $doors, 'adventskalender', 300 );

		return $doors;
	}

	/**
	 * Holt ein einzelnes Türchen.
	 *
	 * @param int      $day      Tag (1–31).
	 * @param int      $year     Kalenderjahr.
	 * @param string[] $statuses Zu berücksichtigende Beitragsstatus.
	 * @return \WP_Post|null
	 */
	public static function get( int $day, int $year, array $statuses = array( 'publish' ) ): ?\WP_Post {
		$doors = self::get_for_year( $year, $statuses );

		return $doors[ $day ] ?? null;
	}

	/**
	 * Leert den internen Objekt-Cache.
	 */
	public static function flush_cache(): void {
		// Der Objekt-Cache kennt keine Wildcards – darum wird die
		// Cache-Gruppe über einen Versionszähler invalidiert.
		wp_cache_set( 'version', (int) wp_cache_get( 'version', 'adventskalender' ) + 1, 'adventskalender' );
	}

	/**
	 * Aktueller Versionszähler der Cache-Gruppe.
	 */
	private static function cache_version(): int {
		$version = wp_cache_get( 'version', 'adventskalender' );
		if ( ! is_numeric( $version ) ) {
			$version = 1;
			wp_cache_set( 'version', $version, 'adventskalender' );
		}

		return (int) $version;
	}

	/**
	 * Listet alle Jahre, für die Türchen existieren.
	 *
	 * @return int[]
	 */
	public static function get_years(): array {
		global $wpdb;

		$years = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status != 'trash'
				 ORDER BY pm.meta_value DESC",
				self::META_YEAR,
				self::POST_TYPE
			)
		);

		$years = array_values( array_unique( array_filter( array_map( 'absint', (array) $years ) ) ) );
		$current = (int) Settings::get( 'year' );
		if ( ! in_array( $current, $years, true ) ) {
			$years[] = $current;
		}
		rsort( $years );

		return $years;
	}
}
