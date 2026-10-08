<?php
/**
 * Bearbeitungsmaske eines Türchens.
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metaboxen und Speicherlogik.
 */
class Metabox {

	/**
	 * Name des Nonce-Felds.
	 */
	const NONCE = 'adventskalender_door_nonce';

	/**
	 * Registriert die Hooks.
	 */
	public static function hooks(): void {
		add_action( 'add_meta_boxes_' . Doors::POST_TYPE, array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . Doors::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'editor_hint' ) );
	}

	/**
	 * Platzhalter im Titelfeld.
	 *
	 * @param string    $text Vorhandener Text.
	 * @param \WP_Post  $post Beitrag.
	 */
	public static function title_placeholder( $text, $post ) {
		if ( $post instanceof \WP_Post && Doors::POST_TYPE === $post->post_type ) {
			return __( 'Titel des Türchens (erscheint in der Lightbox)', 'adventskalender' );
		}

		return $text;
	}

	/**
	 * Kurzer Hinweis über dem Editor.
	 *
	 * @param \WP_Post $post Beitrag.
	 */
	public static function editor_hint( $post ): void {
		if ( ! $post instanceof \WP_Post || Doors::POST_TYPE !== $post->post_type ) {
			return;
		}

		echo '<p class="ak-editor-hint">'
			. esc_html__( 'Der Inhalt aus dem Editor erscheint als Text in der Lightbox – inklusive eingefügter Bilder. Medien, Vorschau und Zeitfenster stellst du rechts bzw. unten ein.', 'adventskalender' )
			. '</p>';
	}

	/**
	 * Registriert die Metaboxen.
	 */
	public static function register(): void {
		add_meta_box(
			'ak_schedule',
			__( 'Zeitfenster', 'adventskalender' ),
			array( __CLASS__, 'render_schedule' ),
			Doors::POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'ak_media',
			__( 'Inhalt des Türchens', 'adventskalender' ),
			array( __CLASS__, 'render_media' ),
			Doors::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'ak_preview',
			__( 'Vorschau hinter dem Türchen', 'adventskalender' ),
			array( __CLASS__, 'render_preview' ),
			Doors::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Liest einen Meta-Wert mit Rückfallwert.
	 *
	 * @param int    $post_id Beitrags-ID.
	 * @param string $key     Meta-Schlüssel.
	 * @param mixed  $default Rückfallwert.
	 * @return mixed
	 */
	private static function value( int $post_id, string $key, $default = '' ) {
		$value = get_post_meta( $post_id, $key, true );

		return ( '' === $value || null === $value ) ? $default : $value;
	}

	/**
	 * Metabox „Zeitfenster“.
	 *
	 * @param \WP_Post $post Beitrag.
	 */
	public static function render_schedule( \WP_Post $post ): void {
		wp_nonce_field( 'adventskalender_save_door', self::NONCE );

		$count = Availability::door_count();

		// Beim Anlegen aus der Übersicht Tag/Jahr vorbelegen.
		$prefill_day  = isset( $_GET['ak_day'] ) ? absint( wp_unslash( $_GET['ak_day'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$prefill_year = isset( $_GET['ak_year'] ) ? absint( wp_unslash( $_GET['ak_year'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$day  = (int) self::value( $post->ID, Doors::META_DAY, 0 );
		$year = (int) self::value( $post->ID, Doors::META_YEAR, 0 );

		if ( 0 === $day && $prefill_day >= 1 && $prefill_day <= 31 ) {
			$day = $prefill_day;
		}
		if ( 0 === $year ) {
			$year = ( $prefill_year >= 2000 && $prefill_year <= 2100 ) ? $prefill_year : (int) Settings::get( 'year' );
		}

		// Bereits belegte Tage im selben Jahr ermitteln.
		$taken = array();
		foreach ( Doors::get_for_year( $year, array( 'publish', 'draft', 'pending', 'private', 'future' ) ) as $taken_day => $taken_post ) {
			if ( $taken_post->ID !== $post->ID ) {
				$taken[] = (int) $taken_day;
			}
		}
		?>
		<p>
			<label for="ak-field-day"><strong><?php esc_html_e( 'Tag im Dezember', 'adventskalender' ); ?></strong></label><br />
			<select id="ak-field-day" name="ak_day" class="widefat">
				<option value="0"><?php esc_html_e( '— bitte wählen —', 'adventskalender' ); ?></option>
				<?php for ( $i = 1; $i <= $count; $i++ ) : ?>
					<option value="<?php echo esc_attr( (string) $i ); ?>" <?php selected( $i, $day ); ?>>
						<?php
						echo esc_html(
							in_array( $i, $taken, true )
								/* translators: %d: Tagesnummer. */
								? sprintf( __( '%d. Dezember (bereits belegt)', 'adventskalender' ), $i )
								/* translators: %d: Tagesnummer. */
								: sprintf( __( '%d. Dezember', 'adventskalender' ), $i )
						);
						?>
					</option>
				<?php endfor; ?>
			</select>
		</p>
		<p>
			<label for="ak-field-year"><strong><?php esc_html_e( 'Kalenderjahr', 'adventskalender' ); ?></strong></label><br />
			<input type="number" id="ak-field-year" name="ak_year" class="widefat" min="2000" max="2100" step="1" value="<?php echo esc_attr( (string) $year ); ?>" />
		</p>
		<?php if ( $day >= 1 ) : ?>
			<p class="ak-schedule__info">
				<?php
				printf(
					/* translators: %s: Datum. */
					esc_html__( 'Öffnet am %s, 00:00 Uhr (Zeitzone der Website).', 'adventskalender' ),
					esc_html( Availability::format_unlock_date( $day, $year ) )
				);
				?>
			</p>
			<?php if ( in_array( $day, $taken, true ) ) : ?>
				<p class="ak-schedule__warning">
					<?php esc_html_e( 'Achtung: Für diesen Tag existiert bereits ein Türchen. Angezeigt wird nur eines davon.', 'adventskalender' ); ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
		<p class="ak-schedule__info">
			<?php esc_html_e( 'Nur veröffentlichte Türchen erscheinen im Kalender.', 'adventskalender' ); ?>
		</p>
		<?php
	}

	/**
	 * Metabox „Inhalt“.
	 *
	 * @param \WP_Post $post Beitrag.
	 */
	public static function render_media( \WP_Post $post ): void {
		$media_type = Doors::sanitize_media_type( self::value( $post->ID, Doors::META_MEDIA_TYPE, 'none' ) );
		$layout     = Doors::sanitize_layout( self::value( $post->ID, Doors::META_LAYOUT, 'media_top' ) );
		$source     = Doors::sanitize_video_source( self::value( $post->ID, Doors::META_VIDEO_SOURCE, 'embed' ) );
		?>
		<div class="ak-fields" data-ak-fields>
			<div class="ak-field">
				<span class="ak-field__label"><?php esc_html_e( 'Welches Medium?', 'adventskalender' ); ?></span>
				<div class="ak-chips">
					<?php foreach ( Doors::media_types() as $key => $label ) : ?>
						<label class="ak-chip">
							<input type="radio" name="ak_media_type" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, $media_type ); ?> data-ak-media-type />
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="ak-field" data-ak-when="image">
				<label class="ak-field__label"><?php esc_html_e( 'Bild', 'adventskalender' ); ?></label>
				<?php Admin::media_field( 'ak_image', (int) self::value( $post->ID, Doors::META_IMAGE, 0 ) ); ?>
			</div>

			<div class="ak-field" data-ak-when="gallery">
				<label class="ak-field__label"><?php esc_html_e( 'Bildergalerie', 'adventskalender' ); ?></label>
				<?php self::gallery_field( 'ak_gallery', (string) self::value( $post->ID, Doors::META_GALLERY, '' ) ); ?>
			</div>

			<div class="ak-field" data-ak-when="video">
				<span class="ak-field__label"><?php esc_html_e( 'Videoquelle', 'adventskalender' ); ?></span>
				<div class="ak-chips">
					<?php foreach ( Doors::video_sources() as $key => $label ) : ?>
						<label class="ak-chip">
							<input type="radio" name="ak_video_source" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, $source ); ?> data-ak-video-source />
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<div class="ak-subfield" data-ak-when-video="embed">
					<label for="ak-field-video-url"><?php esc_html_e( 'Video-URL', 'adventskalender' ); ?></label>
					<input type="url" id="ak-field-video-url" class="large-text code" name="ak_video_url" placeholder="https://www.youtube.com/watch?v=…" value="<?php echo esc_attr( (string) self::value( $post->ID, Doors::META_VIDEO_URL, '' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'YouTube, Vimeo und alle von WordPress unterstützten Anbieter. Direkte MP4-/WebM-Adressen funktionieren ebenfalls.', 'adventskalender' ); ?></p>
				</div>

				<div class="ak-subfield" data-ak-when-video="file">
					<label class="ak-field__label"><?php esc_html_e( 'Videodatei', 'adventskalender' ); ?></label>
					<?php Admin::media_field( 'ak_video_file', (int) self::value( $post->ID, Doors::META_VIDEO_FILE, 0 ), 'video' ); ?>
				</div>

				<div class="ak-subfield">
					<label class="ak-field__label"><?php esc_html_e( 'Vorschaubild des Videos (optional)', 'adventskalender' ); ?></label>
					<?php Admin::media_field( 'ak_video_poster', (int) self::value( $post->ID, Doors::META_VIDEO_POSTER, 0 ) ); ?>
				</div>
			</div>

			<div class="ak-field">
				<label class="ak-field__label" for="ak-field-layout"><?php esc_html_e( 'Anordnung in der Lightbox', 'adventskalender' ); ?></label>
				<select id="ak-field-layout" name="ak_layout">
					<?php foreach ( Doors::layouts() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $layout ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="ak-field ak-field--split">
				<div>
					<label class="ak-field__label" for="ak-field-link-url"><?php esc_html_e( 'Button-Link (optional)', 'adventskalender' ); ?></label>
					<input type="url" id="ak-field-link-url" class="large-text code" name="ak_link_url" placeholder="https://…" value="<?php echo esc_attr( (string) self::value( $post->ID, Doors::META_LINK_URL, '' ) ); ?>" />
				</div>
				<div>
					<label class="ak-field__label" for="ak-field-link-label"><?php esc_html_e( 'Button-Text', 'adventskalender' ); ?></label>
					<input type="text" id="ak-field-link-label" class="regular-text" name="ak_link_label" placeholder="<?php esc_attr_e( 'Mehr erfahren', 'adventskalender' ); ?>" value="<?php echo esc_attr( (string) self::value( $post->ID, Doors::META_LINK_LABEL, '' ) ); ?>" />
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Metabox „Vorschau“.
	 *
	 * @param \WP_Post $post Beitrag.
	 */
	public static function render_preview( \WP_Post $post ): void {
		$layout = (string) Settings::get( 'layout' );
		?>
		<div class="ak-fields">
			<p class="description">
				<?php esc_html_e( 'Diese kleine Vorschau wird sichtbar, sobald sich das Türchen öffnet – noch vor der Lightbox.', 'adventskalender' ); ?>
			</p>

			<div class="ak-field ak-field--split">
				<div>
					<label class="ak-field__label"><?php esc_html_e( 'Vorschaubild', 'adventskalender' ); ?></label>
					<?php Admin::media_field( 'ak_preview_image', (int) self::value( $post->ID, Doors::META_PREVIEW_IMAGE, 0 ) ); ?>
					<p class="description"><?php esc_html_e( 'Ohne Angabe wird automatisch das Bild bzw. Video-Vorschaubild verwendet.', 'adventskalender' ); ?></p>
				</div>
				<div>
					<label class="ak-field__label" for="ak-field-preview-text"><?php esc_html_e( 'Vorschautext', 'adventskalender' ); ?></label>
					<input type="text" id="ak-field-preview-text" class="large-text" name="ak_preview_text" maxlength="120" value="<?php echo esc_attr( (string) self::value( $post->ID, Doors::META_PREVIEW_TEXT, '' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Kurzer Teaser, max. 120 Zeichen. Ohne Angabe werden die ersten Worte des Inhalts verwendet.', 'adventskalender' ); ?></p>
				</div>
			</div>

			<div class="ak-field">
				<label class="ak-field__label"><?php esc_html_e( 'Motiv des geschlossenen Türchens', 'adventskalender' ); ?></label>
				<?php Admin::media_field( 'ak_door_image', (int) self::value( $post->ID, Doors::META_DOOR_IMAGE, 0 ) ); ?>
				<p class="description">
					<?php
					if ( 'individual' === $layout ) {
						esc_html_e( 'Wird im aktuell gewählten Layout „Einzelbilder“ auf dem geschlossenen Türchen angezeigt.', 'adventskalender' );
					} else {
						printf(
							/* translators: %s: Name des Layouts. */
							esc_html__( 'Wird nur im Layout „Einzelbilder“ verwendet. Aktuell ist „%s“ eingestellt.', 'adventskalender' ),
							esc_html( (string) ( Settings::layouts()[ $layout ] ?? $layout ) )
						);
					}
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Rendert das Galerie-Auswahlfeld.
	 *
	 * @param string $field_name Feldname.
	 * @param string $value      Kommaseparierte IDs.
	 */
	private static function gallery_field( string $field_name, string $value ): void {
		$ids = array_filter( array_map( array( Settings::class, 'sanitize_attachment_id' ), explode( ',', $value ) ) );
		?>
		<div class="ak-gallery-field" data-ak-gallery>
			<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" data-ak-gallery-input />
			<ul class="ak-gallery-field__list" data-ak-gallery-list>
				<?php foreach ( $ids as $id ) : ?>
					<li data-id="<?php echo esc_attr( (string) $id ); ?>">
						<?php echo wp_get_attachment_image( (int) $id, 'thumbnail', false, array( 'alt' => '' ) ); ?>
						<button type="button" class="ak-gallery-field__remove" data-ak-gallery-remove aria-label="<?php esc_attr_e( 'Bild entfernen', 'adventskalender' ); ?>">&times;</button>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<button type="button" class="button" data-ak-gallery-select><?php esc_html_e( 'Bilder auswählen', 'adventskalender' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Speichert alle Felder eines Türchens.
	 *
	 * @param int      $post_id Beitrags-ID.
	 * @param \WP_Post $post    Beitrag.
	 */
	public static function save( $post_id, $post = null ): void {
		$post_id = (int) $post_id;

		// Autosave, Revisionen und REST-Massenaktionen überspringen.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( $post instanceof \WP_Post && Doors::POST_TYPE !== $post->post_type ) {
			return;
		}

		// Nonce prüfen – ohne gültiges Formular wird nichts geschrieben.
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE ] ) );
		if ( ! wp_verify_nonce( $nonce, 'adventskalender_save_door' ) ) {
			return;
		}

		// Berechtigung prüfen.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Felder werden einzeln geprüft.

		// Nur Felder anfassen, die das Formular tatsächlich mitgeschickt hat.
		// Sonst würden ausgeblendete Metaboxen (Ansicht anpassen) beim
		// Speichern vorhandene Inhalte löschen.
		$sent = static function ( $field ) use ( $raw ) {
			return array_key_exists( $field, $raw );
		};

		if ( $sent( 'ak_day' ) ) {
			$day = absint( $raw['ak_day'] );
			if ( $day >= 1 && $day <= 31 ) {
				update_post_meta( $post_id, Doors::META_DAY, $day );
			} else {
				delete_post_meta( $post_id, Doors::META_DAY );
			}
		}

		if ( $sent( 'ak_year' ) ) {
			$year = absint( $raw['ak_year'] );
			if ( $year < 2000 || $year > 2100 ) {
				$year = (int) Settings::get( 'year' );
			}
			update_post_meta( $post_id, Doors::META_YEAR, $year );
		}

		if ( $sent( 'ak_media_type' ) ) {
			update_post_meta( $post_id, Doors::META_MEDIA_TYPE, Doors::sanitize_media_type( $raw['ak_media_type'] ) );
		}
		if ( $sent( 'ak_layout' ) ) {
			update_post_meta( $post_id, Doors::META_LAYOUT, Doors::sanitize_layout( $raw['ak_layout'] ) );
		}
		if ( $sent( 'ak_video_source' ) ) {
			update_post_meta( $post_id, Doors::META_VIDEO_SOURCE, Doors::sanitize_video_source( $raw['ak_video_source'] ) );
		}

		foreach ( array(
			'ak_image'         => Doors::META_IMAGE,
			'ak_video_file'    => Doors::META_VIDEO_FILE,
			'ak_video_poster'  => Doors::META_VIDEO_POSTER,
			'ak_preview_image' => Doors::META_PREVIEW_IMAGE,
			'ak_door_image'    => Doors::META_DOOR_IMAGE,
		) as $field => $meta_key ) {
			if ( ! $sent( $field ) ) {
				continue;
			}
			$attachment = Settings::sanitize_attachment_id( $raw[ $field ] );
			if ( $attachment > 0 ) {
				update_post_meta( $post_id, $meta_key, $attachment );
			} else {
				delete_post_meta( $post_id, $meta_key );
			}
		}

		if ( $sent( 'ak_gallery' ) ) {
			$gallery = Doors::sanitize_id_list( $raw['ak_gallery'] );
			if ( '' !== $gallery ) {
				update_post_meta( $post_id, Doors::META_GALLERY, $gallery );
			} else {
				delete_post_meta( $post_id, Doors::META_GALLERY );
			}
		}

		foreach ( array(
			'ak_video_url' => Doors::META_VIDEO_URL,
			'ak_link_url'  => Doors::META_LINK_URL,
		) as $field => $meta_key ) {
			if ( ! $sent( $field ) ) {
				continue;
			}
			$url = esc_url_raw( trim( (string) $raw[ $field ] ) );
			if ( '' !== $url && wp_http_validate_url( $url ) ) {
				update_post_meta( $post_id, $meta_key, $url );
			} else {
				delete_post_meta( $post_id, $meta_key );
			}
		}

		foreach ( array(
			'ak_link_label'   => Doors::META_LINK_LABEL,
			'ak_preview_text' => Doors::META_PREVIEW_TEXT,
		) as $field => $meta_key ) {
			if ( ! $sent( $field ) ) {
				continue;
			}
			$text = sanitize_text_field( (string) $raw[ $field ] );
			if ( '' !== $text ) {
				update_post_meta( $post_id, $meta_key, $text );
			} else {
				delete_post_meta( $post_id, $meta_key );
			}
		}

		Doors::flush_cache();
	}
}
