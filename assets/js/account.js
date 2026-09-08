(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var settings = window.dreamaxLmAccount || {};

		document.querySelectorAll('.dreamax-lm-reveal').forEach(function (button) {
			button.addEventListener('click', async function () {
				button.disabled = true;
				try {
					var body = new URLSearchParams({
						action: 'dreamax_lm_reveal',
						nonce: settings.nonce || '',
						license: button.dataset.license || ''
					});
					var response = await fetch(settings.ajaxUrl || '', {
						method: 'POST',
						credentials: 'same-origin',
						headers: {'Content-Type': 'application/x-www-form-urlencoded'},
						body: body
					});
					var result = await response.json();
					if (!result.success) {
						throw new Error(result.data && result.data.message ? result.data.message : (settings.unable || 'Unable to reveal'));
					}
					var value = document.getElementById('dreamax-key-' + button.dataset.license);
					value.textContent = result.data.key;
					await navigator.clipboard.writeText(result.data.key);
					button.textContent = settings.copied || 'Copied';
				} catch (error) {
					window.alert(error.message);
				} finally {
					button.disabled = false;
				}
			});
		});
	});
}());
