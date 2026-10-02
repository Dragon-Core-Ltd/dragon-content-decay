<?php
/**
 * Uninstall removes data only when the stored opt-in is a clear yes.
 *
 * @package DragonContentDecay
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class UninstallOptInTest extends TestCase {

	public static function opt_in_values(): array {
		return array(
			'true'          => array( true, true ),
			'int 1'         => array( 1, true ),
			'string 1'      => array( '1', true ),
			'string true'   => array( 'true', true ),
			'upper TRUE'    => array( 'TRUE', true ),
			'padded yes'    => array( ' yes ', true ),
			'on'            => array( 'on', true ),
			'false'         => array( false, false ),
			'int 0'         => array( 0, false ),
			'empty string'  => array( '', false ),
			'string 0'      => array( '0', false ),
			'string false'  => array( 'false', false ),
			'no'            => array( 'no', false ),
			'off'           => array( 'off', false ),
			'capital No'    => array( 'No', false ),
			'random string' => array( 'random', false ),
			'null'          => array( null, false ),
			'empty array'   => array( array(), false ),
		);
	}

	private function uninstall( array $options ): void {
		dragoncontentdecay_test_reset();
		$GLOBALS['dragoncontentdecay_test_options'] = $options;
		$GLOBALS['wpdb']                            = AnalyzerTestSupport::wpdb( array() );
		wp_schedule_event( 1000, 'daily', 'dragoncontentdecay_daily_sync' );

		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'dragon-content-decay/dragon-content-decay.php' );
		require __DIR__ . '/../uninstall.php';
	}

	#[DataProvider( 'opt_in_values' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_only_a_clear_yes_deletes_the_data( $stored, bool $deletes ): void {
		$this->uninstall( array( 'dragoncontentdecay_delete_data_on_uninstall' => $stored ) );

		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		if ( $deletes ) {
			$this->assertStringContainsString( 'DROP TABLE IF EXISTS wp_dcd_analytics', $sql );
			$this->assertStringContainsString( 'DROP TABLE IF EXISTS wp_dcd_scores', $sql );
			$this->assertStringContainsString( "DELETE FROM wp_options WHERE option_name LIKE 'dragoncontentdecay", $sql );
			$this->assertFalse( wp_next_scheduled( 'dragoncontentdecay_daily_sync' ) );
		} else {
			$this->assertSame( array(), $GLOBALS['wpdb']->queries );
			$this->assertNotFalse( wp_next_scheduled( 'dragoncontentdecay_daily_sync' ) );
		}
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_missing_opt_in_keeps_the_data(): void {
		$this->uninstall( array() );

		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		$this->assertNotFalse( wp_next_scheduled( 'dragoncontentdecay_daily_sync' ) );
	}
}
