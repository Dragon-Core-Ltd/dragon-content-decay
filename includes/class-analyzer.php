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
	 * Trend constants
	 */
	public const TREND_DECAYING = 'decaying';
	public const TREND_STABLE   = 'stable';
	public const TREND_GROWING  = 'growing';

	/**
	 * Constructor
	 */
	public function __construct( API_GA4 $api_ga4 ) {
		$this->api_ga4 = $api_ga4;
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

		$analyzed = 0;

		// Get all paths from both periods
		$all_paths = array_unique(
			array_merge(
				array_keys( $data['current'] ),
				array_keys( $data['previous'] )
			)
		);

		foreach ( $all_paths as $path ) {
			$post_id = $this->api_ga4->path_to_post_id( $path );
			if ( ! $post_id ) {
				continue;
			}

			$current_views  = $data['current'][ $path ]['pageviews'] ?? 0;
			$previous_views = $data['previous'][ $path ]['pageviews'] ?? 0;

			$this->calculate_and_store_score( $post_id, $current_views, $previous_views );
			++$analyzed;
		}

		return $analyzed;
	}

	/**
	 * Calculate and store decay score for a single post
	 */
	public function calculate_and_store_score( int $post_id, int $current_views, int $previous_views ): void {
		$score = $this->calculate_decay_score( $current_views, $previous_views );
		$trend = $this->determine_trend( $score );

		global $wpdb;
		$table_scores = $wpdb->prefix . 'dcd_scores';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to a plugin-owned custom table; no core API or cache applies.
		$wpdb->replace(
			$table_scores,
			array(
				'post_id'            => $post_id,
				'decay_score'        => $score,
				'trend'              => $trend,
				'pageviews_current'  => $current_views,
				'pageviews_previous' => $previous_views,
			),
			array( '%d', '%f', '%s', '%d', '%d' )
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
