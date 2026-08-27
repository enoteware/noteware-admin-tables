const AxeBuilder = require('@axe-core/playwright').default;
const { test, expect } = require('@playwright/test');

const SCREEN = '/wp-admin/edit.php?post_type=nat_demo_record';
const ONE_RECORD = `${SCREEN}&nat_filter_nat_demo_text=Text%2031`;

test.describe('parity surface', () => {
	test('renders the configured order, widths, links, terms, and images', async ({
		page,
	}) => {
		const consoleErrors = [];
		page.on('pageerror', (error) => consoleErrors.push(error.message));
		page.on('console', (message) => {
			if (message.type() === 'error') {
				consoleErrors.push(message.text());
			}
		});

		await page.goto(SCREEN);

		const headers = await page
			.locator('thead tr:first-child th, thead tr:first-child td')
			.evaluateAll((cells) => cells.map((cell) => cell.id));
		expect(headers.slice(0, 6)).toEqual([
			'cb',
			'title',
			'nat_nat_demo_link',
			'nat_nat_demo_text',
			'nat_nat_demo_choice',
			'nat_nat_demo_topic',
		]);
		expect(headers).not.toContain('author');
		expect(headers).not.toContain('date');
		expect(headers).toContain('nat_nat_demo_published');

		await expect(page.locator('#noteware-admin-tables-widths')).toHaveCount(
			1
		);

		await expect(
			page
				.locator('.nat-cell[data-column="nat_demo_link"] a.nat-link')
				.first()
		).toBeVisible();
		await expect(
			page
				.locator(
					'.nat-cell[data-column="nat_demo_permalink"] a.nat-link'
				)
				.first()
		).toBeVisible();
		await expect(
			page.locator('.nat-cell[data-column="nat_demo_thumb"] img').first()
		).toBeVisible();
		await expect(
			page.locator('.nat-cell[data-column="nat_demo_topic"]').first()
		).toContainText(/Topic|Not set/);

		// A narrow configured column must not squeeze a control below the
		// minimum accessible target size.
		const narrowEdit = page
			.locator('.nat-cell[data-column="nat_demo_thumb"] .nat-edit-button')
			.first();
		await expect(narrowEdit).toBeVisible();
		const box = await narrowEdit.boundingBox();
		expect(box.width).toBeGreaterThanOrEqual(24);
		expect(box.height).toBeGreaterThanOrEqual(24);
		expect(box.height).toBeLessThan(120);

		await page.screenshot({
			path: 'tests/artifacts/parity-default-layout.png',
			fullPage: true,
		});

		expect(consoleErrors).toEqual([]);
	});

	test('edits a link, reloads, undoes, and reloads again', async ({
		page,
	}) => {
		await page.goto(ONE_RECORD);

		const row = page.locator('#the-list tr').first();
		const rowId = await row.getAttribute('id');
		const selector = `#${rowId} .nat-cell[data-column="nat_demo_link"]`;
		const cell = page.locator(selector);

		const original = await cell
			.locator('.nat-value a.nat-link')
			.getAttribute('href');
		expect(original).toMatch(/^https:\/\//);

		await cell.getByRole('button', { name: 'Edit' }).click();
		const input = cell.locator('[data-field="value"]');
		await expect(input).toBeFocused();
		await expect(input).toHaveAttribute('type', 'url');

		await input.fill('not-a-link');
		await cell.getByRole('button', { name: 'Save' }).click();
		await expect(cell.locator('.nat-status')).toHaveClass(/nat-error/);
		await expect(cell.locator('.nat-value a.nat-link')).toHaveAttribute(
			'href',
			original
		);

		await input.fill('https://example.test/apply/browser-proof');
		const saved = page.waitForResponse((response) =>
			response.url().includes('admin-ajax.php')
		);
		await cell.getByRole('button', { name: 'Save' }).click();
		expect((await saved).status()).toBe(200);
		await expect(cell.locator('.nat-value')).toHaveText(
			'https://example.test/apply/browser-proof'
		);
		await page.screenshot({
			path: 'tests/artifacts/parity-link-edited.png',
			fullPage: true,
		});

		// First reload: the saved link must persist and stay undoable.
		await page.goto(ONE_RECORD);
		await expect(page.locator(`${selector} a.nat-link`)).toHaveAttribute(
			'href',
			'https://example.test/apply/browser-proof'
		);
		await page.screenshot({
			path: 'tests/artifacts/parity-link-after-reload.png',
			fullPage: true,
		});

		const undo = page.locator(`${selector} .nat-undo`);
		await expect(undo).toHaveCount(1);
		const undone = page.waitForResponse((response) =>
			response.url().includes('admin-ajax.php')
		);
		await undo.click();
		expect((await undone).status()).toBe(200);
		await expect(page.locator(`${selector} .nat-value`)).toHaveText(
			original
		);

		// Second reload: the restored link must be the exact original.
		await page.goto(ONE_RECORD);
		await expect(page.locator(`${selector} a.nat-link`)).toHaveAttribute(
			'href',
			original
		);
		await expect(page.locator(`${selector} .nat-undo`)).toHaveCount(0);
		await page.screenshot({
			path: 'tests/artifacts/parity-link-after-undo-reload.png',
			fullPage: true,
		});
	});

	test('filters on the empty and has-a-value operators', async ({ page }) => {
		await page.goto(`${SCREEN}&nat_op_nat_demo_link=empty`);
		const emptyCells = page.locator(
			'#the-list .nat-cell[data-column="nat_demo_link"]'
		);
		await expect(emptyCells.first()).toBeVisible();
		const emptyCount = await emptyCells.count();
		for (let index = 0; index < emptyCount; index += 1) {
			await expect(
				emptyCells.nth(index).locator('.nat-value .nat-empty')
			).toHaveCount(1);
		}
		await page.screenshot({
			path: 'tests/artifacts/parity-filter-empty.png',
			fullPage: true,
		});

		await page.goto(`${SCREEN}&nat_op_nat_demo_link=not_empty`);
		const presentCells = page.locator(
			'#the-list .nat-cell[data-column="nat_demo_link"]'
		);
		await expect(presentCells.first()).toBeVisible();
		const presentCount = await presentCells.count();
		for (let index = 0; index < presentCount; index += 1) {
			await expect(
				presentCells.nth(index).locator('.nat-value a.nat-link')
			).toHaveCount(1);
		}
	});

	test('bulk edits the selected records and undoes them together', async ({
		page,
	}) => {
		await page.goto(`${SCREEN}&nat_op_nat_demo_link=not_empty`);

		const checkboxes = page.locator('#the-list input[name="post[]"]');
		await checkboxes.nth(0).check();
		await checkboxes.nth(1).check();

		const toggle = page.getByRole('button', { name: 'Bulk edit fields' });
		await toggle.click();
		await expect(toggle).toHaveAttribute('aria-expanded', 'true');

		await page.locator('.nat-bulk-column').selectOption('nat_demo_link');
		await page
			.locator(
				'.nat-bulk-control[data-column="nat_demo_link"] [data-field="value"]'
			)
			.fill('https://example.test/apply/bulk-proof');

		const panelAxe = await new AxeBuilder({ page })
			.include('#nat-bulk-panel')
			.withTags(['wcag2a', 'wcag2aa', 'wcag22aa'])
			.analyze();
		expect(panelAxe.violations).toEqual([]);

		const applied = page.waitForResponse((response) =>
			response.url().includes('admin-ajax.php')
		);
		await page.getByRole('button', { name: 'Apply to selected' }).click();
		expect((await applied).status()).toBe(200);
		await expect(page.locator('.nat-bulk-status')).toContainText(
			'2 changed, 0 not changed.'
		);
		await page.screenshot({
			path: 'tests/artifacts/parity-bulk-applied.png',
			fullPage: true,
		});

		await page.getByRole('button', { name: 'Undo these changes' }).click();
		await expect(page.locator('.nat-bulk-status')).toContainText(
			'2 undone, 0 not undone.'
		);
		await page.screenshot({
			path: 'tests/artifacts/parity-bulk-undone.png',
			fullPage: true,
		});
	});
});
