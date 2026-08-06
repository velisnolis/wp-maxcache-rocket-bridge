<?php

use PHPUnit\Framework\TestCase;

final class SyncManagerTest extends TestCase {

	private const HTACCESS_WORDPRESS = <<<'HTA'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
</IfModule>
# END WordPress
HTA;

	private const HTACCESS_EXTERNAL_MAXCACHE = <<<'HTA'
<IfModule maxcache_module>
    MaxCache On
    MaxCachePath /wp-content/cache/wp-rocket/{HTTP_HOST}{REQUEST_URI}/index.html
</IfModule>

# BEGIN WordPress
RewriteEngine On
# END WordPress
HTA;

	private string $htaccess;

	protected function setUp(): void {
		WMRB_Test_State::reset();

		// The retry delay is real-world pacing, not behaviour under test.
		add_filter( 'wmrb_probe_retry_delay_ms', static fn() => 0 );

		$this->htaccess = ABSPATH . '.htaccess';
		$this->remove_backups();

		if ( file_exists( $this->htaccess ) ) {
			chmod( $this->htaccess, 0644 );
			unlink( $this->htaccess );
		}
	}

	protected function tearDown(): void {
		$this->remove_backups();
	}

	private function remove_backups(): void {
		foreach ( (array) glob( WP_CONTENT_DIR . '/wmrb-backups/*.bak' ) as $file ) {
			@unlink( $file );
		}
	}

	/**
	 * @return array<int,string>
	 */
	private function backups(): array {
		$files = glob( WP_CONTENT_DIR . '/wmrb-backups/*.bak' );
		return is_array( $files ) ? $files : array();
	}

	private function write_htaccess( string $content ): void {
		file_put_contents( $this->htaccess, $content );
	}

	private function read_htaccess(): string {
		return (string) file_get_contents( $this->htaccess );
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function manager( array $overrides = array() ): WMRB_Sync_Manager {
		$options = array_merge( WMRB_Plugin::default_options(), $overrides );
		return new WMRB_Sync_Manager( new WMRB_Snippet_Service( $options ), $options );
	}

	// -----------------------------------------------------------------
	// Ownership detection
	// -----------------------------------------------------------------

	public function test_missing_htaccess_is_unmanaged(): void {
		$this->assertSame( WMRB_Sync_Manager::MODE_UNMANAGED, $this->manager()->inspect_htaccess_configuration()['mode'] );
	}

	public function test_htaccess_without_maxcache_is_unmanaged(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		$this->assertSame( WMRB_Sync_Manager::MODE_UNMANAGED, $this->manager()->inspect_htaccess_configuration()['mode'] );
	}

	public function test_foreign_maxcache_block_is_external(): void {
		$this->write_htaccess( self::HTACCESS_EXTERNAL_MAXCACHE );

		$inspection = $this->manager()->inspect_htaccess_configuration();

		$this->assertSame( WMRB_Sync_Manager::MODE_EXTERNAL, $inspection['mode'] );
		$this->assertSame( 1, $inspection['maxcache_blocks'] );
		$this->assertSame( 0, $inspection['wmrb_blocks'] );
	}

	public function test_own_block_is_managed(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );
		$this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( WMRB_Sync_Manager::MODE_MANAGED, $this->manager()->inspect_htaccess_configuration()['mode'] );
	}

	// -----------------------------------------------------------------
	// Applying the block
	// -----------------------------------------------------------------

	public function test_apply_appends_block_and_preserves_existing_rules(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		$state = $this->manager()->apply_snippet_to_htaccess();
		$after = $this->read_htaccess();

		$this->assertSame( 'in_sync', $state['status'] );
		$this->assertStringContainsString( '# BEGIN WordPress', $after );
		$this->assertStringContainsString( 'RewriteRule ^index\.php$ - [L]', $after );
		$this->assertStringContainsString( '# BEGIN WMRB suggested MaxCache snippet', $after );
		$this->assertStringContainsString( 'MaxCache On', $after );
	}

	public function test_apply_is_idempotent(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		$this->manager()->apply_snippet_to_htaccess();
		$first = $this->read_htaccess();

		$this->manager()->apply_snippet_to_htaccess();
		$second = $this->read_htaccess();

		$this->assertSame( 1, substr_count( $second, '# BEGIN WMRB suggested MaxCache snippet' ) );
		$this->assertSame( $first, $second );
	}

	public function test_apply_replaces_a_stale_block_rather_than_duplicating(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );
		$this->manager()->apply_snippet_to_htaccess();

		$this->manager( array( 'serve_gzip_variant' => true ) )->apply_snippet_to_htaccess();
		$after = $this->read_htaccess();

		$this->assertSame( 1, substr_count( $after, '# BEGIN WMRB suggested MaxCache snippet' ) );
		$this->assertStringContainsString( '{GZIP_SUFFIX}', $after );
	}

	public function test_apply_creates_a_backup_of_the_original(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertCount( 1, $this->backups() );
		$this->assertFileExists( $state['last_backup_file'] );
		$this->assertSame( self::HTACCESS_WORDPRESS, file_get_contents( $state['last_backup_file'] ) );
	}

	public function test_apply_refuses_when_an_external_block_is_present(): void {
		$this->write_htaccess( self::HTACCESS_EXTERNAL_MAXCACHE );

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( 'pending_apply', $state['status'] );
		$this->assertNotSame( '', $state['last_error'] );
		$this->assertSame( self::HTACCESS_EXTERNAL_MAXCACHE, $this->read_htaccess() );
		$this->assertCount( 0, $this->backups() );
	}

	// -----------------------------------------------------------------
	// Takeover
	// -----------------------------------------------------------------

	public function test_takeover_removes_external_block_and_installs_managed_one(): void {
		$this->write_htaccess( self::HTACCESS_EXTERNAL_MAXCACHE );

		$this->manager()->take_over_htaccess_management();
		$after = $this->read_htaccess();

		$this->assertSame( 1, substr_count( $after, '<IfModule maxcache_module>' ) );
		$this->assertSame( 1, substr_count( $after, '# BEGIN WMRB suggested MaxCache snippet' ) );
		$this->assertStringContainsString( '# BEGIN WordPress', $after );
		$this->assertSame( WMRB_Sync_Manager::MODE_MANAGED, $this->manager()->inspect_htaccess_configuration()['mode'] );
	}

	public function test_takeover_backs_up_before_touching_anything(): void {
		$this->write_htaccess( self::HTACCESS_EXTERNAL_MAXCACHE );

		$state = $this->manager()->take_over_htaccess_management();

		$this->assertSame( self::HTACCESS_EXTERNAL_MAXCACHE, file_get_contents( $state['last_backup_file'] ) );
	}

	// -----------------------------------------------------------------
	// Takeover over foreign blocks with nested sections (P2 #5)
	//
	// An orphaned </IfModule> is a confirmed 500 on Apache, so the balance of
	// opening and closing tags is the invariant that matters here.
	// -----------------------------------------------------------------

	private const HTACCESS_NESTED_MAXCACHE = <<<'HTA'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
</IfModule>
# END WordPress

<IfModule maxcache_module>
    MaxCache On
    <IfModule mod_headers.c>
        Header set X-Cache-Layer "maxcache"
    </IfModule>
    MaxCacheExcludeURI "/feed"
    MaxCachePath /wp-content/cache/wp-rocket/{HTTP_HOST}{REQUEST_URI}/index.html
</IfModule>

<IfModule mod_deflate.c>
AddOutputFilterByType DEFLATE text/html
</IfModule>
HTA;

	private function assert_ifmodule_tags_balanced( string $content ): void {
		$open  = preg_match_all( '/<IfModule\b/i', $content );
		$close = preg_match_all( '/<\/IfModule\s*>/i', $content );

		$this->assertSame(
			$open,
			$close,
			"Unbalanced IfModule tags ({$open} open, {$close} close) — Apache returns 500 for this:\n" . $content
		);
	}

	public function test_takeover_over_a_nested_block_leaves_balanced_tags(): void {
		$this->write_htaccess( self::HTACCESS_NESTED_MAXCACHE );

		$this->manager()->take_over_htaccess_management();

		$this->assert_ifmodule_tags_balanced( $this->read_htaccess() );
	}

	public function test_takeover_removes_the_whole_nested_block(): void {
		$this->write_htaccess( self::HTACCESS_NESTED_MAXCACHE );

		$this->manager()->take_over_htaccess_management();
		$after = $this->read_htaccess();

		$this->assertStringNotContainsString( 'X-Cache-Layer', $after );
		$this->assertStringNotContainsString( 'MaxCacheExcludeURI "/feed"', $after );
		$this->assertSame( 1, substr_count( $after, '<IfModule maxcache_module>' ) );
	}

	public function test_takeover_preserves_unrelated_blocks(): void {
		$this->write_htaccess( self::HTACCESS_NESTED_MAXCACHE );

		$this->manager()->take_over_htaccess_management();
		$after = $this->read_htaccess();

		$this->assertStringContainsString( 'RewriteEngine On', $after );
		$this->assertStringContainsString( 'AddOutputFilterByType DEFLATE text/html', $after );
		$this->assertStringContainsString( '<IfModule mod_deflate.c>', $after );
	}

	public function test_takeover_handles_a_maxcache_block_inside_another_block(): void {
		$this->write_htaccess(
			"<IfModule mod_env.c>\n" .
			"SetEnv FOO bar\n" .
			"<IfModule maxcache_module>\n" .
			"    MaxCache On\n" .
			"</IfModule>\n" .
			"</IfModule>\n"
		);

		$this->manager()->take_over_htaccess_management();
		$after = $this->read_htaccess();

		$this->assert_ifmodule_tags_balanced( $after );
		$this->assertStringContainsString( 'SetEnv FOO bar', $after );
		$this->assertStringContainsString( '<IfModule mod_env.c>', $after );
	}

	public function test_takeover_removes_several_foreign_blocks(): void {
		$this->write_htaccess(
			"<IfModule maxcache_module>\nMaxCache On\n</IfModule>\n\n" .
			"# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n\n" .
			"<IfModule maxcache_module>\nMaxCache Off\n</IfModule>\n"
		);

		$this->manager()->take_over_htaccess_management();
		$after = $this->read_htaccess();

		$this->assert_ifmodule_tags_balanced( $after );
		$this->assertSame( 1, substr_count( $after, '<IfModule maxcache_module>' ) );
		$this->assertStringNotContainsString( 'MaxCache Off', $after );
	}

	public function test_takeover_refuses_when_a_block_is_never_closed(): void {
		$malformed = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n\n" .
			"<IfModule maxcache_module>\n    MaxCache On\n";

		$this->write_htaccess( $malformed );

		$state = $this->manager()->take_over_htaccess_management();

		$this->assertSame( $malformed, $this->read_htaccess(), 'A file that cannot be parsed must not be rewritten' );
		$this->assertSame( 'pending_apply', $state['status'] );
		$this->assertNotSame( '', $state['last_error'] );
		$this->assertCount( 0, $this->backups() );
	}

	public function test_takeover_replaces_an_existing_wmrb_block_without_duplicating(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );
		$this->manager()->apply_snippet_to_htaccess();

		$this->manager()->take_over_htaccess_management();
		$after = $this->read_htaccess();

		$this->assert_ifmodule_tags_balanced( $after );
		$this->assertSame( 1, substr_count( $after, '# BEGIN WMRB suggested MaxCache snippet' ) );
		$this->assertSame( 1, substr_count( $after, '<IfModule maxcache_module>' ) );
	}

	public function test_takeover_leaves_a_negated_ifmodule_alone(): void {
		// "<IfModule !maxcache_module>" is a fallback for when the module is
		// absent; it is not a MaxCache configuration block to take over.
		$this->write_htaccess(
			"<IfModule !maxcache_module>\n" .
			"Header set X-No-MaxCache 1\n" .
			"</IfModule>\n"
		);

		$this->manager()->take_over_htaccess_management();
		$after = $this->read_htaccess();

		$this->assert_ifmodule_tags_balanced( $after );
		$this->assertStringContainsString( 'X-No-MaxCache', $after );
	}

	/**
	 * Structural parsing is exactly the kind of code that breaks on the input
	 * nobody thought of, so throw a pile of shapes at it. The contract is
	 * absolute: either the takeover refuses, or the result is balanced. There
	 * is no third outcome in which a half-cut section reaches Apache.
	 */
	public function test_takeover_never_produces_unbalanced_tags(): void {
		mt_srand( 20260806 );

		$fragments = array(
			"<IfModule maxcache_module>\nMaxCache On\n</IfModule>",
			"<IfModule maxcache_module>\n<IfModule mod_headers.c>\nHeader set A b\n</IfModule>\nMaxCachePath /x\n</IfModule>",
			"<IfModule maxcache_module>\n<IfModule a.c>\n<IfModule b.c>\nSetEnv X 1\n</IfModule>\n</IfModule>\n</IfModule>",
			"<IfModule mod_rewrite.c>\nRewriteEngine On\n</IfModule>",
			"<IfModule mod_env.c>\n<IfModule maxcache_module>\nMaxCache On\n</IfModule>\n</IfModule>",
			"# BEGIN WordPress\nRewriteEngine On\n# END WordPress",
			"AddDefaultCharset UTF-8",
			"<FilesMatch \"\\.css$\">\nHeader set X y\n</FilesMatch>",
			"<IfModule !maxcache_module>\nHeader set X-No 1\n</IfModule>",
			"   <IfModule maxcache_module>\n   MaxCache On\n   </IfModule>",
		);

		for ( $case = 0; $case < 300; $case++ ) {
			$content = '';
			$count   = 1 + mt_rand( 0, 4 );

			for ( $i = 0; $i < $count; $i++ ) {
				$content .= $fragments[ mt_rand( 0, count( $fragments ) - 1 ) ] . "\n\n";
			}

			$this->write_htaccess( $content );
			$this->manager()->take_over_htaccess_management();
			$after = $this->read_htaccess();

			if ( $after === $content ) {
				continue; // Refused, which is a valid outcome.
			}

			$this->assert_ifmodule_tags_balanced( $after );
			$this->assertSame(
				1,
				substr_count( $after, '<IfModule maxcache_module>' ),
				"Expected exactly one managed block for input:\n" . $content
			);
		}
	}

	// -----------------------------------------------------------------
	// Rollback
	// -----------------------------------------------------------------

	public function test_manual_rollback_restores_the_backup(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		$manager = $this->manager();
		$manager->apply_snippet_to_htaccess();
		$this->assertStringContainsString( 'MaxCache On', $this->read_htaccess() );

		$manager->rollback_last_backup();

		$this->assertSame( self::HTACCESS_WORDPRESS, $this->read_htaccess() );
	}

	public function test_rollback_without_a_backup_reports_an_error(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		$state = $this->manager()->rollback_last_backup();

		$this->assertNotSame( '', $state['last_error'] );
	}

	// -----------------------------------------------------------------
	// Post-write verification and auto-rollback (P1 fix 2)
	// -----------------------------------------------------------------

	public function test_write_is_reverted_when_the_site_stops_responding(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		// Healthy before the write, persistently 500 after it (one failure
		// alone is retried, so it must fail every attempt).
		WMRB_Test_State::queue_response( 200 );
		WMRB_Test_State::queue_response( 500 );
		WMRB_Test_State::queue_response( 500 );
		WMRB_Test_State::queue_response( 500 );

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( self::HTACCESS_WORDPRESS, $this->read_htaccess() );
		$this->assertStringNotContainsString( 'MaxCache On', $this->read_htaccess() );
		$this->assertSame( 'pending_apply', $state['status'] );
		$this->assertStringContainsString( 'restaurat', $state['last_error'] );
	}

	public function test_write_is_kept_when_the_site_still_responds(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		WMRB_Test_State::queue_response( 200 );
		WMRB_Test_State::queue_response( 200 );

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertStringContainsString( 'MaxCache On', $this->read_htaccess() );
		$this->assertSame( 'in_sync', $state['status'] );
		$this->assertSame( '', $state['last_error'] );
	}

	public function test_a_redirect_counts_as_a_healthy_site(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		WMRB_Test_State::queue_response( 301 );
		WMRB_Test_State::queue_response( 301 );

		$this->assertSame( 'in_sync', $this->manager()->apply_snippet_to_htaccess()['status'] );
		$this->assertStringContainsString( 'MaxCache On', $this->read_htaccess() );
	}

	public function test_an_already_broken_site_is_not_rolled_back(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		// Broken before the write too, so this write is not the culprit.
		WMRB_Test_State::queue_response( 500 );
		WMRB_Test_State::queue_response( 500 );

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertStringContainsString( 'MaxCache On', $this->read_htaccess() );
		$this->assertSame( 'in_sync', $state['status'] );
	}

	public function test_unreachable_loopback_warns_instead_of_rolling_back(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		WMRB_Test_State::queue_response( 200 );
		WMRB_Test_State::queue_response( 'error' );
		WMRB_Test_State::queue_response( 'error' );
		WMRB_Test_State::queue_response( 'error' );

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertStringContainsString( 'MaxCache On', $this->read_htaccess() );
		$this->assertSame( 'applied_unverified', $state['status'] );
		$this->assertStringContainsString( 'no s’ha pogut verificar', $state['last_error'] );
	}

	public function test_verification_is_skipped_when_nothing_would_change(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );
		$this->manager()->apply_snippet_to_htaccess();

		WMRB_Test_State::$remote_calls = array();
		$this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( array(), WMRB_Test_State::$remote_calls );
		$this->assertCount( 1, $this->backups(), 'A no-op apply should not pile up backups' );
	}

	public function test_probe_url_is_cache_busted(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		$this->manager()->apply_snippet_to_htaccess();

		$this->assertNotEmpty( WMRB_Test_State::$remote_calls );
		$this->assertMatchesRegularExpression( '/wmrb_probe=[A-Za-z0-9]{20}/', WMRB_Test_State::$remote_calls[0] );
	}

	public function test_takeover_is_verified_too(): void {
		$this->write_htaccess( self::HTACCESS_EXTERNAL_MAXCACHE );

		WMRB_Test_State::queue_response( 200 );
		WMRB_Test_State::queue_response( 503 );
		WMRB_Test_State::queue_response( 503 );
		WMRB_Test_State::queue_response( 503 );

		$state = $this->manager()->take_over_htaccess_management();

		$this->assertSame( self::HTACCESS_EXTERNAL_MAXCACHE, $this->read_htaccess() );
		$this->assertSame( 'pending_apply', $state['status'] );
	}

	public function test_backup_survives_an_auto_rollback(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		WMRB_Test_State::queue_response( 200 );
		WMRB_Test_State::queue_response( 500 );

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertNotSame( '', $state['last_backup_file'] );
		$this->assertFileExists( $state['last_backup_file'] );
		$this->assertSame( self::HTACCESS_WORDPRESS, file_get_contents( $state['last_backup_file'] ) );
	}

	// -----------------------------------------------------------------
	// Atomic writes (P1 fix 3)
	// -----------------------------------------------------------------

	public function test_write_preserves_file_permissions(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );
		chmod( $this->htaccess, 0640 );

		$this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( '0640', substr( sprintf( '%o', fileperms( $this->htaccess ) ), -4 ) );
	}

	public function test_write_leaves_no_temporary_files_behind(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );

		$this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( array(), glob( ABSPATH . '.wmrb-tmp-*' ) ?: array() );
	}

	public function test_two_backups_in_the_same_second_do_not_collide(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );
		$this->manager()->apply_snippet_to_htaccess();

		// A different snippet forces a second real write within the same second.
		$this->manager( array( 'serve_gzip_variant' => true ) )->apply_snippet_to_htaccess();

		$this->assertCount( 2, $this->backups() );
	}

	// -----------------------------------------------------------------
	// Sync state
	// -----------------------------------------------------------------

	public function test_state_becomes_pending_when_the_snippet_changes(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );
		$this->manager()->apply_snippet_to_htaccess();

		$state = $this->manager( array( 'serve_gzip_variant' => true ) )->refresh_state_from_current_fingerprint();

		$this->assertSame( 'pending_apply', $state['status'] );
	}

	public function test_state_stays_in_sync_when_nothing_changes(): void {
		$this->write_htaccess( self::HTACCESS_WORDPRESS );
		$this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( 'in_sync', $this->manager()->refresh_state_from_current_fingerprint()['status'] );
	}
}
