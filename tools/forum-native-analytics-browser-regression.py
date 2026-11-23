#!/usr/bin/env python3
"""Chromium/Firefox regression of the shipped first-party native collector (no network)."""
import argparse
from pathlib import Path
import shutil
import os
from playwright.sync_api import sync_playwright

SOURCE=(Path(__file__).resolve().parents[1]/'public/forum-assets/native-analytics.js').read_text()
TOKEN='v1.thread.1795400000.'+'a'*32+'.'+'b'*64
PATH='/forum/threads/01234567-89ab-4cde-8fab-0123456789ab'

def check(result,why):
    if not result:raise AssertionError(why)
    print('PASS',why,flush=True)

def setup(browser,*,path=PATH,query='',consent=False,protocol='https:',approved=PATH,response_ok=True):
    page=browser.new_page()
    page.set_content('<!doctype html><html><head></head><body><h1>Forum</h1></body></html>')
    page.evaluate('''({source,receipt,approved,path,query,protocol,consent,responseOk})=>{
      window.test={sent:[],consent,pathname:path,attempts:0};
      window.CajeerConsent={allows:(kind)=>kind==='analytics' && test.consent};
      const script=document.createElement('script');
      script.dataset.ceNativeReceipt=receipt;script.dataset.ceNativePath=approved;
      Object.defineProperty(document,'currentScript',{configurable:true,get:()=>script});
      const location={protocol,pathname:path,search:query};
      const fetch=(url,opts)=>{test.sent.push({url,opts});return Promise.resolve({ok:responseOk,status:responseOk?200:503});};
      new Function('location','fetch','document',source)(location,fetch,document);
    }''',dict(source=SOURCE,receipt=TOKEN,approved=approved,path=path,query=query,protocol=protocol,consent=consent,responseOk=response_ok))
    return page

p=argparse.ArgumentParser()
p.add_argument('--browser',choices=['chromium','firefox'],default='chromium')
args=p.parse_args()
with sync_playwright() as pw:
    opts={'headless':True}
    if args.browser=='chromium':
        exe=os.getenv('CHROMIUM_PATH') or shutil.which('chromium')
        if exe:opts['executable_path']=exe
        opts['args']=['--no-sandbox']
    browser=getattr(pw,args.browser).launch(**opts)
    page=setup(browser)
    check(page.evaluate('test.sent.length===0'),'no native POST before consent')
    page.evaluate("document.dispatchEvent(new CustomEvent('cajeer:consent-change'))")
    check(page.evaluate('test.sent.length===0'),'consent denial never queues a view')
    page.evaluate("test.consent=true;document.dispatchEvent(new CustomEvent('cajeer:consent-change'))")
    page.wait_for_function('test.sent.length===1')
    req=page.evaluate('test.sent[0]')
    check(req['url']=='/api/v1/forum/analytics/page-view' and req['opts']['method']=='POST' and req['opts']['credentials']=='omit' and req['opts']['keepalive'] is True, 'same-origin stateless POST with no cookies')
    check(req['opts']['body']=='{"receipt":"'+TOKEN+'"}', 'only one receipt, no URL or visitor data')
    page.evaluate("document.dispatchEvent(new CustomEvent('cajeer:consent-change'))")
    check(page.evaluate('test.sent.length===1'),'repeated consent changes do not duplicate views')
    page.close()
    page=setup(browser,consent=True,query='?page=2&password=secret')
    check(page.evaluate('test.sent.length===0'),'sensitive query prevents collection')
    page.close()
    page=setup(browser,consent=True,path='/forum/account')
    check(page.evaluate('test.sent.length===0'),'history rewrite to private path cannot collect')
    page.close()
    page=setup(browser,consent=True,protocol='http:')
    check(page.evaluate('test.sent.length===0'),'http page never collects')
    page.close()
    page=setup(browser,consent=True,query='?page=2')
    page.wait_for_function('test.sent.length===1')
    check(page.evaluate('test.sent.length===1'),'allowlisted pagination view collects after consent')
    page.close()
    page=setup(browser,consent=True,response_ok=False)
    page.wait_for_function('test.sent.length===1')
    page.wait_for_function('test.sent.length===2',timeout=4000)
    page.wait_for_timeout(950)
    check(page.evaluate('test.sent.length===2'),'one automatic retry on backend 503, without consent change or third attempt')
    page.close()
    page=setup(browser,consent=True,response_ok=False)
    page.wait_for_function('test.sent.length===1')
    page.evaluate("test.consent=false;document.dispatchEvent(new CustomEvent('cajeer:consent-change'))")
    page.wait_for_timeout(950)
    check(page.evaluate('test.sent.length===1'),'consent withdrawal cancels scheduled retry')
    page.close()
    browser.close()
print('Forum native browser PASS')
