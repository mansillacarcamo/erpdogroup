// ── Service Worker de DOGroup ────────────────────────────
// Version cache-first para assets, network-only para paginas .php.
// v4: bumpeada para invalidar cache viejo que podia redirigir a gastos_diarios.
const CACHE = 'dogroup-v4';

const ASSETS = [
  './manifest.json',
  './img/icon-192.png',
  './img/icon-512.png',
  './img/logo.png',
  './css/styles.css',
  './css/notificaciones.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
  'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js'
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS).catch(()=>{})));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(k => k !== CACHE).map(k => caches.delete(k))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Páginas .php — network-only (nunca servir HTML cacheado: cada página
  // depende de la sesión y del estado actual de la BD, y el fallback a
  // inicio.php enmascaraba navegaciones fallidas haciendo creer que el
  // botón "no abría").
  if (url.pathname.endsWith('.php') || url.pathname === '/') {
    return; // deja pasar la petición al navegador sin interceptar
  }

  // Assets estáticos — cache-first
  e.respondWith(
    caches.match(req).then(r => r || fetch(req).then(resp => {
      if (resp.ok && req.url.startsWith('http')) {
        const copy = resp.clone();
        caches.open(CACHE).then(c => c.put(req, copy)).catch(()=>{});
      }
      return resp;
    }))
  );
});
