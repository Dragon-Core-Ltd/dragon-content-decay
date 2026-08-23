<?php
/**
 * OAuth Class
 *
 * Handles Google OAuth2 authentication
 *
 * @package DragonContentDecay
 */

namespace DragonContentDecay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Google\Client;

class OAuth {

	/**
	 * Google Client instance
	 */
	private ?Client $client = null;

	/**
	 * OAuth scopes required
	 */
	private const SCOPES = array(
		'https://www.googleapis.com/auth/analytics.readonly',
	);

	/**
	 * Option name for storing tokens
	 */
	private const TOKEN_OPTION = 'dragoncontentdecay_google_tokens';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_client();
	}

	/**
	 * Initialize Google Client
	 */
	private function init_client(): void {
		$client_id     = get_option( 'dragoncontentdecay_google_client_id', '' );
		$client_secret = self::get_client_secret();

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return;
		}

		try {
			$this->client = new Client();
			$this->client->setClientId( $client_id );
			$this->client->setClientSecret( $client_secret );
			$this->client->setRedirectUri( $this->get_redirect_uri() );
			$this->client->setScopes( self::SCOPES );
			$this->client->setAccessType( 'offline' );
			$this->client->setPrompt( 'consent' );

			// Load existing tokens
			$tokens = $this->get_stored_tokens();
			if ( $tokens ) {
				$this->client->setAccessToken( $tokens );

				// Refresh token if expired
				if ( $this->client->isAccessTokenExpired() ) {
					$this->refresh_token();
				}
			}
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DCD OAuth Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
			}
			$this->client = null;
		}
	}

	/**
	 * Get redirect URI for OAuth callback
	 */
	public function get_redirect_uri(): string {
		// Must exactly match the admin page the callback is handled on, or Google
		// rejects the flow with redirect_uri_mismatch.
		return admin_url( 'tools.php?page=dragon-content-decay&tab=settings&action=callback' );
	}

	/**
	 * Option storing the encrypted Google client secret.
	 */
	private const SECRET_OPTION = 'dragoncontentdecay_google_client_secret';

	/**
	 * Transient key holding the pending OAuth state token, per user.
	 */
	private const STATE_TRANSIENT = 'dragoncontentdecay_oauth_state_';

	/**
	 * The decrypted Google client secret, migrating any legacy plaintext value.
	 *
	 * @return string
	 */
	public static function get_client_secret(): string {
		$stored = (string) get_option( self::SECRET_OPTION, '' );
		if ( '' === $stored ) {
			return '';
		}

		$plain = Crypto::decrypt( $stored );
		if ( null !== $plain ) {
			return $plain;
		}

		// An authenticated value that no longer decrypts (e.g. salts changed)
		// must not be mistaken for plaintext: re-encrypting it would corrupt the
		// stored secret permanently and hand the ciphertext to Google. Fail safe.
		if ( str_starts_with( $stored, 'DRGNc1:' ) ) {
			return '';
		}

		// Legacy plaintext: re-store encrypted, keeping the value.
		self::set_client_secret( $stored );
		return $stored;
	}

	/**
	 * Store the Google client secret encrypted at rest (or clear it).
	 *
	 * @param string $plain Plaintext secret, or '' to clear.
	 */
	public static function set_client_secret( string $plain ): void {
		if ( '' === $plain ) {
			update_option( self::SECRET_OPTION, '', false );
			return;
		}

		update_option( self::SECRET_OPTION, Crypto::encrypt( $plain ), false );
	}

	/**
	 * Whether a client secret is stored.
	 *
	 * @return bool
	 */
	public static function has_client_secret(): bool {
		// Reflect whether a *usable* secret exists: an encrypted value that no
		// longer decrypts (e.g. salts changed) should not read as configured.
		return '' !== self::get_client_secret();
	}

	/**
	 * Get authorization URL, binding it to a fresh CSRF state token.
	 */
	public function get_auth_url(): ?string {
		if ( ! $this->client ) {
			return null;
		}

		$state = wp_generate_password( 32, false, false );
		set_transient( self::STATE_TRANSIENT . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
		$this->client->setState( $state );

		return $this->client->createAuthUrl();
	}

	/**
	 * Verify and consume the OAuth state returned on the callback.
	 *
	 * Without this an attacker can CSRF a logged-in admin into completing the
	 * flow with the attacker's authorization code, binding the site to the
	 * attacker's Google account.
	 *
	 * @param string $provided State value from the callback request.
	 * @return bool
	 */
	public static function verify_state( string $provided ): bool {
		$key      = self::STATE_TRANSIENT . get_current_user_id();
		$expected = get_transient( $key );
		delete_transient( $key );

		return is_string( $expected ) && '' !== $expected && hash_equals( $expected, $provided );
	}

	/**
	 * Handle OAuth callback
	 */
	public function handle_callback( string $code ): bool {
		if ( ! $this->client ) {
			return false;
		}

		try {
			$tokens = $this->client->fetchAccessTokenWithAuthCode( $code );

			if ( isset( $tokens['error'] ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'DCD OAuth Error: ' . $tokens['error_description'] ?? $tokens['error'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
				}
				return false;
			}

			$this->store_tokens( $tokens );
			$this->client->setAccessToken( $tokens );

			return true;
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DCD OAuth Callback Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
			}
			return false;
		}
	}

	/**
	 * Refresh access token
	 */
	private function refresh_token(): bool {
		if ( ! $this->client ) {
			return false;
		}

		try {
			$refresh_token = $this->client->getRefreshToken();
			if ( ! $refresh_token ) {
				return false;
			}

			$tokens = $this->client->fetchAccessTokenWithRefreshToken( $refresh_token );

			if ( isset( $tokens['error'] ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'DCD Token Refresh Error: ' . $tokens['error_description'] ?? $tokens['error'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
				}
				return false;
			}

			// Preserve refresh token if not returned
			if ( ! isset( $tokens['refresh_token'] ) ) {
				$tokens['refresh_token'] = $refresh_token;
			}

			$this->store_tokens( $tokens );
			$this->client->setAccessToken( $tokens );

			return true;
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic logging of API/auth failures for troubleshooting; no sensitive data logged.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DCD Token Refresh Error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
			}
			return false;
		}
	}

	/**
	 * Store tokens in database (encrypted)
	 */
	private function store_tokens( array $tokens ): void {
		$json      = wp_json_encode( $tokens );
		$encrypted = Crypto::encrypt( $json );

		if ( ! empty( $encrypted ) ) {
			update_option( self::TOKEN_OPTION, $encrypted );
		}
	}

	/**
	 * Get stored tokens from database
	 */
	private function get_stored_tokens(): ?array {
		$encrypted = get_option( self::TOKEN_OPTION, '' );

		if ( empty( $encrypted ) ) {
			return null;
		}

		$json = Crypto::decrypt( $encrypted );

		if ( null === $json ) {
			// Migration: try to read old base64-only format
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Migration path for tokens stored in the legacy base64 format.
			$old_data = base64_decode( $encrypted, true );
			if ( false !== $old_data ) {
				$tokens = json_decode( $old_data, true );
				if ( is_array( $tokens ) && isset( $tokens['access_token'] ) ) {
					// Re-encrypt with proper encryption
					$this->store_tokens( $tokens );
					return $tokens;
				}
			}
			return null;
		}

		$tokens = json_decode( $json, true );

		return is_array( $tokens ) ? $tokens : null;
	}

	/**
	 * Get refresh token for API clients
	 */
	public function get_refresh_token(): ?string {
		$tokens = $this->get_stored_tokens();

		return $tokens['refresh_token'] ?? null;
	}

	/**
	 * Check if connected to Google
	 */
	public function is_connected(): bool {
		if ( ! $this->client ) {
			return false;
		}

		$tokens = $this->get_stored_tokens();
		if ( ! $tokens ) {
			return false;
		}

		$this->client->setAccessToken( $tokens );

		// If expired, try to refresh
		if ( $this->client->isAccessTokenExpired() ) {
			return $this->refresh_token();
		}

		return true;
	}

	/**
	 * Disconnect from Google
	 */
	public function disconnect(): void {
		if ( $this->client ) {
			try {
				$this->client->revokeToken();
			} catch ( \Exception $e ) {
				unset( $e ); // Token may already be revoked.
			}
		}

		delete_option( self::TOKEN_OPTION );
		$this->client = null;
	}

	/**
	 * Get Google Client instance
	 */
	public function get_client(): ?Client {
		return $this->client;
	}

	/**
	 * Get access token for API calls
	 */
	public function get_access_token(): ?string {
		if ( ! $this->client || ! $this->is_connected() ) {
			return null;
		}

		$token = $this->client->getAccessToken();
		return $token['access_token'] ?? null;
	}
}
