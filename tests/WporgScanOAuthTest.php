<?php
/**
 * The Google connect and disconnect links and the OAuth callback: each one
 * checks the capability and its own nonce (or the single-use OAuth state) in
 * its own body, and the authorization code reaches the token exchange byte
 * for byte or not at all.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Admin;
use DragonContentDecay\Crypto;
use DragonContentDecay\OAuth;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wporg-scan-stubs.php';

/**
 * Admin::handle_oauth_request() and the three handlers it routes to.
 */
final class WporgScanOAuthTest extends TestCase {

	private const LINK_ACTION     = 'dragoncontentdecay_oauth_action';
	private const STATE_TRANSIENT = 'dragoncontentdecay_oauth_state_1';
	private const SETTINGS_URL    = 'https://www.example.test/wp-admin/tools.php?page=dragon-content-decay&tab=settings';

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		$GLOBALS['dragoncontentdecay_test_user_id'] = 1;
		$GLOBALS['dragoncontentdecay_test_nonces']  = array(
			self::LINK_ACTION                  => 'link-nonce',
			'dragoncontentdecay_save_settings' => 'settings-nonce',
		);
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		dragoncontentdecay_test_reset();
	}

	/**
	 * Set the query string the way WordPress sees it: slashed, mirrored into $_REQUEST.
	 *
	 * @param array $query Unslashed query arguments.
	 */
	private function query( array $query ): void {
		$slashed  = map_deep(
			$query,
			static function ( $leaf ) {
				return is_string( $leaf ) ? addslashes( $leaf ) : $leaf;
			}
		);
		$_GET     = $slashed;
		$_REQUEST = $slashed;
	}

	/**
	 * An OAuth with Google credentials saved, whose code exchange is canned
	 * and recorded.
	 *
	 * @param array $response Token-endpoint response.
	 */
	private function configured_oauth( array $response = array() ): OAuth {
		update_option( 'dragoncontentdecay_google_client_id', 'client-id.apps.googleusercontent.com' );
		OAuth::set_client_secret( 'client-secret' );

		return new class( $response ) extends OAuth {
			/**
			 * Codes handed to the token exchange.
			 *
			 * @var string[]
			 */
			public array $codes = array();

			/**
			 * Times disconnect() ran.
			 *
			 * @var int
			 */
			public int $disconnects = 0;

			private array $response;

			public function __construct( array $response ) {
				$this->response = $response;
				parent::__construct();
			}

			protected function request_auth_code_exchange( string $code ): array {
				$this->codes[] = $code;
				return $this->response;
			}
		};
	}

	/**
	 * An OAuth with no Google credentials, so disconnect() never calls Google.
	 */
	private function bare_oauth(): OAuth {
		return new OAuth();
	}

	private function admin( OAuth $oauth ): Admin {
		return new Admin( $oauth, AnalyzerTestSupport::analyzer() );
	}

	/**
	 * Run a callable and return what ended the request: a redirect, a wp_die()
	 * or null when it returned normally.
	 *
	 * @param callable $run Code under test.
	 * @return DragonContentDecay_Test_Redirect|DragonContentDecay_Test_Die|null
	 */
	private function outcome( callable $run ) {
		try {
			$run();
		} catch ( DragonContentDecay_Test_Redirect $redirect ) {
			return $redirect;
		} catch ( DragonContentDecay_Test_Die $die ) {
			return $die;
		}
		return null;
	}

	private function good_tokens(): array {
		return array(
			'access_token'  => 'access-1',
			'refresh_token' => 'refresh-1',
			'expires_in'    => 3600,
			'created'       => time(),
			'scope'         => OAuth::SCOPE_ANALYTICS,
		);
	}

	private function plant_connection(): string {
		$stored = Crypto::encrypt( (string) wp_json_encode( $this->good_tokens() ) );
		update_option( 'dragoncontentdecay_google_tokens', $stored );
		update_option( 'dragoncontentdecay_google_granted_scopes', OAuth::SCOPE_ANALYTICS );
		return $stored;
	}

	private function stored_tokens(): ?array {
		$stored = (string) get_option( 'dragoncontentdecay_google_tokens', '' );
		return '' === $stored ? null : json_decode( (string) Crypto::decrypt( $stored ), true );
	}

	// ---- routing ---------------------------------------------------------------

	public function test_nothing_runs_outside_the_settings_tab(): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		set_transient( self::STATE_TRANSIENT, 'state-1', 900 );

		foreach ( array( array(), array( 'tab' => 'dashboard' ), array( 'tab' => array( 'settings' ) ) ) as $tab ) {
			foreach ( array( 'connect', 'disconnect', 'callback' ) as $action ) {
				$this->query(
					$tab + array(
						'action'   => $action,
						'_wpnonce' => 'link-nonce',
						'state'    => 'state-1',
						'code'     => 'code-1',
					)
				);
				$this->assertNull( $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_request' ) ) );
			}
		}

		$this->assertSame( array(), $oauth->codes );
		$this->assertSame( 'state-1', get_transient( self::STATE_TRANSIENT ) );
	}

	public function test_an_unknown_action_does_nothing(): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		$this->query(
			array(
				'tab'      => 'settings',
				'action'   => 'purge',
				'_wpnonce' => 'link-nonce',
			)
		);

		$this->assertNull( $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_request' ) ) );
		$this->assertSame( array(), $oauth->codes );
	}

	public function test_no_action_does_nothing(): void {
		$this->query( array( 'tab' => 'settings' ) );

		$this->assertNull( $this->outcome( array( $this->admin( $this->configured_oauth() ), 'handle_oauth_request' ) ) );
	}

	// ---- connect ---------------------------------------------------------------

	public function test_connect_sends_the_admin_to_google_with_a_fresh_state(): void {
		$this->query(
			array(
				'tab'      => 'settings',
				'action'   => 'connect',
				'_wpnonce' => 'link-nonce',
			)
		);

		$outcome = $this->outcome( array( $this->admin( $this->configured_oauth() ), 'handle_oauth_request' ) );

		$this->assertInstanceOf( DragonContentDecay_Test_Redirect::class, $outcome );
		$this->assertStringStartsWith( 'https://accounts.google.com/o/oauth2/', $outcome->location );
		parse_str( (string) parse_url( $outcome->location, PHP_URL_QUERY ), $sent );
		$this->assertSame( 'client-id.apps.googleusercontent.com', $sent['client_id'] );
		$this->assertSame( self::SETTINGS_URL . '&action=callback', $sent['redirect_uri'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $sent['state'] );
		$this->assertSame( $sent['state'], get_transient( self::STATE_TRANSIENT ) );
		$this->assertStringNotContainsString( 'client-secret', $outcome->location );
	}

	/**
	 * @dataProvider bad_link_nonces
	 *
	 * @param array $nonce Query arguments carrying the nonce (or none).
	 */
	public function test_connect_without_a_valid_nonce_is_refused( array $nonce ): void {
		$this->query(
			$nonce + array(
				'tab'    => 'settings',
				'action' => 'connect',
			)
		);

		$outcome = $this->outcome( array( $this->admin( $this->configured_oauth() ), 'handle_oauth_request' ) );

		$this->assertInstanceOf( DragonContentDecay_Test_Die::class, $outcome );
		$this->assertSame( 403, $outcome->status );
		$this->assertSame( 'Security check failed. Please try again.', $outcome->getMessage() );
		$this->assertFalse( get_transient( self::STATE_TRANSIENT ) );
	}

	public static function bad_link_nonces(): array {
		return array(
			'missing'        => array( array() ),
			'wrong value'    => array( array( '_wpnonce' => 'not-the-nonce' ) ),
			'another action' => array( array( '_wpnonce' => 'settings-nonce' ) ),
			'empty'          => array( array( '_wpnonce' => '' ) ),
			'sent as a list' => array( array( '_wpnonce' => array( 'link-nonce' ) ) ),
		);
	}

	public function test_connect_without_the_capability_does_nothing(): void {
		$GLOBALS['dragoncontentdecay_test_can'] = false;
		$this->query(
			array(
				'tab'      => 'settings',
				'action'   => 'connect',
				'_wpnonce' => 'link-nonce',
			)
		);
		$admin = $this->admin( $this->configured_oauth() );

		$this->assertNull( $this->outcome( array( $admin, 'handle_oauth_request' ) ) );
		$this->assertNull( $this->outcome( array( $admin, 'handle_oauth_connect' ) ) );
		$this->assertFalse( get_transient( self::STATE_TRANSIENT ) );
	}

	public function test_the_connect_handler_checks_the_nonce_itself(): void {
		$this->query( array( '_wpnonce' => 'settings-nonce' ) );

		$outcome = $this->outcome( array( $this->admin( $this->configured_oauth() ), 'handle_oauth_connect' ) );

		$this->assertInstanceOf( DragonContentDecay_Test_Die::class, $outcome );
		$this->assertSame( 403, $outcome->status );
		$this->assertFalse( get_transient( self::STATE_TRANSIENT ) );
	}

	public function test_connect_without_saved_credentials_stays_on_the_screen(): void {
		$this->query(
			array(
				'tab'      => 'settings',
				'action'   => 'connect',
				'_wpnonce' => 'link-nonce',
			)
		);

		$this->assertNull( $this->outcome( array( $this->admin( $this->bare_oauth() ), 'handle_oauth_request' ) ) );
		$this->assertFalse( get_transient( self::STATE_TRANSIENT ) );
	}

	// ---- disconnect ------------------------------------------------------------

	public function test_disconnect_removes_the_saved_connection(): void {
		$this->plant_connection();
		update_option( 'dragoncontentdecay_google_auth_revoked', 1 );
		$this->query(
			array(
				'tab'      => 'settings',
				'action'   => 'disconnect',
				'_wpnonce' => 'link-nonce',
			)
		);

		$outcome = $this->outcome( array( $this->admin( $this->bare_oauth() ), 'handle_oauth_request' ) );

		$this->assertInstanceOf( DragonContentDecay_Test_Redirect::class, $outcome );
		$this->assertTrue( $outcome->safe );
		$this->assertSame( self::SETTINGS_URL . '&disconnected=1', $outcome->location );
		$this->assertNull( $this->stored_tokens() );
		$this->assertFalse( get_option( 'dragoncontentdecay_google_granted_scopes' ) );
		$this->assertFalse( OAuth::is_access_revoked() );
	}

	/**
	 * @dataProvider bad_link_nonces
	 *
	 * @param array $nonce Query arguments carrying the nonce (or none).
	 */
	public function test_disconnect_without_a_valid_nonce_is_refused( array $nonce ): void {
		$stored = $this->plant_connection();
		$this->query(
			$nonce + array(
				'tab'    => 'settings',
				'action' => 'disconnect',
			)
		);

		$outcome = $this->outcome( array( $this->admin( $this->bare_oauth() ), 'handle_oauth_request' ) );

		$this->assertInstanceOf( DragonContentDecay_Test_Die::class, $outcome );
		$this->assertSame( 403, $outcome->status );
		$this->assertSame( 'Security check failed. Please try again.', $outcome->getMessage() );
		$this->assertSame( $stored, get_option( 'dragoncontentdecay_google_tokens' ) );
	}

	public function test_disconnect_without_the_capability_does_nothing(): void {
		$stored                                 = $this->plant_connection();
		$GLOBALS['dragoncontentdecay_test_can'] = false;
		$this->query(
			array(
				'tab'      => 'settings',
				'action'   => 'disconnect',
				'_wpnonce' => 'link-nonce',
			)
		);
		$admin = $this->admin( $this->bare_oauth() );

		$this->assertNull( $this->outcome( array( $admin, 'handle_oauth_request' ) ) );
		$this->assertNull( $this->outcome( array( $admin, 'handle_oauth_disconnect' ) ) );
		$this->assertSame( $stored, get_option( 'dragoncontentdecay_google_tokens' ) );
	}

	public function test_the_disconnect_handler_checks_the_nonce_itself(): void {
		$stored = $this->plant_connection();
		$this->query( array( '_wpnonce' => 'settings-nonce' ) );

		$outcome = $this->outcome( array( $this->admin( $this->bare_oauth() ), 'handle_oauth_disconnect' ) );

		$this->assertInstanceOf( DragonContentDecay_Test_Die::class, $outcome );
		$this->assertSame( 403, $outcome->status );
		$this->assertSame( $stored, get_option( 'dragoncontentdecay_google_tokens' ) );
	}

	// ---- callback --------------------------------------------------------------

	public function test_callback_with_the_right_state_connects(): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		set_transient( self::STATE_TRANSIENT, 'Abc123State', 900 );
		update_option( 'dragoncontentdecay_google_auth_revoked', 1 );
		$this->query(
			array(
				'tab'    => 'settings',
				'action' => 'callback',
				'state'  => 'Abc123State',
				'code'   => '4/0AX4XfWh-abc_DEF.ghi~jkl',
				'scope'  => OAuth::SCOPE_ANALYTICS,
			)
		);

		$outcome = $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_request' ) );

		$this->assertInstanceOf( DragonContentDecay_Test_Redirect::class, $outcome );
		$this->assertTrue( $outcome->safe );
		$this->assertSame( self::SETTINGS_URL . '&connected=1', $outcome->location );
		$this->assertSame( array( '4/0AX4XfWh-abc_DEF.ghi~jkl' ), $oauth->codes );
		$this->assertSame( $this->good_tokens(), $this->stored_tokens() );
		$this->assertSame( OAuth::SCOPE_ANALYTICS, get_option( 'dragoncontentdecay_google_granted_scopes' ) );
		$this->assertFalse( OAuth::is_access_revoked() );
		$this->assertFalse( get_transient( self::STATE_TRANSIENT ), 'the state is single-use' );
	}

	/**
	 * @dataProvider exact_codes
	 */
	public function test_the_code_reaches_the_exchange_byte_for_byte( string $code ): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		set_transient( self::STATE_TRANSIENT, 'state-1', 900 );
		$this->query(
			array(
				'tab'    => 'settings',
				'action' => 'callback',
				'state'  => 'state-1',
				'code'   => $code,
			)
		);

		$outcome = $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_request' ) );

		$this->assertSame( array( $code ), $oauth->codes );
		$this->assertSame( self::SETTINGS_URL . '&connected=1', $outcome->location );
	}

	public static function exact_codes(): array {
		return array(
			'google format'      => array( '4/0AX4XfWjabc-DEF_ghi.jkl~mno' ),
			'older format'       => array( '4/P7q7W91a-oMsCeLvIaQm6bTrgtp7' ),
			'percent octets'     => array( 'a%2Fb%41c%20d' ),
			'plus equals amp'    => array( 'a+b=c&d' ),
			'quotes'             => array( 'it\'s "quoted"' ),
			'backslashes'        => array( 'a\\b\\\\c' ),
			'angle brackets'     => array( 'a<b>c<d' ),
			'inner double space' => array( 'a  b' ),
			'outer spaces'       => array( ' a ' ),
		);
	}

	/**
	 * @dataProvider unusable_codes
	 *
	 * @param mixed $code Code argument.
	 */
	public function test_a_code_that_cannot_be_a_code_is_refused_whole( $code ): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		set_transient( self::STATE_TRANSIENT, 'state-1', 900 );
		$this->query(
			array(
				'tab'    => 'settings',
				'action' => 'callback',
				'state'  => 'state-1',
				'code'   => $code,
			)
		);

		$outcome = $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_request' ) );

		$this->assertInstanceOf( DragonContentDecay_Test_Redirect::class, $outcome );
		$this->assertSame( self::SETTINGS_URL . '&oauth_error=callback', $outcome->location );
		$this->assertSame( array(), $oauth->codes, 'nothing is sent to Google' );
		$this->assertNull( $this->stored_tokens() );
		$this->assertFalse( get_transient( self::STATE_TRANSIENT ), 'the state is still used up' );
	}

	public static function unusable_codes(): array {
		return array(
			'empty'             => array( '' ),
			'control character' => array( "4/0A\x01bc" ),
			'null byte'         => array( "4/0A\x00bc" ),
			'line break'        => array( "4/0A\r\nbc" ),
			'trailing newline'  => array( "4/0Abc\n" ),
			'tab'               => array( "4/0A\tbc" ),
			'multibyte'         => array( '4/0Aé' ),
			'sent as a list'    => array( array( '4/0Abc' ) ),
		);
	}

	/**
	 * @dataProvider bad_states
	 *
	 * @param array $state Query arguments carrying the state (or none).
	 */
	public function test_callback_with_a_bad_state_is_refused( array $state ): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		set_transient( self::STATE_TRANSIENT, 'state-1', 900 );
		$this->query(
			$state + array(
				'tab'    => 'settings',
				'action' => 'callback',
				'code'   => '4/0Abc',
			)
		);

		$outcome = $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_request' ) );

		$this->assertInstanceOf( DragonContentDecay_Test_Redirect::class, $outcome );
		$this->assertTrue( $outcome->safe );
		$this->assertSame( self::SETTINGS_URL . '&oauth_error=state', $outcome->location );
		$this->assertSame( array(), $oauth->codes );
		$this->assertNull( $this->stored_tokens() );
	}

	public static function bad_states(): array {
		return array(
			'missing'        => array( array() ),
			'wrong value'    => array( array( 'state' => 'state-2' ) ),
			'empty'          => array( array( 'state' => '' ) ),
			'sent as a list' => array( array( 'state' => array( 'state-1' ) ) ),
			'a link nonce'   => array( array( 'state' => 'link-nonce' ) ),
		);
	}

	public function test_callback_with_no_pending_sign_in_is_refused(): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		$this->query(
			array(
				'tab'    => 'settings',
				'action' => 'callback',
				'state'  => '',
				'code'   => '4/0Abc',
			)
		);

		$outcome = $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_request' ) );

		$this->assertSame( self::SETTINGS_URL . '&oauth_error=state', $outcome->location );
		$this->assertSame( array(), $oauth->codes );
	}

	public function test_a_state_issued_to_another_user_is_refused(): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		set_transient( 'dragoncontentdecay_oauth_state_2', 'state-of-user-2', 900 );
		$this->query(
			array(
				'tab'    => 'settings',
				'action' => 'callback',
				'state'  => 'state-of-user-2',
				'code'   => '4/0Abc',
			)
		);

		$outcome = $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_request' ) );

		$this->assertSame( self::SETTINGS_URL . '&oauth_error=state', $outcome->location );
		$this->assertSame( array(), $oauth->codes );
		$this->assertSame( 'state-of-user-2', get_transient( 'dragoncontentdecay_oauth_state_2' ) );
	}

	public function test_callback_whose_exchange_fails_connects_nothing(): void {
		$oauth = $this->configured_oauth(
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Bad Request',
			)
		);
		set_transient( self::STATE_TRANSIENT, 'state-1', 900 );
		$this->query(
			array(
				'tab'    => 'settings',
				'action' => 'callback',
				'state'  => 'state-1',
				'code'   => '4/0Abc',
			)
		);

		$outcome = $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_request' ) );

		$this->assertSame( self::SETTINGS_URL . '&oauth_error=callback', $outcome->location );
		$this->assertSame( array( '4/0Abc' ), $oauth->codes );
		$this->assertNull( $this->stored_tokens() );
	}

	public function test_callback_with_a_google_error_reports_it(): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		set_transient( self::STATE_TRANSIENT, 'state-1', 900 );
		$this->query(
			array(
				'tab'    => 'settings',
				'action' => 'callback',
				'error'  => 'access_denied',
				'state'  => 'state-1',
			)
		);

		$outcome = $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_request' ) );

		$this->assertInstanceOf( DragonContentDecay_Test_Redirect::class, $outcome );
		$this->assertTrue( $outcome->safe );
		$this->assertSame( self::SETTINGS_URL . '&oauth_error=denied', $outcome->location );
		$this->assertSame( array(), $oauth->codes );
		$this->assertNull( $this->stored_tokens() );
	}

	public function test_callback_with_neither_code_nor_error_does_nothing(): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		set_transient( self::STATE_TRANSIENT, 'state-1', 900 );
		$this->query(
			array(
				'tab'    => 'settings',
				'action' => 'callback',
				'state'  => 'state-1',
			)
		);

		$this->assertNull( $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_request' ) ) );
		$this->assertSame( 'state-1', get_transient( self::STATE_TRANSIENT ) );
	}

	public function test_callback_without_the_capability_does_nothing(): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		set_transient( self::STATE_TRANSIENT, 'state-1', 900 );
		$GLOBALS['dragoncontentdecay_test_can'] = false;
		$this->query(
			array(
				'tab'    => 'settings',
				'action' => 'callback',
				'state'  => 'state-1',
				'code'   => '4/0Abc',
			)
		);
		$admin = $this->admin( $oauth );

		$this->assertNull( $this->outcome( array( $admin, 'handle_oauth_request' ) ) );
		$this->assertNull( $this->outcome( array( $admin, 'handle_oauth_callback' ) ) );
		$this->assertSame( array(), $oauth->codes );
		$this->assertNull( $this->stored_tokens() );
		$this->assertSame( 'state-1', get_transient( self::STATE_TRANSIENT ), 'the pending sign-in is untouched' );
	}

	public function test_the_callback_handler_checks_the_state_itself(): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		set_transient( self::STATE_TRANSIENT, 'state-1', 900 );
		$this->query(
			array(
				'state' => 'state-2',
				'code'  => '4/0Abc',
			)
		);

		$outcome = $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_callback' ) );

		$this->assertSame( self::SETTINGS_URL . '&oauth_error=state', $outcome->location );
		$this->assertSame( array(), $oauth->codes );
	}

	public function test_a_valid_link_nonce_does_not_stand_in_for_the_state(): void {
		$oauth = $this->configured_oauth( $this->good_tokens() );
		set_transient( self::STATE_TRANSIENT, 'state-1', 900 );
		$this->query(
			array(
				'tab'      => 'settings',
				'action'   => 'callback',
				'_wpnonce' => 'link-nonce',
				'code'     => '4/0Abc',
			)
		);

		$outcome = $this->outcome( array( $this->admin( $oauth ), 'handle_oauth_request' ) );

		$this->assertSame( self::SETTINGS_URL . '&oauth_error=state', $outcome->location );
		$this->assertSame( array(), $oauth->codes );
	}
}
