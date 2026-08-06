<?php
/**
 * PHPUnit bootstrap.
 *
 * Creates a throwaway filesystem sandbox that stands in for a WordPress install,
 * defines the constants the plugin expects, loads the WordPress stubs, and then
 * loads the classes under test.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$wmrb_sandbox = sys_get_temp_dir() . '/wmrb-tests-' . getmypid();
if ( ! is_dir( $wmrb_sandbox . '/wp/wp-content' ) ) {
	mkdir( $wmrb_sandbox . '/wp/wp-content', 0777, true );
}

define( 'WMRB_TEST_SANDBOX', $wmrb_sandbox );
define( 'ABSPATH', $wmrb_sandbox . '/wp/' );
define( 'WP_CONTENT_DIR', $wmrb_sandbox . '/wp/wp-content' );
define( 'WMRB_VERSION', '1.0.0' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WMRB_GITHUB_REPO', 'velisnolis/wp-maxcache-rocket-bridge' );

require_once __DIR__ . '/wp-stubs.php';

require_once __DIR__ . '/../includes/class-wmrb-plugin.php';
require_once __DIR__ . '/../includes/class-wmrb-snippet-service.php';
require_once __DIR__ . '/../includes/class-wmrb-sync-manager.php';
require_once __DIR__ . '/../includes/class-wmrb-github-updater.php';
require_once __DIR__ . '/../includes/class-wmrb-diagnostics-service.php';
require_once __DIR__ . '/../includes/class-wmrb-quick-test-service.php';
require_once __DIR__ . '/../includes/class-wmrb-purge-observer.php';
require_once __DIR__ . '/../includes/class-wmrb-admin-page.php';

register_shutdown_function(
	static function () use ( $wmrb_sandbox ) {
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $wmrb_sandbox, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
		}

		@rmdir( $wmrb_sandbox );
	}
);
