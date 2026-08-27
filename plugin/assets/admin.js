(function () {
	'use strict';

	function message(form, text, isError) {
		const status = form.closest('.nat-cell').querySelector('.nat-status');
		status.textContent = text;
		status.classList.toggle('nat-error', Boolean(isError));
	}

	function bulkStatus() {
		return document.querySelector('.nat-bulk-status');
	}

	function readJson(response) {
		return response.json();
	}

	function failureMessage(payload, fallback) {
		return payload.data && payload.data.message
			? payload.data.message
			: fallback;
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

		const bulkToggle = event.target.closest('.nat-bulk-toggle');
		if (bulkToggle) {
			const panel = document.getElementById(
				bulkToggle.getAttribute('aria-controls')
			);
			panel.hidden = !panel.hidden;
			bulkToggle.setAttribute(
				'aria-expanded',
				panel.hidden ? 'false' : 'true'
			);
			if (!panel.hidden) {
				showBulkControl();
			}
			return;
		}

		const bulkApply = event.target.closest('.nat-bulk-apply');
		if (bulkApply) {
			applyBulkEdit(bulkApply);
			return;
		}

		const bulkUndo = event.target.closest('.nat-bulk-undo');
		if (bulkUndo) {
			undoBulkEdit(bulkUndo);
			return;
		}

		const undo = event.target.closest('.nat-undo');
		if (undo) {
			undo.disabled = true;
			sendUndo(undo.dataset.auditId, undo.dataset.nonce)
				.then(function (data) {
					const cell = undo.closest('.nat-cell');
					cell.querySelector('.nat-value').textContent = data.text;
					syncEditor(cell.querySelector('.nat-inline-editor'), data);
					message(
						cell.querySelector('.nat-inline-editor'),
						data.message,
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

	document.addEventListener('change', function (event) {
		if (event.target.closest('.nat-bulk-column')) {
			showBulkControl();
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

	function sendUndo(auditId, nonce) {
		const undoData = new FormData();
		undoData.append('action', 'nat_undo_edit');
		undoData.append('audit_id', auditId);
		undoData.append('nonce', nonce);
		return fetch(natAdminTables.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: undoData,
		})
			.then(readJson)
			.then(function (payload) {
				if (!payload.success) {
					throw new Error(failureMessage(payload, 'Undo failed.'));
				}
				return payload.data;
			});
	}

	function saveEditor(form) {
		const submit = form.querySelector('.nat-save');
		submit.disabled = true;
		message(form, 'Saving...', false);
		const data = new FormData();
		form.querySelectorAll('[data-field]').forEach(function (field) {
			if ('checkbox' !== field.type || field.checked) {
				data.append(field.dataset.field, field.value);
			}
		});
		data.append('action', 'nat_inline_edit');
		fetch(natAdminTables.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		})
			.then(readJson)
			.then(function (payload) {
				if (!payload.success) {
					throw new Error(failureMessage(payload, 'Save failed.'));
				}
				const cell = form.closest('.nat-cell');
				cell.querySelector('.nat-value').textContent =
					payload.data.text;
				syncEditor(form, payload.data);
				message(form, payload.data.savedLabel, false);
				addUndoButton(cell, form, payload.data);
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

	function addUndoButton(cell, form, data) {
		const previousUndo = cell.querySelector('.nat-undo');
		if (previousUndo) {
			previousUndo.remove();
		}
		const undo = document.createElement('button');
		undo.type = 'button';
		undo.className = 'button-link nat-undo';
		undo.textContent = data.undoLabel;
		undo.dataset.auditId = data.auditId;
		undo.dataset.nonce = data.undoNonce;
		form.after(undo);
	}

	function syncEditor(form, data) {
		if (!form) {
			return;
		}
		const value = form.querySelector('[data-field="value"]');
		const remove = form.querySelector('[data-field="remove"]');
		const snapshot = form.querySelector('[data-field="snapshot"]');
		if (value) {
			value.value = data.value;
		}
		if (remove) {
			remove.checked = false;
		}
		if (snapshot) {
			snapshot.value = data.snapshot;
		}
	}

	function showBulkControl() {
		const select = document.querySelector('.nat-bulk-column');
		if (!select) {
			return;
		}
		document
			.querySelectorAll('.nat-bulk-control')
			.forEach(function (control) {
				control.hidden = control.dataset.column !== select.value;
			});
	}

	function selectedPostIds() {
		const ids = [];
		document
			.querySelectorAll('input[name="post[]"]:checked')
			.forEach(function (checkbox) {
				ids.push(checkbox.value);
			});
		return ids;
	}

	function applyBulkEdit(button) {
		const status = bulkStatus();
		const ids = selectedPostIds();
		if (!ids.length) {
			status.textContent = natAdminTables.noSelectionLabel;
			status.classList.add('nat-error');
			return;
		}

		const select = document.querySelector('.nat-bulk-column');
		const control = document.querySelector(
			'.nat-bulk-control[data-column="' +
				select.value +
				'"] [data-field="value"]'
		);

		status.classList.remove('nat-error');
		status.textContent = natAdminTables.workingLabel;
		button.disabled = true;

		const data = new FormData();
		data.append('action', 'nat_bulk_edit');
		data.append(
			'post_type',
			document.getElementById('nat-bulk-post-type').value
		);
		data.append('nonce', document.getElementById('nat-bulk-nonce').value);
		data.append('column', select.value);
		data.append('value', control ? control.value : '');
		ids.forEach(function (id) {
			data.append('post_ids[]', id);
		});

		fetch(natAdminTables.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data,
		})
			.then(readJson)
			.then(function (payload) {
				if (!payload.success) {
					throw new Error(
						failureMessage(payload, 'Bulk edit failed.')
					);
				}
				reportBulkResult(status, payload.data);
			})
			.catch(function (error) {
				status.textContent = error.message;
				status.classList.add('nat-error');
			})
			.finally(function () {
				button.disabled = false;
			});
	}

	function reportBulkResult(status, data) {
		status.textContent = '';
		const summary = document.createElement('p');
		summary.textContent =
			String(data.changed.length) +
			' changed, ' +
			String(data.failed.length) +
			' not changed.';
		status.appendChild(summary);

		data.changed.forEach(function (change) {
			const cell = document.querySelector(
				'#post-' +
					String(change.postId) +
					' .nat-cell[data-column="' +
					change.column +
					'"]'
			);
			if (!cell) {
				return;
			}
			cell.querySelector('.nat-value').textContent = change.text;
			syncEditor(cell.querySelector('.nat-inline-editor'), change);
		});

		if (data.failed.length) {
			const list = document.createElement('ul');
			data.failed.forEach(function (failure) {
				const item = document.createElement('li');
				item.textContent =
					'Record ' + String(failure.postId) + ': ' + failure.message;
				list.appendChild(item);
			});
			status.appendChild(list);
		}

		if (data.changed.length) {
			const undoAll = document.createElement('button');
			undoAll.type = 'button';
			undoAll.className = 'button nat-bulk-undo';
			undoAll.textContent = natAdminTables.undoAllLabel;
			undoAll.dataset.changes = JSON.stringify(
				data.changed.map(function (change) {
					return {
						auditId: change.auditId,
						nonce: change.undoNonce,
						postId: change.postId,
						column: change.column,
					};
				})
			);
			status.appendChild(undoAll);
		}
	}

	function undoBulkEdit(button) {
		const status = bulkStatus();
		const changes = JSON.parse(button.dataset.changes);
		button.disabled = true;
		let undone = 0;
		let refused = 0;

		const steps = changes.map(function (change) {
			return sendUndo(change.auditId, change.nonce)
				.then(function (result) {
					undone += 1;
					const cell = document.querySelector(
						'#post-' +
							String(change.postId) +
							' .nat-cell[data-column="' +
							change.column +
							'"]'
					);
					if (cell) {
						cell.querySelector('.nat-value').textContent =
							result.text;
						syncEditor(
							cell.querySelector('.nat-inline-editor'),
							result
						);
					}
				})
				.catch(function () {
					refused += 1;
				});
		});

		Promise.all(steps).then(function () {
			status.textContent =
				String(undone) + ' undone, ' + String(refused) + ' not undone.';
			status.classList.toggle('nat-error', refused > 0);
			button.remove();
		});
	}
})();
