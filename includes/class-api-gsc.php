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
	 * @return array{current: array<string,array>, previous: array<string,array>} Keyed by URL path.
	 */
	public function fetch_comparison_data( int $period_days ): array {
		$empty    = array(
			'current'  => array(),
			'previous' => array(),
		);
		$property = (string) get_option( 'dragoncontentdecay_gsc_property', '' );
		$token    = $this->oauth->get_access_token();

		if ( '' === $property || ! $token || $period_days < 1 ) {
			return $empty;
		}

		$lag           = self::DATA_LAG_DAYS;
		$current_end   = gmdate( 'Y-m-d', time() - $lag * DAY_IN_SECONDS );
		$current_start = gmdate( 'Y-m-d', time() - ( $lag + $period_days ) * DAY_IN_SECONDS );
		$prev_end      = gmdate( 'Y-m-d', time() - ( $lag + $period_days + 1 ) * DAY_IN_SECONDS );
		$prev_start    = gmdate( 'Y-m-d', time() - ( $lag + ( 2 * $period_days ) + 1 ) * DAY_IN_SECONDS );

		return array(
			'current'  => $this->query( $property, $current_start, $current_end, $token ),
			'previous' => $this->query( $property, $prev_start, $prev_end, $token ),
		);
	}

	/**
	 * Run one searchAnalytics.query grouped by page.
	 *
	 * @param string $property Site URL.
	 * @param string $start    Start date (Y-m-d).
	 * @param string $end      End date (Y-m-d).
	 * @param string $token    Bearer token.
	 * @return array<string,array> Keyed by URL path.
	 */
	private function query( string $property, string $start, string $end, string $token ): array {
		$url  = self::API_BASE . '/sites/' . rawurlencode( $property ) . '/searchAnalytics/query';
		$body = array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => array( 'page' ),
			'rowLimit'   => 5000,
		);

		$res = call_user_func( $this->transport, 'POST', $url, $body, $token );
		if ( 200 !== (int) ( $res['code'] ?? 0 ) ) {
			return array();
		}

		$data = json_decode( (string) ( $res['body'] ?? '' ), true );

		return is_array( $data ) ? self::parse_rows( $data ) : array();
	}

	/**
	 * Parse a searchAnalytics.query response (grouped by page) into a
	 * path => metrics map. Pure — no WordPress or HTTP required.
	 *
	 * @param array $response Decoded API response.
	 * @return array<string,array{clicks:int,impressions:int,position:float}>
	 */
	public static function parse_rows( array $response ): array {
		$out = array();

		if ( empty( $response['rows'] ) || ! is_array( $response['rows'] ) ) {
			return $out;
		}

		foreach ( $response['rows'] as $row ) {
			$key = isset( $row['keys'][0] ) ? (string) $row['keys'][0] : '';
			if ( '' === $key ) {
				continue;
			}

			$path = self::url_to_path( $key );
			if ( '' === $path ) {
				continue;
			}

			$out[ $path ] = array(
				'clicks'      => (int) round( (float) ( $row['clicks'] ?? 0 ) ),
				'impressions' => (int) round( (float) ( $row['impressions'] ?? 0 ) ),
				'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
			);
		}

		return $out;
	}

	/**
	 * Reduce a GSC page URL to the path GA4 keys on.
	 *
	 * @param string $url Full page URL.
	 * @return string
	 */
	private static function url_to_path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		return is_string( $path ) ? $path : '';
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
