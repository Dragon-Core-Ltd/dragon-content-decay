<?php
/**
 * A failed Search Console property lookup says why on the settings screen,
 * instead of reading as an account with no properties.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Admin;
use DragonContentDecay\API_GSC;
use DragonContentDecay\OAuth;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wporg-scan-stubs.php';

final class GscSiteListTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		$GLOBALS['dragoncontentdecay_test_settings_errors'] = array();
		$_GET                                               = array();
		$_POST                                              = array();
	}

	/**
	 * An OAuth whose access token is the given value (null: the sign-in could
	 * not be refreshed), reporting a live connection.
	 *
	 * @param string|null $token Access token.
	 */
	private static function oauth( ?string $token ): OAuth {
		return new class( $token ) extends OAuth {
			private ?string $token;

			public function __construct( ?string $token ) {
				$this->token = $token;
				parent::__construct();
			}

			public function get_access_token(): ?string {
				return $this->token;
			}

			public function is_connected(): bool {
				return true;
			}

			public function has_connection(): bool {
				return true;
			}
		};
	}

	/**
	 * A transport that answers every request with the given response.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Body.
	 */
	private static function transport( int $code, string $body ): callable {
		return static function () use ( $code, $body ): array {
			return array(
				'code' => $code,
				'body' => $body,
			);
		};
	}

	public function test_a_listed_account_reports_no_error(): void {
		$gsc = new API_GSC( self::oauth( 'token' ), self::transport( 200, '{"siteEntry":[{"siteUrl":"sc-domain:example.test"}]}' ) );

		$this->assertSame( array( 'sc-domain:example.test' ), $gsc->list_sites() );
		$this->assertSame( '', $gsc->get_list_error() );
	}

	public function test_an_account_with_no_properties_reports_no_error(): void {
		$gsc = new API_GSC( self::oauth( 'token' ), self::transport( 200, '{}' ) );

		$this->assertSame( array(), $gsc->list_sites() );
		$this->assertSame( '', $gsc->get_list_error() );
	}

	public function test_a_disabled_api_is_named(): void {
		$body = '{"error":{"code":403,"message":"Google Search Console API has not been used in project 1 before or it is disabled.","status":"PERMISSION_DENIED"}}';
		$gsc  = new API_GSC( self::oauth( 'token' ), self::transport( 403, $body ) );

		$this->assertSame( array(), $gsc->list_sites() );
		$this->assertStringContainsString( 'Search Console API is not enabled', $gsc->get_list_error() );
	}

	public function test_an_unreadable_answer_is_reported(): void {
		$gsc = new API_GSC( self::oauth( 'token' ), self::transport( 200, '<html>' ) );

		$this->assertSame( array(), $gsc->list_sites() );
		$this->assertNotSame( '', $gsc->get_list_error() );
	}

	public function test_a_sign_in_that_cannot_be_refreshed_is_reported(): void {
		$gsc = new API_GSC( self::oauth( null ), self::transport( 200, '{}' ) );

		$this->assertSame( array(), $gsc->list_sites() );
		$this->assertNotSame( '', $gsc->get_list_error() );
	}

	/**
	 * The settings screen for a site with Search Console on and granted.
	 *
	 * @param OAuth $oauth OAuth instance.
	 */
	private static function settings_screen( OAuth $oauth ): string {
		ob_start();
		try {
			( new Admin( $oauth, AnalyzerTestSupport::analyzer() ) )->render_settings_page();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	private static function configure( int $token_created ): void {
		update_option( 'dragoncontentdecay_google_client_id', 'client-id' );
		OAuth::set_client_secret( 'client-secret' );
		update_option( 'dragoncontentdecay_gsc_enabled', 1 );
		update_option( 'dragoncontentdecay_google_granted_scopes', OAuth::SCOPE_ANALYTICS . ' ' . OAuth::SCOPE_SEARCHCONSOLE );
		update_option(
			'dragoncontentdecay_google_tokens',
			\DragonContentDecay\Crypto::encrypt(
				(string) wp_json_encode(
					array(
						'access_token'  => 'access-1',
						'refresh_token' => 'refresh-1',
						'expires_in'    => 3600,
						'created'       => $token_created,
					)
				)
			)
		);
	}

	public function test_a_sign_in_waiting_to_be_refreshed_is_explained_on_the_settings_screen(): void {
		self::configure( time() - 2 * HOUR_IN_SECONDS );
		set_transient( 'dragoncontentdecay_token_refresh_backoff', 1, 900 );

		$html = self::settings_screen( new OAuth() );

		$this->assertStringNotContainsString( 'No Search Console properties were found', $html );
		$this->assertStringContainsString( 'Search Console properties could not be listed.', $html );
		$this->assertStringContainsString( 'The Google sign-in could not be refreshed', $html );
	}

	public function test_a_lookup_without_a_token_says_it_could_not_run(): void {
		self::configure( time() );
		$oauth = new class() extends OAuth {
			public function get_access_token(): ?string {
				return null;
			}
		};

		$html = self::settings_screen( $oauth );

		$this->assertStringContainsString( 'Search Console properties could not be listed.', $html, 'no token means the lookup could not run' );
	}
}
