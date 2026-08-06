<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMRB_Github_Updater {
	const CACHE_TRANSIENT   = 'wmrb_github_release_data';
	const SUCCESS_LIFETIME  = HOUR_IN_SECONDS;
	const FAILURE_LIFETIME  = 15 * MINUTE_IN_SECONDS;

	/**
	 * @var string
	 */
	private $plugin_file;

	/**
	 * @var string
	 */
	private $plugin_basename;

	/**
	 * @var string
	 */
	private $slug;

	public function __construct( $plugin_file ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$slug                  = dirname( $this->plugin_basename );
		$this->slug            = '.' === $slug ? basename( $this->plugin_basename, '.php' ) : $slug;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_updates' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
	}

	public function check_for_updates( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( empty( $release['version'] ) || version_compare( (string) $release['version'], WMRB_VERSION, '<=' ) ) {
			return $transient;
		}

		$update              = new stdClass();
		$update->slug        = $this->slug;
		$update->plugin      = $this->plugin_basename;
		$update->new_version = (string) $release['version'];
		$update->url         = isset( $release['url'] ) ? (string) $release['url'] : '';
		$update->package     = isset( $release['package'] ) ? (string) $release['package'] : '';

		if ( '' !== $update->package ) {
			$transient->response[ $this->plugin_basename ] = $update;
		}

		return $transient;
	}

	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( empty( $release ) ) {
			return $result;
		}

		$info              = new stdClass();
		$info->name        = 'WP Rocket + MAxCache Bridge';
		$info->slug        = $this->slug;
		$info->version     = isset( $release['version'] ) ? (string) $release['version'] : WMRB_VERSION;
		$info->author      = '<a href="https://github.com/velisnolis">Miras</a>';
		$info->homepage    = 'https://github.com/' . WMRB_GITHUB_REPO;
		$info->download_link = isset( $release['package'] ) ? (string) $release['package'] : '';
		$info->sections    = array(
			'description' => __( 'Bridge between WP Rocket and mod_maxcache with diagnostics, sync, and safe auto-apply.', 'wp-maxcache-rocket-bridge' ),
			'changelog'   => isset( $release['body'] ) ? wp_kses_post( wpautop( (string) $release['body'] ) ) : '',
		);

		return $info;
	}

	/**
	 * @return array<string,string>
	 */
	private function get_latest_release() {
		$cached = get_transient( self::CACHE_TRANSIENT );
		if ( is_array( $cached ) ) {
			// Failures are cached too, as an empty array. Without that, every
			// update check pays for another blocking request — and GitHub's
			// unauthenticated limit is 60 requests per hour per IP, which on
			// shared hosting is an IP the site does not have to itself.
			return ! empty( $cached['version'] ) ? $cached : array();
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . WMRB_GITHUB_REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WMRB-Updater/' . WMRB_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->remember_failure();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return $this->remember_failure();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return $this->remember_failure();
		}

		$tag = isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '';
		$version = ltrim( $tag, 'vV' );
		$html_url = isset( $body['html_url'] ) ? (string) $body['html_url'] : '';
		$release_body = isset( $body['body'] ) ? (string) $body['body'] : '';

		$package = '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				if ( 'wp-maxcache-rocket-bridge.zip' === (string) $asset['name'] ) {
					$package = (string) $asset['browser_download_url'];
					break;
				}
			}
		}

		// There is deliberately no fallback to GitHub's source archive. That
		// archive is the repository, not the plugin: it unpacks under a
		// version-suffixed directory and now carries the test suite, the build
		// script and the CI configuration. A missing asset means no update.
		if ( '' === $package ) {
			return $this->remember_failure();
		}

		$data = array(
			'version' => $version,
			'url'     => $html_url,
			'package' => $package,
			'body'    => $release_body,
		);

		set_transient( self::CACHE_TRANSIENT, $data, self::SUCCESS_LIFETIME );
		return $data;
	}

	/**
	 * Caches the fact that the lookup failed, so a broken or rate-limited
	 * GitHub does not cost a blocking request on every single update check.
	 *
	 * @return array<string,string>
	 */
	private function remember_failure() {
		set_transient( self::CACHE_TRANSIENT, array(), self::FAILURE_LIFETIME );
		return array();
	}
}
