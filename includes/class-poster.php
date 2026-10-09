<?php
/**
 * Holt das Vorschaubild eines eingebetteten Videos.
 *
 * Ohne eigenes Vorschaubild wirkt ein Video-Türchen als leere Fläche –
 * bei dunklen Markenfarben stehen dann schwarze Vierecke im Kalender.
 * Deshalb wird beim Speichern einmalig das Vorschaubild des Anbieters
 * geholt und in die Mediathek gelegt.
 *
 * Bewusst beim Speichern und nicht beim Ausliefern: so entsteht beim
 * Seitenaufruf keine Verbindung zu YouTube oder Vimeo, das Bild liegt
 * auf dem eigenen Server und die Redaktion kann es jederzeit ersetzen.
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatisches Vorschaubild für Videos.
 */
class Poster {

	/**
	 * Gleicht das automatische Vorschaubild eines Türchens ab.
	 *
	 * @param int $post_id Beitrags-ID.
	 */
	public static function sync( int $post_id ): void {
		/**
		 * Filtert, ob Vorschaubilder von Videoanbietern geholt werden dürfen.
		 *
		 * @param bool $enabled Ob geholt werden darf.
		 * @param int  $post_id Beitrags-ID.
		 */
		if ( ! apply_filters( 'adventskalender_fetch_video_poster', true, $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Ein selbst gewähltes Bild hat immer Vorrang.
		$own = Settings::sanitize_attachment_id( get_post_meta( $post_id, Doors::META_PREVIEW_IMAGE, true ) )
			+ Settings::sanitize_attachment_id( get_post_meta( $post_id, Doors::META_VIDEO_POSTER, true ) );

		$source = self::source_url( $post );

		if ( '' === $source || $own > 0 ) {
			self::forget( $post_id );
			return;
		}

		// Schon erledigt – auch ein Fehlversuch wird vermerkt, damit nicht
		// bei jedem Speichern erneut das Netz befragt wird.
		if ( (string) get_post_meta( $post_id, Doors::META_AUTO_POSTER_SRC, true ) === $source ) {
			return;
		}

		update_post_meta( $post_id, Doors::META_AUTO_POSTER_SRC, $source );

		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$thumbnail = self::thumbnail_url( $source );
		if ( '' === $thumbnail ) {
			delete_post_meta( $post_id, Doors::META_AUTO_POSTER );
			return;
		}

		$attachment = self::sideload( $thumbnail, $post_id, (string) $post->post_title );

		if ( $attachment > 0 ) {
			update_post_meta( $post_id, Doors::META_AUTO_POSTER, $attachment );
		} else {
			delete_post_meta( $post_id, Doors::META_AUTO_POSTER );
		}
	}

	/**
	 * Vergisst ein automatisch geholtes Bild.
	 *
	 * Der Anhang selbst bleibt in der Mediathek – er könnte inzwischen
	 * anderswo verwendet werden.
	 *
	 * @param int $post_id Beitrags-ID.
	 */
	private static function forget( int $post_id ): void {
		delete_post_meta( $post_id, Doors::META_AUTO_POSTER );
		delete_post_meta( $post_id, Doors::META_AUTO_POSTER_SRC );
	}

	/**
	 * Ermittelt die Videoadresse eines Türchens.
	 *
	 * @param \WP_Post $post Türchen-Beitrag.
	 */
	public static function source_url( \WP_Post $post ): string {
		$type = Doors::sanitize_media_type( get_post_meta( $post->ID, Doors::META_MEDIA_TYPE, true ) );

		if ( 'video' === $type ) {
			$source = Doors::sanitize_video_source( get_post_meta( $post->ID, Doors::META_VIDEO_SOURCE, true ) );
			if ( 'embed' === $source ) {
				$url = (string) get_post_meta( $post->ID, Doors::META_VIDEO_URL, true );
				if ( '' !== $url && wp_http_validate_url( $url ) ) {
					return $url;
				}
			}

			return '';
		}

		return Content::first_content_video_url( $post );
	}

	/**
	 * Fragt die Adresse des Vorschaubilds beim Anbieter ab.
	 *
	 * @param string $url Videoadresse.
	 */
	public static function thumbnail_url( string $url ): string {
		if ( ! function_exists( '_wp_oembed_get_object' ) ) {
			return '';
		}

		$data = _wp_oembed_get_object()->get_data( $url, array( 'width' => 640 ) );

		if ( ! is_object( $data ) || empty( $data->thumbnail_url ) ) {
			return '';
		}

		$thumbnail = esc_url_raw( (string) $data->thumbnail_url );

		return wp_http_validate_url( $thumbnail ) ? $thumbnail : '';
	}

	/**
	 * Lädt ein Bild in die Mediathek.
	 *
	 * @param string $url     Bildadresse.
	 * @param int    $post_id Zugehöriges Türchen.
	 * @param string $title   Titel des Türchens.
	 * @return int Anhang-ID oder 0.
	 */
	private static function sideload( string $url, int $post_id, string $title ): int {
		foreach ( array( 'file.php', 'media.php', 'image.php' ) as $include ) {
			$path = ABSPATH . 'wp-admin/includes/' . $include;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			return 0;
		}

		$description = sprintf(
			/* translators: %s: Titel des Türchens. */
			__( 'Automatisches Vorschaubild für „%s“', 'adventskalender' ),
			$title
		);

		$attachment = media_sideload_image( $url, $post_id, $description, 'id' );

		if ( is_wp_error( $attachment ) ) {
			return 0;
		}

		return Settings::sanitize_attachment_id( $attachment );
	}
}
