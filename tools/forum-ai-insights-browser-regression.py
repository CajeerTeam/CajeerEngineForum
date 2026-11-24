#!/usr/bin/env python3
"""Exercise the deployed ACP module, explicitly approved provider call and evidence navigation."""
import argparse,shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
src=(root/'admin/static/assets/admin-app.js').read_text()
start=src.index('const forumAcp = (() => {')
end=src.index('\n})()',start)+len('\n})()')
module=src[start:end]+'\nwindow.__dashboard=forumAcp;'
mock=r'''
window.dataOf=x=>x;
window.calls=[];window.allowed=true;
window.api=async(path,options={})=>{
 const u=new URL('https://localhost.invalid'+path),view=u.searchParams.get('view');
 window.calls.push({path,method:options.method||'GET',body:options.body?JSON.parse(options.body):null});
 if(view==='access')return {allowed:true,capabilities:{admin:false,settings:window.allowed,maintenance:false}};
 if(view==='analytics_dashboard'){
  const from=u.searchParams.get('from'),until=u.searchParams.get('until');
  const length=Math.round((new Date(until+'T00:00:00Z')-new Date(from+'T00:00:00Z'))/86400000)+1;
  const daily=Array.from({length},(_,i)=>({day:new Date(Date.parse(from+'T00:00:00Z')+i*86400000).toISOString().slice(0,10),views:2,actions:1,total:3,events:{forum_thread_view:2,forum_thread_created:1}}));
  return {enabled:true,from,until,days:length,timezone:'UTC',daily,summary:{page_views:2*length,actions:length,total:3*length,previous_page_views:length,previous_actions:0,previous_total:length,page_views_change_pct:100,actions_change_pct:null,total_change_pct:200},comparison:{available:true,from,until},breakdown:[{event_name:'forum_thread_view',category:'view',total:2*length,previous:0,percent_change:null},{event_name:'forum_thread_created',category:'action',total:length,previous:0,percent_change:null}],pending_events:0,raw_retention_days:90,aggregate_retention_days:365,last_aggregated_at:0,last_recalculated_at:0};
 }
 if(path.includes('/acp/ai/insights')){
  const from=u.searchParams.get('from')||window.calls.at(-1).body.from;
  const until=u.searchParams.get('until')||window.calls.at(-1).body.until;
  const focus=u.searchParams.get('focus')||window.calls.at(-1).body.focus;
  const fact={id:'events.total',label:'Всего событий за период',value:90,unit:'событий'};
  const report={from,until,focus,days:30,methodology:'UTC · анонимные значения',facts:[fact]};
  if(options.method==='POST'){
   if(!window.calls.at(-1).body.acknowledge_external_processing)throw Error('No explicit consent');
   return {headline:'Рост <img src=x onerror=alert(1)>',observations:[{kind:'observation',text:'Активность растёт <script>alert(1)</script>',evidence:[fact]}],report,model:'gpt-4.1-mini',generated_at:1764000000,usage:{input_tokens:20,output_tokens:10}};
  }
  return report;
 }
 throw Error('Unexpected '+path);
};
'''
parser=argparse.ArgumentParser();parser.add_argument('--browser',choices=['chromium','firefox'],default='chromium');args=parser.parse_args()
count=0
def check(value,label):
 global count
 assert value,label
 count+=1
with sync_playwright() as p:
 type_=getattr(p,args.browser)
 browser=type_.launch(headless=True,**({'executable_path':'/usr/bin/chromium','args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}))
 page=browser.new_page(viewport={'width':1280,'height':900},accept_downloads=True)
 errs=[];page.on('pageerror',lambda e:errs.append(str(e)))
 page.set_content('<!doctype html><html lang="ru"><meta charset="utf-8"><body><div id="forum-acp"></div></body>')
 page.add_script_tag(content=mock+'\n'+module)
 page.evaluate('window.__dashboard.mount()')
 page.get_by_text('AI ACP Insights · объяснение статистики',exact=True).wait_for()
 check(page.get_by_text('Аналитическая панель форума',exact=True).count()==1,'existing dashboard intact')
 check(page.evaluate('window.calls.filter(c=>c.path.includes("/acp/ai/insights")).length')==0,'no provider or snapshot prefetch')
 page.get_by_role('button',name='Сгенерировать AI-объяснение').click()
 page.get_by_text('Сначала подтвердите передачу',exact=False).wait_for()
 check(page.evaluate('window.calls.filter(c=>c.path.includes("/acp/ai/insights")).length')==0,'consent is required')
 page.get_by_role('button',name='Показать проверяемые факты').click()
 page.get_by_text('Всего событий за период (events.total)').wait_for()
 check(page.evaluate('window.calls.filter(c=>c.path.includes("/acp/ai/insights")&&c.method==="GET").length')==1,'facts endpoint used')
 check(page.evaluate('window.calls.filter(c=>c.path.includes("/acp/ai/insights")&&c.method==="POST").length')==0,'facts without provider')
 page.locator('input[name=insight_ack]').check()
 page.get_by_role('button',name='Сгенерировать AI-объяснение').click()
 page.get_by_text('Рост <img src=x onerror=alert(1)>',exact=True).wait_for()
 check(page.evaluate('window.calls.filter(c=>c.path.includes("/acp/ai/insights")&&c.method==="POST").length')==1,'one explicit generate')
 check(page.locator('#forum-acp img').count()==0 and page.locator('#forum-acp script').count()==0,'model output is text, not HTML')
 check(page.get_by_text('Активность растёт <script>alert(1)</script>',exact=True).count()==1,'model explanation escaped')
 check(page.get_by_text('Всего событий за период: 90 событий',exact=False).count()==1,'authoritative evidence visible')
 with page.expect_download() as download:page.get_by_role('button',name='Экспорт AI-отчёта CSV').click()
 csv=Path(download.value.path()).read_bytes().decode('utf-8-sig')
 check('events.total' in csv and '90' in csv,'CSV evidence provenance')
 page.locator('select[name=native_focus]').select_option('forum_thread_view')
 page.get_by_role('button',name='Показать проверяемые факты').click()
 page.get_by_text('Всего событий за период (events.total)').wait_for()
 check('event%3Aforum_thread_view' in page.evaluate('window.calls.at(-1).path'),'event focus carried to server')
 page.evaluate('window.allowed=false;window.__dashboard.mount()')
 page.get_by_text('Недостаточно прав.').wait_for()
 check(page.get_by_text('AI ACP Insights · объяснение статистики',exact=True).count()==0,'role revocation clears report')
 check(not errs,'no JS errors: '+str(errs))
 browser.close()
print(f'Forum AI Insights {args.browser}: {count} PASS')
