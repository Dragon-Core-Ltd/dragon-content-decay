<?php
/**
 * Dashboard View
 *
 * @package DragonContentDecay
 */

defined( 'ABSPATH' ) || exit;

// Template variables are provided by Admin::render_dashboard_page().
?>
<div class="wrap dragon-ui dcd-dashboard">
	<h1 class="dragon-title wp-heading-inline"><span class="dragon-mark" aria-hidden="true"></span>
		<?php esc_html_e( 'Dragon Content Decay', 'dragon-content-decay' ); ?>
	</h1>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-content-decay' ) ); ?>" class="nav-tab <?php echo 'dashboard' === $current_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Dashboard', 'dragon-content-decay' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-content-decay&tab=settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $current_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Settings', 'dragon-content-decay' ); ?>
		</a>
	</nav>

	<?php if ( ! $is_connected ) : ?>
		<div class="dragon-card dragon-firstrun" style="max-width:640px;margin:12px 0;">
			<h2 style="margin-top:0;"><?php esc_html_e( 'Get set up in two minutes', 'dragon-content-decay' ); ?></h2>
			<ol style="margin:0 0 12px 18px;">
				<li><?php esc_html_e( 'Connect your Google account - the plugin only requests read access to Analytics.', 'dragon-content-decay' ); ?></li>
				<li><?php esc_html_e( 'Enter the GA4 Property ID for this site on the Settings tab.', 'dragon-content-decay' ); ?></li>
				<li><?php esc_html_e( 'The first scan compares recent traffic to your baseline and flags the posts losing ground.', 'dragon-content-decay' ); ?></li>
			</ol>
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-content-decay&tab=settings' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Connect Google Analytics', 'dragon-content-decay' ); ?>
			</a>
		</div>
	<?php else : ?>
		<?php if ( $access_revoked ) : ?>
			<div class="notice notice-error inline">
				<p>
					<?php esc_html_e( 'Google no longer accepts this site\'s saved sign-in (access was revoked or has expired), so syncing has stopped. The scores below are from the last successful sync.', 'dragon-content-decay' ); ?>
					<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-content-decay&tab=settings' ) ); ?>"><?php esc_html_e( 'Connect to Google again', 'dragon-content-decay' ); ?></a>
				</p>
			</div>
		<?php endif; ?>
		<!-- Summary Cards -->
		<div class="dcd-summary-cards">
			<div class="dcd-card dcd-card-decaying">
				<div class="dcd-card-icon">
					<span class="dashicons dashicons-arrow-down-alt"></span>
				</div>
				<div class="dcd-card-content">
					<div class="dcd-card-value"><?php echo esc_html( number_format_i18n( (int) $summary['decaying'] ) ); ?></div>
					<div class="dcd-card-label"><?php esc_html_e( 'Decaying Posts', 'dragon-content-decay' ); ?></div>
				</div>
			</div>

			<div class="dcd-card dcd-card-stable">
				<div class="dcd-card-icon">
					<span class="dashicons dashicons-minus"></span>
				</div>
				<div class="dcd-card-content">
					<div class="dcd-card-value"><?php echo esc_html( number_format_i18n( (int) $summary['stable'] ) ); ?></div>
					<div class="dcd-card-label"><?php esc_html_e( 'Stable Posts', 'dragon-content-decay' ); ?></div>
				</div>
			</div>

			<div class="dcd-card dcd-card-growing">
				<div class="dcd-card-icon">
					<span class="dashicons dashicons-arrow-up-alt"></span>
				</div>
				<div class="dcd-card-content">
					<div class="dcd-card-value"><?php echo esc_html( number_format_i18n( (int) $summary['growing'] ) ); ?></div>
					<div class="dcd-card-label"><?php esc_html_e( 'Growing Posts', 'dragon-content-decay' ); ?></div>
				</div>
			</div>

			<div class="dcd-card dcd-card-total">
				<div class="dcd-card-icon">
					<span class="dashicons dashicons-analytics"></span>
				</div>
				<div class="dcd-card-content">
					<div class="dcd-card-value"><?php echo esc_html( number_format_i18n( (int) $summary['total'] ) ); ?></div>
					<div class="dcd-card-label"><?php esc_html_e( 'Total Tracked', 'dragon-content-decay' ); ?></div>
				</div>
			</div>
		</div>

		<!-- Sync Status -->
		<div class="dcd-sync-status">
			<span class="dcd-sync-info">
				<?php
				printf(
					/* translators: %s: Last sync time */
					esc_html__( 'Last synced: %s', 'dragon-content-decay' ),
					esc_html( $last_sync['formatted'] )
				);
				?>
			</span>
			<?php if ( ! empty( $last_sync['error'] ) ) : ?>
				<span class="dcd-sync-warning">
					<?php
					printf(
						/* translators: %s: why the analytics data could not be fetched */
						esc_html__( 'Last sync failed, so the scores below are from the previous successful sync. %s', 'dragon-content-decay' ),
						esc_html( $last_sync['error'] )
					);
					?>
				</span>
			<?php elseif ( ! empty( $last_sync['status'] ) && \DragonContentDecay\Scheduler::STATUS_COMPLETE !== $last_sync['status'] ) : ?>
				<span class="dcd-sync-warning">
					<?php
					printf(
						/* translators: 1: number of posts analyzed, 2: number of posts whose score could not be saved */
						esc_html__( 'Last sync finished with errors. Posts analyzed: %1$s. Scores not saved: %2$s.', 'dragon-content-decay' ),
						esc_html( number_format_i18n( (int) ( $last_sync['count'] ?? 0 ) ) ),
						esc_html( number_format_i18n( (int) ( $last_sync['failed'] ?? 0 ) ) )
					);
					?>
				</span>
			<?php endif; ?>
			<?php if ( empty( $last_sync['error'] ) && ! empty( $last_sync['search_error'] ) && get_option( 'dragoncontentdecay_gsc_enabled', 0 ) ) : ?>
				<span class="dcd-sync-warning">
					<?php
					printf(
						/* translators: %s: why the Search Console data could not be fetched */
						esc_html__( 'Search Console data could not be fetched in the last sync, so the search columns below still show the previous values. %s', 'dragon-content-decay' ),
						esc_html( $last_sync['search_error'] )
					);
					?>
				</span>
			<?php endif; ?>
			<button type="button" class="button dcd-sync-button" id="dcd-manual-sync">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Sync Now', 'dragon-content-decay' ); ?>
			</button>
		</div>

		<?php if ( $focus_post_id ) : ?>
			<div class="dragon-card dcd-focus-post" style="margin:12px 0;">
				<?php if ( $focus_post ) : ?>
					<h2 style="margin-top:0;">
						<?php
						printf(
							/* translators: %s: post title */
							esc_html__( 'Analytics for "%s"', 'dragon-content-decay' ),
							esc_html( get_the_title( $focus_post_id ) )
						);
						?>
					</h2>
					<p>
						<span class="dcd-score dcd-<?php echo esc_attr( $focus_post['trend'] ); ?>">
							<?php
							/* translators: %s: Decay score percentage */
							echo esc_html( sprintf( __( '%s%%', 'dragon-content-decay' ), number_format_i18n( (float) $focus_post['decay_score'], 1 ) ) );
							?>
						</span>
						<?php if ( ! empty( $focus_post['uncertain'] ) ) : ?>
							<span class="dragon-pill dragon-pill--warning" title="<?php esc_attr_e( 'Google Analytics returned only the busiest pages for this period, and some of this post\'s addresses were not among them, so its views may be higher than shown.', 'dragon-content-decay' ); ?>">
								<?php esc_html_e( 'Partial data', 'dragon-content-decay' ); ?>
							</span>
						<?php endif; ?>
						&middot;
						<?php echo esc_html( $trend_labels[ $focus_post['trend'] ] ?? '' ); ?>
						&middot;
						<?php
						printf(
							/* translators: 1: Current views, 2: Previous views */
							esc_html( _n( '%1$s view this period (previous period: %2$s)', '%1$s views this period (previous period: %2$s)', absint( $focus_post['pageviews_current'] ), 'dragon-content-decay' ) ),
							esc_html( number_format_i18n( absint( $focus_post['pageviews_current'] ) ) ),
							esc_html( number_format_i18n( absint( $focus_post['pageviews_previous'] ) ) )
						);
						?>
					</p>
				<?php else : ?>
					<p style="margin:0;">
						<?php
						printf(
							/* translators: %s: post title */
							esc_html__( 'No analytics data yet for "%s". It appears here after a sync once Google Analytics has recorded views for it.', 'dragon-content-decay' ),
							esc_html( get_the_title( $focus_post_id ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<!-- Posts Table -->
		<div class="dcd-table-container">
			<div class="dcd-table-header">
				<h2><?php esc_html_e( 'Content Performance', 'dragon-content-decay' ); ?></h2>
				<div class="dcd-filters">
					<label class="screen-reader-text" for="dcd-filter-trend"><?php esc_html_e( 'Filter by trend', 'dragon-content-decay' ); ?></label>
					<select id="dcd-filter-trend" class="dcd-filter">
						<option value=""><?php esc_html_e( 'All Trends', 'dragon-content-decay' ); ?></option>
						<option value="decaying"><?php esc_html_e( 'Decaying', 'dragon-content-decay' ); ?></option>
						<option value="stable"><?php esc_html_e( 'Stable', 'dragon-content-decay' ); ?></option>
						<option value="growing"><?php esc_html_e( 'Growing', 'dragon-content-decay' ); ?></option>
					</select>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped dcd-posts-table">
				<?php $dragoncontentdecay_gsc_on = (bool) get_option( 'dragoncontentdecay_gsc_enabled', 0 ); ?>
				<thead>
					<tr>
						<th class="column-title" scope="col"><?php esc_html_e( 'Post Title', 'dragon-content-decay' ); ?></th>
						<th class="column-decay" scope="col"><?php esc_html_e( 'Decay Score', 'dragon-content-decay' ); ?></th>
						<th class="column-views" scope="col"><?php esc_html_e( 'Current Views', 'dragon-content-decay' ); ?></th>
						<th class="column-previous" scope="col"><?php esc_html_e( 'Previous Views', 'dragon-content-decay' ); ?></th>
						<?php if ( $dragoncontentdecay_gsc_on ) : ?>
						<th class="column-search" scope="col"><?php esc_html_e( 'Search Clicks', 'dragon-content-decay' ); ?></th>
						<?php endif; ?>
						<th class="column-trend" scope="col"><?php esc_html_e( 'Trend', 'dragon-content-decay' ); ?></th>
						<th class="column-updated" scope="col"><?php esc_html_e( 'Last Updated', 'dragon-content-decay' ); ?></th>
						<th class="column-actions" scope="col"><?php esc_html_e( 'Actions', 'dragon-content-decay' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $posts_data ) ) : ?>
						<tr>
							<td colspan="<?php echo $dragoncontentdecay_gsc_on ? 8 : 7; ?>" class="dcd-no-data">
								<?php esc_html_e( 'No data available. Click "Sync Now" to fetch analytics data.', 'dragon-content-decay' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $posts_data as $dragoncontentdecay_post ) : ?>
							<tr data-trend="<?php echo esc_attr( $dragoncontentdecay_post['trend'] ); ?>">
								<td class="column-title">
									<strong>
										<a href="<?php echo esc_url( get_permalink( $dragoncontentdecay_post['post_id'] ) ); ?>" target="_blank">
											<?php echo esc_html( $dragoncontentdecay_post['post_title'] ); ?>
										</a>
									</strong>
								</td>
								<td class="column-decay">
									<span class="dcd-score dcd-<?php echo esc_attr( $dragoncontentdecay_post['trend'] ); ?>">
										<?php
										/* translators: %s: Decay score percentage */
										echo esc_html( sprintf( __( '%s%%', 'dragon-content-decay' ), number_format_i18n( (float) $dragoncontentdecay_post['decay_score'], 1 ) ) );
										?>
									</span>
									<?php if ( ! empty( $dragoncontentdecay_post['uncertain'] ) ) : ?>
										<span class="dragon-pill dragon-pill--warning" title="<?php esc_attr_e( 'Google Analytics returned only the busiest pages for this period, and some of this post\'s addresses were not among them, so its views may be higher than shown.', 'dragon-content-decay' ); ?>">
											<?php esc_html_e( 'Partial data', 'dragon-content-decay' ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td class="column-views">
									<?php echo esc_html( number_format_i18n( (int) $dragoncontentdecay_post['pageviews_current'] ) ); ?>
								</td>
								<td class="column-previous">
									<?php echo esc_html( number_format_i18n( (int) $dragoncontentdecay_post['pageviews_previous'] ) ); ?>
								</td>
								<?php if ( $dragoncontentdecay_gsc_on ) : ?>
								<td class="column-search">
									<?php
									$dragoncontentdecay_sc = (int) ( $dragoncontentdecay_post['search_clicks_current'] ?? 0 );
									$dragoncontentdecay_sp = (int) ( $dragoncontentdecay_post['search_clicks_previous'] ?? 0 );
									$dragoncontentdecay_sd = $dragoncontentdecay_sc - $dragoncontentdecay_sp;
									echo esc_html( number_format_i18n( $dragoncontentdecay_sc ) );
									if ( 0 !== $dragoncontentdecay_sd ) {
										$dragoncontentdecay_sd_label = $dragoncontentdecay_sd > 0
											/* translators: %s: Increase in search clicks versus the previous period */
											? sprintf( __( '(+%s)', 'dragon-content-decay' ), number_format_i18n( $dragoncontentdecay_sd ) )
											/* translators: %s: Decrease in search clicks versus the previous period, without the minus sign */
											: sprintf( __( '(-%s)', 'dragon-content-decay' ), number_format_i18n( abs( $dragoncontentdecay_sd ) ) );
										echo ' <span class="description">' . esc_html( $dragoncontentdecay_sd_label ) . '</span>';
									}
									?>
								</td>
								<?php endif; ?>
								<td class="column-trend">
									<span class="dcd-trend dcd-trend-<?php echo esc_attr( $dragoncontentdecay_post['trend'] ); ?>">
										<span class="dashicons dashicons-<?php echo esc_attr( $trend_icons[ $dragoncontentdecay_post['trend'] ] ); ?>"></span>
										<?php echo esc_html( $trend_labels[ $dragoncontentdecay_post['trend'] ] ); ?>
									</span>
								</td>
								<td class="column-updated">
									<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $dragoncontentdecay_post['post_modified'] ) ) ); ?>
								</td>
								<td class="column-actions">
									<a href="<?php echo esc_url( get_edit_post_link( $dragoncontentdecay_post['post_id'] ) ); ?>" class="button button-small">
										<?php esc_html_e( 'Edit', 'dragon-content-decay' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<?php if ( count( $posts_data ) < (int) ( $summary['total'] ?? 0 ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: number of posts listed, 2: number of posts tracked */
						esc_html( _n( 'Showing the %1$s lowest-scoring post of %2$s tracked.', 'Showing the %1$s lowest-scoring posts of %2$s tracked.', count( $posts_data ), 'dragon-content-decay' ) ),
						esc_html( number_format_i18n( count( $posts_data ) ) ),
						esc_html( number_format_i18n( (int) $summary['total'] ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
