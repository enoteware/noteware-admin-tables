const normalizeLicense = (value) => {
	const entries = Array.isArray(value) ? value : [value];
	return entries
		.map((entry) => {
			if ('string' === typeof entry) {
				return entry.trim();
			}
			if (
				entry &&
				'object' === typeof entry &&
				'string' === typeof entry.type
			) {
				return entry.type.trim();
			}
			return '';
		})
		.filter(Boolean)
		.join(' OR ');
};

module.exports = { normalizeLicense };
