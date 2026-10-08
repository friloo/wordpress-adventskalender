<?php
/**
 * Verfügbarkeitslogik: Wann darf ein Türchen geöffnet werden?
 *
 * Diese Klasse ist die einzige Autorität darüber, ob ein Inhalt
 * ausgeliefert werden darf. Sie wird serverseitig vor jeder Ausgabe
 * befragt – gesperrte Inhalte verlassen den Server nie.
 *
 * @package Adventskalender
 */

namespace Adventskalender;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prüft Öffnungszeitpunkte der Türchen.
 */
class Availability {

	/**
	 * Grund, warum ein Türchen offen ist: regulärer Termin erreicht.
	 */
	const REASON_DATE = 'date';

	/**
	 * Grund: globaler Testmodus.
	 */
	const REASON_TEST = 'test_mode';

	/**
	 * Grund: Vorschau für Redaktion.
	 */
	const REASON_PREVIEW = 'preview';

	/**
	 * Grund: noch gesperrt.
	 */
	const REASON_LOCKED = 'locked';

	/**
	 * Ist der globale Testmodus aktiv?
	 */
	public static function test_mode_active(): bool {
		/**
		 * Filtert den globalen Testmodus.
		 *
		 * @param bool $active Ob der Testmodus aktiv ist.
		 */
		return (bool) apply_filters( 'adventskalender_test_mode', (bool) Settings::get( 'test_mode' ) );
	}

	/**
	 * Darf der aktuelle Benutzer die Redaktionsvorschau sehen?
	 */
	public static function preview_allowed(): bool {
		if ( ! Settings::get( 'editor_preview' ) ) {
			return false;
		}

		/**
		 * Filtert die benötigte Berechtigung für die Redaktionsvorschau.
		 *
		 * @param string $capability Benötigte Capability.
		 */
		$capability = (string) apply_filters( 'adventskalender_preview_capability', 'edit_posts' );

		return is_user_logged_in() && current_user_can( $capability );
	}

	/**
	 * Zeitstempel, ab dem ein Türchen offen ist (Website-Zeitzone, 00:00 Uhr).
	 *
	 * @param int $day  Tag im Dezember.
	 * @param int $year Kalenderjahr.
	 */
	public static function unlock_time( int $day, int $year ): \DateTimeImmutable {
		$day  = max( 1, min( 31, $day ) );
		$date = \DateTimeImmutable::createFromFormat(
			'Y-n-j H:i:s',
			sprintf( '%d-12-%d 00:00:00', $year, $day ),
			wp_timezone()
		);

		if ( false === $date ) {
			// Fallback, falls das Datum nicht konstruierbar ist.
			$date = new \DateTimeImmutable( sprintf( '%d-12-01 00:00:00', $year ), wp_timezone() );
		}

		return $date;
	}

	/**
	 * Formatiert das Öffnungsdatum lokalisiert, z. B. „5. Dezember 2026“.
	 *
	 * @param int    $day    Tag im Dezember.
	 * @param int    $year   Kalenderjahr.
	 * @param string $format Abweichendes Datumsformat (optional).
	 */
	public static function format_unlock_date( int $day, int $year, string $format = '' ): string {
		$time = self::unlock_time( $day, $year );

		if ( '' === $format ) {
			/**
			 * Filtert das Datumsformat des Öffnungshinweises.
			 *
			 * @param string $format Datumsformat.
			 */
			$format = (string) apply_filters( 'adventskalender_date_format', 'j. F Y' );
		}

		return (string) wp_date( $format, $time->getTimestamp() );
	}

	/**
	 * Höchster gültiger Tag laut Einstellungen.
	 */
	public static function door_count(): int {
		$count = (int) Settings::get( 'door_count' );

		return ( $count >= 1 && $count <= 31 ) ? $count : 24;
	}

	/**
	 * Prüft, ob ein Tag innerhalb des gültigen Bereichs liegt.
	 *
	 * @param int $day Tag.
	 */
	public static function is_valid_day( int $day ): bool {
		return $day >= 1 && $day <= self::door_count();
	}

	/**
	 * Ermittelt den Öffnungsgrund für ein Türchen.
	 *
	 * @param int      $day  Tag im Dezember.
	 * @param int|null $year Kalenderjahr; null = Einstellung.
	 */
	public static function reason( int $day, ?int $year = null ): string {
		$year = null === $year ? (int) Settings::get( 'year' ) : $year;

		if ( ! self::is_valid_day( $day ) ) {
			return self::REASON_LOCKED;
		}

		if ( self::test_mode_active() ) {
			return self::REASON_TEST;
		}

		$now = current_datetime();
		if ( $now >= self::unlock_time( $day, $year ) ) {
			return self::REASON_DATE;
		}

		if ( self::preview_allowed() ) {
			return self::REASON_PREVIEW;
		}

		return self::REASON_LOCKED;
	}

	/**
	 * Ist das Türchen geöffnet?
	 *
	 * @param int      $day  Tag im Dezember.
	 * @param int|null $year Kalenderjahr; null = Einstellung.
	 */
	public static function is_unlocked( int $day, ?int $year = null ): bool {
		$year   = null === $year ? (int) Settings::get( 'year' ) : $year;
		$reason = self::reason( $day, $year );

		/**
		 * Filtert die Verfügbarkeit eines einzelnen Türchens.
		 *
		 * @param bool   $unlocked Ob das Türchen offen ist.
		 * @param int    $day      Tag im Dezember.
		 * @param int    $year     Kalenderjahr.
		 * @param string $reason   Ermittelter Grund.
		 */
		return (bool) apply_filters(
			'adventskalender_is_unlocked',
			self::REASON_LOCKED !== $reason,
			$day,
			$year,
			$reason
		);
	}

	/**
	 * Freischaltung aus Sicht eines nicht angemeldeten Besuchers.
	 *
	 * Die Redaktionsvorschau bleibt bewusst außen vor: im Backend will man
	 * wissen, was Besucher sehen – nicht, was man selbst sehen darf.
	 *
	 * @param int      $day  Tag im Dezember.
	 * @param int|null $year Kalenderjahr; null = Einstellung.
	 */
	public static function is_public_unlocked( int $day, ?int $year = null ): bool {
		$year = null === $year ? (int) Settings::get( 'year' ) : $year;

		if ( ! self::is_valid_day( $day ) ) {
			return false;
		}

		if ( self::test_mode_active() ) {
			return true;
		}

		return current_datetime() >= self::unlock_time( $day, $year );
	}

	/**
	 * Liste aller aktuell geöffneten Tage.
	 *
	 * @param int|null $year Kalenderjahr; null = Einstellung.
	 * @return int[]
	 */
	public static function unlocked_days( ?int $year = null ): array {
		$year = null === $year ? (int) Settings::get( 'year' ) : $year;
		$days = array();

		for ( $day = 1; $day <= self::door_count(); $day++ ) {
			if ( self::is_unlocked( $day, $year ) ) {
				$days[] = $day;
			}
		}

		return $days;
	}

	/**
	 * Hinweistext für ein gesperrtes Türchen.
	 *
	 * @param int $day  Tag im Dezember.
	 * @param int $year Kalenderjahr.
	 */
	public static function locked_notice( int $day, int $year ): string {
		$template = (string) Settings::get( 'locked_notice' );
		if ( '' === trim( $template ) ) {
			$template = __( 'Dieses Türchen öffnet erst am {datum}.', 'adventskalender' );
		}

		return str_replace(
			array( '{datum}', '{tag}' ),
			array( self::format_unlock_date( $day, $year ), (string) $day ),
			$template
		);
	}
}
