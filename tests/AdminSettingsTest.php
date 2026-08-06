<?php

use PHPUnit\Framework\TestCase;

final class AdminSettingsTest extends TestCase {
	protected function setUp(): void {
		WMRB_Test_State::reset();
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	/**
	 * @param array<string,mixed> $options
	 */
	private function admin_page( array $options ): WMRB_Admin_Page {
		$snippet = new WMRB_Snippet_Service( $options );
		$manager = new WMRB_Sync_Manager( $snippet, $options );

		return new WMRB_Admin_Page(
			new WMRB_Diagnostics_Service( $options ),
			$snippet,
			new WMRB_Quick_Test_Service(),
			new WMRB_Purge_Observer( $options ),
			$manager,
			$options
		);
	}

	public function test_one_submission_saves_all_cache_settings_and_preserves_unrelated_options(): void {
		$options = array_merge(
			WMRB_Plugin::default_options(),
			array(
				'auto_sync_enabled'          => false,
				'serve_gzip_variant'         => true,
				'custom_cache_path_template' => '/custom/{HTTP_HOST}/index.html',
			)
		);
		WMRB_Test_State::$options[ WMRB_Plugin::OPTION_KEY ] = $options;
		$_POST = array(
			'auto_sync_enabled'     => '1',
			'serve_bot_user_agents' => '1',
			'serve_webp_variant'    => '1',
		);

		$this->admin_page( $options )->handle_save_settings();

		$saved = WMRB_Test_State::$options[ WMRB_Plugin::OPTION_KEY ];
		$this->assertTrue( $saved['auto_sync_enabled'] );
		$this->assertFalse( $saved['auto_apply_htaccess'] );
		$this->assertTrue( $saved['serve_bot_user_agents'] );
		$this->assertFalse( $saved['serve_gzip_variant'] );
		$this->assertTrue( $saved['serve_webp_variant'] );
		$this->assertSame( '/custom/{HTTP_HOST}/index.html', $saved['custom_cache_path_template'] );
		$this->assertSame( 1, WMRB_Test_State::writes( WMRB_Plugin::OPTION_KEY ) );
		$this->assertSame( array( 'wmrb_save_settings' ), WMRB_Test_State::$checked_nonces );
		$this->assertSame(
			array( 'https://example.test/wp-admin/tools.php?page=wmrb-bridge&wmrb=settings-updated' ),
			WMRB_Test_State::$redirects
		);
	}

	public function test_the_admin_screen_renders_one_form_for_all_cache_settings(): void {
		$options = array_merge(
			WMRB_Plugin::default_options(),
			array( 'auto_sync_enabled' => false )
		);

		ob_start();
		$this->admin_page( $options )->render_page();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, preg_match( '#<form(?:(?!</form>).)*name="action" value="wmrb_save_settings"(?:(?!</form>).)*</form>#s', $html, $match ) );
		foreach ( array( 'auto_sync_enabled', 'auto_apply_htaccess', 'serve_bot_user_agents', 'serve_gzip_variant', 'serve_webp_variant' ) as $setting ) {
			$this->assertStringContainsString( 'name="' . $setting . '"', $match[0] );
		}
		$this->assertStringNotContainsString( 'value="wmrb_toggle_', $html );
		$this->assertSame( 1, substr_count( $match[0], 'type="submit"' ) );
	}

	public function test_a_snippet_setting_change_refreshes_state_with_the_new_fingerprint(): void {
		$options = array_merge(
			WMRB_Plugin::default_options(),
			array(
				'auto_sync_enabled'  => false,
				'auto_apply_htaccess' => false,
			)
		);
		WMRB_Test_State::$options[ WMRB_Plugin::OPTION_KEY ] = $options;

		$old_snippet = new WMRB_Snippet_Service( $options );
		$old_manager = new WMRB_Sync_Manager( $old_snippet, $options );
		$old_state   = $old_manager->mark_applied();

		$_POST = array( 'serve_bot_user_agents' => '1' );
		$this->admin_page( $options )->handle_save_settings();

		$saved    = WMRB_Test_State::$options[ WMRB_Plugin::OPTION_KEY ];
		$expected = ( new WMRB_Snippet_Service( $saved ) )->get_sync_fingerprint();
		$state    = WMRB_Test_State::$options[ WMRB_Sync_Manager::STATE_OPTION_KEY ];

		$this->assertSame( 'pending_apply', $state['status'] );
		$this->assertSame( $expected, $state['current_hash'] );
		$this->assertSame( $old_state['last_applied_hash'], $state['last_applied_hash'] );
		$this->assertNotSame( $state['current_hash'], $state['last_applied_hash'] );
	}

	public function test_saving_settings_does_not_register_a_second_sync_manager(): void {
		$options = array_merge(
			WMRB_Plugin::default_options(),
			array( 'auto_sync_enabled' => false )
		);
		WMRB_Test_State::$options[ WMRB_Plugin::OPTION_KEY ] = $options;
		$_POST = array(
			'auto_sync_enabled'     => '1',
			'serve_gzip_variant'    => '1',
		);
		$page = $this->admin_page( $options );

		$this->assertCount( 1, WMRB_Test_State::$actions['update_option_wp_rocket_settings'] );
		$page->handle_save_settings();

		$this->assertCount( 1, WMRB_Test_State::$actions['update_option_wp_rocket_settings'] );
	}
}
