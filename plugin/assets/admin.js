(function () {
	'use strict';

	function message(form, text, isError) {
		const status = form.closest('.nat-cell').querySelector('.nat-status');
		status.textContent = text;
		status.classList.toggle('nat-error', Boolean(isError));
	}

	document.addEventListener('click', function (event) {
		const edit = event.target.closest('.nat-edit-button');
		if (edit) {
			const form = document.getElementById(
				edit.getAttribute('aria-controls')
			);
			form.hidden = false;
			edit.setAttribute('aria-expanded', 'true');
			form.querySelector('input:not([type="hidden"]), select').focus();
			return;
		}

		const cancel = event.target.closest('.nat-cancel');
		if (cancel) {
			const cancelForm = cancel.closest('.nat-inline-editor');
			cancelForm.hidden = true;
			const trigger =
				cancelForm.parentNode.querySelector('.nat-edit-button');
			trigger.setAttribute('aria-expanded', 'false');
			trigger.focus();
			return;
		}

		const save = event.target.closest('.nat-save');
		if (save) {
			saveEditor(save.closest('.nat-inline-editor'));
			return;
		}

		const undo = event.target.closest('.nat-undo');
		if (undo) {
			undo.disabled = true;
			const undoData = new FormData();
			undoData.append('action', 'nat_undo_edit');
			undoData.append('audit_id', undo.dataset.auditId);
			undoData.append('nonce', undo.dataset.nonce);
			fetch(natAdminTables.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: undoData,
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (payload) {
					if (!payload.success) {
						throw new Error(
							payload.data && payload.data.message
								? payload.data.message
								: 'Undo failed.'
						);
					}
					const cell = undo.closest('.nat-cell');
					cell.querySelector('.nat-value').textContent =
						payload.data.text;
					syncEditor(
						cell.querySelector('.nat-inline-editor'),
						payload.data
					);
					message(
						cell.querySelector('.nat-inline-editor'),
						payload.data.message,
						false
					);
					undo.remove();
				})
				.catch(function (error) {
					undo.disabled = false;
					message(
						undo
							.closest('.nat-cell')
							.querySelector('.nat-inline-editor'),
						error.message,
						true
					);
				});
		}
	});

	document.addEventListener('keydown', function (event) {
		if (
			'Escape' === event.key &&
			event.target.closest('.nat-inline-editor')
		) {
			event.target
				.closest('.nat-inline-editor')
				.querySelector('.nat-cancel')
				.click();
		}
	});

	function saveEditor(form) {
		const submit = form.querySelector('.nat-save');
		submit.disabled = true;
		message(form, 'Saving...', false);
		const data = new FormData();
		form.querySelectorAll('[name]').forEach(function (field) {
			if ('checkbox' !== field.type || field.checked) {
				data.append(field.name, field.value);
			}
		});
		data.append('action', 'nat_inline_edit');
		fetch(natAdminTables.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload.success) {
					throw new Error(
						payload.data && payload.data.message
							? payload.data.message
							: 'Save failed.'
					);
				}
				const cell = form.closest('.nat-cell');
				cell.querySelector('.nat-value').textContent =
					payload.data.text;
				syncEditor(form, payload.data);
				message(form, payload.data.savedLabel, false);
				const previousUndo = cell.querySelector('.nat-undo');
				if (previousUndo) {
					previousUndo.remove();
				}
				const undo = document.createElement('button');
				undo.type = 'button';
				undo.className = 'button-link nat-undo';
				undo.textContent = payload.data.undoLabel;
				undo.dataset.auditId = payload.data.auditId;
				undo.dataset.nonce = payload.data.undoNonce;
				form.after(undo);
				form.hidden = true;
				const trigger = cell.querySelector('.nat-edit-button');
				trigger.setAttribute('aria-expanded', 'false');
				trigger.focus();
			})
			.catch(function (error) {
				message(form, error.message, true);
			})
			.finally(function () {
				submit.disabled = false;
			});
	}

	function syncEditor(form, data) {
		const value = form.querySelector('[name="value"]');
		const remove = form.querySelector('[name="remove"]');
		const snapshot = form.querySelector('[name="snapshot"]');
		value.value = data.value;
		remove.checked = false;
		snapshot.value = data.snapshot;
	}
})();
