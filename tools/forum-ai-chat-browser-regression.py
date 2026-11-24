#!/usr/bin/env python3
"""Render and exercise the shipped Forum AI view in Chromium/Firefox with mocked API/stream transport."""
import argparse
import json
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser()
parser.add_argument('--browser',choices=['chromium','firefox'],default='chromium')
args=parser.parse_args()
src=(root/'public/forum-assets/forum.js').read_text()
start=src.index('async function aiPage(){')
end=src.index('\nasync function init(){',start)
view=src[start:end]
assert "fetch('/api/v1/forum/ai/chat/stream'" in view
mock=r'''
const app=document.getElementById('app');
let me={csrf_token:'csrf-test'};
const node=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e};
const add=(parent,...children)=>{parent.append(...children);return parent};
const panel=()=>node('div',undefined,'panel');
const link=(text,url,cls)=>{const x=node('a',text,cls);x.href=url;return x};
const button=(text,fn,cls='secondary')=>{const x=node('button',text,cls);x.type='button';x.addEventListener('click',()=>run(fn));return x};
function heading(text){add(app,node('h1',text))}
async function run(fn){try{await fn()}catch(e){window.__errors.push(e.message)}}
window.__errors=[];
const id='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
window.__calls=[];window.__conversations=[];window.__messages=[];
async function f(path,method='GET',body){
 if(path==='/ai/status')return {enabled:true,configured:true,model:'gpt-4.1-mini',daily_requests_used:0,daily_request_limit:20,monthly_tokens_used:0,monthly_token_limit:100000,requests_per_minute:5,history_retention_days:90};
 if(path==='/ai/conversations' && method==='GET')return window.__conversations;
 if(path==='/ai/conversations/'+id && method==='GET')return window.__messages;
 if(path==='/ai/conversations/'+id && method==='PATCH'){
  window.__conversations[0].title=body.title;return {title:body.title,id};
 }
 if(path==='/ai/conversations/'+id && method==='DELETE'){
  window.__conversations.length=0;window.__messages.length=0;return {deleted:true};
 }
 throw Error('Unexpected API '+method+' '+path)
}
window.fetch=async(url,opts)=>{
 if(url!=='/api/v1/forum/ai/chat/stream')throw Error('Unexpected fetch '+url);
 if(opts.headers['X-CSRF-Token']!=='csrf-test')throw Error('CSRF token absent');
 const payload=JSON.parse(opts.body);window.__calls.push(payload);
 if(payload.message!=='Вопрос о форуме')throw Error('Wrong user content');
 window.__conversations.splice(0,window.__conversations.length,{id,title:'Вопрос о форуме'});
 window.__messages.push({role:'user',content:payload.message},{role:'assistant',content:'Ответ помощника'});
 const result={conversation_id:id,text:'Ответ помощника',model:'gpt-4.1-mini',usage:{input_tokens:20,output_tokens:10}};
 const frames='event: delta\ndata: '+JSON.stringify({text:'Ответ '})+'\n\n'+
 'event: delta\ndata: '+JSON.stringify({text:'помощника'})+'\n\n'+
 'event: done\ndata: '+JSON.stringify(result)+'\n\n';
 return new Response(frames,{status:200,headers:{'Content-Type':'text/event-stream'}})
};
'''
checks=0

def check(test,caption):
 global checks
 assert test,caption
 checks+=1

with sync_playwright() as p:
 bt=getattr(p,args.browser)
 options={'executable_path':'/usr/bin/chromium','args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}
 b=bt.launch(headless=True,**options)
 page=b.new_page(viewport={'width':1060,'height':750})
 errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 page.set_content('<!doctype html><html lang="ru"><head><meta charset="UTF-8"></head><body><div id="app"></div></body></html>')
 page.add_style_tag(content=(root/'public/forum-assets/forum.css').read_text())
 page.add_script_tag(content=mock+'\n'+view+'\nwindow.__aiPage=aiPage;')
 page.evaluate('window.__aiPage()')
 page.get_by_text('AI-помощник форума',exact=True).wait_for()
 check(page.get_by_role('button',name='Новый диалог').count()==1,'new conversation button renders')
 check(page.get_by_role('textbox',name='Сообщение AI-помощнику').count()==1,'user text field renders')
 page.get_by_role('textbox',name='Сообщение AI-помощнику').fill('Вопрос о форуме')
 page.get_by_role('button',name='Отправить').click()
 page.get_by_text('Готово · gpt-4.1-mini').wait_for()
 check(page.locator('.forum-ai-text').filter(has_text='Ответ помощника').count()==1,'SSE deltas appended to response node')
 check(page.evaluate('window.__calls.length')==1,'single valid streamed POST was sent')
 page.get_by_role('button',name='Вопрос о форуме',exact=True).click()
 page.locator('.forum-ai-message').first.wait_for()
 check(page.locator('.forum-ai-message').count()==2,'history fetched from server state')
 page.once('dialog',lambda d:d.accept('Переименованный чат'))
 page.get_by_role('button',name='Переименовать Вопрос о форуме').click()
 page.get_by_role('button',name='Переименованный чат',exact=True).wait_for()
 check(page.evaluate('window.__conversations[0].title')=='Переименованный чат','rename persisted via PATCH')
 page.once('dialog',lambda d:d.accept())
 page.get_by_role('button',name='Удалить Переименованный чат').click()
 page.get_by_text('Диалогов пока нет.').wait_for()
 check(page.evaluate('window.__messages.length===0 && window.__conversations.length===0'),'DELETE clears owned history')
 check(not errors and not page.evaluate('window.__errors'),'no uncaught browser or UI errors')
 b.close()
print('PASS',args.browser,'forum AI browser',checks,'checks')
