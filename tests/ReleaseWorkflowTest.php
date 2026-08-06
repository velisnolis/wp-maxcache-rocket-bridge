<?php

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowTest extends TestCase {
	private string $workflow;

	protected function setUp(): void {
		parent::setUp();
		$this->workflow = (string) file_get_contents( dirname( __DIR__ ) . '/.github/workflows/release.yml' );
	}

	public function test_release_is_blocked_by_php_74_and_83_verification(): void {
		$this->assertStringContainsString( "php: ['7.4', '8.3']", $this->workflow );
		$this->assertStringContainsString( "if: matrix.php != '7.4'", $this->workflow );
		$this->assertStringContainsString( 'run: composer test', $this->workflow );
		$this->assertStringContainsString( 'needs: verify', $this->workflow );
	}

	public function test_tests_appear_before_release_publication(): void {
		$tests   = strpos( $this->workflow, 'run: composer test' );
		$publish = strpos( $this->workflow, 'gh release create' );

		$this->assertNotFalse( $tests );
		$this->assertNotFalse( $publish );
		$this->assertLessThan( $publish, $tests );
	}
}
