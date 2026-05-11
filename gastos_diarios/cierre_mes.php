<?php
require_once 'config.php';
requireAuth();

$uid = (int)$_SESSION['user_id'];
$p = periodoActual();
$anio = (int)($_GET['anio'] ?? $p['anio']);
$mes  = (int)($_GET['mes']  ?? $p['mes']);

$asig = asignacionPeriodo($pdo, $uid, $anio, $mes);
$gastado = totalGastadoPeriodo($pdo, $uid, $anio, $mes);
$saldo = ($asig['total'] ?? 0) - $gastado;

// cierre existente
$qc = $pdo->prepare("SELECT * FROM cierres_mensuales WHERE usuario_id=? AND anio=? AND mes=?");
$qc->execute([$uid, $anio, $mes]);
$cierre = $qc->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'enviar') {
    $obs = trim($_POST['observaciones'] ?? '');
    if ($cierre) {
        $pdo->prepare("UPDATE cierres_mensuales SET total_gastado=?, monto_asignado=?, monto_carryover=?, saldo_final=?,
                       estado='enviado_jefe', observaciones_usuario=?, enviado_jefe_en=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$gastado, $asig['monto_asignado'] ?? 0, $asig['monto_carryover'] ?? 0, $saldo, $obs, $cierre['id']]);
    } else {
        $pdo->prepare("INSERT INTO cierres_mensuales
                       (usuario_id,anio,mes,monto_asignado,monto_carryover,total_gastado,saldo_final,estado,observaciones_usuario,enviado_jefe_en)
                       VALUES (?,?,?,?,?,?,?, 'enviado_jefe', ?, CURRENT_TIMESTAMP)")
            ->execute([$uid,$anio,$mes,$asig['monto_asignado']??0,$asig['monto_carryover']??0,$gastado,$saldo,$obs]);
    }

    // Notificar al Jefe Zonal del tecnico
    try {
        $qj = $pdo->prepare("SELECT jefe_zonal_id, nombre FROM usuarios WHERE id=?");
        $qj->execute([$uid]);
        $infoU = $qj->fetch();
        if (!empty($infoU['jefe_zonal_id'])) {
            $titulo = 'Nueva rendicion por revisar';
            $mensaje = ($infoU['nombre'] ?: 'Un tecnico').' envio la rendicion de '.nombreMes($mes).' '.$anio.' para tu revision.';
            $enlace = 'jefe_revisar_cierre.php?u='.$uid.'&anio='.$anio.'&mes='.$mes;
            $pdo->prepare("INSERT INTO notificaciones (usuario_id,titulo,mensaje,tipo,enlace) VALUES (?,?,?,?,?)")
                ->execute([(int)$infoU['jefe_zonal_id'], $titulo, $mensaje, 'info', $enlace]);
        }
    } catch (Exception $e) { /* silencioso, no bloquear */ }

    flash('exito','Cierre enviado a tu Jefe Zonal. Ya puedes descargar el PDF de respaldo.');
    header('Location: cierre_mes.php?anio='.$anio.'&mes='.$mes); exit;
}

$g = $pdo->prepare("SELECT * FROM gastos WHERE usuario_id=? AND fecha BETWEEN ? AND ? ORDER BY fecha");
$g->execute([$uid, sprintf('%04d-%02d-01',$anio,$mes), date('Y-m-t', strtotime("$anio-$mes-01"))]);
$gastos = $g->fetchAll();

$mu = $pdo->prepare("SELECT nombre, usuario, ciudad, region, zona, cargo, foto_perfil FROM usuarios WHERE id=?");
$mu->execute([$uid]); $mu = $mu->fetch();

$titulo = 'Cierre mensual';
include 'includes/head.php';
include 'includes/nav.php';
?>

<div class="card mb-3 border-0 bg-light">
  <div class="card-body py-2 px-3 d-flex align-items-center gap-2 flex-wrap">
    <?php $urlFotoCm = urlFotoUsuario($mu['foto_perfil'] ?? ''); ?>
    <?php if ($urlFotoCm): ?>
      <img src="<?= h($urlFotoCm) ?>" alt="<?= h($mu['nombre']) ?>" class="tecnico-avatar-sm" style="object-fit:cover;">
    <?php else: ?>
      <div class="tecnico-avatar-sm"><?= strtoupper(mb_substr($mu['nombre'] ?: 'U',0,1)) ?></div>
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
  <h4 class="mb-0"><i class="bi bi-send-check me-2"></i>Cierre - <?= nombreMes($mes) ?> <?= $anio ?></h4>
  <form class="d-flex gap-2" method="get">
    <select name="mes" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php for($m=1;$m<=12;$m++): ?>
        <option value="<?= $m ?>" <?= $m===$mes?'selected':'' ?>><?= nombreMes($m) ?></option>
      <?php endfor; ?>
    </select>
    <select name="anio" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php for($y=date('Y');$y>=date('Y')-2;$y--): ?>
        <option value="<?= $y ?>" <?= $y===$anio?'selected':'' ?>><?= $y ?></option>
      <?php endfor; ?>
    </select>
  </form>
</div>

<!-- Acciones: PDF + correo al jefe -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="exportar_pdf.php?anio=<?= $anio ?>&mes=<?= $mes ?>" target="_blank" class="btn btn-outline-danger">
    <i class="bi bi-file-earmark-pdf me-1"></i>Generar PDF del cierre
  </a>
  <?php if ($cierre && in_array($cierre['estado'], ['enviado_jefe','enviado_validador','aprobado','validado'])): ?>
    <a href="enviar_correo.php?anio=<?= $anio ?>&mes=<?= $mes ?>" class="btn btn-outline-primary">
      <i class="bi bi-envelope me-1"></i>Enviar PDF por correo al Jefe
    </a>
  <?php endif; ?>
</div>

<?php if ($cierre): ?>
  <div class="alert alert-<?= in_array($cierre['estado'], ['aprobado','validado']) ? 'success' : (in_array($cierre['estado'], ['enviado_jefe','enviado_validador']) ? 'info' : ($cierre['estado']==='rechazado' ? 'warning' : 'secondary')) ?>">
    <i class="bi bi-info-circle me-1"></i>
    Estado actual del cierre: <span class="estado <?= htmlspecialchars($cierre['estado']) ?>"><?= htmlspecialchars($cierre['estado']) ?></span>
    <?php if ($cierre['enviado_jefe_en']): ?> · Enviado al Jefe: <strong><?= htmlspecialchars(date('d/m/Y H:i', strtotime($cierre['enviado_jefe_en']))) ?></strong><?php endif; ?>
    <?php if ($cierre['observaciones_jefe']): ?>
      <div class="mt-2"><strong>Comentario del Jefe:</strong> <?= nl2br(htmlspecialchars($cierre['observaciones_jefe'])) ?></div>
    <?php endif; ?>
    <?php if ($cierre['observaciones_validador']): ?>
      <div class="mt-2"><strong>Comentario del Validador:</strong> <?= nl2br(htmlspecialchars($cierre['observaciones_validador'])) ?></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="row g-2 mb-3">
  <div class="col-6 col-md-3"><div class="kpi"><div class="lbl">Asignado</div><div class="val"><?= fmtCLP($asig['monto_asignado']??0) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="kpi"><div class="lbl">Arrastre</div><div class="val"><?= fmtCLP($asig['monto_carryover']??0) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="kpi"><div class="lbl">Gastado</div><div class="val text-danger"><?= fmtCLP($gastado) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="kpi"><div class="lbl">Saldo</div><div class="val text-<?= $saldo<0?'danger':'success' ?>"><?= fmtCLP($saldo) ?></div></div></div>
</div>

<div class="card mb-3">
  <div class="card-header"><i class="bi bi-list-ul me-1"></i>Resumen de gastos (<?= count($gastos) ?>)</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr><th>Fecha</th><th>Categoría</th><th>Detalle</th><th>Doc</th><th class="text-end">Monto</th></tr>
        </thead>
        <tbody>
        <?php if (!$gastos): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">Sin gastos en este periodo.</td></tr>
        <?php else: foreach ($gastos as $x): ?>
          <tr>
            <td><?= date('d/m', strtotime($x['fecha'])) ?></td>
            <td><span class="cat-pill"><?= htmlspecialchars($x['categoria']?:'—') ?></span></td>
            <td><?= htmlspecialchars($x['descripcion']?:($x['proveedor']?:'—')) ?></td>
            <td class="small"><?= htmlspecialchars($x['tipo_documento']?:'') ?> <?= htmlspecialchars($x['numero_documento']?:'') ?></td>
            <td class="text-end fw-semibold"><?= fmtCLP($x['monto']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if (!$cierre || in_array($cierre['estado'], ['borrador','rechazado'])): ?>
<div class="card">
  <div class="card-header"><i class="bi bi-send me-1"></i>Enviar a Jefe Zonal</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="accion" value="enviar">
      <div class="mb-3">
        <label class="form-label">Observaciones / comentarios (opcional)</label>
        <textarea name="observaciones" class="form-control" rows="3" placeholder="Detalle relevante para tu jefe zonal"><?= htmlspecialchars($cierre['observaciones_usuario'] ?? '') ?></textarea>
      </div>
      <button class="btn btn-success btn-lg w-100" data-confirm="¿Confirmas enviar este cierre a tu jefe zonal?">
        <i class="bi bi-send-check me-1"></i> Enviar cierre
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include 'includes/foot.php'; ?>
