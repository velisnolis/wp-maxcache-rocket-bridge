<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Executable specification for snippet fidelity work that is planned but not
 * implemented. These tests are expected to FAIL until that work lands.
 *
 * Every expectation here was read off the reference implementation:
 * AccelerateWP (clsop) `inc/functions/htaccess.php`, plus the WP Rocket
 * accessors in `inc/functions/options.php`, on a live CloudLinux host.
 *
 * Run with: vendor/bin/phpunit --group batch2
 */
#[Group( 'batch2' )]
final class Batch2SnippetFidelityTest extends TestCase {

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

	private function options_line( string $snippet ): string {
		return preg_match( '/^\s*MaxCacheOptions (.*)$/m', $snippet, $m ) ? trim( $m[1] ) : '(cap directiva MaxCacheOptions)';
	}

	private function path_line( string $snippet ): string {
		return preg_match( '/^\s*MaxCachePath (.*)$/m', $snippet, $m ) ? trim( $m[1] ) : '(cap directiva MaxCachePath)';
	}

	// =================================================================
	// Finding #1 — mobile options are hardcoded
	//
	// clsop htaccess.php:535-545
	//     if ( $cache_mobile ) {
	//         $options[] = '-SkipCacheOnMobile';
	//         $options[] = $cache_tablet_as_mobile ? '+TabletAsMobile' : '-TabletAsMobile';
	//     } else {
	//         $options[] = '+SkipCacheOnMobile';
	//     }
	//
	// The bridge always emits "-SkipCacheOnMobile -TabletAsMobile", which tells
	// MaxCache to serve mobile visitors from cache even on sites where WP Rocket
	// generates no mobile variants at all.
	// =================================================================

	public function test_mobile_caching_off_skips_cache_on_mobile(): void {
		$this->set_rocket_settings( array( 'cache_mobile' => 0 ) );

		$this->assertSame( '+SkipCacheOnMobile', $this->options_line( $this->service()->get_snippet() ) );
	}

	public function test_mobile_caching_on_serves_mobile_from_cache(): void {
		$this->set_rocket_settings( array( 'cache_mobile' => 1 ) );

		$this->assertSame( '-SkipCacheOnMobile -TabletAsMobile', $this->options_line( $this->service()->get_snippet() ) );
	}

	public function test_tablet_as_mobile_follows_the_rocket_filter(): void {
		$this->set_rocket_settings( array( 'cache_mobile' => 1 ) );
		add_filter( 'rocket_cache_mobile_files_tablet', static fn() => 'mobile' );

		$this->assertSame( '-SkipCacheOnMobile +TabletAsMobile', $this->options_line( $this->service()->get_snippet() ) );
	}

	public function test_mobile_suffix_requires_separate_mobile_files(): void {
		// clsop htaccess.php:510 — if ( $cache_mobile && $cache_mobile_files )
		$this->set_rocket_settings(
			array(
				'cache_mobile'            => 1,
				'do_caching_mobile_files' => 1,
			)
		);

		$this->assertStringContainsString( '{MOBILE_SUFFIX}', $this->path_line( $this->service()->get_snippet() ) );
	}

	public function test_no_mobile_suffix_without_separate_mobile_files(): void {
		$this->set_rocket_settings(
			array(
				'cache_mobile'            => 1,
				'do_caching_mobile_files' => 0,
			)
		);

		$this->assertStringNotContainsString( '{MOBILE_SUFFIX}', $this->path_line( $this->service()->get_snippet() ) );
	}

	public function test_no_mobile_suffix_when_mobile_caching_is_off(): void {
		$this->set_rocket_settings( array( 'cache_mobile' => 0 ) );

		$this->assertStringNotContainsString( '{MOBILE_SUFFIX}', $this->path_line( $this->service()->get_snippet() ) );
	}

	// =================================================================
	// Finding #2 — dynamic cookies are not handled at all
	//
	// clsop htaccess.php:586-590 emits MaxCacheDynamicCookies, and schema[10]
	// adds {DYNAMIC_COOKIE_SUFFIX}. Without both, WP Rocket writes cache files
	// under a name the generated MaxCachePath never looks for: the cache goes
	// quiet, and nothing is written to any log.
	//
	// Note these come from the `rocket_cache_dynamic_cookies` FILTER, not from
	// an option — the bridge cannot see them while it reads wp_rocket_settings
	// directly. Fixing this requires finding #4 first.
	// =================================================================

	public function test_dynamic_cookies_are_declared(): void {
		add_filter( 'rocket_cache_dynamic_cookies', static fn() => array( 'currency', 'lang_pref' ) );

		$this->assertMatchesRegularExpression(
			'/^\s*MaxCacheDynamicCookies currency lang_pref$/m',
			$this->service()->get_snippet()
		);
	}

	public function test_dynamic_cookie_suffix_is_added_to_the_path(): void {
		add_filter( 'rocket_cache_dynamic_cookies', static fn() => array( 'currency' ) );

		$this->assertStringContainsString( '{DYNAMIC_COOKIE_SUFFIX}.html', $this->path_line( $this->service()->get_snippet() ) );
	}

	public function test_no_dynamic_cookie_traces_when_there_are_none(): void {
		$snippet = $this->service()->get_snippet();

		$this->assertStringNotContainsString( 'MaxCacheDynamicCookies', $snippet );
		$this->assertStringNotContainsString( '{DYNAMIC_COOKIE_SUFFIX}', $snippet );
	}

	// =================================================================
	// Finding #3 — mandatory cookies are not handled
	//
	// clsop htaccess.php:611-614. WP Rocket's accessor returns the cookies
	// already joined with "|".
	// =================================================================

	public function test_mandatory_cookies_are_declared(): void {
		add_filter( 'rocket_cache_mandatory_cookies', static fn() => array( 'wp_woocommerce_session', 'wordpress_test' ) );

		$this->assertMatchesRegularExpression(
			'/^\s*MaxCacheMandatoryCookies wp_woocommerce_session\|wordpress_test$/m',
			$this->service()->get_snippet()
		);
	}

	// =================================================================
	// Finding #4 — the bridge reimplements WP Rocket's accessors
	//
	// Reading wp_rocket_settings directly misses everything other plugins add
	// through WP Rocket's filters. WooCommerce, WPML and similar integrations
	// register exclusions that way, so those URLs end up cached and served as
	// static HTML.
	// =================================================================

	public function test_uri_exclusions_added_by_a_filter_are_honoured(): void {
		$this->set_rocket_settings( array( 'cache_reject_uri' => array( '/from-options(.*)' ) ) );
		add_filter(
			'rocket_cache_reject_uri',
			static fn( $uris ) => array_merge( (array) $uris, array( '/from-a-plugin(.*)' ) )
		);

		$snippet = $this->service()->get_snippet();

		$this->assertStringContainsString( '/from-options(.*)', $snippet );
		$this->assertStringContainsString( '/from-a-plugin(.*)', $snippet );
	}

	public function test_ua_exclusions_added_by_a_filter_are_honoured(): void {
		add_filter(
			'rocket_cache_reject_ua',
			static fn( $ua ) => array_merge( (array) $ua, array( 'SomeIntegrationAgent' ) )
		);

		$this->assertStringContainsString( 'SomeIntegrationAgent', $this->service()->get_snippet() );
	}

	public function test_cookie_exclusions_added_by_a_filter_are_honoured(): void {
		add_filter(
			'rocket_cache_reject_cookies',
			static fn( $cookies ) => array_merge( (array) $cookies, array( 'plugin_session_' ) )
		);

		$this->assertStringContainsString( 'plugin_session_', $this->service()->get_snippet() );
	}

	public function test_spaces_in_user_agents_are_escaped(): void {
		// wp-rocket options.php, get_rocket_cache_reject_ua():
		//     str_replace( [ ' ', '\\\\ ' ], '\\ ', $ua )
		// An unescaped space would end the quoted argument early.
		$this->set_rocket_settings( array( 'cache_reject_ua' => array( 'Some Bot Agent' ) ) );

		$this->assertStringContainsString( 'Some\\ Bot\\ Agent', $this->service()->get_snippet() );
	}
}
