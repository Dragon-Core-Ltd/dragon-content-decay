<?php
/**
 * Notifications Class
 *
 * Handles email digest notifications
 *
 * @package DragonContentDecay
 */

namespace DragonContentDecay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Notifications {

	/**
	 * Weekly digest cron hook
	 */
	public const WEEKLY_HOOK = 'dragoncontentdecay_weekly_digest';

	/**
	 * Monthly digest cron hook
	 */
	public const MONTHLY_HOOK = 'dragoncontentdecay_monthly_digest';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks(): void {
		add_action( self::WEEKLY_HOOK, array( $this, 'send_weekly_digest' ) );
		add_action( self::MONTHLY_HOOK, array( $this, 'send_monthly_digest' ) );

		// Core has no monthly recurrence; without this the monthly digest can
		// never be booked. Registered on every request because the digest
		// itself fires from cron.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );

		// Reschedule digests when frequency changes
		add_action( 'update_option_dragoncontentdecay_email_frequency', array( $this, 'reschedule_digests' ), 10, 2 );

		// Book a missing digest event for the chosen frequency (e.g. a monthly
		// digest chosen before the recurrence was registered).
		add_action( 'init', array( $this, 'ensure_digest_scheduled' ) );
	}

	/**
	 * Add the 30-day 'monthly' recurrence unless something already provides one.
	 *
	 * @param array $schedules Registered cron schedules.
	 * @return array
	 */
	public static function add_schedules( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();

		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly', 'dragon-content-decay' ),
			);
		}

		return $schedules;
	}

	/**
	 * Make the booked digest events match the chosen frequency: the chosen
	 * digest is booked if missing, and the other one is cleared.
	 */
	public function ensure_digest_scheduled(): void {
		self::sync_digest_schedule();
	}

	/**
	 * Make the booked digest events match the chosen frequency.
	 *
	 * @return bool False when a digest is chosen but is not in the schedule.
	 */
	public static function sync_digest_schedule(): bool {
		$frequency = (string) get_option( 'dragoncontentdecay_email_frequency', 'off' );
		$wanted    = self::hook_for( $frequency );

		foreach ( array( self::WEEKLY_HOOK, self::MONTHLY_HOOK ) as $hook ) {
			if ( $hook !== $wanted && wp_next_scheduled( $hook ) ) {
				wp_clear_scheduled_hook( $hook );
			}
		}

		if ( '' === $wanted || wp_next_scheduled( $wanted ) ) {
			return true;
		}

		return self::book_digest( $frequency );
	}

	/**
	 * Cron hook for a digest frequency, or '' when digests are off.
	 *
	 * @param string $frequency 'weekly', 'monthly' or anything else for off.
	 * @return string
	 */
	private static function hook_for( string $frequency ): string {
		if ( 'weekly' === $frequency ) {
			return self::WEEKLY_HOOK;
		}
		if ( 'monthly' === $frequency ) {
			return self::MONTHLY_HOOK;
		}
		return '';
	}

	/**
	 * Book the digest event for a frequency and confirm it is in the cron array.
	 *
	 * @param string $frequency 'weekly' or 'monthly'.
	 * @return bool Whether the event is booked.
	 */
	private static function book_digest( string $frequency ): bool {
		$hook = self::hook_for( $frequency );
		if ( '' === $hook ) {
			return false;
		}

		$booked = wp_schedule_event( self::first_send_time( $frequency ), $frequency, $hook, array(), true );
		if ( true !== $booked || ! wp_next_scheduled( $hook ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'DCD: could not schedule the ' . $frequency . ' digest: ' . ( is_wp_error( $booked ) ? $booked->get_error_message() : 'unknown reason' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging, only when WP_DEBUG is enabled.
			}
			return false;
		}

		return true;
	}

	/**
	 * First send time for a digest: next Monday or the 1st of next month, at
	 * 9am in the site's timezone (PHP itself runs in UTC under WordPress).
	 *
	 * @param string $frequency 'weekly' or 'monthly'.
	 * @return int Unix timestamp.
	 */
	private static function first_send_time( string $frequency ): int {
		$now  = new \DateTimeImmutable( 'now', wp_timezone() );
		$when = 'weekly' === $frequency
			? $now->modify( 'next monday' )
			: $now->modify( 'first day of next month' );

		return $when->setTime( 9, 0 )->getTimestamp();
	}

	/**
	 * Why the scores in a digest may be out of date, or '' when the last sync
	 * worked: Google revoked access, or the last sync could not fetch data.
	 *
	 * @return string
	 */
	private static function sync_warning(): string {
		$had_success = Scheduler::has_successful_sync();

		if ( OAuth::is_access_revoked() ) {
			return $had_success
				? __( 'Google no longer accepts this site\'s saved sign-in, so syncing has stopped and the scores below are from the last successful sync. Connect to Google again on the Settings tab to resume.', 'dragon-content-decay' )
				: __( 'Google no longer accepts this site\'s saved sign-in, so syncing has stopped. Connect to Google again on the Settings tab to resume.', 'dragon-content-decay' );
		}

		$error = (string) get_option( 'dragoncontentdecay_last_sync_error', '' );
		if ( '' !== $error ) {
			return $had_success
				? sprintf(
					/* translators: %s: why the analytics data could not be fetched */
					__( 'The last sync failed, so the scores below are from the previous successful sync. %s', 'dragon-content-decay' ),
					$error
				)
				: sprintf(
					/* translators: %s: why the analytics data could not be fetched */
					__( 'The last sync failed and no sync has succeeded yet, so there are no scores. %s', 'dragon-content-decay' ),
					$error
				);
		}

		return '';
	}

	/**
	 * The site name as plain text. get_bloginfo( 'name' ) is stored HTML-escaped,
	 * which would put "&amp;" and "&#039;" into a subject line or plain-text body.
	 *
	 * @return string
	 */
	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Analyzer used to build a digest. Overridable in tests.
	 *
	 * @return Analyzer
	 */
	protected function make_analyzer(): Analyzer {
		$dragoncontentdecay_oauth = new OAuth();
		return new Analyzer( new API_GA4( $dragoncontentdecay_oauth ), new API_GSC( $dragoncontentdecay_oauth ) );
	}

	/**
	 * Send weekly digest email
	 */
	public function send_weekly_digest(): void {
		if ( 'weekly' !== get_option( 'dragoncontentdecay_email_frequency', 'off' ) ) {
			return;
		}

		$this->send_digest( 'weekly' );
	}

	/**
	 * Send monthly digest email
	 */
	public function send_monthly_digest(): void {
		if ( 'monthly' !== get_option( 'dragoncontentdecay_email_frequency', 'off' ) ) {
			return;
		}

		$this->send_digest( 'monthly' );
	}

	/**
	 * Send digest email
	 *
	 * @param string $type 'weekly' or 'monthly'
	 */
	private function send_digest( string $type ): void {
		$analyzer       = $this->make_analyzer();
		$decaying_posts = $analyzer->get_decaying_posts( 10 );

		if ( empty( $decaying_posts ) ) {
			return;
		}

		$summary     = $analyzer->get_summary();
		$admin_email = get_option( 'admin_email' );
		$site_name   = self::site_name();

		$decaying = (int) $summary['decaying'];
		$subject  = sprintf(
			/* translators: 1: Site name, 2: Number of decaying posts */
			_n( '[%1$s] Content Decay Alert: %2$s post needs attention', '[%1$s] Content Decay Alert: %2$s posts need attention', $decaying, 'dragon-content-decay' ),
			$site_name,
			number_format_i18n( $decaying )
		);

		$message = $this->build_email_message( $decaying_posts, $summary, $type );

		// No From header: the site's mailer (core default or an SMTP plugin)
		// chooses the sender, so the digest passes the same SPF/DMARC checks as
		// the site's other mail.
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		wp_mail( $admin_email, $subject, $message, $headers );
	}

	/**
	 * Build email message HTML
	 *
	 * @param array  $posts   Decaying posts
	 * @param array  $summary Summary stats
	 * @param string $type    Digest type
	 * @return string HTML message
	 */
	private function build_email_message( array $posts, array $summary, string $type ): string {
		$site_name = self::site_name();
		$period    = 'weekly' === $type ? __( 'Weekly Summary', 'dragon-content-decay' ) : __( 'Monthly Summary', 'dragon-content-decay' );

		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<style>
				body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; }
				.container { max-width: 600px; margin: 0 auto; padding: 20px; }
				.header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 8px 8px 0 0; }
				.content { background: #f9fafb; padding: 30px; border-radius: 0 0 8px 8px; }
				.stats { display: flex; gap: 20px; margin-bottom: 30px; }
				.stat { background: white; padding: 15px; border-radius: 8px; text-align: center; flex: 1; }
				.stat-value { font-size: 24px; font-weight: bold; color: #667eea; }
				.stat-label { font-size: 12px; color: #6b7280; text-transform: uppercase; }
				.post-list { background: white; border-radius: 8px; overflow: hidden; }
				.post-item { padding: 15px; border-bottom: 1px solid #e5e7eb; }
				.post-item:last-child { border-bottom: none; }
				.post-title { font-weight: 600; color: #1f2937; }
				.post-meta { font-size: 13px; color: #6b7280; margin-top: 5px; }
				.decay-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
				.decay-red { background: #fee2e2; color: #dc2626; }
				.decay-yellow { background: #fef3c7; color: #d97706; }
				.btn { display: inline-block; background: #667eea; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin-top: 20px; }
				.footer { text-align: center; font-size: 12px; color: #9ca3af; margin-top: 30px; }
			</style>
		</head>
		<body>
			<div class="container">
				<div class="header">
					<h1 style="margin: 0;"><?php esc_html_e( 'Content Decay Report', 'dragon-content-decay' ); ?></h1>
					<p style="margin: 10px 0 0; opacity: 0.9;">
						<?php
						printf(
							/* translators: 1: Site name, 2: Digest period, e.g. "Weekly Summary" */
							esc_html__( '%1$s - %2$s', 'dragon-content-decay' ),
							esc_html( $site_name ),
							esc_html( $period )
						);
						?>
					</p>
				</div>
				<div class="content">
					<?php $dragoncontentdecay_sync_warning = self::sync_warning(); ?>
					<?php if ( '' !== $dragoncontentdecay_sync_warning ) : ?>
						<p style="background: #fee2e2; color: #991b1b; padding: 12px 15px; border-radius: 6px; margin-top: 0;">
							<?php echo esc_html( $dragoncontentdecay_sync_warning ); ?>
							<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-content-decay&tab=settings' ) ); ?>" style="color: #991b1b;"><?php esc_html_e( 'Open settings', 'dragon-content-decay' ); ?></a>
						</p>
					<?php endif; ?>
					<div class="stats">
						<div class="stat">
							<div class="stat-value"><?php echo esc_html( number_format_i18n( (int) $summary['decaying'] ) ); ?></div>
							<div class="stat-label"><?php esc_html_e( 'Decaying', 'dragon-content-decay' ); ?></div>
						</div>
						<div class="stat">
							<div class="stat-value"><?php echo esc_html( number_format_i18n( (int) $summary['stable'] ) ); ?></div>
							<div class="stat-label"><?php esc_html_e( 'Stable', 'dragon-content-decay' ); ?></div>
						</div>
						<div class="stat">
							<div class="stat-value"><?php echo esc_html( number_format_i18n( (int) $summary['growing'] ) ); ?></div>
							<div class="stat-label"><?php esc_html_e( 'Growing', 'dragon-content-decay' ); ?></div>
						</div>
					</div>

					<h2 style="margin-top: 0;"><?php esc_html_e( 'Posts Needing Attention', 'dragon-content-decay' ); ?></h2>

					<div class="post-list">
						<?php foreach ( $posts as $post ) : ?>
							<div class="post-item">
								<div class="post-title"><?php echo esc_html( $post['post_title'] ); ?></div>
								<div class="post-meta">
									<span class="decay-badge <?php echo $post['decay_score'] <= -50 ? 'decay-red' : 'decay-yellow'; ?>">
										<?php
										/* translators: %s: Decay score percentage */
										echo esc_html( sprintf( __( '%s%%', 'dragon-content-decay' ), number_format_i18n( (float) $post['decay_score'], 1 ) ) );
										?>
									</span>
									&middot;
									<?php
									printf(
										/* translators: 1: Current views, 2: Previous views */
										esc_html( _n( '%1$s view (was %2$s)', '%1$s views (was %2$s)', absint( $post['pageviews_current'] ), 'dragon-content-decay' ) ),
										esc_html( number_format_i18n( absint( $post['pageviews_current'] ) ) ),
										esc_html( number_format_i18n( absint( $post['pageviews_previous'] ) ) )
									);
									?>
									&middot;
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $post['post_id'] ) . '&action=edit' ) ); ?>"><?php esc_html_e( 'Edit', 'dragon-content-decay' ); ?></a>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<center>
						<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-content-decay' ) ); ?>" class="btn">
							<?php esc_html_e( 'View Full Dashboard', 'dragon-content-decay' ); ?>
						</a>
					</center>
				</div>
				<div class="footer">
					<p><?php esc_html_e( 'This email was sent by Dragon Content Decay plugin.', 'dragon-content-decay' ); ?></p>
					<p><a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-content-decay&tab=settings' ) ); ?>"><?php esc_html_e( 'Manage notification settings', 'dragon-content-decay' ); ?></a></p>
				</div>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Reschedule digests when frequency changes
	 *
	 * @param mixed $old_value
	 * @param mixed $new_value
	 * @return bool False when a digest was chosen but could not be booked.
	 */
	public function reschedule_digests( $old_value, $new_value ): bool {
		unset( $old_value );

		// Clear existing schedules
		wp_clear_scheduled_hook( self::WEEKLY_HOOK );
		wp_clear_scheduled_hook( self::MONTHLY_HOOK );

		$frequency = is_string( $new_value ) ? $new_value : '';
		if ( '' === self::hook_for( $frequency ) ) {
			return true;
		}

		return self::book_digest( $frequency );
	}

	/**
	 * Send test email
	 *
	 * @return bool
	 */
	public function send_test_email(): bool {
		$admin_email = get_option( 'admin_email' );
		$site_name   = self::site_name();

		$subject = sprintf(
			/* translators: %s: Site name */
			__( '[%s] Content Decay - Test Email', 'dragon-content-decay' ),
			$site_name
		);

		$message = sprintf(
			/* translators: %s: Site name */
			__( 'This is a test email from Dragon Content Decay on %s. If you received this, email notifications are working correctly.', 'dragon-content-decay' ),
			$site_name
		);

		return wp_mail( $admin_email, $subject, $message );
	}
}
