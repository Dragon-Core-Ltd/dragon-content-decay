<?php
/**
 * Saving the digest frequency reports a digest that could not be scheduled
 * instead of only "Settings saved."
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Admin;
use DragonContentDecay\Notifications;
use PHPUnit\Framework\TestCase;

final class SettingsDigestTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		$GLOBALS['dragoncontentdecay_test_settings_errors'] = array();
		$_POST = array(
			'dragoncontentdecay_settings_nonce' => 'valid',
			'dragoncontentdecay_email_frequency' => 'monthly',
		);
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	private function save(): void {
		$admin  = new Admin( AnalyzerTestSupport::oauth(), AnalyzerTestSupport::analyzer() );
		$method = new ReflectionMethod( Admin::class, 'save_settings' );
		$method->invoke( $admin );
	}

	private function codes(): array {
		return array_column( $GLOBALS['dragoncontentdecay_test_settings_errors'], 'code' );
	}

	public function test_saving_monthly_books_the_digest(): void {
		add_filter( 'cron_schedules', array( Notifications::class, 'add_schedules' ) );

		$this->save();

		$this->assertSame( 'monthly', wp_get_schedule( Notifications::MONTHLY_HOOK ) );
		$this->assertNotContains( 'digest_not_scheduled', $this->codes() );
	}

	public function test_a_digest_that_cannot_be_booked_is_reported(): void {
		// No 'monthly' recurrence registered: core refuses the booking.
		$this->save();

		$this->assertFalse( wp_next_scheduled( Notifications::MONTHLY_HOOK ) );
		$this->assertContains( 'digest_not_scheduled', $this->codes() );
	}
}
