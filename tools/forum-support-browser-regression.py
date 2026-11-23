#!/usr/bin/env python3
"""Shipped member/operator Forum Support UI with stubbed HTTP boundary (Chromium/Firefox)."""
import argparse
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser();parser.add_argument('--browser',choices=['chromium','firefox'],default='chromium');args=parser.parse_args()
src=(root/'public/forum-assets/forum.js').read_text()
start=src.index('/** Forum Support 1.3.5.')
end=src.index('\nasync function init(){',start)
view=src[start:end]
assert "'/support/tickets'" in view and 'function forumSupportOperatorPage' in view
mock=r'''
const app=document.getElementById('app');const notice=document.createElement('div');document.body.append(notice);
let me={session:true,csrf_token:'csrf-test',scopes:['forum:acp:members']};
const node=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e};
const add=(parent,...items)=>{parent.append(...items);return parent};
const panel=()=>node('section',undefined,'panel');
const link=(title,href)=>{const a=node('a',title);a.href=href;return a};
const button=(title,fn)=>{const b=node('button',title);b.type='button';b.addEventListener('click',()=>run(fn));return b};
const isAdmin=()=>false;
function heading(title){app.replaceChildren(node('h1',title))}
async function run(fn){try{await fn()}catch(e){window.__errors.push(e.message)}}
function say(text){notice.textContent=text}
window.__errors=[];window.__calls=[];
const id='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const thread='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',post='cccccccc-cccc-4ccc-8ccc-cccccccccccc';
window.__ticket=null;window.__faq=[{id:'faq-a',question:'Как войти?',answer:'Через SkiF4er ID',published:1,sort_order:0,revision:1}];
function ticketView(){return {ticket:{...window.__ticket},messages:[{role:'user',body:'Нужна помощь'},{role:'assistant',body:'Ответ [1] <script>alert(1)</script>',sources:[{thread_id:thread,post_id:post,page:1,title:'Публичная тема'}]}]}}
async function f(path,method='GET',body){window.__calls.push({path,method,body});
 if(path==='/support/faqs')return window.__faq.filter(x=>x.published);
 if(path==='/support/tickets'&&method==='GET')return window.__ticket?[window.__ticket]:[];
 if(path==='/support/tickets'&&method==='POST'){
  window.__ticket={id,subject:body.subject,status:body.ai_help?'waiting':'open',revision:body.ai_help?2:1,needs_human:body.ai_help?0:1};
  return ticketView();
 }
 if(path==='/support/tickets/'+id&&method==='GET')return ticketView();
 if(path==='/support/tickets/'+id+'/handoff'&&method==='POST'){
  if(body.expected_revision!==window.__ticket.revision)throw Error('Stale revision');
  Object.assign(window.__ticket,{status:'open',needs_human:1,revision:window.__ticket.revision+1});return ticketView();
 }
 if(path==='/support/tickets/'+id+'/reply'&&method==='POST'){
  if(body.expected_revision!==window.__ticket.revision)throw Error('Stale revision');
  Object.assign(window.__ticket,{status:'open',needs_human:1,revision:window.__ticket.revision+1});return ticketView();
 }
 if(path.startsWith('/acp/support/tickets?'))return window.__ticket?[window.__ticket]:[];
 if(path==='/acp/support/tickets/'+id+'/action'&&method==='POST'){
  if(body.expected_revision!==window.__ticket.revision)throw Error('Stale revision');
  Object.assign(window.__ticket,{status:body.action==='resolve'?'resolved':'open',needs_human:body.action==='resolve'?0:1,revision:window.__ticket.revision+1});return ticketView();
 }
 if(path==='/acp/support/faqs'&&method==='GET')return window.__faq;
 if(path==='/acp/support/faqs'&&method==='POST'){
  window.__faq.push({...body,id:'faq-b',revision:1});return window.__faq.at(-1);
 }
 if(path==='/acp/support/faqs/faq-a'&&method==='PUT'){
  if(body.expected_revision!==window.__faq[0].revision)throw Error('Stale FAQ');
  Object.assign(window.__faq[0],body,{revision:body.expected_revision+1});return window.__faq[0];
 }
 if(path==='/acp/support/faqs/faq-a'&&method==='DELETE'){
  window.__faq.shift();return {deleted:true};
 }
 throw Error('Unexpected '+method+' '+path)
}
'''
checks=0
def check(condition,message):
 global checks
 assert condition,message
 checks+=1

with sync_playwright() as playwright:
 bt=getattr(playwright,args.browser)
 opts={'executable_path':'/usr/bin/chromium','args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}
 browser=bt.launch(headless=True,**opts)
 page=browser.new_page(viewport={'width':1200,'height':800})
 errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 page.set_content('<!doctype html><html lang="ru"><head><meta charset="utf-8"></head><body><div id="app"></div></body></html>')
 page.add_style_tag(content=(root/'public/forum-assets/forum.css').read_text())
 page.add_script_tag(content=mock+'\n'+view+'\nwindow.__userPage=forumSupportPage;window.__operatorPage=forumSupportOperatorPage;')
 page.evaluate('window.__userPage()')
 page.get_by_text('Частые вопросы').wait_for()
 check(page.get_by_text('Как войти?').count()==1,'curated FAQ shown')
 check(not page.get_by_text('Через SkiF4er ID').is_visible(),'FAQ initially collapsed')
 page.get_by_text('Как войти?').click()
 check(page.get_by_text('Через SkiF4er ID').count()==1,'FAQ expands')
 check(page.get_by_label('Попробовать AI-ответ',exact=False).count()==1,'opt-in checkbox displayed')
 check(page.get_by_label('Попробовать AI-ответ',exact=False).is_checked() is False,'AI opt-in disabled by default')
 page.get_by_label('Тема').fill('Вопрос о форуме')
 page.get_by_label('Описание').fill('Нужна помощь')
 page.get_by_label('Попробовать AI-ответ',exact=False).check()
 page.get_by_role('button',name='Создать обращение').click()
 page.get_by_text('Обращение создано.').wait_for()
 check(page.evaluate("window.__calls.some(x=>x.method==='POST'&&x.path==='/support/tickets'&&x.body.ai_help===true)"),'ticket POST contains explicit AI consent')
 check(page.locator('.forum-support-message script').count()==0,'AI response is text-only and cannot inject HTML')
 check(page.locator('.forum-support-sources a').first.get_attribute('href')==f'/forum/threads/bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb?page=1#post-cccccccc-cccc-4ccc-8ccc-cccccccccccc','source link built from validated IDs')
 page.once('dialog',lambda d:d.accept())
 page.get_by_role('button',name='Передать оператору').click()
 page.get_by_text('Передано оператору.').wait_for()
 check(page.evaluate("window.__ticket.status==='open'&&window.__ticket.needs_human===1"),'human handoff persists')
 page.evaluate('window.__operatorPage()')
 page.get_by_text('Очередь обращений').wait_for()
 check(page.get_by_role('button',name='Вопрос о форуме · open',exact=False).count()==1,'operator queue renders ticket')
 page.get_by_role('button',name='Вопрос о форуме · open',exact=False).click()
 page.get_by_role('button',name='Закрыть обращение').click()
 page.get_by_text('Статус изменён.').wait_for()
 check(page.evaluate("window.__ticket.status==='resolved'"),'operator resolution updates ticket')
 page.get_by_role('button',name='Добавить FAQ').click()
 page.get_by_role('button',name='Сохранить FAQ').wait_for()
 check(page.get_by_role('button',name='Сохранить FAQ').count()==1,'functional FAQ editor')
 check(not errors and not page.evaluate('window.__errors'),'no JS runtime errors')
 browser.close()
print(f'Forum support {args.browser}: {checks} PASS')
