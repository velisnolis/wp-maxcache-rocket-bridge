<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMRB_Snippet_Service {
	const BASE_URI_EXCLUSION = '/(?:.+/)?feed(?:/(?:.+/?)?)?$|/(?:.+/)?embed/|/(?:wp-content|wp-includes)/|/(index.php/)?(.*)wp-json(/.*|$)';

	/**
	 * @var array<int,string>
	 */
	private static $base_qs_allowed_params = array(
		'lang',
		's',
		'permalink_name',
		'lp-variation-id',
	);

	/**
	 * @var array<int,string>
	 */
	private static $base_qs_ignored_params = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_expid',
		'utm_term',
		'utm_content',
		'utm_id',
		'utm_source_platform',
		'utm_creative_format',
		'utm_marketing_tactic',
		'mtm_source',
		'mtm_medium',
		'mtm_campaign',
		'mtm_keyword',
		'mtm_cid',
		'mtm_content',
		'pk_source',
		'pk_medium',
		'pk_campaign',
		'pk_keyword',
		'pk_cid',
		'pk_content',
		'fb_action_ids',
		'fb_action_types',
		'fb_source',
		'fbclid',
		'campaignid',
		'adgroupid',
		'adid',
		'gclid',
		'age-verified',
		'ao_noptimize',
		'usqp',
		'cn-reloaded',
		'_ga',
		'sscid',
		'gclsrc',
		'_gl',
		'mc_cid',
		'mc_eid',
		'_bta_tid',
		'_bta_c',
		'trk_contact',
		'trk_msg',
		'trk_module',
		'trk_sid',
		'gdfms',
		'gdftrk',
		'gdffi',
		'_ke',
		'_kx',
		'redirect_log_mongo_id',
		'redirect_mongo_id',
		'sb_referer_host',
		'mkwid',
		'pcrid',
		'ef_id',
		's_kwcid',
		'msclkid',
		'dm_i',
		'epik',
		'pp',
		'gbraid',
		'wbraid',
		'ssp_iabi',
		'ssp_iaba',
		'gad',
		'vgo_ee',
		'gad_source',
		'gad_campaignid',
		'onlywprocket',
		'srsltid',
		'gadid',
		'fbadid',
	);

	/**
	 * @var array<int,string>
	 */
	private static $base_ua_exclusions = array(
		'^(facebookexternalhit|WhatsApp).*',
	);

	/**
	 * @var array<int,string>
	 */
	private static $bot_ua_exclusions = array(
		'bot',
		'crawl',
		'spider',
	);

	/**
	 * @var array<int,string>
	 */
	private static $base_cookie_exclusions = array(
		'wordpress_logged_in_.+',
		'wp-postpass_',
		'wptouch_switch_toggle',
		'comment_author_',
		'comment_author_email_',
	);

	/**
	 * @var array<string,mixed>
	 */
	private $options;

	/**
	 * Explicit WP Rocket settings used for before/after comparisons. Null means
	 * read the live option, preserving the normal runtime behaviour.
	 *
	 * @var array<string,mixed>|null
	 */
	private $rocket_settings_override;

	/**
	 * @param array<string,mixed> $options
	 * @param array<string,mixed>|null $rocket_settings_override
	 */
	public function __construct( array $options, $rocket_settings_override = null ) {
		$this->options                  = $options;
		$this->rocket_settings_override = is_array( $rocket_settings_override ) ? $rocket_settings_override : null;
	}

	public function get_snippet() {
		$safe_path = $this->get_effective_cache_path_template();

		$uri_exclusions    = $this->build_uri_exclusions();
		$ua_exclusions     = $this->build_ua_exclusions();
		$cookie_exclusions = $this->build_cookie_exclusions();
		$logged_hash       = $this->get_logged_user_cache_hash();

		$lines = array(
			'# BEGIN WMRB suggested MaxCache snippet',
			'<IfModule maxcache_module>',
			'    MaxCache On',
			'    MaxCacheOptions -SkipCacheOnMobile -TabletAsMobile',
			'',
			'    # Query string handling',
			'    MaxCacheQSAllowedParams ' . implode( ' ', self::$base_qs_allowed_params ),
			'    MaxCacheQSIgnoredParams ' . implode( ' ', self::$base_qs_ignored_params ),
			'',
			'    # CloudLinux baseline + WP Rocket exclusions',
			'    MaxCacheExcludeURI "' . $uri_exclusions . '"',
			'    MaxCacheExcludeUA "' . $ua_exclusions . '"',
			'    MaxCacheExcludeCookie "' . $cookie_exclusions . '"',
			'',
		);

		if ( '' !== $logged_hash ) {
			$lines[] = '    MaxCacheLoggedHash "' . $logged_hash . '"';
			$lines[] = '';
		}

		$lines = array_merge(
			$lines,
			array(
			'    MaxCachePath ' . $safe_path,
			'</IfModule>',
			'# END WMRB suggested MaxCache snippet',
			)
		);

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @return array<string,int>
	 */
	public function get_sync_summary() {
		$uri    = $this->resolve_uri_fragments();
		$ua     = $this->resolve_ua_fragments();
		$cookie = $this->resolve_cookie_fragments();

		return array(
			'uri_total'     => 1 + count( $uri['accepted'] ),
			'ua_total'      => count( $ua['accepted'] ),
			'cookie_total'  => count( $cookie['accepted'] ),
			'uri_synced'    => count( array_intersect( $uri['accepted'], $uri['from_rocket'] ) ),
			'ua_synced'     => count( array_intersect( $ua['accepted'], $ua['from_rocket'] ) ),
			'cookie_synced' => count( array_intersect( $cookie['accepted'], $cookie['from_rocket'] ) ),
		);
	}

	public function get_sync_fingerprint() {
		return md5( $this->get_snippet() );
	}

	public function get_effective_cache_path_template() {
		$cache_path_template = isset( $this->options['custom_cache_path_template'] ) ? (string) $this->options['custom_cache_path_template'] : '';
		$cache_path_template = trim( $cache_path_template );

		if ( '' === $cache_path_template ) {
			$cache_path_template = $this->get_default_cache_path_template();
		}

		return $this->sanitize_cache_path_template( $cache_path_template );
	}

	private function sanitize_cache_path_template( $template ) {
		$allowed = preg_replace( '/[^A-Za-z0-9\/\-\._\{\}\$]/', '', $template );
		return (string) $allowed;
	}

	private function get_default_cache_path_template() {
		$path = '/wp-content/cache/wp-rocket/{HTTP_HOST}{REQUEST_URI}{QS_SUFFIX}/index{MOBILE_SUFFIX}{SSL_SUFFIX}.html';
		if ( '' !== $this->get_logged_user_cache_hash() ) {
			$path = '/wp-content/cache/wp-rocket/{HTTP_HOST}{USER_SUFFIX}{REQUEST_URI}{QS_SUFFIX}/index{MOBILE_SUFFIX}{SSL_SUFFIX}.html';
		}
		if ( $this->should_use_webp_variant() ) {
			$path = str_replace( '.html', '{WEBP_SUFFIX}.html', $path );
		}
		if ( ! empty( $this->options['serve_gzip_variant'] ) ) {
			$path .= '{GZIP_SUFFIX}';
		}

		return $path;
	}

	private function should_use_webp_variant() {
		if ( ! empty( $this->options['serve_webp_variant'] ) ) {
			return true;
		}

		$settings = get_option( 'wp_rocket_settings', array() );
		return is_array( $settings ) && ! empty( $settings['cache_webp'] );
	}

	private function build_uri_exclusions() {
		$accepted = $this->resolve_uri_fragments()['accepted'];

		if ( empty( $accepted ) ) {
			return self::BASE_URI_EXCLUSION;
		}

		return self::BASE_URI_EXCLUSION . '|' . implode( '|', $accepted );
	}

	private function build_ua_exclusions() {
		return implode( '|', $this->resolve_ua_fragments()['accepted'] );
	}

	private function build_cookie_exclusions() {
		return '(' . implode( '|', $this->resolve_cookie_fragments()['accepted'] ) . ')';
	}

	/**
	 * @return array{accepted: array<int,string>, rejected: array<int,string>, from_rocket: array<int,string>}
	 */
	private function resolve_uri_fragments() {
		$from_rocket = $this->unique_sanitized( 'cache_reject_uri', 'sanitize_uri_pattern' );

		return $this->partition_fragments( $from_rocket, $from_rocket, self::BASE_URI_EXCLUSION, '', '', $this->representative_uri_subjects() );
	}

	/**
	 * @return array{accepted: array<int,string>, rejected: array<int,string>, from_rocket: array<int,string>}
	 */
	private function resolve_ua_fragments() {
		$from_rocket = $this->get_effective_rocket_ua_exclusions();
		$candidates  = array_values( array_unique( array_merge( $this->get_base_ua_exclusions(), $from_rocket ) ) );

		return $this->partition_fragments( $candidates, $from_rocket, '', '', '', $this->representative_ua_subjects() );
	}

	/**
	 * @return array{accepted: array<int,string>, rejected: array<int,string>, from_rocket: array<int,string>}
	 */
	private function resolve_cookie_fragments() {
		$from_rocket = $this->unique_sanitized( 'cache_reject_cookies', 'sanitize_pipe_fragment' );
		$candidates  = array_values( array_unique( array_merge( $this->get_base_cookie_exclusions(), $from_rocket ) ) );

		return $this->partition_fragments( $candidates, $from_rocket, '', '(', ')', $this->representative_cookie_subjects() );
	}

	/**
	 * @return array<int,string>
	 */
	private function unique_sanitized( $setting, $sanitizer ) {
		$values = array_map( array( $this, $sanitizer ), $this->get_wp_rocket_array_setting( $setting ) );

		return array_values( array_unique( array_filter( $values, static function ( $value ) {
			return '' !== $value;
		} ) ) );
	}

	/**
	 * Accepts fragments one at a time, re-checking the whole alternation after
	 * each addition.
	 *
	 * Validating fragments in isolation is not enough: two patterns can each be
	 * valid yet refuse to compile together — duplicate named groups being the
	 * obvious case — and Apache discards the entire directive when that happens,
	 * silently dropping every exclusion with it.
	 *
	 * @param array<int,string> $candidates  Fragments to consider, in order.
	 * @param array<int,string> $from_rocket Which of them came from WP Rocket.
	 * @param string            $prefix      Trusted leading alternative, if any.
	 * @param string            $wrap_open   Wrapper the directive applies, if any.
	 * @param string            $wrap_close
	 * @param array<int,string> $subjects    Representative inputs for this directive.
	 * @return array{accepted: array<int,string>, rejected: array<int,string>, from_rocket: array<int,string>}
	 */
	private function partition_fragments( array $candidates, array $from_rocket, $prefix = '', $wrap_open = '', $wrap_close = '', array $subjects = array() ) {
		$accepted = array();
		$rejected = array();

		foreach ( $candidates as $fragment ) {
			if ( ! $this->is_usable_regex_fragment( $fragment, $subjects ) ) {
				$rejected[] = $fragment;
				continue;
			}

			$parts = $accepted;
			$parts[] = $fragment;

			$joined = implode( '|', $parts );
			if ( '' !== $prefix ) {
				$joined = $prefix . '|' . $joined;
			}

			if ( ! $this->compiles( $wrap_open . $joined . $wrap_close ) ) {
				$rejected[] = $fragment;
				continue;
			}

			$accepted[] = $fragment;
		}

		return array(
			'accepted'    => $accepted,
			'rejected'    => $rejected,
			'from_rocket' => $from_rocket,
		);
	}

	/**
	 * WP Rocket exclusions are typed by hand and land verbatim inside a PCRE
	 * alternation in the generated directives. A fragment that does not compile
	 * would make Apache reject the whole directive, and a fragment that behaves
	 * as a universal match would exclude every request and silently kill the cache.
	 * Either way the fragment is dropped rather than shipped.
	 *
	 * @param string            $fragment
	 * @param array<int,string> $subjects Representative inputs for the target directive.
	 * @return bool
	 */
	public function is_usable_regex_fragment( $fragment, array $subjects = array() ) {
		$fragment = (string) $fragment;
		if ( '' === $fragment ) {
			return false;
		}

		// Wrapped in a group because the fragment is alternated with "|" in the
		// final directive: "foo)" compiles alone but breaks the combined pattern.
		$wrapped = '(?:' . $fragment . ')';
		if ( empty( $subjects ) ) {
			$subjects = array_merge( $this->representative_uri_subjects(), $this->representative_ua_subjects(), $this->representative_cookie_subjects() );
		}

		return $this->compiles( $wrapped ) && ! $this->matches_every_representative_subject( $wrapped, $subjects );
	}

	/**
	 * @param string $pattern
	 * @return bool
	 */
	private function compiles( $pattern ) {
		$delimiter = $this->pick_delimiter( $pattern );
		if ( '' === $delimiter ) {
			return false;
		}

		return false !== @preg_match( $delimiter . $pattern . $delimiter, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Detects fragments that behave as universal matches across representative
	 * non-empty subjects for one directive. Empty subjects are deliberately not
	 * part of the universal set: anchored patterns such as "^$" legitimately
	 * target absence, while ".+" still excludes every real non-empty value.
	 *
	 * @param string            $pattern
	 * @param array<int,string> $subjects
	 * @return bool
	 */
	private function matches_every_representative_subject( $pattern, array $subjects ) {
		$delimiter = $this->pick_delimiter( $pattern );
		if ( '' === $delimiter ) {
			return false;
		}

		$non_empty_subjects = array_values( array_filter( $subjects, static function ( $subject ) {
			return '' !== (string) $subject;
		} ) );

		if ( empty( $non_empty_subjects ) ) {
			return false;
		}

		foreach ( $non_empty_subjects as $subject ) {
			if ( 1 !== @preg_match( $delimiter . $pattern . $delimiter, $subject ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return false;
			}
		}

		return true;
	}

	/**
	 * Request URIs are non-empty absolute paths in the Apache directive.
	 *
	 * @return array<int,string>
	 */
	private function representative_uri_subjects() {
		return array( '/', '/wmrb-probe', '/index.php', '/shop/product', '/ca/noticies/article' );
	}

	/**
	 * @return array<int,string>
	 */
	private function representative_ua_subjects() {
		return array( '', 'Mozilla/5.0', 'Googlebot/2.1', 'curl/8.0' );
	}

	/**
	 * @return array<int,string>
	 */
	private function representative_cookie_subjects() {
		return array( '', 'wordpress_logged_in_hash=value', 'wmrb_cookie=value', 'foo=bar; baz=qux' );
	}

	private function pick_delimiter( $pattern ) {
		foreach ( array( '#', '~', '%', '!', '@', ';', ',', '=' ) as $candidate ) {
			if ( false === strpos( $pattern, $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Exclusions coming from WP Rocket that had to be dropped, for display in
	 * the admin screen. Uses exactly the pipeline that builds the snippet, so
	 * the two can never disagree.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function get_rejected_patterns() {
		$sources = array(
			'cache_reject_uri'     => $this->resolve_uri_fragments(),
			'cache_reject_ua'      => $this->resolve_ua_fragments(),
			'cache_reject_cookies' => $this->resolve_cookie_fragments(),
		);

		$rejected = array();

		foreach ( $sources as $setting => $resolved ) {
			foreach ( $resolved['rejected'] as $pattern ) {
				// Baseline fragments never fail; only report what the user can fix.
				if ( ! in_array( $pattern, $resolved['from_rocket'], true ) ) {
					continue;
				}

				$rejected[] = array(
					'setting' => $setting,
					'pattern' => $pattern,
				);
			}
		}

		return $rejected;
	}

	/**
	 * @return array<int,string>
	 */
	private function get_base_cookie_exclusions() {
		$values = self::$base_cookie_exclusions;
		if ( '' !== $this->get_logged_user_cache_hash() ) {
			$values = array_values(
				array_filter(
					$values,
					static function ( $value ) {
						return 'wordpress_logged_in_.+' !== $value;
					}
				)
			);
		}

		return $values;
	}

	/**
	 * @return array<int,string>
	 */
	private function get_base_ua_exclusions() {
		$values = self::$base_ua_exclusions;
		if ( empty( $this->options['serve_bot_user_agents'] ) ) {
			$values = array_merge( $values, self::$bot_ua_exclusions );
		}

		return $values;
	}

	/**
	 * @return array<int,string>
	 */
	private function get_effective_rocket_ua_exclusions() {
		$rocket_exclusions = $this->get_wp_rocket_array_setting( 'cache_reject_ua' );
		$rocket_exclusions = array_values( array_unique( array_filter( array_map( array( $this, 'sanitize_pipe_fragment' ), $rocket_exclusions ) ) ) );

		return array_values( array_filter( $rocket_exclusions, array( $this, 'is_non_generic_bot_ua_exclusion' ) ) );
	}

	private function is_non_generic_bot_ua_exclusion( $value ) {
		return ! $this->is_generic_bot_ua_exclusion( $value );
	}

	private function is_generic_bot_ua_exclusion( $value ) {
		$value = trim( (string) $value );
		$value = trim( $value, '()' );
		if ( '' === $value ) {
			return false;
		}

		$parts = array_filter( array_map( 'trim', explode( '|', $value ) ) );
		if ( empty( $parts ) ) {
			return false;
		}

		foreach ( $parts as $part ) {
			if ( ! in_array( strtolower( $part ), self::$bot_ua_exclusions, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array<int,string>
	 */
	private function get_wp_rocket_array_setting( $key ) {
		$settings = $this->get_wp_rocket_settings();
		if ( ! is_array( $settings ) || ! isset( $settings[ $key ] ) || ! is_array( $settings[ $key ] ) ) {
			return array();
		}

		$values = array();
		foreach ( $settings[ $key ] as $value ) {
			if ( is_scalar( $value ) ) {
				$values[] = trim( (string) $value );
			}
		}

		return $values;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function get_wp_rocket_settings() {
		if ( null !== $this->rocket_settings_override ) {
			return $this->rocket_settings_override;
		}

		$settings = get_option( 'wp_rocket_settings', array() );
		return is_array( $settings ) ? $settings : array();
	}

	private function get_wp_rocket_scalar_setting( $key ) {
		$settings = $this->get_wp_rocket_settings();
		if ( ! array_key_exists( $key, $settings ) || ! is_scalar( $settings[ $key ] ) ) {
			return null;
		}

		return $settings[ $key ];
	}

	private function is_logged_user_cache_enabled() {
		return ! empty( $this->get_wp_rocket_scalar_setting( 'cache_logged_user' ) );
	}

	private function get_logged_user_cache_hash() {
		if ( ! $this->is_logged_user_cache_enabled() ) {
			return '';
		}

		$value = $this->get_wp_rocket_scalar_setting( 'secret_cache_key' );
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_key( (string) $value );
	}

	private function sanitize_uri_pattern( $value ) {
		// Keep regex-ish URI chars but drop quotes/control characters.
		$value = preg_replace( '/["\'\x00-\x1F\x7F]/', '', (string) $value );
		return (string) $value;
	}

	private function sanitize_pipe_fragment( $value ) {
		$value = preg_replace( '/["\'\x00-\x1F\x7F]/', '', (string) $value );
		return (string) $value;
	}
}
