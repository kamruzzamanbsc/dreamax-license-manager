(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('[data-dlm-import-form]').forEach(function (form) {
			var input = form.querySelector('[data-dlm-file-input]');
			var filename = form.querySelector('[data-dlm-file-name]');
			var preview = form.querySelector('[data-dlm-preview-only]');
			var confirmWrap = form.querySelector('[data-dlm-import-confirm]');
			var confirmation = confirmWrap ? confirmWrap.querySelector('input') : null;
			var submit = form.querySelector('[data-dlm-import-submit]');
			var label = form.querySelector('[data-dlm-import-label]');
			var strings = window.dreamaxLmAdmin || {};

			if (!input || !filename || !preview || !confirmWrap || !confirmation || !submit || !label) {
				return;
			}

			function updateImport() {
				var committing = !preview.checked;
				filename.textContent = input.files && input.files.length ? input.files[0].name : (strings.noFileSelected || 'No file selected');
				confirmWrap.hidden = !committing;
				confirmation.disabled = !committing;
				confirmation.required = committing;
				if (!committing) {
					confirmation.checked = false;
				}
				submit.disabled = !input.files.length || (committing && !confirmation.checked);
				label.textContent = committing ? (strings.commitImport || 'Import licenses') : (strings.checkImport || 'Check import');
			}

			input.addEventListener('change', updateImport);
			preview.addEventListener('change', updateImport);
			confirmation.addEventListener('change', updateImport);
			updateImport();
		});

		document.querySelectorAll('[data-dlm-export-form]').forEach(function (form) {
			var exportType = form.querySelector('select[name="export_type"]');
			var fullKeys = form.querySelector('[data-dlm-full-keys]');
			var fullKeysWrap = fullKeys ? fullKeys.closest('label') : null;
			var confirmWrap = form.querySelector('[data-dlm-export-confirm]');
			var confirmation = confirmWrap ? confirmWrap.querySelector('input') : null;
			var submit = form.querySelector('[data-dlm-export-submit]');
			var label = form.querySelector('[data-dlm-export-label]');
			var strings = window.dreamaxLmAdmin || {};

			if (!fullKeys || !confirmWrap || !confirmation || !submit || !label) {
				return;
			}

			function updateExport() {
				var licenseExport = !exportType || exportType.value === 'licenses';
				var sensitive = licenseExport && fullKeys.checked;
				fullKeys.disabled = !licenseExport;
				if (fullKeysWrap) {
					fullKeysWrap.hidden = !licenseExport;
				}
				confirmWrap.hidden = !sensitive;
				confirmation.disabled = !sensitive;
				confirmation.required = sensitive;
				if (!sensitive) {
					confirmation.checked = false;
				}
				submit.disabled = sensitive && !confirmation.checked;
				label.textContent = sensitive ? (strings.sensitiveExport || 'Download sensitive export') : (strings.safeExport || 'Download safe export');
			}

			fullKeys.addEventListener('change', updateExport);
			if (exportType) {
				exportType.addEventListener('change', updateExport);
			}
			confirmation.addEventListener('change', updateExport);
			updateExport();
		});

		document.querySelectorAll('[data-dlm-credential-create]').forEach(function (form) {
			var name = form.querySelector('[data-dlm-credential-name]');
			var scopes = Array.prototype.slice.call(form.querySelectorAll('[data-dlm-credential-scope]'));
			var submit = form.querySelector('[data-dlm-credential-create-submit]');

			if (!name || !scopes.length || !submit) {
				return;
			}

			function updateCredentialCreate() {
				submit.disabled = !name.value.trim() || !scopes.some(function (scope) { return scope.checked; });
			}

			name.addEventListener('input', updateCredentialCreate);
			scopes.forEach(function (scope) { scope.addEventListener('change', updateCredentialCreate); });
			updateCredentialCreate();
		});

		document.querySelectorAll('[data-dlm-credential-action]').forEach(function (form) {
			var confirmation = form.querySelector('[data-dlm-credential-confirm]');
			var submit = form.querySelector('[data-dlm-credential-action-submit]');

			if (!confirmation || !submit) {
				return;
			}

			function updateCredentialAction() {
				submit.disabled = !confirmation.checked;
			}

			confirmation.addEventListener('change', updateCredentialAction);
			updateCredentialAction();
		});

		document.querySelectorAll('[data-dlm-copy-secret]').forEach(function (button) {
			var selector = button.getAttribute('data-dlm-copy-secret');
			var value = selector ? document.querySelector(selector) : null;

			if (!value || !navigator.clipboard) {
				return;
			}

			button.addEventListener('click', function () {
				navigator.clipboard.writeText(value.textContent || '').then(function () {
					button.textContent = 'Copied';
				});
			});
		});

		document.querySelectorAll('[data-dlm-add-license]').forEach(function (form) {
			var sources = Array.prototype.slice.call(form.querySelectorAll('input[name="key_source"]'));
			var importField = form.querySelector('[data-dlm-import-field]');
			var keyInput = form.querySelector('#license_key');
			var toggle = form.querySelector('[data-dlm-toggle-key]');
			var toggleLabel = form.querySelector('[data-dlm-toggle-label]');
			var toggleIcon = form.querySelector('[data-dlm-toggle-icon]');
			var strings = window.dreamaxLmAdmin || {};

			if (!importField || !keyInput || !toggle || !toggleLabel) {
				return;
			}

			function updateKeySource() {
				var selected = sources.find(function (source) { return source.checked; });
				var importing = selected && selected.value === 'imported';
				importField.hidden = !importing;
				keyInput.disabled = !importing;
				keyInput.required = importing;
				if (!importing) {
					keyInput.value = '';
					keyInput.type = 'password';
					toggle.setAttribute('aria-pressed', 'false');
					toggleLabel.textContent = strings.showKey || 'Show';
					if (toggleIcon) {
						toggleIcon.classList.remove('dashicons-hidden');
						toggleIcon.classList.add('dashicons-visibility');
					}
				}
			}

			sources.forEach(function (source) { source.addEventListener('change', updateKeySource); });
			toggle.addEventListener('click', function () {
				var revealing = keyInput.type === 'password';
				keyInput.type = revealing ? 'text' : 'password';
				toggle.setAttribute('aria-pressed', revealing ? 'true' : 'false');
				toggleLabel.textContent = revealing ? (strings.hideKey || 'Hide') : (strings.showKey || 'Show');
				if (toggleIcon) {
					toggleIcon.classList.toggle('dashicons-visibility', !revealing);
					toggleIcon.classList.toggle('dashicons-hidden', revealing);
				}
				keyInput.focus();
			});
			updateKeySource();
		});

		document.querySelectorAll('[data-dlm-lifecycle-form]').forEach(function (form) {
			var operation = form.querySelector('[data-dlm-operation]');
			var extension = form.querySelector('[data-dlm-extension]');
			var extensionInput = extension ? extension.querySelector('input') : null;
			var reason = form.querySelector('input[name="reason"]');
			var confirmation = form.querySelector('[data-dlm-confirm]');
			var submit = form.querySelector('[data-dlm-submit]');

			if (!operation || !reason || !confirmation || !submit) {
				return;
			}

			function updateLifecycle() {
				var extending = operation.value === 'extend';
				if (extension) {
					extension.hidden = !extending;
				}
				if (extensionInput) {
					extensionInput.disabled = !extending;
					extensionInput.required = extending;
				}
				submit.disabled = operation.value === '' || reason.value.trim().length < 3 || !confirmation.checked;
			}

			operation.addEventListener('change', updateLifecycle);
			reason.addEventListener('input', updateLifecycle);
			confirmation.addEventListener('change', updateLifecycle);
			updateLifecycle();
		});

		document.querySelectorAll('[data-dlm-order-preview]').forEach(function (form) {
			var orderIds = form.querySelector('[data-dlm-order-ids]');
			var submit = form.querySelector('[data-dlm-order-preview-submit]');

			if (!orderIds || !submit) {
				return;
			}

			function updateOrderPreview() {
				submit.disabled = !orderIds.value.trim();
			}

			orderIds.addEventListener('input', updateOrderPreview);
			updateOrderPreview();
		});

		document.querySelectorAll('[data-dlm-claim-form]').forEach(function (form) {
			var operation = form.querySelector('[data-dlm-claim-operation]');
			var target = form.querySelector('[data-dlm-claim-target]');

			if (!operation || !target) {
				return;
			}

			function updateClaimTarget() {
				var overriding = operation.value === 'override';
				target.disabled = !overriding;
				target.required = overriding;
				if (!overriding) {
					target.value = '';
				}
			}

			operation.addEventListener('change', updateClaimTarget);
			updateClaimTarget();
		});

		document.querySelectorAll('[data-dlm-confirmed-form]').forEach(function (form) {
			var confirmation = form.querySelector('[data-dlm-confirm]');
			var submit = form.querySelector('[data-dlm-submit]');

			if (!confirmation || !submit) {
				return;
			}

			function updateConfirmedForm() {
				submit.disabled = !confirmation.checked || !form.checkValidity();
			}

			form.querySelectorAll('input, select, textarea').forEach(function (field) {
				field.addEventListener('input', updateConfirmedForm);
				field.addEventListener('change', updateConfirmedForm);
			});
			updateConfirmedForm();
		});

		document.querySelectorAll('[data-dlm-bulk]').forEach(function (form) {
			var selectAll = form.querySelector('[data-dlm-select-all]');
			var checkboxes = Array.prototype.slice.call(form.querySelectorAll('[data-dlm-license-checkbox]'));
			var operation = form.querySelector('[data-dlm-operation]');
			var extension = form.querySelector('[data-dlm-extension]');
			var extensionInput = extension ? extension.querySelector('input') : null;
			var reason = form.querySelector('input[name="reason"]');
			var confirmation = form.querySelector('[data-dlm-confirm]');
			var submit = form.querySelector('[data-dlm-submit]');
			var status = form.querySelector('[data-dlm-selection-status]');
			var bulkPanel = form.querySelector('.dreamax-lm-bulk');

			if (!selectAll || !operation || !reason || !confirmation || !submit || !status) {
				return;
			}

			function selectionMessage(count) {
				var strings = window.dreamaxLmAdmin || {};
				if (count === 0) {
					return strings.noneSelected || 'Select one or more licenses to continue.';
				}
				if (count === 1) {
					return strings.oneSelected || '1 license selected';
				}
				return (strings.manySelected || '%d licenses selected').replace('%d', String(count));
			}

			function update() {
				var selected = checkboxes.filter(function (checkbox) { return checkbox.checked; }).length;
				var hasSelection = selected > 0;
				var extending = operation.value === 'extend';
				var reasonReady = reason.value.trim().length >= 3;

				selectAll.checked = checkboxes.length > 0 && selected === checkboxes.length;
				selectAll.indeterminate = selected > 0 && selected < checkboxes.length;
				status.textContent = selectionMessage(selected);
				operation.disabled = !hasSelection;
				reason.disabled = !hasSelection;
				confirmation.disabled = !hasSelection;
				if (!hasSelection) {
					confirmation.checked = false;
				}
				if (bulkPanel) {
					bulkPanel.classList.toggle('is-inactive', !hasSelection);
				}
				if (extension) {
					extension.hidden = !extending;
				}
				if (extensionInput) {
					extensionInput.disabled = !hasSelection || !extending;
					extensionInput.required = hasSelection && extending;
				}
				submit.disabled = !hasSelection || operation.value === '' || !reasonReady || !confirmation.checked;
			}

			selectAll.addEventListener('change', function () {
				checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
				update();
			});
			checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', update); });
			operation.addEventListener('change', update);
			reason.addEventListener('input', update);
			confirmation.addEventListener('change', update);
			update();
		});
	});
}());
