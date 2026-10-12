"""Security gate regression controls; no waiver mechanism or ignored advisory."""
import copy
import runpy
import unittest
from pathlib import Path

evaluate = runpy.run_path(str(Path(__file__).resolve().parents[2] / 'scripts/dependency-audit-policy'))['evaluate']


class DependencyPolicyTest(unittest.TestCase):
    def setUp(self):
        self.clean = {'metadata': {'vulnerabilities': dict.fromkeys(['info', 'low', 'moderate', 'high', 'critical'], 0)}, 'advisories': {}}

    def test_clean_success(self):
        self.assertTrue(evaluate(self.clean, 0))

    def test_findings_never_inherit_local_approval(self):
        for finding in ['unapproved', 'expired', 'changed-version', 'changed-path', 'additional-advisory']:
            with self.subTest(finding=finding):
                report = copy.deepcopy(self.clean)
                report['metadata']['vulnerabilities']['high'] = 1
                report['advisories'] = {finding: {'module_name': 'node-forge'}}
                self.assertFalse(evaluate(report, 1))
                self.assertFalse(evaluate(report, 0))

    def test_registry_error_and_unavailable_evidence(self):
        for status in [1, 125, -15, '0', None, False]:
            self.assertFalse(evaluate(self.clean, status))
        for report in [{}, None, [], {'error': 'registry unavailable'}, {'metadata': {'vulnerabilities': {}}}]:
            self.assertFalse(evaluate(report, 0))

    def test_inconsistent_or_malformed_success(self):
        for value in [-1, '0', False, None]:
            report = copy.deepcopy(self.clean)
            report['metadata']['vulnerabilities']['high'] = value
            self.assertFalse(evaluate(report, 0))
        report = copy.deepcopy(self.clean)
        report['error'] = 'Registry failure'
        self.assertFalse(evaluate(report, 0))


if __name__ == '__main__':
    unittest.main()
