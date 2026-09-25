<?php
/**
 * Digest emails: the monthly schedule exists, and the rendered email works
 * when sent from cron (no logged-in user) and leaves From to the mailer.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use DragonContentDecay\Notifications;
use PHPUnit\Framework\TestCase;

final class NotificationsTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		update_option( 'admin_email', 'owner@example.test' );
		add_filter( 'cron_schedules', array( Notifications::class, 'add_schedules' ) );
	}

	private function notifications( array $posts = array(), array $summary = array() ): Notifications {
		$posts   = $posts ? $posts : array(
			array(
				'post_id'            => 42,
				'post_title'         => 'A post',
				'decay_score'        => -60.0,
				'pageviews_current'  => 40,
				'pageviews_previous' => 100,
			),
		);
		$summary = $summary + array(
			'total'     => 3,
			'decaying'  => 1,
			'stable'    => 1,
			'growing'   => 1,
			'avg_decay' => -10.0,
		);

		return new class( $posts, $summary ) extends Notifications {
			private array $posts;
			private array $summary;

			public function __construct( array $posts, array $summary ) {
				$this->posts   = $posts;
				$this->summary = $summary;
				parent::__construct();
			}

			protected function make_analyzer(): Analyzer {
				$analyzer = new class( $this->posts, $this->summary ) extends Analyzer {
					private array $p;
					private array $s;

					public function __construct( array $p, array $s ) {
						parent::__construct( AnalyzerTestSupport::ga4(), new \DragonContentDecay\API_GSC( AnalyzerTestSupport::oauth() ) );
						$this->p = $p;
						$this->s = $s;
					}

					public function get_decaying_posts( int $limit = 50 ): array {
						unset( $limit );
						return $this->p;
					}

					public function get_summary(): array {
						return $this->s;
					}
				};
				return $analyzer;
			}
		};
	}

	public function test_monthly_schedule_is_registered(): void {
		$schedules = wp_get_schedules();

		$this->assertArrayHasKey( 'monthly', $schedules );
		$this->assertSame( 30 * DAY_IN_SECONDS, $schedules['monthly']['interval'] );
	}

	public function test_an_existing_monthly_schedule_is_left_alone(): void {
		$existing = array(
			'monthly' => array(
				'interval' => 31 * DAY_IN_SECONDS,
				'display'  => 'Monthly (other plugin)',
			),
		);

		$this->assertSame( $existing, Notifications::add_schedules( $existing ) );
	}

	public function test_choosing_monthly_books_the_monthly_digest(): void {
		$result = $this->notifications()->reschedule_digests( 'off', 'monthly' );

		$this->assertTrue( $result );
		$this->assertNotFalse( wp_next_scheduled( Notifications::MONTHLY_HOOK ) );
		$this->assertSame( 'monthly', wp_get_schedule( Notifications::MONTHLY_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Notifications::WEEKLY_HOOK ) );
	}

	public function test_booking_failure_is_reported(): void {
		$notifications = $this->notifications();
		// Something later on the filter removes the recurrence, so core refuses
		// the booking.
		add_filter(
			'cron_schedules',
			static function ( $schedules ) {
				unset( $schedules['monthly'] );
				return $schedules;
			}
		);

		$this->assertFalse( $notifications->reschedule_digests( 'off', 'monthly' ) );
		$this->assertFalse( wp_next_scheduled( Notifications::MONTHLY_HOOK ) );
	}

	public function test_missing_digest_event_is_rebooked_on_init(): void {
		// A site that picked "monthly" before the schedule was registered: the
		// option is set but no event exists, and the option-change hook will
		// never fire again.
		update_option( 'dragoncontentdecay_email_frequency', 'monthly' );
		wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', Notifications::WEEKLY_HOOK );

		$this->notifications()->ensure_digest_scheduled();

		$this->assertSame( 'monthly', wp_get_schedule( Notifications::MONTHLY_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Notifications::WEEKLY_HOOK ) );
	}

	public function test_digest_off_clears_any_leftover_events(): void {
		update_option( 'dragoncontentdecay_email_frequency', 'off' );
		wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', Notifications::WEEKLY_HOOK );

		$this->notifications()->ensure_digest_scheduled();

		$this->assertFalse( wp_next_scheduled( Notifications::WEEKLY_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Notifications::MONTHLY_HOOK ) );
	}

	public function test_booked_digest_is_not_rebooked(): void {
		update_option( 'dragoncontentdecay_email_frequency', 'weekly' );
		$when = time() + 3 * DAY_IN_SECONDS;
		wp_schedule_event( $when, 'weekly', Notifications::WEEKLY_HOOK );

		$this->notifications()->ensure_digest_scheduled();

		$this->assertSame( $when, wp_next_scheduled( Notifications::WEEKLY_HOOK ) );
	}

	public function test_cron_digest_links_each_post_to_its_editor(): void {
		update_option( 'dragoncontentdecay_email_frequency', 'monthly' );

		$this->notifications()->send_monthly_digest();

		$this->assertCount( 1, $GLOBALS['dragoncontentdecay_test_mail'] );
		$message = $GLOBALS['dragoncontentdecay_test_mail'][0]['message'];
		$this->assertStringContainsString( 'href="https://www.example.test/wp-admin/post.php?post=42&#038;action=edit"', $message );
		$this->assertStringNotContainsString( 'href=""', $message );
	}

	public function test_digest_subject_uses_the_plain_site_name(): void {
		update_option( 'dragoncontentdecay_email_frequency', 'weekly' );

		$this->notifications()->send_weekly_digest();

		$subject = $GLOBALS['dragoncontentdecay_test_mail'][0]['subject'];
		$this->assertStringStartsWith( "[Rich's Shop & Co]", $subject );
	}

	public function test_digest_leaves_the_from_address_to_the_mailer(): void {
		update_option( 'dragoncontentdecay_email_frequency', 'weekly' );

		$this->notifications()->send_weekly_digest();

		$headers = (array) $GLOBALS['dragoncontentdecay_test_mail'][0]['headers'];
		foreach ( $headers as $header ) {
			$this->assertStringStartsNotWith( 'from:', strtolower( $header ) );
		}
		$this->assertContains( 'Content-Type: text/html; charset=UTF-8', $headers );
	}

	public function test_weekly_digest_is_booked_for_monday_9am_site_time(): void {
		$previous = date_default_timezone_get();
		date_default_timezone_set( 'UTC' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- WordPress runs PHP in UTC; the test pins that.
		update_option( 'timezone_string', 'America/New_York' );

		try {
			$this->assertTrue( $this->notifications()->reschedule_digests( 'off', 'weekly' ) );
			$first = wp_next_scheduled( Notifications::WEEKLY_HOOK );
			$local = ( new DateTimeImmutable( '@' . $first ) )->setTimezone( new DateTimeZone( 'America/New_York' ) );

			$this->assertSame( 'Mon 09:00', $local->format( 'D H:i' ) );
			$this->assertGreaterThan( time(), $first );
		} finally {
			date_default_timezone_set( $previous ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Restores the value pinned above.
		}
	}

	public function test_monthly_digest_is_booked_for_the_1st_9am_site_time(): void {
		update_option( 'gmt_offset', 5.5 );

		$this->assertTrue( $this->notifications()->reschedule_digests( 'off', 'monthly' ) );
		$first = wp_next_scheduled( Notifications::MONTHLY_HOOK );
		$local = ( new DateTimeImmutable( '@' . $first ) )->setTimezone( new DateTimeZone( '+05:30' ) );

		$this->assertSame( '01 09:00', $local->format( 'd H:i' ) );
	}

	public function test_digest_says_syncing_stopped_when_access_is_revoked(): void {
		update_option( 'dragoncontentdecay_email_frequency', 'weekly' );
		update_option( 'dragoncontentdecay_google_auth_revoked', 1 );

		$this->notifications()->send_weekly_digest();

		$message = $GLOBALS['dragoncontentdecay_test_mail'][0]['message'];
		$this->assertStringContainsString( 'syncing has stopped', $message );
		$this->assertStringContainsString( 'Connect to Google again', $message );
	}

	public function test_digest_says_the_last_sync_failed(): void {
		update_option( 'dragoncontentdecay_email_frequency', 'weekly' );
		update_option( 'dragoncontentdecay_last_sync_status', 'failed' );
		update_option( 'dragoncontentdecay_last_sync_error', 'No GA4 property ID is set on the Settings tab.' );

		$this->notifications()->send_weekly_digest();

		$message = $GLOBALS['dragoncontentdecay_test_mail'][0]['message'];
		$this->assertStringContainsString( 'The last sync failed', $message );
		$this->assertStringContainsString( 'No GA4 property ID is set on the Settings tab.', $message );
	}

	public function test_a_healthy_digest_has_no_sync_warning(): void {
		update_option( 'dragoncontentdecay_email_frequency', 'weekly' );

		$this->notifications()->send_weekly_digest();

		$message = $GLOBALS['dragoncontentdecay_test_mail'][0]['message'];
		$this->assertStringNotContainsString( 'syncing has stopped', $message );
		$this->assertStringNotContainsString( 'last sync failed', $message );
	}

	public function test_test_email_uses_the_plain_site_name(): void {
		$this->assertTrue( $this->notifications()->send_test_email() );

		$mail = $GLOBALS['dragoncontentdecay_test_mail'][0];
		$this->assertSame( "[Rich's Shop & Co] Content Decay - Test Email", $mail['subject'] );
		$this->assertStringContainsString( "on Rich's Shop & Co.", $mail['message'] );
	}
}
