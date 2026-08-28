const AxeBuilder = require('@axe-core/playwright').default;
const { test, expect } = require('@playwright/test');

test.describe('generic admin-table vertical slice', () => {
	test('renders configured ACF and metadata columns', async ({ page }) => {
		const consoleErrors = [];
		page.on('pageerror', (error) => consoleErrors.push(error.message));
		page.on('console', (message) => {
			if (message.type() === 'error') {
				consoleErrors.push(message.text());
			}
		});

		await page.goto('/wp-admin/edit.php?post_type=nat_demo_record');
		await expect(
			page.getByRole('heading', { name: /Demo records/i })
		).toBeVisible();
		await expect(
			page
				.locator('thead')
				.getByRole('columnheader', { name: 'Demo text' })
		).toBeVisible();
		await expect(
			page
				.locator('thead')
				.getByRole('columnheader', { name: 'Demo number' })
		).toBeVisible();
		await expect(
			page.locator('thead').getByRole('columnheader', { name: 'Enabled' })
		).toBeVisible();
		await expect(page.locator('tbody tr').first()).toBeVisible();

		await page.screenshot({
			path: 'tests/artifacts/admin-table-default.png',
			fullPage: true,
		});

		expect(consoleErrors).toEqual([]);
	});

	test('sorts and filters through the public list-screen controls', async ({
		page,
	}) => {
		await page.goto('/wp-admin/edit.php?post_type=nat_demo_record');

		await page.locator('thead #nat_nat_demo_number a').click();
		await expect(page).toHaveURL(/orderby=nat_nat_demo_number/);

		await page.locator('#nat_filter_nat_demo_text').fill('Text 37');
		await page.locator('#post-query-submit').click();
		await expect(page).toHaveURL(
			/nat_filter_nat_demo_text=Text(?:\+|%20)37/
		);
		await expect(
			page.getByRole('link', { name: 'Demo record 00037', exact: true })
		).toBeVisible();
		await expect(page.locator('.wp-list-table tbody tr')).toHaveCount(1);

		await page.screenshot({
			path: 'tests/artifacts/admin-table-filtered.png',
			fullPage: true,
		});
	});

	test('supports keyboard cancel, inline edit, error, and undo states', async ({
		page,
	}) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=nat_demo_record&nat_filter_nat_demo_text=Text%2037'
		);

		const row = page
			.getByRole('row')
			.filter({
				has: page.getByRole('link', {
					name: 'Demo record 00037',
					exact: true,
				}),
			})
			.first();
		const rowId = await row.getAttribute('id');
		expect(rowId).toMatch(/^post-\d+$/);
		const cell = page.locator(
			`#${rowId} .nat-cell[data-column="nat_demo_note"]`
		);
		const edit = cell.getByRole('button', { name: 'Edit' });

		await edit.click();
		const input = cell.locator('[data-field="value"]');
		await expect(input).toBeFocused();
		await page.screenshot({
			path: 'tests/artifacts/admin-table-editor-open.png',
			fullPage: true,
		});
		const openEditorAxe = await new AxeBuilder({ page })
			.include(`#${rowId} .nat-cell[data-column="nat_demo_note"]`)
			.withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
			.analyze();
		expect(openEditorAxe.violations).toEqual([]);

		await input.press('Escape');
		await expect(edit).toBeFocused();
		await expect(cell.locator('.nat-inline-editor')).toBeHidden();

		await edit.click();
		await input.fill('Browser edited value');
		const saveResponse = page.waitForResponse((response) =>
			response.url().includes('admin-ajax.php')
		);
		await cell.getByRole('button', { name: 'Save' }).click();
		expect((await saveResponse).status()).toBe(200);
		await expect(cell.locator('.nat-value')).toHaveText(
			'Browser edited value'
		);
		await expect(cell.locator('.nat-status')).toHaveText('Saved.');
		await page.screenshot({
			path: 'tests/artifacts/admin-table-edited.png',
			fullPage: true,
		});

		await cell.getByRole('button', { name: 'Undo' }).click();
		await expect(cell.locator('.nat-value')).toHaveText('Note 37');
		await expect(cell.getByRole('button', { name: 'Undo' })).toHaveCount(0);
		await page.screenshot({
			path: 'tests/artifacts/admin-table-undone.png',
			fullPage: true,
		});

		await edit.click();
		await cell.locator('[data-field="nonce"]').evaluate((field) => {
			field.value = 'invalid';
		});
		await input.fill('Rejected value');
		await cell.getByRole('button', { name: 'Save' }).click();
		await expect(cell.locator('.nat-status')).toHaveText(
			'The edit link expired. Refresh the page and try again.'
		);
		await expect(cell.locator('.nat-status')).toHaveClass(/nat-error/);
		await page.screenshot({
			path: 'tests/artifacts/admin-table-error.png',
			fullPage: true,
		});
		const errorAxe = await new AxeBuilder({ page })
			.include(`#${rowId} .nat-cell[data-column="nat_demo_note"]`)
			.withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
			.analyze();
		expect(errorAxe.violations).toEqual([]);
	});

	test('rejects an invalid typed filter without broadening results', async ({
		page,
	}) => {
		await page.goto(
			'/wp-admin/edit.php?post_type=nat_demo_record&nat_filter_nat_demo_number=1%20OR%201%3D1'
		);

		await expect(
			page.getByText('The Demo number filter value is invalid.')
		).toBeVisible();
		await expect(
			page.locator('.wp-list-table tbody tr:not(.no-items)')
		).toHaveCount(0);
	});

	test('plugin-owned table markup passes automated WCAG checks', async ({
		page,
	}) => {
		await page.goto('/wp-admin/edit.php?post_type=nat_demo_record');

		const results = await new AxeBuilder({ page })
			.include('.wp-list-table')
			.withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
			.analyze();

		expect(results.violations).toEqual([]);
	});

	test('keeps plugin contrast when the OS prefers dark mode', async ({
		page,
	}) => {
		await page.emulateMedia({ colorScheme: 'dark' });
		await page.goto(
			'/wp-admin/edit.php?post_type=nat_demo_record&nat_filter_nat_demo_text=Text%2010'
		);

		const empty = page.locator('.wp-list-table .nat-empty').first();
		await expect(empty).toBeVisible();
		await expect(empty).toHaveCSS('color', 'rgb(80, 87, 94)');

		const cell = page
			.locator('.nat-cell[data-column="nat_demo_note"]')
			.first();
		await cell.getByRole('button', { name: 'Edit' }).click();
		await cell.locator('[data-field="nonce"]').evaluate((field) => {
			field.value = 'invalid';
		});
		await cell
			.locator('[data-field="value"]')
			.fill('Rejected contrast value');
		await cell.getByRole('button', { name: 'Save' }).click();
		await expect(cell.locator('.nat-error')).toBeVisible();
		await expect(cell.locator('.nat-error')).toHaveCSS(
			'color',
			'rgb(179, 45, 46)'
		);
		await page.screenshot({
			path: 'tests/artifacts/admin-table-dark-os-preference.png',
			fullPage: true,
		});

		const results = await new AxeBuilder({ page })
			.include('.wp-list-table')
			.withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
			.analyze();

		expect(results.violations).toEqual([]);
	});
});
