// Execute the real Forum register-page UI with isolated DOM and API adapters.
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../public/forum-assets/forum.js',import.meta.url),'utf8');
const start=source.indexOf('async function auth(register=false){');
const end=source.indexOf('\nconst watchModes=',start);
if(start<0||end<0)throw Error('Forum auth implementation missing');
const active=source.slice(start,end);
let assertions=0;
function assert(value,message){if(!value)throw Error('FAIL '+message);assertions++;console.log('PASS '+message)}
async function fixture(registration,autoProvision,provider=true){
 const panels=[],calls=[],redirects=[];
 const make=(tag,text,cls)=>({tag,text,cls,children:[],append(item){this.children.push(item)},setAttribute(){}});
 const app={children:[]};
 const ctx={
  URLSearchParams,location:{search:'',pathname:'/forum/register'},
  document:{documentElement:{dataset:{registration:registration?'true':'false'}}},app,
  node:make,panel:()=>make('section'),
  heading:()=>{},say:()=>{},
  link:(label,url,cls)=>make('a',label,cls) && {tag:'a',text:label,url,cls,children:[]},
  add:(parent,...items)=>{parent.children.push(...items);if(parent===app)panels.push(...items);return parent},
  form:(fields,label,onSubmit)=>({tag:'form',fields,label,onSubmit,children:[],append(item){this.children.push(item)}}),
  forumReturn:()=>'/forum',
  go:url=>redirects.push(url),
  f:async()=>({verification_required:true}),
  api:async(path)=>{calls.push(path);if(path==='/auth/oidc/status')return {enabled:provider,auto_provision:autoProvision};throw Error('bad path')},
 };
 vm.runInNewContext(active+'\nglobalThis.testAuth=auth;',ctx);
 await ctx.testAuth(true);
 const flatten=x=>[x,...(x.children||[]).flatMap(flatten)];
 return {links:flatten(app).filter(x=>x.tag==='a'),calls,redirects};
}
{
 const t=await fixture(true,true);
 const s=t.links.find(x=>x.text==='Создать аккаунт через SkiF4er ID');
 assert(!!s,'open registration and enabled provider renders actual SSO create-account link');
 assert(s.url==='/auth/oidc/start?return_to='+encodeURIComponent('/forum/account'),'new registration goes through browser-bound OIDC start and account continuation');
 assert(t.calls.includes('/auth/oidc/status'),'capability obtained from live OIDC status endpoint');
}
{
 const t=await fixture(true,false);
 assert(!t.links.some(x=>x.text==='Создать аккаунт через SkiF4er ID'),'provisioning disabled hides SSO signup action');
}
{
 const t=await fixture(true,true,false);
 assert(!t.links.some(x=>x.text==='Создать аккаунт через SkiF4er ID'),'provider disabled hides SSO signup action');
}
{
 const t=await fixture(false,true);
 assert(!t.links.some(x=>x.text==='Создать аккаунт через SkiF4er ID'),'closed forum never renders SSO signup action');
 assert(!t.calls.includes('/auth/oidc/status'),'closed forum does not start SSO enrollment');
}
console.log('Forum Account Provisioning browser: '+assertions+' PASS');
