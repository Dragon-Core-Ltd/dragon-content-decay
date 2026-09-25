<?php
/**
 * Score writes: a failed $wpdb->replace() is reported, not counted as analysed.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use PHPUnit\Framework\TestCase;

final class AnalyzerStoreTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['dragoncontentdecay_test_options_readonly'] = false;
	}

	public function test_store_score_returns_false_when_replace_fails(): void {
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array( 7 => false ) );

		$analyzer = AnalyzerTestSupport::analyzer();

		$this->assertFalse( $analyzer->calculate_and_store_score( 7, 1, 2 ) );
		$this->assertTrue( $analyzer->calculate_and_store_score( 8, 1, 2 ) );
	}

	private function two_post_run( array $replace_results ): array {
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb(
			$replace_results,
			array(
				'alpha' => 1,
				'beta'  => 2,
			)
		);

		return AnalyzerTestSupport::analyzer(
			array(
				'current' => array(
					'/alpha/' => array( 'pageviews' => 5, 'sessions' => 5, 'avg_time_on_page' => 1.0 ),
					'/beta/'  => array( 'pageviews' => 5, 'sessions' => 5, 'avg_time_on_page' => 1.0 ),
				),
			)
		)->analyze_all();
	}

	public function test_analyze_all_counts_failed_writes_separately(): void {
		$result = $this->two_post_run( array( 2 => false ) );

		$this->assertSame( 1, $result['analyzed'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertTrue( $result['cursor_saved'] );
	}

	private static function out_of_time(): void {
		add_filter(
			'dragoncontentdecay_analyze_time_budget',
			static function () {
				return 0.0;
			}
		);
	}

	public function test_analyze_all_reports_resolutions_that_did_not_persist(): void {
		self::out_of_time();
		$GLOBALS['dragoncontentdecay_test_options_readonly'] = true;

		$result = $this->two_post_run( array() );

		$this->assertTrue( $result['pending'] );
		$this->assertSame( 0, $result['analyzed'] );
		$this->assertFalse( $result['cursor_saved'] );
	}

	public function test_a_finished_pass_forgets_its_resolutions(): void {
		update_option( 'dragoncontentdecay_resolved_paths', array( '/alpha' => 1 ) );

		$result = $this->two_post_run( array() );

		$this->assertSame( 2, $result['analyzed'] );
		$this->assertFalse( get_option( 'dragoncontentdecay_resolved_paths' ) );
	}
}
