<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMRB_Snippet_Service {
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
		$cache_path_template = isset( $this->options['custom_cache_path_template'] ) ? (string) $this->options['custom_cache_path_template'] : '';
		$cache_path_template = trim( $cache_path_template );

		if ( '' === $cache_path_template ) {
			$cache_path_template = $this->get_default_cache_path_template();
		}

		$safe_path = $this->sanitize_cache_path_template( $cache_path_template );

		$uri_exclusions    = $this->build_uri_exclusions();
		$ua_exclusions     = $this->build_ua_exclusions();
		$cookie_exclusions = $this->build_cookie_exclusions();

		$lines = array(
			'# BEGIN WMRB suggested MaxCache snippet',
			'<IfModule maxcache_module>',
			'    MaxCache On',
			'    MaxCacheOptions -SkipCacheOnMobile -TabletAsMobile',
			'',
			'    # Query string handling',
			'    MaxCacheQSAllowedParams utm_source utm_medium utm_campaign utm_term utm_content gclid fbclid',
			'    MaxCacheQSIgnoredParams _ga _gl',
			'',
			'    # Safe exclusions (default + WP Rocket)',
			'    MaxCacheExcludeURI "' . $uri_exclusions . '"',
			'    MaxCacheExcludeUA "' . $ua_exclusions . '"',
			'    MaxCacheExcludeCookie "' . $cookie_exclusions . '"',
			'',
			'    MaxCachePath ' . $safe_path,
			'</IfModule>',
			'# END WMRB suggested MaxCache snippet',
		);

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @return array<string,int>
	 */
	public function get_sync_summary() {
		$uri_defaults = array(
			'^/wp-admin/',
			'^/wp-login\\.php',
		);
		$ua_defaults = array(
			'bot',
			'crawl',
			'spider',
		);
		$cookie_defaults = array(
			'wordpress_logged_in_',
			'woocommerce_items_in_cart',
			'wp_woocommerce_session_',
		);

		$uri_rocket = array_values( array_unique( array_filter( array_map( array( $this, 'sanitize_uri_pattern' ), $this->get_wp_rocket_array_setting( 'cache_reject_uri' ) ) ) ) );
		$ua_rocket = array_values( array_unique( array_filter( array_map( array( $this, 'sanitize_pipe_fragment' ), $this->get_wp_rocket_array_setting( 'cache_reject_ua' ) ) ) ) );
		$cookie_rocket = array_values( array_unique( array_filter( array_map( array( $this, 'sanitize_pipe_fragment' ), $this->get_wp_rocket_array_setting( 'cache_reject_cookies' ) ) ) ) );

		return array(
			'uri_total'     => count( array_unique( array_merge( $uri_defaults, $uri_rocket ) ) ),
			'ua_total'      => count( array_unique( array_merge( $ua_defaults, $ua_rocket ) ) ),
			'cookie_total'  => count( array_unique( array_merge( $cookie_defaults, $cookie_rocket ) ) ),
			'uri_synced'    => count( $uri_rocket ),
			'ua_synced'     => count( $ua_rocket ),
			'cookie_synced' => count( $cookie_rocket ),
		);
	}

	public function get_sync_fingerprint() {
		$payload = array(
			'uri'    => $this->build_uri_exclusions(),
			'ua'     => $this->build_ua_exclusions(),
			'cookie' => $this->build_cookie_exclusions(),
			'path'   => $this->get_default_cache_path_template(),
		);

		return md5( wp_json_encode( $payload ) );
	}

	private function sanitize_cache_path_template( $template ) {
		$allowed = preg_replace( '/[^A-Za-z0-9\/\-\._\{\}\$]/', '', $template );
		return (string) $allowed;
	}

	private function get_default_cache_path_template() {
		$path = '/wp-content/cache/wp-rocket/{HTTP_HOST}{REQUEST_URI}{QS_SUFFIX}/index{MOBILE_SUFFIX}{SSL_SUFFIX}.html';
		if ( ! empty( $this->options['serve_gzip_variant'] ) ) {
			$path .= '{GZIP_SUFFIX}';
		}

		return $path;
	}

	private function build_uri_exclusions() {
		$defaults = array(
			'^/wp-admin/',
			'^/wp-login\\.php',
		);

		$rocket_exclusions = $this->get_wp_rocket_array_setting( 'cache_reject_uri' );
		$rocket_exclusions = array_map( array( $this, 'sanitize_uri_pattern' ), $rocket_exclusions );

		$values = array_values( array_unique( array_filter( array_merge( $defaults, $rocket_exclusions ) ) ) );
		return implode( ' ', $values );
	}

	private function build_ua_exclusions() {
		$defaults = array(
			'bot',
			'crawl',
			'spider',
		);

		$rocket_exclusions = $this->get_wp_rocket_array_setting( 'cache_reject_ua' );
		$rocket_exclusions = array_map( array( $this, 'sanitize_pipe_fragment' ), $rocket_exclusions );

		$values = array_values( array_unique( array_filter( array_merge( $defaults, $rocket_exclusions ) ) ) );
		return implode( '|', $values );
	}

	private function build_cookie_exclusions() {
		$defaults = array(
			'wordpress_logged_in_',
			'woocommerce_items_in_cart',
			'wp_woocommerce_session_',
		);

		$rocket_exclusions = $this->get_wp_rocket_array_setting( 'cache_reject_cookies' );
		$rocket_exclusions = array_map( array( $this, 'sanitize_pipe_fragment' ), $rocket_exclusions );

		$values = array_values( array_unique( array_filter( array_merge( $defaults, $rocket_exclusions ) ) ) );
		return implode( '|', $values );
	}

	/**
	 * @return array<int,string>
	 */
	private function get_wp_rocket_array_setting( $key ) {
		$settings = get_option( 'wp_rocket_settings', array() );
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
