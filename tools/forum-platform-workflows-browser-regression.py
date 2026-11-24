#!/usr/bin/env python3
"""Actual shipped ACP DOM; controlled API transport, no live database/provider."""
import argparse, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--browser',choices=['chromium','firefox'],default='chromium');args=p.parse_args()
mock=r'''
const store=new Map();for(const name of ['localStorage','sessionStorage'])Object.defineProperty(window,name,{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});
window.scopes=['*'];window.calls=[];window.rules=[];window.confirmed=true;window.confirm=()=>confirmed;
const runs=[{rule_id:'00000000-0000-4000-8000-000000000001',rule_revision:1,action_name:'support.assign',status:'done',affected:1,created_at:1700000000}];
const data=()=>({rules,runs});
window.fetch=async(url,opts={})=>{const u=new URL(url,'https://forum.test'),path=u.pathname.replace('/api/v1',''),method=opts.method||'GET',body=opts.body?JSON.parse(opts.body):null;calls.push({path,method,body});const send=(data,status=200)=>new Response(JSON.stringify(status===200?{data}:{error:{message:'Forbidden or stale'}}),{status});
if(path==='/auth/oidc/status')return send({enabled:false});if(path==='/auth/me')return send({user_id:'admin',session:true,scopes,csrf_token:'token'});if(path==='/admin/dashboard')return send({cards:[]});if(path==='/forum/acp')return send({allowed:false});
if(!scopes.includes('*'))return send(null,403);
if(path==='/platform/workflows'&&method==='GET')return send(data());
if(opts.headers.get('X-CSRF-Token')!=='token')return send(null,403);
if(path==='/platform/workflows/preview')return send({eligible:2,limit:50});
if(path==='/platform/workflows'&&method==='PUT'){const old=rules.find(x=>x.id===body.id);if(old&&old.revision!==body.revision)return send(null,409);const item={...body,id:body.id||'00000000-0000-4000-8000-000000000001',revision:body.revision+1,enabled:Number(body.enabled),next_due:1700000000};rules=[item,...rules.filter(x=>x.id!==item.id)];return send(data())}
if(path==='/platform/workflows/remove'){rules=rules.filter(x=>x.id!==body.id);return send(data())}return send(null,403)};
'''
count=0
def check(ok,label):
    global count
    assert ok,label
    count+=1
with sync_playwright() as p:
    browser=getattr(p,args.browser).launch(headless=True,**({'executable_path':shutil.which('chromium'),'args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}))
    page=browser.new_page(viewport={'width':1280,'height':900});page.set_default_timeout(8000);errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<div id="app"></div>');page.add_script_tag(content=mock);page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text());page.locator('.sidebar [data-route="platform-workflows"]').click();page.locator('[data-workflow-form]').wait_for()
    check(page.url.endswith('#/platform-workflows'),'direct route')
    page.locator('[data-workflow-form] [name="name"]').fill('<img src=x>')
    page.locator('[name="trigger_name"]').select_option('support.requested');check(page.locator('[name="interval_seconds"]').is_disabled(),'event schedule excluded')
    page.locator('[name="operator_id"]').fill('11111111-1111-4111-8111-111111111111');page.locator('[name="enabled"]').check()
    page.locator('[data-workflow-preview]').click();page.get_by_text('Предпросмотр: 2',exact=False).wait_for();check(page.evaluate('rules.length===0'),'preview does not save')
    page.evaluate('confirmed=false');page.locator('[data-workflow-form] button[type="submit"]').click();check(page.evaluate('rules.length===0'),'confirmation required')
    page.evaluate('confirmed=true');page.locator('[data-workflow-form] button[type="submit"]').click();page.locator('[data-workflow-edit]').wait_for();check(page.evaluate("rules.length===1&&rules[0].trigger_name==='support.requested'&&rules[0].interval_seconds===0"),'save real rule payload')
    check(page.locator('main img').count()==0,'escaped rule name')
    page.locator('[data-workflow-edit]').click();page.locator('[name="enabled"]').uncheck();page.locator('[data-workflow-form] button[type="submit"]').click();page.wait_for_function('rules[0].revision===2');page.get_by_text('Пауза',exact=True).wait_for();check(page.evaluate('rules[0].enabled===0'),'disable with revision')
    page.locator('[data-workflow-delete]').click();page.wait_for_function('rules.length===0');page.locator('[data-workflow-edit]').wait_for(state='detached');check(page.get_by_text('done',exact=True).count()==1,'remove preserves history')
    page.evaluate("scopes=['system:read']");page.evaluate("location.hash='#/dashboard'");page.locator('.sidebar [data-route="platform-workflows"]').wait_for(state='detached');check(page.locator('[data-workflow-form]').count()==0,'revoked permission hides rule editor')
    check(not errors,'no browser errors '+str(errors));browser.close()
print(f'Platform workflows {args.browser}: {count} PASS')
