#!/usr/bin/env python3
"""Live cross-subsystem acceptance. No interception or replacement of application APIs.
Only a fresh loopback SQLite installation is permitted; never target an existing site.
"""
import argparse
import json
from pathlib import Path
import shutil
import sqlite3
import subprocess
import unittest
import xml.etree.ElementTree as ET

from run import ADMIN, ADMIN_PASSWORD, PASSWORD, Client, JUnitResult, server, unique_email


class PlatformJourney(unittest.TestCase):
    base = site = env = report = browser_name = None

    def sql(self, query, args=()):
        path = self.site / 'storage/database/forum.sqlite'
        with sqlite3.connect(path.as_uri() + '?mode=ro', uri=True, timeout=10) as db:
            return db.execute(query, args).fetchall()

    def cli(self, *args):
        proc = subprocess.run(['php', 'bin/cajeer', *args], cwd=self.site, env=self.env,
                              capture_output=True, text=True, timeout=120)
        with (self.report / 'worker.log').open('a', encoding='utf-8') as log:
            log.write(' '.join(args) + '\n' + proc.stdout + proc.stderr + '\n')
        self.assertEqual(proc.returncode, 0, 'CLI failed: ' + args[0])
        data = json.loads(proc.stdout)
        self.assertTrue(data.get('ok'), args[0])
        return data

    def test_browser_sql_worker_journey(self):
        from playwright.sync_api import sync_playwright, expect
        # Account creation through normal privileged API, no direct DB writes.
        c = Client(self.base)
        login_data = c.login(ADMIN, ADMIN_PASSWORD, browser=True)[0]['data']
        c.headers['X-CSRF-Token'] = login_data['csrf_token']
        c.call('POST', '/auth/assurance/confirm', 200, {'password': ADMIN_PASSWORD, 'two_factor_code': ''})
        admin_id = c.call('GET', '/auth/me')['user_id']
        member_email, outsider_email = unique_email(), unique_email()
        for email in (member_email, outsider_email):
            c.call('POST', '/users', 201, {'email': email, 'name': 'E2E member',
                   'password': PASSWORD, 'roles': ['forum-member']})
        policy = c.call('GET', '/forum/acp?view=settings')
        values = {k: v for k, v in policy['values'].items() if k not in ('paused', 'registration_open')}
        values['analytics_enabled'] = True
        c.call('PUT', '/forum/acp/policy', 200, {'revision': policy['revision'], 'values': values})
        version = json.loads((self.site / 'release.json').read_text())['edition_version']
        subject = 'Platform E2E support'
        content = 'Literal <img src=x onerror=alert(1)> support request'
        answer = 'Cross-subsystem operator response'
        title = 'Platform E2E searchable topic'
        errors, failures = [], []
        with sync_playwright() as p:
            options = {'headless': True}
            if self.browser_name == 'chromium' and shutil.which('chromium'):
                options.update(executable_path=shutil.which('chromium'), args=['--no-sandbox'])
            browser = getattr(p, self.browser_name).launch(**options)
            contexts = []
            try:
                for name in ('admin', 'member', 'outsider'):
                    ctx = browser.new_context(viewport={'width': 1280, 'height': 900})
                    ctx.tracing.start(screenshots=True, snapshots=True, sources=False)
                    contexts.append((name, ctx))
                def page_for(ctx):
                    page = ctx.new_page()
                    page.set_default_timeout(15000)
                    page.on('pageerror', lambda e: errors.append(str(e)))
                    page.on('response', lambda r: failures.append((r.status, r.url.split('?')[0]))
                            if r.url.startswith(self.base + '/') and r.status >= 500 else None)
                    page.on('dialog', lambda d: d.accept() if d.type == 'confirm' else d.dismiss())
                    return page
                admin, member, outsider = [page_for(ctx) for _, ctx in contexts]
                def api(page, path, expected=200, method='GET', body=None, csrf=True):
                    # Real browser fetch + current cookie; never insert a bearer token into JS.
                    status, data = page.evaluate('''async ({path,method,body,csrf}) => {
                        const headers={'Accept':'application/json'};
                        if(body!==null){headers['Content-Type']='application/json';
                          if(csrf){const me=await fetch('/api/v1/auth/me',{cache:'no-store'});
                            if(!me.ok)throw Error('session missing');headers['X-CSRF-Token']=(await me.json()).data.csrf_token;}}
                        const res=await fetch('/api/v1'+path,{method,headers,credentials:'same-origin',cache:'no-store',
                            ...(body===null?{}:{body:JSON.stringify(body)})});
                        return [res.status,await res.json()];
                    }''', {'path': path, 'method': method, 'body': body, 'csrf': csrf})
                    self.assertEqual(status, expected, f'{method} {path}')
                    self.assertIn('error' if expected >= 400 else 'data', data)
                    return data.get('data', data.get('error'))
                def login(page, email, password):
                    page.goto(self.base + '/forum/login')
                    page.locator('#app [name=email]').fill(email)
                    page.locator('#app [name=password]').fill(password)
                    page.get_by_role('button', name='Войти', exact=True).click()
                    expect(page).to_have_url(self.base + '/forum')
                    self.assertTrue(api(page, '/auth/me')['session'])
                login(admin, ADMIN, ADMIN_PASSWORD)
                login(member, member_email, PASSWORD)
                login(outsider, outsider_email, PASSWORD)
                self.assertTrue(api(admin, '/auth/assurance/confirm', 200, 'POST', {'password': ADMIN_PASSWORD, 'two_factor_code': ''})['fresh'])
                # Live Update Center: same browser cookie, real RBAC, CSRF, assurance,
                # host restrictions and durable policy file. No API interception.
                admin.goto(self.base + '/admin/#/updates')
                center = api(admin, '/updates')['center_policy']
                self.assertTrue(center['editable'])
                self.assertEqual(center['revision'], 0)
                self.assertFalse(center['host']['auto_apply'])
                proposal = dict(center['settings'], mode='notify_only', timezone='Europe/Warsaw',
                                window_start='23:00', window_end='02:00')
                payload = {'revision': 0, 'baseline_hash': center['baseline_hash'],
                           'settings': proposal, 'confirm': True}
                api(member, '/updates/policy', 403, 'POST', payload)
                api(admin, '/updates/policy', 403, 'POST', payload, False)
                api(admin, '/updates/policy', 422, 'POST', dict(payload, settings=dict(proposal, auto_apply=True)))
                admin.locator('[data-update-policy-editor] summary').click()
                editor = admin.locator('[data-update-policy-form]')
                expect(editor.locator('[name=auto_apply]')).to_be_disabled()
                expect(editor.locator('[name=sequential]')).to_be_disabled()
                editor.locator('[name=mode]').select_option('notify_only')
                editor.locator('[name=timezone]').fill('Europe/Warsaw')
                editor.locator('[name=window_start]').fill('23:00')
                editor.locator('[name=window_end]').fill('02:00')
                editor.get_by_role('button', name='Сохранить политику').click()
                expect(admin.get_by_text('Ревизия 1.', exact=False)).to_be_visible()
                stored = json.loads((self.site/'storage/app/update-requests/center-policy.json').read_text())
                self.assertEqual(stored['revision'], 1)
                self.assertEqual(stored['settings'], proposal)
                self.assertEqual(stored['history'][-1]['actor'], admin_id)
                api(admin, '/updates/policy', 409, 'POST', payload)
                admin.reload()
                effective = api(admin, '/updates')['policy']
                self.assertEqual(effective['mode'], 'notify_only')
                self.assertEqual(effective['timezone'], 'Europe/Warsaw')
                self.assertFalse(effective['autonomous_apply_enabled'])
                admin.locator('[data-update-policy-editor] summary').click()
                admin.locator('[data-update-policy-reset]').click()
                expect(admin.get_by_text('Ревизия 2.', exact=False)).to_be_visible()
                self.assertEqual(api(admin, '/updates')['policy']['mode'], 'disabled')
                self.assertIsNone(json.loads((self.site/'storage/app/update-requests/center-policy.json').read_text())['settings'])
                # Same cookie works in both ACP and forum, including its published module assets.
                admin.goto(self.base + '/admin/#/platform-workflows')
                expect(admin.locator('.brand-sub')).to_contain_text('Forum ' + version)
                self.assertEqual(api(admin, '/platform/workflows')['rules'], [])
                form = admin.locator('[data-workflow-form]')
                expect(form).to_be_visible()
                form.locator('[name=name]').fill('E2E assignment')
                form.locator('[name=trigger_name]').select_option('support.requested')
                form.locator('[name=operator_id]').fill(admin_id)
                form.locator('[name=enabled]').check()
                form.locator('[data-workflow-preview]').click()
                expect(form.locator('[data-workflow-result]')).to_contain_text('Предпросмотр:')
                self.assertEqual(self.sql('SELECT COUNT(*) FROM ce_platform_workflows')[0][0], 0)
                form.locator('button[type=submit]').click()
                expect(admin.locator('[data-workflow-edit]')).to_have_count(1)
                rule = api(admin, '/platform/workflows')['rules'][0]['id']
                api(member, '/platform/workflows', 403)
                # Publication through real UI feeds anonymous analytics, and search reads the same SQL.
                member.get_by_role('link', name='Test board', exact=True).click()
                member.locator('#app [name=title]').fill(title)
                member.locator('#app [name=body]').fill('Cross subsystem indexing and analytics test content')
                member.get_by_role('button', name='Создать тему', exact=True).click()
                expect(member.locator('#app').get_by_role('heading', name=title, exact=True)).to_be_visible()
                thread_id = member.url.split('?')[0].rstrip('/').rsplit('/', 1)[-1]
                self.assertTrue(self.sql('SELECT id FROM ce_forum_threads WHERE id=?', (thread_id,)))
                self.assertIn(thread_id, [row['id'] for row in api(member, '/forum/search?q=searchable')])
                self.assertGreaterEqual(self.sql("SELECT COUNT(*) FROM ce_forum_analytics_events WHERE event_name='forum_thread_created'")[0][0], 1)
                member.goto(self.base + '/forum/support')
                member.get_by_label('Тема обращения').fill(subject)
                member.get_by_label('Описание проблемы').fill(content)
                member.get_by_role('button', name='Создать обращение').click()
                expect(member.get_by_text(content, exact=True)).to_be_visible()
                expect(member.locator('.forum-support-message img')).to_have_count(0)
                ticket = api(member, '/forum/support/tickets')[0]['id']
                path = '/forum/support/tickets/' + ticket
                self.assertNotIn('assigned_to', api(member, path)['ticket'])
                api(outsider, path, 404)
                api(outsider, '/forum/acp/support/tickets', 403)
                api(member, path + '/reply', 403, 'POST', {'message': 'CSRF rejection', 'expected_revision': 1}, False)
                self.assertEqual(self.sql('SELECT assigned_to,revision FROM ce_forum_support_tickets WHERE id=?', (ticket,)), [(None, 1)])
                # Real worker picks up transactional support outbox and rollup jobs.
                scheduled = self.cli('scheduler:run')
                self.assertTrue(scheduled['tasks']['forum.jobs.dispatch']['ok'])
                self.assertIn('platform.workflows.run', scheduled['tasks']['forum.jobs.dispatch']['jobs'])
                self.cli('queue:work', '--limit=100')
                self.assertEqual(self.sql('SELECT assigned_to,revision FROM ce_forum_support_tickets WHERE id=?', (ticket,)), [(admin_id, 2)])
                self.assertEqual(self.sql("SELECT COUNT(*) FROM ce_platform_workflow_runs WHERE rule_id=? AND status='done'", (rule,)), [(1,)])
                totals = self.sql('SELECT event_name,SUM(total) FROM ce_forum_analytics_daily GROUP BY event_name')
                self.assertGreaterEqual(dict(totals).get('forum_thread_created', 0), 1)
                self.cli('queue:work', '--limit=100')
                self.assertEqual(self.sql('SELECT revision FROM ce_forum_support_tickets WHERE id=?', (ticket,)), [(2,)])
                self.assertEqual(self.sql("SELECT COUNT(*) FROM ce_platform_workflow_runs WHERE rule_id=? AND status='done'", (rule,)), [(1,)])
                self.assertEqual(totals, self.sql('SELECT event_name,SUM(total) FROM ce_forum_analytics_daily GROUP BY event_name'))
                report = api(admin, '/forum/acp?view=analytics_dashboard')
                self.assertEqual(report['totals']['forum_thread_created'], dict(totals)['forum_thread_created'])
                api(member, '/forum/acp?view=analytics_dashboard', 403)
                admin.goto(self.base + '/admin/#/forum/support')
                admin.get_by_role('button', name=subject + ' · open', exact=True).click()
                expect(admin.get_by_text('open · ревизия 2', exact=True)).to_be_visible()
                # Stale revision is rejected before any write, despite valid session and CSRF.
                api(admin, '/forum/acp/support/tickets/' + ticket + '/reply', 409, 'POST', {'message': 'Stale reply', 'expected_revision': 1})
                admin.locator('#forum-acp textarea[name=message]').fill(answer)
                admin.get_by_role('button', name='Отправить сообщение').click()
                expect(admin.get_by_text('waiting · ревизия 3', exact=True)).to_be_visible()
                admin.get_by_role('button', name='Закрыть обращение').click()
                expect(admin.get_by_text('resolved · ревизия 4', exact=True)).to_be_visible()
                member.reload()
                member.get_by_role('button', name=subject + ' · resolved', exact=True).click()
                expect(member.get_by_text(answer, exact=True)).to_be_visible()
                api(outsider, path, 404)
                self.assertEqual(self.sql('SELECT role,body FROM ce_forum_support_messages WHERE ticket_id=? ORDER BY role', (ticket,)), [('operator', answer), ('user', content)])
                self.assertEqual(self.sql("SELECT COUNT(*) FROM ce_audit_log WHERE event='platform.workflow.executed' AND actor_id=?", (admin_id,)), [(1,)])
                ai = api(member, '/forum/ai/status')
                self.assertFalse(ai['configured'])
                self.assertFalse(ai['enabled'])
                api(member, '/forum/ai/chat', 403, 'POST', {'message': 'Disabled provider must not be invoked'})
                api(member, '/forum/acp/ai', 403)
                # Operational report and integration catalog must be available only to authorized users.
                api(admin, '/admin/dashboard')
                api(admin, '/integrations')
                api(member, '/integrations', 403)
                api(admin, '/analytics/reports')
                api(member, '/analytics/reports', 403)
                api(admin, '/queue')
                ops = admin.request.get(self.base + '/api/v1/system/operations')
                self.assertEqual(ops.status, 200)
                self.assertTrue(ops.json()['checks']['worker']['ok'])
                api(member, '/system/operations', 403)
                api(member, '/queue', 403)
                self.assertFalse(errors, 'Uncaught browser errors: ' + str(errors))
                self.assertFalse(failures, 'Unexpected HTTP 5xx: ' + str(failures))
            finally:
                for name, ctx in contexts:
                    try:
                        ctx.tracing.stop(path=self.report / (name + '-trace.zip'))
                    finally:
                        ctx.close()
                browser.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--browser', choices=('chromium', 'firefox'), required=True)
    parser.add_argument('--report', type=Path, required=True)
    args = parser.parse_args()
    report = args.report.resolve()
    report.mkdir(parents=True, exist_ok=True)
    # Never accept a supplied URL or existing database; install only via fixture.
    import os
    if os.environ.get('FORUM_TEST_DRIVER', 'sqlite') != 'sqlite':
        raise RuntimeError('Platform browser journey requires disposable SQLite')
    with server(report, include_site=True, update_policy_fixture=True) as (base, site, env):
        PlatformJourney.base, PlatformJourney.site, PlatformJourney.env = base, site, env
        PlatformJourney.report, PlatformJourney.browser_name = report, args.browser
        result = unittest.TextTestRunner(verbosity=2, resultclass=JUnitResult).run(
            unittest.defaultTestLoader.loadTestsFromTestCase(PlatformJourney))
    suite = ET.Element('testsuite', name='platform-e2e', tests=str(result.testsRun),
                       failures=str(len(result.failures)), errors=str(len(result.errors)), skipped=str(len(result.skipped)))
    suite.extend(result.cases)
    ET.ElementTree(suite).write(report / 'junit.xml', encoding='utf-8', xml_declaration=True)
    return 0 if result.wasSuccessful() and result.testsRun == 1 and not result.skipped else 1


if __name__ == '__main__':
    raise SystemExit(main())
