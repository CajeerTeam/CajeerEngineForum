#!/usr/bin/env python3
"""Execute actual forum AI search UI with a controlled SSE transport in Chromium/Firefox."""
import argparse,shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser();parser.add_argument('--browser',choices=['chromium','firefox'],default='chromium');args=parser.parse_args()
src=(root/'public/forum-assets/forum.js').read_text();start=src.index('function aiSearchPanel(');end=src.index('\nasync function search(){',start);view=src[start:end]
assert '/api/v1/forum/ai/search/stream' in view
mock=r'''
const app=document.getElementById('app');const me={csrf_token:'csrf-search-test'};
const node=(tag,text,cls)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(cls)el.className=cls;return el};
const add=(parent,...items)=>{parent.append(...items);return parent};
const panel=()=>node('section');
const link=(text,url)=>{const a=node('a',text);a.href=url;return a};
const aiMessage=(role,text)=>{const a=node('article');add(a,node('div',text,'forum-ai-text'));return a};
async function run(fn){try{await fn()}catch(err){window.__errors.push(err.message)}}
window.__errors=[];window.__calls=[];
const thread='11111111-1111-4111-8111-111111111111',post='22222222-2222-4222-8222-222222222222';
window.fetch=async(url,opts)=>{
 if(url!=='/api/v1/forum/ai/search/stream')throw Error('Unexpected URL '+url);
 if(opts.headers['X-CSRF-Token']!=='csrf-search-test')throw Error('Missing CSRF');
 const input=JSON.parse(opts.body);window.__calls.push(input);
 if(!input.question)throw Error('No query');
 const sources=[{number:1,title:'Безопасное обсуждение <img onerror=alert(1)>',thread_id:thread,post_id:post,page:2,url:'/wrong',excerpt:'Из текста настоящего публичного сообщения <script>alert(1)</script>'}];
 const frames='event: sources\ndata: '+JSON.stringify({sources,scope:'public_only'})+'\n\n'+
 'event: delta\ndata: '+JSON.stringify({text:'Ответ '})+'\n\n'+
 'event: delta\ndata: '+JSON.stringify({text:'[1]'})+'\n\n'+
 'event: done\ndata: '+JSON.stringify({text:'Ответ [1]',sources,citation_check:{valid:true,cited_numbers:[1],invalid_numbers:[]},no_evidence:false})+'\n\n';
 return new Response(frames,{status:200,headers:{'Content-Type':'text/event-stream'}});
};
'''
checks=0
def check(ok,why):
 global checks
 assert ok,why
 checks+=1
with sync_playwright() as p:
 browser_type=getattr(p,args.browser)
 opts={'executable_path':'/usr/bin/chromium','args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}
 browser=browser_type.launch(headless=True,**opts)
 page=browser.new_page(viewport={'width':950,'height':680})
 exceptions=[];page.on('pageerror',lambda err:exceptions.append(str(err)))
 page.set_content('<!doctype html><html><head><meta charset="utf-8"></head><body><div id="app"></div></body></html>')
 page.add_script_tag(content=mock+'\n'+view+'\nwindow.__panel=aiSearchPanel;')
 page.evaluate('app.append(window.__panel("Как проверить настройку?"))')
 check(page.get_by_role('textbox',name='Вопрос для AI-поиска').count()==1,'natural language text field')
 check(page.evaluate('window.__calls.length')==0,'no external provider action without click')
 page.get_by_role('button',name='Найти ответ с AI').click()
 page.get_by_text('Цитаты ссылаются на доступные сообщения.',exact=False).wait_for()
 check(page.locator('.forum-ai-text').inner_text()=='Ответ [1]','SSE answer assembled')
 check(page.locator('.forum-ai-search-source').count()==1,'real source rendered')
 check(page.locator('.forum-ai-search-source.cited').count()==1,'citation highlighting')
 check(page.locator('.forum-ai-search-source a').get_attribute('href')=='/forum/threads/11111111-1111-4111-8111-111111111111?page=2#post-22222222-2222-4222-8222-222222222222','safe server-owned href ignores tampered URL')
 check(page.locator('.forum-ai-search-source img').count()==0 and page.locator('.forum-ai-search-source script').count()==0,'untrusted source title escaped')
 check(page.locator('.forum-ai-search-source p').inner_text().endswith('<script>alert(1)</script>'),'source excerpt is text only')
 check(page.evaluate('window.__calls[0].question')=='Как проверить настройку?','only user question sent')
 check(not exceptions and not page.evaluate('window.__errors'),'no browser exceptions')
 browser.close()
print(f'Forum AI search browser {args.browser}: {checks} PASS')
