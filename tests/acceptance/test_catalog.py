# SPDX-License-Identifier: GPL-2.0-or-later
"""Acceptance gate attack cases. No WordPress or database is required."""
import copy
import importlib.util
import json
from pathlib import Path
import unittest
import subprocess
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

    def test_receipt_subjects_must_be_a_list(self):
        feature = self.catalog['features'][0]
        subject = '/'.join([feature['id'], feature['screens'][0], feature['fields'][0], feature['operations'][0]])
        record = {
            'result': 'pass',
            'revision': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
            'subjects': subject,
            'command': 'synthetic validator test', 'environment': {'test': True}, 'reviewer': 'unit-test',
            'test_ids': [self.catalog['test_inventory'][0]['id']],
            'fixture_ids': [self.catalog['fixtures'][0]['id']],
            'output_path': self.catalog['test_inventory'][0]['path'], 'date': date.today().isoformat(),
        }
        with tempfile.TemporaryDirectory(dir=ROOT / 'tests' / 'artifacts') as directory:
            receipt = Path(directory) / 'receipt.json'
            receipt.write_text(json.dumps(record))
            feature['cell_overrides'] = {subject: {'state': 'implemented', 'evidence': [str(receipt.relative_to(ROOT))]}}
            errors = gate.validate(self.catalog, ROOT)[0]
            self.assertIn(f'{subject}: invalid/stale evidence receipt', errors)

    def test_pending_release_cross_product_blocks_parity(self):
        self.assertIn('Release version/role/screen/field cross-product is incomplete', gate.validate(self.catalog, ROOT)[1])


if __name__ == '__main__':
    unittest.main()
