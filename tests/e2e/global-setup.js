const fs = require('node:fs/promises');
const path = require('node:path');
const { chromium } = require('@playwright/test');

module.exports = async (config) => {
	const username = process.env.NAT_WP_ADMIN_USER;
	const password = process.env.NAT_WP_ADMIN_PASSWORD;

	if (!username || !password) {
		throw new Error(
			'NAT_WP_ADMIN_USER and NAT_WP_ADMIN_PASSWORD are required for browser tests.'
		);
	}

	const baseURL = config.projects[0].use.baseURL;
	const statePath = path.resolve('test-results/.auth/admin.json');
	await fs.mkdir(path.dirname(statePath), { recursive: true });

	const browser = await chromium.launch();
	try {
		await fs.access(statePath);
		const savedContext = await browser.newContext({
			storageState: statePath,
		});
		const savedPage = await savedContext.newPage();
		await savedPage.goto(`${baseURL}/wp-admin/`);
		if (savedPage.url().includes('/wp-admin/')) {
			await savedContext.close();
			await browser.close();
			return;
		}
		await savedContext.close();
	} catch (error) {
		if ('ENOENT' !== error.code) {
			throw error;
		}
	}

	const page = await browser.newPage();
	await page.goto(`${baseURL}/wp-login.php`);
	await page.locator('#user_login').fill(username);
	await page.locator('#user_pass').fill(password);
	await page.locator('#wp-submit').click();
	await page.waitForURL(/\/wp-admin\//);
	await page.context().storageState({ path: statePath });
	await browser.close();
};
