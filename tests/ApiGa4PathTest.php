<?php
/**
 * GA4 path resolution on subfolder installs and against the tracked post
 * types, and the comparison date ranges.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\API_GA4;
use PHPUnit\Framework\TestCase;

final class ApiGa4PathTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		update_option( 'dragoncontentdecay_post_types', array( 'post', 'page' ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	private static function ga4(): API_GA4 {
		return new API_GA4( AnalyzerTestSupport::oauth() );
	}

	private static function post( int $id, string $path, string $type, string $url ): void {
		$GLOBALS['dragoncontentdecay_test_posts'][ $id ] = array(
			'path' => $path,
			'type' => $type,
		);
		$GLOBALS['dragoncontentdecay_test_url_ids'][ $url ] = $id;
	}

	public function test_a_subfolder_install_resolves_its_own_paths(): void {
		$GLOBALS['dragoncontentdecay_test_home_path'] = '/blog';
		self::post( 4, '2026/09/my-post', 'post', 'https://www.example.test/blog/2026/09/my-post' );

		$this->assertSame( 4, self::ga4()->path_to_post_id( '/blog/2026/09/my-post/' ) );
	}

	public function test_the_install_path_is_stripped_exactly_once(): void {
		$GLOBALS['dragoncontentdecay_test_home_path'] = '/blog';
		self::post( 9, 'blog/x', 'page', 'https://www.example.test/blog/blog/x' );
		self::post( 8, 'x', 'page', 'https://www.example.test/blog/x' );

		$this->assertSame( 9, self::ga4()->path_to_post_id( '/blog/blog/x' ) );
		$this->assertSame( 8, self::ga4()->path_to_post_id( '/blog/x' ) );
	}

	public function test_paths_outside_the_install_do_not_resolve(): void {
		$GLOBALS['dragoncontentdecay_test_home_path'] = '/blog';
		self::post( 5, 'shop/x', 'post', 'https://www.example.test/blog/shop/x' );

		$this->assertNull( self::ga4()->path_to_post_id( '/shop/x' ) );
		$this->assertNull( self::ga4()->path_to_post_id( '/blogger/shop/x' ) );
	}

	public function test_strip_home_path(): void {
		$GLOBALS['dragoncontentdecay_test_home_path'] = '/blog';

		$this->assertSame( '/my-post', API_GA4::strip_home_path( '/blog/my-post' ) );
		$this->assertSame( '/', API_GA4::strip_home_path( '/blog' ) );
		$this->assertSame( '/blog/x', API_GA4::strip_home_path( '/blog/blog/x' ) );
		$this->assertNull( API_GA4::strip_home_path( '/blogger' ) );

		$GLOBALS['dragoncontentdecay_test_home_path'] = '';
		$this->assertSame( '/blog/x', API_GA4::strip_home_path( '/blog/x' ) );
	}

	public function test_untracked_post_types_do_not_resolve(): void {
		update_option( 'dragoncontentdecay_post_types', array( 'post' ) );
		self::post( 2, 'about', 'page', 'https://www.example.test/about' );
		self::post( 3, 'hello', 'post', 'https://www.example.test/2026/hello' );

		$this->assertNull( self::ga4()->path_to_post_id( '/about/' ) );
		$this->assertSame( 3, self::ga4()->path_to_post_id( '/2026/hello/' ) );
	}

	public function test_a_subfolder_single_slug_uses_the_slug_map(): void {
		$GLOBALS['dragoncontentdecay_test_home_path'] = '/blog';
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array(), array( 'alpha' => 1 ) );

		$result = AnalyzerTestSupport::analyzer(
			array(
				'current'  => array( '/blog/alpha/' => array( 'pageviews' => 5, 'sessions' => 5, 'avg_time_on_page' => 1.0 ) ),
				'previous' => array( '/blog/alpha/' => array( 'pageviews' => 10, 'sessions' => 10, 'avg_time_on_page' => 1.0 ) ),
			)
		)->analyze_all();

		$this->assertSame( 1, $result['analyzed'] );
		$this->assertSame( array( 1 ), $GLOBALS['wpdb']->replaced );
	}

	public function test_the_current_period_ends_yesterday_in_the_site_timezone(): void {
		update_option( 'timezone_string', 'America/Los_Angeles' );
		$now = new DateTimeImmutable( '2026-09-25 03:00:00', new DateTimeZone( 'UTC' ) );

		$ranges = API_GA4::comparison_ranges( 30, $now );

		$this->assertSame( '2026-09-23', $ranges['current_end'] );
		$this->assertSame( '2026-08-25', $ranges['current_start'] );
		$this->assertSame( '2026-08-24', $ranges['previous_end'] );
		$this->assertSame( '2026-07-26', $ranges['previous_start'] );
	}

	public function test_fetch_comparison_data_requests_full_days_only(): void {
		update_option( 'timezone_string', 'America/Los_Angeles' );

		$ga4 = new class( AnalyzerTestSupport::oauth() ) extends API_GA4 {
			public array $ranges = array();

			public function fetch_pageviews( string $start_date, string $end_date ): array {
				$this->ranges[] = array( $start_date, $end_date );
				return array();
			}
		};
		$ga4->fetch_comparison_data( 30 );

		$yesterday = ( new DateTimeImmutable( 'now', wp_timezone() ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$this->assertSame( $yesterday, $ga4->ranges[0][1] );
		$this->assertSame( 30, (int) ( new DateTime( $ga4->ranges[0][0] ) )->diff( new DateTime( $ga4->ranges[0][1] ) )->days + 1 );
		$this->assertSame( 30, (int) ( new DateTime( $ga4->ranges[1][0] ) )->diff( new DateTime( $ga4->ranges[1][1] ) )->days + 1 );
	}
}
