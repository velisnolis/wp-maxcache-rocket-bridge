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
	 * @param array<string,mixed> $options
	 */
	public function __construct( array $options ) {
		$this->options = $options;
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
		$uri_rocket = array_values( array_unique( array_filter( array_map( array( $this, 'sanitize_uri_pattern' ), $this->get_wp_rocket_array_setting( 'cache_reject_uri' ) ) ) ) );
		$ua_rocket = array_values( array_unique( array_filter( array_map( array( $this, 'sanitize_pipe_fragment' ), $this->get_wp_rocket_array_setting( 'cache_reject_ua' ) ) ) ) );
		$cookie_rocket = array_values( array_unique( array_filter( array_map( array( $this, 'sanitize_pipe_fragment' ), $this->get_wp_rocket_array_setting( 'cache_reject_cookies' ) ) ) ) );
		$base_cookies  = $this->get_base_cookie_exclusions();

		return array(
			'uri_total'     => 1 + count( $uri_rocket ),
			'ua_total'      => count( array_unique( array_merge( self::$base_ua_exclusions, $ua_rocket ) ) ),
			'cookie_total'  => count( array_unique( array_merge( $base_cookies, $cookie_rocket ) ) ),
			'uri_synced'    => count( $uri_rocket ),
			'ua_synced'     => count( $ua_rocket ),
			'cookie_synced' => count( $cookie_rocket ),
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
		$rocket_exclusions = $this->get_wp_rocket_array_setting( 'cache_reject_uri' );
		$rocket_exclusions = array_map( array( $this, 'sanitize_uri_pattern' ), $rocket_exclusions );

		$values = array_values( array_unique( array_filter( $rocket_exclusions ) ) );

		if ( empty( $values ) ) {
			return self::BASE_URI_EXCLUSION;
		}

		return self::BASE_URI_EXCLUSION . '|' . implode( '|', $values );
	}

	private function build_ua_exclusions() {
		$rocket_exclusions = $this->get_wp_rocket_array_setting( 'cache_reject_ua' );
		$rocket_exclusions = array_map( array( $this, 'sanitize_pipe_fragment' ), $rocket_exclusions );

		$values = array_values( array_unique( array_filter( array_merge( self::$base_ua_exclusions, $rocket_exclusions ) ) ) );
		return implode( '|', $values );
	}

	private function build_cookie_exclusions() {
		$rocket_exclusions = $this->get_wp_rocket_array_setting( 'cache_reject_cookies' );
		$rocket_exclusions = array_map( array( $this, 'sanitize_pipe_fragment' ), $rocket_exclusions );

		$values = array_values( array_unique( array_filter( array_merge( $this->get_base_cookie_exclusions(), $rocket_exclusions ) ) ) );
		return '(' . implode( '|', $values ) . ')';
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
