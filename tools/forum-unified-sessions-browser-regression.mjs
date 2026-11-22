// Execute actual Forum Edition session UI with a deterministic DOM and HTTP adapter.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const src=fs.readFileSync(new URL('../public/forum-assets/forum.js',import.meta.url),'utf8');
const start=src.indexOf('async function accountSessionsPage(){');
const end=src.indexOf('\nconst notificationKinds=',start);
assert(start>=0&&end>start,'live session page exists');
const code=src.slice(start,end);
const items=[
 {id:'11111111-1111-4111-8111-111111111111',current:true,provider:'local',device:'Firefox',network:'192.0.2.0/24',created_at:'2026-11-22',last_seen_at:'now',expires_at:'tomorrow'},
 {id:'22222222-2222-4222-8222-222222222222',current:false,provider:'skif4er',device:'Chrome on Android',network:'2001:db8::/48',created_at:'2026-11-21',last_seen_at:'today',expires_at:'tomorrow'},
 {id:'33333333-3333-4333-8333-333333333333',current:false,provider:'legacy',device:'Unknown browser',network:'',created_at:'2026-11-20',last_seen_at:'yesterday',expires_at:'tomorrow'},
];
let current=items.slice(),calls=[],redirects=[],alerts=[],approvals=true;
function el(tag,text='',cls=''){
 return {tag,text,cls,children:[],handlers:{},append(...children){this.children.push(...children)},replaceChildren(...children){this.children=children},addEventListener(type,cb){this.handlers[type]=cb}};
}
const app=el('main');
const me={session:true,cookie:true,csrf_token:'original-csrf'};
const ctx={
 app,me,encodeURIComponent,confirm:()=>approvals,
 node:el,panel:()=>el('section'),link:(text,url,cls)=>Object.assign(el('a',text,cls),{url}),
 add:(parent,...children)=>{parent.append(...children);return parent},
 button:(text,cb,cls)=>{const b=el('button',text,cls);b.handlers.click=async()=>{try{await cb()}catch(e){alerts.push(e.message)}};return b},
 heading:text=>app.append(el('h1',text)),go:url=>redirects.push(url),say:text=>alerts.push(text),
 api:async(path,method='GET',body)=>{
   calls.push({path,method,body,csrf:ctx.me?.csrf_token});
   if(path==='/auth/sessions'&&method==='GET')return current.slice();
   if(path==='/auth/sessions/revoke-others'&&method==='POST'){
    const count=current.filter(s=>!s.current).length;current=current.filter(s=>s.current);return {revoked_count:count};
   }
   if(path==='/auth/sessions/revoke-all'&&method==='POST'){
    const count=current.length;current=[];return {revoked_count:count};
   }
   if(path==='/auth/sessions/rotate'&&method==='POST'){
    current=current.map(s=>s.current?{...s,id:'44444444-4444-4444-8444-444444444444'}:s);
    return {csrf_token:'renewed-csrf',expires_at:'tomorrow'};
   }
   if(path.startsWith('/auth/sessions/')&&method==='DELETE'){
    const id=decodeURIComponent(path.split('/').at(-1)),found=current.find(s=>s.id===id);
    current=current.filter(s=>s.id!==id);return {revoked:!!found};
   }
   throw Error('Unknown request '+method+' '+path);
 }
};
vm.runInNewContext(code+'\nglobalThis.renderSessions=accountSessionsPage;',ctx);
const flatten=(e)=>[e,...(e.children||[]).flatMap(flatten)];
const click=async text=>{const b=flatten(app).find(e=>e.tag==='button'&&e.text===text);assert(b,`button ${text}`);await b.handlers.click()};
await ctx.renderSessions();
let view=flatten(app).map(e=>e.text).join(' | ');
assert(view.includes('SkiF4er ID')&&view.includes('Пароль форума')&&view.includes('Сессия до обновления'));
assert(view.includes('Это устройство')&&view.includes('Последняя активность: now'));
assert(!view.includes('undefined')&&!view.includes('token_secret'));
assert.equal(calls[0].path,'/auth/sessions');
console.log('PASS: real page lists local, SkiF4er ID and legacy sessions with live timestamps');
await click('Завершить сессию');
assert.equal(current.length,2);
assert(calls.some(c=>c.method==='DELETE'&&c.path.endsWith(items[1].id)));
console.log('PASS: remote SSO browser session revoked using shared API');
await click('Обновить защиту текущей сессии');
assert.equal(ctx.me.csrf_token,'renewed-csrf');
await click('Завершить остальные сессии');
assert.equal(current.length,1);
assert.equal(calls.find(c=>c.path==='/auth/sessions/revoke-others').csrf,'renewed-csrf');
assert.equal(current[0].id,'44444444-4444-4444-8444-444444444444');
console.log('PASS: rotating session renews CSRF for following mutation; other devices revoked');
approvals=false;
await click('Завершить все сессии, включая текущую');
assert.equal(current.length,1);
assert.equal(redirects.length,0);
approvals=true;
await click('Завершить все сессии, включая текущую');
assert.equal(current.length,0);assert.deepEqual(redirects,['/forum/login']);
assert.equal(ctx.me,null);
console.log('PASS: confirmation protects global logout and redirects to forum login');
ctx.me=null;
const before=calls.length;
await ctx.renderSessions();
assert.equal(calls.length,before);
assert(flatten(app).some(e=>e.tag==='a'&&e.url==='/forum/login'));
console.log('PASS: unauthenticated browser cannot query session list');
