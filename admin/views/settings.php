<?php
/**
 * Settings View
 *
 * @package DragonContentDecay
 */

defined( 'ABSPATH' ) || exit;

// Template variables are provided by Admin::render_settings_page().
?>
<div class="wrap dragon-ui dcd-settings">
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

	<?php settings_errors( 'dragoncontentdecay_settings' ); ?>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only success notice flag; no state change. ?>
	<?php if ( isset( $_GET['connected'] ) ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Successfully connected to Google Analytics!', 'dragon-content-decay' ); ?></p>
		</div>
	<?php endif; ?>

	<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only info notice flag; no state change. ?>
	<?php if ( isset( $_GET['disconnected'] ) ) : ?>
		<div class="notice notice-info is-dismissible">
			<p><?php esc_html_e( 'Disconnected from Google Analytics.', 'dragon-content-decay' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'dragoncontentdecay_save_settings', 'dragoncontentdecay_settings_nonce' ); ?>

		<!-- Google API Section -->
		<div class="dcd-settings-section">
			<h2>
				<span class="dashicons dashicons-google"></span>
				<?php esc_html_e( 'Google API Configuration', 'dragon-content-decay' ); ?>
			</h2>

			<p class="description">
				<?php
				printf(
					/* translators: %s: Google Cloud Console URL */
					esc_html__( 'Create OAuth credentials in the %s to connect with Google Analytics.', 'dragon-content-decay' ),
					'<a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a>'
				);
				?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="dragoncontentdecay_google_client_id"><?php esc_html_e( 'Client ID', 'dragon-content-decay' ); ?></label>
					</th>
					<td>
						<input type="text"
								id="dragoncontentdecay_google_client_id"
								name="dragoncontentdecay_google_client_id"
								value="<?php echo esc_attr( $settings['client_id'] ); ?>"
								class="regular-text"
								placeholder="<?php esc_attr_e( 'Paste the client ID from your Google Cloud OAuth credentials', 'dragon-content-decay' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="dragoncontentdecay_google_client_secret"><?php esc_html_e( 'Client Secret', 'dragon-content-decay' ); ?></label>
					</th>
					<td>
						<input type="password"
								id="dragoncontentdecay_google_client_secret"
								name="dragoncontentdecay_google_client_secret"
								value="<?php echo esc_attr( $settings['client_secret'] ); ?>"
								class="regular-text"
								placeholder="GOCSPX-...">
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="dragoncontentdecay_ga4_property_id"><?php esc_html_e( 'GA4 Property ID', 'dragon-content-decay' ); ?></label>
					</th>
					<td>
						<input type="text"
								id="dragoncontentdecay_ga4_property_id"
								name="dragoncontentdecay_ga4_property_id"
								value="<?php echo esc_attr( $settings['ga4_property_id'] ); ?>"
								class="regular-text"
								placeholder="123456789">
						<p class="description">
							<?php esc_html_e( 'Find this in Google Analytics > Admin > Property Settings', 'dragon-content-decay' ); ?>
						</p>
					</td>
				</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Search Console', 'dragon-content-decay' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="dragoncontentdecay_gsc_enabled" value="1" <?php checked( $settings['gsc_enabled'] ); ?>>
								<?php esc_html_e( 'Also pull Google Search Console data (search clicks & impressions) alongside GA4', 'dragon-content-decay' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Enable the Search Console API in your Google Cloud project first. After turning this on and saving, reconnect to Google to grant Search Console access.', 'dragon-content-decay' ); ?>
							</p>
							<?php if ( $settings['gsc_enabled'] && $settings['is_connected'] && ! $settings['gsc_scope_granted'] ) : ?>
								<p class="description dcd-status-disconnected">
									<span class="dashicons dashicons-warning"></span>
									<?php esc_html_e( 'Reconnect to Google to grant Search Console access.', 'dragon-content-decay' ); ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=dragon-content-decay&tab=settings&action=connect' ), 'dragoncontentdecay_oauth_action' ) ); ?>"><?php esc_html_e( 'Reconnect now', 'dragon-content-decay' ); ?></a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $settings['gsc_enabled'] ) : ?>
					<tr>
						<th scope="row">
							<label for="dragoncontentdecay_gsc_property"><?php esc_html_e( 'Search Console property', 'dragon-content-decay' ); ?></label>
						</th>
						<td>
							<?php if ( ! empty( $settings['gsc_sites'] ) ) : ?>
								<select id="dragoncontentdecay_gsc_property" name="dragoncontentdecay_gsc_property">
									<option value=""><?php esc_html_e( '— Select a property —', 'dragon-content-decay' ); ?></option>
									<?php foreach ( $settings['gsc_sites'] as $dragoncontentdecay_site ) : ?>
										<option value="<?php echo esc_attr( $dragoncontentdecay_site ); ?>" <?php selected( $settings['gsc_property'], $dragoncontentdecay_site ); ?>>
											<?php echo esc_html( $dragoncontentdecay_site ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( $settings['is_connected'] && $settings['gsc_scope_granted'] ) : ?>
								<p class="description"><?php esc_html_e( 'No Search Console properties were found for this Google account.', 'dragon-content-decay' ); ?></p>
								<input type="hidden" name="dragoncontentdecay_gsc_property" value="<?php echo esc_attr( $settings['gsc_property'] ); ?>">
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Connect and grant Search Console access to choose a property.', 'dragon-content-decay' ); ?></p>
								<input type="hidden" name="dragoncontentdecay_gsc_property" value="<?php echo esc_attr( $settings['gsc_property'] ); ?>">
							<?php endif; ?>
						</td>
					</tr>
					<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection Status', 'dragon-content-decay' ); ?></th>
					<td>
						<?php if ( $settings['is_connected'] ) : ?>
							<span class="dcd-status dcd-status-connected">
								<span class="dashicons dashicons-yes-alt"></span>
								<?php esc_html_e( 'Connected', 'dragon-content-decay' ); ?>
							</span>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=dragon-content-decay&tab=settings&action=disconnect' ), 'dragoncontentdecay_oauth_action' ) ); ?>"
								class="button button-secondary">
								<?php esc_html_e( 'Disconnect', 'dragon-content-decay' ); ?>
							</a>
						<?php else : ?>
							<span class="dcd-status dcd-status-disconnected">
								<span class="dashicons dashicons-warning"></span>
								<?php esc_html_e( 'Not Connected', 'dragon-content-decay' ); ?>
							</span>
							<?php if ( ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] ) ) : ?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=dragon-content-decay&tab=settings&action=connect' ), 'dragoncontentdecay_oauth_action' ) ); ?>"
									class="button button-primary">
									<?php esc_html_e( 'Connect to Google', 'dragon-content-decay' ); ?>
								</a>
							<?php else : ?>
								<p class="description">
									<?php esc_html_e( 'Enter your Client ID and Secret above, save settings, then connect.', 'dragon-content-decay' ); ?>
								</p>
							<?php endif; ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<!-- Analysis Settings -->
		<div class="dcd-settings-section">
			<h2>
				<span class="dashicons dashicons-chart-bar"></span>
				<?php esc_html_e( 'Analysis Settings', 'dragon-content-decay' ); ?>
			</h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="dragoncontentdecay_decay_threshold"><?php esc_html_e( 'Decay Threshold', 'dragon-content-decay' ); ?></label>
					</th>
					<td>
						<input type="number"
								id="dragoncontentdecay_decay_threshold"
								name="dragoncontentdecay_decay_threshold"
								value="<?php echo esc_attr( $settings['decay_threshold'] ); ?>"
								min="-100"
								max="0"
								class="small-text">
						<span>%</span>
						<p class="description">
							<?php esc_html_e( 'Posts with traffic change below this threshold will be marked as decaying. Default: -20%', 'dragon-content-decay' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="dragoncontentdecay_comparison_period"><?php esc_html_e( 'Comparison Period', 'dragon-content-decay' ); ?></label>
					</th>
					<td>
						<select id="dragoncontentdecay_comparison_period" name="dragoncontentdecay_comparison_period">
							<option value="30" <?php selected( $settings['comparison_period'], 30 ); ?>>
								<?php esc_html_e( '30 days', 'dragon-content-decay' ); ?>
							</option>
							<option value="60" <?php selected( $settings['comparison_period'], 60 ); ?>>
								<?php esc_html_e( '60 days', 'dragon-content-decay' ); ?>
							</option>
							<option value="90" <?php selected( $settings['comparison_period'], 90 ); ?>>
								<?php esc_html_e( '90 days', 'dragon-content-decay' ); ?>
							</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Compare traffic over this period vs the previous period of the same length.', 'dragon-content-decay' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post Types to Track', 'dragon-content-decay' ); ?></th>
					<td>
						<?php
						foreach ( $post_types as $dragoncontentdecay_post_type ) :
							if ( in_array( $dragoncontentdecay_post_type->name, array( 'attachment' ), true ) ) {
								continue;
							}
							?>
							<label>
								<input type="checkbox"
										name="dragoncontentdecay_post_types[]"
										value="<?php echo esc_attr( $dragoncontentdecay_post_type->name ); ?>"
										<?php checked( in_array( $dragoncontentdecay_post_type->name, $selected_types, true ) ); ?>>
								<?php echo esc_html( $dragoncontentdecay_post_type->label ); ?>
							</label><br>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
		</div>

		<!-- Notification Settings -->
		<div class="dcd-settings-section">
			<h2>
				<span class="dashicons dashicons-email-alt"></span>
				<?php esc_html_e( 'Email Notifications', 'dragon-content-decay' ); ?>
			</h2>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="dragoncontentdecay_email_frequency"><?php esc_html_e( 'Email Digest', 'dragon-content-decay' ); ?></label>
					</th>
					<td>
						<select id="dragoncontentdecay_email_frequency" name="dragoncontentdecay_email_frequency">
							<option value="off" <?php selected( $settings['email_frequency'], 'off' ); ?>>
								<?php esc_html_e( 'Off', 'dragon-content-decay' ); ?>
							</option>
							<option value="weekly" <?php selected( $settings['email_frequency'], 'weekly' ); ?>>
								<?php esc_html_e( 'Weekly', 'dragon-content-decay' ); ?>
							</option>
							<option value="monthly" <?php selected( $settings['email_frequency'], 'monthly' ); ?>>
								<?php esc_html_e( 'Monthly', 'dragon-content-decay' ); ?>
							</option>
						</select>
						<p class="description">
							<?php
							printf(
								/* translators: %s: Admin email */
								esc_html__( 'Digest emails will be sent to: %s', 'dragon-content-decay' ),
								'<strong>' . esc_html( get_option( 'admin_email' ) ) . '</strong>'
							);
							?>
						</p>
					</td>
				</tr>
			</table>
		</div>

		<h2><?php esc_html_e( 'Uninstall', 'dragon-content-decay' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Delete data on uninstall', 'dragon-content-decay' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="dragoncontentdecay_delete_data_on_uninstall" value="1" <?php checked( get_option( 'dragoncontentdecay_delete_data_on_uninstall' ) ); ?> />
						<?php esc_html_e( 'Remove settings, Google credentials and analytics history when the plugin is deleted. Leave off to keep them through a reinstall.', 'dragon-content-decay' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'dragon-content-decay' ) ); ?>
	</form>

	<!-- Setup Instructions -->
	<div class="dcd-settings-section dcd-setup-guide">
		<h2>
			<span class="dashicons dashicons-info"></span>
			<?php esc_html_e( 'Setup Guide', 'dragon-content-decay' ); ?>
		</h2>

		<div class="dcd-setup-steps">
			<div class="dcd-step">
				<div class="dcd-step-number">1</div>
				<div class="dcd-step-content">
					<h4><?php esc_html_e( 'Create Google Cloud Project', 'dragon-content-decay' ); ?></h4>
					<p><?php esc_html_e( 'Go to Google Cloud Console and create a new project (or select an existing one).', 'dragon-content-decay' ); ?></p>
					<a href="https://console.cloud.google.com/projectcreate" target="_blank" class="button button-secondary">
						<?php esc_html_e( 'Open Cloud Console', 'dragon-content-decay' ); ?>
					</a>
				</div>
			</div>

			<div class="dcd-step">
				<div class="dcd-step-number">2</div>
				<div class="dcd-step-content">
					<h4><?php esc_html_e( 'Enable Analytics Data API', 'dragon-content-decay' ); ?></h4>
					<p><?php esc_html_e( 'Enable the Google Analytics Data API for your project.', 'dragon-content-decay' ); ?></p>
					<a href="https://console.cloud.google.com/apis/library/analyticsdata.googleapis.com" target="_blank" class="button button-secondary">
						<?php esc_html_e( 'Enable API', 'dragon-content-decay' ); ?>
					</a>
				</div>
			</div>

			<div class="dcd-step">
				<div class="dcd-step-number">3</div>
				<div class="dcd-step-content">
					<h4><?php esc_html_e( 'Create OAuth Credentials', 'dragon-content-decay' ); ?></h4>
					<p><?php esc_html_e( 'Create OAuth 2.0 credentials (Web application type).', 'dragon-content-decay' ); ?></p>
					<p>
						<strong><?php esc_html_e( 'Authorized redirect URI:', 'dragon-content-decay' ); ?></strong><br>
						<code><?php echo esc_html( admin_url( 'tools.php?page=dragon-content-decay&tab=settings&action=callback' ) ); ?></code>
					</p>
				</div>
			</div>

			<div class="dcd-step">
				<div class="dcd-step-number">4</div>
				<div class="dcd-step-content">
					<h4><?php esc_html_e( 'Find Your GA4 Property ID', 'dragon-content-decay' ); ?></h4>
					<p><?php esc_html_e( 'In Google Analytics, go to Admin → Property Settings to find your Property ID (numeric).', 'dragon-content-decay' ); ?></p>
				</div>
			</div>
		</div>
	</div>
</div>
