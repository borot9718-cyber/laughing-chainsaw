(function(){
  const $=id=>document.getElementById(id);
  const api=async(url,opts={})=>{const r=await fetch(url,{headers:{'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-KOVA-CSRF':document.querySelector('meta[name="kova-csrf"]')?.content||''},...opts});const d=await r.json().catch(()=>({}));if(!r.ok||d.ok===false)throw Error(d.error||'Erreur');return d;};
  const localValue=iso=>{if(!iso)return '';const d=new Date(iso);if(Number.isNaN(d.getTime()))return '';const p=n=>String(n).padStart(2,'0');return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;};
  const state=$('maintenance-state'),status=$('maintenance-status'),enabled=$('maintenance-enabled'),start=$('maintenance-start'),end=$('maintenance-end'),msg=$('maintenance-message');
  const load=async()=>{try{const d=await api('/api/admin/maintenance');const m=d.maintenance||{};enabled.checked=!!m.enabled;start.value=localValue(m.start_at);end.value=localValue(m.end_at);msg.value=m.message||'';state.textContent=m.active?'🟠 Maintenance actuellement ACTIVE.':'🟢 Maintenance inactive.';}catch(e){state.textContent=e.message;}};
  $('admin-maintenance-form').addEventListener('submit',async e=>{e.preventDefault();status.textContent='Enregistrement…';try{const d=await api('/api/admin/maintenance',{method:'POST',body:JSON.stringify({enabled:enabled.checked,start_at:start.value,end_at:end.value,message:msg.value})});const m=d.maintenance||{};state.textContent=m.active?'🟠 Maintenance actuellement ACTIVE.':'🟢 Maintenance inactive.';status.textContent='Paramètres enregistrés.';}catch(e){status.textContent=e.message;}});
  load();
})();
