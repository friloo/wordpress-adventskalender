<?php
/**
 * Aufbereitung der Türchen-Inhalte für Vorschau und Lightbox.
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Erzeugt das Markup der Türchen-Inhalte.
 */
class Content {

	/**
	 * Liest ein Meta-Feld eines Türchens.
	 *
	 * @param int    $post_id Beitrags-ID.
	 * @param string $key     Meta-Schlüssel.
	 * @param mixed  $default Rückfallwert.
	 * @return mixed
	 */
	private static function meta( int $post_id, string $key, $default = '' ) {
		$value = get_post_meta( $post_id, $key, true );

		return ( '' === $value || null === $value ) ? $default : $value;
	}

	/**
	 * Vollständige Nutzlast eines geöffneten Türchens.
	 *
	 * Darf ausschließlich nach erfolgreicher Verfügbarkeitsprüfung
	 * aufgerufen werden.
	 *
	 * @param \WP_Post $post Türchen-Beitrag.
	 * @param int      $day  Tag im Dezember.
	 * @return array<string,mixed>
	 */
	public static function payload( \WP_Post $post, int $day ): array {
		$layout = Doors::sanitize_layout( self::meta( $post->ID, Doors::META_LAYOUT, Doors::DEFAULT_LAYOUT ) );

		$payload = array(
			'day'     => $day,
			'title'   => get_the_title( $post ),
			'layout'  => $layout,
			'media'   => self::render_media( $post ),
			'text'    => self::render_text( $post ),
			'link'    => self::render_link( $post ),
			'classes' => 'ak-lightbox__body ak-lightbox__body--' . $layout,
		);

		/**
		 * Filtert die Nutzlast eines Türchens.
		 *
		 * @param array    $payload Aufbereitete Daten.
		 * @param \WP_Post $post    Türchen-Beitrag.
		 * @param int      $day     Tag im Dezember.
		 */
		return (array) apply_filters( 'adventskalender_door_payload', $payload, $post, $day );
	}

	/**
	 * Baut das komplette HTML für die Lightbox.
	 *
	 * @param \WP_Post $post Türchen-Beitrag.
	 * @param int      $day  Tag im Dezember.
	 */
	public static function render( \WP_Post $post, int $day ): string {
		$data   = self::payload( $post, $day );
		$layout = (string) $data['layout'];

		$show_media = 'text_only' !== $layout && '' !== $data['media'];
		$show_text  = 'media_only' !== $layout && ( '' !== $data['text'] || '' !== $data['link'] );

		// Im Bericht ohne Titelbild soll der Text nicht in der Luft hängen.
		$classes = (string) $data['classes'];
		if ( 'report' === $layout && ! $show_media ) {
			$classes .= ' is-without-lead';
		}

		$html  = '<div class="' . esc_attr( $classes ) . '">';
		if ( $show_media ) {
			$html .= '<div class="ak-lightbox__media">' . $data['media'] . '</div>';
		}
		if ( $show_text ) {
			$html .= '<div class="ak-lightbox__text">' . $data['text'] . $data['link'] . '</div>';
		}
		$html .= '</div>';

		/**
		 * Filtert das fertige Lightbox-Markup.
		 *
		 * @param string   $html HTML der Lightbox.
		 * @param \WP_Post $post Türchen-Beitrag.
		 * @param int      $day  Tag im Dezember.
		 */
		return (string) apply_filters( 'adventskalender_door_html', $html, $post, $day );
	}

	/**
	 * Rendert den Textkörper eines Türchens.
	 *
	 * @param \WP_Post $post Türchen-Beitrag.
	 */
	public static function render_text( \WP_Post $post ): string {
		$raw = (string) $post->post_content;
		if ( '' === trim( $raw ) ) {
			return '';
		}

		// Bewusst ohne „the_content“, um Rekursionen mit anderen Plugins zu
		// vermeiden. Dafür müssen die Schritte, die WordPress dort erledigt,
		// hier einzeln nachgezogen werden.
		$has_blocks = function_exists( 'has_blocks' ) && has_blocks( $raw );

		// Einbettungen brauchen den Beitragskontext: WordPress legt die
		// oEmbed-Antwort als Meta am Beitrag ab, und get_post() liefert
		// dafür den globalen Beitrag.
		$previous_post     = $GLOBALS['post'] ?? null;
		$GLOBALS['post']   = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$html              = $raw;

		// [embed]-Shortcodes und nackte URLs auflösen. WordPress hängt beides
		// mit Priorität 8 vor do_blocks ein – ohne diesen Schritt bleibt die
		// YouTube-Adresse aus einem Embed-Block als Text stehen.
		if ( isset( $GLOBALS['wp_embed'] ) && is_object( $GLOBALS['wp_embed'] ) ) {
			if ( method_exists( $GLOBALS['wp_embed'], 'run_shortcode' ) ) {
				$html = $GLOBALS['wp_embed']->run_shortcode( $html );
			}
			if ( method_exists( $GLOBALS['wp_embed'], 'autoembed' ) ) {
				$html = $GLOBALS['wp_embed']->autoembed( $html );
			}
		}

		$html = do_blocks( $html );
		$html = wptexturize( $html );
		$html = convert_smilies( $html );
		if ( ! $has_blocks ) {
			$html = wpautop( $html );
		}
		$html = shortcode_unautop( $html );
		$html = do_shortcode( $html );
		$html = wp_filter_content_tags( $html, 'adventskalender' );

		$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

		return '<div class="ak-prose">' . $html . '</div>';
	}

	/**
	 * Rendert das Medium eines Türchens (Bild, Galerie oder Video).
	 *
	 * @param \WP_Post $post Türchen-Beitrag.
	 */
	public static function render_media( \WP_Post $post ): string {
		$type = Doors::sanitize_media_type( self::meta( $post->ID, Doors::META_MEDIA_TYPE, 'none' ) );

		switch ( $type ) {
			case 'image':
				return self::render_image( (int) self::meta( $post->ID, Doors::META_IMAGE, 0 ) );

			case 'gallery':
				return self::render_gallery( (string) self::meta( $post->ID, Doors::META_GALLERY, '' ) );

			case 'video':
				return self::render_video( $post );
		}

		return '';
	}

	/**
	 * Rendert ein einzelnes Bild inklusive Bildunterschrift.
	 *
	 * @param int $attachment_id Anhang-ID.
	 */
	private static function render_image( int $attachment_id ): string {
		$attachment_id = Settings::sanitize_attachment_id( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$image = wp_get_attachment_image(
			$attachment_id,
			'large',
			false,
			array(
				'class'    => 'ak-media__img',
				'loading'  => 'lazy',
				'decoding' => 'async',
			)
		);

		if ( '' === $image ) {
			return '';
		}

		$caption = wp_get_attachment_caption( $attachment_id );
		$html    = '<figure class="ak-media ak-media--image">' . $image;
		if ( ! empty( $caption ) ) {
			$html .= '<figcaption class="ak-media__caption">' . esc_html( $caption ) . '</figcaption>';
		}
		$html .= '</figure>';

		return $html;
	}

	/**
	 * Rendert eine Bildergalerie.
	 *
	 * @param string $id_list Kommaseparierte Anhang-IDs.
	 */
	private static function render_gallery( string $id_list ): string {
		$ids = array_filter( array_map( array( Settings::class, 'sanitize_attachment_id' ), explode( ',', $id_list ) ) );
		if ( empty( $ids ) ) {
			return '';
		}

		$count = count( $ids );
		if ( 1 === $count ) {
			return self::render_image( (int) reset( $ids ) );
		}

		$html = '<div class="ak-gallery" data-count="' . esc_attr( (string) $count ) . '">';
		foreach ( $ids as $id ) {
			$image = wp_get_attachment_image(
				$id,
				'large',
				false,
				array(
					'class'    => 'ak-gallery__img',
					'loading'  => 'lazy',
					'decoding' => 'async',
				)
			);
			if ( '' === $image ) {
				continue;
			}
			$full    = wp_get_attachment_image_url( $id, 'full' );
			$caption = wp_get_attachment_caption( $id );

			$html .= '<figure class="ak-gallery__item">';
			$html .= '<button type="button" class="ak-gallery__zoom" data-ak-zoom="' . esc_url( (string) $full ) . '" aria-label="' . esc_attr__( 'Bild größer anzeigen', 'adventskalender' ) . '">' . $image . '</button>';
			if ( ! empty( $caption ) ) {
				$html .= '<figcaption class="ak-gallery__caption">' . esc_html( $caption ) . '</figcaption>';
			}
			$html .= '</figure>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Rendert ein Video (Einbettung oder Mediathek-Datei).
	 *
	 * @param \WP_Post $post Türchen-Beitrag.
	 */
	private static function render_video( \WP_Post $post ): string {
		$source = Doors::sanitize_video_source( self::meta( $post->ID, Doors::META_VIDEO_SOURCE, 'embed' ) );
		$poster = Settings::sanitize_attachment_id( self::meta( $post->ID, Doors::META_VIDEO_POSTER, 0 ) );

		if ( 'file' === $source ) {
			$file_id = Settings::sanitize_attachment_id( self::meta( $post->ID, Doors::META_VIDEO_FILE, 0 ) );
			if ( $file_id <= 0 ) {
				return '';
			}
			$url = wp_get_attachment_url( $file_id );
			if ( ! $url ) {
				return '';
			}

			$attributes = array(
				'src'      => $url,
				'controls' => 'controls',
				'preload'  => 'metadata',
				'class'    => 'ak-media__video',
			);
			if ( $poster > 0 ) {
				$poster_url = wp_get_attachment_image_url( $poster, 'large' );
				if ( $poster_url ) {
					$attributes['poster'] = $poster_url;
				}
			}

			$tag = '<video';
			foreach ( $attributes as $name => $value ) {
				$tag .= ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
			}
			$tag .= ' playsinline></video>';

			return '<figure class="ak-media ak-media--video">' . $tag . '</figure>';
		}

		$url = (string) self::meta( $post->ID, Doors::META_VIDEO_URL, '' );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}

		// Direkte Videodateien per URL.
		$extension = strtolower( (string) pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) );
		if ( in_array( $extension, array( 'mp4', 'webm', 'ogv', 'm4v' ), true ) ) {
			$tag = sprintf(
				'<video class="ak-media__video" src="%s" controls preload="metadata" playsinline></video>',
				esc_url( $url )
			);

			return '<figure class="ak-media ak-media--video">' . $tag . '</figure>';
		}

		// oEmbed (YouTube, Vimeo …). WordPress erlaubt nur gelistete Anbieter.
		$embed = wp_oembed_get( $url, array( 'width' => 960 ) );
		if ( ! $embed ) {
			return sprintf(
				'<p class="ak-media ak-media--fallback"><a href="%1$s" target="_blank" rel="noopener noreferrer nofollow">%2$s</a></p>',
				esc_url( $url ),
				esc_html__( 'Video in neuem Tab ansehen', 'adventskalender' )
			);
		}

		return '<figure class="ak-media ak-media--embed"><div class="ak-embed">' . $embed . '</div></figure>';
	}

	/**
	 * Rendert den optionalen Call-to-Action-Button.
	 *
	 * @param \WP_Post $post Türchen-Beitrag.
	 */
	public static function render_link( \WP_Post $post ): string {
		$url = (string) self::meta( $post->ID, Doors::META_LINK_URL, '' );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}

		$label = (string) self::meta( $post->ID, Doors::META_LINK_LABEL, '' );
		if ( '' === trim( $label ) ) {
			$label = __( 'Mehr erfahren', 'adventskalender' );
		}

		// Kompatibel mit PHP 7.4 – kein str_contains().
		$host        = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host    = (string) wp_parse_url( $url, PHP_URL_HOST );
		$is_external = '' !== $url_host && 0 !== strcasecmp( $url_host, $host );
		$rel         = $is_external ? ' target="_blank" rel="noopener noreferrer"' : '';

		return sprintf(
			'<p class="ak-cta"><a class="ak-button" href="%1$s"%2$s>%3$s</a></p>',
			esc_url( $url ),
			$rel, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- statische Attribute.
			esc_html( $label )
		);
	}

	/**
	 * Sucht das erste Bild im Beitragstext.
	 *
	 * Deckt beide Editoren ab: WordPress hängt eingefügten Mediathek-
	 * Bildern die Klasse „wp-image-123“ an, extern eingebundene Bilder
	 * werden über ihre Adresse erkannt.
	 *
	 * @param \WP_Post $post Türchen-Beitrag.
	 * @return array{id:int,url:string}
	 */
	public static function first_content_image( \WP_Post $post ): array {
		$content = (string) $post->post_content;
		$empty   = array(
			'id'  => 0,
			'url' => '',
		);

		if ( '' === trim( $content ) ) {
			return $empty;
		}

		if ( preg_match( '/wp-image-(\d+)/', $content, $match ) ) {
			$id = Settings::sanitize_attachment_id( $match[1] );
			if ( $id > 0 ) {
				return array(
					'id'  => $id,
					'url' => '',
				);
			}
		}

		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $match ) ) {
			$url = esc_url_raw( html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $url ) {
				return array(
					'id'  => 0,
					'url' => $url,
				);
			}
		}

		return $empty;
	}

	/**
	 * Leitet einen Teaser aus dem Beitragstext ab.
	 *
	 * Blockkommentare und nackte Adressen fliegen heraus: bei einem
	 * Einbettungsblock stünde sonst die rohe YouTube-Adresse als Teaser
	 * hinter dem Türchen.
	 *
	 * @param \WP_Post $post Türchen-Beitrag.
	 */
	public static function teaser_from_content( \WP_Post $post ): string {
		$plain = (string) $post->post_content;
		if ( '' === trim( $plain ) ) {
			return '';
		}

		$plain = (string) preg_replace( '#<!--.*?-->#s', ' ', $plain );
		$plain = strip_shortcodes( $plain );
		$plain = wp_strip_all_tags( $plain );
		$plain = (string) preg_replace( '#https?://\S+#i', ' ', $plain );
		$plain = trim( (string) preg_replace( '/\s+/u', ' ', $plain ) );

		return '' === $plain ? '' : wp_trim_words( $plain, 12, '…' );
	}

	/**
	 * Erste Videoadresse im Beitragstext.
	 *
	 * @param \WP_Post $post Türchen-Beitrag.
	 */
	public static function first_content_video_url( \WP_Post $post ): string {
		$content = (string) $post->post_content;
		if ( '' === trim( $content ) ) {
			return '';
		}

		$pattern = '#https?://(?:www\.)?(?:youtube\.com/[^\s"\'<>]+|youtu\.be/[^\s"\'<>]+|vimeo\.com/[^\s"\'<>]+)#i';
		if ( preg_match( $pattern, $content, $match ) ) {
			return esc_url_raw( html_entity_decode( $match[0], ENT_QUOTES, 'UTF-8' ) );
		}

		return '';
	}

	/**
	 * Steckt im Beitrag ein Video?
	 *
	 * Erkennt Einbettungsblöcke und die üblichen Videoadressen, damit die
	 * Vorschau ein Abspielsymbol zeigen kann.
	 *
	 * @param \WP_Post $post Türchen-Beitrag.
	 */
	public static function content_has_video( \WP_Post $post ): bool {
		$content = (string) $post->post_content;
		if ( '' === trim( $content ) ) {
			return false;
		}

		return (bool) preg_match(
			'#is-type-video|wp-block-embed-(youtube|vimeo|dailymotion)|<video|youtube\.com|youtu\.be|vimeo\.com#i',
			$content
		);
	}

	/**
	 * Daten für die kleine Vorschau hinter dem geöffneten Türchen.
	 *
	 * @param \WP_Post|null $post Türchen-Beitrag oder null.
	 * @return array{image:int,url:string,text:string,video:bool}
	 */
	public static function preview( ?\WP_Post $post ): array {
		$empty = array(
			'image' => 0,
			'url'   => '',
			'text'  => '',
			'video' => false,
		);

		if ( ! $post instanceof \WP_Post ) {
			return $empty;
		}

		$type  = Doors::sanitize_media_type( self::meta( $post->ID, Doors::META_MEDIA_TYPE, 'none' ) );
		$image = Settings::sanitize_attachment_id( self::meta( $post->ID, Doors::META_PREVIEW_IMAGE, 0 ) );
		$url   = '';

		if ( $image <= 0 ) {
			// Sinnvolle Rückfallbilder je Medientyp.
			if ( 'image' === $type ) {
				$image = Settings::sanitize_attachment_id( self::meta( $post->ID, Doors::META_IMAGE, 0 ) );
			} elseif ( 'gallery' === $type ) {
				$ids   = array_filter( array_map( 'absint', explode( ',', (string) self::meta( $post->ID, Doors::META_GALLERY, '' ) ) ) );
				$image = $ids ? Settings::sanitize_attachment_id( reset( $ids ) ) : 0;
			} elseif ( 'video' === $type ) {
				$image = Settings::sanitize_attachment_id( self::meta( $post->ID, Doors::META_VIDEO_POSTER, 0 ) );
			}
		}

		// Automatisch beim Speichern geholtes Videobild.
		if ( $image <= 0 ) {
			$image = Settings::sanitize_attachment_id( self::meta( $post->ID, Doors::META_AUTO_POSTER, 0 ) );
		}

		// Zuletzt das erste Bild aus dem Text – damit ein Bericht ohne
		// eigenes Vorschaubild nicht als leere Fläche erscheint.
		if ( $image <= 0 ) {
			$found = self::first_content_image( $post );
			$image = $found['id'];
			$url   = $found['url'];
		}

		$text = (string) self::meta( $post->ID, Doors::META_PREVIEW_TEXT, '' );
		if ( '' === trim( $text ) ) {
			$text = self::teaser_from_content( $post );
		}

		return array(
			'image' => $image,
			'url'   => $url,
			'text'  => $text,
			'video' => 'video' === $type || self::content_has_video( $post ),
		);
	}

	/**
	 * Besitzt ein Türchen überhaupt anzeigbaren Inhalt?
	 *
	 * @param \WP_Post|null $post Türchen-Beitrag oder null.
	 */
	public static function has_content( ?\WP_Post $post ): bool {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( '' !== trim( (string) $post->post_content ) ) {
			return true;
		}

		return '' !== self::render_media( $post );
	}
}
