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
          location.hash='#/forum';window.confirm=()=>true;window.allowed=true;window.calls=[];
          const categories=[{id:CAT,name:'Original',description:'',position:0}],user={id:USER,name:'<img src=x onerror=alert(1)>',email:'user@example.test',status:'active'};
          window.fetch=async(url,opts={})=>{const u=new URL(url,'https://forum.test'),path=u.pathname,method=opts.method||'GET',q=u.searchParams,csrf=opts.headers?.get('X-CSRF-Token'),body=opts.body?JSON.parse(opts.body):null;window.calls.push({method,path,csrf});let data;
            const response=(data,status=200)=>new Response(JSON.stringify(status===200?{data}:{error:{message:data}}),{status});
            if(path==='/api/v1/auth/me')data={user_id:ADMIN,csrf_token:'csrf-test',scopes:['*']};
            else if(path==='/api/v1/forum/acp'){if(!window.allowed)return response('Недостаточно прав.',403);const v=q.get('view');if(v==='overview')data={counts:{users:1,threads:0,boards:0}};else if(v==='access')data={allowed:true};else if(v==='structure')data={categories,boards:[]};else if(v==='users')data={items:[user],page:Number(q.get('page')||1),has_more:q.get('page')==='1'};else data={items:[],page:1,has_more:false};}
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
        page.evaluate('window.allowed=false');page.get_by_role('button',name='Участники',exact=True).click();expect(page.get_by_text('Недостаточно прав.')).to_be_visible();assert page.get_by_text('user@example.test').count()==0
        assert not errors,errors;assert page.evaluate('(id)=>window.calls.some(x=>x.method==="PATCH"&&x.path.endsWith(id)&&x.csrf==="csrf-test")',USER)
        browser.close();print('PASS '+args.browser+' ACP dashboard, category creation, ACL inspection, pagination, member write, CSRF, escaping, denied data')
if __name__=='__main__':main()
