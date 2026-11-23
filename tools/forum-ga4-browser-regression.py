#!/usr/bin/env python3
"""Real-browser GA4 Forum integration contract using local SDK/network substitutes.

Never connects to Google or forwards a live measurement. Exercises production JS.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'resources/consent/consent.js').read_text()
UUID = '01234567-89ab-4cde-8fab-0123456789ab'
PAGE = '/forum/threads/' + UUID
GA = {name: name for name in ['forum_index_view','forum_board_view','forum_thread_view',
                             'forum_paginated_view','forum_attachment_click','forum_outbound_click']}
POLICY = {'revision': 1, 'ttl_days': 180, 'version': 'c'*64,
          'title_ru': 'Privacy', 'title_en': 'Privacy', 'message_ru': 'Consent',
          'message_en': 'Consent', 'policy_url': '', 'script_origins': [],
          'tracking': {'enabled': True, 'hosts': ['forum.test'], 'metrika_id': '',
                       'ga4_id': 'G-ABCDEFGH', 'gtm_id': '', 'ga4_debug': False}}

def check(ok, msg):
    if not ok:
        raise AssertionError(msg)
    print('PASS', msg, flush=True)


def setup(browser, path=PAGE, events=None, policy=None, approved=True):
    if events is None:
        events = GA
    if policy is None:
        policy = POLICY
    page=browser.new_page()
    page.set_content('<!doctype html><html lang="en"><head></head><body><script type="text/plain" data-ce-consent-config="{}"></script></body></html>')
    page.evaluate('''({source, policy, approved, path, events})=>{
      const boot=document.querySelector('[data-ce-consent-config]');
      boot.dataset.ceConsentConfig=JSON.stringify(policy);boot.dataset.ceConsentDynamic='1';
      if(approved){boot.dataset.ceForumApprovedPath=path;
        boot.dataset.ceForumGa4Events=JSON.stringify(events);
        boot.dataset.ceForumMetrikaGoals=JSON.stringify(Object.fromEntries(Object.keys(events).map(k=>[k,''])));}
      window.test={location:{origin:'https://forum.test',hostname:'forum.test',protocol:'https:',
        pathname:path,search:'?page=2&token=NEVER_EXPORT',href:'https://forum.test'+path+'?page=2&token=NEVER_EXPORT',reload(){test.reloads++}},
        storage:null,requests:[],reloads:0,fetches:0,policy};
      Object.defineProperty(document,'cookie',{configurable:true,get:()=>'',set:()=>{}});
      const localStorage={getItem:()=>test.storage,setItem:(k,v)=>{test.storage=v},removeItem:()=>{test.storage=null}};
      const fetch=async url=>{test.fetches++;return {ok:true,json:async()=>({data:test.policy})}};
      const append=document.head.append.bind(document.head);
      document.head.append=(...nodes)=>{
        for(const n of nodes){if(n.tagName==='SCRIPT'){
          test.requests.push(n.src);queueMicrotask(()=>n.dispatchEvent(new Event('load')));
        }else append(n);}
      };
      new Function('location','localStorage','fetch',source)(test.location,localStorage,fetch);
    }''', {'source':SOURCE,'policy':policy,'approved':approved,'path':path,'events':events})
    page.wait_for_function('window.CajeerConsent && CajeerConsent.getState().ready')
    return page


def ga_events(page):
    return page.evaluate('''()=>(window.dataLayer||[]).filter(e=>e[0]==='event')
        .map(e=>({name:e[1],params:e[2]}))''')

p=argparse.ArgumentParser()
p.add_argument('--browser',choices=('chromium','firefox'),default='chromium')
args=p.parse_args()
with sync_playwright() as pw:
    launch={'headless': True}
    if args.browser=='chromium':
        exe=os.getenv('CHROMIUM_PATH') or shutil.which('chromium')
        if exe:launch['executable_path']=exe
        launch['args']=['--no-sandbox']
    browser=getattr(pw,args.browser).launch(**launch)
    page=setup(browser)
    check(page.evaluate('test.requests.length===0 && !CajeerConsent.allows("analytics")'), 'no Google traffic before affirmative consent')
    page.get_by_role('button',name='Reject all').click()
    check(page.evaluate('test.requests.length===0'), 'rejected consent never starts Google tag')
    page.get_by_role('button',name='Privacy preferences').click()
    page.get_by_role('checkbox',name='Analytics').check()
    page.get_by_role('button',name='Save choices').click()
    page.wait_for_function('dataLayer.some(e=>e[0]==="event" && e[1]==="forum_thread_view")')
    events=ga_events(page)
    check([e['name'] for e in events]==['page_view','forum_thread_view','forum_paginated_view'], 'one GA4 pageview and exactly two forum-specific events')
    check(page.evaluate('test.requests.length===1 && test.requests[0].includes("gtag/js?id=G-")'), 'single inherited Google tag; no duplicate loader')
    check(events[0]['params']['page_location']=='https://forum.test'+PAGE+'?page=2' and
          all(e['params']=={'send_to':'G-ABCDEFGH'} for e in events[1:]), 'custom events contain only measurement target, never UUID, query or content')
    check('NEVER_EXPORT' not in json.dumps(events), 'sensitive query values stripped from GA4 payload')
    check(page.evaluate('CajeerTracking.pageView()') is False and len(ga_events(page))==3, 'duplicate page view not forwarded')
    page.evaluate('''() => {for(const [href, label] of [
        ['https://forum.test/api/v1/forum/attachments/01234567-89ab-4cde-8fab-0123456789ab/download','Attachment'],
        ['https://external.example','Outbound']]){
      const a=document.createElement('a');a.href=href;a.target='_blank';a.textContent=label;document.body.append(a);
    }}''')
    with page.expect_popup() as pop:page.get_by_role('link',name='Attachment').click()
    pop.value.close()
    with page.expect_popup() as pop:page.get_by_role('link',name='Outbound').click()
    pop.value.close()
    events=ga_events(page)
    check(events[-3:][0]['name']=='forum_attachment_click' and
          [x['name'] for x in events].count('forum_attachment_click')==1 and
          [x['name'] for x in events].count('forum_outbound_click')==1,
          'real trusted attachment and outbound interactions trigger one GA4 forum event each')
    check(all(x['params']=={'send_to':'G-ABCDEFGH'} for x in events if x['name'].startswith('forum_')),
          'forum GA4 event arguments do not include attachment names, outbound URLs or user data')
    page.evaluate("test.location.pathname='/forum/account';test.location.href='https://forum.test/forum/account'")
    before=len(ga_events(page))
    check(page.evaluate('CajeerTracking.pageView()') is False and len(ga_events(page))==before, 'private forum route remains forbidden after history rewrite')
    page.evaluate('CajeerConsent.withdraw()')
    check(page.evaluate('!CajeerConsent.allows("analytics") && test.reloads===1'), 'consent withdrawal prevents further event delivery')
    page.close()

    for name,opts in [
        ('unapproved',{'approved':False}),
        ('all-events-disabled',{'events':{k:'' for k in GA}}),
        ('one-event-disabled',{'events':{**GA,'forum_thread_view':''}}),
        ('bad-event-name',{'events':{**GA,'forum_thread_view':'ga_reserved'}}),
        ('duplicate-event-name',{'events':{**GA,'forum_board_view':'forum_thread_view'}}),
        ('index',{'path':'/forum'}),
        ('board',{'path':'/forum/boards/'+UUID}),
        ('no-ga4-counter',{'policy':{**POLICY,'tracking':{**POLICY['tracking'],'ga4_id':'','metrika_id':'12345678'}}})]:
        page=setup(browser,**opts)
        page.get_by_role('button',name='Accept all').click()
        e=ga_events(page)
        names=[v['name'] for v in e]
        if name in ('unapproved','no-ga4-counter'):
            check(names==[], name+' never sends Google event')
        elif name=='all-events-disabled':
            check(names==['page_view'], 'disabled forum events preserve standard GA4 pageview')
        elif name=='one-event-disabled':
            check(names==['page_view','forum_paginated_view'], 'individual GA4 event can be disabled')
        elif name in ('bad-event-name','duplicate-event-name'):
            check(names==['page_view'], name+' fails closed without leaking custom events')
        elif name=='index':
            check(names==['page_view','forum_index_view'], 'public index GA4 event classified correctly')
        elif name=='board':
            check(names==['page_view','forum_board_view','forum_paginated_view'], 'public board and pagination classified correctly')
        page.close()
    browser.close()
