</div><!-- /container -->
<footer class="text-center text-muted small py-4">
  <i class="bi bi-wallet2"></i> Control de Gastos Diarios · v<?= APP_VER ?>
</footer>
</main>

<?php if (!empty($_SESSION['user_id'])):
    // Cargar notificaciones no leidas del usuario
    try {
        $qn = $pdo->prepare("SELECT id, titulo, mensaje, tipo, enlace, creado_en
                             FROM notificaciones
                             WHERE usuario_id=? AND leida=0
                             ORDER BY creado_en DESC");
        $qn->execute([(int)$_SESSION['user_id']]);
        $notifPend = $qn->fetchAll();
    } catch (Exception $e) { $notifPend = []; }
?>
<?php if (!empty($notifPend)): ?>
<!-- ============================================================ -->
<!--  POP-UP DE NOTIFICACIONES PENDIENTES                          -->
<!-- ============================================================ -->
<div class="modal fade" id="mNotif" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">
          <i class="bi bi-bell-fill me-2"></i>
          Tienes <?= count($notifPend) ?> notificacion<?= count($notifPend)===1?'':'es' ?> nueva<?= count($notifPend)===1?'':'s' ?>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-0">
        <div class="list-group list-group-flush" id="notifList">
          <?php foreach ($notifPend as $n):
            $tipo = $n['tipo'] ?: 'info';
            $iconMap = [
                'info'    => ['bi-info-circle-fill',     '#3b82f6'],
                'success' => ['bi-check-circle-fill',    '#10b981'],
                'warning' => ['bi-exclamation-triangle-fill', '#f59e0b'],
                'error'   => ['bi-x-octagon-fill',       '#ef4444'],
            ];
            $ico = $iconMap[$tipo] ?? $iconMap['info'];
          ?>
          <div class="list-group-item py-3 notif-item" data-id="<?= (int)$n['id'] ?>">
            <div class="d-flex gap-3">
              <div style="color:<?= $ico[1] ?>; font-size:1.6rem; line-height:1;">
                <i class="bi <?= $ico[0] ?>"></i>
              </div>
              <div class="flex-grow-1">
                <div class="fw-bold mb-1"><?= h($n['titulo']) ?></div>
                <?php if ($n['mensaje']): ?>
                  <div class="small text-muted mb-2"><?= nl2br(h($n['mensaje'])) ?></div>
                <?php endif; ?>
                <div class="small text-muted">
                  <i class="bi bi-clock"></i> <?= h(date('d/m/Y H:i', strtotime($n['creado_en']))) ?>
                </div>
                <?php if ($n['enlace']): ?>
                  <div class="mt-2">
                    <a href="<?= h($n['enlace']) ?>" class="btn btn-sm btn-primary notif-ir" data-id="<?= (int)$n['id'] ?>">
                      <i class="bi bi-arrow-right-circle me-1"></i>Ir a revisar
                    </a>
                  </div>
                <?php endif; ?>
              </div>
              <button type="button" class="btn btn-sm btn-outline-secondary notif-marcar" data-id="<?= (int)$n['id'] ?>" title="Marcar como leida">
                <i class="bi bi-check2"></i>
              </button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <small class="text-muted">Las notificaciones se marcan como leidas al revisarlas.</small>
        <button type="button" class="btn btn-outline-primary btn-sm" id="btnMarcarTodas">
          <i class="bi bi-check-all me-1"></i>Marcar todas como leidas
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/app.js?v=<?= APP_VER ?>"></script>

<?php if (!empty($notifPend)): ?>
<script>
// Pop-up automatico de notificaciones nuevas
(function(){
  var modalEl = document.getElementById('mNotif');
  if (!modalEl) return;
  var modal = new bootstrap.Modal(modalEl);
  // Mostrar al cargar la pagina (solo la primera vez por sesion de pestana)
  var keyShown = 'notifShown_' + <?= (int)$_SESSION['user_id'] ?> + '_' + <?= count($notifPend) ?>;
  if (!sessionStorage.getItem(keyShown)) {
    setTimeout(function(){ modal.show(); }, 400);
    sessionStorage.setItem(keyShown, '1');
  }
  // Reabrir desde los botones de campana
  ['btnNotifTop','btnNotifSide'].forEach(function(id){
    var b = document.getElementById(id);
    if (b) b.addEventListener('click', function(e){ e.preventDefault(); modal.show(); });
  });

  function marcarLeida(id){
    var fd = new FormData();
    fd.append('accion','marcar_leida');
    fd.append('id', id);
    return fetch('notificaciones_api.php', { method:'POST', body: fd }).catch(function(){});
  }

  // Boton individual "marcar como leida"
  document.querySelectorAll('.notif-marcar').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = this.dataset.id;
      var item = this.closest('.notif-item');
      marcarLeida(id).then(function(){
        if (item) item.remove();
        if (document.querySelectorAll('#notifList .notif-item').length === 0) {
          modal.hide();
          location.reload();
        }
      });
    });
  });

  // Click en "Ir a revisar": marcar leida y seguir navegando
  document.querySelectorAll('.notif-ir').forEach(function(a){
    a.addEventListener('click', function(e){
      e.preventDefault();
      var href = this.getAttribute('href');
      marcarLeida(this.dataset.id).finally(function(){
        window.location.href = href;
      });
    });
  });

  // Boton "Marcar todas como leidas"
  var btnTodas = document.getElementById('btnMarcarTodas');
  if (btnTodas) {
    btnTodas.addEventListener('click', function(){
      var fd = new FormData();
      fd.append('accion','marcar_todas');
      fetch('notificaciones_api.php', { method:'POST', body: fd })
        .finally(function(){ modal.hide(); location.reload(); });
    });
  }
})();
</script>
<?php endif; ?>

<script>
// === Limpieza de backdrops huerfanos (Bootstrap) ===
// Si por alguna razon un modal/offcanvas deja un backdrop activo,
// los clicks de la pagina se bloquean. Esto lo previene.
(function(){
  function limpiarBackdropsHuerfanos(){
    var modalAbierto = document.querySelector('.modal.show');
    var offcanvasAbierto = document.querySelector('.offcanvas.show');
    if (!modalAbierto) {
      document.querySelectorAll('.modal-backdrop').forEach(function(b){ b.remove(); });
    }
    if (!offcanvasAbierto) {
      document.querySelectorAll('.offcanvas-backdrop').forEach(function(b){ b.remove(); });
    }
    if (!modalAbierto && !offcanvasAbierto) {
      document.body.classList.remove('modal-open');
      document.body.style.overflow = '';
      document.body.style.paddingRight = '';
    }
  }
  // Al cargar la pagina
  limpiarBackdropsHuerfanos();
  // Tras cerrar cualquier modal u offcanvas
  document.addEventListener('hidden.bs.modal', function(){ setTimeout(limpiarBackdropsHuerfanos, 100); });
  document.addEventListener('hidden.bs.offcanvas', function(){ setTimeout(limpiarBackdropsHuerfanos, 100); });
})();

// === Sidebar movil: cierre y navegacion robustos ===
(function(){
  var sidebar = document.getElementById('sidebar');
  if (!sidebar) return;

  function isMobile(){ return window.innerWidth < 992; }
  function getOffcanvas(){
    if (!window.bootstrap) return null;
    return bootstrap.Offcanvas.getInstance(sidebar) || new bootstrap.Offcanvas(sidebar);
  }

  // 1) Cerrar offcanvas al hacer click en un link interno (movil).
  //    Navegacion diferida para que la animacion no aborte el click.
  sidebar.querySelectorAll('a[href]:not([href^="#"])').forEach(function(a){
    a.addEventListener('click', function(e){
      if (!isMobile()) return;
      var href = a.getAttribute('href');
      var target = a.getAttribute('target');
      // Permitir confirmar (ej. logout) sin interferir
      var inst = getOffcanvas();
      if (inst) inst.hide();
      if (target === '_blank' || e.ctrlKey || e.metaKey || e.shiftKey) return;
    });
  });

  // 2) Cerrar al hacer click fuera (sobre el backdrop) - Bootstrap ya lo hace,
  //    pero garantizamos que el body se desbloquee si la animacion falla.
  sidebar.addEventListener('hidden.bs.offcanvas', function(){
    document.body.classList.remove('modal-open','offcanvas-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    document.querySelectorAll('.offcanvas-backdrop').forEach(function(b){ b.remove(); });
  });

  // 3) Si por algun motivo el sidebar quedo "show" tras cambiar de tamano,
  //    forzar cierre al pasar a escritorio.
  window.addEventListener('resize', function(){
    if (!isMobile() && sidebar.classList.contains('show')){
      var inst = bootstrap.Offcanvas.getInstance(sidebar);
      if (inst) inst.hide();
    }
  });

  // 4) Boton hamburguesa: garantizar que SIEMPRE abra (por si el data-bs-toggle falla).
  var burger = document.querySelector('[data-bs-target="#sidebar"][data-bs-toggle="offcanvas"]');
  if (burger){
    burger.addEventListener('click', function(e){
      if (!isMobile()) return;
      e.preventDefault();
      var inst = getOffcanvas();
      if (sidebar.classList.contains('show')) inst.hide(); else inst.show();
    });
  }
})();

// === Service worker ===
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js').catch(function(){});
}

// === Widget Comparte/Instala (sidebar) ===
(function(){
  var btnInstall = document.getElementById('btnInstallApp');
  var btnCopy    = document.getElementById('btnCopyApp');
  var btnWa      = document.getElementById('btnShareWA');

  // Boton Copiar enlace
  if (btnCopy) {
    btnCopy.addEventListener('click', function(){
      var url = btnCopy.dataset.appUrl || window.location.origin;
      var done = function(){
        var lbl = btnCopy.querySelector('span');
        var ico = btnCopy.querySelector('i');
        var prevTxt = lbl ? lbl.textContent : '';
        if (lbl) lbl.textContent = 'Copiado';
        if (ico) ico.className = 'bi bi-check2';
        btnCopy.classList.add('copied');
        setTimeout(function(){
          if (lbl) lbl.textContent = prevTxt || 'Copiar';
          if (ico) ico.className = 'bi bi-link-45deg';
          btnCopy.classList.remove('copied');
        }, 1800);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done).catch(function(){
          window.prompt('Copia el enlace:', url);
        });
      } else {
        window.prompt('Copia el enlace:', url);
      }
    });
  }

  // Boton WhatsApp en mobile: usar Web Share nativo si esta disponible
  if (btnWa && navigator.share) {
    btnWa.addEventListener('click', function(e){
      // Si el SO soporta share nativo, usarlo (mejor UX en mobile)
      if (/Android|iPhone|iPad|iPod/.test(navigator.userAgent)) {
        e.preventDefault();
        navigator.share({
          title: 'Control de Gastos Diarios',
          text:  btnWa.dataset.waMsg || 'Te invito a usar Control de Gastos',
          url:   btnWa.dataset.appUrl
        }).catch(function(){
          // si el usuario cancela, abrir el wa.me como fallback
          window.open(btnWa.href, '_blank');
        });
      }
      // Desktop: deja que el href de wa.me se abra normalmente
    });
  }

  // Boton Instalar PWA
  if (!btnInstall) return;
  // Si ya esta instalada, no mostrar
  if (window.matchMedia('(display-mode: standalone)').matches) return;
  if (window.navigator.standalone === true) return;

  var deferred = null;
  var esIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

  if (esIOS) {
    btnInstall.hidden = false;
    btnInstall.addEventListener('click', function(e){
      e.preventDefault();
      alert('Para instalar Control Gastos en iOS:\n\n1. Toca el boton Compartir\n2. Luego elige "Agregar a inicio"');
    });
    return;
  }

  window.addEventListener('beforeinstallprompt', function(e){
    e.preventDefault();
    deferred = e;
    btnInstall.hidden = false;
  });

  btnInstall.addEventListener('click', function(e){
    e.preventDefault();
    if (!deferred) return;
    deferred.prompt();
    deferred.userChoice.then(function(){
      deferred = null;
      btnInstall.hidden = true;
    });
  });

  window.addEventListener('appinstalled', function(){
    btnInstall.hidden = true;
    deferred = null;
  });
})();
</script>

<?php if (!empty($_SESSION['user_id'])): ?>
<!-- ── TOAST estilo WhatsApp para notificaciones en tiempo real ── -->
<style>
#waToastWrap { position: fixed; bottom: 18px; right: 18px; z-index: 9999; display: flex; flex-direction: column; gap: 10px; pointer-events: none; }
.wa-toast {
  pointer-events: auto;
  width: 360px; max-width: calc(100vw - 36px);
  background: #128C7E; color: #fff;
  border-radius: 12px;
  box-shadow: 0 6px 24px rgba(0,0,0,.25);
  overflow: hidden;
  animation: waSlideIn .35s cubic-bezier(.2,.9,.3,1.1);
  border-left: 5px solid #25D366;
}
.wa-toast.cerrando { animation: waSlideOut .25s ease-in forwards; }
.wa-toast-head {
  background: rgba(255,255,255,.08);
  padding: 8px 12px;
  display: flex; align-items: center; gap: 8px;
  font-weight: 700; font-size: .85rem;
}
.wa-toast-head .wa-x {
  margin-left: auto; cursor: pointer; opacity: .8;
  background: none; border: none; color: #fff; font-size: 1.1rem;
}
.wa-toast-head .wa-x:hover { opacity: 1; }
.wa-toast-body {
  padding: 12px 14px;
  font-size: .92rem; line-height: 1.35;
  background: #FFF; color: #0b1d2a;
}
.wa-toast-body small { color: #128C7E; font-weight: 700; display: block; margin-bottom: 3px; font-size: .72rem; }
.wa-toast-foot {
  padding: 8px 12px;
  background: #ECE5DD;
  display: flex; gap: 8px; justify-content: flex-end;
}
.wa-toast-foot a, .wa-toast-foot button {
  background: transparent; border: 1px solid #128C7E; color: #128C7E;
  padding: 4px 12px; border-radius: 6px; font-size: .78rem; font-weight: 700;
  text-decoration: none; cursor: pointer;
}
.wa-toast-foot .wa-primary { background: #25D366; color: #fff; border-color: #25D366; }
@keyframes waSlideIn { from { transform: translateX(110%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes waSlideOut { to { transform: translateX(110%); opacity: 0; } }
@media (max-width: 600px) {
  #waToastWrap { bottom: 70px; right: 12px; left: 12px; }
  .wa-toast { width: 100%; }
}
</style>
<div id="waToastWrap"></div>
<audio id="waBeep" src="data:audio/wav;base64,UklGRrYAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YZIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA" preload="auto"></audio>
<script>
(function(){
  var wrap = document.getElementById('waToastWrap');
  if (!wrap) return;
  var mostradas = new Set();
  try { (JSON.parse(sessionStorage.getItem('wa_seen')||'[]')||[]).forEach(function(id){ mostradas.add(id); }); } catch(e){}
  function guardarVistas(){ try { sessionStorage.setItem('wa_seen', JSON.stringify(Array.from(mostradas))); } catch(e){} }
  function fmtTime(s) {
    if (!s) return '';
    try { return new Date(s.replace(' ','T')).toLocaleTimeString('es-CL', {hour:'2-digit', minute:'2-digit'}); }
    catch(e){ return s; }
  }
  function escape(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function mostrar(n){
    if (mostradas.has(n.id)) return;
    mostradas.add(n.id); guardarVistas();
    var el = document.createElement('div'); el.className = 'wa-toast';
    el.innerHTML =
      '<div class="wa-toast-head"><i class="bi bi-whatsapp"></i><span>'+escape(n.titulo||'Notificación')+'</span>'+
      '<button class="wa-x" title="Cerrar">&times;</button></div>'+
      '<div class="wa-toast-body"><small>'+fmtTime(n.creado_en)+'</small>'+escape(n.mensaje||'')+'</div>'+
      (n.enlace ? '<div class="wa-toast-foot">'+
        '<button class="wa-mark-read">Marcar leída</button>'+
        '<a href="'+escape(n.enlace)+'" class="wa-primary">Ver</a>'+
      '</div>' : '');
    wrap.appendChild(el);
    try { var beep = document.getElementById('waBeep'); if (beep) beep.play().catch(function(){}); } catch(e){}
    var cerrar = function(){ el.classList.add('cerrando'); setTimeout(function(){ el.remove(); }, 250); };
    el.querySelector('.wa-x').addEventListener('click', function(){ marcarLeida(n.id); cerrar(); });
    var btn = el.querySelector('.wa-mark-read');
    if (btn) btn.addEventListener('click', function(){ marcarLeida(n.id); cerrar(); });
    setTimeout(cerrar, 12000);
  }
  function marcarLeida(id){
    var fd = new FormData(); fd.append('id', id);
    fetch('notif_marcar.php', {method:'POST', body: fd}).catch(function(){});
  }
  function poll(){
    fetch('notif_poll.php', {credentials:'same-origin'})
      .then(function(r){ return r.ok ? r.json() : null; })
      .then(function(d){ if (d && d.ok) (d.pendientes || []).forEach(mostrar); })
      .catch(function(){});
  }
  poll();
  setInterval(poll, 15000);
})();
</script>
<?php endif; ?>

</body>
</html>
