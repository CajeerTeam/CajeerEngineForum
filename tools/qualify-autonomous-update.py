#!/usr/bin/env python3
"""Live autonomous updater qualification on an explicitly provisioned disposable TLS host.
No mocked transport, pre-staged ZIP, manual approval or direct apply is allowed.
"""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
import threading
import time
import uuid

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'deploy/container'))
from qualify import Client, data, expect, load, assert_load, write_report

REPO = 'CajeerTeam/CajeerEngineForum'
def transition(published_candidate=False):
    return ('1.5.11','1.6.0') if published_candidate else ('1.5.10','1.5.11')


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def read(path):
    require(path.is_file() and not path.is_symlink(), 'Missing regular record: ' + path.name)
    return json.loads(path.read_text())


def digest(path):
    require(path.is_file() and not path.is_symlink(), 'Missing artifact')
    with path.open('rb') as f:
        return hashlib.file_digest(f, 'sha256').hexdigest()


def protected(path):
    require(path.is_absolute() and path.resolve() == path and path.is_file(), 'Canonical host file required')
    for p in (path, *path.parents):
        s = p.stat()
        require(s.st_uid == 0 and not s.st_mode & 0o022, 'Unsafe host file ownership')


def command(argv, root, timeout=900):
    # On timeout terminate the whole test process group, never silently continue.
    # The existing durable recovery supervisor remains responsible for recovery.
    with subprocess.Popen(argv, cwd=root, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          start_new_session=True) as p:
        try:
            out, _ = p.communicate(timeout=timeout)
        except BaseException:
            try:
                os.killpg(p.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            p.communicate()
            raise
        require(p.returncode == 0, 'Host operation failed: ' + Path(argv[0]).name)
        return out


def fixture(args):
    require(os.geteuid() != 0 and args.disposable == 'destroy-test-services', 'Dedicated non-root disposable host required')
    protected(args.fixture)
    cfg = read(args.fixture)
    source, target = transition(args.published_candidate)
    require(cfg.get('purpose') == 'forum-autonomous-update-qualification' and cfg.get('source') == source and cfg.get('target') == target, 'Wrong fixture purpose or versions')
    root = Path(cfg['root'])
    require(root == Path('/var/www/cajeerengine') and root.resolve() == root and root != ROOT, 'Dedicated installed host required')
    require(re.fullmatch(r'[a-z0-9.-]+\.test', cfg['domain']) and type(cfg['port']) is int and 1 <= cfg['port'] <= 65535, 'Loopback test domain required')
    require(read(root/'release.json')['edition_version'] == source, 'Reset to authentic baseline before test')
    require((root/'storage/app/installed.lock').is_file(), 'Installed fixture required')
    supervisor = Path('/etc/cajeerengine/update-supervisor.php')
    protected(supervisor)
    protected(args.trusted_key)
    # Stop the timer before invoking the exact independent supervisor; no parallel actor.
    timer = subprocess.run(['systemctl','show','cajeerengine-update-operator.timer','--property=ActiveState','--value'], capture_output=True, text=True, check=True).stdout.strip()
    unit = subprocess.run(['systemctl','show','cajeerengine-update-operator.service','--property=ActiveState','--value'], capture_output=True, text=True, check=True).stdout.strip()
    require(timer == unit == 'inactive', 'Disable timer and wait for idle operator before qualification')
    app = root/'storage/app'; updates = app/'updates'
    for p in (app/'maintenance.json', updates/'active.json', app/'backup-restore.json', updates/'last-update.json', updates/'auto-stage.json', updates/'autonomous/stage.json', updates/'downloads'/target):
        require(not p.exists() and not p.is_symlink(), 'Fixture is not fresh: '+p.name)
    for pattern in ('autonomous/attempt-*.json','transactions/*/journal.json'):
        require(not list(updates.glob(pattern)), 'Fixture already has update history')
    require(not list((app/'update-requests').glob('request-*.json')), 'Manual requests are forbidden in autonomous test')
    return cfg, root, supervisor


def run(args):
    cfg, root, supervisor = fixture(args)
    source, target = transition(args.published_candidate)
    app = root/'storage/app'; updates = app/'updates'
    candidate = digest(args.candidate)
    require(args.candidate.name == 'cajeerengine-1.6.0-dist.zip', 'Wrong qualification candidate')
    pub = base64.b64decode(args.trusted_key.read_text().strip(), validate=True)
    require(len(pub)==32, 'Invalid independent key'); key_id=hashlib.sha256(pub).hexdigest()
    require(args.baseline.name == f'cajeerengine-{source}-dist.zip', 'Wrong installed baseline package')
    baseline_sha = digest(args.baseline)
    require(baseline_sha != candidate, 'Candidate cannot be the baseline')
    baseline = json.loads(command(['php','bin/cajeer','update:integrity','--package='+str(args.baseline),'--sha256='+baseline_sha],root))
    require(baseline.get('ok') is True and baseline.get('version')==source and baseline.get('sha256')==baseline_sha and baseline.get('mismatch_count')==0 and baseline.get('key_id')==key_id and baseline.get('checked',0)>0, 'Installed baseline does not match authentic signed distribution')
    state = read(Path(cfg['state'])); thread=str(uuid.UUID(state['thread'])); reply=str(uuid.UUID(state['reply']))
    client = Client(cfg['domain'], Path(cfg['ca']), cfg['port'])
    path='/api/v1/forum/threads/'+thread
    def api_ok(body):
        return any(r['id']==reply and r['body']=='ContainerSmokeReply' for r in data(body)['posts'])
    def verify(version):
        expect(client,'/health/ready',200)
        require(json.loads(expect(client,'/api/v1/health',200)[1])['edition_version']==version, 'Wrong running version')
        require(api_ok(expect(client,path,200)[1]), 'Acknowledged reply missing')
        require(b'ContainerSmokeBody' in expect(client,'/forum/threads/'+thread,200)[1], 'SSR content missing')
        require(any(r['id']==thread for r in data(expect(client,'/api/v1/forum/search?q=ContainerSmokeMarker',200)[1])), 'Search content missing')
    routes=[('thread',path,{},api_ok),('page','/forum/threads/'+thread,{},lambda b:b'ContainerSmokeBody' in b)]
    verify(source)
    before=load(client,routes,8,60,10); assert_load(before,500,.001,5)
    start=time.monotonic(); done=threading.Event(); counts={'ok':0,'maintenance':0,'errors':0}
    def probe():
        while not done.is_set():
            try:
                status,_,body=client.request(path)
                if status==200 and api_ok(body): counts['ok']+=1
                elif status==503: counts['maintenance']+=1
                else: counts['errors']+=1
            except Exception: counts['errors']+=1
            done.wait(.2)
    observer=threading.Thread(target=probe,daemon=True); observer.start()
    try:
        command(['php',str(supervisor),str(root)],root)
        stage=read(updates/'auto-stage.json')
        require(stage.get('state')=='staged' and stage.get('version')==target, 'Operator did not automatically stage expected GitHub version')
        receipt=read(updates/'downloads'/target/'verified.json')
        package=updates/'downloads'/target/f'cajeerengine-{target}-dist.zip'
        sha=digest(package)
        if args.published_candidate:
            require(sha==candidate, 'Published candidate differs from exact certified CI artifact')
        require(receipt.get('source') in [f'https://github.com/{REPO}/releases/tag/{v}' for v in ('v'+target,target)] and receipt.get('version')==target and receipt.get('sha256')==sha and receipt.get('key_id')==key_id, 'Wrong canonical downloaded release')
        require(receipt.get('size')==package.stat().st_size, 'Download size mismatch')
        require(read(root/'release.json')['edition_version']==source, 'Staging unexpectedly modified platform')
        command(['php',str(supervisor),str(root)],root)
        elapsed=time.monotonic()-start
    finally:
        done.set(); observer.join(12)
    require(counts['maintenance']>0 and counts['errors']==0, 'Maintenance gate or live request failure')
    require(elapsed<=900, 'Update exceeded time budget')
    report=read(updates/'last-update.json'); tx=report.get('id','')
    require(re.fullmatch(r'\d{8}-\d{6}-[a-f0-9]{16}',tx), 'Invalid transaction identity')
    journal=read(updates/'transactions'/tx/'journal.json')
    require(report.get('ok') is True and report.get('state')=='committed' and journal.get('state')=='committed' and journal.get('id')==tx and journal.get('sha256')==report.get('sha256')==sha and journal.get('previous_version')==source and journal.get('target_version')==target, 'No exact committed update')
    require(journal.get('authorization',{}).get('type')=='host_policy' and journal.get('service_phase')=='started', 'Missing policy authorization or service restart')
    proof=journal.get('recovery_proof',{}); db=proof.get('database',{})
    require(proof.get('ok') is True and proof.get('id')==journal.get('backup') and proof.get('environment_restored') is True and (db.get('restore_verified') is True or db.get('integrity_verified') is True) and db.get('tables_read',0)>0, 'Missing exact snapshot restore proof')
    attempt=read(updates/'autonomous'/('attempt-'+hashlib.sha256(f'{source}>{target}'.encode()).hexdigest()+'.json'))
    require(attempt.get('state')=='completed' and attempt.get('transaction_id')==tx, 'Autonomous receipt not reconciled')
    require(not (updates/'active.json').exists() and not (app/'maintenance.json').exists(), 'Recovery or maintenance still pending')
    require(not list((app/'update-requests').glob('request-*.json')), 'Manual approval unexpectedly created')
    integrity=json.loads(command(['php','bin/cajeer','update:integrity','--package='+str(package),'--sha256='+sha],root))
    require(integrity.get('ok') is True and integrity.get('mismatch_count')==0 and integrity.get('key_id')==key_id and integrity.get('checked',0)>0, 'Installed distribution integrity failed')
    verify(target)
    after=load(client,routes,8,60,10); assert_load(after,500,.001,5)
    command(['php',str(supervisor),str(root)],root)
    require(read(updates/'last-update.json')['id']==tx and read(root/'release.json')['edition_version']==target, 'Operator repeated committed update')
    verify(target)
    write_report(args.report,{'schema':1,'ok':True,'phase':'postpublication' if args.published_candidate else 'prepublication','baseline_sha256':baseline_sha,'baseline_integrity':True,'commit':os.environ['GITHUB_SHA'],'candidate_sha256':candidate,'source_version':source,'target_version':target,'github_dist_sha256':sha,'key_id':key_id,'transaction_id':tx,'actual_autonomous_update':True,'canonical_github':True,'backup_rehearsed':True,'installed_integrity':True,'preserved_fixture':True,'no_duplicate_apply':True,'manual_requests':0,'seconds':elapsed,'during':counts,'before':before,'after':after})


if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__)
    for name in ('fixture','trusted-key','candidate','baseline','report'): p.add_argument('--'+name,type=Path,required=True)
    p.add_argument('--disposable',required=True)
    p.add_argument('--published-candidate',action='store_true',help='Postpublication acceptance of exact 1.6.0 from GitHub; not the prepublication historical evidence')
    run(p.parse_args())
