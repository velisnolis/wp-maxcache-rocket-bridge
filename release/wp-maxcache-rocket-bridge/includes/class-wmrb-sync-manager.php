<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMRB_Sync_Manager {
	const STATE_OPTION_KEY = 'wmrb_sync_state';
	const BACKUP_DIR_NAME  = 'wmrb-backups';
	const BACKUP_RETENTION = 5;
	const MODE_MANAGED     = 'managed';
	const MODE_UNMANAGED   = 'unmanaged';
	const MODE_EXTERNAL    = 'external';
	const MODE_CONFLICT    = 'conflict';
	const MODE_UNREADABLE  = 'unreadable';

	/**
	 * @var WMRB_Snippet_Service
	 */
	private $snippet_service;

	/**
	 * @var array<string,mixed>
	 */
	private $options;

	/**
	 * @param array<string,mixed> $options
	 */
	public function __construct( WMRB_Snippet_Service $snippet_service, array $options ) {
		$this->snippet_service = $snippet_service;
		$this->options         = $options;
		$this->register_hooks();
	}

	private function register_hooks() {
		add_action( 'update_option_wp_rocket_settings', array( $this, 'handle_rocket_settings_update' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'maybe_backfill_state' ) );
	}

	public function maybe_backfill_state() {
		$state = get_option( self::STATE_OPTION_KEY, array() );
		if ( is_array( $state ) && ! empty( $state['current_hash'] ) ) {
			return;
		}

		$current_hash = $this->snippet_service->get_sync_fingerprint();
		$initial = array(
			'status'             => 'in_sync',
			'current_hash'       => $current_hash,
			'last_applied_hash'  => $current_hash,
			'last_change_at'     => current_time( 'mysql' ),
			'last_applied_at'    => current_time( 'mysql' ),
		);
		update_option( self::STATE_OPTION_KEY, $initial, false );
	}

	/**
	 * @param mixed $old_value
	 * @param mixed $value
	 * @param string $option
	 */
	public function handle_rocket_settings_update( $old_value, $value, $option ) {
		unset( $old_value, $value, $option );

		if ( empty( $this->options['auto_sync_enabled'] ) ) {
			return;
		}

		$state = $this->refresh_state_from_current_fingerprint();
		$inspection = $this->inspect_htaccess_configuration();

		if ( ! empty( $this->options['auto_apply_htaccess'] ) && isset( $state['status'] ) && 'pending_apply' === $state['status'] && $this->can_manage_htaccess_mode( $inspection['mode'] ) ) {
			$this->apply_snippet_to_htaccess();
		} elseif ( ! $this->can_manage_htaccess_mode( $inspection['mode'] ) ) {
			$state['last_error'] = $this->get_management_mode_message( $inspection['mode'] );
			update_option( self::STATE_OPTION_KEY, $state, false );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function inspect_htaccess_configuration() {
		$htaccess_path = ABSPATH . '.htaccess';

		if ( ! file_exists( $htaccess_path ) ) {
			return array(
				'mode'            => self::MODE_UNMANAGED,
				'wmrb_blocks'     => 0,
				'maxcache_blocks' => 0,
				'message'         => __( 'Encara no hi ha cap bloc MaxCache.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		if ( ! is_readable( $htaccess_path ) ) {
			return array(
				'mode'            => self::MODE_UNREADABLE,
				'wmrb_blocks'     => 0,
				'maxcache_blocks' => 0,
				'message'         => __( '.htaccess no és llegible; no es pot determinar qui governa MaxCache.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		$content = (string) file_get_contents( $htaccess_path );
		$wmrb_blocks = preg_match_all( '/# BEGIN WMRB suggested MaxCache snippet.*?# END WMRB suggested MaxCache snippet/s', $content, $matches );
		$maxcache_blocks = preg_match_all( '/<IfModule\s+maxcache_module>.*?<\/IfModule>/is', $content, $matches );
		$wmrb_blocks = false === $wmrb_blocks ? 0 : (int) $wmrb_blocks;
		$maxcache_blocks = false === $maxcache_blocks ? 0 : (int) $maxcache_blocks;

		if ( $wmrb_blocks > 1 || ( $wmrb_blocks >= 1 && $maxcache_blocks > 1 ) ) {
			return array(
				'mode'            => self::MODE_CONFLICT,
				'wmrb_blocks'     => $wmrb_blocks,
				'maxcache_blocks' => $maxcache_blocks,
				'message'         => __( 'Hi ha més d’un bloc MaxCache actiu o el bloc WMRB conviu amb un altre bloc extern.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		if ( 1 === $wmrb_blocks && 1 === $maxcache_blocks ) {
			return array(
				'mode'            => self::MODE_MANAGED,
				'wmrb_blocks'     => $wmrb_blocks,
				'maxcache_blocks' => $maxcache_blocks,
				'message'         => __( 'El bridge governa l’únic bloc MaxCache actiu.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		if ( 0 === $wmrb_blocks && $maxcache_blocks > 0 ) {
			return array(
				'mode'            => self::MODE_EXTERNAL,
				'wmrb_blocks'     => $wmrb_blocks,
				'maxcache_blocks' => $maxcache_blocks,
				'message'         => __( 'S’ha detectat un bloc MaxCache extern/manual; el bridge no és el propietari actual.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		return array(
			'mode'            => self::MODE_UNMANAGED,
			'wmrb_blocks'     => $wmrb_blocks,
			'maxcache_blocks' => $maxcache_blocks,
			'message'         => __( 'No hi ha cap bloc MaxCache gestionat pel bridge.', 'wp-maxcache-rocket-bridge' ),
		);
	}

	public function refresh_state_from_current_fingerprint() {
		$current_hash = $this->snippet_service->get_sync_fingerprint();
		$state        = $this->get_state();
		$last_applied = isset( $state['last_applied_hash'] ) ? (string) $state['last_applied_hash'] : '';

		// First run: assume current snippet is applied to avoid false pending status.
		if ( '' === $last_applied ) {
			$last_applied = $current_hash;
		}

		$status       = $current_hash === $last_applied ? 'in_sync' : 'pending_apply';

		$next = array(
			'status'            => $status,
			'current_hash'      => $current_hash,
			'last_applied_hash' => $last_applied,
			'last_change_at'    => current_time( 'mysql' ),
			'last_applied_at'   => isset( $state['last_applied_at'] ) ? (string) $state['last_applied_at'] : '',
			'last_backup_file'  => isset( $state['last_backup_file'] ) ? (string) $state['last_backup_file'] : '',
			'last_error'        => isset( $state['last_error'] ) ? (string) $state['last_error'] : '',
		);

		update_option( self::STATE_OPTION_KEY, $next, false );
		return $next;
	}

	/**
	 * @return array<string,string>
	 */
	public function mark_applied() {
		$current_hash = $this->snippet_service->get_sync_fingerprint();
		$state        = $this->get_state();

		$next = array(
			'status'            => 'in_sync',
			'current_hash'      => $current_hash,
			'last_applied_hash' => $current_hash,
			'last_change_at'    => isset( $state['last_change_at'] ) ? (string) $state['last_change_at'] : current_time( 'mysql' ),
			'last_applied_at'   => current_time( 'mysql' ),
			'last_backup_file'  => isset( $state['last_backup_file'] ) ? (string) $state['last_backup_file'] : '',
			'last_error'        => '',
		);

		update_option( self::STATE_OPTION_KEY, $next, false );
		return $next;
	}

	/**
	 * @return array<string,string>
	 */
	public function get_state() {
		$state = get_option( self::STATE_OPTION_KEY, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$defaults = array(
			'status'            => 'in_sync',
			'current_hash'      => '',
			'last_applied_hash' => '',
			'last_change_at'    => '',
			'last_applied_at'   => '',
			'last_backup_file'  => '',
			'last_error'        => '',
		);

		return wp_parse_args( $state, $defaults );
	}

	/**
	 * @return array<string,string>
	 */
	public function apply_snippet_to_htaccess() {
		$htaccess_path = ABSPATH . '.htaccess';
		$state         = $this->get_state();
		$inspection    = $this->inspect_htaccess_configuration();

		if ( ! $this->can_manage_htaccess_mode( $inspection['mode'] ) ) {
			$state['status']     = 'pending_apply';
			$state['last_error'] = $this->get_management_mode_message( $inspection['mode'] );
			update_option( self::STATE_OPTION_KEY, $state, false );
			return $state;
		}

		if ( ! file_exists( $htaccess_path ) || ! is_readable( $htaccess_path ) || ! is_writable( $htaccess_path ) ) {
			$state['status']     = 'pending_apply';
			$state['last_error'] = __( '.htaccess no accessible (read/write).', 'wp-maxcache-rocket-bridge' );
			update_option( self::STATE_OPTION_KEY, $state, false );
			return $state;
		}

		$original = (string) file_get_contents( $htaccess_path );
		$backup   = $this->create_backup( $original );
		if ( '' === $backup ) {
			$state['status']     = 'pending_apply';
			$state['last_error'] = __( 'No s’ha pogut crear backup de .htaccess.', 'wp-maxcache-rocket-bridge' );
			update_option( self::STATE_OPTION_KEY, $state, false );
			return $state;
		}

		$snippet  = rtrim( $this->snippet_service->get_snippet() ) . "\n";
		$updated  = $this->upsert_wmrb_block( $original, $snippet );
		$written  = file_put_contents( $htaccess_path, $updated );

		if ( false === $written ) {
			$state['status']          = 'pending_apply';
			$state['last_backup_file']= $backup;
			$state['last_error']      = __( 'No s’ha pogut escriure .htaccess.', 'wp-maxcache-rocket-bridge' );
			update_option( self::STATE_OPTION_KEY, $state, false );
			return $state;
		}

		$state                    = $this->mark_applied();
		$state['last_backup_file']= $backup;
		$state['last_error']      = '';
		update_option( self::STATE_OPTION_KEY, $state, false );
		return $state;
	}

	/**
	 * @return array<string,string>
	 */
	public function take_over_htaccess_management() {
		$htaccess_path = ABSPATH . '.htaccess';
		$state         = $this->get_state();

		if ( ! file_exists( $htaccess_path ) || ! is_readable( $htaccess_path ) || ! is_writable( $htaccess_path ) ) {
			$state['status']     = 'pending_apply';
			$state['last_error'] = __( '.htaccess no accessible (read/write).', 'wp-maxcache-rocket-bridge' );
			update_option( self::STATE_OPTION_KEY, $state, false );
			return $state;
		}

		$original = (string) file_get_contents( $htaccess_path );
		$backup   = $this->create_backup( $original );
		if ( '' === $backup ) {
			$state['status']     = 'pending_apply';
			$state['last_error'] = __( 'No s’ha pogut crear backup de .htaccess.', 'wp-maxcache-rocket-bridge' );
			update_option( self::STATE_OPTION_KEY, $state, false );
			return $state;
		}

		$snippet = rtrim( $this->snippet_service->get_snippet() ) . "\n";
		$cleaned = $this->remove_all_maxcache_blocks( $original );
		$updated = $this->upsert_wmrb_block( $cleaned, $snippet );
		$written = file_put_contents( $htaccess_path, $updated );

		if ( false === $written ) {
			$state['status']          = 'pending_apply';
			$state['last_backup_file']= $backup;
			$state['last_error']      = __( 'No s’ha pogut escriure .htaccess.', 'wp-maxcache-rocket-bridge' );
			update_option( self::STATE_OPTION_KEY, $state, false );
			return $state;
		}

		$state                    = $this->mark_applied();
		$state['last_backup_file']= $backup;
		$state['last_error']      = '';
		update_option( self::STATE_OPTION_KEY, $state, false );
		return $state;
	}

	/**
	 * @return array<string,string>
	 */
	public function rollback_last_backup() {
		$state = $this->get_state();
		$file  = isset( $state['last_backup_file'] ) ? (string) $state['last_backup_file'] : '';
		if ( '' === $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
			$state['last_error'] = __( 'No hi ha backup vàlid per rollback.', 'wp-maxcache-rocket-bridge' );
			update_option( self::STATE_OPTION_KEY, $state, false );
			return $state;
		}

		$htaccess_path = ABSPATH . '.htaccess';
		$content       = (string) file_get_contents( $file );
		$written       = file_put_contents( $htaccess_path, $content );
		if ( false === $written ) {
			$state['last_error'] = __( 'Rollback fallit: no es pot escriure .htaccess.', 'wp-maxcache-rocket-bridge' );
			update_option( self::STATE_OPTION_KEY, $state, false );
			return $state;
		}

		$state['status']       = 'pending_apply';
		$state['current_hash'] = $this->snippet_service->get_sync_fingerprint();
		$state['last_error']   = '';
		update_option( self::STATE_OPTION_KEY, $state, false );
		return $state;
	}

	private function upsert_wmrb_block( $content, $snippet ) {
		$pattern = '/\# BEGIN WMRB suggested MaxCache snippet.*?\# END WMRB suggested MaxCache snippet\s*/s';
		if ( preg_match( $pattern, $content ) ) {
			return (string) preg_replace( $pattern, $snippet . "\n", $content );
		}

		$trimmed = rtrim( $content );
		return $trimmed . "\n\n" . $snippet;
	}

	private function remove_all_maxcache_blocks( $content ) {
		$cleaned = preg_replace( '/(?:^[ \t]*# BEGIN WMRB suggested MaxCache snippet.*?^[ \t]*# END WMRB suggested MaxCache snippet\s*)/ms', '', $content );
		$cleaned = preg_replace( '/(?:^[ \t]*<IfModule\s+maxcache_module>.*?^[ \t]*<\/IfModule>\s*)/mis', '', (string) $cleaned );
		return is_string( $cleaned ) ? trim( $cleaned ) : trim( $content );
	}

	private function create_backup( $content ) {
		$dir = WP_CONTENT_DIR . '/' . self::BACKUP_DIR_NAME;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$file = $dir . '/htaccess-' . gmdate( 'Ymd-His' ) . '.bak';
		$ok   = file_put_contents( $file, $content );
		if ( false === $ok ) {
			return '';
		}

		$this->enforce_backup_retention( $dir );
		return $file;
	}

	private function enforce_backup_retention( $dir ) {
		$pattern = trailingslashit( $dir ) . 'htaccess-*.bak';
		$files   = glob( $pattern );
		if ( ! is_array( $files ) || count( $files ) <= self::BACKUP_RETENTION ) {
			return;
		}

		usort(
			$files,
			static function ( $a, $b ) {
				return strcmp( (string) $b, (string) $a );
			}
		);

		$to_delete = array_slice( $files, self::BACKUP_RETENTION );
		foreach ( $to_delete as $file ) {
			if ( is_string( $file ) && file_exists( $file ) ) {
				@unlink( $file );
			}
		}
	}

	private function can_manage_htaccess_mode( $mode ) {
		return in_array( $mode, array( self::MODE_MANAGED, self::MODE_UNMANAGED ), true );
	}

	private function get_management_mode_message( $mode ) {
		if ( self::MODE_EXTERNAL === $mode ) {
			return __( 'Hi ha un bloc MaxCache extern; el bridge no auto-aplica fins que es passi a mode gestionat.', 'wp-maxcache-rocket-bridge' );
		}

		if ( self::MODE_CONFLICT === $mode ) {
			return __( 'Hi ha conflicte entre blocs MaxCache; resol-lo abans d’auto-aplicar.', 'wp-maxcache-rocket-bridge' );
		}

		if ( self::MODE_UNREADABLE === $mode ) {
			return __( '.htaccess no és llegible; no es pot validar el mode gestionat.', 'wp-maxcache-rocket-bridge' );
		}

		return '';
	}
}
