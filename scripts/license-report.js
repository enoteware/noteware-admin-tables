const fs = require('node:fs');
const path = require('node:path');
const { normalizeLicense } = require('./license-utils');

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
		license: normalizeLicense(dependency.license) || 'MISSING',
	});
}

const nodeModulesRoot = path.resolve('node_modules');
for (const [location, dependency] of Object.entries(npm.packages || {})) {
	if (!location) {
		continue;
	}
	let installed = {};
	const installedManifest = path.resolve(location, 'package.json');
	if (
		installedManifest.startsWith(`${nodeModulesRoot}${path.sep}`) &&
		fs.existsSync(installedManifest)
	) {
		installed = JSON.parse(fs.readFileSync(installedManifest, 'utf8'));
	}
	const license = [
		dependency.license,
		installed.license,
		dependency.licenses,
		installed.licenses,
	]
		.map(normalizeLicense)
		.find(Boolean);
	rows.push({
		manager: 'npm',
		name: location.replace(/^node_modules\//, ''),
		license: license || 'MISSING',
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
