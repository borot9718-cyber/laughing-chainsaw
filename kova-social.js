/* KOVA — Profil : lecture seule pour les autres, modification réservée au propriétaire,
   abonnements, blocage, signalement, publications du profil. */
(function(){
"use strict";
const K=window.KOVA; if(!K||!document.getElementById("profile-display-name")) return;
const {api,apiUpload,toast,escapeHtml,setBtn,formError,formSuccess,avatarMarkup,publicProfileUrl,ICONS,openModal,openReportModal,renderPost}=K;
let profile=null;
const $=id=>document.getElementById(id);
const setText=(id,v)=>{const el=$(id);if(el)el.textContent=v;};

async function load(){
  const wanted=new URLSearchParams(location.search).get("user")||"";
  try{
    profile=(await api("/api/profile/view"+(wanted?"?user_id="+encodeURIComponent(wanted):""))).user;
  }catch(err){
    $("profile-display-name").textContent="Profil introuvable";
    $("profile-bio").textContent=err.message;
    $("profile-posts-section").hidden=true;
    return;
  }
  render();
  if(!profile.is_blocked) loadPosts(); else $("profile-posts").innerHTML='<p class="muted">Vous avez bloqué cet utilisateur.</p>';
}

function render(){
  const u=profile, own=u.is_me;
  const name=u.display_name||"Utilisateur";
  document.title=name+" — KOVA";
  setText("profile-title",own?"Votre profil":name);
  setText("profile-subtitle",own?"Gérez votre identité et personnalisez votre présence.":"Profil public");
  setText("profile-display-name",name);
  setText("profile-bio",u.bio||"Aucune bio pour le moment.");
  setText("profile-email",own?(u.email||"—"):"Profil public");
  setText("profile-since",u.created_at?new Date(u.created_at).toLocaleDateString("fr-FR"):"—");
  setText("stat-posts",u.posts_count);
  setText("stat-followers",u.followers_count);
  setText("stat-following",u.following_count);

  const av=$("profile-avatar-display");
  if(av) av.outerHTML=avatarMarkup(u.avatar_url,name,"avatar avatar-xl").replace('class="','id="profile-avatar-display" class="');
  const cover=$("profile-cover-display");
  cover.style.backgroundImage=u.cover_url?'url("'+String(u.cover_url).replace(/["\\]/g,"")+'")':"";
  cover.classList.toggle("has-image",!!u.cover_url);

  // Éléments propriétaire : visibles UNIQUEMENT sur son propre profil.
  $("profile-avatar-edit").hidden=!own;
  $("profile-cover-actions").hidden=!own;
  $("password").hidden=!own;

  const actions=$("profile-actions");
  if(own){
    actions.innerHTML='<button class="button button-soft" id="btn-edit-profile">Modifier le profil</button>';
    $("btn-edit-profile").onclick=openEdit;
  }else if(u.is_blocked){
    actions.innerHTML='<button class="button button-soft" id="btn-unblock">Débloquer</button>';
    $("btn-unblock").onclick=()=>toggleBlock(false);
  }else{
    actions.innerHTML=
      '<button class="button '+(u.is_following?"button-soft":"button-primary")+'" id="btn-follow">'+(u.is_following?"Abonné(e)":"Suivre")+'</button>'+
      '<a class="button button-soft" href="/messages?to='+encodeURIComponent(u.id)+'">'+ICONS.comment+' Message</a>'+
      '<button class="button button-soft" id="btn-more" aria-label="Plus d’actions">⋯</button>';
    $("btn-follow").onclick=toggleFollow;
    $("btn-more").onclick=openMore;
  }
}

async function toggleFollow(){
  const btn=$("btn-follow");
  try{
    const path="/api/users/"+encodeURIComponent(profile.id)+"/"+(profile.is_following?"unfollow":"follow");
    const r=await api(path,{method:"POST"});
    profile.is_following=r.following;
    profile.followers_count+=r.following?1:-1;
    render();
  }catch(err){toast(err.message,"error");}
}

function openMore(){
  const m=openModal("Actions",
    '<div class="form-stack"><button class="button button-soft" id="_m_report">'+ICONS.flag+' Signaler ce profil</button>'+
    '<button class="button button-soft danger-text" id="_m_block">Bloquer '+escapeHtml(profile.display_name)+'</button>'+
    '<p class="muted" style="font-size:.8rem">Bloquer masque ses publications et empêche tout message ou abonnement entre vous.</p></div>',{width:420});
  m.body.querySelector("#_m_report").onclick=()=>{m.close();openReportModal("profile",profile.id);};
  m.body.querySelector("#_m_block").onclick=async()=>{m.close();if(confirm("Bloquer cet utilisateur ?"))toggleBlock(true);};
}
async function toggleBlock(block){
  try{await api("/api/users/"+encodeURIComponent(profile.id)+"/"+(block?"block":"unblock"),{method:"POST"});toast(block?"Utilisateur bloqué.":"Utilisateur débloqué.","success");await load();}
  catch(err){toast(err.message,"error");}
}

function openEdit(){
  const m=openModal("Modifier le profil",
    '<form class="form-stack" id="_ep_form">'+
      '<div class="form-field"><label>Nom affiché</label><input name="display_name" maxlength="60" required minlength="2" value="'+escapeHtml(profile.display_name||"")+'"></div>'+
      '<div class="form-field"><label>Bio</label><textarea name="bio" maxlength="300" rows="4" placeholder="Quelques mots sur vous…">'+escapeHtml(profile.bio||"")+'</textarea></div>'+
      '<div class="form-message" data-form-message hidden></div>'+
      '<button class="button button-primary" type="submit">Enregistrer</button></form>');
  const f=m.body.querySelector("#_ep_form");
  f.addEventListener("submit",async e=>{
    e.preventDefault();const msg=f.querySelector("[data-form-message]");msg.hidden=true;
    try{
      await api("/api/profile/update",{method:"POST",body:JSON.stringify({display_name:f.display_name.value.trim(),bio:f.bio.value.trim()})});
      m.close();toast("Profil mis à jour.","success");load();
    }catch(err){formError(msg,err.message);}
  });
}

async function uploadImage(input,endpoint,maxBytes,label){
  const file=input.files&&input.files[0]; if(!file) return;
  if(file.size>maxBytes){toast(label+" : taille maximale "+Math.round(maxBytes/1048576)+" Mo.","error");input.value="";return;}
  try{const fd=new FormData();fd.append("image",file);await apiUpload(endpoint,fd);toast(label+" mise à jour.","success");await load();}
  catch(err){toast(err.message,"error");}finally{input.value="";}
}
$("profile-avatar-input").addEventListener("change",function(){uploadImage(this,"/api/profile/avatar",5*1048576,"Photo de profil");});
$("profile-cover-input").addEventListener("change",function(){uploadImage(this,"/api/profile/cover",8*1048576,"Photo de couverture");});

const pw=$("change-password-form");
pw.addEventListener("submit",async e=>{
  e.preventDefault();const msg=pw.querySelector("[data-form-message]"),btn=pw.querySelector("button[type=submit]");msg.hidden=true;setBtn(btn,"Mise à jour…",true);
  try{const r=await api("/api/profile/change-password",{method:"POST",body:JSON.stringify({current_password:pw.current_password.value,new_password:pw.new_password.value})});formSuccess(msg,r.message);pw.reset();}
  catch(err){formError(msg,err.message);}finally{setBtn(btn,"Mettre à jour",false);}
});

async function loadPosts(){
  const root=$("profile-posts");
  try{
    const d=await api("/api/profile/posts?user_id="+encodeURIComponent(profile.id));
    root.innerHTML="";
    if(!d.posts.length){root.innerHTML='<article class="card post-card"><p class="muted">Aucune publication pour le moment.</p></article>';return;}
    d.posts.forEach(p=>root.appendChild(renderPost(p,{shareUrl:"/profil?user="+encodeURIComponent(profile.id)+"#post-"+p.id,onDeleted:()=>{profile.posts_count=Math.max(0,profile.posts_count-1);setText("stat-posts",profile.posts_count);}})));
    if(location.hash.startsWith("#post-")){const t=document.getElementById(location.hash.slice(1));if(t){t.scrollIntoView({block:"center"});t.classList.add("post-highlight");}}
  }catch(err){root.innerHTML='<p class="muted">'+escapeHtml(err.message)+'</p>';}
}

async function showList(kind){
  const title=kind==="followers"?"Abonnés":"Abonnements";
  const m=openModal(title,'<p class="muted">Chargement…</p>',{width:460});
  try{
    const d=await api("/api/users/"+encodeURIComponent(profile.id)+"/"+kind);
    m.body.innerHTML=d.users.length?d.users.map(u=>'<a class="search-row" href="'+escapeHtml(publicProfileUrl(u.id))+'">'+avatarMarkup(u.avatar_url,u.display_name,"avatar avatar-sm")+'<span><strong>'+escapeHtml(u.display_name)+'</strong></span></a>').join(""):'<p class="muted">Personne pour le moment.</p>';
  }catch(err){m.body.innerHTML='<p class="muted">'+escapeHtml(err.message)+'</p>';}
}
$("btn-followers").onclick=()=>profile&&showList("followers");
$("btn-following").onclick=()=>profile&&showList("following");

load();
})();
