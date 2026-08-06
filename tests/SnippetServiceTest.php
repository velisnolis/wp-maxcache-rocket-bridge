<?php

use PHPUnit\Framework\TestCase;

final class SnippetServiceTest extends TestCase {

	protected function setUp(): void {
		WMRB_Test_State::reset();
	}

	/**
	 * @param array<string,mixed> $overrides
	 */
	private function service( array $overrides = array() ): WMRB_Snippet_Service {
		return new WMRB_Snippet_Service( array_merge( WMRB_Plugin::default_options(), $overrides ) );
	}

	/**
	 * @param array<string,mixed> $settings
	 */
	private function set_rocket_settings( array $settings ): void {
		WMRB_Test_State::$options['wp_rocket_settings'] = $settings;
	}

	// -----------------------------------------------------------------
	// Baseline snippet
	// -----------------------------------------------------------------

	public function test_default_snippet_has_markers_and_ifmodule_guard(): void {
		$snippet = $this->service()->get_snippet();

		$this->assertStringStartsWith( '# BEGIN WMRB suggested MaxCache snippet', $snippet );
		$this->assertStringEndsWith( "# END WMRB suggested MaxCache snippet\n", $snippet );
		$this->assertStringContainsString( '<IfModule maxcache_module>', $snippet );
		$this->assertStringContainsString( '</IfModule>', $snippet );
		$this->assertSame( 1, substr_count( $snippet, '<IfModule' ) );
	}

	public function test_default_cache_path_template(): void {
		$this->assertStringContainsString(
			'MaxCachePath /wp-content/cache/wp-rocket/{HTTP_HOST}{REQUEST_URI}{QS_SUFFIX}/index{MOBILE_SUFFIX}{SSL_SUFFIX}.html',
			$this->service()->get_snippet()
		);
	}

	public function test_default_cookie_exclusions(): void {
		$this->assertStringContainsString(
			'MaxCacheExcludeCookie "(wordpress_logged_in_.+|wp-postpass_|wptouch_switch_toggle|comment_author_|comment_author_email_)"',
			$this->service()->get_snippet()
		);
	}

	public function test_no_logged_hash_directive_by_default(): void {
		$this->assertStringNotContainsString( 'MaxCacheLoggedHash', $this->service()->get_snippet() );
	}

	// -----------------------------------------------------------------
	// Bot / crawler user agents
	// -----------------------------------------------------------------

	public function test_generic_bot_user_agents_excluded_by_default(): void {
		$snippet = $this->service()->get_snippet();

		$this->assertStringContainsString( 'MaxCacheExcludeUA "^(facebookexternalhit|WhatsApp).*|bot|crawl|spider"', $snippet );
	}

	public function test_generic_bot_user_agents_dropped_when_serving_bots(): void {
		$snippet = $this->service( array( 'serve_bot_user_agents' => true ) )->get_snippet();

		$this->assertStringContainsString( 'MaxCacheExcludeUA "^(facebookexternalhit|WhatsApp).*"', $snippet );
	}

	public function test_generic_bot_fragments_from_rocket_are_deduplicated(): void {
		$this->set_rocket_settings( array( 'cache_reject_ua' => array( '(bot|crawl|spider)', 'MyCustomAgent' ) ) );

		$snippet = $this->service( array( 'serve_bot_user_agents' => true ) )->get_snippet();

		$this->assertStringContainsString( 'MyCustomAgent', $snippet );
		$this->assertStringNotContainsString( '(bot|crawl|spider)', $snippet );
	}

	// -----------------------------------------------------------------
	// WebP / gzip variants
	// -----------------------------------------------------------------

	public function test_webp_suffix_auto_detected_from_rocket(): void {
		$this->set_rocket_settings( array( 'cache_webp' => 1 ) );

		$this->assertStringContainsString( 'index{MOBILE_SUFFIX}{SSL_SUFFIX}{WEBP_SUFFIX}.html', $this->service()->get_snippet() );
	}

	public function test_webp_suffix_can_be_forced_by_option(): void {
		$this->assertStringContainsString(
			'{WEBP_SUFFIX}.html',
			$this->service( array( 'serve_webp_variant' => true ) )->get_snippet()
		);
	}

	public function test_gzip_suffix_appended_when_enabled(): void {
		$this->assertStringContainsString(
			'.html{GZIP_SUFFIX}',
			$this->service( array( 'serve_gzip_variant' => true ) )->get_snippet()
		);
	}

	public function test_no_gzip_suffix_by_default(): void {
		$this->assertStringNotContainsString( '{GZIP_SUFFIX}', $this->service()->get_snippet() );
	}

	// -----------------------------------------------------------------
	// Logged-in user cache
	// -----------------------------------------------------------------

	public function test_logged_user_cache_adds_hash_and_user_suffix(): void {
		$this->set_rocket_settings(
			array(
				'cache_logged_user' => 1,
				'secret_cache_key'  => 'abc123def',
			)
		);

		$snippet = $this->service()->get_snippet();

		$this->assertStringContainsString( 'MaxCacheLoggedHash "abc123def"', $snippet );
		$this->assertStringContainsString( '{HTTP_HOST}{USER_SUFFIX}{REQUEST_URI}', $snippet );
	}

	public function test_logged_user_cache_drops_logged_in_cookie_exclusion(): void {
		$this->set_rocket_settings(
			array(
				'cache_logged_user' => 1,
				'secret_cache_key'  => 'abc123def',
			)
		);

		$this->assertStringNotContainsString( 'wordpress_logged_in_', $this->service()->get_snippet() );
	}

	public function test_logged_user_cache_stays_safe_without_secret_key(): void {
		$this->set_rocket_settings( array( 'cache_logged_user' => 1 ) );

		$snippet = $this->service()->get_snippet();

		$this->assertStringNotContainsString( 'MaxCacheLoggedHash', $snippet );
		$this->assertStringNotContainsString( '{USER_SUFFIX}', $snippet );
		$this->assertStringContainsString( 'wordpress_logged_in_.+', $snippet );
	}

	// -----------------------------------------------------------------
	// WP Rocket exclusions
	// -----------------------------------------------------------------

	public function test_rocket_uri_exclusions_appended_to_baseline(): void {
		$this->set_rocket_settings( array( 'cache_reject_uri' => array( '/checkout(.*)', '/my-account(.*)' ) ) );

		$snippet = $this->service()->get_snippet();

		$this->assertStringContainsString( WMRB_Snippet_Service::BASE_URI_EXCLUSION . '|/checkout(.*)|/my-account(.*)', $snippet );
	}

	public function test_quotes_are_stripped_from_rocket_patterns(): void {
		$this->set_rocket_settings( array( 'cache_reject_uri' => array( '/foo"bar' ) ) );

		$snippet = $this->service()->get_snippet();

		$this->assertStringContainsString( '/foobar', $snippet );

		// Exactly three quoted directives: ExcludeURI, ExcludeUA, ExcludeCookie.
		// A leaked quote would terminate an argument early and break the config.
		$this->assertSame( 6, substr_count( $snippet, '"' ) );
	}

	public function test_sync_summary_counts_rocket_contributions(): void {
		$this->set_rocket_settings(
			array(
				'cache_reject_uri'     => array( '/checkout(.*)' ),
				'cache_reject_ua'      => array( 'MyAgent', 'OtherAgent' ),
				'cache_reject_cookies' => array( 'my_cookie' ),
			)
		);

		$summary = $this->service()->get_sync_summary();

		$this->assertSame( 1, $summary['uri_synced'] );
		$this->assertSame( 2, $summary['ua_synced'] );
		$this->assertSame( 1, $summary['cookie_synced'] );
		$this->assertSame( 2, $summary['uri_total'] );
	}

	// -----------------------------------------------------------------
	// Rejecting unusable patterns (P1 fix 1)
	// -----------------------------------------------------------------

	/**
	 * @return array<string,array<int,string>>
	 */
	public static function unusable_pattern_provider(): array {
		return array(
			'unclosed character class' => array( '/checkout[' ),
			'unclosed group'           => array( '/checkout(.*' ),
			'stray closing group'      => array( '/checkout)' ),
			'dangling quantifier'      => array( '*/checkout' ),
			'lone alternation pipe'    => array( '|/checkout' ),
			'matches everything'       => array( '.*' ),
			'matches empty string'     => array( '(foo)?' ),
		);
	}

	#[PHPUnit\Framework\Attributes\DataProvider( 'unusable_pattern_provider' )]
	public function test_unusable_uri_pattern_is_dropped( string $pattern ): void {
		$this->set_rocket_settings( array( 'cache_reject_uri' => array( $pattern, '/valid-one(.*)' ) ) );

		$service = $this->service();

		$this->assertSame(
			array(
				array(
					'setting' => 'cache_reject_uri',
					'pattern' => $pattern,
				),
			),
			$service->get_rejected_patterns()
		);

		// Asserted on the exact directive value: short patterns such as ".*"
		// also occur as substrings of legitimate ones.
		$this->assertSame(
			1,
			preg_match( '/MaxCacheExcludeURI "(.*)"/', $service->get_snippet(), $matches )
		);
		$this->assertSame( WMRB_Snippet_Service::BASE_URI_EXCLUSION . '|/valid-one(.*)', $matches[1] );
	}

	public function test_unusable_cookie_pattern_is_dropped(): void {
		$this->set_rocket_settings( array( 'cache_reject_cookies' => array( 'my_cookie[', 'good_cookie' ) ) );

		$snippet = $this->service()->get_snippet();

		$this->assertStringNotContainsString( 'my_cookie[', $snippet );
		$this->assertStringContainsString( 'good_cookie', $snippet );
	}

	public function test_unusable_ua_pattern_is_dropped(): void {
		$this->set_rocket_settings( array( 'cache_reject_ua' => array( 'Bad(Agent', 'GoodAgent' ) ) );

		$snippet = $this->service()->get_snippet();

		$this->assertStringNotContainsString( 'Bad(Agent', $snippet );
		$this->assertStringContainsString( 'GoodAgent', $snippet );
	}

	public function test_legitimate_complex_patterns_survive(): void {
		$patterns = array(
			'/(?:.+/)?feed(?:/(?:.+/?)?)?$',
			'/checkout(.*)',
			'/wp-json(/.*|$)',
			'^/es/tienda/.*$',
			'/product/[a-z0-9\-]+/?',
		);

		$this->set_rocket_settings( array( 'cache_reject_uri' => $patterns ) );

		$snippet = $this->service()->get_snippet();

		foreach ( $patterns as $pattern ) {
			$this->assertStringContainsString( $pattern, $snippet, 'Legitimate pattern was dropped: ' . $pattern );
		}
	}

	public function test_baseline_exclusions_are_never_rejected(): void {
		$snippet = $this->service()->get_snippet();

		$this->assertStringContainsString( 'facebookexternalhit', $snippet );
		$this->assertStringContainsString( 'wp-postpass_', $snippet );
		$this->assertStringContainsString( 'bot|crawl|spider', $snippet );
	}

	public function test_rejected_patterns_are_reported_for_the_admin_screen(): void {
		$this->set_rocket_settings(
			array(
				'cache_reject_uri'     => array( '/checkout[', '/fine(.*)' ),
				'cache_reject_cookies' => array( 'bad_cookie)' ),
			)
		);

		$rejected = $this->service()->get_rejected_patterns();

		$this->assertCount( 2, $rejected );
		$this->assertSame( 'cache_reject_uri', $rejected[0]['setting'] );
		$this->assertSame( '/checkout[', $rejected[0]['pattern'] );
		$this->assertSame( 'cache_reject_cookies', $rejected[1]['setting'] );
	}

	public function test_nothing_is_reported_when_all_patterns_are_usable(): void {
		$this->set_rocket_settings( array( 'cache_reject_uri' => array( '/checkout(.*)' ) ) );

		$this->assertSame( array(), $this->service()->get_rejected_patterns() );
	}

	/**
	 * The whole point of fix 1: whatever WP Rocket contains, every regex the
	 * bridge emits must compile, or Apache rejects the directive.
	 */
	public function test_emitted_directives_always_compile(): void {
		$this->set_rocket_settings(
			array(
				'cache_reject_uri'     => array( '/a[', '/b(', '/c)', '*d', '/legit(.*)' ),
				'cache_reject_ua'      => array( 'Agent(', 'GoodAgent' ),
				'cache_reject_cookies' => array( 'cookie[', 'good_cookie' ),
			)
		);

		$snippet = $this->service()->get_snippet();

		foreach ( array( 'MaxCacheExcludeURI', 'MaxCacheExcludeUA', 'MaxCacheExcludeCookie' ) as $directive ) {
			$this->assertSame( 1, preg_match( '/' . $directive . ' "(.*)"/', $snippet, $matches ), $directive . ' missing' );
			$this->assertNotFalse(
				@preg_match( '~' . $matches[1] . '~', '/some/request/uri' ),
				$directive . ' emitted a pattern that does not compile: ' . $matches[1]
			);
		}
	}

	// -----------------------------------------------------------------
	// Cache path template
	// -----------------------------------------------------------------

	public function test_custom_cache_path_template_overrides_default(): void {
		$snippet = $this->service( array( 'custom_cache_path_template' => '/custom/{HTTP_HOST}/index.html' ) )->get_snippet();

		$this->assertStringContainsString( 'MaxCachePath /custom/{HTTP_HOST}/index.html', $snippet );
	}

	public function test_custom_cache_path_template_is_sanitized(): void {
		$snippet = $this->service( array( 'custom_cache_path_template' => '/custom/ "; rm -rf /{HTTP_HOST}' ) )->get_snippet();

		$this->assertStringContainsString( 'MaxCachePath /custom/rm-rf/{HTTP_HOST}', $snippet );
	}

	// -----------------------------------------------------------------
	// Fingerprint
	// -----------------------------------------------------------------

	public function test_fingerprint_is_stable_for_identical_input(): void {
		$this->assertSame( $this->service()->get_sync_fingerprint(), $this->service()->get_sync_fingerprint() );
	}

	public function test_fingerprint_changes_when_options_change(): void {
		$this->assertNotSame(
			$this->service()->get_sync_fingerprint(),
			$this->service( array( 'serve_gzip_variant' => true ) )->get_sync_fingerprint()
		);
	}

	public function test_fingerprint_changes_when_rocket_settings_change(): void {
		$before = $this->service()->get_sync_fingerprint();

		$this->set_rocket_settings( array( 'cache_reject_uri' => array( '/checkout(.*)' ) ) );

		$this->assertNotSame( $before, $this->service()->get_sync_fingerprint() );
	}
}
