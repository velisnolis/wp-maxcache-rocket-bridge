<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMRB_Sync_Manager {
	const STATE_OPTION_KEY = 'wmrb_sync_state';
	const BACKUP_DIR_NAME  = 'wmrb-backups';
	const BACKUP_RETENTION = 5;
	const PROBE_TOKEN_KEY  = 'wmrb_probe_token';
	const PROBE_MARKER     = 'WMRB_PROBE_OK:';
	const PROBE_ATTEMPTS   = 3;
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
		add_action( 'init', array( $this, 'maybe_respond_to_probe' ), 1 );
	}

	/**
	 * Answers the health probe with the token it was issued, proving the
	 * request reached PHP through this site rather than a cached edge response.
	 */
	public function maybe_respond_to_probe() {
		if ( ! isset( $_GET['wmrb_probe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$body = $this->get_probe_response_body( sanitize_text_field( wp_unslash( $_GET['wmrb_probe'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( null === $body ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $body );
		exit;
	}

	/**
	 * @param string $token
	 * @return string|null Null when the token was not the one just issued.
	 */
	public function get_probe_response_body( $token ) {
		$token    = (string) $token;
		$expected = (string) get_transient( self::PROBE_TOKEN_KEY );

		if ( '' === $token || '' === $expected || ! hash_equals( $expected, $token ) ) {
			return null;
		}

		return self::PROBE_MARKER . $token;
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
		unset( $option );

		if ( empty( $this->options['auto_sync_enabled'] ) ) {
			return;
		}

		$old_settings = is_array( $old_value ) ? $old_value : array();
		$new_settings = is_array( $value ) ? $value : array();
		$old_hash     = ( new WMRB_Snippet_Service( $this->options, $old_settings ) )->get_sync_fingerprint();
		$new_hash     = ( new WMRB_Snippet_Service( $this->options, $new_settings ) )->get_sync_fingerprint();

		// A writer may persist unrelated WP Rocket metadata during an admin page
		// load. Existing WMRB drift must not be applied unless the effective
		// MaxCache snippet actually changed in this option update.
		if ( $old_hash === $new_hash ) {
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

		$status     = $current_hash === $last_applied ? 'in_sync' : 'pending_apply';
		$last_error = isset( $state['last_error'] ) ? (string) $state['last_error'] : '';

		if ( 'in_sync' === $status && isset( $state['status'] ) && 'applied_unverified' === $state['status'] ) {
			if ( 'ok' === $this->probe_site_repeatedly() ) {
				$last_error = '';
			} else {
				$status = 'applied_unverified';
			}
		}

		$next = array(
			'status'            => $status,
			'current_hash'      => $current_hash,
			'last_applied_hash' => $last_applied,
			'last_change_at'    => current_time( 'mysql' ),
			'last_applied_at'   => isset( $state['last_applied_at'] ) ? (string) $state['last_applied_at'] : '',
			'last_backup_file'  => isset( $state['last_backup_file'] ) ? (string) $state['last_backup_file'] : '',
			'last_error'        => $last_error,
		);

		update_option( self::STATE_OPTION_KEY, $next, false );
		return $next;
	}

	/**
	 * Refreshes the existing manager after WMRB options change in the same
	 * request. Reusing this instance avoids registering a duplicate hook set.
	 *
	 * @param array<string,mixed> $options
	 * @return array<string,string>
	 */
	public function refresh_state_for_options( array $options ) {
		$this->options         = $options;
		$this->snippet_service = new WMRB_Snippet_Service( $options );

		return $this->refresh_state_from_current_fingerprint();
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
	 * Records that the expected snippet was written but site health could not be
	 * established. Unlike in_sync, this state remains eligible for a later
	 * verification attempt.
	 *
	 * @param string $message
	 * @return array<string,string>
	 */
	private function mark_applied_unverified( $message ) {
		$current_hash = $this->snippet_service->get_sync_fingerprint();
		$state        = $this->get_state();

		$next = array(
			'status'            => 'applied_unverified',
			'current_hash'      => $current_hash,
			'last_applied_hash' => $current_hash,
			'last_change_at'    => isset( $state['last_change_at'] ) ? (string) $state['last_change_at'] : current_time( 'mysql' ),
			'last_applied_at'   => current_time( 'mysql' ),
			'last_backup_file'  => isset( $state['last_backup_file'] ) ? (string) $state['last_backup_file'] : '',
			'last_error'        => (string) $message,
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
		$inspection = $this->inspect_htaccess_configuration();

		if ( ! $this->can_manage_htaccess_mode( $inspection['mode'] ) ) {
			return $this->fail_state( $this->get_management_mode_message( $inspection['mode'] ) );
		}

		return $this->write_managed_block( false );
	}

	/**
	 * @return array<string,string>
	 */
	public function take_over_htaccess_management() {
		return $this->write_managed_block( true );
	}

	/**
	 * Writes the managed block, then confirms the site still answers. A write
	 * that takes the site down is reverted immediately instead of waiting for
	 * someone to notice.
	 *
	 * @param bool $remove_existing_blocks Whether to strip foreign MaxCache blocks first.
	 * @return array<string,string>
	 */
	private function write_managed_block( $remove_existing_blocks ) {
		$htaccess_path = ABSPATH . '.htaccess';

		if ( ! file_exists( $htaccess_path ) || ! is_readable( $htaccess_path ) || ! is_writable( $htaccess_path ) ) {
			return $this->fail_state( __( '.htaccess no accessible (read/write).', 'wp-maxcache-rocket-bridge' ) );
		}

		// Without a writable directory there is no atomic rename, and a direct
		// write can leave Apache reading a truncated .htaccess. Refuse instead.
		if ( ! is_writable( dirname( $htaccess_path ) ) ) {
			return $this->fail_state( __( 'El directori arrel no és escrivible: no es pot escriure .htaccess de forma atòmica i el bridge no farà una escriptura insegura.', 'wp-maxcache-rocket-bridge' ) );
		}

		$lock = $this->acquire_lock();
		if ( null === $lock ) {
			return $this->fail_state( __( 'Ja hi ha una operació del bridge en curs; torna-ho a provar en uns segons.', 'wp-maxcache-rocket-bridge' ) );
		}

		try {
			$original = (string) file_get_contents( $htaccess_path );
			$snippet  = rtrim( $this->snippet_service->get_snippet() ) . "\n";
			$base     = $original;

			if ( $remove_existing_blocks ) {
				$base = $this->remove_all_maxcache_blocks( $original );

				if ( null === $base ) {
					return $this->fail_state( __( 'No s’ha pogut analitzar .htaccess amb prou seguretat: hi ha una secció <IfModule> sense tancar o sintaxi ambigua. Corregeix-la manualment abans de fer el takeover.', 'wp-maxcache-rocket-bridge' ) );
				}
			}

			$updated = $this->upsert_wmrb_block( $base, $snippet );

			// Nothing to do: skip the backup, the write and the probes.
			if ( $updated === $original ) {
				return $this->mark_applied();
			}

			$backup = $this->create_backup( $original );
			if ( '' === $backup ) {
				return $this->fail_state( __( 'No s’ha pogut crear backup de .htaccess.', 'wp-maxcache-rocket-bridge' ) );
			}

			// Baseline taken before touching anything: a site that was already
			// failing must not be blamed on this write, nor rolled back for it.
			$before = $this->probe_site_repeatedly();

			// The probe can take seconds, and WordPress itself rewrites
			// .htaccess when permalinks are saved. Anything that landed in the
			// meantime wins; this operation starts over rather than clobber it.
			clearstatcache( true, $htaccess_path );
			if ( (string) file_get_contents( $htaccess_path ) !== $original ) {
				return $this->fail_state( __( '.htaccess ha canviat mentre s’aplicava el snippet; no s’ha escrit res. Torna-ho a provar.', 'wp-maxcache-rocket-bridge' ), $backup );
			}

			if ( ! $this->write_file_atomically( $htaccess_path, $updated ) ) {
				return $this->fail_state( __( 'No s’ha pogut escriure .htaccess de forma atòmica i segura.', 'wp-maxcache-rocket-bridge' ), $backup );
			}

			$verification = $this->verify_write( $htaccess_path, $original, $updated, $before );

			if ( in_array( $verification['result'], array( 'rolled_back', 'changed' ), true ) ) {
				return $this->fail_state( $verification['message'], $backup );
			}

			$state = 'verified' === $verification['result']
				? $this->mark_applied()
				: $this->mark_applied_unverified( $verification['message'] );
			$state['last_backup_file'] = $backup;
			$state['last_error']       = $verification['message'];
			update_option( self::STATE_OPTION_KEY, $state, false );
			return $state;
		} finally {
			$this->release_lock( $lock );
		}
	}

	/**
	 * @return resource|null
	 */
	private function acquire_lock() {
		$dir = WP_CONTENT_DIR . '/' . self::BACKUP_DIR_NAME;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		$handle = @fopen( $dir . '/.lock', 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return null;
		}

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return null;
		}

		return $handle;
	}

	private function release_lock( $handle ) {
		if ( is_resource( $handle ) ) {
			flock( $handle, LOCK_UN );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * @param string $original Content to restore if verification fails.
	 * @param string $before   Site health recorded before the write.
	 * @return array<string,string>
	 */
	private function verify_write( $htaccess_path, $original, $written, $before ) {
		if ( 'ok' !== $before ) {
			return array(
				'result'  => 'skipped',
				'message' => __( 'Snippet aplicat, però la sonda prèvia no ha establert una referència fiable. Comprova manualment que el web respon.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		$after = $this->probe_site_repeatedly();

		if ( 'ok' === $after ) {
			clearstatcache( true, $htaccess_path );
			if ( (string) file_get_contents( $htaccess_path ) !== $written ) {
				return array(
					'result'  => 'changed',
					'message' => __( 'El web respon, però .htaccess ha canviat després de l’escriptura del bridge; el snippet ja no es pot considerar aplicat. Torna-ho a provar.', 'wp-maxcache-rocket-bridge' ),
				);
			}

			return array(
				'result'  => 'verified',
				'message' => '',
			);
		}

		if ( 'unknown' === $after ) {
			return array(
				'result'  => 'unverified',
				'message' => __( 'Snippet aplicat, però no s’ha pogut verificar que el web respon. Comprova-ho manualment.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		// Only undo what this operation actually wrote. If the file changed
		// again in the meantime, restoring would destroy someone else's work
		// and the site may be broken for a reason that is not ours.
		clearstatcache( true, $htaccess_path );
		if ( (string) file_get_contents( $htaccess_path ) !== $written ) {
			return array(
				'result'  => 'unverified',
				'message' => __( 'El web no respon, però .htaccess ha canviat després de l’escriptura del bridge; no s’ha fet cap rollback automàtic. Revisa-ho manualment.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		if ( $this->write_file_atomically( $htaccess_path, $original ) ) {
			return array(
				'result'  => 'rolled_back',
				'message' => __( 'El web ha deixat de respondre després d’aplicar el snippet; s’ha restaurat .htaccess automàticament.', 'wp-maxcache-rocket-bridge' ),
			);
		}

		return array(
			'result'  => 'rolled_back',
			'message' => __( 'CRÍTIC: el web no respon i no s’ha pogut restaurar .htaccess. Restaura el backup manualment.', 'wp-maxcache-rocket-bridge' ),
		);
	}

	/**
	 * A single 502/503 is a routine event on shared hosting (LSAPI process
	 * limits, for one), and rolling back for it would be worse than useless.
	 *
	 * @return string One of: ok, fail, unknown.
	 */
	private function probe_site_repeatedly() {
		$delay_ms    = (int) apply_filters( 'wmrb_probe_retry_delay_ms', 750 );
		$saw_unknown = false;

		for ( $attempt = 0; $attempt < self::PROBE_ATTEMPTS; $attempt++ ) {
			if ( $attempt > 0 && $delay_ms > 0 ) {
				usleep( $delay_ms * 1000 );
			}

			$result = $this->probe_site();
			if ( 'ok' === $result ) {
				return 'ok';
			}
			if ( 'unknown' === $result ) {
				$saw_unknown = true;
			}
		}

		return $saw_unknown ? 'unknown' : 'fail';
	}

	/**
	 * @return string One of: ok, fail, unknown.
	 */
	private function probe_site() {
		$token = wp_generate_password( 20, false );
		set_transient( self::PROBE_TOKEN_KEY, $token, MINUTE_IN_SECONDS );

		$response = wp_remote_get(
			home_url( '/' ) . '?wmrb_probe=' . rawurlencode( $token ),
			array(
				'timeout'     => 10,
				'redirection' => 0,
				// Certificate verification is off so that a staging or
				// self-signed cert cannot masquerade as a broken site and
				// trigger a needless rollback. No response content is trusted
				// beyond the token this request just minted.
				'sslverify'   => false,
				'headers'     => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
			)
		);

		delete_transient( self::PROBE_TOKEN_KEY );

		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 0 === $code ) {
			return 'unknown';
		}

		if ( $code < 200 || $code >= 400 ) {
			return 'fail';
		}

		// A 200 proves little on its own: a CDN can serve a cached page, or an
		// error page, while the origin is down. The token has to come back.
		return false !== strpos( (string) wp_remote_retrieve_body( $response ), self::PROBE_MARKER . $token )
			? 'ok'
			: 'fail';
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

		$lock = $this->acquire_lock();
		if ( null === $lock ) {
			$state['last_error'] = __( 'Ja hi ha una operació del bridge en curs; torna-ho a provar en uns segons.', 'wp-maxcache-rocket-bridge' );
			update_option( self::STATE_OPTION_KEY, $state, false );
			return $state;
		}

		$written = $this->write_file_atomically( $htaccess_path, $content );
		$this->release_lock( $lock );

		if ( ! $written ) {
			$state['last_error'] = __( 'Rollback fallit: no es pot escriure .htaccess de forma atòmica.', 'wp-maxcache-rocket-bridge' );
			update_option( self::STATE_OPTION_KEY, $state, false );
			return $state;
		}

		$state['status']       = 'pending_apply';
		$state['current_hash'] = $this->snippet_service->get_sync_fingerprint();
		$state['last_error']   = '';
		update_option( self::STATE_OPTION_KEY, $state, false );
		return $state;
	}

	/**
	 * @param string $message
	 * @param string $backup
	 * @return array<string,string>
	 */
	private function fail_state( $message, $backup = '' ) {
		$state           = $this->get_state();
		$state['status'] = 'pending_apply';

		if ( '' !== $backup ) {
			$state['last_backup_file'] = $backup;
		}

		$state['last_error'] = $message;
		update_option( self::STATE_OPTION_KEY, $state, false );
		return $state;
	}

	/**
	 * Writes through a temporary file in the same directory and renames it into
	 * place. There is deliberately no in-place fallback: file_put_contents()
	 * truncates before writing and its lock is only advisory, so Apache can
	 * read an empty or half-written .htaccess and answer 500 for every request.
	 * Refusing is the safer failure.
	 *
	 * @param string $path
	 * @param string $content
	 * @return bool
	 */
	private function write_file_atomically( $path, $content ) {
		$dir = dirname( $path );
		if ( ! is_writable( $dir ) ) {
			return false;
		}

		clearstatcache( true, $path );
		$exists = file_exists( $path );
		$perms  = $exists ? ( fileperms( $path ) & 0777 ) : 0644;
		$group  = $exists ? filegroup( $path ) : false;
		$owner  = $exists ? $this->get_file_owner( $path ) : false;

		$temp = tempnam( $dir, '.wmrb-tmp-' );
		if ( false === $temp ) {
			return false;
		}

		$written = file_put_contents( $temp, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( strlen( $content ) !== $written ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		// Replacing the inode would silently transfer ownership to the PHP
		// worker. Refuse when that differs from the existing .htaccess owner.
		if ( false !== $owner && $this->get_file_owner( $temp ) !== $owner ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		// tempnam() creates the file as 0600 owned by the running user, so mode
		// and group have to be restored explicitly and then confirmed: a file
		// Apache cannot read is as bad as no file at all.
		@chmod( $temp, $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $group && filegroup( $temp ) !== $group ) {
			@chgrp( $temp, $group ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		clearstatcache( true, $temp );
		if ( ( fileperms( $temp ) & 0777 ) !== $perms || ( false !== $group && filegroup( $temp ) !== $group ) ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		if ( ! $this->rename_file( $temp, $path ) ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}

		clearstatcache( true, $path );
		if ( (string) file_get_contents( $path ) === $content ) {
			return true;
		}

		// A third party changed the file after rename. It wins: restoring here
		// would both clobber its work and reintroduce an unsafe in-place write.
		return false;
	}

	/**
	 * Filesystem boundary kept as a protected seam so rename failure can be
	 * verified through the public apply operation without depending on variable
	 * names or source-code grep assertions.
	 */
	protected function rename_file( $source, $target ) {
		return @rename( $source, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Filesystem boundary kept overridable so ownership policies can be tested
	 * without requiring privileged chown operations in CI.
	 *
	 * @param string $path
	 * @return int|false
	 */
	protected function get_file_owner( $path ) {
		return fileowner( $path );
	}

	private function upsert_wmrb_block( $content, $snippet ) {
		$pattern = '/\# BEGIN WMRB suggested MaxCache snippet.*?\# END WMRB suggested MaxCache snippet\s*/s';
		if ( preg_match( $pattern, $content ) ) {
			// $snippet already ends in a newline; appending another one here
			// would grow the file by a blank line on every re-apply.
			return (string) preg_replace( $pattern, $snippet, $content );
		}

		$trimmed = rtrim( $content );
		return $trimmed . "\n\n" . $snippet;
	}

	/**
	 * Strips every MaxCache section from the given .htaccess content.
	 *
	 * Sections are matched by walking the file and tracking nesting depth. A
	 * regex cannot do this: a non-greedy match stops at the first closing tag,
	 * so a MaxCache block wrapping any other <IfModule> would be cut in half
	 * and leave an orphaned </IfModule> behind — which Apache answers with a
	 * 500 for every request to the site.
	 *
	 * @param string $content
	 * @return string|null Null when the content cannot be parsed safely.
	 */
	private function remove_all_maxcache_blocks( $content ) {
		$without_markers = preg_replace( '/(?:^[ \t]*# BEGIN WMRB suggested MaxCache snippet.*?^[ \t]*# END WMRB suggested MaxCache snippet\s*)/ms', '', $content );
		if ( ! is_string( $without_markers ) ) {
			return null;
		}

		$lines = explode( "\n", $without_markers );
		$kept  = array();
		$depth = 0;

		foreach ( $lines as $line ) {
			// Section tags only count as structure when they are actual
			// directives. A comment such as "# closes with </IfModule>" would
			// otherwise end the section early and leave an orphaned tag, which
			// Apache answers with a 500 on every request.
			$code = $this->directive_part( $line );
			if ( null === $code ) {
				return null;
			}

			if ( 0 === $depth ) {
				// "!maxcache_module" is a fallback for when the module is
				// absent, not a configuration block the bridge should own.
				if ( preg_match( '/^[ \t]*<IfModule\s+maxcache_module\s*>/i', $code ) ) {
					$depth = 1;
					continue;
				}

				$kept[] = $line;
				continue;
			}

			$depth += preg_match_all( '/<IfModule\b/i', $code );
			$depth -= preg_match_all( '#</IfModule\s*>#i', $code );

			if ( $depth <= 0 ) {
				$depth = 0;
			}
		}

		// Reaching the end still inside a section means the input was already
		// malformed. Rewriting it would only compound the damage.
		if ( 0 !== $depth ) {
			return null;
		}

		return trim( implode( "\n", $kept ) );
	}

	/**
	 * Returns the directive portion of a line: comments and the contents of
	 * quoted arguments removed, so only real syntax is left to count.
	 *
	 * @param string $line
	 * @return string|null Null when the line is ambiguous and must not be parsed.
	 */
	private function directive_part( $line ) {
		$line   = (string) $line;
		$length = strlen( $line );
		$code   = '';
		$quote  = null;

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $line[ $i ];

			if ( null !== $quote ) {
				if ( '\\' === $char && $i + 1 < $length ) {
					++$i;
					continue;
				}
				if ( $char === $quote ) {
					$quote = null;
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}

			if ( '#' === $char ) {
				break;
			}

			$code .= $char;
		}

		// An unterminated quote, or a line continued with a trailing backslash,
		// means this line cannot be judged on its own. Rather than guess, the
		// caller declines the whole operation.
		if ( null !== $quote || preg_match( '/\\\\$/', rtrim( $code, " \t" ) ) ) {
			return null;
		}

		return $code;
	}

	private function create_backup( $content ) {
		$dir = WP_CONTENT_DIR . '/' . self::BACKUP_DIR_NAME;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// The suffix keeps two writes in the same second from overwriting each
		// other's backup, while the timestamp prefix still drives retention order.
		$file = $dir . '/htaccess-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( $content . microtime() ), 0, 6 ) . '.bak';
		$ok   = file_put_contents( $file, $content, LOCK_EX );
		if ( strlen( $content ) !== $ok ) {
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
