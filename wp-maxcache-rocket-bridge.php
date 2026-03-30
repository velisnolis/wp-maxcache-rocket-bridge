<?php
/**
 * Plugin Name: WP Rocket + MAxCache Bridge
 * Description: Diagnostic and helper bridge between WP Rocket and Apache mod_maxcache.
 * Version: 0.2.0
 * Author: Miras
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wp-maxcache-rocket-bridge
 * Update URI: https://github.com/velisnolis/wp-maxcache-rocket-bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WMRB_VERSION', '0.2.0' );
define( 'WMRB_PLUGIN_FILE', __FILE__ );
define( 'WMRB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WMRB_GITHUB_REPO', 'velisnolis/wp-maxcache-rocket-bridge' );

require_once WMRB_PLUGIN_DIR . 'includes/class-wmrb-diagnostics-service.php';
require_once WMRB_PLUGIN_DIR . 'includes/class-wmrb-snippet-service.php';
require_once WMRB_PLUGIN_DIR . 'includes/class-wmrb-sync-manager.php';
require_once WMRB_PLUGIN_DIR . 'includes/class-wmrb-quick-test-service.php';
require_once WMRB_PLUGIN_DIR . 'includes/class-wmrb-purge-observer.php';
require_once WMRB_PLUGIN_DIR . 'includes/class-wmrb-admin-page.php';
require_once WMRB_PLUGIN_DIR . 'includes/class-wmrb-github-updater.php';
require_once WMRB_PLUGIN_DIR . 'includes/class-wmrb-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'wp-maxcache-rocket-bridge', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

new WMRB_Github_Updater( WMRB_PLUGIN_FILE );

WMRB_Plugin::instance();
