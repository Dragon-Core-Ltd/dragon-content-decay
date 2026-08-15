<?php
/**
 * Plugin Name: Dragon Content Decay
 * Plugin URI: https://dragoncore.ltd/plugins/dragon-content-decay
 * Description: Identify content losing traffic over time by connecting to Google Analytics 4. Helps prioritize which posts to refresh.
 * Version: 1.0.2
 * Author: Dragon Core
 * Author URI: https://dragoncore.ltd
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dragon-content-decay
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

namespace DragonContentDecay;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'DRAGONCONTENTDECAY_VERSION', '1.0.2' );
define( 'DRAGONCONTENTDECAY_PLUGIN_FILE', __FILE__ );
define( 'DRAGONCONTENTDECAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DRAGONCONTENTDECAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DRAGONCONTENTDECAY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoload Composer dependencies
if ( file_exists( DRAGONCONTENTDECAY_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once DRAGONCONTENTDECAY_PLUGIN_DIR . 'vendor/autoload.php';
}

// Load plugin classes
require_once DRAGONCONTENTDECAY_PLUGIN_DIR . 'includes/class-plugin.php';
require_once DRAGONCONTENTDECAY_PLUGIN_DIR . 'includes/class-admin.php';
require_once DRAGONCONTENTDECAY_PLUGIN_DIR . 'includes/class-oauth.php';
require_once DRAGONCONTENTDECAY_PLUGIN_DIR . 'includes/class-api-ga4.php';
require_once DRAGONCONTENTDECAY_PLUGIN_DIR . 'includes/class-analyzer.php';
require_once DRAGONCONTENTDECAY_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once DRAGONCONTENTDECAY_PLUGIN_DIR . 'includes/class-notifications.php';

/**
 * Plugin activation hook
 */
function dragoncontentdecay_activate() {
	Plugin::activate();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\dragoncontentdecay_activate' );

/**
 * Plugin deactivation hook
 */
function dragoncontentdecay_deactivate() {
	Plugin::deactivate();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\dragoncontentdecay_deactivate' );

/**
 * Initialize the plugin
 */
function dragoncontentdecay_init() {
	Plugin::get_instance();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\dragoncontentdecay_init' );

/**
 * Add settings link to plugin row
 *
 * @param array $links Plugin action links.
 * @return array Modified links.
 */
function dragoncontentdecay_plugin_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'tools.php?page=dragon-content-decay&tab=settings' ) ),
		esc_html__( 'Settings', 'dragon-content-decay' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . DRAGONCONTENTDECAY_PLUGIN_BASENAME, __NAMESPACE__ . '\dragoncontentdecay_plugin_action_links' );
