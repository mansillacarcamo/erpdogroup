<?php
require_once 'config.php';
$rol = $_SESSION['user_rol'] ?? '';
requireAuth();
if (!in_array($rol, ['jefe','admin','validador'])) { http_response_code(403); die('Sin permiso.'); }

$uid  = (int)($_GET['u'] ?? 0);
$p = periodoActual();
$anio = (int)($_GET['anio'] ?? $p['anio']);
$mes  = (int)($_GET['mes']  ?? $p['mes']);

$u = $pdo->prepare("SELECT * FROM usuarios WHERE id=?"); $u->execute([$uid]); $u = $u->fetch();
if (!$u) { flash('error','Usuario no encontrado.'); header('Location: jefe_dashboard.php'); exit; }
if ($rol === 'jefe' && $u['jefe_zonal_id'] != $_SESSION['user_id']) { http_response_code(403); die('Sin permiso.'); }

$ini = sprintf('%04d-%02d-01',$anio,$mes); $fin = date('Y-m-t', strtotime($ini));
$gs = $pdo->prepare("SELECT g.*, (SELECT COUNT(*) FROM archivos_gasto a WHERE a.gasto_id=g.id) as nadj
                     FROM gastos g WHERE usuario_id=? AND fecha BETWEEN ? AND ? ORDER BY fecha");
$gs->execute([$uid,$ini,$fin]);
$gastos = $gs->fetchAll();
$a = asignacionPeriodo($pdo,$uid,$anio,$mes);
$tot = array_sum(array_map(fn($g)=>(float)$g['monto'], $gastos));
$saldo = ($a['total']??0) - $tot;

// Contadores por estado
$nPend = 0; $nAprob = 0; $nRech = 0;
foreach ($gastos as $gg) {
    if ($gg['estado'] === 'aprobado_jefe') $nAprob++;
    elseif ($gg['estado'] === 'rechazado_jefe') $nRech++;
    elseif ($gg['estado'] === 'registrado') $nPend++;
}
$puedeRevisar = in_array($rol, ['jefe','admin']);
$urlActual = 'jefe_ver_usuario.php?u='.$uid.'&anio='.$anio.'&mes='.$mes;

$titulo = 'Gastos de '.$u['nombre'];
include 'includes/head.php'; include 'includes/nav.php';
?>
<a href="javascript:history.back()" class="text-decoration-none small mb-2 d-inline-block"><i class="bi bi-arrow-left"></i> Volver</a>
<div class="card mb-3"><div class="card-body">
  <div class="d-flex justify-content-between flex-wrap gap-3">
    <div class="flex-grow-1">
      <div class="d-flex align-items-center gap-2">
        <?php $urlFotoJv = urlFotoUsuario($u['foto_perfil'] ?? ''); ?>
        <?php if ($urlFotoJv): ?>
          <img src="<?= h($urlFotoJv) ?>" alt="<?= h($u['nombre']) ?>" class="tecnico-avatar" style="object-fit:cover;">
        <?php else: ?>
          <div class="tecnico-avatar"><?= strtoupper(mb_substr($u['nombre'],0,1)) ?></div>
        <?php endif; ?>
        <div>
          <h5 class="mb-0 fw-bold"><?= h($u['nombre']) ?></h5>
          <div class="small text-muted">
            <i class="bi bi-person"></i> @<?= h($u['usuario']) ?>
            <?php if ($u['cargo']): ?> - <?= h($u['cargo']) ?><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="mt-2 d-flex flex-wrap gap-1">
        <?php if ($u['region']): ?>
          <span class="badge bg-primary-subtle text-primary"><i class="bi bi-geo-alt-fill"></i> <?= h($u['region']) ?></span>
        <?php endif; ?>
        <?php if ($u['ciudad']): ?>
          <span class="badge bg-info-subtle text-info-emphasis"><i class="bi bi-geo-alt"></i> <?= h($u['ciudad']) ?></span>
        <?php endif; ?>
        <?php if ($u['zona']): ?>
          <span class="badge bg-light text-dark border"><?= h($u['zona']) ?></span>
        <?php endif; ?>
        <span class="badge bg-light text-dark border"><?= h($u['email']) ?></span>
        <?php if ($u['telefono']): ?>
          <span class="badge bg-light text-dark border"><i class="bi bi-telephone"></i> <?= h($u['telefono']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="text-end">
      <div class="small text-muted">Periodo</div>
      <div class="fw-bold"><?= nombreMes($mes) ?> <?= $anio ?></div>
    </div>
  </div>
  <style>
    .tecnico-avatar{ width:48px; height:48px; border-radius:50%; background:linear-gradient(135deg,#0d6efd,#6610f2); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.3rem; }
  </style>
  <hr>
  <div class="row g-2">
    <div class="col-6 col-md-3"><div class="kpi"><div class="lbl">Asignado</div><div class="val"><?= fmtCLP($a['total']??0) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi"><div class="lbl">Gastado</div><div class="val text-danger"><?= fmtCLP($tot) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi"><div class="lbl">Saldo</div><div class="val text-<?= $saldo<0?'danger':'success' ?>"><?= fmtCLP($saldo) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi"><div class="lbl">N° gastos</div><div class="val"><?= count($gastos) ?></div></div></div>
  </div>
  <?php if ($puedeRevisar && count($gastos) > 0): ?>
    <div class="mt-3 d-flex flex-wrap gap-2 small">
      <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> Pendientes: <?= $nPend ?></span>
      <span class="badge bg-success"><i class="bi bi-check-circle"></i> Aprobados: <?= $nAprob ?></span>
      <span class="badge bg-danger"><i class="bi bi-x-circle"></i> Rechazados: <?= $nRech ?></span>
    </div>
  <?php endif; ?>
</div></div>

<div class="card"><div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0">
  <thead class="table-light"><tr>
    <th>Fecha</th><th>Categoría</th><th>Detalle</th><th>Doc</th><th>Adj</th>
    <th class="text-end">Monto</th><th>Estado</th><th class="text-end">Acciones</th>
  </tr></thead>
  <tbody>
  <?php foreach ($gastos as $g):
    $estClass = [
      'registrado' => 'warning',
      'aprobado_jefe' => 'success',
      'rechazado_jefe' => 'danger',
    ][$g['estado']] ?? 'secondary';
  ?>
    <tr>
      <td class="small"><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
      <td><span class="cat-pill"><?= htmlspecialchars($g['categoria']?:'—') ?></span></td>
      <td class="small">
        <?= htmlspecialchars($g['descripcion']?:($g['proveedor']?:'—')) ?>
        <?php if ($g['observacion_revision']): ?>
          <div class="text-muted small mt-1" style="font-size:11px;"><i class="bi bi-chat-left-text"></i> <?= h($g['observacion_revision']) ?></div>
        <?php endif; ?>
      </td>
      <td class="small"><?= htmlspecialchars($g['tipo_documento']?:'') ?> <?= htmlspecialchars($g['numero_documento']?:'') ?></td>
      <td class="small"><?= $g['nadj'] ? '<i class="bi bi-paperclip"></i> '.(int)$g['nadj'] : '' ?></td>
      <td class="text-end fw-semibold"><?= fmtCLP($g['monto']) ?></td>
      <td><span class="badge bg-<?= $estClass ?>"><?= h($g['estado']) ?></span></td>
      <td class="text-end text-nowrap">
        <a href="gasto_ver.php?id=<?= $g['id'] ?>" class="btn btn-sm btn-outline-primary" title="Ver detalle"><i class="bi bi-eye"></i></a>
        <?php if ($puedeRevisar && $g['estado'] === 'registrado'): ?>
          <button class="btn btn-sm btn-success btn-revisar" title="Revisar (aprobar / rechazar / observar)"
                  data-gid="<?= (int)$g['id'] ?>"
                  data-desc="<?= h($g['descripcion'] ?: $g['categoria']) ?>"
                  data-fecha="<?= h(date('d/m/Y', strtotime($g['fecha']))) ?>"
                  data-monto="<?= h(fmtCLP($g['monto'])) ?>">
            <i class="bi bi-clipboard-check"></i> Revisar
          </button>
        <?php elseif ($puedeRevisar && in_array($g['estado'], ['aprobado_jefe','rechazado_jefe'])): ?>
          <!-- Permitir dejar observacion adicional aun despues -->
          <button class="btn btn-sm btn-outline-info btn-solo-obs" title="Dejar observacion adicional"
                  data-gid="<?= (int)$g['id'] ?>">
            <i class="bi bi-chat-left-text"></i>
          </button>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$gastos): ?><tr><td colspan="8" class="text-center text-muted py-4">Sin gastos en este periodo.</td></tr><?php endif; ?>
  </tbody>
</table>
</div></div></div>

<?php if ($puedeRevisar): ?>
<!-- Modal unificado de revision (aprobar / rechazar / observar) -->
<div class="modal fade" id="mRev" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="gasto_revisar.php" id="fRev">
        <input type="hidden" name="accion" id="revAccion" value="aprobar">
        <input type="hidden" name="gid" id="revGid">
        <input type="hidden" name="volver" value="<?= h($urlActual) ?>">
        <div class="modal-header" id="revHeader">
          <h5 class="modal-title"><i class="bi bi-clipboard-check me-1"></i>Revisar gasto</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="alert alert-light small border" id="revDesc"></div>

          <label class="form-label fw-semibold mb-1">Accion</label>
          <div class="btn-group w-100 mb-3" role="group">
            <input type="radio" class="btn-check" name="revAcc" id="accAprob" value="aprobar" checked>
            <label class="btn btn-outline-success" for="accAprob"><i class="bi bi-check-lg"></i> Aprobar</label>

            <input type="radio" class="btn-check" name="revAcc" id="accObs" value="observar">
            <label class="btn btn-outline-info" for="accObs"><i class="bi bi-chat-left-text"></i> Solo observar</label>

            <input type="radio" class="btn-check" name="revAcc" id="accRech" value="rechazar">
            <label class="btn btn-outline-danger" for="accRech"><i class="bi bi-x-lg"></i> Rechazar</label>
          </div>

          <label class="form-label fw-semibold" id="revLblObs">Observacion (opcional)</label>
          <textarea name="obs" id="revObs" class="form-control" rows="3" placeholder="Observacion opcional para el tecnico..."></textarea>
          <div class="form-text" id="revHelp">El tecnico recibira una notificacion con tu observacion.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-success" id="revSubmit"><i class="bi bi-check-circle me-1"></i>Aprobar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal solo observacion (para gastos ya aprobados/rechazados) -->
<div class="modal fade" id="mObs" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="gasto_revisar.php">
        <input type="hidden" name="accion" value="observar">
        <input type="hidden" name="gid" id="obsGid">
        <input type="hidden" name="volver" value="<?= h($urlActual) ?>">
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title"><i class="bi bi-chat-left-text me-1"></i>Dejar observacion</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label fw-semibold">Observacion *</label>
          <textarea name="obs" class="form-control" rows="3" required placeholder="Escribe tu observacion al tecnico..."></textarea>
          <div class="form-text">El tecnico recibira una notificacion. No se cambia el estado del gasto.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-info text-white"><i class="bi bi-send me-1"></i>Enviar observacion</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  var mRev = new bootstrap.Modal(document.getElementById('mRev'));
  var accInput = document.getElementById('revAccion');
  var btnSubmit = document.getElementById('revSubmit');
  var lbl = document.getElementById('revLblObs');
  var obs = document.getElementById('revObs');
  var help = document.getElementById('revHelp');

  function setAccion(a){
    accInput.value = a;
    if (a === 'aprobar') {
      btnSubmit.className = 'btn btn-success';
      btnSubmit.innerHTML = '<i class="bi bi-check-circle me-1"></i>Aprobar gasto';
      lbl.textContent = 'Observacion (opcional)';
      obs.required = false;
      obs.placeholder = 'Observacion opcional para el tecnico...';
      help.textContent = 'Puedes aprobar sin comentar o con una observacion.';
    } else if (a === 'observar') {
      btnSubmit.className = 'btn btn-info text-white';
      btnSubmit.innerHTML = '<i class="bi bi-send me-1"></i>Enviar observacion';
      lbl.textContent = 'Observacion *';
      obs.required = true;
      obs.placeholder = 'Ej: adjuntar boleta legible, detallar motivo...';
      help.textContent = 'El tecnico recibira la observacion. El gasto queda pendiente.';
    } else {
      btnSubmit.className = 'btn btn-danger';
      btnSubmit.innerHTML = '<i class="bi bi-x-circle me-1"></i>Rechazar gasto';
      lbl.textContent = 'Motivo del rechazo *';
      obs.required = true;
      obs.placeholder = 'Ej: falta boleta, monto no corresponde...';
      help.textContent = 'El tecnico recibira una notificacion con el motivo.';
    }
  }
  document.querySelectorAll('input[name="revAcc"]').forEach(function(r){
    r.addEventListener('change', function(){ setAccion(this.value); });
  });

  document.querySelectorAll('.btn-revisar').forEach(function(b){
    b.addEventListener('click', function(){
      document.getElementById('revGid').value = this.dataset.gid;
      document.getElementById('revDesc').innerHTML =
        '<strong>'+this.dataset.fecha+'</strong> &middot; '+this.dataset.desc+' &middot; <strong>'+this.dataset.monto+'</strong>';
      document.getElementById('accAprob').checked = true;
      setAccion('aprobar');
      obs.value = '';
      mRev.show();
    });
  });

  var mObs = new bootstrap.Modal(document.getElementById('mObs'));
  document.querySelectorAll('.btn-solo-obs').forEach(function(b){
    b.addEventListener('click', function(){
      document.getElementById('obsGid').value = this.dataset.gid;
      mObs.show();
    });
  });
})();
</script>
<?php endif; ?>

<div class="mt-3 text-end">
  <a href="exportar_pdf.php?u=<?= $uid ?>&anio=<?= $anio ?>&mes=<?= $mes ?>" target="_blank" class="btn btn-outline-danger">
    <i class="bi bi-file-earmark-pdf"></i> Exportar PDF
  </a>
</div>

<?php include 'includes/foot.php'; ?>
