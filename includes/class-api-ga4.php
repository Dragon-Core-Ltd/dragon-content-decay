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
     * API Scopes
     */
    private const SCOPES = [
        'https://www.googleapis.com/auth/analytics.readonly',
    ];

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

        $client_id     = get_option( 'dcd_google_client_id', '' );
        $client_secret = get_option( 'dcd_google_client_secret', '' );
        $refresh_token = $this->oauth->get_refresh_token();

        if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
            return null;
        }

        try {
            // Use UserRefreshCredentials for OAuth2 user authentication
            $credentials = new UserRefreshCredentials(
                self::SCOPES,
                [
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                    'refresh_token' => $refresh_token,
                ]
            );

            $this->client = new BetaAnalyticsDataClient( [
                'credentials' => $credentials,
            ] );

            return $this->client;
        } catch ( \Exception $e ) {
            error_log( 'DCD GA4 Client Error: ' . $e->getMessage() );
            return null;
        }
    }

    /**
     * Get property ID
     */
    private function get_property_id(): string {
        return get_option( 'dcd_ga4_property_id', '' );
    }

    /**
     * Fetch pageviews for a date range
     *
     * @param string $start_date Format: Y-m-d
     * @param string $end_date   Format: Y-m-d
     * @return array Array of [page_path => pageviews]
     */
    public function fetch_pageviews( string $start_date, string $end_date ): array {
        $property_id = $this->get_property_id();
        if ( empty( $property_id ) ) {
            return [];
        }

        // Use transient cache to avoid hitting API limits
        $cache_key = 'dcd_pageviews_' . md5( $property_id . $start_date . $end_date );
        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        try {
            $client = $this->get_client();
            if ( ! $client ) {
                return [];
            }

            $response = $client->runReport( [
                'property'   => 'properties/' . $property_id,
                'dateRanges' => [
                    new DateRange( [
                        'start_date' => $start_date,
                        'end_date'   => $end_date,
                    ] ),
                ],
                'dimensions' => [
                    new Dimension( [ 'name' => 'pagePath' ] ),
                ],
                'metrics'    => [
                    new Metric( [ 'name' => 'screenPageViews' ] ),
                    new Metric( [ 'name' => 'sessions' ] ),
                    new Metric( [ 'name' => 'averageSessionDuration' ] ),
                ],
                'limit'      => 10000,
            ] );

            $results = [];
            foreach ( $response->getRows() as $row ) {
                $path = $row->getDimensionValues()[0]->getValue();
                $results[ $path ] = [
                    'pageviews'         => (int) $row->getMetricValues()[0]->getValue(),
                    'sessions'          => (int) $row->getMetricValues()[1]->getValue(),
                    'avg_time_on_page'  => (float) $row->getMetricValues()[2]->getValue(),
                ];
            }

            // Cache for 1 hour
            set_transient( $cache_key, $results, HOUR_IN_SECONDS );

            return $results;
        } catch ( \Exception $e ) {
            error_log( 'DCD GA4 API Error: ' . $e->getMessage() );
            return [];
        }
    }

    /**
     * Fetch pageviews for comparison periods
     *
     * @param int $period_days Number of days to compare (30, 60, 90)
     * @return array ['current' => [...], 'previous' => [...]]
     */
    public function fetch_comparison_data( int $period_days = 30 ): array {
        $today = new \DateTime();
        $current_end = $today->format( 'Y-m-d' );

        $current_start_date = clone $today;
        $current_start_date->modify( "-{$period_days} days" );
        $current_start = $current_start_date->format( 'Y-m-d' );

        $previous_end_date = clone $current_start_date;
        $previous_end_date->modify( '-1 day' );
        $previous_end = $previous_end_date->format( 'Y-m-d' );

        $previous_start_date = clone $previous_end_date;
        $previous_start_date->modify( "-{$period_days} days" );
        $previous_start = $previous_start_date->format( 'Y-m-d' );

        return [
            'current'  => $this->fetch_pageviews( $current_start, $current_end ),
            'previous' => $this->fetch_pageviews( $previous_start, $previous_end ),
        ];
    }

    /**
     * Map URL path to post ID
     *
     * @param string $path URL path (e.g., /my-blog-post/)
     * @return int|null Post ID or null if not found
     */
    public function path_to_post_id( string $path ): ?int {
        // Remove leading/trailing slashes
        $path = trim( $path, '/' );

        // Try to find post by slug
        $post = get_page_by_path( $path, OBJECT, get_option( 'dcd_post_types', [ 'post' ] ) );

        if ( $post ) {
            return $post->ID;
        }

        // Try url_to_postid as fallback
        $url = home_url( $path );
        $post_id = url_to_postid( $url );

        return $post_id > 0 ? $post_id : null;
    }

    /**
     * Sync analytics data to database
     *
     * @param int $period_days Comparison period in days
     * @return int Number of posts synced
     */
    public function sync_data( int $period_days = 30 ): int {
        $data = $this->fetch_comparison_data( $period_days );

        if ( empty( $data['current'] ) && empty( $data['previous'] ) ) {
            return 0;
        }

        global $wpdb;
        $table_analytics = $wpdb->prefix . 'dcd_analytics';
        $today = current_time( 'Y-m-d' );
        $synced = 0;

        foreach ( $data['current'] as $path => $metrics ) {
            $post_id = $this->path_to_post_id( $path );
            if ( ! $post_id ) {
                continue;
            }

            // Insert or update analytics record
            $wpdb->replace(
                $table_analytics,
                [
                    'post_id'          => $post_id,
                    'date'             => $today,
                    'pageviews'        => $metrics['pageviews'],
                    'sessions'         => $metrics['sessions'],
                    'avg_time_on_page' => $metrics['avg_time_on_page'],
                ],
                [ '%d', '%s', '%d', '%d', '%f' ]
            );

            $synced++;
        }

        return $synced;
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
            $today = ( new \DateTime() )->format( 'Y-m-d' );
            $response = $client->runReport( [
                'property'   => 'properties/' . $property_id,
                'dateRanges' => [
                    new DateRange( [
                        'start_date' => $today,
                        'end_date'   => $today,
                    ] ),
                ],
                'metrics'    => [
                    new Metric( [ 'name' => 'sessions' ] ),
                ],
                'limit'      => 1,
            ] );

            return true;
        } catch ( \Exception $e ) {
            error_log( 'DCD GA4 Connection Test Failed: ' . $e->getMessage() );
            return false;
        }
    }
}
