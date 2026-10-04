<?php
/**
 * A post with no views in either period keeps no score row once both reports
 * were read in full, so a decline that is over does not stay on the
 * dashboard and in the digest. Rows are kept whenever a report was partly
 * read, the fetch failed or the pass is unfinished.
 *
 * @package DragonContentDecay
 */

use PHPUnit\Framework\TestCase;

final class StaleScoreRowsTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	private static function rows( array $views ): array {
		$out = array();
		foreach ( $views as $path => $v ) {
			$out[ $path ] = array(
				'pageviews'        => $v,
				'sessions'         => $v,
				'avg_time_on_page' => 1.0,
			);
		}
		return $out;
	}

	/**
	 * A $wpdb double holding score rows for post 1 (fading) and post 2 (steady).
	 */
	private function seeded_wpdb(): object {
		$wpdb = AnalyzerTestSupport::wpdb(
			array(),
			array(
				'dying' => 1,
				'other' => 2,
			)
		);
		$GLOBALS['wpdb'] = $wpdb;

		AnalyzerTestSupport::analyzer(
			array(
				'current'  => self::rows( array( '/other' => 10 ) ),
				'previous' => self::rows(
					array(
						'/dying' => 5,
						'/other' => 10,
					)
				),
			)
		)->analyze_all();

		return $wpdb;
	}

	private function second_pass( array $comparison ): array {
		return AnalyzerTestSupport::analyzer( $comparison )->analyze_all();
	}

	public function test_a_post_gone_from_both_full_reports_loses_its_row(): void {
		$GLOBALS['wpdb'] = $this->seeded_wpdb();
		$this->assertSame( -100.0, $GLOBALS['wpdb']->rows[1]['decay_score'] );

		$result = $this->second_pass(
			array(
				'current'  => self::rows( array( '/other' => 10 ) ),
				'previous' => self::rows( array( '/other' => 10 ) ),
			)
		);

		$this->assertSame( 1, $result['analyzed'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertArrayNotHasKey( 1, $GLOBALS['wpdb']->rows );
		$this->assertArrayHasKey( 2, $GLOBALS['wpdb']->rows );
		$this->assertSame( array( 1 ), $GLOBALS['wpdb']->deleted );
	}

	public function test_two_empty_full_reports_clear_every_row(): void {
		$GLOBALS['wpdb'] = $this->seeded_wpdb();
		update_option( 'dragoncontentdecay_uncertain_posts', array( 2 ) );

		$result = $this->second_pass(
			array(
				'current'  => array(),
				'previous' => array(),
			)
		);

		$this->assertSame( 0, $result['analyzed'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows );
		$this->assertFalse( get_option( 'dragoncontentdecay_uncertain_posts' ) );
	}

	/**
	 * @dataProvider truncated_periods
	 */
	public function test_rows_are_kept_when_a_report_was_partly_read( array $truncated ): void {
		$GLOBALS['wpdb'] = $this->seeded_wpdb();

		$this->second_pass(
			array(
				'current'   => self::rows( array( '/other' => 10 ) ),
				'previous'  => self::rows( array( '/other' => 10 ) ),
				'truncated' => $truncated,
			)
		);

		$this->assertArrayHasKey( 1, $GLOBALS['wpdb']->rows );
		$this->assertSame( array(), $GLOBALS['wpdb']->deleted );
	}

	public static function truncated_periods(): array {
		return array(
			'current'  => array(
				array(
					'current'  => true,
					'previous' => false,
				),
			),
			'previous' => array(
				array(
					'current'  => false,
					'previous' => true,
				),
			),
		);
	}

	public function test_rows_are_kept_when_two_empty_reports_were_partly_read(): void {
		$GLOBALS['wpdb'] = $this->seeded_wpdb();

		$this->second_pass(
			array(
				'current'   => array(),
				'previous'  => array(),
				'truncated' => array(
					'current'  => true,
					'previous' => false,
				),
			)
		);

		$this->assertCount( 2, $GLOBALS['wpdb']->rows );
	}

	public function test_rows_are_kept_when_the_fetch_failed(): void {
		$GLOBALS['wpdb'] = $this->seeded_wpdb();

		$result = $this->second_pass( array( 'error' => 'Google could not be reached.' ) );

		$this->assertSame( 'Google could not be reached.', $result['error'] );
		$this->assertCount( 2, $GLOBALS['wpdb']->rows );
	}

	public function test_rows_are_kept_while_a_pass_is_unfinished(): void {
		$GLOBALS['wpdb'] = $this->seeded_wpdb();
		add_filter(
			'dragoncontentdecay_analyze_time_budget',
			static function () {
				return -1.0;
			}
		);

		$result = $this->second_pass(
			array(
				'current'  => self::rows(
					array(
						'/other' => 10,
						'/new-a' => 3,
					)
				),
				'previous' => self::rows( array( '/other' => 10 ) ),
			)
		);

		$this->assertTrue( $result['pending'] );
		$this->assertCount( 2, $GLOBALS['wpdb']->rows );
	}

	public function test_a_row_whose_write_failed_is_not_removed(): void {
		$seeded          = $this->seeded_wpdb();
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array( 2 => false ), array( 'other' => 2 ) );
		$GLOBALS['wpdb']->rows = $seeded->rows;

		$result = $this->second_pass(
			array(
				'current'  => self::rows( array( '/other' => 10 ) ),
				'previous' => self::rows( array( '/other' => 10 ) ),
			)
		);

		$this->assertSame( 1, $result['failed'] );
		$this->assertArrayHasKey( 2, $GLOBALS['wpdb']->rows );
		$this->assertArrayNotHasKey( 1, $GLOBALS['wpdb']->rows );
	}

	public function test_a_failed_delete_is_reported(): void {
		$GLOBALS['wpdb']                = $this->seeded_wpdb();
		$GLOBALS['wpdb']->delete_result = false;

		$result = $this->second_pass(
			array(
				'current'  => self::rows( array( '/other' => 10 ) ),
				'previous' => self::rows( array( '/other' => 10 ) ),
			)
		);

		$this->assertSame( 1, $result['analyzed'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertArrayHasKey( 1, $GLOBALS['wpdb']->rows );
	}
}
