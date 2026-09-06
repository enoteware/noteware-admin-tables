# SPDX-License-Identifier: GPL-2.0-or-later
"""Acceptance gate attack cases. No WordPress or database is required."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest
import subprocess
import sys
import tempfile
from datetime import date

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('gate', ROOT / 'scripts/check-acceptance.py')
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)


class AcceptanceGateTest(unittest.TestCase):
    def setUp(self):
        self.catalog = json.loads((ROOT / 'docs/acceptance/catalog.json').read_text())

    def test_catalog_is_valid_but_coverage_is_not_complete(self):
        errors, blockers, counts = gate.validate(self.catalog, ROOT)
        self.assertEqual([], errors)
        self.assertTrue(blockers)
        self.assertGreater(counts['missing'], 0)
        self.assertEqual(0, counts['implemented'])

    def test_blanket_claim_is_rejected(self):
        self.catalog['baseline']['claim'] = 'complete'
        self.assertTrue(gate.validate(self.catalog, ROOT)[0])

    def test_invented_test_selector_is_rejected(self):
        self.catalog['test_inventory'][0]['selector'] = 'imaginary-test'
        self.assertTrue(gate.validate(self.catalog, ROOT)[0])

    def test_missing_operations_cannot_shrink_denominator(self):
        self.catalog['features'][0]['operations'].remove('export')
        self.assertTrue(gate.validate(self.catalog, ROOT)[0])

    def test_unsupported_cell_cannot_be_passed_without_evidence(self):
        self.catalog['features'][0]['state'] = 'implemented'
        self.assertTrue(gate.validate(self.catalog, ROOT)[0])

    def test_duplicate_feature_id_is_rejected(self):
        self.catalog['features'].append(copy.deepcopy(self.catalog['features'][0]))
        self.assertTrue(gate.validate(self.catalog, ROOT)[0])

    def test_no_missing_owner_family(self):
        self.catalog['features'] = [f for f in self.catalog['features'] if f['owner_issue'] != 11]
        self.assertTrue(gate.validate(self.catalog, ROOT)[0])

    def test_test_existence_does_not_count_as_execution(self):
        _, _, before = gate.validate(self.catalog, ROOT)
        self.catalog['features'][0]['test_ids'] = [t['id'] for t in self.catalog['test_inventory']]
        _, _, after = gate.validate(self.catalog, ROOT)
        self.assertEqual(before, after)

    def test_path_traversal_is_rejected(self):
        self.catalog['fixtures'][0]['path'] = '../outside'
        self.assertTrue(gate.validate(self.catalog, ROOT)[0])

    def test_duplicate_json_keys_are_rejected(self):
        with self.assertRaisesRegex(ValueError, 'Duplicate JSON key: state'):
            json.loads('{"state":"missing","state":"implemented"}', object_pairs_hook=gate.strict_object)

    def receipt_fixture(self):
        """Synthetic unit-only receipt; never retained as product pass evidence."""
        feature = self.catalog['features'][0]
        subject = '/'.join([feature['id'], feature['screens'][0], feature['fields'][0], feature['operations'][0]])
        directory = tempfile.TemporaryDirectory(dir=ROOT / 'tests' / 'artifacts')
        self.addCleanup(directory.cleanup)
        output = Path(directory.name) / 'output.txt'
        output.write_text('Synthetic validator test output, not a product test run.\n')
        receipt = Path(directory.name) / 'receipt.json'
        record = {
            'result': 'pass',
            'revision': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
            'subjects': [subject],
            'command': 'synthetic validator test', 'environment': {'synthetic': True}, 'reviewer': 'unit-test',
            'test_ids': list(feature['test_ids']),
            'fixture_ids': list(feature['fixture_ids']),
            'output_path': str(output.relative_to(ROOT)), 'date': date.today().isoformat(),
        }
        feature['installed_state'] = 'installed'
        feature.setdefault('cell_overrides', {})[subject] = {
            'state': 'implemented', 'evidence': [str(receipt.relative_to(ROOT))]}
        return feature, subject, record, receipt

    def test_valid_feature_receipt_is_accepted_without_promoting_other_cells(self):
        _, _, before = gate.validate(self.catalog, ROOT)
        _, subject, record, receipt = self.receipt_fixture()
        receipt.write_text(json.dumps(record))
        errors, blockers, counts = gate.validate(self.catalog, ROOT)
        self.assertEqual([], errors)
        self.assertEqual(1, counts['implemented'])
        self.assertEqual(sum(before.values()), sum(counts.values()))
        self.assertNotIn(f'{subject}: unverified', blockers)
        self.assertTrue(blockers)

    def test_receipt_subjects_must_be_a_list(self):
        _, subject, record, receipt = self.receipt_fixture()
        record['subjects'] = subject
        receipt.write_text(json.dumps(record))
        self.assertIn(f'{subject}: invalid/stale evidence receipt', gate.validate(self.catalog, ROOT)[0])

    def test_receipt_metadata_requires_nonblank_strings_and_nonempty_object(self):
        _, subject, record, receipt = self.receipt_fixture()
        invalid_values = {
            'command': [True, 1, ['command'], {'command': 'run'}, '', ' \t\n', None],
            'reviewer': [True, 1, ['reviewer'], {'name': 'reviewer'}, '', ' \t\n', None],
            'environment': [True, 1, 'environment', ['environment'], {}, None],
        }
        for field, values in invalid_values.items():
            for value in values:
                with self.subTest(field=field, value=value):
                    altered = dict(record, **{field: value})
                    receipt.write_text(json.dumps(altered))
                    self.assertIn(f'{subject}: invalid/stale evidence receipt', gate.validate(self.catalog, ROOT)[0])

    def test_globally_known_test_from_another_feature_cannot_prove_this_cell(self):
        feature, subject, record, receipt = self.receipt_fixture()
        unrelated = next(test['id'] for test in self.catalog['test_inventory']
                         if test['id'] not in feature['test_ids'])
        record['test_ids'] = [unrelated]
        receipt.write_text(json.dumps(record))
        self.assertIn(f'{subject}: invalid/stale evidence receipt', gate.validate(self.catalog, ROOT)[0])

    def test_globally_known_fixture_from_another_feature_cannot_prove_this_cell(self):
        _, subject, record, receipt = self.receipt_fixture()
        unrelated = dict(self.catalog['fixtures'][0], id='synthetic-unrelated-fixture')
        self.catalog['fixtures'].append(unrelated)
        record['fixture_ids'] = [unrelated['id']]
        receipt.write_text(json.dumps(record))
        self.assertIn(f'{subject}: invalid/stale evidence receipt', gate.validate(self.catalog, ROOT)[0])

    def test_release_and_improvement_receipts_keep_global_inventory_scope(self):
        for section in ['release_matrix', 'improvements']:
            with self.subTest(section=section):
                self.catalog = json.loads((ROOT / 'docs/acceptance/catalog.json').read_text())
                feature, subject, record, receipt = self.receipt_fixture()
                # Only the release/improvement row is promoted in this scenario.
                del feature['cell_overrides'][subject]
                record['test_ids'] = [next(test['id'] for test in self.catalog['test_inventory']
                                          if test['id'] not in feature['test_ids'])]
                row = self.catalog[section][0]
                record['subjects'] = [row['id']]
                row['state'] = 'implemented'
                row['evidence'] = [str(receipt.relative_to(ROOT))]
                receipt.write_text(json.dumps(record))
                errors, blockers, _ = gate.validate(self.catalog, ROOT)
                self.assertEqual([], errors)
                self.assertNotIn(f"{row['id']}: unverified", blockers)
                record['test_ids'] = ['unknown-synthetic-test']
                receipt.write_text(json.dumps(record))
                self.assertIn(f"{row['id']}: invalid/stale evidence receipt", gate.validate(self.catalog, ROOT)[0])

    def test_non_object_catalog_roots_return_structured_cli_errors(self):
        with tempfile.TemporaryDirectory() as directory:
            catalog = Path(directory) / 'catalog.json'
            for value in [[], None, True, 42, 'catalog']:
                with self.subTest(value=value):
                    catalog.write_text(json.dumps(value))
                    result = subprocess.run([sys.executable, str(ROOT / 'scripts/check-acceptance.py'),
                                             '--catalog', str(catalog)], cwd=ROOT, capture_output=True, text=True)
                    self.assertEqual(1, result.returncode)
                    self.assertEqual('', result.stderr)
                    self.assertFalse(json.loads(result.stdout)['catalog_valid'])

    def test_pending_release_cross_product_blocks_parity(self):
        self.assertIn('Release version/role/screen/field cross-product is incomplete', gate.validate(self.catalog, ROOT)[1])


if __name__ == '__main__':
    unittest.main()
