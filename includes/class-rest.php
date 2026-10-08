<?php
/**
 * REST-Schnittstelle für Türchen-Inhalte.
 *
 * Inhalte werden ausschließlich hier ausgeliefert – und nur, wenn die
 * Verfügbarkeitsprüfung serverseitig zustimmt. Gesperrte Inhalte sind
 * im Quellcode der Seite nicht enthalten.
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registriert die REST-Routen.
 */
class Rest {

	/**
	 * REST-Namespace.
	 */
	const NAMESPACE_V1 = 'adventskalender/v1';

	/**
	 * Registriert alle Routen.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/door/(?P<day>[0-9]{1,2})',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_door' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'day'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_day' ),
					),
					'year' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_year' ),
					),
				),
			)
		);

		// Nur für die Live-Vorschau im Adminbereich.
		register_rest_route(
			self::NAMESPACE_V1,
			'/palette',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_palette' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'color'  => array(
						'required' => true,
						'type'     => 'string',
					),
					'scheme' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/state',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_state' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'year' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => array( __CLASS__, 'validate_year' ),
					),
				),
			)
		);
	}

	/**
	 * Zweite Sperre für die Standard-Routen des Inhaltstyps.
	 *
	 * Der eigene Controller schützt bereits das Lesen von Liste und
	 * Einzeleintrag. Dieser Filter deckt zusätzlich alle Unterrouten ab
	 * (Revisionen, Autosaves) und bleibt wirksam, falls die Registrierung
	 * des Controllers einmal nicht greift.
	 *
	 * @param mixed            $result  Vorberechnetes Ergebnis.
	 * @param mixed            $server  REST-Server.
	 * @param \WP_REST_Request $request Anfrage.
	 * @return mixed
	 */
	public static function guard_door_routes( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}

		$route = (string) $request->get_route();
		$base  = '/wp/v2/' . Doors::REST_BASE;

		if ( $route !== $base && 0 !== strpos( $route, $base . '/' ) ) {
			return $result;
		}

		if ( current_user_can( Settings::manage_capability() ) ) {
			return $result;
		}

		return new \WP_Error(
			'adventskalender_rest_forbidden',
			__( 'Türchen lassen sich über die REST-API nur von der Redaktion lesen.', 'adventskalender' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Validiert einen Tageswert.
	 *
	 * @param mixed $value Rohwert.
	 */
	public static function validate_day( $value ): bool {
		$day = absint( $value );

		return $day >= 1 && $day <= 31;
	}

	/**
	 * Validiert ein Jahr.
	 *
	 * @param mixed $value Rohwert.
	 */
	public static function validate_year( $value ): bool {
		$year = absint( $value );

		return $year >= 2000 && $year <= 2100;
	}

	/**
	 * Ermittelt das anzufragende Jahr.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 */
	private static function resolve_year( \WP_REST_Request $request ): int {
		$year = absint( $request->get_param( 'year' ) );

		return ( $year >= 2000 && $year <= 2100 ) ? $year : (int) Settings::get( 'year' );
	}

	/**
	 * Liefert den Inhalt eines Türchens.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_door( \WP_REST_Request $request ) {
		nocache_headers();

		$day  = absint( $request->get_param( 'day' ) );
		$year = self::resolve_year( $request );

		if ( ! Availability::is_valid_day( $day ) ) {
			return new \WP_Error(
				'adventskalender_invalid_day',
				__( 'Dieses Türchen gibt es nicht.', 'adventskalender' ),
				array( 'status' => 404 )
			);
		}

		// Autorisierung: ausschließlich serverseitig.
		if ( ! Availability::is_unlocked( $day, $year ) ) {
			return new \WP_Error(
				'adventskalender_locked',
				Availability::locked_notice( $day, $year ),
				array(
					'status' => 403,
					'day'    => $day,
				)
			);
		}

		$post = Doors::get( $day, $year );

		if ( ! $post instanceof \WP_Post || ! Content::has_content( $post ) ) {
			return new \WP_REST_Response(
				array(
					'day'   => $day,
					'year'  => $year,
					'title' => sprintf(
						/* translators: %d: Tagesnummer. */
						__( 'Türchen %d', 'adventskalender' ),
						$day
					),
					'html'  => '<div class="ak-lightbox__body"><p class="ak-empty">'
						. esc_html__( 'Für dieses Türchen ist noch kein Inhalt hinterlegt.', 'adventskalender' )
						. '</p></div>',
					'empty' => true,
				),
				200
			);
		}

		return new \WP_REST_Response(
			array(
				'day'     => $day,
				'year'    => $year,
				'title'   => get_the_title( $post ),
				'html'    => Content::render( $post, $day ),
				'preview' => Renderer::render_preview( $post ),
				'empty'   => false,
			),
			200
		);
	}

	/**
	 * Liefert die abgeleitete Palette einer Markenfarbe.
	 *
	 * Ausschließlich für die Vorschau in den Einstellungen – die
	 * Berechtigungsprüfung verlangt „manage_options“.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 */
	public static function get_palette( \WP_REST_Request $request ): \WP_REST_Response {
		nocache_headers();

		$color  = Color::sanitize_hex( $request->get_param( 'color' ) );
		$scheme = (string) $request->get_param( 'scheme' );

		return new \WP_REST_Response( Color::report( $color, $scheme ), 200 );
	}

	/**
	 * Liefert den aktuellen Freischaltzustand.
	 *
	 * Wird vom Frontend beim Laden abgefragt, damit auch stark
	 * gecachte Seiten den korrekten Zustand anzeigen.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return \WP_REST_Response
	 */
	public static function get_state( \WP_REST_Request $request ): \WP_REST_Response {
		nocache_headers();

		$year  = self::resolve_year( $request );
		$count = Availability::door_count();
		$doors = Doors::get_for_year( $year );
		$days  = array();

		for ( $day = 1; $day <= $count; $day++ ) {
			$post     = $doors[ $day ] ?? null;
			$unlocked = Availability::is_unlocked( $day, $year );
			$entry    = array(
				'day'      => $day,
				'unlocked' => $unlocked,
				'state'    => 'locked',
				'notice'   => '',
			);

			if ( $unlocked ) {
				$entry['state']   = Content::has_content( $post ) ? 'closed' : 'empty';
				$entry['preview'] = 'closed' === $entry['state'] ? Renderer::render_preview( $post ) : '';
				if ( 'empty' === $entry['state'] ) {
					$entry['notice'] = __( 'Für dieses Türchen ist noch kein Inhalt hinterlegt.', 'adventskalender' );
				}
			} else {
				$entry['notice'] = Availability::locked_notice( $day, $year );
			}

			$days[] = $entry;
		}

		return new \WP_REST_Response(
			array(
				'year'      => $year,
				'today'     => current_datetime()->format( 'Y-m-d' ),
				'test_mode' => Availability::test_mode_active(),
				'preview'   => Availability::preview_allowed(),
				'days'      => $days,
			),
			200
		);
	}
}
