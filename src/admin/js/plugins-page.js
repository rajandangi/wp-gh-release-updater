/**
 * Quick update check functionality for WordPress plugins page
 * Pure vanilla JavaScript - no jQuery dependency
 *
 * @package RajanDangi\WP_GH_Release_Updater
 */

(() => {
	document.addEventListener('click', (event) => {
		if (event.__wpGhUpdaterHandled === true) {
			return;
		}

		const target = event.target instanceof Element ? event.target : null;
		const link = target?.closest('[data-wp-gh-release-updater-check]');

		if (!link) {
			return;
		}

		event.__wpGhUpdaterHandled = true;
		event.preventDefault();
		handleCheckUpdates(link);
	});

	function handleCheckUpdates(link) {
		const action = link.getAttribute('data-action');
		const ajaxUrl = link.getAttribute('data-ajax-url');
		const plugin = link.getAttribute('data-plugin');
		const nonce = link.getAttribute('data-nonce');
		const originalText = link.textContent;

		if (!action || !ajaxUrl || !plugin || !nonce) {
			console.error('Updater link is missing required data attributes');
			return;
		}

		// Show loading state
		link.textContent = 'Checking...';
		link.style.opacity = '0.6';
		link.style.pointerEvents = 'none';

		// Prepare form data
		const formData = new FormData();
		formData.append('action', action);
		formData.append('plugin', plugin);
		formData.append('nonce', nonce);

		// Make AJAX request
		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		})
			.then((response) => {
				if (!response.ok) {
					throw new Error('Network response was not ok');
				}
				const contentType = response.headers.get('content-type');
				if (!contentType?.includes('application/json')) {
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
