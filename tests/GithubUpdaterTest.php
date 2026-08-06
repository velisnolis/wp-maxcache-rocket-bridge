<?php

use PHPUnit\Framework\TestCase;

final class GithubUpdaterTest extends TestCase {

	protected function setUp(): void {
		WMRB_Test_State::reset();
	}

	private function updater(): WMRB_Github_Updater {
		return new WMRB_Github_Updater( '/plugins/wp-maxcache-rocket-bridge/wp-maxcache-rocket-bridge.php' );
	}

	/**
	 * @param array<string,mixed> $release
	 */
	private function queue_release( array $release ): void {
		WMRB_Test_State::$remote_queue[] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $release ),
		);
	}

	private function transient(): stdClass {
		$transient          = new stdClass();
		$transient->checked = array( 'wp-maxcache-rocket-bridge/wp-maxcache-rocket-bridge.php' => WMRB_VERSION );
		$transient->response = array();

		return $transient;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function release_with_asset( string $tag ): array {
		return array(
			'tag_name' => $tag,
			'html_url' => 'https://github.com/velisnolis/wp-maxcache-rocket-bridge/releases/' . $tag,
			'body'     => 'Notes',
			'assets'   => array(
				array(
					'name'                 => 'wp-maxcache-rocket-bridge.zip',
					'browser_download_url' => 'https://example.test/' . $tag . '.zip',
				),
			),
		);
	}

	public function test_a_newer_release_is_offered(): void {
		$this->queue_release( $this->release_with_asset( 'v9.9.9' ) );

		$result = $this->updater()->check_for_updates( $this->transient() );

		$this->assertArrayHasKey( 'wp-maxcache-rocket-bridge/wp-maxcache-rocket-bridge.php', $result->response );
		$this->assertSame( '9.9.9', $result->response['wp-maxcache-rocket-bridge/wp-maxcache-rocket-bridge.php']->new_version );
	}

	public function test_an_older_release_is_ignored(): void {
		$this->queue_release( $this->release_with_asset( 'v0.0.1' ) );

		$this->assertSame( array(), $this->updater()->check_for_updates( $this->transient() )->response );
	}

	public function test_a_release_without_the_expected_asset_is_not_offered(): void {
		// The GitHub source archive is the repository, not the plugin: it
		// unpacks under a version-suffixed directory and carries tests and
		// build tooling. No asset must mean no update, not a broken one.
		$this->queue_release(
			array(
				'tag_name' => 'v9.9.9',
				'assets'   => array(
					array(
						'name'                 => 'Source code (zip)',
						'browser_download_url' => 'https://example.test/source.zip',
					),
				),
			)
		);

		$this->assertSame( array(), $this->updater()->check_for_updates( $this->transient() )->response );
	}

	public function test_a_failed_lookup_is_cached(): void {
		WMRB_Test_State::queue_response( 503, 'upstream is unwell' );

		$updater = $this->updater();
		$updater->check_for_updates( $this->transient() );
		$updater->check_for_updates( $this->transient() );
		$updater->check_for_updates( $this->transient() );

		$this->assertCount(
			1,
			WMRB_Test_State::$remote_calls,
			'A failing GitHub must not cost a blocking request on every update check'
		);
	}

	public function test_a_transport_error_is_cached(): void {
		WMRB_Test_State::queue_response( 'error' );

		$updater = $this->updater();
		$updater->check_for_updates( $this->transient() );
		$updater->check_for_updates( $this->transient() );

		$this->assertCount( 1, WMRB_Test_State::$remote_calls );
	}

	public function test_a_successful_lookup_is_cached(): void {
		$this->queue_release( $this->release_with_asset( 'v9.9.9' ) );

		$updater = $this->updater();
		$updater->check_for_updates( $this->transient() );
		$updater->check_for_updates( $this->transient() );

		$this->assertCount( 1, WMRB_Test_State::$remote_calls );
	}

	public function test_a_malformed_body_is_treated_as_a_failure(): void {
		WMRB_Test_State::$remote_queue[] = array(
			'response' => array( 'code' => 200 ),
			'body'     => 'not json at all',
		);

		$updater = $this->updater();

		$this->assertSame( array(), $updater->check_for_updates( $this->transient() )->response );
		$updater->check_for_updates( $this->transient() );
		$this->assertCount( 1, WMRB_Test_State::$remote_calls );
	}
}
