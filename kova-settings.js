/* KOVA — Paramètres : sécurité (mot de passe, 2FA, sessions), apparence, notifications,
   confidentialité, applications connectées, export/suppression des données. */
(function(){
"use strict";
const K=window.KOVA; if(!K||!document.getElementById("settings-root")) return;
const {api,toast,escapeHtml,timeAgo,avatarMarkup,publicProfileUrl,openModal,formError,setBtn}=K;
const $=id=>document.getElementById(id);
const enc=encodeURIComponent;
const SCOPE={profile:"Profil (nom, photo, bio)",email:"Adresse e-mail"};
let sec=null;

/* ── Fenêtre « action sensible » : mot de passe (+ code 2FA si nécessaire) ── */
function secureAction({title,text,needCode,label,danger,run}){
  const m=openModal(title,
    '<form class="form-stack" id="_sa">'+(text?'<p class="muted">'+escapeHtml(text)+'</p>':'')+
    '<div class="form-field"><label>Mot de passe actuel</label><input name="password" type="password" required autocomplete="current-password"></div>'+
    (needCode?'<div class="form-field"><label>Code de double authentification (ou code de secours)</label><input name="code" inputmode="text" autocomplete="one-time-code" maxlength="11" required></div>':'')+
    '<div class="form-message" data-form-message hidden></div>'+
    '<button class="button '+(danger?'button-danger':'button-primary')+'" type="submit">'+escapeHtml(label||"Confirmer")+'</button></form>',{width:440});
  const f=m.body.querySelector("#_sa"); f.password.focus();
  f.addEventListener("submit",async e=>{
    e.preventDefault(); const msg=f.querySelector("[data-form-message]"), btn=f.querySelector("button[type=submit]"); msg.hidden=true; setBtn(btn,"Patientez…",true);
    try{ await run({password:f.password.value,code:f.code?f.code.value:"",close:m.close,modal:m}); }
    catch(err){ formError(msg,err.message); setBtn(btn,label||"Confirmer",false); }
  });
}
async function copy(text){ try{await navigator.clipboard.writeText(text);toast("Copié.","success");}catch(_){window.prompt("Copiez :",text);} }

/* ── Sécurité ── */
async function loadSecurity(){
  try{
    sec=await api("/api/account/security");
    $("sec-summary").innerHTML='Compte <strong>'+escapeHtml(sec.email)+'</strong> · '+(sec.email_verified?'<span class="pill pill-ok">e-mail vérifié</span>':'<span class="pill pill-danger">e-mail non vérifié</span>')+
      (sec.last_login?' · dernière connexion '+timeAgo(sec.last_login):'');
    const box=$("twofa-actions");
    if(sec.twofa_enabled){
      $("twofa-desc").innerHTML='<span class="pill pill-ok">Activée</span> Un code est demandé à chaque connexion. Codes de secours restants : <strong>'+sec.backup_left+'</strong>.';
      box.innerHTML='<button class="button button-soft" id="btn-2fa-codes" type="button">Nouveaux codes de secours</button><button class="button button-soft danger-text" id="btn-2fa-off" type="button">Désactiver</button>';
      $("btn-2fa-codes").onclick=()=>secureAction({title:"Nouveaux codes de secours",text:"Les anciens codes cesseront de fonctionner.",needCode:true,label:"Générer",run:async c=>{const r=await api("/api/account/2fa/backup-codes",{method:"POST",body:JSON.stringify({password:c.password,code:c.code})});c.close();showBackupCodes(r.backup_codes);loadSecurity();}});
      $("btn-2fa-off").onclick=()=>secureAction({title:"Désactiver la double authentification",text:"Votre compte sera moins protégé.",needCode:true,label:"Désactiver",danger:true,run:async c=>{const r=await api("/api/account/2fa/disable",{method:"POST",body:JSON.stringify({password:c.password,code:c.code})});c.close();toast(r.message,"success");loadSecurity();}});
    }else{
      $("twofa-desc").textContent="Protège votre compte même si votre mot de passe est volé : un code temporaire est demandé à chaque connexion.";
      box.innerHTML='<button class="button button-primary" id="btn-2fa-on" type="button">Activer</button>';
      $("btn-2fa-on").onclick=start2fa;
    }
    if(sec.is_admin&&!sec.twofa_enabled) $("twofa-desc").innerHTML+=' <strong class="danger-text">Fortement recommandé pour un compte administrateur.</strong>';
  }catch(err){ $("sec-summary").textContent=err.message; }
}

function start2fa(){
  secureAction({title:"Activer la double authentification",text:"Pour commencer, confirmez votre mot de passe.",label:"Continuer",run:async c=>{
    const r=await api("/api/account/2fa/setup",{method:"POST",body:JSON.stringify({password:c.password})});
    c.close();
    const m=openModal("Activer la double authentification",
      '<ol class="steps-list"><li>Ouvrez votre application d’authentification (Google Authenticator, Authy, Aegis, 1Password, Microsoft Authenticator…).</li>'+
      '<li>Ajoutez un compte avec cette clé :<div class="secret-box"><code>'+escapeHtml(r.secret)+'</code><button class="button button-soft" id="_cp" type="button">Copier</button></div>'+
      '<a class="button button-soft" href="'+escapeHtml(r.uri)+'">Ouvrir dans mon application (téléphone)</a></li>'+
      '<li>Saisissez le code à 6 chiffres affiché :</li></ol>'+
      '<form class="form-stack" id="_e2"><div class="form-field"><input class="code-input" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" placeholder="000000" required></div>'+
      '<div class="form-message" data-form-message hidden></div><button class="button button-primary" type="submit">Activer</button></form>',{width:500});
    m.body.querySelector("#_cp").onclick=()=>copy(r.secret.replace(/\s+/g,""));
    const f=m.body.querySelector("#_e2"); f.code.focus();
    f.addEventListener("submit",async e=>{
      e.preventDefault(); const msg=f.querySelector("[data-form-message]"), btn=f.querySelector("button[type=submit]"); setBtn(btn,"Vérification…",true);
      try{ const x=await api("/api/account/2fa/enable",{method:"POST",body:JSON.stringify({code:f.code.value})}); m.close(); showBackupCodes(x.backup_codes); loadSecurity(); }
      catch(err){ formError(msg,err.message); setBtn(btn,"Activer",false); }
    });
  }});
}

function showBackupCodes(codes){
  const m=openModal("Vos codes de secours",
    '<p class="muted">Chaque code ne sert <strong>qu’une seule fois</strong> si vous perdez votre téléphone. Conservez-les dans un endroit sûr : <strong>ils ne seront plus affichés</strong>.</p>'+
    '<div class="backup-codes">'+codes.map(c=>'<code>'+escapeHtml(c)+'</code>').join("")+'</div>'+
    '<div class="setting-actions"><button class="button button-soft" id="_bc" type="button">Copier</button><button class="button button-soft" id="_bd" type="button">Télécharger</button></div>'+
    '<label class="toggle-row" style="margin-top:12px"><span>J’ai conservé ces codes</span><input type="checkbox" id="_bok"></label>'+
    '<button class="button button-primary" id="_bclose" type="button" disabled>Terminer</button>',{width:460});
  m.body.querySelector("#_bc").onclick=()=>copy(codes.join("\n"));
  m.body.querySelector("#_bd").onclick=()=>{const a=document.createElement("a");a.href=URL.createObjectURL(new Blob(["Codes de secours KOVA\n\n"+codes.join("\n")+"\n"],{type:"text/plain"}));a.download="kova-codes-de-secours.txt";document.body.appendChild(a);a.click();a.remove();};
  m.body.querySelector("#_bok").onchange=e=>{m.body.querySelector("#_bclose").disabled=!e.target.checked;};
  m.body.querySelector("#_bclose").onclick=m.close;
}

$("btn-logout-all").onclick=()=>secureAction({title:"Déconnecter tous les appareils",text:"Toutes les autres sessions seront fermées immédiatement.",label:"Tout déconnecter",run:async c=>{
  const r=await api("/api/account/sessions/logout-all",{method:"POST",body:JSON.stringify({password:c.password})}); K.setCsrf&&K.setCsrf(r.csrf); c.close(); toast(r.message,"success");
}});

/* ── Apparence / langue ── */
$("theme-select").addEventListener("change",async e=>{
  const theme=e.target.value;
  try{await api("/api/settings",{method:"POST",body:JSON.stringify({theme})});document.documentElement.dataset.theme=theme;toast("Thème appliqué.","success");}
  catch(err){toast(err.message,"error");}
});
$("lang-select").addEventListener("change",async e=>{
  try{await api("/api/settings",{method:"POST",body:JSON.stringify({language:e.target.value})});location.reload();}
  catch(err){toast(err.message,"error");}
});

/* ── Notifications ── */
function renderPush(){
  const P=K.push, box=$("push-actions"), desc=$("push-desc"); if(!P){ box.textContent=""; return; }
  const st=P.status();
  if(!st.supported){ desc.textContent="Ce navigateur ne prend pas en charge les notifications."; box.innerHTML=""; return; }
  if(st.permission==="denied"){ desc.textContent="Les notifications sont bloquées pour ce site. Autorisez-les dans les réglages du navigateur (icône cadenas › Notifications), puis rechargez la page."; box.innerHTML=""; return; }
  if(st.ios && !st.pushSupported){ desc.textContent="Sur iPhone/iPad : touchez Partager puis « Sur l’écran d’accueil », ouvrez KOVA depuis l’icône, puis activez les notifications ici."; }
  if(st.enabled){
    box.innerHTML='<span class="pill pill-ok">Activées</span><button class="button button-soft" id="push-test" type="button">Envoyer un test</button><button class="button button-soft" id="push-off" type="button">Désactiver</button>';
    $("push-off").onclick=async()=>{await P.disable();toast("Notifications désactivées sur cet appareil.","success");renderPush();};
    $("push-test").onclick=async()=>{
      const b=$("push-test"); b.disabled=true;
      try{ const r=await P.sendTest(); toast(r.message||"Test envoyé.","success"); }
      catch(err){ toast(err.message,"error"); }
      finally{ b.disabled=false; }
    };
  }else{
    box.innerHTML='<button class="button button-primary" id="push-on" type="button">Activer</button>';
    $("push-on").onclick=async()=>{
      try{ const r=await P.enable(); toast(r.pushed?"Notifications activées sur cet appareil.":"Notifications activées (tant que KOVA est ouvert).","success"); }
      catch(err){ toast(err.message,"error"); }
      renderPush();
    };
  }
}
setTimeout(renderPush,50);   // kova-push.js se charge après ce module

document.querySelectorAll("[data-pref]").forEach(cb=>cb.addEventListener("change",async()=>{
  try{await api("/api/settings",{method:"POST",body:JSON.stringify({prefs:{[cb.dataset.pref]:cb.checked}})});toast("Préférence enregistrée.","success");}
  catch(err){cb.checked=!cb.checked;toast(err.message,"error");}
}));

/* ── Confidentialité ── */
$("msgperm-select").addEventListener("change",async e=>{
  try{await api("/api/settings",{method:"POST",body:JSON.stringify({message_permission:e.target.value})});toast("Réglage enregistré.","success");}catch(err){toast(err.message,"error");}
});
$("discoverable").addEventListener("change",async e=>{
  try{await api("/api/settings",{method:"POST",body:JSON.stringify({discoverable:e.target.checked})});toast("Réglage enregistré.","success");}
  catch(err){e.target.checked=!e.target.checked;toast(err.message,"error");}
});

async function loadGrants(){
  const box=$("grants-list");
  try{
    const d=await api("/api/oauth/grants");
    if(!d.grants.length){box.innerHTML='<p class="muted">Aucune application connectée.</p>';return;}
    box.innerHTML=d.grants.map(g=>'<div class="member-row" data-cid="'+escapeHtml(g.client_id)+'"><div><strong>'+escapeHtml(g.name)+'</strong>'+(g.verified?' <span class="pill pill-ok">Vérifiée</span>':' <span class="pill">Non vérifiée</span>')+
      '<br><small class="muted">Accès : '+g.scopes.map(s=>escapeHtml(SCOPE[s]||s)).join(", ")+' · depuis '+timeAgo(g.granted_at)+'</small></div><button class="button button-soft danger-text" data-revoke>Révoquer</button></div>').join("");
    box.querySelectorAll("[data-revoke]").forEach(b=>b.onclick=async()=>{
      if(!confirm("Retirer l’accès de cette application ?"))return;
      try{await api("/api/oauth/grants/"+enc(b.closest("[data-cid]").dataset.cid)+"/revoke",{method:"POST"});toast("Accès révoqué.","success");loadGrants();}
      catch(err){toast(err.message,"error");}
    });
  }catch(err){box.innerHTML='<p class="muted">'+escapeHtml(err.message)+'</p>';}
}
async function loadBlocks(){
  const box=$("blocks-list");
  try{
    const d=await api("/api/blocks");
    if(!d.blocks.length){box.innerHTML='<p class="muted">Vous n’avez bloqué personne.</p>';return;}
    box.innerHTML=d.blocks.map(u=>'<div class="member-row" data-uid="'+escapeHtml(u.id)+'"><a class="member-id" href="'+escapeHtml(publicProfileUrl(u.id))+'">'+avatarMarkup(u.avatar_url,u.display_name,"avatar avatar-sm")+'<strong>'+escapeHtml(u.display_name)+'</strong></a><button class="button button-soft" data-unblock>Débloquer</button></div>').join("");
    box.querySelectorAll("[data-unblock]").forEach(b=>b.onclick=async()=>{
      try{await api("/api/users/"+enc(b.closest("[data-uid]").dataset.uid)+"/unblock",{method:"POST"});toast("Utilisateur débloqué.","success");loadBlocks();}
      catch(err){toast(err.message,"error");}
    });
  }catch(err){box.innerHTML='<p class="muted">'+escapeHtml(err.message)+'</p>';}
}

/* ── Mes données ── */
$("btn-export").onclick=async()=>{
  const b=$("btn-export"); b.disabled=true;
  try{
    const csrf=document.querySelector('meta[name="kova-csrf"]')?.content||"";
    const r=await fetch("/api/privacy/export",{credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest","X-KOVA-CSRF":csrf}});
    if(!r.ok){const d=await r.json().catch(()=>({}));throw Error(d.error||"Export impossible.");}
    const url=URL.createObjectURL(await r.blob()); const a=document.createElement("a"); a.href=url; a.download="kova-mes-donnees.json"; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    toast("Votre export a été téléchargé.","success");
  }catch(err){toast(err.message,"error");}
  finally{b.disabled=false;}
};
$("btn-request").onclick=()=>{
  const m=openModal("Exercer un droit",
    '<form class="form-stack" id="_rq"><div class="form-field"><label>Type de demande</label><select name="type"><option value="access">Accès à mes données</option><option value="rectification">Rectification</option><option value="erasure">Effacement</option><option value="restriction">Limitation du traitement</option><option value="objection">Opposition</option><option value="portability">Portabilité</option><option value="consent">Retrait du consentement</option></select></div>'+
    '<div class="form-field"><label>Précisions (facultatif)</label><textarea name="message" rows="4" maxlength="3000"></textarea></div><div class="form-message" data-form-message hidden></div><button class="button button-primary" type="submit">Envoyer</button></form>');
  const f=m.body.querySelector("#_rq");
  f.addEventListener("submit",async e=>{
    e.preventDefault(); const msg=f.querySelector("[data-form-message]");
    try{const d=await api("/api/privacy/request",{method:"POST",body:JSON.stringify({type:f.type.value,message:f.message.value})});m.close();toast(d.message||"Demande enregistrée.","success");}
    catch(err){formError(msg,err.message);}
  });
};
$("btn-delete").onclick=()=>{
  secureAction({title:"Supprimer mon compte",text:"Action définitive : votre compte, vos publications, vos messages et vos fichiers seront supprimés ou anonymisés.",needCode:!!(sec&&sec.twofa_enabled),label:"Supprimer définitivement",danger:true,run:async c=>{
    const r=await api("/api/privacy/delete",{method:"POST",body:JSON.stringify({password:c.password,code:c.code})});
    c.close(); toast(r.message||"Compte supprimé.","success"); setTimeout(()=>location.href="/",900);
  }});
};

/* ── Chargement initial ── */
(async function(){
  try{
    const s=await api("/api/settings");
    $("lang-select").value=s.language; $("theme-select").value=s.theme; $("msgperm-select").value=s.message_permission; $("discoverable").checked=!!s.discoverable;
    document.querySelectorAll("[data-pref]").forEach(cb=>cb.checked=s.prefs[cb.dataset.pref]!==false);
  }catch(err){toast(err.message,"error");}
  await loadSecurity();
  if(new URLSearchParams(location.search).get("setup2fa")==="1"&&sec&&!sec.twofa_enabled) start2fa();
})();
loadGrants(); loadBlocks();
})();
