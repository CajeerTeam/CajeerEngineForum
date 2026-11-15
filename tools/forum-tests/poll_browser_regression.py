#!/usr/bin/env python3
"""Real browser UI with isolated API transport; full HTTP lifecycle lives in run.py."""
import argparse
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
ROOT=Path(__file__).resolve().parents[2]
SCRIPT=(ROOT/'public/forum-assets/forum.js').read_text().rsplit('run(init);',1)[0]
# Stub only navigation/reload at the test boundary, not the poll UI or serialization.
SCRIPT=SCRIPT.replace('const go=url=>{location.href=url}', 'const go=url=>{window.destination=url}').replace('async function thread(id){','async function realThread(id){',1)
SCRIPT+='''\nasync function thread(id){window.reloaded=id};me={user_id:'owner',scopes:['forum:write'],csrf_token:'test'};window.composer=()=>app.replaceChildren(topicComposer('board'));window.pollDemo=d=>app.replaceChildren(pollPanel(d,'topic'));window.answerDemo=(d,p)=>app.replaceChildren(answerActions(d,p,'topic'));'''

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--browser',default='chromium',choices=['chromium','firefox']);ap.add_argument('--executable');args=ap.parse_args()
    with sync_playwright() as p:
        options={'executable_path':args.executable} if args.executable else {}
        if args.browser=='chromium':options['args']=['--no-sandbox']
        browser=getattr(p,args.browser).launch(**options);page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content('<div id="notice"></div><div id="app"></div>');page.evaluate('()=>{window.fetch=async (url,opts)=>{window.last={url,method:opts.method,data:JSON.parse(opts.body||"null"),csrf:opts.headers["X-CSRF-Token"]};if(url.includes("/drafts?")&&opts.method==="GET")return {ok:true,json:async()=>({data:[]})};if(url.includes("/drafts/")&&opts.method==="PUT"){window.saved=window.last.data.payload;return {ok:true,json:async()=>({data:{revision_number:1}})}}return {ok:true,json:async()=>({data:{id:"new"}})}}}');page.add_script_tag(content='(()=>{'+SCRIPT+'})()')
        page.evaluate('window.composer()');page.locator('[name=kind]').select_option('poll');page.locator('[name=title]').fill('A poll');page.locator('[name=body]').fill('Please vote');page.locator('fieldset input').nth(0).fill('Which one?');page.locator('fieldset textarea').fill('One\nTwo\nThree');page.locator('fieldset input[type=number]').fill('2');page.locator('[name=poll_visibility]').select_option('closed');page.get_by_role('button',name='Создать тему',exact=True).click();page.wait_for_function('window.destination==="/forum/threads/new"');data=page.evaluate('window.saved');assert data['kind']=='poll' and data['poll']['options']==['One','Two','Three'] and data['poll']['max_choices']==2 and data['poll']['visibility']=='closed' and page.evaluate('window.last.csrf')=='test'
        page.evaluate('window.composer()');page.locator('[name=kind]').select_option('question');page.locator('[name=title]').fill('A question');page.locator('[name=body]').fill('How?');page.get_by_role('button',name='Создать тему',exact=True).click();page.wait_for_function('window.saved.kind==="question"');assert 'poll' not in page.evaluate('window.saved')
        d={'board':{'effective_state':'open','permissions':{'post:reply':True}},'thread':{'locked':0,'author_id':'owner'},'interaction':{'kind':'poll','poll':{'question':'Which one?','closed':False,'max_choices':2,'allow_change':True,'my_choices':[],'options':[{'option_no':1,'label':'One'},{'option_no':2,'label':'Two'}],'results_visible':False,'voters':None}}}
        page.evaluate('(d)=>window.pollDemo(d)',d);expect(page.get_by_text('Результаты пока скрыты.')).to_be_visible();assert page.locator('progress').count()==0;page.get_by_role('checkbox',name='One').check();page.get_by_role('checkbox',name='Two').check();page.get_by_role('button',name='Проголосовать',exact=True).click();page.wait_for_function('window.last.url.endsWith("/poll/vote")');assert page.evaluate('window.last.data.choices')==[1,2]
        d['interaction']['poll'].update({'closed':True,'results_visible':True,'voters':1,'my_choices':[1,2],'options':[{'option_no':1,'label':'One','votes':1,'percent':100},{'option_no':2,'label':'Two','votes':1,'percent':100}]});page.evaluate('(d)=>window.pollDemo(d)',d);expect(page.locator('progress')).to_have_count(2);assert page.get_by_role('button',name='Проголосовать',exact=True).count()==0
        d['interaction']={'kind':'question','question':{'accepted_post_id':None}};page.evaluate('(d)=>window.answerDemo(d,{id:"answer",author_id:"other",answer_vote:{score:1,voted:false}})',d);page.get_by_role('button',name='Принять ответ',exact=True).click();page.wait_for_function('window.last.url.endsWith("/accepted-answer")');assert page.evaluate('window.last.data.post_id')=='answer'
        d['interaction']['question']['accepted_post_id']='answer';page.evaluate('(d)=>window.answerDemo(d,{id:"answer",author_id:"other",answer_vote:{score:1,voted:true}})',d);expect(page.get_by_text('Принятый ответ',exact=True)).to_be_visible();page.get_by_role('button',name='Снять решение',exact=True).click();page.wait_for_function('window.last.data.post_id===null');assert not errors,errors
        browser.close();print('PASS real browser poll composer, multiple choice, hidden results, closed state, question and accept/clear controls')
if __name__=='__main__':main()
