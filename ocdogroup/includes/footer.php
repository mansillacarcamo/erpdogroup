<?php if (!empty($usuario)): ?></div><?php endif; ?>
</main>
<?php if (!empty($usuario)): ?></div><?php endif; ?>
<footer class="text-center text-muted py-3 border-top bg-white no-print app-footer">
  <small class="d-block mb-1">DOGROUP &copy; <?= date('Y') ?> &mdash; Sistema OC</small>
  <small class="d-block">
    Todos los derechos reservados &middot; Desarrollado por
    <strong>César Mansilla</strong> / <strong>Bynari SpA</strong>
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
        <a href="mis_aprobaciones.php" class="btn btn-warning"><i class="bi bi-shield-check me-1"></i>Ir a Mis Aprobaciones</a>
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
  if(!t||!s)return;
  var KEY='dogroup_sidebar_collapsed';
  function isMobile(){return window.matchMedia('(max-width: 767.98px)').matches;}
  if(localStorage.getItem(KEY)==='1' && !isMobile()){
    b.classList.add('sidebar-collapsed');
  }
  function toggle(){
    if(isMobile()){
      b.classList.toggle('sidebar-open');
    } else {
      b.classList.toggle('sidebar-collapsed');
      localStorage.setItem(KEY, b.classList.contains('sidebar-collapsed') ? '1' : '0');
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

// Botón "Volver atrás" inteligente: usa history.back() si hay historial dentro del sitio,
// de lo contrario navega al fallback (inicio.php).
(function(){
  var btn = document.getElementById('btnVolverAtras');
  if (!btn) return;
  btn.addEventListener('click', function(e){
    e.preventDefault();
    var fallback = btn.getAttribute('data-fallback') || 'inicio.php';
    if (window.history.length > 1 && document.referrer) {
      try {
        var refOrigin = new URL(document.referrer).origin;
        if (refOrigin === window.location.origin) {
          window.history.back();
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
