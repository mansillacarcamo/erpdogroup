<?php if (!empty($usuario)): ?></div><?php endif; ?>
</main>
<?php if (!empty($usuario)): ?></div><?php endif; ?>
<footer class="text-center text-muted py-3 border-top bg-white no-print app-footer">
  <small class="d-block mb-1">DOGROUP &copy; <?= date('Y') ?> &mdash; Sistema OC <span style="color:#16a34a;font-weight:600">[deploy-test ✓]</span></small>
  <small class="d-block">
    Todos los derechos reservados &middot; Desarrollado por
    <strong>Departamento de Informática DOGroup</strong>
  </small>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($usuario)):
  $popupOC = [];
  $popupCot = [];
  try {
    $stmtPopOC = $pdo->prepare("
      SELECT a.id, o.numero, o.proveedor_nombre, o.total, o.moneda, o.preparada_por
      FROM oc_aprobaciones a
      JOIN ordenes_compra o ON a.oc_id = o.id
      WHERE a.usuario_id = ? AND a.estado = 'pendiente'
      ORDER BY a.creado_en DESC
    ");
    $stmtPopOC->execute([$usuario['id']]);
    $popupOC = $stmtPopOC->fetchAll(PDO::FETCH_ASSOC);

    $stmtPopCot = $pdo->prepare("
      SELECT a.id, c.numero, c.cliente_nombre, c.total, c.creada_por
      FROM cot_aprobaciones a
      JOIN cotizaciones c ON a.cot_id = c.id
      WHERE a.usuario_id = ? AND a.estado = 'pendiente'
      ORDER BY a.creado_en DESC
    ");
    $stmtPopCot->execute([$usuario['id']]);
    $popupCot = $stmtPopCot->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) {}
  $totalPopup = count($popupOC) + count($popupCot);
?>

<?php if ($totalPopup > 0): ?>
<div class="modal fade" id="popupNotifPendientes" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-warning" style="border-width:2px;">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title"><i class="bi bi-bell-fill me-2"></i>Tiene <?= $totalPopup ?> validaci<?= $totalPopup === 1 ? 'ón pendiente' : 'ones pendientes' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="max-height:400px;overflow-y:auto;">
        <?php if (!empty($popupOC)): ?>
        <h6 class="fw-bold text-dark mb-2"><i class="bi bi-file-earmark-text me-1"></i>Órdenes de Compra</h6>
        <?php foreach ($popupOC as $poc): ?>
        <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
          <div>
            <span class="badge bg-dark me-1">OC N° <?= $poc['numero'] ?></span>
            <small><?= htmlspecialchars($poc['proveedor_nombre']) ?></small>
            <br><small class="text-muted">Total: <strong>$<?= number_format($poc['total'], 0, ',', '.') ?></strong> — Por: <?= htmlspecialchars($poc['preparada_por']) ?></small>
          </div>
          <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($popupCot)): ?>
        <?php if (!empty($popupOC)): ?><hr><?php endif; ?>
        <h6 class="fw-bold text-dark mb-2"><i class="bi bi-receipt me-1"></i>Cotizaciones</h6>
        <?php foreach ($popupCot as $pcot): ?>
        <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
          <div>
            <span class="badge bg-dark me-1">Cot. N° <?= $pcot['numero'] ?></span>
            <small><?= htmlspecialchars($pcot['cliente_nombre']) ?></small>
            <br><small class="text-muted">Total: <strong>$<?= number_format($pcot['total'], 0, ',', '.') ?></strong> — Por: <?= htmlspecialchars($pcot['creada_por']) ?></small>
          </div>
          <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="modal-footer justify-content-center">
        <a href="/mis_aprobaciones.php" class="btn btn-warning"><i class="bi bi-shield-check me-1"></i>Ir a Mis Aprobaciones</a>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var shown = sessionStorage.getItem('notifPopupShown_<?= $usuario['id'] ?>_<?= $totalPopup ?>');
  if (!shown) {
    var m = new bootstrap.Modal(document.getElementById('popupNotifPendientes'));
    m.show();
    sessionStorage.setItem('notifPopupShown_<?= $usuario['id'] ?>_<?= $totalPopup ?>', '1');
  }
});
</script>
<?php endif; ?>

<script>
(function(){
  var t=document.getElementById('sidebarToggle'),
      c=document.getElementById('sidebarClose'),
      o=document.getElementById('sidebarOverlay'),
      s=document.getElementById('sidebar'),
      b=document.body;
  // Limpieza única: cualquier preferencia previa de collapse se descarta para que el sidebar
  // siempre arranque visible. El usuario puede volver a colapsarlo con el botón si quiere.
  try { localStorage.removeItem('dogroup_sidebar_collapsed'); } catch(e) {}
  b.classList.remove('sidebar-collapsed');
  if(!t||!s)return;
  function isMobile(){return window.matchMedia('(max-width: 767.98px)').matches;}
  function toggle(){
    if(isMobile()){
      b.classList.toggle('sidebar-open');
    } else {
      b.classList.toggle('sidebar-collapsed');
    }
  }
  function closeMobile(){b.classList.remove('sidebar-open');}
  t.addEventListener('click', toggle);
  if(c) c.addEventListener('click', closeMobile);
  if(o) o.addEventListener('click', closeMobile);
  s.querySelectorAll('.btn-menu').forEach(function(l){
    l.addEventListener('click', function(){ if(isMobile()) closeMobile(); });
  });
})();

// Sidebar: áreas plegables. Recuerda el estado de cada área en localStorage y abre la
// que contenga la página activa.
(function(){
  var KEY = 'dogroup_sidebar_areas_open';
  var toggles = document.querySelectorAll('.sidebar-area-toggle');
  if (!toggles.length) return;
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch(e) { saved = {}; }
  var aquiArchivo = location.pathname.split('/').pop();
  function setOpen(area, open){
    var btn = document.querySelector('.sidebar-area-toggle[data-area="'+area+'"]');
    var box = document.querySelector('.sidebar-area-content[data-area="'+area+'"]');
    if (!btn || !box) return;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    box.classList.toggle('open', !!open);
  }
  toggles.forEach(function(btn){
    var area = btn.getAttribute('data-area');
    var box = document.querySelector('.sidebar-area-content[data-area="'+area+'"]');
    if (!box) return;
    var hayActivo = false;
    if (aquiArchivo) {
      box.querySelectorAll('a[href]').forEach(function(a){
        var href = a.getAttribute('href') || '';
        var archivo = href.split('?')[0].split('/').pop();
        if (archivo && archivo === aquiArchivo) hayActivo = true;
      });
    }
    var abrir = hayActivo || saved[area] === true;
    setOpen(area, abrir);
    btn.addEventListener('click', function(){
      var ahora = btn.getAttribute('aria-expanded') === 'true';
      setOpen(area, !ahora);
      saved[area] = !ahora;
      try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch(e){}
    });
  });
})();

// Sidebar autohide: oculto por defecto en escritorio, aparece al pasar el puntero por la
// franja izquierda (visible) y se oculta al salir.
(function(){
  var sidebar = document.getElementById('sidebar');
  if (!sidebar) return;
  var b = document.body;
  function isMobile(){ return window.matchMedia('(max-width: 767.98px)').matches; }
  if (isMobile()) return;

  b.classList.add('sidebar-autohide');
  b.classList.remove('sidebar-collapsed');

  var trigger = document.querySelector('.sidebar-hover-trigger');
  if (!trigger) {
    trigger = document.createElement('div');
    trigger.className = 'sidebar-hover-trigger';
    trigger.title = 'Mostrar menú';
    trigger.innerHTML = '<i class="bi bi-list"></i>';
    document.body.appendChild(trigger);
  }

  var hideTimer = null;
  function show(){ clearTimeout(hideTimer); b.classList.add('sidebar-peek'); }
  function hide(){
    clearTimeout(hideTimer);
    hideTimer = setTimeout(function(){ b.classList.remove('sidebar-peek'); }, 200);
  }
  trigger.addEventListener('mouseenter', show);
  trigger.addEventListener('click', show);
  sidebar.addEventListener('mouseenter', show);
  sidebar.addEventListener('mouseleave', hide);
})();

// Dashboard: cards plegables (cada module-card abre/cierra sus acciones al hacer clic en el encabezado).
(function(){
  var cards = document.querySelectorAll('.module-card');
  if (!cards.length) return;
  cards.forEach(function(card){
    var header = card.querySelector('.module-card-header');
    if (!header) return;
    if (!header.querySelector('.module-chevron')) {
      var chev = document.createElement('i');
      chev.className = 'bi bi-chevron-right module-chevron';
      header.appendChild(chev);
    }
    header.addEventListener('click', function(e){
      if (e.target.closest('a,button')) return;
      card.classList.toggle('expanded');
    });
  });
})();

// Dashboard: áreas plegables (inicio.php). Persiste el estado en localStorage.
(function(){
  var KEY = 'dogroup_dashboard_areas_open';
  var toggles = document.querySelectorAll('.dashboard-area-toggle');
  if (!toggles.length) return;
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch(e) { saved = {}; }
  function setOpen(area, open){
    var btn = document.querySelector('.dashboard-area-toggle[data-area="'+area+'"]');
    var box = document.querySelector('.dashboard-area-content[data-area="'+area+'"]');
    if (!btn || !box) return;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    box.classList.toggle('open', !!open);
  }
  toggles.forEach(function(btn){
    var area = btn.getAttribute('data-area');
    var abrir = saved[area] === true;
    setOpen(area, abrir);
    btn.addEventListener('click', function(){
      var ahora = btn.getAttribute('aria-expanded') === 'true';
      setOpen(area, !ahora);
      saved[area] = !ahora;
      try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch(e){}
    });
  });
})();

// Función global de "Volver atrás": cualquier elemento con [data-volver] (a/button) regresa
// a la página anterior usando history.back() y, si no hay historial, va al data-fallback.
window.volverAtras = function(fallback){
  if (window.history.length > 1) {
    window.history.back();
  } else if (fallback) {
    window.location.href = fallback;
  } else {
    window.location.href = '/inicio.php';
  }
};
document.addEventListener('click', function(e){
  var el = e.target.closest('[data-volver]');
  if (!el) return;
  e.preventDefault();
  window.volverAtras(el.getAttribute('data-fallback') || el.getAttribute('href') || '/inicio.php');
}, true);

// Botón "Volver atrás" inteligente: usa el referrer del mismo origen si está disponible;
// de lo contrario navega al fallback (inicio.php). Evita history.back() que puede dejar
// pantalla en blanco en pestañas nuevas o tras un POST.
(function(){
  var btn = document.getElementById('btnVolverAtras');
  if (!btn) return;
  btn.addEventListener('click', function(e){
    e.preventDefault();
    var fallback = btn.getAttribute('data-fallback') || 'inicio.php';
    if (document.referrer) {
      try {
        var refUrl = new URL(document.referrer);
        if (refUrl.origin === window.location.origin && refUrl.href !== window.location.href) {
          window.location.href = refUrl.href;
          return;
        }
      } catch (err) {}
    }
    window.location.href = fallback;
  });
})();
</script>
<?php endif; ?>
</body>
</html>
