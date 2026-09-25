<?php
/**
 * Every GA4 path that resolves to a post is summed into one score row;
 * feed, embed, AMP, comment-page and paged variants of a post are left out.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use DragonContentDecay\API_GSC;
use DragonContentDecay\OAuth;
use PHPUnit\Framework\TestCase;

final class AnalyzerAggregationTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	private static function row( int $views ): array {
		return array(
			'pageviews'        => $views,
			'sessions'         => $views,
			'avg_time_on_page' => 1.0,
		);
	}

	/**
	 * @param array<string,int> $current  Path => current views.
	 * @param array<string,int> $previous Path => previous views.
	 * @param array<string,int> $resolve  Path key => post ID.
	 * @param array<string,int> $slugs    Leaf slug => post ID.
	 * @param array             $extra    Extra comparison keys (truncated).
	 */
	private static function score( array $current, array $previous, array $resolve, array $slugs = array(), array $extra = array() ): array {
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array(), $slugs );

		return AnalyzerTestSupport::analyzer(
			array(
				'current'  => array_map( array( self::class, 'row' ), $current ),
				'previous' => array_map( array( self::class, 'row' ), $previous ),
			) + $extra,
			$resolve
		)->analyze_all();
	}

	public function test_a_paged_variant_does_not_overwrite_the_posts_drop(): void {
		$result = self::score(
			array(
				'/my-post'   => 400,
				'/my-post/2' => 5,
			),
			array(
				'/my-post'   => 1000,
				'/my-post/2' => 5,
			),
			array( '/my-post/2' => 1 ),
			array( 'my-post' => 1 )
		);

		$this->assertSame( 1, $result['analyzed'], 'one post, one row' );
		$this->assertSame( array( 1 ), $GLOBALS['wpdb']->replaced );
		$this->assertSame( -60.0, $GLOBALS['wpdb']->rows[1]['decay_score'] );
		$this->assertSame( 400, $GLOBALS['wpdb']->rows[1]['pageviews_current'] );
		$this->assertSame( 1000, $GLOBALS['wpdb']->rows[1]['pageviews_previous'] );
	}

	public function test_every_path_resolving_to_a_post_is_summed(): void {
		$result = self::score(
			array(
				'/my-post'          => 100,
				'/My-Post'          => 300,
				'/category/my-post' => 50,
			),
			array(
				'/my-post'          => 500,
				'/My-Post'          => 500,
				'/category/my-post' => 50,
			),
			array(
				'/My-Post'          => 1,
				'/category/my-post' => 1,
			),
			array( 'my-post' => 1 )
		);

		$this->assertSame( 1, $result['analyzed'] );
		$this->assertSame( 450, $GLOBALS['wpdb']->rows[1]['pageviews_current'] );
		$this->assertSame( 1050, $GLOBALS['wpdb']->rows[1]['pageviews_previous'] );
	}

	public function test_feed_embed_amp_comment_page_and_page_variants_are_left_out(): void {
		$variants = array(
			'/my-post/feed',
			'/my-post/feed/rss2',
			'/my-post/embed',
			'/my-post/amp',
			'/my-post/comment-page-3',
			'/my-post/page/2',
			'/my-post/2',
		);
		$current  = array( '/my-post' => 10 );
		$previous = array( '/my-post' => 20 );
		$resolve  = array();
		foreach ( $variants as $path ) {
			$current[ $path ]  = 1000;
			$previous[ $path ] = 1;
			$resolve[ $path ]  = 1;
		}

		self::score( $current, $previous, $resolve, array( 'my-post' => 1 ) );

		$this->assertSame( 10, $GLOBALS['wpdb']->rows[1]['pageviews_current'] );
		$this->assertSame( 20, $GLOBALS['wpdb']->rows[1]['pageviews_previous'] );
	}

	public function test_a_numeric_permalink_is_not_mistaken_for_a_paged_variant(): void {
		// Plain /archives/%post_id% permalinks: /archives does not resolve to the post.
		$result = self::score(
			array( '/archives/123' => 40 ),
			array( '/archives/123' => 80 ),
			array( '/archives/123' => 123 )
		);

		$this->assertSame( 1, $result['analyzed'] );
		$this->assertSame( -50.0, $GLOBALS['wpdb']->rows[123]['decay_score'] );
	}

	public function test_a_child_page_named_amp_is_its_own_post(): void {
		$result = self::score(
			array(
				'/guide'     => 10,
				'/guide/amp' => 30,
			),
			array(
				'/guide'     => 10,
				'/guide/amp' => 60,
			),
			array(
				'/guide'     => 6,
				'/guide/amp' => 7,
			)
		);

		$this->assertSame( 2, $result['analyzed'] );
		$this->assertSame( 30, $GLOBALS['wpdb']->rows[7]['pageviews_current'] );
		$this->assertSame( 10, $GLOBALS['wpdb']->rows[6]['pageviews_current'] );
	}

	public function test_a_post_whose_missing_path_could_matter_is_scored_and_marked_uncertain(): void {
		$result = self::score(
			array( '/my-post' => 400 ),
			array(
				'/my-post'          => 1000,
				'/category/my-post' => 50,
			),
			array( '/category/my-post' => 1 ),
			array( 'my-post' => 1 ),
			array(
				'truncated' => array(
					'current'  => true,
					'previous' => false,
				),
			)
		);

		// The current report stops at 400 views, so the missing alias may
		// have had up to 400: scored on what was read, flagged as partial.
		$this->assertSame( 1, $result['analyzed'] );
		$this->assertSame( 400, $GLOBALS['wpdb']->rows[1]['pageviews_current'] );
		$this->assertSame( 1050, $GLOBALS['wpdb']->rows[1]['pageviews_previous'] );
		$this->assertSame( array( 1 ), get_option( Analyzer::UNCERTAIN_OPTION ) );
	}

	public function test_a_low_traffic_alias_below_the_cutoff_does_not_make_a_post_uncertain(): void {
		$result = self::score(
			array(
				'/my-post'  => 400,
				'/tail-end' => 2,
			),
			array(
				'/my-post'          => 1000,
				'/category/my-post' => 50,
			),
			array( '/category/my-post' => 1 ),
			array( 'my-post' => 1 ),
			array(
				'truncated' => array(
					'current'  => true,
					'previous' => false,
				),
			)
		);

		$this->assertSame( 1, $result['analyzed'] );
		$this->assertSame( -61.9, $GLOBALS['wpdb']->rows[1]['decay_score'] );
		$this->assertFalse( get_option( Analyzer::UNCERTAIN_OPTION ) );
	}

	public function test_a_later_complete_read_clears_the_uncertain_mark(): void {
		update_option( Analyzer::UNCERTAIN_OPTION, array( 1 ) );

		self::score( array( '/my-post' => 400 ), array( '/my-post' => 1000 ), array(), array( 'my-post' => 1 ) );

		$this->assertFalse( get_option( Analyzer::UNCERTAIN_OPTION ) );
	}

	public function test_a_complete_pass_prunes_rows_of_untracked_types(): void {
		update_option( 'dragoncontentdecay_post_types', array( 'post', 'page' ) );

		self::score( array( '/my-post' => 400 ), array( '/my-post' => 1000 ), array(), array( 'my-post' => 1 ) );

		$deletes = array_values( preg_grep( '/^\s*DELETE/', $GLOBALS['wpdb']->queries ) );
		$this->assertCount( 1, $deletes );
		$this->assertStringContainsString( 'wp_dcd_scores', $deletes[0] );
		$this->assertStringContainsString( 'p.post_type NOT IN', $deletes[0] );
	}

	public function test_search_metrics_are_summed_across_the_posts_paths(): void {
		update_option( 'dragoncontentdecay_gsc_enabled', 1 );
		update_option( 'dragoncontentdecay_gsc_property', 'sc-domain:example.test' );
		update_option( 'dragoncontentdecay_google_granted_scopes', OAuth::SCOPE_ANALYTICS . ' ' . OAuth::SCOPE_SEARCHCONSOLE );

		$body = static function ( array $rows ): array {
			return array(
				'code' => 200,
				'body' => (string) wp_json_encode( array( 'rows' => $rows ) ),
			);
		};
		$responses = array(
			$body(
				array(
					array( 'keys' => array( 'https://example.test/my-post/' ), 'clicks' => 4, 'impressions' => 100, 'position' => 2.0 ),
					array( 'keys' => array( 'https://example.test/My-Post/' ), 'clicks' => 6, 'impressions' => 300, 'position' => 6.0 ),
				)
			),
			$body(
				array(
					array( 'keys' => array( 'https://example.test/my-post/' ), 'clicks' => 10, 'impressions' => 200, 'position' => 3.0 ),
				)
			),
		);
		$calls = 0;
		$gsc   = new API_GSC(
			new class() extends OAuth {
				public function get_access_token(): ?string {
					return 'token';
				}
			},
			static function () use ( $responses, &$calls ): array {
				return $responses[ $calls++ ];
			}
		);

		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array(), array( 'my-post' => 1 ) );
		$analyzer        = new Analyzer(
			AnalyzerTestSupport::ga4(
				array(
					'current'  => array(
						'/my-post' => self::row( 10 ),
						'/My-Post' => self::row( 10 ),
					),
					'previous' => array( '/my-post' => self::row( 10 ) ),
				),
				array( '/My-Post' => 1 )
			),
			$gsc
		);
		$analyzer->analyze_all();

		$row = $GLOBALS['wpdb']->rows[1];
		$this->assertSame( 10, $row['search_clicks_current'] );
		$this->assertSame( 10, $row['search_clicks_previous'] );
		$this->assertSame( 400, $row['search_impressions_current'] );
		$this->assertSame( 200, $row['search_impressions_previous'] );
		// Impression-weighted: (2*100 + 6*300) / 400 = 5.0.
		$this->assertSame( 5.0, $row['search_position'] );
	}

	public function test_a_run_out_of_time_writes_nothing_and_resumes_without_losing_paths(): void {
		add_filter(
			'dragoncontentdecay_analyze_time_budget',
			static function () {
				return 0.0;
			}
		);

		$comparison = array(
			'current'  => array(
				'/a/my-post' => self::row( 400 ),
				'/b/my-post' => self::row( 0 ),
			),
			'previous' => array(
				'/a/my-post' => self::row( 500 ),
				'/b/my-post' => self::row( 500 ),
			),
		);
		$resolve    = array(
			'/a/my-post' => 1,
			'/b/my-post' => 1,
		);

		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array() );
		$first           = AnalyzerTestSupport::analyzer( $comparison, $resolve )->analyze_all();

		$this->assertTrue( $first['pending'] ?? false );
		$this->assertSame( 0, $first['analyzed'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->replaced, 'a half-resolved post is not scored' );
		$this->assertTrue( $first['cursor_saved'] );
		$this->assertSame( array(), preg_grep( '/^\s*DELETE/', $GLOBALS['wpdb']->queries ), 'an unfinished pass prunes nothing' );

		$second = AnalyzerTestSupport::analyzer( $comparison, $resolve )->analyze_all();

		$this->assertFalse( $second['pending'] ?? false );
		$this->assertSame( 1, $second['analyzed'] );
		$this->assertSame( 400, $GLOBALS['wpdb']->rows[1]['pageviews_current'] );
		$this->assertSame( 1000, $GLOBALS['wpdb']->rows[1]['pageviews_previous'] );
	}
}
