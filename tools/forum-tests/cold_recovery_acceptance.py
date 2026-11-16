#!/usr/bin/env python3
"""Restore an authentic previous forum dist on a different path/database after total source loss."""
import argparse
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import zipfile

from release_acceptance import (ROOT, HERE, Client, ADMIN, ADMIN_PASSWORD, env_for_test, run, unpack, free_port, http,
    install, seed, verify_data, seed_structure, verify_structure, verify_search_seo,
    seed_media, verify_media, install_ecosystem_plugins, seed_ecosystem, verify_ecosystem, require)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--artifact', type=Path, required=True)
    parser.add_argument('--driver', choices=('sqlite','pgsql'), required=True)
    parser.add_argument('--report', type=Path, required=True)
    args=parser.parse_args(); report=args.report.resolve(); report.mkdir(parents=True,exist_ok=True)
    env=env_for_test(); artifact=args.artifact.resolve()
    with zipfile.ZipFile(artifact) as z: version=json.loads(z.read('cajeerengine/release.json'))['edition_version']
    with tempfile.TemporaryDirectory(prefix='forum-cold-dr-',dir='/tmp') as tmp:
        work=Path(tmp); source=unpack(artifact,work/'source',version); port=free_port()
        if args.driver=='pgsql':
            require(os.environ.get('FORUM_TEST_RELEASE_DB')=='forum_test_release_dr' and os.environ.get('DB_HOST')=='127.0.0.1','Dedicated disposable database required')
        install(source,env,report,'dr-source',port,args.driver)
        install_ecosystem_plugins(source,env,report,work,version)
        with http(source,env,report,'dr-source',port,version) as base:
            state=seed(base); ecosystem=seed_ecosystem(base,state); fixture=seed_structure(base,state); media=seed_media(base,fixture)
            verify_data(base,state); verify_structure(base,fixture); verify_ecosystem(base,state,ecosystem,version)
        bundle=work/'offsite.cebackup'; key=work/'ecosystem-backup.key'
        capture=json.loads(run(source,env,report,'dr-backup',['php','bin/cajeer','backup:create','--json']))
        run(source,env,report,'dr-export',['php','bin/cajeer','backup:export','--id='+capture['id'],'--file='+str(bundle),'--json'])
        # Nothing in the old app, local database, or local backup directory remains.
        shutil.rmtree(work/'source')
        if args.driver=='pgsql':
            pg=env | {k:os.environ[k] for k in ('DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD')}
            code=r'$p=new PDO("pgsql:host=127.0.0.1;port=".getenv("DB_PORT").";dbname=postgres",getenv("DB_USERNAME"),getenv("DB_PASSWORD"));$p->exec("DROP DATABASE forum_test_release_dr");'
            run(ROOT,pg,report,'dr-delete-source-db',['php','-r',code])
        require(not source.exists(),'Source still exists')
        newport=free_port(); target=work/'new-host-forum'; profile=work/'profile.json'
        config={'APP_URL':f'http://127.0.0.1:{newport}','EXTENSIONS_TRUST_DIR':str(work/'extension-trust')}
        if args.driver=='pgsql':
            config |= {'DB_HOST':'127.0.0.1','DB_PORT':os.environ['DB_PORT'],'DB_USERNAME':'forum_dr_target','DB_PASSWORD':os.environ['FORUM_DR_PASSWORD'],'DB_SSLMODE':'disable'}
        profile.write_text(json.dumps(config)); profile.chmod(0o600)
        cmd=['php','bin/cajeer','backup:recover','--file='+str(bundle),'--key='+str(key),'--target='+str(target),'--profile='+str(profile),'--rto-seconds=600','--rpo-seconds=3600','--yes','--json']
        receipt=json.loads(run(ROOT,env_for_test(),report,'dr-new-host',cmd,timeout=900))
        require(receipt['ok'] and receipt['application_boot_tested'] and receipt['edition_version']==version and receipt['rto_met'] and receipt['maintenance'],'New-host recovery did not pass')
        require((target/'storage/app/maintenance.json').is_file() and (target/'storage/app/installed.lock').is_file(),'Recovery opened traffic prematurely')
        require(json.loads(run(ROOT,env_for_test(),report,'dr-idempotent',cmd,timeout=900))==receipt,'Recovery repeat changed published data')
        if args.driver=='pgsql': require(re.fullmatch(r'ce_dr_[a-f0-9]{24}',receipt['database_name']) is not None,'Not a fresh database')
        # A real HTTP server must reject traffic while maintenance is on.
        with (report/'dr-maintenance-http.log').open('w') as log:
            proc=subprocess.Popen(['php','-S',f'127.0.0.1:{newport}','-t',str(target/'public'),str(HERE/'router.php')],cwd=target,env=env_for_test(),stdout=log,stderr=subprocess.STDOUT)
            try:
                for _ in range(80):
                    try:
                        urllib.request.urlopen(f'http://127.0.0.1:{newport}/health/live',timeout=2).close(); break
                    except (OSError,urllib.error.URLError): time.sleep(.25)
                else: raise RuntimeError('Recovered HTTP listener did not start')
                for path in ('/health/ready','/api/v1/health','/forum'):
                    try: urllib.request.urlopen(f'http://127.0.0.1:{newport}'+path,timeout=10).close()
                    except urllib.error.HTTPError as exc: require(exc.code==503,'Recovered site escaped maintenance')
                    else: raise RuntimeError('Recovered site escaped maintenance')
            finally: proc.terminate(); proc.wait(timeout=10)
        run(target,env_for_test(),report,'dr-open',['php','bin/cajeer','maintenance:off'])
        with http(target,env_for_test(),report,'dr-restored',newport,version) as base:
            verify_data(base,state); verify_structure(base,fixture); verify_search_seo(base,state,fixture); verify_ecosystem(base,state,ecosystem,version); verify_media(base,state,fixture,media,variants=False)
            client=Client(base); new_token=client.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
            client.call('POST','/forum/threads/'+state[1]+'/posts',201,{'body':'New host publication'},token=new_token)
            require(client.call('GET','/forum/threads/'+state[1])['posts'][-1]['body']=='New host publication','Recovered forum cannot write')
        if args.driver=='pgsql':
            pg=env | {k:os.environ[k] for k in ('DB_HOST','DB_PORT','DB_USERNAME','DB_PASSWORD')}
            code=r'$p=new PDO("pgsql:host=127.0.0.1;port=".getenv("DB_PORT").";dbname=postgres",getenv("DB_USERNAME"),getenv("DB_PASSWORD"));if(!preg_match("/^ce_dr_[a-f0-9]{24}$/D",$argv[1]))exit(1);$p->exec("DROP DATABASE ".$argv[1]);'
            run(ROOT,pg,report,'dr-clean-disposable-db',['php','-r',code,receipt['database_name']])
        print('PASS physical cold recovery: source destroyed, new database/path, HTTP, ACL, signed plugins, media and publication',flush=True)

if __name__=='__main__': main()
