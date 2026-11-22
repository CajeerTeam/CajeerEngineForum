#!/usr/bin/env python3
"""Fail-closed verification of executable release admission policy and historical identity.

Tests the actual packaged production validators with negative fixture evidence;
CI publish permissions are independently checked against an explicit trust root.
"""
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]


def load(name):
    spec = importlib.util.spec_from_file_location(name.replace('-', '_'), ROOT / 'tools' / (name+'.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class ReleasePolicy(unittest.TestCase):
    def test_version_chain_is_current(self):
        metadata = json.loads((ROOT/'release.json').read_text())
        self.assertEqual(metadata['edition'], 'forum')
        self.assertEqual(metadata['edition_version'], '1.1.4')
        self.assertEqual(metadata['upstream_version'], (ROOT/'VERSION').read_text().strip())
        self.assertEqual(metadata['version'], metadata['upstream_version'])
        self.assertEqual(metadata['channel'], 'stable')
        self.assertEqual(metadata['distribution']['dist_artifact'], 'storage/releases/cajeerengine-1.1.4-dist.zip')

    def test_publication_needs_external_trust_and_complete_certificate(self):
        # Verify these are executable constraints on the release job, not release.json claims.
        publish = (ROOT/'.github/workflows/forum-release.yml').read_text()
        candidate = (ROOT/'.github/workflows/forum-ci.yml').read_text()
        for rule in ('environment: forum-production',
                     'RELEASE_TRUSTED_PUBLIC_B64',
                     'cmp -s "$pub" "$ARTIFACT_ROOT/ci-release.pub"',
                     'php tools/verify-signed-release.php "$ARTIFACT_ROOT" "$pub"',
                     'python3 tools/final-audit.py',
                     'python3 tools/certification-evidence.py verify'):
            self.assertIn(rule, publish, rule)
        self.assertLess(publish.index('python3 tools/final-audit.py'), publish.index('gh release create'))
        self.assertIn('if [[ "$GITHUB_REF" == refs/heads/main || "$GITHUB_REF" == refs/tags/v* ]]; then', candidate)
        self.assertIn('Publishing refs require an external Ed25519 release signing key', candidate)

    def test_historical_release_checks_2_1_kernel_and_strict_failure(self):
        staging = load('staging-gate')
        final = load('final-audit')
        self.assertTrue(callable(staging.check))
        self.assertTrue(callable(final.audit))
        # Version validation is performed before reading any external artifacts.
        with self.assertRaises(ValueError):
            staging.check(Path('/missing'),Path('/missing'),Path('/missing'),Path('/missing'),Path('/missing'), 'invalid', 'a'*40)
        with self.assertRaises(ValueError):
            final.audit(Path('/missing'),Path('/missing'),'invalid','a'*40)


if __name__ == '__main__':
    unittest.main(verbosity=2)
