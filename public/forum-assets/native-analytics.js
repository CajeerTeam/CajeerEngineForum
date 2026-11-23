/* CajeerEngine Forum native counters: anonymous, opt-in and first-party only. */
(() => {
  'use strict';
  const script=document.currentScript;
  const receipt=script?.dataset.ceNativeReceipt;
  const approved=script?.dataset.ceNativePath;
  const approvedPage=script?.dataset.ceNativePage ?? '';
  if (!receipt || !approved || !/^v1\.(index|board|thread|page)\.[0-9]+\.[a-f0-9]{32}\.[a-f0-9]{64}$/.test(receipt)) return;
  // A route pushed into history or an untrusted query must not inherit authorization.
  // Revalidate the ORIGINAL server-approved representation on every consent
  // change or retry. Consent may be granted after client-side navigation.
  function onApprovedPage(){
    if (location.protocol!=='https:' || location.pathname.replace(/\/+$/,'')!==approved) return false;
    const query=new URLSearchParams(location.search);
    if ([...query.keys()].some(key=>key!=='page') || query.getAll('page').length>1 ||
        (query.has('page')&&!/^[1-9][0-9]{0,3}$/.test(query.get('page')))) return false;
    return (query.get('page') ?? '')===approvedPage;
  }
  if (!onApprovedPage()) return;
  let completed=false, sending=false, attempts=0, retryTimer=null;
  // A transient outage must not require the visitor to change consent again.
  // Reuse the SAME receipt: SQL idempotency ensures a lost HTTP response
  // cannot double-count a committed view. No cookies or identifiers are added.
  function retryOnce(){
    if (completed || attempts>=2 || retryTimer!==null || !window.CajeerConsent?.allows('analytics')) return;
    retryTimer=setTimeout(()=>{retryTimer=null;void submit();},800);
  }
  async function submit(){
    if (completed || sending || attempts>=2 || !onApprovedPage() ||
        !window.CajeerConsent?.allows('analytics')) return;
    sending=true;attempts++;
    let transient=false;
    try {
      const response=await fetch('/api/v1/forum/analytics/page-view',{
        method:'POST',credentials:'omit',cache:'no-store',keepalive:true,
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({receipt})
      });
      if (response.ok || response.status===409) completed=true;
      else if (response.status===429 || response.status>=500) transient=true;
      else completed=true; // 4xx invalid receipt/consent: never retry it.
    } catch { transient=true; /* Best effort; browsing must never fail. */ }
    finally { sending=false; if(transient) retryOnce(); }
  }
  document.addEventListener('cajeer:consent-change',()=>{
    if (!window.CajeerConsent?.allows('analytics') && retryTimer!==null) {
      clearTimeout(retryTimer);retryTimer=null;
    }
    void submit();
  });
  // Consent is asynchronously refreshed after scripts load. Re-read current state,
  // never localStorage or a stale policy snapshot, before making the request.
  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',submit,{once:true});
  else submit();
})();
