#!/usr/bin/env python3
"""Actual forum registration and diagnostics UI with isolated API transport."""
import argparse
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
ROOT=Path(__file__).resolve().parents[2]
SCRIPT=(ROOT/'public/forum-assets/forum.js').read_text().replace('run(init);','',1)
SCRIPT+='''\nwindow.openRegistration=()=>{app.replaceChildren();auth(true)};window.openSpamStats=async()=>{app.replaceChildren();add(app,await spamAdmin())};'''
MOCK='''()=>{window.requests=[];window.fetch=async(url,opts)=>{window.requests.push({url,body:JSON.parse(opts.body||'null')});if(url.endsWith('/auth/oidc/status'))return {ok:true,json:async()=>({data:{enabled:false,auto_provision:false}})};if(url.endsWith('/register')){if(window.requests.filter(r=>r.url.endsWith('/register')).length===1)return {ok:false,status:429,json:async()=>({error:{message:'Слишком много попыток регистрации. Повторите позже.'}})};return {ok:true,json:async()=>({data:{verification_required:true}})}}return {ok:true,json:async()=>({data:{actions:[{action:'thread',events:3,amount:3}],review_reasons:[{reason:'duplicate',events:1}]}})}}}'''
def main():
    ap=argparse.ArgumentParser();ap.add_argument('--browser',default='chromium',choices=['chromium','firefox']);ap.add_argument('--executable');args=ap.parse_args()
    with sync_playwright() as p:
        opts={'executable_path':args.executable} if args.executable else {}
        if args.browser=='chromium':opts['args']=['--no-sandbox']
        browser=getattr(p,args.browser).launch(**opts);page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content('<div id="notice"></div><div id="app"></div>');page.evaluate(MOCK);page.add_script_tag(content='(()=>{'+SCRIPT+'})()');page.evaluate('window.openRegistration()')
        expect(page.locator('[name=website]')).to_be_hidden();page.locator('[name=name]').fill('Member');page.locator('[name=email]').fill('member@example.test');page.locator('[name=password]').fill('ExamplePassword123!')
        page.get_by_role('button',name='Создать аккаунт').click();expect(page.locator('#notice')).to_contain_text('Слишком много попыток регистрации');assert page.evaluate("window.requests.find(r=>r.url.endsWith('/register')).body.website")==''
        page.get_by_role('button',name='Создать аккаунт').click();expect(page.get_by_role('heading',name='Подтвердите email')).to_be_visible()
        page.evaluate('window.openSpamStats()');expect(page.get_by_role('heading',name='Антиспам: последние 24 часа')).to_be_visible();expect(page.get_by_text('На проверку (duplicate): 1')).to_be_visible();assert not errors,errors
        browser.close();print('PASS '+args.browser+' registration honeypot, 429 retry and aggregate spam diagnostics UI')
if __name__=='__main__':main()
