<?php
/**
 * GA4 API Class
 *
 * Handles Google Analytics 4 Data API interactions
 *
 * @package DragonContentDecay
 */

namespace DragonContentDecay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Google\Analytics\Data\V1beta\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\OrderBy;
use Google\Analytics\Data\V1beta\OrderBy\DimensionOrderBy;
use Google\Analytics\Data\V1beta\OrderBy\MetricOrderBy;
use Google\Auth\Credentials\UserRefreshCredentials;

class API_GA4 {

	/**
	 * OAuth instance
	 */
	private OAuth $oauth;

	/**
	 * Analytics Data Client
	 */
	private ?BetaAnalyticsDataClient $client = null;

	/**
	 * Why the most recent fetch_pageviews() call returned no data, or '' when
	 * it succeeded (an empty result can be genuine: a property with no traffic).
	 */
	private string $last_error = '';

	/**
	 * The raw text behind $last_error when it came from a failed request
	 * (technical detail for display), or ''.
	 */
	private string $last_error_detail = '';

	/**
	 * Whether the most recent fetch_pageviews() result is only the top of a
	 * report too large to read in full.
	 */
	private bool $last_truncated = false;

	/**
	 * Rows requested per runReport call (the API's default page size).
	 */
	private const PAGE_SIZE = 10000;

	/**
	 * Most runReport calls per date range, bounding one period to
	 * PAGE_SIZE * MAX_PAGES paths.
	 */
	private const MAX_PAGES = 5;

	/**
	 * API Scopes
	 */
	private const SCOPES = array(
		'https://www.googleapis.com/auth/analytics.readonly',
	);

	/**
	 * Constructor
	 */
	public function __construct( OAuth $oauth ) {
		$this->oauth = $oauth;
	}

	/**
	 * Get Analytics Data Client
	 */
	private function get_client(): ?BetaAnalyticsDataClient {
		if ( $this->client ) {
			return $this->client;
		}

		if ( ! $this->oauth->is_connected() ) {
			return null;
		}

		$client_id     = get_option( 'dragoncontentdecay_google_client_id', '' );
		$client_secret = OAuth::get_client_secret();
		$refresh_token = $this->oauth->get_refresh_token();

		if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
			return null;
		}

		try {
			// Use UserRefreshCredentials for OAuth2 user authentication
			$credentials = new UserRefreshCredentials(
				self::SCOPES,
				array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
				)
			);

			$this->client = new BetaAnalyticsDataClient(
				array(
					'credentials' => $credentials,
				)
			);

			return $this->client;
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DCD GA4 Client Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
			}
			return null;
		}
	}

	/**
	 * Get property ID
	 */
	private function get_property_id(): string {
		return get_option( 'dragoncontentdecay_ga4_property_id', '' );
	}

	/**
	 * Fetch pageviews for a date range
	 *
	 * The report is ordered by views (then path, so pages are stable) and read
	 * page by page until GA4's rowCount is reached, up to MAX_PAGES pages. A
	 * report larger than that keeps its busiest paths and is flagged through
	 * was_truncated(), because a path missing from it may still have views.
	 *
	 * @param string $start_date Format: Y-m-d
	 * @param string $end_date   Format: Y-m-d
	 * @return array Array of [page_path => pageviews]
	 */
	public function fetch_pageviews( string $start_date, string $end_date ): array {
		$this->last_error        = '';
		$this->last_error_detail = '';
		$this->last_truncated    = false;

		$property_id = $this->get_property_id();
		if ( empty( $property_id ) ) {
			$this->last_error = __( 'No GA4 property ID is set on the Settings tab.', 'dragon-content-decay' );
			return array();
		}

		// Use transient cache to avoid hitting API limits. The rows and the
		// truncation flag are cached together.
		$cache_key = 'dragoncontentdecay_ga4report_' . md5( $property_id . $start_date . $end_date );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['rows'] ) && is_array( $cached['rows'] ) ) {
			$this->last_truncated = ! empty( $cached['truncated'] );
			return $cached['rows'];
		}

		try {
			$client = $this->get_client();
			if ( ! $client ) {
				$this->last_error = $this->unavailable_reason();
				return array();
			}

			$run_page = static function ( int $offset, int $limit ) use ( $client, $property_id, $start_date, $end_date ): array {
				$response = $client->runReport(
					array(
						'property'   => 'properties/' . $property_id,
						'dateRanges' => array(
							new DateRange(
								array(
									'start_date' => $start_date,
									'end_date'   => $end_date,
								)
							),
						),
						'dimensions' => array(
							new Dimension( array( 'name' => 'pagePath' ) ),
						),
						'metrics'    => array(
							new Metric( array( 'name' => 'screenPageViews' ) ),
							new Metric( array( 'name' => 'sessions' ) ),
							new Metric( array( 'name' => 'averageSessionDuration' ) ),
						),
						'orderBys'   => array(
							new OrderBy(
								array(
									'metric' => new MetricOrderBy( array( 'metric_name' => 'screenPageViews' ) ),
									'desc'   => true,
								)
							),
							new OrderBy(
								array(
									'dimension' => new DimensionOrderBy( array( 'dimension_name' => 'pagePath' ) ),
								)
							),
						),
						'offset'     => $offset,
						'limit'      => $limit,
					)
				);

				$rows = array();
				foreach ( $response->getRows() as $row ) {
					$path          = $row->getDimensionValues()[0]->getValue();
					$rows[ $path ] = array(
						'pageviews'        => (int) $row->getMetricValues()[0]->getValue(),
						'sessions'         => (int) $row->getMetricValues()[1]->getValue(),
						'avg_time_on_page' => (float) $row->getMetricValues()[2]->getValue(),
					);
				}

				return array(
					'rows'      => $rows,
					'row_count' => (int) $response->getRowCount(),
				);
			};

			$report = self::page_report( $run_page, self::PAGE_SIZE, self::MAX_PAGES );

			$this->last_truncated = $report['truncated'];

			// Cache for 1 hour
			set_transient( $cache_key, $report, HOUR_IN_SECONDS );

			return $report['rows'];
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DCD GA4 API Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
			}
			$this->last_error        = Error_Text::message( Error_Text::classify_exception( $e ), Error_Text::SERVICE_GA4 );
			$this->last_error_detail = Error_Text::detail( $e->getMessage() );
			return array();
		}
	}

	/**
	 * Read a report page by page. Pure: the page runner does the request.
	 *
	 * The offset advances by the rows each page actually returned, and the loop
	 * stops once rowCount rows have been read, on an empty page, or after
	 * $max_pages pages. A row repeated on a later page (the report's window
	 * can still be collecting data) replaces the earlier one rather than being
	 * counted twice.
	 *
	 * @param callable $run_page  fn(int $offset, int $limit): array{rows: array<string,array>, row_count: int}.
	 * @param int      $page_size Rows requested per page.
	 * @param int      $max_pages Most pages to read.
	 * @return array{rows: array<string,array>, truncated: bool} truncated is
	 *               true when fewer rows were read than the report holds.
	 */
	public static function page_report( callable $run_page, int $page_size, int $max_pages ): array {
		$rows      = array();
		$offset    = 0;
		$row_count = 0;

		for ( $page = 0; $page < $max_pages; $page++ ) {
			$result    = (array) call_user_func( $run_page, $offset, $page_size );
			$got       = isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array();
			$row_count = (int) ( $result['row_count'] ?? 0 );

			foreach ( $got as $path => $row ) {
				$rows[ $path ] = $row;
			}

			$offset += count( $got );

			if ( array() === $got || $offset >= $row_count ) {
				break;
			}
		}

		return array(
			'rows'      => $rows,
			'truncated' => $offset < $row_count,
		);
	}

	/**
	 * Whether the most recent fetch_pageviews() result is only the busiest
	 * part of a report too large to read in full.
	 *
	 * @return bool
	 */
	public function was_truncated(): bool {
		return $this->last_truncated;
	}

	/**
	 * Why no API client could be built.
	 *
	 * @return string
	 */
	private function unavailable_reason(): string {
		if ( OAuth::is_access_revoked() ) {
			return __( 'Google no longer accepts the saved sign-in (access was revoked or has expired). Connect to Google again on the Settings tab.', 'dragon-content-decay' );
		}

		if ( ! $this->oauth->has_connection() ) {
			return __( 'Google Analytics is not connected. Connect it on the Settings tab.', 'dragon-content-decay' );
		}

		return __( 'The Google sign-in could not be refreshed just now, so Google Analytics could not be reached. The next sync will try again.', 'dragon-content-decay' );
	}

	/**
	 * Why the most recent fetch_pageviews() call returned no data ('' if it
	 * succeeded).
	 *
	 * @return string
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * The raw text Google or the HTTP library returned for the most recent
	 * failed request ('' if none), for display as technical detail.
	 *
	 * @return string
	 */
	public function get_last_error_detail(): string {
		return $this->last_error_detail;
	}

	/**
	 * Fetch pageviews for comparison periods
	 *
	 * @param int $period_days Number of days to compare (30, 60, 90)
	 * @return array ['current' => [...], 'previous' => [...], 'error' => string, 'error_detail' => string, 'truncated' => ['current' => bool, 'previous' => bool]]
	 *               'error' is non-empty when either request failed, in which
	 *               case the data must not be scored: a missing period would
	 *               read as zero views. 'truncated' marks a period whose report
	 *               was too large to read in full, where a missing path does
	 *               not mean zero views. 'error_detail' is the raw text
	 *               behind 'error' when a request failed.
	 */
	public function fetch_comparison_data( int $period_days = 30 ): array {
		$ranges         = self::comparison_ranges( $period_days, new \DateTimeImmutable( 'now' ) );
		$current_start  = $ranges['current_start'];
		$current_end    = $ranges['current_end'];
		$previous_start = $ranges['previous_start'];
		$previous_end   = $ranges['previous_end'];

		$current           = $this->fetch_pageviews( $current_start, $current_end );
		$current_error     = $this->last_error;
		$current_detail    = $this->last_error_detail;
		$current_truncated = $this->last_truncated;

		$previous = '' === $current_error ? $this->fetch_pageviews( $previous_start, $previous_end ) : array();

		return array(
			'current'      => $current,
			'previous'     => $previous,
			'error'        => '' !== $current_error ? $current_error : $this->last_error,
			'error_detail' => '' !== $current_error ? $current_detail : $this->last_error_detail,
			'truncated'    => array(
				'current'  => $current_truncated,
				'previous' => '' === $current_error && $this->last_truncated,
			),
		);
	}

	/**
	 * The two equal-length comparison windows, in the site's timezone. The
	 * current window ends yesterday: today is a partial day and GA4 is still
	 * processing it, so including it would read as a drop against the
	 * previous window's full days.
	 *
	 * @param int                $period_days Days in each window (at least 1).
	 * @param \DateTimeImmutable $now         The current moment.
	 * @return array{current_start:string,current_end:string,previous_start:string,previous_end:string} Y-m-d dates, inclusive.
	 */
	public static function comparison_ranges( int $period_days, \DateTimeImmutable $now ): array {
		$span = max( 1, $period_days ) - 1;

		$current_end    = $now->setTimezone( wp_timezone() )->setTime( 0, 0 )->modify( '-1 day' );
		$current_start  = $current_end->modify( "-{$span} days" );
		$previous_end   = $current_start->modify( '-1 day' );
		$previous_start = $previous_end->modify( "-{$span} days" );

		return array(
			'current_start'  => $current_start->format( 'Y-m-d' ),
			'current_end'    => $current_end->format( 'Y-m-d' ),
			'previous_start' => $previous_start->format( 'Y-m-d' ),
			'previous_end'   => $previous_end->format( 'Y-m-d' ),
		);
	}

	/**
	 * Reduce a GA4 page path (which includes the install's subfolder, e.g.
	 * /blog/my-post on a site at example.com/blog) to the path below the
	 * WordPress home URL. The subfolder is removed once, on a segment
	 * boundary, so a page at /blog/blog/x keeps its own /blog.
	 *
	 * @param string $path GA4 page path.
	 * @return string|null Path with a leading slash ('/' for home), or null
	 *                     when the path is outside the install.
	 */
	public static function strip_home_path( string $path ): ?string {
		$home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$path = '/' . ltrim( $path, '/' );

		if ( '' === $home ) {
			return $path;
		}

		$prefix = '/' . $home;
		if ( rtrim( $path, '/' ) === $prefix ) {
			return '/';
		}

		if ( ! str_starts_with( $path, $prefix . '/' ) ) {
			return null;
		}

		return substr( $path, strlen( $prefix ) );
	}

	/**
	 * Map a GA4 page path to a post of one of the tracked post types.
	 *
	 * @param string $path GA4 page path, including any install subfolder.
	 * @return int|null Post ID or null if not found
	 */
	public function path_to_post_id( string $path ): ?int {
		$relative = self::strip_home_path( $path );
		if ( null === $relative ) {
			return null;
		}

		$relative = trim( $relative, '/' );
		$types    = Analyzer::tracked_post_types();

		if ( '' !== $relative ) {
			$post = get_page_by_path( $relative, OBJECT, $types );
			if ( $post ) {
				return (int) $post->ID;
			}
		}

		// url_to_postid() answers for any post type, so keep only tracked ones.
		$post_id = url_to_postid( home_url( $relative ) );
		if ( $post_id <= 0 || ! in_array( get_post_type( $post_id ), $types, true ) ) {
			return null;
		}

		return $post_id;
	}

	/**
	 * Test connection to GA4
	 */
	public function test_connection(): bool {
		$property_id = $this->get_property_id();
		if ( empty( $property_id ) ) {
			return false;
		}

		try {
			$client = $this->get_client();
			if ( ! $client ) {
				return false;
			}

			// Try a simple API call
			$today    = ( new \DateTime() )->format( 'Y-m-d' );
			$response = $client->runReport(
				array(
					'property'   => 'properties/' . $property_id,
					'dateRanges' => array(
						new DateRange(
							array(
								'start_date' => $today,
								'end_date'   => $today,
							)
						),
					),
					'metrics'    => array(
						new Metric( array( 'name' => 'sessions' ) ),
					),
					'limit'      => 1,
				)
			);

			return true;
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DCD GA4 Connection Test Failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
			}
			return false;
		}
	}
}
