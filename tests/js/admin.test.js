const path = require('node:path');

describe('admin list-screen interaction', () => {
	test('opens and cancels an editor with focus returned to its trigger', () => {
		document.body.innerHTML = `
			<div class="nat-cell">
				<button type="button" class="nat-edit-button" aria-expanded="false" aria-controls="nat-editor">Edit</button>
				<div id="nat-editor" class="nat-inline-editor" hidden>
					<input data-field="value" value="Before">
					<input type="hidden" data-field="snapshot" value="old">
					<input type="checkbox" data-field="remove" value="1">
					<button type="button" class="nat-save">Save</button>
					<button type="button" class="nat-cancel">Cancel</button>
				</div>
				<span class="nat-status"></span>
			</div>`;

		global.natAdminTables = { ajaxUrl: '/admin-ajax.php' };
		jest.isolateModules(() => {
			require(path.resolve(__dirname, '../../plugin/assets/admin.js'));
		});

		const edit = document.querySelector('.nat-edit-button');
		const editor = document.querySelector('.nat-inline-editor');
		edit.click();
		expect(editor.hidden).toBe(false);
		expect(edit.getAttribute('aria-expanded')).toBe('true');
		expect(document.activeElement).toBe(editor.querySelector('input'));

		editor.querySelector('.nat-cancel').click();
		expect(editor.hidden).toBe(true);
		expect(edit.getAttribute('aria-expanded')).toBe('false');
		expect(document.activeElement).toBe(edit);
	});
});
