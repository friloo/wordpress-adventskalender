<?php
/**
 * Administrationsoberfläche.
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menüs, Übersichtsseite und Einstellungsseite.
 */
class Admin {

	/**
	 * Slug der Übersichtsseite.
	 */
	const PAGE_OVERVIEW = 'adventskalender';

	/**
	 * Slug der Einstellungsseite.
	 */
	const PAGE_SETTINGS = 'adventskalender-settings';

	/**
	 * Registriert die Admin-Hooks.
	 */
	public static function hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_adventskalender_create_missing', array( __CLASS__, 'handle_create_missing' ) );
		add_action( 'admin_post_adventskalender_toggle_test', array( __CLASS__, 'handle_toggle_test' ) );
		add_filter( 'manage_' . Doors::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Doors::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . Doors::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'order_admin_list' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
	}

	/**
	 * Benötigte Berechtigung für die Türchen-Verwaltung.
	 */
	public static function manage_capability(): string {
		/**
		 * Filtert die Capability zur Verwaltung der Türchen.
		 *
		 * @param string $capability Capability.
		 */
		return (string) apply_filters( 'adventskalender_manage_capability', 'edit_posts' );
	}

	/**
	 * Registriert Menü und Untermenüs.
	 */
	public static function register_menu(): void {
		$cap = self::manage_capability();

		add_menu_page(
			__( 'Adventskalender', 'adventskalender' ),
			__( 'Adventskalender', 'adventskalender' ),
			$cap,
			self::PAGE_OVERVIEW,
			array( __CLASS__, 'render_overview' ),
			'dashicons-calendar-alt',
			26
		);

		add_submenu_page(
			self::PAGE_OVERVIEW,
			__( 'Kalender-Übersicht', 'adventskalender' ),
			__( 'Übersicht', 'adventskalender' ),
			$cap,
			self::PAGE_OVERVIEW,
			array( __CLASS__, 'render_overview' )
		);

		add_submenu_page(
			self::PAGE_OVERVIEW,
			__( 'Alle Türchen', 'adventskalender' ),
			__( 'Alle Türchen', 'adventskalender' ),
			$cap,
			'edit.php?post_type=' . Doors::POST_TYPE
		);

		add_submenu_page(
			self::PAGE_OVERVIEW,
			__( 'Türchen hinzufügen', 'adventskalender' ),
			__( 'Türchen hinzufügen', 'adventskalender' ),
			$cap,
			'post-new.php?post_type=' . Doors::POST_TYPE
		);

		add_submenu_page(
			self::PAGE_OVERVIEW,
			__( 'Einstellungen', 'adventskalender' ),
			__( 'Einstellungen', 'adventskalender' ),
			'manage_options',
			self::PAGE_SETTINGS,
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * Lädt Admin-Assets nur auf den eigenen Seiten.
	 *
	 * @param string $hook_suffix Aktuelle Admin-Seite.
	 */
	public static function enqueue( string $hook_suffix ): void {
		$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_door   = $screen && Doors::POST_TYPE === $screen->post_type;
		$is_own    = false !== strpos( $hook_suffix, self::PAGE_OVERVIEW ) || false !== strpos( $hook_suffix, self::PAGE_SETTINGS );

		if ( ! $is_door && ! $is_own ) {
			return;
		}

		wp_enqueue_style(
			'adventskalender-admin',
			plugin_url_base() . 'assets/css/admin.css',
			array(),
			VERSION
		);

		wp_enqueue_media();

		wp_enqueue_script(
			'adventskalender-admin',
			plugin_url_base() . 'assets/js/admin.js',
			array( 'jquery' ),
			VERSION,
			true
		);

		wp_localize_script(
			'adventskalender-admin',
			'AdventskalenderAdmin',
			array(
				'i18n' => array(
					'selectImage'  => __( 'Bild auswählen', 'adventskalender' ),
					'selectVideo'  => __( 'Video auswählen', 'adventskalender' ),
					'useImage'     => __( 'Dieses Bild verwenden', 'adventskalender' ),
					'useVideo'     => __( 'Dieses Video verwenden', 'adventskalender' ),
					'selectImages' => __( 'Bilder auswählen', 'adventskalender' ),
					'useImages'    => __( 'Diese Bilder verwenden', 'adventskalender' ),
					'remove'       => __( 'Entfernen', 'adventskalender' ),
					'copied'       => __( 'Kopiert!', 'adventskalender' ),
					'confirmShuffle' => __( 'Neu mischen ändert die Anordnung für alle Besucher. Fortfahren?', 'adventskalender' ),
				),
			)
		);
	}

	/**
	 * Ermittelt das in der Oberfläche gewählte Jahr.
	 */
	private static function current_year(): int {
		$year = isset( $_GET['ak_year'] ) ? absint( wp_unslash( $_GET['ak_year'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reiner Anzeigefilter.

		if ( $year >= 2000 && $year <= 2100 ) {
			return $year;
		}

		return (int) Settings::get( 'year' );
	}

	/**
	 * Rendert die Übersichtsseite.
	 */
	public static function render_overview(): void {
		if ( ! current_user_can( self::manage_capability() ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diese Seite.', 'adventskalender' ) );
		}

		$year     = self::current_year();
		$count    = Availability::door_count();
		$doors    = Doors::get_for_year( $year, array( 'publish', 'draft', 'pending', 'private', 'future' ) );
		$years    = Doors::get_years();
		$filled   = 0;
		$drafts   = 0;
		$missing  = array();

		for ( $day = 1; $day <= $count; $day++ ) {
			$post = $doors[ $day ] ?? null;
			if ( ! $post instanceof \WP_Post ) {
				$missing[] = $day;
				continue;
			}
			if ( 'publish' !== $post->post_status ) {
				++$drafts;
			}
			if ( Content::has_content( $post ) && 'publish' === $post->post_status ) {
				++$filled;
			}
		}

		// „heute“ nur markieren, wenn das angezeigte Jahr auch das laufende ist.
		$now   = current_datetime();
		$today = ( (int) $now->format( 'Y' ) === $year && 12 === (int) $now->format( 'n' ) )
			? (int) $now->format( 'j' )
			: 0;
		?>
		<div class="wrap ak-admin">
			<h1 class="ak-admin__title">
				<?php esc_html_e( 'Adventskalender', 'adventskalender' ); ?>
				<span class="ak-admin__year-badge"><?php echo esc_html( (string) $year ); ?></span>
			</h1>

			<div class="ak-admin__bar">
				<form method="get" class="ak-admin__yearform">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_OVERVIEW ); ?>" />
					<label for="ak-year-select" class="screen-reader-text"><?php esc_html_e( 'Jahr wählen', 'adventskalender' ); ?></label>
					<select name="ak_year" id="ak-year-select">
						<?php foreach ( $years as $option_year ) : ?>
							<option value="<?php echo esc_attr( (string) $option_year ); ?>" <?php selected( $option_year, $year ); ?>>
								<?php echo esc_html( (string) $option_year ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button"><?php esc_html_e( 'Anzeigen', 'adventskalender' ); ?></button>
				</form>

				<div class="ak-admin__actions">
					<?php if ( ! empty( $missing ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'adventskalender_create_missing' ); ?>
							<input type="hidden" name="action" value="adventskalender_create_missing" />
							<input type="hidden" name="ak_year" value="<?php echo esc_attr( (string) $year ); ?>" />
							<button type="submit" class="button button-secondary">
								<?php
								printf(
									/* translators: %d: Anzahl fehlender Türchen. */
									esc_html__( '%d fehlende Türchen als Entwurf anlegen', 'adventskalender' ),
									count( $missing )
								);
								?>
							</button>
						</form>
					<?php endif; ?>

					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . Doors::POST_TYPE ) ); ?>">
						<?php esc_html_e( 'Türchen hinzufügen', 'adventskalender' ); ?>
					</a>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SETTINGS ) ); ?>">
							<?php esc_html_e( 'Einstellungen', 'adventskalender' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>

			<div class="ak-admin__stats">
				<div class="ak-stat">
					<span class="ak-stat__value"><?php echo esc_html( $filled . ' / ' . $count ); ?></span>
					<span class="ak-stat__label"><?php esc_html_e( 'veröffentlicht & gefüllt', 'adventskalender' ); ?></span>
				</div>
				<div class="ak-stat<?php echo $drafts > 0 ? ' is-warning' : ''; ?>">
					<span class="ak-stat__value"><?php echo esc_html( (string) $drafts ); ?></span>
					<span class="ak-stat__label"><?php esc_html_e( 'Entwürfe (noch nicht sichtbar)', 'adventskalender' ); ?></span>
				</div>
				<div class="ak-stat<?php echo ! empty( $missing ) ? ' is-warning' : ''; ?>">
					<span class="ak-stat__value"><?php echo esc_html( (string) count( $missing ) ); ?></span>
					<span class="ak-stat__label"><?php esc_html_e( 'fehlende Türchen', 'adventskalender' ); ?></span>
				</div>
				<div class="ak-stat<?php echo Availability::test_mode_active() ? ' is-active' : ''; ?>">
					<span class="ak-stat__value">
						<?php echo Availability::test_mode_active() ? esc_html__( 'AN', 'adventskalender' ) : esc_html__( 'AUS', 'adventskalender' ); ?>
					</span>
					<span class="ak-stat__label">
						<?php esc_html_e( 'Testmodus', 'adventskalender' ); ?>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ak-stat__toggle">
								<?php wp_nonce_field( 'adventskalender_toggle_test' ); ?>
								<input type="hidden" name="action" value="adventskalender_toggle_test" />
								<button type="submit" class="button-link">
									<?php echo Availability::test_mode_active() ? esc_html__( 'ausschalten', 'adventskalender' ) : esc_html__( 'einschalten', 'adventskalender' ); ?>
								</button>
							</form>
						<?php endif; ?>
					</span>
				</div>
			</div>

			<?php if ( Availability::test_mode_active() ) : ?>
				<div class="notice notice-warning ak-admin__notice">
					<p>
						<strong><?php esc_html_e( 'Der Testmodus ist aktiv.', 'adventskalender' ); ?></strong>
						<?php esc_html_e( 'Alle Besucher können jedes Türchen öffnen – unabhängig vom Datum. Bitte vor dem 1. Dezember ausschalten.', 'adventskalender' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="ak-admin__shortcode">
				<label for="ak-shortcode"><?php esc_html_e( 'Kalender einbinden – Shortcode:', 'adventskalender' ); ?></label>
				<input type="text" id="ak-shortcode" class="ak-admin__code" readonly value="<?php echo esc_attr( '[adventskalender]' ); ?>" />
				<button type="button" class="button" data-ak-copy="#ak-shortcode"><?php esc_html_e( 'Kopieren', 'adventskalender' ); ?></button>
				<p class="description">
					<?php esc_html_e( 'Alternativ steht der Block „Adventskalender“ im Block-Editor zur Verfügung. Optionale Attribute: year, layout, theme, columns, shuffle, snow.', 'adventskalender' ); ?>
				</p>
			</div>

			<ol class="ak-admin__grid">
				<?php
				for ( $day = 1; $day <= $count; $day++ ) :
					$post        = $doors[ $day ] ?? null;
					$exists      = $post instanceof \WP_Post;
					$published   = $exists && 'publish' === $post->post_status;
					$has_content = $exists && Content::has_content( $post );
					$unlocked    = Availability::is_unlocked( $day, $year );
					$preview     = $exists ? Content::preview( $post ) : array(
						'image' => 0,
						'text'  => '',
					);

					$card_classes = array( 'ak-card' );
					if ( ! $exists ) {
						$card_classes[] = 'is-missing';
					} elseif ( ! $published ) {
						$card_classes[] = 'is-draft';
					} elseif ( ! $has_content ) {
						$card_classes[] = 'is-empty';
					} else {
						$card_classes[] = 'is-ready';
					}
					if ( $day === $today ) {
						$card_classes[] = 'is-today';
					}
					?>
					<li class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>">
						<div class="ak-card__head">
							<span class="ak-card__day"><?php echo esc_html( (string) $day ); ?></span>
							<span class="ak-card__date"><?php echo esc_html( Availability::format_unlock_date( $day, $year ) ); ?></span>
							<?php if ( $day === $today ) : ?>
								<span class="ak-card__badge ak-card__badge--today"><?php esc_html_e( 'heute', 'adventskalender' ); ?></span>
							<?php endif; ?>
						</div>

						<?php if ( $preview['image'] > 0 ) : ?>
							<div class="ak-card__thumb">
								<?php echo wp_get_attachment_image( (int) $preview['image'], 'medium', false, array( 'alt' => '' ) ); ?>
							</div>
						<?php endif; ?>

						<div class="ak-card__body">
							<?php if ( $exists ) : ?>
								<p class="ak-card__title"><?php echo esc_html( get_the_title( $post ) ); ?></p>
								<p class="ak-card__meta">
									<?php
									$type_labels = Doors::media_types();
									$type        = Doors::sanitize_media_type( get_post_meta( $post->ID, Doors::META_MEDIA_TYPE, true ) );
									echo esc_html( $type_labels[ $type ] ?? '' );
									?>
								</p>
							<?php else : ?>
								<p class="ak-card__title ak-card__title--muted"><?php esc_html_e( 'Noch kein Türchen angelegt', 'adventskalender' ); ?></p>
							<?php endif; ?>

							<p class="ak-card__status">
								<?php if ( ! $exists ) : ?>
									<span class="ak-pill ak-pill--missing"><?php esc_html_e( 'fehlt', 'adventskalender' ); ?></span>
								<?php elseif ( ! $published ) : ?>
									<span class="ak-pill ak-pill--draft"><?php esc_html_e( 'Entwurf', 'adventskalender' ); ?></span>
								<?php elseif ( ! $has_content ) : ?>
									<span class="ak-pill ak-pill--empty"><?php esc_html_e( 'leer', 'adventskalender' ); ?></span>
								<?php else : ?>
									<span class="ak-pill ak-pill--ready"><?php esc_html_e( 'bereit', 'adventskalender' ); ?></span>
								<?php endif; ?>

								<?php if ( $unlocked ) : ?>
									<span class="ak-pill ak-pill--open"><?php esc_html_e( 'freigeschaltet', 'adventskalender' ); ?></span>
								<?php else : ?>
									<span class="ak-pill ak-pill--closed"><?php esc_html_e( 'gesperrt', 'adventskalender' ); ?></span>
								<?php endif; ?>
							</p>
						</div>

						<div class="ak-card__foot">
							<?php if ( $exists && current_user_can( 'edit_post', $post->ID ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>">
									<?php esc_html_e( 'Bearbeiten', 'adventskalender' ); ?>
								</a>
							<?php else : ?>
								<a class="button button-small button-primary" href="<?php echo esc_url( add_query_arg( array( 'post_type' => Doors::POST_TYPE, 'ak_day' => $day, 'ak_year' => $year ), admin_url( 'post-new.php' ) ) ); ?>">
									<?php esc_html_e( 'Anlegen', 'adventskalender' ); ?>
								</a>
							<?php endif; ?>
						</div>
					</li>
				<?php endfor; ?>
			</ol>
		</div>
		<?php
	}

	/**
	 * Legt fehlende Türchen als Entwurf an.
	 */
	public static function handle_create_missing(): void {
		if ( ! current_user_can( self::manage_capability() ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diese Aktion.', 'adventskalender' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'adventskalender_create_missing' );

		$year = isset( $_POST['ak_year'] ) ? absint( wp_unslash( $_POST['ak_year'] ) ) : (int) Settings::get( 'year' );
		if ( $year < 2000 || $year > 2100 ) {
			$year = (int) Settings::get( 'year' );
		}

		$count   = Availability::door_count();
		$doors   = Doors::get_for_year( $year, array( 'publish', 'draft', 'pending', 'private', 'future' ) );
		$created = 0;

		for ( $day = 1; $day <= $count; $day++ ) {
			if ( isset( $doors[ $day ] ) ) {
				continue;
			}

			$post_id = wp_insert_post(
				array(
					'post_type'   => Doors::POST_TYPE,
					'post_status' => 'draft',
					'post_title'  => sprintf(
						/* translators: %d: Tagesnummer. */
						__( 'Türchen %d', 'adventskalender' ),
						$day
					),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				continue;
			}

			update_post_meta( $post_id, Doors::META_DAY, $day );
			update_post_meta( $post_id, Doors::META_YEAR, $year );
			update_post_meta( $post_id, Doors::META_LAYOUT, 'media_top' );
			update_post_meta( $post_id, Doors::META_MEDIA_TYPE, 'none' );
			++$created;
		}

		Doors::flush_cache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_OVERVIEW,
					'ak_year'    => $year,
					'ak_created' => $created,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Schaltet den Testmodus um.
	 */
	public static function handle_toggle_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diese Aktion.', 'adventskalender' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'adventskalender_toggle_test' );

		$settings              = Settings::get();
		$settings['test_mode'] = empty( $settings['test_mode'] ) ? 1 : 0;
		update_option( Settings::OPTION, $settings );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_OVERVIEW,
					'ak_test' => $settings['test_mode'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Zeigt Rückmeldungen nach Aktionen.
	 */
	public static function admin_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::PAGE_OVERVIEW ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reine Anzeige nach Redirect.
		if ( isset( $_GET['ak_created'] ) ) {
			$created = absint( wp_unslash( $_GET['ak_created'] ) );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: Anzahl angelegter Türchen. */
						_n( '%d Türchen als Entwurf angelegt.', '%d Türchen als Entwürfe angelegt.', $created, 'adventskalender' ),
						$created
					)
				)
			);
		}

		if ( isset( $_GET['ak_test'] ) ) {
			$on = '1' === (string) wp_unslash( $_GET['ak_test'] );
			printf(
				'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
				$on ? 'notice-warning' : 'notice-success',
				esc_html(
					$on
						? __( 'Testmodus eingeschaltet – alle Türchen sind geöffnet.', 'adventskalender' )
						: __( 'Testmodus ausgeschaltet – es gilt wieder das Datum.', 'adventskalender' )
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Spalten der Türchen-Liste.
	 *
	 * @param array<string,string> $columns Bestehende Spalten.
	 * @return array<string,string>
	 */
	public static function columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['ak_day'] = __( 'Tag', 'adventskalender' );
			}
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['ak_year']  = __( 'Jahr', 'adventskalender' );
				$new['ak_media'] = __( 'Medium', 'adventskalender' );
				$new['ak_state'] = __( 'Status', 'adventskalender' );
			}
		}

		return $new;
	}

	/**
	 * Inhalt der eigenen Spalten.
	 *
	 * @param string $column  Spaltenname.
	 * @param int    $post_id Beitrags-ID.
	 */
	public static function column_content( $column, $post_id ): void {
		$post_id = (int) $post_id;

		switch ( $column ) {
			case 'ak_day':
				$day = (int) get_post_meta( $post_id, Doors::META_DAY, true );
				echo $day > 0 ? '<strong class="ak-col-day">' . esc_html( (string) $day ) . '</strong>' : '<span class="ak-col-warn">' . esc_html__( '– fehlt', 'adventskalender' ) . '</span>';
				break;

			case 'ak_year':
				$year = (int) get_post_meta( $post_id, Doors::META_YEAR, true );
				echo $year > 0 ? esc_html( (string) $year ) : '<span class="ak-col-warn">' . esc_html__( '– fehlt', 'adventskalender' ) . '</span>';
				break;

			case 'ak_media':
				$types = Doors::media_types();
				$type  = Doors::sanitize_media_type( get_post_meta( $post_id, Doors::META_MEDIA_TYPE, true ) );
				echo esc_html( $types[ $type ] ?? '' );
				break;

			case 'ak_state':
				$post = get_post( $post_id );
				$day  = (int) get_post_meta( $post_id, Doors::META_DAY, true );
				$year = (int) get_post_meta( $post_id, Doors::META_YEAR, true );
				if ( ! Content::has_content( $post ) ) {
					echo '<span class="ak-pill ak-pill--empty">' . esc_html__( 'leer', 'adventskalender' ) . '</span>';
				} elseif ( $day > 0 && $year > 0 && Availability::is_unlocked( $day, $year ) ) {
					echo '<span class="ak-pill ak-pill--open">' . esc_html__( 'freigeschaltet', 'adventskalender' ) . '</span>';
				} else {
					echo '<span class="ak-pill ak-pill--closed">' . esc_html__( 'gesperrt', 'adventskalender' ) . '</span>';
				}
				break;
		}
	}

	/**
	 * Macht die Tagesspalte sortierbar.
	 *
	 * @param array<string,string> $columns Spalten.
	 * @return array<string,string>
	 */
	public static function sortable_columns( array $columns ): array {
		$columns['ak_day']  = 'ak_day';
		$columns['ak_year'] = 'ak_year';

		return $columns;
	}

	/**
	 * Sortiert die Admin-Liste standardmäßig nach Tag.
	 *
	 * @param \WP_Query $query Abfrage.
	 */
	public static function order_admin_list( $query ): void {
		if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return;
		}
		if ( Doors::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$orderby = (string) $query->get( 'orderby' );

		if ( 'ak_day' === $orderby || '' === $orderby ) {
			$query->set( 'meta_key', Doors::META_DAY );
			$query->set( 'orderby', 'meta_value_num' );
			if ( '' === $orderby ) {
				$query->set( 'order', 'ASC' );
			}
		} elseif ( 'ak_year' === $orderby ) {
			$query->set( 'meta_key', Doors::META_YEAR );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * Entfernt irrelevante Zeilenaktionen.
	 *
	 * @param array<string,string> $actions Aktionen.
	 * @param \WP_Post             $post    Beitrag.
	 * @return array<string,string>
	 */
	public static function row_actions( $actions, $post ): array {
		if ( $post instanceof \WP_Post && Doors::POST_TYPE === $post->post_type ) {
			unset( $actions['view'], $actions['inline hide-if-no-js'] );
		}

		return (array) $actions;
	}

	/**
	 * Rendert die Einstellungsseite.
	 */
	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du hast keine Berechtigung für diese Seite.', 'adventskalender' ) );
		}

		$s = Settings::get();
		?>
		<div class="wrap ak-admin ak-admin--settings">
			<h1><?php esc_html_e( 'Adventskalender – Einstellungen', 'adventskalender' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php settings_fields( 'adventskalender_settings_group' ); ?>
				<?php $name = Settings::OPTION; ?>

				<div class="ak-panel">
					<h2><?php esc_html_e( 'Kalender', 'adventskalender' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ak-year"><?php esc_html_e( 'Kalenderjahr', 'adventskalender' ); ?></label></th>
							<td>
								<input type="number" id="ak-year" name="<?php echo esc_attr( $name ); ?>[year]" value="<?php echo esc_attr( (string) $s['year'] ); ?>" min="2000" max="2100" step="1" class="small-text" />
								<p class="description"><?php esc_html_e( 'Bestimmt, für welches Jahr die Türchen gezählt und freigeschaltet werden.', 'adventskalender' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ak-door-count"><?php esc_html_e( 'Anzahl Türchen', 'adventskalender' ); ?></label></th>
							<td>
								<input type="number" id="ak-door-count" name="<?php echo esc_attr( $name ); ?>[door_count]" value="<?php echo esc_attr( (string) $s['door_count'] ); ?>" min="1" max="31" step="1" class="small-text" />
								<p class="description"><?php esc_html_e( 'Standard: 24. Für einen Kalender bis Silvester z. B. 31.', 'adventskalender' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ak-columns"><?php esc_html_e( 'Spalten (Desktop)', 'adventskalender' ); ?></label></th>
							<td>
								<input type="number" id="ak-columns" name="<?php echo esc_attr( $name ); ?>[columns]" value="<?php echo esc_attr( (string) $s['columns'] ); ?>" min="2" max="8" step="1" class="small-text" />
								<p class="description"><?php esc_html_e( 'Auf kleineren Bildschirmen passt sich das Raster automatisch an.', 'adventskalender' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="ak-panel">
					<h2><?php esc_html_e( 'Darstellung', 'adventskalender' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Aussehen der geschlossenen Türchen', 'adventskalender' ); ?></th>
							<td>
								<fieldset class="ak-radio-cards">
									<legend class="screen-reader-text"><?php esc_html_e( 'Layout wählen', 'adventskalender' ); ?></legend>
									<?php foreach ( Settings::layouts() as $key => $label ) : ?>
										<label class="ak-radio-card">
											<input type="radio" name="<?php echo esc_attr( $name ); ?>[layout]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, $s['layout'] ); ?> />
											<span class="ak-radio-card__preview ak-radio-card__preview--<?php echo esc_attr( $key ); ?>" aria-hidden="true"></span>
											<span class="ak-radio-card__label"><?php echo esc_html( $label ); ?></span>
										</label>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ak-theme"><?php esc_html_e( 'Farbwelt', 'adventskalender' ); ?></label></th>
							<td>
								<select id="ak-theme" name="<?php echo esc_attr( $name ); ?>[theme]">
									<?php foreach ( Settings::themes() as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $s['theme'] ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Mosaik-Bild', 'adventskalender' ); ?></th>
							<td>
								<?php self::media_field( $name . '[mosaic_image]', (int) $s['mosaic_image'] ); ?>
								<p class="description"><?php esc_html_e( 'Nur für das Layout „Mosaik“: ein großes Bild, das über alle Türchen verteilt wird. Empfohlen: mindestens 1800 px breit, Querformat.', 'adventskalender' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hintergrundbild', 'adventskalender' ); ?></th>
							<td>
								<?php self::media_field( $name . '[background_image]', (int) $s['background_image'] ); ?>
								<p class="description"><?php esc_html_e( 'Optional: liegt hinter dem gesamten Kalender.', 'adventskalender' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Anordnung', 'adventskalender' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[shuffle]" value="1" <?php checked( 1, (int) $s['shuffle'] ); ?> />
									<?php esc_html_e( 'Türchen zufällig anordnen (1–24 bunt gemischt)', 'adventskalender' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Die Anordnung bleibt für das ganze Jahr stabil, damit Besucher ihre Türchen wiederfinden.', 'adventskalender' ); ?></p>
								<p>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[reshuffle]" value="1" data-ak-confirm-shuffle />
										<?php esc_html_e( 'Beim Speichern neu mischen', 'adventskalender' ); ?>
									</label>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Schneefall', 'adventskalender' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[snow]" value="1" <?php checked( 1, (int) $s['snow'] ); ?> />
									<?php esc_html_e( 'Dezente Schnee-Animation über dem Kalender anzeigen', 'adventskalender' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Wird bei „Bewegung reduzieren“ im Betriebssystem automatisch deaktiviert.', 'adventskalender' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="ak-panel">
					<h2><?php esc_html_e( 'Texte', 'adventskalender' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ak-heading"><?php esc_html_e( 'Überschrift', 'adventskalender' ); ?></label></th>
							<td>
								<input type="text" id="ak-heading" class="regular-text" name="<?php echo esc_attr( $name ); ?>[heading]" value="<?php echo esc_attr( (string) $s['heading'] ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ak-intro"><?php esc_html_e( 'Einleitungstext', 'adventskalender' ); ?></label></th>
							<td>
								<textarea id="ak-intro" class="large-text" rows="3" name="<?php echo esc_attr( $name ); ?>[intro]"><?php echo esc_textarea( (string) $s['intro'] ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ak-locked"><?php esc_html_e( 'Hinweis bei gesperrtem Türchen', 'adventskalender' ); ?></label></th>
							<td>
								<input type="text" id="ak-locked" class="large-text" name="<?php echo esc_attr( $name ); ?>[locked_notice]" value="<?php echo esc_attr( (string) $s['locked_notice'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Platzhalter: {datum} für das Öffnungsdatum, {tag} für die Tagesnummer.', 'adventskalender' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="ak-panel ak-panel--test">
					<h2><?php esc_html_e( 'Test & Vorschau', 'adventskalender' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Testmodus', 'adventskalender' ); ?></th>
							<td>
								<label class="ak-switch">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[test_mode]" value="1" <?php checked( 1, (int) $s['test_mode'] ); ?> />
									<span><?php esc_html_e( 'Alle Türchen für alle Besucher öffnen', 'adventskalender' ); ?></span>
								</label>
								<p class="description ak-description--warning">
									<?php esc_html_e( 'Achtung: Im Testmodus sieht jeder Besucher sämtliche Inhalte. Nur zum Testen verwenden und vor dem Start ausschalten.', 'adventskalender' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hinweis im Frontend', 'adventskalender' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[test_mode_notice]" value="1" <?php checked( 1, (int) $s['test_mode_notice'] ); ?> />
									<?php esc_html_e( 'Bei aktivem Testmodus einen Hinweis über dem Kalender anzeigen', 'adventskalender' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Redaktionsvorschau', 'adventskalender' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[editor_preview]" value="1" <?php checked( 1, (int) $s['editor_preview'] ); ?> />
									<?php esc_html_e( 'Angemeldete Redakteure dürfen alle Türchen öffnen', 'adventskalender' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Empfohlen: So kannst du Inhalte prüfen, ohne den Testmodus für alle einzuschalten.', 'adventskalender' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="ak-panel">
					<h2><?php esc_html_e( 'Verhalten & Daten', 'adventskalender' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Geöffnete Türchen merken', 'adventskalender' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[remember_opened]" value="1" <?php checked( 1, (int) $s['remember_opened'] ); ?> />
									<?php esc_html_e( 'Bereits geöffnete Türchen bleiben im Browser des Besuchers offen', 'adventskalender' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Speichert ausschließlich lokal im Browser (localStorage) – keine personenbezogenen Daten, keine Cookies.', 'adventskalender' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Beim Löschen des Plugins', 'adventskalender' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_data]" value="1" <?php checked( 1, (int) $s['delete_data'] ); ?> />
									<?php esc_html_e( 'Türchen und Einstellungen vollständig entfernen', 'adventskalender' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Standardmäßig bleiben alle Inhalte erhalten, wenn das Plugin gelöscht wird.', 'adventskalender' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'Einstellungen speichern', 'adventskalender' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Rendert ein Medienauswahl-Feld.
	 *
	 * @param string $field_name Name des Formularfelds.
	 * @param int    $value      Aktuelle Anhang-ID.
	 * @param string $type       Medientyp (image|video).
	 */
	public static function media_field( string $field_name, int $value, string $type = 'image' ): void {
		$value = Settings::sanitize_attachment_id( $value );
		$url   = $value > 0 ? wp_get_attachment_image_url( $value, 'medium' ) : '';
		$title = $value > 0 ? get_the_title( $value ) : '';
		?>
		<div class="ak-media-field" data-ak-media data-type="<?php echo esc_attr( $type ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" data-ak-media-input />
			<div class="ak-media-field__preview" data-ak-media-preview>
				<?php if ( $url ) : ?>
					<img src="<?php echo esc_url( (string) $url ); ?>" alt="" />
				<?php elseif ( $value > 0 ) : ?>
					<span class="ak-media-field__filename"><?php echo esc_html( $title ); ?></span>
				<?php endif; ?>
			</div>
			<p class="ak-media-field__buttons">
				<button type="button" class="button" data-ak-media-select>
					<?php echo 'video' === $type ? esc_html__( 'Video wählen', 'adventskalender' ) : esc_html__( 'Bild wählen', 'adventskalender' ); ?>
				</button>
				<button type="button" class="button-link ak-media-field__remove" data-ak-media-remove <?php echo $value > 0 ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'Entfernen', 'adventskalender' ); ?>
				</button>
			</p>
		</div>
		<?php
	}
}
