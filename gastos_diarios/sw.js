// Service Worker - Control de Gastos Diarios
// Estrategia:
//   - PHP / paginas dinamicas / requests con query string: network-first (siempre fresco)
//   - CSS, JS, imagenes, CDN, manifest: cache-first
//   - POST y otros metodos no-GET: bypass total
const CACHE = 'cgastos-v4';
const STATIC_ASSETS = [
  'css/app.css',
  'js/app.js',
  'manifest.json',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(STATIC_ASSETS).catch(()=>{})));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

function isStaticAsset(url) {
  if (url.origin !== self.location.origin) {
    // CDN externos
    return /\.(css|js|woff2?|ttf|otf|svg|png|jpe?g|webp)(\?|$)/i.test(url.href);
  }
  // Mismo origen: solo cachear assets estaticos por extension
  return /\.(css|js|woff2?|ttf|otf|svg|png|jpe?g|webp|ico)(\?|$)/i.test(url.pathname)
      || url.pathname.endsWith('/manifest.json');
}

self.addEventListener('fetch', e => {
  const req = e.request;

  // Bypass total para metodos que no son GET (POST de formularios, etc)
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Estrategia para assets estaticos: cache-first
  if (isStaticAsset(url)) {
    e.respondWith(
      caches.match(req).then(cached => cached || fetch(req).then(resp => {
        if (resp.ok) {
          const copy = resp.clone();
          caches.open(CACHE).then(c => c.put(req, copy)).catch(()=>{});
        }
        return resp;
      }))
    );
    return;
  }

  // Estrategia para PHP / paginas dinamicas: network-first con fallback a cache solo si no hay red
  e.respondWith(
    fetch(req).then(resp => {
      // No cachear paginas dinamicas (PHP) - siempre traer fresco
      return resp;
    }).catch(() => caches.match(req))
  );
});
