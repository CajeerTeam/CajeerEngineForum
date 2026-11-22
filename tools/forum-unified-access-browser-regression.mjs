/** Test the real Forum HTTP client in isolation; no browser or network needed. */
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const source=fs.readFileSync(new URL('../public/forum-assets/forum.js',import.meta.url),'utf8');
const beginning=source.indexOf('async function api('), end=source.indexOf('const f=(',beginning);
assert.ok(beginning!==-1&&end>beginning,'production api() must be present');
const apiSource=source.slice(beginning,end);
let count=0;
const check=(v,label)=>{assert.ok(v,label);count++;};
function make(callback,{onConfirm=()=>true,onPrompt=()=> 'secret'}={}){
 const calls=[], redirects=[];
 const context={ URL, Error, Array, confirm:onConfirm, prompt:onPrompt, window:{location:{assign:url=>redirects.push(url)}},location:{pathname:'/forum/manage',search:''},
  fetch:async(url,options)=>{
   const entry={path:url.replace('/api/v1',''),options};calls.push(entry);
   const result=await callback(entry,calls);
   return {status:result.status??200,ok:(result.status??200)<400,json:async()=>result.payload};
  }};
 vm.createContext(context);
 vm.runInContext('const base="/api/v1"; let me={csrf_token:"csrf-secret"};'+apiSource+'globalThis.testApi=api;',context);
 return {api:context.testApi,calls,redirects};
}
const forbidden={status:403,payload:{error:{code:'step_up_required',message:'Step-up needed'}}};
{
 const {api,calls}=make(()=>({payload:{data:{ok:true}}}));
 const result=await api('/forum/posts','POST',{body:'test'});
 check(result.ok===true&&calls.length===1,'routine post uses a single request');
 check(calls[0].options.headers['X-CSRF-Token']==='csrf-secret','CSRF remains enabled');
}
{
 const {api,calls}=make(({path},calls)=>{
  if(path==='/forum/acp/theme')return calls.filter(c=>c.path===path).length===1?forbidden:{payload:{data:{saved:true}}};
  if(path==='/auth/sessions')return {payload:{data:[{id:'abc',provider:'local',current:true}]}};
  if(path==='/auth/assurance/confirm')return {payload:{data:{fresh:true}}};
  throw Error('Unexpected '+path);
 },{onPrompt:text=>text.includes('TOTP')?'123456':'correct-password'});
 const result=await api('/forum/acp/theme','PUT',{theme:'dark'});
 check(result.saved===true&&calls.length===4,'local step-up confirms then retries exactly once');
 check(calls[0].options.body===calls[3].options.body,'original mutation remains byte-identical');
 check(JSON.parse(calls[2].options.body).two_factor_code==='123456','TOTP evidence sent only to auth endpoint');
 check(calls.every(c=>c.options.credentials==='same-origin'),'credentials stay same-origin');
}
{
 const url='https://id.skif4er.ru/realms/skif4er/protocol/openid-connect/auth?client_id=forum&max_age=0&state=one-time';
 const {api,calls,redirects}=make(({path})=>path==='/forum/acp/policy'?forbidden:path==='/auth/sessions'?{payload:{data:[{current:true,provider:'skif4er'}]}}:{payload:{data:{authorization_url:url}}});
 await assert.rejects(api('/forum/acp/policy','PUT',{values:{}}),/Повторите операцию/);
 check(redirects[0]===url,'SSO step-up uses approved OIDC URL');
 check(calls.length===3&&calls.filter(c=>c.path==='/forum/acp/policy').length===1,'no mutation is replayed before OIDC MFA');
 check(calls[2].options.headers['X-CSRF-Token']==='csrf-secret','OIDC step-up start is CSRF-protected');
 check(JSON.parse(calls[2].options.body).return_to==='/forum/manage','OIDC callback return is bound to current forum page');
}
{
 const url='https://evil.example/realms/skif4er/protocol/openid-connect/auth?max_age=0';
 const {api,redirects}=make(({path})=>path==='/forum/acp/member'?forbidden:path==='/auth/sessions'?{payload:{data:[{current:true,provider:'skif4er'}]}}:{payload:{data:{authorization_url:url}}});
 await assert.rejects(api('/forum/acp/member','PATCH',{}),/Недопустимый адрес/);
 check(redirects.length===0,'reject injected IdP URL');
}
{
 const {api,calls}=make(({path})=>path==='/forum/setBoardPermissions'?forbidden:{payload:{data:[{current:true,provider:'local'}]}},{onPrompt:()=>null});
 await assert.rejects(api('/forum/setBoardPermissions','PUT',{}),/отменено/);
 check(calls.length===2,'cancelled step-up does not replay mutation');
}
{
 const {api,calls}=make(({path})=>path==='/forum/acp/theme'?forbidden:path==='/auth/sessions'?{payload:{data:[{current:true,provider:'local'}]}}:{payload:{data:{fresh:true}}});
 await assert.rejects(api('/forum/acp/theme','PUT',{}),/Step-up needed/);
 check(calls.filter(c=>c.path==='/forum/acp/theme').length===2,'retry limit is one; no infinite recursion');
}
console.log(`Forum 1.0.8 step-up browser: ${count} PASS`);
