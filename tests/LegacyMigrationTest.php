<?php
/**
 * The pre-1.0.1 dcd_ option migration runs once, then never again, and an
 * opted-in uninstall removes only the plugin's own legacy option names.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Plugin;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-plugin.php';

final class LegacyMigrationTest extends TestCase {

	private const FLAG = 'dragoncontentdecay_legacy_migrated';

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	private function migrate(): void {
		$method = new ReflectionMethod( Plugin::class, 'migrate_legacy_prefix' );
		$method->invoke( null );
	}

	public function test_the_first_run_copies_the_legacy_options_and_records_it(): void {
		update_option( 'dcd_post_types', array( 'post', 'page' ) );
		update_option( 'dcd_ga4_property_id', '123456' );
		update_option( 'dcd_db_version', '1.0.0' );

		$this->migrate();

		$this->assertSame( array( 'post', 'page' ), get_option( 'dragoncontentdecay_post_types' ) );
		$this->assertSame( '123456', get_option( 'dragoncontentdecay_ga4_property_id' ) );
		$this->assertNull( get_option( 'dcd_post_types', null ) );
		$this->assertNull( get_option( 'dcd_db_version', null ) );
		$this->assertTrue( (bool) get_option( self::FLAG ) );
	}

	public function test_a_fresh_install_records_the_migration_as_done(): void {
		$this->migrate();

		$this->assertTrue( (bool) get_option( self::FLAG ) );
	}

	public function test_once_recorded_legacy_options_are_left_alone(): void {
		update_option( self::FLAG, 1 );
		update_option( 'dragoncontentdecay_google_tokens', 'live-connection' );
		update_option( 'dcd_google_tokens', 'stray-copy' );
		update_option( 'dcd_post_types', array( 'product' ) );
		update_option( 'dcd_db_version', 'another-plugin' );

		$this->migrate();

		$this->assertSame( 'live-connection', get_option( 'dragoncontentdecay_google_tokens' ) );
		$this->assertSame( 'stray-copy', get_option( 'dcd_google_tokens' ) );
		$this->assertFalse( get_option( 'dragoncontentdecay_post_types' ) );
		$this->assertSame( array( 'product' ), get_option( 'dcd_post_types' ) );
		$this->assertSame( 'another-plugin', get_option( 'dcd_db_version' ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_uninstall_removes_only_the_named_legacy_options(): void {
		dragoncontentdecay_test_reset();
		update_option( 'dragoncontentdecay_delete_data_on_uninstall', 1 );
		update_option( 'dcd_google_tokens', 'old-tokens' );
		update_option( 'dcd_post_types', array( 'post' ) );
		update_option( 'dcd_db_version', '1.0.0' );
		update_option( 'dcd_other_plugin_setting', 'keep me' );
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array() );

		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'dragon-content-decay/dragon-content-decay.php' );
		require __DIR__ . '/../uninstall.php';

		$this->assertNull( get_option( 'dcd_google_tokens', null ) );
		$this->assertNull( get_option( 'dcd_post_types', null ) );
		$this->assertNull( get_option( 'dcd_db_version', null ) );
		$this->assertSame( 'keep me', get_option( 'dcd_other_plugin_setting' ) );

		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		$this->assertStringNotContainsString( 'dcd\_', $sql, 'no dcd_ wildcard reaches the database' );
		$this->assertStringContainsString( "DELETE FROM wp_options WHERE option_name LIKE 'dragoncontentdecay", $sql );
	}
}
