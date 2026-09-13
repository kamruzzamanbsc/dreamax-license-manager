(function () {
	'use strict';

	function renderImpact(preview, records, action, reason, days, extra) {
		var strings = window.dreamaxLmAdmin || {};
		var effects = {
			suspend: 'impactSuspend', restore: 'impactRestore', revoke: 'impactRevoke',
			extend: 'impactExtend', reset: 'impactReset', delete: 'impactDelete',
			export: 'impactExport', reassign: 'impactReassign'
		};
		var ready = !!effects[action] && records.length > 0;
		var validExpiry = true;
		preview.hidden = !ready;
		if (!ready) {
			return false;
		}
		preview.querySelector('[data-dlm-impact-effect]').textContent = strings[effects[action]] || '';
		var list = preview.querySelector('[data-dlm-impact-records]');
		list.replaceChildren();
		records.forEach(function (record) {
			var data = record.dataset;
			var expiry = data.expiry || (strings.impactNever || 'Never');
			var facts = [data.publicId,
				(strings.impactProduct || 'Product') + ': ' + data.product,
				(strings.impactCustomer || 'Customer') + ': ' + (Number(data.customer) || (strings.impactNone || 'Not linked')),
				(strings.impactOrder || 'Order') + ': ' + (Number(data.order) || (strings.impactNone || 'Not linked')),
				(strings.impactActive || 'Active installations') + ': ' + data.active,
				(strings.impactStatus || 'Current lifecycle state') + ': ' + data.status,
				(strings.impactExpiry || 'Expiry (UTC)') + ': ' + expiry];
			if (action === 'extend' && data.expiry && Number.isInteger(Number(days)) && Number(days) >= 1 && Number(days) <= 3650) {
				var original = Date.parse(data.expiry.replace(' ', 'T') + 'Z') / 1000;
				var base = Math.max(original, Number(data.serverNow));
				facts.push((strings.impactDays || 'Extension days') + ': ' + days);
				if (Number.isFinite(base)) {
					facts.push((strings.impactResult || 'Estimated new expiry (UTC)') + ': ' + new Date((base + Number(days) * 86400) * 1000).toISOString().replace('T', ' ').slice(0, 19));
				} else {
					validExpiry = false;
				}
			}
			var item = document.createElement('li');
			item.textContent = facts.join(' | ');
			list.appendChild(item);
		});
		var context = (strings.impactActor || 'Administrator') + ' #' + records[0].dataset.actor;
		if (action !== 'export') {
			context += ' | ' + (strings.impactReason || 'Audit reason') + ': ' + (reason || '...');
		}
		if (extra) {
			context += ' | ' + extra;
		}
		preview.querySelector('[data-dlm-impact-context]').textContent = context;
		return ready && validExpiry && (action !== 'extend' || (records.every(function (record) { return !!record.dataset.expiry; }) && Number.isInteger(Number(days)) && Number(days) >= 1 && Number(days) <= 3650));
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.dreamax-lm-merchant-notes').forEach(function (panel) {
			var rows = panel.querySelector('[data-dlm-merchant-fields]');
			var template = panel.querySelector('[data-dlm-merchant-template]');
			var add = panel.querySelector('[data-dlm-add-merchant-field]');
			if (!rows || !template || !add) {
				return;
			}

			function update() {
				add.disabled = rows.querySelectorAll('[data-dlm-merchant-row]').length >= Number(rows.dataset.limit);
			}
			add.addEventListener('click', function () {
				if (add.disabled) {
					return;
				}
				var row = template.content.firstElementChild.cloneNode(true);
				rows.appendChild(row);
				row.querySelector('input').focus();
				update();
			});
			rows.addEventListener('click', function (event) {
				var button = event.target.closest('[data-dlm-remove-merchant-field]');
				if (button && rows.contains(button)) {
					button.closest('[data-dlm-merchant-row]').remove();
					update();
				}
			});
			update();
		});

		document.querySelectorAll('[data-dlm-policy-form]').forEach(function (form) {
			var activationMode = form.querySelector('[data-dlm-activation-mode]');
			var limitWrap = form.querySelector('[data-dlm-policy-limit]');
			var limit = limitWrap ? limitWrap.querySelector('input') : null;
			var expiryMode = form.querySelector('[data-dlm-expiry-mode]');
			var expiryWrap = form.querySelector('[data-dlm-policy-expiry]');
			var expiry = expiryWrap ? expiryWrap.querySelector('input') : null;
			var reason = form.querySelector('input[name="reason"]');
			var confirmation = form.querySelector('[data-dlm-confirm]');
			var submit = form.querySelector('[data-dlm-submit]');
			var preview = form.querySelector('[data-dlm-policy-preview]');
			var strings = window.dreamaxLmAdmin || {};
			if (!activationMode || !limitWrap || !limit || !expiryMode || !expiryWrap || !expiry || !reason || !confirmation || !submit || !preview) {
				return;
			}

			function format(pattern, value) {
				return pattern.replace('%d', String(value)).replace('%s', String(value));
			}
			function updatePolicy() {
				var limited = activationMode.value === 'limited';
				var fixed = expiryMode.value === 'fixed';
				limitWrap.hidden = !limited;
				limit.disabled = !limited;
				limit.required = limited;
				expiryWrap.hidden = !fixed;
				expiry.disabled = !fixed;
				expiry.required = fixed;
				var activationText = activationMode.value === 'unlimited'
					? (strings.policyUnlimited || 'No activation limit.')
					: activationMode.value === 'disabled'
						? (strings.policyDisabled || 'New activations will be blocked; existing installations remain registered.')
						: format(strings.policyLimited || '%d activation slot(s).', limit.value || 0);
				var expiryText = fixed
					? format(strings.policyFixed || 'The license expires at %s UTC.', expiry.value || '')
					: (strings.policyNever || 'The license will not expire.');
				var activeText = format(strings.policyActive || '%d installation(s) are currently active.', form.dataset.activeCount || 0);
				preview.textContent = activationText + ' ' + expiryText + ' ' + activeText;
				submit.disabled = !form.checkValidity() || !confirmation.checked;
			}
			form.addEventListener('input', function (event) {
				if (event.target !== confirmation) { confirmation.checked = false; }
				updatePolicy();
			});
			form.addEventListener('change', function (event) {
				if (event.target !== confirmation) { confirmation.checked = false; }
				updatePolicy();
			});
			updatePolicy();
		});

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
			var preview = form.querySelector('[data-dlm-impact-preview]');

			if (!operation || !reason || !confirmation || !submit || !preview) {
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
				var shown = renderImpact(preview, [form], operation.value, reason.value.trim(), extensionInput ? extensionInput.value : '', '');
				confirmation.disabled = !shown;
				submit.disabled = !shown || !form.checkValidity() || !confirmation.checked;
			}

			[operation, reason, extensionInput].filter(Boolean).forEach(function (field) {
				field.addEventListener('input', function () { confirmation.checked = false; updateLifecycle(); });
				field.addEventListener('change', function () { confirmation.checked = false; updateLifecycle(); });
			});
			confirmation.addEventListener('change', updateLifecycle);
			updateLifecycle();
		});

		document.querySelectorAll('[data-dlm-reassign-form]').forEach(function (form) {
			var preview = form.querySelector('[data-dlm-impact-preview]');
			var confirmation = form.querySelector('[data-dlm-confirm]');
			var submit = form.querySelector('[data-dlm-submit]');
			var customer = form.querySelector('[name="target_customer_id"]');
			var order = form.querySelector('[name="target_order_id"]');
			var product = form.querySelector('[name="target_product_public_id"]');
			var reason = form.querySelector('[name="reason"]');
			var reset = form.querySelector('[name="reset_activations"]');
			var notify = form.querySelector('[name="notify_customer"]');
			var strings = window.dreamaxLmAdmin || {};
			if (!preview || !confirmation || !submit || !customer || !order || !product || !reason || !reset || !notify) { return; }
			function update() {
				var target = (strings.impactCustomer || 'Customer') + ': ' + (customer.value || '...') + ', ' +
					(strings.impactOrder || 'Order') + ': ' + (order.value || (strings.impactNone || 'Not linked')) + ', ' +
					(strings.impactProduct || 'Product') + ': ' + (product.value || form.dataset.product);
				var effects = (reset.checked ? strings.impactResetYes : strings.impactResetNo) + ' ' + (notify.checked ? strings.impactNotifyYes : strings.impactNotifyNo);
			renderImpact(preview, [form], 'reassign', reason.value.trim(), '', target + ' | ' + effects);
			confirmation.disabled = !customer.validity.valid || !order.validity.valid || !product.validity.valid || !reason.validity.valid;
			submit.disabled = confirmation.disabled || !confirmation.checked || !form.checkValidity();
			}
			[customer, order, product, reason, reset, notify].forEach(function (field) {
				field.addEventListener('input', function () { confirmation.checked = false; update(); });
				field.addEventListener('change', function () { confirmation.checked = false; update(); });
			});
			confirmation.addEventListener('change', update);
			update();
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
			var preview = form.querySelector('[data-dlm-impact-preview]');

			if (!selectAll || !operation || !reason || !confirmation || !submit || !status || !preview) {
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
				var records = checkboxes.filter(function (checkbox) { return checkbox.checked; });
				var selected = records.length;
				var hasSelection = selected > 0;
				var extending = operation.value === 'extend';
				var exporting = operation.value === 'export';
				var reasonReady = exporting || reason.value.trim().length >= 3;

				selectAll.checked = checkboxes.length > 0 && selected === checkboxes.length;
				selectAll.indeterminate = selected > 0 && selected < checkboxes.length;
				status.textContent = selectionMessage(selected);
				operation.disabled = !hasSelection;
				reason.disabled = !hasSelection || exporting;
				reason.required = hasSelection && !exporting;
				reason.closest('label').hidden = exporting;
				var shown = renderImpact(preview, records, operation.value, reason.value.trim(), extensionInput ? extensionInput.value : '', '');
				confirmation.disabled = !shown;
				if (!shown) {
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
				submit.disabled = !shown || !reasonReady || !form.checkValidity() || !confirmation.checked;
				submit.textContent = exporting ? ((window.dreamaxLmAdmin || {}).selectedExport || 'Download masked CSV') : ((window.dreamaxLmAdmin || {}).applyAction || 'Apply action');
			}

			selectAll.addEventListener('change', function () {
				checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
				confirmation.checked = false;
				update();
			});
			checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', function () { confirmation.checked = false; update(); }); });
			[operation, reason, extensionInput].filter(Boolean).forEach(function (field) {
				field.addEventListener('input', function () { confirmation.checked = false; update(); });
				field.addEventListener('change', function () { confirmation.checked = false; update(); });
			});
			confirmation.addEventListener('change', update);
			update();
		});
	});
}());
