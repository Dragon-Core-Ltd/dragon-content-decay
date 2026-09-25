<?php
/**
 * A failed GA4 request aborts the sync instead of being read as zero views.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use DragonContentDecay\API_GA4;
use DragonContentDecay\Scheduler;
use PHPUnit\Framework\TestCase;

final class SyncErrorTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_missing_property_id_is_an_error_not_empty_data(): void {
		$data = ( new API_GA4( AnalyzerTestSupport::oauth() ) )->fetch_comparison_data( 30 );

		$this->assertSame( array(), $data['current'] );
		$this->assertNotSame( '', $data['error'] ?? '' );
	}

	public function test_unconnected_client_is_an_error_not_empty_data(): void {
		update_option( 'dragoncontentdecay_ga4_property_id', '123456' );

		$data = ( new API_GA4( AnalyzerTestSupport::oauth() ) )->fetch_comparison_data( 30 );

		$this->assertStringContainsString( 'not connected', $data['error'] ?? '' );
	}

	public function test_revoked_access_says_to_reconnect(): void {
		update_option( 'dragoncontentdecay_ga4_property_id', '123456' );
		update_option( 'dragoncontentdecay_google_auth_revoked', 1 );

		$data = ( new API_GA4( AnalyzerTestSupport::oauth() ) )->fetch_comparison_data( 30 );

		$this->assertStringContainsString( 'no longer accepts', $data['error'] );
	}

	public function test_one_failed_period_writes_no_scores(): void {
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array(), array( 'alpha' => 1 ) );
		update_option( 'dragoncontentdecay_analyze_cursor', 0 );

		// The current period came back; the previous-period request failed.
		$result = AnalyzerTestSupport::analyzer(
			array(
				'current'  => array(
					'/alpha/' => array( 'pageviews' => 5, 'sessions' => 5, 'avg_time_on_page' => 1.0 ),
				),
				'previous' => array(),
				'error'    => 'Google Analytics request failed: quota exceeded',
			)
		)->analyze_all();

		$this->assertSame( array(), $GLOBALS['wpdb']->replaced, 'no score row may be overwritten' );
		$this->assertSame( 0, $result['analyzed'] );
		$this->assertSame( 'Google Analytics request failed: quota exceeded', $result['error'] );
		$this->assertSame( 0, get_option( 'dragoncontentdecay_analyze_cursor' ) );
	}

	private function scheduler( array $outcome ): Scheduler {
		$analyzer = new class( $outcome ) extends Analyzer {
			private array $outcome;

			public function __construct( array $outcome ) {
				parent::__construct( AnalyzerTestSupport::ga4(), new \DragonContentDecay\API_GSC( AnalyzerTestSupport::oauth() ) );
				$this->outcome = $outcome;
			}

			public function analyze_all(): array {
				return $this->outcome;
			}
		};

		return new Scheduler( $analyzer );
	}

	public function test_fetch_error_records_a_failed_sync_with_its_reason(): void {
		update_option( 'dragoncontentdecay_last_sync', 1000 );
		update_option( 'dragoncontentdecay_last_sync_count', 12 );

		$result = $this->scheduler(
			array(
				'analyzed'     => 0,
				'failed'       => 0,
				'cursor_saved' => true,
				'error'        => 'Google Analytics is not connected.',
			)
		)->sync();

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'Google Analytics is not connected.', $result['error'] );
		$this->assertSame( 'failed', get_option( 'dragoncontentdecay_last_sync_status' ) );
		$this->assertSame( 'Google Analytics is not connected.', get_option( 'dragoncontentdecay_last_sync_error' ) );
		// The last good sync is still the one the dashboard reports.
		$this->assertSame( 1000, get_option( 'dragoncontentdecay_last_sync' ) );
		$this->assertSame( 12, get_option( 'dragoncontentdecay_last_sync_count' ) );

		$info = ( new Scheduler( AnalyzerTestSupport::analyzer() ) )->get_last_sync_info();
		$this->assertSame( 'Google Analytics is not connected.', $info['error'] );
	}

	public function test_a_clean_sync_clears_the_previous_error(): void {
		update_option( 'dragoncontentdecay_last_sync_error', 'old failure' );

		$result = $this->scheduler(
			array(
				'analyzed'     => 2,
				'failed'       => 0,
				'cursor_saved' => true,
			)
		)->sync();

		$this->assertSame( 'complete', $result['status'] );
		$this->assertFalse( get_option( 'dragoncontentdecay_last_sync_error' ) );
	}
}
