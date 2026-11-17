#!/usr/bin/env python3
"""Enforce final 0.9.9 / RC / 1.0.0 source freeze using immutable release tags.

Only a Git working tree with authentic, immutable historical tags is accepted.
Used by mandatory release-candidate CI before any release package is built.
"""
import json
from pathlib import Path
import subprocess
import sys

ROOT=Path(__file__).resolve().parent.parent


def cmd(*args):
    return subprocess.check_output(['git','-C',str(ROOT),*args],text=True,timeout=30).strip()


def check():
    release=json.loads((ROOT/'release.json').read_text())
    version=release['edition_version']
    if version not in ('0.9.9','1.0.0-rc.1','1.0.0'):
        raise ValueError('Unsupported edition for the final feature freeze')
    if release.get('upstream_version')!='1.3.0' or release.get('edition')!='forum':
        raise ValueError('Core/Edition identity changed during freeze')
    if (ROOT/'VERSION').read_text().strip()!='1.3.0':
        raise ValueError('Unexpected upstream Core release')
    channel='rc' if version=='1.0.0-rc.1' else 'stable'
    if release.get('channel') != channel:
        raise ValueError('Release channel and version must match (RC must not use stable channel)')
    ref='refs/tags/v1.0.0-rc.1' if version=='1.0.0' else ('refs/tags/v0.9.9' if channel=='rc' else 'refs/tags/v0.9.8')
    cmd('rev-parse','--verify',ref+'^{commit}')
    changed=cmd('diff','--name-only','--no-renames',ref,'HEAD','--').splitlines()
    if not changed:
        raise ValueError('No reviewed release changes relative to baseline')
    if channel=='rc' or version=='1.0.0':
        allowed={'release.json','CHANGELOG.md','README.md','docs/forum-rc-final-audit.md','docs/forum-1.0-production-operations.md','docs/forum-1.0-upgrade.md','docs/forum-1.0-compatibility.md', '.github/workflows/forum-ci.yml', '.github/workflows/forum-long-qualification.yml', '.github/workflows/forum-chaos-update.yml', 'tools/final-audit.py', 'tools/final-audit-regression.py', 'tools/staging-gate.py', 'tools/release-freeze.py', 'tools/stable-certification.py', 'tools/stable-certification-regression.py', 'tools/github-publish-release.sh', 'tools/github-publish-regression.py', 'tools/certification-regression.py', 'tools/forum-tests/test_upgrade_098.py', 'tools/forum-tests/release_acceptance.py', 'deploy/container/chaos_update.py', 'deploy/container/certify_load.py', 'core/Release/ReleaseBuilder.php', 'core/Release/ReleaseArtifactVerifier.php', 'core/Release/ReleaseFilePolicy.php'}
        forbidden=[name for name in changed if name not in allowed]
    else:
        protected=('migrations/','core/Forum/','public/forum-assets/','public/admin/',
                   'api/','admin/','templates/','core/Database/')
        forbidden=[name for name in changed if name.startswith(protected)]
    if forbidden:
        raise ValueError('Release freeze violated: '+', '.join(forbidden[:12]))
    if channel=='rc' or version=='1.0.0':
        # No new forum features, schema changes, or dependency changes in RC or stable.
        stable=json.loads(cmd('show',ref+':release.json'))
        for key in ('version','php','db','engine_api_version','upstream_version','edition'):
            if release.get(key)!=stable.get(key):
                raise ValueError('RC metadata changed protected release property '+key)
    print('PASS immutable feature freeze:',version,'baseline:',ref,'changed files:',len(changed))


if __name__=='__main__':
    try: check()
    except (KeyError,ValueError,OSError,subprocess.CalledProcessError,subprocess.TimeoutExpired) as e:
        print('FAIL release freeze:',e,file=sys.stderr)
        raise SystemExit(1)
