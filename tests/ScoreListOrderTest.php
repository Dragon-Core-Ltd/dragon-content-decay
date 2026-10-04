<?php
/**
 * The dashboard table and the digest list break ties on the score by the
 * previous period's views, busiest first, so a post that lost thousands of
 * views ranks above one that lost a handful at the same percentage.
 *
 * @package DragonContentDecay
 */

use PHPUnit\Framework\TestCase;

final class ScoreListOrderTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	private function wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';
			public string $posts  = 'wp_posts';
			public array $queries = array();

			public function prepare( string $query, ...$args ): string {
				unset( $args );
				return $query;
			}

			public function get_results( string $query, $output = null ) {
				unset( $output );
				$this->queries[] = preg_replace( '/\s+/', ' ', $query );
				return array();
			}
		};
	}

	public function test_dashboard_rows_break_ties_by_previous_views(): void {
		$GLOBALS['wpdb'] = $this->wpdb();

		AnalyzerTestSupport::analyzer()->get_dashboard_rows( 100 );

		$this->assertStringContainsString( 'ORDER BY s.decay_score ASC, s.pageviews_previous DESC LIMIT', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_digest_list_breaks_ties_by_previous_views(): void {
		$GLOBALS['wpdb'] = $this->wpdb();

		AnalyzerTestSupport::analyzer()->get_decaying_posts( 10 );

		$this->assertStringContainsString( 'ORDER BY s.decay_score ASC, s.pageviews_previous DESC LIMIT', $GLOBALS['wpdb']->queries[0] );
	}
}
