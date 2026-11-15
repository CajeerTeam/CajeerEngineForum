#!/usr/bin/env python3
"""Real network and browser regression tests. Never use a production database."""
import argparse
import base64
import hashlib
import hmac
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import struct
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zlib
import xml.etree.ElementTree as ET
from contextlib import contextmanager

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
PASSWORD = 'MemberPass12345!'
NEW_PASSWORD = 'ChangedPass12345!'
ADMIN = 'admin@example.test'
ADMIN_PASSWORD = 'AdminPass12345!'
XSS = '<img src=x onerror="window.forumXss=1"><script>window.forumXss=1</script>'


def profile_png():
    def chunk(kind,data):
        return struct.pack('>I',len(data))+kind+data+struct.pack('>I',zlib.crc32(kind+data)&0xffffffff)
    return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',2,2,8,2,0,0,0))+chunk(b'IDAT',zlib.compress(b'\0'+b'\xff\0\0'*2+b'\0'+b'\0\xff\0'*2))+chunk(b'IEND',b'')

def unique_email():
    return 'forum-' + uuid.uuid4().hex + '@example.test'


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(self.jar))
        self.headers = {}

    def request(self, method, path, status=200, body=None, token=None, headers=None, raw=None):
        h = {'Accept': 'application/json', **self.headers, **(headers or {})}
        if body is not None:
            raw = json.dumps(body).encode()
        if raw is not None:
            h.setdefault('Content-Type', 'application/json')
        if token:
            h['Authorization'] = 'Bearer ' + token
        req = urllib.request.Request(self.base + '/api/v1' + path, data=raw, headers=h, method=method)
        try:
            res = self.opener.open(req, timeout=20)
        except urllib.error.HTTPError as e:
            res = e
        with res:
            text = res.read().decode()
            if res.status != status:
                raise AssertionError(f'{method} {path}: expected {status}, got {res.status}')
            if 'application/json' not in res.headers.get('Content-Type', ''):
                raise AssertionError(f'{method} {path}: response is not JSON')
            data = json.loads(text)
            if status >= 400:
                assert 'error' in data and 'debug' not in data['error'], f'{method} {path}: invalid error envelope'
            else:
                assert 'data' in data, f'{method} {path}: missing data envelope'
            return data, res.headers

    def call(self, *args, **kwargs):
        data, _ = self.request(*args, **kwargs)
        return data.get('data', data.get('error'))

    def upload_attachment(self, filename, content, token=None, status=201, headers=None):
        boundary='forum-'+uuid.uuid4().hex
        raw=(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="{filename}"\r\nContent-Type: application/octet-stream\r\n\r\n').encode()+content+f'\r\n--{boundary}--\r\n'.encode()
        return self.call('POST','/forum/attachments',status,token=token,raw=raw,headers={'Content-Type':'multipart/form-data; boundary='+boundary,**(headers or {})})

    def download_attachment(self, aid, token=None):
        h={'Authorization':'Bearer '+token} if token else {}
        with self.opener.open(urllib.request.Request(self.base+'/api/v1/forum/attachments/'+aid+'/download',headers=h),timeout=20) as res:
            assert res.status==200 and res.headers.get_content_type()=='application/octet-stream'
            assert res.headers['Content-Disposition'].startswith('attachment;') and res.headers['Cache-Control'].find('no-store')>=0
            return res.read()

    def image_variant(self, aid, variant='thumbnail', token=None):
        h={'Authorization':'Bearer '+token} if token else {}
        with self.opener.open(urllib.request.Request(self.base+'/api/v1/forum/attachments/'+aid+'/image/'+variant,headers=h),timeout=20) as res:
            assert res.status==200 and res.headers.get_content_type() in ('image/png','image/jpeg')
            assert res.headers['Content-Disposition']=='inline' and 'no-store' in res.headers['Cache-Control']
            return res.read()

    def login(self, email, password=PASSWORD, code=None, status=201, browser=False):
        body = {'email': email, 'password': password}
        if code is not None:
            body['two_factor_code'] = code
        return self.request('POST', '/auth/login', status, body, headers={'X-Cajeer-Browser': '1'} if browser else {})


def mail_link(email, path, base):
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    mail = 'http://127.0.0.1:8025'
    deadline = time.monotonic() + 20
    while time.monotonic() < deadline:
        with opener.open(mail + '/api/v1/messages', timeout=5) as res:
            messages = json.load(res).get('messages', [])
        for item in messages:
            if email not in [a['Address'] for a in item.get('To', [])]:
                continue
            with opener.open(mail + '/api/v1/message/' + urllib.parse.quote(item['ID'], safe=''), timeout=5) as res:
                text = json.load(res).get('Text', '')
            match = re.search(re.escape(base + path) + r'#token=([a-f0-9-]{36}\.[0-9]{1,12}\.[a-f0-9]{64}|[a-f0-9]{64})', text)
            if match:
                return match.group(0), match.group(1)
        time.sleep(.25)
    raise AssertionError('Mailpit did not receive ' + path)


def totp(secret):
    key = base64.b32decode(secret + '=' * (-len(secret) % 8))
    digest = hmac.new(key, struct.pack('>Q', int(time.time()) // 30), hashlib.sha1).digest()
    offset = digest[-1] & 15
    return str((int.from_bytes(digest[offset:offset+4], 'big') & 0x7fffffff) % 1000000).zfill(6)


@contextmanager
def server(report):
    if not (ROOT / 'vendor/autoload.php').is_file():
        raise RuntimeError('Run composer install first')
    # Copy only source/configuration. Never copy .env, storage, uploads or a database.
    with tempfile.TemporaryDirectory(prefix='ce-forum-test-') as tmp:
        site = Path(tmp) / 'site'
        site.mkdir()
        # Reuse the production source distribution allowlist (no uploads,
        # installed extensions or local secrets, even in a dirty checkout).
        plan = json.loads(subprocess.check_output(['php', str(ROOT / 'tools/build-release.php'), '--dry-run', '--json'], cwd=ROOT, timeout=30))
        version = json.loads((ROOT / 'release.json').read_text())['edition_version']
        if plan['version'] != version or not plan['release_requirements']['ready']:
            raise RuntimeError('Source release prerequisites failed')
        for name in plan['files']:
            target = site / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / name, target)
        for name in ('storage/app', 'storage/database', 'storage/cache', 'storage/logs', 'storage/tmp', 'bootstrap/cache', 'public/uploads', 'public/extensions'):
            (site / name).mkdir(parents=True, exist_ok=True)
        shutil.copytree(ROOT / 'vendor', site / 'vendor')
        (site / '.forum-test-fixture').touch()
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = f'http://127.0.0.1:{port}'
        # Do not propagate application secrets, DB settings or dotenv overrides.
        env = {k: os.environ[k] for k in ('PATH', 'HOME', 'LANG', 'LC_ALL', 'TZ', 'SYSTEMROOT') if k in os.environ}
        env['TZ'] = 'UTC'
        env['FORUM_TEST_DRIVER'] = os.environ.get('FORUM_TEST_DRIVER', 'sqlite')
        if env['FORUM_TEST_DRIVER'] == 'pgsql':
            for key in ('FORUM_TEST_NETWORK_DB', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'):
                if key in os.environ:
                    env[key] = os.environ[key]
        with (report / 'install.log').open('w') as log:
            subprocess.run(['php', str(HERE / 'install.php'), str(site), base], cwd=site, env=env, stdout=log, stderr=subprocess.STDOUT, check=True, timeout=180)
        # Server loads the fixture .env; no external DB credentials or app overrides.
        server_env = {k:v for k,v in env.items() if not k.startswith('DB_')}
        with (report / 'http.log').open('w') as log:
            proc = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(site / 'public'), str(HERE / 'router.php')], cwd=site, env=server_env, stdout=log, stderr=subprocess.STDOUT)
            try:
                deadline = time.monotonic() + 30
                opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
                while time.monotonic() < deadline:
                    if proc.poll() is not None:
                        raise RuntimeError('PHP HTTP server terminated')
                    try:
                        with opener.open(base + '/forum', timeout=2) as res:
                            if res.status == 200 and res.headers.get('X-CajeerEngine-Forum-Version') == version:
                                break
                    except (urllib.error.URLError, TimeoutError):
                        pass
                    time.sleep(.2)
                else:
                    raise RuntimeError('Forum did not become ready; inspect http.log')
                yield base
            finally:
                proc.terminate()
                try:
                    proc.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    proc.kill()
                    proc.wait(timeout=10)


class NetworkTests(unittest.TestCase):
    base = ''

    def activated(self, client):
        email = unique_email()
        reg = client.call('POST', '/forum/register', 201, {'name': 'Network member', 'email': email, 'password': PASSWORD})
        self.assertTrue(reg['verification_required'])
        client.login(email, status=401)
        _, token = mail_link(email, '/forum/verify-email', self.base)
        client.call('POST', '/auth/email/verify', 422, {'token': token, 'password': 'Incorrect123!'})
        client.call('POST', '/auth/email/verify', 200, {'token': token, 'password': PASSWORD})
        client.call('POST', '/auth/email/verify', 422, {'token': token, 'password': PASSWORD})
        return email, reg['id'], client.login(email)[0]['data']['access_token']

    def test_01_identity_and_session_security(self):
        c = Client(self.base)
        email, _, token = self.activated(c)
        other = Client(self.base)
        _, _, foreign = self.activated(other)
        foreign_id = other.call('GET', '/auth/sessions', token=foreign)[0]['id']
        self.assertFalse(c.call('DELETE', '/auth/sessions/' + foreign_id, token=token)['revoked'])
        other.call('GET', '/auth/me', token=foreign)
        second = c.login(email)[0]['data']['access_token']
        self.assertEqual(2, len(c.call('GET', '/auth/sessions', token=token)))
        for session in c.call('GET', '/auth/sessions', token=token):
            if not session['current']:
                c.call('DELETE', '/auth/sessions/' + session['id'], token=token)
        c.call('GET', '/auth/me', 401, token=second)
        missing = c.call('POST', '/auth/password/forgot', 202, {'email': unique_email()})
        self.assertEqual(missing, c.call('POST', '/auth/password/forgot', 202, {'email': email}))
        _, reset = mail_link(email, '/forum/reset-password', self.base)
        c.call('POST', '/auth/password/reset', 200, {'token': reset, 'password': NEW_PASSWORD})
        c.call('POST', '/auth/password/reset', 422, {'token': reset, 'password': PASSWORD})
        c.call('GET', '/auth/me', 401, token=token)
        c.login(email, status=401)
        token = c.login(email, NEW_PASSWORD)[0]['data']['access_token']
        setup = c.call('POST', '/auth/2fa/enable', 201, {'password': NEW_PASSWORD}, token=token)
        self.assertFalse(c.call('GET', '/auth/me', token=token)['user']['two_factor_enabled'])
        code = totp(setup['secret'])
        confirm = c.call('POST', '/auth/2fa/confirm', 200, {'password': NEW_PASSWORD, 'setup_token': setup['setup_token'], 'two_factor_code': code}, token=token)
        self.assertEqual(8, len(set(confirm['recovery_codes'])))
        c.call('GET', '/auth/me', 401, token=token)
        c.login(email, NEW_PASSWORD, status=401)
        c.login(email, NEW_PASSWORD, code, status=401)
        recovery = confirm['recovery_codes'][0]
        token = c.login(email, NEW_PASSWORD, recovery)[0]['data']['access_token']
        c.login(email, NEW_PASSWORD, recovery, status=401)
        c.call('POST', '/auth/2fa/disable', 403, {'password': NEW_PASSWORD}, token=token)
        c.call('POST', '/auth/2fa/disable', 200, {'password': NEW_PASSWORD, 'two_factor_code': confirm['recovery_codes'][1]}, token=token)
        c.call('GET', '/auth/me', 401, token=token)

    def test_02_forum_http_and_authorization(self):
        c = Client(self.base)
        email, member, token = self.activated(c)
        other = Client(self.base)
        _, _, foreign = self.activated(other)
        admin = c.login(ADMIN, ADMIN_PASSWORD)[0]['data']['access_token']
        board = c.call('GET', '/forum')['boards'][0]['id']
        payload = {'title': 'Network regression topic', 'body': XSS}
        c.call('POST', f'/forum/boards/{board}/threads', 401, payload)
        c.call('POST', f'/forum/boards/{board}/threads', 422, {'title': [], 'body': 'x'}, token=token)
        c.call('POST', f'/forum/boards/{board}/threads', 422, token=token, raw=b'{broken')
        c.call('POST', f'/forum/boards/{board}/threads', 415, token=token, raw=b'x', headers={'Content-Type': 'text/plain'})
        thread = c.call('POST', f'/forum/boards/{board}/threads', 201, payload, token=token)['id']
        url = f'/forum/threads/{thread}'
        first = c.call('GET', url)['posts'][0]
        self.assertEqual(XSS, first['body'])
        post = '/forum/posts/' + first['id']
        c.call('PATCH', post, 403, {'body': 'stolen'}, token=foreign)
        self.assertEqual(XSS, c.call('GET', url)['posts'][0]['body'])
        c.call('PATCH', post, 200, {'body': 'edited'}, token=token)
        c.call('POST', url+'/posts', 201, {'body': 'reply'}, token=foreign)
        c.call('PUT', post+'/reaction', 422, {'enabled': 'true'}, token=foreign)
        for _ in range(2):
            c.call('PUT', post+'/reaction', 200, {'enabled': True}, token=foreign)
        self.assertEqual(1, int(c.call('GET', url)['posts'][0]['likes']))
        c.call('PUT', url+'/watch', 200, {'enabled': True}, token=token)
        self.assertIn(thread, [t['id'] for t in c.call('GET', '/forum/watches', token=token)])
        report = c.call('POST', post+'/report', 201, {'reason': 'Network moderation'}, token=foreign)['id']
        c.call('GET', '/forum/reports', 403, token=token)
        c.call('POST', f'/forum/reports/{report}/resolve', 200, token=admin)
        c.call('POST', url+'/moderate', 403, {'action':'lock'}, token=token)
        c.call('POST', url+'/moderate', 200, {'action':'lock'}, token=admin)
        c.call('POST', url+'/posts', 409, {'body':'blocked'}, token=token)
        c.call('POST', url+'/moderate', 200, {'action':'unlock'}, token=admin)
        self.assertIn(thread, [t['id'] for t in c.call('GET', '/forum/search?q=Network')])
        self.assertNotIn('email', c.call('GET', '/forum/members/'+member)['member'])
        self.assertEqual([], c.call('GET', '/forum/search?q=%27%20OR%201%3D1--'))
        self.assertEqual([], c.call('GET', '/forum/search?q=%25%25'))
        # Real cookies and CSRF are checked over the wire, not injected into Request.
        browser = Client(self.base)
        response, headers = browser.login(email, browser=True)
        cookie = headers.get('Set-Cookie', '').lower()
        self.assertIn('httponly', cookie)
        self.assertIn('samesite=strict', cookie)
        self.assertIn('path=/api/v1', cookie)
        self.assertNotIn('access_token', response['data'])
        csrf = response['data']['csrf_token']
        browser.call('POST', url+'/posts', 403, {'body': 'CSRF denied'})
        browser.call('POST', url+'/posts', 403, {'body': 'CSRF denied'}, headers={'X-CSRF-Token': 'wrong'})
        browser.call('POST', url+'/posts', 201, {'body': 'cookie reply'}, headers={'X-CSRF-Token': csrf})
        self.assertEqual(3, len(c.call('GET', url)['posts']))
        browser.call('POST', '/auth/logout', headers={'X-CSRF-Token': csrf})
        browser.call('GET', '/auth/me', 401)
        c.call('POST', url+'/moderate', 200, {'action':'delete'}, token=admin)
        c.call('GET', url, 404)
        self.assertNotIn(thread, [t['id'] for t in c.call('GET', '/forum/search?q=Network')])

    def test_03_node_management(self):
        c = Client(self.base)
        admin = c.login(ADMIN, ADMIN_PASSWORD)[0]['data']['access_token']
        data = {'name': 'API tree', 'description': ''}
        cat = c.call('POST', '/forum/categories', 201, data, token=admin)['id']
        dest = c.call('POST', '/forum/categories', 201, {'name':'API destination','description':''}, token=admin)['id']
        path = f'/forum/categories/{cat}/boards'
        root = c.call('POST',path,201,{**data,'node_type':'container'},token=admin)['id']
        child = c.call('POST',path,201,{'name':'API child','description':'','parent_id':root},token=admin)['id']
        c.call('POST',f'/forum/boards/{root}/threads',409,{'title':'Forbidden','body':'No'},token=admin)
        t = c.call('POST',f'/forum/boards/{child}/threads',201,{'title':'Moved thread','body':'Preserved'},token=admin)['id']
        c.call('POST',f'/forum/boards/{root}/move',409,{'category_id':cat,'parent_id':child},token=admin)
        c.call('POST',f'/forum/boards/{root}/move',200,{'category_id':dest,'parent_id':None,'position':0},token=admin)
        self.assertEqual(dest,c.call('GET',f'/forum/boards/{child}')['board']['category_id'])
        self.assertEqual(root,c.call('GET',f'/forum/boards/{child}')['board']['parent_id'])
        self.assertEqual([dest,root,child], [n['id'] for n in c.call('GET',f'/forum/threads/{t}')['breadcrumbs']])
        reorder='/forum/nodes/reorder'
        move={'kind':'board','id':child,'target_kind':'category','target_id':cat,'placement':'inside'}
        c.call('POST',reorder,401,move)
        c.call('POST',reorder,200,move,token=admin)
        self.assertEqual([cat,child],[n['id'] for n in c.call('GET',f'/forum/threads/{t}')['breadcrumbs']])
        c.call('POST',reorder,200,{'kind':'board','id':child,'target_kind':'board','target_id':root,'placement':'inside'},token=admin)
        c.call('POST',reorder,409,{'kind':'board','id':root,'target_kind':'board','target_id':child,'placement':'inside'},token=admin)
        c.call('POST',reorder,200,{'kind':'category','id':dest,'target_kind':'category','target_id':cat,'placement':'before'},token=admin)
        categories=[x['id'] for x in c.call('GET','/forum')['categories']]
        self.assertLess(categories.index(dest),categories.index(cat))
        sibling=c.call('POST',f'/forum/categories/{dest}/boards',201,{'name':'Sibling','description':'','parent_id':root},token=admin)['id']
        c.call('POST',reorder,200,{'kind':'board','id':sibling,'target_kind':'board','target_id':child,'placement':'before'},token=admin)
        self.assertEqual([sibling,child],[x['id'] for x in c.call('GET',f'/forum/boards/{root}')['children']])
        c.call('POST',reorder,200,{'kind':'board','id':sibling,'target_kind':'board','target_id':child,'placement':'after'},token=admin)
        self.assertEqual([child,sibling],[x['id'] for x in c.call('GET',f'/forum/boards/{root}')['children']])
        c.call('DELETE',f'/forum/boards/{sibling}',200,token=admin)
        self.assertEqual([dest,root,child],[n['id'] for n in c.call('GET',f'/forum/threads/{t}')['breadcrumbs']])
        c.call('DELETE',f'/forum/boards/{root}',409,token=admin)
        c.call('PATCH',f'/forum/boards/{root}',200,{**data,'node_type':'container','state':'archived'},token=admin)
        c.call('POST',f'/forum/threads/{t}/posts',409,{'body':'No'},token=admin)
        self.assertEqual('Preserved',c.call('GET',f'/forum/threads/{t}')['posts'][0]['body'])
        c.call('PATCH',f'/forum/boards/{root}',200,{**data,'node_type':'container','state':'open'},token=admin)
        c.call('POST',f'/forum/threads/{t}/moderate',200,{'action':'delete'},token=admin)
        c.call('DELETE',f'/forum/boards/{child}',200,token=admin)
        c.call('DELETE',f'/forum/boards/{root}',200,token=admin)
        c.call('DELETE',f'/forum/categories/{dest}',200,token=admin)
        c.call('DELETE',f'/forum/categories/{cat}',200,token=admin)

    def test_04_private_nodes_and_revocation(self):
        c = Client(self.base)
        _, member, token = self.activated(c)
        other_email, other_id, other = self.activated(Client(self.base))
        admin = c.login(ADMIN, ADMIN_PASSWORD)[0]['data']['access_token']
        category = c.call('POST','/forum/categories',201,{'name':'ACL confidential category','description':''},token=admin)['id']
        root = c.call('POST',f'/forum/categories/{category}/boards',201,{'name':'ACL confidential parent','description':'','node_type':'container'},token=admin)['id']
        board = c.call('POST',f'/forum/categories/{category}/boards',201,{'name':'ACL confidential child','description':'','parent_id':root},token=admin)['id']
        thread = c.call('POST',f'/forum/boards/{board}/threads',201,{'title':'ACLUniqueSecret','body':'ACLUniqueSecret'},token=token)['id']
        url=f'/forum/threads/{thread}'
        post='/forum/posts/'+c.call('GET',url)['posts'][0]['id']
        c.call('PUT',url+'/watch',200,{'enabled':True},token=other)
        report=c.call('POST',post+'/report',201,{'reason':'confidential report'},token=other)['id']
        def rule(permission,effect,subject_type='user',subject_id=member):
            return {'subject_type':subject_type,'subject_id':subject_id,'permission':permission,'effect':effect}
        policy=f'/forum/categories/{category}/permissions'
        rules=[rule('thread:read','allow'),rule('node:view','allow'),rule('post:reply','never')]
        c.call('PUT',policy,403,{'visibility':'hidden','rules':rules},token=token)
        original_policy=c.call('GET',policy,token=admin)
        c.call('PUT',policy,200,{'visibility':'hidden','rules':rules,'revision':original_policy['revision']},token=admin)
        c.call('PUT',policy,409,{'visibility':'public','rules':[],'revision':original_policy['revision']},token=admin)
        inspect=f'/forum/permission-inspector?kind=board&id={board}'
        c.call('GET',inspect,401)
        c.call('GET',inspect,403,token=token)
        guest=c.call('GET',inspect,token=admin)
        self.assertFalse(guest['effective']['thread:read'])
        self.assertTrue(guest['trace']['steps'][0]['blocked'])
        inspected=c.call('GET',inspect+'&user_id='+member,token=admin)
        self.assertTrue(inspected['effective']['thread:read'])
        self.assertFalse(inspected['effective']['post:reply'])
        self.assertIn('post:reply',inspected['trace']['steps'][0]['never'])
        actual=c.call('GET',f'/forum/boards/{board}',token=token)
        self.assertEqual(actual['board']['permissions'],inspected['effective'])
        self.assertEqual([category,root,board],[n['id'] for n in actual['breadcrumbs']])
        for viewer in (None,other):
            tree=c.call('GET','/forum',token=viewer)
            self.assertNotIn(category,[x['id'] for x in tree['categories']])
            self.assertNotIn(root,[x['id'] for x in tree['boards']])
            self.assertNotIn(board,[x['id'] for x in tree['boards']])
            c.call('GET',f'/forum/boards/{board}',404,token=viewer)
            c.call('GET',url,404,token=viewer)
            self.assertNotIn(thread,[x['id'] for x in c.call('GET','/forum/latest',token=viewer)])
            self.assertNotIn(thread,[x['id'] for x in c.call('GET','/forum/search?q=ACLUniqueSecret',token=viewer)])
            self.assertNotIn(thread,[x['id'] for x in c.call('GET',f'/forum/members/{member}',token=viewer)['threads']])
        self.assertNotIn(thread,[x['id'] for x in c.call('GET','/forum/watches',token=other)])
        c.call('POST',url+'/posts',404,{'body':'blocked'},token=other)
        c.call('PATCH',post,404,{'body':'blocked'},token=other)
        c.call('PUT',post+'/reaction',404,{'enabled':True},token=other)
        c.call('PUT',url+'/watch',404,{'enabled':True},token=other)
        c.call('POST',post+'/report',404,{'reason':'blocked'},token=other)
        self.assertEqual('ACLUniqueSecret',c.call('GET',url,token=token)['posts'][0]['body'])
        self.assertIn(thread,[x['id'] for x in c.call('GET','/forum/search?q=ACLUniqueSecret',token=token)])
        c.call('POST',url+'/posts',403,{'body':'blocked by never'},token=token)
        # Descendant allow cannot override never or an inaccessible ancestor.
        child_policy=f'/forum/boards/{board}/permissions'
        c.call('PUT',child_policy,200,{'visibility':'public','rules':[rule('post:reply','allow'),rule('thread:read','allow','everyone',''),rule('node:view','allow','everyone','')]},token=admin)
        c.call('POST',url+'/posts',403,{'body':'still blocked'},token=token)
        c.call('GET',url,404,token=other)
        self.assertIn(report,[x['id'] for x in c.call('GET','/forum/reports',token=admin)])
        c.call('PATCH',f'/users/{other_id}',200,{'roles':['forum-member','forum-moderator']},token=admin)
        mod=c.login(other_email)[0]['data']['access_token']
        self.assertNotIn(report,[x['id'] for x in c.call('GET','/forum/reports',token=mod)])
        c.call('POST',f'/forum/reports/{report}/resolve',404,token=mod)
        c.call('POST',url+'/moderate',404,{'action':'lock'},token=mod)
        c.call('PUT',policy,200,{'visibility':'private','rules':[]},token=admin)
        c.call('GET',url,404,token=token)
        c.call('GET',url,200,token=admin)
        # Explicit role allow grants access with existing token; removing it revokes access.
        role=next(r['id'] for r in c.call('GET','/roles',token=admin) if r['handle']=='forum-member')
        c.call('PUT',policy,200,{'visibility':'private','rules':[rule('thread:read','allow','role',role)]},token=admin)
        c.call('GET',url,200,token=token)
        c.call('GET',url,200,token=other)
        # A moderator can read a report only if both visibility and moderation allow it.
        c.call('PUT',child_policy,200,{'visibility':'public','rules':[rule('thread:moderate','deny','role',next(r['id'] for r in c.call('GET','/roles',token=admin) if r['handle']=='forum-moderator'))]},token=admin)
        self.assertNotIn(report,[x['id'] for x in c.call('GET','/forum/reports',token=mod)])
        c.call('POST',url+'/moderate',403,{'action':'lock'},token=mod)
        c.call('PUT',child_policy,200,{'visibility':'public','rules':[]},token=admin)
        self.assertIn(report,[x['id'] for x in c.call('GET','/forum/reports',token=mod)])
        c.call('POST',f'/forum/reports/{report}/resolve',200,token=mod)
        # Revoking the role grant changes access for an already issued token.
        c.call('PATCH',f'/users/{other_id}',200,{'roles':['forum-moderator']},token=admin)
        c.call('GET',url,404,token=mod)
        c.call('PUT',policy,200,{'visibility':'private','rules':[]},token=admin)
        c.call('GET',url,404,token=other)
        c.call('POST',url+'/moderate',200,{'action':'delete'},token=admin)
        c.call('DELETE',f'/forum/boards/{board}',200,token=admin)
        c.call('DELETE',f'/forum/boards/{root}',200,token=admin)
        c.call('DELETE',f'/forum/categories/{category}',200,token=admin)

    def test_05_group_membership_and_delegation(self):
        c=Client(self.base)
        email,user,token=self.activated(c)
        admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        groups=c.call('GET','/forum/groups',token=admin)
        default=next(g['id'] for g in groups if g['handle']=='forum-member')
        path=f'/forum/members/{user}/groups'
        self.assertEqual(default,c.call('GET',path,token=admin)['primary_group_id'])
        c.call('GET','/forum/groups',403,token=token)
        c.call('PUT',path,403,{'primary_group_id':default,'secondary_group_ids':[]},token=token)
        payload={'handle':'network-vip','name':'Network VIP','description':'VIP access','permissions':['forum:write']}
        c.call('POST','/forum/groups',422,{**payload,'permissions':['*']},token=admin)
        vip=c.call('POST','/forum/groups',201,payload,token=admin)['id']
        support=c.call('POST','/forum/groups',201,{**payload,'handle':'network-support','name':'Support','permissions':['forum:write','forum:moderate']},token=admin)['id']
        core_admin=next(r['id'] for r in c.call('GET','/roles',token=admin) if r['handle']=='admin')
        c.call('PUT',path,422,{'primary_group_id':core_admin,'secondary_group_ids':[]},token=admin)
        c.call('PUT',path,422,{'primary_group_id':default,'secondary_group_ids':[default]},token=admin)
        data=c.call('PUT',path,200,{'primary_group_id':vip,'secondary_group_ids':[default,support]},token=admin)
        self.assertEqual(vip,data['primary_group_id']);self.assertEqual({default,support},set(data['secondary_group_ids']))
        c.call('DELETE',f'/forum/groups/{vip}',409,token=admin)
        c.call('PATCH',f'/forum/groups/{vip}',200,{'name':'Renamed VIP','description':'Updated','permissions':['forum:write']},token=admin)
        c.call('POST','/roles',403,{'handle':'forum-group-network-vip','name':'Override','permissions':['*']},token=admin)
        # A new scope requires a new token; old token still gains role-based private read access.
        cat=c.call('POST','/forum/categories',201,{'name':'Groups category','description':''},token=admin)['id']
        board=c.call('POST',f'/forum/categories/{cat}/boards',201,{'name':'Groups private','description':''},token=admin)['id']
        thread=c.call('POST',f'/forum/boards/{board}/threads',201,{'title':'Groups secret','body':'Group content'},token=admin)['id']
        public=c.call('POST',f'/forum/categories/{cat}/boards',201,{'name':'Groups public','description':''},token=admin)['id']
        other=c.call('POST',f'/forum/boards/{public}/threads',201,{'title':'Public group topic','body':'Public'},token=admin)['id']
        policy=f'/forum/boards/{board}/permissions'; url=f'/forum/threads/{thread}'
        rule=lambda subject,permission: {'subject_type':'role','subject_id':subject,'permission':permission,'effect':'allow'}
        c.call('PUT',policy,200,{'visibility':'private','rules':[rule(vip,'thread:read'),rule(support,'thread:moderate')]},token=admin)
        c.call('GET',url,404);c.call('GET',url,200,token=token)
        c.call('POST',url+'/moderate',403,{'action':'lock'},token=token)
        fresh=c.login(email)[0]['data']['access_token']
        c.call('POST',f'/forum/threads/{other}/moderate',403,{'action':'lock'},token=fresh)
        c.call('POST',url+'/moderate',200,{'action':'lock'},token=fresh)
        c.call('POST',url+'/moderate',200,{'action':'unlock'},token=fresh)
        c.call('PUT',path,200,{'primary_group_id':default,'secondary_group_ids':[]},token=admin)
        c.call('GET',url,404,token=fresh)
        c.call('POST',url+'/moderate',403,{'action':'lock'},token=fresh)
        c.call('DELETE',f'/forum/groups/{vip}',409,token=admin)
        c.call('PUT',policy,200,{'visibility':'public','rules':[]},token=admin)
        c.call('DELETE',f'/forum/groups/{vip}',200,token=admin)
        c.call('DELETE',f'/forum/groups/{support}',200,token=admin)
        c.call('DELETE',f'/forum/groups/{default}',403,token=admin)
        self.assertNotIn(vip,[g['id'] for g in c.call('GET','/forum/groups',token=admin)])

    def test_06_discussion_management(self):
        c=Client(self.base)
        admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        mod_email=unique_email()
        c.call('POST','/users',201,{'email':mod_email,'name':'Discussion moderator','password':PASSWORD,'roles':['forum-moderator']},token=admin)
        mod=c.login(mod_email)[0]['data']['access_token']
        board=c.call('GET','/forum')['boards'][0]['id']
        category=c.call('POST','/forum/categories',201,{'name':'Discussion management','description':''},token=admin)['id']
        dest=c.call('POST',f'/forum/categories/{category}/boards',201,{'name':'Destination','description':'','rules':'Posting rules for this board.'},token=admin)['id']
        self.assertEqual('Posting rules for this board.',c.call('GET',f'/forum/boards/{dest}')['board']['rules'])
        make=lambda title: c.call('POST',f'/forum/boards/{board}/threads',201,{'title':title,'body':'Original'},token=admin)['id']
        source,target=make('Merge source'),make('Merge target')
        url=lambda t:f'/forum/threads/{t}'
        first=c.call('GET',url(source))['posts'][0]['id']
        reply=c.call('POST',url(source)+'/posts',201,{'body':'Moved answer'},token=admin)['id']
        c.call('PUT',f'/forum/posts/{reply}/reaction',200,{'enabled':True},token=admin)
        c.call('PUT',url(source)+'/watch',200,{'enabled':True},token=admin)
        c.call('PUT',url(source)+'/metadata',200,{'prefix':'Bug','tags':['PHP','php','Discussion-tag']},token=mod)
        self.assertEqual(['discussion-tag','php'],c.call('GET',url(source))['thread']['tags'])
        self.assertIn(source,[x['id'] for x in c.call('GET','/forum/search?tag=discussion-tag')])
        bulk='/forum/thread-actions/bulk'
        c.call('POST',bulk,401,{'action':'pin','thread_ids':[source]})
        c.call('POST',bulk,200,{'action':'pin','thread_ids':[source,target]},token=mod)
        c.call('PUT',f'/forum/boards/{dest}/permissions',200,{'visibility':'hidden','rules':[]},token=admin)
        c.call('POST',bulk,404,{'action':'move','thread_ids':[source,target],'board_id':dest},token=mod)
        self.assertEqual(board,c.call('GET',url(source))['thread']['board_id'])
        c.call('POST',bulk,200,{'action':'move','thread_ids':[source],'board_id':dest},token=admin)
        c.call('GET',url(source),404)
        self.assertNotIn(source,[x['id'] for x in c.call('GET','/forum/search?tag=discussion-tag')])
        c.call('POST',url(source)+'/transfer',409,{'post_ids':[first],'board_id':dest,'title':'Cannot split first'},token=admin)
        split=c.call('POST',url(source)+'/transfer',200,{'post_ids':[reply],'board_id':dest,'title':'New split topic'},token=admin)['id']
        self.assertEqual(reply,c.call('GET',url(split),token=admin)['first_post_id'])
        self.assertEqual(1,int(c.call('GET',url(split),token=admin)['posts'][0]['likes']))
        c.call('POST',url(target)+'/merge',404,{'source_ids':[source,split]},token=mod)
        self.assertEqual(1,len(c.call('GET',url(target))['posts']))
        c.call('POST',url(target)+'/merge',200,{'source_ids':[source,split]},token=admin)
        self.assertEqual(target,c.call('GET',url(source),token=admin)['thread']['id'])
        c.call('GET',url(source),404)
        self.assertEqual([first,reply],[x['id'] for x in c.call('GET',url(target))['posts'][1:]])
        self.assertIn(target,[x['id'] for x in c.call('GET','/forum/watches',token=admin)])
        c.call('POST',url(target)+'/moderate',200,{'action':'delete'},token=admin)
        c.call('GET',url(source),404,token=admin)
        c.call('DELETE',f'/forum/boards/{dest}',200,token=admin)
        c.call('DELETE',f'/forum/categories/{category}',200,token=admin)

    def test_07_member_profiles(self):
        c=Client(self.base)
        admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        email,member,token=self.activated(c)
        _,other,foreign=self.activated(c)
        profile='/forum/members/'+member
        own='/forum/account/profile'
        prefs='/forum/account/preferences'
        field=c.call('POST','/forum/profile-fields',201,{'label':'Favorite language','visibility':'public','position':1},token=admin)['id']
        private=c.call('POST','/forum/profile-fields',201,{'label':'Private field','visibility':'private','position':2},token=admin)['id']
        c.call('POST','/forum/profile-fields',403,{'label':'Denied','visibility':'public'},token=token)
        c.call('PATCH',own,401,{'bio':'unauthorized'})
        c.call('PATCH',own,422,{'website':'javascript:alert(1)'},token=token)
        c.call('PATCH',own,422,{'bio':'unchanged','fields':[{'id':str(uuid.uuid4()),'value':'invalid'}]},token=token)
        self.assertEqual('',c.call('GET',own,token=token)['profile']['bio'])
        label='ProfileTest-'+uuid.uuid4().hex[:12]
        data={'display_name':label,'bio':XSS,'location':'Berlin','website':'https://example.org','signature':'Test signature','fields':[{'id':field,'value':'PHP'},{'id':private,'value':'secret marker'}]}
        c.call('PATCH',own,200,data,token=token)
        self.assertEqual(label,c.call('GET',profile)['member']['name'])
        self.assertEqual(['PHP'],[v['field_value'] for v in c.call('GET',profile)['member']['fields']])
        self.assertEqual(2,len(c.call('GET',own,token=token)['values']))
        self.assertNotIn('secret marker',json.dumps(c.call('GET',profile,token=foreign)))
        self.assertIn(member,[m['id'] for m in c.call('GET','/forum/members?q='+label)])
        c.call('PUT','/forum/account/images/avatar',422,{'data':base64.b64encode(b'<svg onload="alert(1)"/>').decode()},token=token)
        image=base64.b64encode(profile_png()).decode()
        c.call('PUT','/forum/account/images/avatar',200,{'data':image},token=token)
        c.call('PUT','/forum/account/images/cover',200,{'data':image},token=token)
        avatar=c.call('GET',profile)['member']['avatar_url']
        def image_status(client,status,bearer=None):
            req=urllib.request.Request(self.base+avatar,headers={'Authorization':'Bearer '+bearer} if bearer else {})
            try: response=client.opener.open(req,timeout=10)
            except urllib.error.HTTPError as err: response=err
            with response:
                self.assertEqual(status,response.status)
                self.assertIn('no-store',response.headers['Cache-Control'])
                if status==200:
                    self.assertEqual('image/jpeg',response.headers.get_content_type())
                    self.assertTrue(response.read().startswith(b'\xff\xd8'))
        image_status(c,200)
        board=c.call('GET','/forum')['boards'][0]['id']
        thread=c.call('POST',f'/forum/boards/{board}/threads',201,{'title':'Profile privacy topic','body':'Public content'},token=token)['id']
        url='/forum/threads/'+thread
        self.assertEqual('Test signature',c.call('GET',url)['posts'][0]['signature'])
        c.call('PATCH',prefs,200,{'show_signatures':False},token=foreign)
        self.assertEqual('',c.call('GET',url,token=foreign)['posts'][0]['signature'])
        c.call('PATCH',prefs,200,{'profile_visibility':'members','activity_visibility':'private','theme':'light','locale':'en-US','timezone':'Europe/Berlin','show_groups':True},token=token)
        c.call('GET',profile,404)
        self.assertEqual([],c.call('GET','/forum/members?q='+label))
        image_status(c,404)
        image_status(c,200,foreign)
        self.assertEqual([],c.call('GET',profile,token=foreign)['threads'])
        self.assertIn(thread,[t['id'] for t in c.call('GET',profile,token=token)['threads']])
        c.call('PATCH',prefs,200,{'profile_visibility':'private'},token=token)
        c.call('GET',profile,404,token=foreign)
        image_status(c,404,foreign)
        image_status(c,200,token)
        browser=Client(self.base)
        response,_=browser.login(email,browser=True)
        csrf=response['data']['csrf_token']
        browser.call('PATCH',own,403,{'bio':'CSRF attempt'})
        browser.call('PATCH',own,200,{'bio':'Cookie authenticated'},headers={'X-CSRF-Token':csrf})
        image_status(browser,200)
        self.assertEqual('Public content',c.call('GET',url)['posts'][0]['body'])
        self.assertEqual('',c.call('GET',url)['posts'][0]['signature'])
        c.call('POST',profile+'/moderate-profile',403,{'field':'bio'},token=foreign)
        c.call('POST',profile+'/moderate-profile',200,{'field':'bio'},token=admin)
        self.assertEqual('',c.call('GET',own,token=token)['profile']['bio'])
        c.call('DELETE','/forum/account/images/avatar',200,token=token)
        image_status(c,404,token)
        c.call('PUT','/forum/profile-fields/'+field,200,{'label':'Language','visibility':'members','position':1},token=admin)
        c.call('DELETE','/forum/profile-fields/'+private,200,token=admin)
        self.assertEqual([field],[v['id'] for v in c.call('GET',own,token=token)['values']])
        self.assertEqual('',c.call('GET','/forum/members/'+other,token=foreign)['member']['bio'])
        c.call('DELETE','/forum/profile-fields/'+field,200,token=admin)
        c.call('DELETE','/forum/account/images/cover',200,token=token)

    def test_08_read_tracking(self):
        c=Client(self.base)
        admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        email,_,member=self.activated(c)
        second_device=Client(self.base).login(email)[0]['data']['access_token']
        category=c.call('POST','/forum/categories',201,{'name':'Read tracking','description':''},token=admin)['id']
        parent=c.call('POST',f'/forum/categories/{category}/boards',201,{'name':'Read parent','description':''},token=admin)['id']
        board=c.call('POST',f'/forum/categories/{category}/boards',201,{'name':'Read child','description':'','parent_id':parent},token=admin)['id']
        hidden=c.call('POST',f'/forum/categories/{category}/boards',201,{'name':'Hidden read child','description':'','parent_id':parent},token=admin)['id']
        c.call('PUT',f'/forum/boards/{hidden}/permissions',200,{'visibility':'hidden','rules':[]},token=admin)
        def topic(b,title): return c.call('POST',f'/forum/boards/{b}/threads',201,{'title':title,'body':'First post'},token=admin)['id']
        t=topic(board,'Read tracking topic'); secret=topic(hidden,'Secret unread topic')
        url='/forum/threads/'+t; mark='/forum/read-state'
        d=c.call('GET',url,token=member); first=d['posts'][0]
        self.assertEqual(1,d['read_state']['unread_posts'])
        self.assertEqual(1,c.call('GET',url,token=second_device)['read_state']['unread_posts'])
        self.assertNotIn('read_state',c.call('GET',url))
        c.call('GET','/forum/unread',401)
        c.call('POST',mark,401,{'scope':'thread','id':t})
        browser_client=Client(self.base); browser_client.login(email,browser=True)
        browser_client.call('POST',mark,403,{'scope':'thread','id':t})
        c.call('POST',mark,404,{'scope':'thread','id':secret},token=member)
        self.assertEqual(1,c.call('GET',f'/forum/boards/{parent}',token=member)['board']['unread_threads'])
        new=c.call('POST',url+'/posts',201,{'body':'Arrived after page load'},token=admin)['id']
        ack={'scope':'thread','id':t,'post_id':first['id'],'sequence':int(first['read_sequence'])}
        result=c.call('POST',mark,200,ack,token=member)
        self.assertEqual(1,result['unread_posts']);self.assertEqual(new,result['first_unread']['id'])
        self.assertEqual(1,c.call('GET',url,token=second_device)['read_state']['unread_posts'])
        self.assertIn(t,[x['id'] for x in c.call('GET','/forum/unread',token=member)])
        self.assertNotIn(secret,[x['id'] for x in c.call('GET','/forum/unread',token=member)])
        c.call('POST',mark,200,{'scope':'thread','id':t},token=second_device)
        self.assertEqual(0,c.call('POST',mark,200,ack,token=member)['unread_posts'])
        target=topic(board,'Read target')
        c.call('POST',mark,200,{'scope':'thread','id':target},token=member)
        old=c.call('GET',url,token=member)['posts'][1]
        c.call('POST',url+'/transfer',200,{'post_ids':[new],'target_id':target},token=admin)
        self.assertEqual(1,c.call('GET','/forum/threads/'+target,token=member)['read_state']['unread_posts'])
        c.call('POST',mark,409,{'scope':'thread','id':t,'post_id':new,'sequence':int(old['read_sequence'])},token=member)
        # ordinal 2 is reused in the source, but the sequence is not.
        replacement=c.call('POST',url+'/posts',201,{'body':'Replacement ordinal'},token=admin)['id']
        self.assertEqual(replacement,c.call('GET',url,token=member)['read_state']['first_unread']['id'])
        c.call('POST',mark,200,{'scope':'board','id':parent},token=member)
        self.assertEqual(0,c.call('GET',f'/forum/boards/{parent}',token=member)['board']['unread_threads'])
        c.call('POST','/forum/threads/'+target+'/merge',200,{'source_ids':[t]},token=admin)
        self.assertEqual(2,c.call('GET','/forum/threads/'+target,token=member)['read_state']['unread_posts'])
        target_url='/forum/threads/'+target
        for i in range(22): c.call('POST',target_url+'/posts',201,{'body':'Page two reply '+str(i)},token=admin)
        last=c.call('GET',target_url,token=member)['posts'][-1]
        result=c.call('POST',mark,200,{'scope':'thread','id':target,'post_id':last['id'],'sequence':int(last['read_sequence'])},token=member)
        self.assertEqual(2,result['first_unread']['page'])
        jump=c.call('GET',target_url+'?first_unread=1',token=member)
        self.assertEqual(2,jump['page']); self.assertEqual(result['first_unread']['id'],jump['posts'][0]['id'])
        c.call('POST',mark,200,{'scope':'all'},token=member)
        self.assertEqual([],c.call('GET','/forum/unread',token=member))
        c.call('PUT',f'/forum/boards/{hidden}/permissions',200,{'visibility':'public','rules':[]},token=admin)
        self.assertEqual(1,c.call('GET','/forum/threads/'+secret,token=member)['read_state']['unread_posts'])
        c.call('POST','/forum/threads/'+target+'/moderate',200,{'action':'delete'},token=admin)
        c.call('POST','/forum/threads/'+secret+'/moderate',200,{'action':'delete'},token=admin)
        for b in (hidden,board,parent): c.call('DELETE',f'/forum/boards/{b}',200,token=admin)
        c.call('DELETE',f'/forum/categories/{category}',200,token=admin)

    def test_09_notifications(self):
        c=Client(self.base)
        admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        email,recipient,owner=self.activated(c)
        _,sender,writer=self.activated(c)
        inbox='/forum/notifications'; mark=inbox+'/read'
        c.call('GET',inbox,401); c.call('POST',mark,401,{'all':True})
        category=c.call('POST','/forum/categories',201,{'name':'Notification tests','description':''},token=admin)['id']
        board=c.call('POST',f'/forum/categories/{category}/boards',201,{'name':'Notification test board','description':''},token=admin)['id']
        t=c.call('POST',f'/forum/boards/{board}/threads',201,{'title':'Notification source','body':'Original'},token=owner)['id']
        path='/forum/threads/'+t
        first=c.call('GET',path,token=owner)['first_post_id']
        c.call('PUT',path+'/watch',200,{'enabled':True},token=owner)
        body=f'@{{{recipient}}} @{{{recipient}}} >>{{{first}}} >>{{{first}}}'
        reply=c.call('POST',path+'/posts',201,{'body':body},token=writer)['id']
        for _ in range(2): c.call('PUT','/forum/posts/'+first+'/reaction',200,{'enabled':True},token=writer)
        c.call('POST',path+'/moderate',200,{'action':'lock'},token=admin)
        c.call('POST',path+'/moderate',200,{'action':'lock'},token=admin)
        self.assertEqual(0,c.call('GET',inbox+'?count_only=1',token=owner)['unread'])
        def drain():
            for _ in range(20):
                result=c.call('POST','/queue/work',200,{'limit':10},token=admin)
                self.assertEqual(0,result['failed'])
                if result['forum_notifications_processed']==0: return
            self.fail('Notification outbox did not drain')
        drain()
        data=c.call('GET',inbox,token=owner); items=data['items']
        self.assertEqual(5,data['unread']);self.assertCountEqual(['reply','mention','quote','reaction','moderation'],[x['kind'] for x in items])
        drain(); self.assertEqual(5,c.call('GET',inbox+'?count_only=1',token=owner)['unread'])
        self.assertEqual(0,c.call('GET',inbox+'?count_only=1',token=writer)['unread'])
        item=next(x for x in items if x['kind']=='reply')
        c.call('GET',inbox+'/'+item['id'],404,token=writer)
        c.call('POST',mark,404,{'id':item['id']},token=writer)
        browser=Client(self.base);browser.login(email,browser=True)
        browser.call('POST',mark,403,{'all':True})
        self.assertEqual(4,c.call('POST',mark,200,{'id':item['id']},token=owner)['unread'])
        self.assertEqual(4,c.call('GET',inbox+'?count_only=1',token=Client(self.base).login(email)[0]['data']['access_token'])['unread'])
        # Revoke access after delivery, and ensure even the counter does not reveal the rows.
        acl=f'/forum/boards/{board}/permissions'; old=c.call('GET',acl,token=admin)
        c.call('PUT',acl,200,{'visibility':'hidden','rules':[]},token=admin)
        self.assertEqual([],c.call('GET',inbox,token=owner)['items'])
        self.assertEqual(0,c.call('GET',inbox+'?count_only=1',token=owner)['unread'])
        c.call('GET',inbox+'/'+item['id'],404,token=owner)
        c.call('POST',mark,404,{'id':item['id']},token=owner)
        # Pending mention is discarded when the target is inaccessible at dispatch.
        secret=c.call('POST',f'/forum/boards/{board}/threads',201,{'title':'Secret notification','body':f'@{{{recipient}}}'},token=admin)['id']
        drain()
        c.call('PUT',acl,200,{'visibility':old['visibility'],'rules':old['rules']},token=admin)
        self.assertEqual(5,len(c.call('GET',inbox,token=owner)['items']))
        # Notification follows the stable message UUID after a split/transfer.
        target=c.call('POST',f'/forum/boards/{board}/threads',201,{'title':'Notification target','body':'Target'},token=admin)['id']
        c.call('POST',path+'/transfer',200,{'post_ids':[reply],'target_id':target},token=admin)
        resolved=c.call('GET',inbox+'/'+item['id'],token=owner)
        self.assertEqual(target,resolved['thread_id']);self.assertEqual(reply,resolved['post_id'])
        prefs=data['preferences'];prefs['reaction']=False
        c.call('PUT','/forum/notification-preferences',200,prefs,token=owner)
        c.call('PUT','/forum/posts/'+first+'/reaction',200,{'enabled':False},token=writer)
        c.call('PUT','/forum/posts/'+first+'/reaction',200,{'enabled':True},token=writer)
        drain();self.assertNotIn('reaction',[x['kind'] for x in c.call('GET',inbox,token=owner)['items']])
        self.assertEqual(0,c.call('POST',mark,200,{'all':True},token=owner)['unread'])
        for thread in (t,target,secret):c.call('POST','/forum/threads/'+thread+'/moderate',200,{'action':'delete'},token=admin)
        self.assertEqual([],c.call('GET',inbox,token=owner)['items'])
        c.call('DELETE',f'/forum/boards/{board}',200,token=admin)
        c.call('DELETE',f'/forum/categories/{category}',200,token=admin)

    def test_10_watch_delivery(self):
        c=Client(self.base); admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        email,user,subscriber=self.activated(c)
        cat=c.call('POST','/forum/categories',201,{'name':'Mail test category','description':''},token=admin)['id']
        board=c.call('POST',f'/forum/categories/{cat}/boards',201,{'name':'Mail test board','description':''},token=admin)['id']
        path=f'/forum/boards/{board}/watch'; settings='/forum/watch-settings'
        c.call('PUT',path,401,{'enabled':True,'mode':'email'})
        c.call('PUT',path,422,{'enabled':True,'mode':'bad'},token=subscriber)
        browser=Client(self.base);browser.login(email,browser=True)
        browser.call('PUT',path,403,{'enabled':True,'mode':'email'})
        c.call('PUT',path,200,{'enabled':True,'mode':'email'},token=subscriber)
        self.assertEqual('email',c.call('GET',settings,token=subscriber)['boards'][0]['mode'])
        thread=c.call('POST',f'/forum/boards/{board}/threads',201,{'title':'Mail private title','body':'Mail private body'},token=admin)['id']
        self.assertEqual(1,int(c.call('GET',settings,token=subscriber)['delivery']['pending']))
        result=c.call('POST','/queue/work',200,{'limit':10},token=admin)
        self.assertEqual(1,result['forum_mail']['sent']); self.assertEqual(0,result['failed'])
        url,token=mail_link(email,'/forum/unsubscribe',self.base)
        self.assertEqual(1,int(c.call('GET',settings,token=subscriber)['delivery']['sent']))
        self.assertEqual(0,c.call('POST','/queue/work',200,{'limit':10},token=admin)['forum_mail']['sent'])
        c.call('POST','/forum/unsubscribe',422,{'token':token[:-1]+('0' if token[-1]!='0' else '1')})
        tpath='/forum/threads/'+thread+'/watch'
        c.call('PUT',tpath,200,{'enabled':True,'mode':'daily'},token=subscriber)
        c.call('PUT',settings,200,{'auto_mode':'weekly'},token=subscriber)
        c.call('POST','/forum/threads/'+thread+'/posts',201,{'body':'Digest update'},token=admin)
        self.assertEqual(1,int(c.call('GET',settings,token=subscriber)['delivery']['pending']))
        self.assertEqual(0,c.call('POST','/queue/work',200,{'limit':10},token=admin)['forum_mail']['sent'])
        c.call('POST','/forum/unsubscribe',200,{'token':token})
        prefs=c.call('GET',settings,token=subscriber)
        self.assertEqual('internal',prefs['auto_mode']);self.assertEqual('internal',prefs['boards'][0]['mode'])
        self.assertEqual(1,int(prefs['delivery']['cancelled']))
        self.assertEqual('internal',c.call('GET','/forum/threads/'+thread,token=subscriber)['thread']['watch_mode'])
        # New board topics generate in-app notifications after opting out of email.
        topic=c.call('POST',f'/forum/boards/{board}/threads',201,{'title':'New board notification','body':'New topic'},token=admin)['id']
        c.call('POST','/queue/work',200,{'limit':10},token=admin)
        self.assertTrue(any(n['thread_id']==topic and n['subscription_kind']=='board' for n in c.call('GET','/forum/notifications',token=subscriber)['items']))
        # Auto-watch never overwrites an explicit mode.
        c.call('PUT',tpath,200,{'enabled':True,'mode':'none'},token=subscriber)
        c.call('POST','/forum/threads/'+thread+'/posts',201,{'body':'Own response'},token=subscriber)
        self.assertEqual('none',c.call('GET','/forum/threads/'+thread,token=subscriber)['thread']['watch_mode'])
        # An already queued email is invisible and cancelled when the ACL is revoked.
        other_email,_,other=self.activated(c)
        c.call('PUT',tpath,200,{'enabled':True,'mode':'email'},token=other)
        c.call('POST','/forum/threads/'+thread+'/posts',201,{'body':'Pending private message'},token=admin)
        acl=f'/forum/boards/{board}/permissions'
        c.call('PUT',acl,200,{'visibility':'hidden','rules':[]},token=admin)
        self.assertEqual([],c.call('GET',settings,token=other)['boards'])
        self.assertEqual(0,c.call('GET',settings,token=other)['delivery']['pending'])
        result=c.call('POST','/queue/work',200,{'limit':10},token=admin)
        self.assertEqual(1,result['forum_mail']['cancelled']);self.assertEqual(0,result['forum_mail']['sent'])
        c.call('PUT',acl,200,{'visibility':'public','rules':[]},token=admin)
        for t in (thread,topic):c.call('POST','/forum/threads/'+t+'/moderate',200,{'action':'delete'},token=admin)
        c.call('DELETE',f'/forum/boards/{board}',200,token=admin)
        c.call('DELETE',f'/forum/categories/{cat}',200,token=admin)

    def test_11_conversations(self):
        c=Client(self.base);admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        ea,a,at=self.activated(c); eb,b,bt=self.activated(c); ec,third,ct=self.activated(c)
        base='/forum/conversations'; settings='/forum/conversation-settings'
        data={'participant_ids':[b],'title':'Private conversation regression','body':XSS,'kind':'group'}
        c.call('POST',base,401,data)
        browser=Client(self.base);browser.login(ea,browser=True);browser.call('POST',base,403,data)
        made=c.call('POST',base,201,data,token=at);path=base+'/'+made['id']; first=made['message_id']
        for token in (ct,admin):
            c.call('GET',path,404,token=token);c.call('POST',path+'/messages',404,{'body':'Intrusion'},token=token)
        self.assertEqual(1,c.call('GET',base,token=bt)['unread'])
        self.assertEqual(0,c.call('GET',base+'?q=Private',token=ct)['unread'])
        self.assertEqual([],c.call('GET',base+'?q=Private',token=ct)['items'])
        self.assertEqual(XSS,c.call('GET',path,token=bt)['messages'][0]['body'])
        c.call('PATCH',path+'/messages/'+first,403,{'body':'Intrusion'},token=bt)
        c.call('PATCH',path+'/messages/'+first,200,{'body':'Original private history'},token=at)
        c.call('POST',path+'/read',200,{'message_id':first},token=bt)
        self.assertEqual(0,c.call('GET',base,token=bt)['unread'])
        c.call('POST',path+'/invite',403,{'participant_ids':[third]},token=bt)
        c.call('PUT',settings,200,{'incoming':'none'},token=ct)
        c.call('POST',path+'/invite',403,{'participant_ids':[third]},token=at)
        c.call('PUT',settings,200,{'incoming':'members'},token=ct)
        c.call('POST',path+'/invite',200,{'participant_ids':[third]},token=at)
        self.assertEqual([],c.call('GET',path,token=ct)['messages'])
        self.assertEqual([],c.call('GET',base+'?q=Original+private+history',token=ct)['items'])
        c.call('POST',path+'/read',404,{'message_id':first},token=ct)
        c.call('POST',path+'/messages/'+first+'/report',404,{'reason':'Attempt'},token=ct)
        c.call('PATCH',path+'/settings',200,{'muted':True,'archived':True},token=ct)
        second=c.call('POST',path+'/messages',201,{'body':'New group message'},token=bt)['id']
        self.assertEqual(1,c.call('GET',base,token=ct)['unread'])
        self.assertEqual(0,c.call('GET',base+'?notifications=1',token=ct)['notification_unread'])
        muted=c.call('GET','/forum/notifications?count_only=1',token=ct)
        self.assertEqual(0,muted['conversation_unread']);self.assertEqual(1,muted['conversation_total_unread'])
        self.assertEqual([second],[m['id'] for m in c.call('GET',path,token=ct)['messages']])
        c.call('PATCH',path+'/settings',200,{'muted':False},token=ct)
        self.assertEqual(1,c.call('GET',base+'?notifications=1',token=ct)['notification_unread'])
        self.assertEqual(1,c.call('GET','/forum/notifications?count_only=1',token=ct)['conversation_unread'])
        self.assertEqual([],c.call('GET','/forum/notifications',token=admin)['conversations'])
        report=c.call('POST',path+'/messages/'+second+'/report',201,{'reason':'Reported message'},token=ct)['id']
        c.call('PATCH',path+'/messages/'+second,200,{'body':'Edited afterwards'},token=bt)
        c.call('GET','/forum/conversation-reports/'+report,403,token=ct)
        self.assertEqual('New group message',c.call('GET','/forum/conversation-reports/'+report,token=admin)['body_snapshot'])
        c.call('PUT','/forum/conversation-reports/'+report,200,{'status':'resolved'},token=admin)
        c.call('PUT','/forum/conversation-blocks/'+b,200,{'enabled':True},token=ct)
        c.call('POST',path+'/messages',403,{'body':'Blocked'},token=bt)
        c.call('PATCH',path+'/messages/'+second,403,{'body':'Blocked edit'},token=bt)
        self.assertEqual('Edited afterwards',c.call('GET',path,token=ct)['messages'][0]['body'])
        c.call('POST',base,403,{'participant_ids':[third],'title':'Blocked','body':'Blocked'},token=bt)
        c.call('PUT','/forum/conversation-blocks/'+b,200,{'enabled':False},token=ct)
        c.call('DELETE',path+'/participants/'+third,403,token=bt)
        c.call('DELETE',path+'/participants/'+third,200,token=at);c.call('GET',path,404,token=ct)
        c.call('POST',path+'/invite',200,{'participant_ids':[third]},token=at)
        self.assertEqual([],c.call('GET',path,token=ct)['messages'])
        c.call('POST',path+'/leave',200,token=at);c.call('GET',path,404,token=at)
        self.assertTrue(c.call('GET',path,token=bt)['can_invite'])
        # Older-page watermark cannot regress or acknowledge future messages.
        third_msg=c.call('POST',path+'/messages',201,{'body':'After reinvite'},token=bt)['id']
        c.call('POST',path+'/read',200,{'message_id':third_msg},token=ct)
        self.assertEqual(0,c.call('GET',base,token=ct)['unread'])
        direct=c.call('POST',base,201,{'participant_ids':[b],'title':'Direct message','body':'Private direct','kind':'direct'},token=at)['id']
        c.call('POST',base+'/'+direct+'/invite',403,{'participant_ids':[third]},token=at)

    def test_12_activity(self):
        c=Client(self.base); admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        email=unique_email(); author=c.call('POST','/users',201,{'email':email,'name':'Reputation author','password':PASSWORD,'roles':['forum-member']},token=admin)['id']
        writer=c.login(email)[0]['data']['access_token']
        cat=c.call('POST','/forum/categories',201,{'name':'Activity category','description':'test'},token=admin)['id']
        board=c.call('POST','/forum/categories/'+cat+'/boards',201,{'name':'Activity board','description':'test'},token=admin)['id']
        thread=c.call('POST','/forum/boards/'+board+'/threads',201,{'title':'Activity test topic','body':'Real publication'},token=writer)['id']
        path='/forum/threads/'+thread;post=c.call('GET',path)['posts'][0]['id'];reaction='/forum/posts/'+post+'/reaction';profile='/forum/members/'+author
        relation=profile+'/relationship';feed='/forum/activity?scope=following';acl='/forum/boards/'+board+'/permissions'
        for _ in range(2): c.call('PUT',reaction,200,{'enabled':True,'type':'helpful'},token=admin)
        self.assertEqual(2,c.call('GET',profile)['member']['reputation']['reputation'])
        self.assertEqual('helpful',c.call('GET',path,token=admin)['posts'][0]['my_reaction'])
        self.assertIn('first_post',c.call('GET',profile)['member']['reputation']['achievements'])
        c.call('PUT',reaction,200,{'enabled':True,'type':'celebrate'},token=admin)
        self.assertEqual(1,c.call('GET',profile)['member']['reputation']['reputation'])
        c.call('PUT',reaction,200,{'enabled':True,'type':'helpful'},token=writer)
        self.assertEqual(1,c.call('GET',profile)['member']['reputation']['reputation'])
        c.call('PUT',relation,200,{'enabled':True,'kind':'follow'},token=admin)
        self.assertTrue(any(x['post_id']==post for x in c.call('GET',feed,token=admin)))
        c.call('PUT',relation,200,{'enabled':True,'kind':'ignore'},token=admin)
        self.assertFalse(any(x['post_id']==post for x in c.call('GET',feed,token=admin)))
        self.assertTrue(c.call('GET',path,token=admin)['posts'][0]['ignored_author'])
        c.call('PUT',relation,200,{'enabled':False,'kind':'ignore'},token=admin)
        c.call('PUT',acl,200,{'visibility':'hidden','rules':[]},token=admin)
        self.assertFalse(any(x['post_id']==post for x in c.call('GET','/forum/activity')))
        self.assertEqual(0,c.call('GET',profile)['member']['reputation']['reputation'])
        c.call('PUT',acl,200,{'visibility':'public','rules':[]},token=admin)
        c.call('PATCH','/forum/account/preferences',200,{'activity_visibility':'private'},token=writer)
        self.assertIsNone(c.call('GET',profile)['member']['reputation'])
        self.assertFalse(any(x['post_id']==post for x in c.call('GET',feed,token=admin)))
        self.assertFalse(any(x['id']==author for x in c.call('GET','/forum/leaderboard')))
        c.call('PUT','/forum/achievement-rules/first_post',403,{'enabled':True,'threshold':2},token=writer)
        c.call('PATCH','/forum/account/preferences',200,{'activity_visibility':'public'},token=writer)
        c.call('PUT','/forum/achievement-rules/first_post',200,{'enabled':True,'threshold':2},token=admin)
        self.assertNotIn('first_post',c.call('GET',profile)['member']['reputation']['achievements'])
        c.call('PUT','/forum/achievement-rules/first_post',200,{'enabled':True,'threshold':1},token=admin)
        c.call('PUT',reaction,200,{'enabled':False},token=admin)
        self.assertEqual(0,c.call('GET',profile)['member']['reputation']['reputation'])
        c.call('POST',path+'/moderate',200,{'action':'delete'},token=admin)
        c.call('DELETE','/forum/boards/'+board,200,token=admin);c.call('DELETE','/forum/categories/'+cat,200,token=admin)

    def test_13_conversation_invitation_budget(self):
        c=Client(self.base); admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        email=unique_email(); owner=c.call('POST','/users',201,{'email':email,'name':'Invite quota owner','password':PASSWORD,'roles':['forum-member']},token=admin)['id']
        token=c.login(email)[0]['data']['access_token']; recipients=[]
        for i in range(11):
            recipients.append(c.call('POST','/users',201,{'email':unique_email(),'name':'Quota recipient '+str(i),'password':PASSWORD,'roles':['forum-member']},token=admin)['id'])
        path='/forum/conversations'; payload={'kind':'group','title':'Quota test','body':'Initial message','participant_ids':recipients}
        first=c.call('POST',path,201,payload,token=token)['id']
        c.call('POST',path,429,payload,token=token)
        self.assertEqual([first],[r['id'] for r in c.call('GET',path,token=token)['items']])
        # The failed creation must not consume the remaining invite budget.
        payload['participant_ids']=recipients[:9];c.call('POST',path,201,payload,token=token)
        payload['participant_ids']=recipients[:1];c.call('POST',path,429,payload,token=token)

    def test_14_rich_content(self):
        c=Client(self.base); admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        board=c.call('GET','/forum')['boards'][0]['id']; source='**Bold** <script>alert(1)</script>'
        preview=c.call('POST','/forum/content/preview',200,{'body':source,'body_format':'markdown'},token=admin)
        self.assertIn('<strong>Bold</strong>',preview['html']); self.assertNotIn('<script>',preview['html'])
        thread=c.call('POST','/forum/boards/'+board+'/threads',201,{'title':'Rich content regression','body':source,'body_format':'markdown'},token=admin)['id']
        path='/forum/threads/'+thread;post=c.call('GET',path)['posts'][0];self.assertEqual(preview['html'],post['body_html']);self.assertEqual('markdown',post['body_format'])
        c.call('PATCH','/forum/posts/'+post['id'],200,{'body':'[b]Edited[/b]','body_format':'bbcode'},token=admin)
        self.assertEqual('<strong>Edited</strong>',c.call('GET',path)['posts'][0]['body_html'])
        c.call('PATCH','/forum/posts/'+post['id'],200,{'body':'[i]Preserved[/i]'},token=admin)
        self.assertEqual('bbcode',c.call('GET',path)['posts'][0]['body_format'])
        c.call('POST',path+'/posts',201,{'body':'**Literal**'},token=admin)
        self.assertEqual('plain',c.call('GET',path)['posts'][1]['body_format'])
        c.call('POST',path+'/posts',422,{'body':'Bad','body_format':'html'},token=admin)
        quote='>>{'+post['id']+'}';c.call('POST',path+'/posts',201,{'body':quote,'body_format':'markdown'},token=admin)
        self.assertIn('/forum/threads/'+thread+'?page=1#post-'+post['id'],c.call('GET',path)['posts'][-1]['body_html'])
        email=unique_email(); member=c.call('POST','/users',201,{'email':email,'name':'Rich recipient','password':PASSWORD,'roles':['forum-member']},token=admin)['id']
        recipient=c.login(email)[0]['data']['access_token'];conv=c.call('POST','/forum/conversations',201,{'title':'Rich private','participant_ids':[member],'body':'[b]Private[/b]','body_format':'bbcode'},token=admin)['id']
        self.assertEqual('<strong>Private</strong>',c.call('GET','/forum/conversations/'+conv,token=recipient)['messages'][0]['body_html'])
        c.call('GET','/forum/conversations/'+conv,401)
        c.call('POST',path+'/moderate',200,{'action':'delete'},token=admin)

    def test_15_attachments(self):
        c=Client(self.base); admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        email=unique_email(); member=c.call('POST','/users',201,{'email':email,'name':'Attachment member','password':PASSWORD,'roles':['forum-member']},token=admin)['id']; other=c.login(email)[0]['data']['access_token']
        raw=b'Attachment test content.\n'; a=c.upload_attachment('report.txt',raw,admin); aid=a['id']; path='/forum/attachments/'+aid
        self.assertEqual(hashlib.sha256(raw).hexdigest(),a['sha256']);self.assertEqual(raw,c.download_attachment(aid,admin))
        c.call('GET',path,404,token=other);c.call('GET',path+'/download',404);c.upload_attachment('wrong.php.txt',raw,admin,415)
        public=c.call('GET','/forum')['boards'][0]['id']; before=c.call('GET','/forum/boards/'+public)['threads']
        c.call('POST','/forum/boards/'+public+'/threads',404,{'title':'Foreign attachment','body':'Denied','attachment_ids':[aid]},token=other)
        self.assertEqual(before,c.call('GET','/forum/boards/'+public)['threads'])
        thread=c.call('POST','/forum/boards/'+public+'/threads',201,{'title':'Attachment test','body':'File','attachment_ids':[aid]},token=admin)['id']; url='/forum/threads/'+thread
        post=c.call('GET',url)['posts'][0];self.assertEqual(aid,post['attachments'][0]['id']);self.assertEqual(raw,c.download_attachment(aid))
        c.call('POST',url+'/posts',404,{'body':'Cannot reuse','attachment_ids':[aid]},token=admin)
        cat=c.call('POST','/forum/categories',201,{'name':'Attachment hidden','description':''},token=admin)['id']; hidden=c.call('POST','/forum/categories/'+cat+'/boards',201,{'name':'Attachment hidden board','description':''},token=admin)['id']
        c.call('PUT','/forum/categories/'+cat+'/permissions',200,{'visibility':'hidden','rules':[]},token=admin)
        c.call('POST','/forum/thread-actions/bulk',200,{'thread_ids':[thread],'action':'move','board_id':hidden},token=admin)
        c.call('GET',path+'/download',404,token=other);self.assertEqual(raw,c.download_attachment(aid,admin))
        private=c.upload_attachment('private.txt',raw,admin)['id']; conv=c.call('POST','/forum/conversations',201,{'kind':'direct','title':'File conversation','participant_ids':[member],'body':'Private file','attachment_ids':[private]},token=admin)['id'];cp='/forum/conversations/'+conv
        self.assertEqual(private,c.call('GET',cp,token=other)['messages'][0]['attachments'][0]['id']);self.assertEqual(raw,c.download_attachment(private,other));c.call('GET','/forum/attachments/'+private+'/download',404)
        c.call('POST',cp+'/leave',200,token=other);c.call('GET','/forum/attachments/'+private+'/download',404,token=other)
        c.call('DELETE',path,200,token=admin);c.call('GET',path,404,token=admin)
        pending=c.upload_attachment('pending.txt',raw,admin)['id'];self.assertIn(pending,[x['id'] for x in c.call('GET','/forum/attachments',token=admin)['items']]);c.call('DELETE','/forum/attachments/'+pending,200,token=admin)
        c.call('POST',url+'/moderate',200,{'action':'delete'},token=admin)

    def test_16_media(self):
        c=Client(self.base);admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        email=unique_email();member=c.call('POST','/users',201,{'email':email,'name':'Media member','password':PASSWORD,'roles':['forum-member']},token=admin)['id'];other=c.login(email)[0]['data']['access_token']
        raw=profile_png();aid=c.upload_attachment('photo.png',raw,admin)['id'];path='/forum/attachments/'+aid
        for _ in range(4):
            c.call('POST','/queue/work',200,{'limit':10},token=admin)
            if c.call('GET',path,token=admin)['variants']:break
        variants=c.call('GET',path,token=admin)['variants'];self.assertEqual(set(variants),{'thumbnail','medium','large'})
        self.assertEqual((2,2),(variants['thumbnail']['width'],variants['thumbnail']['height']))
        self.assertTrue(c.image_variant(aid,token=admin).startswith(b'\x89PNG'));self.assertEqual(raw,c.download_attachment(aid,admin))
        c.call('GET',path+'/image/thumbnail',404,token=other);c.call('GET',path+'/image/thumbnail',404)
        public=c.call('GET','/forum')['boards'][0]['id'];thread=c.call('POST','/forum/boards/'+public+'/threads',201,{'title':'Image publication','body':'image{'+aid+'}','attachment_ids':[aid]},token=admin)['id'];url='/forum/threads/'+thread
        self.assertEqual(aid,c.call('GET',url)['posts'][0]['attachments'][0]['id']);self.assertTrue(c.image_variant(aid).startswith(b'\x89PNG'))
        cat=c.call('POST','/forum/categories',201,{'name':'Media hidden','description':''},token=admin)['id'];hidden=c.call('POST','/forum/categories/'+cat+'/boards',201,{'name':'Media hidden board','description':''},token=admin)['id'];c.call('PUT','/forum/categories/'+cat+'/permissions',200,{'visibility':'hidden','rules':[]},token=admin)
        c.call('POST','/forum/thread-actions/bulk',200,{'thread_ids':[thread],'action':'move','board_id':hidden},token=admin);c.call('GET',path+'/image/large',404,token=other)
        c.call('GET','/forum/media/status',403,token=other);self.assertTrue(c.call('GET','/forum/media/status',token=admin)['processor_available'])
        c.call('POST',path+'/image/retry',200,token=admin);c.call('POST','/queue/work',200,{'limit':10},token=admin);self.assertTrue(c.image_variant(aid,token=admin))
        private=c.upload_attachment('private.png',raw,admin)['id'];conv=c.call('POST','/forum/conversations',201,{'kind':'direct','title':'Image conversation','participant_ids':[member],'body':'image{'+private+'}','attachment_ids':[private]},token=admin)['id'];cp='/forum/conversations/'+conv
        for _ in range(4):
            c.call('POST','/queue/work',200,{'limit':10},token=admin)
            if c.call('GET','/forum/attachments/'+private,token=admin)['variants']:break
        self.assertTrue(c.image_variant(private,token=other));c.call('GET','/forum/attachments/'+private+'/image/thumbnail',404)
        c.call('POST',cp+'/leave',200,token=other);c.call('GET','/forum/attachments/'+private+'/image/thumbnail',404,token=other)
        c.call('POST',url+'/moderate',200,{'action':'delete'},token=admin);c.call('GET',path+'/image/thumbnail',404,token=admin)

    def test_17_polls_questions(self):
        c=Client(self.base);admin=c.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
        email=unique_email();member=c.call('POST','/users',201,{'email':email,'name':'Poll member','password':PASSWORD,'roles':['forum-member']},token=admin)['id'];other=c.login(email)[0]['data']['access_token']
        public=c.call('GET','/forum')['boards'][0]['id'];create='/forum/boards/'+public+'/threads'
        config={'question':'Which release?','options':['First','Second','Third'],'max_choices':2,'allow_change':True,'visibility':'voted'}
        poll=c.call('POST',create,201,{'title':'Poll regression','body':'Vote now','kind':'poll','poll':config},token=admin)['id'];path='/forum/threads/'+poll
        p=c.call('GET',path)['interaction']['poll'];self.assertFalse(p['results_visible']);self.assertIsNone(p['voters']);self.assertNotIn('votes',p['options'][0])
        c.call('PUT',path+'/poll/vote',401,{'choices':[1]});c.call('PUT',path+'/poll/vote',422,{'choices':[1,1]},token=other)
        c.call('PUT',path+'/poll/vote',422,{'choices':[1,2,3]},token=other)
        c.call('PUT',path+'/poll/vote',200,{'choices':[1,2]},token=other)
        c.call('PUT',path+'/poll/vote',200,{'choices':[2,1]},token=other)
        p=c.call('GET',path,token=other)['interaction']['poll'];self.assertEqual(1,p['voters']);self.assertEqual([1,1,0],[o['votes'] for o in p['options']]);self.assertEqual([1,2],p['my_choices'])
        self.assertFalse(c.call('GET',path,token=admin)['interaction']['poll']['results_visible'])
        c.call('PUT',path+'/poll/vote',200,{'choices':[3]},token=other)
        c.call('PATCH',path+'/poll',403,{'closed':True},token=other);c.call('PATCH',path+'/poll',200,{'closed':True},token=admin)
        p=c.call('GET',path)['interaction']['poll'];self.assertTrue(p['closed']);self.assertEqual([0,0,1],[o['votes'] for o in p['options']]);c.call('PUT',path+'/poll/vote',409,{'choices':[1]},token=other)
        c.call('PATCH',path+'/poll',200,{'closed':False},token=admin);c.call('PUT',path+'/poll/vote',200,{'choices':[]},token=other)
        self.assertFalse(c.call('GET',path,token=other)['interaction']['poll']['results_visible'])
        fixed=c.call('POST',create,201,{'title':'Fixed vote poll','body':'One vote','kind':'poll','poll':{**config,'max_choices':1,'allow_change':False,'visibility':'closed'}},token=admin)['id'];fp='/forum/threads/'+fixed
        c.call('PUT',fp+'/poll/vote',200,{'choices':[1]},token=other);c.call('PUT',fp+'/poll/vote',409,{'choices':[2]},token=other)
        self.assertFalse(c.call('GET',fp,token=other)['interaction']['poll']['results_visible'])
        q=c.call('POST',create,201,{'title':'Question regression','body':'How to vote?','kind':'question'},token=admin)['id'];qp='/forum/threads/'+q
        self.assertIn(q,[x['id'] for x in c.call('GET','/forum/questions?status=unanswered')]);first=c.call('GET',qp)['first_post_id']
        reply=c.call('POST',qp+'/posts',201,{'body':'Answer'},token=other)['id'];second=c.call('POST',qp+'/posts',201,{'body':'Another answer'},token=admin)['id']
        c.call('PUT','/forum/posts/'+reply+'/answer-vote',403,{'enabled':True},token=other)
        c.call('PUT','/forum/posts/'+reply+'/answer-vote',200,{'enabled':True},token=admin);c.call('PUT','/forum/posts/'+reply+'/answer-vote',200,{'enabled':True},token=admin)
        ranked=c.call('GET',qp+'/answers?sort=score',token=admin);self.assertEqual(reply,ranked[0]['id']);self.assertEqual(1,ranked[0]['score']);self.assertTrue(ranked[0]['voted'])
        c.call('PUT',qp+'/accepted-answer',404,{'post_id':first},token=admin);c.call('PUT',qp+'/accepted-answer',403,{'post_id':reply},token=other)
        c.call('PUT',qp+'/accepted-answer',200,{'post_id':reply},token=admin);c.call('PUT',qp+'/accepted-answer',200,{'post_id':reply},token=admin)
        self.assertIn(q,[x['id'] for x in c.call('GET','/forum/questions?status=solved')]);self.assertEqual(reply,c.call('GET',qp)['interaction']['question']['accepted_post_id'])
        for _ in range(10):
            c.call('POST','/queue/work',200,{'limit':10},token=admin)
            items=[n for n in c.call('GET','/forum/notifications',token=other)['items'] if n['kind']=='accepted']
            if items:break
        self.assertEqual(1,len(items));self.assertEqual(reply,items[0]['post_id']);c.call('POST','/forum/notifications/read',200,{'id':items[0]['id']},token=other)
        c.call('POST',qp+'/merge',409,{'source_ids':[poll]},token=admin)
        target=c.call('POST',create,201,{'title':'Ordinary target','body':'Target'},token=admin)['id']
        c.call('POST',qp+'/transfer',200,{'post_ids':[reply],'target_id':target},token=admin)
        self.assertIsNone(c.call('GET',qp)['interaction']['question']['accepted_post_id']);self.assertEqual(second,c.call('GET',qp+'/answers')[0]['id']);c.call('GET','/forum/notifications/'+items[0]['id'],404,token=other)
        c.call('PUT','/forum/posts/'+reply+'/answer-vote',404,{'enabled':True},token=admin)
        cat=c.call('POST','/forum/categories',201,{'name':'Poll hidden','description':''},token=admin)['id'];hidden=c.call('POST','/forum/categories/'+cat+'/boards',201,{'name':'Hidden Q&A','description':''},token=admin)['id'];c.call('PUT','/forum/categories/'+cat+'/permissions',200,{'visibility':'hidden','rules':[]},token=admin)
        c.call('POST','/forum/thread-actions/bulk',200,{'thread_ids':[poll,q],'action':'move','board_id':hidden},token=admin)
        c.call('GET',path,404,token=other);c.call('PUT',path+'/poll/vote',404,{'choices':[1]},token=other);c.call('GET',qp+'/answers',404,token=other);self.assertNotIn(q,[x['id'] for x in c.call('GET','/forum/questions',token=other)])
        self.assertIn(q,[x['id'] for x in c.call('GET','/forum/questions',token=admin)])

    def test_99_rate_limit(self):
        # Run last: exhaust the real IP auth bucket without guessing credentials.
        c = Client(self.base)
        _, headers = c.request('GET', '/auth/me', 401)
        remaining = int(headers['X-RateLimit-Remaining'])
        self.assertGreaterEqual(remaining, 0)
        self.assertLessEqual(remaining, 160)
        for _ in range(remaining):
            c.request('GET', '/auth/me', 401)
        _, headers = c.request('GET', '/auth/me', 429)
        self.assertGreater(int(headers['Retry-After']), 0)


class BrowserTests(unittest.TestCase):
    base = ''
    browser_name = 'chromium'
    report = None

    def test_browser_lifecycle(self):
        from playwright.sync_api import sync_playwright, expect
        with sync_playwright() as p:
            browser = getattr(p, self.browser_name).launch(headless=True)
            context = browser.new_context(viewport={'width': 1280, 'height': 900})
            context.tracing.start(screenshots=True, snapshots=True, sources=True)
            page = context.new_page()
            errors = []
            page.on('pageerror', lambda error: errors.append(str(error)))
            email = unique_email()
            def fill(name, value):
                page.locator(f'#app [name="{name}"]').fill(value)
            def click(label):
                page.get_by_role('button', name=label, exact=True).click()
            def heading(label):
                expect(page.locator('#app').get_by_role('heading', name=label, exact=True)).to_be_visible()
            def login(address, password, code=None):
                page.goto(self.base + '/forum/login')
                fill('email', address); fill('password', password)
                if code:
                    fill('two_factor_code', code)
                click('Войти')
                expect(page).to_have_url(self.base + '/forum')
                heading('Разделы форума')
            try:
                page.goto(self.base + '/forum/register')
                fill('name', 'Browser member'); fill('email', email); fill('password', PASSWORD)
                click('Создать аккаунт'); heading('Подтвердите email')
                url, _ = mail_link(email, '/forum/verify-email', self.base)
                page.goto(url); fill('password', PASSWORD); click('Подтвердить'); heading('Email подтверждён')
                login(email, PASSWORD)
                expect(page.locator('#session')).to_contain_text('Browser member')
                page.goto(self.base+'/forum/account/profile'); heading('Профиль и настройки')
                fill('bio', XSS); fill('signature','Browser signature'); click('Сохранить профиль')
                expect(page.locator('#notice')).to_contain_text('Профиль сохранён')
                page.locator('#app input[type=file]').first.set_input_files({'name':'avatar.png','mimeType':'image/png','buffer':profile_png()})
                click('Загрузить аватар'); expect(page.locator('#app img.profile-avatar')).to_be_visible()
                page.locator('#app [name=theme]').select_option('light'); click('Сохранить настройки')
                expect(page.locator('html')).to_have_attribute('data-theme','light')
                page.goto(self.base+'/forum/members?q=Browser+member'); heading('Участники')
                page.get_by_role('link',name='Browser member',exact=True).click()
                expect(page.locator('.profile-text')).to_have_text(XSS)
                self.assertEqual(0,page.locator('.profile-text img,.profile-text script').count())
                writer=Client(self.base); admin_token=writer.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
                admin_id=writer.call('GET','/auth/me',token=admin_token)['user_id']
                page.goto(self.base+'/forum/conversations?to='+admin_id); heading('Личные сообщения')
                fill('title','Browser private conversation'); fill('body',XSS); click('Создать переписку'); heading('Browser private conversation')
                expect(page.locator('.conversation-message .body')).to_have_text(XSS)
                self.assertEqual(0,page.locator('.conversation-message img,.conversation-message script').count())
                private_id=page.url.rsplit('/',1)[-1]
                writer.call('POST','/forum/conversations/'+private_id+'/messages',201,{'body':'Private remote answer'},token=admin_token)
                page.goto(self.base+'/forum/notifications'); heading('Уведомления')
                page.get_by_role('link',name='Browser private conversation',exact=True).click()
                expect(page.locator('.conversation-message .body').last).to_have_text('Private remote answer')
                expect(page.locator('#conversations')).to_have_text('Переписки')
                click('Архивировать'); page.goto(self.base+'/forum/conversations?archived=1')
                expect(page.get_by_role('link',name='Browser private conversation',exact=True)).to_be_visible()
                page.goto(self.base+'/forum'); heading('Разделы форума')
                page.get_by_role('link', name='Test board', exact=True).click()
                fill('title', 'Browser regression topic'); fill('body', XSS); click('Создать тему')
                heading('Browser regression topic')
                thread = page.url
                expect(page.locator('.read-controls')).to_contain_text('Тема прочитана')
                expect(page.locator('article .body').first).to_have_text(XSS)
                self.assertEqual(0, page.locator('article .body img, article .body script').count())
                self.assertIsNone(page.evaluate('window.forumXss'))
                fill('body', 'Browser reply'); click('Отправить')
                expect(page.locator('article .body').last).to_have_text('Browser reply')
                page.locator('#app [name=body_format]').select_option('markdown'); fill('body','**Rich preview**'); click('Предпросмотр')
                expect(page.locator('.rich-preview strong')).to_have_text('Rich preview')
                click('Отправить'); expect(page.locator('article .body strong').last).to_have_text('Rich preview')
                click('Визуальный редактор'); visual=page.locator('.rich-visual'); expect(visual).to_be_visible(); visual.fill('Visual text'); visual.press('Control+a'); click('Жирный'); click('Отправить'); expect(page.locator('article .body strong').last).to_have_text('Visual text')
                expect(page.locator('.read-controls')).to_contain_text('Тема прочитана')
                page.goto(self.base+'/forum/unread'); heading('Непрочитанные темы')
                expect(page.get_by_role('link',name='Browser regression topic',exact=True)).to_have_count(0)
                page.goto(thread); heading('Browser regression topic')
                # A second account publishes while the first user's page is closed.
                writer=Client(self.base); token=writer.login(ADMIN,ADMIN_PASSWORD)[0]['data']['access_token']
                thread_id=thread.rsplit('/',1)[-1]
                reply=writer.call('POST','/forum/threads/'+thread_id+'/posts',201,{'body':'New remote reply'},token=token)['id']
                result=writer.call('POST','/queue/work',200,{'limit':10},token=token)
                self.assertEqual(0,result['failed'])
                page.goto(self.base+'/forum/notifications'); heading('Уведомления')
                expect(page.locator('.notification-unread')).to_contain_text('Новый ответ')
                # Marking a notification read does not acknowledge the forum post itself.
                page.locator('.notification-unread').get_by_role('button',name='Прочитано',exact=True).click()
                expect(page.locator('.notification-unread')).to_have_count(0)
                expect(page.get_by_role('heading',name='Настройки уведомлений')).to_be_visible()
                page.goto(self.base+'/forum/unread'); heading('Непрочитанные темы')
                page.get_by_role('link',name='Browser regression topic',exact=True).locator('..').get_by_role('link',name='1 новых',exact=True).click()
                expect(page).to_have_url(re.compile(r'#post-'+reply+r'$'))
                expect(page.locator('#post-'+reply)).to_contain_text('New remote reply')
                expect(page.locator('.read-controls')).to_contain_text('Тема прочитана')
                page.locator('article').first.get_by_role('button', name='Нравится', exact=True).click()
                expect(page.locator('article').first).to_contain_text('Нравится: 1')
                page.locator('article').first.get_by_role('button', name='Полезно', exact=True).click()
                expect(page.locator('article').first).to_contain_text('Полезно: 1')
                page.locator('article').first.get_by_role('button', name='Нравится', exact=True).click()
                expect(page.locator('article').first).to_contain_text('Нравится: 1')
                click('Подписаться'); expect(page.locator('#notice')).to_contain_text('Подписка сохранена')
                page.goto(self.base + '/forum/watches')
                expect(page.get_by_role('link', name='Browser regression topic')).to_be_visible()
                expect(page.get_by_role('heading',name='Автоматические подписки')).to_be_visible()
                page.locator('[name=auto_mode]').select_option('daily');click('Сохранить');expect(page.locator('#notice')).to_contain_text('Настройки сохранены')
                page.goto(self.base + '/forum/search?q=Browser')
                expect(page.get_by_role('link', name='Browser regression topic')).to_be_visible()
                # Password reset uses a real email link and revokes the browser session.
                page.goto(self.base + '/forum/forgot-password'); fill('email', email); click('Отправить письмо')
                expect(page.locator('#notice')).to_contain_text('письмо будет отправлено')
                url, _ = mail_link(email, '/forum/reset-password', self.base)
                page.goto(url); fill('password', NEW_PASSWORD); click('Сохранить пароль'); heading('Пароль изменён')
                login(email, NEW_PASSWORD)
                page.goto(self.base + '/forum/account'); heading('Настройки аккаунта')
                page.get_by_role('button', name='Настроить 2FA', exact=True).locator('..').locator('[name=password]').fill(NEW_PASSWORD)
                click('Настроить 2FA')
                secret = page.locator('#app pre').inner_text()
                confirm_form = page.get_by_role('button', name='Подтвердить 2FA', exact=True).locator('..')
                confirm_form.locator('[name=password]').fill(NEW_PASSWORD)
                confirm_form.locator('[name=two_factor_code]').fill(totp(secret))
                click('Подтвердить 2FA')
                heading('2FA включена')
                codes = page.locator('#app pre').inner_text().splitlines()
                self.assertEqual(8, len(set(codes)))
                login(email, NEW_PASSWORD, codes[0])
                page.goto(self.base + '/forum/account/sessions'); heading('Настройки аккаунта')
                expect(page.locator('#app')).to_contain_text('Текущая сессия')
                page.get_by_role('button', name='Завершить все сессии', exact=True).locator('..').locator('[name=password]').fill(NEW_PASSWORD)
                page.get_by_role('button', name='Завершить все сессии', exact=True).locator('..').locator('[name=two_factor_code]').fill(codes[1])
                click('Завершить все сессии'); expect(page).to_have_url(self.base + '/forum/login')
                # Admin browser session in a fresh context, no mocked requests.
                admin_context = browser.new_context()
                try:
                    admin_page = admin_context.new_page()
                    admin_page.goto(self.base + '/forum/login')
                    admin_page.locator('[name=email]').fill(ADMIN)
                    admin_page.locator('[name=password]').fill(ADMIN_PASSWORD)
                    admin_page.get_by_role('button', name='Войти', exact=True).click()
                    expect(admin_page).to_have_url(self.base + '/forum')
                    admin_page.goto(thread)
                    admin_page.get_by_role('button', name='Закрыть', exact=True).click()
                    expect(admin_page.get_by_role('button', name='Открыть', exact=True)).to_be_visible()
                    expect(admin_page.get_by_role('button', name='Отправить', exact=True)).to_have_count(0)
                    admin_page.get_by_role('button', name='Открыть', exact=True).click()
                    expect(admin_page.get_by_role('button', name='Отправить', exact=True)).to_be_visible()
                    # Exercise real moderator UI for metadata and split; no mock requests.
                    admin_page.get_by_role('button',name='Префикс и теги',exact=True).click()
                    admin_page.locator('[name=prefix]').fill('Browser prefix')
                    admin_page.locator('[name=tags]').fill('browser-tag, Browser-tag')
                    admin_page.get_by_role('button',name='Сохранить метаданные',exact=True).click()
                    expect(admin_page.locator('.thread-prefix')).to_have_text('Browser prefix')
                    expect(admin_page.get_by_role('link',name='#browser-tag',exact=True)).to_be_visible()
                    admin_page.locator('input[name=post-selection]').first.check()
                    admin_page.get_by_role('button',name='Разделить тему',exact=True).click()
                    admin_page.locator('[name=title]').fill('Browser split topic')
                    admin_page.once('dialog',lambda dialog: dialog.accept())
                    admin_page.get_by_role('button',name='Создать отдельную тему',exact=True).click()
                    expect(admin_page.get_by_role('heading',name='Browser split topic',exact=True)).to_be_visible()
                    expect(admin_page.locator('article .body').first).to_have_text('Browser reply')
                    admin_page.goto(thread)
                    expect(admin_page.locator('article')).to_have_count(1)
                    # Use the actual node-management forms, then navigate the public tree.
                    admin_page.goto(self.base + '/forum/manage')
                    group_form=admin_page.get_by_role('button',name='Создать группу',exact=True).locator('..')
                    group_form.locator('[name=handle]').fill('browser-vip')
                    group_form.locator('[name=name]').fill('Browser VIP')
                    group_form.get_by_role('button',name='Создать группу',exact=True).click()
                    expect(admin_page.get_by_role('heading',name='Browser VIP',exact=True)).to_be_visible()
                    admin_page.locator('[name=member]').select_option(label='Browser member ('+email+')')
                    primary=admin_page.locator('[name=primary_group_id]')
                    expect(primary).to_be_visible()
                    primary.select_option(label='Browser VIP')
                    admin_page.get_by_role('button',name='Сохранить назначения',exact=True).click()
                    expect(admin_page.locator('#notice')).to_contain_text('Назначения сохранены')
                    expect(admin_page.locator('[name=primary_group_id] option:checked')).to_have_text('Browser VIP')
                    category_form = admin_page.get_by_role('button', name='Создать', exact=True).locator('..')
                    category_form.locator('[name=name]').fill('Browser node category')
                    category_form.locator('[name=description]').fill('Node integration')
                    category_form.get_by_role('button', name='Создать', exact=True).click()
                    category_panel = admin_page.get_by_role('heading', name='Browser node category', exact=True).locator('..')
                    expect(category_panel).to_be_visible()
                    create = category_panel.get_by_role('button', name='Создать раздел', exact=True).locator('..')
                    create.locator('[name=name]').fill('Browser parent')
                    create.locator('[name=node_type]').select_option('container')
                    create.get_by_role('button', name='Создать раздел', exact=True).click()
                    parent = admin_page.get_by_role('heading', name='Browser parent', exact=True).locator('..')
                    expect(parent).to_be_visible()
                    child_form = parent.get_by_role('button', name='Создать подраздел', exact=True).locator('..')
                    child_form.locator('[name=name]').fill('Browser child')
                    child_form.get_by_role('button', name='Создать подраздел', exact=True).click()
                    expect(admin_page.get_by_role('heading', name='Browser child', exact=True)).to_be_visible()
                    admin_page.goto(self.base + '/forum')
                    admin_page.get_by_role('link', name='Browser parent', exact=True).click()
                    expect(admin_page.get_by_role('link', name='Browser child', exact=True)).to_be_visible()
                    expect(admin_page.get_by_role('button', name='Создать тему', exact=True)).to_have_count(0)
                    admin_page.get_by_role('link', name='Browser child', exact=True).click()
                    expect(admin_page.get_by_role('button', name='Создать тему', exact=True)).to_be_visible()
                    expect(admin_page.get_by_role('navigation',name='Навигационная цепочка')).to_contain_text('Browser node category')
                    expect(admin_page.get_by_role('navigation',name='Навигационная цепочка')).to_contain_text('Browser parent')
                    child_url=admin_page.url
                    admin_page.goto(self.base+'/forum/manage')
                    # Native drag/drop persists via the same relative transaction as keyboard selection.
                    source=admin_page.get_by_role('button',name='Переместить Browser child',exact=True)
                    target=admin_page.get_by_role('button',name='Внутрь Browser node category',exact=True)
                    expect(source).to_be_visible()
                    admin_page.once('dialog',lambda dialog: dialog.accept())
                    source.drag_to(target)
                    expect(admin_page.locator('#notice')).to_contain_text('Порядок разделов сохранён')
                    admin_page.goto(child_url)
                    expect(admin_page.get_by_role('navigation',name='Навигационная цепочка')).not_to_contain_text('Browser parent')
                    admin_page.goto(self.base+'/forum/manage')
                    source=admin_page.get_by_role('button',name='Переместить Browser child',exact=True)
                    source.focus();source.press('Enter')
                    target=admin_page.get_by_role('button',name='Внутрь Browser parent',exact=True)
                    admin_page.once('dialog',lambda dialog: dialog.accept())
                    target.focus();target.press('Enter')
                    expect(admin_page.locator('#notice')).to_contain_text('Порядок разделов сохранён')
                    admin_page.goto(child_url)
                    expect(admin_page.get_by_role('navigation',name='Навигационная цепочка')).to_contain_text('Browser parent')
                    admin_page.goto(self.base+'/forum/manage')
                    child_panel=admin_page.get_by_role('heading',name='Browser child',exact=True).locator('..')
                    child_panel.get_by_role('button',name='Разрешения',exact=True).click()
                    child_panel.locator('[name=visibility]').select_option('hidden')
                    child_panel.locator('[name=subject]').select_option('guest')
                    child_panel.locator('[data-permission="thread:read"]').select_option('never')
                    admin_page.once('dialog',lambda dialog: dialog.accept())
                    child_panel.get_by_role('button',name='Сохранить разрешения',exact=True).click()
                    expect(child_panel.get_by_role('button',name='Разрешения',exact=True)).to_be_visible()
                    child_panel.get_by_role('button',name='Разрешения',exact=True).click()
                    child_panel.get_by_role('button',name='Проверить',exact=True).click()
                    expect(child_panel.locator('.permission-inspector .acl-deny')).to_have_count(10)
                    guest=browser.new_context()
                    try:
                        guest_page=guest.new_page();guest_page.goto(self.base+'/forum')
                        expect(guest_page.get_by_role('link',name='Browser parent',exact=True)).to_be_visible()
                        expect(guest_page.get_by_role('link',name='Browser child',exact=True)).to_have_count(0)
                        guest_page.goto(child_url)
                        expect(guest_page.locator('#notice')).to_contain_text('Раздел не найден')
                    finally: guest.close()
                finally:
                    admin_context.close()
                self.assertEqual([], errors)
            except BaseException:
                page.screenshot(path=str(self.report / 'failure.png'), full_page=True)
                context.tracing.stop(path=str(self.report / 'trace.zip'))
                raise
            else:
                context.tracing.stop()
            finally:
                context.close(); browser.close()


class JUnitResult(unittest.TextTestResult):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.cases = []

    def startTest(self, test):
        super().startTest(test)
        self.started = time.monotonic()
        self.case = ET.Element('testcase', classname=test.__class__.__name__, name=test._testMethodName)

    def stopTest(self, test):
        self.case.set('time', f'{time.monotonic()-self.started:.3f}')
        self.cases.append(self.case)
        super().stopTest(test)

    def addFailure(self, test, err):
        super().addFailure(test, err)
        ET.SubElement(self.case, 'failure').text = self._exc_info_to_string(err, test)

    def addError(self, test, err):
        super().addError(test, err)
        ET.SubElement(self.case, 'error').text = self._exc_info_to_string(err, test)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--suite', choices=('api', 'browser'), required=True)
    parser.add_argument('--browser', choices=('chromium', 'firefox'), default='chromium')
    parser.add_argument('--report', type=Path, required=True)
    args = parser.parse_args()
    report = args.report.resolve(); report.mkdir(parents=True, exist_ok=True)
    with server(report) as base:
        cls = NetworkTests if args.suite == 'api' else BrowserTests
        cls.base = base
        if cls is BrowserTests:
            cls.browser_name = args.browser; cls.report = report
        result = unittest.TextTestRunner(verbosity=2, resultclass=JUnitResult).run(unittest.defaultTestLoader.loadTestsFromTestCase(cls))
        suite = ET.Element('testsuite', name=args.suite, tests=str(result.testsRun), failures=str(len(result.failures)), errors=str(len(result.errors)))
        suite.extend(result.cases)
        ET.ElementTree(suite).write(report / 'junit.xml', encoding='utf-8', xml_declaration=True)
        return 0 if result.wasSuccessful() else 1


if __name__ == '__main__':
    raise SystemExit(main())
