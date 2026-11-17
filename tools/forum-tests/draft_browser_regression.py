#!/usr/bin/env python3
"""Real browser draft controls and revision comparison, isolated API transport."""
import argparse
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
ROOT=Path(__file__).resolve().parents[2]
SCRIPT=(ROOT/'public/forum-assets/forum.js').read_text().rsplit('run(init);',1)[0]
# Preserve the production navigation code outside this browser fixture. Mock
# only the navigation function; assert its exact boundaries before replacing.
start=SCRIPT.index('const go=url=>{');stop=SCRIPT.index('};const threadUrl',start)+2
assert 'location.assign(target.href)' in SCRIPT[start:stop], 'Forum navigation changed; update fixture'
SCRIPT=SCRIPT[:start]+'const go=url=>{window.destination=url};'+SCRIPT[stop:]
SCRIPT+='''\nme={user_id:'owner',scopes:['forum:write'],csrf_token:'test'};window.composer=()=>app.replaceChildren(topicComposer('22222222-2222-4222-8222-222222222222'));window.diff=revisionDiff;window.historyDemo=async()=>app.replaceChildren(await revisionPanel('/posts/test/revisions',2,()=>{window.refreshed=true}));'''
MOCK='''()=>{window.confirm=()=>true;window.records=new Map();window.fetch=async(url,opts)=>{const method=opts.method,body=JSON.parse(opts.body||'null');const result=(data,status=200)=>({ok:status<400,status,json:async()=>status<400?{data}:{error:{message:'Черновик изменён'}}});if(url.includes('/drafts?'))return result([...window.records.values()].filter(x=>!x.result));if(url.includes('/drafts/')){const id=url.split('/drafts/')[1].split('/')[0];const old=window.records.get(id);if(method==='PUT'){if((old?.revision_number||0)!==body.revision_number)return result(null,409);const d={id,scope:body.scope,target_id:body.target_id,revision_number:body.revision_number+1,payload:body.payload,result:null,updated_at:1};window.records.set(id,d);return result(d)}if(url.endsWith('/publish')){window.published=old.payload;old.result={id:'published'};return result(old.result)}return result(old)}if(url.includes('/revisions/restore')){window.restored=body;return result({id:'test',revision_number:3})}if(url.includes('/revisions?'))return result([{revision_number:2,body:'same\\nnew',body_format:'plain',created_at:1,edit_reason:'edit'},{revision_number:1,body:'same\\nold',body_format:'plain',created_at:1,edit_reason:''}]);throw Error(url)}}'''

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--browser',default='chromium');ap.add_argument('--executable');args=ap.parse_args()
    with sync_playwright() as p:
        opts={'executable_path':args.executable} if args.executable else {}
        if args.browser=='chromium':opts['args']=['--no-sandbox']
        browser=getattr(p,args.browser).launch(**opts);page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content('<div id="notice"></div><div id="app"></div>');page.evaluate(MOCK);page.add_script_tag(content='(()=>{'+SCRIPT+'})()');page.evaluate('window.composer()');page.locator('[name=title]').fill('Saved draft');page.locator('[name=body]').fill('Initial body');expect(page.get_by_text('Сохранено · версия 1',exact=True)).to_be_visible(timeout=8000)
        page.evaluate('window.composer()');page.get_by_role('button',name='Продолжить сохранённый черновик').click();expect(page.locator('[name=body]')).to_have_value('Initial body');page.locator('[name=body]').fill('Edited body');page.get_by_role('button',name='Сохранить черновик',exact=True).click();expect(page.get_by_text('Сохранено · версия 2',exact=True)).to_be_visible()
        page.evaluate('window.records.values().next().value.revision_number++');page.locator('[name=body]').fill('Conflict text');page.get_by_role('button',name='Сохранить черновик',exact=True).click();expect(page.get_by_text('Не сохранено: Черновик изменён',exact=True)).to_be_visible();expect(page.locator('[name=body]')).to_have_value('Conflict text');page.get_by_role('button',name='Сохранить отдельную копию').click();expect(page.get_by_text('Сохранено · версия 1',exact=True)).to_be_visible();page.get_by_role('button',name='Создать тему',exact=True).click();page.wait_for_function('window.destination==="/forum/threads/published"');assert page.evaluate('window.published.body')=='Conflict text'
        # Pause a real submission in flight; the composer must not accept edits.
        page.evaluate("""()=>{window.destination='';window.composer();const orig=window.fetch;window.fetch=async(url,opts)=>{if(url.endsWith('/publish')){window.waiting=true;await new Promise(resolve=>window.finishPublish=resolve);window.waiting=false}return orig(url,opts)}}""")
        page.locator('[name=title]').fill('In-flight draft');page.locator('[name=body]').fill('Frozen content');page.get_by_role('button',name='Создать тему',exact=True).click();page.wait_for_function('window.waiting===true');assert page.locator('form').evaluate('(el)=>el.inert && el.getAttribute("aria-busy")==="true"');page.evaluate('window.finishPublish()');page.wait_for_function('window.destination==="/forum/threads/published"');assert page.evaluate('window.published.body')=='Frozen content'
        page.evaluate('window.historyDemo()');expect(page.locator('.diff-remove')).to_contain_text('old');expect(page.locator('.diff-add')).to_contain_text('new');page.get_by_text('Версия 1 ·',exact=False).last.click();page.get_by_role('button',name='Восстановить версию 1').click();page.wait_for_function('window.refreshed===true');assert page.evaluate('window.restored')=={'revision_number':1,'expected_revision':2};assert not errors,errors;browser.close();print('PASS Chromium autosave, reopen, conflict, copy, publish, line diff and restore request')
if __name__=='__main__':main()
