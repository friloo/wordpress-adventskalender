<?php
/**
 * Zentrale Registrierung aller Hooks.
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap-Klasse des Plugins.
 */
class Plugin {

	/**
	 * Singleton-Instanz.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Verhindert direkte Instanziierung.
	 */
	private function __construct() {}

	/**
	 * Gibt die Instanz zurück.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registriert alle Hooks.
	 */
	public function boot(): void {
		register_activation_hook( PLUGIN_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( PLUGIN_FILE, array( __CLASS__, 'deactivate' ) );

		add_action( 'init', array( $this, 'on_init' ) );
		add_action( 'admin_init', array( Settings::class, 'register' ) );
		add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );

		// Zweite Sperre für /wp/v2/ak_door – unabhängig vom eigenen Controller.
		add_filter( 'rest_pre_dispatch', array( Rest::class, 'guard_door_routes' ), 10, 3 );

		// Früh registrieren: der Block rendert serverseitig über die REST-API,
		// wo "wp_enqueue_scripts" nie ausgelöst wird.
		add_action( 'init', array( Renderer::class, 'register_assets' ), 5 );
		add_action( 'after_setup_theme', array( Renderer::class, 'register_image_size' ) );
		add_action( 'admin_init', array( Renderer::class, 'register_assets' ) );

		// Cache der Türchen bei Änderungen leeren.
		add_action( 'save_post_' . Doors::POST_TYPE, array( Doors::class, 'flush_cache' ) );
		add_action( 'deleted_post', array( $this, 'maybe_flush_cache' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'maybe_flush_cache_single' ) );
		add_action( 'untrashed_post', array( $this, 'maybe_flush_cache_single' ) );
		add_action( 'update_option_' . Settings::OPTION, array( Doors::class, 'flush_cache' ) );

		Admin::hooks();
		Metabox::hooks();
		Block::hooks();
	}

	/**
	 * Läuft bei „init“: Übersetzungen, Post Type, Shortcode.
	 */
	public function on_init(): void {
		load_plugin_textdomain( TEXTDOMAIN, false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );

		Doors::register_post_type();
		Doors::register_meta();

		add_shortcode( 'adventskalender', array( Renderer::class, 'shortcode' ) );
	}

	/**
	 * Leert den Cache, wenn ein Türchen gelöscht wurde.
	 *
	 * @param int           $post_id Beitrags-ID.
	 * @param \WP_Post|null $post    Beitrag.
	 */
	public function maybe_flush_cache( $post_id, $post = null ): void {
		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( (int) $post_id );
		if ( Doors::POST_TYPE === $type ) {
			Doors::flush_cache();
		}
	}

	/**
	 * Leert den Cache für Papierkorb-Aktionen.
	 *
	 * @param int $post_id Beitrags-ID.
	 */
	public function maybe_flush_cache_single( $post_id ): void {
		if ( Doors::POST_TYPE === get_post_type( (int) $post_id ) ) {
			Doors::flush_cache();
		}
	}

	/**
	 * Aktivierung: Standardoptionen anlegen.
	 */
	public static function activate(): void {
		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		// Seed einmalig zufällig initialisieren.
		$settings = get_option( Settings::OPTION, array() );
		if ( is_array( $settings ) && empty( $settings['shuffle_seed'] ) ) {
			$settings['shuffle_seed'] = wp_rand( 1, 999999 );
			update_option( Settings::OPTION, $settings );
		}
	}

	/**
	 * Deaktivierung: Caches aufräumen.
	 */
	public static function deactivate(): void {
		Doors::flush_cache();
	}
}
