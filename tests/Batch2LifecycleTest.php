<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Executable specification for lifecycle and sync-state work that is planned
 * but not implemented. These tests are expected to FAIL until it lands.
 *
 * Run with: vendor/bin/phpunit --group batch2
 */
#[Group( 'batch2' )]
final class Batch2LifecycleTest extends TestCase {

	private const HTACCESS_WORDPRESS = <<<'HTA'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^index\.php$ - [L]
</IfModule>
# END WordPress
HTA;

	private string $htaccess;

	protected function setUp(): void {
		WMRB_Test_State::reset();

		$this->htaccess = ABSPATH . '.htaccess';
		foreach ( (array) glob( WP_CONTENT_DIR . '/wmrb-backups/*.bak' ) as $file ) {
			@unlink( $file );
		}
		if ( file_exists( $this->htaccess ) ) {
			unlink( $this->htaccess );
		}
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function manager( array $overrides = array() ): WMRB_Sync_Manager {
		$options = array_merge( WMRB_Plugin::default_options(), $overrides );
		return new WMRB_Sync_Manager( new WMRB_Snippet_Service( $options ), $options );
	}

	private function read(): string {
		return (string) file_get_contents( $this->htaccess );
	}

	// =================================================================
	// P1 #4 — uninstalling leaves MaxCache running
	//
	// Removing the plugin does not remove its block, so Apache keeps serving
	// static HTML with no WordPress involvement — and now with no UI to undo
	// it. Anyone deleting the plugin to "turn this off" gets the opposite.
	// =================================================================

	public function test_an_uninstall_script_exists(): void {
		$this->assertFileExists( dirname( __DIR__ ) . '/uninstall.php' );
	}

	public function test_uninstalling_removes_the_managed_block_and_its_options(): void {
		$uninstall = dirname( __DIR__ ) . '/uninstall.php';
		if ( ! file_exists( $uninstall ) ) {
			$this->fail( 'uninstall.php does not exist yet' );
		}

		file_put_contents( $this->htaccess, self::HTACCESS_WORDPRESS );
		$this->manager()->apply_snippet_to_htaccess();
		$this->assertStringContainsString( 'MaxCache On', $this->read() );

		WMRB_Test_State::$options['wmrb_purge_log'] = array( array( 'time' => 'x', 'hook' => 'y' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp-maxcache-rocket-bridge/wp-maxcache-rocket-bridge.php' );
		}
		require $uninstall;

		$after = $this->read();

		$this->assertStringNotContainsString( 'MaxCache On', $after, 'The MaxCache block must not outlive the plugin' );
		$this->assertStringNotContainsString( 'BEGIN WMRB', $after );
		$this->assertStringContainsString( '# BEGIN WordPress', $after, 'Unrelated rules must survive' );

		$this->assertArrayNotHasKey( 'wmrb_options', WMRB_Test_State::$options );
		$this->assertArrayNotHasKey( 'wmrb_sync_state', WMRB_Test_State::$options );
		$this->assertArrayNotHasKey( 'wmrb_purge_log', WMRB_Test_State::$options );
	}

	// =================================================================
	// P1 #6 — "in_sync" never looks at .htaccess
	//
	// The status compares the freshly generated snippet against a hash stored
	// in the database. Whatever happened to the actual file is invisible: hand
	// edits, another plugin rewriting it, or AccelerateWP taking the block
	// back all leave the UI cheerfully reporting in_sync.
	// =================================================================

	public function test_an_externally_modified_block_is_reported_as_pending(): void {
		file_put_contents( $this->htaccess, self::HTACCESS_WORDPRESS );
		$this->manager()->apply_snippet_to_htaccess();

		$mangled = str_replace( 'MaxCache On', 'MaxCache Off', $this->read() );
		file_put_contents( $this->htaccess, $mangled );

		$this->assertSame( 'pending_apply', $this->manager()->refresh_state_from_current_fingerprint()['status'] );
	}

	public function test_an_externally_removed_block_is_reported_as_pending(): void {
		file_put_contents( $this->htaccess, self::HTACCESS_WORDPRESS );
		$this->manager()->apply_snippet_to_htaccess();

		file_put_contents( $this->htaccess, self::HTACCESS_WORDPRESS );

		$this->assertSame( 'pending_apply', $this->manager()->refresh_state_from_current_fingerprint()['status'] );
	}

	public function test_an_untouched_block_stays_in_sync(): void {
		file_put_contents( $this->htaccess, self::HTACCESS_WORDPRESS );
		$this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( 'in_sync', $this->manager()->refresh_state_from_current_fingerprint()['status'] );
	}

	// =================================================================
	// P1 #7 — a database write on every admin page view
	//
	// refresh_state_from_current_fingerprint() writes unconditionally and sets
	// last_change_at to now every time, so "last change detected" really means
	// "last time you opened the page".
	// =================================================================

	public function test_refreshing_without_changes_writes_nothing(): void {
		file_put_contents( $this->htaccess, self::HTACCESS_WORDPRESS );
		$manager = $this->manager();
		$manager->apply_snippet_to_htaccess();

		$before = WMRB_Test_State::writes( 'wmrb_sync_state' );
		$manager->refresh_state_from_current_fingerprint();
		$manager->refresh_state_from_current_fingerprint();

		$this->assertSame( $before, WMRB_Test_State::writes( 'wmrb_sync_state' ) );
	}

	public function test_last_change_at_only_moves_on_a_real_change(): void {
		file_put_contents( $this->htaccess, self::HTACCESS_WORDPRESS );
		$manager = $this->manager();
		$manager->apply_snippet_to_htaccess();

		$first = $manager->refresh_state_from_current_fingerprint()['last_change_at'];

		// Without moving the clock this passes for the wrong reason: both
		// calls land in the same second.
		WMRB_Test_State::advance_clock( 3600 );
		$again = $manager->refresh_state_from_current_fingerprint()['last_change_at'];

		$this->assertSame( $first, $again );
	}

	public function test_last_change_at_does_move_when_the_snippet_changes(): void {
		file_put_contents( $this->htaccess, self::HTACCESS_WORDPRESS );
		$this->manager()->apply_snippet_to_htaccess();

		$changed = $this->manager( array( 'serve_gzip_variant' => true ) )->refresh_state_from_current_fingerprint();

		$this->assertSame( 'pending_apply', $changed['status'] );
		$this->assertNotSame( '', $changed['last_change_at'] );
	}
}
