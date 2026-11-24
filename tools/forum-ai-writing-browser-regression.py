#!/usr/bin/env python3
"""Exercise shipped writing toolbar against stubbed API boundary in Chromium/Firefox."""
import argparse
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--browser',choices=['chromium','firefox'],default='chromium');args=p.parse_args()
src=(root/'public/forum-assets/forum.js').read_text()
start=src.index('/** Private, opt-in writing suggestions.')
end=src.index('\nfunction topicComposer(',start)
view=src[start:end]
assert "f('/ai/writing/suggest','POST'" in view
mock=r'''
window.__calls=[];window.__errors=[];window.__result='AI <img src=x onerror=alert(1)>';window.__pending=null;
let me={user_id:'test',scopes:['forum:write']};
const node=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e};
const add=(parent,...children)=>{parent.append(...children);return parent};
const button=(text,fn,cls='secondary')=>{const b=node('button',text,cls);b.type='button';b.addEventListener('click',()=>run(fn));return b};
const canWrite=()=>true;
async function run(fn){try{await fn()}catch(e){window.__errors.push(String(e))}}
async function f(path,method,body){if(path!=='/ai/writing/suggest'||method!=='POST')throw Error('Unexpected API call');window.__calls.push(body);
 if(window.__defer){return new Promise(resolve=>{window.__pending=()=>resolve({text:window.__result,mode:body.mode})})}
 return {text:window.__result,mode:body.mode};
}
window.__build=(withTitle=true)=>{
 const editor=document.createElement('form');editor.innerHTML='<label>Сообщение<textarea name="body"></textarea></label><select name="body_format"><option value="plain">Plain</option><option value="markdown">Markdown</option><option value="bbcode">BBCode</option></select>'+(withTitle?'<input name="title">':'')+'<button type="submit">Опубликовать</button>';
 editor.addEventListener('submit',e=>{e.preventDefault();window.__published=true});
 editor.elements.body.value='Исходный текст';editor.elements.body._syncVisual=()=>{};editor.elements.body._exitVisual=()=>{window.__exited=true};
 document.body.append(editor);writingAssistant(editor);return editor;
};
'''
checks=0
def check(test,label):
 global checks
 assert test,label
 checks+=1
with sync_playwright() as playwright:
 bt=getattr(playwright,args.browser)
 options={'executable_path':'/usr/bin/chromium','args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}
 browser=bt.launch(headless=True,**options)
 page=browser.new_page()
 errors=[];page.on('pageerror',lambda exc:errors.append(str(exc)))
 page.set_content('<!doctype html><html lang="ru"><head><meta charset="UTF-8"></head><body></body></html>')
 page.add_script_tag(content=mock+'\n'+view)
 page.evaluate('window.__editor=window.__build()')
 check(page.get_by_role('button',name='Исправить ошибки').count()==1,'fix button')
 check(page.get_by_role('button',name='Предложить заголовок').count()==1,'title button for topic')
 check(page.evaluate('window.__calls.length')==0,'no request without click')
 page.get_by_role('button',name='Исправить ошибки').click()
 page.get_by_text('Предложение готово.',exact=False).wait_for()
 check(page.evaluate('window.__calls.length')==1,'single request')
 check(page.evaluate('window.__calls[0].body')=='Исходный текст','current draft sent')
 check(page.locator('.forum-writing-result img').count()==0,'untrusted AI text not interpreted as HTML')
 check(page.evaluate('window.__editor.elements.body.value')=='Исходный текст','no automatic overwrite')
 check(page.evaluate('window.__published!==true'),'no automatic publish')
 page.get_by_role('button',name='Применить предложение').click()
 check(page.evaluate('window.__editor.elements.body.value')=='AI <img src=x onerror=alert(1)>','only explicit apply changes draft')
 check(page.evaluate('window.__exited===true'),'visual editing mode exited')
 page.evaluate("window.__editor.elements.body.value='Другая версия'")
 page.get_by_role('button',name='Предложить заголовок').click()
 page.get_by_text('Предложение готово.',exact=False).wait_for()
 page.get_by_role('button',name='Отклонить').click()
 check(page.evaluate('window.__editor.elements.title.value')=='','reject leaves title unchanged')
 page.evaluate("window.__result='Точный заголовок'")
 page.get_by_role('button',name='Предложить заголовок').click()
 page.get_by_text('Предложение готово.',exact=False).wait_for()
 page.get_by_role('button',name='Применить предложение').click()
 check(page.evaluate('window.__editor.elements.title.value')=='Точный заголовок','title applies explicitly')
 page.evaluate("window.__defer=true;window.__result='Отложенное предложение'")
 page.get_by_role('button',name='Улучшить текст').click()
 page.wait_for_function('window.__pending!==null')
 page.evaluate("window.__editor.elements.body.value='Исправлено пользователем';window.__pending();window.__pending=null")
 page.get_by_text('Предложение готово.',exact=False).wait_for()
 page.get_by_role('button',name='Применить предложение').click()
 check(page.evaluate('window.__editor.elements.body.value')=='Исправлено пользователем','stale result rejected')
 check(page.get_by_text('Черновик изменён после запроса.',exact=False).count()==1,'conflict signaled')
 check(not errors and not page.evaluate('window.__errors'),'no runtime errors')
 browser.close()
print(f'Forum AI Writing {args.browser}: {checks} PASS')
