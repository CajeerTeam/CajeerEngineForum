#!/usr/bin/env python3
"""Published unified ACP JavaScript, real DOM and isolated API transport."""
import argparse,shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser();parser.add_argument('--browser',choices=['chromium','firefox'],default='chromium');args=parser.parse_args()
mock=r'''
const store=new Map();for(const name of ['localStorage','sessionStorage'])Object.defineProperty(window,name,{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});
window.confirm=()=>true;window.scopes=['*'];window.calls=[];window.rev=1;window.status='open';window.faq=[];window.messages=[{role:'user',body:'<img src=x onerror=alert(1)>',sources:[]}];
window.fetch=async(url,opts={})=>{const u=new URL(url,'https://forum.test'),path=u.pathname.replace('/api/v1',''),q=u.searchParams,method=opts.method||'GET',data=opts.body?JSON.parse(opts.body):null;window.calls.push({path,method,data});const send=(data,status=200)=>new Response(JSON.stringify(status===200?{data}:{error:{message:'Недостаточно прав.'}}),{status});const admin=scopes.includes('*'),members=admin||scopes.includes('forum:acp:members');
if(path==='/auth/oidc/status')return send({enabled:false});if(path==='/auth/me')return send({user_id:'u',session:true,scopes,csrf_token:'token'});
if(path==='/admin/dashboard'&&admin)return send({cards:[{label:'Core records',value:42}]});
if(path==='/forum/acp'&&q.get('view')==='access')return admin||members?send({allowed:true,capabilities:{admin,members,settings:admin,audit:admin,maintenance:admin}}):send(null,403);
if(path==='/forum/acp'&&q.get('view')==='overview'&&admin)return send({counts:{threads:17,reports:2}});
if(!members)return send(null,403);
if(path==='/forum/acp/support/tickets')return send([{id:'t1',subject:'Test ticket',status:window.status}]);
if(path==='/forum/support/tickets/t1')return send({ticket:{subject:'Test ticket',status:window.status,revision:rev},messages});
if(path==='/forum/acp/support/tickets/t1/reply'){if(data.expected_revision!==rev||opts.headers.get('X-CSRF-Token')!=='token')return send(null,403);rev++;messages.push({role:'operator',body:data.message});return send({})}
if(path==='/forum/acp/support/tickets/t1/action'){if(data.expected_revision!==rev||data.action!=='resolve')return send(null,403);rev++;window.status='resolved';return send({})}
if(path==='/forum/acp/support/faqs'){if(method==='POST')faq.push({id:'f1',revision:1,...data});return send(faq)}
if(path==='/forum/acp/support/faqs/f1'&&method==='DELETE'){if(data.expected_revision!==1)return send(null,403);faq.length=0;return send({})}return send(null,403)};
'''
count=0
def check(v,label):
 global count
 assert v,label
 count+=1
with sync_playwright() as p:
 browser=getattr(p,args.browser).launch(headless=True,**({'executable_path':shutil.which('chromium'),'args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}))
 page=browser.new_page(viewport={'width':1280,'height':900});page.set_default_timeout(8000);errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 page.set_content('<div id="app"></div>');page.add_script_tag(content=mock);page.add_script_tag(content=(root/'public/admin/assets/admin-app.js').read_text())
 page.get_by_text('Core records',exact=True).wait_for();check(page.get_by_text('17',exact=True).count()==1,'forum and core data together')
 page.locator('.sidebar [data-route="forum/support"]').click();page.get_by_text('Очередь обращений форума',exact=True).wait_for();check(page.url.endswith('#/forum/support'),'direct route')
 page.get_by_role('button',name='Test ticket · open').click();page.get_by_text('<img src=x onerror=alert(1)>',exact=True).wait_for();check(page.locator('#forum-acp img').count()==0,'safe text')
 page.locator('#forum-acp textarea[name=message]').fill('Operator answer');page.get_by_role('button',name='Отправить сообщение').click();page.get_by_text('Operator answer',exact=True).wait_for();check(page.evaluate('rev===2'),'reply API with revision and CSRF')
 page.get_by_role('button',name='Закрыть обращение').click();page.get_by_text('resolved · ревизия 3',exact=True).wait_for();check(page.evaluate("window.status==='resolved'"),'resolve API')
 page.get_by_role('button',name='Добавить FAQ').click();page.locator('input[name=question]').fill('FAQ test');page.locator('textarea[name=answer]').fill('A useful answer');page.get_by_role('button',name='Сохранить FAQ').click();page.get_by_text('FAQ test',exact=True).wait_for();check(page.evaluate('faq.length===1&&!faq[0].published'),'draft FAQ persisted')
 page.get_by_role('button',name='Удалить',exact=True).click();page.get_by_text('FAQ test',exact=True).wait_for(state='detached');check(page.evaluate('faq.length===0'),'FAQ deletion')
 page.evaluate("location.hash='#/dashboard'");page.get_by_text('Core records',exact=True).wait_for();page.evaluate('history.back()');page.get_by_text('Очередь обращений форума',exact=True).wait_for();check(page.url.endswith('#/forum/support'),'back navigation')
 page.evaluate("scopes=['forum:acp:members'];location.hash='#/dashboard'");page.get_by_text('Доступные инструменты',exact=True).wait_for();check(page.locator('.sidebar [data-route="system"]').count()==0,'core sections hidden');check(page.locator('.sidebar [data-route="forum/support"]').count()==1,'delegated support')
 page.evaluate("location.hash='#/system'");page.get_by_text('Доступ ограничен',exact=True).wait_for();check(page.evaluate("!calls.some(c=>c.path==='/system/operations')"),'forbidden direct route not loaded')
 page.locator('.sidebar [data-route="forum/support"]').click();page.get_by_text('Очередь обращений форума',exact=True).wait_for();page.evaluate("scopes=['forum:read']");page.get_by_role('button',name='Обновить',exact=True).click();page.get_by_text('Доступ ограничен',exact=True).wait_for();check(page.locator('#forum-acp').count()==0,'revoked access clears content')
 check(not errors,'no JS errors '+str(errors));browser.close()
print(f'Unified Administration {args.browser}: {count} PASS')
