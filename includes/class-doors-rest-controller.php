<?php
/**
 * Abgeschotteter REST-Controller für Türchen.
 *
 * Der Block-Editor arbeitet über die REST-API – dafür muss der Inhaltstyp
 * dort registriert sein. Ohne weitere Maßnahme wären damit aber alle
 * veröffentlichten Türchen unter /wp-json/wp/v2/ak_door öffentlich lesbar,
 * also auch die, die im Kalender noch gesperrt sind.
 *
 * Dieser Controller verlangt deshalb für jeden lesenden Zugriff dieselbe
 * Berechtigung wie die Türchen-Verwaltung. Eine zweite, unabhängige Sperre
 * sitzt in Rest::guard_door_routes().
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_REST_Posts_Controller' ) ) {
	return;
}

/**
 * Lesender Zugriff nur für die Redaktion.
 */
class Doors_Rest_Controller extends \WP_REST_Posts_Controller {

	/**
	 * Prüft die Redaktionsberechtigung.
	 *
	 * @return true|\WP_Error
	 */
	protected function require_editor() {
		if ( current_user_can( Settings::manage_capability() ) ) {
			return true;
		}

		return new \WP_Error(
			'adventskalender_rest_forbidden',
			__( 'Türchen lassen sich über die REST-API nur von der Redaktion lesen.', 'adventskalender' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Liste der Türchen.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return true|\WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$allowed = $this->require_editor();

		return is_wp_error( $allowed ) ? $allowed : parent::get_items_permissions_check( $request );
	}

	/**
	 * Einzelnes Türchen.
	 *
	 * @param \WP_REST_Request $request Anfrage.
	 * @return true|\WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$allowed = $this->require_editor();

		return is_wp_error( $allowed ) ? $allowed : parent::get_item_permissions_check( $request );
	}
}
