#!/usr/bin/env python3
"""Chromium DOM regression of real consent.js with a deterministic transport stub.

Never loads an external analytics script or contacts the actual providers. Tests
consent, deduplication, private URLs, page pagination and revocation in the DOM.
"""
import argparse
import json
import os
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'resources/consent/consent.js').read_text()
UUID = '01234567-89ab-4cde-8fab-0123456789ab'
BASE = 'https://forum.test'
PUBLIC = '/forum/threads/' + UUID
POLICY = {'revision': 1, 'ttl_days': 180, 'version': 'a'*64,
          'title_ru': 'Приватность', 'title_en': 'Privacy',
          'message_ru': 'Согласие', 'message_en': 'Your choices',
          'policy_url': '', 'script_origins': [],
          'tracking': {'enabled': True, 'hosts': ['forum.test'],
                       'metrika_id': '12345678', 'ga4_id': 'G-ABCDEFGH',
                       'gtm_id': '', 'ga4_debug': False}}


def check(condition, message):
    if not condition:
        raise AssertionError(message)
    print('PASS', message, flush=True)


def setup(browser, approved=True):
    page = browser.new_page()
    page.set_content('<!doctype html><html lang="en"><head></head><body><script type="text/plain" data-ce-consent-config="{}"></script></body></html>')
    page.evaluate('''({source, policy, approved, path}) => {
      const boot=document.querySelector('[data-ce-consent-config]');
      boot.dataset.ceConsentConfig=JSON.stringify(policy);
      boot.dataset.ceConsentDynamic='1';
      if(approved)boot.dataset.ceForumApprovedPath=path;
      window.test={location:{origin:'https://forum.test',hostname:'forum.test',protocol:'https:',
        pathname:path,search:'?page=2&secret=DO_NOT_LEAK',href:'https://forum.test'+path+'?page=2&secret=DO_NOT_LEAK',reload(){test.reloads++}},
        storage:null,requests:[],reloads:0,fetches:0,policy};
      Object.defineProperty(document,'cookie',{configurable:true,get:()=>'',set:()=>{}});
      const localStorage={getItem:()=>test.storage,setItem:(k,v)=>{test.storage=v},removeItem:()=>{test.storage=null}};
      const fetch=async url=>{test.fetches++;return {ok:true,json:async()=>({data:test.policy})}};
      const append=document.head.append.bind(document.head);
      document.head.append=(...nodes)=>{
        for(const n of nodes){if(n.tagName==='SCRIPT'){
          test.requests.push(n.src);
          // A script can be loaded without contacting the real provider.
          queueMicrotask(()=>n.dispatchEvent(new Event('load')));
        }else append(n);}
      };
      new Function('location','localStorage','fetch',source)(test.location,localStorage,fetch);
    }''', {'source': SOURCE, 'policy': POLICY, 'approved': approved, 'path': PUBLIC})
    page.wait_for_function('window.CajeerConsent && CajeerConsent.getState().ready')
    return page


parser = argparse.ArgumentParser()
parser.add_argument('--browser', choices=('chromium', 'firefox'), default='chromium')
args = parser.parse_args()

with sync_playwright() as p:
    launch = {'headless': True}
    if args.browser == 'chromium':
        binary = os.getenv('CHROMIUM_PATH') or shutil.which('chromium')
        if binary:
            launch['executable_path'] = binary
        launch['args'] = ['--no-sandbox']
    browser = getattr(p, args.browser).launch(**launch)
    page = setup(browser)
    check(page.evaluate('!CajeerConsent.allows("analytics") && test.requests.length===0'), 'no provider load before consent')
    page.get_by_role('button', name='Reject all').click()
    check(page.evaluate('test.requests.length===0'), 'rejecting consent prevents provider requests')
    page.get_by_role('button', name='Privacy preferences').click(timeout=1500)
    page.get_by_role('checkbox', name='Analytics').check(timeout=1500)
    page.get_by_role('button', name='Save choices').click(timeout=1500)
    page.wait_for_function("dataLayer.some(e=>e[0]==='event' && e[1]==='page_view')")
    check(page.evaluate('test.requests.length') == 2, 'GA4 and Metrika each initialize once')
    viewed = page.evaluate("dataLayer.filter(e=>e[0]==='event'&&e[1]==='page_view').map(e=>e[2].page_location)")
    check(viewed == [BASE + PUBLIC + '?page=2'], 'public pagination preserved without arbitrary query strings')
    check('DO_NOT_LEAK' not in json.dumps(viewed), 'private query values never forwarded')
    check(page.evaluate('CajeerTracking.pageView()') is False, 'same-page duplicate view denied')
    page.evaluate("document.dispatchEvent(new Event('visibilitychange'))")
    page.wait_for_timeout(100)
    check(page.evaluate("dataLayer.filter(e=>e[0]==='event'&&e[1]==='page_view').length") == 1,
          'visibility refresh does not double count')
    page.evaluate("test.location.pathname='/forum/conversations';test.location.search='';test.location.href=test.location.origin+'/forum/conversations';")
    check(page.evaluate('CajeerTracking.pageView()') is False, 'private account route excluded after history navigation')
    check(page.evaluate('CajeerAnalytics.emit("forum_post_published",{})') is False, 'events on private forum routes denied')
    page.evaluate("test.location.pathname='" + PUBLIC + "';test.location.search='?page=3';test.location.href=test.location.origin+test.location.pathname+test.location.search;")
    check(page.evaluate('CajeerTracking.pageView()') is True, 'approved public pagination transition tracked')
    check(page.evaluate('CajeerTracking.pageView()') is False, 'public pagination not counted twice')
    check(page.evaluate("dataLayer.filter(e=>e[0]==='event'&&e[1]==='page_view').length") == 2,
          'two distinct views counted exactly once')
    page.evaluate("test.location.search='?page=2';test.location.href=test.location.origin+test.location.pathname+test.location.search;window.dispatchEvent(new PopStateEvent('popstate'))")
    check(page.evaluate("dataLayer.filter(e=>e[0]==='event'&&e[1]==='page_view').length") == 3,
          'back navigation to approved page counted once')
    page.evaluate("window.dispatchEvent(new PopStateEvent('popstate'))")
    check(page.evaluate("dataLayer.filter(e=>e[0]==='event'&&e[1]==='page_view').length") == 3,
          'duplicate popstate on same page ignored')
    page.evaluate("window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}))")
    page.wait_for_function("dataLayer.filter(e=>e[0]==='event'&&e[1]==='page_view').length===4")
    check(True,'BFCache restore rechecks consent and counts a new visit')
    page.evaluate('CajeerConsent.withdraw()')
    check(page.evaluate('!CajeerConsent.allows("analytics")'), 'withdrawal denies subsequent events')
    check(page.evaluate('test.reloads') == 1, 'revocation requests document reload to stop loaded SDKs')
    page.close()
    page = setup(browser, approved=False)
    page.get_by_role('button', name='Accept all').click()
    check(page.evaluate('!CajeerTracking.pageView() && test.requests.length===0'),
          'forum path without server authorization never loads providers')
    page.close()
    page = setup(browser)
    page.get_by_role('button', name='Accept all').click()
    page.evaluate("test.storage=null;window.dispatchEvent(new StorageEvent('storage',{key:'ce.consent.v1'}))")
    check(page.evaluate('!CajeerConsent.allows("analytics") && test.reloads===1'),
          'cross-tab consent withdrawal immediately blocks further tracking')
    page.close()
    page = setup(browser)
    page.get_by_role('button', name='Accept all').click()
    page.evaluate("test.policy={...test.policy,version:'b'.repeat(64)};document.dispatchEvent(new Event('visibilitychange'))")
    page.wait_for_function('test.reloads===1')
    check(page.evaluate('!CajeerConsent.allows("analytics")'),
          'revised remote policy invalidates previous affirmative consent')
    page.close()
    browser.close()
