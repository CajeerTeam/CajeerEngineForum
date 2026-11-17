#!/usr/bin/env python3
"""Physical DR acceptance: active forum -> protected off-site -> total loss -> authenticated HTTPS recovery.

Destructive! Only for disposable CI databases and a fresh temporary installation; never production.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request
import zipfile

from dr_tls import tls_ingress
from release_acceptance import (ROOT, Client, ADMIN, ADMIN_PASSWORD, PASSWORD,
    env_for_test, run, unpack, free_port, http, install, seed, verify_data,
    seed_structure, verify_structure, verify_search_seo, seed_media, verify_media,
    install_ecosystem_plugins, seed_ecosystem, verify_ecosystem, require)


def private_dir(path):
    path.mkdir(mode=0o700, parents=True)
    path.chmod(0o700)
    return path


def source_requests(base, thread, stop, stats):
    client = Client(base)
    token = client.login(ADMIN, ADMIN_PASSWORD)[0]['data']['access_token']
    seq = 0
    while not stop.wait(.2):
        try:
            page = client.call('GET', '/forum')
            require('boards' in page, 'Live read response malformed')
            stats['reads'] += 1
            if seq % 5 == 0:
                client.call('POST', '/forum/threads/'+thread+'/posts', 201,
                            {'body': 'Active backup load '+str(seq)}, token=token)
                stats['writes'] += 1
        except AssertionError as exc:
            # The exclusive backup lease may temporarily reject writes. Unrecognized
            # HTTP errors are never counted as successful acknowledged publications.
            if 'got 503' in str(exc) or 'got 429' in str(exc):
                stats['busy'] += 1
            else:
                stats['failure'] = str(exc); stop.set(); return
        except Exception as exc:
            stats['failure'] = type(exc).__name__; stop.set(); return
        seq += 1


def fail_closed_report(path, report):
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(report, sort_keys=True, indent=2)+'\n')
    tmp.replace(path)


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--release-dir', type=Path, required=True)
    p.add_argument('--trusted-public-key', type=Path, required=True)
    p.add_argument('--driver', choices=['sqlite','pgsql'], required=True)
    p.add_argument('--transport', choices=['file','s3'], required=True)
    p.add_argument('--s3-endpoint', default='')
    p.add_argument('--s3-bucket', default='')
    p.add_argument('--rto-seconds', type=int, default=1800)
    p.add_argument('--rpo-seconds', type=int, default=3600)
    p.add_argument('--report', type=Path, required=True)
    a = p.parse_args()
    require(os.environ.get('FORUM_DR_DISPOSABLE') == 'destroy-test-services', 'Disposable DR opt-in is required')
    require(a.rto_seconds >= 60 and a.rpo_seconds >= 60, 'DR objectives must be positive')
    if a.driver == 'pgsql':
        require(os.environ.get('FORUM_TEST_RELEASE_DB') == 'forum_test_release_dr096'
                and os.environ.get('DB_HOST') == '127.0.0.1', 'Dedicated disposable PostgreSQL identity required')
        require(os.environ.get('FORUM_DR_PASSWORD') and os.environ.get('DB_USERNAME') == 'forum_ci',
                'Dedicated recovery credentials required')
    if a.transport == 's3':
        require(a.s3_endpoint == 'https://127.0.0.1:19000' and a.s3_bucket == 'forum-dr-ci'
                and os.environ.get('AWS_CA_BUNDLE'), 'Use only dedicated loopback TLS S3 fixture')
    else:
        require(not a.s3_endpoint and not a.s3_bucket, 'File recovery cannot use S3 inputs')
    a.report = a.report.resolve(); private_dir(a.report.parent)
    release_dir = a.release_dir.resolve(); public_key = a.trusted_public_key.resolve()
    verify = subprocess.run(['php','tools/verify-signed-release.php',str(release_dir),str(public_key)],
                            cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=180)
    require(verify.returncode == 0, 'Signed release verification failed')
    with (ROOT/'release.json').open() as f: expected = json.load(f)['edition_version']
    artifact = release_dir / f'cajeerengine-{expected}-dist.zip'
    require(artifact.is_file(), 'Missing exact signed dist')
    sha = hashlib.sha256(artifact.read_bytes()).hexdigest()
    commit = subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    require(re.fullmatch('[a-f0-9]{40}',commit) is not None,'Missing Git identity')
    report = {'schema':1, 'ok':False, 'version':expected, 'commit':commit,
              'artifact_sha256':sha, 'transport':a.transport, 'driver':a.driver,
              'checks':{}, 'service_rto_seconds':None, 'rpo_upper_bound_seconds':None}
    fail_closed_report(a.report, report)
    with tempfile.TemporaryDirectory(prefix='forum-disaster-096-') as folder:
        work = Path(folder); keyvault = private_dir(work/'independent-key-vault')
        offsite = private_dir(work/'offsite'); host = private_dir(work/'source-host')
        target_parent = private_dir(work/'replacement-host'); recovery = private_dir(work/'recovery-operator')
        source = unpack(artifact,host,expected); env = env_for_test(); port = free_port()
        install(source,env,a.report.parent,'dr096-source',port,a.driver)
        install_ecosystem_plugins(source,env,a.report.parent,work,expected)
        # Existing integration helper created an extension signing key. The BACKUP
        # key is kept separately and never travels with the source host or ZIP.
        source_key = work/'ecosystem-backup.key'; isolated_key = keyvault/'recovery.key'
        shutil.move(source_key,isolated_key); isolated_key.chmod(0o600)
        require(not str(isolated_key).startswith(str(host)) and isolated_key.stat().st_mode & 0o077 == 0,
                'Recovery secret is not independent/private')
        env['BACKUP_KEY_FILE'] = str(isolated_key)
        env['EXTENSIONS_TRUST_DIR'] = str(work/'extension-trust')
        for field in ('AWS_ACCESS_KEY_ID','AWS_SECRET_ACCESS_KEY','AWS_CA_BUNDLE','AWS_CONFIG_FILE',
                      'AWS_DEFAULT_REGION','AWS_EC2_METADATA_DISABLED','AWS_REQUEST_CHECKSUM_CALCULATION',
                      'AWS_RESPONSE_CHECKSUM_VALIDATION'):
            if field in os.environ: env[field] = os.environ[field]
        policy = recovery/'policy.json'
        destination = ({'type':'file','directory':str(offsite)} if a.transport=='file'
                       else {'type':'s3','bucket':a.s3_bucket,'prefix':'dr-certification',
                             'region':'us-east-1','endpoint':a.s3_endpoint})
        policy.write_text(json.dumps({'namespace':'forum-dr-096','destination':destination,
                                      'require_separate_device':False,'min_free_bytes':0,
                                      'interval_seconds':86400,'lock_wait_seconds':60}))
        policy.chmod(0o600)
        thread = None
        with http(source,env,a.report.parent,'dr096-live',port,expected) as base:
            state=seed(base); fixture=seed_structure(base,state); media=seed_media(base,fixture)
            ecosystem=seed_ecosystem(base,state)
            verify_data(base,state);verify_structure(base,fixture)
            c=Client(base)
            first=c.call('POST','/forum/boards/'+fixture['board']+'/threads',201,
                         {'title':'DR acknowledged marker','body':'DR before snapshot marker'},token=fixture['token'])['id']
            report['checks']['pre_snapshot_write_acknowledged']=True
            stop=threading.Event();stats={'reads':0,'writes':0,'busy':0,'failure':None}
            thread=threading.Thread(target=source_requests,args=(base,first,stop,stats),daemon=True)
            thread.start();time.sleep(1)
            try:
                result=json.loads(run(source,env,a.report.parent,'dr096-automation',
                     ['php','bin/cajeer','backup:automation:run','--policy='+str(policy),'--force','--json'],timeout=720))
                require(result.get('ok') and result.get('latest_offsite_id'), 'Automatic off-site transfer incomplete')
            finally:
                stop.set();thread.join(timeout=15)
            require(not thread.is_alive() and not stats['failure'] and stats['reads']>0,
                    'Concurrent HTTP workload did not survive snapshot')
            report['checks']['backup_under_http_load']=True
            report['load']={k:v for k,v in stats.items() if k!='failure'}
        catalog=json.loads((source/'storage/app/backups/automation/state.json').read_text())
        ident=result['latest_offsite_id']; record=catalog['records'][ident]
        require(record['state']=='verified' and re.fullmatch('[a-f0-9]{64}',record['sha256']),
                'Unverified offsite object cannot authorize recovery')
        stamp=record['created_at']; report['checks']['offsite_readback_verified']=True
        # Destroy *all* original application bytes, including local backups, then the
        # source database. Original remote object and separately escrowed key survive.
        disaster_at=time.monotonic()
        shutil.rmtree(host)
        require(not source.exists(),'Source host still exists')
        if a.driver == 'pgsql':
            pg=env | {k:os.environ[k] for k in ('DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD')}
            cmd='$p=new PDO("pgsql:host=127.0.0.1;port=".getenv("DB_PORT").";dbname=postgres",getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec("DROP DATABASE forum_test_release_dr096");'
            run(ROOT,pg,a.report.parent,'dr096-drop-source-db',['php','-r',cmd])
        report['checks']['source_installation_destroyed']=True
        # Discover records from the remote store itself: the original host catalog
        # has been destroyed. Names are NOT authenticated until fetch and recovery.
        listing=json.loads(run(ROOT,env,a.report.parent,'dr096-offsite-discover',
                       ['php','bin/cajeer','backup:discover','--policy='+str(policy),'--json']))
        require(listing['ok'] and not listing['authenticated'] and any(
                obj['id']==ident and obj['sha256']==record['sha256'] and not obj['authenticated']
                for obj in listing['objects']), 'Lost-host off-site discovery failed')
        report['checks']['catalog_free_offsite_discovery']=True
        bundle=recovery/'verified.cebackup'
        fetched=json.loads(run(ROOT,env,a.report.parent,'dr096-offsite-fetch',
                     ['php','bin/cajeer','backup:fetch','--policy='+str(policy),'--id='+ident,
                      '--sha256='+record['sha256'],'--file='+str(bundle),'--json']))
        require(fetched['bundle_sha256']==record['sha256'] and bundle.stat().st_size==record['bytes'],
                'Fetched object does not match durable catalog')
        report['checks']['offsite_fetch_after_host_loss']=True
        webport=free_port(); tlsport=free_port(); target=target_parent/'forum'; profile=recovery/'profile.json'
        settings={'APP_URL':f'https://localhost:{tlsport}', 'EXTENSIONS_TRUST_DIR':str(work/'extension-trust')}
        if a.driver=='pgsql':
            settings.update({'DB_HOST':'127.0.0.1','DB_PORT':os.environ['DB_PORT'],
                            'DB_USERNAME':'forum_dr_target','DB_PASSWORD':os.environ['FORUM_DR_PASSWORD'],
                            'DB_SSLMODE':'disable'})
        profile.write_text(json.dumps(settings)); profile.chmod(0o600)
        command=['php','bin/cajeer','backup:recover','--file='+str(bundle),
                 '--key='+str(isolated_key),'--target='+str(target),'--profile='+str(profile),
                 '--rto-seconds='+str(a.rto_seconds),'--rpo-seconds='+str(a.rpo_seconds),'--yes','--json']
        receipt=json.loads(run(ROOT,env_for_test(),a.report.parent,'dr096-recover',command,timeout=1200))
        require(receipt['ok'] and receipt['rto_met'] and receipt['rpo_met'] and receipt['maintenance']
                and receipt['application_boot_tested'] and receipt['edition_version']==expected,
                'Authenticated portable application restore failed')
        require((target/'storage/app/maintenance.json').is_file(), 'Recovered forum exposed before checks')
        report['checks']['isolated_recovery_and_maintenance']=True
        require(json.loads(run(ROOT,env_for_test(),a.report.parent,'dr096-idempotent',command,timeout=1200))==receipt,
                'Repeated recovery did not preserve restored state')
        run(target,env_for_test(),a.report.parent,'dr096-open',['php','bin/cajeer','maintenance:off'])
        # An actual CA-verified HTTPS socket is required. PHP's built-in listener is
        # test-only; this DOES NOT certify production Nginx or external DNS/TLS.
        with http(target,env_for_test(),a.report.parent,'dr096-restored',webport,expected):
            with tls_ingress(webport,tlsport,recovery/'tls') as (https,ca):
                with urllib.request.urlopen(urllib.request.Request(https+'/api/v1/health'),timeout=15) as response:
                    require(response.status==200 and json.load(response)['status']=='ok',
                            'Recovered HTTPS readiness failed')
                verify_data(https,state);verify_structure(https,fixture)
                verify_search_seo(https,state,fixture)
                verify_ecosystem(https,state,ecosystem,expected)
                verify_media(https,state,fixture,media,variants=False)
                c=Client(https)
                require('DR before snapshot marker' in c.call('GET','/forum/threads/'+first,token=fixture['token'])['posts'][0]['body'],
                        'Acknowledged pre-snapshot publication lost')
                new_token=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
                c.call('POST','/forum/threads/'+state[1]+'/posts',201,
                       {'body':'Restored new host works'},token=new_token)
                report['checks']['https_acl_media_search_identity_write']=True
                report['service_rto_seconds']=round(time.monotonic()-disaster_at,3)
                report['rpo_upper_bound_seconds']=max(0,int(time.time()-stamp))
                require(report['service_rto_seconds']<=a.rto_seconds, 'End-to-end HTTPS RTO exceeded')
                require(report['rpo_upper_bound_seconds']<=a.rpo_seconds, 'Snapshot age at HTTPS recovery exceeded')
                report['checks']['end_to_end_rto_rpo']=True
        if a.driver=='pgsql':
            name=receipt['database_name']
            require(re.fullmatch('ce_dr_[a-f0-9]{24}',name) is not None, 'Non-disposable recovery DB')
            pg=env | {k:os.environ[k] for k in ('DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD')}
            cmd='$p=new PDO("pgsql:host=127.0.0.1;port=".getenv("DB_PORT").";dbname=postgres",getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec("DROP DATABASE ".$argv[1]);'
            run(ROOT,pg,a.report.parent,'dr096-clean-db',['php','-r',cmd,name])
        report['ok']=True
        fail_closed_report(a.report,report)
        print('PASS DR: offsite '+a.transport+', source loss, key escrow, media/ACL/FTS/auth, verified HTTPS; RTO',report['service_rto_seconds'],flush=True)

if __name__ == '__main__':
    main()
