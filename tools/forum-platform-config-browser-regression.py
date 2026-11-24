#!/usr/bin/env python3
"""Exercise published ACP with a controlled transport and real browser DOM."""
import argparse, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--browser',choices=['chromium','firefox'],default='chromium');args=p.parse_args()
mock=r'''
const store=new Map();for(const name of ['localStorage','sessionStorage'])Object.defineProperty(window,name,{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});
window.confirm=()=>true;window.scopes=['*'];window.calls=[];window.rev=0;window.overrides={};window.historyRows=[];
const baseline={'integrations.enabled':true,'integrations.max_connections':200,'integrations.max_payload_bytes':65536,'queue.workers.default_limit':10};
const data=()=>({revision:rev,baseline_hash:'a'.repeat(64),baseline,effective:{...baseline,...overrides},overrides,history:historyRows,integrations:{openai:{configured:true},smtp:{configured:false},redis:{configured:true},s3:{configured:false}}});
window.fetch=async(url,opts={})=>{const u=new URL(url,'https://forum.test'),path=u.pathname.replace('/api/v1',''),method=opts.method||'GET',body=opts.body?JSON.parse(opts.body):null;calls.push({path,method,body});const send=(data,status=200)=>new Response(JSON.stringify(status===200?{data}:{error:{message:'Forbidden or stale'}}),{status});
if(path==='/auth/oidc/status')return send({enabled:false});if(path==='/auth/me')return send({user_id:'admin',session:true,scopes,csrf_token:'token'});if(path==='/admin/dashboard')return send({cards:[]});if(path==='/forum/acp')return send({allowed:false});
if(path==='/platform/config'&&method==='GET'&&(scopes.includes('*')||scopes.includes('system:read')))return send(data());
if(!scopes.includes('*')||opts.headers.get('X-CSRF-Token')!=='token')return send(null,403);
if(path==='/platform/config'&&method==='PUT'){if(body.revision!==rev||body.baseline_hash!=='a'.repeat(64))return send(null,409);const before=overrides;overrides=body.overrides??historyRows.find(h=>h.revision===body.rollback_revision).before;historyRows.unshift({revision:++rev,before,after:overrides,action:body.overrides?'update':'rollback',actor_id:'<img src=x>',created_at:'2026-11-24'});return send(data())}
if(path==='/platform/config/probe')return send({ok:true,duration_ms:12,checked_at:'2026-11-24',provider:body.provider});return send(null,403)};
'''
count=0
def check(v,label):
 global count
 assert v,label
 count+=1
with sync_playwright() as p:
 browser=getattr(p,args.browser).launch(headless=True,**({'executable_path':shutil.which('chromium'),'args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}))
 page=browser.new_page(viewport={'width':1280,'height':900});page.set_default_timeout(8000);errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 page.set_content('<div id="app"></div>');page.add_script_tag(content=mock);page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text());page.locator('.sidebar [data-route="platform-config"]').click();page.locator('[data-platform-config]').wait_for()
 check(page.url.endswith('#/platform-config'),'direct route')
 page.locator('[name="queue.workers.default_limit"]').fill('3');page.get_by_role('button',name='Сохранить настройки').click();page.get_by_text('Конфигурация сохранена.',exact=True).wait_for();check(page.evaluate("rev===1&&overrides['queue.workers.default_limit']===3"),'save with CSRF and revision')
 check(page.locator('main img').count()==0,'escaped actor')
 page.locator('[data-platform-probe="openai"]').click();page.get_by_text('Проверка успешна · 12 мс · 2026-11-24',exact=True).wait_for();check(page.evaluate("calls.some(c=>c.path==='/platform/config/probe'&&c.body.provider==='openai')"),'explicit probe')
 page.locator('[data-platform-rollback="1"]').click();page.wait_for_function('rev===2');page.locator('[data-platform-rollback="2"]').wait_for();check(page.evaluate('Object.keys(overrides).length===0'),'rollback')
 page.locator('[data-platform-json]').fill('{"integrations.max_connections":5}');page.locator('[data-platform-import]').click();page.wait_for_function('rev===3');page.locator('[data-platform-rollback="3"]').wait_for();check(page.evaluate("overrides['integrations.max_connections']===5"),'import')
 page.evaluate("scopes=['system:read']");page.get_by_role('button',name='Обновить',exact=True).click();page.locator('[data-platform-config]').wait_for();check(page.locator('[data-platform-config] button').count()==0 and page.locator('[data-platform-probe]').count()==0,'read-only')
 page.evaluate("scopes=['forum:read']");page.get_by_role('button',name='Обновить',exact=True).click();page.get_by_text('Доступ ограничен',exact=True).wait_for();check(page.locator('[data-platform-config]').count()==0,'revoked scope');check(not errors,'no JS errors '+str(errors));browser.close()
print(f'Platform configuration {args.browser}: {count} PASS')
