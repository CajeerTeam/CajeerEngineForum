#!/usr/bin/env python3
"""Ensure the real publisher fails closed before ever contacting GitHub on bad inputs."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent
VERSION = json.loads((ROOT / 'release.json').read_text())['edition_version']
SCRIPT = ROOT / 'tools/github-publish-release.sh'


class PublishAdmissionTests(unittest.TestCase):
    def test_unsafe_input_never_calls_remote_publisher(self):
        with tempfile.TemporaryDirectory(prefix='forum-publish-gate-') as temp:
            root = Path(temp)
            release = root / 'release'
            release.mkdir()
            calls = root / 'remote-called'
            gh = root / 'gh'
            gh.write_text('#!/bin/sh\ntouch "$PUBLISH_TEST_REMOTE_CALLED"\nexit 99\n')
            gh.chmod(0o700)
            git = root / 'git'
            git.write_text('#!/bin/sh\ncase "$*" in *rev-parse*) printf "' + 'a'*40 + '\\n";; *rev-list*) printf "${PUBLISH_TEST_TAG_COMMIT}\\n";; esac\n')
            git.chmod(0o700)
            key = root / 'trusted.pub'
            key.write_text('operator-key-not-from-release')
            base = dict(os.environ, PATH=f'{root}:{os.environ["PATH"]}',
                        PUBLISH_TEST_REMOTE_CALLED=str(calls), PUBLISH_TEST_TAG_COMMIT='a'*40)

            def refuse(name, *, tag='v'+VERSION, repo='CajeerTeam/CajeerEngineForum', trusted=True, tag_commit=None):
                env = dict(base)
                if trusted:
                    env['RELEASE_TRUSTED_PUBLIC_KEY_FILE'] = str(key)
                else:
                    env.pop('RELEASE_TRUSTED_PUBLIC_KEY_FILE', None)
                if tag_commit:
                    env['PUBLISH_TEST_TAG_COMMIT'] = tag_commit
                result = subprocess.run(['bash', str(SCRIPT), str(release), tag, repo], env=env,
                                        capture_output=True, text=True)
                self.assertNotEqual(result.returncode, 0, f'{name}: publisher accepted invalid state')
                self.assertFalse(calls.exists(), f'{name}: publisher contacted remote before checking evidence')

            refuse('wrong-tag', tag='v0.0.0')
            refuse('wrong-repo', repo='somebody/repository')
            refuse('no-trusted-key', trusted=False)
            refuse('tag-points-elsewhere', tag_commit='b'*40)
            refuse('no-certification')
            (release / 'certification.json').write_text('{"ok":true}')
            refuse('fabricated-certificate-without-signed-inputs')


if __name__ == '__main__':
    unittest.main(verbosity=2)
