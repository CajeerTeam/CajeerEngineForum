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
import uuid

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
    require((old['CE_RELEASE_VERSION'],new['CE_RELEASE_VERSION']) in {('0.9.8','0.9.9'),('0.9.9','1.0.0-rc.1'),('0.9.9','1.0.0'),('1.0.0-rc.1','1.0.0'),('1.4.10','1.4.11'),('1.4.11','1.5.0'),('1.5.0','1.5.1'),('1.5.1','1.5.2'),('1.5.2','1.5.3'),('1.5.3','1.5.4'),('1.5.4','1.5.5'),('1.5.5','1.5.6'),('1.5.6','1.5.7')},
            'Chaos update requires authentic supported release history and verified stable target')
    require(old['CE_QUEUE_DRIVER']=='pgsql' and new['CE_QUEUE_DRIVER']=='pgsql',
            'Only PostgreSQL-backed queue updates are supported')
    if new['CE_RELEASE_VERSION'] in ('1.4.11','1.5.0','1.5.1','1.5.2','1.5.3','1.5.4','1.5.5','1.5.6','1.5.7'):
        require(old['CE_BIND_ADDRESS'] == new['CE_BIND_ADDRESS'] == '127.0.0.1', 'Physical update fixture must bind loopback')
        state=getattr(args,'state',None)
        require(state is not None and state.is_file() and not state.is_symlink(), 'Existing smoke fixture required')
    return old,new


def verify_content(site, ca, state_path):
    from qualify import Client, data, expect
    state=json.loads(state_path.read_text())
    thread=str(uuid.UUID(state['thread'])); reply=str(uuid.UUID(state['reply']))
    client=Client(site['CE_DOMAIN'],ca,int(site['CE_HTTPS_PORT']))
    expect(client,'/health/ready',200)
    require(json.loads(expect(client,'/api/v1/health',200)[1])['edition_version']==site['CE_RELEASE_VERSION'], 'Recovered edition differs')
    path='/api/v1/forum/threads/'+thread
    require(any(row['id']==reply and row['body']=='ContainerSmokeReply' for row in data(expect(client,path,200)[1])['posts']), 'Acknowledged fixture reply not preserved')
    require(b'ContainerSmokeBody' in expect(client,'/forum/threads/'+thread,200)[1], 'Fixture SSR missing')
    require(any(row['id']==thread for row in data(expect(client,'/api/v1/forum/search?q=ContainerSmokeMarker',200)[1])), 'Fixture search missing')


def run(args):
    old,new=verify_fixture(args)
    physical=new['CE_RELEASE_VERSION'] in ('1.4.11','1.5.0','1.5.1','1.5.2','1.5.3','1.5.4','1.5.5','1.5.6','1.5.7')
    if physical: verify_content(old,args.ca,args.state)
    if new['CE_RELEASE_VERSION'] in ('1.5.0','1.5.1','1.5.2','1.5.3','1.5.4','1.5.5','1.5.6','1.5.7'):
        from release_identity import verify_running
        from qualify import Compose
        require(args.baseline_dist and args.signed_dist and args.trusted_key,'signed baseline, target and trust key required')
        verify_running(Compose(args.project,args.old_env),old,args.baseline_dist,args.trusted_key)
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
    recovery_start=time.monotonic()
    clean_env={k:v for k,v in os.environ.items() if k not in ('CE_CHAOS_PAUSE_AFTER_STATE','CE_CHAOS_DISPOSABLE')}
    subprocess.run(base+['recover'],check=True,timeout=900,env=clean_env)
    status=json.loads(subprocess.check_output(base+['status'],text=True,timeout=30))
    expected='rolled_back' if args.checkpoint=='drained' else 'committed'
    require(status['state']==expected, 'Recovery ended in unexpected journal state')
    commit=subprocess.check_output(['git','rev-parse','HEAD'],cwd=HERE.parents[1],text=True,timeout=30).strip()
    require(re.fullmatch(r'[a-f0-9]{40}',commit) is not None,'Git identity missing')
    if physical: verify_content(old if expected=='rolled_back' else new,args.ca,args.state)
    if new['CE_RELEASE_VERSION'] in ('1.5.0','1.5.1','1.5.2','1.5.3','1.5.4','1.5.5','1.5.6','1.5.7'):
        selected, env, archive = (old,args.old_env,args.baseline_dist) if expected=='rolled_back' else (new,args.new_env,args.signed_dist)
        verified = verify_running(Compose(args.project,env),selected,archive,args.trusted_key)
    result={'schema':1,'commit':commit,'recovery_seconds':round(time.monotonic()-recovery_start,3),'project':args.project,'checkpoint':args.checkpoint,
            'source_version':old['CE_RELEASE_VERSION'],'target_version':new['CE_RELEASE_VERSION'],
            'source_sha256':old['CE_RELEASE_SHA256'],'target_sha256':new['CE_RELEASE_SHA256'],
            'recovery_state':expected,'actual_operator_sigkill':True,'ok':True}
    if physical:
        result.update(preserved_fixture=True,recovered_version=(old if expected=='rolled_back' else new)['CE_RELEASE_VERSION'])
    if new['CE_RELEASE_VERSION'] in ('1.5.0','1.5.1','1.5.2','1.5.3','1.5.4','1.5.5','1.5.6','1.5.7'): result.update(signed_deployment_verified=True,deployment_identity=verified)
    args.report.parent.mkdir(parents=True,exist_ok=True)
    from update import durable_json
    durable_json(args.report,result)
    print(json.dumps(result,indent=2))


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--project',required=True);p.add_argument('--disposable',required=True)
    for x in ('old-env','new-env','state-dir','ca','report'):
        p.add_argument('--'+x,type=Path,required=True)
    for x in ('baseline-dist','signed-dist','trusted-key'): p.add_argument('--'+x,type=Path)
    p.add_argument('--state',type=Path,help='pre-existing disposable smoke fixture state')
    p.add_argument('--checkpoint',choices=('drained','activating'),required=True)
    p.add_argument('--timeout',type=int,default=600)
    a=p.parse_args()
    require(60<=a.timeout<=1200,'Invalid checkpoint timeout')
    run(a)

if __name__=='__main__':main()
