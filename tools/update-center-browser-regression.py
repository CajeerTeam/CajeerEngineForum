#!/usr/bin/env python3
"""Real published ACP DOM; controlled API transport (not live backend acceptance)."""
import argparse, shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser();parser.add_argument('--browser',choices=['chromium','firefox'],default='chromium');args=parser.parse_args()
mock=r'''
const store=new Map();for(const name of ['localStorage','sessionStorage'])Object.defineProperty(window,name,{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});
window.confirm=()=>true;window.prompt=()=> 'ОТКАТ';window.calls=[];window.rev=0;window.editable=true;
window.host={mode:'manual_approval',channel:'stable',timezone:'UTC',days:'1,2,3,4,5,6,7',window_start:'',window_end:'',defer_until:'',blocked_versions:'1.5.10',sequential:true,auto_apply:true};window.settings={...host};
window.fetch=async(url,opts={})=>{const path=new URL(url,'https://forum.test').pathname.replace('/api/v1',''),method=opts.method||'GET',data=opts.body?JSON.parse(opts.body):null;calls.push({path,method,data,csrf:opts.headers.get('X-CSRF-Token')});const send=(data,status=200)=>new Response(JSON.stringify(status===200?{data}:{error:{message:'Conflict'}}),{status});
if(path==='/auth/oidc/status')return send({enabled:false});if(path==='/auth/me')return send({user_id:'u',session:true,scopes:['*'],csrf_token:'token'});if(path==='/admin/dashboard')return send({cards:[]});if(path==='/forum/acp')return send({allowed:true,capabilities:{admin:true},counts:{}});
if(path==='/updates')return send({current_version:'1.5.9',channel:settings.channel,web_upgrade_enabled:true,policy:{mode:settings.mode,valid:true,autonomous_apply_enabled:settings.auto_apply,days:[1,2,3,4,5,6,7]},wizard:{operator_ready:true,production_apply_enabled:true,installed:true,operator_status:{status:'idle'},rollback:{available:true,transaction:'20261124-053408-aaaaaaaaaaaaaaaa',from_version:'1.5.9',to_version:'1.5.8'}},center_policy:{editable,revision:rev,baseline_hash:'a'.repeat(64),settings,host,history:[]},requests:[]});
if(path==='/updates/policy'){if(data.revision!==rev||data.confirm!==true||opts.headers.get('X-CSRF-Token')!=='token')return send(null,409);settings=data.settings===null?{...host}:data.settings;rev++;return send({revision:rev});}
if(path==='/updates/rollback')return send({});if(path==='/updates/history')return send([]);if(path==='/updates/notifications')return send({items:[],unread_count:0});return send({});};
'''
with sync_playwright() as p:
 browser=getattr(p,args.browser).launch(headless=True,**({'executable_path':shutil.which('chromium'),'args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}))
 page=browser.new_page(viewport={'width':1280,'height':900});page.set_default_timeout(10000);errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 page.set_content('<div id="app"></div>');page.add_script_tag(content=mock);page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
 page.locator('.sidebar [data-route="updates"]').click();page.locator('[data-update-policy-editor] summary').click()
 form=page.locator('[data-update-policy-form]');assert form.locator('[name=sequential]').is_disabled();assert form.locator('[name=channel] option').count()==1
 form.locator('[name=mode]').select_option('automatic_patch');form.locator('[name=timezone]').fill('Europe/Warsaw');form.locator('[name=window_start]').fill('23:00');form.locator('[name=window_end]').fill('02:00')
 page.wait_for_timeout(5200);assert form.locator('[name=timezone]').input_value()=='Europe/Warsaw','poll must preserve editor'
 form.get_by_role('button',name='Сохранить политику').click();page.wait_for_function('rev===1');page.get_by_text('Ревизия 1.',exact=False).wait_for();assert page.evaluate("settings.mode==='automatic_patch'&&settings.window_end==='02:00'&&settings.sequential&&calls.find(c=>c.path==='/updates/policy').csrf==='token'")
 page.locator('[data-update-policy-editor] summary').click();page.locator('[data-update-policy-reset]').click();page.wait_for_function('rev===2');page.get_by_text('Ревизия 2.',exact=False).wait_for();assert page.evaluate('settings.mode===host.mode')
 page.locator('[data-update-rollback]').click();page.wait_for_function("calls.some(c=>c.path==='/updates/rollback'&&c.data.transaction==='20261124-053408-aaaaaaaaaaaaaaaa')")
 page.evaluate('editable=false');page.wait_for_timeout(5600);assert page.locator('[data-update-policy-form]').count()==0;assert not errors,errors
 browser.close()
print('Unified Update Center browser: PASS (host controls, preserved edits, save, reset, rollback, read-only)')
