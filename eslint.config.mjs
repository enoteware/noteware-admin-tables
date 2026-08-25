import wordpress from '@wordpress/eslint-plugin';
import globals from 'globals';

export default [
	...wordpress.configs.recommended,
	{
		files: [ 'plugin/assets/**/*.js' ],
		languageOptions: {
			globals: {
				...globals.browser,
				natAdminTables: 'readonly',
			},
		},
	},
	{
		files: [ 'scripts/**/*.js', 'tests/**/*.js', 'playwright.config.js' ],
		languageOptions: {
			globals: {
				...globals.node,
				...globals.jest,
			},
		},
	},
];
