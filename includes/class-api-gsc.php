<?php
/**
 * GSC API Class
 *
 * Google Search Console (Search Analytics) integration — an optional signal
 * alongside GA4 pageviews. Uses the same OAuth connection with the added
 * webmasters.readonly scope and a plain HTTPS client against the documented
 * Search Console API v3 endpoint.
 *
 * @package DragonContentDecay
 */

namespace DragonContentDecay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class API_GSC {

	/**
	 * OAuth instance.
	 */
	private OAuth $oauth;

	/**
	 * HTTP transport: fn(string $method, string $url, array $body, string $token): array{code:int, body:string}.
	 * Overridable for tests.
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Search Console API base.
	 */
	private const API_BASE = 'https://searchconsole.googleapis.com/webmasters/v3';

	/**
	 * Search Console data lags a couple of days; offset windows so complete
	 * periods are compared.
	 */
	private const DATA_LAG_DAYS = 3;

	/**
	 * Rows requested per Search Analytics page.
	 */
	public const PAGE_SIZE = 5000;

	/**
	 * Most pages read per period (PAGE_SIZE * MAX_PAGES rows).
	 */
	public const MAX_PAGES = 5;

	/**
	 * Constructor.
	 *
	 * @param OAuth         $oauth     OAuth instance.
	 * @param callable|null $transport Optional HTTP transport override (tests).
	 */
	public function __construct( OAuth $oauth, ?callable $transport = null ) {
		$this->oauth     = $oauth;
		$this->transport = $transport ?? array( $this, 'http_request' );
	}

	/**
	 * List the verified Search Console properties for the connected account.
	 *
	 * @return string[] siteUrls (e.g. "sc-domain:example.com", "https://example.com/").
	 */
	public function list_sites(): array {
		$token = $this->oauth->get_access_token();
		if ( ! $token ) {
			return array();
		}

		$res = call_user_func( $this->transport, 'GET', self::API_BASE . '/sites', array(), $token );
		if ( 200 !== (int) ( $res['code'] ?? 0 ) ) {
			return array();
		}

		$data = json_decode( (string) ( $res['body'] ?? '' ), true );
		if ( ! is_array( $data ) || empty( $data['siteEntry'] ) || ! is_array( $data['siteEntry'] ) ) {
			return array();
		}

		$sites = array();
		foreach ( $data['siteEntry'] as $entry ) {
			if ( ! empty( $entry['siteUrl'] ) ) {
				$sites[] = (string) $entry['siteUrl'];
			}
		}

		return $sites;
	}

	/**
	 * Fetch current-vs-previous per-page search metrics for the configured property.
	 *
	 * @param int $period_days Comparison period length in days.
	 * @return array{current: array<string,array>, previous: array<string,array>, error: string, truncated: array{current: bool, previous: bool}}
	 *               Keyed by normalised URL path. 'error' is non-empty when
	 *               either period could not be fetched, in which case neither
	 *               period may be stored: a missing period would read as zero
	 *               clicks. 'truncated' marks a period with more pages than
	 *               were read, so a path missing from it may still have clicks.
	 */
	public function fetch_comparison_data( int $period_days ): array {
		$result = array(
			'current'   => array(),
			'previous'  => array(),
			'error'     => '',
			'truncated' => array(
				'current'  => false,
				'previous' => false,
			),
		);

		$property = (string) get_option( 'dragoncontentdecay_gsc_property', '' );
		if ( '' === $property ) {
			$result['error'] = __( 'No Search Console property is selected on the Settings tab.', 'dragon-content-decay' );
			return $result;
		}

		$token = $this->oauth->get_access_token();
		if ( ! $token ) {
			$result['error'] = __( 'The Google sign-in could not be refreshed, so Search Console could not be reached.', 'dragon-content-decay' );
			return $result;
		}

		if ( $period_days < 1 ) {
			return $result;
		}

		$lag           = self::DATA_LAG_DAYS;
		$current_end   = gmdate( 'Y-m-d', time() - $lag * DAY_IN_SECONDS );
		$current_start = gmdate( 'Y-m-d', time() - ( $lag + $period_days ) * DAY_IN_SECONDS );
		$prev_end      = gmdate( 'Y-m-d', time() - ( $lag + $period_days + 1 ) * DAY_IN_SECONDS );
		$prev_start    = gmdate( 'Y-m-d', time() - ( $lag + ( 2 * $period_days ) + 1 ) * DAY_IN_SECONDS );

		$current = $this->query( $property, $current_start, $current_end, $token );
		if ( is_string( $current ) ) {
			$result['error'] = $current;
			return $result;
		}

		$previous = $this->query( $property, $prev_start, $prev_end, $token );
		if ( is_string( $previous ) ) {
			$result['error'] = $previous;
			return $result;
		}

		$result['current']   = $current['rows'];
		$result['previous']  = $previous['rows'];
		$result['truncated'] = array(
			'current'  => $current['truncated'],
			'previous' => $previous['truncated'],
		);

		return $result;
	}

	/**
	 * Run a searchAnalytics.query grouped by page, reading it page by page.
	 *
	 * @param string $property Site URL.
	 * @param string $start    Start date (Y-m-d).
	 * @param string $end      End date (Y-m-d).
	 * @param string $token    Bearer token.
	 * @return array{rows: array<string,array>, truncated: bool}|string Rows
	 *         keyed by normalised URL path and whether more rows exist than
	 *         were read, or why a request failed.
	 */
	private function query( string $property, string $start, string $end, string $token ) {
		$report = self::page_rows(
			function ( int $start_row, int $limit ) use ( $property, $start, $end, $token ) {
				return $this->query_page( $property, $start, $end, $token, $start_row, $limit );
			},
			self::PAGE_SIZE,
			self::MAX_PAGES
		);

		if ( is_string( $report ) ) {
			return $report;
		}

		return array(
			'rows'      => self::parse_rows( array( 'rows' => $report['rows'] ), self::site_hosts() ),
			'truncated' => $report['truncated'],
		);
	}

	/**
	 * Read Search Analytics rows page by page. Pure: the page runner does the
	 * request.
	 *
	 * startRow advances by the rows each page returned. The loop stops on a
	 * page shorter than $page_size (the last one) or after $max_pages pages;
	 * a full last page means more rows may exist, so the result is flagged
	 * truncated. A row repeated on a later page (ordering can shift while
	 * Google is still collecting the period) replaces the earlier copy, since
	 * parse_rows() adds up rows and would otherwise count it twice. Any failed
	 * page fails the whole period.
	 *
	 * @param callable $run_page  fn(int $start_row, int $limit): array|string (raw API rows, or an error).
	 * @param int      $page_size Rows requested per page.
	 * @param int      $max_pages Most pages to read.
	 * @return array{rows: array<int,array>, truncated: bool}|string
	 */
	public static function page_rows( callable $run_page, int $page_size, int $max_pages ) {
		$rows      = array();
		$start_row = 0;
		$truncated = false;

		for ( $page = 0; $page < $max_pages; $page++ ) {
			$got = call_user_func( $run_page, $start_row, $page_size );
			if ( is_string( $got ) ) {
				return $got;
			}
			$got = is_array( $got ) ? array_values( $got ) : array();

			foreach ( $got as $row ) {
				$key = is_array( $row ) && isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '';
				if ( '' !== $key ) {
					$rows[ $key ] = $row;
				}
			}

			$start_row += count( $got );

			if ( count( $got ) < $page_size ) {
				break;
			}
			if ( $page === $max_pages - 1 ) {
				$truncated = true;
			}
		}

		return array(
			'rows'      => array_values( $rows ),
			'truncated' => $truncated,
		);
	}

	/**
	 * Run one page of a searchAnalytics.query grouped by page.
	 *
	 * @param string $property  Site URL.
	 * @param string $start     Start date (Y-m-d).
	 * @param string $end       End date (Y-m-d).
	 * @param string $token     Bearer token.
	 * @param int    $start_row First row to return (zero-based).
	 * @param int    $limit     Rows to return.
	 * @return array<int,array>|string The raw rows, or why the request failed.
	 */
	private function query_page( string $property, string $start, string $end, string $token, int $start_row, int $limit ) {
		$url  = self::API_BASE . '/sites/' . rawurlencode( $property ) . '/searchAnalytics/query';
		$body = array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => array( 'page' ),
			'rowLimit'   => $limit,
			'startRow'   => $start_row,
		);

		$res  = call_user_func( $this->transport, 'POST', $url, $body, $token );
		$code = (int) ( $res['code'] ?? 0 );
		$data = json_decode( (string) ( $res['body'] ?? '' ), true );

		if ( 200 !== $code ) {
			$reason = is_array( $data ) && isset( $data['error']['message'] ) && is_string( $data['error']['message'] )
				? trim( $data['error']['message'] )
				: '';

			if ( 0 === $code ) {
				return __( 'The Search Console request could not be sent (no response from Google).', 'dragon-content-decay' );
			}

			return '' !== $reason
				? sprintf(
					/* translators: 1: HTTP status code, 2: error message returned by Google */
					__( 'The Search Console request failed (HTTP %1$d): %2$s', 'dragon-content-decay' ),
					$code,
					mb_substr( $reason, 0, 300 )
				)
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'The Search Console request failed (HTTP %d).', 'dragon-content-decay' ),
					$code
				);
		}

		if ( ! is_array( $data ) ) {
			return __( 'Search Console sent a response that could not be read.', 'dragon-content-decay' );
		}

		// A page past the last row has no "rows" key at all.
		return isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
	}

	/**
	 * Parse a searchAnalytics.query response (grouped by page) into a
	 * path => metrics map. Pure: no WordPress or HTTP required.
	 *
	 * Only rows whose host is one of the accepted hosts (www. and non-www.
	 * both count) are kept, so a Domain property's other subdomains do not
	 * leak in. Rows that share a normalised path (scheme or www. variants,
	 * trailing slash, encoding) are summed: clicks and impressions added,
	 * position impression-weighted (plain mean when the rows carry no
	 * impressions).
	 *
	 * @param array    $response   Decoded API response.
	 * @param string[] $site_hosts Accepted host names (compared via normalize_host()).
	 * @return array<string,array{clicks:int,impressions:int,position:float}>
	 */
	public static function parse_rows( array $response, array $site_hosts ): array {
		$out = array();

		if ( empty( $response['rows'] ) || ! is_array( $response['rows'] ) ) {
			return $out;
		}

		$site_hosts = array_map( array( self::class, 'normalize_host' ), array_map( 'strval', $site_hosts ) );
		$acc        = array();

		foreach ( $response['rows'] as $row ) {
			$key = isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '';
			if ( '' === $key ) {
				continue;
			}

			$host = wp_parse_url( $key, PHP_URL_HOST );
			if ( ! is_string( $host ) || ! in_array( self::normalize_host( $host ), $site_hosts, true ) ) {
				continue;
			}

			$path        = self::url_to_path( $key );
			$impressions = (float) ( $row['impressions'] ?? 0 );
			$position    = (float) ( $row['position'] ?? 0 );

			if ( ! isset( $acc[ $path ] ) ) {
				$acc[ $path ] = array(
					'clicks'       => 0.0,
					'impressions'  => 0.0,
					'pos_weighted' => 0.0,
					'pos_sum'      => 0.0,
					'rows'         => 0,
				);
			}

			$acc[ $path ]['clicks']       += (float) ( $row['clicks'] ?? 0 );
			$acc[ $path ]['impressions']  += $impressions;
			$acc[ $path ]['pos_weighted'] += $position * $impressions;
			$acc[ $path ]['pos_sum']      += $position;
			++$acc[ $path ]['rows'];
		}

		foreach ( $acc as $path => $sums ) {
			if ( $sums['impressions'] > 0 ) {
				$position = $sums['pos_weighted'] / $sums['impressions'];
			} else {
				$position = $sums['pos_sum'] / $sums['rows'];
			}

			$out[ $path ] = array(
				'clicks'      => (int) round( $sums['clicks'] ),
				'impressions' => (int) round( $sums['impressions'] ),
				'position'    => round( $position, 1 ),
			);
		}

		return $out;
	}

	/**
	 * Lower-case a host name and drop a leading "www." so the two forms of a
	 * site compare equal.
	 *
	 * @param string $host Host name.
	 * @return string
	 */
	public static function normalize_host( string $host ): string {
		$host = strtolower( trim( $host ) );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Host names whose Search Console rows count as this site: the home URL's
	 * host by default (www. and non-www. compare equal), filterable for a
	 * property that lives on a different host.
	 *
	 * @return string[]
	 */
	public static function site_hosts(): array {
		$host  = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$hosts = is_string( $host ) && '' !== $host ? array( self::normalize_host( $host ) ) : array();

		/*
		 * The configured property is accepted too. Its host is legitimately not
		 * home_url() after a domain move, or on a headless or proxied front end,
		 * and keeping only home_url() would drop every row and report zero
		 * Search data on sites that were getting it before.
		 */
		$property = (string) get_option( 'dragoncontentdecay_gsc_property', '' );

		if ( '' !== $property ) {
			$candidate = str_starts_with( $property, 'sc-domain:' )
				? substr( $property, strlen( 'sc-domain:' ) )
				: (string) wp_parse_url( $property, PHP_URL_HOST );

			$candidate = trim( $candidate );

			if ( '' !== $candidate ) {
				$hosts[] = self::normalize_host( $candidate );
			}
		}

		$hosts = array_values( array_unique( $hosts ) );

		/**
		 * Filter the host names whose Search Console rows are attributed to this site.
		 *
		 * @param string[] $hosts Accepted host names; "www." prefixes are ignored when comparing.
		 */
		$hosts = apply_filters( 'dragoncontentdecay_gsc_site_hosts', $hosts );

		return array_values( array_filter( array_map( 'strval', (array) $hosts ) ) );
	}

	/**
	 * Reduce a GSC page URL to the normalised path key GA4 data is joined on.
	 *
	 * @param string $url Full page URL.
	 * @return string
	 */
	private static function url_to_path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		return Analyzer::path_key( is_string( $path ) ? $path : '/' );
	}

	/**
	 * Default HTTPS transport.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    URL (always a googleapis.com endpoint).
	 * @param array  $body   Request body (POST).
	 * @param string $token  Bearer token.
	 * @return array{code:int, body:string}
	 */
	private function http_request( string $method, string $url, array $body, string $token ): array {
		$args = array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);

		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
			$res                             = wp_remote_post( $url, $args );
		} else {
			$res = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $res ) ) {
			return array(
				'code' => 0,
				'body' => '',
			);
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $res ),
			'body' => (string) wp_remote_retrieve_body( $res ),
		);
	}
}
