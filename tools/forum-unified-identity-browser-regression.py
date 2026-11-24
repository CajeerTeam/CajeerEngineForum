#!/usr/bin/env python3
"""Drive the shipped production Identity Center admin UI with actual Chromium events."""
import argparse
import os
import json
import shutil
from pathlib import Path
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parents[1]
script = (root / 'public/admin/assets/admin-app.js').read_text()
uid = '11111111-1111-4111-8111-111111111111'
other = '22222222-2222-4222-8222-222222222222'
parser=argparse.ArgumentParser();parser.add_argument('--browser',choices=['chromium','firefox'],default='chromium');args=parser.parse_args()
with sync_playwright() as p:
    browser = getattr(p,args.browser).launch(headless=True,**({'executable_path':shutil.which('chromium'),'args':['--no-sandbox']} if args.browser=='chromium' and shutil.which('chromium') else {}))
    page = browser.new_page();page.set_default_timeout(8000)
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.set_content('<div id="app"></div>')
    page.evaluate('''([uid, other]) => {
      const local = new Map();
      Object.defineProperty(window,'localStorage',{value:{getItem:k=>local.get(k)||null,setItem:(k,v)=>local.set(k,v),removeItem:k=>local.delete(k)}});
      Object.defineProperty(window,'sessionStorage',{value:{getItem:k=>local.get(k)||null,setItem:(k,v)=>local.set(k,v),removeItem:k=>local.delete(k)}});
      location.hash='#/identity';window.calls=[];window.confirms=[];window.stepup=false;
      window.confirm = text => { confirms.push(text);return true };
      window.prompt = text => text.includes('TOTP') ? '' : 'CorrectPassword123!';
      const users=[
        {id:uid,email:'target@identity.test',name:'Target',status:'active',linked:false,subject:null,provisioned:false,linked_at:null},
        {id:other,email:'other@identity.test',name:'Other',status:'active',linked:true,subject:'linked-other',provisioned:false,linked_at:'2026-11-18'}
      ];
      window.userRecords=users;window.roles=['viewer'];window.delegation=[];window.group='g1';window.scopes=['*'];
      const role=(handle,managed=false)=>({id:handle,handle,name:handle,managed,permissions:handle==='viewer'?['system:read']:['content:write']});
      const access=()=>({user_id:uid,status:'active',local_mfa:true,suspended:false,protected:false,revision:'a'.repeat(64),roles:[...window.roles.map(h=>role(h)),role('forum-member',true),...window.delegation.map(s=>role('forum-acp-'+s.split(':').pop(),true))],available_roles:[role('viewer'),role('editor')],effective_scopes:['forum:write',...window.roles]});
      window.fetch=async(url,opt={})=>{
        const full=String(url),sub=full.split('/api/v1')[1]||'', path=sub.split('?')[0],method=opt.method||'GET';
        calls.push({path,method,body:opt.body,csrf:opt.headers?.get?.('X-CSRF-Token'),credentials:opt.credentials});
        const respond=(data,status=200)=>new Response(JSON.stringify(status>=400?{error:{code:data,message:data}}:{data}),{status,headers:{'Content-Type':'application/json'}});
        if(path==='/auth/oidc/status')return respond({enabled:true});
        if(path==='/auth/me')return respond({user_id:'99999999-9999-4999-8999-999999999999',email:'admin@test',scopes:window.scopes,csrf_token:'csrf-xyz',cookie:true,session:true});
        if(path==='/identity/account')return respond({enabled:true,linked:false});
        if(path==='/auth/sessions')return respond([{id:'id-local',provider:'local',current:true,device:'Chromium',network:'masked',last_seen_at:'2026-11-18'}]);
        if(path==='/identity/admin/users'&&method==='GET'){
          const query=new URL(full,'https://cms.test').searchParams;
          const needle=(query.get('q')||'').toLowerCase();let filtered=users.filter(u=>u.email.includes(needle));
          if(query.get('linked')==='yes')filtered=filtered.filter(u=>u.linked);
          if(query.get('linked')==='no')filtered=filtered.filter(u=>!u.linked);
          return respond({items:filtered,total:filtered.length,limit:30,offset:0});
        }
        if(path.endsWith('/sessions')&&method==='GET')return respond([{id:'33333333-3333-4333-8333-333333333333',provider:'skif4er',device:'Firefox',network:'masked',last_seen_at:'2026-11-18'},{id:'44444444-4444-4444-8444-444444444444',provider:'local',device:'Chromium Local',last_seen_at:'2026-11-18'}]);
        if(path.endsWith('/access')&&method==='GET')return respond(access());
        if(path.endsWith('/access')&&method==='PUT'){const data=JSON.parse(opt.body);if(data.confirm_user_id!==uid||data.revision!=='a'.repeat(64)||opt.headers.get('X-CSRF-Token')!=='csrf-xyz')return respond('forbidden',403);window.roles=data.roles;return respond(access());}
        if(path.startsWith('/forum/acp/delegation/')&&method==='PUT'){window.delegation=JSON.parse(opt.body).scopes;return respond({scopes:window.delegation});}
        if(path==='/forum/permission-inspector')return respond({effective:{'thread:read':true,'thread:write':false},trace:{steps:[]},publication_state:'active'});
        if(path==='/forum/groups')return respond([{id:'g1',name:'Members'},{id:'g2',name:'Staff'}]);
        if(path.endsWith('/groups')&&method==='GET')return respond({primary_group_id:window.group,secondary_group_ids:[]});
        if(path.endsWith('/groups')&&method==='PUT'){window.group=JSON.parse(opt.body).primary_group_id;return respond({});}
        if(path.startsWith('/forum/acp/members/')&&method==='PATCH'){Object.assign(users[0],JSON.parse(opt.body));return respond(users[0]);}
        if(path==='/auth/assurance/confirm'){stepup=true;return respond({fresh:true,expires_at:'2026-11-18T12:00:00Z'});}
        if(method!=='GET' && path.startsWith('/identity/admin/users/') && !stepup) return respond('step_up_required',403);
        if(method==='POST'&&path.endsWith('/link')){const body=JSON.parse(opt.body);if(body.subject!==body.confirm_subject)return respond('confirmation_required',422);
          users[0].linked=true;users[0].subject=body.subject;return respond({linked:true});}
        if(method==='DELETE'&&path.endsWith('/link')){users[0].linked=false;users[0].subject=null;return respond({unlinked:true,revoked_sessions:1});}
        if(method==='DELETE'&&path.includes('/sessions/'))return respond({revoked:true});
        if(method==='POST'&&path.endsWith('/sessions/revoke-all'))return respond({revoked_sessions:1});
        return respond([]);
      }
    }''', [uid,other])
    page.add_script_tag(content=script)
    page.locator('[data-identity-admin-filter]').wait_for()
    assert page.get_by_text('Identity Center · управление').count()==1
    assert page.locator('[data-identity-select]').count()==2
    print('PASS shipped SPA lists and filters actual local Identity mappings')
    page.locator('[data-identity-admin-filter] input[name=q]').fill('target@identity.test')
    page.locator('[data-identity-admin-filter] button[type=submit]').click()
    page.wait_for_function("document.querySelectorAll('[data-identity-select]').length===1")
    page.locator('[data-identity-select]').click()
    page.locator('[data-identity-admin-link] input[name=subject]').fill('verified-external-sub')
    page.locator('[data-identity-admin-link] input[name=confirm_subject]').fill('verified-external-sub')
    page.locator('[data-identity-admin-link] button[type=submit]').click()
    page.wait_for_function("window.userRecords[0].linked === true")
    page.locator('[data-identity-admin-unlink]').wait_for()
    calls=page.evaluate('calls')
    assert len([c for c in calls if c['path'].endswith('/link') and c['method']=='POST'])==2,calls
    assert len([c for c in calls if c['path']=='/auth/assurance/confirm'])==1,calls
    assert all(c['csrf']=='csrf-xyz' and c['credentials']=='same-origin' for c in calls if c['method']=='POST' and c['path'].endswith('/link'))
    assert page.evaluate('confirms.length')>0
    assert not errors,errors
    print('PASS exact-sub prelink requires confirmation, CSRF, step-up and retries only once')
    page.locator('[data-identity-admin-unlink]').click()
    page.wait_for_function("window.userRecords[0].linked === false")
    calls=page.evaluate('calls')
    assert any(c['path'].endswith('/link') and c['method']=='DELETE' and json.loads(c['body'])['confirm_user_id']==uid for c in calls)
    print('PASS unlink calls real backend operation with explicit target confirmation')
    page.locator('[data-identity-session-revoke]').first.wait_for()
    page.locator('[data-identity-session-revoke]').first.click()
    page.wait_for_function("window.calls.some(c => c.method === 'DELETE' && c.path.endsWith('/sessions/33333333-3333-4333-8333-333333333333'))")
    print('PASS session revocation targets exact user and session')
    page.locator('[data-unified-access-roles]').wait_for()
    assert page.get_by_text('Chromium Local',exact=False).count()>0
    assert page.get_by_text('Локальная MFA: включена.',exact=False).count()==1
    print('PASS local and SSO sessions plus local MFA visible')
    page.locator('[data-unified-access-roles] input[value=viewer]').uncheck()
    page.locator('[data-unified-access-roles] input[value=editor]').check()
    page.locator('[data-unified-access-roles] button').click()
    page.wait_for_function("roles.join(',')==='editor'")
    page.get_by_text('Системные роли сохранены.',exact=True).wait_for()
    page.locator('[data-unified-access-roles] input[value=editor]:checked').wait_for()
    print('PASS system role update includes exact target, revision and CSRF')
    page.locator('[data-unified-access-acl] input[name=node]').fill('55555555-5555-4555-8555-555555555555')
    page.locator('[data-unified-access-acl] button').click()
    page.locator('[data-unified-access-result]').get_by_text('thread:read',exact=False).wait_for()
    print('PASS live ACL inspection displayed')
    page.locator('[data-unified-access-delegation] input[value="forum:acp:settings"]').check()
    page.locator('[data-unified-access-delegation] button').click()
    page.wait_for_function("delegation.includes('forum:acp:settings')")
    page.get_by_text('Полномочия сохранены.',exact=True).wait_for()
    print('PASS delegation update routed to real ACP operation')
    page.locator('[data-unified-load-groups]').click()
    page.get_by_label('Основная группа').select_option('g2')
    page.get_by_role('button',name='Сохранить группы').click()
    page.wait_for_function("group==='g2'")
    page.get_by_text('Группы сохранены.',exact=True).wait_for()
    page.locator('[data-unified-load-groups]').wait_for()
    print('PASS group assignment uses existing group endpoint')
    page.locator('[data-unified-access-member] input[name=name]').fill('New name')
    page.locator('[data-unified-access-member] button').click()
    page.wait_for_function("userRecords[0].name==='New name'")
    print('PASS account update uses ACP member endpoint')
    assert not errors,errors
    browser.close()
