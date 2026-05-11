<?php
require_once 'config.php';
requireAuth();

$uid = (int)$_SESSION['user_id'];
$p = periodoActual();
$anio = (int)($_GET['anio'] ?? $p['anio']);
$mes  = (int)($_GET['mes']  ?? $p['mes']);

$ini = sprintf('%04d-%02d-01', $anio, $mes);
$fin = date('Y-m-t', strtotime($ini));

$q = $pdo->prepare("SELECT g.*, (SELECT COUNT(*) FROM archivos_gasto a WHERE a.gasto_id=g.id) as nadj
                    FROM gastos g WHERE usuario_id=? AND fecha BETWEEN ? AND ?
                    ORDER BY fecha DESC, id DESC");
$q->execute([$uid,$ini,$fin]);
$gastos = $q->fetchAll();

$total = array_sum(array_map(fn($g)=>(float)$g['monto'], $gastos));
$asig = asignacionPeriodo($pdo, $uid, $anio, $mes);
$saldo = ($asig['total'] ?? 0) - $total;

// Contadores por estado de revision
$nAprob = 0; $nRech = 0; $nObs = 0; $nPend = 0;
foreach ($gastos as $g) {
    if ($g['estado'] === 'aprobado_jefe')       $nAprob++;
    elseif ($g['estado'] === 'rechazado_jefe')  $nRech++;
    elseif (!empty($g['observacion_revision'])) $nObs++;
    else                                        $nPend++;
}

$mu = $pdo->prepare("SELECT nombre, usuario, ciudad, region, zona, cargo, foto_perfil FROM usuarios WHERE id=?");
$mu->execute([$uid]); $mu = $mu->fetch();

$qc = $pdo->prepare("SELECT * FROM cierres_mensuales WHERE usuario_id=? AND anio=? AND mes=?");
$qc->execute([$uid, $anio, $mes]);
$cierrePeriodo = $qc->fetch();

$titulo = 'Mis gastos';
include 'includes/head.php';
include 'includes/nav.php';
?>

<div class="card mb-3 border-0 bg-light">
  <div class="card-body py-2 px-3 d-flex align-items-center gap-2 flex-wrap">
    <?php $urlFotoMu = urlFotoUsuario($mu['foto_perfil'] ?? ''); ?>
    <?php if ($urlFotoMu): ?>
      <img src="<?= h($urlFotoMu) ?>" alt="<?= h($mu['nombre']) ?>" class="tecnico-avatar-sm" style="object-fit:cover;">
    <?php else: ?>
      <div class="tecnico-avatar-sm"><?= strtoupper(mb_substr($mu['nombre'],0,1)) ?></div>
    <?php endif; ?>
    <div class="flex-grow-1">
      <div class="fw-bold"><?= h($mu['nombre']) ?></div>
      <div class="small text-muted">
        <?php if ($mu['cargo']): ?><?= h($mu['cargo']) ?> - <?php endif; ?>
        <?php if ($mu['region']): ?><i class="bi bi-geo-alt-fill"></i> <?= h($mu['region']) ?><?php endif; ?>
        <?php if ($mu['ciudad']): ?> - <?= h($mu['ciudad']) ?><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<style>.tecnico-avatar-sm{ width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,#0d6efd,#6610f2); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; }</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-list-ul me-2"></i>Mis gastos - <?= nombreMes($mes) ?> <?= $anio ?></h4>
  <form class="d-flex gap-2" method="get">
    <select name="mes" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php for($m=1;$m<=12;$m++): ?>
        <option value="<?= $m ?>" <?= $m===$mes?'selected':'' ?>><?= nombreMes($m) ?></option>
      <?php endfor; ?>
    </select>
    <select name="anio" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php for($y=date('Y');$y>=date('Y')-3;$y--): ?>
        <option value="<?= $y ?>" <?= $y===$anio?'selected':'' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
    <a href="gasto_nuevo.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i></a>
  </form>
</div>

<?php if ($cierrePeriodo): ?>
<div class="alert alert-<?= in_array($cierrePeriodo['estado'], ['aprobado','validado']) ? 'success' : (in_array($cierrePeriodo['estado'], ['enviado_jefe','enviado_validador']) ? 'info' : ($cierrePeriodo['estado']==='rechazado' ? 'warning' : 'secondary')) ?> py-2 px-3 mb-3 d-flex align-items-center flex-wrap gap-2">
  <i class="bi bi-send-check"></i>
  <span>Cierre del periodo: <strong class="estado <?= h($cierrePeriodo['estado']) ?>"><?= h($cierrePeriodo['estado']) ?></strong></span>
  <?php if (!empty($cierrePeriodo['enviado_jefe_en'])): ?>
    <span class="small text-muted">- Enviado al Gerente de Finanzas: <?= h($cierrePeriodo['enviado_jefe_en']) ?></span>
  <?php endif; ?>
  <?php if (!empty($cierrePeriodo['observaciones_jefe'])): ?>
    <div class="w-100 small mt-1"><strong>Gerente de Finanzas:</strong> <?= nl2br(h($cierrePeriodo['observaciones_jefe'])) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="exportar_pdf.php?anio=<?= $anio ?>&mes=<?= $mes ?>" target="_blank" class="btn btn-outline-danger btn-sm">
    <i class="bi bi-file-earmark-pdf me-1"></i>Generar PDF
  </a>
</div>

<div class="row g-2 mb-3">
  <div class="col-4"><div class="kpi"><div class="lbl">Asignado</div><div class="val"><?= fmtCLP($asig['total'] ?? 0) ?></div></div></div>
  <div class="col-4"><div class="kpi"><div class="lbl">Gastado</div><div class="val text-danger"><?= fmtCLP($total) ?></div></div></div>
  <div class="col-4"><div class="kpi"><div class="lbl">Saldo</div><div class="val text-<?= $saldo<0?'danger':'success' ?>"><?= fmtCLP($saldo) ?></div></div></div>
</div>

<?php if ($gastos): ?>
<div class="d-flex flex-wrap gap-2 mb-3 small">
  <span class="rev-chip rev-aprob" title="Aprobados por el Gerente de Finanzas"><i class="bi bi-check-circle-fill"></i> <?= $nAprob ?> aprobado<?= $nAprob===1?'':'s' ?></span>
  <span class="rev-chip rev-obs"   title="Con observacion del Gerente de Finanzas"><i class="bi bi-chat-left-text-fill"></i> <?= $nObs ?> observado<?= $nObs===1?'':'s' ?></span>
  <span class="rev-chip rev-rech"  title="Rechazados por el Gerente de Finanzas"><i class="bi bi-x-circle-fill"></i> <?= $nRech ?> rechazado<?= $nRech===1?'':'s' ?></span>
  <span class="rev-chip rev-pend"  title="Pendientes de revision"><i class="bi bi-hourglass-split"></i> <?= $nPend ?> pendiente<?= $nPend===1?'':'s' ?></span>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-body p-2">
    <?php if (!$gastos): ?>
      <div class="text-center text-muted py-5">
        <i class="bi bi-receipt" style="font-size:3rem; opacity:.3;"></i>
        <div class="mt-2">No hay gastos en este periodo.</div>
      </div>
    <?php else: foreach ($gastos as $g):
      // Estado de revision del gasto
      if ($g['estado'] === 'aprobado_jefe') {
          $revCls='aprob'; $revIco='bi-check-circle-fill'; $revTxt='Aprobado por el Gerente de Finanzas';
      } elseif ($g['estado'] === 'rechazado_jefe') {
          $revCls='rech';  $revIco='bi-x-circle-fill';     $revTxt='Rechazado por el Gerente de Finanzas';
      } elseif (!empty($g['observacion_revision'])) {
          $revCls='obs';   $revIco='bi-chat-left-text-fill'; $revTxt='Con observacion del Gerente de Finanzas';
      } else {
          $revCls='pend';  $revIco='bi-hourglass-split';   $revTxt='Pendiente de revision';
      }
    ?>
      <div class="list-group-item gasto p-3 rev-card rev-<?= $revCls ?>">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div class="flex-grow-1" style="min-width:0;">
            <div class="fw-semibold"><?= h($g['descripcion'] ?: ($g['proveedor'] ?: ($g['categoria'] ?: 'Gasto'))) ?></div>
            <div class="small text-muted mt-1">
              <i class="bi bi-calendar3"></i> <?= date('d/m/Y', strtotime($g['fecha'])) ?>
              <?php if ($g['categoria']): ?> - <span class="cat-pill"><?= h($g['categoria']) ?></span><?php endif; ?>
              <?php if ($g['proveedor']): ?> - <i class="bi bi-shop"></i> <?= h($g['proveedor']) ?><?php endif; ?>
              <?php if ($g['nadj']): ?> - <i class="bi bi-paperclip"></i> <?= (int)$g['nadj'] ?><?php endif; ?>
            </div>
            <div class="mt-2">
              <span class="rev-chip rev-<?= $revCls ?>"><i class="bi <?= $revIco ?>"></i> <?= $revTxt ?></span>
            </div>
            <?php if (!empty($g['observacion_revision'])): ?>
              <div class="rev-obs-box mt-2">
                <div class="small fw-semibold text-uppercase text-muted mb-1">
                  <i class="bi bi-megaphone-fill"></i> Observacion del Gerente de Finanzas:
                </div>
                <div class="small"><?= nl2br(h($g['observacion_revision'])) ?></div>
              </div>
            <?php endif; ?>
          </div>
          <div class="text-end flex-shrink-0">
            <div class="fw-bold text-danger fs-5 text-nowrap"><?= fmtCLP($g['monto']) ?></div>
            <div class="mt-2 d-flex gap-1 justify-content-end">
              <a href="gasto_ver.php?id=<?= (int)$g['id'] ?>" class="btn btn-sm btn-outline-primary" title="Ver detalle"><i class="bi bi-eye"></i></a>
              <?php if ($g['estado'] === 'registrado'): ?>
                <a href="gasto_editar.php?id=<?= (int)$g['id'] ?>" class="btn btn-sm btn-outline-warning" title="Editar gasto"><i class="bi bi-pencil"></i></a>
                <a href="gasto_eliminar.php?id=<?= (int)$g['id'] ?>"
                   data-confirm="Eliminar este gasto?"
                   class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<a href="gasto_nuevo.php" class="fab d-md-none"><i class="bi bi-plus-lg"></i></a>

<style>
/* Chips de estado de revision */
.rev-chip{
  display:inline-flex; align-items:center; gap:5px;
  padding:3px 10px; border-radius:999px; font-weight:600;
  font-size:.78rem; line-height:1.3;
}
.rev-chip.rev-aprob{ background:#d1e7dd; color:#0a3622; border:1px solid #a3cfbb; }
.rev-chip.rev-rech{  background:#f8d7da; color:#58151c; border:1px solid #f1aeb5; }
.rev-chip.rev-obs{   background:#fff3cd; color:#664d03; border:1px solid #ffe69c; }
.rev-chip.rev-pend{  background:#e9ecef; color:#495057; border:1px solid #ced4da; }

/* Tarjeta de gasto con banda lateral segun estado */
.rev-card{ border-left:4px solid #dee2e6; border-radius:8px; margin-bottom:8px; background:#fff; }
.rev-card.rev-aprob{ border-left-color:#198754; }
.rev-card.rev-rech{  border-left-color:#dc3545; background:#fff5f5; }
.rev-card.rev-obs{   border-left-color:#ffc107; background:#fffcf0; }
.rev-card.rev-pend{  border-left-color:#6c757d; }

/* Caja de observacion del jefe */
.rev-obs-box{
  background:#fff3cd; border:1px solid #ffe69c;
  border-radius:6px; padding:8px 10px;
}
</style>

<?php include 'includes/foot.php'; ?>
