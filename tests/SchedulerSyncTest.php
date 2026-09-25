<?php
/**
 * Sync outcome: write failures surface as a partial or failed sync.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use DragonContentDecay\Scheduler;
use PHPUnit\Framework\TestCase;

final class SchedulerSyncTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['dragoncontentdecay_test_options']          = array();
		$GLOBALS['dragoncontentdecay_test_options_readonly'] = false;
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

	public function test_clean_run_is_complete(): void {
		$result = $this->scheduler( array( 'analyzed' => 3, 'failed' => 0, 'cursor_saved' => true ) )->sync();

		$this->assertSame( 'complete', $result['status'] );
		$this->assertSame( 3, $result['analyzed'] );
		$this->assertSame( 0, $result['failed'] );
		$this->assertSame( 'complete', get_option( 'dragoncontentdecay_last_sync_status' ) );
		$this->assertSame( 0, get_option( 'dragoncontentdecay_last_sync_failed' ) );
	}

	public function test_some_failed_writes_make_the_sync_partial(): void {
		$result = $this->scheduler( array( 'analyzed' => 2, 'failed' => 1, 'cursor_saved' => true ) )->sync();

		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( 1, $result['failed'] );
		$this->assertSame( 'partial', get_option( 'dragoncontentdecay_last_sync_status' ) );
		$this->assertSame( 1, get_option( 'dragoncontentdecay_last_sync_failed' ) );
	}

	public function test_all_failed_writes_make_the_sync_failed(): void {
		$result = $this->scheduler( array( 'analyzed' => 0, 'failed' => 4, 'cursor_saved' => true ) )->sync();

		$this->assertSame( 'failed', $result['status'] );
		$this->assertSame( 'failed', get_option( 'dragoncontentdecay_last_sync_status' ) );
	}

	public function test_unsaved_cursor_makes_the_sync_partial(): void {
		$result = $this->scheduler( array( 'analyzed' => 3, 'failed' => 0, 'cursor_saved' => false ) )->sync();

		$this->assertSame( 'partial', $result['status'] );
	}

	public function test_a_pending_manual_sync_is_reported_as_pending_not_complete(): void {
		$scheduler = $this->scheduler( array() );

		$reply = $scheduler->manual_sync_reply(
			array(
				'analyzed' => 0,
				'failed'   => 0,
				'synced'   => 0,
				'status'   => 'complete',
				'pending'  => true,
			)
		);

		$this->assertTrue( $reply['success'] );
		$this->assertTrue( $reply['data']['pending'] );
		$this->assertStringContainsString( 'carries on automatically', $reply['data']['message'] );
	}

	public function test_a_finished_manual_sync_is_not_pending(): void {
		$reply = $this->scheduler( array() )->manual_sync_reply(
			array(
				'analyzed' => 3,
				'failed'   => 0,
				'synced'   => 3,
				'status'   => 'complete',
			)
		);

		$this->assertTrue( $reply['success'] );
		$this->assertFalse( $reply['data']['pending'] );
	}
}
