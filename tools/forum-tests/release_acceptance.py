#!/usr/bin/env python3
"""Physical dist acceptance. Uses disposable local databases only; no production secrets."""
import argparse
import base64
from contextlib import contextmanager
import json
import os
import re
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import zipfile

from run import ROOT, HERE, Client, ADMIN, ADMIN_PASSWORD, PASSWORD, unique_email, mail_link, profile_png


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def version(site):
    return json.loads((site / 'release.json').read_text())['edition_version']


def env_for_test():
    return {key: os.environ[key] for key in ('PATH', 'HOME', 'LANG', 'LC_ALL', 'SYSTEMROOT') if key in os.environ} | {'TZ': 'UTC'}


def run(site, env, report, label, args, expected=0, timeout=240):
    # Logs contain only disposable credentials, never print command arguments.
    with (report / (label + '.log')).open('w') as log:
        result = subprocess.run(args, cwd=site, env=env, stdout=log, stderr=subprocess.STDOUT, timeout=timeout)
    require((result.returncode == 0) if expected == 0 else (result.returncode != 0), f'{label}: unexpected exit {result.returncode}')
    print('PASS', label, flush=True)
    return (report / (label + '.log')).read_text()


def unpack(artifact, dest, expected):
    # Production verifier checks the exact ZIP bytes and dependency inventory first.
    code = r'require "vendor/autoload.php"; (new CajeerEngine\Release\ReleaseArtifactVerifier())->verify($argv[1], "dist", $argv[2]);'
    subprocess.run(['php', '-r', code, str(artifact), expected], cwd=ROOT, env=env_for_test(), check=True, timeout=120)
    with zipfile.ZipFile(artifact) as z:
        z.extractall(dest)
    site = dest / 'cajeerengine'
    require(version(site) == expected and (site / 'VERSION').read_text().strip() == '1.3.0', 'Wrong forum/upstream identity')
    require(not (site / '.env').exists() and not (site / 'storage/app/installed.lock').exists() and not (site / 'tests').exists() and (site / 'vendor/autoload.php').is_file(), 'Dirty or incomplete dist')
    return site


def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


@contextmanager
def http(site, env, report, label, port, expected_version):
    base = f'http://127.0.0.1:{port}'
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    with (report / (label + '-http.log')).open('w') as log:
        proc = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(site / 'public'), str(HERE / 'router.php')], cwd=site, env=env, stdout=log, stderr=subprocess.STDOUT)
        try:
            deadline = time.monotonic() + 40
            while time.monotonic() < deadline:
                require(proc.poll() is None, 'HTTP process stopped')
                try:
                    with opener.open(base + '/api/v1/health', timeout=3) as res:
                        data = json.load(res)
                        require(data['edition_version'] == expected_version and data['version'] == '1.3.0', 'Health reports wrong release')
                        require(data['status'] == 'ok' and all(data['checks'].values()), 'Health not ready')
                        break
                except (urllib.error.URLError, TimeoutError):
                    time.sleep(.2)
            else:
                raise RuntimeError('Physical dist HTTP readiness failed')
            yield base
        finally:
            proc.terminate()
            try:
                proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                proc.kill(); proc.wait(timeout=10)


def install(site, env, report, label, port, driver):
    args = ['php', 'bin/cajeer', 'install', '--no-interaction', '--production', '--json', f'--domain=http://127.0.0.1:{port}', '--app-name=Acceptance Forum', '--category-name=Acceptance category', '--board-name=Acceptance board', '--registration=closed', f'--admin-email={ADMIN}', f'--admin-password={ADMIN_PASSWORD}']
    if driver == 'sqlite':
        args += ['--sqlite']
    else:
        name = os.environ.get('FORUM_TEST_RELEASE_DB', '')
        require(re.fullmatch(r'forum_test_release_[a-z0-9_]+', name) is not None and os.environ.get('DB_HOST') == '127.0.0.1', 'Disposable loopback release DB required')
        # Refuse an existing installation before running even the real installer.
        pg = env | {k: os.environ[k] for k in ('DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD')}
        probe = r'$p=new PDO("pgsql:host=127.0.0.1;port=".getenv("DB_PORT").";dbname=".$argv[1],getenv("DB_USERNAME"),getenv("DB_PASSWORD"));if((int)$p->query("SELECT COUNT(*) FROM pg_catalog.pg_tables WHERE schemaname=\x27public\x27")->fetchColumn()!==0)exit(1);'
        # PHP double quoted SQL string needs actual single quotes, not a literal backslash sequence.
        probe = probe.replace(r'\x27', "'")
        run(site, pg, report, label+'-empty-db', ['php', '-r', probe, name])
        args += ['--db=pgsql', '--db-host=127.0.0.1', '--db-port='+os.environ['DB_PORT'], '--db-name='+name, '--db-user='+os.environ['DB_USERNAME'], '--db-password='+os.environ['DB_PASSWORD'], '--db-sslmode=disable']
    result = json.loads(run(site, env, report, label+'-install', args))
    require(result['ok'] and result['final']['ok'], 'Install not ready')
    # Explicit local cache makes baseline and current installations reproducible.
    code = r'require "vendor/autoload.php"; (new CajeerEngine\Installer\InstallerEnvironment(".env"))->write(["CACHE_DRIVER"=>"file", "FORUM_REGISTRATION"=>"false", "FORUM_REQUIRE_EMAIL_VERIFICATION"=>"true", "FORUM_SMTP_HOST"=>"127.0.0.1", "FORUM_SMTP_PORT"=>"1025", "FORUM_SMTP_SECURITY"=>"plain", "FORUM_SMTP_FROM"=>"forum@example.test", "FORUM_SMTP_USERNAME"=>"", "FORUM_SMTP_PASSWORD"=>"", "RATE_LIMIT_AUTH"=>"80", "RATE_LIMIT_GLOBAL"=>"1000"], ".env.example");'
    run(site, env, report, label+'-configure', ['php', '-r', code])
    ready = json.loads(run(site, env, report, label+'-install-check', ['php','bin/cajeer','install:check','--json']))
    require(ready['configured_ready'], 'Install check not ready')
    run(site, env, report, label+'-migrate', ['php','bin/cajeer','migrate'])
    run(site, env, report, label+'-postcheck', ['php','tools/update-postcheck.php','verify'])


def seed(base):
    c = Client(base)
    c.call('POST', '/forum/register', 403, {'name':'Blocked', 'email':unique_email(), 'password':PASSWORD})
    admin = c.login(ADMIN, ADMIN_PASSWORD)[0]['data']['access_token']
    board = c.call('GET','/forum')['boards'][0]
    require(board['name']=='Acceptance board', 'Installer board not persisted')
    thread = c.call('POST', f'/forum/boards/{board["id"]}/threads', 201, {'title':'Release acceptance topic','body':'Original content'}, token=admin)['id']
    url = '/forum/threads/'+thread
    post = c.call('GET',url)['posts'][0]['id']
    c.call('POST',url+'/posts',201,{'body':'Original reply'},token=admin)
    c.call('PUT','/forum/posts/'+post+'/reaction',200,{'enabled':True},token=admin)
    c.call('PUT',url+'/watch',200,{'enabled':True},token=admin)
    member=c.call('POST','/users',201,{'email':unique_email(),'name':'Existing group member','password':PASSWORD,'roles':['viewer','forum-member','forum-moderator']},token=admin)['id']
    return admin, thread, post, member


def verify_data(base, state):
    token, thread, post, member = state
    c = Client(base)
    c.call('GET','/auth/me',token=token)
    posts = c.call('GET','/forum/threads/'+thread)['posts']
    require([p['body'] for p in posts]==['Original content','Original reply'] and posts[0]['id']==post and int(posts[0]['likes'])==1, 'Content or reactions lost')
    require(thread in [t['id'] for t in c.call('GET','/forum/watches',token=token)], 'Watch lost')
    require(set(c.call('GET','/users/'+member,token=token)['roles'])=={'viewer','forum-member','forum-moderator'}, 'Existing roles lost')
    c.login(ADMIN, ADMIN_PASSWORD)


def verify_groups(base,state):
    token, _, _, member=state; c=Client(base)
    groups=c.call('GET','/forum/groups',token=token)
    ids={g['handle']:g['id'] for g in groups}
    membership=c.call('GET',f'/forum/members/{member}/groups',token=token)
    require(membership['primary_group_id']==ids['forum-member'] and membership['secondary_group_ids']==[ids['forum-moderator']], 'Group membership backfill failed')
    print('PASS primary and secondary role assignments preserved',flush=True)


def verify_discussions(base,state):
    token, thread, _, _=state; c=Client(base)
    board=c.call('GET','/forum/threads/'+thread,token=token)['thread']['board_id']
    create=lambda title:c.call('POST',f'/forum/boards/{board}/threads',201,{'title':title,'body':'Acceptance message'},token=token)['id']
    source,target=create('Acceptance source'),create('Acceptance destination')
    url=lambda t:'/forum/threads/'+t
    post=c.call('GET',url(source))['first_post_id']
    c.call('PUT',url(source)+'/metadata',200,{'prefix':'Release','tags':['acceptance-tag']},token=token)
    c.call('POST',url(target)+'/merge',200,{'source_ids':[source]},token=token)
    require(c.call('GET',url(source))['thread']['id']==target,'Merged URL did not resolve')
    require(c.call('GET',url(target))['posts'][1]['id']==post,'Merged post identity lost')
    require(target in [t['id'] for t in c.call('GET','/forum/search?tag=acceptance-tag')],'Tag search failed')
    c.call('POST',url(target)+'/moderate',200,{'action':'delete'},token=token)
    print('PASS discussion management on physical dist',flush=True)


def verify_navigation(base,state):
    token, thread, _, member=state; c=Client(base)
    original=c.call('GET','/forum/threads/'+thread,token=token)
    board=original['board']['id']; category=original['board']['category_id']
    parent=c.call('POST',f'/forum/categories/{category}/boards',201,{'name':'Acceptance parent','description':'','node_type':'container'},token=token)['id']
    c.call('POST','/forum/nodes/reorder',200,{'kind':'board','id':board,'target_kind':'board','target_id':parent,'placement':'inside'},token=token)
    path=c.call('GET','/forum/threads/'+thread)['breadcrumbs']
    require([n['id'] for n in path]==[category,parent,board],'Breadcrumb hierarchy incorrect')
    effective=c.call('GET',f'/forum/permission-inspector?kind=board&id={board}&user_id={member}',token=token)
    require(effective['effective']['thread:read'] and effective['effective']['post:reply'],'Permission inspector incorrect')
    c.call('POST','/forum/nodes/reorder',200,{'kind':'board','id':board,'target_kind':'category','target_id':category,'placement':'inside'},token=token)
    c.call('DELETE',f'/forum/boards/{parent}',200,token=token)
    print('PASS navigation and permission inspector on physical dist',flush=True)


def seed_structure(base, state):
    """Create real 0.3.0 structure before updating, not just after the upgrade."""
    admin, _, _, member = state
    c=Client(base)
    member_group=next(g['id'] for g in c.call('GET','/forum/groups',token=admin) if g['handle']=='forum-member')
    group=c.call('POST','/forum/groups',201,{'handle':'release-private','name':'Release private','description':'Upgrade fixture','permissions':['forum:write','forum:moderate']},token=admin)['id']
    email=unique_email(); user=c.call('POST','/users',201,{'email':email,'name':'Release delegate','password':PASSWORD,'roles':['forum-member']},token=admin)['id']
    c.call('PUT',f'/forum/members/{user}/groups',200,{'primary_group_id':member_group,'secondary_group_ids':[group]},token=admin)
    token=c.login(email)[0]['data']['access_token']
    cat=c.call('POST','/forum/categories',201,{'name':'Release confidential category','description':''},token=admin)['id']
    path=f'/forum/categories/{cat}/boards'
    root=c.call('POST',path,201,{'name':'Release confidential parent','description':'','node_type':'container'},token=admin)['id']
    board=c.call('POST',path,201,{'name':'Release confidential child','description':'','parent_id':root},token=admin)['id']
    rules=[{'subject_type':'role','subject_id':group,'permission':p,'effect':'allow'} for p in ('node:view','thread:read','thread:moderate')]
    policy=f'/forum/categories/{cat}/permissions'
    c.call('PUT',policy,200,{'visibility':'hidden','rules':rules},token=admin)
    create=lambda title: c.call('POST',f'/forum/boards/{board}/threads',201,{'title':title,'body':'ReleasePrivateMarker'},token=token)['id']
    source,target=create('ReleasePrivateSource'),create('ReleasePrivateTarget')
    url=lambda t:'/forum/threads/'+t
    post=c.call('GET',url(source),token=token)['first_post_id']
    c.call('PUT',url(source)+'/metadata',200,{'prefix':'Private','tags':['release-private']},token=token)
    c.call('PUT','/forum/posts/'+post+'/reaction',200,{'enabled':True},token=token)
    c.call('PUT',url(source)+'/watch',200,{'enabled':True},token=token)
    report=c.call('POST','/forum/posts/'+post+'/report',201,{'reason':'Release confidential report'},token=token)['id']
    c.call('POST',url(target)+'/merge',200,{'source_ids':[source]},token=token)
    return {'admin':admin,'token':token,'user':user,'email':email,'member_group':member_group,'group':group,'category':cat,'root':root,'board':board,'source':source,'target':target,'post':post,'report':report,'policy':c.call('GET',policy,token=admin)}


def verify_structure(base, fixture):
    c=Client(base); f=fixture; admin=f['admin']; token=f['token']
    url='/forum/threads/'+f['target']; old='/forum/threads/'+f['source']
    c.call('GET','/auth/me',token=token)
    membership=c.call('GET',f'/forum/members/{f["user"]}/groups',token=admin)
    require(membership['primary_group_id']==f['member_group'] and membership['secondary_group_ids']==[f['group']], 'Private group assignments changed')
    policy=c.call('GET',f'/forum/categories/{f["category"]}/permissions',token=admin)
    require(policy==f['policy'],'Private ACL or revision changed')
    result=c.call('GET',old,token=token)
    require(result['thread']['id']==f['target'] and result['posts'][1]['id']==f['post'] and int(result['posts'][1]['likes'])==1,'Private redirect, post or reaction lost')
    require([n['id'] for n in result['breadcrumbs']]==[f['category'],f['root'],f['board']], 'Private ancestry changed')
    require('release-private' in result['thread']['tags'],'Private tags lost')
    require(f['target'] in [t['id'] for t in c.call('GET','/forum/watches',token=token)],'Private watch lost')
    require(f['report'] in [r['id'] for r in c.call('GET','/forum/reports',token=token)],'Private report lost')
    effective=c.call('GET',f'/forum/permission-inspector?kind=board&id={f["board"]}&user_id={f["user"]}',token=admin)['effective']
    require(effective==result['board']['permissions'] and effective['thread:moderate'],'Inspector/delegation drift')
    # Verify the guest and an unrelated logged-in moderator separately. The latter
    # has global moderation but must still not receive the closed content.
    other=Client(base)
    outsider_email=unique_email()
    outsider=c.call('POST','/users',201,{'email':outsider_email,'name':'Release outsider','password':PASSWORD,'roles':['forum-member','forum-moderator']},token=admin)['id']
    other_token=other.login(outsider_email)[0]['data']['access_token']
    for viewer in (None,other_token):
        c.call('GET',url,404,token=viewer); c.call('GET',old,404,token=viewer)
        tree=c.call('GET','/forum',token=viewer)
        require(f['category'] not in [n['id'] for n in tree['categories']] and f['board'] not in [n['id'] for n in tree['boards']],'Private tree leaked')
        for path in ('/forum/latest','/forum/search?q=ReleasePrivateMarker','/forum/search?tag=release-private'):
            require(f['target'] not in [t['id'] for t in c.call('GET',path,token=viewer)],'Private search/latest leaked')
        require(f['target'] not in [t['id'] for t in c.call('GET',f'/forum/members/{f["user"]}',token=viewer)['threads']],'Private profile leaked')
    require(f['report'] not in [r['id'] for r in c.call('GET','/forum/reports',token=other_token)],'Private moderation report leaked')
    c.call('POST',f'/forum/reports/{f["report"]}/resolve',404,token=other_token)
    c.call('POST',url+'/posts',404,{'body':'Forbidden'},token=other_token)
    print('PASS private nested structure, delegated roles, ACL and discussion identities',flush=True)


def verify_profiles(base, fixture):
    c=Client(base); token=fixture['token']; admin=fixture['admin']; user=fixture['user']
    own='/forum/account/profile'; public='/forum/members/'+user
    require(c.call('GET',own,token=token)['profile']['bio']=='','Existing member profile not initialized lazily')
    field=c.call('POST','/forum/profile-fields',201,{'label':'Release profile field','visibility':'private'},token=admin)['id']
    c.call('PATCH',own,200,{'display_name':'Release Profile','bio':'Release bio','signature':'Release signature','fields':[{'id':field,'value':'private profile value'}]},token=token)
    c.call('PATCH','/forum/account/preferences',200,{'profile_visibility':'public','activity_visibility':'public','timezone':'Europe/Berlin','theme':'dark'},token=token)
    c.call('PUT','/forum/account/images/avatar',200,{'data':base64.b64encode(profile_png()).decode()},token=token)
    result=c.call('GET',public)
    require(result['member']['name']=='Release Profile' and result['member']['fields']==[] and fixture['target'] not in [t['id'] for t in result['threads']],'Profile or ACL leak after upgrade')
    avatar=result['member']['avatar_url']
    with c.opener.open(base+avatar,timeout=10) as res:
        require(res.headers.get_content_type()=='image/jpeg' and res.read().startswith(b'\xff\xd8'),'Avatar was not reencoded')
    c.call('PATCH','/forum/account/preferences',200,{'profile_visibility':'private'},token=token)
    c.call('GET',public,404)
    try:
        c.opener.open(base+avatar,timeout=10)
        raise RuntimeError('Private avatar exposed')
    except urllib.error.HTTPError as err:
        require(err.code==404,'Unexpected private avatar response')
    require(c.call('GET',own,token=token)['values'][0]['field_value']=='private profile value','Private field lost')
    c.call('PATCH','/forum/account/preferences',200,{'profile_visibility':'public'},token=token)
    print('PASS profile privacy, image delivery and legacy user upgrade',flush=True)


def verify_identity(site, env, report, base):
    code = r'require "vendor/autoload.php"; (new CajeerEngine\Installer\InstallerEnvironment(".env"))->write(["FORUM_REGISTRATION"=>"true"], ".env.example");'
    run(site, env, report, 'open-registration', ['php','-r',code])
    c=Client(base); email=unique_email()
    reg=c.call('POST','/forum/register',201,{'name':'Acceptance member','email':email,'password':PASSWORD})
    require(reg['verification_required'], 'Email verification bypassed')
    c.login(email,status=401)
    _, token=mail_link(email,'/forum/verify-email',base)
    c.call('POST','/auth/email/verify',200,{'token':token,'password':PASSWORD})
    c.call('POST','/auth/email/verify',422,{'token':token,'password':PASSWORD})
    token=c.login(email)[0]['data']['access_token']
    c.call('GET','/auth/me',token=token)
    print('PASS dist registration and verified email',flush=True)


def verify_readiness(site, env, report, base):
    # Deliberately damage one checksum in this disposable database, then restore it.
    script = r'''require "vendor/autoload.php"; (new Symfony\Component\Dotenv\Dotenv())->usePutenv()->loadEnv(".env"); $db=new CajeerEngine\Database\DatabaseManager(new CajeerEngine\Runtime\ConfigRepository(getcwd())); $p=$db->connection(); $file=glob("migrations/".$db->migrationDriver()."/*.sql")[0]; $q=$p->prepare("UPDATE ce_migrations SET checksum=:checksum WHERE migration=:name"); $q->execute(["checksum"=>$argv[1]==="break"?str_repeat("0",64):hash_file("sha256",$file),"name"=>basename($file)]); if($q->rowCount()!==1)exit(1);'''
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
    run(site,env,report,'damage-ledger',['php','-r',script,'break'])
    try:
        try:
            opener.open(base+'/api/v1/health',timeout=10).close()
            raise RuntimeError('Damaged migration ledger accepted')
        except urllib.error.HTTPError as e:
            with e:
                require(e.code==503 and not json.load(e)['checks']['database'],'Incorrect readiness failure')
        run(site,env,report,'reject-damaged-postcheck',['php','tools/update-postcheck.php','verify'],expected=1)
    finally:
        run(site,env,report,'restore-ledger',['php','-r',script,'restore'])
    with opener.open(base+'/api/v1/health',timeout=10) as res:
        require(res.status==200 and json.load(res)['checks']['database'],'Readiness did not recover')
    print('PASS damaged ledger rejected and recovered',flush=True)


def verify_data_integrity(site, env, report, fixture):
    # Simulate a corrupt topic on the disposable installed dist, then remove it.
    # A post-update gate must reject actual data corruption even when schema and
    # migration checksums are intact. Never mutate a caller's production DB.
    script=r'''require "vendor/autoload.php"; (new Symfony\Component\Dotenv\Dotenv())->usePutenv()->loadEnv(".env"); $db=new CajeerEngine\Database\DatabaseManager(new CajeerEngine\Runtime\ConfigRepository(getcwd())); $p=$db->connection(); if ($argv[1]==="break") {$q=$p->prepare("INSERT INTO ce_forum_threads(id,board_id,title) VALUES(?,?,?)");$q->execute([$argv[2],$argv[3],"Incomplete release fixture"]);} else {$q=$p->prepare("DELETE FROM ce_forum_threads WHERE id=?");$q->execute([$argv[2]]);} if ($q->rowCount()!==1) exit(1);'''
    import uuid
    damaged=str(uuid.uuid4())
    run(site,env,report,'damage-forum-data',['php','-r',script,'break',damaged,fixture['board']])
    try:
        result=run(site,env,report,'reject-forum-data',['php','tools/update-postcheck.php','verify'],expected=1)
        require('empty_threads' in result,'Postcheck failed for the wrong reason')
    finally:
        run(site,env,report,'restore-forum-data',['php','-r',script,'restore',damaged,fixture['board']])
    run(site,env,report,'verify-restored-forum-data',['php','tools/update-postcheck.php','verify'])
    print('PASS physical dist rejects corrupt forum data and recovers',flush=True)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--artifact',type=Path,required=True)
    parser.add_argument('--previous',type=Path,help='Original 0.3.0 dist; enables real signed upgrade and rollback')
    parser.add_argument('--driver',choices=('sqlite','pgsql'),default='sqlite')
    parser.add_argument('--report',type=Path,required=True)
    args=parser.parse_args(); report=args.report.resolve(); report.mkdir(parents=True,exist_ok=True)
    artifact=args.artifact.resolve(); current=version(ROOT); env=env_for_test()
    with tempfile.TemporaryDirectory(prefix='forum-release-', dir='/tmp') as tmp:
        work=Path(tmp); port=free_port()
        # With --previous use the authentic baseline for upgrade, not a relabelled current tree.
        previous=args.previous.resolve() if args.previous else None
        initial='0.3.0' if previous else current
        site=unpack(previous or artifact,work/'live',initial)
        install(site,env,report,'initial',port,args.driver)
        with http(site,env,report,'initial',port,initial) as base:
            state=seed(base); verify_data(base,state)
            fixture=seed_structure(base,state); verify_structure(base,fixture)
            if not previous:
                verify_groups(base,state)
                verify_discussions(base,state)
                verify_navigation(base,state)
                verify_identity(site,env,report,base)
                verify_readiness(site,env,report,base)
                verify_data_integrity(site,env,report,fixture)
                verify_profiles(base,fixture)
        if previous:
            key=work/'backup.key'; secret=work/'release.secret'; public=work/'release.public'
            run(site,env,report,'backup-key',['php','bin/cajeer','backup:keygen','--key='+str(key)])
            run(site,env,report,'release-key',['php','bin/cajeer','release:keygen','--secret-key='+str(secret),'--public-key='+str(public)])
            env |= {'BACKUP_KEY_FILE':str(key),'UPDATE_TRUSTED_PUBLIC_KEY_FILE':str(public)}
            # Never modify the caller's artifact or its signature.
            package=work/'target.zip'; shutil.copy2(artifact,package)
            run(site,env,report,'sign-target',['php','bin/cajeer','release:sign','--package='+str(package),'--secret-key='+str(secret)])
            run(site,env,report,'dry-run',['php','bin/cajeer','update:apply','--package='+str(package),'--dry-run'])
            require(version(site)==initial,'Dry run changed release')
            update=json.loads(run(site,env,report,'upgrade',['php','bin/cajeer','update:apply','--package='+str(package),'--json'],timeout=600))
            require(update['state']=='committed' and version(site)==current,'Update not committed')
            with http(site,env,report,'updated',port,current) as base:
                verify_data(base,state); verify_structure(base,fixture); verify_groups(base,state); verify_discussions(base,state); verify_navigation(base,state); verify_identity(site,env,report,base)
                verify_data_integrity(site,env,report,fixture)
                verify_profiles(base,fixture)
            run(site,env,report,'rollback',['php','bin/cajeer','update:rollback','--yes','--json'],timeout=600)
            require(version(site)==initial,'Rollback did not restore release')
            with http(site,env,report,'rollback',port,initial) as base:
                verify_data(base,state); verify_structure(base,fixture)
            # Inject actual failing SQL into a separately built, correctly signed target.
            broken=unpack(artifact,work/'broken',current)
            (broken/'migrations'/args.driver/'9999_acceptance_failure.sql').write_text('CREATE TABLE ce_acceptance_failure(id INTEGER); THIS IS INVALID SQL;\n')
            out=work/'broken-artifacts'; out.mkdir()
            run(broken,env,report,'build-broken',['php','tools/build-release.php','--dist','--target-dir='+str(out),'--json'])
            pkg=out/f'cajeerengine-{current}-dist.zip'
            run(site,env,report,'sign-broken',['php','bin/cajeer','release:sign','--package='+str(pkg),'--secret-key='+str(secret)])
            failed=json.loads(run(site,env,report,'failed-upgrade',['php','bin/cajeer','update:apply','--package='+str(pkg),'--json'],expected=1,timeout=600))
            require(failed['state']=='rolled_back' and version(site)==initial and not (site/'storage/app/updates/active.json').exists(), 'Automatic rollback failed')
            with http(site,env,report,'failed-rollback',port,initial) as base:
                verify_data(base,state); verify_structure(base,fixture)
        print('PASS physical dist acceptance',flush=True)


if __name__=='__main__': main()
