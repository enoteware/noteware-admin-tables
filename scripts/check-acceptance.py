#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Validate catalog integrity (0/1), or require product coverage (0/1/2).

Exit 1: invalid catalog. Exit 2: valid but incomplete coverage.
Evidence is a reviewable assertion, never automatically a trusted test runner.
"""
import argparse
from collections import Counter
from datetime import date
import itertools
import json
import re
from pathlib import Path
import subprocess

OPERATIONS = {'display', 'sort', 'filter', 'inline_edit', 'bulk_edit', 'export',
              'metrics', 'validation', 'authorization', 'value_states'}
STATES = {'implemented', 'unverified', 'missing'}


def strict_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f'Duplicate JSON key: {key}')
        result[key] = value
    return result


def string_list(value):
    return isinstance(value, list) and bool(value) and all(isinstance(item, str) and item for item in value)


def validate(data, root):
    errors, blockers = [], []
    counts = Counter({state: 0 for state in STATES})
    root = root.resolve()
    current_revision = None

    def require(condition, message):
        if not condition:
            errors.append(message)

    def file(path):
        if not isinstance(path, str):
            return None
        target = (root / path).resolve()
        if not target.is_relative_to(root) or not target.is_file():
            return None
        return target

    def evidence(refs, subject):
        nonlocal current_revision
        if not string_list(refs):
            return False
        for ref in refs:
            path = file(ref)
            if not path:
                errors.append(f'{subject}: evidence file missing or unsafe')
                return False
            try:
                record = json.loads(path.read_text(), object_pairs_hook=strict_object)
                if current_revision is None:
                    current_revision = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
                valid = (record['result'] == 'pass' and record['revision'] == current_revision
                         and string_list(record['subjects']) and subject in record['subjects'] and bool(record['command'])
                         and bool(record['environment']) and bool(record['reviewer'])
                         and string_list(record['test_ids']) and string_list(record['fixture_ids'])
                         and all(t in test_ids for t in record['test_ids'])
                         and all(f in fixtures for f in record['fixture_ids'])
                         and bool(file(record['output_path']))
                         and date.fromisoformat(record['date']) <= date.today())
                require(valid, f'{subject}: invalid/stale evidence receipt')
                if not valid:
                    return False
            except (KeyError, ValueError, TypeError, subprocess.CalledProcessError):
                errors.append(f'{subject}: malformed evidence receipt')
                return False
        return True

    require(data.get('schema_version') == 1, 'Unsupported schema version')
    require(set(data.get('operation_universe', [])) == OPERATIONS, 'Operation universe must be complete')
    baseline = data['baseline']
    require(baseline['source_id'] in data['sources'], 'Unknown baseline source')
    require(baseline['claim'] in {'incomplete', 'complete'}, 'Invalid baseline claim')
    require(bool(baseline['legacy_scope']), 'Legacy scope must be separate')
    if not baseline['inventory_complete']:
        blockers.append('Public baseline inventory is incomplete')
    for key, source in data['sources'].items():
        require(source['url'].startswith('https://'), f'{key}: full HTTPS source required')
        require(bool(source['version']) and bool(source['inspection']), f'{key}: provenance required')
        date.fromisoformat(source['observed_date'])
    fixtures = {f['id'] for f in data['fixtures']}
    require(len(fixtures) == len(data['fixtures']), 'Duplicate fixture ID')
    for fixture in data['fixtures']:
        require(bool(file(fixture['path'])), f"{fixture['id']}: missing fixture")
    test_ids = {t['id'] for t in data['test_inventory']}
    require(len(test_ids) == len(data['test_inventory']), 'Duplicate test ID')
    for test in data['test_inventory']:
        path = file(test['path'])
        require(bool(path), f"{test['id']}: missing test file")
        if path and test['selector']:
            content = path.read_text()
            selectors = (re.findall(r'public function (test_\w+)\(', content) if path.suffix == '.php'
                         else re.findall(r'''\btest\(\s*['"]([^'"]+)['"]''', content))
            require(test['selector'] in selectors, f"{test['id']}: missing test selector")
        expected_id = test['path'] + ('::' + test['selector'] if test['selector'] else '')
        require(test['id'] == expected_id, f"{test['id']}: noncanonical test ID")
        require(test['execution'] == 'not_run', 'Inventory is not execution evidence; attach receipts instead')
    seen = set()
    require({f['owner_issue'] for f in data['features']} == set(range(5, 12)), 'Every owner issue 5 through 11 is required')
    for feature in data['features']:
        fid = feature['id']
        require(fid not in seen, f'{fid}: duplicate feature')
        seen.add(fid)
        require(feature['state'] in STATES, f'{fid}: invalid state')
        require(feature['installed_state'] in {'unverified', 'installed', 'not-installed'}, f'{fid}: invalid installation state')
        require(set(feature['operations']) == OPERATIONS, f'{fid}: every operation must be explicit')
        for axis in ['screens', 'fields', 'operations']:
            require(bool(feature[axis]) and len(set(feature[axis])) == len(feature[axis]), f'{fid}: empty/duplicate {axis}')
        require(bool(feature['source_ids']) and all(s in data['sources'] for s in feature['source_ids']), f'{fid}: source required')
        require(all(t in test_ids for t in feature['test_ids']), f'{fid}: unknown test ID')
        require(all(f in fixtures for f in feature['fixture_ids']), f'{fid}: unknown fixture ID')
        require(all(file(p) for p in feature['implementation_paths']), f'{fid}: missing implementation path')
        require(bool(feature['limitation']), f'{fid}: explain state/limitations')
        if not feature['inventory_complete']:
            blockers.append(f'{fid}: field/property inventory incomplete')
        cells = {f'{fid}/{screen}/{field}/{op}' for screen, field, op in itertools.product(feature['screens'], feature['fields'], feature['operations'])}
        overrides = feature.get('cell_overrides', {})
        require(set(overrides).issubset(cells), f'{fid}: unknown cell override')
        for cell in sorted(cells):
            entry = overrides.get(cell, feature)
            state = entry['state']
            require(state in STATES, f'{cell}: invalid state')
            counts[state] += 1
            if state == 'implemented':
                require(bool(feature['implementation_paths']) and bool(feature['test_ids']) and bool(feature['fixture_ids']), f'{cell}: implementation/test/fixture required')
                require(feature['installed_state'] == 'installed', f'{cell}: installed dependency proof required')
                require(evidence(entry.get('evidence', []), cell), f'{cell}: passing exact-cell evidence required')
            else:
                blockers.append(f'{cell}: {state}')
    for section in ['release_matrix', 'improvements']:
        require(bool(data[section]), f'{section}: cannot omit acceptance dimensions')
        ids = set()
        for row in data[section]:
            require(row['id'] not in ids, f'{section}: duplicate ID')
            ids.add(row['id'])
            require(row['state'] in STATES, f"{row['id']}: invalid state")
            if row['state'] == 'implemented':
                require(evidence(row['evidence'], row['id']), f"{row['id']}: passing evidence required")
            else:
                blockers.append(f"{row['id']}: {row['state']}")
    required_dimensions = {'wordpress', 'php', 'integrations', 'roles', 'dataset', 'interaction', 'consumer', 'resilience'}
    require({r['dimension'] for r in data['release_matrix']} == required_dimensions, 'Missing release dimensions')
    required_improvements = {'bounded-queries', 'task-efficiency', 'audit-undo', 'write-preview', 'bulk-recovery', 'accessible-errors'}
    require({r['id'] for r in data['improvements']} == required_improvements, 'Missing improvement criteria')
    for improvement in data['improvements']:
        require(isinstance(improvement['threshold'], (int, float)) and bool(improvement['metric']) and bool(improvement['protocol']), 'Measurable improvement protocol required')
    if not data['release_cross_product_complete']:
        blockers.append('Release version/role/screen/field cross-product is incomplete')
    require(baseline['claim'] != 'complete' or not (errors or blockers), 'Blanket parity claim contradicts incomplete acceptance')
    return errors, blockers, dict(sorted(counts.items()))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--catalog', default='docs/acceptance/catalog.json')
    parser.add_argument('--coverage', action='store_true', help='Fail for missing/unverified product coverage')
    parser.add_argument('--details', action='store_true', help='List every blocking cell')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    try:
        errors, blockers, counts = validate(json.loads((root / args.catalog).read_text(), object_pairs_hook=strict_object), root)
    except (KeyError, TypeError, ValueError, OSError) as error:
        print(json.dumps({'catalog_valid': False, 'error': str(error)}))
        return 1
    print(json.dumps({'catalog_valid': not errors, 'product_coverage_complete': not errors and not blockers,
                      'cells': counts, 'blocker_count': len(blockers), 'errors': errors,
                      **({'blockers': blockers} if args.details else {})}, indent=2))
    return 1 if errors else 2 if args.coverage and blockers else 0


if __name__ == '__main__':
    raise SystemExit(main())
