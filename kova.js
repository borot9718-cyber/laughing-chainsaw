(function(){
"use strict";
window.KOVA = window.KOVA || {};
const ROOT=document.documentElement;
window.KOVA_USER_ID=ROOT.dataset.userId||null;
window.KOVA_LANG=ROOT.dataset.lang||"fr";
window.KOVA_DEBUG=ROOT.dataset.debug==="1";
const debug = !!window.KOVA_DEBUG;
const dock = document.getElementById("kova-js-errors");

function showError(type, message, detail){
  if(!debug || !dock) return;
  dock.hidden = false;
  const item=document.createElement("div");
  item.className="js-error-item";
  item.innerHTML="<strong>"+escapeHtml(type)+"</strong> "+escapeHtml(message)+(detail?"<br><small>"+escapeHtml(detail)+"</small>":"");
  dock.appendChild(item);
}
function escapeHtml(v){return String(v).replace(/[&<>"']/g,c=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"}[c]));}

window.addEventListener("error", e=>{
  showError("JavaScript", e.message||"Erreur inconnue", (e.filename||"")+":"+(e.lineno||""));
});
window.addEventListener("unhandledrejection", e=>{
  showError("Promise", e.reason?.message||String(e.reason||"Erreur asynchrone"));
});

/* ════ Utilitaires ════ */
function toast(message, type="info"){
  let t=document.querySelector(".kova-toast");
  if(!t){
    t=document.createElement("div"); t.className="kova-toast";
    Object.assign(t.style,{position:"fixed",right:"18px",bottom:"18px",zIndex:"9998",
      background:"#111a31",color:"#fff",padding:"13px 18px",borderRadius:"12px",
      fontSize:"13px",boxShadow:"0 15px 40px rgba(0,0,0,.25)"});
    document.body.appendChild(t);
  }
  t.textContent=message;
  t.style.borderLeft=type==="error"?"4px solid #f87171":type==="success"?"4px solid #4ade80":"4px solid #7c3aed";
  t.hidden=false; clearTimeout(t._timer); t._timer=setTimeout(()=>t.hidden=true,3500);
}

async function api(url, options={}){
  const request = async (target) => {
    const baseHeaders={"Content-Type":"application/json","X-Requested-With":"XMLHttpRequest"};
    const csrf=document.querySelector('meta[name="kova-csrf"]')?.content||"";
    if(csrf) baseHeaders["X-KOVA-CSRF"]=csrf;
    const res = await fetch(target,{
      ...options,
      cache:'no-store',
      credentials:'same-origin',
      headers:{...baseHeaders,...(options.headers||{})}
    });
    const text = await res.text();
    let data = null;
    try { data = JSON.parse(text); } catch(e) {}
    return {res, text, data};
  };

  let result = await request(url);

  // Si la session a été renouvelée après l'affichage de la page, le jeton
  // présent dans le DOM peut être ancien. On récupère alors le jeton courant
  // une seule fois et on rejoue la requête, sans désactiver la protection CSRF.
  if(result.res.status===403 && result.data?.code==='csrf_invalid'){
    try{
      const fresh=await fetch('/api/security/csrf?ts='+Date.now(),{credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}});
      const freshData=await fresh.json();
      if(fresh.ok && freshData?.csrf){
        const meta=document.querySelector('meta[name="kova-csrf"]');
        if(meta) meta.content=freshData.csrf;
        result=await request(url);
      }
    }catch(_){/* conserver la réponse CSRF initiale */}
  }

  // Certains hébergements mutualisés peuvent servir /api/* comme une page 404
  // HTML malgré mod_rewrite. Dans ce cas, on réessaie via index.php en
  // CONSERVANT le préfixe api/ attendu par Router.php.
  if (result.res.status === 404 && !result.data && url.startsWith('/api/')) {
    const fallback = '/index.php?route=' + encodeURIComponent(url.slice(1));
    result = await request(fallback);
  }

  const data = result.data || {
    ok:false,
    error:"Réponse serveur non JSON ("+result.res.status+")"
  };

  if(!result.res.ok || data.ok===false){
    const e=new Error(data.error||"Erreur serveur ("+result.res.status+")");
    e.data=data; e.status=result.res.status; throw e;
  }
  return data;
}

/* Réduit les photos avant envoi (2048 px max) : envoi plus rapide, moins de données mobiles,
   et les métadonnées (position GPS…) ne quittent jamais le téléphone. Le serveur re-vérifie tout. */
async function optimizeImage(file){
  try{
    if(!file||!/^image\/(jpeg|png|webp)$/.test(file.type)||file.size<350*1024||!window.createImageBitmap) return file;
    const bmp=await createImageBitmap(file);
    const scale=Math.min(1,2048/Math.max(bmp.width,bmp.height));
    const w=Math.max(1,Math.round(bmp.width*scale)), h=Math.max(1,Math.round(bmp.height*scale));
    const c=document.createElement("canvas"); c.width=w; c.height=h;
    c.getContext("2d").drawImage(bmp,0,0,w,h); if(bmp.close) bmp.close();
    const type=file.type==="image/png"?"image/png":"image/jpeg";
    const blob=await new Promise(r=>c.toBlob(r,type,0.85));
    if(!blob||blob.size>=file.size) return file;
    return new File([blob],file.name.replace(/\.\w+$/,type==="image/png"?".png":".jpg"),{type});
  }catch(_){return file;}
}
async function optimizeFormImages(fd){
  if(!(fd instanceof FormData)) return fd;
  const out=new FormData();
  for(const [k,v] of fd.entries()) out.append(k,(v instanceof File)?await optimizeImage(v):v);
  return out;
}

// Comme api(), mais pour l'envoi de fichiers : on laisse le navigateur
// fixer lui-même le Content-Type multipart/form-data (avec sa boundary),
// donc pas d'en-tête Content-Type forcé ici.
async function apiUpload(url, formData, method="POST"){
  formData=await optimizeFormImages(formData);
  const request = async (target) => {
    const csrf=document.querySelector('meta[name="kova-csrf"]')?.content||"";
    const headers={"X-Requested-With":"XMLHttpRequest"};
    if(csrf) headers["X-KOVA-CSRF"]=csrf;
    const res = await fetch(target,{method, headers, body:formData, cache:'no-store', credentials:'same-origin'});
    const text = await res.text();
    let data = null;
    try { data = JSON.parse(text); } catch(e) {}
    return {res, text, data};
  };
  let result = await request(url);
  if(result.res.status===403 && result.data?.code==='csrf_invalid'){
    try{
      const fresh=await fetch('/api/security/csrf?ts='+Date.now(),{credentials:'same-origin',cache:'no-store',headers:{'X-Requested-With':'XMLHttpRequest'}});
      const freshData=await fresh.json();
      if(fresh.ok && freshData?.csrf){
        const meta=document.querySelector('meta[name="kova-csrf"]');
        if(meta) meta.content=freshData.csrf;
        result=await request(url);
      }
    }catch(_){}
  }
  if (result.res.status === 404 && !result.data && url.startsWith('/api/')) {
    const fallback = '/index.php?route=' + encodeURIComponent(url.slice(1));
    result = await request(fallback);
  }
  const data = result.data || { ok:false, error:"Réponse serveur non JSON ("+result.res.status+")" };
  if(!result.res.ok || data.ok===false){
    const e=new Error(data.error||"Erreur serveur ("+result.res.status+")");
    e.data=data; e.status=result.res.status; throw e;
  }
  return data;
}

function formError(el,text){el.hidden=false;el.className="form-message error";el.textContent=text;el.scrollIntoView({behavior:"smooth",block:"nearest"});}
function formSuccess(el,text){el.hidden=false;el.className="form-message success";el.textContent=text;}
function setBtn(btn, text, disabled){btn.disabled=disabled; btn.textContent=text;}

function timeAgo(dateStr){
  const diff=(Date.now()-new Date(dateStr).getTime())/1000;
  if(diff<60) return "À l'instant";
  if(diff<3600) return Math.floor(diff/60)+" min";
  if(diff<86400) return Math.floor(diff/3600)+"h";
  return new Date(dateStr).toLocaleDateString("fr-FR");
}

/* ════ Preuve de travail (« captcha » sans service tiers) ════ */
const K256=new Uint32Array([0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2]);
function sha256(bytes){
  const l=bytes.length, total=((l+9+63)>>6)<<6, m=new Uint8Array(total);
  m.set(bytes); m[l]=0x80;
  const dv=new DataView(m.buffer); dv.setUint32(total-4,(l*8)>>>0); dv.setUint32(total-8,Math.floor(l*8/4294967296));
  let h0=0x6a09e667,h1=0xbb67ae85,h2=0x3c6ef372,h3=0xa54ff53a,h4=0x510e527f,h5=0x9b05688c,h6=0x1f83d9ab,h7=0x5be0cd19;
  const w=new Uint32Array(64);
  for(let o=0;o<total;o+=64){
    for(let i=0;i<16;i++) w[i]=dv.getUint32(o+i*4);
    for(let i=16;i<64;i++){
      const a=w[i-15],b=w[i-2];
      const s0=((a>>>7)|(a<<25))^((a>>>18)|(a<<14))^(a>>>3), s1=((b>>>17)|(b<<15))^((b>>>19)|(b<<13))^(b>>>10);
      w[i]=(w[i-16]+s0+w[i-7]+s1)|0;
    }
    let a=h0,b=h1,c=h2,d=h3,e=h4,f=h5,g=h6,h=h7;
    for(let i=0;i<64;i++){
      const S1=((e>>>6)|(e<<26))^((e>>>11)|(e<<21))^((e>>>25)|(e<<7)), ch=(e&f)^(~e&g);
      const t1=(h+S1+ch+K256[i]+w[i])|0;
      const S0=((a>>>2)|(a<<30))^((a>>>13)|(a<<19))^((a>>>22)|(a<<10)), mj=(a&b)^(a&c)^(b&c);
      const t2=(S0+mj)|0;
      h=g;g=f;f=e;e=(d+t1)|0;d=c;c=b;b=a;a=(t1+t2)|0;
    }
    h0=(h0+a)|0;h1=(h1+b)|0;h2=(h2+c)|0;h3=(h3+d)|0;h4=(h4+e)|0;h5=(h5+f)|0;h6=(h6+g)|0;h7=(h7+h)|0;
  }
  const out=new Uint8Array(32), ov=new DataView(out.buffer);
  [h0,h1,h2,h3,h4,h5,h6,h7].forEach((x,i)=>ov.setUint32(i*4,x));
  return out;
}
function leadingZeroBits(d){let n=0;for(const b of d){if(b===0){n+=8;continue;}n+=Math.clz32(b)-24;break;}return n;}
async function solvePow(challenge,bits){
  const enc=new TextEncoder(); let nonce=0;
  for(;;){
    for(let i=0;i<4000;i++,nonce++){
      if(leadingZeroBits(sha256(enc.encode(challenge+":"+nonce)))>=bits) return String(nonce);
    }
    await new Promise(r=>setTimeout(r,0));      // laisse respirer l'interface
  }
}

/* ════ Auth ════ */
function bindForm(id, handler){
  const f=document.getElementById(id);
  if(f){ f.addEventListener("submit", handler); }
}

bindForm("register-form", async function(e){
  e.preventDefault(); e.stopPropagation();
  const f=e.currentTarget, msg=f.querySelector("[data-form-message]"), btn=f.querySelector("button[type=submit]");
  msg.hidden=true;
  if(!f.querySelector("[name=terms]")?.checked) return formError(msg,"Vous devez accepter les conditions.");
  const displayNameVal=f.querySelector("[name=display_name]")?.value?.trim()||"";
  if(displayNameVal.length<2) return formError(msg,"Le pseudo doit contenir au moins 2 caractères.");
  setBtn(btn,"Création…",true);
  try{
    const payload={
      display_name: displayNameVal,
      email: f.querySelector("[name=email]")?.value?.trim()||"",
      password: f.querySelector("[name=password]")?.value||"",
      date_of_birth: f.querySelector("[name=date_of_birth]")?.value||"",
      privacy_consent: !!f.querySelector("[name=privacy_consent]")?.checked,
      terms_consent: !!f.querySelector("[name=terms]")?.checked
    };
    const res=await api("/api/auth/register",{method:"POST",body:JSON.stringify(payload)});
    formSuccess(msg, res.message||"Compte créé !");
    const q=new URLSearchParams({email:res.email||payload.email});
    if(f.dataset.next) q.set("next",f.dataset.next);
    setTimeout(()=>{location.href="/verifier-email?"+q.toString();}, 900);
  }catch(err){formError(msg,err.message); setBtn(btn,"Créer mon compte",false);}
});

function setCsrf(token){ if(!token) return; const m=document.querySelector('meta[name="kova-csrf"]'); if(m) m.content=token; }

bindForm("login-form", async function(e){
  e.preventDefault(); e.stopPropagation();
  const f=e.currentTarget, msg=f.querySelector("[data-form-message]"), btn=f.querySelector("button[type=submit]");
  msg.hidden=true;
  setBtn(btn,"Connexion…",true);
  const payload={
    email: f.querySelector("[name=email]")?.value?.trim()||"",
    password: f.querySelector("[name=password]")?.value||"",
    next: f.dataset.next||""
  };
  try{
    let res;
    for(let attempt=0;attempt<3;attempt++){
      try{
        res=await api("/api/auth/login",{method:"POST",body:JSON.stringify(payload)});
        break;
      }catch(err){
        // Trop d'échecs récents : le serveur exige une petite « preuve de travail » (~1 s), résolue automatiquement.
        if(err.data && err.data.code==="pow_required" && attempt<2){
          setBtn(btn,"Vérification de sécurité…",true);
          payload.pow_token=err.data.challenge;
          payload.pow_nonce=await solvePow(err.data.challenge,err.data.bits);
          continue;
        }
        throw err;
      }
    }
    setCsrf(res.csrf);
    if(res.twofa_required){
      f.hidden=true;
      const t=document.getElementById("twofa-form");
      if(t){ t.hidden=false; t.dataset.next=res.redirect||f.dataset.next||""; t.querySelector("[name=code]")?.focus(); }
      return;
    }
    formSuccess(msg,"Connexion réussie, redirection…");
    setTimeout(()=>location.href=res.redirect||"/app", 400);
  }catch(err){
    if(err.data && err.data.unverified){
      const q=new URLSearchParams({email:err.data.email||"",resend:"1"});
      if(f.dataset.next) q.set("next",f.dataset.next);
      location.href="/verifier-email?"+q.toString();
      return;
    }
    formError(msg,err.message);
    setBtn(btn,"Se connecter",false);
  }
});

/* Seconde étape : code de l'application d'authentification ou code de secours */
bindForm("twofa-form", async function(e){
  e.preventDefault(); e.stopPropagation();
  const f=e.currentTarget, msg=f.querySelector("[data-form-message]"), btn=f.querySelector("button[type=submit]");
  msg.hidden=true; setBtn(btn,"Vérification…",true);
  try{
    const res=await api("/api/auth/2fa",{method:"POST",body:JSON.stringify({code:f.querySelector("[name=code]")?.value||""})});
    setCsrf(res.csrf); formSuccess(msg,"Connexion réussie, redirection…");
    setTimeout(()=>location.href=res.redirect||"/app",300);
  }catch(err){
    formError(msg,err.message); setBtn(btn,"Valider",false);
    if(/expir|Reconnectez/i.test(err.message)) setTimeout(()=>location.reload(),1800);
  }
});
document.getElementById("twofa-cancel")?.addEventListener("click",()=>location.reload());

/* Vérification de l'e-mail : code à 6 chiffres */
(function(){
  const f=document.getElementById("verify-form"); if(!f) return;
  const msg=f.querySelector("[data-form-message]"), btn=f.querySelector("button[type=submit]");
  const codeInput=f.querySelector("[name=code]"), resend=document.getElementById("resend-code");
  const emailOf=()=>(f.querySelector("[name=email]")?.value||"").trim();
  let submitting=false;

  async function submit(){
    if(submitting) return;
    const code=(codeInput.value||"").replace(/\D/g,"");
    if(code.length!==6) return formError(msg,"Saisissez les 6 chiffres du code.");
    if(!emailOf()) return formError(msg,"Indiquez votre adresse e-mail.");
    submitting=true; msg.hidden=true; setBtn(btn,"Vérification…",true);
    try{
      const res=await api("/api/auth/verify-code",{method:"POST",body:JSON.stringify({email:emailOf(),code,next:f.dataset.next||""})});
      formSuccess(msg,"Adresse vérifiée, connexion…");
      setTimeout(()=>location.href=res.redirect||"/app",600);
    }catch(err){
      formError(msg,err.message); setBtn(btn,"Vérifier",false); submitting=false;
      codeInput.select();
    }
  }
  f.addEventListener("submit",e=>{e.preventDefault(); submit();});
  codeInput.addEventListener("input",()=>{
    codeInput.value=codeInput.value.replace(/\D/g,"").slice(0,6);
    if(codeInput.value.length===6) submit();
  });

  let left=0,timer=null;
  const tick=()=>{
    if(left>0){resend.disabled=true;resend.textContent="Renvoyer le code ("+left+" s)";left--;}
    else{clearInterval(timer);resend.disabled=false;resend.textContent="Renvoyer le code";}
  };
  const startCountdown=s=>{left=s;clearInterval(timer);tick();timer=setInterval(tick,1000);};
  async function requestCode(){
    if(!emailOf()) return formError(msg,"Indiquez votre adresse e-mail.");
    try{
      const r=await api("/api/auth/resend-code",{method:"POST",body:JSON.stringify({email:emailOf()})});
      formSuccess(msg,r.message); startCountdown(r.wait||60);
    }catch(err){formError(msg,err.message); if(err.data&&err.data.wait) startCountdown(err.data.wait);}
  }
  resend.addEventListener("click",requestCode);
  if(new URLSearchParams(location.search).get("resend")==="1") requestCode(); else startCountdown(60);
  codeInput.focus();
})();

bindForm("forgot-form", async function(e){
  e.preventDefault(); e.stopPropagation();
  const f=e.currentTarget, msg=f.querySelector("[data-form-message]"), btn=f.querySelector("button[type=submit]");
  msg.hidden=true; setBtn(btn,"Envoi…",true);
  try{
    const res=await api("/api/auth/forgot-password",{method:"POST",body:JSON.stringify({email:f.querySelector("[name=email]")?.value?.trim()||""})});
    formSuccess(msg,res.message); f.reset();
  }catch(err){formError(msg,err.message);}
  finally{setBtn(btn,"Envoyer le lien",false);}
});

bindForm("reset-form", async function(e){
  e.preventDefault(); e.stopPropagation();
  const f=e.currentTarget, msg=f.querySelector("[data-form-message]"), btn=f.querySelector("button[type=submit]");
  msg.hidden=true; setBtn(btn,"Mise à jour…",true);
  try{
    const res=await api("/api/auth/reset-password",{method:"POST",body:JSON.stringify({
      token:f.querySelector("[name=token]")?.value||"",
      password:f.querySelector("[name=password]")?.value||""
    })});
    formSuccess(msg,res.message+" Redirection…");
    setTimeout(()=>location.href="/connexion",2000);
  }catch(err){formError(msg,err.message);}
  finally{setBtn(btn,"Réinitialiser",false);}
});

/* ════ Jauge de robustesse du mot de passe ════ */
const COMMON_PW=new Set(["123456789","1234567890","password","password1","motdepasse","azerty123","qwerty123","azertyuiop","qwertyuiop","iloveyou","bonjour123","kova1234","cameroun","douala237","abcd1234","admin123","welcome1","soleil123","jetaime123"]);
function passwordAdvice(pw,email,name){
  if(!pw) return {score:0,label:"",tips:[]};
  const tips=[];
  if(pw.length<10) tips.push("10 caractères minimum");
  const classes=[/[a-z]/,/[A-Z]/,/[0-9]/,/[^a-zA-Z0-9]/].filter(r=>r.test(pw)).length;
  if(classes<2) tips.push("mélangez lettres, chiffres ou symboles");
  if(new Set(pw).size<5) tips.push("évitez les répétitions");
  const flat=pw.toLowerCase().replace(/[^a-z0-9@]/g,"");
  if(COMMON_PW.has(flat)||/^(?:0123456789|abcdefghijklmnopqrstuvwxyz|azertyuiop|qwertyuiop)/.test(flat)&&flat.length>=6) tips.push("trop courant ou trop prévisible");
  const local=(email||"").split("@")[0].toLowerCase().replace(/[^a-z0-9]/g,"");
  if(local.length>=4&&flat.includes(local)) tips.push("ne pas contenir votre e-mail");
  const nm=(name||"").toLowerCase().replace(/[^a-z0-9]/g,"");
  if(nm.length>=4&&flat.includes(nm)) tips.push("ne pas contenir votre pseudo");
  let score=Math.min(4,Math.floor(pw.length/4)+classes-1);
  if(tips.length) score=Math.min(score,1);
  return {score:Math.max(0,score),label:["Très faible","Faible","Moyen","Bon","Excellent"][Math.max(0,score)],tips};
}
document.querySelectorAll("input[data-strength]").forEach(input=>{
  const box=document.createElement("div"); box.className="pw-meter"; box.hidden=true;
  box.innerHTML='<div class="pw-bar"><span></span></div><small class="pw-text"></small>';
  (input.closest(".password-wrap")||input).after(box);
  const form=input.closest("form");
  const update=()=>{
    const r=passwordAdvice(input.value,form?.querySelector("[name=email]")?.value,form?.querySelector("[name=display_name]")?.value);
    box.hidden=!input.value; box.dataset.score=String(r.score);
    box.querySelector(".pw-bar span").style.width=((r.score+1)*20)+"%";
    box.querySelector(".pw-text").textContent=r.label+(r.tips.length?" — "+r.tips.join(", "):"");
  };
  input.addEventListener("input",update);
});

/* Compte à rebours (page de maintenance) */
document.querySelectorAll("[data-countdown-end]").forEach(el=>{
  const end=Date.parse(el.dataset.countdownEnd); if(Number.isNaN(end)) return;
  const p=n=>String(n).padStart(2,"0");
  const tick=()=>{const s=Math.max(0,Math.floor((end-Date.now())/1000));el.textContent=p(Math.floor(s/3600))+":"+p(Math.floor(s%3600/60))+":"+p(s%60);if(s<=0)setTimeout(()=>location.reload(),1500);};
  tick(); setInterval(tick,1000);
});

/* Diagnostic des assets (page debug) */
(function(){
  const el=document.getElementById("asset-runtime-check"); if(!el) return;
  window.addEventListener("load",()=>{
    const css=[...document.styleSheets].some(s=>s.href&&s.href.includes("/assets/css/kova.css"));
    el.textContent="Navigateur : CSS chargé = "+(css?"OUI":"NON")+" | JS chargé = OUI";
    el.className="runtime-check "+(css?"runtime-ok":"runtime-fail");
  });
})();

/* ════ Déconnexion ════ */
document.querySelectorAll('a[href="/deconnexion"]').forEach(link=>{
  link.addEventListener("click", async function(e){
    // Laisser le lien GET fonctionner si le navigateur n'a pas de JS.
    if(!window.fetch) return;
    e.preventDefault();
    const original=link.textContent;
    link.setAttribute("aria-busy","true");
    link.textContent="Déconnexion…";
    try{
      await api("/api/auth/logout",{method:"POST"});
      window.location.replace("/");
    }catch(_){
      // Fallback serveur : /deconnexion détruit également la session.
      window.location.href="/deconnexion";
    }finally{
      link.removeAttribute("aria-busy");
      link.textContent=original;
    }
  });
});

/* ════ Password toggle ════ */
document.querySelectorAll("[data-password-toggle]").forEach(btn=>{
  btn.addEventListener("click",()=>{
    const input=btn.closest(".password-wrap")?.querySelector("input");
    if(input) input.type=input.type==="password"?"text":"password";
  });
});

/* ════ Icônes & helpers partagés ════ */
const ICONS={
  like:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10v10H4V10h3Zm0 0 3-7a2 2 0 0 1 2 2v3h5.5a2 2 0 0 1 2 2l-1 7.5a3 3 0 0 1-3 2.5H7"/></svg>',
  comment:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5a8 8 0 0 1-8 8 8.7 8.7 0 0 1-3.8-.9L4 20l1.4-4.1A8 8 0 1 1 20 11.5Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/></svg>',
  share:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 8-8 8"/><path d="M4 12h15"/></svg>',
  edit:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 4.5-1 10-10-3.5-3.5-10 10L4 20Z"/><path d="m13.5 6.5 3.5 3.5"/></svg>',
  trash:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-9 0 1 13h10l1-13M10 11v6M14 11v6"/></svg>',
  reply:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 17-5-5 5-5"/><path d="M4 12h9a7 7 0 0 1 7 7"/></svg>',
  profile:'<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>',
  flag:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 21V4h11l-1.5 4L16 12H5"/></svg>',
  lock:'<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
  globe:'<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>',
  users:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  bell:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>',
  bag:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14l-1 12H6L5 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>',
  camera:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h3l1.5-2h7L17 7h3v12H4z"/><circle cx="12" cy="13" r="3.5"/></svg>',
  send:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 16-8-6 16-3-6-7-2Z"/></svg>',
  heart:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 10c0 5.6-7 10-7 10Z"/></svg>'
};
function avatarMarkup(url,name,cls='avatar'){return url?'<img class="'+cls+' avatar-image" src="'+escapeHtml(url)+'" alt="" loading="lazy">':'<div class="'+cls+'">'+escapeHtml((name||'K').charAt(0).toUpperCase())+'</div>';}
function publicProfileUrl(id){return '/profil?user='+encodeURIComponent(id);}
function formatContent(text){
  return escapeHtml(text).replace(/(^|[\s(>])#([\p{L}\p{N}_]{2,40})/gu,(m,pre,tag)=>pre+'<a class="hashtag" href="/recherche?q=%23'+encodeURIComponent(tag)+'">#'+tag+'</a>');
}

function openModal(title,html,opts={}){
  const wrap=document.createElement('div'); wrap.className='kova-modal';
  wrap.innerHTML='<div class="card kova-modal-card" role="dialog" aria-modal="true" style="width:min('+(opts.width||520)+'px,100%)"><div class="panel-title"><h3>'+escapeHtml(title)+'</h3><button type="button" class="icon-button" data-modal-close aria-label="Fermer">×</button></div><div class="kova-modal-body">'+html+'</div></div>';
  document.body.appendChild(wrap);
  const onKey=e=>{if(e.key==='Escape')close();};
  function close(){wrap.remove();document.removeEventListener('keydown',onKey);}
  document.addEventListener('keydown',onKey);
  wrap.addEventListener('mousedown',e=>{if(e.target===wrap)close();});
  wrap.querySelector('[data-modal-close]').onclick=close;
  return {wrap,close,body:wrap.querySelector('.kova-modal-body')};
}

/* Signalement (publication, commentaire, profil, groupe…) */
function openReportModal(targetType,targetId){
  const m=openModal('Signaler',
    '<form class="form-stack" id="_rep_form">'+
      '<div class="form-field"><label>Motif</label><select name="reason">'+
        '<option>Spam ou publicité</option><option>Harcèlement ou haine</option><option>Contenu inapproprié</option><option>Fausse information</option><option>Usurpation d’identité</option><option>Autre</option>'+
      '</select></div>'+
      '<div class="form-field"><label>Détails (facultatif)</label><textarea name="details" rows="3" maxlength="2000"></textarea></div>'+
      '<div class="form-message" data-form-message hidden></div>'+
      '<button class="button button-primary" type="submit">Envoyer le signalement</button>'+
    '</form>');
  const f=m.body.querySelector('#_rep_form');
  f.addEventListener('submit',async e=>{
    e.preventDefault(); const msg=f.querySelector('[data-form-message]'); const btn=f.querySelector('button[type=submit]'); setBtn(btn,'Envoi…',true);
    try{
      await api('/api/reports',{method:'POST',body:JSON.stringify({target_type:targetType,target_id:targetId,reason:f.reason.value,details:f.details.value})});
      m.close(); toast('Signalement transmis à l’administration.','success');
    }catch(err){formError(msg,err.message); setBtn(btn,'Envoyer le signalement',false);}
  });
}

/* ════ Commentaires (fil, groupes, communautés, profils) ════ */
async function renderCommentsThread(listEl, postId, opts={}){
  listEl.innerHTML='<p class="muted">Chargement…</p>';
  try{
    const d=await api('/api/posts/'+encodeURIComponent(postId)+'/comments');
    const comments=d.comments||[];
    const byParent={};
    comments.forEach(c=>{const k=c.parent_id||'root';(byParent[k]??=[]).push(c);});
    const canDelete=c=>c.is_mine||opts.canModerate||opts.postIsMine;
    const render=(parent,depth=0)=>(byParent[parent]||[]).map(c=>'<div class="comment-item '+(depth?'comment-reply':'')+'" data-comment-id="'+escapeHtml(c.id)+'">'+avatarMarkup(c.author_avatar,c.author_name,'avatar avatar-sm')+'<div class="comment-main"><a href="'+escapeHtml(publicProfileUrl(c.user_id))+'"><strong>'+escapeHtml(c.author_name||'Utilisateur')+'</strong></a><span class="user-content">'+escapeHtml(c.content)+'</span><div class="comment-actions"><button type="button" data-comment-like aria-pressed="'+(c.liked_by_me?'true':'false')+'">'+ICONS.like+' <span>'+String(c.like_count||0)+'</span></button><button type="button" data-comment-reply>'+ICONS.reply+' Répondre</button>'+(canDelete(c)?'<button type="button" data-comment-delete>'+ICONS.trash+' Supprimer</button>':'')+(!c.is_mine?'<button type="button" data-comment-report>'+ICONS.flag+' Signaler</button>':'')+'<small>'+timeAgo(c.created_at)+'</small></div><div class="reply-form-wrap" hidden></div>'+render(c.id,depth+1)+'</div></div>').join('');
    listEl.innerHTML=render('root')||'<p class="muted">Aucun commentaire pour le moment.</p>';
    listEl.querySelectorAll('[data-comment-like]').forEach(btn=>btn.addEventListener('click',async()=>{const id=btn.closest('[data-comment-id]').dataset.commentId;try{const r=await api('/api/comments/react',{method:'POST',body:JSON.stringify({comment_id:id})});btn.setAttribute('aria-pressed',r.liked_by_me?'true':'false');btn.querySelector('span').textContent=r.like_count;}catch(err){toast(err.message,'error');}}));
    listEl.querySelectorAll('[data-comment-report]').forEach(btn=>btn.addEventListener('click',()=>openReportModal('comment',btn.closest('[data-comment-id]').dataset.commentId)));
    listEl.querySelectorAll('[data-comment-delete]').forEach(btn=>btn.addEventListener('click',async()=>{
      if(!confirm('Supprimer ce commentaire ?'))return;
      try{await api('/api/comments/'+encodeURIComponent(btn.closest('[data-comment-id]').dataset.commentId),{method:'DELETE'});if(opts.onCommentDeleted)opts.onCommentDeleted();await renderCommentsThread(listEl,postId,opts);}catch(err){toast(err.message,'error');}
    }));
    listEl.querySelectorAll('[data-comment-reply]').forEach(btn=>btn.addEventListener('click',()=>{
      const item=btn.closest('[data-comment-id]'),wrap=item.querySelector(':scope > .comment-main > .reply-form-wrap');if(!wrap)return;
      wrap.hidden=!wrap.hidden;
      if(!wrap.hidden){
        wrap.innerHTML='<form class="comment-form reply-form"><input maxlength="1000" placeholder="Répondre…" required><button class="button button-soft" type="submit">Répondre</button></form>';
        wrap.querySelector('input').focus();
        wrap.querySelector('form').addEventListener('submit',async e=>{e.preventDefault();const input=e.currentTarget.querySelector('input');const text=input.value.trim();if(!text)return;try{await api('/api/posts/'+encodeURIComponent(postId)+'/comments',{method:'POST',body:JSON.stringify({content:text,parent_id:item.dataset.commentId})});if(opts.onCommentAdded)opts.onCommentAdded();await renderCommentsThread(listEl,postId,opts);}catch(err){toast(err.message,'error');}});
      }
    }));
  }catch(err){
    listEl.innerHTML='<p class="muted">'+escapeHtml(err.message)+'</p>';
  }
}

function wireCommentForm(formEl, listEl, postId, onPosted, opts={}){
  if(!formEl) return;
  formEl.addEventListener('submit',async function(e){
    e.preventDefault();
    const input=formEl.querySelector('input');
    const text=input.value.trim();
    if(!text)return;
    try{
      await api('/api/posts/'+encodeURIComponent(postId)+'/comments',{method:'POST',body:JSON.stringify({content:text})});
      input.value='';
      if(onPosted) onPosted();
      await renderCommentsThread(listEl,postId,opts);
    }catch(err){toast(err.message,'error');}
  });
}

/* ════ Publication ════ */
function renderPost(p,opts={}){
  const el=document.createElement('article'); el.className='card post-card'; el.dataset.postId=p.id; el.id='post-'+p.id;
  const isMine=!!(window.KOVA_USER_ID && p.user_id===window.KOVA_USER_ID);
  const canMod=!!(opts.canModerate||p.can_moderate);
  const full=String(p.content||''); const hasText=full.trim().length>0;
  const disclosureMap={self:'',assisted:'Assisté par IA',generated:'Généré par IA',unknown:''};
  const disc=p.ai_disclosure&&disclosureMap[p.ai_disclosure]?'<span class="ai-badge">'+escapeHtml(disclosureMap[p.ai_disclosure])+'</span>':'';
  let extra='';
  if(isMine) extra='<button type="button" data-edit-post>'+ICONS.edit+' Modifier</button><button type="button" data-delete-post>'+ICONS.trash+' Supprimer</button>';
  else extra=(canMod?'<button type="button" data-delete-post>'+ICONS.trash+' Supprimer</button>':'')+'<button type="button" data-report-post>'+ICONS.flag+' Signaler</button>';
  const image=p.image_url?'<div class="post-image-wrap"><img class="post-image" src="'+escapeHtml(p.image_url)+'" alt="Image de la publication" loading="lazy"></div>':'';
  el.innerHTML='<div class="post-head">'+avatarMarkup(p.author_avatar,p.author_name)+'<div class="post-author"><a href="'+escapeHtml(publicProfileUrl(p.user_id))+'"><strong>'+escapeHtml(p.author_name||'Utilisateur')+'</strong></a><small>'+timeAgo(p.created_at)+(p.edited_at?' · modifié':'')+'</small></div>'+disc+'</div>'+
    '<div class="post-content user-content '+(hasText?'is-collapsed':'')+'" data-post-content>'+formatContent(full)+'</div>'+
    (hasText?'<button type="button" class="see-more" data-see-more>Voir plus</button>':'')+image+
    '<div class="post-actions">'+
      '<button type="button" data-react data-post-id="'+escapeHtml(p.id)+'" data-type="like" aria-pressed="'+(p.liked_by_me?'true':'false')+'">'+ICONS.like+' J’aime <span data-like-count>'+(p.like_count||0)+'</span></button>'+
      '<button type="button" data-toggle-comments>'+ICONS.comment+' Commenter <span data-comment-count>'+(p.comments_count||0)+'</span></button>'+
      '<button type="button" data-share-post>'+ICONS.share+' Partager</button>'+extra+'</div>'+
    '<div class="comments-section" data-comments hidden><div class="comments-list" data-comments-list></div><form class="comment-form" data-comment-form><input type="text" maxlength="1000" placeholder="Écrire un commentaire…" data-comment-input required><button class="button button-soft" type="submit">Envoyer</button></form></div>';

  if(hasText){
    const toggle=el.querySelector('[data-see-more]');
    const content=el.querySelector('[data-post-content]');
    if(toggle&&content){
      toggle.style.display='none';
      const measure=()=>{
        if(!content.classList.contains('is-collapsed')){toggle.style.display='inline-block';return;}
        const hasOverflow=content.scrollHeight>content.clientHeight+1;
        toggle.style.display=hasOverflow?'inline-block':'none';
        if(hasOverflow){toggle.textContent='Voir plus';toggle.setAttribute('aria-expanded','false');}
      };
      requestAnimationFrame(()=>requestAnimationFrame(measure));
      toggle.addEventListener('click',function(){
        const collapsed=content.classList.contains('is-collapsed');
        content.classList.toggle('is-collapsed',!collapsed);
        this.textContent=collapsed?'Voir moins':'Voir plus';
        this.setAttribute('aria-expanded',collapsed?'true':'false');
      });
      if(window.ResizeObserver){new ResizeObserver(measure).observe(content);}else{window.addEventListener('resize',measure,{passive:true});}
    }
  }
  el.querySelector('[data-react]')?.addEventListener('click',async function(){
    try{const d=await api('/api/posts/react',{method:'POST',body:JSON.stringify({post_id:p.id,type:'like'})});p.liked_by_me=d.liked_by_me;p.like_count=d.like_count;this.setAttribute('aria-pressed',d.liked_by_me?'true':'false');this.querySelector('[data-like-count]').textContent=d.like_count;}catch(err){toast(err.message,'error');}
  });
  el.querySelector('[data-share-post]')?.addEventListener('click',async()=>{
    const base=opts.shareUrl||('/app#post-'+p.id);
    const link=location.origin+base;
    try{await navigator.clipboard?.writeText(link);toast('Lien copié.','success');}catch(_){window.prompt('Copiez ce lien :',link);}
  });
  el.querySelector('[data-report-post]')?.addEventListener('click',()=>openReportModal('post',p.id));

  const commentsBox=el.querySelector('[data-comments]'),commentsList=el.querySelector('[data-comments-list]');let commentsLoaded=false;
  const bump=d=>()=>{p.comments_count=Math.max(0,(p.comments_count||0)+d);const c=el.querySelector('[data-comment-count]');if(c)c.textContent=p.comments_count;};
  const cOpts={canModerate:canMod,postIsMine:isMine,onCommentAdded:bump(1),onCommentDeleted:bump(-1)};
  el.querySelector('[data-toggle-comments]')?.addEventListener('click',()=>{commentsBox.hidden=!commentsBox.hidden;if(!commentsBox.hidden&&!commentsLoaded){commentsLoaded=true;renderCommentsThread(commentsList,p.id,cOpts);}});
  wireCommentForm(el.querySelector('[data-comment-form]'),commentsList,p.id,bump(1),cOpts);

  el.querySelector('[data-delete-post]')?.addEventListener('click',async()=>{
    if(!confirm('Supprimer cette publication ?'))return;
    try{await api('/api/posts/'+encodeURIComponent(p.id),{method:'DELETE'});el.remove();if(opts.onDeleted)opts.onDeleted(p);toast('Publication supprimée.','success');}catch(err){toast(err.message,'error');}
  });
  el.querySelector('[data-edit-post]')?.addEventListener('click',function(){
    const contentEl=el.querySelector('[data-post-content]');if(el.querySelector('[data-edit-form]'))return;
    const wrap=document.createElement('div');wrap.className='form-stack';wrap.dataset.editForm='';
    wrap.innerHTML='<textarea rows="4" maxlength="5000" data-edit-textarea>'+escapeHtml(p.content||'')+'</textarea><div class="post-edit-actions"><button type="button" class="button button-primary" data-save-edit>Enregistrer</button><button type="button" class="button button-soft" data-cancel-edit>Annuler</button></div>';
    contentEl.replaceWith(wrap);
    wrap.querySelector('[data-cancel-edit]').onclick=()=>wrap.replaceWith(contentEl);
    wrap.querySelector('[data-save-edit]').onclick=async()=>{
      const newText=wrap.querySelector('[data-edit-textarea]').value.trim();
      if(!newText)return toast('Le contenu ne peut pas être vide.','error');
      try{await api('/api/posts/'+encodeURIComponent(p.id),{method:'PUT',body:JSON.stringify({content:newText})});p.content=newText;contentEl.innerHTML=formatContent(newText);wrap.replaceWith(contentEl);toast('Publication modifiée.','success');}catch(err){toast(err.message,'error');}
    };
  });
  return el;
}

/* ════ Fil d'actualité ════ */
let feedFilter='';
async function loadFeed(){
  const root=document.getElementById("feed"); if(!root) return;
  try{
    const data=await api("/api/posts"+(feedFilter?"?filter="+encodeURIComponent(feedFilter):""));
    root.innerHTML="";
    const posts=(data.posts||[]).filter(p=>!p.deleted);
    if(!posts.length){
      root.innerHTML=feedFilter==="following"
        ?'<article class="card post-card"><strong>Rien à afficher pour le moment.</strong><p class="muted">Abonnez-vous à des personnes depuis leur profil (ou via la recherche) pour voir leurs publications ici.</p></article>'
        :'<article class="card post-card"><strong>Votre fil est prêt.</strong><p class="muted">Créez votre première publication pour commencer.</p></article>';
      return;
    }
    posts.forEach(p=>root.appendChild(renderPost(p)));
    if(location.hash.startsWith("#post-")){
      const t=document.getElementById(location.hash.slice(1));
      if(t){t.scrollIntoView({behavior:"smooth",block:"center"});t.classList.add("post-highlight");}
    }
  }catch(err){
    root.innerHTML='<article class="card post-card"><strong>Erreur</strong><p class="muted">'+escapeHtml(err.message)+'</p></article>';
    showError("Feed",err.message);
  }
}
if(document.getElementById("feed")) loadFeed();
document.querySelectorAll("[data-feed-filter]").forEach(tab=>tab.addEventListener("click",()=>{
  feedFilter=tab.dataset.feedFilter||"";
  document.querySelectorAll("[data-feed-filter]").forEach(t=>t.setAttribute("aria-selected",t===tab?"true":"false"));
  loadFeed();
}));

/* Composer (fil général, groupes, communautés) : endpoint et rappel configurables. */
function openComposer(opts={}){
  if(document.querySelector(".composer-modal")) return;
  const endpoint=opts.endpoint||"/api/posts";
  const wrap=document.createElement("div"); wrap.className="composer-modal kova-modal";
  wrap.innerHTML=
    '<div class="card kova-modal-card" style="width:min(620px,100%)">'+
      '<div class="panel-title"><h3>'+escapeHtml(opts.title||"Nouvelle publication")+'</h3><button class="icon-button" id="_comp_close" aria-label="Fermer">×</button></div>'+
      '<div class="form-stack" id="_comp_body">'+
        '<div class="form-field"><label>Contenu</label><textarea id="_comp_txt" rows="6" maxlength="5000" placeholder="Qu\'avez-vous envie de partager ?"></textarea></div>'+
        '<div class="form-field"><label>Image (facultatif)</label>'+
          '<input type="file" id="_comp_img" accept="image/png,image/jpeg,image/webp,image/gif">'+
          '<div id="_comp_img_preview" class="composer-image-preview" hidden><img id="_comp_img_preview_img" alt=""><button type="button" id="_comp_img_remove" class="icon-button">×</button></div>'+
        '</div>'+
        '<div class="form-field"><label>Origine</label>'+
          '<select id="_comp_disc">'+
            '<option value="self">Créé entièrement par moi</option>'+
            '<option value="assisted">Créé avec l\'aide d\'une IA</option>'+
            '<option value="generated">Généré principalement par une IA</option>'+
            '<option value="unknown">Je ne sais pas</option>'+
          '</select>'+
        '</div>'+
        '<div class="form-message" id="_comp_msg" hidden></div>'+
        '<button class="button button-primary" id="_comp_submit">Publier</button>'+
      '</div>'+
    '</div>';
  document.body.appendChild(wrap);
  wrap.querySelector("#_comp_close").onclick=()=>wrap.remove();
  wrap.addEventListener("mousedown",e=>{if(e.target===wrap)wrap.remove();});

  const imgInput=document.getElementById("_comp_img");
  const imgPreview=document.getElementById("_comp_img_preview");
  const imgPreviewImg=document.getElementById("_comp_img_preview_img");
  imgInput.addEventListener("change", ()=>{
    const file=imgInput.files?.[0];
    if(!file){ imgPreview.hidden=true; return; }
    if(file.size>8*1024*1024){ toast("Image trop lourde (8 Mo maximum).","error"); imgInput.value=""; imgPreview.hidden=true; return; }
    imgPreviewImg.src=URL.createObjectURL(file);
    imgPreview.hidden=false;
  });
  document.getElementById("_comp_img_remove").onclick=()=>{ imgInput.value=""; imgPreview.hidden=true; };

  wrap.querySelector("#_comp_submit").onclick=async()=>{
    const txt=(document.getElementById("_comp_txt")?.value||"").trim();
    const disc=document.getElementById("_comp_disc")?.value||"self";
    const msg=document.getElementById("_comp_msg");
    const btn=document.getElementById("_comp_submit");
    const file=imgInput.files?.[0]||null;
    if(!txt){ msg.hidden=false; msg.className="form-message error"; msg.textContent="Le contenu est obligatoire."; return; }
    setBtn(btn,"Publication…",true);
    try{
      if(file){
        const fd=new FormData();
        fd.append("content",txt); fd.append("ai_disclosure",disc); fd.append("image",file);
        await apiUpload(endpoint,fd,"POST");
      }else{
        await api(endpoint,{method:"POST",body:JSON.stringify({content:txt,ai_disclosure:disc})});
      }
      wrap.remove(); (opts.onDone||loadFeed)(); toast("Publication créée !","success");
    }catch(err){msg.hidden=false;msg.className="form-message error";msg.textContent=err.message; setBtn(btn,"Publier",false);}
  };
  setTimeout(()=>document.getElementById("_comp_txt")?.focus(),50);
}
["btn-open-composer","btn-open-composer-2","btn-open-composer-3"].forEach(id=>{
  document.getElementById(id)?.addEventListener("click",()=>openComposer());
});

/* ════ Badges (notifications + messages) ════ */
async function loadUnreadBadge(){
  const set=(name,n)=>document.querySelectorAll("[data-badge="+name+"]").forEach(b=>{b.textContent=n>99?"99+":String(n);b.hidden=n===0;});
  try{ set("notifications",Number((await api("/api/notifications/unread-count")).count||0)); }catch(_){}
  try{ set("messages",Number((await api("/api/messages/unread-count")).count||0)); }catch(_){}
}
if(window.KOVA_USER_ID){ loadUnreadBadge(); setInterval(()=>{ if(!document.hidden) loadUnreadBadge(); },30000); }

/* ════ Notifications ════ */
const NOTIF_ICON={like:'like',comment:'comment',reply:'comment',follow:'profile',group_request:'users',group_join:'users',group_approved:'users',group_rejected:'users',group_removed:'users',order_new:'bag',order_status:'bag'};
async function loadNotifications(){
  const list=document.getElementById("notification-list"); if(!list) return;
  try{
    const d=await api("/api/notifications");
    const notifs=d.notifications||[];
    if(!notifs.length){ list.innerHTML='<div class="empty-panel"><strong>Aucune notification</strong><p>Les activités importantes apparaîtront ici.</p></div>'; return; }
    list.innerHTML=notifs.map(n=>{
      const inner='<span class="notif-icon">'+(ICONS[NOTIF_ICON[n.type]]||ICONS.bell)+'</span><div><p>'+escapeHtml(n.message||"")+'</p><small>'+timeAgo(n.created_at)+'</small></div>';
      const cls='notif-item'+(n.read?" notif-read":"");
      return n.link?'<a class="'+cls+'" href="'+escapeHtml(n.link)+'" data-notif-id="'+escapeHtml(n.id)+'" data-unread="'+(n.read?'0':'1')+'">'+inner+'</a>':'<div class="'+cls+'">'+inner+'</div>';
    }).join("");
    list.querySelectorAll("a[data-notif-id]").forEach(a=>a.addEventListener("click",async e=>{
      if(a.dataset.unread!=="1") return;
      e.preventDefault();
      try{ await api("/api/notifications/"+encodeURIComponent(a.dataset.notifId)+"/read",{method:"POST"}); }catch(_){}
      location.href=a.getAttribute("href");
    }));
  }catch(err){showError("Notifs",err.message);}
}
if(document.getElementById("notification-list")) loadNotifications();

document.getElementById("btn-read-all-notifs")?.addEventListener("click",async()=>{
  try{ await api("/api/notifications/read-all",{method:"POST"}); toast("Tout marqué lu","success"); loadNotifications(); loadUnreadBadge(); }
  catch(err){ toast(err.message,"error"); }
});

/* ════ Recherche globale (tableau de bord, barre du haut, page /recherche) ════ */
function searchResultsHtml(d,full){
  const sec=(title,items)=>items.length?'<div class="search-section"><h4>'+title+'</h4>'+items.join('')+'</div>':'';
  const users=(d.users||[]).map(u=>'<a class="search-row" href="'+escapeHtml(publicProfileUrl(u.id))+'">'+avatarMarkup(u.avatar_url,u.display_name,'avatar avatar-sm')+'<span><strong>'+escapeHtml(u.display_name)+'</strong>'+(full&&u.bio?'<small class="user-content">'+escapeHtml(u.bio)+'</small>':'')+'</span></a>');
  const spaceRow=(kind,s)=>'<a class="search-row" href="/'+(kind==='g'?'groupes?group=':'communautes?community=')+encodeURIComponent(s.id)+'">'+avatarMarkup(s.avatar_url,s.name,'avatar avatar-sm')+'<span><strong>'+escapeHtml(s.name)+'</strong><small>'+s.member_count+' membre'+(s.member_count>1?'s':'')+(s.visibility==='private'?' · Privé':'')+'</small></span></a>';
  const groups=(d.groups||[]).map(s=>spaceRow('g',s));
  const comms=(d.communities||[]).map(s=>spaceRow('c',s));
  const posts=(d.posts||[]).map(p=>'<a class="search-row" href="'+escapeHtml(publicProfileUrl(p.user_id))+'#post-'+encodeURIComponent(p.id)+'">'+avatarMarkup(p.author_avatar,p.author_name,'avatar avatar-sm')+'<span><strong>'+escapeHtml(p.author_name)+'</strong><small class="user-content">'+escapeHtml(p.excerpt)+'</small></span></a>');
  const products=(d.products||[]).map(p=>'<a class="search-row" href="/boutique?q='+encodeURIComponent(p.title)+'"><span class="search-thumb">'+(p.image_url?'<img src="'+escapeHtml(p.image_url)+'" alt="" loading="lazy">':ICONS.bag)+'</span><span><strong class="user-content">'+escapeHtml(p.title)+'</strong><small>'+Number(p.price).toLocaleString('fr-FR')+' '+escapeHtml(p.currency)+' · '+escapeHtml(p.seller_name)+'</small></span></a>');
  const html=sec('Personnes',users)+sec('Groupes',groups)+sec('Communautés',comms)+sec('Publications',posts)+sec('Produits',products);
  return html||'<p class="muted search-empty">Aucun résultat.</p>';
}
function debounce(fn,ms){let t;return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),ms);};}

// Barre du haut : liste déroulante rapide
(function(){
  const box=document.querySelector(".top-search"); const input=box?.querySelector("input"); if(!input) return;
  box.style.position="relative";
  const dd=document.createElement("div"); dd.className="search-dropdown"; dd.hidden=true; box.appendChild(dd);
  let seq=0;
  const close=()=>{dd.hidden=true;};
  input.addEventListener("input",debounce(async()=>{
    const q=input.value.trim();
    if(q.length<2){close();return;}
    const my=++seq;
    try{const d=await api("/api/search?limit=4&q="+encodeURIComponent(q)); if(my!==seq) return; dd.innerHTML=searchResultsHtml(d,false)+'<a class="search-all" href="/recherche?q='+encodeURIComponent(q)+'">Voir tous les résultats</a>'; dd.hidden=false;}catch(_){}
  },250));
  input.addEventListener("keydown",e=>{
    if(e.key==="Enter"&&input.value.trim().length>=2){location.href="/recherche?q="+encodeURIComponent(input.value.trim());}
    if(e.key==="Escape"){close();input.blur();}
  });
  document.addEventListener("click",e=>{if(!box.contains(e.target))close();});
})();

/* Recherche « tout-en-un » avec filtres : tableau de bord et page /recherche */
function initSearchPanel(root,opts){
  const input=root.querySelector("input[type=search]"), out=root.querySelector("[data-search-out]"); if(!input||!out) return;
  const chips=[...root.querySelectorAll("[data-search-type]")];
  let type=opts.type||"", seq=0;
  const run=async()=>{
    const q=input.value.trim();
    if(q.length<2){ out.hidden=!opts.alwaysShow; out.innerHTML=opts.alwaysShow?'<p class="muted">Saisissez au moins 2 caractères.</p>':""; opts.onIdle&&opts.onIdle(); return; }
    const my=++seq; out.hidden=false; out.innerHTML='<p class="muted">Recherche…</p>';
    try{
      const d=await api("/api/search?limit="+(opts.limit||8)+"&q="+encodeURIComponent(q)+(type?"&type="+encodeURIComponent(type):""));
      if(my!==seq) return;
      out.innerHTML=searchResultsHtml(d,!!opts.full)+(opts.moreLink?'<a class="search-all" href="/recherche?q='+encodeURIComponent(q)+(type?"&type="+encodeURIComponent(type):"")+'">Voir tous les résultats</a>':"");
      opts.onResults&&opts.onResults();
    }catch(err){ out.innerHTML='<p class="muted">'+escapeHtml(err.message)+'</p>'; }
  };
  chips.forEach(c=>c.addEventListener("click",()=>{
    type=c.dataset.searchType||""; chips.forEach(x=>x.setAttribute("aria-selected",x===c?"true":"false"));
    const url=new URL(location.href); if(opts.syncUrl){ if(type) url.searchParams.set("type",type); else url.searchParams.delete("type"); history.replaceState(null,"",url); }
    run();
  }));
  chips.forEach(c=>c.setAttribute("aria-selected",(c.dataset.searchType||"")===type?"true":"false"));
  input.addEventListener("input",debounce(run,250));
  input.form?.addEventListener("submit",e=>{e.preventDefault();run();});
  if(input.value.trim().length>=2) run();
  return {run};
}
(function(){
  const home=document.getElementById("home-search");
  if(home){
    const feedWrap=document.getElementById("feed-area");
    initSearchPanel(home,{limit:8,moreLink:true,onResults:()=>{feedWrap&&(feedWrap.hidden=true);},onIdle:()=>{feedWrap&&(feedWrap.hidden=false);}});
  }
  const page=document.getElementById("search-page");
  if(page){
    const params=new URLSearchParams(location.search);
    const input=page.querySelector("input[type=search]"); input.value=params.get("q")||"";
    initSearchPanel(page,{limit:20,full:true,alwaysShow:true,syncUrl:true,type:params.get("type")||""});
  }
})();

/* ════ Support ════ */
async function loadSupportTickets(){
  const list=document.getElementById("support-tickets-list"); if(!list) return;
  try{
    const d=await api("/api/support/tickets");
    const tickets=d.tickets||[];
    if(!tickets.length){ list.innerHTML='<p class="muted">Aucune demande pour le moment.</p>'; return; }
    const labels={new:"Nouveau",open:"Ouvert",in_progress:"En cours",closed:"Fermé",resolved:"Résolu"};
    list.innerHTML=tickets.map(t=>
      '<div style="padding:10px 0;border-bottom:1px solid var(--line)">'+
        '<div style="display:flex;justify-content:space-between"><strong>'+escapeHtml(t.subject)+'</strong>'+
        '<span style="font-size:.75rem;background:var(--primary);color:#fff;padding:2px 8px;border-radius:99px">'+escapeHtml(labels[t.status]||t.status)+'</span></div>'+
        '<p style="font-size:.8rem;color:var(--muted);margin-top:3px">'+escapeHtml(t.category)+' · '+timeAgo(t.created_at)+'</p>'+
      '</div>'
    ).join("");
  }catch(err){ list.innerHTML='<p class="muted">'+escapeHtml(err.message)+'</p>'; }
}

bindForm("support-form", async function(e){
  e.preventDefault(); e.stopPropagation();
  const f=e.currentTarget, msg=f.querySelector("[data-form-message]"), btn=f.querySelector("button[type=submit]");
  msg.hidden=true; setBtn(btn,"Envoi…",true);
  try{
    await api("/api/support/tickets",{method:"POST",body:JSON.stringify({
      category:f.querySelector("[name=category]")?.value||"technical",
      subject:f.querySelector("[name=subject]")?.value?.trim()||"",
      message:f.querySelector("[name=message]")?.value?.trim()||""
    })});
    formSuccess(msg,"Votre demande a été enregistrée."); f.reset(); loadSupportTickets();
  }catch(err){formError(msg,err.message);}
  finally{setBtn(btn,"Envoyer la demande",false);}
});
if(document.getElementById("support-tickets-list")) loadSupportTickets();

/* ════ PWA ════ */
if("serviceWorker" in navigator){
  navigator.serviceWorker.register("/sw.js").catch(e=>showError("PWA","Service worker",e.message));
}
let deferred;
window.addEventListener("beforeinstallprompt",e=>{
  e.preventDefault(); deferred=e;
  const b=document.querySelector("[data-install-pwa]");
  if(b){ b.hidden=false; b.onclick=async()=>{ deferred.prompt(); await deferred.userChoice; deferred=null; b.hidden=true; }; }
});


/* ════ API publique pour les modules de page (kova-*.js) ════ */
Object.assign(window.KOVA,{api,apiUpload,setCsrf,toast,escapeHtml,timeAgo,setBtn,formError,formSuccess,avatarMarkup,publicProfileUrl,formatContent,
  ICONS,openModal,openReportModal,renderPost,renderCommentsThread,wireCommentForm,openComposer,loadUnreadBadge,showError});

})();
