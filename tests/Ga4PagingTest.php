<?php
/**
 * GA4 reports are paged past the per-request row limit, and a path missing
 * only because a period's report was cut short is never scored.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\API_GA4;
use PHPUnit\Framework\TestCase;

final class Ga4PagingTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * A fake report of $total rows ordered by views, served $cap rows at most
	 * per request.
	 */
	private static function runner( int $total, array &$calls, int $cap = PHP_INT_MAX ): callable {
		return static function ( int $offset, int $limit ) use ( $total, &$calls, $cap ): array {
			$calls[] = array( $offset, $limit );
			$rows    = array();
			$end     = min( $total, $offset + min( $limit, $cap ) );
			for ( $i = $offset; $i < $end; $i++ ) {
				$rows[ '/p' . $i . '/' ] = array(
					'pageviews'        => $total - $i,
					'sessions'         => 1,
					'avg_time_on_page' => 1.0,
				);
			}
			return array(
				'rows'      => $rows,
				'row_count' => $total,
			);
		};
	}

	public function test_every_page_is_read_until_row_count(): void {
		$calls  = array();
		$report = API_GA4::page_report( self::runner( 25, $calls ), 10, 5 );

		$this->assertCount( 25, $report['rows'] );
		$this->assertFalse( $report['truncated'] );
		$this->assertSame( array( array( 0, 10 ), array( 10, 10 ), array( 20, 10 ) ), $calls );
	}

	public function test_offset_advances_by_the_rows_actually_returned(): void {
		$calls  = array();
		$report = API_GA4::page_report( self::runner( 25, $calls, 7 ), 10, 10 );

		$this->assertCount( 25, $report['rows'] );
		$this->assertSame( array( 0, 7, 14, 21 ), array_column( $calls, 0 ) );
		$this->assertFalse( $report['truncated'] );
	}

	public function test_a_report_larger_than_the_page_cap_is_marked_truncated(): void {
		$calls  = array();
		$report = API_GA4::page_report( self::runner( 100, $calls ), 10, 3 );

		$this->assertCount( 30, $report['rows'] );
		$this->assertTrue( $report['truncated'] );
		$this->assertCount( 3, $calls );
	}

	public function test_an_empty_page_ends_the_loop(): void {
		$calls  = array();
		$runner = static function ( int $offset, int $limit ) use ( &$calls ): array {
			$calls[] = $offset;
			unset( $limit );
			return array(
				'rows'      => 0 === $offset ? array( '/a/' => array( 'pageviews' => 1 ) ) : array(),
				'row_count' => 50,
			);
		};

		$report = API_GA4::page_report( $runner, 10, 5 );

		$this->assertSame( array( 0, 1 ), $calls );
		$this->assertTrue( $report['truncated'], 'fewer rows than rowCount reported is an incomplete report' );
	}

	public function test_a_path_absent_only_from_a_truncated_period_is_not_scored(): void {
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb(
			array(),
			array(
				'kept'  => 1,
				'cut'   => 2,
				'fresh' => 3,
			)
		);

		$result = AnalyzerTestSupport::analyzer(
			array(
				'current'   => array(
					'/kept/'  => array( 'pageviews' => 50, 'sessions' => 1, 'avg_time_on_page' => 1.0 ),
					'/cut/'   => array( 'pageviews' => 2, 'sessions' => 1, 'avg_time_on_page' => 1.0 ),
				),
				'previous'  => array(
					'/kept/'  => array( 'pageviews' => 60, 'sessions' => 1, 'avg_time_on_page' => 1.0 ),
					'/fresh/' => array( 'pageviews' => 40, 'sessions' => 1, 'avg_time_on_page' => 1.0 ),
				),
				// The previous period's report was cut short; the current one was not.
				'truncated' => array(
					'current'  => false,
					'previous' => true,
				),
			)
		)->analyze_all();

		// /cut/ has no previous-period row only because that report was cut
		// short, so it would read as +100%. /fresh/ is missing from the
		// complete current report, so it really had no views.
		$this->assertSame( array( 1, 3 ), $GLOBALS['wpdb']->replaced );
		$this->assertSame( 2, $result['analyzed'] );
	}
}
