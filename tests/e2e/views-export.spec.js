const fs = require('node:fs');
const AxeBuilder = require('@axe-core/playwright').default;
const { test, expect } = require('@playwright/test');

const SCREEN = '/wp-admin/edit.php?post_type=nat_demo_record';
const ONE_RECORD = `${SCREEN}&nat_filter_nat_demo_text=Text%2037`;

test.describe('views, scalar editing, and export', () => {
	test('saves a personal view and reopens it', async ({ page }) => {
		await page.goto('/wp-admin/tools.php?page=nat-views');
		await expect(
			page.getByRole('heading', { name: 'Table views' })
		).toBeVisible();

		const editor = page.locator('.nat-view-editor').first();
		const viewName = `Browser personal view ${Date.now()}`;
		await editor.locator('[data-name]').fill(viewName);
		await editor.getByRole('button', { name: 'Save as new view' }).click();
		await expect(page).toHaveURL(/nat_saved=1/);
		await expect(page.locator('select[name="view_id"]').first()).toContainText(
			viewName
		);
		await page.locator('select[name="view_id"]').first().selectOption('default');
		await page.getByRole('button', { name: 'Switch view' }).first().click();
		await expect(page).toHaveURL(/nat_saved=1/);

		await page.screenshot({
			path: 'tests/artifacts/table-views-saved.png',
			fullPage: true,
		});

		const results = await new AxeBuilder({ page })
			.include('.nat-views')
			.withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
			.analyze();
		expect(results.violations).toEqual([]);
	});

	test('keeps view editor contrast in dark mode', async ({ page }) => {
		await page.emulateMedia({ colorScheme: 'dark' });
		await page.goto('/wp-admin/tools.php?page=nat-views');
		const screen = page.locator('.nat-view-screen').first();
		await expect(screen).toBeVisible();
		await expect(screen).toHaveCSS('color', 'rgb(240, 240, 241)');
		await expect(screen).toHaveCSS('background-color', 'rgb(29, 35, 39)');
		await page.screenshot({
			path: 'tests/artifacts/table-views-dark.png',
			fullPage: true,
		});
	});

	test('edits ACF number and boolean values with undo', async ({ page }) => {
		await page.goto(
			`${SCREEN}&nat_filter_nat_demo_text=Text%2041`
		);
		const row = page
			.getByRole('row')
			.filter({
				has: page.getByRole('link', {
					name: 'Demo record 00041',
					exact: true,
				}),
			})
			.first();

		const numberCell = row.locator(
			'.nat-cell[data-column="nat_demo_number"]'
		);
		await numberCell.getByRole('button', { name: 'Edit' }).click();
		await numberCell.locator('[data-field="value"]').fill('42');
		await numberCell.getByRole('button', { name: 'Save' }).click();
		await expect(numberCell.locator('.nat-value')).toHaveText('42');
		await numberCell.getByRole('button', { name: 'Undo' }).click();
		await expect(numberCell.locator('.nat-value')).toHaveText('123');

		const enabledCell = row.locator(
			'.nat-cell[data-column="nat_demo_enabled"]'
		);
		await enabledCell.getByRole('button', { name: 'Edit' }).click();
		await enabledCell.locator('[data-field="value"]').selectOption('1');
		await enabledCell.getByRole('button', { name: 'Save' }).click();
		await expect(enabledCell.locator('.nat-value')).toHaveText('Yes');
		await enabledCell.getByRole('button', { name: 'Undo' }).click();
		await expect(enabledCell.locator('.nat-value')).toHaveText('No');

		await page.screenshot({
			path: 'tests/artifacts/acf-scalar-edited.png',
			fullPage: true,
		});
	});

	test('downloads a filtered CSV of the active view', async ({ page }) => {
		await page.goto(ONE_RECORD);
		await expect(
			page.getByRole('button', { name: 'Export CSV' })
		).toBeVisible();

		const downloadPromise = page.waitForEvent('download');
		await page.getByRole('button', { name: 'Export CSV' }).click();
		const download = await downloadPromise;
		expect(download.suggestedFilename()).toBe('table-export.csv');
		const filePath = await download.path();
		const csv = fs.readFileSync(filePath, 'utf8');
		expect(csv).toContain('Demo text');
		expect(csv).toContain('Text 37');
		expect(csv).not.toContain('Text 31');

		await page.screenshot({
			path: 'tests/artifacts/export-filtered.png',
			fullPage: true,
		});
	});
});
