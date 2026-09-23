/* KOVA — Groupes (publics/privés) et communautés, façon Facebook.
   Groupe privé : la fiche est visible de tous, mais publications et membres sont réservés
   aux membres. Rejoindre = envoyer une demande, approuvée UNIQUEMENT par le créateur. */
(function(){
"use strict";
const K=window.KOVA; const root=document.getElementById("spaces-root"); if(!K||!root) return;
const {api,apiUpload,toast,escapeHtml,setBtn,formError,timeAgo,avatarMarkup,publicProfileUrl,ICONS,openModal,openReportModal,renderPost,openComposer}=K;

const kind=root.dataset.kind;                       // "groups" | "communities"
const isGroups=kind==="groups";
const L=isGroups
  ?{one:"groupe",the:"le groupe",This:"Ce groupe",param:"group",page:"/groupes",detailKey:"group",del:"Supprimer définitivement ce groupe ? Toutes ses publications seront supprimées.",leave:"Quitter ce groupe ?",created:"Groupe créé !"}
  :{one:"communauté",the:"la communauté",This:"Cette communauté",param:"community",page:"/communautes",detailKey:"community",del:"Supprimer définitivement cette communauté ? Toutes ses publications seront supprimées.",leave:"Quitter cette communauté ?",created:"Communauté créée !"};
const API="/api/"+kind;
const $=id=>document.getElementById(id);
const enc=encodeURIComponent;
const plural=(n,w)=>n+" "+w+(n>1?"s":"");

/* ─────────── Liste ─────────── */
function visLine(s){
  const priv=s.visibility==="private";
  return '<span class="vis-badge '+(priv?"is-private":"")+'">'+(priv?ICONS.lock:ICONS.globe)+(priv?"Privé":"Public")+'</span> · '+plural(s.member_count,"membre");
}
function actionButton(s){
  switch(s.viewer_status){
    case "owner": case "moderator": case "member": return '<button class="button button-soft" data-open="'+escapeHtml(s.id)+'">Ouvrir</button>';
    case "pending": return '<button class="button button-soft" data-cancel="'+escapeHtml(s.id)+'">Demande envoyée · Annuler</button>';
    default: return '<button class="button button-primary" data-join="'+escapeHtml(s.id)+'">'+(s.visibility==="private"?"Demander à rejoindre":"Rejoindre")+'</button>';
  }
}
function card(s){
  const cover=s.cover_url?' style="background-image:url(\''+String(s.cover_url).replace(/['"\\]/g,"")+'\')"':"";
  return '<article class="space-card card" data-card="'+escapeHtml(s.id)+'">'+
    '<div class="space-card-cover'+(s.cover_url?" has-image":"")+'"'+cover+'></div>'+
    '<div class="space-card-body">'+avatarMarkup(s.avatar_url,s.name,"avatar avatar-lg space-card-avatar")+
      '<a class="space-card-name" href="'+L.page+'?'+L.param+'='+enc(s.id)+'"><strong>'+escapeHtml(s.name)+'</strong></a>'+
      '<small class="space-card-meta">'+visLine(s)+'</small>'+
      (s.description?'<p class="space-card-desc user-content">'+escapeHtml(s.description)+'</p>':"")+
      '<div class="space-card-actions">'+actionButton(s)+
      (s.is_owner&&s.pending_count?'<span class="pill">'+plural(s.pending_count,"demande")+'</span>':"")+'</div>'+
    '</div></article>';
}

let allItems=[];
function renderList(){
  const q=($("space-search").value||"").trim().toLowerCase();
  const items=allItems.filter(s=>!q||(s.name+" "+s.description).toLowerCase().includes(q));
  const mine=items.filter(s=>s.is_member), pending=items.filter(s=>s.viewer_status==="pending"), other=items.filter(s=>!s.is_member&&s.viewer_status!=="pending");
  $("my-spaces").innerHTML=mine.length?mine.map(card).join(""):'<p class="muted">'+(isGroups?"Vous n’êtes membre d’aucun groupe pour le moment.":"Vous n’avez rejoint aucune communauté pour le moment.")+'</p>';
  $("pending-section").hidden=!pending.length; $("pending-spaces").innerHTML=pending.map(card).join("");
  $("discover-spaces").innerHTML=other.length?other.map(card).join(""):'<p class="muted">Rien à découvrir pour le moment.</p>';
  root.querySelectorAll("[data-open]").forEach(b=>b.onclick=()=>{location.href=L.page+"?"+L.param+"="+enc(b.dataset.open);});
  root.querySelectorAll("[data-join]").forEach(b=>b.onclick=()=>join(b.dataset.join,b));
  root.querySelectorAll("[data-cancel]").forEach(b=>b.onclick=()=>cancelRequest(b.dataset.cancel));
}
async function loadList(){
  try{allItems=(await api(API))[kind]||[];renderList();}
  catch(err){$("my-spaces").innerHTML='<p class="muted">'+escapeHtml(err.message)+'</p>';$("discover-spaces").innerHTML="";}
}
async function join(id,btn){
  if(btn) btn.disabled=true;
  try{
    const r=await api(API+"/"+enc(id)+"/join",{method:"POST"});
    toast(r.message||"Fait.","success");
    if(currentId) await openDetail(currentId); else await loadList();
  }catch(err){toast(err.message,"error");if(btn)btn.disabled=false;}
}
async function cancelRequest(id){
  try{await api(API+"/"+enc(id)+"/cancel-request",{method:"POST"});toast("Demande annulée.","success");if(currentId)await openDetail(currentId);else await loadList();}
  catch(err){toast(err.message,"error");}
}

/* ─────────── Création ─────────── */
$("btn-create-space").addEventListener("click",()=>{
  const vis=isGroups?
    '<div class="form-field"><label>Confidentialité</label><div class="vis-choice">'+
      '<label class="vis-option"><input type="radio" name="visibility" value="public" checked><span>'+ICONS.globe+'<strong>Public</strong><small>Tout le monde voit les publications. Rejoindre est immédiat.</small></span></label>'+
      '<label class="vis-option"><input type="radio" name="visibility" value="private"><span>'+ICONS.lock+'<strong>Privé</strong><small>Seuls les membres voient les publications. Chaque demande doit être approuvée par vous.</small></span></label>'+
    '</div></div>':"";
  const m=openModal(isGroups?"Créer un groupe":"Créer une communauté",
    '<form class="form-stack" id="_cs_form">'+
      '<div class="form-field"><label>Nom</label><input name="name" maxlength="60" minlength="2" required placeholder="'+(isGroups?"Nom du groupe":"Nom de la communauté")+'"></div>'+
      '<div class="form-field"><label>Description</label><textarea name="description" rows="3" maxlength="300" placeholder="De quoi s’agit-il ? (facultatif)"></textarea></div>'+vis+
      '<div class="form-message" data-form-message hidden></div><button class="button button-primary" type="submit">Créer</button></form>');
  const f=m.body.querySelector("#_cs_form"); f.name.focus();
  f.addEventListener("submit",async e=>{
    e.preventDefault();const msg=f.querySelector("[data-form-message]"),btn=f.querySelector("button[type=submit]");setBtn(btn,"Création…",true);
    try{
      const r=await api(API,{method:"POST",body:JSON.stringify({name:f.name.value.trim(),description:f.description.value.trim(),visibility:isGroups?f.visibility.value:"public"})});
      m.close();toast(L.created,"success");location.href=L.page+"?"+L.param+"="+enc(r[L.detailKey].id);
    }catch(err){formError(msg,err.message);setBtn(btn,"Créer",false);}
  });
});
$("space-search").addEventListener("input",renderList);

/* ─────────── Détail ─────────── */
let currentId=null, current=null, tab="posts";

function statusBox(s){
  if(s.viewer_status==="pending") return '<button class="button button-soft" data-act="cancel">Demande envoyée · Annuler</button>';
  if(s.viewer_status==="none") return '<button class="button button-primary" data-act="join">'+(s.visibility==="private"?"Demander à rejoindre":"Rejoindre")+'</button>';
  if(s.viewer_status==="owner") return '<button class="button button-soft" data-act="edit">'+ICONS.edit+' Modifier</button><button class="button button-soft danger-text" data-act="delete">'+ICONS.trash+' Supprimer</button>';
  return '<button class="button button-soft" data-act="leave">Quitter</button>';
}

async function openDetail(id,initialTab){
  currentId=id; if(initialTab) tab=initialTab;
  $("space-list-view").hidden=true;
  const box=$("space-detail"); box.hidden=false;
  if(!current) box.innerHTML='<p class="muted">Chargement…</p>';
  try{
    current=(await api(API+"/"+enc(id)))[L.detailKey];
  }catch(err){
    box.innerHTML='<div class="card" style="padding:28px"><h3>'+escapeHtml(L.This)+' est introuvable</h3><p class="muted">'+escapeHtml(err.message)+'</p><a class="button button-soft" href="'+L.page+'">Retour</a></div>';
    return;
  }
  document.title=current.name+" — KOVA";
  renderDetail();
}

function renderDetail(){
  const s=current, box=$("space-detail"), owner=s.is_owner;
  const cover=s.cover_url?' style="background-image:url(\''+String(s.cover_url).replace(/['"\\]/g,"")+'\')"':"";
  const tabs=[["posts","Discussion"],["about","À propos"]];
  if(s.can_view_content) tabs.push(["members","Membres"]);
  if(owner&&isGroups) tabs.push(["requests","Demandes"+(s.pending_count?" ("+s.pending_count+")":"")]);
  if(!tabs.some(t=>t[0]===tab)) tab="posts";

  box.innerHTML=
   '<a class="back-link" href="'+L.page+'">← '+(isGroups?"Tous les groupes":"Toutes les communautés")+'</a>'+
   '<section class="space-header card">'+
     '<div class="space-cover'+(s.cover_url?" has-image":"")+'"'+cover+'>'+(owner?'<label class="button button-soft space-cover-edit" for="_sp_cover">'+ICONS.camera+' Couverture</label><input id="_sp_cover" type="file" accept="image/jpeg,image/png,image/webp" hidden>':"")+'</div>'+
     '<div class="space-headbody">'+
       '<div class="space-avatar-wrap">'+avatarMarkup(s.avatar_url,s.name,"avatar avatar-xl")+(owner?'<label class="profile-avatar-edit" for="_sp_avatar" aria-label="Changer la photo">'+ICONS.camera+'</label><input id="_sp_avatar" type="file" accept="image/jpeg,image/png,image/webp" hidden>':"")+'</div>'+
       '<div class="space-title"><h2>'+escapeHtml(s.name)+'</h2><p class="space-meta">'+visLine(s)+' · créé par <a href="'+escapeHtml(publicProfileUrl(s.owner.id))+'">'+escapeHtml(s.owner.display_name)+'</a></p></div>'+
       '<div class="space-actions">'+statusBox(s)+'</div>'+
     '</div>'+
     '<nav class="space-tabs" role="tablist">'+tabs.map(t=>'<button type="button" role="tab" data-tab="'+t[0]+'" aria-selected="'+(t[0]===tab?"true":"false")+'">'+t[1]+'</button>').join("")+'</nav>'+
   '</section>'+
   '<div id="space-tab-content" class="space-content"></div>';

  box.querySelectorAll("[data-tab]").forEach(b=>b.onclick=()=>{tab=b.dataset.tab;history.replaceState(null,"",L.page+"?"+L.param+"="+enc(currentId)+"&tab="+tab);renderDetail();});
  box.querySelector('[data-act="join"]')?.addEventListener("click",e=>join(currentId,e.currentTarget));
  box.querySelector('[data-act="cancel"]')?.addEventListener("click",()=>cancelRequest(currentId));
  box.querySelector('[data-act="leave"]')?.addEventListener("click",async()=>{if(!confirm(L.leave))return;try{await api(API+"/"+enc(currentId)+"/leave",{method:"POST"});toast("Vous avez quitté.","success");location.href=L.page;}catch(err){toast(err.message,"error");}});
  box.querySelector('[data-act="delete"]')?.addEventListener("click",async()=>{if(!confirm(L.del))return;try{await api(API+"/"+enc(currentId),{method:"DELETE"});toast("Supprimé.","success");location.href=L.page;}catch(err){toast(err.message,"error");}});
  box.querySelector('[data-act="edit"]')?.addEventListener("click",openEdit);
  const up=(input,ep,label,max)=>input&&input.addEventListener("change",async()=>{const file=input.files[0];if(!file)return;if(file.size>max){toast(label+" trop lourde.","error");return;}try{const fd=new FormData();fd.append("image",file);await apiUpload(API+"/"+enc(currentId)+"/"+ep,fd);toast(label+" mise à jour.","success");await openDetail(currentId);}catch(err){toast(err.message,"error");}});
  up($("_sp_cover"),"cover","Couverture",8*1048576); up($("_sp_avatar"),"avatar","Photo",5*1048576);

  const c=$("space-tab-content");
  if(tab==="about") return renderAbout(c);
  if(tab==="members") return renderMembers(c);
  if(tab==="requests") return renderRequests(c);
  renderPosts(c);
}

function lockPanel(){
  const s=current;
  return '<div class="card lock-panel">'+
    '<div class="icon-large">'+ICONS.lock+'</div><h3>Ce groupe est privé</h3>'+
    '<p class="muted">Seuls les membres peuvent voir les publications et la liste des membres.<br>'+
    (s.viewer_status==="pending"?"Votre demande est en attente : le créateur du groupe doit l’approuver.":"Envoyez une demande : seul le créateur du groupe peut l’accepter.")+'</p>'+
    (s.viewer_status==="pending"?'<button class="button button-soft" data-act="cancel2">Annuler ma demande</button>':'<button class="button button-primary" data-act="join2">Demander à rejoindre</button>')+
  '</div>';
}
function bindLock(c){
  c.querySelector('[data-act="join2"]')?.addEventListener("click",e=>join(currentId,e.currentTarget));
  c.querySelector('[data-act="cancel2"]')?.addEventListener("click",()=>cancelRequest(currentId));
}

function renderAbout(c){
  const s=current, priv=s.visibility==="private";
  c.innerHTML='<div class="card" style="padding:24px"><h3>À propos</h3>'+
    (s.description?'<p class="user-content">'+escapeHtml(s.description)+'</p>':'<p class="muted">Aucune description.</p>')+
    '<div class="about-row">'+(priv?ICONS.lock:ICONS.globe)+'<div><strong>'+(priv?"Privé":"Public")+'</strong><small class="muted">'+(priv
      ?"Seuls les membres voient les publications. Les demandes d’adhésion sont approuvées par le créateur."
      :"Tout le monde peut voir les publications et rejoindre immédiatement.")+'</small></div></div>'+
    '<div class="about-row">'+ICONS.users+'<div><strong>'+plural(s.member_count,"membre")+'</strong></div></div>'+
    '<div class="about-row">'+ICONS.profile+'<div><strong>Créé par '+escapeHtml(s.owner.display_name)+'</strong><small class="muted">le '+(s.created_at?new Date(s.created_at).toLocaleDateString("fr-FR"):"—")+'</small></div></div>'+
    (s.is_member&&!s.is_owner?'':'')+
    '<div style="margin-top:14px"><button class="button button-soft" data-report>'+ICONS.flag+' Signaler '+L.the+'</button></div></div>';
  c.querySelector("[data-report]").onclick=()=>openReportModal(isGroups?"group":"community",currentId);
}

async function renderPosts(c){
  const s=current;
  if(!s.can_view_content){c.innerHTML=lockPanel();bindLock(c);return;}
  c.innerHTML=(s.can_post?'<section class="composer card"><div class="composer-main"><button class="composer-trigger" id="_sp_compose">Écrivez quelque chose pour '+(isGroups?"le groupe":"la communauté")+'…</button></div></section>':
      '<div class="card notice">Rejoignez '+L.the+' pour publier.</div>')+
    '<div id="space-feed" class="feed-list"><div class="feed-loading">Chargement des publications…</div></div>';
  $("_sp_compose")?.addEventListener("click",()=>openComposer({endpoint:API+"/"+enc(currentId)+"/posts",title:"Publier dans "+L.the,onDone:loadPosts}));
  await loadPosts();
}
async function loadPosts(){
  const feed=$("space-feed"); if(!feed) return;
  try{
    const d=await api(API+"/"+enc(currentId)+"/posts");
    feed.innerHTML="";
    if(!d.posts.length){feed.innerHTML='<article class="card post-card"><p class="muted">Aucune publication. Soyez le premier à lancer une discussion.</p></article>';return;}
    d.posts.forEach(p=>feed.appendChild(renderPost(p,{canModerate:d.can_moderate,shareUrl:L.page+"?"+L.param+"="+enc(currentId)+"#post-"+p.id})));
    if(location.hash.startsWith("#post-")){const t=document.getElementById(location.hash.slice(1));if(t){t.scrollIntoView({block:"center"});t.classList.add("post-highlight");}}
  }catch(err){feed.innerHTML='<div class="card notice">'+escapeHtml(err.message)+'</div>';}
}

async function renderMembers(c){
  c.innerHTML='<div class="card" style="padding:20px"><p class="muted">Chargement…</p></div>';
  try{
    const d=await api(API+"/"+enc(currentId)+"/members");
    const roleLabel={owner:"Créateur",moderator:"Modérateur",member:""};
    c.innerHTML='<div class="card" style="padding:8px 20px"><h3 style="margin:14px 0 4px">'+plural(d.members.length,"membre")+'</h3>'+d.members.map(m=>
      '<div class="member-row" data-uid="'+escapeHtml(m.id)+'">'+
        '<a class="member-id" href="'+escapeHtml(publicProfileUrl(m.id))+'">'+avatarMarkup(m.avatar_url,m.display_name,"avatar avatar-sm")+'<strong>'+escapeHtml(m.display_name)+'</strong>'+(roleLabel[m.role]?'<span class="pill">'+roleLabel[m.role]+'</span>':"")+'</a>'+
        (d.viewer_is_owner&&m.role!=="owner"?'<div class="member-actions"><button class="button button-soft" data-role="'+(m.role==="moderator"?"member":"moderator")+'">'+(m.role==="moderator"?"Retirer modérateur":"Nommer modérateur")+'</button><button class="button button-soft danger-text" data-remove>Exclure</button></div>':"")+
      '</div>').join("")+'</div>';
    c.querySelectorAll("[data-role]").forEach(b=>b.onclick=async()=>{const uid=b.closest("[data-uid]").dataset.uid;try{await api(API+"/"+enc(currentId)+"/members/"+enc(uid)+"/role",{method:"POST",body:JSON.stringify({role:b.dataset.role})});toast("Rôle mis à jour.","success");renderMembers(c);}catch(err){toast(err.message,"error");}});
    c.querySelectorAll("[data-remove]").forEach(b=>b.onclick=async()=>{if(!confirm("Exclure ce membre ?"))return;const uid=b.closest("[data-uid]").dataset.uid;try{await api(API+"/"+enc(currentId)+"/members/"+enc(uid)+"/remove",{method:"POST"});toast("Membre exclu.","success");await openDetail(currentId);}catch(err){toast(err.message,"error");}});
  }catch(err){c.innerHTML='<div class="card notice">'+escapeHtml(err.message)+'</div>';}
}

async function renderRequests(c){
  c.innerHTML='<div class="card" style="padding:20px"><p class="muted">Chargement…</p></div>';
  try{
    const d=await api(API+"/"+enc(currentId)+"/requests");
    c.innerHTML='<div class="card" style="padding:8px 20px"><h3 style="margin:14px 0 4px">Demandes d’adhésion</h3>'+
      (d.requests.length?d.requests.map(r=>'<div class="member-row" data-uid="'+escapeHtml(r.id)+'"><a class="member-id" href="'+escapeHtml(publicProfileUrl(r.id))+'">'+avatarMarkup(r.avatar_url,r.display_name,"avatar avatar-sm")+'<span><strong>'+escapeHtml(r.display_name)+'</strong><small class="muted">'+timeAgo(r.requested_at)+'</small></span></a><div class="member-actions"><button class="button button-primary" data-decide="approve">Approuver</button><button class="button button-soft" data-decide="reject">Refuser</button></div></div>').join(""):'<p class="muted" style="padding:10px 0 16px">Aucune demande en attente.</p>')+'</div>';
    c.querySelectorAll("[data-decide]").forEach(b=>b.onclick=async()=>{
      const uid=b.closest("[data-uid]").dataset.uid;b.disabled=true;
      try{await api(API+"/"+enc(currentId)+"/requests/"+enc(uid)+"/"+b.dataset.decide,{method:"POST"});toast(b.dataset.decide==="approve"?"Membre accepté.":"Demande refusée.","success");await openDetail(currentId);}
      catch(err){toast(err.message,"error");b.disabled=false;}
    });
  }catch(err){c.innerHTML='<div class="card notice">'+escapeHtml(err.message)+'</div>';}
}

function openEdit(){
  const s=current;
  const vis=isGroups?'<div class="form-field"><label>Confidentialité</label><select name="visibility"><option value="public"'+(s.visibility==="public"?" selected":"")+'>Public</option><option value="private"'+(s.visibility==="private"?" selected":"")+'>Privé</option></select><small class="field-hint">Passer en public accepte automatiquement les demandes en attente.</small></div>':"";
  const m=openModal("Modifier",'<form class="form-stack" id="_ed_form"><div class="form-field"><label>Nom</label><input name="name" maxlength="60" minlength="2" required value="'+escapeHtml(s.name)+'"></div><div class="form-field"><label>Description</label><textarea name="description" rows="3" maxlength="300">'+escapeHtml(s.description||"")+'</textarea></div>'+vis+'<div class="form-message" data-form-message hidden></div><button class="button button-primary" type="submit">Enregistrer</button></form>');
  const f=m.body.querySelector("#_ed_form");
  f.addEventListener("submit",async e=>{
    e.preventDefault();const msg=f.querySelector("[data-form-message]");
    try{await api(API+"/"+enc(currentId)+"/update",{method:"POST",body:JSON.stringify({name:f.name.value.trim(),description:f.description.value.trim(),visibility:isGroups?f.visibility.value:undefined})});m.close();toast("Modifications enregistrées.","success");await openDetail(currentId);}
    catch(err){formError(msg,err.message);}
  });
}

/* ─────────── Démarrage ─────────── */
const qs=new URLSearchParams(location.search);
const wanted=qs.get(L.param);
if(wanted){ openDetail(wanted,qs.get("tab")||"posts"); } else { loadList(); }
})();
