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
import sqlite3
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


def verify_release_sequence(releases):
    if not 2 <= len(releases) <= 10:
        raise ValueError('Upgrade chain must have two to ten real releases')
    stable = r'(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)'
    if not all(re.fullmatch(stable, v) for v in releases[:-1]):
        raise ValueError('All historical upgrade stages must be stable releases')
    if not (re.fullmatch(stable, releases[-1]) or releases[-1] == '1.0.0-rc.1'):
        raise ValueError('Only an explicitly approved final RC target is allowed')
    keys = [tuple(map(int, v.split('-')[0].split('.'))) for v in releases]
    if not all(a < b for a, b in zip(keys, keys[1:])):
        raise ValueError('Upgrade chain must be strictly increasing with no duplicates')


def artifact_sha256(path):
    import hashlib
    with path.open('rb') as package:
        return hashlib.file_digest(package,'sha256').hexdigest()


def chain_receipt(releases, paths, git_sha, rollback, failed_migration):
    if not (rollback and failed_migration):
        raise RuntimeError('Incomplete upgrade/recovery certification')
    verify_release_sequence(releases)
    if len(paths)!=len(releases): raise ValueError('Artifact chain does not match versions')
    if not re.fullmatch('[a-f0-9]{40}',git_sha or ''):
        raise ValueError('Verified Git commit is mandatory for release chain evidence')
    # A receipt is created only by the real acceptance script after all probes.
    return {'schema':1,'edition':'forum','target_version':releases[-1],
            'git_commit':git_sha,
            'steps':[{'from':a,'to':b,'source_sha256':artifact_sha256(src),
                      'target_sha256':artifact_sha256(dst),'http_data_acl_media_fts_verified':True}
                     for a,b,src,dst in zip(releases,releases[1:],paths,paths[1:])],
            'rollback_to':releases[-2],'rollback_verified':True,
            'failed_migration_restored':True}


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
            last_status = {'reason': 'no HTTP response'}
            while time.monotonic() < deadline:
                require(proc.poll() is None, 'HTTP process stopped')
                try:
                    with opener.open(base + '/api/v1/health', timeout=3) as res:
                        data = json.load(res)
                        last_status = {'http_status': res.status,
                                       'status': data.get('status'),
                                       'edition_version': data.get('edition_version'),
                                       'checks': data.get('checks')}
                        if (data.get('edition_version') == expected_version
                                and data.get('version') == '1.3.0'
                                and data.get('status') == 'ok'
                                and all(data.get('checks', {}).values())):
                            break
                except urllib.error.HTTPError as exc:
                    try:
                        data = json.loads(exc.read(8192))
                        last_status = {'http_status': exc.code, 'status': data.get('status'),
                                       'edition_version': data.get('edition_version'),
                                       'checks': data.get('checks')}
                    except (ValueError, TypeError):
                        last_status = {'http_status': exc.code}
                except (urllib.error.URLError, TimeoutError) as exc:
                    last_status = {'reason': type(exc).__name__}
                time.sleep(.2)
            else:
                # Fail closed, but retain enough sanitized health evidence to
                # distinguish a maintenance lock, missing extension, bad DB,
                # or incorrect release identity without another blind CI run.
                # The HTTP middleware logs only allowlisted exception classes,
                # not secrets or arbitrary exception messages. Copy this narrow
                # diagnostic into CI evidence so failures after plugin activation
                # can be attributed to the actual runtime, rather than HTTP 500.
                runtime_log = site / 'storage/logs/runtime.jsonl'
                if runtime_log.is_file():
                    recent = []
                    for line in runtime_log.read_text(errors='replace').splitlines()[-300:]:
                        try:
                            item = json.loads(line)
                        except (ValueError, TypeError):
                            continue
                        if item.get('level') != 'error':
                            continue
                        context = item.get('context', {})
                        recent.append({'message': item.get('message'),
                                       'exception': context.get('exception') if isinstance(context, dict) else None})
                    last_status['runtime_errors'] = recent[-8:]
                (report / (label + '-readiness.json')).write_text(
                    json.dumps(last_status, indent=2, sort_keys=True) + '\n')
                raise RuntimeError('Physical dist HTTP readiness failed: '+json.dumps(last_status, sort_keys=True)[:1500])
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
    code = r'require "vendor/autoload.php"; (new CajeerEngine\Installer\InstallerEnvironment(".env"))->write(["CACHE_DRIVER"=>"file", "FORUM_REGISTRATION"=>"false", "FORUM_REQUIRE_EMAIL_VERIFICATION"=>"true", "FORUM_SMTP_HOST"=>"127.0.0.1", "FORUM_SMTP_PORT"=>"1025", "FORUM_SMTP_SECURITY"=>"plain", "FORUM_SMTP_FROM"=>"forum@example.test", "FORUM_SMTP_USERNAME"=>"", "FORUM_SMTP_PASSWORD"=>"", "FORUM_SPAM_NEW_PRIVATE_10MIN"=>"100", "FORUM_SPAM_NEW_THREADS_HOUR"=>"100", "FORUM_SPAM_THREADS_HOUR"=>"100", "FORUM_SPAM_NEW_REPLIES_10MIN"=>"200", "FORUM_SPAM_REPLIES_10MIN"=>"200", "RATE_LIMIT_AUTH"=>"80", "RATE_LIMIT_GLOBAL"=>"1000"], ".env.example");'
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


def verify_search_seo(base, state, fixture):
    """Read-only cross-surface check on the actual installed artifact, including rollback."""
    c=Client(base); _, thread, post, _=state
    path='/forum/threads/'+thread
    found=c.call('GET','/forum/search?q=Original%20content&mode=posts')
    require(any(r['post_id']==post and r['id']==thread and r['post_page']==1 for r in found),'Public post not discoverable after release transition')
    require(c.call('GET','/forum/search?q=ReleasePrivateMarker')==[],'Private text leaked into search')
    private=c.call('GET','/forum/search?q=ReleasePrivateMarker',token=fixture['token'])
    require(fixture['target'] in [r['id'] for r in private],'Authorized private search lost after transition')
    def page(url,status=200):
        try: response=c.opener.open(base+url,timeout=15)
        except urllib.error.HTTPError as e: response=e
        with response:
            require(response.status==status,f'{url}: unexpected HTTP {response.status}')
            return response.read().decode(),response.headers
    html,headers=page(path)
    require('Original content' in html and 'rel="canonical" href="'+base+path+'"' in html and 'index, follow' in headers.get('X-Robots-Tag',''),'SSR or canonical mismatch after release transition')
    hidden,_=page('/forum/threads/'+fixture['target'],404)
    require('ReleasePrivateMarker' not in hidden,'Private SSR leaked')
    sitemap,_=page('/forum/sitemap.xml?page=1')
    require(thread in sitemap and fixture['target'] not in sitemap and fixture['source'] not in sitemap,'Sitemap visibility or redirect mismatch')
    robots,_=page('/robots.txt');require(base+'/forum/sitemap.xml' in robots,'Forum sitemap discovery missing')
    print('PASS physical dist search/SSR/canonical/sitemap/ACL after release transition',flush=True)


def verify_rich_content(base,state):
    token,thread,post,_=state;c=Client(base);path='/forum/threads/'+thread
    original=c.call('GET',path)['posts'][0]
    require(original['body_format']=='plain' and original['body_html']=='Original content','Legacy content format changed')
    source='**Release rich text** <script>unsafe</script>'
    preview=c.call('POST','/forum/content/preview',200,{'body':source,'body_format':'markdown'},token=token)
    created=c.call('POST',path+'/posts',201,{'body':source,'body_format':'markdown'},token=token)['id']
    item=next(p for p in c.call('GET',path)['posts'] if p['id']==created)
    require(item['body_html']==preview['html'] and '<strong>Release rich text</strong>' in item['body_html'] and '<script>' not in item['body_html'],'Rich rendering failed')
    print('PASS physical dist rich content and legacy migration',flush=True)


def verify_attachments(base,state,fixture):
    c=Client(base);token=state[0];raw=b'Physical distribution attachment bytes.\n'
    a=c.upload_attachment('release.txt',raw,token);aid=a['id']
    path='/forum/threads/'+fixture['target'];post=c.call('POST',path+'/posts',201,{'body':'Release attachment','attachment_ids':[aid]},token=token)['id']
    item=next(p for p in c.call('GET',path,token=token)['posts'] if p['id']==post)
    require(item['attachments'][0]['id']==aid and c.download_attachment(aid,fixture['token'])==raw,'Attachment bytes or ACL changed')
    c.call('GET','/forum/attachments/'+aid+'/download',404)
    print('PASS physical dist private attachment upload, binding and download ACL',flush=True)


def seed_media(base,fixture):
    c=Client(base);token=fixture['token'];raw=profile_png();aid=c.upload_attachment('baseline.png',raw,token)['id'];tid=c.call('POST','/forum/boards/'+fixture['board']+'/threads',201,{'title':'Baseline private media','body':'image{'+aid+'}','attachment_ids':[aid]},token=token)['id']
    require(c.download_attachment(aid,token)==raw,'Baseline original upload failed')
    return {'id':aid,'thread':tid,'raw':raw}


def verify_media(base,state,fixture,media,variants=True):
    c=Client(base);aid=media['id'];token=fixture['token'];path='/forum/attachments/'+aid
    require(c.download_attachment(aid,token)==media['raw'],'Original image changed across upgrade/rollback')
    require(c.call('GET','/forum/threads/'+media['thread'],token=token)['posts'][0]['attachments'][0]['id']==aid,'Image binding changed')
    if variants:
        for _ in range(5):
            c.call('POST','/queue/work',200,{'limit':10},token=state[0])
            if c.call('GET',path,token=token)['variants']:break
        meta=c.call('GET',path,token=token)['variants'];require(set(meta)=={'thumbnail','medium','large'},'Legacy image variants not generated')
        require(c.image_variant(aid,token=token).startswith(b'\x89PNG'),'Invalid raster derivative');c.call('GET',path+'/image/thumbnail',404)
        require(c.call('GET','/forum/media/status',token=state[0])['processor_available'],'Missing media processor')
    print('PASS physical dist original and private image variants' if variants else 'PASS restored original image',flush=True)


def verify_polls_questions(base,state,fixture):
    c=Client(base);token=state[0];member=fixture['token'];board=fixture['board'];create='/forum/boards/'+board+'/threads'
    poll=c.call('POST',create,201,{'title':'Release poll','body':'Select','kind':'poll','poll':{'question':'Select release','options':['One','Two'],'visibility':'closed'}},token=token)['id'];path='/forum/threads/'+poll
    c.call('PUT',path+'/poll/vote',200,{'choices':[2]},token=member)
    require(not c.call('GET',path,token=member)['interaction']['poll']['results_visible'],'Hidden poll results leaked')
    c.call('PATCH',path+'/poll',200,{'closed':True},token=token);p=c.call('GET',path,token=member)['interaction']['poll'];require(p['voters']==1 and p['options'][1]['votes']==1,'Poll tally incorrect')
    q=c.call('POST',create,201,{'title':'Release question','body':'Question','kind':'question'},token=token)['id'];qp='/forum/threads/'+q
    post=c.call('POST',qp+'/posts',201,{'body':'Accepted answer'},token=member)['id'];c.call('PUT',qp+'/accepted-answer',200,{'post_id':post},token=token)
    require(c.call('GET',qp,token=member)['interaction']['question']['accepted_post_id']==post,'Answer not accepted')
    c.call('GET',qp,404);c.call('GET',qp+'/answers',404);require(q not in [x['id'] for x in c.call('GET','/forum/questions')],'Private question leaked')
    for _ in range(5):
        c.call('POST','/queue/work',200,{'limit':10},token=token)
        if any(n['kind']=='accepted' and n['post_id']==post for n in c.call('GET','/forum/notifications',token=member)['items']):break
    else:raise AssertionError('Accepted answer notification not delivered')
    print('PASS physical dist polls, Q&A, result visibility and private accepted notifications',flush=True)

def verify_drafts_revisions(base,state):
    import uuid
    c=Client(base);token,thread,post,_=state
    history=c.call('GET','/forum/posts/'+post+'/revisions',token=token)
    require(history[-1]['revision_number']==1 and history[-1]['body']=='Original content','Initial revision not preserved')
    did=str(uuid.uuid4());path='/forum/drafts/'+did
    saved=c.call('PUT',path,200,{'scope':'reply','target_id':thread,'revision_number':0,'payload':{'body':'Release draft','body_format':'markdown','attachment_ids':[]}},token=token)
    require(saved['revision_number']==1,'Draft was not saved')
    result=c.call('POST',path+'/publish',200,{'revision_number':1},token=token)
    require(c.call('POST',path+'/publish',200,{'revision_number':1},token=token)==result,'Duplicate draft publication')
    pp='/forum/posts/'+result['id'];c.call('PATCH',pp,200,{'body':'Release edited','expected_revision':1},token=token);c.call('PATCH',pp,409,{'body':'Stale edit','expected_revision':1},token=token)
    c.call('POST',pp+'/revisions/restore',200,{'revision_number':1,'expected_revision':2},token=token)
    revisions=c.call('GET',pp+'/revisions',token=token);require([r['revision_number'] for r in revisions]==[3,2,1] and revisions[0]['body']=='Release draft','Revision restore failed')
    print('PASS physical dist draft publication and revision migration/restore',flush=True)

def content_snapshot(base, f, token, admin):
    """Read persisted content through the production API (not a relabelled fixture)."""
    c=Client(base);poll='/forum/threads/'+f['poll'];question='/forum/threads/'+f['question']
    post=c.call('GET',poll,token=token)['posts'][0]
    require(post['id']==f['post'] and post['attachments'][0]['id']==f['image'],'Published draft/image binding lost')
    require('<strong>Edited poll</strong>' in post['body_html'],'Formatted rendering lost')
    draft=c.call('GET','/forum/drafts/'+f['pending'],token=token)
    receipt=c.call('GET','/forum/drafts/'+f['published'],token=token)
    require(receipt['result']['id']==f['poll'],'Publication receipt lost')
    require(c.call('POST','/forum/drafts/'+f['published']+'/publish',200,{'revision_number':1},token=token)==receipt['result'],'Receipt replay created duplicate')
    private='/forum/conversations/'+f['conversation']
    msg=c.call('GET',private,token=token)['messages'][0]
    require(msg['id']==f['message'] and msg['attachments'][0]['id']==f['private_file'],'Private attachment binding lost')
    history='/forum/posts/'+f['post']+'/revisions'
    private_history=private+'/messages/'+f['message']+'/revisions'
    c.call('GET',poll,404);c.call('GET',question,404);c.call('GET','/forum/attachments/'+f['image']+'/image/thumbnail',404)
    c.call('GET','/forum/drafts/'+f['pending'],404,token=admin)
    c.call('GET',private_history,403,token=admin)
    c.call('GET','/forum/attachments/'+f['private_file']+'/download',404)
    return {'post':{k:post[k] for k in ('id','body','body_format','revision_number')},
            'history':c.call('GET',history,token=token),'poll':c.call('GET',poll,token=token)['interaction']['poll'],
            'question':c.call('GET',question,token=token)['interaction']['question'],
            'answers':[(x['id'],x['score']) for x in c.call('GET',question+'/answers',token=token)],
            'draft':draft,'receipt':receipt,'private_history':c.call('GET',private_history,token=token),
            'image':c.download_attachment(f['image'],token),'preview':c.image_variant(f['image'],token=token),
            'pending_file':c.download_attachment(f['pending_file'],token),'private_file':c.download_attachment(f['private_file'],token)}


def seed_content(base, state, fixture):
    import uuid
    c=Client(base);admin=state[0];token=fixture['token'];board=fixture['board'];create='/forum/boards/'+board+'/threads'
    image=c.upload_attachment('stable.png',profile_png(),token)['id']
    published=str(uuid.uuid4());path='/forum/drafts/'+published
    c.call('PUT',path,200,{'scope':'thread','target_id':board,'revision_number':0,'payload':{'title':'Content stable poll','body':'**Original poll** image{'+image+'}','body_format':'markdown','attachment_ids':[image],'kind':'poll','poll':{'question':'Stable choice','options':['One','Two'],'max_choices':1,'allow_change':True,'visibility':'always'}}},token=token)
    poll=c.call('POST',path+'/publish',200,{'revision_number':1},token=token)['id'];pp='/forum/threads/'+poll
    post=c.call('GET',pp,token=token)['first_post_id']
    c.call('PATCH','/forum/posts/'+post,200,{'body':'**Edited poll** image{'+image+'}','expected_revision':1,'edit_reason':'Release snapshot'},token=token)
    c.call('PUT',pp+'/poll/vote',200,{'choices':[2]},token=admin)
    question=c.call('POST',create,201,{'title':'Content stable question','body':'Question','kind':'question'},token=token)['id'];qp='/forum/threads/'+question
    answer=c.call('POST',qp+'/posts',201,{'body':'[b]Stable answer[/b]','body_format':'bbcode'},token=admin)['id']
    c.call('PUT','/forum/posts/'+answer+'/answer-vote',200,{'enabled':True},token=token)
    c.call('PUT',qp+'/accepted-answer',200,{'post_id':answer},token=token)
    pending_file=c.upload_attachment('pending.txt',b'Pending content snapshot',token)['id'];pending=str(uuid.uuid4())
    c.call('PUT','/forum/drafts/'+pending,200,{'scope':'reply','target_id':question,'revision_number':0,'payload':{'body':'Pending answer','body_format':'markdown','attachment_ids':[pending_file]}},token=token)
    private_file=c.upload_attachment('private.txt',b'Private content snapshot',token)['id'];admin_id=c.call('GET','/auth/me',token=admin)['user_id']
    private=c.call('POST','/forum/conversations',201,{'kind':'direct','title':'Content snapshot','participant_ids':[admin_id],'body':'[b]Private original[/b]','body_format':'bbcode','attachment_ids':[private_file]},token=token)
    c.call('PATCH','/forum/conversations/'+private['id']+'/messages/'+private['message_id'],200,{'body':'[b]Private edited[/b]','expected_revision':1},token=token)
    for _ in range(8):
        c.call('POST','/queue/work',200,{'limit':10},token=admin)
        if c.call('GET','/forum/attachments/'+image,token=token)['variants']:break
    else:raise AssertionError('Baseline derivative was not generated')
    f={'poll':poll,'post':post,'image':image,'published':published,'pending':pending,'pending_file':pending_file,'question':question,'conversation':private['id'],'message':private['message_id'],'private_file':private_file}
    f['snapshot']=content_snapshot(base,f,token,admin)
    print('PASS seeded full content platform before upgrade',flush=True)
    return f


def verify_content(base, state, fixture, f):
    require(content_snapshot(base,f,fixture['token'],state[0])==f['snapshot'],'Content platform snapshot changed across update/rollback')
    print('PASS content platform snapshot: drafts, receipts, revisions, poll votes, Q&A, originals, derivatives and private history',flush=True)


def verify_moderation(base,state,fixture):
    c=Client(base);admin=state[0]
    # The private upgrade fixture is a delegated moderator and bypasses review.
    email=unique_email();c.call('POST','/users',201,{'email':email,'name':'Acceptance review author','password':PASSWORD,'roles':['forum-member']},token=admin);token=c.login(email)[0]['data']['access_token']
    category=c.call('POST','/forum/categories',201,{'name':'Acceptance review category','description':''},token=admin)['id'];board=c.call('POST','/forum/categories/'+category+'/boards',201,{'name':'Acceptance review board','description':''},token=admin)['id'];policy='/forum/boards/'+board+'/review-policy'
    old=c.call('GET','/forum/moderation/cases/report/'+fixture['report'],token=admin)['item'];require(old['reason']=='Release confidential report' and old['post_id']==fixture['post'],'Original report lost')
    c.call('PUT',policy,200,{'review_required':True},token=admin)
    try:
        file=c.upload_attachment('moderation.txt',b'Acceptance moderation',token)['id'];r=c.call('POST','/forum/boards/'+board+'/threads',201,{'title':'Moderated topic','body':'**Pending review**','body_format':'markdown','attachment_ids':[file]},token=token)
        require(r['pending_review'],'Submission bypassed review');sid=r['submission_id'];path='/forum/moderation/cases/submission/'+sid;c.call('GET','/forum/threads/'+sid,404,token=token);c.call('GET',path,403,token=token)
        r=c.call('POST',path,200,{'action':'approve','revision_number':1},token=admin);require(r['status']=='approved','Approval failed');topic='/forum/threads/'+r['result_id'];data=c.call('GET',topic,token=token);require(data['posts'][0]['body']=='**Pending review**' and c.download_attachment(file,token)==b'Acceptance moderation','Approval lost content or bytes')
        report=c.call('POST','/forum/posts/'+data['first_post_id']+'/report',201,{'reason':'Acceptance evidence','category':'other'},token=admin)['id'];rp='/forum/moderation/cases/report/'+report;require(c.call('GET',rp,token=admin)['item']['snapshot_body']=='**Pending review**','Report snapshot missing');c.call('POST',rp,200,{'action':'resolve','revision_number':1,'reason':'Accepted evidence','disposition':'resolved'},token=admin)
    finally:c.call('PUT',policy,200,{'review_required':False},token=admin)
    print('PASS moderation queue, approval, attachment and report evidence',flush=True)

def verify_antispam(base,state):
    admin=state[0];c=Client(base);email=unique_email();c.call('POST','/users',201,{'email':email,'name':'Acceptance spam member','password':PASSWORD,'roles':['forum-member']},token=admin)
    member=c.login(email)[0]['data']['access_token'];board=c.call('GET','/forum')['boards'][0]['id'];path='/forum/boards/'+board+'/threads'
    text='Acceptance duplicate publication content with enough characters to be fingerprinted by the real anti-spam service.'
    c.call('POST',path,201,{'title':'Acceptance clean','body':text},token=member)
    queued=c.call('POST',path,201,{'title':'Acceptance duplicate','body':text},token=member);require(queued.get('pending_review'), 'Duplicate bypassed moderation')
    c.call('GET','/forum/threads/'+queued['submission_id'],404);case='/forum/moderation/cases/submission/'+queued['submission_id'];row=c.call('GET',case,token=admin)['item'];c.call('POST',case,200,{'action':'reject','revision_number':row['revision_number'],'reason':'Duplicate'},token=admin)
    require(any(x['reason']=='duplicate' for x in c.call('GET','/forum/antispam/status',token=admin)['review_reasons']),'Missing spam diagnostics')


def verify_sanctions(base,state):
    c=Client(base);admin=state[0];email=unique_email();c.call('POST','/users',201,{'email':email,'name':'Acceptance sanction member','password':PASSWORD,'roles':['forum-member']},token=admin)
    member=c.login(email)[0]['data']['access_token'];uid=c.call('GET','/auth/me',token=member)['user_id'];board=c.call('GET','/forum')['boards'][0]['id'];path='/forum/boards/'+board+'/threads'
    sanction=c.call('POST','/forum/members/'+uid+'/sanctions',201,{'kind':'posting','board_id':board,'reason':'Acceptance posting restriction','duration_seconds':3600},token=admin)['id'];c.call('POST',path,403,{'title':'Blocked publication','body':'Blocked'},token=member)
    c.call('POST','/forum/sanctions/'+sanction+'/appeal',201,{'body':'Acceptance appeal'},token=member);c.call('POST','/forum/sanctions/'+sanction+'/appeal/decision',200,{'status':'accepted','reason':'Acceptance decision'},token=admin)
    c.call('POST',path,201,{'title':'Restored publication','body':'Restored'},token=member);require(not c.call('GET','/forum/sanctions',token=member)[0]['active'],'Revoked sanction still active')
    print('PASS sanction enforcement and appeal restores publication',flush=True)

def verify_recycle(base,state,fixture,f):
    c=Client(base);admin=state[0];token=fixture['token'];poll='/forum/threads/'+f['poll'];path='/forum/recycle-bin/thread/'+f['poll']
    generation=c.call('POST',path,200,{'action':'delete','reason':'Acceptance restore'},token=admin)['deletion_id']
    c.call('GET',poll,404,token=token);c.call('GET','/forum/attachments/'+f['image'],404,token=token)
    c.call('GET','/forum/posts/'+f['post']+'/revisions',404,token=token)
    require(c.call('GET',path,token=admin)['posts'][0]['id']==f['post'],'Trash lost original message')
    c.call('PUT',poll+'/poll/vote',404,{'choices':[1]},token=admin)
    c.call('POST',path,200,{'action':'restore','deletion_id':generation},token=admin)
    verify_content(base,state,fixture,f)
    print('PASS recycle on existing content: same UUID, revisions, votes, attachment, derivative and receipt',flush=True)


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
    """Create real 0.5.1 structure before updating, not just after the upgrade."""
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


def verify_read_tracking(base, state):
    admin, thread, post, _ = state; c=Client(base)
    url='/forum/threads/'+thread; mark='/forum/read-state'
    d=c.call('GET',url,token=admin)
    require(d['read_state']['unread_posts']==2 and d['posts'][0]['id']==post,'Legacy posts must be unread and preserve IDs')
    first=d['posts'][0]
    result=c.call('POST',mark,200,{'scope':'thread','id':thread,'post_id':post,'sequence':int(first['read_sequence'])},token=admin)
    require(result['unread_posts']==1 and result['first_unread']['id']==d['posts'][1]['id'],'Partial acknowledgement failed')
    c.call('POST',mark,200,{'scope':'thread','id':thread},token=admin)
    require(c.call('GET',url,token=admin)['read_state']['unread_posts']==0,'Read watermark not persisted')
    print('PASS physical dist read tracking and legacy sequence backfill',flush=True)


def verify_notifications(base, state, fixture):
    c=Client(base); admin,thread,post,_=state
    # The original 0.5.0 topic has no synthetic historical notifications.
    path='/forum/notifications'
    c.call('POST','/queue/work',200,{'limit':10},token=admin)
    c.call('POST',path+'/read',200,{'all':True},token=admin)
    require(c.call('GET',path,token=admin)['unread']==0,'Unexpected unread notification')
    reply=c.call('POST','/forum/threads/'+thread+'/posts',201,{'body':'Release notification reply'},token=fixture['token'])['id']
    result=c.call('POST','/queue/work',200,{'limit':10},token=admin)
    require(result['failed']==0 and result['forum_notifications_processed']>0,'Outbox did not run')
    data=c.call('GET',path,token=admin)
    require(data['unread']==1 and any(i['post_id']==reply and not i['is_read'] for i in data['items']),'Physical dist notification failed')
    item=next(i for i in data['items'] if i['post_id']==reply); require(c.call('GET',path+'/'+item['id'],token=admin)['thread_id']==thread,'Notification target failed')
    c.call('POST',path+'/read',200,{'id':item['id']},token=admin)
    require(c.call('GET',path+'?count_only=1',token=admin)['unread']==0,'Notification mark failed')
    print('PASS physical dist notification outbox and personal inbox',flush=True)


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


def verify_watches(base,state):
    c=Client(base);admin,thread,_,_=state
    email=unique_email(); c.call('POST','/forum/register',201,{'name':'Mail acceptance','email':email,'password':PASSWORD})
    _,token=mail_link(email,'/forum/verify-email',base)
    c.call('POST','/auth/email/verify',200,{'token':token,'password':PASSWORD})
    watcher=c.login(email)[0]['data']['access_token']
    path='/forum/threads/'+thread
    require(c.call('GET',path,token=admin)['thread']['watch_mode']=='internal','Legacy watch mode not preserved')
    c.call('PUT',path+'/watch',200,{'enabled':True,'mode':'email'},token=watcher)
    c.call('POST',path+'/posts',201,{'body':'Physical dist email update'},token=admin)
    result=c.call('POST','/queue/work',200,{'limit':10},token=admin)
    require(result['forum_mail']['sent']>=1 and result['failed']==0,'Physical dist SMTP delivery failed')
    _,token=mail_link(email,'/forum/unsubscribe',base)
    c.call('POST','/forum/unsubscribe',200,{'token':token})
    require(c.call('GET',path,token=watcher)['thread']['watch_mode']=='internal','Physical dist unsubscribe failed')
    print('PASS physical dist email delivery and signed unsubscribe',flush=True)


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


def verify_conversations(base,state):
    admin,_,_,_=state;c=Client(base)
    email=unique_email(); member=c.call('POST','/users',201,{'email':email,'name':'Private acceptance member','password':PASSWORD,'roles':['forum-member']},token=admin)['id']
    token=c.login(email)[0]['data']['access_token'];path='/forum/conversations'
    result=c.call('POST',path,201,{'participant_ids':[member],'title':'Physical private conversation','body':'Private acceptance message'},token=admin)
    url=path+'/'+result['id'];c.call('GET',url,401)
    require(c.call('GET',url,token=token)['messages'][0]['body']=='Private acceptance message','Private history not persisted')
    require(c.call('GET',path,token=token)['unread']==1,'Missing conversation unread')
    c.call('POST',url+'/read',200,{'message_id':result['message_id']},token=token)
    require(c.call('GET',path,token=token)['unread']==0,'Conversation watermark not persisted')
    c.call('POST',url+'/leave',200,token=token);c.call('GET',url,404,token=token)
    print('PASS physical dist private conversations and membership revocation',flush=True)


def verify_activity(base,state,fixture):
    c=Client(base);admin,thread,post,_=state;author=c.call('GET','/auth/me',token=admin)['user_id'];voter=fixture['token']
    profile='/forum/members/'+author;reaction='/forum/posts/'+post+'/reaction'
    before=c.call('GET',profile)['member']['reputation']['reputation']
    for _ in range(2): c.call('PUT',reaction,200,{'enabled':True,'type':'helpful'},token=voter)
    require(c.call('GET',profile)['member']['reputation']['reputation']==before+2,'Reputation not idempotent')
    require(c.call('GET','/forum/threads/'+thread,token=voter)['posts'][0]['my_reaction']=='helpful','Typed reaction missing')
    c.call('PUT',profile+'/relationship',200,{'kind':'follow','enabled':True},token=voter)
    require(any(x['thread_id']==thread for x in c.call('GET','/forum/activity?scope=following',token=voter)),'Following feed missing')
    c.call('PUT',profile+'/relationship',200,{'kind':'ignore','enabled':True},token=voter)
    require(not any(x['thread_id']==thread for x in c.call('GET','/forum/activity?scope=following',token=voter)),'Ignored feed leak')
    c.call('PUT',reaction,200,{'enabled':False},token=voter)
    require(c.call('GET',profile)['member']['reputation']['reputation']==before,'Reaction removal not reflected')
    require(not any(x['thread_id']==fixture['target'] for x in c.call('GET','/forum/activity')),'Private forum activity leaked')
    print('PASS physical dist reputation and activity',flush=True)


def seed_community(base,state):
    """Persist a representative community before upgrade, not merely exercise new code."""
    c=Client(base);admin=state[0];email=unique_email()
    user=c.call('POST','/users',201,{'email':email,'name':'Community baseline','password':PASSWORD,'roles':['forum-member']},token=admin)['id']
    c.call('POST','/auth/email/resend',202,{'email':email})
    _,code=mail_link(email,'/forum/verify-email',base)
    c.call('POST','/auth/email/verify',200,{'token':code,'password':PASSWORD})
    token=c.login(email)[0]['data']['access_token']
    owner=c.call('GET','/auth/me',token=admin)['user_id']
    own='/forum/account/profile'
    field=c.call('POST','/forum/profile-fields',201,{'label':'Community baseline private field','visibility':'private'},token=admin)['id']
    c.call('PATCH',own,200,{'display_name':'Community baseline profile','bio':'Persistent community bio','signature':'Persistent signature','fields':[{'id':field,'value':'Private baseline value'}]},token=token)
    c.call('PATCH','/forum/account/preferences',200,{'theme':'dark','timezone':'Europe/Berlin','activity_visibility':'public'},token=token)
    c.call('PUT','/forum/account/images/avatar',200,{'data':base64.b64encode(profile_png()).decode()},token=token)
    board=c.call('GET','/forum')['boards'][0]['id']
    thread=c.call('POST',f'/forum/boards/{board}/threads',201,{'title':'Community baseline topic','body':'Persistent community post'},token=token)['id']
    url='/forum/threads/'+thread
    post=c.call('GET',url,token=token)['posts'][0]['id']
    c.call('POST','/forum/read-state',200,{'scope':'thread','id':thread},token=token)
    c.call('PUT','/forum/posts/'+post+'/reaction',200,{'enabled':True,'type':'helpful'},token=admin)
    c.call('PUT',url+'/watch',200,{'enabled':True,'mode':'email'},token=token)
    reply=c.call('POST',url+'/posts',201,{'body':'Persistent reply'},token=admin)['id']
    work=c.call('POST','/queue/work',200,{'limit':10},token=admin)
    require(work['failed']==0 and work['forum_mail']['sent']>=1,'Baseline email delivery did not run')
    mail_link(email,'/forum/unsubscribe',base)
    c.call('PUT',url+'/watch',200,{'enabled':True,'mode':'weekly'},token=token)
    c.call('POST',url+'/posts',201,{'body':'Pending weekly digest'},token=admin)
    c.call('PUT','/forum/members/'+owner+'/relationship',200,{'kind':'follow','enabled':True},token=token)
    conv=c.call('POST','/forum/conversations',201,{'kind':'direct','title':'Community baseline private','participant_ids':[user],'body':'Persistent private message'},token=admin)
    cid=conv['id']; c.call('PATCH','/forum/conversations/'+cid+'/settings',200,{'muted':True},token=token)
    f={'token':token,'user':user,'owner':owner,'thread':thread,'post':post,'reply':reply,'conversation':cid,'field':field,'admin':admin,'email':email}
    f['snapshot']=community_snapshot(base,f)
    require(f['snapshot']['delivery']['sent']==1 and f['snapshot']['delivery']['pending']==1,'Expected sent and pending delivery before update')
    require(f['snapshot']['read']['unread_posts']==2 and f['snapshot']['private_unread']==1,'Expected baseline unread state')
    require(f['snapshot']['reputation']['reputation']==2,'Expected baseline reputation')
    require(any(n['post_id']==reply for n in f['snapshot']['notifications']),'Baseline reply notification missing')
    print('PASS seeded real community data, sent email and pending digest',flush=True)
    return f


def community_snapshot(base,f):
    c=Client(base);token=f['token'];profile='/forum/members/'+f['user'];url='/forum/threads/'+f['thread'];conv='/forum/conversations/'+f['conversation']
    own=c.call('GET','/forum/account/profile',token=token);public=c.call('GET',profile)['member']
    with c.opener.open(base+public['avatar_url'],timeout=10) as res:
        require(res.headers.get_content_type()=='image/jpeg','Stored avatar is not JPEG')
        avatar=__import__('hashlib').sha256(res.read()).hexdigest()
    thread=c.call('GET',url,token=token); inbox=c.call('GET','/forum/notifications',token=token)
    private=c.call('GET',conv,token=token)
    for message in private['messages']:
        if 'body_format' in message:
            require(message['body_format']=='plain' and isinstance(message['body_html'],str),'Legacy private format changed')
            for key in ('body_format','body_html','renderer_version','attachments','revision_number'): message.pop(key,None)
    return {'profile':own['profile'],'preferences':own['preferences'],'fields':own['values'],'avatar':avatar,
            'read':thread['read_state'],'watch':thread['thread']['watch_mode'],'posts':[(p['id'],p['body']) for p in thread['posts']],
            'delivery':c.call('GET','/forum/watch-settings',token=token)['delivery'],
            'notifications':inbox['items'],'notification_unread':inbox['unread'],'private_unread':c.call('GET','/forum/conversations',token=token)['unread'],
            'muted_unread':inbox['conversation_unread'],'conversation':private,
            'relationship':c.call('GET','/forum/members/'+f['owner']+'/relationship',token=token),'reputation':public['reputation']}


def verify_community(base,f):
    actual=community_snapshot(base,f)
    for key,expected in f['snapshot'].items():
        require(actual[key]==expected,'Community upgrade/rollback changed '+key)
    c=Client(base); token=f['token'];conv='/forum/conversations/'+f['conversation'];profile='/forum/members/'+f['user']
    c.call('GET',conv,401)
    require(c.call('GET',profile)['member']['fields']==[],'Private profile field leaked')
    require(c.call('GET','/forum/notifications?count_only=1',token=token)['conversation_unread']==0,'Muted conversation became an alert')
    print('PASS persisted community profile/avatar/read/notification/mail/conversation/reputation/follow state',flush=True)


def verify_community_live(base,f):
    c=Client(base); token=f['token']; admin=f['admin'];path='/forum/conversations/'+f['conversation']
    count=c.call('GET','/forum/notifications?count_only=1',token=token)
    require(count['conversation_total_unread']==1 and count['conversation_unread']==0,'Separate unread and muted counters drift')
    c.call('PUT','/forum/conversation-blocks/'+f['owner'],200,{'enabled':True},token=token)
    msg=f['snapshot']['conversation']['messages'][0]['id']
    c.call('PATCH',path+'/messages/'+msg,403,{'body':'Blocked modification'},token=admin)
    c.call('POST',path+'/messages',403,{'body':'Blocked send'},token=admin)
    require(c.call('GET',path,token=token)['messages'][0]['body']=='Persistent private message','Blocked sender changed content')
    c.call('PUT','/forum/conversation-blocks/'+f['owner'],200,{'enabled':False},token=token)
    c.call('POST',path+'/read',200,{'message_id':msg},token=token)
    require(c.call('GET','/forum/notifications?count_only=1',token=token)['conversation_total_unread']==0,'Read did not synchronize')
    c.call('PATCH',path+'/settings',200,{'muted':False},token=token)
    c.call('POST',path+'/messages',201,{'body':'Post-upgrade private reply'},token=admin)
    require(c.call('GET','/forum/notifications?count_only=1',token=token)['conversation_unread']==1,'Post-upgrade private notification missing')
    c.call('PUT','/forum/threads/'+f['thread']+'/watch',200,{'enabled':True,'mode':'internal'},token=token)
    require(c.call('GET','/forum/watch-settings',token=token)['delivery']['pending']==0,'Changing watch left pending mail')
    print('PASS post-upgrade cross-module community operations',flush=True)


def verify_migration_recovery(site, env, report, work, port, current, state, ecosystem=None):
    # Exercise the actual encrypted recovery set, not a mock backup id.
    source=work/'migration-source.sqlite'
    with sqlite3.connect(source) as db:
        db.executescript((HERE/'multi_forum_fixture.sql').read_text())
    files=work/'migration-files'; (files/'1').mkdir(parents=True)
    (files/'1'/('1001-'+'b'*32+'.data')).write_bytes(b'hello world!')
    key=work/'migration-backup.key'
    run(site,env,report,'migration-backup-key',['php','bin/cajeer','backup:keygen','--key='+str(key)])
    menv=env | {'BACKUP_KEY_FILE':str(key),'FORUM_IMPORT_SOURCE_DSN':'sqlite:'+str(source)}
    opts=['--platform=xenforo','--source-version=2.3.0','--snapshot=acceptance-recovery','--files='+str(files),'--json']
    run(site,menv,report,'migration-maintenance',['php','bin/cajeer','maintenance:on'])
    plan=json.loads(run(site,menv,report,'migration-plan',['php','bin/cajeer','forum:import:plan',*opts]))
    require(plan['ok'],'Migration plan failed')
    partial=json.loads(run(site,menv,report,'migration-partial',['php','bin/cajeer','forum:import:run',*opts,'--confirm=import-xenforo','--steps=1','--batch=1']))
    job=partial['job']; require(job['state']!='complete','Expected incomplete migration')
    run(site,menv,report,'migration-fence',['php','bin/cajeer','maintenance:off'],expected=1)
    run(site,menv,report,'migration-backup-verify',['php','bin/cajeer','backup:verify','--id='+job['backup_id']])
    run(site,menv,report,'migration-resume',['php','bin/cajeer','forum:import:run',*opts,'--confirm=import-xenforo','--steps=1','--batch=1','--verify-backup=1','--progress-only=1'])
    run(site,menv,report,'migration-restore',['php','bin/cajeer','backup:restore','--id='+job['backup_id'],'--yes'],timeout=600)
    run(site,menv,report,'migration-restored-job-absent',['php','bin/cajeer','forum:import:status','--job='+job['id'],'--json'],expected=1)
    run(site,menv,report,'migration-reopen',['php','bin/cajeer','maintenance:off'])
    with http(site,env,report,'migration-recovered',port,current) as base:
        verify_data(base,state)
        if ecosystem:verify_ecosystem(base,state,ecosystem,current)
    before=json.loads(run(site,env,report,'migration-extension-counts-before',['php','bin/cajeer','forum:extensions:check','--json']))['counts']
    run(site,menv,report,'migration-complete-maintenance',['php','bin/cajeer','maintenance:on'])
    completed=json.loads(run(site,menv,report,'migration-complete',['php','bin/cajeer','forum:import:run',*opts,'--confirm=import-xenforo','--steps=1000','--batch=100'],timeout=600))
    require(completed['job']['state']=='complete','Physical import not complete')
    run(site,menv,report,'migration-complete-reopen',['php','bin/cajeer','maintenance:off'])
    after=json.loads(run(site,env,report,'migration-extension-counts-after',['php','bin/cajeer','forum:extensions:check','--json']))['counts']
    require(before==after,'Historical migration emitted ordinary plugin events')
    run(site,env,report,'migration-ecosystem-check',['php','bin/cajeer','forum:ecosystem:check','--deep','--json'])
    with http(site,env,report,'migration-complete-http',port,current) as base:
        if ecosystem:verify_ecosystem(base,state,ecosystem,current)
        try:
            urllib.request.urlopen(base+'/forum/legacy/'+completed['job']['id']+'/topics/20',timeout=20)
            raise RuntimeError('Quarantined legacy URL exposed')
        except urllib.error.HTTPError as e:
            require(e.code==404 and 'Location' not in e.headers,'Quarantined migration leaked URL')



def install_ecosystem_plugins(site, env, report, work, initial):
    trust=work/'extension-trust'; trust.mkdir()
    secret=work/'extension.secret'; key=work/'ecosystem-backup.key'
    run(site,env,report,'ecosystem-key',['php','bin/cajeer','release:keygen','--secret-key='+str(secret),'--public-key='+str(trust/'cajeer.pub')])
    run(site,env,report,'ecosystem-backup-key',['php','bin/cajeer','backup:keygen','--key='+str(key)])
    env |= {'EXTENSIONS_TRUST_DIR':str(trust),'BACKUP_KEY_FILE':str(key)}
    for name in ('forum-announcements','forum-topic-templates'):
        package=work/name; shutil.copytree(ROOT/'examples/extensions'/name,package)
        if initial=='0.7.7' and name=='forum-topic-templates':
            manifest=package/'cajeer.extension.json'; data=json.loads(manifest.read_text());data['version']='1.0.0';data['forum']='>=0.7.4 <0.8.0';manifest.write_text(json.dumps(data))
        run(site,env,report,'sign-'+name,['php','bin/cajeer','extension:sign',str(package),'--secret-key='+str(secret)])
        run(site,env,report,'install-'+name,['php','bin/cajeer','extension:package:install',str(package),'--enable'],timeout=600)


def seed_ecosystem(base, state):
    c=Client(base); admin=state[0]; email=unique_email()
    user=c.call('POST','/users',201,{'email':email,'name':'Release design delegate','password':PASSWORD,'roles':['forum-member']},token=admin)['id']
    c.call('PUT','/forum/acp/delegation/'+user,200,{'scopes':['forum:acp:settings']},token=admin)
    token=c.login(email,PASSWORD)[0]['data']['access_token']
    policy=c.call('GET','/forum/acp?view=settings',token=token)
    values=dict(policy['values']); values.pop('paused'); values.pop('registration_open');values['name']='Release ecosystem forum'
    policy=c.call('PUT','/forum/acp/policy',200,{'revision':policy['revision'],'values':values},token=token)
    theme=c.call('GET','/forum/acp?view=themes',token=token); design=theme['presets']['ocean'];design['name']='Release Ocean';design['logo']='RO';design['scheme']='light'
    theme=c.call('PUT','/forum/acp/theme',200,{'revision':theme['revision'],'action':'draft','design':design},token=token)
    theme=c.call('PUT','/forum/acp/theme',200,{'revision':theme['revision'],'action':'activate'},token=token)
    action='/extensions/actions/cajeer--forum-announcements/'
    before=c.call('GET',action+'overview',token=admin)['data']['announcement']
    c.call('POST',action+'save',200,{'revision':int(before['revision']),'title':'Release ecosystem announcement','body':'Persistent extension data','starts_at':0,'ends_at':0},token=admin)
    return {'token':token,'user':user,'policy':policy,'theme':theme,'design':design}


def verify_ecosystem(base, state, saved, current):
    c=Client(base); admin=state[0]; token=saved['token']
    caps=c.call('GET','/forum/acp?view=access',token=token)['capabilities'];require(caps['settings'] and not caps['admin'] and not caps['members'],'Delegate privileges changed')
    c.call('GET','/forum/acp?view=imports',403,token=token)
    require(c.call('GET','/forum/acp?view=settings',token=token)==saved['policy'],'Community policy not preserved')
    theme=c.call('GET','/forum/acp?view=themes',token=token);require(theme['active']==saved['design'] and theme['revision']==saved['theme']['revision'],'Published theme not preserved')
    announcement=c.call('GET','/extensions/actions/cajeer--forum-announcements/overview',token=admin)['data']['announcement']
    require(announcement['title']=='Release ecosystem announcement' and announcement['body']=='Persistent extension data','Extension state lost')
    runtime=c.call('GET','/extensions/runtime',token=admin);require(len(runtime['forum']['panels'])>=3 and len(runtime['forum']['listeners'])>=2,'Extension SDK not booted')
    with urllib.request.urlopen(base+'/forum',timeout=20) as res:
        html=res.read().decode();require('Release ecosystem announcement' in html and 'Release ecosystem forum' in html and '--bg:#081b23' in html ,'SSR lost theme or plugin panels')
    board=next(b['id'] for b in c.call('GET','/forum')['boards'] if b.get('node_type','forum')!='container')
    with urllib.request.urlopen(base+'/forum/boards/'+board,timeout=20) as res:
        require('forum-extension-topic-templates' in res.read().decode(),'Topic template composer not rendered')
    if tuple(map(int,current.split('-')[0].split('.'))) >= (0,8,0):
        c.call('POST','/forum/acp/maintenance',403,{'action':'ecosystem_check'},token=token)
        require(c.call('POST','/forum/acp/maintenance',200,{'action':'ecosystem_check'},token=admin)['ok'],'ACP ecosystem check failed')
    print('PASS physical ACP delegation, policy, published theme and signed plugin state',flush=True)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--artifact',type=Path,required=True)
    parser.add_argument('--previous',type=Path,help='Authentic historical signed-update baseline dist')
    parser.add_argument('--intermediate',type=Path,action='append',default=[],help='Authentic intermediate dist, oldest first; each is installed with the real updater')
    parser.add_argument('--driver',choices=('sqlite','pgsql'),default='sqlite')
    parser.add_argument('--report',type=Path,required=True)
    args=parser.parse_args(); report=args.report.resolve(); report.mkdir(parents=True,exist_ok=True)
    artifact=args.artifact.resolve(); current=version(ROOT); env=env_for_test()
    rc_target = current == '1.0.0-rc.1'
    with tempfile.TemporaryDirectory(prefix='forum-release-', dir='/tmp') as tmp:
        work=Path(tmp); port=free_port()
        # With --previous use the authentic baseline for upgrade, not a relabelled current tree.
        previous=args.previous.resolve() if args.previous else None
        initial=current
        if previous:
            with zipfile.ZipFile(previous) as z: initial=json.loads(z.read('cajeerengine/release.json'))['edition_version']
            require(initial in ('0.5.5','0.5.6','0.6.0','0.6.1','0.6.2','0.6.3','0.6.4','0.6.5','0.6.6','0.7.0','0.7.1','0.7.2','0.7.3','0.7.4','0.7.5','0.7.6','0.7.7','0.8.0','0.8.1','0.8.2','0.8.3','0.8.4','0.8.5','0.8.6','0.8.7','0.9.0','0.9.1','0.9.2','0.9.3','0.9.4','0.9.5','0.9.6','0.9.7','0.9.8','0.9.9'),'Unsupported authentic baseline')
        stages=[]
        require(previous is not None or not args.intermediate, 'Intermediate releases require --previous')
        for intermediate in args.intermediate:
            intermediate=intermediate.resolve()
            with zipfile.ZipFile(intermediate) as z:
                stage=json.loads(z.read('cajeerengine/release.json'))['edition_version']
            stages.append((intermediate,stage))
        releases=[initial,*[v for _,v in stages],current] if previous else [current]
        if previous: verify_release_sequence(releases)
        site=unpack(previous or artifact,work/'live',initial)
        install(site,env,report,'initial',port,args.driver)
        ecosystem=None
        if tuple(map(int,initial.split('.'))) >= (0,7,4):
            install_ecosystem_plugins(site,env,report,work,initial)
        with http(site,env,report,'initial',port,initial) as base:
            state=seed(base); verify_data(base,state)
            if tuple(map(int,initial.split('.'))) >= (0,7,4):
                ecosystem=seed_ecosystem(base,state);verify_ecosystem(base,state,ecosystem,initial)
            fixture=seed_structure(base,state); verify_structure(base,fixture)
            if tuple(map(int,initial.split("."))) >= (0,6,3): verify_search_seo(base,state,fixture)
            media=seed_media(base,fixture)
            content=seed_content(base,state,fixture)
            # Seed community last: its pending notifications/digests are part of the snapshot.
            community=seed_community(base,state)
            verify_community(base,community)
            if not previous:
                verify_community_live(base,community)
                verify_groups(base,state)
                verify_discussions(base,state)
                verify_navigation(base,state)
                verify_identity(site,env,report,base)
                verify_readiness(site,env,report,base)
                verify_data_integrity(site,env,report,fixture)
                verify_profiles(base,fixture)
                verify_read_tracking(base,state)
                verify_notifications(base,state,fixture)
                verify_watches(base,state)
                verify_conversations(base,state)
                verify_activity(base,state,fixture)
                verify_rich_content(base,state)
                verify_attachments(base,state,fixture)
                verify_media(base,state,fixture,media)
                verify_polls_questions(base,state,fixture)
                verify_drafts_revisions(base,state)
                verify_recycle(base,state,fixture,content)
                verify_moderation(base,state,fixture)
                verify_sanctions(base,state)
                verify_antispam(base,state)
        if not previous and tuple(map(int,current.split("-")[0].split("."))) >= (0,7,7):
            if rc_target: env['UPDATE_CHANNEL']='rc'
            verify_migration_recovery(site,env,report,work,port,current,state,ecosystem)
        if previous:
            key=work/'backup.key'; secret=work/'release.secret'; public=work/'release.public'
            run(site,env,report,'backup-key',['php','bin/cajeer','backup:keygen','--key='+str(key)])
            run(site,env,report,'release-key',['php','bin/cajeer','release:keygen','--secret-key='+str(secret),'--public-key='+str(public)])
            env |= {'BACKUP_KEY_FILE':str(key),'UPDATE_TRUSTED_PUBLIC_KEY_FILE':str(public)}
            # Never modify the caller's artifact or its signature.
            package=work/'target.zip'; shutil.copy2(artifact,package)
            run(site,env,report,'sign-target',['php','bin/cajeer','release:sign','--package='+str(package),'--secret-key='+str(secret)])
            run(site,env | ({'UPDATE_CHANNEL':'rc'} if rc_target else {}),report,'dry-run',['php','bin/cajeer','update:apply','--package='+str(package),'--dry-run'])
            require(version(site)==initial,'Dry run changed release')
            if initial=='0.7.7':
                blocked=json.loads(run(site,env,report,'incompatible-extension-upgrade',['php','bin/cajeer','update:apply','--package='+str(package),'--json'],expected=1,timeout=600))
                require(blocked['state']=='rolled_back' and version(site)==initial,'Incompatible plugin did not trigger rollback')
                replacement=work/'forum-topic-templates-compatible';shutil.copytree(ROOT/'examples/extensions/forum-topic-templates',replacement)
                run(site,env,report,'sign-compatible-templates',['php','bin/cajeer','extension:sign',str(replacement),'--secret-key='+str(work/'extension.secret')])
                run(site,env,report,'upgrade-compatible-templates',['php','bin/cajeer','extension:package:upgrade',str(replacement)],timeout=600)
                with http(site,env,report,'compatible-templates',port,initial) as base:
                    verify_ecosystem(base,state,ecosystem,initial)
            # Real historical sequence. Use the installed release's trusted updater
            # for every step. Do not merely replace manifests or copy current files.
            for index,(intermediate,expected_version) in enumerate(stages):
                label=f'chain-{index+1}-{expected_version}'
                # Inspect the whole historical dist, then sign its exact bytes
                # with the disposable operator key trusted by the old runtime.
                unpack(intermediate,work/('intermediate-'+str(index)),expected_version)
                stage_package=work/(label+'.zip')
                shutil.copy2(intermediate,stage_package)
                run(site,env,report,label+'-sign',['php','bin/cajeer','release:sign','--package='+str(stage_package),'--secret-key='+str(secret)])
                run(site,env,report,label+'-dry-run',['php','bin/cajeer','update:apply','--package='+str(stage_package),'--dry-run'])
                require(version(site)==releases[index], 'Intermediate preflight mutated live version')
                step=json.loads(run(site,env,report,label+'-apply',['php','bin/cajeer','update:apply','--package='+str(stage_package),'--json'],timeout=600))
                require(step['state']=='committed' and version(site)==expected_version, 'Intermediate upgrade was not committed')
                with http(site,env,report,label+'-http',port,expected_version) as base:
                    verify_data(base,state)
                    verify_structure(base,fixture)
                    verify_community(base,community)
                    verify_content(base,state,fixture,content)
                    verify_media(base,state,fixture,media)
                    if tuple(map(int,expected_version.split('-')[0].split('.'))) >= (0,6,3):
                        verify_search_seo(base,state,fixture)
                    if ecosystem: verify_ecosystem(base,state,ecosystem,expected_version)
                run(site,env,report,label+'-postcheck',['php','tools/update-postcheck.php','verify'])
            if rc_target: env['UPDATE_CHANNEL']='rc'
            update=json.loads(run(site,env,report,'upgrade',['php','bin/cajeer','update:apply','--package='+str(package),'--json'],timeout=600))
            require(update['state']=='committed' and version(site)==current,'Update not committed')
            with http(site,env,report,'updated',port,current) as base:
                if ecosystem:verify_ecosystem(base,state,ecosystem,current)
                verify_content(base,state,fixture,content)
                verify_data(base,state); verify_structure(base,fixture); verify_search_seo(base,state,fixture); verify_community(base,community); verify_community_live(base,community); verify_groups(base,state); verify_discussions(base,state); verify_navigation(base,state); verify_identity(site,env,report,base)
                verify_data_integrity(site,env,report,fixture)
                verify_profiles(base,fixture)
                verify_read_tracking(base,state)
                verify_notifications(base,state,fixture)
                verify_watches(base,state)
                verify_conversations(base,state)
                verify_activity(base,state,fixture)
                verify_rich_content(base,state)
                verify_attachments(base,state,fixture)
                verify_media(base,state,fixture,media)
                verify_polls_questions(base,state,fixture)
                verify_drafts_revisions(base,state)
                verify_recycle(base,state,fixture,content)
                verify_moderation(base,state,fixture)
                verify_sanctions(base,state)
                verify_antispam(base,state)
            run(site,env,report,'rollback',['php','bin/cajeer','update:rollback','--yes','--json'],timeout=600)
            rollback_version = stages[-1][1] if stages else initial
            require(version(site)==rollback_version,'Rollback did not restore immediately previous release')
            with http(site,env,report,'rollback',port,rollback_version) as base:
                if ecosystem:verify_ecosystem(base,state,ecosystem,rollback_version)
                verify_data(base,state); verify_structure(base,fixture); verify_community(base,community); verify_media(base,state,fixture,media,False); verify_content(base,state,fixture,content)
                if tuple(map(int,initial.split("."))) >= (0,6,3): verify_search_seo(base,state,fixture)
            # Inject actual failing SQL into a separately built, correctly signed target.
            broken=unpack(artifact,work/'broken',current)
            (broken/'migrations'/args.driver/'9999_acceptance_failure.sql').write_text('CREATE TABLE ce_acceptance_failure(id INTEGER); THIS IS INVALID SQL;\n')
            out=work/'broken-artifacts'; out.mkdir()
            run(broken,env,report,'build-broken',['php','tools/build-release.php','--dist','--target-dir='+str(out),'--json'])
            pkg=out/f'cajeerengine-{current}-dist.zip'
            run(site,env,report,'sign-broken',['php','bin/cajeer','release:sign','--package='+str(pkg),'--secret-key='+str(secret)])
            failed=json.loads(run(site,env,report,'failed-upgrade',['php','bin/cajeer','update:apply','--package='+str(pkg),'--json'],expected=1,timeout=600))
            require(failed['state']=='rolled_back' and version(site)==rollback_version and not (site/'storage/app/updates/active.json').exists(), 'Automatic rollback failed')
            with http(site,env,report,'failed-rollback',port,rollback_version) as base:
                if ecosystem:verify_ecosystem(base,state,ecosystem,rollback_version)
                verify_data(base,state); verify_structure(base,fixture); verify_community(base,community); verify_media(base,state,fixture,media,False); verify_content(base,state,fixture,content)
                if tuple(map(int,initial.split("."))) >= (0,6,3): verify_search_seo(base,state,fixture)
        if previous:
            # Include hashes of the *actual* tested ZIPs in the evidence file;
            # CI publishes this alongside the HTTP/SQL logs. Never emit a pass
            # certificate for an incomplete chain or an interrupted rollback.
            commit=os.environ.get('GITHUB_SHA') or os.environ.get('FORUM_RELEASE_COMMIT')
            if not commit:
                commit=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True,timeout=10).strip()
            receipt=chain_receipt(releases,[previous,*[path for path,_ in stages],artifact],
                                 commit,True,True)
            destination=report/'upgrade-chain.json'
            with destination.open('x') as output:
                json.dump(receipt,output,indent=2,sort_keys=True)
                output.write('\n')
        print('PASS physical dist acceptance',flush=True)


if __name__=='__main__': main()
