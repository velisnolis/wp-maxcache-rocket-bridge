<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMRB_Quick_Test_Service {
	/**
	 * @return array<string,mixed>
	 */
	public function run() {
		$url = home_url( '/' );

		$identity = $this->request( $url, '' );
		$gzip     = $this->request( $url, 'gzip' );

		return array(
			'url'      => $url,
			'identity' => $this->analyze_response( $identity, false ),
			'gzip'     => $this->analyze_response( $gzip, true ),
		);
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private function request( $url, $accept_encoding ) {
		$args = array(
			'timeout'    => 10,
			'redirection'=> 2,
			'headers'    => array(
				'Accept-Encoding' => $accept_encoding,
			),
		);

		return wp_remote_get( $url, $args );
	}

	/**
	 * @param array<string,mixed>|WP_Error $response
	 * @return array<string,mixed>
	 */
	private function analyze_response( $response, $expects_gzip ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 'ERROR',
				'details' => $response->get_error_message(),
				'headers' => array(),
			);
		}

		$headers = wp_remote_retrieve_headers( $response );
		$normalized_headers = array();

		foreach ( $headers as $key => $value ) {
			$normalized_headers[ strtolower( (string) $key ) ] = (string) $value;
		}

		$score   = 0;
		$reasons = array();

		if ( isset( $normalized_headers['last-modified'] ) ) {
			$score += 1;
			$reasons[] = __( 'last-modified present', 'wp-maxcache-rocket-bridge' );
		} else {
			$reasons[] = __( 'last-modified missing', 'wp-maxcache-rocket-bridge' );
		}

		if ( isset( $normalized_headers['accept-ranges'] ) ) {
			$score += 1;
			$reasons[] = __( 'accept-ranges present', 'wp-maxcache-rocket-bridge' );
		} else {
			$reasons[] = __( 'accept-ranges missing', 'wp-maxcache-rocket-bridge' );
		}

		if ( $expects_gzip ) {
			if ( isset( $normalized_headers['content-encoding'] ) && false !== stripos( $normalized_headers['content-encoding'], 'gzip' ) ) {
				$score += 1;
				$reasons[] = __( 'gzip encoding present', 'wp-maxcache-rocket-bridge' );
			} else {
				$reasons[] = __( 'gzip encoding missing', 'wp-maxcache-rocket-bridge' );
			}
		}

		$status = 'WARN';
		if ( $score >= 2 ) {
			$status = 'OK';
		} elseif ( 0 === $score ) {
			$status = 'ERROR';
		}

		return array(
			'status'  => $status,
			'details' => implode( '; ', $reasons ),
			'headers' => $normalized_headers,
		);
	}
}
