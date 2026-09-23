// KOVA — Service Worker
// v2 : ne met plus en cache les réponses /api/*, ne renvoie plus la page
// d'accueil en remplacement d'un CSS/JS/image qui échoue, et ne bloque plus
// l'installation si un seul fichier de base est temporairement indisponible.
const CACHE = "kova-v8";
const CORE = ["/", "/assets/css/kova.css", "/assets/js/kova.js", "/manifest.webmanifest", "/assets/images/kova-logo.svg"];

self.addEventListener("install", e => {
  e.waitUntil(
    caches.open(CACHE).then(c =>
      Promise.all(CORE.map(url => c.add(url).catch(() => null)))
    ).then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", e => {
  const req = e.request;
  if (req.method !== "GET") return;

  const url = new URL(req.url);
  if (url.origin !== location.origin) return;

  // Jamais de cache pour l'API : les réponses dépendent de la session en
  // cours (utilisateur connecté, données à jour) et ne doivent jamais être
  // rejouées depuis un cache local.
  if (url.pathname.startsWith("/api/") || url.pathname.startsWith("/oauth/") || url.pathname.startsWith("/.well-known/")) return;

  // Navigation (chargement de page) : réseau en priorité, avec la page
  // d'accueil mise en cache comme secours uniquement hors-ligne.
  if (req.mode === "navigate") {
    e.respondWith(
      fetch(req).catch(() => caches.match("/"))
    );
    return;
  }

  // JS/CSS : réseau d'abord pour éviter qu'une ancienne version du code
  // de sécurité reste active après une mise à jour. Les autres ressources
  // restent cache-first pour de meilleures performances.
  const networkFirst = req.destination === 'script' || req.destination === 'style';
  if(networkFirst){
    e.respondWith(fetch(req).then(res=>{
      if(res && res.ok){ const copy=res.clone(); caches.open(CACHE).then(c=>c.put(req,copy)); }
      return res;
    }).catch(()=>caches.match(req)));
    return;
  }

  e.respondWith(
    caches.match(req).then(cached => cached || fetch(req).then(res => {
      if (res && res.ok) {
        const copy = res.clone();
        caches.open(CACHE).then(c => c.put(req, copy));
      }
      return res;
    }))
  );
});

// ── Notifications push (pop-up dans la barre du téléphone) ──────────────
// Le contenu arrive chiffré de bout en bout ; l'URL est contrôlée avant toute ouverture.
function safeUrl(u){
  try{
    const x=new URL(String(u||"/notifications"), self.location.origin);
    return x.origin===self.location.origin ? x.pathname+x.search+x.hash : "/notifications";
  }catch(_){ return "/notifications"; }
}

self.addEventListener("push", e => {
  let data = { title: "KOVA", body: "Nouvelle activité", unreadCount: 1, url: "/notifications", tag: "kova" };
  try { data = Object.assign(data, e.data ? e.data.json() : {}); } catch (_) {}
  e.waitUntil((async () => {
    if ("setAppBadge" in self.navigator) { try { await self.navigator.setAppBadge(Number(data.unreadCount || 1)); } catch (_) {} }
    await self.registration.showNotification(String(data.title || "KOVA").slice(0, 80), {
      body: String(data.body || "").slice(0, 180),
      icon: "/assets/images/generated/kova-192.png",
      badge: "/assets/images/generated/kova-96.png",
      tag: String(data.tag || "kova"),        // même tag = la notification est remplacée, pas dupliquée
      renotify: true,
      data: { url: safeUrl(data.url) },
      timestamp: Date.now()
    });
  })());
});

self.addEventListener("notificationclick", e => {
  e.notification.close();
  const target = safeUrl(e.notification.data && e.notification.data.url);
  e.waitUntil((async () => {
    const wins = await clients.matchAll({ type: "window", includeUncontrolled: true });
    for (const w of wins) {
      if (new URL(w.url).origin === self.location.origin && "focus" in w) {
        try { if ("navigate" in w) await w.navigate(target); } catch (_) {}
        return w.focus();
      }
    }
    return clients.openWindow(target);
  })());
});
