#!/usr/bin/env python3
"""Browser integration of actual Forum Matomo/Plausible/GTM/Cloudflare tracking code.

Mocks third-party transports and SDK loading; exercises real consent runtime, never
transmits visitor data to production analytics endpoints.
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
THREAD = '/forum/threads/' + UUID
KEYS = ('forum_index_view','forum_board_view','forum_thread_view','forum_paginated_view','forum_attachment_click','forum_outbound_click')
DEST = {name: name for name in KEYS}

def check(ok, why):
    if not ok: raise AssertionError(why)
    print('PASS', why, flush=True)

def scenario(browser, provider, *, path=THREAD, overrides=None, destinations=None, approved=True, search='?page=2&secret=DO_NOT_EXPORT', rules=None):
    tracking={'enabled':True,'hosts':['forum.test'],'metrika_id':'','ga4_id':'','gtm_id':'','ga4_debug':False,
              'matomo_id':'','matomo_url':'','plausible_id':'','plausible_url':'','cloudflare_token':''}
    tracking.update({
        'matomo':{'matomo_id':'8','matomo_url':'https://matomo.example'},
        'plausible':{'plausible_id':'forum.test','plausible_url':'https://plausible.io'},
        'gtm':{'gtm_id':'GTM-ABCDEFG'},
        'cloudflare':{'cloudflare_token':'a'*32},
    }[provider])
    if overrides: tracking.update(overrides)
    settings={'matomo':DEST,'plausible':DEST,'gtm':DEST}
    if destinations is not None:settings[provider]=destinations
    policy={'revision':1,'ttl_days':180,'version':'d'*64,'title_ru':'Privacy','title_en':'Privacy',
            'message_ru':'Consent','message_en':'Consent','policy_url':'','script_origins':['https://matomo.example'],
            'tracking':tracking}
    if rules is not None:policy['events']=rules
    page=browser.new_page()
    page.set_content('<!doctype html><html lang="en"><head></head><body><script type="text/plain" data-ce-consent-config="{}"></script></body></html>')
    page.evaluate('''({source,policy,approved,path,settings,search})=>{
      const boot=document.querySelector('[data-ce-consent-config]');
      boot.dataset.ceConsentConfig=JSON.stringify(policy);boot.dataset.ceConsentDynamic='1';
      if(approved){boot.dataset.ceForumApprovedPath=path;
        boot.dataset.ceForumAlternativeEvents=JSON.stringify(settings);
        boot.dataset.ceForumGa4Events=JSON.stringify(Object.fromEntries(Object.keys(settings.matomo).map(k=>[k,''])));
        boot.dataset.ceForumMetrikaGoals=JSON.stringify(Object.fromEntries(Object.keys(settings.matomo).map(k=>[k,''])));}
      window.test={location:{origin:'https://forum.test',hostname:'forum.test',protocol:'https:',
        pathname:path,search,href:'https://forum.test'+path+search,reload(){test.reloads++}},
        storage:null,requests:[],calls:[],reloads:0,policy};
      Object.defineProperty(document,'cookie',{configurable:true,get:()=>'',set:()=>{}});
      const storage={getItem:()=>test.storage,setItem:(k,v)=>{test.storage=v},removeItem:()=>{test.storage=null}};
      const fetch=(url,options)=>{test.calls.push({url:String(url),options:options||{}});return Promise.resolve({ok:true,json:async()=>({data:test.policy})});};
      const originalAppend=document.head.append.bind(document.head);
      document.head.append=(...nodes)=>{for(const n of nodes){if(n.tagName==='SCRIPT'){
        test.requests.push(n.src);queueMicrotask(()=>n.dispatchEvent(new Event('load')));
      }else originalAppend(n);}};
      new Function('location','localStorage','fetch',source)(test.location,storage,fetch);
    }''',{'source':SOURCE,'policy':policy,'approved':approved,'path':path,'settings':settings,'search':search})
    page.wait_for_function('window.CajeerConsent && CajeerConsent.getState().ready')
    return page

def matomo(page):
    return page.evaluate("window._paq||[]")

def plausible(page):
    return page.evaluate('''()=>test.calls.filter(c=>c.url.endsWith('/api/event')).map(c=>JSON.parse(c.options.body))''')

def gtm(page):
    return page.evaluate('''()=>(window.dataLayer||[]).filter(x=>x&&typeof x.event==='string')''')

def real_links(page):
    page.evaluate('''()=>{for(const [href,name] of [
       ['https://forum.test/api/v1/forum/attachments/01234567-89ab-4cde-8fab-0123456789ab/download','attachment'],
       ['https://example.net/destination?private=NEVER','outbound']]){
       const a=document.createElement('a');a.href=href;a.textContent=name;a.target='_blank';document.body.append(a);
    }}''')
    for name in ('attachment','outbound'):
        with page.expect_popup() as popup:page.get_by_role('link',name=name).click()
        popup.value.close()

argp=argparse.ArgumentParser()
argp.add_argument('--browser',choices=('chromium','firefox'),default='chromium')
arg=argp.parse_args()
with sync_playwright() as pw:
    opts={'headless':True}
    if arg.browser=='chromium':
        exe=os.getenv('CHROMIUM_PATH') or shutil.which('chromium')
        if exe:opts['executable_path']=exe
        opts['args']=['--no-sandbox']
    browser=getattr(pw,arg.browser).launch(**opts)

    page=scenario(browser,'matomo')
    check(page.evaluate('test.requests.length===0 && !CajeerConsent.allows("analytics")'),'Matomo is not loaded before opt-in')
    page.get_by_role('button',name='Reject all').click()
    check(page.evaluate('test.requests.length===0'),'refusal does not load Matomo')
    page.get_by_role('button',name='Privacy preferences').click()
    page.get_by_role('checkbox',name='Analytics').check()
    page.get_by_role('button',name='Save choices').click()
    commands=matomo(page)
    check(page.evaluate("test.requests.length===1 && test.requests[0]==='https://matomo.example/matomo.js'"),'one existing Matomo SDK is used')
    check([x[0] for x in commands].count('trackPageView')==1 and
          [x[2] for x in commands if x[0]=='trackEvent']==['forum_thread_view','forum_paginated_view'],
          'Matomo tracks one pageview and correct category/action forum events')
    check('DO_NOT_EXPORT' not in json.dumps(commands) and any(x[0]=='setCustomUrl' and x[1].endswith('?page=2') for x in commands),
          'Matomo sends sanitized page URL without sensitive query')
    check(page.evaluate('CajeerTracking.pageView()') is False,'Matomo avoids duplicate pageviews')
    real_links(page)
    check(all([x[2] for x in matomo(page) if x[0]=='trackEvent'].count(name)==1 for name in ('forum_attachment_click','forum_outbound_click')),
          'real attachment/outbound clicks generate Matomo events')
    before=len(matomo(page))
    page.evaluate("test.location.pathname='/forum/account';test.location.href='https://forum.test/forum/account'")
    check(page.evaluate('CajeerTracking.pageView()') is False and len(matomo(page))==before,
          'Matomo cannot track private routes after history rewrite')
    page.evaluate('CajeerConsent.withdraw()')
    check(page.evaluate('test.reloads===1 && !CajeerConsent.allows("analytics")'),'Matomo revocation stops tracker and demands safe reload')
    page.close()

    page=scenario(browser,'plausible')
    page.get_by_role('button',name='Accept all').click()
    events=plausible(page)
    check([e['name'] for e in events]==['pageview','forum_thread_view','forum_paginated_view'],
          'Plausible Events API receives one pageview and named Forum events')
    check(all(e['domain']=='forum.test' and e['url']=='https://forum.test'+THREAD+'?page=2' and not e.get('props') for e in events),
          'Plausible payload has sanitized public URL and no personal props')
    check(page.evaluate('test.requests.length===0'),'Plausible uses its existing core Events API, no second SDK')
    real_links(page)
    check(all([e['name'] for e in plausible(page)].count(name)==1 for name in ('forum_attachment_click','forum_outbound_click')),
          'Plausible records trusted clicks without raw link destinations')
    check('NEVER' not in json.dumps(plausible(page)) and 'DO_NOT_EXPORT' not in json.dumps(plausible(page)),
          'Plausible never forwards attachment or outbound URLs, or private query values')
    page.close()

    page=scenario(browser,'gtm',search='?page=2')
    page.get_by_role('button',name='Privacy preferences').click()
    page.get_by_role('checkbox',name='Analytics').check()
    page.get_by_role('button',name='Save choices').click()
    check(page.evaluate("!test.requests.some(x=>x.includes('gtm.js'))"),'GTM requires marketing and functional consent in addition to analytics')
    page.get_by_role('button',name='Privacy preferences').click()
    page.get_by_role('checkbox',name='Marketing').check()
    page.get_by_role('checkbox',name='Functional').check()
    page.get_by_role('button',name='Save choices').click()
    check(page.evaluate("test.requests.length===1 && test.requests[0].includes('gtm.js?id=GTM-ABCDEFG')"),
          'GTM container boots once, with consent granted')
    events=gtm(page)
    check([e['event'] for e in events]==['gtm.js','ce_page_view','ce_forum_thread_view','ce_forum_paginated_view'],
          'GTM dataLayer contains one pageview and custom Forum events')
    check('DO_NOT_EXPORT' not in json.dumps(events),'GTM events do not leak secret query parameters')
    real_links(page)
    check(all([e['event'] for e in gtm(page)].count('ce_'+name)==1 for name in ('forum_attachment_click','forum_outbound_click')),
          'GTM receives trusted click events only')
    page.close()
    page=scenario(browser,'gtm')
    page.get_by_role('button',name='Accept all').click()
    check(page.evaluate('test.requests.length===0'),'GTM cannot read original URL with sensitive query parameters')
    page.close()

    page=scenario(browser,'cloudflare',search='?page=2')
    page.get_by_role('button',name='Accept all').click()
    check(page.evaluate("test.requests.length===1 && test.requests[0].startsWith('https://static.cloudflareinsights.com/beacon.min.js?token=')"),
          'Cloudflare Web Analytics loads exactly one official beacon after consent')
    check(page.evaluate('!window._paq && !window.plausible && !(window.dataLayer||[]).some(x=>x.event&&x.event.startsWith("ce_forum_"))'),
          'Cloudflare beacon only: no unsupported synthetic custom events')
    page.close()
    page=scenario(browser,'cloudflare')
    page.get_by_role('button',name='Accept all').click()
    check(page.evaluate("test.requests.length===0"),'Cloudflare beacon excluded when current URL contains sensitive query fields')
    page.close()
    for provider in ('matomo','plausible','gtm'):
        args={'search':'?page=2'} if provider=='gtm' else {}
        page=scenario(browser,provider,approved=False,**args)
        page.get_by_role('button',name='Accept all').click()
        check(page.evaluate('test.requests.length===0 && !CajeerTracking.pageView()'),provider+' cannot run on unapproved Forum SSR')
        page.close()
        page=scenario(browser,provider,**args,destinations={**DEST,'forum_thread_view':''})
        page.get_by_role('button',name='Accept all').click()
        if provider=='matomo':names=[x[2] for x in matomo(page) if x[0]=='trackEvent']
        elif provider=='plausible':names=[e['name'] for e in plausible(page)]
        else:names=[e['event'] for e in gtm(page)]
        check('forum_thread_view' not in names and 'ce_forum_thread_view' not in names,
              provider+' individual event can be turned off in ACP')
        page.close()
        page=scenario(browser,provider,**args,destinations={**DEST,'forum_thread_view':'topic_opened'})
        page.get_by_role('button',name='Accept all').click()
        if provider=='matomo':names=[x[2] for x in matomo(page) if x[0]=='trackEvent']
        elif provider=='plausible':names=[e['name'] for e in plausible(page)]
        else:names=[e['event'] for e in gtm(page)]
        check(('ce_topic_opened' if provider=='gtm' else 'topic_opened') in names,
              provider+' custom event name is dispatched to the live provider')
        page.close()
        page=scenario(browser,provider,**args,destinations={**DEST,'forum_thread_view':'forum_board_view'})
        page.get_by_role('button',name='Accept all').click()
        if provider=='matomo':names=[x[2] for x in matomo(page) if x[0]=='trackEvent']
        elif provider=='plausible':names=[e['name'] for e in plausible(page)]
        else:names=[e['event'] for e in gtm(page)]
        check(not any(name.endswith('forum_thread_view') for name in names),
              provider+' duplicate destinations fail closed')
        page.close()
        page=scenario(browser,provider,**args,destinations={**DEST,'forum_thread_view':'google_invalid'})
        page.get_by_role('button',name='Accept all').click()
        if provider=='matomo':names=[x[2] for x in matomo(page) if x[0]=='trackEvent']
        elif provider=='plausible':names=[e['name'] for e in plausible(page)]
        else:names=[e['event'] for e in gtm(page)]
        check(not any('forum_' in x for x in names),provider+' invalid provider map is rejected without custom events')
        page.close()
    browser.close()
