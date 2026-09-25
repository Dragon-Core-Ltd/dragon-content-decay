<?php
/**
 * Failed Google requests are shown as plain messages, with the raw text kept
 * as separate detail; sync notices only mention a previous sync when one
 * succeeded; the first-run card is honest about the setup.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use DragonContentDecay\API_GSC;
use DragonContentDecay\Error_Text;
use DragonContentDecay\OAuth;
use DragonContentDecay\Scheduler;
use PHPUnit\Framework\TestCase;

final class UserFacingErrorsTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:int,3:string}>
	 */
	public static function failures(): array {
		return array(
			'curl timeout'          => array( 'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received', '', -1, Error_Text::CONNECTION ),
			'dns failure'           => array( 'cURL error 6: Could not resolve host: analyticsdata.googleapis.com', '', -1, Error_Text::CONNECTION ),
			'grpc unavailable'      => array( '{"message":"Service unavailable","code":14,"status":"UNAVAILABLE","details":[]}', '', -1, Error_Text::CONNECTION ),
			'invalid_grant'         => array( 'Client error: `POST https://oauth2.googleapis.com/token` resulted in a `400 Bad Request` response: {"error": "invalid_grant", "error_description": "Token has been expired or revoked."}', '', -1, Error_Text::AUTH ),
			'unauthenticated'       => array( '{"message":"Request had invalid authentication credentials.","code":16,"status":"UNAUTHENTICATED","details":[]}', '', -1, Error_Text::AUTH ),
			'api not enabled'       => array( '{"message":"Google Analytics Data API has not been used in project 123 before or it is disabled.","code":7,"status":"PERMISSION_DENIED","details":[{"reason":"SERVICE_DISABLED"}]}', '', -1, Error_Text::API_DISABLED ),
			'permission denied'     => array( '{"message":"User does not have sufficient permissions for this property.","code":7,"status":"PERMISSION_DENIED","details":[]}', '', -1, Error_Text::PERMISSION ),
			'property not found'    => array( '{"message":"Requested entity was not found.","code":5,"status":"NOT_FOUND","details":[]}', '', -1, Error_Text::NOT_FOUND ),
			'quota'                 => array( '{"message":"Exhausted property tokens per day quota.","code":8,"status":"RESOURCE_EXHAUSTED","details":[]}', '', -1, Error_Text::QUOTA ),
			'status argument wins'  => array( 'Something odd', 'NOT_FOUND', -1, Error_Text::NOT_FOUND ),
			'http 0 is no response' => array( '', '', 0, Error_Text::CONNECTION ),
			'http 401'              => array( '{"error":{"code":401,"message":"Bad token"}}', '', 401, Error_Text::AUTH ),
			'http 403'              => array( '{"error":{"code":403,"message":"User does not have sufficient permission for site"}}', '', 403, Error_Text::PERMISSION ),
			'http 403 api disabled' => array( '{"error":{"code":403,"message":"Google Search Console API has not been used in project 9 before or it is disabled.","status":"PERMISSION_DENIED"}}', '', 403, Error_Text::API_DISABLED ),
			'http 404'              => array( '', '', 404, Error_Text::NOT_FOUND ),
			'http 429'              => array( '', '', 429, Error_Text::QUOTA ),
			'anything else'         => array( '{"message":"Internal error encountered.","code":13,"status":"INTERNAL","details":[]}', '', -1, Error_Text::GENERIC ),
		);
	}

	/**
	 * @dataProvider failures
	 */
	public function test_known_failures_are_classified( string $raw, string $status, int $http, string $expected ): void {
		$this->assertSame( $expected, Error_Text::classify( $raw, $status, $http ) );
	}

	public function test_an_exception_status_is_used(): void {
		$e = new class( 'opaque' ) extends \Exception {
			public function getStatus(): string {
				return 'RESOURCE_EXHAUSTED';
			}
		};

		$this->assertSame( Error_Text::QUOTA, Error_Text::classify_exception( $e ) );
	}

	public function test_every_class_has_a_distinct_plain_message_per_service(): void {
		$classes = array( Error_Text::CONNECTION, Error_Text::AUTH, Error_Text::API_DISABLED, Error_Text::PERMISSION, Error_Text::NOT_FOUND, Error_Text::QUOTA, Error_Text::GENERIC );

		foreach ( array( Error_Text::SERVICE_GA4, Error_Text::SERVICE_GSC ) as $service ) {
			$messages = array_map( static fn( $c ) => Error_Text::message( $c, $service ), $classes );
			$this->assertCount( count( $classes ), array_unique( $messages ) );
			foreach ( $messages as $message ) {
				$this->assertStringNotContainsString( '{', $message );
			}
		}
	}

	public function test_detail_reduces_json_to_status_and_message(): void {
		$this->assertSame(
			'NOT_FOUND: Requested entity was not found.',
			Error_Text::detail( '{"message":"Requested entity was not found.","code":5,"status":"NOT_FOUND","details":[]}' )
		);
		$this->assertSame( 300, mb_strlen( Error_Text::detail( str_repeat( 'x', 400 ) ) ) );
	}

	private static function gsc( array $response ): API_GSC {
		update_option( 'dragoncontentdecay_gsc_property', 'sc-domain:example.test' );
		$oauth = new class() extends OAuth {
			public function get_access_token(): ?string {
				return 'token';
			}
		};

		return new API_GSC(
			$oauth,
			static function () use ( $response ): array {
				return $response;
			}
		);
	}

	public function test_search_console_errors_are_plain_with_the_raw_text_as_detail(): void {
		$data = self::gsc(
			array(
				'code' => 403,
				'body' => '{"error":{"code":403,"message":"Google Search Console API has not been used in project 9 before or it is disabled.","status":"PERMISSION_DENIED"}}',
			)
		)->fetch_comparison_data( 30 );

		$this->assertSame( Error_Text::message( Error_Text::API_DISABLED, Error_Text::SERVICE_GSC ), $data['error'] );
		$this->assertStringContainsString( 'HTTP 403', $data['error_detail'] );
		$this->assertStringContainsString( 'has not been used in project 9', $data['error_detail'] );
	}

	public function test_search_console_no_response_is_a_connection_failure(): void {
		$data = self::gsc(
			array(
				'code'  => 0,
				'body'  => '',
				'error' => 'cURL error 28: Operation timed out',
			)
		)->fetch_comparison_data( 30 );

		$this->assertSame( Error_Text::message( Error_Text::CONNECTION, Error_Text::SERVICE_GSC ), $data['error'] );
		$this->assertStringContainsString( 'cURL error 28', $data['error_detail'] );
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

	public function test_the_analyzer_passes_the_detail_through(): void {
		$result = AnalyzerTestSupport::analyzer(
			array(
				'current'      => array(),
				'previous'     => array(),
				'error'        => 'Plain message.',
				'error_detail' => 'PERMISSION_DENIED: raw',
			)
		)->analyze_all();

		$this->assertSame( 'PERMISSION_DENIED: raw', $result['error_detail'] ?? '' );
	}

	public function test_the_detail_is_stored_and_cleared_with_the_error(): void {
		$this->scheduler(
			array(
				'analyzed'            => 0,
				'failed'              => 0,
				'cursor_saved'        => true,
				'error'               => 'Plain message.',
				'error_detail'        => 'PERMISSION_DENIED: raw',
			)
		)->sync();

		$info = ( new Scheduler( AnalyzerTestSupport::analyzer() ) )->get_last_sync_info();
		$this->assertSame( 'PERMISSION_DENIED: raw', $info['error_detail'] );

		Scheduler::clear_fetch_failure();
		$this->assertFalse( get_option( 'dragoncontentdecay_last_sync_error_detail' ) );

		update_option( 'dragoncontentdecay_last_sync_error', 'Old.' );
		update_option( 'dragoncontentdecay_last_sync_error_detail', 'old raw' );
		$this->scheduler(
			array(
				'analyzed'            => 1,
				'failed'              => 0,
				'cursor_saved'        => true,
				'search_error'        => 'Search plain.',
				'search_error_detail' => 'HTTP 503',
			)
		)->sync();

		$info = ( new Scheduler( AnalyzerTestSupport::analyzer() ) )->get_last_sync_info();
		$this->assertSame( '', $info['error_detail'] );
		$this->assertSame( 'HTTP 503', $info['search_error_detail'] );

		$this->scheduler(
			array(
				'analyzed'     => 1,
				'failed'       => 0,
				'cursor_saved' => true,
			)
		)->sync();
		$this->assertFalse( get_option( 'dragoncontentdecay_last_search_error_detail' ) );
	}

	/**
	 * Render the dashboard view with the given state.
	 *
	 * @param bool  $is_connected   Connected to Google.
	 * @param array $last_sync      Scheduler::get_last_sync_info() shape.
	 * @param bool  $access_revoked Google revoked access.
	 */
	private static function render_dashboard( bool $is_connected, array $last_sync = array(), bool $access_revoked = false ): string {
		$last_sync    += array(
			'timestamp'           => 0,
			'formatted'           => 'Never',
			'count'               => 0,
			'failed'              => 0,
			'status'              => 'complete',
			'error'               => '',
			'error_detail'        => '',
			'search_error'        => '',
			'search_error_detail' => '',
		);
		$vars = compact( 'is_connected', 'last_sync', 'access_revoked' ) + array(
			'current_tab'   => 'dashboard',
			'focus_post_id' => 0,
			'focus_post'    => null,
			'posts_data'    => array(),
			'summary'       => array(
				'decaying' => 0,
				'stable'   => 0,
				'growing'  => 0,
				'total'    => 0,
			),
			'trend_icons'   => array(),
			'trend_labels'  => array(),
		);

		return ( static function ( array $vars ): string {
			extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			ob_start();
			include __DIR__ . '/../admin/views/dashboard.php';
			return (string) ob_get_clean();
		} )( $vars );
	}

	public function test_first_run_card_is_honest_about_the_setup(): void {
		$html = self::render_dashboard( false );

		$this->assertStringNotContainsString( 'two minutes', $html );
		$this->assertStringContainsString( 'Google Cloud', $html );
		$this->assertStringContainsString( 'OAuth consent screen', $html );
	}

	public function test_a_failure_before_any_sync_does_not_mention_a_previous_sync(): void {
		$html = self::render_dashboard( true, array( 'error' => 'Plain message.' ) );

		$this->assertStringNotContainsString( 'previous successful sync', $html );
		$this->assertStringContainsString( 'Plain message.', $html );
	}

	public function test_a_failure_after_a_good_sync_says_the_scores_are_older(): void {
		update_option( 'dragoncontentdecay_last_sync', 1000 );
		$html = self::render_dashboard(
			true,
			array(
				'timestamp' => 1000,
				'error'     => 'Plain message.',
			)
		);

		$this->assertStringContainsString( 'previous successful sync', $html );
	}

	public function test_revoked_access_before_any_sync_does_not_mention_a_last_sync(): void {
		$html = self::render_dashboard( true, array(), true );

		$this->assertStringNotContainsString( 'last successful sync', $html );
		$this->assertStringContainsString( 'syncing has stopped', $html );
	}

	public function test_the_raw_detail_is_escaped_inside_a_details_element(): void {
		$html = self::render_dashboard(
			true,
			array(
				'error'        => 'Plain message.',
				'error_detail' => '<script>alert(1)</script>',
			)
		);

		$this->assertStringContainsString( '<details', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
	}
}
