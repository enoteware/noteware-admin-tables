const { normalizeLicense } = require('../../scripts/license-utils');

describe('dependency license normalization', () => {
	test.each([
		['MIT', 'MIT'],
		[['MIT', 'GPL-2.0-or-later'], 'MIT OR GPL-2.0-or-later'],
		[{ type: 'BSD-3-Clause' }, 'BSD-3-Clause'],
		[[{ type: 'MIT' }, { type: 'Apache-2.0' }], 'MIT OR Apache-2.0'],
		[undefined, ''],
		[{ url: 'https://example.test/license' }, ''],
	])('normalizes %p', (input, expected) => {
		expect(normalizeLicense(input)).toBe(expected);
	});
});
