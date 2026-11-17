#!/usr/bin/env python3
"""Enforce the 0.9.9 feature freeze and metadata-only 1.0.0-rc.1 commit.

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
    if version not in ('0.9.9','1.0.0-rc.1'):
        raise ValueError('Only 0.9.9 and approved 1.0.0-rc.1 may pass the final freeze')
    if release.get('upstream_version')!='1.3.0' or release.get('edition')!='forum':
        raise ValueError('Core/Edition identity changed during freeze')
    if (ROOT/'VERSION').read_text().strip()!='1.3.0':
        raise ValueError('Unexpected upstream Core release')
    channel='rc' if version=='1.0.0-rc.1' else 'stable'
    if release.get('channel') != channel:
        raise ValueError('Release channel and version must match (RC must not use stable channel)')
    ref='refs/tags/v0.9.9' if channel=='rc' else 'refs/tags/v0.9.8'
    cmd('rev-parse','--verify',ref+'^{commit}')
    changed=cmd('diff','--name-only','--no-renames',ref,'HEAD','--').splitlines()
    if not changed:
        raise ValueError('No reviewed release changes relative to baseline')
    if channel=='rc':
        allowed={'release.json','CHANGELOG.md','docs/forum-rc-final-audit.md'}
        forbidden=[name for name in changed if name not in allowed]
    else:
        protected=('migrations/','core/Forum/','public/forum-assets/','public/admin/',
                   'api/','admin/','templates/','core/Database/')
        forbidden=[name for name in changed if name.startswith(protected)]
    if forbidden:
        raise ValueError('Release freeze violated: '+', '.join(forbidden[:12]))
    if channel=='rc':
        # No new source, dependency or runtime behavior is allowed in the RC tag.
        stable=json.loads(cmd('show','refs/tags/v0.9.9:release.json'))
        for key in ('version','php','db','engine_api_version','upstream_version','edition'):
            if release.get(key)!=stable.get(key):
                raise ValueError('RC metadata changed protected release property '+key)
    print('PASS immutable feature freeze:',version,'baseline:',ref,'changed files:',len(changed))


if __name__=='__main__':
    try: check()
    except (KeyError,ValueError,OSError,subprocess.CalledProcessError,subprocess.TimeoutExpired) as e:
        print('FAIL release freeze:',e,file=sys.stderr)
        raise SystemExit(1)
