<?php
/**
 * Dashboard View
 *
 * @package DragonContentDecay
 */

defined( 'ABSPATH' ) || exit;

// Template variables are provided by Admin::render_dashboard_page().
?>
<div class="wrap dcd-dashboard">
	<h1 class="wp-heading-inline">
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
		<div class="dcd-notice dcd-notice-warning">
			<p>
				<strong><?php esc_html_e( 'Not Connected', 'dragon-content-decay' ); ?></strong>
				<?php esc_html_e( 'Connect to Google Analytics to start tracking content decay.', 'dragon-content-decay' ); ?>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=dragon-content-decay&tab=settings' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Connect Now', 'dragon-content-decay' ); ?>
				</a>
			</p>
		</div>
	<?php else : ?>
		<!-- Summary Cards -->
		<div class="dcd-summary-cards">
			<div class="dcd-card dcd-card-decaying">
				<div class="dcd-card-icon">
					<span class="dashicons dashicons-arrow-down-alt"></span>
				</div>
				<div class="dcd-card-content">
					<div class="dcd-card-value"><?php echo esc_html( $summary['decaying'] ); ?></div>
					<div class="dcd-card-label"><?php esc_html_e( 'Decaying Posts', 'dragon-content-decay' ); ?></div>
				</div>
			</div>

			<div class="dcd-card dcd-card-stable">
				<div class="dcd-card-icon">
					<span class="dashicons dashicons-minus"></span>
				</div>
				<div class="dcd-card-content">
					<div class="dcd-card-value"><?php echo esc_html( $summary['stable'] ); ?></div>
					<div class="dcd-card-label"><?php esc_html_e( 'Stable Posts', 'dragon-content-decay' ); ?></div>
				</div>
			</div>

			<div class="dcd-card dcd-card-growing">
				<div class="dcd-card-icon">
					<span class="dashicons dashicons-arrow-up-alt"></span>
				</div>
				<div class="dcd-card-content">
					<div class="dcd-card-value"><?php echo esc_html( $summary['growing'] ); ?></div>
					<div class="dcd-card-label"><?php esc_html_e( 'Growing Posts', 'dragon-content-decay' ); ?></div>
				</div>
			</div>

			<div class="dcd-card dcd-card-total">
				<div class="dcd-card-icon">
					<span class="dashicons dashicons-analytics"></span>
				</div>
				<div class="dcd-card-content">
					<div class="dcd-card-value"><?php echo esc_html( $summary['total'] ); ?></div>
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
			<button type="button" class="button dcd-sync-button" id="dcd-manual-sync">
				<span class="dashicons dashicons-update"></span>
				<?php esc_html_e( 'Sync Now', 'dragon-content-decay' ); ?>
			</button>
		</div>

		<!-- Posts Table -->
		<div class="dcd-table-container">
			<div class="dcd-table-header">
				<h2><?php esc_html_e( 'Content Performance', 'dragon-content-decay' ); ?></h2>
				<div class="dcd-filters">
					<select id="dcd-filter-trend" class="dcd-filter">
						<option value=""><?php esc_html_e( 'All Trends', 'dragon-content-decay' ); ?></option>
						<option value="decaying"><?php esc_html_e( 'Decaying', 'dragon-content-decay' ); ?></option>
						<option value="stable"><?php esc_html_e( 'Stable', 'dragon-content-decay' ); ?></option>
						<option value="growing"><?php esc_html_e( 'Growing', 'dragon-content-decay' ); ?></option>
					</select>
				</div>
			</div>

			<table class="wp-list-table widefat fixed striped dcd-posts-table">
				<thead>
					<tr>
						<th class="column-title" scope="col"><?php esc_html_e( 'Post Title', 'dragon-content-decay' ); ?></th>
						<th class="column-decay" scope="col"><?php esc_html_e( 'Decay Score', 'dragon-content-decay' ); ?></th>
						<th class="column-views" scope="col"><?php esc_html_e( 'Current Views', 'dragon-content-decay' ); ?></th>
						<th class="column-previous" scope="col"><?php esc_html_e( 'Previous Views', 'dragon-content-decay' ); ?></th>
						<th class="column-trend" scope="col"><?php esc_html_e( 'Trend', 'dragon-content-decay' ); ?></th>
						<th class="column-updated" scope="col"><?php esc_html_e( 'Last Updated', 'dragon-content-decay' ); ?></th>
						<th class="column-actions" scope="col"><?php esc_html_e( 'Actions', 'dragon-content-decay' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $posts_data ) ) : ?>
						<tr>
							<td colspan="7" class="dcd-no-data">
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
										<?php echo esc_html( number_format( $dragoncontentdecay_post['decay_score'], 1 ) ); ?>%
									</span>
								</td>
								<td class="column-views">
									<?php echo esc_html( number_format( $dragoncontentdecay_post['pageviews_current'] ) ); ?>
								</td>
								<td class="column-previous">
									<?php echo esc_html( number_format( $dragoncontentdecay_post['pageviews_previous'] ) ); ?>
								</td>
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
		</div>
	<?php endif; ?>
</div>
