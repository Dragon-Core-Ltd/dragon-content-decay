<?php
/**
 * Token refresh: never on plugin load, backed off after a failure, and a
 * refresh token Google has revoked marks the connection as needing a
 * reconnect instead of being retried on every request.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Crypto;
use DragonContentDecay\OAuth;
use PHPUnit\Framework\TestCase;

final class OAuthRefreshTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		update_option( 'dragoncontentdecay_google_client_id', 'client-id' );
		OAuth::set_client_secret( 'client-secret' );
		update_option(
			'dragoncontentdecay_google_tokens',
			Crypto::encrypt(
				wp_json_encode(
					array(
						'access_token'  => 'expired-access',
						'refresh_token' => 'refresh-1',
						'expires_in'    => 3600,
						'created'       => time() - 2 * HOUR_IN_SECONDS,
					)
				)
			)
		);
	}

	/**
	 * @param array $responses Queue of token-endpoint responses (arrays), or
	 *                         Exception instances to throw.
	 */
	private function oauth( array $responses ): OAuth {
		return new class( $responses ) extends OAuth {
			public int $refresh_calls = 0;
			private array $responses;

			public function __construct( array $responses ) {
				$this->responses = $responses;
				parent::__construct();
			}

			protected function request_token_refresh( string $refresh_token ): array {
				unset( $refresh_token );
				++$this->refresh_calls;
				$next = array_shift( $this->responses ) ?? array( 'error' => 'no_canned_response' );
				if ( $next instanceof \Exception ) {
					throw $next;
				}
				return $next;
			}
		};
	}

	public function test_loading_the_plugin_does_not_refresh_the_token(): void {
		$oauth = $this->oauth( array() );

		$this->assertSame( 0, $oauth->refresh_calls );
	}

	public function test_expired_token_is_refreshed_when_the_connection_is_used(): void {
		$oauth = $this->oauth(
			array(
				array(
					'access_token' => 'fresh',
					'expires_in'   => 3600,
					'created'      => time(),
				),
			)
		);

		$this->assertTrue( $oauth->is_connected() );
		$this->assertTrue( $oauth->is_connected() );
		$this->assertSame( 1, $oauth->refresh_calls );
	}

	public function test_failed_refresh_backs_off_instead_of_retrying_every_request(): void {
		$oauth = $this->oauth( array( new \RuntimeException( 'network down' ) ) );

		$this->assertFalse( $oauth->is_connected() );
		$this->assertFalse( $oauth->is_connected() );
		$this->assertSame( 1, $oauth->refresh_calls );

		// A later request (new instance) also respects the back-off.
		$again = $this->oauth( array() );
		$this->assertFalse( $again->is_connected() );
		$this->assertSame( 0, $again->refresh_calls );
		$this->assertFalse( OAuth::is_access_revoked() );
	}

	public function test_revoked_refresh_token_marks_the_connection_for_reconnect(): void {
		$oauth = $this->oauth(
			array(
				array(
					'error'             => 'invalid_grant',
					'error_description' => 'Token has been expired or revoked.',
				),
			)
		);

		$this->assertFalse( $oauth->is_connected() );
		$this->assertTrue( OAuth::is_access_revoked() );

		// Even once any back-off has lapsed, a revoked token is not retried.
		dragoncontentdecay_test_reset_transients();
		$later = $this->oauth( array() );
		$this->assertFalse( $later->is_connected() );
		$this->assertSame( 0, $later->refresh_calls );
	}

	public function test_disconnect_clears_the_revoked_flag(): void {
		update_option( 'dragoncontentdecay_google_auth_revoked', 1 );

		$this->oauth( array() )->disconnect();

		$this->assertFalse( OAuth::is_access_revoked() );
	}

	public function test_has_connection_is_local_and_survives_a_refresh_backoff(): void {
		set_transient( 'dragoncontentdecay_token_refresh_backoff', 1, 900 );
		$oauth = $this->oauth( array() );

		$this->assertTrue( $oauth->has_connection() );
		$this->assertFalse( $oauth->is_connected() );
		$this->assertSame( 0, $oauth->refresh_calls );
	}

	public function test_has_connection_is_false_once_access_is_revoked(): void {
		update_option( 'dragoncontentdecay_google_auth_revoked', 1 );

		$this->assertFalse( $this->oauth( array() )->has_connection() );
	}

	public function test_has_connection_is_false_without_tokens(): void {
		delete_option( 'dragoncontentdecay_google_tokens' );

		$this->assertFalse( $this->oauth( array() )->has_connection() );
	}
}
