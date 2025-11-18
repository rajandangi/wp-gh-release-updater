/**
 * Quick update check functionality for WordPress plugins page
 * Pure vanilla JavaScript - no jQuery dependency
 *
 * @package RajanDangi\WP_GH_Release_Updater
 */

(() => {
	// Wait for DOM to be ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	function init() {
		// Get plugin slug from localized data
		const slug = window.pluginUpdaterConfig?.slug;

		if (!slug) {
			console.error('Plugin slug not provided');
			return;
		}

		// Delegate click events for "Check for Updates" links
		document.addEventListener('click', (e) => {
			const link = e.target.closest(`.${slug}-check-updates`);
			if (!link) return;

			e.preventDefault();
			handleCheckUpdates(link, slug);
		});
	}

	function handleCheckUpdates(link, slug) {
		const plugin = link.getAttribute('data-plugin');
		const nonce = link.getAttribute('data-nonce');
		const originalText = link.textContent;

		const ajaxAction = `${slug}_check_updates_quick`;

		// Show loading state
		link.textContent = 'Checking...';
		link.style.opacity = '0.6';
		link.style.pointerEvents = 'none';

		// Prepare form data
		const formData = new FormData();
		formData.append('action', ajaxAction);
		formData.append('plugin', plugin);
		formData.append('nonce', nonce);

		// Make AJAX request
		fetch(ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		})
			.then((response) => {
				if (!response.ok) {
					throw new Error('Network response was not ok');
				}
				const contentType = response.headers.get('content-type');
				if (!contentType || !contentType.includes('application/json')) {
					throw new Error('Server returned non-JSON response');
				}
				return response.json();
			})
			.then((data) => {
				if (data.success) {
					// Show success message
					link.textContent = `✓ ${data.data.message}`;
					link.style.color = '#46b450';

					// If update available, reload page to show update button
					if (data.data.update_available) {
						setTimeout(() => {
							window.location.reload();
						}, 1000);
					} else {
						// Restore original text after 3 seconds
						setTimeout(() => {
							resetLink(link, originalText);
						}, 3000);
					}
				} else {
					// Show error message
					link.textContent = `Check Failed`;
					link.style.color = '#dc3232';

					// Restore original text after 5 seconds
					setTimeout(() => {
						resetLink(link, originalText);
					}, 5000);
				}
			})
			.catch((error) => {
				console.error('AJAX error:', error);
				link.textContent = `Check Failed`;
				link.style.color = '#dc3232';

				// Restore original text after 5 seconds
				setTimeout(() => {
					resetLink(link, originalText);
				}, 5000);
			});
	}

	function resetLink(link, originalText) {
		link.textContent = originalText;
		link.style.color = '';
		link.style.opacity = '';
		link.style.pointerEvents = '';
	}
})();
