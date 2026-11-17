#!/usr/bin/env python3
"""SIGKILL a real signed Compose update at a fsynced deployment checkpoint.

Operator pre-provisions disposable OLD (0.9.8) and signed NEW (0.9.9), with
working update.py artifact, trust anchor, CA, and empty private state-dir.
This program never performs destructive rollback; it invokes normal recovery.
"""
import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

HERE=Path(__file__).resolve().parent


def require(ok, message):
    if not ok: raise RuntimeError(message)


def verify_fixture(args):
    require(args.disposable=='destroy-test-services', 'Explicit destructive test confirmation required')
    require(re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,62}',args.project) is not None and
            re.search(r'(^|[-_])chaos([-_]|$)', args.project) is not None,
            'Use an isolated Compose project with a chaos component')
    require(args.state_dir.is_dir() and not args.state_dir.is_symlink(), 'Private existing state directory required')
    stat=args.state_dir.stat()
    require(stat.st_uid==os.getuid() and stat.st_mode & 0o077==0, 'State directory must be owned/private')
    require(not (args.state_dir/'update.json').exists(), 'Fresh journal required; do not overwrite recovery history')
    for path in (args.old_env,args.new_env,args.ca):
        require(path.is_file() and not path.is_symlink(), 'Missing independent operator input: '+path.name)
    from update import read_site
    old,new=read_site(args.old_env),read_site(args.new_env)
    require((old['CE_RELEASE_VERSION'],new['CE_RELEASE_VERSION']) in {('0.9.8','0.9.9'),('0.9.9','1.0.0-rc.1')},
            'Chaos update requires authentic 0.9.8 -> 0.9.9 or 0.9.9 -> 1.0.0-rc.1 artifacts')
    require(old['CE_QUEUE_DRIVER']=='pgsql' and new['CE_QUEUE_DRIVER']=='pgsql',
            'Only PostgreSQL-backed queue updates are supported')
    return old,new


def run(args):
    old,new=verify_fixture(args)
    base=[sys.executable,str(HERE/'update.py'),'--project',args.project,
          '--old-env',str(args.old_env),'--new-env',str(args.new_env),
          '--state-dir',str(args.state_dir),'--ca',str(args.ca)]
    # update.py performs its own artifact sha256, trusted-key and DB preflight.
    env=os.environ.copy()
    env['CE_CHAOS_PAUSE_AFTER_STATE']=args.checkpoint
    env['CE_CHAOS_DISPOSABLE']='destroy-test-services'
    apply=subprocess.Popen(base+['apply'],env=env)
    checkpoint_seen=False
    try:
        deadline=time.monotonic()+args.timeout
        while time.monotonic()<deadline:
            file=args.state_dir/'update.json'
            try:
                record=json.loads(file.read_text()) if file.exists() else {}
            except (OSError,json.JSONDecodeError):
                record={}
            if record.get('state')==args.checkpoint:
                checkpoint_seen=True
                break
            require(apply.poll() is None, 'Update finished or failed before selected crash checkpoint')
            time.sleep(.1)
        require(checkpoint_seen, 'Timed out before durable crash checkpoint')
        # The operator is paused AFTER atomic/fsynced checkpoint. Kill the
        # actual host updater, not PostgreSQL or a running unrelated service.
        apply.kill();apply.wait(timeout=10)
    finally:
        if apply.poll() is None:
            apply.kill();apply.wait(timeout=10)
    # Recover with the production operator itself, without fault variables.
    recover=subprocess.run(base+['recover'],check=True,timeout=900)
    status=json.loads(subprocess.check_output(base+['status'],text=True,timeout=30))
    expected='rolled_back' if args.checkpoint=='drained' else 'committed'
    require(status['state']==expected, 'Recovery ended in unexpected journal state')
    result={'schema':1,'project':args.project,'checkpoint':args.checkpoint,
            'source_version':old['CE_RELEASE_VERSION'],'target_version':new['CE_RELEASE_VERSION'],
            'source_sha256':old['CE_RELEASE_SHA256'],'target_sha256':new['CE_RELEASE_SHA256'],
            'recovery_state':expected,'actual_operator_sigkill':True,'ok':True}
    args.report.parent.mkdir(parents=True,exist_ok=True)
    from update import durable_json
    durable_json(args.report,result)
    print(json.dumps(result,indent=2))


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--project',required=True);p.add_argument('--disposable',required=True)
    for x in ('old-env','new-env','state-dir','ca','report'):
        p.add_argument('--'+x,type=Path,required=True)
    p.add_argument('--checkpoint',choices=('drained','activating'),required=True)
    p.add_argument('--timeout',type=int,default=600)
    a=p.parse_args()
    require(60<=a.timeout<=1200,'Invalid checkpoint timeout')
    run(a)

if __name__=='__main__':main()
