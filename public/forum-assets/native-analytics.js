/* CajeerEngine Forum native counters: anonymous, opt-in and first-party only. */
(() => {
  'use strict';
  const script=document.currentScript;
  const receipt=script?.dataset.ceNativeReceipt;
  const approved=script?.dataset.ceNativePath;
  if (!receipt || !approved || !/^v1\.(index|board|thread|page)\.[0-9]+\.[a-f0-9]{32}\.[a-f0-9]{64}$/.test(receipt)) return;
  // A route pushed into history or an untrusted query must not inherit authorization.
  if (location.protocol!=='https:' || location.pathname.replace(/\/+$/,'')!==approved) return;
  const query=new URLSearchParams(location.search);
  if ([...query.keys()].some(key=>key!=='page') || query.getAll('page').length>1 ||
      (query.has('page')&&!/^[1-9][0-9]{0,3}$/.test(query.get('page')))) return;
  let completed=false, sending=false, attempts=0;
  async function submit(){
    if (completed || sending || attempts>=2 || location.pathname.replace(/\/+$/,'')!==approved ||
        !window.CajeerConsent?.allows('analytics')) return;
    sending=true;attempts++;
    try {
      const response=await fetch('/api/v1/forum/analytics/page-view',{
        method:'POST',credentials:'omit',cache:'no-store',keepalive:true,
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({receipt})
      });
      if (response.ok || response.status===409) completed=true;
    } catch { /* Collection is best-effort and cannot interrupt browsing. */ }
    finally { sending=false; }
  }
  document.addEventListener('cajeer:consent-change',submit);
  // Consent is asynchronously refreshed after scripts load. Re-read current state,
  // never localStorage or a stale policy snapshot, before making the request.
  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',submit,{once:true});
  else submit();
})();
