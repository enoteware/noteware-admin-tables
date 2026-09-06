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

	test('posts the export from a detached form with selected rows', () => {
		document.body.innerHTML = `
			<div class="nat-export" data-url="/wp-admin/admin-post.php">
				<input type="hidden" name="action" value="nat_export">
				<input type="hidden" name="_wpnonce" value="nonce">
				<input type="hidden" name="post_type" value="nat_demo_record">
				<button type="button" name="format" value="csv">Export CSV</button>
			</div>
			<table>
				<tbody id="the-list">
					<tr><td><input type="checkbox" name="post[]" value="11" checked></td></tr>
					<tr><td><input type="checkbox" name="post[]" value="22"></td></tr>
					<tr><td><input type="checkbox" name="post[]" value="33" checked></td></tr>
				</tbody>
			</table>`;

		global.natAdminTables = { ajaxUrl: '/admin-ajax.php' };
		const originalSubmit = HTMLFormElement.prototype.submit;
		HTMLFormElement.prototype.submit = jest.fn();
		try {
			jest.isolateModules(() => {
				require(path.resolve(__dirname, '../../plugin/assets/admin.js'));
			});

			document.querySelector('[name="format"]').click();
			const form = document.querySelector('body > form');
			expect(form.action).toContain('/wp-admin/admin-post.php');
			expect(form.method).toBe('post');
			const values = Object.fromEntries(new FormData(form));
			expect(values.action).toBe('nat_export');
			expect(values.format).toBe('csv');
			expect(
				[...form.querySelectorAll('input[name="post[]"]')].map(
					(input) => input.value
				)
			).toEqual(['11', '33']);
			expect(HTMLFormElement.prototype.submit).toHaveBeenCalled();
		} finally {
			HTMLFormElement.prototype.submit = originalSubmit;
		}
	});
});
