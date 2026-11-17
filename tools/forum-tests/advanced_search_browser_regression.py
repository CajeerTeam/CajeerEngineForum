#!/usr/bin/env python3
"""Real forum UI with isolated HTTP transport: search filters, discovery, safe highlights and composer."""
import argparse
from pathlib import Path
import re
from playwright.sync_api import sync_playwright, expect
ROOT=Path(__file__).resolve().parents[2]
SCRIPT=(ROOT/'public/forum-assets/forum.js').read_text().replace('run(init);','',1).replace('new URLSearchParams(location.search)','new URLSearchParams(window.testQuery)')
# This harness intercepts navigation in the current production implementation,
# including hash-only navigation introduced for live post refreshes.
SCRIPT, replaced = re.subn(r'const go=url=>\{[\s\S]*?\};const threadUrl=', 'const go=url=>{window.destination=url};const threadUrl=', SCRIPT, count=1)
if replaced != 1: raise RuntimeError('Forum navigation hook missing; update browser harness')
SCRIPT+='''\nwindow.openSearch=async()=>{app.replaceChildren();await search()};window.openComposer=()=>app.replaceChildren(topicComposer('22222222-2222-4222-8222-222222222222'));'''
MOCK='''()=>{window.requests=[];window.fetch=async(url,opts)=>{window.requests.push(url);const u=new URL(url,'https://forum.test');const hit={id:'11111111-1111-4111-8111-111111111111',title:'Database index <img src=x onerror=alert(1)>',prefix:'',post_id:'33333333-3333-4333-8333-333333333333',post_page:2,match_type:'post',snippet:'Database index <script>alert(1)</script>',updated_at:'2026-01-01 00:00:00',total_results:1,reply_count:2,matched_tag:null};let data;if(u.pathname==='/api/v1/forum')data={boards:[{id:'22222222-2222-4222-8222-222222222222',name:'Database',node_type:'forum'}]};else if(u.pathname.endsWith('/members'))data=[{id:'44444444-4444-4444-8444-444444444444',name:'Visible author'}];else if(u.pathname.endsWith('/search'))data=u.searchParams.has('discover')?[{...hit,matched_tag:'database'}]:[hit];else throw Error(url);return {ok:true,status:200,json:async()=>({data})}}}'''
def main():
    ap=argparse.ArgumentParser();ap.add_argument('--browser',default='chromium');ap.add_argument('--executable');args=ap.parse_args()
    with sync_playwright() as p:
        opts={'executable_path':args.executable} if args.executable else {}
        if args.browser=='chromium':opts['args']=['--no-sandbox']
        browser=getattr(p,args.browser).launch(**opts);page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content('<div id="notice"></div><div id="app"></div>');page.evaluate("window.testQuery='?q=Database&mode=posts'");page.evaluate(MOCK);page.add_script_tag(content='(()=>{'+SCRIPT+'})()');page.evaluate('window.openSearch()')
        expect(page.get_by_role('heading',name='Расширенный поиск')).to_be_visible();expect(page.get_by_text('Найдено: 1',exact=False)).to_be_visible();expect(page.locator('mark').first).to_have_text('Database');assert page.locator('#app script,#app img').count()==0
        expect(page.get_by_role('link',name='Перейти к сообщению')).to_have_attribute('href','/forum/threads/11111111-1111-4111-8111-111111111111?page=2#post-33333333-3333-4333-8333-333333333333')
        page.locator('[name=q]').fill('Data');page.get_by_role('button',name='Подсказки по темам и тегам').click();expect(page.get_by_role('link',name='#database')).to_be_visible()
        page.get_by_role('textbox',name='Имя автора').fill('Visible');page.get_by_role('button',name='Найти автора').click();page.get_by_role('button',name='Visible author').click();expect(page.locator('[name=author_id]')).to_have_value('44444444-4444-4444-8444-444444444444')
        page.locator('[name=board_id]').select_option('22222222-2222-4222-8222-222222222222');page.locator('[name=sort]').select_option('replies');page.locator('[name=question]').select_option('solved');page.locator('[name=from]').fill('2026-01-01');page.locator('[name=to]').fill('2026-01-31');page.get_by_role('button',name='Найти',exact=True).click();page.wait_for_function('window.destination?.includes("sort=replies")');assert 'question=solved' in page.evaluate('window.destination') and 'author_id=44444444' in page.evaluate('window.destination')
        page.evaluate('window.openComposer()');page.locator('[name=title]').fill('Database index');page.get_by_role('button',name='Найти похожие темы').click();expect(page.get_by_role('link',name='#database')).to_be_visible();assert any('discover=similar' in x and 'board_id=22222222' in x for x in page.evaluate('window.requests'));assert not errors,errors
        browser.close();print('PASS '+args.browser+' advanced filters, author picker, safe highlighting, suggestions and composer discovery')
if __name__=='__main__':main()
