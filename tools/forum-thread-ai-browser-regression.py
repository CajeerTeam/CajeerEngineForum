#!/usr/bin/env python3
"""Real browser controls and SSE for the shipped in-thread assistant, mocked HTTP boundary."""
from pathlib import Path
from playwright.sync_api import sync_playwright
import argparse, shutil

root=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser()
parser.add_argument('--browser',choices=['chromium','firefox'],default='chromium')
args=parser.parse_args()
src=(root/'public/forum-assets/forum.js').read_text()
view=src[src.index('async function threadAiPanel('):src.index('async function thread(id){')]
message=src[src.index('function aiMessage('):src.index('\nasync function init(){')]
# Keep only the original aiMessage function; no other app initializers.
message=message.splitlines()[0]
mock=r'''
const app=document.getElementById('app');
let me={csrf_token:'session-csrf'};
const node=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e};
const add=(p,...children)=>{p.append(...children);return p};
const panel=()=>node('section',undefined,'panel');
const button=(text,fn,cls='secondary')=>{const e=node('button',text,cls);e.type='button';e.onclick=()=>run(fn);return e};
const link=(text,url)=>{const e=node('a',text);e.href=url;return e};
window.__errors=[];async function run(fn){try{await fn()}catch(e){window.__errors.push(e.message)}}
const tid='11111111-1111-4111-8111-111111111111';
const post='22222222-2222-4222-8222-222222222222';
const cid='33333333-3333-4333-8333-333333333333';
window.__state={requests:[],private:false,sessions:[],history:[]};
async function f(path,method='GET',body){
 const s=window.__state;
 if(s.private)throw Error('Private forum');
 if(path==='/threads/'+tid+'/ai'&&method==='GET')return {enabled:true,sampled_posts:24,conversations:s.sessions};
 if(path==='/threads/'+tid+'/ai/conversations/'+cid&&method==='GET')return {messages:s.history,sources:[{number:1,post_id:post,page:2,url:'/forum/threads/'+tid+'?page=2#post-'+post}]};
 if(path==='/threads/'+tid+'/ai/conversations/'+cid&&method==='DELETE'){s.sessions=[];s.history=[];return {deleted:true};}
 throw Error('Unexpected '+method+' '+path);
}
window.fetch=async(url,opts)=>{
 const s=window.__state;
 if(url!=='/api/v1/forum/threads/'+tid+'/ai/stream')throw Error('Wrong route '+url);
 if(opts.headers['X-CSRF-Token']!=='session-csrf'||opts.credentials!=='same-origin')throw Error('Missing session protection');
 const payload=JSON.parse(opts.body);s.requests.push(payload);
 if(s.requests.length===1&&payload.mode!=='summary')throw Error('First call must summarize');
 if(s.requests.length===2&&(payload.mode!=='question'||payload.message!=='Что решили?'||payload.conversation_id!==cid))throw Error('Second call lost thread session');
 s.sessions=[{id:cid,title:'Контекстный чат'}];
 s.history.push({role:'user',content:payload.message||payload.mode},{role:'assistant',content:'Вывод [1] <img src=x onerror=alert(1)>',sources:[{number:1,post_id:post,page:2,url:'/forum/threads/'+tid+'?page=2#post-'+post}]});
 const frames='event: delta\ndata: '+JSON.stringify({text:'Вывод [1] '})+'\n\n'+
  'event: delta\ndata: '+JSON.stringify({text:'<img src=x onerror=alert(1)>'})+'\n\n'+
  'event: done\ndata: '+JSON.stringify({conversation_id:cid,model:'gpt-4.1-mini',sources:[{number:1,post_id:post,page:2,url:'/forum/threads/'+tid+'?page=2#post-'+post}]})+'\n\n';
 return new Response(frames,{status:200,headers:{'Content-Type':'text/event-stream'}});
};
'''
checks=0
def check(ok,name):
 global checks
 assert ok,name
 checks+=1

with sync_playwright() as p:
 kw={'executable_path':'/usr/bin/chromium','args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}
 browser=getattr(p,args.browser).launch(headless=True,**kw)
 page=browser.new_page(viewport={'width':1000,'height':850})
 errors=[];page.on('pageerror',lambda e: errors.append(str(e)))
 page.set_content('<!doctype html><html lang="ru"><head><meta charset="utf-8"></head><body><main id="app"></main></body></html>')
 page.add_style_tag(content=(root/'public/forum-assets/forum.css').read_text())
 page.add_script_tag(content=mock+'\n'+message+'\n'+view+'\nwindow.__threadPanel=threadAiPanel;')
 page.evaluate('async()=>app.append(await window.__threadPanel(tid))')
 check(page.get_by_text('AI-помощник по теме').count()==1,'assistant visible in permitted thread')
 check(page.get_by_role('button',name='Пересказать обсуждение').count()==1,'summary action')
 check(page.get_by_role('button',name='Объяснить обсуждение').count()==1,'explanation action')
 check(page.get_by_role('textbox',name='Вопрос по обсуждению').count()==1,'contextual question input')
 page.get_by_role('button',name='Пересказать обсуждение').click()
 page.get_by_text('Ответ сохранён · gpt-4.1-mini').wait_for()
 check(page.get_by_text('Вывод [1]',exact=False).count()>=1,'streamed response rendered')
 check(page.locator('.forum-thread-ai-result img').count()==0,'untrusted model output not parsed as HTML')
 check(page.get_by_role('link',name='[1] Сообщение 22222222').count()==1,'source link comes from server')
 check(page.locator('.forum-thread-ai-sources a').first.get_attribute('href').endswith('?page=2#post-'+ '22222222-2222-4222-8222-222222222222'),'pagination and post anchor')
 page.get_by_role('textbox',name='Вопрос по обсуждению').fill('Что решили?')
 page.get_by_role('button',name='Задать вопрос').click()
 page.get_by_text('Ответ сохранён · gpt-4.1-mini').wait_for()
 check(page.evaluate('window.__state.requests.length')==2,'both modes reached backend')
 check(page.evaluate('window.__state.requests[1].conversation_id')=='33333333-3333-4333-8333-333333333333','follow-up uses isolated conversation')
 page.get_by_role('button',name='Контекстный чат').click()
 check(page.get_by_text('Что решили?',exact=False).count()>0,'persisted history reloads')
 page.on('dialog',lambda d:d.accept())
 page.get_by_role('button',name='Удалить').click()
 page.wait_for_timeout(200)
 page.evaluate('window.__state.private=true')
 page.evaluate('async()=>window.__hidden=await window.__threadPanel(tid)')
 check(page.evaluate('window.__hidden===null'),'ACL forbidden thread does not render assistant')
 check(not errors,'no browser JS exceptions: '+str(errors))
 check(not page.evaluate('window.__errors'),'no UI handler errors')
 browser.close()
print(f'Forum thread AI browser ({args.browser}): {checks} PASS')
