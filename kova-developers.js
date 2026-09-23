/* KOVA — Espace développeur : enregistrement des applications « Continuer avec KOVA ». */
(function(){
"use strict";
const K=window.KOVA; if(!K||!document.getElementById("dev-root")) return;
const {api,toast,escapeHtml,setBtn,formError,openModal}=K;
const $=id=>document.getElementById(id);
const enc=encodeURIComponent;
let apps=[], canCreate=true;

async function copy(text){try{await navigator.clipboard.writeText(text);toast("Copié.","success");}catch(_){window.prompt("Copiez :",text);}}

async function load(){
  try{
    const d=await api("/api/developer/apps"); apps=d.apps; canCreate=d.can_create;
    $("btn-new-app").hidden=!canCreate;
    const box=$("apps-list");
    if(!apps.length){box.innerHTML='<div class="card empty-panel"><strong>Aucune application</strong><p>'+(canCreate?"Cliquez sur « Nouvelle application » pour enregistrer votre première plateforme.":"La création d’applications est réservée aux administrateurs.")+'</p></div>';return;}
    box.innerHTML=apps.map(a=>
      '<article class="card app-card" data-cid="'+escapeHtml(a.client_id)+'"><div class="app-head"><div><strong>'+escapeHtml(a.name)+'</strong> '+
        (a.verified?'<span class="pill pill-ok">Vérifiée</span>':'<span class="pill">Non vérifiée</span>')+(a.disabled?' <span class="pill pill-danger">Désactivée</span>':"")+' <span class="pill">'+(a.type==="public"?"Publique (PKCE)":"Confidentielle")+'</span>'+
        (a.description?'<br><small class="muted">'+escapeHtml(a.description)+'</small>':"")+'</div></div>'+
        '<div class="app-field"><small class="muted">Client ID</small><code>'+escapeHtml(a.client_id)+'</code><button class="button button-soft" data-copy="'+escapeHtml(a.client_id)+'">Copier</button></div>'+
        '<div class="app-field"><small class="muted">URLs de retour</small><span>'+a.redirect_uris.map(u=>'<code>'+escapeHtml(u)+'</code>').join("<br>")+'</span></div>'+
        '<small class="muted">'+a.users_count+' utilisateur'+(a.users_count>1?"s":"")+' connecté'+(a.users_count>1?"s":"")+' avec KOVA</small>'+
        '<div class="app-actions"><button class="button button-soft" data-edit>Modifier</button>'+(a.type==="confidential"?'<button class="button button-soft" data-secret>Régénérer le secret</button>':"")+'<button class="button button-soft danger-text" data-del>Supprimer</button></div></article>').join("");
    box.querySelectorAll("[data-copy]").forEach(b=>b.onclick=()=>copy(b.dataset.copy));
    box.querySelectorAll("[data-cid]").forEach(card=>{
      const a=apps.find(x=>x.client_id===card.dataset.cid);
      card.querySelector("[data-edit]").onclick=()=>form(a);
      card.querySelector("[data-secret]")?.addEventListener("click",async()=>{
        if(!confirm("Générer un nouveau secret ? L’ancien cessera immédiatement de fonctionner."))return;
        try{const r=await api("/api/developer/apps/"+enc(a.client_id)+"/secret",{method:"POST"});showSecret(a.client_id,r.client_secret);}catch(err){toast(err.message,"error");}
      });
      card.querySelector("[data-del]").onclick=async()=>{
        if(!confirm("Supprimer « "+a.name+" » ? Tous les accès accordés seront révoqués."))return;
        try{await api("/api/developer/apps/"+enc(a.client_id),{method:"DELETE"});toast("Application supprimée.","success");load();}catch(err){toast(err.message,"error");}
      };
    });
  }catch(err){$("apps-list").innerHTML='<div class="card notice">'+escapeHtml(err.message)+'</div>';}
}

function showSecret(clientId,secret){
  const m=openModal("Identifiants de l’application",
    '<p class="muted">Copiez le <strong>secret</strong> maintenant et conservez-le côté serveur uniquement : il ne sera <strong>plus jamais affiché</strong>.</p>'+
    '<div class="app-field"><small class="muted">Client ID</small><code>'+escapeHtml(clientId)+'</code></div>'+
    (secret?'<div class="app-field"><small class="muted">Client secret</small><code id="_sec">'+escapeHtml(secret)+'</code><button class="button button-soft" id="_cp">Copier</button></div>':'<p class="muted">Application publique : pas de secret (PKCE obligatoire).</p>')+
    '<button class="button button-primary" id="_ok">J’ai copié mes identifiants</button>',{width:560});
  m.body.querySelector("#_cp")?.addEventListener("click",()=>copy(secret));
  m.body.querySelector("#_ok").onclick=()=>{m.close();load();};
}

function form(a){
  const m=openModal(a?"Modifier l’application":"Nouvelle application",
    '<form class="form-stack" id="_af">'+
      '<div class="form-field"><label>Nom de votre plateforme</label><input name="name" required minlength="2" maxlength="60" value="'+escapeHtml(a?a.name:"")+'"><small class="field-hint">Affiché aux utilisateurs sur l’écran d’autorisation.</small></div>'+
      '<div class="form-field"><label>Description (facultatif)</label><input name="description" maxlength="200" value="'+escapeHtml(a?a.description:"")+'"></div>'+
      '<div class="form-field"><label>Site web (facultatif)</label><input name="website" type="url" maxlength="200" placeholder="https://" value="'+escapeHtml(a?a.website:"")+'"></div>'+
      '<div class="form-field"><label>URLs de retour (une par ligne)</label><textarea name="redirect_uris" rows="3" required placeholder="https://votre-site.com/callback">'+escapeHtml(a?a.redirect_uris.join("\n"):"")+'</textarea><small class="field-hint">https:// obligatoire (http://localhost accepté pour vos tests). Comparaison exacte.</small></div>'+
      (a?"":'<div class="form-field"><label>Type</label><select name="type"><option value="confidential">Confidentielle — site avec serveur (secret gardé côté serveur)</option><option value="public">Publique — application web sans serveur ou mobile (PKCE)</option></select></div>')+
      '<div class="form-message" data-form-message hidden></div><button class="button button-primary" type="submit">'+(a?"Enregistrer":"Créer l’application")+'</button></form>',{width:560});
  const f=m.body.querySelector("#_af");
  f.addEventListener("submit",async e=>{
    e.preventDefault();const msg=f.querySelector("[data-form-message]"),btn=f.querySelector("button[type=submit]");setBtn(btn,"Envoi…",true);
    const body={name:f.name.value.trim(),description:f.description.value.trim(),website:f.website.value.trim(),redirect_uris:f.redirect_uris.value};
    try{
      if(a){await api("/api/developer/apps/"+enc(a.client_id)+"/update",{method:"POST",body:JSON.stringify(body)});m.close();toast("Application mise à jour.","success");load();}
      else{body.type=f.type.value;const r=await api("/api/developer/apps",{method:"POST",body:JSON.stringify(body)});m.close();showSecret(r.app.client_id,r.client_secret);}
    }catch(err){formError(msg,err.message);setBtn(btn,a?"Enregistrer":"Créer l’application",false);}
  });
}
$("btn-new-app").onclick=()=>form(null);
load();
})();
