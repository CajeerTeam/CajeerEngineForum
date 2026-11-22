// Run the Forum's actual account-linking UI functions with an isolated DOM/API adapter.
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../public/forum-assets/forum.js',import.meta.url),'utf8');
const first=source.indexOf('function skif4erAuthorizeUrl(value){');
const last=source.indexOf('\nasync function account(){',first);
if(first<0||last<0)throw Error('Forum account-linking runtime not found');
const runtime=source.slice(first,last);
let n=0;
const check=(condition,label)=>{if(!condition)throw Error('FAIL '+label);console.log('PASS '+label);n++};
const authUrl='https://id.skif4er.ru/realms/skif4er/protocol/openid-connect/auth?client_id=forum&state=state&code_challenge=abc&code_challenge_method=S256';
function fixture(status,opts={}){
 const calls=[],redirects=[],children=[],messages=[];
 const elements={password:{value:''},two_factor_code:{value:''}};
 const view={
  URL,URLSearchParams,Object,location:{search:opts.query||''},
  me:{user:{two_factor_enabled:!!opts.totp}},
  confirm:()=>opts.confirm!==false,
  app:{replaceChildren(){children.length=0}},
  node:(tag,text,cls)=>({tag,text,cls,children:[]}),
  add:(parent,...items)=>{parent.children??=[];parent.children.push(...items);return parent},
  panel:()=>({tag:'div',children:[]}),
  go:url=>redirects.push(url),
  say:(message)=>messages.push(message),
  account:async()=>{},
  form:(fields,label,fn)=>{
   const el={tag:'form',fields,label,submit:fn,elements:{namedItem:key=>fields.some(field=>field[0]===key)?elements[key]:null},
    querySelector:()=>({classList:{add:()=>{}}})};return el;
  },
  api:async(path,method='GET',payload)=>{
   calls.push({path,method,payload});
   if(path==='/identity/account'){
    if(opts.offline)throw Error('not connected');
    return status;
   }
   if(path==='/identity/link/start')return {authorization_url:opts.authorizationUrl??authUrl};
   if(path==='/identity/unlink')return {unlinked:true,session_revoked:!!opts.revoked};
   throw Error('Unexpected API route '+path);
  },
  setTimeout:()=>{},
 };
 vm.runInNewContext(runtime+'\nglobalThis.renderLink=accountLinkingPanel;globalThis.validateUrl=skif4erAuthorizeUrl;',view);
 return {view,calls,redirects,children,messages,elements,render:()=>view.renderLink()};
}
function flatten(node){return [node,...(node.children||[]).flatMap(flatten)]}
{
 const t=fixture({enabled:true,linked:false,created_at:null},{totp:true});
 const p=await t.render();const form=flatten(p).find(x=>x.tag==='form');
 check(form?.label==='Привязать SkiF4er ID','real linking action rendered on forum security page');
 check(form.fields.some(x=>x[0]==='two_factor_code') && t.elements.two_factor_code.required,'two-factor challenge required when local 2FA is enabled');
 t.elements.password.value='secret';t.elements.two_factor_code.value='123456';
 await form.submit({password:'local-secret',two_factor_code:'123456'});
 check(t.calls[0].path==='/identity/account' && t.calls[1].path==='/identity/link/start' && t.calls[1].method==='POST','forum invokes existing secured linking API');
 check(t.calls[1].payload.return_to==='/forum/account/security' && t.calls[1].payload.user_id===undefined,'local UUID and return destination cannot be chosen by browser');
 check(t.redirects[0]===authUrl,'browser navigates to validated SkiF4er ID authorization endpoint');
 check(t.elements.password.value==='' && t.elements.two_factor_code.value==='','password and 2FA fields cleared after submission');
}
{
 const t=fixture({enabled:true,linked:true,provisioned:false,created_at:'2026-11-22'},{revoked:true});
 const p=await t.render();const form=flatten(p).find(x=>x.tag==='form');
 check(form?.label==='Отвязать SkiF4er ID','linked account renders unlink control');
 await form.submit({password:'local-secret'});
 check(t.calls[1].path==='/identity/unlink' && t.calls[1].method==='POST' && t.calls[1].payload.return_to===undefined,'unlink uses reauthenticated API, not a GET redirect');
 check(t.redirects[0]==='/forum/login?identity=unlinked','revoked SSO browser returns to login');
}
{
 const t=fixture({enabled:true,linked:true,provisioned:true});
 const p=await t.render();
 check(!flatten(p).some(x=>x.tag==='form'),'OIDC-only account does not expose unsafe self-unlink action');
}
{
 const t=fixture({enabled:true,linked:false},{authorizationUrl:'https://evil.example/authorize'});
 const p=await t.render();let failed=false;
 try{await flatten(p).find(x=>x.tag==='form').submit({password:'local-secret'});}catch{failed=true}
 check(failed && t.redirects.length===0,'malicious authorization URL never opened');
}
{
 const t=fixture({enabled:true,linked:true},{confirm:false});
 const p=await t.render();await flatten(p).find(x=>x.tag==='form').submit({password:'local-secret'});
 check(t.calls.length===1,'cancelled unlink does not reach server');
}
{
 const t=fixture({enabled:false,linked:false});const p=await t.render();
 check(!flatten(p).some(x=>x.tag==='form'),'disabled provider does not show linking form');
}
{
 const t=fixture({enabled:true,linked:true,created_at:'2026-11-22'},{query:'?identity=linked'});
 const p=await t.render();check(flatten(p).some(x=>x.text?.includes('успешно привязан')),'success message requires server-confirmed linked status');
}
{
 const t=fixture({enabled:true,linked:false},{query:'?identity=linked'});
 const p=await t.render();check(!flatten(p).some(x=>x.text?.includes('успешно привязан')),'query string alone cannot claim completed link');
}
console.log('Forum Account Linking browser 1.0.4:',n,'PASS');
