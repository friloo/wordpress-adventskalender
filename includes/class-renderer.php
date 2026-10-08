<?php
/**
 * Frontend-Ausgabe des Adventskalenders.
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rendert Kalenderraster, Türchen und Lightbox.
 */
class Renderer {

	/**
	 * Zähler für eindeutige IDs bei mehreren Kalendern pro Seite.
	 *
	 * @var int
	 */
	private static $instance = 0;

	/**
	 * Wurden die Assets bereits eingebunden?
	 *
	 * @var bool
	 */
	private static $assets_done = false;

	/**
	 * Registriert Styles und Skripte.
	 */
	public static function register_assets(): void {
		wp_register_style(
			'adventskalender',
			plugin_url_base() . 'assets/css/frontend.css',
			array(),
			VERSION
		);

		wp_register_script(
			'adventskalender',
			plugin_url_base() . 'assets/js/frontend.js',
			array(),
			VERSION,
			true
		);
	}

	/**
	 * Bindet die Assets ein und übergibt die Laufzeitdaten.
	 */
	public static function enqueue_assets(): void {
		wp_enqueue_style( 'adventskalender' );
		wp_enqueue_script( 'adventskalender' );

		if ( self::$assets_done ) {
			return;
		}
		self::$assets_done = true;

		$data = array(
			'restUrl' => esc_url_raw( rest_url( Rest::NAMESPACE_V1 . '/' ) ),
			'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'i18n'    => array(
				'loading'     => __( 'Türchen wird geöffnet …', 'adventskalender' ),
				'error'       => __( 'Der Inhalt konnte nicht geladen werden. Bitte später erneut versuchen.', 'adventskalender' ),
				'doorLabel'   => __( 'Türchen %d', 'adventskalender' ),
				'openDoor'    => __( 'Türchen %d öffnen', 'adventskalender' ),
				'lockedDoor'  => __( 'Türchen %1$d – %2$s', 'adventskalender' ),
				'alreadyOpen' => __( 'Türchen %d erneut ansehen', 'adventskalender' ),
			),
		);

		wp_add_inline_script(
			'adventskalender',
			'window.AdventskalenderData = ' . wp_json_encode( $data ) . ';',
			'before'
		);
	}

	/**
	 * Shortcode-Handler für [adventskalender].
	 *
	 * @param array<string,mixed>|string $atts Shortcode-Attribute.
	 */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'year'    => '',
				'layout'  => '',
				'theme'   => '',
				'columns' => '',
				'shuffle' => '',
				'snow'    => '',
				'heading' => '',
				'intro'   => '',
			),
			is_array( $atts ) ? $atts : array(),
			'adventskalender'
		);

		return self::render( $atts );
	}

	/**
	 * Setzt Shortcode-Attribute gegen die Einstellungen durch.
	 *
	 * @param array<string,mixed> $atts Attribute.
	 * @return array<string,mixed>
	 */
	private static function resolve_args( array $atts ): array {
		$settings = Settings::get();

		$year = '' !== (string) ( $atts['year'] ?? '' ) ? absint( $atts['year'] ) : (int) $settings['year'];
		if ( $year < 2000 || $year > 2100 ) {
			$year = (int) $settings['year'];
		}

		$layout = (string) ( $atts['layout'] ?? '' );
		$layout = array_key_exists( $layout, Settings::layouts() ) ? $layout : (string) $settings['layout'];

		$theme = (string) ( $atts['theme'] ?? '' );
		$theme = array_key_exists( $theme, Settings::themes() ) ? $theme : (string) $settings['theme'];

		$columns = '' !== (string) ( $atts['columns'] ?? '' ) ? absint( $atts['columns'] ) : (int) $settings['columns'];
		if ( $columns < 2 || $columns > 8 ) {
			$columns = (int) $settings['columns'];
		}

		$shuffle = '' !== (string) ( $atts['shuffle'] ?? '' )
			? self::to_bool( $atts['shuffle'] )
			: (bool) $settings['shuffle'];

		$snow = '' !== (string) ( $atts['snow'] ?? '' )
			? self::to_bool( $atts['snow'] )
			: (bool) $settings['snow'];

		$heading = '' !== (string) ( $atts['heading'] ?? '' ) ? sanitize_text_field( (string) $atts['heading'] ) : (string) $settings['heading'];
		$intro   = '' !== (string) ( $atts['intro'] ?? '' ) ? wp_kses_post( (string) $atts['intro'] ) : (string) $settings['intro'];

		return array(
			'year'     => $year,
			'layout'   => $layout,
			'theme'    => $theme,
			'columns'  => $columns,
			'shuffle'  => $shuffle,
			'snow'     => $snow,
			'heading'  => $heading,
			'intro'    => $intro,
			'settings' => $settings,
		);
	}

	/**
	 * Wandelt Shortcode-Wahrheitswerte um.
	 *
	 * @param mixed $value Rohwert.
	 */
	private static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'ja', 'on' ), true );
	}

	/**
	 * Erzeugt die (optional zufällige, aber stabile) Reihenfolge der Tage.
	 *
	 * Die Anordnung bleibt für ein Jahr identisch, damit Besucher ihre
	 * Türchen wiederfinden. Ein neuer Seed kann im Admin erzeugt werden.
	 *
	 * @param int  $count   Anzahl Türchen.
	 * @param int  $year    Kalenderjahr.
	 * @param bool $shuffle Mischen?
	 * @param int  $seed    Seed aus den Einstellungen.
	 * @return int[]
	 */
	public static function arrangement( int $count, int $year, bool $shuffle, int $seed ): array {
		$days = range( 1, max( 1, $count ) );

		if ( ! $shuffle ) {
			return $days;
		}

		// Eigener deterministischer Generator – verändert den globalen
		// Zufallszustand von PHP nicht. Bewusst ein Hash statt eines
		// linearen Kongruenzgenerators: dessen niedrige Bits sind schwach,
		// und genau die bräuchte der Modulo unten.
		$base    = 'adventskalender|' . $year . '|' . $seed;
		$counter = 0;
		$next    = static function () use ( $base, &$counter ): int {
			++$counter;

			return (int) hexdec( substr( md5( $base . '|' . $counter ), 0, 8 ) );
		};

		for ( $i = count( $days ) - 1; $i > 0; $i-- ) {
			$j = $next() % ( $i + 1 );
			$tmp         = $days[ $i ];
			$days[ $i ]  = $days[ $j ];
			$days[ $j ]  = $tmp;
		}

		/**
		 * Filtert die Anordnung der Türchen.
		 *
		 * @param int[] $days Reihenfolge der Tage.
		 * @param int   $year Kalenderjahr.
		 */
		return (array) apply_filters( 'adventskalender_arrangement', $days, $year );
	}

	/**
	 * Rendert einen kompletten Kalender.
	 *
	 * @param array<string,mixed> $atts Attribute.
	 */
	public static function render( array $atts = array() ): string {
		$args     = self::resolve_args( $atts );
		$settings = $args['settings'];
		$year     = (int) $args['year'];
		$count    = Availability::door_count();

		self::enqueue_assets();

		++self::$instance;
		$uid = 'ak-' . self::$instance;

		$doors = Doors::get_for_year( $year );
		$order = self::arrangement( $count, $year, (bool) $args['shuffle'], (int) $settings['shuffle_seed'] );
		$cols  = (int) $args['columns'];
		$rows  = (int) ceil( count( $order ) / max( 1, $cols ) );

		$mosaic_url = '';
		if ( 'mosaic' === $args['layout'] ) {
			$mosaic_id = Settings::sanitize_attachment_id( $settings['mosaic_image'] );
			if ( $mosaic_id > 0 ) {
				$mosaic_url = (string) wp_get_attachment_image_url( $mosaic_id, 'full' );
			}
			if ( '' === $mosaic_url ) {
				// Ohne Bild ist der Mosaik-Modus sinnlos – elegant zurückfallen.
				$args['layout'] = 'classic';
			}
		}

		$background_url = '';
		$background_id  = Settings::sanitize_attachment_id( $settings['background_image'] );
		if ( $background_id > 0 ) {
			$background_url = (string) wp_get_attachment_image_url( $background_id, 'full' );
		}

		$styles = array( '--ak-cols:' . $cols, '--ak-rows:' . max( 1, $rows ) );
		if ( '' !== $background_url ) {
			$styles[] = '--ak-bg:url(' . esc_url_raw( $background_url ) . ')';
		}

		$classes = array(
			'ak-calendar',
			'ak-calendar--' . $args['layout'],
			'ak-calendar--theme-' . $args['theme'],
		);
		if ( '' !== $background_url ) {
			$classes[] = 'has-background';
		}
		if ( $args['snow'] ) {
			$classes[] = 'has-snow';
		}

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $uid ); ?>"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			style="<?php echo esc_attr( implode( ';', $styles ) ); ?>"
			data-ak-calendar
			data-year="<?php echo esc_attr( (string) $year ); ?>"
			data-layout="<?php echo esc_attr( (string) $args['layout'] ); ?>"
			data-remember="<?php echo esc_attr( $settings['remember_opened'] ? '1' : '0' ); ?>"
			<?php if ( '' !== $mosaic_url ) : ?>
				data-mosaic="<?php echo esc_url( $mosaic_url ); ?>"
			<?php endif; ?>
		>
			<?php if ( '' !== $args['heading'] || '' !== $args['intro'] ) : ?>
				<header class="ak-calendar__intro">
					<?php if ( '' !== $args['heading'] ) : ?>
						<h2 class="ak-calendar__heading"><?php echo esc_html( (string) $args['heading'] ); ?></h2>
					<?php endif; ?>
					<?php if ( '' !== $args['intro'] ) : ?>
						<div class="ak-calendar__intro-text"><?php echo wp_kses_post( (string) $args['intro'] ); ?></div>
					<?php endif; ?>
				</header>
			<?php endif; ?>

			<?php echo self::render_notices(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bereits escaped. ?>

			<?php if ( $args['snow'] ) : ?>
				<div class="ak-snow" aria-hidden="true"></div>
			<?php endif; ?>

			<div class="ak-grid-wrap">
				<ul class="ak-grid" role="list">
					<?php
					$position = 0;
					foreach ( $order as $day ) {
						echo self::render_door( (int) $day, $year, $doors[ (int) $day ] ?? null, $position, $cols, $rows, (string) $args['layout'], $mosaic_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bereits escaped.
						++$position;
					}
					?>
				</ul>
			</div>

			<?php echo self::render_lightbox( $uid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bereits escaped. ?>

			<div class="ak-toast" data-ak-toast role="status" aria-live="polite"></div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Hinweise oberhalb des Kalenders (Testmodus, Redaktionsvorschau).
	 */
	private static function render_notices(): string {
		$html = '';

		if ( Availability::test_mode_active() && Settings::get( 'test_mode_notice' ) ) {
			$html .= '<p class="ak-notice ak-notice--test" role="status">'
				. '<strong>' . esc_html__( 'Testmodus aktiv:', 'adventskalender' ) . '</strong> '
				. esc_html__( 'Alle Türchen sind unabhängig vom Datum geöffnet.', 'adventskalender' )
				. '</p>';
		} elseif ( Availability::preview_allowed() ) {
			$html .= '<p class="ak-notice ak-notice--preview" role="status">'
				. '<strong>' . esc_html__( 'Redaktionsvorschau:', 'adventskalender' ) . '</strong> '
				. esc_html__( 'Du siehst alle Türchen, weil du angemeldet bist. Besucher sehen nur die freigeschalteten.', 'adventskalender' )
				. '</p>';
		}

		return $html;
	}

	/**
	 * Rendert ein einzelnes Türchen.
	 *
	 * @param int           $day        Tag im Dezember.
	 * @param int           $year       Kalenderjahr.
	 * @param \WP_Post|null $post       Türchen-Beitrag.
	 * @param int           $position   Position im Raster (0-basiert).
	 * @param int           $cols       Spaltenzahl.
	 * @param int           $rows       Zeilenzahl.
	 * @param string        $layout     Layout-Modus.
	 * @param string        $mosaic_url Bild-URL für den Mosaik-Modus.
	 */
	public static function render_door( int $day, int $year, ?\WP_Post $post, int $position, int $cols, int $rows, string $layout, string $mosaic_url = '' ): string {
		$unlocked    = Availability::is_unlocked( $day, $year );
		$has_content = Content::has_content( $post );
		$reason      = Availability::reason( $day, $year );

		if ( ! $unlocked ) {
			$state = 'locked';
		} elseif ( ! $has_content ) {
			$state = 'empty';
		} else {
			$state = 'closed';
		}

		$label = 'locked' === $state
			? sprintf(
				/* translators: 1: Tagesnummer, 2: Hinweistext. */
				__( 'Türchen %1$d – %2$s', 'adventskalender' ),
				$day,
				Availability::locked_notice( $day, $year )
			)
			: sprintf(
				/* translators: %d: Tagesnummer. */
				__( 'Türchen %d öffnen', 'adventskalender' ),
				$day
			);

		$face_style = '';
		$face_inner = '';

		if ( 'mosaic' === $layout && '' !== $mosaic_url ) {
			$col = $cols > 1 ? ( $position % $cols ) * 100 / ( $cols - 1 ) : 0;
			$row = $rows > 1 ? (int) floor( $position / max( 1, $cols ) ) * 100 / ( $rows - 1 ) : 0;

			$face_style = sprintf(
				'background-image:url(%1$s);background-size:%2$s%% %3$s%%;background-position:%4$s%% %5$s%%',
				esc_url_raw( $mosaic_url ),
				$cols * 100,
				max( 1, $rows ) * 100,
				round( $col, 4 ),
				round( $row, 4 )
			);
		} elseif ( 'individual' === $layout && $post instanceof \WP_Post ) {
			$door_image = Settings::sanitize_attachment_id( get_post_meta( $post->ID, Doors::META_DOOR_IMAGE, true ) );
			if ( $door_image > 0 ) {
				$face_inner = wp_get_attachment_image(
					$door_image,
					'medium_large',
					false,
					array(
						'class'   => 'ak-door__image',
						'loading' => 'lazy',
						'alt'     => '',
					)
				);
			}
		}

		$attributes = array(
			'type'        => 'button',
			'class'       => 'ak-door ak-door--' . $state,
			'data-day'    => (string) $day,
			'data-state'  => $state,
			'aria-label'    => $label,
			'aria-expanded' => 'false',
			'aria-haspopup' => 'dialog',
		);

		if ( 'locked' === $state ) {
			$attributes['aria-disabled'] = 'true';
			$attributes['data-notice']   = Availability::locked_notice( $day, $year );
		} elseif ( 'empty' === $state ) {
			$attributes['data-notice'] = __( 'Für dieses Türchen ist noch kein Inhalt hinterlegt.', 'adventskalender' );
		}

		if ( Availability::REASON_PREVIEW === $reason ) {
			$attributes['data-preview-only'] = '1';
		}

		$attr_html = '';
		foreach ( $attributes as $name => $value ) {
			$attr_html .= ' ' . esc_attr( $name ) . '="' . esc_attr( (string) $value ) . '"';
		}

		$html  = '<li class="ak-cell">';
		$html .= '<button' . $attr_html . '>';

		// Inhalt hinter dem Türchen – nur für freigeschaltete Türchen.
		$html .= '<span class="ak-door__inside" data-ak-inside aria-hidden="true">';
		if ( 'locked' !== $state ) {
			$html .= self::render_preview( $post );
		} else {
			$html .= '<span class="ak-door__lock" aria-hidden="true">'
				. '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="4" y="10.5" width="16" height="10.5" rx="2.5"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/></svg>'
				. '</span>';
		}
		$html .= '</span>';

		// Die eigentliche Klappe.
		$html .= '<span class="ak-door__flap" aria-hidden="true">';
		$html .= '<span class="ak-door__front"' . ( '' !== $face_style ? ' style="' . esc_attr( $face_style ) . '"' : '' ) . '>';
		$html .= $face_inner;
		$html .= '<span class="ak-door__number">' . esc_html( number_format_i18n( $day ) ) . '</span>';
		$html .= '<span class="ak-door__knob" aria-hidden="true"></span>';
		$html .= '</span>';
		$html .= '<span class="ak-door__back"></span>';
		$html .= '</span>';

		$html .= '</button>';
		$html .= '</li>';

		return $html;
	}

	/**
	 * Rendert die kleine Vorschau hinter dem Türchen.
	 *
	 * @param \WP_Post|null $post Türchen-Beitrag.
	 */
	public static function render_preview( ?\WP_Post $post ): string {
		$preview = Content::preview( $post );
		$html    = '';

		if ( $preview['image'] > 0 ) {
			$html .= wp_get_attachment_image(
				$preview['image'],
				'medium',
				false,
				array(
					'class'   => 'ak-door__preview-image',
					'loading' => 'lazy',
					'alt'     => '',
				)
			);
		}

		if ( '' !== trim( (string) $preview['text'] ) ) {
			$html .= '<span class="ak-door__preview-text">' . esc_html( (string) $preview['text'] ) . '</span>';
		}

		if ( '' === $html ) {
			$html = '<span class="ak-door__preview-icon" aria-hidden="true">'
				. '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M12 3.5 14.4 9l5.6.6-4.2 4 1.2 5.6L12 16.4 6.9 19.2 8.1 13.6 4 9.6 9.6 9z"/></svg>'
				. '</span>';
		}

		return '<span class="ak-door__preview">' . $html . '</span>';
	}

	/**
	 * Rendert die Lightbox-Hülle.
	 *
	 * @param string $uid Eindeutiges Präfix.
	 */
	private static function render_lightbox( string $uid ): string {
		$title_id = $uid . '-title';

		ob_start();
		?>
		<div class="ak-lightbox" data-ak-lightbox hidden>
			<div class="ak-lightbox__backdrop" data-ak-close></div>
			<div class="ak-lightbox__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $title_id ); ?>" tabindex="-1" data-ak-dialog>
				<button type="button" class="ak-lightbox__close" data-ak-close aria-label="<?php esc_attr_e( 'Schließen', 'adventskalender' ); ?>">
					<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" focusable="false" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
				</button>
				<div class="ak-lightbox__header">
					<p class="ak-lightbox__day" data-ak-day></p>
					<h2 class="ak-lightbox__title" id="<?php echo esc_attr( $title_id ); ?>" data-ak-title></h2>
				</div>
				<div class="ak-lightbox__content" data-ak-content>
					<p class="ak-lightbox__loading"><?php esc_html_e( 'Türchen wird geöffnet …', 'adventskalender' ); ?></p>
				</div>
				<div class="ak-lightbox__footer">
					<button type="button" class="ak-lightbox__nav ak-lightbox__nav--prev" data-ak-prev>
						<span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Vorheriges', 'adventskalender' ); ?>
					</button>
					<button type="button" class="ak-lightbox__nav ak-lightbox__nav--next" data-ak-next>
						<?php esc_html_e( 'Nächstes', 'adventskalender' ); ?> <span aria-hidden="true">&rarr;</span>
					</button>
				</div>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}
}
