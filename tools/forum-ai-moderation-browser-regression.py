#!/usr/bin/env python3
"""Exercise shipped forum moderation advisory UI with a stubbed HTTP transport."""
import argparse,shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
parser=argparse.ArgumentParser();parser.add_argument('--browser',default='chromium',choices=['chromium','firefox']);args=parser.parse_args()
source=(root/'public/forum-assets/forum.js').read_text()
start=source.index('const aiModerationLabels=')
end=source.index('\nasync function showModerationCase(',start)
view=source[start:end]
assert "acknowledge_external_processing:true" in view
assert "include_non_public:true" in view
mock=r'''
window.__calls=[];window.__errors=[];window.__consent=true;window.__assessment=null;
const node=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e};
const add=(parent,...children)=>{parent.append(...children);return parent};
const button=(text,fn,cls='secondary')=>{const b=node('button',text,cls);b.type='button';b.addEventListener('click',()=>run(fn));return b};
async function run(fn){try{await fn()}catch(e){window.__errors.push(String(e))}}
window.confirm=()=>window.__consent;
async function f(path,method='GET',body){window.__calls.push({path,method,body});
 if(!path.endsWith('/ai'))throw Error('Incorrect path');
 if(method==='POST'){
  if(!body?.acknowledge_external_processing||!body?.include_non_public)throw Error('Missing consent');
  window.__assessment={available:true,advisory_only:true,revision_number:3,model:'gpt-4.1-mini',created_at:1763900000,assessment:{risk:'high',recommendation:'review',flags:['spam'],rationale:'Spam <img src=x onerror=alert(1)>',evidence:['Exact quote']}};
 }
 return window.__assessment||{available:false,revision_number:3};
}
window.__build=()=>{let box=document.createElement('div');document.body.append(box);aiModerationCard(box,'/moderation/cases/report/aabb',3);return box};
'''
checks=0
def check(condition,label):
 global checks
 assert condition,label
 checks+=1
with sync_playwright() as p:
 engine=getattr(p,args.browser)
 opts={'executable_path':'/usr/bin/chromium','args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}
 browser=engine.launch(headless=True,**opts)
 page=browser.new_page();errs=[];page.on('pageerror',lambda e:errs.append(str(e)))
 page.set_content('<!doctype html><html lang="ru"><head><meta charset="UTF-8"></head><body></body></html>')
 page.add_script_tag(content=mock+'\n'+view)
 page.evaluate('window.__build()')
 page.get_by_text('Актуальная оценка ещё не создана.').wait_for()
 check(page.get_by_role('button',name='Провести AI-анализ').count()==1,'advisor button visible')
 check(page.evaluate('window.__calls.filter(x=>x.method==="POST").length')==0,'no automatic provider call')
 page.evaluate('window.__consent=false')
 page.get_by_role('button',name='Провести AI-анализ').click()
 check(page.evaluate('window.__calls.filter(x=>x.method==="POST").length')==0,'cancelled consent prevents provider')
 page.evaluate('window.__consent=true')
 page.get_by_role('button',name='Провести AI-анализ').click()
 page.get_by_text('Spam <img src=x onerror=alert(1)>',exact=True).wait_for()
 check(page.evaluate('window.__calls.filter(x=>x.method==="POST").length')==1,'one explicit assess POST')
 check(page.evaluate('window.__calls.find(x=>x.method==="POST").body.include_non_public') is True,'private content explicit consent')
 check(page.locator('img').count()==0,'untrusted model response not HTML')
 check(page.get_by_text('Риск: Высокий',exact=False).count()==1,'risk translation')
 check(page.get_by_text('Exact quote',exact=True).count()==1,'evidence displayed')
 check(not errs and not page.evaluate('window.__errors'),'no JavaScript errors')
 browser.close()
print(f'Forum AI Moderation {args.browser}: {checks} PASS')
