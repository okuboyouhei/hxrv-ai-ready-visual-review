<?php
/**
 * Plugin Name:       HXRV - AI-Ready Visual Review
 * Plugin URI:        https://github.com/okuboyouhei/hxrv-ai-ready-visual-review
 * Description:       Self-hosted visual review for WordPress. Click any element to leave a comment, resolve it Figma-style, and export open issues as an AI-ready Markdown brief for coding agents. Powered by htmx + Alpine.js. No SaaS, no build step, no data leaves your site.
 * Version:           0.1.13
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Youhei Okubo
 * Author URI:        https://profiles.wordpress.org/youheiokubo/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hxrv-ai-ready-visual-review
 *
 * @package HXRV
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HXRV_VERSION', '0.1.13' );
define( 'HXRV_DB_VERSION', '1' );
define( 'HXRV_PLUGIN_FILE', __FILE__ );
define( 'HXRV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HXRV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Capability required to use review mode and manage comments.
 * Filterable: `hxrv_capability`.
 */
function hxrv_capability() {
	return apply_filters( 'hxrv_capability', 'edit_pages' );
}

require_once HXRV_PLUGIN_DIR . 'includes/class-hxrv-db.php';
require_once HXRV_PLUGIN_DIR . 'includes/class-hxrv-frontend.php';
require_once HXRV_PLUGIN_DIR . 'includes/class-hxrv-ajax.php';
require_once HXRV_PLUGIN_DIR . 'includes/class-hxrv-admin.php';
require_once HXRV_PLUGIN_DIR . 'includes/class-hxrv-export.php';

register_activation_hook( __FILE__, array( 'HXRV_DB', 'install' ) );

/**
 * Upgrade the schema when the plugin is updated without re-activation.
 */
function hxrv_maybe_upgrade() {
	if ( get_option( 'hxrv_db_version' ) !== HXRV_DB_VERSION ) {
		HXRV_DB::install();
	}
}
add_action( 'plugins_loaded', 'hxrv_maybe_upgrade' );

/**
 * Boot the plugin.
 */
function hxrv_init() {
	HXRV_Frontend::init();
	HXRV_Ajax::init();

	if ( is_admin() ) {
		HXRV_Admin::init();
		HXRV_Export::init();
	}
}
add_action( 'plugins_loaded', 'hxrv_init' );
