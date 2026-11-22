// Executes the actual Forum 1.0.7 global-logout UI code with deterministic API/DOM adapters.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const src=fs.readFileSync(new URL('../public/forum-assets/forum.js',import.meta.url),'utf8');
const start=src.indexOf('async function logoutSkiF4erFromForum(){');
const end=src.indexOf('\nconst notificationKinds=',start);
assert(start>=0 && end>start,'production forum global logout action exists');
const code=src.slice(start,end);
const url='https://id.skif4er.ru/realms/skif4er/protocol/openid-connect/logout?client_id=forum&state='+'a'.repeat(48);
let current=[],calls=[],redirect=[],approvals=true,link=url,notices=[];
const element=(tag,text='',cls='')=>({tag,text,cls,children:[],append(...c){this.children.push(...c)},replaceChildren(...c){this.children=c}});
const app=element('main');
const ctx={app,URL,encodeURIComponent,location:{assign:u=>redirect.push(u)},me:{session:true,cookie:true,csrf_token:'old'},
 node:element,panel:()=>element('section'),link:(text,url)=>Object.assign(element('a',text),{url}),
 add:(parent,...children)=>{parent.append(...children);return parent},heading:text=>app.append(element('h1',text)),go:url=>redirect.push(url),confirm:()=>approvals,say:text=>notices.push(text),
 button:(text,fn)=>Object.assign(element('button',text),{click:fn}),
 api:async(path,method='GET',body)=>{calls.push({path,method,body,csrf:ctx.me?.csrf_token});if(path==='/auth/sessions')return current;if(path==='/auth/oidc/logout?return_to=%2Fforum%2Flogin'&&method==='POST')return {authorization_url:link,local_session_revoked:true};if(path==='/auth/logout'&&method==='POST')return {logged_out:true};throw Error('Unexpected API '+path)}
};
vm.runInNewContext(code+'\nglobalThis.renderSessions=accountSessionsPage; globalThis.globalSso=logoutSkiF4erFromForum;',ctx);
const flatten=e=>[e,...(e.children||[]).flatMap(flatten)];
const click=async name=>{const b=flatten(app).find(e=>e.tag==='button'&&e.text===name);assert(b,'Missing action '+name);return b.click()};
current=[{id:'11111111-1111-4111-8111-111111111111',current:true,provider:'local'}];
await ctx.renderSessions();
assert(!flatten(app).some(e=>e.text==='Глобальный выход через SkiF4er ID'));
console.log('PASS: local sessions cannot trigger global IdP logout');
current=[{id:'22222222-2222-4222-8222-222222222222',current:true,provider:'skif4er'}];
await ctx.renderSessions();
approvals=false;await click('Глобальный выход через SkiF4er ID');
assert.equal(calls.filter(x=>x.path.startsWith('/auth/oidc/logout')).length,0);
console.log('PASS: explicit consent required before global logout');
approvals=true;await click('Глобальный выход через SkiF4er ID');
assert.equal(redirect.at(-1),url);
assert.equal(calls.at(-1).method,'POST');
assert.equal(calls.at(-1).csrf,'old');
assert.equal(ctx.me,null);
console.log('PASS: real global logout POST uses CSRF and navigates only to pinned IdP');
ctx.me={session:true,cookie:true,csrf_token:'next'}; link='https://attacker.example/steal?state=abc';
await ctx.globalSso();
assert.equal(redirect.at(-1),'/forum/login?logout=unconfirmed');
assert.equal(ctx.me,null);
console.log('PASS: attacker-controlled logout destination cannot receive browser navigation');
ctx.me={session:true,cookie:true,csrf_token:'third'};
link=url.replace('/logout?','/token?');
await ctx.globalSso();
assert.equal(redirect.at(-1),'/forum/login?logout=unconfirmed');
console.log('PASS: provider URL must be exact logout endpoint');
ctx.me={session:true,cookie:true,csrf_token:'fourth'};
link='not-a-url';
await ctx.globalSso();
assert.equal(redirect.at(-1),'/forum/login?logout=unconfirmed');
assert.equal(ctx.me,null);
console.log('PASS: malformed provider URL after local revoke fails safely');
