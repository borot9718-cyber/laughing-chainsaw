/* KOVA — Boutique : catalogue, favoris, panier, commandes, ventes, gestion des produits. */
(function(){
"use strict";
const K=window.KOVA; const root=document.getElementById("store-root"); if(!K||!root) return;
const {api,apiUpload,toast,escapeHtml,timeAgo,setBtn,formError,avatarMarkup,publicProfileUrl,ICONS,openModal}=K;
const ME=window.KOVA_USER_ID;
const $=id=>document.getElementById(id);
const enc=encodeURIComponent;

let meta={categories:[],currency:"FCFA"};
let tab=new URLSearchParams(location.search).get("tab")||"catalog";
const money=n=>Number(n||0).toLocaleString("fr-FR")+" "+meta.currency;
const STATUS={pending:"En attente",confirmed:"Confirmée",shipped:"Expédiée",delivered:"Livrée",cancelled:"Annulée"};
const ACTION={confirmed:"Confirmer",shipped:"Marquer expédiée",delivered:"Marquer livrée",cancelled:"Annuler"};

function setTab(t){
  tab=t;
  $("store-tabs").querySelectorAll("[data-tab]").forEach(b=>b.setAttribute("aria-selected",b.dataset.tab===t?"true":"false"));
  $("store-toolbar").hidden=!(t==="catalog"||t==="favorites"||t==="mine");
  history.replaceState(null,"","/boutique"+(t==="catalog"?"":"?tab="+t));
  render();
}
$("store-tabs").querySelectorAll("[data-tab]").forEach(b=>b.onclick=()=>setTab(b.dataset.tab));
const __q0=new URLSearchParams(location.search).get("q"); if(__q0) $("store-q").value=__q0;
let timer; $("store-q").addEventListener("input",()=>{clearTimeout(timer);timer=setTimeout(render,250);});
$("store-cat").addEventListener("change",render); $("store-sort").addEventListener("change",render);

/* ── Produits ── */
function productCard(p,mode){
  const mine=p.seller.id===ME;
  const img=p.image_url?'<img src="'+escapeHtml(p.image_url)+'" alt="" loading="lazy">':'<div class="product-noimg">'+ICONS.bag+'</div>';
  const out=p.stock<1;
  let actions="";
  if(mode==="mine") actions='<button class="button button-soft" data-edit>'+ICONS.edit+' Modifier</button><button class="button button-soft danger-text" data-del>'+ICONS.trash+'</button>';
  else if(mine) actions='<span class="muted">Votre produit</span>';
  else actions=(out?'<span class="pill">Rupture</span>':'<button class="button button-primary" data-add>Ajouter au panier</button>');
  return '<article class="product-card card" data-pid="'+escapeHtml(p.id)+'">'+
    '<div class="product-media" data-view>'+img+
      (mode!=="mine"&&!mine?'<button type="button" class="fav-btn" data-fav aria-pressed="'+(p.is_favorite?"true":"false")+'" aria-label="Favori">'+ICONS.heart+'</button>':"")+'</div>'+
    '<div class="product-body"><strong class="product-title" data-view>'+escapeHtml(p.title)+'</strong>'+
      '<span class="product-price">'+money(p.price)+'</span>'+
      '<small class="muted">'+escapeHtml(p.category)+' · '+(out?"Rupture":p.stock+" en stock")+'</small>'+
      '<small class="muted">par <a href="'+escapeHtml(publicProfileUrl(p.seller.id))+'">'+escapeHtml(p.seller.display_name)+'</a></small>'+
      '<div class="product-actions">'+actions+'</div></div></article>';
}
function bindProducts(container,list,mode){
  container.querySelectorAll("[data-pid]").forEach(card=>{
    const p=list.find(x=>x.id===card.dataset.pid);
    card.querySelectorAll("[data-view]").forEach(el=>el.onclick=()=>showProduct(p));
    card.querySelector("[data-fav]")?.addEventListener("click",async e=>{e.stopPropagation();try{const r=await api("/api/store/products/"+enc(p.id)+"/favorite",{method:"POST"});p.is_favorite=r.is_favorite;e.currentTarget.setAttribute("aria-pressed",r.is_favorite?"true":"false");if(tab==="favorites"&&!r.is_favorite)card.remove();}catch(err){toast(err.message,"error");}});
    card.querySelector("[data-add]")?.addEventListener("click",()=>addToCart(p.id,1));
    card.querySelector("[data-edit]")?.addEventListener("click",()=>productForm(p));
    card.querySelector("[data-del]")?.addEventListener("click",async()=>{if(!confirm("Supprimer « "+p.title+" » ?"))return;try{await api("/api/store/products/"+enc(p.id),{method:"DELETE"});toast("Produit supprimé.","success");render();}catch(err){toast(err.message,"error");}});
  });
}
function showProduct(p){
  const mine=p.seller.id===ME;
  const m=openModal(p.title,
    (p.image_url?'<img class="product-detail-img" src="'+escapeHtml(p.image_url)+'" alt="">':"")+
    '<p class="product-price" style="font-size:1.3rem">'+money(p.price)+'</p>'+
    '<p class="user-content" style="white-space:pre-wrap">'+escapeHtml(p.description||"Aucune description.")+'</p>'+
    '<p class="muted">'+escapeHtml(p.category)+' · '+(p.stock<1?"Rupture de stock":p.stock+" en stock")+'</p>'+
    '<p class="muted">Vendeur : <a href="'+escapeHtml(publicProfileUrl(p.seller.id))+'">'+escapeHtml(p.seller.display_name)+'</a></p>'+
    (mine?"":'<div class="product-actions"><a class="button button-soft" href="/messages?to='+enc(p.seller.id)+'">'+ICONS.comment+' Contacter</a>'+(p.stock>0?'<button class="button button-primary" id="_pd_add">Ajouter au panier</button>':"")+'</div>'),{width:560});
  m.body.querySelector("#_pd_add")?.addEventListener("click",()=>{m.close();addToCart(p.id,1);});
}

/* ── Formulaire produit (création / modification) ── */
function productForm(p){
  const cats=meta.categories.map(c=>'<option'+(p&&p.category===c?" selected":"")+'>'+escapeHtml(c)+'</option>').join("");
  const m=openModal(p?"Modifier le produit":"Vendre un produit",
    '<form class="form-stack" id="_pf">'+
      '<div class="form-field"><label>Titre</label><input name="title" required minlength="2" maxlength="120" value="'+escapeHtml(p?p.title:"")+'"></div>'+
      '<div class="form-grid-2"><div class="form-field"><label>Prix ('+escapeHtml(meta.currency)+')</label><input name="price" type="number" min="0" step="1" required value="'+(p?p.price:"")+'"></div>'+
      '<div class="form-field"><label>Stock</label><input name="stock" type="number" min="0" step="1" required value="'+(p?p.stock:1)+'"></div></div>'+
      '<div class="form-field"><label>Catégorie</label><select name="category">'+cats+'</select></div>'+
      '<div class="form-field"><label>Description</label><textarea name="description" rows="4" maxlength="2000">'+escapeHtml(p?p.description:"")+'</textarea></div>'+
      '<div class="form-field"><label>Photo'+(p?" (laisser vide pour conserver)":"")+'</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>'+
      '<div class="form-message" data-form-message hidden></div><button class="button button-primary" type="submit">'+(p?"Enregistrer":"Publier le produit")+'</button></form>');
  const f=m.body.querySelector("#_pf");
  f.addEventListener("submit",async e=>{
    e.preventDefault();const msg=f.querySelector("[data-form-message]"),btn=f.querySelector("button[type=submit]");setBtn(btn,"Envoi…",true);
    try{
      const fd=new FormData(f);
      if(!(f.image.files&&f.image.files[0])) fd.delete("image");
      await apiUpload(p?"/api/store/products/"+enc(p.id)+"/update":"/api/store/products",fd);
      m.close();toast(p?"Produit mis à jour.":"Produit publié.","success");if(!p)setTab("mine");else render();
    }catch(err){formError(msg,err.message);setBtn(btn,p?"Enregistrer":"Publier le produit",false);}
  });
}
$("btn-new-product").onclick=()=>productForm(null);

/* ── Panier ── */
async function refreshCartCount(items){
  try{
    if(!items) items=(await api("/api/store/cart")).items;
    const n=items.reduce((a,i)=>a+i.qty,0); const b=$("cart-count"); b.textContent=n; b.hidden=n===0;
  }catch(_){}
}
async function addToCart(id,qty){
  try{
    const cur=(await api("/api/store/cart")).items.find(i=>i.product.id===id);
    await api("/api/store/cart/set",{method:"POST",body:JSON.stringify({product_id:id,qty:(cur?cur.qty:0)+qty})});
    toast("Ajouté au panier.","success"); refreshCartCount();
  }catch(err){toast(err.message,"error");}
}
async function renderCart(c){
  const d=await api("/api/store/cart"); refreshCartCount(d.items);
  if(!d.items.length){c.innerHTML='<div class="card empty-panel"><strong>Votre panier est vide</strong><p>Parcourez le catalogue pour ajouter des produits.</p></div>';return;}
  c.innerHTML='<div class="cart-layout"><div class="card cart-lines">'+d.items.map(i=>
    '<div class="cart-line" data-pid="'+escapeHtml(i.product.id)+'"><div class="cart-thumb">'+(i.product.image_url?'<img src="'+escapeHtml(i.product.image_url)+'" alt="">':ICONS.bag)+'</div>'+
    '<div class="cart-info"><strong>'+escapeHtml(i.product.title)+'</strong><small class="muted">'+money(i.product.price)+' · '+escapeHtml(i.product.seller.display_name)+'</small></div>'+
    '<div class="qty"><button type="button" data-q="-1" aria-label="Moins">−</button><span>'+i.qty+'</span><button type="button" data-q="1" aria-label="Plus">+</button></div>'+
    '<strong class="cart-total">'+money(i.line_total)+'</strong><button type="button" class="icon-button" data-rm aria-label="Retirer">'+ICONS.trash+'</button></div>').join("")+
    '<div class="cart-sum"><span>Total</span><strong>'+money(d.total)+'</strong></div></div>'+
    '<form class="card form-stack checkout-form" id="_co"><h3>Livraison</h3>'+
      '<div class="form-field"><label>Téléphone</label><input name="phone" required maxlength="30" autocomplete="tel"></div>'+
      '<div class="form-field"><label>Adresse de livraison</label><textarea name="address" rows="2" required maxlength="300"></textarea></div>'+
      '<div class="form-field"><label>Note pour le vendeur (facultatif)</label><input name="note" maxlength="500"></div>'+
      '<div class="form-message" data-form-message hidden></div><button class="button button-primary" type="submit">Passer la commande</button>'+
      '<small class="muted">Paiement réglé avec le vendeur. Aucune somme n’est prélevée par KOVA.</small></form></div>';
  c.querySelectorAll(".cart-line").forEach(line=>{
    const id=line.dataset.pid, it=d.items.find(x=>x.product.id===id);
    line.querySelectorAll("[data-q]").forEach(b=>b.onclick=async()=>{try{await api("/api/store/cart/set",{method:"POST",body:JSON.stringify({product_id:id,qty:it.qty+Number(b.dataset.q)})});renderCart(c);}catch(err){toast(err.message,"error");}});
    line.querySelector("[data-rm]").onclick=async()=>{await api("/api/store/cart/set",{method:"POST",body:JSON.stringify({product_id:id,qty:0})});renderCart(c);};
  });
  const f=$("_co");
  f.addEventListener("submit",async e=>{
    e.preventDefault();const msg=f.querySelector("[data-form-message]"),btn=f.querySelector("button[type=submit]");setBtn(btn,"Envoi…",true);
    try{const r=await api("/api/store/checkout",{method:"POST",body:JSON.stringify({phone:f.phone.value,address:f.address.value,note:f.note.value})});toast(r.message,"success");refreshCartCount([]);setTab("orders");}
    catch(err){formError(msg,err.message);setBtn(btn,"Passer la commande",false);}
  });
}

/* ── Commandes / ventes ── */
async function renderOrders(c,role){
  const d=await api("/api/store/orders?role="+role);
  if(!d.orders.length){c.innerHTML='<div class="card empty-panel"><strong>'+(role==="buyer"?"Aucune commande":"Aucune vente")+' pour le moment</strong></div>';return;}
  c.innerHTML=d.orders.map(o=>{
    const other=role==="buyer"?o.seller:o.buyer;
    return '<article class="card order-card" data-oid="'+escapeHtml(o.id)+'"><div class="order-head"><div><strong>Commande #'+escapeHtml(o.id.slice(-6).toUpperCase())+'</strong><small class="muted"> · '+timeAgo(o.created_at)+'</small></div><span class="pill status-'+escapeHtml(o.status)+'">'+(STATUS[o.status]||o.status)+'</span></div>'+
      '<div class="order-items">'+o.items.map(i=>'<div>'+escapeHtml(i.title)+' × '+i.qty+'<span>'+money(i.price*i.qty)+'</span></div>').join("")+'</div>'+
      '<div class="order-foot"><div><small class="muted">'+(role==="buyer"?"Vendeur":"Acheteur")+' : <a href="'+escapeHtml(publicProfileUrl(other.id))+'">'+escapeHtml(other.display_name)+'</a></small>'+
        (role==="seller"?'<small class="muted">Tél. '+escapeHtml(o.phone)+' · '+escapeHtml(o.address)+(o.note?" · "+escapeHtml(o.note):"")+'</small>':"")+'</div><strong>'+money(o.total)+'</strong></div>'+
      '<div class="order-actions"><a class="button button-soft" href="/messages?to='+enc(other.id)+'">'+ICONS.comment+' Contacter</a>'+
        (o.next||[]).map(s=>'<button class="button '+(s==="cancelled"?"button-soft danger-text":"button-primary")+'" data-status="'+s+'">'+ACTION[s]+'</button>').join("")+'</div></article>';
  }).join("");
  c.querySelectorAll("[data-status]").forEach(b=>b.onclick=async()=>{
    if(b.dataset.status==="cancelled"&&!confirm("Annuler cette commande ?"))return;
    try{await api("/api/store/orders/"+enc(b.closest("[data-oid]").dataset.oid)+"/status",{method:"POST",body:JSON.stringify({status:b.dataset.status})});toast("Statut mis à jour.","success");renderOrders(c,role);}
    catch(err){toast(err.message,"error");}
  });
}

/* ── Rendu principal ── */
async function render(){
  const c=$("store-content");
  try{
    if(tab==="cart") return await renderCart(c);
    if(tab==="orders") return await renderOrders(c,"buyer");
    if(tab==="sales") return await renderOrders(c,"seller");
    const qs=new URLSearchParams({q:$("store-q").value.trim(),category:$("store-cat").value,sort:$("store-sort").value});
    if(tab==="favorites") qs.set("favorites","1"); if(tab==="mine") qs.set("mine","1");
    const list=(await api("/api/store/products?"+qs.toString())).products;
    if(!list.length){c.innerHTML='<div class="card empty-panel"><strong>'+(tab==="mine"?"Vous n’avez encore aucun produit":tab==="favorites"?"Aucun favori":"Aucun produit")+'</strong><p>'+(tab==="mine"?"Cliquez sur « Vendre un produit » pour publier le premier.":"Essayez une autre recherche.")+'</p></div>';return;}
    c.innerHTML='<div class="product-grid">'+list.map(p=>productCard(p,tab==="mine"?"mine":"")).join("")+'</div>';
    bindProducts(c,list,tab);
  }catch(err){c.innerHTML='<div class="card notice">'+escapeHtml(err.message)+'</div>';}
}

(async function(){
  try{meta=await api("/api/store/meta");}catch(_){}
  $("store-cat").innerHTML='<option value="">Toutes les catégories</option>'+meta.categories.map(c=>'<option>'+escapeHtml(c)+'</option>').join("");
  if(!["catalog","favorites","cart","orders","sales","mine"].includes(tab)) tab="catalog";
  refreshCartCount(); setTab(tab);
})();
})();
