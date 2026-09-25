<?php
/**
 * A failed Search Console request is reported, and the stored search columns
 * are kept instead of being overwritten with zeros.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use DragonContentDecay\API_GSC;
use DragonContentDecay\OAuth;
use DragonContentDecay\Scheduler;
use PHPUnit\Framework\TestCase;

final class SearchConsoleFailureTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		update_option( 'dragoncontentdecay_gsc_enabled', 1 );
		update_option( 'dragoncontentdecay_gsc_property', 'sc-domain:example.test' );
		update_option( 'dragoncontentdecay_google_granted_scopes', OAuth::SCOPE_ANALYTICS . ' ' . OAuth::SCOPE_SEARCHCONSOLE );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	private static function token_oauth(): OAuth {
		return new class() extends OAuth {
			public function get_access_token(): ?string {
				return 'token';
			}
		};
	}

	/**
	 * @param array<int,array{code:int,body:string}> $responses One per request, in order.
	 */
	private static function gsc( array $responses ): API_GSC {
		$calls = 0;

		return new API_GSC(
			self::token_oauth(),
			static function () use ( $responses, &$calls ): array {
				return $responses[ $calls++ ] ?? array(
					'code' => 500,
					'body' => '',
				);
			}
		);
	}

	private static function ok( int $clicks ): array {
		return array(
			'code' => 200,
			'body' => (string) wp_json_encode(
				array(
					'rows' => array(
						array(
							'keys'        => array( 'https://example.test/alpha/' ),
							'clicks'      => $clicks,
							'impressions' => 100,
							'position'    => 3.0,
						),
					),
				)
			),
		);
	}

	public function test_one_failed_period_is_an_error(): void {
		$data = self::gsc(
			array(
				self::ok( 5 ),
				array(
					'code' => 500,
					'body' => '{"error":{"message":"Backend Error"}}',
				),
			)
		)->fetch_comparison_data( 30 );

		$this->assertNotSame( '', $data['error'] ?? '' );
	}

	public function test_an_unreadable_body_is_an_error(): void {
		$data = self::gsc(
			array(
				self::ok( 5 ),
				array(
					'code' => 200,
					'body' => '<html>gateway</html>',
				),
			)
		)->fetch_comparison_data( 30 );

		$this->assertNotSame( '', $data['error'] ?? '' );
	}

	public function test_no_token_is_an_error(): void {
		$data = ( new API_GSC( AnalyzerTestSupport::oauth() ) )->fetch_comparison_data( 30 );

		$this->assertNotSame( '', $data['error'] ?? '' );
	}

	public function test_no_property_is_an_error(): void {
		update_option( 'dragoncontentdecay_gsc_property', '' );

		$data = self::gsc( array( self::ok( 5 ), self::ok( 5 ) ) )->fetch_comparison_data( 30 );

		$this->assertNotSame( '', $data['error'] ?? '' );
	}

	public function test_two_good_periods_are_not_an_error(): void {
		$data = self::gsc( array( self::ok( 5 ), self::ok( 7 ) ) )->fetch_comparison_data( 30 );

		$this->assertSame( '', $data['error'] ?? '' );
		$this->assertSame( 5, $data['current']['/alpha']['clicks'] );
		$this->assertSame( 7, $data['previous']['/alpha']['clicks'] );
	}

	private static function run_with_gsc( API_GSC $gsc ): array {
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array(), array( 'alpha' => 1 ) );

		$analyzer = new Analyzer(
			AnalyzerTestSupport::ga4(
				array(
					'current'  => array(
						'/alpha/' => array( 'pageviews' => 5, 'sessions' => 5, 'avg_time_on_page' => 1.0 ),
					),
					'previous' => array(
						'/alpha/' => array( 'pageviews' => 10, 'sessions' => 10, 'avg_time_on_page' => 1.0 ),
					),
				)
			),
			$gsc
		);

		return $analyzer->analyze_all();
	}

	public function test_a_failed_search_fetch_keeps_the_stored_search_columns(): void {
		$result = self::run_with_gsc(
			self::gsc(
				array(
					self::ok( 5 ),
					array(
						'code' => 503,
						'body' => '',
					),
				)
			)
		);

		$this->assertSame( 1, $result['analyzed'], 'the GA4 score is still written' );
		$this->assertSame( array(), $GLOBALS['wpdb']->replaced, 'a full-row replace would zero the search columns' );

		$queries = self::writes( $GLOBALS['wpdb']->queries );
		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $queries[0] );
		$update = substr( $queries[0], strpos( $queries[0], 'ON DUPLICATE KEY UPDATE' ) );
		$this->assertStringNotContainsString( 'search_', $update, 'stored search values are left as they were' );
		$this->assertStringContainsString( 'pageviews_current', $update );
		$this->assertNotSame( '', $result['search_error'] ?? '' );
	}

	public function test_a_good_search_fetch_writes_the_search_columns(): void {
		$result = self::run_with_gsc( self::gsc( array( self::ok( 5 ), self::ok( 7 ) ) ) );

		$this->assertSame( 1, $result['analyzed'] );
		$this->assertSame( array( 1 ), $GLOBALS['wpdb']->replaced );
		$this->assertSame( '', $result['search_error'] ?? '' );
	}

	public function test_a_failed_keep_write_counts_as_failed(): void {
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array() );
		$GLOBALS['wpdb']->query_result = false;

		$analyzer = AnalyzerTestSupport::analyzer();

		$this->assertFalse( $analyzer->calculate_and_store_score( 7, 1, 2, null, true ) );
	}

	private function scheduler( array $outcome ): Scheduler {
		$analyzer = new class( $outcome ) extends Analyzer {
			private array $outcome;

			public function __construct( array $outcome ) {
				parent::__construct( AnalyzerTestSupport::ga4(), new API_GSC( AnalyzerTestSupport::oauth() ) );
				$this->outcome = $outcome;
			}

			public function analyze_all(): array {
				return $this->outcome;
			}
		};

		return new Scheduler( $analyzer );
	}

	public function test_scheduler_records_the_search_error_without_failing_the_sync(): void {
		$result = $this->scheduler(
			array(
				'analyzed'     => 3,
				'failed'       => 0,
				'cursor_saved' => true,
				'search_error' => 'Search Console request failed (HTTP 503).',
			)
		)->sync();

		$this->assertSame( 'complete', $result['status'] );
		$this->assertSame( 'Search Console request failed (HTTP 503).', get_option( 'dragoncontentdecay_last_search_error' ) );
		$this->assertFalse( get_option( 'dragoncontentdecay_last_sync_error' ) );

		$info = ( new Scheduler( AnalyzerTestSupport::analyzer() ) )->get_last_sync_info();
		$this->assertSame( 'Search Console request failed (HTTP 503).', $info['search_error'] );
	}

	public function test_a_clean_search_fetch_clears_the_old_search_error(): void {
		update_option( 'dragoncontentdecay_last_search_error', 'old' );

		$this->scheduler(
			array(
				'analyzed'     => 3,
				'failed'       => 0,
				'cursor_saved' => true,
			)
		)->sync();

		$this->assertFalse( get_option( 'dragoncontentdecay_last_search_error' ) );
	}

	/**
	 * Queries other than the end-of-pass prune of untracked rows.
	 *
	 * @param string[] $queries Recorded queries.
	 * @return string[]
	 */
	private static function writes( array $queries ): array {
		return array_values( preg_grep( '/^\s*DELETE/', $queries, PREG_GREP_INVERT ) );
	}
}
