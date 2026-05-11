/* ───────────────────────────────────────────────────────────
   Auto-refresh para los portales de Despacho
   Hace polling al endpoint api_refresh_despacho.php y
   refresca la página cuando detecta cambios (validaciones,
   revisiones o nuevos tickets).
   ─────────────────────────────────────────────────────────── */
(function () {
  if (window.__despachoAutoRefreshLoaded) return;
  window.__despachoAutoRefreshLoaded = true;

  const cfg = window.DESPACHO_REFRESH || {};
  const scope    = cfg.scope    || null;
  const interval = cfg.interval || 8000;
  if (!scope) return;

  let lastWatermark = null;
  let pollTimer = null;
  let paused    = false;
  let badgeEl   = null;

  /* API pública para pausar/reanudar desde otros scripts */
  window.despachoRefresh = window.despachoRefresh || {};
  window.despachoRefresh.pause = function () { paused = true; };
  window.despachoRefresh.resume = function () { paused = false; };

  /* Pausar automáticamente cuando el usuario está enviando un formulario
     (evita interrumpir un POST/redirect en curso) */
  document.addEventListener('submit', function () {
    paused = true;
    /* Reanudar tras 30s por si el submit fue cancelado */
    setTimeout(function () { paused = false; }, 30000);
  }, true);

  function ensureBadge() {
    if (badgeEl || !cfg.badge) return;
    badgeEl = document.createElement('div');
    badgeEl.className = 'desp-refresh-badge';
    badgeEl.innerHTML = '<i class="bi bi-arrow-repeat"></i> Actualizando...';
    badgeEl.style.cssText = 'position:fixed;bottom:14px;right:14px;z-index:9999;'
      + 'background:#198754;color:#fff;padding:8px 14px;border-radius:999px;'
      + 'font-size:.85rem;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,.2);'
      + 'opacity:0;transition:opacity .3s;pointer-events:none;';
    document.body.appendChild(badgeEl);
  }

  function showBadge(text, color) {
    ensureBadge();
    if (!badgeEl) return;
    badgeEl.innerHTML = text;
    badgeEl.style.background = color || '#198754';
    badgeEl.style.opacity = '1';
    setTimeout(() => { if (badgeEl) badgeEl.style.opacity = '0'; }, 1800);
  }

  function poll() {
    if (paused) return;
    fetch('api_refresh_despacho.php?scope=' + encodeURIComponent(scope) + '&_=' + Date.now(), {
      credentials: 'same-origin',
      cache: 'no-store',
    })
      .then(r => r.ok ? r.json() : null)
      .then(data => {
        if (!data || data.error) return;
        if (lastWatermark === null) {
          lastWatermark = data.watermark;
          return;
        }
        if (data.watermark !== lastWatermark) {
          lastWatermark = data.watermark;
          paused = true;
          showBadge('<i class="bi bi-check-circle-fill"></i> Cambios detectados — recargando…', '#0d6efd');
          setTimeout(() => {
            try {
              const url = new URL(window.location.href);
              url.searchParams.set('_r', Date.now());
              window.location.href = url.toString();
            } catch (e) { window.location.reload(); }
          }, 900);
        }
      })
      .catch(() => { /* silencio: reintenta en el próximo tick */ });
  }

  function start() {
    poll();
    pollTimer = setInterval(poll, interval);
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      paused = true;
    } else {
      paused = false;
      poll();
    }
  });

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    start();
  } else {
    document.addEventListener('DOMContentLoaded', start);
  }
})();
