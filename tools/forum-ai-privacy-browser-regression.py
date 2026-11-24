#!/usr/bin/env python3
"""Exercise the shipped privacy screen and actual bulk AI history deletion handler."""
from pathlib import Path
import argparse
import shutil
from playwright.sync_api import sync_playwright

root=Path(__file__).resolve().parents[1]
p=argparse.ArgumentParser();p.add_argument('--browser',choices=['chromium','firefox'],default='chromium');args=p.parse_args()
src=(root/'public/forum-assets/forum.js').read_text()
start=src.index('const privacyLabels=')
end=src.index('\n// Search renders only text nodes',start)
code=src[start:end]
assert "f('/ai/history/erase','POST',{confirmation:'DELETE AI HISTORY'})" in code
mock=r'''
const app=document.getElementById('app');
const me={session:true,suspended:false,user:{},user_id:'alice'};
const node=(tag,text,cls)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(cls)el.className=cls;return el};
const add=(parent,...children)=>{parent.append(...children);return parent};
const panel=()=>node('section',undefined,'panel');
const link=(text,url)=>{const el=node('a',text);el.href=url;return el};
const button=(text,fn,cls='')=>{const el=node('button',text,cls);el.type='button';el.addEventListener('click',()=>Promise.resolve(fn()).catch(e=>window.__errors.push(e.message)));return el};
const form=()=>node('form');
const isAdmin=()=>false;
const heading=t=>add(app,node('h1',t));
const say=t=>{window.__notice=t};
const api=async(path,method,body)=>{
 if(path==='/auth/privacy/export')return {sections:['ai_conversations','ai_messages'],page_size:25};
 throw Error('Unexpected API '+path)
};
window.__calls=[];window.__errors=[];window.__notice='';
const f=async(path,method,body)=>{
 window.__calls.push({path,method,body});
 if(path==='/ai/history/erase'&&method==='POST'&&body.confirmation==='DELETE AI HISTORY')return {deleted_conversations:3,deleted_messages:6};
 throw Error('Unknown API '+path)
};
'''
with sync_playwright() as play:
    bt=getattr(play,args.browser)
    opts={'executable_path':'/usr/bin/chromium','args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}
    browser=bt.launch(headless=True,**opts);page=browser.new_page()
    page.set_content('<html><body><div id="app"></div></body></html>')
    page.add_script_tag(content=mock+'\n'+code+'\nwindow.__privacyPage=privacyPage;')
    page.evaluate('window.__privacyPage()')
    page.get_by_role('heading',name='AI-история').wait_for()
    assert '90 дней' in page.locator('.panel').filter(has_text='AI-история').inner_text()
    assert page.locator('select option').all_text_contents()==['AI-диалоги','Сообщения AI-диалогов']
    assert page.evaluate('window.__calls.length')==0
    page.once('dialog',lambda d:d.dismiss())
    page.get_by_role('button',name='Удалить всю AI-историю').click()
    assert page.evaluate('window.__calls.length')==0,'confirmation rejected yet sent request'
    page.once('dialog',lambda d:d.accept())
    page.get_by_role('button',name='Удалить всю AI-историю').click()
    page.wait_for_function('window.__calls.length===1')
    call=page.evaluate('window.__calls[0]')
    assert call=={'path':'/ai/history/erase','method':'POST','body':{'confirmation':'DELETE AI HISTORY'}},call
    page.get_by_role('heading',name='AI-история').wait_for()
    assert not page.evaluate('window.__errors')
    browser.close()
print('PASS',args.browser,'privacy screen, exported AI categories, mandatory confirmation, owner erase request')
