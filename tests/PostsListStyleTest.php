<?php
/**
 * The posts list loads the stylesheet that colours the Decay column, and
 * nothing else of the plugin's admin assets.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Admin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wporg-scan-stubs.php';

final class PostsListStyleTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		$GLOBALS['dragoncontentdecay_test_styles']  = array();
		$GLOBALS['dragoncontentdecay_test_scripts'] = array();
	}

	private function enqueue( string $hook ): void {
		( new Admin( AnalyzerTestSupport::oauth(), AnalyzerTestSupport::analyzer() ) )->enqueue_assets( $hook );
	}

	public function test_the_posts_list_gets_the_column_styles_only(): void {
		$this->enqueue( 'edit.php' );

		$this->assertSame( array( 'dcd-admin' ), array_keys( $GLOBALS['dragoncontentdecay_test_styles'] ) );
		$this->assertStringEndsWith( 'admin/css/admin.css', $GLOBALS['dragoncontentdecay_test_styles']['dcd-admin']['src'] );
		$this->assertSame( array(), $GLOBALS['dragoncontentdecay_test_styles']['dcd-admin']['deps'] );
		$this->assertSame( array(), $GLOBALS['dragoncontentdecay_test_scripts'] );
	}

	public function test_the_plugin_screen_keeps_all_its_assets(): void {
		$this->enqueue( 'tools_page_dragon-content-decay' );

		$this->assertSame( array( 'dragon-content-decay-dragon-ui', 'dcd-admin' ), array_keys( $GLOBALS['dragoncontentdecay_test_styles'] ) );
		$this->assertSame( array( 'dcd-admin' ), array_keys( $GLOBALS['dragoncontentdecay_test_scripts'] ) );
		$this->assertArrayHasKey( 'dcdAdmin', $GLOBALS['dragoncontentdecay_test_scripts']['dcd-admin']['data'] );
	}

	public function test_other_screens_get_nothing(): void {
		$this->enqueue( 'index.php' );
		$this->enqueue( 'post.php' );

		$this->assertSame( array(), $GLOBALS['dragoncontentdecay_test_styles'] );
		$this->assertSame( array(), $GLOBALS['dragoncontentdecay_test_scripts'] );
	}
}
