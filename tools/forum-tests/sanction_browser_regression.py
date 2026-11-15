#!/usr/bin/env python3
"""Actual forum sanction UI in Chromium/Firefox with isolated API transport."""
import argparse
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
ROOT=Path(__file__).resolve().parents[2]
SCRIPT=(ROOT/'public/forum-assets/forum.js').read_text().replace('run(init);','',1).replace('location.reload()','window.testReload()')
SCRIPT+='''
window.openSanctions=(admin)=>{me={user_id:admin?'admin':'member',scopes:admin?['*']:[],suspended:!admin,csrf_token:'test-csrf'};window.testReload=()=>sanctionsPage(admin?'member':null);return sanctionsPage(admin?'member':null)};
window.openAppeals=()=>{me={user_id:'admin',scopes:['*'],csrf_token:'test-csrf'};window.testReload=()=>sanctionAppealsPage();return sanctionAppealsPage()};
'''
MOCK='''()=>{window.requests=[];window.sanction=null;window.appeal=null;window.fetch=async(url,opts)=>{
 const body=JSON.parse(opts.body||'null');let data={};if(url==='/api/v1/forum')data={boards:[{id:'board',name:'Test board',permissions:{'thread:moderate':true}}]};
 else if(url.endsWith('/sanctions')&&opts.method==='POST'){window.requests.push({url,body,csrf:opts.headers['X-CSRF-Token']});window.sanction={id:'sanction',kind:body.kind,reason:body.reason,active:true,points:body.points,board_id:body.board_id,ends_at:1800000000,revoked_at:null};data=window.sanction}
 else if(url.includes('/sanctions?page='))data=window.sanction?[window.sanction]:[];
 else if(url.endsWith('/appeal')){window.requests.push({url,body,csrf:opts.headers['X-CSRF-Token']});window.appeal={id:'appeal',sanction_id:'sanction',user_id:'member',kind:'suspension',reason:window.sanction.reason,body:body.body,status:'pending'};Object.assign(window.sanction,{appeal_id:'appeal',appeal_status:'pending',appeal_body:body.body});data=window.appeal}
 else if(url.includes('/moderation/appeals?page='))data=window.appeal?.status==='pending'?[window.appeal]:[];
 else if(url.endsWith('/appeal/decision')){window.requests.push({url,body,csrf:opts.headers['X-CSRF-Token']});window.appeal.status=body.status;Object.assign(window.sanction,{active:false,revoked_at:1700000000,appeal_status:'accepted',appeal_decision:body.reason});data=window.appeal}
 return {ok:true,status:200,json:async()=>({data})};};}'''
def main():
    parser=argparse.ArgumentParser();parser.add_argument('--browser',default='chromium',choices=['chromium','firefox']);parser.add_argument('--executable');args=parser.parse_args()
    with sync_playwright() as p:
        opts={'executable_path':args.executable} if args.executable else {}
        if args.browser=='chromium':opts['args']=['--no-sandbox']
        browser=getattr(p,args.browser).launch(**opts);page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content('<div id="notice"></div><div id="app"></div>');page.evaluate(MOCK);page.add_script_tag(content='(()=>{'+SCRIPT+'})()');page.evaluate('window.openSanctions(true)')
        expect(page.get_by_role('heading',name='Назначить предупреждение или санкцию')).to_be_visible()
        page.get_by_label('Вид').select_option('suspension');page.get_by_label('Причина').fill('<script>window.unsafe=true</script>');page.get_by_role('button',name='Назначить',exact=True).click();expect(page.get_by_role('heading',name='Блокировка аккаунта')).to_be_visible();assert page.evaluate('window.requests[0].body.kind')=='suspension' and page.evaluate('window.requests[0].csrf')=='test-csrf';assert page.evaluate('window.unsafe') is None
        page.evaluate('window.openSanctions(false)');expect(page.get_by_text('Аккаунт заблокирован.',exact=False)).to_be_visible();page.get_by_role('button',name='Подать апелляцию').click();page.get_by_label('Причина').fill('Review my restriction');page.get_by_role('button',name='Отправить апелляцию').click();expect(page.get_by_text('Апелляция: Ожидает',exact=False)).to_be_visible()
        page.evaluate('window.openAppeals()');expect(page.get_by_text('Review my restriction')).to_be_visible();page.get_by_role('button',name='Принять и отменить санкцию').click();page.get_by_label('Причина').fill('Accepted after review');page.get_by_role('button',name='Принять и отменить санкцию').last.click();expect(page.get_by_text('Нет ожидающих апелляций.')).to_be_visible();assert page.evaluate('window.requests.at(-1).body.status')=='accepted' and not page.evaluate('window.sanction.active');assert not errors,errors
        browser.close();print('PASS sanction UI: issue, safe reason display, appeal and decision')
if __name__=='__main__':main()
