<?php
/**
 * Plugin Name:       Adventskalender
 * Plugin URI:        https://github.com/friloo/wordpress-adventskalender
 * Description:       Ein eleganter, barrierefreier Adventskalender mit 24 Türchen, zufälliger Anordnung, Öffnungs-Animation und weißer Lightbox für Videos, Bilder und Texte.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Friederich Loheide
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       adventskalender
 * Domain Path:       /languages
 *
 * @package Adventskalender
 */

namespace Adventskalender;

// Direktzugriff verhindern.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;
const TEXTDOMAIN  = 'adventskalender';

/**
 * Absoluter Pfad zum Plugin-Verzeichnis (mit Trailing Slash).
 */
function plugin_dir(): string {
	return plugin_dir_path( PLUGIN_FILE );
}

/**
 * URL zum Plugin-Verzeichnis (mit Trailing Slash).
 */
function plugin_url_base(): string {
	return plugin_dir_url( PLUGIN_FILE );
}

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-doors.php';
require_once __DIR__ . '/includes/class-availability.php';
require_once __DIR__ . '/includes/class-content.php';
require_once __DIR__ . '/includes/class-renderer.php';
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-metabox.php';
require_once __DIR__ . '/includes/class-block.php';
require_once __DIR__ . '/includes/class-plugin.php';

Plugin::instance()->boot();
