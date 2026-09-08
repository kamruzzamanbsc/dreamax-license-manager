(function () {
	'use strict';

	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}

		return new Promise(function (resolve, reject) {
			var helper = document.createElement('textarea');
			helper.value = text;
			helper.setAttribute('readonly', 'readonly');
			helper.style.position = 'fixed';
			helper.style.left = '-9999px';
			document.body.appendChild(helper);
			helper.select();
			try {
				if (!document.execCommand('copy')) {
					throw new Error('Copy is not available in this browser.');
				}
				resolve();
			} catch (error) {
				reject(error);
			} finally {
				helper.remove();
			}
		});
	}

	function initializeDashboards() {
		document.querySelectorAll('[data-dreamax-license-dashboard]').forEach(function (dashboard) {
			var links = Array.from(dashboard.querySelectorAll('[data-dreamax-dashboard-target]'));
			var panels = Array.from(dashboard.querySelectorAll('[data-dreamax-dashboard-panel]'));

			function activate(name, updateHash) {
				var activePanel = null;

				panels.forEach(function (panel) {
					var isActive = panel.dataset.dreamaxDashboardPanel === name;
					panel.classList.toggle('is-active', isActive);
					panel.hidden = !isActive;
					if (isActive) {
						activePanel = panel;
					}
				});

				links.forEach(function (link) {
					var isActive = link.dataset.dreamaxDashboardTarget === name;
					if (link.closest('.dreamax-lm-dashboard-nav')) {
						link.setAttribute('aria-current', isActive ? 'page' : 'false');
					}
				});

				if (!activePanel) {
					return;
				}

				if (updateHash && window.history && window.history.replaceState) {
					window.history.replaceState(null, '', '#' + activePanel.id);
				}

			}

			function preserveMobileViewport(callback) {
				var mobile = window.matchMedia && window.matchMedia('(max-width: 960px)').matches;
				var scrollLeft = window.scrollX;
				var scrollTop = window.scrollY;

				if (mobile) {
					dashboard.style.minHeight = Math.ceil(dashboard.getBoundingClientRect().height) + 'px';
				}

				callback();
				window.requestAnimationFrame(function () {
					window.scrollTo({left: scrollLeft, top: scrollTop, behavior: 'auto'});
				});
			}

			links.forEach(function (link) {
				link.addEventListener('click', function (event) {
					event.preventDefault();
					preserveMobileViewport(function () {
						activate(link.dataset.dreamaxDashboardTarget || 'overview', true);
					});
				});
			});

			var hashPanel = panels.find(function (panel) {
				return '#' + panel.id === window.location.hash;
			});
			dashboard.classList.add('is-enhanced');
			activate(hashPanel ? hashPanel.dataset.dreamaxDashboardPanel : 'overview', false);

			window.addEventListener('resize', function () {
				dashboard.style.minHeight = '';
			}, {passive: true});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var settings = window.dreamaxLmAccount || {};
		initializeDashboards();

		document.querySelectorAll('.dreamax-lm-reveal').forEach(function (button) {
			button.addEventListener('click', async function () {
				var originalLabel = button.textContent;
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
					if (!value) {
						throw new Error(settings.unable || 'Unable to reveal');
					}
					value.textContent = result.data.key;
					await copyText(result.data.key);
					button.textContent = settings.copied || 'Copied';
					window.setTimeout(function () {
						button.textContent = originalLabel;
					}, 1800);
				} catch (error) {
					window.alert(error.message);
				} finally {
					button.disabled = false;
				}
			});
		});
	});
}());
