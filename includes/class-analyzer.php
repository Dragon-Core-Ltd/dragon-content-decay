<?php
/**
 * Analyzer Class
 *
 * Handles decay score calculation and trend analysis
 *
 * @package DragonContentDecay
 */

namespace DragonContentDecay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Analyzer {

	/**
	 * GA4 API instance
	 */
	private API_GA4 $api_ga4;

	/**
	 * GSC API instance
	 */
	private API_GSC $api_gsc;

	/**
	 * Trend constants
	 */
	public const TREND_DECAYING = 'decaying';
	public const TREND_STABLE   = 'stable';
	public const TREND_GROWING  = 'growing';

	/**
	 * Constructor
	 */
	public function __construct( API_GA4 $api_ga4, API_GSC $api_gsc ) {
		$this->api_ga4 = $api_ga4;
		$this->api_gsc = $api_gsc;
	}

	/**
	 * Calculate decay scores for all posts
	 *
	 * @return int Number of posts analyzed
	 */
	public function analyze_all(): int {
		$period_days = (int) get_option( 'dragoncontentdecay_comparison_period', 30 );
		$data        = $this->api_ga4->fetch_comparison_data( $period_days );

		if ( empty( $data['current'] ) && empty( $data['previous'] ) ) {
			return 0;
		}

		// All paths from both periods, reindexed 0..n-1 so the rotating cursor
		// below can address them positionally.
		$all_paths = array_values(
			array_unique(
				array_merge(
					array_keys( $data['current'] ),
					array_keys( $data['previous'] )
				)
			)
		);

		$total = count( $all_paths );
		if ( 0 === $total ) {
			return 0;
		}

		// Pre-resolve leaf slugs in one query so a large GA4 property (up to ~20k
		// paths across both periods) doesn't run a per-path database lookup and
		// time the sync out. Ambiguous or unmatched slugs fall back to the precise
		// resolver.
		$slug_map = $this->build_slug_map( $all_paths );

		// Optional Google Search Console signal, keyed by the same URL paths.
		$gsc = $this->maybe_fetch_gsc( $period_days );

		// Bound the run to a wall-clock budget so a large property (thousands of
		// multi-segment paths, each needing a precise rewrite lookup) cannot exhaust
		// PHP's max_execution_time and fatal mid-sync. A rotating cursor guarantees
		// every path is eventually scored across successive runs rather than the
		// same prefix each time.
		$deadline = microtime( true ) + $this->time_budget();
		$cursor   = (int) get_option( 'dragoncontentdecay_analyze_cursor', 0 );
		if ( $cursor < 0 || $cursor >= $total ) {
			$cursor = 0;
		}

		$analyzed  = 0;
		$processed = 0;
		$index     = $cursor;

		while ( $processed < $total ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}

			$path = $all_paths[ $index % $total ];
			++$index;
			++$processed;

			$post_id = $this->resolve_path( $path, $slug_map );
			if ( ! $post_id ) {
				continue;
			}

			$current_views  = $data['current'][ $path ]['pageviews'] ?? 0;
			$previous_views = $data['previous'][ $path ]['pageviews'] ?? 0;

			$this->calculate_and_store_score( $post_id, $current_views, $previous_views, $this->build_search_metrics( $path, $gsc ) );
			++$analyzed;
		}

		update_option( 'dragoncontentdecay_analyze_cursor', $index % $total, false );

		return $analyzed;
	}

	/**
	 * Wall-clock budget in seconds for a single analysis run, derived from PHP's
	 * max_execution_time with headroom (or a safe default when it is unlimited).
	 *
	 * @return float
	 */
	private function time_budget(): float {
		$max    = (int) ini_get( 'max_execution_time' );
		$budget = $max > 0 ? max( 10, $max - 10 ) : 60;

		/**
		 * Filter the wall-clock budget (in seconds) for one decay analysis run.
		 *
		 * @param float $budget Seconds.
		 */
		return (float) apply_filters( 'dragoncontentdecay_analyze_time_budget', (float) $budget );
	}

	/**
	 * Build a leaf-slug => [post IDs] map for the given paths in one query set.
	 *
	 * @param array<int,string> $paths GA4 paths.
	 * @return array<string,array<int,int>>
	 */
	private function build_slug_map( array $paths ): array {
		global $wpdb;

		$slugs = array();
		foreach ( $paths as $path ) {
			$trimmed = trim( (string) $path, '/' );
			if ( '' === $trimmed ) {
				continue;
			}
			$parts                  = explode( '/', $trimmed );
			$slugs[ end( $parts ) ] = true;
		}
		$slugs = array_keys( $slugs );
		if ( empty( $slugs ) ) {
			return array();
		}

		$types = (array) get_option( 'dragoncontentdecay_post_types', array( 'post' ) );
		if ( empty( $types ) ) {
			$types = array( 'post' );
		}

		$map     = array();
		$type_ph = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		foreach ( array_chunk( $slugs, 500 ) as $chunk ) {
			$slug_ph = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom read; the query is prepared below with only fixed %s placeholder lists interpolated.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $slug_ph/$type_ph are fixed lists of %s placeholders and $wpdb->posts is the core table name; all values are prepared.
					"SELECT ID, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_name IN ( {$slug_ph} ) AND post_type IN ( {$type_ph} )",
					array_merge( $chunk, $types )
				)
			);
			foreach ( (array) $rows as $row ) {
				$map[ $row->post_name ][] = (int) $row->ID;
			}
		}

		return $map;
	}

	/**
	 * Resolve a GA4 path to a post ID, preferring the batched slug map for an
	 * unambiguous leaf-slug match and falling back to the precise resolver.
	 *
	 * @param string                        $path     GA4 path.
	 * @param array<string,array<int,int>>  $slug_map Leaf-slug map.
	 * @return int|null
	 */
	private function resolve_path( string $path, array $slug_map ): ?int {
		$trimmed = trim( $path, '/' );

		// Fast path only for a single-segment path (/slug/): there the slug is the
		// whole path, so a unique match is identical to what the precise resolver
		// would return. Multi-segment paths (/category/slug/) depend on hierarchy
		// or rewrite rules, so always resolve those precisely to avoid mis-
		// attributing views to a same-slug post under a different path.
		if ( '' !== $trimmed && ! str_contains( $trimmed, '/' ) ) {
			if ( isset( $slug_map[ $trimmed ] ) && 1 === count( $slug_map[ $trimmed ] ) ) {
				return $slug_map[ $trimmed ][0];
			}
		}

		return $this->api_ga4->path_to_post_id( $path );
	}

	/**
	 * Calculate and store decay score for a single post.
	 *
	 * The decay score stays GA4-pageviews based; the optional Search Console
	 * metrics are stored alongside as a supplementary signal. $wpdb->replace
	 * rewrites the whole row, so the search_* columns are always written (0 when
	 * no GSC data applies) rather than being reset to defaults.
	 *
	 * @param int        $post_id        Post ID.
	 * @param int        $current_views  Current-period pageviews.
	 * @param int        $previous_views Previous-period pageviews.
	 * @param array|null $search         Optional GSC metrics for this post.
	 */
	public function calculate_and_store_score( int $post_id, int $current_views, int $previous_views, ?array $search = null ): void {
		$score  = $this->calculate_decay_score( $current_views, $previous_views );
		$trend  = $this->determine_trend( $score );
		$search = is_array( $search ) ? $search : array();

		global $wpdb;
		$table_scores = $wpdb->prefix . 'dcd_scores';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to a plugin-owned custom table; no core API or cache applies.
		$wpdb->replace(
			$table_scores,
			array(
				'post_id'                     => $post_id,
				'decay_score'                 => $score,
				'trend'                       => $trend,
				'pageviews_current'           => $current_views,
				'pageviews_previous'          => $previous_views,
				'search_clicks_current'       => (int) ( $search['clicks_current'] ?? 0 ),
				'search_clicks_previous'      => (int) ( $search['clicks_previous'] ?? 0 ),
				'search_impressions_current'  => (int) ( $search['impressions_current'] ?? 0 ),
				'search_impressions_previous' => (int) ( $search['impressions_previous'] ?? 0 ),
				'search_position'             => (float) ( $search['position'] ?? 0 ),
			),
			array( '%d', '%f', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%f' )
		);
	}

	/**
	 * Fetch the Search Console comparison data when GSC is enabled and the scope
	 * has been granted; otherwise an empty structure.
	 *
	 * @param int $period_days Comparison period.
	 * @return array{current: array<string,array>, previous: array<string,array>}
	 */
	private function maybe_fetch_gsc( int $period_days ): array {
		$empty = array(
			'current'  => array(),
			'previous' => array(),
		);

		if ( ! get_option( 'dragoncontentdecay_gsc_enabled' ) || ! OAuth::has_searchconsole_scope() ) {
			return $empty;
		}

		return $this->api_gsc->fetch_comparison_data( $period_days );
	}

	/**
	 * Build the per-post Search Console metric set for a path, or null if there is
	 * no GSC data for it.
	 *
	 * @param string $path GA4/GSC URL path.
	 * @param array  $gsc  GSC comparison data.
	 * @return array|null
	 */
	private function build_search_metrics( string $path, array $gsc ): ?array {
		$cur  = $gsc['current'][ $path ] ?? null;
		$prev = $gsc['previous'][ $path ] ?? null;

		if ( null === $cur && null === $prev ) {
			return null;
		}

		return array(
			'clicks_current'       => (int) ( $cur['clicks'] ?? 0 ),
			'clicks_previous'      => (int) ( $prev['clicks'] ?? 0 ),
			'impressions_current'  => (int) ( $cur['impressions'] ?? 0 ),
			'impressions_previous' => (int) ( $prev['impressions'] ?? 0 ),
			'position'             => (float) ( $cur['position'] ?? 0 ),
		);
	}

	/**
	 * Calculate decay score (percentage change)
	 *
	 * @param int $current  Current period pageviews
	 * @param int $previous Previous period pageviews
	 * @return float Percentage change (negative = decay)
	 */
	public function calculate_decay_score( int $current, int $previous ): float {
		// If no previous data, can't calculate decay
		if ( 0 === $previous ) {
			// If current has views, it's growth; otherwise stable
			return $current > 0 ? 100.0 : 0.0;
		}

		// Calculate percentage change
		$change = ( ( $current - $previous ) / $previous ) * 100;

		// Round to 1 decimal place
		return round( $change, 1 );
	}

	/**
	 * Determine trend based on decay score
	 *
	 * @param float $score Decay score percentage
	 * @return string Trend constant
	 */
	public function determine_trend( float $score ): string {
		$threshold = (int) get_option( 'dragoncontentdecay_decay_threshold', -20 );

		if ( $score <= $threshold ) {
			return self::TREND_DECAYING;
		}

		if ( $score >= abs( $threshold ) ) {
			return self::TREND_GROWING;
		}

		return self::TREND_STABLE;
	}

	/**
	 * Get posts that are decaying
	 *
	 * @param int $limit Maximum number of posts to return
	 * @return array Array of post data with decay info
	 */
	public function get_decaying_posts( int $limit = 50 ): array {
		global $wpdb;

		$table_scores = $wpdb->prefix . 'dcd_scores';
		$threshold    = (int) get_option( 'dragoncontentdecay_decay_threshold', -20 );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table name built from $wpdb->prefix, not user input; values passed through $wpdb->prepare().
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, p.post_title, p.post_date, p.post_modified
                 FROM {$table_scores} s
                 JOIN {$wpdb->posts} p ON s.post_id = p.ID
                 WHERE s.decay_score <= %f
                 AND p.post_status = 'publish'
                 ORDER BY s.decay_score ASC
                 LIMIT %d",
				$threshold,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get posts by trend
	 *
	 * @param string $trend One of TREND_DECAYING, TREND_STABLE, TREND_GROWING
	 * @param int    $limit Maximum number of posts
	 * @return array
	 */
	public function get_posts_by_trend( string $trend, int $limit = 50 ): array {
		global $wpdb;

		$table_scores = $wpdb->prefix . 'dcd_scores';

		$order = self::TREND_GROWING === $trend ? 'DESC' : 'ASC';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table name built from $wpdb->prefix and $order limited to a hardcoded ASC/DESC keyword; values passed through $wpdb->prepare().
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, p.post_title, p.post_date, p.post_modified
                 FROM {$table_scores} s
                 JOIN {$wpdb->posts} p ON s.post_id = p.ID
                 WHERE s.trend = %s
                 AND p.post_status = 'publish'
                 ORDER BY s.decay_score {$order}
                 LIMIT %d",
				$trend,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get summary statistics
	 *
	 * @return array
	 */
	public function get_summary(): array {
		global $wpdb;

		$table_scores = $wpdb->prefix . 'dcd_scores';
		$threshold    = (int) get_option( 'dragoncontentdecay_decay_threshold', -20 );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table name built from $wpdb->prefix, not user input; values passed through $wpdb->prepare().
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_scores}" );

		$decaying = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_scores} WHERE decay_score <= %f",
				$threshold
			)
		);

		$growing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_scores} WHERE decay_score >= %f",
				abs( $threshold )
			)
		);

		$stable = $total - $decaying - $growing;

		$avg_decay = $wpdb->get_var( "SELECT AVG(decay_score) FROM {$table_scores}" );
		// phpcs:enable

		return array(
			'total'     => (int) $total,
			'decaying'  => (int) $decaying,
			'stable'    => (int) $stable,
			'growing'   => (int) $growing,
			'avg_decay' => round( (float) $avg_decay, 1 ),
		);
	}

	/**
	 * Get single post decay info
	 *
	 * @param int $post_id
	 * @return array|null
	 */
	public function get_post_decay( int $post_id ): ?array {
		global $wpdb;

		$table_scores = $wpdb->prefix . 'dcd_scores';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table name built from $wpdb->prefix, not user input; values passed through $wpdb->prepare().
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_scores} WHERE post_id = %d",
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $result ) ? $result : null;
	}
}
