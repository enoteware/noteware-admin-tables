const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
	testDir: './tests/e2e',
	fullyParallel: false,
	workers: 1,
	forbidOnly: Boolean(process.env.CI),
	retries: process.env.CI ? 1 : 0,
	reporter: [['line'], ['html', { open: 'never' }]],
	use: {
		baseURL: process.env.NAT_WP_URL || 'http://127.0.0.1:8097',
		storageState: 'test-results/.auth/admin.json',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	globalSetup: require.resolve('./tests/e2e/global-setup.js'),
	outputDir: 'test-results/playwright',
	projects: [
		{
			name: 'chromium',
			use: {
				...devices['Desktop Chrome'],
				// A parity screen carries many columns, so prove it on a real
				// desktop admin width rather than a squeezed default.
				viewport: { width: 1680, height: 1200 },
			},
		},
	],
	webServer: undefined,
});
