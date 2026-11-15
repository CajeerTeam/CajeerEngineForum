#!/usr/bin/env python3
"""Exercise actual moderation UI in a browser with isolated HTTP transport."""
import argparse
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
ROOT=Path(__file__).resolve().parents[2]
SCRIPT=(ROOT/'public/forum-assets/forum.js').read_text().replace('run(init);','',1)
SCRIPT+='''\nme={user_id:'moderator',scopes:['*'],csrf_token:'test'};window.openCase=(kind)=>{app.replaceChildren();return showModerationCase(app,kind,'case')};window.own=()=>submissionsPage();window.report=()=>{app.replaceChildren();reportForm(app,'post')};'''
MOCK='''()=>{window.events=[];window.item={id:'case',status:'open',revision_number:1,reason:'Evidence',category:'spam',snapshot_body:'<script>window.injected=true</script>',current_body:'Updated',thread_id:'thread',priority:1};window.fetch=async(url,opts)=>{const data=JSON.parse(opts.body||'null');window.last={url,data,csrf:opts.headers['X-CSRF-Token']};if(opts.method==='POST'&&url.includes('/cases/')){if(data.revision_number!==window.item.revision_number)return {ok:false,status:409,json:async()=>({error:{message:'Conflict'}})};window.item.revision_number++;if(data.action==='resolve')window.item.status='resolved';if(data.action==='approve')window.item.status='approved';window.events.unshift({action:data.action,created_at:1700000000,actor_id:'moderator',detail:data.reason||''})}if(url.includes('/cases/'))return {ok:true,json:async()=>({data:{item:window.item,history:window.events}})};if(url.endsWith('/cancel'))window.item.status='cancelled';if(url.includes('/submissions'))return {ok:true,json:async()=>({data:[window.item]})};return {ok:true,json:async()=>({data:{id:'report'}})}}}'''
def main():
    ap=argparse.ArgumentParser();ap.add_argument('--browser',default='chromium',choices=['chromium','firefox']);ap.add_argument('--executable');args=ap.parse_args()
    with sync_playwright() as p:
        opts={'executable_path':args.executable} if args.executable else {}
        if args.browser=='chromium':opts['args']=['--no-sandbox']
        browser=getattr(p,args.browser).launch(**opts);page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept('Reviewed reason') if d.type=='prompt' else d.accept())
        page.set_content('<div id="notice"></div><div id="app"></div>');page.evaluate(MOCK);page.add_script_tag(content='(()=>{'+SCRIPT+'})()')
        page.evaluate('window.openCase("report")');expect(page.get_by_text('Снимок при создании жалобы')).to_be_visible();assert page.evaluate('window.injected') is None
        page.get_by_role('button',name='Назначить себя').click();page.wait_for_function('window.item.revision_number===2');expect(page.get_by_text('assign ·',exact=False)).to_be_visible()
        page.get_by_role('button',name='Приоритет: высокий').click();page.wait_for_function('window.item.revision_number===3');expect(page.get_by_text('priority ·',exact=False)).to_be_visible()
        page.get_by_role('button',name='Закрытая заметка').click();page.wait_for_function('window.item.revision_number===4');expect(page.get_by_text('note ·',exact=False)).to_be_visible()
        page.get_by_role('button',name='Рассмотреть жалобу').click();page.wait_for_function('window.item.status==="resolved"');expect(page.get_by_role('button',name='Рассмотреть жалобу')).to_have_count(0);assert page.evaluate('window.events[0].action')=='resolve'
        page.evaluate('''()=>{window.item={id:'case',status:'pending',revision_number:1,author_id:'author',payload:{body:'Pending body',title:'Pending title',attachment_ids:['file']}};window.events=[];return window.openCase('submission')}''');expect(page.get_by_role('link',name='Скачать файл file')).to_have_attribute('href','/api/v1/forum/moderation/submissions/case/files/file');page.get_by_role('button',name='Одобрить публикацию').click();page.wait_for_function('window.item.status==="approved"');expect(page.get_by_role('button',name='Одобрить публикацию')).to_have_count(0)
        page.evaluate('''()=>{window.item.status='pending';return window.own()}''');expect(page.get_by_text('Ожидает проверки')).to_be_visible();page.get_by_role('button',name='Отменить заявку').click();expect(page.get_by_text('Отменено')).to_be_visible()
        page.evaluate('window.report()');page.locator('[name=category]').select_option('spam');page.locator('[name=reason]').fill('Repeated spam');page.get_by_role('button',name='Отправить жалобу').click();expect(page.get_by_text('Жалоба отправлена.')).to_be_visible();assert page.evaluate('window.last.data')=={'category':'spam','reason':'Repeated spam'};assert page.evaluate('window.last.csrf')=='test';assert not errors,errors
        browser.close();print('PASS browser moderation evidence escaping, assignment, priority, notes, approval, author cancellation and report form')
if __name__=='__main__':main()
