<?php
/**
 * The screens that read GET parameters: tab routing, the notices on the
 * settings screen and the sort direction of the posts list. Each value is
 * read through a core sanitizer and anything unexpected falls back to the
 * default.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Admin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wporg-scan-stubs.php';

/**
 * Admin::render_admin_page(), the settings view and Admin::modify_query_for_decay_sort().
 */
final class WporgScanScreensTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		$GLOBALS['dragoncontentdecay_test_settings_errors'] = array();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['wpdb'] );
		dragoncontentdecay_test_reset();
	}

	/**
	 * An Admin that records which screen it rendered instead of printing it.
	 */
	private function routing_admin(): Admin {
		return new class( AnalyzerTestSupport::oauth(), AnalyzerTestSupport::analyzer() ) extends Admin {
			/**
			 * Screens rendered, in order.
			 *
			 * @var string[]
			 */
			public array $rendered = array();

			public function render_settings_page(): void {
				$this->rendered[] = 'settings';
			}

			public function render_dashboard_page(): void {
				$this->rendered[] = 'dashboard';
			}
		};
	}

	/**
	 * @dataProvider tabs
	 *
	 * @param array  $query    Query arguments.
	 * @param string $expected Screen rendered.
	 */
	public function test_tab_routing( array $query, string $expected ): void {
		$_GET  = $query;
		$admin = $this->routing_admin();

		$admin->render_admin_page();

		$this->assertSame( array( $expected ), $admin->rendered );
	}

	public static function tabs(): array {
		return array(
			'no tab'         => array( array(), 'dashboard' ),
			'dashboard'      => array( array( 'tab' => 'dashboard' ), 'dashboard' ),
			'settings'       => array( array( 'tab' => 'settings' ), 'settings' ),
			'unknown'        => array( array( 'tab' => 'licence' ), 'dashboard' ),
			'sent as a list' => array( array( 'tab' => array( 'settings' ) ), 'dashboard' ),
			'other case'     => array( array( 'tab' => 'Settings' ), 'dashboard' ),
		);
	}

	public function test_the_screen_is_refused_without_the_capability(): void {
		$GLOBALS['dragoncontentdecay_test_can'] = false;
		$_GET                                   = array( 'tab' => 'settings' );
		$admin                                  = $this->routing_admin();

		try {
			$admin->render_admin_page();
			$this->fail( 'the screen rendered' );
		} catch ( DragonContentDecay_Test_Die $die ) {
			$this->assertSame( 'You do not have permission to access this page.', $die->getMessage() );
		}

		$this->assertSame( array(), $admin->rendered );
	}

	/**
	 * The real settings screen for a query string.
	 *
	 * @param array $query Query arguments.
	 */
	private function settings_screen( array $query ): string {
		$_GET = $query;
		ob_start();
		try {
			( new Admin( AnalyzerTestSupport::oauth(), AnalyzerTestSupport::analyzer() ) )->render_settings_page();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	public function test_settings_screen_notices_follow_the_query_flags(): void {
		$plain = $this->settings_screen( array() );
		$this->assertStringNotContainsString( 'Successfully connected', $plain );
		$this->assertStringNotContainsString( 'Disconnected from Google Analytics.', $plain );
		$this->assertStringNotContainsString( 'notice-error', $plain );

		$this->assertStringContainsString( 'Successfully connected to Google Analytics!', $this->settings_screen( array( 'connected' => '1' ) ) );
		$this->assertStringContainsString( 'Disconnected from Google Analytics.', $this->settings_screen( array( 'disconnected' => '1' ) ) );
		$this->assertStringContainsString( 'Google sign-in could not be verified', $this->settings_screen( array( 'oauth_error' => 'state' ) ) );
		$this->assertStringContainsString( 'Google sign-in did not complete', $this->settings_screen( array( 'oauth_error' => 'callback' ) ) );
		$this->assertStringContainsString( 'Google sign-in was cancelled', $this->settings_screen( array( 'oauth_error' => 'denied' ) ) );
	}

	/**
	 * @dataProvider unknown_error_flags
	 *
	 * @param mixed $flag oauth_error value.
	 */
	public function test_an_unknown_error_flag_shows_no_notice( $flag ): void {
		$html = $this->settings_screen( array( 'oauth_error' => $flag ) );

		$this->assertStringNotContainsString( 'notice-error', $html );
		$this->assertStringNotContainsString( 'alert(', $html );
	}

	public static function unknown_error_flags(): array {
		return array(
			'unknown'        => array( 'nonsense' ),
			'markup'         => array( '<script>alert(1)</script>' ),
			'sent as a list' => array( array( 'state' ) ),
			'empty'          => array( '' ),
		);
	}

	/**
	 * @dataProvider sort_orders
	 *
	 * @param array  $query    Query arguments.
	 * @param string $expected SQL direction.
	 */
	public function test_sort_direction( array $query, string $expected ): void {
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array() );
		$_GET            = $query;
		$admin           = new Admin( AnalyzerTestSupport::oauth(), AnalyzerTestSupport::analyzer() );

		$clauses = $admin->modify_query_for_decay_sort(
			array(
				'join'    => '',
				'orderby' => '',
			)
		);

		$this->assertSame( 'dcd_scores.decay_score IS NULL, dcd_scores.decay_score ' . $expected, $clauses['orderby'] );
		$this->assertSame( ' LEFT JOIN wp_dcd_scores AS dcd_scores ON wp_posts.ID = dcd_scores.post_id', $clauses['join'] );
	}

	public static function sort_orders(): array {
		return array(
			'missing'        => array( array(), 'DESC' ),
			'asc'            => array( array( 'order' => 'asc' ), 'ASC' ),
			'ASC'            => array( array( 'order' => 'ASC' ), 'ASC' ),
			'desc'           => array( array( 'order' => 'desc' ), 'DESC' ),
			'unknown'        => array( array( 'order' => 'sideways' ), 'DESC' ),
			'injection'      => array( array( 'order' => 'asc; DROP TABLE wp_posts' ), 'DESC' ),
			'sent as a list' => array( array( 'order' => array( 'asc' ) ), 'DESC' ),
		);
	}
}
