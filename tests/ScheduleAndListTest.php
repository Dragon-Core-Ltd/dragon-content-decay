<?php
/**
 * The daily sync books itself again when it is missing (every site of a
 * network included), uninstall runs per site, and the list-table Decay column
 * reaches tracked pages and sorts unscored posts last.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Admin;
use DragonContentDecay\Scheduler;
use PHPUnit\Framework\TestCase;

final class ScheduleAndListTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_a_missing_daily_sync_is_booked_again(): void {
		$this->assertFalse( wp_next_scheduled( Scheduler::CRON_HOOK ) );

		$this->assertTrue( Scheduler::ensure_scheduled() );

		$this->assertNotFalse( wp_next_scheduled( Scheduler::CRON_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( Scheduler::CRON_HOOK ) );
	}

	public function test_an_existing_daily_sync_is_left_alone(): void {
		$at = time() + 5 * HOUR_IN_SECONDS;
		wp_schedule_event( $at, 'daily', Scheduler::CRON_HOOK );

		Scheduler::ensure_scheduled();

		$this->assertSame( $at, wp_next_scheduled( Scheduler::CRON_HOOK ) );
	}

	public function test_a_run_that_ran_out_of_time_books_a_follow_up(): void {
		$analyzer = new class( AnalyzerTestSupport::ga4(), new \DragonContentDecay\API_GSC( AnalyzerTestSupport::oauth() ) ) extends \DragonContentDecay\Analyzer {
			public function analyze_all(): array {
				return array(
					'analyzed'     => 0,
					'failed'       => 0,
					'cursor_saved' => true,
					'pending'      => true,
				);
			}
		};
		wp_schedule_event( time() + 20 * HOUR_IN_SECONDS, 'daily', Scheduler::CRON_HOOK );
		update_option( 'dragoncontentdecay_last_sync_count', 42 );

		$result = ( new Scheduler( $analyzer ) )->sync();

		$this->assertTrue( $result['pending'] );
		$this->assertSame( 42, get_option( 'dragoncontentdecay_last_sync_count' ), 'the last full result is kept' );
		$this->assertLessThan( time() + HOUR_IN_SECONDS, wp_next_scheduled( Scheduler::CRON_HOOK ) );
	}

	public function test_unscored_posts_sort_last_both_ways(): void {
		$GLOBALS['wpdb'] = AnalyzerTestSupport::wpdb( array() );
		$admin           = new Admin( AnalyzerTestSupport::oauth(), AnalyzerTestSupport::analyzer() );

		foreach ( array( 'asc', 'desc' ) as $order ) {
			$_GET['order'] = $order;
			$clauses       = $admin->modify_query_for_decay_sort(
				array(
					'join'    => '',
					'orderby' => '',
				)
			);
			$this->assertMatchesRegularExpression( '/^dcd_scores\.decay_score IS NULL, dcd_scores\.decay_score (ASC|DESC)$/', $clauses['orderby'], $order );
		}
		unset( $_GET['order'] );
	}

	public function test_tracked_pages_get_the_sortable_decay_column(): void {
		update_option( 'dragoncontentdecay_post_types', array( 'post', 'page', 'product' ) );

		new Admin( AnalyzerTestSupport::oauth(), AnalyzerTestSupport::analyzer() );

		$filters = $GLOBALS['dragoncontentdecay_test_filters'];
		$this->assertArrayHasKey( 'manage_pages_columns', $filters );
		$this->assertArrayHasKey( 'manage_edit-page_sortable_columns', $filters );
		$this->assertArrayHasKey( 'manage_edit-product_sortable_columns', $filters );
		$this->assertArrayHasKey( 'manage_edit-post_sortable_columns', $filters );
	}

	public function test_untracked_pages_get_no_decay_column(): void {
		update_option( 'dragoncontentdecay_post_types', array( 'post' ) );

		new Admin( AnalyzerTestSupport::oauth(), AnalyzerTestSupport::analyzer() );

		$this->assertArrayNotHasKey( 'manage_pages_columns', $GLOBALS['dragoncontentdecay_test_filters'] );
	}

	public function test_uninstall_removes_the_data_of_every_site_in_a_network(): void {
		$GLOBALS['dragoncontentdecay_test_sites'] = array( 1, 2, 3 );
		update_option( 'dragoncontentdecay_delete_data_on_uninstall', 1 );
		$GLOBALS['wpdb']          = AnalyzerTestSupport::wpdb( array() );
		$GLOBALS['wpdb']->options = 'wp_options';

		defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'dragon-content-decay/dragon-content-decay.php' );
		require __DIR__ . '/../uninstall.php';

		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		foreach ( array( 'wp_', 'wp_2_', 'wp_3_' ) as $prefix ) {
			$this->assertStringContainsString( "DROP TABLE IF EXISTS {$prefix}dcd_scores", $sql );
			$this->assertStringContainsString( "DELETE FROM {$prefix}options WHERE option_name LIKE 'dragoncontentdecay", $sql );
		}
		$this->assertSame( 1, $GLOBALS['dragoncontentdecay_test_blog_id'], 'the original site is restored' );
	}
}
