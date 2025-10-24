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
		// Delegate click events for "Check for Updates" links
		document.addEventListener('click', (e) => {
			const link = e.target.closest('[class*="-check-updates"]');
			if (!link) return;

			e.preventDefault();
			handleCheckUpdates(link);
		});
	}

	function handleCheckUpdates(link) {
		const plugin = link.getAttribute('data-plugin');
		const nonce = link.getAttribute('data-nonce');
		const originalText = link.textContent;

		// Extract plugin slug from class name (e.g., "my-plugin-check-updates" -> "my-plugin")
		const classList = link.className.split(' ');
		let slug = null;

		for (const className of classList) {
			const match = className.match(/^(.+)-check-updates$/);
			if (match) {
				slug = match[1];
				break;
			}
		}

		if (!slug) {
			console.error('Could not determine plugin slug from class name');
			return;
		}

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
			.then((response) => response.json())
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
					const errorMessage = data.data?.message ? data.data.message : 'Error checking for updates';
					link.textContent = `✗ ${errorMessage}`;
					link.style.color = '#dc3232';

					// Restore original text after 5 seconds
					setTimeout(() => {
						resetLink(link, originalText);
					}, 5000);
				}
			})
			.catch((error) => {
				console.error('AJAX error:', error);
				link.textContent = `✗ ${error.message}`;
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
