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
import xml.etree.ElementTree as ET
from contextlib import contextmanager

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
PASSWORD = 'MemberPass12345!'
NEW_PASSWORD = 'ChangedPass12345!'
ADMIN = 'admin@example.test'
ADMIN_PASSWORD = 'AdminPass12345!'
XSS = '<img src=x onerror="window.forumXss=1"><script>window.forumXss=1</script>'


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
            match = re.search(re.escape(base + path) + r'#token=([a-f0-9]{64})', text)
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
        if plan['version'] != '0.1.4' or not plan['release_requirements']['ready']:
            raise RuntimeError('Source release prerequisites failed')
        for name in plan['files']:
            target = site / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / name, target)
        for name in ('storage/app', 'storage/database', 'storage/cache', 'storage/logs', 'storage/tmp', 'bootstrap/cache', 'public/uploads', 'public/extensions'):
            (site / name).mkdir(parents=True, exist_ok=True)
        (site / 'vendor').symlink_to(ROOT / 'vendor', target_is_directory=True)
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
                            if res.status == 200 and res.headers.get('X-CajeerEngine-Forum-Version') == '0.1.4':
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

    def test_03_rate_limit(self):
        # Run last: exhaust the real IP auth bucket without guessing credentials.
        c = Client(self.base)
        _, headers = c.request('GET', '/auth/me', 401)
        remaining = int(headers['X-RateLimit-Remaining'])
        self.assertGreaterEqual(remaining, 0)
        self.assertLessEqual(remaining, 80)
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
                page.get_by_role('link', name='Test board', exact=True).click()
                fill('title', 'Browser regression topic'); fill('body', XSS); click('Создать тему')
                heading('Browser regression topic')
                thread = page.url
                expect(page.locator('article .body').first).to_have_text(XSS)
                self.assertEqual(0, page.locator('article .body img, article .body script').count())
                self.assertIsNone(page.evaluate('window.forumXss'))
                fill('body', 'Browser reply'); click('Отправить')
                expect(page.locator('article .body').last).to_have_text('Browser reply')
                page.locator('article').first.get_by_role('button', name='Нравится', exact=True).click()
                expect(page.locator('article').first).to_contain_text('1 отметок')
                click('Подписаться'); expect(page.locator('#notice')).to_contain_text('Тема добавлена')
                page.goto(self.base + '/forum/watches')
                expect(page.get_by_role('link', name='Browser regression topic')).to_be_visible()
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
