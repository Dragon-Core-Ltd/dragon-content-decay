<?php
/**
 * A one-segment GA4 path is credited to a post only when that post really
 * lives at that address, and the precise resolver never hands back a post
 * matched on its slug alone, a draft or a private post.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use DragonContentDecay\API_GA4;
use DragonContentDecay\API_GSC;
use PHPUnit\Framework\TestCase;

final class PathAttributionTest extends TestCase {

	private const DATED = '/%year%/%monthnum%/%day%/%postname%/';

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
	 * Run a pass where a post "pricing" (80 -> 20 views at its dated address)
	 * shares its slug with the busy root URL /pricing.
	 *
	 * @param array $slug Slug-map row for "pricing".
	 * @return API_GA4 The GA4 double (records the paths sent to the precise resolver).
	 */
	private function pricing_pass( array $slug ): API_GA4 {
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array(), array( 'pricing' => $slug ) );
		$ga4             = AnalyzerTestSupport::ga4(
			array(
				'current'  => self::rows(
					array(
						'/2026/09/14/pricing' => 20,
						'/pricing'            => 5000,
					)
				),
				'previous' => self::rows(
					array(
						'/2026/09/14/pricing' => 80,
						'/pricing'            => 5000,
					)
				),
			),
			array( '/2026/09/14/pricing' => 7 )
		);
		( new Analyzer( $ga4, new API_GSC( AnalyzerTestSupport::oauth() ) ) )->analyze_all();

		return $ga4;
	}

	public function test_a_post_under_date_permalinks_does_not_take_a_root_path(): void {
		update_option( 'permalink_structure', self::DATED );

		$ga4 = $this->pricing_pass( array( 'ID' => 7 ) );

		$this->assertContains( '/pricing', $ga4->resolved, 'the root path goes to the precise resolver' );
		$this->assertSame( 20, $GLOBALS['wpdb']->rows[7]['pageviews_current'] );
		$this->assertSame( 80, $GLOBALS['wpdb']->rows[7]['pageviews_previous'] );
		$this->assertSame( -75.0, $GLOBALS['wpdb']->rows[7]['decay_score'] );
	}

	public function test_a_post_under_postname_permalinks_keeps_the_fast_path(): void {
		$ga4 = $this->pricing_pass( array( 'ID' => 7 ) );

		$this->assertNotContains( '/pricing', $ga4->resolved );
		$this->assertSame( 5020, $GLOBALS['wpdb']->rows[7]['pageviews_current'] );
	}

	public function test_a_top_level_page_keeps_the_fast_path_under_any_structure(): void {
		update_option( 'permalink_structure', self::DATED );
		update_option( 'dragoncontentdecay_post_types', array( 'post', 'page' ) );

		$ga4 = $this->pricing_pass(
			array(
				'ID'   => 7,
				'type' => 'page',
			)
		);

		$this->assertNotContains( '/pricing', $ga4->resolved );
	}

	public function test_a_child_page_does_not_take_a_root_path(): void {
		update_option( 'dragoncontentdecay_post_types', array( 'post', 'page' ) );

		$ga4 = $this->pricing_pass(
			array(
				'ID'     => 7,
				'type'   => 'page',
				'parent' => 3,
			)
		);

		$this->assertContains( '/pricing', $ga4->resolved );
	}

	public function test_another_post_type_does_not_take_a_root_path(): void {
		update_option( 'dragoncontentdecay_post_types', array( 'post', 'product' ) );

		$ga4 = $this->pricing_pass(
			array(
				'ID'   => 7,
				'type' => 'product',
			)
		);

		$this->assertContains( '/pricing', $ga4->resolved );
	}

	// ---- precise resolver ------------------------------------------------------

	private static function ga4(): API_GA4 {
		return new API_GA4( AnalyzerTestSupport::oauth() );
	}

	private static function post( int $id, string $path, string $type, string $status = 'publish' ): void {
		$GLOBALS['dragoncontentdecay_test_posts'][ $id ]                                      = array(
			'path'   => $path,
			'type'   => $type,
			'status' => $status,
		);
		$GLOBALS['dragoncontentdecay_test_url_ids'][ 'https://www.example.test/' . $path ] = $id;
	}

	public function test_a_post_is_not_matched_on_its_slug_alone(): void {
		update_option( 'permalink_structure', self::DATED );
		self::post( 7, '2026/09/14/pricing', 'post' );

		$this->assertNull( self::ga4()->path_to_post_id( '/pricing' ) );
		$this->assertSame( 7, self::ga4()->path_to_post_id( '/2026/09/14/pricing/' ) );
	}

	public function test_the_page_hierarchy_still_resolves(): void {
		update_option( 'dragoncontentdecay_post_types', array( 'post', 'page' ) );
		self::post( 4, 'about/team', 'page' );
		unset( $GLOBALS['dragoncontentdecay_test_url_ids']['https://www.example.test/about/team'] );

		$this->assertSame( 4, self::ga4()->path_to_post_id( '/about/team/' ) );
	}

	public function test_a_draft_page_is_not_returned(): void {
		update_option( 'dragoncontentdecay_post_types', array( 'post', 'page' ) );
		self::post( 5, 'coming-soon', 'page', 'draft' );

		$this->assertNull( self::ga4()->path_to_post_id( '/coming-soon/' ) );
	}

	public function test_a_private_post_is_not_returned(): void {
		self::post( 6, 'members', 'post', 'private' );

		$this->assertNull( self::ga4()->path_to_post_id( '/members/' ) );
	}
}
