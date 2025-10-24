<?php
/**
 * Admin settings page template
 *
 * @package WPGitHubReleaseUpdater
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$plugin_status = $this->getPluginStatus();
?>

<div class="wrap">
	<h1><?php echo esc_html( $this->config->getPageTitle() ); ?></h1>

	<div class="wp-github-updater-container">
		<!-- Notice -->
		<div class="notice notice-info inline">
			<p><strong>💡 Tip:</strong> You can check for updates directly from the Plugins page using the "Check for Updates" link.</p>
		</div>

		<!-- Settings Form -->
		<div class="card">
			<h2 class="title">GitHub Authentication</h2>

			<form method="post" action="options.php" id="wp-github-updater-settings-form">
				<?php
				settings_fields( $this->config->getSettingsGroup() );
				?>

				<p><?php $this->settingsSectionCallback(); ?></p>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="repository_url">Repository URL</label>
						</th>
						<td>
							<?php $this->repositoryUrlFieldCallback(); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="access_token">Access Token</label>
						</th>
						<td>
							<?php $this->accessTokenFieldCallback(); ?>
						</td>
					</tr>
				</table>

				<div class="wp-github-updater-settings-actions">
					<?php submit_button( 'Save Settings', 'primary', 'submit', false ); ?>

					<button type="button" id="test-repository" class="button button-secondary">
						<span class="button-text">Test Repository Access</span>
						<span class="spinner"></span>
					</button>
				</div>

				<!-- Status Messages -->
				<div id="wp-github-updater-messages"></div>
			</form>
		</div>

	</div>
</div>
