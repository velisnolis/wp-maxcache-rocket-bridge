<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMRB_Diagnostics_Service {
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

	/**
	 * @return array<string,mixed>
	 */
	public function run_checks() {
		$checks = array(
			'wp_rocket_active'  => $this->check_wp_rocket(),
			'apache_environment'=> $this->check_apache(),
			'htaccess_block'    => $this->check_htaccess_block(),
			'cache_files'       => $this->check_cache_files(),
		);

		$statuses = wp_list_pluck( $checks, 'status' );
		$overall  = 'OK';

		if ( in_array( 'ERROR', $statuses, true ) ) {
			$overall = 'ERROR';
		} elseif ( in_array( 'WARN', $statuses, true ) ) {
			$overall = 'WARN';
		}

		return array(
			'overall' => $overall,
			'checks'  => $checks,
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function check_wp_rocket() {
		$active = defined( 'WP_ROCKET_VERSION' ) || function_exists( 'rocket_clean_domain' );

		if ( $active ) {
			return array(
				'status' => 'OK',
				'reason' => __( 'WP Rocket detectat correctament.', 'wp-maxcache-rocket-bridge' ),
				'action' => '',
			);
		}

		return array(
			'status' => 'ERROR',
			'reason' => __( 'WP Rocket no està actiu o no és detectable.', 'wp-maxcache-rocket-bridge' ),
			'action' => __( 'Activa WP Rocket abans d’usar el bridge.', 'wp-maxcache-rocket-bridge' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function check_apache() {
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
		$is_apache       = false !== stripos( $server_software, 'apache' );

		if ( ! $is_apache && function_exists( 'apache_get_modules' ) ) {
			$modules   = (array) apache_get_modules();
			$is_apache = ! empty( $modules );
		}

		if ( $is_apache ) {
			return array(
				'status' => 'OK',
				'reason' => __( 'Entorn Apache detectat.', 'wp-maxcache-rocket-bridge' ),
				'action' => '',
			);
		}

		return array(
			'status' => 'WARN',
			'reason' => __( 'No s’ha pogut confirmar Apache des de WordPress.', 'wp-maxcache-rocket-bridge' ),
			'action' => __( 'Valida manualment stack i presència de mod_maxcache.', 'wp-maxcache-rocket-bridge' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function check_htaccess_block() {
		$htaccess_path = ABSPATH . '.htaccess';
		if ( ! file_exists( $htaccess_path ) ) {
			return array(
				'status' => 'WARN',
				'reason' => __( '.htaccess no trobat.', 'wp-maxcache-rocket-bridge' ),
				'action' => __( 'Comprova si el teu entorn usa una altra capa de routing.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		if ( ! is_readable( $htaccess_path ) ) {
			return array(
				'status' => 'WARN',
				'reason' => __( '.htaccess existeix però no és llegible.', 'wp-maxcache-rocket-bridge' ),
				'action' => __( 'Revisa permisos de fitxer per a diagnòstic.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		$content = (string) file_get_contents( $htaccess_path );
		$found   = false !== stripos( $content, 'maxcache_module' ) || false !== stripos( $content, 'MaxCache On' );

		if ( $found ) {
			return array(
				'status' => 'OK',
				'reason' => __( 'Snippet MaxCache detectat a .htaccess.', 'wp-maxcache-rocket-bridge' ),
				'action' => '',
			);
		}

		return array(
			'status' => 'WARN',
			'reason' => __( 'No s’ha detectat bloc MaxCache a .htaccess.', 'wp-maxcache-rocket-bridge' ),
			'action' => __( 'Afegeix manualment el snippet recomanat.', 'wp-maxcache-rocket-bridge' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function check_cache_files() {
		$cache_root = WP_CONTENT_DIR . '/cache/wp-rocket';
		if ( ! is_dir( $cache_root ) ) {
			return array(
				'status' => 'WARN',
				'reason' => __( 'Directori de cache de WP Rocket no trobat.', 'wp-maxcache-rocket-bridge' ),
				'action' => __( 'Genera cache amb una visita/purga i torna a provar.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		$html_file = $this->find_first_html_cache_file( $cache_root );
		if ( '' === $html_file ) {
			return array(
				'status' => 'WARN',
				'reason' => __( 'No s’han trobat fitxers HTML cachejats encara.', 'wp-maxcache-rocket-bridge' ),
				'action' => __( 'Força generació de cache des de WP Rocket.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		return array(
			'status' => 'OK',
			'reason' => __( 'Hi ha fitxers cachejats a WP Rocket.', 'wp-maxcache-rocket-bridge' ),
			'action' => '',
		);
	}

	private function find_first_html_cache_file( $cache_root ) {
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$cache_root,
					FilesystemIterator::SKIP_DOTS
				)
			);
		} catch ( Exception $e ) {
			return '';
		}

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}

			$name = $file->getFilename();
			if ( preg_match( '/^index.*\.html(\.gz)?$/', $name ) ) {
				return (string) $file->getPathname();
			}
		}

		return '';
	}
}
