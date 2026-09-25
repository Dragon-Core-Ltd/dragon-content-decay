<?php
/**
 * Dashboard cards, the table, the post-list column and "View Analytics" all
 * judge a score against the CURRENT threshold setting.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Admin;
use DragonContentDecay\Analyzer;
use PHPUnit\Framework\TestCase;

final class DashboardConsistencyTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		// Scores were stored while the threshold was -20; it is now -50.
		update_option( 'dragoncontentdecay_decay_threshold', -50 );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * A $wpdb double that serves canned score rows and records every query.
	 *
	 * @param array $rows Score rows (as ARRAY_A).
	 */
	private function wpdb( array $rows ): object {
		return new class( $rows ) {
			public string $prefix  = 'wp_';
			public string $posts   = 'wp_posts';
			public array $queries  = array();
			private array $rows;

			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			public function prepare( string $query, ...$args ) {
				foreach ( $args as $arg ) {
					$query = preg_replace( '/%[dfs]/', (string) $arg, $query, 1 );
				}
				return $query;
			}

			public function get_results( string $query, $output = null ) {
				unset( $output );
				$this->queries[] = $query;
				return $this->rows;
			}

			public function get_row( string $query, $output = null ) {
				$this->queries[] = $query;
				if ( empty( $this->rows ) ) {
					return null;
				}
				return 'ARRAY_A' === $output ? $this->rows[0] : (object) $this->rows[0];
			}

			public function get_var( string $query ) {
				$this->queries[] = $query;
				return 0;
			}
		};
	}

	private function row( float $score, string $stored_trend ): array {
		return array(
			'post_id'            => 7,
			'decay_score'        => $score,
			'trend'              => $stored_trend,
			'pageviews_current'  => 70,
			'pageviews_previous' => 100,
			'post_title'         => 'Seven',
			'post_modified'      => '2026-09-01 00:00:00',
		);
	}

	public function test_dashboard_rows_use_the_current_threshold(): void {
		$GLOBALS['wpdb'] = $this->wpdb( array( $this->row( -30.0, 'decaying' ) ) );

		$rows = AnalyzerTestSupport::analyzer()->get_dashboard_rows( 100 );

		$this->assertSame( 'stable', $rows[0]['trend'] );
	}

	public function test_summary_counts_only_published_posts_like_the_table(): void {
		$GLOBALS['wpdb'] = $this->wpdb( array() );

		AnalyzerTestSupport::analyzer()->get_summary();

		$this->assertNotEmpty( $GLOBALS['wpdb']->queries );
		foreach ( $GLOBALS['wpdb']->queries as $query ) {
			$this->assertStringContainsString( "post_status = 'publish'", $query );
		}
	}

	public function test_posts_by_trend_filters_on_the_score_not_the_stored_label(): void {
		$GLOBALS['wpdb'] = $this->wpdb( array( $this->row( -60.0, 'decaying' ) ) );

		$rows = AnalyzerTestSupport::analyzer()->get_posts_by_trend( Analyzer::TREND_DECAYING );

		$this->assertStringContainsString( 's.decay_score <= -50', $GLOBALS['wpdb']->queries[0] );
		$this->assertStringNotContainsString( 's.trend =', $GLOBALS['wpdb']->queries[0] );
		$this->assertSame( 'decaying', $rows[0]['trend'] );
	}

	public function test_every_reader_lists_only_tracked_post_types(): void {
		update_option( 'dragoncontentdecay_post_types', array( 'post', 'page' ) );
		$GLOBALS['wpdb'] = $this->wpdb( array() );
		$analyzer        = AnalyzerTestSupport::analyzer();

		$analyzer->get_dashboard_rows( 100 );
		$analyzer->get_decaying_posts( 10 );
		$analyzer->get_posts_by_trend( Analyzer::TREND_STABLE );
		$analyzer->get_summary();

		$this->assertCount( 7, $GLOBALS['wpdb']->queries );
		foreach ( $GLOBALS['wpdb']->queries as $query ) {
			$this->assertStringContainsString( 'p.post_type IN (post, page)', $query );
		}
	}

	public function test_single_post_reads_and_the_list_column_only_show_tracked_types(): void {
		update_option( 'dragoncontentdecay_post_types', array( 'post' ) );
		$GLOBALS['wpdb'] = $this->wpdb( array( $this->row( -30.0, 'decaying' ) ) );

		AnalyzerTestSupport::analyzer()->get_post_decay( 7 );
		ob_start();
		$this->admin()->render_decay_column( 'dragoncontentdecay_decay', 7 );
		ob_end_clean();

		$this->assertCount( 2, $GLOBALS['wpdb']->queries );
		foreach ( $GLOBALS['wpdb']->queries as $query ) {
			$this->assertStringContainsString( 'p.post_type IN (post)', $query );
		}
	}

	public function test_the_focused_post_carries_the_uncertain_mark(): void {
		update_option( Analyzer::UNCERTAIN_OPTION, array( 7 ) );
		$GLOBALS['wpdb'] = $this->wpdb( array( $this->row( -30.0, 'decaying' ) ) );

		$this->assertTrue( $this->admin()->get_focus_post( 7 )['uncertain'] );
	}

	public function test_dashboard_rows_carry_the_uncertain_mark(): void {
		update_option( Analyzer::UNCERTAIN_OPTION, array( 7 ) );
		$GLOBALS['wpdb'] = $this->wpdb( array( $this->row( -30.0, 'decaying' ), array( 'post_id' => 8 ) + $this->row( 5.0, 'stable' ) ) );

		$rows = AnalyzerTestSupport::analyzer()->get_dashboard_rows( 100 );

		$this->assertTrue( $rows[0]['uncertain'] );
		$this->assertFalse( $rows[1]['uncertain'] );
	}

	private function admin(): Admin {
		return new Admin( AnalyzerTestSupport::oauth(), AnalyzerTestSupport::analyzer() );
	}

	public function test_post_list_column_uses_the_threshold_setting(): void {
		$GLOBALS['wpdb'] = $this->wpdb( array( $this->row( -30.0, 'decaying' ) ) );

		ob_start();
		$this->admin()->render_decay_column( 'dragoncontentdecay_decay', 7 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'dcd-stable', $html );
		$this->assertStringNotContainsString( 'dcd-decaying', $html );
	}

	public function test_view_analytics_opens_the_dashboard_for_that_post(): void {
		$actions = $this->admin()->add_analytics_link( array(), new WP_Post( 7 ) );

		$this->assertStringContainsString( 'tools.php?page=dragon-content-decay&#038;post_id=7', $actions['dcd_analytics'] );
	}

	public function test_dashboard_focus_returns_that_posts_scores_with_the_current_trend(): void {
		$GLOBALS['wpdb'] = $this->wpdb( array( $this->row( -30.0, 'decaying' ) ) );

		$focus = $this->admin()->get_focus_post( 7 );

		$this->assertSame( 7, (int) $focus['post_id'] );
		$this->assertSame( 'stable', $focus['trend'] );
	}

	public function test_dashboard_focus_is_null_for_a_post_without_scores(): void {
		$GLOBALS['wpdb'] = $this->wpdb( array() );

		$this->assertNull( $this->admin()->get_focus_post( 7 ) );
	}
}
