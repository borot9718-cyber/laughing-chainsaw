/* KOVA — Notifications sur le téléphone / l'ordinateur (barre de notification), avec autorisation.
   1) Web Push : reçu même application fermée (si le serveur peut joindre les services push).
   2) Secours : tant que KOVA est ouvert (même en arrière-plan), l'application interroge le serveur
      et affiche la notification système. Une notification poussée et une notification de secours
      partagent la même « tag » : elles se remplacent au lieu de s'additionner. */
(function(){
"use strict";
const K=window.KOVA; if(!K||!K.api||!window.KOVA_USER_ID) return;
const LS=(k,v)=>{try{if(v===undefined)return localStorage.getItem(k);localStorage.setItem(k,v);}catch(_){return null;}};
const supported="serviceWorker" in navigator && "Notification" in window;
const pushSupported=supported && "PushManager" in window;
const KEY_ON="kova_notif_on:"+window.KOVA_USER_ID, KEY_TS="kova_notif_ts:"+window.KOVA_USER_ID, KEY_LATER="kova_notif_later";

const b64uToU8=s=>{const p="=".repeat((4-s.length%4)%4),b=(s+p).replace(/-/g,"+").replace(/_/g,"/"),r=atob(b);return Uint8Array.from(r,c=>c.charCodeAt(0));};
const ready=()=>navigator.serviceWorker.ready;

function status(){
  return {
    supported,
    pushSupported,
    permission: supported?Notification.permission:"unsupported",
    enabled: supported && Notification.permission==="granted" && LS(KEY_ON)!=="0",
    ios: /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.matchMedia("(display-mode: standalone)").matches
  };
}

async function serverConfig(){ try{ return await K.api("/api/push/config"); }catch(_){ return {supported:false,public_key:""}; } }

/* Demande l'autorisation (doit être appelée depuis un clic) puis enregistre l'appareil. */
async function enable(){
  if(!supported) throw new Error("Ce navigateur ne prend pas en charge les notifications.");
  const st=status();
  if(st.ios && !pushSupported) throw new Error("Sur iPhone/iPad, ajoutez d’abord KOVA à l’écran d’accueil (Partager › Sur l’écran d’accueil), puis rouvrez l’application.");
  const perm=await Notification.requestPermission();
  if(perm!=="granted") throw new Error("Autorisation refusée. Vous pouvez la modifier dans les réglages du navigateur (icône cadenas › Notifications).");
  LS(KEY_ON,"1");
  let pushed=false;
  const cfg=await serverConfig();
  if(cfg.supported && pushSupported){
    try{
      const reg=await ready();
      let sub=await reg.pushManager.getSubscription();
      if(!sub) sub=await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:b64uToU8(cfg.public_key)});
      await K.api("/api/push/subscribe",{method:"POST",body:JSON.stringify(sub.toJSON())});
      pushed=true;
    }catch(e){ console.warn("Push indisponible, mode de secours activé :",e); }
  }
  startPolling(true);
  return {pushed};
}

async function disable(){
  LS(KEY_ON,"0"); stopPolling();
  try{
    const reg=await ready(); const sub=await reg.pushManager.getSubscription();
    if(sub){ await K.api("/api/push/unsubscribe",{method:"POST",body:JSON.stringify({endpoint:sub.endpoint})}); await sub.unsubscribe(); }
    else await K.api("/api/push/unsubscribe",{method:"POST",body:JSON.stringify({})});
  }catch(_){}
}

async function sendTest(){
  const r=await K.api("/api/push/test",{method:"POST"});
  return r;
}

/* ── Secours : interrogation périodique pendant que KOVA est ouvert ── */
let timer=null;
async function poll(first){
  if(!status().enabled) return;
  try{
    const d=await K.api("/api/notifications");
    const list=(d.notifications||[]).filter(n=>!n.read);
    let last=Number(LS(KEY_TS)||0);
    if(!last||first===true&&!last){ last=Date.now(); }
    const fresh=list.filter(n=>Date.parse(n.created_at)>last).sort((a,b)=>Date.parse(a.created_at)-Date.parse(b.created_at));
    if(list.length) LS(KEY_TS,String(Math.max(last,...list.map(n=>Date.parse(n.created_at)))));
    else if(!LS(KEY_TS)) LS(KEY_TS,String(Date.now()));
    K.loadUnreadBadge&&K.loadUnreadBadge();
    if(!fresh.length) return;
    if(document.visibilityState==="visible"){
      const n=fresh[fresh.length-1]; K.toast&&K.toast(n.message,"info");
      return;
    }
    const reg=await ready();
    for(const n of fresh.slice(-3)){
      await reg.showNotification("KOVA",{
        body:String(n.message||"").slice(0,180), icon:"/assets/images/generated/kova-192.png", badge:"/assets/images/generated/kova-96.png",
        tag:String(n.dedupe||n.type||"kova"), renotify:true, data:{url:(n.link&&n.link.startsWith("/")&&!n.link.startsWith("//"))?n.link:"/notifications"}
      });
    }
  }catch(_){}
}
function startPolling(first){ stopPolling(); if(!LS(KEY_TS)) LS(KEY_TS,String(Date.now())); timer=setInterval(()=>poll(),30000); if(first) poll(true); }
function stopPolling(){ if(timer){clearInterval(timer);timer=null;} }
document.addEventListener("visibilitychange",()=>{ if(document.visibilityState==="visible"&&status().enabled){ poll(); if(navigator.clearAppBadge) navigator.clearAppBadge().catch(()=>{}); } });

/* ── Bandeau d'invitation (tableau de bord) ── */
(function banner(){
  const b=document.getElementById("push-banner"); if(!b) return;
  const st=status();
  const later=Number(LS(KEY_LATER)||0);
  if(!st.supported && !st.ios) return;
  if(st.permission==="granted"||st.permission==="denied") return;
  if(later && Date.now()-later<7*86400000) return;
  b.hidden=false;
  document.getElementById("push-later")?.addEventListener("click",()=>{LS(KEY_LATER,String(Date.now()));b.hidden=true;});
  document.getElementById("push-enable")?.addEventListener("click",async()=>{
    try{ const r=await enable(); b.hidden=true; K.toast(r.pushed?"Notifications activées sur cet appareil.":"Notifications activées (tant que KOVA est ouvert).","success"); }
    catch(e){ K.toast(e.message,"error"); if(Notification.permission==="denied") b.hidden=true; }
  });
})();

/* ── Au chargement : resynchroniser l'abonnement (changement de clé, réinstallation…) puis démarrer ── */
(async function boot(){
  if(!status().enabled) return;
  try{
    if(pushSupported){
      const cfg=await serverConfig();
      if(cfg.supported){
        const reg=await ready(); let sub=await reg.pushManager.getSubscription();
        if(!sub) sub=await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:b64uToU8(cfg.public_key)});
        if(sub && cfg.devices===0) await K.api("/api/push/subscribe",{method:"POST",body:JSON.stringify(sub.toJSON())});
      }
    }
  }catch(_){}
  startPolling(false);
  if(navigator.clearAppBadge && document.visibilityState==="visible") navigator.clearAppBadge().catch(()=>{});
})();

K.push={status,enable,disable,sendTest,serverConfig};
})();
