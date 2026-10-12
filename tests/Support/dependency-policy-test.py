"""Exercise real approved audit samples and fail-closed boundary mutations."""
import copy
from datetime import datetime, timedelta, timezone
import json
from pathlib import Path
import runpy
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
POLICY = runpy.run_path(str(ROOT / 'scripts/dependency-audit-policy'))
NOW = datetime(2026, 10, 12, 18, tzinfo=timezone.utc)
FIXTURE = ROOT / 'tests/Support/fixtures/gt017-approved-audit.json'


class DependencyPolicyTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.evidence = self.root / 'evidence'
        self.evidence.mkdir()
        (self.root / 'docs/security').mkdir(parents=True)
        for name in ['pnpm-lock.yaml', 'docs/security/GT-017_DEPENDENCY_PATHS.json']:
            (self.root / name).write_bytes((ROOT / name).read_bytes())
        self.report = json.loads(FIXTURE.read_text())
        self.results = {name: {'result': 'success'} for name in POLICY['REQUIRED']}
        self.status = {
            'command': 'pnpm audit --json', 'exit_code': 1,
            'lockfile_sha256': POLICY['LOCK_HASH'], 'paths_sha256': POLICY['PATHS_HASH'],
            'head': 'tested-head', 'run_id': 'test-run', 'collected_at': NOW.isoformat(),
        }
        self.write_evidence()

    def write_evidence(self):
        raw = json.dumps(self.report).encode()
        (self.evidence / 'audit.json').write_bytes(raw)
        (self.evidence / 'audit-stderr.txt').write_bytes(b'')
        self.status['audit_sha256'] = POLICY['digest'](raw)
        (self.evidence / 'audit-status.json').write_text(json.dumps(self.status))

    def check(self, scope='ci', now=NOW):
        return POLICY['check'](self.evidence, self.root, scope, now, self.results, 'tested-head', 'test-run')

    def first(self):
        return next(iter(self.report['advisories'].values()))

    def test_exact_approved_ci_and_private_local(self):
        self.assertTrue(self.check())
        self.assertTrue(self.check(scope='private-local-web'))

    def test_unapproved_scopes(self):
        for scope in ['unapproved', '', 'production', 'public-staging', 'native', 'local-commit']:
            with self.subTest(scope=scope):
                self.assertFalse(self.check(scope=scope))

    def test_expiry_inclusive_day_and_future_approval(self):
        self.status['collected_at'] = '2026-10-26T23:00:00+00:00'
        self.write_evidence()
        self.assertTrue(self.check(now=datetime(2026, 10, 26, 23, 59, 59, tzinfo=timezone.utc)))
        self.assertFalse(self.check(now=POLICY['EXPIRES']))
        self.assertFalse(self.check(now=POLICY['APPROVED_FROM'] - timedelta(seconds=1)))

    def test_every_category_required(self):
        for category in list(self.results):
            for result in ['failure', 'skipped', 'cancelled', 'pending', None, True]:
                with self.subTest(category=category, result=result):
                    self.results[category]['result'] = result
                    self.assertFalse(self.check())
            self.results[category]['result'] = 'success'
        self.results.pop('native')
        self.assertFalse(self.check())

    def test_malformed_and_extra_category(self):
        for results in [None, [], {}, {'extra': {'result': 'success'}},
                        {**self.results, 'new-check': {'result': 'success'}},
                        {**self.results, 'backend': 'success'}]:
            self.results = results
            self.assertFalse(self.check())

    def test_missing_each_evidence_file(self):
        for name in ['audit.json', 'audit-status.json', 'audit-stderr.txt']:
            with self.subTest(name=name):
                path = self.evidence / name
                data = path.read_bytes()
                path.unlink()
                self.assertFalse(self.check())
                path.write_bytes(data)

    def test_malformed_json_and_duplicate_keys(self):
        for name in ['audit.json', 'audit-status.json']:
            for value in [b'{', b'null', b'[]', b'{"exit_code":1,"exit_code":0}', b'NaN']:
                with self.subTest(name=name, value=value):
                    (self.evidence / name).write_bytes(value)
                    self.assertFalse(self.check())
                    self.write_evidence()
        with self.assertRaises(ValueError):
            POLICY['strict_json']('{"metadata":{},"metadata":{}}')

    def test_registry_error_even_with_findings(self):
        self.report['error'] = {'code': 'ECONNRESET'}
        self.write_evidence()
        self.assertFalse(self.check())
        del self.report['error']
        self.write_evidence()
        (self.evidence / 'audit-stderr.txt').write_text('registry unavailable')
        self.assertFalse(self.check())

    def test_unsuccessful_or_inconsistent_status(self):
        for status in [0, 2, 125, -15, '1', None, True]:
            self.status['exit_code'] = status
            self.write_evidence()
            self.assertFalse(self.check())

    def test_additional_advisory(self):
        self.report['advisories']['999'] = {**self.first(), 'id': 999, 'github_advisory_id': 'GHSA-new'}
        self.write_evidence()
        self.assertFalse(self.check())

    def test_replaced_or_duplicate_advisory(self):
        self.first()['github_advisory_id'] = 'GHSA-new'
        self.write_evidence()
        self.assertFalse(self.check())
        self.first()['github_advisory_id'] = 'GHSA-vfj7-8cjw-p6xm'
        self.write_evidence()
        self.assertFalse(self.check())

    def test_missing_advisory(self):
        self.report['advisories'].pop(next(iter(self.report['advisories'])))
        self.write_evidence()
        self.assertFalse(self.check())

    def test_changed_version_and_extra_version(self):
        finding = self.first()['findings'][0]
        finding['version'] = '1.4.1'
        self.write_evidence()
        self.assertFalse(self.check())
        self.first()['findings'].append(copy.deepcopy(finding))
        finding['version'] = '1.4.0'
        self.write_evidence()
        self.assertFalse(self.check())

    def test_changed_audit_paths(self):
        paths = self.first()['findings'][0]['paths']
        for path in ['.>node-forge', 'apps__mobile>new-parent>node-forge',
                     'apps__mobile>expo>@expo/cli>sprintf-js', '', None]:
            with self.subTest(path=path):
                original = paths[0]
                paths[0] = path
                self.write_evidence()
                self.assertFalse(self.check())
                paths[0] = original

    def test_reordered_capped_samples_still_follow_exact_graph(self):
        for advisory in self.report['advisories'].values():
            advisory['findings'][0]['paths'].reverse()
        self.write_evidence()
        self.assertTrue(self.check())

    def test_changed_lockfile_and_appendix(self):
        for name in ['pnpm-lock.yaml', 'docs/security/GT-017_DEPENDENCY_PATHS.json']:
            path = self.root / name
            original = path.read_bytes()
            path.write_bytes(original + b'\n')
            self.assertFalse(self.check())
            path.write_bytes(original)
        appendix = self.root / 'docs/security/GT-017_DEPENDENCY_PATHS.json'
        graph = json.loads(appendix.read_text())
        graph['graphs']['GT017']['advisories']['node-forge@1.4.0'].pop()
        appendix.write_text(json.dumps(graph))
        self.assertFalse(self.check())

    def test_changed_source_run_command_or_hash(self):
        for field in ['head', 'run_id', 'command', 'lockfile_sha256', 'paths_sha256', 'audit_sha256']:
            with self.subTest(field=field):
                original = self.status[field]
                self.status[field] = 'different'
                (self.evidence / 'audit-status.json').write_text(json.dumps(self.status))
                self.assertFalse(self.check())
                self.status[field] = original

    def test_stale_future_or_invalid_collection_time(self):
        for value in [(NOW - timedelta(hours=25)).isoformat(), (NOW + timedelta(seconds=1)).isoformat(),
                      '2026-10-12', 'invalid', None]:
            self.status['collected_at'] = value
            self.write_evidence()
            self.assertFalse(self.check())

    def test_upstream_fix_or_changed_affected_range_requires_review(self):
        for field, value in [('patched_versions', '>=1.4.1'), ('patched_versions_unpublished', False),
                             ('vulnerable_versions', '<1.4.0'), ('severity', 'critical')]:
            advisory = self.first()
            original = advisory[field]
            advisory[field] = value
            self.write_evidence()
            self.assertFalse(self.check())
            advisory[field] = original

    def test_malformed_findings(self):
        for findings in [None, [], {}, [None], [{'version': '1.4.0'}]]:
            self.first()['findings'] = findings
            self.write_evidence()
            self.assertFalse(self.check())

    def test_missing_empty_or_nonlist_paths(self):
        for paths in [None, [], {}, 'apps__mobile>expo>@expo/cli>node-forge']:
            self.first()['findings'][0]['paths'] = paths
            self.write_evidence()
            self.assertFalse(self.check())

    def test_malformed_counts(self):
        for counts in [{}, None, {**POLICY['COUNTS'], 'high': True}, {**POLICY['COUNTS'], 'high': 3},
                       {**POLICY['COUNTS'], 'critical': '0'}]:
            self.report['metadata']['vulnerabilities'] = counts
            self.write_evidence()
            self.assertFalse(self.check())

    def test_malformed_metadata(self):
        for metadata in [None, {}, {'vulnerabilities': POLICY['COUNTS']},
                         {**self.report['metadata'], 'totalDependencies': '926'}]:
            self.report['metadata'] = metadata
            self.write_evidence()
            self.assertFalse(self.check())

    def test_raw_evidence_tampering(self):
        with (self.evidence / 'audit.json').open('ab') as output:
            output.write(b' ')
        self.assertFalse(self.check())

    def test_clean_or_changed_report_requires_reassessment(self):
        self.report['advisories'] = {}
        self.report['metadata']['vulnerabilities'] = dict.fromkeys(POLICY['COUNTS'], 0)
        self.status['exit_code'] = 0
        self.write_evidence()
        self.assertFalse(self.check())


if __name__ == '__main__':
    unittest.main()
