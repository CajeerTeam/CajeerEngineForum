#!/usr/bin/env python3
"""Chromium UI acceptance against the real Forum ACP module and mocked API transport.
Production SQL semantics are independently tested by the PHP/SQLite suite.
"""
from pathlib import Path
import argparse
import shutil
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
src=(root/'admin/static/assets/admin-app.js').read_text()
start=src.index('const forumAcp = (() => {')
end=src.index('\n})()',start)+len('\n})()')
module=src[start:end]+'\nwindow.__dashboard=forumAcp;'
mock=r'''window.mockCaps={admin:false,settings:true,maintenance:true};
window.apiCalls=[];
window.dataOf=x=>x;
window.api=async(path,options={})=>{
 window.apiCalls.push(path);
 const params=new URL('https://local.invalid'+path).searchParams;
 const view=params.get('view');
 if(view==='access')return {capabilities:window.mockCaps,allowed:true};
 if(view==='analytics_dashboard'){
  const from=params.get('from'),until=params.get('until');
  const a=new Date(from+'T00:00:00Z'),b=new Date(until+'T00:00:00Z');
  const days=Math.round((b-a)/86400000)+1;
  if(days<1||days>365)throw Error('Некорректные даты');
  const rows=[];
  for(let i=0;i<days;i++){
   const day=new Date(+a+i*86400000).toISOString().slice(0,10),views=i===days-1?2:0,actions=i===days-1?3:0;
   rows.push({day,views,actions,total:views+actions,events:i===days-1?{forum_thread_view:2,forum_thread_created:3}:{}});
  }
  const compare=days<=30;
  return {enabled:true,from,until,days,timezone:'UTC',daily:rows,summary:{page_views:2,actions:3,total:5,previous_page_views:compare?1:null,previous_actions:compare?1:null,previous_total:compare?2:null,page_views_change_pct:compare?100:null,actions_change_pct:compare?200:null,total_change_pct:compare?150:null},comparison:{available:compare,from:compare?'2026-10-01':null,until:compare?'2026-10-31':null},breakdown:[{event_name:'forum_thread_created',category:'action',total:3,previous:1,percent_change:200},{event_name:'forum_thread_view',category:'view',total:2,previous:1,percent_change:100}],pending_events:1,raw_retention_days:90,aggregate_retention_days:365,last_aggregated_at:0,last_recalculated_at:0};
 }
 if(path==='/forum/acp/maintenance')return {aggregated:1};
 throw Error('Unexpected API request '+path);
};'''
parser=argparse.ArgumentParser()
parser.add_argument('--browser',choices=['chromium','firefox'],default='chromium')
args=parser.parse_args()
checks=0
def ok(cond,label):
 global checks
 assert cond,label
 checks+=1
with sync_playwright() as p:
 browser_type=getattr(p,args.browser)
 browser=browser_type.launch(**({'executable_path':'/usr/bin/chromium','args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}),headless=True)
 page=browser.new_page(accept_downloads=True,viewport={'width':1200,'height':900})
 errors=[]
 page.on('pageerror',lambda exc:errors.append(str(exc)))
 page.set_content('<!doctype html><html lang="ru"><head><meta charset="utf-8"></head><body><div id="forum-acp"></div></body></html>')
 page.add_style_tag(content=(root/'admin/static/assets/admin-app.css').read_text())
 page.add_script_tag(content=mock+'\n'+module)
 page.evaluate('window.__dashboard.mount()')
 page.get_by_text('Аналитическая панель форума',exact=True).wait_for()
 ok(page.locator('.native-kpi').count()==3,'three dashboard KPIs')
 ok(page.locator('.native-chart-svg').count()==1,'SVG chart rendered')
 ok(page.locator('.native-chart-path').count()==3,'three overview trend lines')
 ok('150%' in page.locator('.native-kpis').inner_text(),'prior period delta')
 ok(page.get_by_text('Активность по дням недели (UTC)').count()==1,'weekday reporting')
 ok(page.get_by_text('Детализация событий').count()==1,'event breakdown')
 ok(page.locator('details').count()==1,'daily table expandable')
 page.locator('select[name="native_focus"]').select_option('views')
 ok(page.locator('.native-chart-path').count()==1,'focus chart on views')
 page.get_by_role('button',name='На график').first.click()
 ok(page.locator('select[name="native_focus"]').input_value()=='forum_thread_created','click event to chart')
 page.locator('select[name="native_period"]').select_option('365')
 page.get_by_text('Предыдущий период полностью вышел').wait_for()
 ok(page.locator('.native-chart-path').count()==1,'365-day selected-event chart renders')
 ok(page.get_by_text('Нет полного периода для сравнения').count()==3,'missing prior data not fabricated')
 page.locator('select[name="native_period"]').select_option('custom')
 page.locator('.native-range input[name="from"]').fill('2026-11-20')
 page.locator('.native-range input[name="until"]').fill('2026-11-23')
 page.get_by_role('button',name='Применить').click()
 page.locator('.native-kpis').wait_for()
 ok(any('from=2026-11-20&until=2026-11-23' in c for c in page.evaluate('window.apiCalls')),'custom date request reaches API')
 with page.expect_download() as download:
  page.get_by_role('button',name='Скачать CSV').click()
 item=download.value
 ok(item.suggested_filename=='forum-analytics-2026-11-20_2026-11-23.csv','CSV export filename and action')
 content=Path(item.path()).read_bytes().decode('utf-8-sig')
 ok('forum_thread_view' in content and '2026-11-23' in content,'CSV has anonymous aggregates')
 page.evaluate('window.mockCaps={admin:false,settings:true,maintenance:false};window.__dashboard.mount()')
 page.locator('.native-kpis').wait_for()
 ok(page.get_by_role('button',name='Агрегировать до 1000').count()==0,'maintenance controls hidden without permission')
 page.evaluate("window.mockCaps={admin:false,settings:false,maintenance:false};window.__dashboard.mount()")
 page.get_by_text('Недостаточно прав.').wait_for()
 ok(page.locator('.native-kpi').count()==0,'reports not retained after authorization loss')
 ok(not errors,'no browser JavaScript exceptions: '+str(errors))
 browser.close()
print('PASS',args.browser,'ACP analytics dashboard:',checks,'checks')
