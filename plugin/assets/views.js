(function () {
	'use strict';
	for (const form of document.querySelectorAll('.nat-view-editor')) {
		const view = JSON.parse(form.dataset.view);
		const list = form.querySelector('.nat-view-columns');
		const status = form.querySelector('[role="status"]');
		const search = form.querySelector('[data-search]');
		function filter() {
			const term = search.value.toLowerCase();
			for (const row of list.children) {
				const column = view.columns.find(
					(item) => item.key === row.dataset.key
				);
				row.hidden = !`${column.key} ${column.label}`
					.toLowerCase()
					.includes(term);
			}
		}
		function render(focusKey, direction) {
			list.replaceChildren();
			view.columns.forEach((column, index) => {
				const row = document.createElement('div');
				row.className = 'nat-view-column';
				row.dataset.key = column.key;
				function input(title, type, value, update) {
					const label = document.createElement('label');
					label.textContent = title + ' ';
					const control = document.createElement('input');
					control.type = type;
					if (type === 'checkbox') {
						control.checked = value;
					} else {
						control.value = value;
					}
					control.addEventListener('input', () =>
						update(
							type === 'checkbox'
								? control.checked
								: control.value
						)
					);
					label.append(control);
					row.append(label);
				}
				input(column.key, 'checkbox', column.visible, (value) => {
					column.visible = value;
				});
				input('Label', 'text', column.label, (value) => {
					column.label = value;
				});
				input(
					'Width (for example 120px)',
					'text',
					column.width,
					(value) => {
						column.width = value;
					}
				);
				for (const [name, delta] of [
					['Up', -1],
					['Down', 1],
				]) {
					const button = document.createElement('button');
					button.type = 'button';
					button.className = 'button';
					button.textContent = name;
					button.dataset.direction = name;
					button.setAttribute(
						'aria-label',
						`Move ${column.label} ${name.toLowerCase()}`
					);
					button.disabled =
						index + delta < 0 ||
						index + delta >= view.columns.length;
					button.addEventListener('click', () => {
						view.columns.splice(index, 1);
						view.columns.splice(index + delta, 0, column);
						render(column.key, name);
						status.textContent = `${column.label} moved to position ${index + delta + 1}.`;
					});
					row.append(button);
				}
				list.append(row);
				if (focusKey === column.key) {
					const buttons = [...row.querySelectorAll('button')];
					(
						buttons.find(
							(button) =>
								button.dataset.direction === direction &&
								!button.disabled
						) || buttons.find((button) => !button.disabled)
					)?.focus();
				}
			});
			filter();
		}
		render();
		search.addEventListener('input', filter);
		form.addEventListener('submit', () => {
			view.name = form.querySelector('[data-name]').value;
			view.roles = [...form.querySelectorAll('[data-role]:checked')].map(
				(input) => input.value
			);
			view.visibility = view.roles.length ? 'shared' : 'personal';
			form.querySelector('[name="view"]').value = JSON.stringify(view);
		});
	}
})();
