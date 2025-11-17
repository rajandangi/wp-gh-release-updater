/**
 * Admin JavaScript for WP GitHub Release Updater
 * Handles repository testing functionality
 * Pure vanilla JavaScript - no jQuery dependency
 */

document.addEventListener('DOMContentLoaded', () => {
	// Find the plugin-specific localized variable
	// Since scripts are only enqueued on their own settings page, only ONE will exist
	let wpGitHubUpdater = null;

	// Search for any variable matching the pattern *_GitHubUpdater
	for (const key in window) {
		if (key.endsWith('_GitHubUpdater') && typeof window[key] === 'object') {
			wpGitHubUpdater = window[key];
			break;
		}
	}

	if (!wpGitHubUpdater) {
		return;
	}

	const testButton = document.getElementById('test-repository');
	const messagesContainer = document.getElementById('wp-github-updater-messages');

	/**
	 * Show message to user
	 * @param {string} message Message text
	 * @param {string} type Message type (info, success, warning, error)
	 */
	function showMessage(message, type = 'info') {
		const notice = document.createElement('div');
		notice.className = `notice notice-${type} is-dismissible`;
		notice.innerHTML = `<p>${message}</p>`;

		messagesContainer.innerHTML = '';
		messagesContainer.appendChild(notice);

		// Auto-dismiss after 5 seconds
		setTimeout(() => {
			if (notice.parentNode) {
				notice.parentNode.removeChild(notice);
			}
		}, 5000);
	}

	/**
	 * Set button loading state
	 * @param {HTMLElement} button Button element
	 * @param {boolean} loading Loading state
	 */
	function setButtonLoading(button, loading) {
		if (loading) {
			button.classList.add('loading');
			button.disabled = true;
		} else {
			button.classList.remove('loading');
			button.disabled = false;
		}
	}

	/**
	 * Make AJAX request
	 * @param {string} action WordPress AJAX action
	 * @param {Object} data Additional data to send
	 * @returns {Promise} Promise resolving to response data
	 */
	function makeAjaxRequest(action, data = {}) {
		return fetch(wpGitHubUpdater.ajaxUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams({
				action: action,
				nonce: wpGitHubUpdater.nonce,
				...data,
			}),
		})
			.then((response) => response.json())
			.then((result) => result)
			.catch(() => {
				return { success: false, message: wpGitHubUpdater.strings.error };
			});
	}

	// Test repository access button handler
	if (testButton) {
		testButton.addEventListener('click', () => {
			setButtonLoading(testButton, true);

			const repositoryUrl = document.getElementById('repository_url').value;
			const accessToken = document.getElementById('access_token').value;

			makeAjaxRequest(wpGitHubUpdater.actions.testRepo, {
				repository_url: repositoryUrl,
				access_token: accessToken,
			}).then((result) => {
				setButtonLoading(testButton, false);

				if (result.success) {
					showMessage(result.message, 'success');
				} else {
					showMessage(result.message, 'error');
				}
			});
		});
	}

	// Form validation
	const settingsForm = document.getElementById('wp-github-updater-settings-form');
	if (settingsForm) {
		settingsForm.addEventListener('submit', (e) => {
			const repositoryUrl = document.getElementById('repository_url').value.trim();

			if (!repositoryUrl) {
				e.preventDefault();
				showMessage('Please enter a repository URL before saving.', 'error');
				return false;
			}

			// Basic URL validation
			const urlPattern =
				/^([a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+|https:\/\/github\.com\/[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+)$/;
			if (!urlPattern.test(repositoryUrl)) {
				e.preventDefault();
				showMessage('Please enter a valid repository URL (owner/repo or GitHub URL).', 'error');
				return false;
			}
		});
	}

	// Handle access token field placeholder behavior
	const accessTokenField = document.getElementById('access_token');
	if (accessTokenField) {
		// Clear placeholder when focusing on a masked field
		accessTokenField.addEventListener('focus', function () {
			if (this.value.match(/^\*+$/)) {
				this.placeholder = 'Enter new token to replace existing one';
			}
		});

		accessTokenField.addEventListener('blur', function () {
			this.placeholder = 'ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
		});
	}
});
