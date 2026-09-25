<?php
/**
 * Plain-language text for failed Google requests.
 *
 * @package DragonContentDecay
 */

namespace DragonContentDecay;

defined( 'ABSPATH' ) || exit;

/**
 * Sorts a failed Google Analytics or Search Console request into a known
 * failure class and gives the matching translated message. The raw text
 * Google or the HTTP library returned is kept separately as the detail.
 */
final class Error_Text {

	public const CONNECTION   = 'connection';
	public const AUTH         = 'auth';
	public const API_DISABLED = 'api_disabled';
	public const PERMISSION   = 'permission';
	public const NOT_FOUND    = 'not_found';
	public const QUOTA        = 'quota';
	public const GENERIC      = 'generic';

	public const SERVICE_GA4 = 'ga4';
	public const SERVICE_GSC = 'gsc';

	/**
	 * Failure class for a raw error.
	 *
	 * @param string $raw    Raw error text (an exception message, possibly a JSON document).
	 * @param string $status gRPC-style status name (PERMISSION_DENIED, ...), if known.
	 * @param int    $http   HTTP status code, if known; 0 when no response arrived.
	 * @param bool   $sent   Whether a response was received at all. False means the
	 *                       request never got an answer (connection or timeout).
	 * @return string One of the class constants.
	 */
	public static function classify( string $raw, string $status = '', int $http = -1, bool $sent = true ): string {
		if ( ! $sent || 0 === $http ) {
			return self::CONNECTION;
		}

		$decoded = json_decode( $raw, true );
		if ( '' === $status && is_array( $decoded ) ) {
			$error  = isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? $decoded['error'] : $decoded;
			$status = isset( $error['status'] ) && is_string( $error['status'] ) ? $error['status'] : '';
			if ( $http < 0 && isset( $error['code'] ) && is_int( $error['code'] ) && $error['code'] >= 100 ) {
				$http = $error['code'];
			}
		}

		$status = strtoupper( $status );

		// The API is switched off in the Cloud project: Google reports this as
		// PERMISSION_DENIED / 403, so it is checked first.
		if ( preg_match( '/SERVICE_DISABLED|has not been used in project|API has not been used|it is disabled|accessNotConfigured/i', $raw ) ) {
			return self::API_DISABLED;
		}

		if ( preg_match( '/\binvalid_grant\b|\binvalid_token\b|\bunauthorized_client\b|expired or revoked|invalid authentication credentials/i', $raw ) ) {
			return self::AUTH;
		}

		$by_status = array(
			'UNAVAILABLE'        => self::CONNECTION,
			'DEADLINE_EXCEEDED'  => self::CONNECTION,
			'UNAUTHENTICATED'    => self::AUTH,
			'PERMISSION_DENIED'  => self::PERMISSION,
			'NOT_FOUND'          => self::NOT_FOUND,
			'RESOURCE_EXHAUSTED' => self::QUOTA,
		);
		if ( isset( $by_status[ $status ] ) ) {
			return $by_status[ $status ];
		}

		$by_http = array(
			401 => self::AUTH,
			403 => self::PERMISSION,
			404 => self::NOT_FOUND,
			429 => self::QUOTA,
			503 => self::CONNECTION,
			504 => self::CONNECTION,
		);
		if ( isset( $by_http[ $http ] ) ) {
			return $by_http[ $http ];
		}

		if ( preg_match( '/cURL error|Could not resolve host|Connection refused|Connection reset|timed out|timeout|Failed to connect|SSL connect|ConnectException/i', $raw ) ) {
			return self::CONNECTION;
		}

		if ( preg_match( '/quota|rate limit|too many requests/i', $raw ) ) {
			return self::QUOTA;
		}

		if ( preg_match( '/\b401\b|unauthenticated/i', $raw ) ) {
			return self::AUTH;
		}

		if ( preg_match( '/\b403\b|permission|forbidden/i', $raw ) ) {
			return self::PERMISSION;
		}

		if ( preg_match( '/\b404\b|not found/i', $raw ) ) {
			return self::NOT_FOUND;
		}

		return self::GENERIC;
	}

	/**
	 * Failure class for an exception thrown by the Google client or Guzzle.
	 *
	 * @param \Throwable $e The exception.
	 * @return string One of the class constants.
	 */
	public static function classify_exception( \Throwable $e ): string {
		$status = method_exists( $e, 'getStatus' ) ? (string) $e->getStatus() : '';
		$raw    = $e->getMessage();

		// A Guzzle connection failure carries no response.
		$class = get_class( $e );
		if ( str_ends_with( $class, 'ConnectException' ) ) {
			return self::CONNECTION;
		}

		return self::classify( $raw, $status );
	}

	/**
	 * Translated message for a failure class.
	 *
	 * @param string $category One of the class constants.
	 * @param string $service  SERVICE_GA4 or SERVICE_GSC.
	 * @return string
	 */
	public static function message( string $category, string $service ): string {
		if ( self::SERVICE_GSC === $service ) {
			$messages = array(
				self::CONNECTION   => __( 'Search Console could not be reached (the connection failed or timed out). This is usually temporary; the next sync will try again.', 'dragon-content-decay' ),
				self::AUTH         => __( 'Google did not accept the saved sign-in for Search Console (it was revoked or has expired). Connect to Google again on the Settings tab.', 'dragon-content-decay' ),
				self::API_DISABLED => __( 'The Google Search Console API is not enabled in the Google Cloud project your Client ID belongs to. Enable it in Google Cloud Console, then sync again.', 'dragon-content-decay' ),
				self::PERMISSION   => __( 'Google refused access to the selected Search Console property. Check that the connected Google account can view that property, and that you reconnected after turning Search Console on.', 'dragon-content-decay' ),
				self::NOT_FOUND    => __( 'Search Console could not find the selected property. Choose the property again on the Settings tab.', 'dragon-content-decay' ),
				self::QUOTA        => __( 'The Search Console usage limit has been reached for now. The next sync will try again.', 'dragon-content-decay' ),
				self::GENERIC      => __( 'The Search Console request failed with an unexpected error. The next sync will try again.', 'dragon-content-decay' ),
			);
		} else {
			$messages = array(
				self::CONNECTION   => __( 'Google Analytics could not be reached (the connection failed or timed out). This is usually temporary; the next sync will try again.', 'dragon-content-decay' ),
				self::AUTH         => __( 'Google did not accept the saved sign-in (it was revoked or has expired). Connect to Google again on the Settings tab.', 'dragon-content-decay' ),
				self::API_DISABLED => __( 'The Google Analytics Data API is not enabled in the Google Cloud project your Client ID belongs to. Enable it in Google Cloud Console, then sync again.', 'dragon-content-decay' ),
				self::PERMISSION   => __( 'Google refused access to this GA4 property. Check the Property ID on the Settings tab, and that the connected Google account can view that property.', 'dragon-content-decay' ),
				self::NOT_FOUND    => __( 'Google Analytics could not find this GA4 property. Check the Property ID on the Settings tab: it is the number shown under Admin, Property settings.', 'dragon-content-decay' ),
				self::QUOTA        => __( 'The Google Analytics usage limit for this property has been reached for now. The next sync will try again.', 'dragon-content-decay' ),
				self::GENERIC      => __( 'The Google Analytics request failed with an unexpected error. The next sync will try again.', 'dragon-content-decay' ),
			);
		}

		return $messages[ $category ] ?? $messages[ self::GENERIC ];
	}

	/**
	 * Raw error reduced to one line of at most 300 characters, for display
	 * as technical detail. A JSON document is reduced to its message.
	 *
	 * @param string $raw Raw error text.
	 * @return string
	 */
	public static function detail( string $raw ): string {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			$error = isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? $decoded['error'] : $decoded;
			if ( isset( $error['message'] ) && is_string( $error['message'] ) ) {
				$raw = isset( $error['status'] ) && is_string( $error['status'] ) && '' !== $error['status']
					? $error['status'] . ': ' . $error['message']
					: $error['message'];
			}
		}

		$raw = trim( (string) preg_replace( '/\s+/', ' ', $raw ) );
		if ( mb_strlen( $raw ) > 300 ) {
			$raw = mb_substr( $raw, 0, 297 ) . '...';
		}

		return $raw;
	}
}
