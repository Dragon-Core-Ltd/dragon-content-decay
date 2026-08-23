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

		// Reschedule digests when frequency changes
		add_action( 'update_option_dragoncontentdecay_email_frequency', array( $this, 'reschedule_digests' ), 10, 2 );
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
		$analyzer       = new Analyzer( new API_GA4( new OAuth() ) );
		$decaying_posts = $analyzer->get_decaying_posts( 10 );

		if ( empty( $decaying_posts ) ) {
			return;
		}

		$summary     = $analyzer->get_summary();
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );

		$subject = sprintf(
			/* translators: 1: Site name, 2: Number of decaying posts */
			__( '[%1$s] Content Decay Alert: %2$d posts need attention', 'dragon-content-decay' ),
			$site_name,
			$summary['decaying']
		);

		$message = $this->build_email_message( $decaying_posts, $summary, $type );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $site_name . ' <' . $admin_email . '>',
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
		$site_name = get_bloginfo( 'name' );
		$period    = 'weekly' === $type ? __( 'week', 'dragon-content-decay' ) : __( 'month', 'dragon-content-decay' );

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
					<h1 style="margin: 0;">Content Decay Report</h1>
					<p style="margin: 10px 0 0; opacity: 0.9;"><?php echo esc_html( $site_name ); ?> - <?php echo esc_html( ucfirst( $period ) ); ?>ly Summary</p>
				</div>
				<div class="content">
					<div class="stats">
						<div class="stat">
							<div class="stat-value"><?php echo esc_html( $summary['decaying'] ); ?></div>
							<div class="stat-label"><?php esc_html_e( 'Decaying', 'dragon-content-decay' ); ?></div>
						</div>
						<div class="stat">
							<div class="stat-value"><?php echo esc_html( $summary['stable'] ); ?></div>
							<div class="stat-label"><?php esc_html_e( 'Stable', 'dragon-content-decay' ); ?></div>
						</div>
						<div class="stat">
							<div class="stat-value"><?php echo esc_html( $summary['growing'] ); ?></div>
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
										<?php echo esc_html( $post['decay_score'] ); ?>%
									</span>
									&middot;
									<?php
									printf(
										/* translators: 1: Current views, 2: Previous views */
										esc_html__( '%1$d views (was %2$d)', 'dragon-content-decay' ),
										absint( $post['pageviews_current'] ),
										absint( $post['pageviews_previous'] )
									);
									?>
									&middot;
									<a href="<?php echo esc_url( get_edit_post_link( $post['post_id'] ) ); ?>"><?php esc_html_e( 'Edit', 'dragon-content-decay' ); ?></a>
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
	 */
	public function reschedule_digests( $old_value, $new_value ): void {
		// Clear existing schedules
		wp_clear_scheduled_hook( self::WEEKLY_HOOK );
		wp_clear_scheduled_hook( self::MONTHLY_HOOK );

		// Schedule new events based on frequency
		switch ( $new_value ) {
			case 'weekly':
				wp_schedule_event( strtotime( 'next monday 9am' ), 'weekly', self::WEEKLY_HOOK );
				break;

			case 'monthly':
				wp_schedule_event( strtotime( 'first day of next month 9am' ), 'monthly', self::MONTHLY_HOOK );
				break;
		}
	}

	/**
	 * Send test email
	 *
	 * @return bool
	 */
	public function send_test_email(): bool {
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );

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
