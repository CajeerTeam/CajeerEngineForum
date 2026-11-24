#!/usr/bin/env python3
"""Verify release identity and authentic chronological baseline for tagged builds.

Historical 1.0.0 freeze policy is not an appropriate allowlist for later maintenance
releases: enforcing it would prohibit the actual PHP bug fixes in 1.0.2.
"""
import json
import os
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent


def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args], text=True, stderr=subprocess.DEVNULL, timeout=30).strip()


def check():
    release = json.loads((ROOT / 'release.json').read_text())
    current = release['edition_version']
    upstream = (ROOT / 'VERSION').read_text().strip()
    if release.get('edition') != 'forum' or release.get('upstream_version') != upstream or release.get('version') != upstream:
        raise ValueError('Forum/engine version identity mismatch')
    if release.get('channel') != ('rc' if '-' in current else 'stable'):
        raise ValueError('Incorrect release channel')
    if current == '1.5.4':
        baseline = 'refs/tags/v1.5.3'
    elif current == '1.5.3':
        baseline = 'refs/tags/v1.5.2'
    elif current == '1.5.2':
        baseline = 'refs/tags/v1.5.1'
    elif current == '1.5.1':
        baseline = 'refs/tags/v1.5.0'
    elif current == '1.5.0':
        baseline = 'refs/tags/v1.4.11'
    elif current == '1.4.11':
        baseline = 'refs/tags/v1.4.10'
    elif current == '1.4.10':
        baseline = 'refs/tags/v1.4.9'
    elif current == '1.4.9':
        baseline = 'refs/tags/v1.4.8'
    elif current == '1.4.8':
        baseline = 'refs/tags/v1.4.7'
    elif current == '1.4.7':
        baseline = 'refs/tags/v1.4.6'
    elif current == '1.4.6':
        baseline = 'refs/tags/v1.4.5'
    elif current == '1.4.5':
        baseline = 'refs/tags/v1.4.4'
    elif current == '1.4.4':
        baseline = 'refs/tags/v1.4.3'
    elif current == '1.4.3':
        baseline = 'refs/tags/v1.4.2'
    elif current == '1.4.2':
        baseline = 'refs/tags/v1.4.1'
    elif current == '1.4.1':
        baseline = 'refs/tags/v1.4.0'
    elif current in ('1.4.0', '1.3.11', '1.3.10', '1.3.9', '1.3.8', '1.3.7'):
        baseline = 'refs/tags/v' + ('1.3.11' if current == '1.4.0' else '1.3.' + str(int(current.split('.')[-1])-1))
    elif current == '1.3.6':
        baseline = 'refs/tags/v1.3.5'
    elif current == '1.3.5':
        baseline = 'refs/tags/v1.3.4'
    elif current == '1.3.4':
        baseline = 'refs/tags/v1.3.3'
    elif current == '1.3.3':
        baseline = 'refs/tags/v1.3.2'
    elif current == '1.3.2':
        baseline = 'refs/tags/v1.3.1'
    elif current == '1.3.1':
        baseline = 'refs/tags/v1.3.0'
    elif current == '1.3.0':
        baseline = 'refs/tags/v1.2.11'
    elif current == '1.2.11':
        baseline = 'refs/tags/v1.2.10'
    elif current == '1.2.10':
        baseline = 'refs/tags/v1.2.9'
    elif current == '1.2.9':
        baseline = 'refs/tags/v1.2.8'
    elif current == '1.2.8':
        baseline = 'refs/tags/v1.2.7'
    elif current == '1.2.7':
        baseline = 'refs/tags/v1.2.6'
    elif current == '1.2.6':
        baseline = 'refs/tags/v1.2.5'
    elif current == '1.2.5':
        baseline = 'refs/tags/v1.2.4'
    elif current == '1.2.4':
        baseline = 'refs/tags/v1.2.3'
    elif current == '1.2.3':
        baseline = 'refs/tags/v1.2.2'
    elif current == '1.2.2':
        baseline = 'refs/tags/v1.2.1'
    elif current == '1.2.1':
        baseline = 'refs/tags/v1.2.0'
    elif current == '1.2.0':
        baseline = 'refs/tags/v1.1.11'
    elif current == '1.1.11':
        baseline = 'refs/tags/v1.1.10'
    elif current == '1.1.10':
        baseline = 'refs/tags/v1.1.9'
    elif current == '1.1.9':
        baseline = 'refs/tags/v1.1.8'
    elif current == '1.1.8':
        baseline = 'refs/tags/v1.1.7'
    elif current == '1.1.7':
        baseline = 'refs/tags/v1.1.6'
    elif current == '1.1.6':
        baseline = 'refs/tags/v1.1.5'
    elif current == '1.1.5':
        baseline = 'refs/tags/v1.1.4'
    elif current == '1.1.4':
        baseline = 'refs/tags/v1.1.3'
    elif current == '1.1.3':
        baseline = 'refs/tags/v1.1.2'
    elif current == '1.1.2':
        baseline = 'refs/tags/v1.1.1'
    elif current == '1.1.1':
        baseline = 'refs/tags/v1.1.0'
    elif current == '1.1.0':
        baseline = 'refs/tags/v1.0.9'
    elif current == '1.0.9':
        baseline = 'refs/tags/v1.0.8'
    elif current == '1.0.8':
        baseline = 'refs/tags/v1.0.7'
    elif current == '1.0.7':
        baseline = 'refs/tags/v1.0.6'
    elif current == '1.0.6':
        baseline = 'refs/tags/v1.0.5'
    elif current == '1.0.5':
        baseline = 'refs/tags/v1.0.4'
    elif current == '1.0.4':
        baseline = 'refs/tags/v1.0.3'
    elif current == '1.0.3':
        baseline = 'refs/tags/v1.0.2'
    elif current == '1.0.2':
        baseline = 'refs/tags/v1.0.1'
    elif current in ('0.9.9', '1.0.0-rc.1', '1.0.0'):
        baseline = {'0.9.9': 'refs/tags/v0.9.8', '1.0.0-rc.1': 'refs/tags/v0.9.9', '1.0.0': 'refs/tags/v1.0.0-rc.1'}[current]
    else:
        raise ValueError('Unsupported Forum Edition release for freeze validation')
    tagged = os.getenv('GITHUB_REF', '').startswith('refs/tags/')
    if tagged and os.environ['GITHUB_REF'] != 'refs/tags/v' + current:
        raise ValueError('Tag and release.json version mismatch')
    try:
        git('rev-parse', '--verify', baseline + '^{commit}')
    except (subprocess.CalledProcessError, OSError):
        if tagged or (os.getenv('GITHUB_EVENT_NAME') == 'push' and os.getenv('GITHUB_REF') == 'refs/heads/main'):
            raise ValueError('Publishing source requires an authentic historical baseline tag: ' + baseline)
        print('PASS development source identity; historical tag admission deferred:', baseline)
        return
    if current in ('1.5.0','1.5.1','1.5.2','1.5.3','1.5.4'):
        expected = {'1.5.4':'1.5.3','1.5.3':'1.5.2','1.5.2':'1.5.1','1.5.1':'1.5.0','1.5.0':'1.4.11'}[current]
        historic = json.loads(git('show', baseline+':release.json'))
        if historic.get('edition') != 'forum' or historic.get('edition_version') != expected:
            raise ValueError('Historical baseline tag does not identify Forum '+expected)
        git('merge-base','--is-ancestor',baseline,'HEAD')
    modified = git('diff', '--name-only', '--no-renames', baseline, 'HEAD', '--').splitlines()
    if not modified:
        raise ValueError('No changes relative to historical tag')
    if current in ('0.9.9', '1.0.0-rc.1', '1.0.0'):
        # Final 1.0.0 patch was metadata-only; preserve historical freeze semantics.
        allowed = {'release.json', 'CHANGELOG.md', 'README.md', '.github/workflows/forum-ci.yml',
                   '.github/workflows/forum-long-qualification.yml', '.github/workflows/forum-chaos-update.yml'}
        if current in ('1.0.0-rc.1', '1.0.0') and any(not (f in allowed or f.startswith('docs/') or f.startswith('tools/')) for f in modified):
            raise ValueError('1.0.0 freeze violated')
    print('PASS historical release baseline:', baseline, 'changed paths:', len(modified))


if __name__ == '__main__':
    try:
        check()
    except (KeyError, ValueError, OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired) as exc:
        print('FAIL release freeze:', exc, file=sys.stderr)
        raise SystemExit(1)
