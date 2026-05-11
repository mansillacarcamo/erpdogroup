<?php
/**
 * Snippet reusable para logins (Carchek, Conductor, Jefe Obra, Combustible).
 *
 * Variables esperadas:
 *   $pwaManifest   → "manifest_carchek.json", etc.
 *   $pwaThemeColor → "#hex"
 *   $pwaTitle      → "Carchek", "Conductor", etc.
 *   $pwaIcon       → opcional (default: img/icon-192.png)
 */
// iOS Safari ignora ?v= en apple-touch-icon. Usamos nombres convencionales
// servidos desde la raíz (que iOS busca por defecto) y aliases nuevos para
// invalidar la caché del ícono cuando ya se instaló una versión previa.
$pwaIcon180 = 'apple-touch-icon-180x180.png';
$pwaIcon167 = 'apple-touch-icon-180x180.png';
$pwaIcon152 = 'apple-touch-icon-152x152.png';
$pwaIcon120 = 'apple-touch-icon-120x120.png';
$pwaIcon192 = 'favicon-192.png';
$pwaIcon512 = 'img/icon-512.png';
$pwaIconRoot = 'apple-touch-icon.png';
$pwaIcon    = $pwaIcon ?? $pwaIcon180;
?>
<link rel="manifest" href="<?= htmlspecialchars($pwaManifest) ?>">
<meta name="theme-color" content="<?= htmlspecialchars($pwaThemeColor) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($pwaTitle) ?>">
<meta name="application-name" content="<?= htmlspecialchars($pwaTitle) ?>">
<link rel="shortcut icon" href="favicon.png">
<link rel="icon" type="image/png" sizes="192x192" href="<?= $pwaIcon192 ?>">
<link rel="icon" type="image/png" sizes="512x512" href="<?= $pwaIcon512 ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $pwaIcon180 ?>">
<link rel="apple-touch-icon" sizes="167x167" href="<?= $pwaIcon167 ?>">
<link rel="apple-touch-icon" sizes="152x152" href="<?= $pwaIcon152 ?>">
<link rel="apple-touch-icon" sizes="120x120" href="<?= $pwaIcon120 ?>">
<link rel="apple-touch-icon" href="<?= $pwaIconRoot ?>">
<link rel="apple-touch-icon-precomposed" href="apple-touch-icon-precomposed.png">
<style>
.pwa-install-banner{
  position:fixed;left:50%;bottom:14px;transform:translateX(-50%);width:calc(100% - 28px);max-width:420px;
  background:#fff;border-radius:14px;padding:12px 14px;display:none;align-items:center;gap:10px;z-index:9999;
  box-shadow:0 12px 28px rgba(0,0,0,.25);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
  border-left:4px solid <?= htmlspecialchars($pwaThemeColor) ?>;
  animation: pwa-up .35s ease-out;
}
@keyframes pwa-up { from { opacity:0; transform:translate(-50%, 30px) } to { opacity:1; transform:translate(-50%, 0) } }
.pwa-install-banner img{width:42px;height:42px;border-radius:10px;flex-shrink:0;background:#fff;padding:2px;border:1px solid #e5e7eb}
.pwa-install-banner .pwa-info{flex:1;min-width:0}
.pwa-install-banner .pwa-title{font-weight:800;font-size:.86rem;color:#1b2838;line-height:1.2}
.pwa-install-banner .pwa-sub{font-size:.72rem;color:#6b7280;margin-top:2px}
.pwa-install-banner .pwa-btn{background:<?= htmlspecialchars($pwaThemeColor) ?>;color:#fff;border:0;border-radius:8px;padding:8px 14px;font-weight:700;font-size:.78rem;cursor:pointer;white-space:nowrap}
.pwa-install-banner .pwa-close{background:none;border:0;font-size:1.3rem;color:#9ca3af;cursor:pointer;padding:0 4px;line-height:1}
.pwa-modal{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:10000;display:none;align-items:center;justify-content:center;padding:18px}
.pwa-modal.show{display:flex}
.pwa-modal .pwa-card{background:#fff;border-radius:18px;max-width:380px;width:100%;padding:22px;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.35);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.pwa-modal h3{font-size:1.05rem;margin:8px 0 4px;color:#1b2838;font-weight:800}
.pwa-modal p{font-size:.86rem;color:#475569;line-height:1.45;margin:8px 0}
.pwa-modal .pwa-step{background:#f8fafc;border-radius:10px;padding:10px 12px;margin:8px 0;font-size:.85rem;text-align:left;display:flex;align-items:center;gap:10px}
.pwa-modal .pwa-step b{color:<?= htmlspecialchars($pwaThemeColor) ?>}
.pwa-modal .pwa-share{display:inline-block;width:28px;height:28px;background:#e2e8f0;border-radius:6px;text-align:center;line-height:28px;color:#0ea5e9;font-size:1rem;font-weight:700}
.pwa-modal .pwa-add{display:inline-block;background:#e2e8f0;border-radius:6px;padding:2px 9px;font-size:.78rem;font-weight:700;color:#0f172a}
.pwa-modal .pwa-dots{display:inline-block;background:#e2e8f0;border-radius:6px;padding:2px 9px;font-size:.95rem;font-weight:700;color:#0f172a}
.pwa-modal .pwa-ok{background:<?= htmlspecialchars($pwaThemeColor) ?>;color:#fff;border:0;border-radius:8px;padding:10px 22px;margin-top:10px;font-weight:700;cursor:pointer;font-size:.86rem}
@media (display-mode: standalone) { .pwa-install-banner, .pwa-modal { display:none !important } }
</style>

<div class="pwa-install-banner" id="pwaInstallBanner">
  <img src="<?= $pwaIcon180 ?>" alt="">
  <div class="pwa-info">
    <div class="pwa-title">Instalar <?= htmlspecialchars($pwaTitle) ?></div>
    <div class="pwa-sub">Acceso directo en tu inicio</div>
  </div>
  <button type="button" class="pwa-btn" id="pwaInstallBtn">Instalar</button>
  <button type="button" class="pwa-close" id="pwaCloseBtn" aria-label="Cerrar">&times;</button>
</div>

<!-- Modal iOS Safari -->
<div class="pwa-modal" id="pwaIosModal">
  <div class="pwa-card">
    <img src="<?= $pwaIcon180 ?>" alt="" style="width:72px;height:72px;border-radius:16px;background:#fff;padding:4px;border:1px solid #e5e7eb">
    <h3>Agrega <?= htmlspecialchars($pwaTitle) ?> a tu inicio</h3>
    <p>Instala la app en tu iPhone para abrirla con un toque, sin barra del navegador.</p>
    <div class="pwa-step">1. Toca <b>Compartir</b> <span class="pwa-share">↑</span> en la barra inferior de Safari.</div>
    <div class="pwa-step">2. Elige <b>"Agregar a pantalla de inicio"</b> <span class="pwa-add">＋</span>.</div>
    <div class="pwa-step">3. Toca <b>"Agregar"</b> arriba a la derecha.</div>
    <button type="button" class="pwa-ok" data-close-modal>Entendido</button>
  </div>
</div>

<!-- Modal Android fallback (cuando el navegador no dispara beforeinstallprompt) -->
<div class="pwa-modal" id="pwaAndModal">
  <div class="pwa-card">
    <img src="<?= $pwaIcon180 ?>" alt="" style="width:72px;height:72px;border-radius:16px;background:#fff;padding:4px;border:1px solid #e5e7eb">
    <h3>Agrega <?= htmlspecialchars($pwaTitle) ?> a tu inicio</h3>
    <p>Instala la app en tu Android para abrirla como una aplicación.</p>
    <div class="pwa-step">1. Toca el menú <span class="pwa-dots">⋮</span> arriba a la derecha de Chrome.</div>
    <div class="pwa-step">2. Elige <b>"Instalar aplicación"</b> o <b>"Añadir a pantalla principal"</b>.</div>
    <div class="pwa-step">3. Confirma con <b>Instalar</b> / <b>Añadir</b>.</div>
    <button type="button" class="pwa-ok" data-close-modal>Entendido</button>
  </div>
</div>

<script>
(function () {
  var banner   = document.getElementById('pwaInstallBanner');
  var btnInst  = document.getElementById('pwaInstallBtn');
  var btnClose = document.getElementById('pwaCloseBtn');
  var iosM     = document.getElementById('pwaIosModal');
  var andM     = document.getElementById('pwaAndModal');
  var KEY      = 'pwa_install_dismissed_<?= preg_replace("/[^a-z0-9_]/i", "_", $pwaTitle) ?>';

  // Cerrar modales con [data-close-modal] o click en backdrop
  document.querySelectorAll('[data-close-modal]').forEach(function (b) {
    b.addEventListener('click', function () {
      iosM.classList.remove('show'); andM.classList.remove('show');
      try { sessionStorage.setItem(KEY, '1'); } catch(_){}
    });
  });
  [iosM, andM].forEach(function (m) {
    m && m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('show'); });
  });

  // Si ya está instalada como PWA, no hacer nada
  if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) return;
  try { if (sessionStorage.getItem(KEY) === '1') return; } catch(_){}

  var ua = (navigator.userAgent || '').toLowerCase();
  var isIos = /iphone|ipad|ipod/.test(ua) && !window.MSStream;
  var isSafari = isIos && /safari/.test(ua) && !/crios|fxios|opios/.test(ua);
  var isAndroid = /android/.test(ua);

  // Service worker (necesario para instalación en Chrome)
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function(){});
  }

  var deferred = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    if (banner) banner.style.display = 'flex';
  });

  // Si en Android Chrome NO se dispara beforeinstallprompt (ya instalada antes,
  // o navegador embebido), mostrar banner igual con instrucciones manuales.
  if (isAndroid) {
    setTimeout(function () {
      if (!deferred && banner.style.display !== 'flex') {
        banner.style.display = 'flex';
      }
    }, 2500);
  }

  // iOS: mostrar modal al cargar (Safari no soporta el prompt nativo)
  if (isSafari) {
    setTimeout(function () { iosM.classList.add('show'); }, 1500);
  }

  btnInst && btnInst.addEventListener('click', function () {
    banner.style.display = 'none';
    if (deferred) {
      deferred.prompt();
      deferred.userChoice.finally(function () { deferred = null; });
    } else if (isAndroid) {
      andM.classList.add('show');
    } else if (isIos) {
      iosM.classList.add('show');
    } else {
      andM.classList.add('show');
    }
  });

  btnClose && btnClose.addEventListener('click', function () {
    banner.style.display = 'none';
    try { sessionStorage.setItem(KEY, '1'); } catch(_){}
  });
})();
</script>
