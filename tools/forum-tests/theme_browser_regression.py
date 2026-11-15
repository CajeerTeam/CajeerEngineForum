#!/usr/bin/env python3
"""Production admin JS + actual PHP theme CSS and real browser, isolated API transport."""
import argparse, json, subprocess
from pathlib import Path
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import sync_playwright, expect
ROOT=Path(__file__).resolve().parents[2]
PHP=r'''require 'core/Forum/ForumException.php';require 'core/Forum/ForumTheme.php';require 'core/Forum/Service/ForumThemeService.php';$r=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$p=CajeerEngine\Forum\ForumTheme::presets();if($r['kind']==='presets')echo json_encode($p);elseif($r['kind']==='css')echo CajeerEngine\Forum\ForumTheme::css($r['design']);else echo json_encode(CajeerEngine\Forum\Service\ForumThemeService::transition($r['state'],$r['action'],$r['input']));'''
def php(data):return subprocess.check_output(['php','-r',PHP],input=json.dumps(data).encode(),cwd=ROOT).decode()
def main():
 ap=argparse.ArgumentParser();ap.add_argument('--browser',default='chromium');ap.add_argument('--executable');args=ap.parse_args()
 presets=json.loads(php({'kind':'presets'}));state={'revision':0,'active':presets['default'],'draft':presets['default'],'library':[],'history':[]};caps={'admin':True,'settings':True,'members':True,'audit':True,'maintenance':True};calls=[]
 with sync_playwright() as p:
  opts={'executable_path':args.executable} if args.executable else {}
  if args.browser=='chromium':opts['args']=['--no-sandbox']
  browser=getattr(p,args.browser).launch(**opts);page=browser.new_page(viewport={'width':1440,'height':900});errors=[];page.on('pageerror',lambda e:errors.append(str(e)));page.on('dialog',lambda d:d.accept())
  def api(path,method,data,csrf):
   nonlocal state
   u=urlparse(path);path=u.path;query=parse_qs(u.query)
   def response(data,status=200):return {'status':status,'body':{'data':data} if status==200 else {'error':{'message':data}}}
   if path=='/api/v1/auth/me':return response({'user_id':'admin','csrf_token':'csrf-test','scopes':['*']})
   if path=='/api/v1/forum/acp':
    v=query.get('view',['overview'])[0]
    if v=='access':return response({'allowed':True,'capabilities':caps})
    if v=='overview':return response({'counts':{'users':1,'boards':1,'threads':1}})
    if v=='themes':return response(state|{'presets':presets}) if caps['settings'] else response('Denied',403)
    if v=='users':return response({'items':[],'page':1,'has_more':False})
   if path=='/api/v1/forum/acp/theme' and method=='PUT':
    calls.append((data,csrf))
    if csrf!='csrf-test':return response('CSRF',403)
    if data['revision']!=state['revision']:return response('Conflict',409)
    try:state=json.loads(php({'kind':'transition','state':state,'action':data['action'],'input':data}))|{'revision':state['revision']+1}
    except subprocess.CalledProcessError:return response('Invalid design',422)
    return response(state|{'presets':presets})
   return response('Unexpected '+path,404)
  page.expose_function('themeTestApi',api)
  page.set_content('<div id="app"></div>')
  page.evaluate(r"""()=>{const store=new Map();Object.defineProperty(window,'localStorage',{value:{getItem:k=>store.get(k)||null,setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)}});location.hash='#/forum';window.confirm=()=>true;window.fetch=async(path,opts={})=>{const r=await window.themeTestApi(path,opts.method||'GET',opts.body?JSON.parse(opts.body):null,opts.headers?.get('X-CSRF-Token')||null);return new Response(JSON.stringify(r.body),{status:r.status})}}""")
  page.add_style_tag(content=(ROOT/'public/admin/assets/admin-app.css').read_text());page.add_script_tag(content=(ROOT/'public/admin/assets/admin-app.js').read_text())
  def mount_preview(scheme):
   # The isolated browser has no real PHP server. Assert the preview URL before
   # substituting a fixture rendered with the actual production template and CSS.
   v=state['draft'];css=php({'kind':'css','design':v});template=(ROOT/'public/forum-assets/index.html').read_text();base=(ROOT/'public/forum-assets/forum.css').read_text()
   replacements={'FORUM_THEME_SCHEME':scheme,'FORUM_THEME_SIDEBAR':v['sidebar'],'FORUM_THEME_LOGO':v['logo'],'FORUM_THEME_CSS':'<style>'+base+css+'</style>','FORUM_THEME_PREVIEW':'<p>Preview fixture</p>','FORUM_NAME':'Forum','FORUM_TITLE':'Forum','FORUM_HEADING':'Forum','FORUM_DESCRIPTION':'Test','FORUM_BODY':'<article class="post">Public message</article>','FORUM_RENDERED':'true','FORUM_REGISTRATION':'true','FORUM_MAINTENANCE':'','FORUM_SCRIPT':''}
   for k,value in replacements.items():template=template.replace('<!--'+k+'-->',value)
   page.locator('iframe').evaluate('(f,html)=>{f.removeAttribute("src");f.srcdoc=html}',template)
  page.get_by_role('button',name='Оформление форума',exact=True).click();expect(page.get_by_role('heading',name='Оформление форума')).to_be_visible()
  page.locator('[name=preset]').select_option('preset:ocean');page.locator('#forum-acp [name=name]').fill('My Ocean');page.locator('[name=logo]').fill('OC');page.locator('[name=sidebar]').select_option('left');page.locator('[name=width]').fill('1000');page.get_by_role('button',name='Сохранить черновик').click();expect(page.get_by_text('Активная тема: Cajeer Default · Ревизия 1')).to_be_visible()
  assert state['active']==presets['default'] and state['draft']['name']=='My Ocean'
  page.locator('[name=preview_scheme]').select_option('light');page.get_by_role('button',name='Обновить предпросмотр').click();expect(page.locator('iframe')).to_have_attribute('src','/forum?theme_preview=1&scheme=light&revision=1');mount_preview('light');frame=page.frame_locator('iframe');expect(frame.locator('html')).to_have_attribute('data-theme','light');expect(frame.locator('body')).to_have_css('background-color','rgb(238, 248, 248)');expect(frame.locator('.logo')).to_have_text('OC');expect(frame.locator('.wrap').first).to_have_css('max-width','1000px')
  page.get_by_role('button',name='Мобильный вид').click();expect(page.locator('iframe')).to_have_css('width','390px');expect(frame.locator('.layout > aside')).to_be_hidden();page.get_by_role('button',name='Широкий вид').click()
  page.get_by_role('button',name='Активировать сохранённый черновик').click();expect(page.get_by_text('Активная тема: My Ocean · Ревизия 2')).to_be_visible();assert state['active']['name']=='My Ocean'
  page.get_by_role('button',name='Восстановить',exact=True).click();expect(page.get_by_text('Активная тема: Cajeer Default · Ревизия 3')).to_be_visible();assert state['active']==presets['default']
  assert all(csrf=='csrf-test' for _,csrf in calls) and [x['action'] for x,_ in calls]==['draft','activate','restore']
  caps.update(admin=False,settings=False,members=True,audit=False,maintenance=False);page.get_by_role('button',name='Обновить',exact=True).click();expect(page.get_by_role('button',name='Оформление форума',exact=True)).to_have_count(0)
  assert not errors,errors;browser.close();print('PASS '+args.browser+' theme editor, PHP CSS preview, mobile layout, draft isolation, activation, restore, CSRF and delegated visibility (isolated transport)')
if __name__=='__main__':main()
