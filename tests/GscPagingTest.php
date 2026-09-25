<?php
/**
 * Search Console reports are paged past the per-request row limit, and a
 * path missing only because a period was cut short keeps its stored search
 * values instead of reading as zero clicks.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use DragonContentDecay\API_GSC;
use DragonContentDecay\OAuth;
use PHPUnit\Framework\TestCase;

final class GscPagingTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		update_option( 'dragoncontentdecay_gsc_enabled', 1 );
		update_option( 'dragoncontentdecay_gsc_property', 'sc-domain:example.test' );
		update_option( 'dragoncontentdecay_google_granted_scopes', OAuth::SCOPE_ANALYTICS . ' ' . OAuth::SCOPE_SEARCHCONSOLE );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	private static function row( string $path, int $clicks ): array {
		return array(
			'keys'        => array( 'https://example.test' . $path ),
			'clicks'      => $clicks,
			'impressions' => 100,
			'position'    => 2.0,
		);
	}

	/**
	 * A fake report of $total rows ordered by clicks.
	 */
	private static function runner( int $total, array &$calls ): callable {
		return static function ( int $start_row, int $limit ) use ( $total, &$calls ): array {
			$calls[] = array( $start_row, $limit );
			$rows    = array();
			for ( $i = $start_row; $i < min( $total, $start_row + $limit ); $i++ ) {
				$rows[] = self::row( '/p' . $i . '/', $total - $i );
			}
			return $rows;
		};
	}

	public function test_pages_until_a_short_page(): void {
		$calls  = array();
		$report = API_GSC::page_rows( self::runner( 5, $calls ), 2, 5 );

		$this->assertSame( array( array( 0, 2 ), array( 2, 2 ), array( 4, 2 ) ), $calls );
		$this->assertCount( 5, $report['rows'] );
		$this->assertFalse( $report['truncated'] );
	}

	public function test_an_exact_multiple_ends_on_an_empty_page(): void {
		$calls  = array();
		$report = API_GSC::page_rows( self::runner( 4, $calls ), 2, 5 );

		$this->assertCount( 4, $report['rows'] );
		$this->assertFalse( $report['truncated'] );
	}

	public function test_a_report_larger_than_the_page_cap_is_flagged(): void {
		$calls  = array();
		$report = API_GSC::page_rows( self::runner( 50, $calls ), 2, 3 );

		$this->assertCount( 3, $calls );
		$this->assertCount( 6, $report['rows'] );
		$this->assertTrue( $report['truncated'] );
	}

	public function test_a_row_repeated_on_a_later_page_is_counted_once(): void {
		$pages  = array(
			array( self::row( '/a/', 9 ), self::row( '/b/', 8 ) ),
			array( self::row( '/b/', 8 ) ),
		);
		$report = API_GSC::page_rows(
			static function ( int $start_row ) use ( $pages ): array {
				return $pages[ intdiv( $start_row, 2 ) ] ?? array();
			},
			2,
			5
		);

		$parsed = API_GSC::parse_rows( array( 'rows' => $report['rows'] ), array( 'example.test' ) );
		$this->assertSame( 8, $parsed['/b']['clicks'] );
	}

	public function test_a_failed_page_fails_the_period(): void {
		$report = API_GSC::page_rows(
			static function ( int $start_row ) {
				return 0 === $start_row ? array( self::row( '/a/', 1 ), self::row( '/b/', 1 ) ) : 'HTTP 500';
			},
			2,
			5
		);

		$this->assertSame( 'HTTP 500', $report );
	}

	/**
	 * API_GSC over a transport that serves $per_period rows for every period.
	 */
	private static function gsc( int $per_period, array &$bodies ): API_GSC {
		$oauth = new class() extends OAuth {
			public function get_access_token(): ?string {
				return 'token';
			}
		};

		return new API_GSC(
			$oauth,
			static function ( string $method, string $url, array $body ) use ( $per_period, &$bodies ): array {
				unset( $method, $url );
				$bodies[] = $body;
				$rows     = array();
				for ( $i = $body['startRow']; $i < min( $per_period, $body['startRow'] + $body['rowLimit'] ); $i++ ) {
					$rows[] = self::row( '/p' . $i . '/', 1 );
				}
				return array(
					'code' => 200,
					'body' => (string) wp_json_encode( $rows ? array( 'rows' => $rows ) : array() ),
				);
			}
		);
	}

	public function test_fetch_reads_past_the_first_page(): void {
		$bodies = array();
		$data   = self::gsc( API_GSC::PAGE_SIZE + 3, $bodies )->fetch_comparison_data( 30 );

		$this->assertSame( '', $data['error'] );
		$this->assertCount( API_GSC::PAGE_SIZE + 3, $data['current'] );
		$this->assertCount( API_GSC::PAGE_SIZE + 3, $data['previous'] );
		$this->assertSame( array( 0, API_GSC::PAGE_SIZE ), array( $bodies[0]['startRow'], $bodies[1]['startRow'] ) );
		$this->assertSame(
			array(
				'current'  => false,
				'previous' => false,
			),
			$data['truncated']
		);
	}

	public function test_fetch_flags_a_period_cut_short(): void {
		$bodies = array();
		$data   = self::gsc( API_GSC::PAGE_SIZE * API_GSC::MAX_PAGES + 1, $bodies )->fetch_comparison_data( 30 );

		$this->assertTrue( $data['truncated']['current'] );
		$this->assertTrue( $data['truncated']['previous'] );
		$this->assertCount( 2 * API_GSC::MAX_PAGES, $bodies );
	}

	/**
	 * Score /alpha/ and /beta/ with canned Search Console data.
	 */
	private static function analyze( array $gsc ): object {
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb(
			array(
				1 => true,
				2 => true,
			),
			array(
				'alpha' => 1,
				'beta'  => 2,
			)
		);

		$views = array(
			'pageviews'        => 5,
			'sessions'         => 5,
			'avg_time_on_page' => 1.0,
		);
		$api   = new class( $gsc ) extends API_GSC {
			private array $canned;

			public function __construct( array $canned ) {
				parent::__construct( AnalyzerTestSupport::oauth() );
				$this->canned = $canned;
			}

			public function fetch_comparison_data( int $period_days ): array {
				unset( $period_days );
				return $this->canned;
			}
		};

		$analyzer = new Analyzer(
			AnalyzerTestSupport::ga4(
				array(
					'current'  => array(
						'/alpha/' => $views,
						'/beta/'  => $views,
					),
					'previous' => array(
						'/alpha/' => $views,
						'/beta/'  => $views,
					),
				)
			),
			$api
		);
		$result   = $analyzer->analyze_all();
		$analyzer = null;

		return (object) array(
			'result' => $result,
			'db'     => $GLOBALS['wpdb'],
		);
	}

	public function test_a_path_missing_from_a_truncated_period_keeps_its_search_values(): void {
		$metrics = array(
			'clicks'      => 4,
			'impressions' => 40,
			'position'    => 3.0,
		);
		$run     = self::analyze(
			array(
				'current'   => array(
					'/alpha' => $metrics,
					'/beta'  => $metrics,
				),
				'previous'  => array( '/beta' => $metrics ),
				'error'     => '',
				'truncated' => array(
					'current'  => false,
					'previous' => true,
				),
			)
		);

		$this->assertSame( 2, $run->result['analyzed'] );
		$this->assertSame( array( 2 ), $run->db->replaced, 'a path in both periods is written in full' );
		$this->assertCount( 1, $run->db->queries );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $run->db->queries[0] );
		$update = substr( $run->db->queries[0], strpos( $run->db->queries[0], 'ON DUPLICATE KEY UPDATE' ) );
		$this->assertStringNotContainsString( 'search_', $update, '/alpha keeps its stored search values' );
	}

	public function test_a_path_missing_from_a_complete_period_reads_as_zero(): void {
		$metrics = array(
			'clicks'      => 4,
			'impressions' => 40,
			'position'    => 3.0,
		);
		$run     = self::analyze(
			array(
				'current'   => array(
					'/alpha' => $metrics,
					'/beta'  => $metrics,
				),
				'previous'  => array( '/beta' => $metrics ),
				'error'     => '',
				'truncated' => array(
					'current'  => false,
					'previous' => false,
				),
			)
		);

		$this->assertSame( array( 1, 2 ), $run->db->replaced );
		$this->assertSame( array(), $run->db->queries );
	}
}
