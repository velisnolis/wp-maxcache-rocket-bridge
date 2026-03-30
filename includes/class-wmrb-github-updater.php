<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMRB_Github_Updater {
	const CACHE_TRANSIENT = 'wmrb_github_release_data';

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
		$this->slug            = dirname( $this->plugin_basename );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_updates' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
	}

	public function check_for_updates( $transient ) {
		if ( empty( $transient->checked ) || ! is_object( $transient ) ) {
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
			'description' => __( 'Bridge entre WP Rocket i mod_maxcache amb diagnòstic, sync i auto-apply segur.', 'wp-maxcache-rocket-bridge' ),
			'changelog'   => isset( $release['body'] ) ? wp_kses_post( wpautop( (string) $release['body'] ) ) : '',
		);

		return $info;
	}

	/**
	 * @return array<string,string>
	 */
	private function get_latest_release() {
		$cached = get_transient( self::CACHE_TRANSIENT );
		if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
			return $cached;
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
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array();
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array();
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

		// Fallback: source zip from release tag.
		if ( '' === $package && '' !== $tag ) {
			$package = 'https://github.com/' . WMRB_GITHUB_REPO . '/archive/refs/tags/' . rawurlencode( $tag ) . '.zip';
		}

		$data = array(
			'version' => $version,
			'url'     => $html_url,
			'package' => $package,
			'body'    => $release_body,
		);

		set_transient( self::CACHE_TRANSIENT, $data, HOUR_IN_SECONDS );
		return $data;
	}
}
