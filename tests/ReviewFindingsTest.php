<?php

use PHPUnit\Framework\TestCase;

final class WMRB_Owner_Mismatch_Sync_Manager extends WMRB_Sync_Manager {
	protected function get_file_owner( $path ) {
		$owner = fileowner( $path );

		return false !== strpos( basename( $path ), '.wmrb-tmp-' ) && false !== $owner
			? $owner + 1
			: $owner;
	}
}

final class WMRB_Rename_Failure_Sync_Manager extends WMRB_Sync_Manager {
	protected function rename_file( $source, $target ) {
		unset( $source, $target );
		return false;
	}
}

/**
 * Regression tests for the findings raised in the adversarial review.
 * Each test names the finding it pins down.
 */
final class ReviewFindingsTest extends TestCase {

	private string $htaccess;

	protected function setUp(): void {
		WMRB_Test_State::reset();

		// The retry delay is real-world pacing, not behaviour under test.
		add_filter( 'wmrb_probe_retry_delay_ms', static fn() => 0 );

		$this->htaccess = ABSPATH . '.htaccess';
		foreach ( (array) glob( WP_CONTENT_DIR . '/wmrb-backups/*.bak' ) as $file ) {
			@unlink( $file );
		}
		if ( file_exists( $this->htaccess ) ) {
			chmod( $this->htaccess, 0644 );
			unlink( $this->htaccess );
		}
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function service( array $overrides = array() ): WMRB_Snippet_Service {
		return new WMRB_Snippet_Service( array_merge( WMRB_Plugin::default_options(), $overrides ) );
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function manager( array $overrides = array() ): WMRB_Sync_Manager {
		$options = array_merge( WMRB_Plugin::default_options(), $overrides );
		return new WMRB_Sync_Manager( new WMRB_Snippet_Service( $options ), $options );
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function set_rocket_settings( array $settings ): void {
		WMRB_Test_State::$options['wp_rocket_settings'] = $settings;
	}

	private function read(): string {
		return (string) file_get_contents( $this->htaccess );
	}

	private function assert_balanced( string $content ): void {
		$this->assertSame(
			preg_match_all( '/<IfModule\b/i', $content ),
			preg_match_all( '#</IfModule\s*>#i', $content ),
			"Unbalanced IfModule tags:\n" . $content
		);
	}

	// =================================================================
	// #1 — the assembled directive must compile, not just its fragments
	// =================================================================

	public function test_fragments_that_clash_when_combined_are_rejected(): void {
		// Each compiles alone; together they are a duplicate named group.
		$this->set_rocket_settings(
			array( 'cache_reject_uri' => array( '(?P<dup>/alpha)', '(?P<dup>/beta)' ) )
		);

		$snippet = $this->service()->get_snippet();

		$this->assertSame( 1, preg_match( '/MaxCacheExcludeURI "(.*)"/', $snippet, $m ) );
		$this->assertNotFalse(
			@preg_match( '~' . $m[1] . '~', '/some/uri' ),
			'The assembled MaxCacheExcludeURI does not compile: ' . $m[1]
		);
	}

	public function test_the_first_of_two_clashing_fragments_is_kept(): void {
		$this->set_rocket_settings(
			array( 'cache_reject_uri' => array( '(?P<dup>/alpha)', '(?P<dup>/beta)' ) )
		);

		$snippet = $this->service()->get_snippet();

		$this->assertStringContainsString( '(?P<dup>/alpha)', $snippet );
		$this->assertStringNotContainsString( '(?P<dup>/beta)', $snippet );
	}

	public function test_a_fragment_dropped_for_clashing_is_reported(): void {
		$this->set_rocket_settings(
			array( 'cache_reject_uri' => array( '(?P<dup>/alpha)', '(?P<dup>/beta)' ) )
		);

		$rejected = $this->service()->get_rejected_patterns();

		$this->assertCount( 1, $rejected );
		$this->assertSame( '(?P<dup>/beta)', $rejected[0]['pattern'] );
	}

	public function test_every_emitted_directive_compiles_under_clashing_input(): void {
		$this->set_rocket_settings(
			array(
				'cache_reject_uri'     => array( '(?P<a>/x)', '(?P<a>/y)', '/ok(.*)' ),
				'cache_reject_ua'      => array( '(?P<b>Foo)', '(?P<b>Bar)' ),
				'cache_reject_cookies' => array( '(?P<c>ck1)', '(?P<c>ck2)' ),
			)
		);

		$snippet = $this->service()->get_snippet();

		foreach ( array( 'MaxCacheExcludeURI', 'MaxCacheExcludeUA', 'MaxCacheExcludeCookie' ) as $directive ) {
			$this->assertSame( 1, preg_match( '/' . $directive . ' "(.*)"/', $snippet, $m ), $directive . ' missing' );
			$this->assertNotFalse( @preg_match( '~' . $m[1] . '~', '/uri' ), $directive . ' does not compile: ' . $m[1] );
		}
	}

	// =================================================================
	// #2 — comments and quoted literals must not be counted as structure
	// =================================================================

	public function test_a_comment_mentioning_a_closing_tag_is_not_counted(): void {
		file_put_contents(
			$this->htaccess,
			"<IfModule maxcache_module>\n" .
			"# nota: aquest bloc es tanca amb </IfModule> al final\n" .
			"MaxCache On\n" .
			"</IfModule>\n\n" .
			"<IfModule mod_deflate.c>\nSetEnv X 1\n</IfModule>\n"
		);

		$this->manager()->take_over_htaccess_management();

		$this->assert_balanced( $this->read() );
		$this->assertStringContainsString( 'SetEnv X 1', $this->read() );
	}

	public function test_a_quoted_literal_closing_tag_is_not_counted(): void {
		file_put_contents(
			$this->htaccess,
			"<IfModule maxcache_module>\n" .
			"Header set X-Note \"</IfModule>\"\n" .
			"MaxCache On\n" .
			"</IfModule>\n\n" .
			"<IfModule mod_env.c>\nSetEnv Y 2\n</IfModule>\n"
		);

		$this->manager()->take_over_htaccess_management();

		$this->assert_balanced( $this->read() );
		$this->assertStringContainsString( 'SetEnv Y 2', $this->read() );
	}

	public function test_a_comment_mentioning_an_opening_tag_is_not_counted(): void {
		file_put_contents(
			$this->htaccess,
			"<IfModule maxcache_module>\n" .
			"# abans hi havia un <IfModule mod_headers.c> aqui\n" .
			"MaxCache On\n" .
			"</IfModule>\n\n" .
			"# END\n"
		);

		$this->manager()->take_over_htaccess_management();

		$this->assert_balanced( $this->read() );
	}

	public function test_an_unterminated_quote_is_refused_as_ambiguous(): void {
		$input = "<IfModule maxcache_module>\nHeader set X \"unterminated\nMaxCache On\n</IfModule>\n";
		file_put_contents( $this->htaccess, $input );

		$state = $this->manager()->take_over_htaccess_management();

		$this->assertSame( $input, $this->read(), 'Ambiguous syntax must not be rewritten' );
		$this->assertSame( 'pending_apply', $state['status'] );
	}

	// =================================================================
	// #3 — concurrent modification must not be clobbered
	// =================================================================

	public function test_a_file_changed_during_the_operation_is_not_overwritten(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );

		// Another process rewrites .htaccess while the probe is in flight.
		$intruder = "# BEGIN WordPress\nRewriteEngine On\nRewriteRule ^new$ - [L]\n# END WordPress\n";
		WMRB_Test_State::$on_remote_get = function () use ( $intruder ) {
			file_put_contents( $this->htaccess, $intruder );
			WMRB_Test_State::$on_remote_get = null;
		};

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( $intruder, $this->read(), 'A concurrent write must survive' );
		$this->assertSame( 'pending_apply', $state['status'] );
		$this->assertNotSame( '', $state['last_error'] );
	}

	public function test_rollback_does_not_clobber_a_later_foreign_change(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );

		WMRB_Test_State::queue_response( 200 ); // healthy baseline
		$foreign = "# someone else owns this now\n";

		// The site looks broken after the write, but by the time we would roll
		// back, the file is no longer the one we wrote.
		WMRB_Test_State::$remote_queue[] = array(
			'response' => array( 'code' => 500 ),
			'body'     => 'error',
		);
		WMRB_Test_State::$on_remote_get = function () use ( $foreign ) {
			if ( 2 === count( WMRB_Test_State::$remote_calls ) ) {
				file_put_contents( $this->htaccess, $foreign );
			}
		};

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( $foreign, $this->read(), 'Rollback must not overwrite a foreign change' );
		$this->assertSame( 'pending_apply', $state['status'], 'A foreign file cannot be recorded as applied' );
	}

	public function test_a_successful_probe_does_not_mark_a_later_foreign_change_as_applied(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		$foreign = "# someone else replaced the managed block\n";

		WMRB_Test_State::$on_remote_get = function () use ( $foreign ) {
			if ( 2 === count( WMRB_Test_State::$remote_calls ) ) {
				file_put_contents( $this->htaccess, $foreign );
				WMRB_Test_State::$on_remote_get = null;
			}
		};

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( $foreign, $this->read(), 'A foreign post-write change must survive' );
		$this->assertSame( 'pending_apply', $state['status'], 'A block that no longer exists cannot be in sync' );
		$this->assertNotSame( '', $state['last_error'] );
	}

	// =================================================================
	// #4 — no non-atomic fallback for .htaccess
	// =================================================================

	public function test_writing_is_refused_when_an_atomic_rename_is_impossible(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		$original = $this->read();

		chmod( ABSPATH, 0555 ); // directory not writable: no temp file, no rename

		try {
			$state = $this->manager()->apply_snippet_to_htaccess();

			$this->assertSame( $original, $this->read(), 'Must not fall back to a truncating in-place write' );
			$this->assertSame( 'pending_apply', $state['status'] );
			$this->assertNotSame( '', $state['last_error'] );
		} finally {
			chmod( ABSPATH, 0755 );
		}
	}

	public function test_a_failed_atomic_rename_never_writes_in_place_to_htaccess(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		$original = $this->read();
		$options  = WMRB_Plugin::default_options();
		$manager  = new WMRB_Rename_Failure_Sync_Manager( new WMRB_Snippet_Service( $options ), $options );

		$state = $manager->apply_snippet_to_htaccess();

		$this->assertSame( $original, $this->read(), 'A failed rename must never fall back to an in-place write' );
		$this->assertSame( 'pending_apply', $state['status'] );
		$this->assertNotSame( '', $state['last_error'] );
		$this->assertSame( array(), glob( ABSPATH . '.htaccess.wmrb-tmp-*' ) ?: array(), 'The failed temporary file must be cleaned up' );
	}

	// =================================================================
	// #5 — the probe must be unique, uncacheable and verified by body
	// =================================================================

	public function test_the_two_probes_use_different_urls(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );

		$this->manager()->apply_snippet_to_htaccess();

		$this->assertCount( 2, WMRB_Test_State::$remote_calls );
		$this->assertNotSame(
			WMRB_Test_State::$remote_calls[0],
			WMRB_Test_State::$remote_calls[1],
			'An identical URL lets a CDN answer the second probe from cache'
		);
	}

	public function test_a_two_hundred_without_the_marker_counts_as_broken(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		$original = $this->read();

		WMRB_Test_State::queue_response( 200 ); // healthy baseline, marker filled in

		// A CDN serving a cached error page does so consistently, so it has to
		// survive every retry.
		WMRB_Test_State::queue_response( 200, '<html>Cloudflare cached error page</html>' );
		WMRB_Test_State::queue_response( 200, '<html>Cloudflare cached error page</html>' );
		WMRB_Test_State::queue_response( 200, '<html>Cloudflare cached error page</html>' );

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( $original, $this->read(), 'A 200 without the marker must trigger a rollback' );
		$this->assertSame( 'pending_apply', $state['status'] );
	}

	public function test_the_probe_responder_echoes_only_the_current_token(): void {
		$manager = $this->manager();
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		$manager->apply_snippet_to_htaccess();

		preg_match( '/wmrb_probe=([A-Za-z0-9]+)/', WMRB_Test_State::$remote_calls[0], $m );

		$this->assertNotEmpty( $m[1] ?? '' );
		$this->assertNull( $manager->get_probe_response_body( 'a-token-that-was-never-issued' ) );
	}

	// =================================================================
	// #6 — a single transient failure must not trigger a rollback
	// =================================================================

	public function test_a_transient_error_is_retried_before_rolling_back(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );

		WMRB_Test_State::queue_response( 200 ); // baseline
		WMRB_Test_State::queue_response( 503 ); // transient, as seen with LSAPI/NPROC
		WMRB_Test_State::queue_response( 200 ); // recovered

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertStringContainsString( 'MaxCache On', $this->read() );
		$this->assertSame( 'in_sync', $state['status'] );
	}

	public function test_a_transient_baseline_failure_does_not_disable_post_write_verification(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		$original = $this->read();

		WMRB_Test_State::queue_response( 503 ); // transient baseline failure
		WMRB_Test_State::queue_response( 200 ); // baseline recovered
		WMRB_Test_State::queue_response( 500 ); // persistent post-write failure
		WMRB_Test_State::queue_response( 500 );
		WMRB_Test_State::queue_response( 500 );

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertCount( 5, WMRB_Test_State::$remote_calls );
		$this->assertSame( $original, $this->read(), 'A transient baseline failure must not skip post-write verification' );
		$this->assertSame( 'pending_apply', $state['status'] );
	}

	public function test_an_unverifiable_baseline_is_reported_if_the_write_continues(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );

		WMRB_Test_State::queue_response( 'error' );
		WMRB_Test_State::queue_response( 'error' );
		WMRB_Test_State::queue_response( 'error' );

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertCount( 3, WMRB_Test_State::$remote_calls );
		$this->assertStringContainsString( 'MaxCache On', $this->read() );
		$this->assertSame( 'applied_unverified', $state['status'] );
		$this->assertNotSame( '', $state['last_error'], 'An inconclusive baseline must require explicit manual verification' );
	}

	public function test_an_applied_unverified_state_is_retried_before_becoming_in_sync(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		WMRB_Test_State::queue_response( 'error' );
		WMRB_Test_State::queue_response( 'error' );
		WMRB_Test_State::queue_response( 'error' );
		$manager = $this->manager();

		$unverified = $manager->apply_snippet_to_htaccess();
		$retried    = $manager->refresh_state_from_current_fingerprint();

		$this->assertSame( 'applied_unverified', $unverified['status'] );
		$this->assertCount( 4, WMRB_Test_State::$remote_calls, 'Refresh must perform a new health probe' );
		$this->assertSame( 'in_sync', $retried['status'] );
		$this->assertSame( '', $retried['last_error'] );
	}

	public function test_a_persistently_failing_baseline_is_reported_if_the_write_continues(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );

		WMRB_Test_State::queue_response( 500 );
		WMRB_Test_State::queue_response( 500 );
		WMRB_Test_State::queue_response( 500 );

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertCount( 3, WMRB_Test_State::$remote_calls );
		$this->assertStringContainsString( 'MaxCache On', $this->read() );
		$this->assertSame( 'applied_unverified', $state['status'] );
		$this->assertNotSame( '', $state['last_error'], 'A failing baseline must require explicit manual verification' );
	}

	public function test_a_persistent_error_still_rolls_back(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		$original = $this->read();

		WMRB_Test_State::queue_response( 200 );
		WMRB_Test_State::queue_response( 500 );
		WMRB_Test_State::queue_response( 500 );
		WMRB_Test_State::queue_response( 500 );
		WMRB_Test_State::queue_response( 500 );

		$state = $this->manager()->apply_snippet_to_htaccess();

		$this->assertSame( $original, $this->read() );
		$this->assertSame( 'pending_apply', $state['status'] );
	}

	public function test_mixed_fail_and_unknown_probe_results_are_order_independent(): void {
		$orders = array(
			array( 'error', 'error', 500 ),
			array( 500, 500, 'error' ),
		);

		foreach ( $orders as $order ) {
			WMRB_Test_State::reset();
			add_filter( 'wmrb_probe_retry_delay_ms', static fn() => 0 );
			file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );

			WMRB_Test_State::queue_response( 200 ); // healthy baseline
			foreach ( $order as $response ) {
				WMRB_Test_State::queue_response( $response );
			}

			$state = $this->manager()->apply_snippet_to_htaccess();

			$this->assertStringContainsString( 'MaxCache On', $this->read(), 'Mixed fail/unknown evidence must not trigger rollback' );
			$this->assertSame( 'applied_unverified', $state['status'] );
			$this->assertNotSame( '', $state['last_error'], 'Mixed evidence must remain explicitly unverified' );
		}
	}

	// =================================================================
	// #7 — permissions must be preserved or the write must fail
	// =================================================================

	public function test_permissions_survive_the_rename(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		chmod( $this->htaccess, 0640 );

		$this->manager()->apply_snippet_to_htaccess();

		clearstatcache( true, $this->htaccess );
		$this->assertSame( '0640', substr( sprintf( '%o', fileperms( $this->htaccess ) ), -4 ) );
	}

	public function test_group_ownership_survives_the_rename(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		$group = filegroup( $this->htaccess );

		$this->manager()->apply_snippet_to_htaccess();

		clearstatcache( true, $this->htaccess );
		$this->assertSame( $group, filegroup( $this->htaccess ) );
	}

	public function test_write_is_refused_when_temporary_file_owner_differs(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		$original = $this->read();
		$options  = WMRB_Plugin::default_options();
		$manager  = new WMRB_Owner_Mismatch_Sync_Manager( new WMRB_Snippet_Service( $options ), $options );

		$state = $manager->apply_snippet_to_htaccess();

		$this->assertSame( $original, $this->read() );
		$this->assertSame( 'pending_apply', $state['status'] );
		$this->assertNotSame( '', $state['last_error'] );
	}

	// =================================================================
	// #8 — the sync summary must use the validation pipeline
	// =================================================================

	public function test_a_rejected_pattern_is_not_counted_as_synced(): void {
		$this->set_rocket_settings( array( 'cache_reject_uri' => array( '/checkout[' ) ) );

		$service = $this->service();

		$this->assertCount( 1, $service->get_rejected_patterns() );
		$this->assertSame( 0, $service->get_sync_summary()['uri_synced'] );
	}

	public function test_the_summary_counts_only_usable_patterns(): void {
		$this->set_rocket_settings(
			array(
				'cache_reject_uri'     => array( '/good(.*)', '/bad[' ),
				'cache_reject_ua'      => array( 'GoodAgent', 'Bad(Agent' ),
				'cache_reject_cookies' => array( 'good_cookie', 'bad_cookie)' ),
			)
		);

		$summary = $this->service()->get_sync_summary();

		$this->assertSame( 1, $summary['uri_synced'] );
		$this->assertSame( 1, $summary['ua_synced'] );
		$this->assertSame( 1, $summary['cookie_synced'] );
	}

	public function test_anchored_patterns_that_only_match_empty_input_are_usable(): void {
		$this->set_rocket_settings( array( 'cache_reject_ua' => array( '^$', '\\A\\z' ) ) );
		$service = $this->service();

		$this->assertTrue( $service->is_usable_regex_fragment( '^$' ) );
		$this->assertTrue( $service->is_usable_regex_fragment( '\\A\\z' ) );
		$this->assertSame( array(), $service->get_rejected_patterns() );
		$this->assertStringContainsString( '^$|\\A\\z', $service->get_snippet() );
	}

	public function test_a_pattern_universal_for_request_uris_is_rejected_as_an_uri_exclusion(): void {
		$this->set_rocket_settings( array( 'cache_reject_uri' => array( '^/', '^$' ) ) );

		$service = $this->service();

		$this->assertSame(
			array(
				array(
					'setting' => 'cache_reject_uri',
					'pattern' => '^/',
				),
			),
			$service->get_rejected_patterns()
		);
		$this->assertStringContainsString( '|^$"', $service->get_snippet() );
	}

	public function test_patterns_universal_for_non_empty_inputs_are_rejected_for_every_directive(): void {
		$universal = array( '.+', '.', '\\S', '[^x]+' );
		$this->set_rocket_settings(
			array(
				'cache_reject_uri'     => array_merge( $universal, array( '^$' ) ),
				'cache_reject_ua'      => array_merge( $universal, array( '^$' ) ),
				'cache_reject_cookies' => array_merge( $universal, array( '^$' ) ),
			)
		);

		$service = $this->service();
		$summary = $service->get_sync_summary();

		$this->assertSame( 1, $summary['uri_synced'], 'Only the empty URI pattern should remain usable' );
		$this->assertSame( 1, $summary['ua_synced'], 'Only the empty UA pattern should remain usable' );
		$this->assertSame( 1, $summary['cookie_synced'], 'Only the empty cookie pattern should remain usable' );
		$this->assertCount( 12, $service->get_rejected_patterns() );
		$this->assertStringContainsString( '^$', $service->get_snippet() );
	}

	public function test_an_irrelevant_rocket_settings_update_does_not_apply_existing_drift(): void {
		$original = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
		file_put_contents( $this->htaccess, $original );
		WMRB_Test_State::$options['wp_rocket_settings'] = array( 'minify_css' => 1 );
		WMRB_Test_State::$options[ WMRB_Sync_Manager::STATE_OPTION_KEY ] = array(
			'status'             => 'pending_apply',
			'current_hash'       => 'new-fingerprint',
			'last_applied_hash'  => 'old-fingerprint',
			'last_change_at'     => '2026-01-01 12:00:00',
			'last_applied_at'    => '2026-01-01 11:00:00',
			'last_backup_file'   => '',
			'last_error'         => '',
		);

		$this->manager(
			array(
				'auto_sync_enabled'   => true,
				'auto_apply_htaccess' => true,
			)
		)->handle_rocket_settings_update(
			array( 'minify_css' => 0 ),
			array( 'minify_css' => 1 ),
			'wp_rocket_settings'
		);

		$this->assertSame( $original, $this->read() );
		$this->assertSame( array(), WMRB_Test_State::$remote_calls );
	}

	public function test_a_rocket_settings_update_that_changes_the_snippet_can_auto_apply(): void {
		file_put_contents( $this->htaccess, "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n" );
		$new_settings = array( 'cache_reject_uri' => array( '/private(.*)' ) );
		$options = array(
			'auto_sync_enabled'   => true,
			'auto_apply_htaccess' => true,
		);
		WMRB_Test_State::$options['wp_rocket_settings'] = array();
		$manager = $this->manager( $options );
		$manager->mark_applied();
		WMRB_Test_State::$options['wp_rocket_settings'] = $new_settings;

		$manager->handle_rocket_settings_update(
			array(),
			$new_settings,
			'wp_rocket_settings'
		);

		$this->assertStringContainsString( '/private(.*)', $this->read() );
		$this->assertSame( 'in_sync', WMRB_Test_State::$options[ WMRB_Sync_Manager::STATE_OPTION_KEY ]['status'] );
	}
}
