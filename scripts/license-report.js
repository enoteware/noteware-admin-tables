const fs = require('node:fs');

const composer = JSON.parse(fs.readFileSync('composer.lock', 'utf8'));
const npm = JSON.parse(fs.readFileSync('package-lock.json', 'utf8'));
const rows = [];

for (const dependency of [
	...(composer.packages || []),
	...(composer['packages-dev'] || []),
]) {
	rows.push({
		manager: 'composer',
		name: dependency.name,
		license: (dependency.license || []).join(' OR ') || 'MISSING',
	});
}

for (const [location, dependency] of Object.entries(npm.packages || {})) {
	if (!location) {
		continue;
	}
	let installed = {};
	const installedManifest = `${location}/package.json`;
	if (fs.existsSync(installedManifest)) {
		installed = JSON.parse(fs.readFileSync(installedManifest, 'utf8'));
	}
	const legacy = dependency.licenses || installed.licenses;
	const legacyLicenses = Array.isArray(legacy)
		? legacy
				.map((item) => (item && item.type ? item.type : ''))
				.filter(Boolean)
				.join(' OR ')
		: '';
	rows.push({
		manager: 'npm',
		name: location.replace(/^node_modules\//, ''),
		license:
			dependency.license ||
			installed.license ||
			legacyLicenses ||
			'MISSING',
	});
}

const blocked = rows.filter((row) =>
	/(?:UNLICENSED|PROPRIETARY|SEE LICENSE)/i.test(row.license)
);
const missing = rows.filter((row) => 'MISSING' === row.license);

process.stdout.write(
	`${JSON.stringify({
		dependencies: rows.length,
		blocked: blocked.length,
		missing: missing.map((row) => `${row.manager}:${row.name}`),
	})}\n`
);

if (blocked.length) {
	throw new Error('A dependency declares a blocked license.');
}

if (missing.length) {
	throw new Error('A dependency does not declare a license.');
}
