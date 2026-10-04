<?php
/**
 * The manual sync AJAX action: its nonce and the capability are checked in
 * the handler before anything runs, and the reply keeps its shape.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use DragonContentDecay\Scheduler;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wporg-scan-stubs.php';

/**
 * Scheduler::handle_manual_sync().
 */
final class WporgScanAjaxTest extends TestCase {

	private const ACTION = 'dragoncontentdecay_admin_nonce';

	/**
	 * The analyzer double behind the scheduler under test.
	 *
	 * @var object|null
	 */
	private ?object $analyzer = null;

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		$GLOBALS['dragoncontentdecay_test_nonces'] = array(
			self::ACTION                       => 'ajax-nonce',
			'dragoncontentdecay_save_settings' => 'settings-nonce',
		);
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		dragoncontentdecay_test_reset();
	}

	/**
	 * A scheduler whose analyzer returns a canned outcome and counts its runs.
	 *
	 * @param array $outcome Outcome of analyze_all().
	 */
	private function scheduler( array $outcome ): Scheduler {
		$analyzer = new class( $outcome ) extends Analyzer {
			/**
			 * Times analyze_all() ran.
			 *
			 * @var int
			 */
			public int $runs = 0;

			private array $outcome;

			public function __construct( array $outcome ) {
				parent::__construct( AnalyzerTestSupport::ga4(), new \DragonContentDecay\API_GSC( AnalyzerTestSupport::oauth() ) );
				$this->outcome = $outcome;
			}

			public function analyze_all(): array {
				++$this->runs;
				return $this->outcome;
			}
		};

		$this->analyzer = $analyzer;

		return new Scheduler( $analyzer );
	}

	/**
	 * Post the AJAX fields the admin script sends.
	 *
	 * @param array $fields Fields.
	 */
	private function post( array $fields ): void {
		$fields  += array( 'action' => 'dragoncontentdecay_manual_sync' );
		$_POST    = $fields;
		$_REQUEST = $fields;
	}

	/**
	 * Run the handler and return what ended the request.
	 *
	 * @param Scheduler $scheduler Scheduler.
	 * @return DragonContentDecay_Test_Json|DragonContentDecay_Test_Die|null
	 */
	private function run_handler( Scheduler $scheduler ) {
		try {
			$scheduler->handle_manual_sync();
		} catch ( DragonContentDecay_Test_Json $json ) {
			return $json;
		} catch ( DragonContentDecay_Test_Die $die ) {
			return $die;
		}
		return null;
	}

	private function clean_outcome(): array {
		return array(
			'analyzed'     => 3,
			'failed'       => 0,
			'cursor_saved' => true,
		);
	}

	public function test_a_valid_request_runs_the_sync_and_reports_it(): void {
		$scheduler = $this->scheduler( $this->clean_outcome() );
		$this->post( array( 'nonce' => 'ajax-nonce' ) );

		$reply = $this->run_handler( $scheduler );

		$this->assertInstanceOf( DragonContentDecay_Test_Json::class, $reply );
		$this->assertTrue( $reply->success );
		$this->assertSame( 'Sync complete. Analyzed 3 posts.', $reply->data['message'] );
		$this->assertSame( 3, $reply->data['analyzed'] );
		$this->assertSame( 3, $reply->data['synced'] );
		$this->assertFalse( $reply->data['pending'] );
		$this->assertSame( 1, $this->analyzer->runs );
		$this->assertSame( 'complete', get_option( 'dragoncontentdecay_last_sync_status' ) );
	}

	public function test_a_failed_fetch_is_reported_as_an_error(): void {
		$scheduler = $this->scheduler( array( 'error' => 'No GA4 property ID is set on the Settings tab.' ) );
		$this->post( array( 'nonce' => 'ajax-nonce' ) );

		$reply = $this->run_handler( $scheduler );

		$this->assertInstanceOf( DragonContentDecay_Test_Json::class, $reply );
		$this->assertFalse( $reply->success );
		$this->assertSame( 'Sync failed, so no scores were changed. No GA4 property ID is set on the Settings tab.', $reply->data['message'] );
		$this->assertSame( 'failed', $reply->data['status'] );
	}

	/**
	 * @dataProvider bad_nonces
	 *
	 * @param array $fields Posted fields.
	 */
	public function test_a_request_without_a_valid_nonce_is_refused( array $fields ): void {
		$scheduler = $this->scheduler( $this->clean_outcome() );
		$before    = $GLOBALS['dragoncontentdecay_test_options'];
		$this->post( $fields );

		$reply = $this->run_handler( $scheduler );

		$this->assertInstanceOf( DragonContentDecay_Test_Die::class, $reply );
		$this->assertSame( 403, $reply->status );
		$this->assertSame( '-1', $reply->getMessage() );
		$this->assertSame( 0, $this->analyzer->runs );
		$this->assertSame( $before, $GLOBALS['dragoncontentdecay_test_options'] );
	}

	public static function bad_nonces(): array {
		return array(
			'missing'            => array( array() ),
			'wrong value'        => array( array( 'nonce' => 'not-the-nonce' ) ),
			'another action'     => array( array( 'nonce' => 'settings-nonce' ) ),
			'empty'              => array( array( 'nonce' => '' ) ),
			'sent as a list'     => array( array( 'nonce' => array( 'ajax-nonce' ) ) ),
			'in the wrong field' => array( array( 'security' => 'ajax-nonce' ) ),
		);
	}

	public function test_a_user_without_the_capability_is_refused(): void {
		$scheduler                              = $this->scheduler( $this->clean_outcome() );
		$before                                 = $GLOBALS['dragoncontentdecay_test_options'];
		$GLOBALS['dragoncontentdecay_test_can'] = false;
		$this->post( array( 'nonce' => 'ajax-nonce' ) );

		$reply = $this->run_handler( $scheduler );

		$this->assertInstanceOf( DragonContentDecay_Test_Json::class, $reply );
		$this->assertFalse( $reply->success );
		$this->assertSame( array( 'message' => 'Permission denied.' ), $reply->data );
		$this->assertSame( 0, $this->analyzer->runs );
		$this->assertSame( $before, $GLOBALS['dragoncontentdecay_test_options'] );
	}
}
