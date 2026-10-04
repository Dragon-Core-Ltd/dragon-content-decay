<?php
/**
 * The settings form: nothing is saved without its nonce and the capability,
 * every posted field is read through a core sanitizer, and each field stores
 * what the form sends. The Google client secret is stored byte for byte.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Admin;
use DragonContentDecay\OAuth;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wporg-scan-stubs.php';

/**
 * Admin::render_settings_page() and Admin::save_settings().
 */
final class WporgScanSettingsTest extends TestCase {

	private const ACTION = 'dragoncontentdecay_save_settings';
	private const FIELD  = 'dragoncontentdecay_settings_nonce';
	private const MASK   = '••••••••';

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		$GLOBALS['dragoncontentdecay_test_settings_errors'] = array();
		$GLOBALS['dragoncontentdecay_test_nonces']          = array(
			self::ACTION                      => 'settings-nonce',
			'dragoncontentdecay_oauth_action' => 'link-nonce',
		);
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	protected function tearDown(): void {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		dragoncontentdecay_test_reset();
		$GLOBALS['dragoncontentdecay_test_settings_errors'] = array();
	}

	private function admin(): Admin {
		return new Admin( AnalyzerTestSupport::oauth(), AnalyzerTestSupport::analyzer() );
	}

	/**
	 * Post the form the way WordPress sees it: slashed, mirrored into $_REQUEST.
	 *
	 * @param array       $fields Unslashed form fields.
	 * @param string|null $nonce  Nonce, or null to leave it out.
	 */
	private function post( array $fields, ?string $nonce = 'settings-nonce' ): void {
		if ( null !== $nonce ) {
			$fields[ self::FIELD ] = $nonce;
		}
		$slashed  = map_deep(
			$fields,
			static function ( $leaf ) {
				return is_string( $leaf ) ? addslashes( $leaf ) : $leaf;
			}
		);
		$_POST    = $slashed;
		$_REQUEST = $slashed;
	}

	/**
	 * Load the settings screen (which handles a posted form) and return its HTML.
	 */
	private function render(): string {
		ob_start();
		try {
			$this->admin()->render_settings_page();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Call the private save method directly.
	 */
	private function save_directly(): void {
		$method = new ReflectionMethod( Admin::class, 'save_settings' );
		$method->invoke( $this->admin() );
	}

	/**
	 * The whole form as the settings screen sends it.
	 */
	private function full_form(): array {
		return array(
			'dragoncontentdecay_google_client_id'         => '1234567890-abc.apps.googleusercontent.com',
			'dragoncontentdecay_google_client_secret'     => 'GOCSPX-Ab3_dEf-456xYz',
			'dragoncontentdecay_ga4_property_id'          => '123456789',
			'dragoncontentdecay_gsc_enabled'              => '1',
			'dragoncontentdecay_gsc_property'             => 'sc-domain:example.test',
			'dragoncontentdecay_decay_threshold'          => '-35',
			'dragoncontentdecay_comparison_period'        => '60',
			'dragoncontentdecay_post_types'               => array( 'post', 'page' ),
			'dragoncontentdecay_email_frequency'          => 'off',
			'dragoncontentdecay_delete_data_on_uninstall' => '1',
			'submit'                                      => 'Save Settings',
		);
	}

	/**
	 * Settings an attacker would want to change, present before each refusal test.
	 */
	private function seed(): array {
		update_option( 'dragoncontentdecay_google_client_id', 'kept-client-id' );
		OAuth::set_client_secret( 'kept-secret' );
		update_option( 'dragoncontentdecay_post_types', array( 'post' ) );
		update_option( 'dragoncontentdecay_delete_data_on_uninstall', 0 );
		update_option( 'dragoncontentdecay_gsc_enabled', 0 );
		return $GLOBALS['dragoncontentdecay_test_options'];
	}

	// ---- nonce and capability ------------------------------------------------

	public function test_a_valid_post_saves_every_field(): void {
		$this->post( $this->full_form() );

		$html = $this->render();

		$this->assertStringContainsString( 'Settings saved.', $html );
		$this->assertSame( '1234567890-abc.apps.googleusercontent.com', get_option( 'dragoncontentdecay_google_client_id' ) );
		$this->assertSame( 'GOCSPX-Ab3_dEf-456xYz', OAuth::get_client_secret() );
		$this->assertStringStartsWith( 'DRGNc1:', get_option( 'dragoncontentdecay_google_client_secret' ) );
		$this->assertSame( '123456789', get_option( 'dragoncontentdecay_ga4_property_id' ) );
		$this->assertSame( 1, get_option( 'dragoncontentdecay_gsc_enabled' ) );
		$this->assertSame( 'sc-domain:example.test', get_option( 'dragoncontentdecay_gsc_property' ) );
		$this->assertSame( -35, get_option( 'dragoncontentdecay_decay_threshold' ) );
		$this->assertSame( 60, get_option( 'dragoncontentdecay_comparison_period' ) );
		$this->assertSame( array( 'post', 'page' ), get_option( 'dragoncontentdecay_post_types' ) );
		$this->assertSame( 'off', get_option( 'dragoncontentdecay_email_frequency' ) );
		$this->assertSame( 1, get_option( 'dragoncontentdecay_delete_data_on_uninstall' ) );
	}

	public function test_the_secret_is_never_printed_back(): void {
		$this->post( $this->full_form() );

		$html = $this->render();

		$this->assertStringNotContainsString( 'GOCSPX-Ab3_dEf-456xYz', $html );
		$this->assertStringContainsString( 'value="' . self::MASK . '"', $html );
	}

	public function test_a_post_without_the_nonce_saves_nothing(): void {
		$before = $this->seed();
		$this->post( $this->full_form(), null );

		$html = $this->render();

		$this->assertStringNotContainsString( 'Settings saved.', $html );
		$this->assertSame( $before, $GLOBALS['dragoncontentdecay_test_options'] );
		$this->assertSame( 'kept-secret', OAuth::get_client_secret() );
	}

	/**
	 * @dataProvider bad_nonces
	 *
	 * @param mixed $nonce Nonce value posted.
	 */
	public function test_a_post_with_a_bad_nonce_saves_nothing( $nonce ): void {
		$before                = $this->seed();
		$fields                = $this->full_form();
		$fields[ self::FIELD ] = $nonce;
		$this->post( $fields, null );

		$html = $this->render();

		$this->assertStringNotContainsString( 'Settings saved.', $html );
		$this->assertSame( $before, $GLOBALS['dragoncontentdecay_test_options'] );
	}

	public static function bad_nonces(): array {
		return array(
			'wrong value'    => array( 'not-the-nonce' ),
			'another action' => array( 'link-nonce' ),
			'empty'          => array( '' ),
			'sent as a list' => array( array( 'settings-nonce' ) ),
		);
	}

	public function test_a_user_without_the_capability_saves_nothing(): void {
		$before                                 = $this->seed();
		$GLOBALS['dragoncontentdecay_test_can'] = false;
		$this->post( $this->full_form() );

		$html = $this->render();

		$this->assertStringNotContainsString( 'Settings saved.', $html );
		$this->assertSame( $before, $GLOBALS['dragoncontentdecay_test_options'] );
	}

	public function test_the_save_method_checks_the_nonce_itself(): void {
		$before = $this->seed();
		$this->post( $this->full_form(), 'link-nonce' );

		$this->save_directly();

		$this->assertSame( $before, $GLOBALS['dragoncontentdecay_test_options'] );
		$this->assertSame( array(), $GLOBALS['dragoncontentdecay_test_settings_errors'] );
	}

	public function test_the_save_method_checks_the_capability_itself(): void {
		$before                                 = $this->seed();
		$GLOBALS['dragoncontentdecay_test_can'] = false;
		$this->post( $this->full_form() );

		$this->save_directly();

		$this->assertSame( $before, $GLOBALS['dragoncontentdecay_test_options'] );
		$this->assertSame( array(), $GLOBALS['dragoncontentdecay_test_settings_errors'] );
	}

	// ---- client secret -------------------------------------------------------

	/**
	 * @dataProvider exact_secrets
	 */
	public function test_the_client_secret_is_stored_byte_for_byte( string $secret ): void {
		$this->post( array( 'dragoncontentdecay_google_client_secret' => $secret ) );

		$this->save_directly();

		$this->assertSame( $secret, OAuth::get_client_secret() );
	}

	public static function exact_secrets(): array {
		return array(
			'google format'        => array( 'GOCSPX-Ab3_dEf-456xYz7890123456789' ),
			'percent octets'       => array( 'ab%20cd%41ef%2F' ),
			'plus and ampersand'   => array( 'a+b&c=d' ),
			'angle brackets'       => array( 'a<b>c<d' ),
			'unclosed angle'       => array( 'abc<def ghi' ),
			'single quote'         => array( "it's" ),
			'double quote'         => array( 'say "hi"' ),
			'backslashes'          => array( 'a\\b\\\\c' ),
			'inner double space'   => array( 'a  b' ),
			'multibyte'            => array( 'pässwörd-é-日本' ),
			'brackets and symbols' => array( 'a[]{}|^~`!@#$*()' ),
			'html entity text'     => array( 'a&amp;b&lt;' ),
		);
	}

	public function test_spaces_pasted_around_the_secret_are_dropped(): void {
		$this->post( array( 'dragoncontentdecay_google_client_secret' => "  GOCSPX-abc \t" ) );

		$this->save_directly();

		$this->assertSame( 'GOCSPX-abc', OAuth::get_client_secret() );
	}

	public function test_control_characters_in_the_secret_are_dropped(): void {
		$this->post( array( 'dragoncontentdecay_google_client_secret' => "GOCSPX\x00-a\x01b\r\nc" ) );

		$this->save_directly();

		$this->assertSame( 'GOCSPX-abc', OAuth::get_client_secret() );
	}

	public function test_the_mask_keeps_the_stored_secret(): void {
		OAuth::set_client_secret( 'stored-secret' );
		$this->post( array( 'dragoncontentdecay_google_client_secret' => self::MASK ) );

		$this->save_directly();

		$this->assertSame( 'stored-secret', OAuth::get_client_secret() );
	}

	public function test_an_emptied_field_clears_the_secret(): void {
		OAuth::set_client_secret( 'stored-secret' );
		$this->post( array( 'dragoncontentdecay_google_client_secret' => '' ) );

		$this->save_directly();

		$this->assertSame( '', OAuth::get_client_secret() );
		$this->assertFalse( OAuth::has_client_secret() );
	}

	public function test_a_missing_secret_field_keeps_the_stored_secret(): void {
		OAuth::set_client_secret( 'stored-secret' );
		$this->post( array( 'dragoncontentdecay_ga4_property_id' => '42' ) );

		$this->save_directly();

		$this->assertSame( 'stored-secret', OAuth::get_client_secret() );
	}

	public function test_a_secret_sent_as_a_list_keeps_the_stored_secret(): void {
		OAuth::set_client_secret( 'stored-secret' );
		$this->post( array( 'dragoncontentdecay_google_client_secret' => array( 'x', 'y' ) ) );

		$this->save_directly();

		$this->assertSame( 'stored-secret', OAuth::get_client_secret() );
	}

	// ---- post types ----------------------------------------------------------

	/**
	 * @dataProvider posted_post_types
	 *
	 * @param mixed $posted   Posted value.
	 * @param array $expected Stored list.
	 */
	public function test_post_types_are_stored_as_a_flat_list( $posted, array $expected ): void {
		$this->post( array( 'dragoncontentdecay_post_types' => $posted ) );

		$this->save_directly();

		$this->assertSame( $expected, get_option( 'dragoncontentdecay_post_types' ) );
	}

	public static function posted_post_types(): array {
		return array(
			'two boxes'         => array( array( 'post', 'page' ), array( 'post', 'page' ) ),
			'one box'           => array( array( 'product' ), array( 'product' ) ),
			'custom type names' => array( array( 'my_type', 'my-type2', 'book' ), array( 'my_type', 'my-type2', 'book' ) ),
			'a bare string'     => array( 'post', array( 'post' ) ),
			'markup stripped'   => array( array( '<b>book</b>', ' page ' ), array( 'book', 'page' ) ),
			'nested list'       => array( array( 'post', array( 'evil' ), 'page' ), array( 'post', 'page' ) ),
			'slashed quote'     => array( array( "o'type" ), array( "o'type" ) ),
		);
	}

	public function test_a_form_with_no_post_type_ticked_keeps_the_stored_list(): void {
		update_option( 'dragoncontentdecay_post_types', array( 'post', 'page' ) );
		$this->post( array( 'dragoncontentdecay_ga4_property_id' => '42' ) );

		$this->save_directly();

		$this->assertSame( array( 'post', 'page' ), get_option( 'dragoncontentdecay_post_types' ) );
	}

	// ---- switches ------------------------------------------------------------

	/**
	 * @dataProvider switch_values
	 *
	 * @param array $fields   Posted fields.
	 * @param int   $expected Stored value.
	 */
	public function test_checkbox_truth_table( array $fields, int $expected ): void {
		update_option( 'dragoncontentdecay_gsc_enabled', 1 - $expected );
		update_option( 'dragoncontentdecay_delete_data_on_uninstall', 1 - $expected );
		$this->post( $fields );

		$this->save_directly();

		$this->assertSame( $expected, get_option( 'dragoncontentdecay_gsc_enabled' ) );
		$this->assertSame( $expected, get_option( 'dragoncontentdecay_delete_data_on_uninstall' ) );
	}

	public static function switch_values(): array {
		$both = static function ( $value ) {
			return array(
				'dragoncontentdecay_gsc_enabled'              => $value,
				'dragoncontentdecay_delete_data_on_uninstall' => $value,
			);
		};

		return array(
			'missing'      => array( array(), 0 ),
			'ticked'       => array( $both( '1' ), 1 ),
			'browser "on"' => array( $both( 'on' ), 1 ),
			'zero'         => array( $both( '0' ), 0 ),
			'empty'        => array( $both( '' ), 0 ),
		);
	}

	// ---- text and number fields ----------------------------------------------

	public function test_text_fields_are_cleaned_like_before(): void {
		$this->post(
			array(
				'dragoncontentdecay_google_client_id' => "  123-abc.apps.googleusercontent.com\n",
				'dragoncontentdecay_ga4_property_id'  => ' 987654321 ',
				'dragoncontentdecay_gsc_property'     => 'https://www.example.test/',
				'dragoncontentdecay_email_frequency'  => 'weekly',
			)
		);

		$this->save_directly();

		$this->assertSame( '123-abc.apps.googleusercontent.com', get_option( 'dragoncontentdecay_google_client_id' ) );
		$this->assertSame( '987654321', get_option( 'dragoncontentdecay_ga4_property_id' ) );
		$this->assertSame( 'https://www.example.test/', get_option( 'dragoncontentdecay_gsc_property' ) );
		$this->assertSame( 'weekly', get_option( 'dragoncontentdecay_email_frequency' ) );
	}

	public function test_numbers_are_stored_as_integers(): void {
		$this->post(
			array(
				'dragoncontentdecay_decay_threshold'   => '-40abc',
				'dragoncontentdecay_comparison_period' => '90',
			)
		);

		$this->save_directly();

		$this->assertSame( -40, get_option( 'dragoncontentdecay_decay_threshold' ) );
		$this->assertSame( 90, get_option( 'dragoncontentdecay_comparison_period' ) );
	}

	public function test_list_shaped_text_fields_do_not_break_the_save(): void {
		$this->post(
			array(
				'dragoncontentdecay_google_client_id' => array( 'a' ),
				'dragoncontentdecay_ga4_property_id'  => array( 'b' ),
				'dragoncontentdecay_email_frequency'  => array( 'weekly' ),
				'dragoncontentdecay_gsc_property'     => array( 'c' ),
			)
		);

		$this->save_directly();

		$this->assertSame( '', get_option( 'dragoncontentdecay_google_client_id' ) );
		$this->assertSame( '', get_option( 'dragoncontentdecay_ga4_property_id' ) );
		$this->assertSame( '', get_option( 'dragoncontentdecay_email_frequency' ) );
		$this->assertSame( '', get_option( 'dragoncontentdecay_gsc_property' ) );
		$this->assertContains( 'settings_saved', array_column( $GLOBALS['dragoncontentdecay_test_settings_errors'], 'code' ) );
	}
}
