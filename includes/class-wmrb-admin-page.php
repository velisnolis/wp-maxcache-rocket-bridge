<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMRB_Admin_Page {
	/**
	 * @var WMRB_Diagnostics_Service
	 */
	private $diagnostics;

	/**
	 * @var WMRB_Snippet_Service
	 */
	private $snippet_service;

	/**
	 * @var WMRB_Quick_Test_Service
	 */
	private $quick_test;

	/**
	 * @var WMRB_Purge_Observer
	 */
	private $purge_observer;

	/**
	 * @var WMRB_Sync_Manager
	 */
	private $sync_manager;

	/**
	 * @var array<string,mixed>
	 */
	private $options;

	/**
	 * @param array<string,mixed> $options
	 */
	public function __construct( WMRB_Diagnostics_Service $diagnostics, WMRB_Snippet_Service $snippet_service, WMRB_Quick_Test_Service $quick_test, WMRB_Purge_Observer $purge_observer, WMRB_Sync_Manager $sync_manager, array $options ) {
		$this->diagnostics    = $diagnostics;
		$this->snippet_service= $snippet_service;
		$this->quick_test     = $quick_test;
		$this->purge_observer = $purge_observer;
		$this->sync_manager   = $sync_manager;
		$this->options        = $options;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_wmrb_run_checks', array( $this, 'handle_run_checks' ) );
		add_action( 'admin_post_wmrb_download_snippet', array( $this, 'handle_download_snippet' ) );
		add_action( 'admin_post_wmrb_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_wmrb_mark_applied', array( $this, 'handle_mark_applied' ) );
		add_action( 'admin_post_wmrb_toggle_auto_sync', array( $this, 'handle_toggle_auto_sync' ) );
		add_action( 'admin_post_wmrb_toggle_auto_apply', array( $this, 'handle_toggle_auto_apply' ) );
		add_action( 'admin_post_wmrb_toggle_gzip_variant', array( $this, 'handle_toggle_gzip_variant' ) );
		add_action( 'admin_post_wmrb_apply_now', array( $this, 'handle_apply_now' ) );
		add_action( 'admin_post_wmrb_rollback', array( $this, 'handle_rollback' ) );
	}

	public function register_menu() {
		add_management_page(
			__( 'MAxCache Bridge', 'wp-maxcache-rocket-bridge' ),
			__( 'MAxCache Bridge', 'wp-maxcache-rocket-bridge' ),
			'manage_options',
			'wmrb-bridge',
			array( $this, 'render_page' )
		);
	}

	public function handle_run_checks() {
		$this->assert_permissions_and_nonce( 'wmrb_run_checks' );

		$checks = $this->diagnostics->run_checks();
		$tests  = $this->quick_test->run();

		set_transient( 'wmrb_last_checks_' . get_current_user_id(), array(
			'checks' => $checks,
			'tests'  => $tests,
		), 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'tools.php?page=wmrb-bridge&wmrb=checked' ) );
		exit;
	}

	public function handle_download_snippet() {
		$this->assert_permissions_and_nonce( 'wmrb_download_snippet' );

		$snippet = $this->snippet_service->get_snippet();

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wmrb-maxcache-snippet.txt"' );
		echo $snippet; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function handle_clear_log() {
		$this->assert_permissions_and_nonce( 'wmrb_clear_log' );
		$this->purge_observer->clear_log();
		wp_safe_redirect( admin_url( 'tools.php?page=wmrb-bridge&wmrb=log-cleared' ) );
		exit;
	}

	public function handle_mark_applied() {
		$this->assert_permissions_and_nonce( 'wmrb_mark_applied' );
		$this->sync_manager->mark_applied();
		wp_safe_redirect( admin_url( 'tools.php?page=wmrb-bridge&wmrb=marked-applied' ) );
		exit;
	}

	public function handle_toggle_auto_sync() {
		$this->assert_permissions_and_nonce( 'wmrb_toggle_auto_sync' );

		$enabled               = isset( $_POST['auto_sync_enabled'] ) && '1' === (string) $_POST['auto_sync_enabled'];
		$this->options['auto_sync_enabled'] = $enabled;

		$current = get_option( WMRB_Plugin::OPTION_KEY, array() );
		$current = is_array( $current ) ? $current : array();
		$current['auto_sync_enabled'] = $enabled;
		update_option( WMRB_Plugin::OPTION_KEY, $current );

		if ( $enabled ) {
			$this->sync_manager->refresh_state_from_current_fingerprint();
		}

		wp_safe_redirect( admin_url( 'tools.php?page=wmrb-bridge&wmrb=sync-updated' ) );
		exit;
	}

	public function handle_toggle_auto_apply() {
		$this->assert_permissions_and_nonce( 'wmrb_toggle_auto_apply' );

		$enabled = isset( $_POST['auto_apply_htaccess'] ) && '1' === (string) $_POST['auto_apply_htaccess'];
		$this->options['auto_apply_htaccess'] = $enabled;

		$current = get_option( WMRB_Plugin::OPTION_KEY, array() );
		$current = is_array( $current ) ? $current : array();
		$current['auto_apply_htaccess'] = $enabled;
		update_option( WMRB_Plugin::OPTION_KEY, $current );

		wp_safe_redirect( admin_url( 'tools.php?page=wmrb-bridge&wmrb=auto-apply-updated' ) );
		exit;
	}

	public function handle_apply_now() {
		$this->assert_permissions_and_nonce( 'wmrb_apply_now' );
		$this->sync_manager->apply_snippet_to_htaccess();
		wp_safe_redirect( admin_url( 'tools.php?page=wmrb-bridge&wmrb=applied-now' ) );
		exit;
	}

	public function handle_toggle_gzip_variant() {
		$this->assert_permissions_and_nonce( 'wmrb_toggle_gzip_variant' );

		$enabled = isset( $_POST['serve_gzip_variant'] ) && '1' === (string) $_POST['serve_gzip_variant'];
		$this->options['serve_gzip_variant'] = $enabled;

		$current = get_option( WMRB_Plugin::OPTION_KEY, array() );
		$current = is_array( $current ) ? $current : array();
		$current['serve_gzip_variant'] = $enabled;
		update_option( WMRB_Plugin::OPTION_KEY, $current );

		$this->sync_manager->refresh_state_from_current_fingerprint();
		wp_safe_redirect( admin_url( 'tools.php?page=wmrb-bridge&wmrb=gzip-updated' ) );
		exit;
	}

	public function handle_rollback() {
		$this->assert_permissions_and_nonce( 'wmrb_rollback' );
		$this->sync_manager->rollback_last_backup();
		wp_safe_redirect( admin_url( 'tools.php?page=wmrb-bridge&wmrb=rollback-done' ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$default = array(
			'checks' => $this->diagnostics->run_checks(),
			'tests'  => null,
		);

		$data = get_transient( 'wmrb_last_checks_' . get_current_user_id() );
		if ( ! is_array( $data ) ) {
			$data = $default;
		}

		$snippet = $this->snippet_service->get_snippet();
		$sync    = $this->snippet_service->get_sync_summary();
		$state   = ! empty( $this->options['auto_sync_enabled'] )
			? $this->sync_manager->refresh_state_from_current_fingerprint()
			: $this->sync_manager->get_state();
		$log     = $this->purge_observer->get_log();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Rocket + MAxCache Bridge', 'wp-maxcache-rocket-bridge' ); ?></h1>
			<p><?php echo esc_html__( 'Diagnòstic de compatibilitat, snippet recomanat i verificació ràpida de capçaleres.', 'wp-maxcache-rocket-bridge' ); ?></p>

			<h2><?php echo esc_html__( 'Estat de l\'entorn', 'wp-maxcache-rocket-bridge' ); ?></h2>
			<?php $this->render_checks_table( $data['checks'] ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wmrb_run_checks' ); ?>
				<input type="hidden" name="action" value="wmrb_run_checks" />
				<?php submit_button( __( 'Run checks', 'wp-maxcache-rocket-bridge' ), 'primary', 'submit', false ); ?>
			</form>

			<h2><?php echo esc_html__( 'Snippet recomanat', 'wp-maxcache-rocket-bridge' ); ?></h2>
			<p>
				<?php echo esc_html__( 'Auto-sync WP Rocket:', 'wp-maxcache-rocket-bridge' ); ?>
				<strong><?php echo ! empty( $this->options['auto_sync_enabled'] ) ? esc_html__( 'ON', 'wp-maxcache-rocket-bridge' ) : esc_html__( 'OFF', 'wp-maxcache-rocket-bridge' ); ?></strong>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px;">
				<?php wp_nonce_field( 'wmrb_toggle_auto_sync' ); ?>
				<input type="hidden" name="action" value="wmrb_toggle_auto_sync" />
				<label>
					<input type="checkbox" name="auto_sync_enabled" value="1" <?php checked( ! empty( $this->options['auto_sync_enabled'] ) ); ?> />
					<?php echo esc_html__( 'Habilitar monitorització automàtica de canvis de WP Rocket', 'wp-maxcache-rocket-bridge' ); ?>
				</label>
				<?php submit_button( __( 'Guardar auto-sync', 'wp-maxcache-rocket-bridge' ), 'secondary', 'submit', false ); ?>
			</form>
			<p>
				<?php echo esc_html__( 'Auto-apply .htaccess:', 'wp-maxcache-rocket-bridge' ); ?>
				<strong><?php echo ! empty( $this->options['auto_apply_htaccess'] ) ? esc_html__( 'ON', 'wp-maxcache-rocket-bridge' ) : esc_html__( 'OFF', 'wp-maxcache-rocket-bridge' ); ?></strong>
			</p>
			<p><?php echo esc_html__( 'Retention backups:', 'wp-maxcache-rocket-bridge' ); ?> <strong>5</strong></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px;">
				<?php wp_nonce_field( 'wmrb_toggle_auto_apply' ); ?>
				<input type="hidden" name="action" value="wmrb_toggle_auto_apply" />
				<label>
					<input type="checkbox" name="auto_apply_htaccess" value="1" <?php checked( ! empty( $this->options['auto_apply_htaccess'] ) ); ?> />
					<?php echo esc_html__( 'Aplicar automàticament el bloc WMRB a .htaccess (amb backup)', 'wp-maxcache-rocket-bridge' ); ?>
				</label>
				<?php submit_button( __( 'Guardar auto-apply', 'wp-maxcache-rocket-bridge' ), 'secondary', 'submit', false ); ?>
			</form>
			<p>
				<?php echo esc_html__( 'Serve gzip variant:', 'wp-maxcache-rocket-bridge' ); ?>
				<strong><?php echo ! empty( $this->options['serve_gzip_variant'] ) ? esc_html__( 'ON', 'wp-maxcache-rocket-bridge' ) : esc_html__( 'OFF', 'wp-maxcache-rocket-bridge' ); ?></strong>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px;">
				<?php wp_nonce_field( 'wmrb_toggle_gzip_variant' ); ?>
				<input type="hidden" name="action" value="wmrb_toggle_gzip_variant" />
				<label>
					<input type="checkbox" name="serve_gzip_variant" value="1" <?php checked( ! empty( $this->options['serve_gzip_variant'] ) ); ?> />
					<?php echo esc_html__( 'Servir fitxer .gz directament des de MaxCachePath (només recomanat sense Cloudflare/proxy)', 'wp-maxcache-rocket-bridge' ); ?>
				</label>
				<?php submit_button( __( 'Guardar gzip variant', 'wp-maxcache-rocket-bridge' ), 'secondary', 'submit', false ); ?>
			</form>
			<p>
				<?php echo esc_html__( 'Sincronització WP Rocket:', 'wp-maxcache-rocket-bridge' ); ?>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: URI total, 2: URI synced, 3: UA total, 4: UA synced, 5: cookie total, 6: cookie synced */
						__( 'URI %1$d (%2$d sincronitzades), UA %3$d (%4$d sincronitzades), Cookies %5$d (%6$d sincronitzades).', 'wp-maxcache-rocket-bridge' ),
						(int) $sync['uri_total'],
						(int) $sync['uri_synced'],
						(int) $sync['ua_total'],
						(int) $sync['ua_synced'],
						(int) $sync['cookie_total'],
						(int) $sync['cookie_synced']
					)
				);
				?>
			</p>
			<p>
				<?php echo esc_html__( 'Estat de sincronització:', 'wp-maxcache-rocket-bridge' ); ?>
				<?php
				$sync_status = isset( $state['status'] ) ? (string) $state['status'] : 'in_sync';
				$sync_label  = 'in_sync' === $sync_status
					? __( 'in_sync', 'wp-maxcache-rocket-bridge' )
					: ( 'pending_apply' === $sync_status
						? __( 'pending_apply', 'wp-maxcache-rocket-bridge' )
						: $sync_status
					);
				?>
				<strong><?php echo esc_html( $sync_label ); ?></strong>
				<?php if ( ! empty( $state['last_change_at'] ) ) : ?>
					(<?php echo esc_html__( 'últim canvi detectat:', 'wp-maxcache-rocket-bridge' ); ?> <?php echo esc_html( (string) $state['last_change_at'] ); ?>)
				<?php endif; ?>
				<?php if ( ! empty( $state['last_applied_at'] ) ) : ?>
					(<?php echo esc_html__( 'últim aplicat:', 'wp-maxcache-rocket-bridge' ); ?> <?php echo esc_html( (string) $state['last_applied_at'] ); ?>)
				<?php endif; ?>
			</p>
			<p>
				<?php if ( ! empty( $state['last_backup_file'] ) ) : ?>
					<?php echo esc_html__( 'Últim backup:', 'wp-maxcache-rocket-bridge' ); ?> <code><?php echo esc_html( (string) $state['last_backup_file'] ); ?></code>
				<?php endif; ?>
				<?php if ( ! empty( $state['last_error'] ) ) : ?>
					<br /><?php echo esc_html__( 'Últim error:', 'wp-maxcache-rocket-bridge' ); ?> <strong><?php echo esc_html( (string) $state['last_error'] ); ?></strong>
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px;">
				<?php wp_nonce_field( 'wmrb_apply_now' ); ?>
				<input type="hidden" name="action" value="wmrb_apply_now" />
				<?php submit_button( __( 'Apply snippet now', 'wp-maxcache-rocket-bridge' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px;">
				<?php wp_nonce_field( 'wmrb_rollback' ); ?>
				<input type="hidden" name="action" value="wmrb_rollback" />
				<?php submit_button( __( 'Rollback last backup', 'wp-maxcache-rocket-bridge' ), 'delete', 'submit', false ); ?>
			</form>
			<?php if ( isset( $state['status'] ) && 'pending_apply' === $state['status'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px;">
					<?php wp_nonce_field( 'wmrb_mark_applied' ); ?>
					<input type="hidden" name="action" value="wmrb_mark_applied" />
					<?php submit_button( __( 'Mark snippet as applied', 'wp-maxcache-rocket-bridge' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<textarea id="wmrb-snippet" rows="14" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $snippet ); ?></textarea>
			<p>
				<button type="button" class="button" id="wmrb-copy-snippet"><?php echo esc_html__( 'Copy snippet', 'wp-maxcache-rocket-bridge' ); ?></button>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-left:8px;">
					<?php wp_nonce_field( 'wmrb_download_snippet' ); ?>
					<input type="hidden" name="action" value="wmrb_download_snippet" />
					<?php submit_button( __( 'Download snippet.txt', 'wp-maxcache-rocket-bridge' ), 'secondary', 'submit', false ); ?>
				</form>
			</p>

			<h2><?php echo esc_html__( 'Resultat proves ràpides', 'wp-maxcache-rocket-bridge' ); ?></h2>
			<?php $this->render_quick_test( $data['tests'] ); ?>

			<h2><?php echo esc_html__( 'Purge observer (debug mode)', 'wp-maxcache-rocket-bridge' ); ?></h2>
			<p><?php echo esc_html__( 'Debug mode:', 'wp-maxcache-rocket-bridge' ); ?> <strong><?php echo ! empty( $this->options['debug_mode'] ) ? esc_html__( 'ON', 'wp-maxcache-rocket-bridge' ) : esc_html__( 'OFF', 'wp-maxcache-rocket-bridge' ); ?></strong></p>
			<?php if ( empty( $log ) ) : ?>
				<p><?php echo esc_html__( 'No hi ha esdeveniments registrats.', 'wp-maxcache-rocket-bridge' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr><th><?php echo esc_html__( 'Time', 'wp-maxcache-rocket-bridge' ); ?></th><th><?php echo esc_html__( 'Hook', 'wp-maxcache-rocket-bridge' ); ?></th></tr>
					</thead>
					<tbody>
						<?php foreach ( array_reverse( $log ) as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( isset( $entry['time'] ) ? (string) $entry['time'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $entry['hook'] ) ? (string) $entry['hook'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
					<?php wp_nonce_field( 'wmrb_clear_log' ); ?>
					<input type="hidden" name="action" value="wmrb_clear_log" />
					<?php submit_button( __( 'Clear log', 'wp-maxcache-rocket-bridge' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Rollout / rollback ràpid', 'wp-maxcache-rocket-bridge' ); ?></h2>
			<ol>
				<li><?php echo esc_html__( 'Instal·la en staging i valida diagnòstic.', 'wp-maxcache-rocket-bridge' ); ?></li>
				<li><?php echo esc_html__( 'Aplica snippet manualment al servidor i fes purga WP Rocket.', 'wp-maxcache-rocket-bridge' ); ?></li>
				<li><?php echo esc_html__( 'Valida capçaleres a origen amb curl.', 'wp-maxcache-rocket-bridge' ); ?></li>
				<li><?php echo esc_html__( 'Per rollback: restaura .htaccess backup i torna a purgar.', 'wp-maxcache-rocket-bridge' ); ?></li>
			</ol>
		</div>
		<script>
			(function() {
				const btn = document.getElementById('wmrb-copy-snippet');
				const ta = document.getElementById('wmrb-snippet');
				if (!btn || !ta || !navigator.clipboard) return;
				btn.addEventListener('click', function() {
					navigator.clipboard.writeText(ta.value);
				});
			})();
		</script>
		<?php
	}

	/**
	 * @param array<string,mixed> $checks
	 */
	private function render_checks_table( array $checks ) {
		$overall = isset( $checks['overall'] ) ? (string) $checks['overall'] : 'WARN';
		$rows    = isset( $checks['checks'] ) && is_array( $checks['checks'] ) ? $checks['checks'] : array();
		?>
		<p><strong><?php echo esc_html__( 'Overall:', 'wp-maxcache-rocket-bridge' ); ?></strong> <?php echo esc_html( $overall ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Check', 'wp-maxcache-rocket-bridge' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'wp-maxcache-rocket-bridge' ); ?></th>
					<th><?php echo esc_html__( 'Reason', 'wp-maxcache-rocket-bridge' ); ?></th>
					<th><?php echo esc_html__( 'Action', 'wp-maxcache-rocket-bridge' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $name => $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $name ); ?></td>
					<td><?php echo esc_html( isset( $row['status'] ) ? (string) $row['status'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $row['reason'] ) ? (string) $row['reason'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $row['action'] ) ? (string) $row['action'] : '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array<string,mixed>|null $tests
	 */
	private function render_quick_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			echo '<p>' . esc_html__( 'Encara no executat.', 'wp-maxcache-rocket-bridge' ) . '</p>';
			return;
		}

		$url = isset( $tests['url'] ) ? (string) $tests['url'] : '';
		echo '<p><strong>' . esc_html__( 'URL:', 'wp-maxcache-rocket-bridge' ) . '</strong> ' . esc_html( $url ) . '</p>';

		foreach ( array( 'identity', 'gzip' ) as $type ) {
			if ( ! isset( $tests[ $type ] ) || ! is_array( $tests[ $type ] ) ) {
				continue;
			}
			$result = $tests[ $type ];
			echo '<p><strong>' . esc_html( strtoupper( $type ) ) . ':</strong> ';
			echo esc_html( isset( $result['status'] ) ? (string) $result['status'] : '' );
			echo ' - ' . esc_html( isset( $result['details'] ) ? (string) $result['details'] : '' );
			echo '</p>';
		}

		echo '<p><em>' . esc_html__( 'Nota:', 'wp-maxcache-rocket-bridge' ) . '</em> ' . esc_html__( 'Cloudflare pot mostrar DYNAMIC i no és bloquejant si l’origen és bo.', 'wp-maxcache-rocket-bridge' ) . '</p>';
	}

	private function assert_permissions_and_nonce( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permisos insuficients.', 'wp-maxcache-rocket-bridge' ) );
		}
		check_admin_referer( $nonce_action );
	}
}
