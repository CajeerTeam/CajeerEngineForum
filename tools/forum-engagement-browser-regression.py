#!/usr/bin/env python3
"""Real 1.2.11 ACP bundle against a deterministic analytics API transport."""
import argparse
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1]
source=(root/'admin/static/assets/admin-app.js').read_text()
module=source[source.index('const forumAcp = (() => {'):]
module=module[:module.index('\n})()')+len('\n})()')]+'\nwindow.__dashboard=forumAcp;'
prior=(root/'tools/forum-analytics-dashboard-browser-regression.py').read_text()
mock=prior.split("mock=r'''",1)[1].split("'''",1)[0]
marker=" if(path==='/forum/acp/maintenance')"
assert marker in mock
mock=mock.replace(marker,""" if(view==='engagement'){
  const days=params.get('days');if(!['7','30','90'].includes(days))throw Error('Bad engagement days');
  return {timezone:'UTC',from:'2026-11-17',until:'2026-11-23',days:Number(days),cohort_from:'2026-08-26',
  summary:{contributors:5,contributions:9,topics:2,replies:7,active_member_days:9,current_week_contributors:5,returning_week_contributors:1,contributions_per_contributor:1.8},
  daily:[{date:'2026-11-22',contributors:4,contributions:4,topics:1,replies:3}],
  retention:{d1:{eligible:null,returned:null,percent:null},d7:{eligible:6,returned:4,percent:66.7},d30:{eligible:null,returned:null,percent:null}},
  funnel:{eligible_registrations:null,first_contribution:null,second_contribution:null,first_rate:null,second_rate:null,repeat_rate:null,within_days:7},
  cohorts:[{date:'2026-11-21',registrations:null,suppressed:true,d1_rate:null,d7_rate:null,d30_rate:null},{date:'2026-10-20',registrations:5,suppressed:false,d1_rate:0,d7_rate:100,d30_rate:100}],
  method:'registered_forum_members_and_retained_authored_posts'};
 }
"""+marker)
parser=argparse.ArgumentParser();parser.add_argument('--browser',choices=['chromium','firefox'],default='chromium');args=parser.parse_args()
checks=0
def ok(test,label):
 global checks
 assert test,label
 checks+=1
with sync_playwright() as p:
 bt=getattr(p,args.browser)
 b=bt.launch(**({'executable_path':'/usr/bin/chromium','args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}),headless=True)
 page=b.new_page(viewport={'width':1180,'height':890})
 errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
 page.set_content('<!doctype html><html lang="ru"><head><meta charset="utf-8"></head><body><div id="forum-acp"></div></body></html>')
 page.add_style_tag(content=(root/'admin/static/assets/admin-app.css').read_text())
 page.add_script_tag(content=mock+'\n'+module)
 page.evaluate('window.__dashboard.mount()')
 page.get_by_text('Вовлечённость, удержание и воронка участников',exact=True).wait_for()
 ok(page.locator('select[name="engagement_metric"]').count()==1,'engagement chart focus control')
 page.locator('select[name="engagement_metric"]').select_option('topics')
 ok('Новые темы' in page.locator('svg[aria-label^="Активность авторов:"]').get_attribute('aria-label'),'chart focus changes actual series')
 with page.expect_download() as download:
  page.get_by_role('button',name='Скачать CSV активности').click()
 item=download.value
 ok(item.suggested_filename=='forum-engagement-2026-11-17_2026-11-23.csv','actual CSV download')
 csv=Path(item.path()).read_bytes().decode('utf-8-sig')
 ok('2026-11-22' in csv and 'contributors' in csv and 'author_id' not in csv,'CSV contains anonymous daily counts only')
 ok(page.get_by_text('Воронка регистрации → публикации за 7 дней').count()==1,'activation funnel')
 ok(page.get_by_text('Когорты регистрации · публикация ровно на день D+N').count()==1,'retention cohorts')
 ok(page.get_by_text('Авторская активность по дням').count()==1,'daily contributors')
 ok(page.get_by_text('Когорты регистраций · последние 90 дней').count()==1,'90-day cohort detail')
 ok('66.7%' in page.get_by_text('Когорты регистрации · публикация ровно на день D+N').locator('..').inner_text(),'D7 retention percent')
 ok('—' in page.get_by_text('Воронка регистрации → публикации за 7 дней').locator('..').inner_text(),'small funnel suppressed in ACP')
 ok(page.get_by_text('Аналитическая панель форума',exact=True).count()==1,'previous dashboard preserved')
 ok(page.get_by_text('Динамика событий').count()==1,'original chart preserved')
 ok(page.get_by_text('Вторая публикация',exact=True).count()==1,'second publication stage visible')
 ok('—' in page.get_by_text('Когорты регистраций · последние 90 дней').locator('..').inner_text(),'unmatured rates unavailable')
 ok('<5' in page.get_by_text('Когорты регистраций · последние 90 дней').locator('..').inner_text(),'suppressed registration is not rendered as exact count or zero')
 ok('<5' in page.get_by_text('Когорты регистраций · последние 90 дней').locator('..').inner_text(),'suppressed registration is not rendered as exact count or zero')
 page.locator('select[name="engagement_days"]').select_option('90')
 page.get_by_text('Вовлечённость, удержание и воронка участников',exact=True).wait_for()
 ok(any('view=engagement&days=90' in c for c in page.evaluate('window.apiCalls')),'90-day filter reaches API')
 page.evaluate('window.mockCaps={admin:false,settings:false,maintenance:false};window.__dashboard.mount()')
 page.get_by_text('Недостаточно прав.').wait_for()
 ok(page.get_by_text('Вовлечённость, удержание и воронка участников').count()==0,'role revoked hides engagement')
 ok(not errors,'no uncaught JS error: '+str(errors))
 b.close()
print('PASS',args.browser,'ACP engagement:',checks,'checks')
