#!/usr/bin/env python3
"""Real container HTTP acceptance; run only against a disposable Compose project."""
import argparse, importlib.util, json, sys, time
from pathlib import Path
spec=importlib.util.spec_from_file_location('production_acceptance',Path(__file__).resolve().parents[2]/'tools/production-acceptance.py')
acceptance=importlib.util.module_from_spec(spec); spec.loader.exec_module(acceptance)
Client,expect,data,require=acceptance.Client,acceptance.expect,acceptance.data,acceptance.require

p=argparse.ArgumentParser()
p.add_argument('--domain', required=True); p.add_argument('--port', type=int, required=True)
p.add_argument('--ca', type=Path, required=True); p.add_argument('--email', required=True)
p.add_argument('--password-file', type=Path, required=True); p.add_argument('--state', type=Path, required=True)
p.add_argument('--version', default='0.9.5'); p.add_argument('--verify-only', action='store_true')
a=p.parse_args(); c=Client(a.domain,a.ca,a.port)
password=a.password_file.read_text().strip()
def call(path,status=200,**kw): return expect(c,path,status,**kw)
def login(email): return {'Authorization':'Bearer '+data(call('/api/v1/auth/login',201,method='POST',data={'email':email,'password':password})[1])['access_token']}
for _ in range(90):
    try:
        call('/health/ready'); break
    except (OSError, RuntimeError): time.sleep(2)
else: raise RuntimeError('Container gateway not ready')
h,b=call('/api/v1/health'); require(json.loads(b)['edition_version']==a.version,'edition identity')
h,b=call('/admin/'); require(b'admin-app.js' in b and 'frame-ancestors' in h.get('content-security-policy',''),'admin shell')
for path in ('/.env','/install/','/upgrade/','/composer.json','/uploads/shell.php'): call(path,404)
admin=login(a.email); call('/api/v1/forum/acp?view=overview',headers=admin)
if a.verify_only:
    state=json.loads(a.state.read_text()); h,b=call('/forum/threads/'+state['thread'])
    require(b'ContainerSmokeReply' in b,'data survived recreation'); print('PASS container persistence'); sys.exit(0)
category=data(call('/api/v1/forum/categories',201,method='POST',headers=admin,data={'name':'Container smoke','description':''})[1])['id']
board=data(call('/api/v1/forum/categories/'+category+'/boards',201,method='POST',headers=admin,data={'name':'Container board','description':''})[1])['id']
thread=data(call('/api/v1/forum/boards/'+board+'/threads',201,method='POST',headers=admin,data={'title':'ContainerSmokeMarker','body':'ContainerSmokeBody'})[1])['id']
path='/api/v1/forum/threads/'+thread
call(path+'/watch',method='PUT',headers=admin,data={'enabled':True})
email='container-smoke-member@example.invalid'
call('/api/v1/users',201,method='POST',headers=admin,data={'email':email,'name':'Container Member','password':password,'roles':['viewer','forum-member']})
member=login(email)
reply=data(call(path+'/posts',201,method='POST',headers=member,data={'body':'ContainerSmokeReply'})[1])['id']
h,b=call('/forum/threads/'+thread); require(b'ContainerSmokeBody' in b and b'ContainerSmokeReply' in b,'real SSR')
require(h.get('x-cajeerengine-forum-version')==a.version,'SSR edition')
require(any(x['id']==thread for x in data(call('/api/v1/forum/search?q=ContainerSmokeMarker')[1])),'FTS')
# Repeat anonymous SSR and verify Redis hit, using the actual runtime diagnostic in CI.
hits=[]
for _ in range(3): hits.append(call('/forum/threads/'+thread)[0].get('x-forum-cache'))
require('HIT' in hits,'Redis cache hit not observed')
if tuple(map(int,a.version.split('.'))) >= (0,8,6):
    # The report cache has a 15-second lifetime; allow one fresh sample after the writes.
    for _ in range(25):
        report=json.loads(call('/api/v1/system/operations',headers=admin)[1])
        checks=report['checks']; http=report.get('forum_http',{})
        if (checks.get('forum_database',{}).get('ok') and checks['forum_database']['postgres']['max_connections']>0
            and checks.get('forum_cache',{}).get('stats',{}).get('hit',0)>0
            and all(http.get(group,{}).get('requests',0)>0 for group in ('ssr','search','publication'))): break
        time.sleep(1)
    else: raise RuntimeError('Forum observability did not report real PostgreSQL, Redis and HTTP traffic')
    call('/metrics',401)

for _ in range(45):
    if any(x.get('post_id')==reply for x in data(call('/api/v1/forum/notifications',headers=admin)[1])['items']): break
    time.sleep(1)
else: raise RuntimeError('Worker did not deliver notification')
policy='/api/v1/forum/categories/'+category+'/permissions'; original=data(call(policy,headers=admin)[1])
call(policy,method='PUT',headers=admin,data={'visibility':'hidden','rules':[],'revision':original['revision']})
call('/forum/threads/'+thread,404)
require(not any(x['id']==thread for x in data(call('/api/v1/forum/search?q=ContainerSmokeMarker')[1])),'ACL leak')
original=data(call(policy,headers=admin)[1]); call(policy,method='PUT',headers=admin,data={'visibility':'public','rules':[],'revision':original['revision']})
a.state.write_text(json.dumps({'thread':thread,'reply':reply})); print('PASS container TLS, ACP, publication, FTS, worker and ACL')
