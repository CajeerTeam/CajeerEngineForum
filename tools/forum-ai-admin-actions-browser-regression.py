#!/usr/bin/env python3
"""Real ACP DOM flows: no AI until consent, exact preview, approval and role revocation."""
import argparse,shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
source=(root/'admin/static/assets/admin-app.js').read_text()
start=source.index('const forumAcp = (() => {');end=source.index('\n})()',start)+len('\n})()')
view=source[start:end]+'\nwindow.__acp=forumAcp;'
mock=r'''
window.dataOf=x=>x;window.calls=[];window.permitted=true;
window.confirm=()=>true;
window.proposals=[];window.nextID=0;
window.api=async(path,options={})=>{
  const u=new URL('https://localhost.invalid'+path),query=u.searchParams;
  const method=options.method||'GET',input=options.body?JSON.parse(options.body):null;
  window.calls.push({path,method,input});
  if(query.get('view')==='access')return {capabilities:{admin:false,settings:window.permitted,members:window.permitted,maintenance:false}};
  if(query.get('view')==='overview')return {counts:{users:2}};
  if(path==='/forum/acp/support/faqs')return [{id:'11111111-2222-4333-8444-555555555555',question:'FAQ вопрос',published:1}];
  if(u.pathname==='/forum/acp/ai/actions'){
    if(method==='GET')return window.proposals;
    if(method==='POST'){
      if(!input.acknowledge_external_processing)throw Error('consent missing');
      const item={id:'dddddddd-aaaa-4aaa-8aaa-'+String(++window.nextID).padStart(12,'0'),action:input.action,
        before:{name:'Прежнее имя'},after:{name:'Изменено <img src=x onerror=alert(1)>'},
        reason:'Тест <script>alert(1)</script>',state:'pending',digest:'a'.repeat(64),model:'gpt-4.1-mini',created_at:1764000000,expires_at:Date.now()/1000+500};
      window.proposals.unshift(item);return item;
    }
  }
  if(u.pathname.startsWith('/forum/acp/ai/actions/')){
    const id=u.pathname.split('/').at(-1)==='decision'?u.pathname.split('/').at(-2):u.pathname.split('/').at(-1);
    const item=window.proposals.find(p=>p.id===id);
    if(!item)throw Error('Unknown proposal');
    if(method==='GET')return item;
    if(method==='POST'){
      if(input.digest!==item.digest||item.state!=='pending')throw Error('Not allowed');
      item.state=input.decision==='approve'?'applied':'rejected';item.result={revision:42};return item;
    }
  }
  throw Error('Unexpected '+path);
};
'''
parser=argparse.ArgumentParser();parser.add_argument('--browser',choices=['chromium','firefox'],default='chromium');args=parser.parse_args()
n=0
def check(condition,name):
 global n
 assert condition,name
 n+=1
with sync_playwright() as p:
    browser=getattr(p,args.browser).launch(headless=True,**({'executable_path':'/usr/bin/chromium','args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}))
    page=browser.new_page(viewport={'width':1280,'height':860})
    errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
    page.set_content('<!doctype html><html lang="ru"><meta charset="utf-8"><body><div id="forum-acp"></div></body>')
    page.add_script_tag(content=mock+'\n'+view)
    page.evaluate('window.__acp.mount()')
    page.get_by_role('button',name='AI-действия').click()
    page.get_by_text('Управляемые AI-действия форума').wait_for()
    check(page.evaluate('window.calls.filter(c=>c.method==="POST").length')==0,'no provider on opening panel')
    page.locator('textarea[name=instructions]').fill('Сформулировать новое название')
    page.get_by_role('button',name='Сформировать предложение').click()
    page.get_by_text('Требуется согласие',exact=False).wait_for()
    check(page.evaluate('window.calls.filter(c=>c.method==="POST").length')==0,'consent gate')
    page.locator('input[name=ack]').check()
    page.get_by_role('button',name='Сформировать предложение').click()
    page.get_by_text('Изменено <img src=x onerror=alert(1)>',exact=False).wait_for()
    check(page.evaluate('window.calls.filter(c=>c.method==="POST").length')==1,'provider proposal one POST')
    check(page.evaluate('window.proposals[0].state')=='pending','generation not execution')
    check(page.locator('#forum-acp img').count()==0,'model preview inert text')
    check(page.get_by_text('Тест <script>alert(1)</script>',exact=False).count()==1,'rationale safe')
    page.get_by_role('button',name='Применить после подтверждения').click()
    page.wait_for_function('window.proposals[0]?.state === "applied"')
    check(page.evaluate('window.calls.filter(c=>c.method==="POST"&&c.path.endsWith("decision")).length')==1,'separate approved mutation')
    check(page.evaluate('window.calls.find(c=>c.method==="POST"&&c.path.endsWith("decision")).input.digest')=='a'*64,'exact digest')
    page.evaluate('window.permitted=false;window.__acp.mount()')
    page.get_by_text('Недостаточно прав.').wait_for()
    check(page.get_by_text('Управляемые AI-действия форума').count()==0,'role revocation clears panel')
    check(not errors,'no runtime errors '+str(errors))
    browser.close()
print(f'Forum controlled admin AI {args.browser}: {n} PASS')
