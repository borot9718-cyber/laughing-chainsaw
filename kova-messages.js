/* KOVA — Messagerie privée : conversations, messages, images, accusés de lecture. */
(function(){
"use strict";
const K=window.KOVA; const layout=document.getElementById("messages-layout"); if(!K||!layout) return;
const {api,apiUpload,toast,escapeHtml,timeAgo,avatarMarkup,publicProfileUrl,ICONS,openModal,loadUnreadBadge}=K;
const ME=window.KOVA_USER_ID;
const $=id=>document.getElementById(id);
const enc=encodeURIComponent;

let convs=[], active=null, lastTs="", otherReadAt="", pollTimer=null, busy=false;

const hhmm=d=>new Date(d).toLocaleTimeString("fr-FR",{hour:"2-digit",minute:"2-digit"});

/* ── Liste des conversations ── */
function renderConvs(){
  const list=$("conv-list");
  if(!convs.length){list.innerHTML='<div class="empty-panel"><strong>Aucune conversation</strong><p>Cliquez sur « Nouvelle conversation » pour écrire à quelqu’un.</p></div>';return;}
  list.innerHTML=convs.map(c=>
    '<button type="button" class="conv-item'+(active&&active.id===c.id?" active":"")+'" data-conv="'+escapeHtml(c.id)+'">'+
      avatarMarkup(c.other.avatar_url,c.other.display_name,"avatar avatar-sm")+
      '<span class="conv-main"><strong>'+escapeHtml(c.other.display_name)+'</strong><small class="user-content">'+(c.last_sender_id===ME&&c.last_preview?"Vous : ":"")+escapeHtml(c.last_preview||"Nouvelle conversation")+'</small></span>'+
      '<span class="conv-side"><small>'+(c.last_preview?timeAgo(c.last_message_at):"")+'</small>'+(c.unread?'<span class="badge badge-static">'+c.unread+'</span>':"")+'</span>'+
    '</button>').join("");
  list.querySelectorAll("[data-conv]").forEach(b=>b.onclick=()=>openConv(b.dataset.conv));
}
async function loadConvs(){
  try{convs=(await api("/api/conversations")).conversations||[];renderConvs();}
  catch(err){$("conv-list").innerHTML='<p class="muted" style="padding:16px">'+escapeHtml(err.message)+'</p>';}
}

/* ── Fil de messages ── */
function bubble(m){
  const mine=m.sender_id===ME;
  if(m.deleted) return '<div class="bubble-row '+(mine?"mine":"")+'" data-mid="'+escapeHtml(m.id)+'"><div class="bubble is-deleted">Message supprimé</div></div>';
  return '<div class="bubble-row '+(mine?"mine":"")+'" data-mid="'+escapeHtml(m.id)+'" data-ts="'+escapeHtml(m.created_at)+'">'+
    '<div class="bubble">'+(m.image_url?'<img class="bubble-img" src="'+escapeHtml(m.image_url)+'" alt="Image jointe" loading="lazy">':"")+
    (m.content?'<span class="user-content">'+escapeHtml(m.content)+'</span>':"")+
    '<small>'+hhmm(m.created_at)+'</small></div>'+
    (mine?'<button type="button" class="bubble-del" data-del aria-label="Supprimer le message" title="Supprimer">'+ICONS.trash+'</button>':"")+
  '</div>';
}
function seenMark(){
  const box=$("chat-thread"); if(!box) return;
  box.querySelectorAll(".seen-mark").forEach(x=>x.remove());
  const mineRows=[...box.querySelectorAll(".bubble-row.mine[data-ts]")]; const last=mineRows[mineRows.length-1];
  if(last&&otherReadAt&&last.dataset.ts<=otherReadAt){const s=document.createElement("small");s.className="seen-mark";s.textContent="Vu";last.after(s);}
}
function bindDelete(scope){
  scope.querySelectorAll("[data-del]").forEach(b=>{if(b._b)return;b._b=1;b.onclick=async()=>{
    const row=b.closest("[data-mid]"); if(!confirm("Supprimer ce message ?"))return;
    try{await api("/api/messages/"+enc(row.dataset.mid),{method:"DELETE"});row.outerHTML='<div class="bubble-row mine"><div class="bubble is-deleted">Message supprimé</div></div>';}
    catch(err){toast(err.message,"error");}
  };});
}

async function openConv(id,other){
  if(busy) return; busy=true;
  try{
    const d=await api("/api/conversations/"+enc(id)+"/messages");
    const c=convs.find(x=>x.id===id);
    active={id,other:d.other||(c&&c.other)||other,blocked:d.blocked};
    lastTs=d.messages.length?d.messages[d.messages.length-1].created_at:""; otherReadAt=d.other_read_at||"";
    layout.classList.add("chat-open");
    renderChat(d.messages);
    api("/api/conversations/"+enc(id)+"/read",{method:"POST"}).then(()=>{loadConvs();loadUnreadBadge();}).catch(()=>{});
    startPolling();
  }catch(err){toast(err.message,"error");}
  finally{busy=false;}
}

function renderChat(msgs){
  const o=active.other, pane=$("chat-pane");
  pane.innerHTML=
    '<header class="chat-head"><button type="button" class="icon-button chat-back" id="chat-back" aria-label="Retour">←</button>'+
      '<a class="chat-who" href="'+escapeHtml(publicProfileUrl(o.id))+'">'+avatarMarkup(o.avatar_url,o.display_name,"avatar avatar-sm")+'<strong>'+escapeHtml(o.display_name)+'</strong></a></header>'+
    '<div class="chat-thread" id="chat-thread">'+(msgs.length?msgs.map(bubble).join(""):'<p class="muted chat-hint">Dites bonjour 👋</p>')+'</div>'+
    (active.blocked
      ?'<div class="chat-blocked">Vous ne pouvez plus échanger avec cet utilisateur.</div>'
      :'<form class="chat-form" id="chat-form"><label class="icon-button" for="chat-img" title="Joindre une image">'+ICONS.camera+'</label><input type="file" id="chat-img" accept="image/jpeg,image/png,image/webp,image/gif" hidden>'+
        '<input type="text" id="chat-input" maxlength="2000" placeholder="Écrire un message…" autocomplete="off"><button class="button button-primary" type="submit">'+ICONS.send+' Envoyer</button></form>'+
        '<div class="chat-attach" id="chat-attach" hidden></div>');
  const box=$("chat-thread"); box.scrollTop=box.scrollHeight; bindDelete(box); seenMark();
  $("chat-back").onclick=()=>{layout.classList.remove("chat-open");active=null;stopPolling();renderConvs();};
  const form=$("chat-form"); if(!form) return;
  const img=$("chat-img"), att=$("chat-attach");
  img.onchange=()=>{const f=img.files[0];if(!f){att.hidden=true;return;}if(f.size>8*1048576){toast("Image trop lourde (8 Mo maximum).","error");img.value="";att.hidden=true;return;}att.hidden=false;att.innerHTML='<span>'+ICONS.camera+' '+escapeHtml(f.name)+'</span><button type="button" class="icon-button" id="chat-attach-x">×</button>';$("chat-attach-x").onclick=()=>{img.value="";att.hidden=true;};};
  form.onsubmit=async e=>{
    e.preventDefault(); const input=$("chat-input"), text=input.value.trim(), file=img.files[0]||null;
    if(!text&&!file) return;
    const btn=form.querySelector("button[type=submit]"); btn.disabled=true;
    try{
      let r;
      if(file){const fd=new FormData();fd.append("content",text);fd.append("image",file);r=await apiUpload("/api/conversations/"+enc(active.id)+"/messages",fd);}
      else r=await api("/api/conversations/"+enc(active.id)+"/messages",{method:"POST",body:JSON.stringify({content:text})});
      input.value="";img.value="";att.hidden=true;
      appendMessages([r.message]); loadConvs();
    }catch(err){toast(err.message,"error");}
    finally{btn.disabled=false;input.focus();}
  };
  $("chat-input").focus();
}

function appendMessages(list){
  const box=$("chat-thread"); if(!box||!list.length) return;
  const near=box.scrollHeight-box.scrollTop-box.clientHeight<120;
  box.querySelector(".chat-hint")?.remove();
  list.forEach(m=>{
    if(box.querySelector('[data-mid="'+CSS.escape(m.id)+'"]')) return;
    box.insertAdjacentHTML("beforeend",bubble(m)); lastTs=m.created_at>lastTs?m.created_at:lastTs;
  });
  bindDelete(box); seenMark();
  if(near||list.some(m=>m.sender_id===ME)) box.scrollTop=box.scrollHeight;
}

/* ── Actualisation périodique (sans WebSocket : compatible hébergement mutualisé) ── */
async function tick(){
  if(document.hidden) return;
  if(active){
    try{
      const d=await api("/api/conversations/"+enc(active.id)+"/messages"+(lastTs?"?since="+enc(lastTs):""));
      otherReadAt=d.other_read_at||otherReadAt;
      const fresh=d.messages.filter(m=>m.sender_id!==ME);
      if(d.messages.length) appendMessages(d.messages);
      if(fresh.length) api("/api/conversations/"+enc(active.id)+"/read",{method:"POST"}).catch(()=>{});
      seenMark();
    }catch(_){}
  }
  loadConvs(); loadUnreadBadge();
}
function startPolling(){stopPolling();pollTimer=setInterval(tick,5000);}
function stopPolling(){if(pollTimer){clearInterval(pollTimer);pollTimer=null;}}

/* ── Nouvelle conversation ── */
async function startWith(userId,other){
  try{const r=await api("/api/conversations",{method:"POST",body:JSON.stringify({user_id:userId})});await loadConvs();await openConv(r.conversation.id,r.conversation.other||other);}
  catch(err){toast(err.message,"error");}
}
$("btn-new-conv").onclick=()=>{
  const m=openModal("Nouvelle conversation",'<div class="form-field"><label>À qui voulez-vous écrire ?</label><input type="search" id="_nc_q" placeholder="Rechercher une personne…" autocomplete="off"></div><div id="_nc_res" class="search-results-list"><p class="muted">Saisissez au moins 2 caractères.</p></div>',{width:460});
  const q=m.body.querySelector("#_nc_q"), res=m.body.querySelector("#_nc_res"); q.focus(); let t,seq=0;
  q.addEventListener("input",()=>{
    clearTimeout(t); const v=q.value.trim();
    if(v.length<2){res.innerHTML='<p class="muted">Saisissez au moins 2 caractères.</p>';return;}
    t=setTimeout(async()=>{
      const my=++seq;
      try{
        const d=await api("/api/search?type=users&exclude_self=1&limit=10&q="+enc(v)); if(my!==seq)return;
        res.innerHTML=d.users.length?d.users.map(u=>'<button type="button" class="search-row" data-uid="'+escapeHtml(u.id)+'">'+avatarMarkup(u.avatar_url,u.display_name,"avatar avatar-sm")+'<span><strong>'+escapeHtml(u.display_name)+'</strong></span></button>').join(""):'<p class="muted">Personne trouvé.</p>';
        res.querySelectorAll("[data-uid]").forEach(b=>b.onclick=()=>{const u=d.users.find(x=>x.id===b.dataset.uid);m.close();startWith(u.id,u);});
      }catch(err){res.innerHTML='<p class="muted">'+escapeHtml(err.message)+'</p>';}
    },250);
  });
};

/* ── Démarrage : ?to=ID ouvre directement la conversation ── */
(async function(){
  await loadConvs();
  const to=new URLSearchParams(location.search).get("to");
  if(to){history.replaceState(null,"",location.pathname);await startWith(to);}
  setInterval(()=>{if(!active&&!document.hidden)loadConvs();},8000);
})();
})();
