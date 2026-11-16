#!/usr/bin/env python3
"""Exercise production Admin SPA with isolated HTTP transport, not a simulated UI."""
import argparse, json
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import sync_playwright, expect
ROOT=Path(__file__).resolve().parents[2]
CAT='11111111-1111-4111-8111-111111111111'; USER='22222222-2222-4222-8222-222222222222'; ADMIN='33333333-3333-4333-8333-333333333333'

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--browser',default='chromium');ap.add_argument('--executable');args=ap.parse_args()
    with sync_playwright() as p:
        opts={'executable_path':args.executable} if args.executable else {}
        if args.browser=='chromium':opts['args']=['--no-sandbox']
        browser=getattr(p,args.browser).launch(**opts);page=browser.new_page();errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        page.set_content('<div id="app"></div>')
        page.evaluate(r"""({CAT,USER,ADMIN})=>{
          const store=new Map();for(const name of ['localStorage','sessionStorage'])Object.defineProperty(window,name,{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});
          location.hash='#/forum';window.confirm=()=>true;window.allowed=true;window.calls=[];window.capabilities={admin:true,settings:true,members:true,audit:true,maintenance:true};window.policy={revision:0,values:{name:'Original forum',description:'Description',registration_enabled:true,registration_open:true,paused:false,trust_days:7,trust_posts:3,threads_hour:30,new_threads_hour:6,replies_10min:60,new_replies_10min:20,attachment_mb:5,attachment_quota_mb:100,pause_from:0,pause_until:0}};window.delegated=[];
          const categories=[{id:CAT,name:'Original',description:'',position:0}],user={id:USER,name:'<img src=x onerror=alert(1)>',email:'user@example.test',status:'active'};
          window.fetch=async(url,opts={})=>{const u=new URL(url,'https://forum.test'),path=u.pathname,method=opts.method||'GET',q=u.searchParams,csrf=opts.headers?.get('X-CSRF-Token'),body=opts.body?JSON.parse(opts.body):null;window.calls.push({method,path,csrf});let data;
            const response=(data,status=200)=>new Response(JSON.stringify(status===200?{data}:{error:{message:data}}),{status});
            if(path==='/api/v1/auth/me')data={user_id:ADMIN,csrf_token:'csrf-test',scopes:['*']};
            else if(path==='/api/v1/forum/acp'){if(!window.allowed)return response('Недостаточно прав.',403);const v=q.get('view');if(v==='overview')data={counts:{users:1,threads:0,boards:0}};else if(v==='access')data={allowed:true,capabilities:window.capabilities};else if(v==='settings')data=window.policy;else if(v==='delegation')data={user_id:USER,scopes:window.delegated};else if(v==='policy_history')data={items:[{created_at:'2026-01-01',event:'forum.acp.policy.update',actor_id:ADMIN,context:{before:{name:'Original forum'},after:{name:'Updated forum'}}}],page:1,has_more:false};else if(v==='imports')data={items:[{id:'a'.repeat(64),platform:'xenforo',source_version:'2.3.0',state:'complete',phase:11,cursor_id:0}],page:1,has_more:false};else if(v==='import_job')data={job:{id:'a'.repeat(64),backup_id:'test-backup',platform:'xenforo'},results:[{kind:'posts',outcome:'imported',count:2}],relation_rows:{watches:2}};else if(v==='structure')data={categories,boards:[]};else if(v==='users')data={items:[user],page:Number(q.get('page')||1),has_more:q.get('page')==='1'};else data={items:[],page:1,has_more:false};}
            else if(path==='/api/v1/forum/acp/policy'&&method==='PUT'){if(csrf!=='csrf-test')return response('csrf',403);if(body.revision!==window.policy.revision)return response('conflict',409);window.policy={revision:body.revision+1,values:{...body.values,registration_open:body.values.registration_enabled,paused:false}};data=window.policy;}
            else if(path==='/api/v1/forum/acp/delegation/'+USER&&method==='PUT'){if(csrf!=='csrf-test')return response('csrf',403);window.delegated=body.scopes;data={user_id:USER,scopes:body.scopes};}
            else if(path==='/api/v1/forum/acp/maintenance'&&method==='POST'){if(csrf!=='csrf-test')return response('csrf',403);data={removed:3};}
            else if(path==='/api/v1/forum/categories'&&method==='POST'){if(csrf!=='csrf-test')return response('csrf',403);categories.push({id:'44444444-4444-4444-8444-444444444444',position:1,...body});data=body;}
            else if(path==='/api/v1/forum/categories/'+CAT+'/permissions')data={visibility:'public',rules:[],revision:'a'.repeat(64)};
            else if(path==='/api/v1/forum/permission-inspector')data={effective:{'thread:read':true}};
            else if(path==='/api/v1/forum/groups')data=[{id:CAT,name:'Members',permissions:['forum:write'],system:true}];
            else if(path==='/api/v1/forum/members/'+USER+'/groups')data={primary_group_id:CAT,secondary_group_ids:[]};
            else if(path==='/api/v1/forum/acp/members/'+USER&&method==='PATCH'){if(csrf!=='csrf-test')return response('csrf',403);Object.assign(user,body);data=user;}
            else if(path==='/api/v1/forum/moderation/queue')data=[];
            else return response('unexpected '+path,404);return response(data);
          };
        }""",{'CAT':CAT,'USER':USER,'ADMIN':ADMIN})
        page.add_script_tag(content=(ROOT/'public/admin/assets/admin-app.js').read_text())
        expect(page.get_by_role('heading',name='Участники',exact=True)).to_be_visible()
        page.get_by_role('button',name='Структура и ACL').click();expect(page.get_by_role('heading',name='Создать категорию')).to_be_visible()
        page.get_by_role('button',name='Original').click();expect(page.get_by_role('heading',name='Разрешения')).to_be_visible();page.get_by_role('button',name='Проверить эффективные права').click();expect(page.get_by_text('"thread:read": true',exact=False)).to_be_visible()
        create=page.locator('section.card').filter(has=page.get_by_role('heading',name='Создать категорию',exact=True));create.locator('[name=name]').fill('New category');create.get_by_role('button',name='Создать',exact=True).click();expect(page.get_by_role('button',name='New category')).to_be_visible()
        page.get_by_role('button',name='Участники',exact=True).click();expect(page.get_by_text('<img src=x onerror=alert(1)>',exact=True)).to_be_visible();assert page.locator('#forum-acp img').count()==0
        page.get_by_role('button',name='Далее').click();expect(page.get_by_text('Страница 2')).to_be_visible();page.get_by_role('button',name='Управлять').click();expect(page.get_by_role('button',name='Назначить группы')).to_be_visible();page.locator('#forum-acp input[name=name]').fill('Updated');page.get_by_role('button',name='Сохранить участника').click();expect(page.get_by_text('Updated',exact=True)).to_be_visible()
        page.get_by_role('button',name='Модерация',exact=True).click();expect(page.get_by_role('heading',name='Очередь модерации')).to_be_visible()
        page.get_by_role('button',name='Настройки сообщества',exact=True).click();expect(page.get_by_role('heading',name='Настройки сообщества')).to_be_visible();page.locator('#forum-acp input[name=name]').fill('Updated forum');page.get_by_role('button',name='Сохранить политику').click();expect(page.get_by_text('Ревизия 1',exact=False)).to_be_visible();assert page.evaluate('window.policy.values.name')=='Updated forum'
        page.get_by_role('button',name='Делегирование',exact=True).click();page.locator('#forum-acp input[name=id]').fill(USER);page.get_by_role('button',name='Загрузить полномочия').click();page.locator('input[name="forum:acp:settings"]').check();page.get_by_role('button',name='Сохранить полномочия').click();expect(page.get_by_role('heading',name='Делегирование полномочий')).to_be_visible();assert page.evaluate('window.delegated')==['forum:acp:settings']
        page.get_by_role('button',name='История политики',exact=True).click();expect(page.get_by_text('forum.acp.policy.update',exact=False)).to_be_visible()
        page.get_by_role('button',name='Обслуживание',exact=True).click();page.get_by_role('button',name='Очистить истёкший антиспам').click();expect(page.get_by_text('"removed": 3',exact=False)).to_be_visible()
        page.get_by_role('button',name='Миграции',exact=True).click();expect(page.get_by_role('heading',name='Миграции форумов')).to_be_visible()
        page.locator('#forum-acp select[name=platform]').select_option('xenforo');page.locator('#forum-acp input[name=version]').fill('2.3.0');page.get_by_role('button',name='Подготовить CLI-команды').click();expect(page.get_by_text('--confirm=import-xenforo',exact=False)).to_be_visible()
        page.get_by_role('button',name='Отчёт',exact=True).click();expect(page.get_by_text('Backup: test-backup',exact=True)).to_be_visible()
        page.evaluate('window.capabilities={admin:false,settings:true,members:false,audit:false,maintenance:false}');page.get_by_role('button',name='Обновить',exact=True).click();expect(page.get_by_role('heading',name='Настройки сообщества')).to_be_visible();assert page.get_by_role('button',name='Миграции',exact=True).count()==0 and page.get_by_role('button',name='Делегирование',exact=True).count()==0 and page.locator('#forum-acp input[name=pause_from]').count()==0
        page.evaluate('window.capabilities={admin:true,settings:true,members:true,audit:true,maintenance:true}');page.get_by_role('button',name='Обновить',exact=True).click();expect(page.get_by_role('button',name='Участники',exact=True)).to_be_visible()
        page.evaluate('window.allowed=false');page.get_by_role('button',name='Участники',exact=True).click();expect(page.get_by_text('Недостаточно прав.')).to_be_visible();assert page.get_by_text('user@example.test').count()==0
        assert not errors,errors;assert page.evaluate('(id)=>window.calls.some(x=>x.method==="PATCH"&&x.path.endsWith(id)&&x.csrf==="csrf-test")',USER)
        browser.close();print('PASS '+args.browser+' ACP dashboard, category creation, ACL inspection, pagination, member write, policy, delegation, maintenance, delegated tabs, CSRF, escaping, denied data')
if __name__=='__main__':main()
