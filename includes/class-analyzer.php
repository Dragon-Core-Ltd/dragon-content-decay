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
	 * @return array{analyzed:int,failed:int,cursor_saved:bool,pending?:bool,error?:string,search_error?:string}
	 *               Posts scored, posts whose score row could not be written,
	 *               whether the partly resolved paths were persisted for the
	 *               next run, whether the run ran out of time before every
	 *               path was matched to a post (nothing is scored then),
	 *               why the run was aborted when the GA4 data could not be
	 *               fetched, and why the Search Console data could not be
	 *               fetched (the stored search columns are then kept).
	 */
	public function analyze_all(): array {
		$period_days = (int) get_option( 'dragoncontentdecay_comparison_period', 30 );
		$data        = $this->api_ga4->fetch_comparison_data( $period_days );

		// A failed request is not zero views: scoring half the data would flag
		// every post as +/-100%. Keep the existing scores and report why.
		$fetch_error = (string) ( $data['error'] ?? '' );
		if ( '' !== $fetch_error ) {
			return array(
				'analyzed'     => 0,
				'failed'       => 0,
				'cursor_saved' => true,
				'error'        => $fetch_error,
			);
		}

		// A truncated report is sorted by views, busiest first, so a path it
		// left out had at most as many views as the last row it did return.
		$cutoff = array(
			'current'  => self::report_cutoff( (array) ( $data['current'] ?? array() ) ),
			'previous' => self::report_cutoff( (array) ( $data['previous'] ?? array() ) ),
		);

		// Key both periods by the same normalised path the GSC map uses so the
		// join below is exact and GA4 rows that differ only in trailing slash or
		// encoding are counted once.
		$data['current']  = self::normalize_ga4_map( (array) ( $data['current'] ?? array() ) );
		$data['previous'] = self::normalize_ga4_map( (array) ( $data['previous'] ?? array() ) );
		$truncated        = array(
			'current'  => ! empty( $data['truncated']['current'] ),
			'previous' => ! empty( $data['truncated']['previous'] ),
		);

		$result = array(
			'analyzed'     => 0,
			'failed'       => 0,
			'cursor_saved' => true,
		);

		if ( empty( $data['current'] ) && empty( $data['previous'] ) ) {
			return $result;
		}

		// All paths from both periods.
		$all_paths = array_values(
			array_unique(
				array_merge(
					array_keys( $data['current'] ),
					array_keys( $data['previous'] )
				)
			)
		);

		if ( empty( $all_paths ) ) {
			return $result;
		}

		// Pre-resolve leaf slugs in one query so a large GA4 property (up to ~20k
		// paths across both periods) doesn't run a per-path database lookup and
		// time the sync out. Ambiguous or unmatched slugs fall back to the precise
		// resolver.
		$slug_map = $this->build_slug_map( $all_paths );

		// Every path must be resolved before any post is scored, because a post's
		// views are the sum over all of its paths. The wall-clock budget keeps a
		// large property (thousands of multi-segment paths, each needing a precise
		// rewrite lookup) from exhausting max_execution_time; when it runs out the
		// resolutions so far are saved and the next run carries on from them.
		$resolved = $this->resolve_all( $all_paths, $slug_map );
		if ( null === $resolved['map'] ) {
			$result['pending']      = true;
			$result['cursor_saved'] = $resolved['saved'];
			return $result;
		}
		$ids = $resolved['map'];

		// Optional Google Search Console signal, keyed by the same normalised
		// paths. A failed fetch is not zero clicks: the stored search columns
		// are then left as they were and the reason is reported.
		$gsc                    = $this->maybe_fetch_gsc( $period_days );
		$search_error           = (string) ( $gsc['error'] ?? '' );
		$keep_search            = '' !== $search_error;
		$result['search_error'] = $search_error;
		$search_truncated       = array(
			'current'  => ! empty( $gsc['truncated']['current'] ),
			'previous' => ! empty( $gsc['truncated']['previous'] ),
		);

		$posts = array();
		foreach ( $all_paths as $path ) {
			$post_id = $this->counted_post_id( $path, $ids );
			if ( 0 === $post_id ) {
				continue;
			}

			if ( ! isset( $posts[ $post_id ] ) ) {
				$posts[ $post_id ] = array(
					'current'      => 0,
					'previous'     => 0,
					'missing'      => array(
						'current'  => 0,
						'previous' => 0,
					),
					'keep_search'  => $keep_search,
					'search_paths' => array(),
				);
			}

			// A period whose report was too large to read in full holds only
			// its busiest paths, so a path missing from it may still have had
			// views, up to that report's cutoff.
			foreach ( array( 'current', 'previous' ) as $period ) {
				if ( $truncated[ $period ] && ! isset( $data[ $period ][ $path ] ) ) {
					++$posts[ $post_id ]['missing'][ $period ];
				}
			}

			$posts[ $post_id ]['current']  += (int) ( $data['current'][ $path ]['pageviews'] ?? 0 );
			$posts[ $post_id ]['previous'] += (int) ( $data['previous'][ $path ]['pageviews'] ?? 0 );

			// Likewise for Search Console: a path missing from a period that was
			// only partly read may still have had clicks there, so the post's
			// stored search values are kept rather than replaced with a false sum.
			if ( ( $search_truncated['current'] && ! isset( $gsc['current'][ $path ] ) )
				|| ( $search_truncated['previous'] && ! isset( $gsc['previous'][ $path ] ) ) ) {
				$posts[ $post_id ]['keep_search'] = true;
			}
			$posts[ $post_id ]['search_paths'][] = $path;
		}

		ksort( $posts );

		$uncertain = array();
		foreach ( $posts as $post_id => $post ) {
			if ( self::is_uncertain( $post, $cutoff ) ) {
				$uncertain[] = (int) $post_id;
			}

			$search = $post['keep_search'] ? null : $this->build_search_metrics( $post['search_paths'], $gsc );

			if ( $this->calculate_and_store_score( $post_id, $post['current'], $post['previous'], $search, $post['keep_search'] ) ) {
				++$result['analyzed'];
			} else {
				++$result['failed'];
			}
		}

		if ( empty( $uncertain ) ) {
			delete_option( self::UNCERTAIN_OPTION );
		} else {
			update_option( self::UNCERTAIN_OPTION, $uncertain, false );
		}

		$this->prune_untracked_scores();

		// The resolutions are only carried over while a pass is unfinished;
		// starting afresh picks up renamed and newly published posts.
		delete_option( self::RESOLVED_OPTION );
		delete_option( 'dragoncontentdecay_analyze_cursor' );

		return $result;
	}

	/**
	 * Option listing the posts last scored from a partly read report whose
	 * missing paths could have changed the result.
	 */
	public const UNCERTAIN_OPTION = 'dragoncontentdecay_uncertain_posts';

	/**
	 * Share of a post's views the paths missing from a truncated report may
	 * reach before its score is marked uncertain.
	 */
	private const MISSING_SHARE = 0.1;

	/**
	 * The fewest views any row of a GA4 report holds, 0 for an empty report.
	 *
	 * @param array<string,array> $rows Raw GA4 rows keyed by pagePath.
	 * @return int
	 */
	private static function report_cutoff( array $rows ): int {
		$cutoff = null;
		foreach ( $rows as $row ) {
			$views  = (int) ( is_array( $row ) ? ( $row['pageviews'] ?? 0 ) : 0 );
			$cutoff = null === $cutoff ? $views : min( $cutoff, $views );
		}

		return (int) $cutoff;
	}

	/**
	 * Whether the views a post's missing paths may have had could change its
	 * score materially: more than MISSING_SHARE of its busier period.
	 *
	 * @param array                                $post   Aggregated post (current, previous, missing).
	 * @param array{current:int,previous:int}      $cutoff Views cutoff of each period's report.
	 * @return bool
	 */
	private static function is_uncertain( array $post, array $cutoff ): bool {
		$known = max( (int) $post['current'], (int) $post['previous'] );

		foreach ( array( 'current', 'previous' ) as $period ) {
			$missing = (int) $post['missing'][ $period ];
			if ( $missing > 0 && $missing * (int) $cutoff[ $period ] > $known * self::MISSING_SHARE ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete the score rows of posts that no longer exist or whose type is
	 * no longer tracked. The readers filter on the tracked types as well, so
	 * a failed delete only leaves hidden rows behind.
	 */
	private function prune_untracked_scores(): void {
		global $wpdb;

		$table_scores = $wpdb->prefix . 'dcd_scores';
		$types        = self::tracked_post_types();
		$type_ph      = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin table name built from $wpdb->prefix and a fixed list of %s placeholders; every value is prepared.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE s FROM {$table_scores} s
				 LEFT JOIN {$wpdb->posts} p ON s.post_id = p.ID
				 WHERE p.ID IS NULL OR p.post_type NOT IN ( {$type_ph} )",
				...$types
			)
		);
		// phpcs:enable
	}

	/**
	 * The tracked post types as a prepared SQL condition on posts alias p.
	 *
	 * @return string
	 */
	private static function tracked_types_condition(): string {
		global $wpdb;

		$types   = self::tracked_post_types();
		$type_ph = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $type_ph is a fixed list of %s placeholders.
		return $wpdb->prepare( "p.post_type IN ({$type_ph})", ...$types );
	}

	/**
	 * Option holding the path => post ID resolutions of an unfinished pass.
	 */
	private const RESOLVED_OPTION = 'dragoncontentdecay_resolved_paths';

	/**
	 * Resolve every path (and the base path of each variant-shaped path that
	 * resolved) to a post ID, 0 for none, within the time budget. At least one path is
	 * resolved per call so successive runs always progress.
	 *
	 * @param array<int,string>            $paths    Normalised GA4 paths.
	 * @param array<string,array<int,int>> $slug_map Leaf-slug map.
	 * @return array{map:?array<string,int>,saved:bool} The full map, or null
	 *               when the budget ran out (then 'saved' says whether the
	 *               partial map was persisted for the next run).
	 */
	private function resolve_all( array $paths, array $slug_map ): array {
		$map = get_option( self::RESOLVED_OPTION, array() );
		$map = is_array( $map ) ? $map : array();

		$deadline = microtime( true ) + $this->time_budget();
		$progress = false;

		foreach ( $paths as $path ) {
			$base = self::variant_base( $path );

			foreach ( array( $path, $base ) as $key ) {
				// The base only matters for a path that resolved to a post.
				if ( null === $key || array_key_exists( $key, $map ) ) {
					continue;
				}
				if ( $key === $base && (int) ( $map[ $path ] ?? 0 ) <= 0 ) {
					continue;
				}

				if ( $progress && microtime( true ) > $deadline ) {
					update_option( self::RESOLVED_OPTION, $map, false );
					return array(
						'map'   => null,
						'saved' => get_option( self::RESOLVED_OPTION, null ) === $map,
					);
				}

				$map[ $key ] = (int) $this->resolve_path( $key, $slug_map );
				$progress    = true;
			}
		}

		return array(
			'map'   => $map,
			'saved' => true,
		);
	}

	/**
	 * The post a path's views count towards, or 0. A feed, embed, AMP,
	 * comment-page or paged variant of a post (its path minus that suffix
	 * resolves to the same post) is not counted: those are not reads of the
	 * post. A path whose suffix is part of the real permalink, such as
	 * /archives/123 or a child page named "amp", is counted.
	 *
	 * @param string            $path Normalised GA4 path.
	 * @param array<string,int> $ids  Path => resolved post ID.
	 * @return int
	 */
	private function counted_post_id( string $path, array $ids ): int {
		$post_id = (int) ( $ids[ $path ] ?? 0 );
		if ( $post_id <= 0 ) {
			return 0;
		}

		$base = self::variant_base( $path );
		if ( null !== $base && (int) ( $ids[ $base ] ?? 0 ) === $post_id ) {
			return 0;
		}

		return $post_id;
	}

	/**
	 * The path without a trailing feed, embed, AMP, comment-page or
	 * pagination suffix, or null when it has none.
	 *
	 * @param string $path Normalised path (see path_key()).
	 * @return string|null
	 */
	public static function variant_base( string $path ): ?string {
		if ( preg_match( '#^(/.+?)/(?:feed(?:/(?:feed|rdf|rss|rss2|atom))?|embed|amp|comment-page-[0-9]+|page/[0-9]+|[0-9]+)$#', $path, $m ) ) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Reduce a URL path to the key both the GA4 and GSC maps are joined on:
	 * query string and fragment dropped, each segment decoded once and
	 * canonically re-encoded (so "%2F", "%3F", "%23" and "%25" survive as
	 * escapes and the result is idempotent), no trailing slash, always a
	 * leading slash ("/" for the root). Case is preserved because WordPress
	 * slugs are case-sensitive on lookup.
	 *
	 * @param string $path URL path, with or without surrounding slashes.
	 * @return string
	 */
	public static function path_key( string $path ): string {
		$path     = substr( $path, 0, strcspn( $path, '?#' ) );
		$segments = explode( '/', trim( $path, '/' ) );

		foreach ( $segments as $i => $segment ) {
			$segments[ $i ] = rawurlencode( rawurldecode( $segment ) );
		}

		return '/' . implode( '/', $segments );
	}

	/**
	 * Re-key a GA4 pagePath => metrics map by path_key(), summing rows that
	 * collapse onto one key (pageviews and sessions added; average time on page
	 * session-weighted, plain mean when no sessions were recorded).
	 *
	 * @param array<string,array> $rows Raw GA4 rows keyed by pagePath.
	 * @return array<string,array{pageviews:int,sessions:int,avg_time_on_page:float}>
	 */
	public static function normalize_ga4_map( array $rows ): array {
		$out  = array();
		$time = array();

		foreach ( $rows as $path => $row ) {
			$key      = self::path_key( (string) $path );
			$row      = is_array( $row ) ? $row : array();
			$sessions = (int) ( $row['sessions'] ?? 0 );
			$avg      = (float) ( $row['avg_time_on_page'] ?? 0 );

			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ]  = array(
					'pageviews'        => 0,
					'sessions'         => 0,
					'avg_time_on_page' => 0.0,
				);
				$time[ $key ] = array(
					'weighted' => 0.0,
					'sum'      => 0.0,
					'rows'     => 0,
				);
			}

			$out[ $key ]['pageviews'] += (int) ( $row['pageviews'] ?? 0 );
			$out[ $key ]['sessions']  += $sessions;
			$time[ $key ]['weighted'] += $avg * $sessions;
			$time[ $key ]['sum']      += $avg;
			++$time[ $key ]['rows'];
		}

		foreach ( $out as $key => $row ) {
			if ( $row['sessions'] > 0 ) {
				$out[ $key ]['avg_time_on_page'] = $time[ $key ]['weighted'] / $row['sessions'];
			} elseif ( $time[ $key ]['rows'] > 0 ) {
				$out[ $key ]['avg_time_on_page'] = $time[ $key ]['sum'] / $time[ $key ]['rows'];
			}
		}

		return $out;
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
	 * The post types chosen under Post Types to Track ('post' when none are).
	 *
	 * @return array<int,string>
	 */
	public static function tracked_post_types(): array {
		$types = array_values( array_filter( array_map( 'strval', (array) get_option( 'dragoncontentdecay_post_types', array( 'post' ) ) ) ) );

		return empty( $types ) ? array( 'post' ) : $types;
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
			$trimmed = trim( (string) API_GA4::strip_home_path( (string) $path ), '/' );
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

		$types = self::tracked_post_types();

		$map     = array();
		$type_ph = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		foreach ( array_chunk( $slugs, 500 ) as $chunk ) {
			$slug_ph = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom read; the query is prepared below with only fixed %s placeholder lists interpolated.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $slug_ph/$type_ph are fixed lists of %s placeholders and $wpdb->posts is the core table name; all values are prepared.
					"SELECT ID, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_name IN ( {$slug_ph} ) AND post_type IN ( {$type_ph} )",
					array_merge( $chunk, $types )
				)
			);
			foreach ( (array) $rows as $row ) {
				/*
				 * Keyed through path_key() so the map and the path being looked up
				 * are spelled identically. WordPress builds post_name with
				 * lowercase percent escapes while path_key() emits uppercase, so
				 * keying on the raw slug missed every non-Latin one and sent it to
				 * the slow resolver the map exists to avoid.
				 */
				$map[ ltrim( self::path_key( (string) $row->post_name ), '/' ) ][] = (int) $row->ID;
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
		$relative = API_GA4::strip_home_path( $path );
		if ( null === $relative ) {
			return null;
		}
		$trimmed = trim( $relative, '/' );

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

		// The full GA4 path: path_to_post_id() removes the install subfolder itself.
		return $this->api_ga4->path_to_post_id( $path );
	}

	/**
	 * Calculate and store decay score for a single post.
	 *
	 * The decay score stays GA4-pageviews based; the optional Search Console
	 * metrics are stored alongside as a supplementary signal. $wpdb->replace
	 * rewrites the whole row, so the search_* columns are always written (0 when
	 * no GSC data applies) rather than being reset to defaults. With
	 * $keep_search (the Search Console fetch failed) only the pageview columns
	 * are written and the stored search_* values are left as they were.
	 *
	 * @param int        $post_id        Post ID.
	 * @param int        $current_views  Current-period pageviews.
	 * @param int        $previous_views Previous-period pageviews.
	 * @param array|null $search         Optional GSC metrics for this post.
	 * @param bool       $keep_search    Keep the stored search_* columns.
	 * @return bool Whether the score row was written.
	 */
	public function calculate_and_store_score( int $post_id, int $current_views, int $previous_views, ?array $search = null, bool $keep_search = false ): bool {
		$score  = $this->calculate_decay_score( $current_views, $previous_views );
		$trend  = $this->determine_trend( $score );
		$search = is_array( $search ) ? $search : array();

		global $wpdb;
		$table_scores = $wpdb->prefix . 'dcd_scores';

		if ( $keep_search ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to a plugin-owned custom table; no core API or cache applies.
			$written = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is the site prefix plus a fixed basename (%i needs WordPress 6.2; the plugin supports 6.0).
					"INSERT INTO {$table_scores} (post_id, decay_score, trend, pageviews_current, pageviews_previous)
					 VALUES (%d, %f, %s, %d, %d)
					 ON DUPLICATE KEY UPDATE decay_score = VALUES(decay_score), trend = VALUES(trend),
						pageviews_current = VALUES(pageviews_current), pageviews_previous = VALUES(pageviews_previous),
						last_calculated = CURRENT_TIMESTAMP",
					$post_id,
					$score,
					$trend,
					$current_views,
					$previous_views
				)
			);

			return false !== $written;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing to a plugin-owned custom table; no core API or cache applies.
		$written = $wpdb->replace(
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

		return false !== $written;
	}

	/**
	 * Fetch the Search Console comparison data when GSC is enabled and the scope
	 * has been granted; otherwise an empty structure.
	 *
	 * @param int $period_days Comparison period.
	 * @return array{current: array<string,array>, previous: array<string,array>, error?: string, truncated?: array{current: bool, previous: bool}}
	 */
	private function maybe_fetch_gsc( int $period_days ): array {
		$empty = array(
			'current'   => array(),
			'previous'  => array(),
			'error'     => '',
			'truncated' => array(
				'current'  => false,
				'previous' => false,
			),
		);

		if ( ! get_option( 'dragoncontentdecay_gsc_enabled' ) || ! OAuth::has_searchconsole_scope() ) {
			return $empty;
		}

		return $this->api_gsc->fetch_comparison_data( $period_days );
	}

	/**
	 * Build the per-post Search Console metric set summed over the post's
	 * paths, or null if there is no GSC data for any of them. Position is the
	 * impression-weighted mean of the current period (a plain mean when no
	 * impressions were recorded).
	 *
	 * @param array<int,string> $paths Normalised path keys (see path_key()).
	 * @param array             $gsc   GSC comparison data keyed the same way.
	 * @return array|null
	 */
	private function build_search_metrics( array $paths, array $gsc ): ?array {
		$out      = array(
			'clicks_current'       => 0,
			'clicks_previous'      => 0,
			'impressions_current'  => 0,
			'impressions_previous' => 0,
			'position'             => 0.0,
		);
		$found    = false;
		$weighted = 0.0;
		$sum      = 0.0;
		$rows     = 0;

		foreach ( array_unique( array_map( array( self::class, 'path_key' ), $paths ) ) as $path ) {
			$cur  = $gsc['current'][ $path ] ?? null;
			$prev = $gsc['previous'][ $path ] ?? null;

			if ( null === $cur && null === $prev ) {
				continue;
			}
			$found = true;

			$out['clicks_current']       += (int) ( $cur['clicks'] ?? 0 );
			$out['clicks_previous']      += (int) ( $prev['clicks'] ?? 0 );
			$out['impressions_current']  += (int) ( $cur['impressions'] ?? 0 );
			$out['impressions_previous'] += (int) ( $prev['impressions'] ?? 0 );

			if ( null !== $cur ) {
				$weighted += (float) ( $cur['position'] ?? 0 ) * (int) ( $cur['impressions'] ?? 0 );
				$sum      += (float) ( $cur['position'] ?? 0 );
				++$rows;
			}
		}

		if ( ! $found ) {
			return null;
		}

		if ( $out['impressions_current'] > 0 ) {
			$out['position'] = $weighted / $out['impressions_current'];
		} elseif ( $rows > 0 ) {
			$out['position'] = $sum / $rows;
		}

		return $out;
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
	 * Clamp a decay threshold to -100..-1. At 0 or above an unchanged post
	 * (score 0) would count as both decaying and growing.
	 *
	 * @param mixed $value Stored or submitted threshold.
	 * @return int
	 */
	public static function clamp_threshold( $value ): int {
		return max( -100, min( -1, (int) $value ) );
	}

	/**
	 * The decay threshold setting, within -100..-1.
	 *
	 * @return int
	 */
	public static function decay_threshold(): int {
		return self::clamp_threshold( get_option( 'dragoncontentdecay_decay_threshold', -20 ) );
	}

	/**
	 * Determine trend based on decay score
	 *
	 * @param float $score Decay score percentage
	 * @return string Trend constant
	 */
	public function determine_trend( float $score ): string {
		$threshold = self::decay_threshold();

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
		$types        = self::tracked_types_condition();
		$threshold    = self::decay_threshold();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table name built from $wpdb->prefix, not user input; values passed through $wpdb->prepare().
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, p.post_title, p.post_date, p.post_modified
                 FROM {$table_scores} s
                 JOIN {$wpdb->posts} p ON s.post_id = p.ID
                 WHERE s.decay_score <= %f
                 AND p.post_status = 'publish'
                 AND {$types}
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
	 * Replace each row's stored trend with the trend its score has under the
	 * CURRENT threshold. The stored label reflects the threshold at sync time;
	 * the summary counts use the live setting, so every screen re-derives it.
	 *
	 * @param array $rows Score rows with a decay_score field.
	 * @return array
	 */
	public function with_current_trend( array $rows ): array {
		foreach ( $rows as $i => $row ) {
			if ( is_array( $row ) && isset( $row['decay_score'] ) ) {
				$rows[ $i ]['trend'] = $this->determine_trend( (float) $row['decay_score'] );
			}
		}
		return $rows;
	}

	/**
	 * Rows for the dashboard table: published posts, lowest score first, with
	 * the trend judged against the current threshold.
	 *
	 * @param int $limit Maximum number of rows.
	 * @return array
	 */
	public function get_dashboard_rows( int $limit = 100 ): array {
		global $wpdb;

		$table_scores = $wpdb->prefix . 'dcd_scores';
		$types        = self::tracked_types_condition();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table name built from $wpdb->prefix, not user input; values passed through $wpdb->prepare().
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, p.post_title, p.post_date, p.post_modified
                 FROM {$table_scores} s
                 JOIN {$wpdb->posts} p ON s.post_id = p.ID
                 WHERE p.post_status = 'publish'
                 AND {$types}
                 ORDER BY s.decay_score ASC
                 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $results ) ? $this->with_uncertain_mark( $this->with_current_trend( $results ) ) : array();
	}

	/**
	 * Mark each row whose score was last calculated from a partly read report
	 * (see UNCERTAIN_OPTION).
	 *
	 * @param array $rows Score rows with a post_id field.
	 * @return array
	 */
	public function with_uncertain_mark( array $rows ): array {
		$uncertain = array_flip( array_map( 'intval', (array) get_option( self::UNCERTAIN_OPTION, array() ) ) );

		foreach ( $rows as $i => $row ) {
			if ( is_array( $row ) ) {
				$rows[ $i ]['uncertain'] = isset( $uncertain[ (int) ( $row['post_id'] ?? 0 ) ] );
			}
		}
		return $rows;
	}

	/**
	 * Get posts by trend, judged by score against the current threshold.
	 *
	 * @param string $trend One of TREND_DECAYING, TREND_STABLE, TREND_GROWING
	 * @param int    $limit Maximum number of posts
	 * @return array
	 */
	public function get_posts_by_trend( string $trend, int $limit = 50 ): array {
		global $wpdb;

		$table_scores = $wpdb->prefix . 'dcd_scores';
		$types        = self::tracked_types_condition();
		$threshold    = self::decay_threshold();

		if ( self::TREND_DECAYING === $trend ) {
			$where = $wpdb->prepare( 's.decay_score <= %f', $threshold );
			$order = 'ASC';
		} elseif ( self::TREND_GROWING === $trend ) {
			$where = $wpdb->prepare( 's.decay_score >= %f', abs( $threshold ) );
			$order = 'DESC';
		} else {
			$where = $wpdb->prepare( 's.decay_score > %f AND s.decay_score < %f', $threshold, abs( $threshold ) );
			$order = 'ASC';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table name built from $wpdb->prefix, $where built by $wpdb->prepare() above and $order limited to a hardcoded ASC/DESC keyword.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, p.post_title, p.post_date, p.post_modified
                 FROM {$table_scores} s
                 JOIN {$wpdb->posts} p ON s.post_id = p.ID
                 WHERE {$where}
                 AND p.post_status = 'publish'
                 AND {$types}
                 ORDER BY s.decay_score {$order}
                 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $results ) ? $this->with_current_trend( $results ) : array();
	}

	/**
	 * Get summary statistics over published posts (the same set the dashboard
	 * table and the digest list), judged against the current threshold.
	 *
	 * @return array
	 */
	public function get_summary(): array {
		global $wpdb;

		$table_scores = $wpdb->prefix . 'dcd_scores';
		$threshold    = self::decay_threshold();
		$types        = self::tracked_types_condition();
		$from         = "{$table_scores} s JOIN {$wpdb->posts} p ON s.post_id = p.ID WHERE p.post_status = 'publish' AND {$types}";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table name built from $wpdb->prefix and the core posts table, not user input; values passed through $wpdb->prepare().
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$from}" );

		$decaying = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$from} AND s.decay_score <= %f",
				$threshold
			)
		);

		$growing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$from} AND s.decay_score >= %f",
				abs( $threshold )
			)
		);

		$stable = max( 0, $total - $decaying - $growing );

		$avg_decay = $wpdb->get_var( "SELECT AVG(s.decay_score) FROM {$from}" );
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
		$types        = self::tracked_types_condition();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table name built from $wpdb->prefix and a prepared post-type condition; values passed through $wpdb->prepare().
		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.* FROM {$table_scores} s
                 JOIN {$wpdb->posts} p ON s.post_id = p.ID
                 WHERE s.post_id = %d
                 AND {$types}",
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $result ) ) {
			return null;
		}

		return $this->with_uncertain_mark( $this->with_current_trend( array( $result ) ) )[0];
	}
}
