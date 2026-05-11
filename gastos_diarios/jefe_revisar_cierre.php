<?php
require_once 'config.php';
requireRol('jefe');

$jid = (int)$_SESSION['user_id'];
$uid  = (int)($_GET['u'] ?? $_POST['u'] ?? 0);
$anio = (int)($_GET['anio'] ?? $_POST['anio'] ?? 0);
$mes  = (int)($_GET['mes']  ?? $_POST['mes'] ?? 0);

$u = $pdo->prepare("SELECT * FROM usuarios WHERE id=? AND jefe_zonal_id=?");
$u->execute([$uid,$jid]);
$u = $u->fetch();
if (!$u) { http_response_code(403); die('Sin permiso.'); }

$c = $pdo->prepare("SELECT * FROM cierres_mensuales WHERE usuario_id=? AND anio=? AND mes=?");
$c->execute([$uid,$anio,$mes]); $c = $c->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $c) {
    $obs = trim($_POST['obs'] ?? '');
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'aprobar') {
        $pdo->prepare("UPDATE cierres_mensuales SET estado='enviado_validador', observaciones_jefe=?, aprobado_jefe_en=CURRENT_TIMESTAMP, enviado_validador_en=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$obs, $c['id']]);
        // Notificar al tecnico
        try {
            $pdo->prepare("INSERT INTO notificaciones (usuario_id,titulo,mensaje,tipo,enlace) VALUES (?,?,?,?,?)")
                ->execute([$uid, 'Rendicion aprobada por el Jefe',
                           'Tu rendicion de '.nombreMes($mes).' '.$anio.' fue aprobada y enviada al Validador.'.($obs?' Comentario: '.$obs:''),
                           'success', 'cierre_mes.php?anio='.$anio.'&mes='.$mes]);
            // Notificar al validador asignado (si existe)
            $qv = $pdo->prepare("SELECT validador_id, nombre FROM usuarios WHERE id=?");
            $qv->execute([$uid]); $uu = $qv->fetch();
            if (!empty($uu['validador_id'])) {
                $pdo->prepare("INSERT INTO notificaciones (usuario_id,titulo,mensaje,tipo,enlace) VALUES (?,?,?,?,?)")
                    ->execute([(int)$uu['validador_id'], 'Nueva rendicion para validar',
                               ($uu['nombre'] ?: 'Un tecnico').' - '.nombreMes($mes).' '.$anio.' lista para tu validacion.',
                               'info', 'validador_dashboard.php']);
            }
        } catch (Exception $e) { /* silencioso */ }
        flash('exito','Cierre aprobado y enviado al Validador.');
    } elseif ($accion === 'rechazar') {
        $pdo->prepare("UPDATE cierres_mensuales SET estado='rechazado', observaciones_jefe=?, rechazado_en=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$obs, $c['id']]);
        // Notificar al tecnico
        try {
            $pdo->prepare("INSERT INTO notificaciones (usuario_id,titulo,mensaje,tipo,enlace) VALUES (?,?,?,?,?)")
                ->execute([$uid, 'Rendicion rechazada por el Jefe',
                           'Tu rendicion de '.nombreMes($mes).' '.$anio.' fue devuelta.'.($obs?' Motivo: '.$obs:' Revisa y vuelve a enviar.'),
                           'warning', 'cierre_mes.php?anio='.$anio.'&mes='.$mes]);
        } catch (Exception $e) { /* silencioso */ }
        flash('exito','Cierre devuelto al usuario.');
    }
    header('Location: jefe_dashboard.php?anio='.$anio.'&mes='.$mes); exit;
}

$ini = sprintf('%04d-%02d-01',$anio,$mes); $fin = date('Y-m-t', strtotime($ini));
$gs = $pdo->prepare("SELECT * FROM gastos WHERE usuario_id=? AND fecha BETWEEN ? AND ? ORDER BY fecha");
$gs->execute([$uid,$ini,$fin]);
$gastos = $gs->fetchAll();

$titulo = 'Revisar cierre';
include 'includes/head.php'; include 'includes/nav.php';
?>
<a href="jefe_dashboard.php?anio=<?= $anio ?>&mes=<?= $mes ?>" class="text-decoration-none small mb-2 d-inline-block"><i class="bi bi-arrow-left"></i> Volver</a>

<div class="card mb-3"><div class="card-body">
  <div class="d-flex align-items-start gap-3 flex-wrap">
    <?php $urlFotoJr = urlFotoUsuario($u['foto_perfil'] ?? ''); ?>
    <?php if ($urlFotoJr): ?>
      <img src="<?= h($urlFotoJr) ?>" alt="<?= h($u['nombre']) ?>" class="tecnico-avatar-lg" style="object-fit:cover;">
    <?php else: ?>
      <div class="tecnico-avatar-lg"><?= strtoupper(mb_substr($u['nombre'],0,1)) ?></div>
    <?php endif; ?>
    <div class="flex-grow-1">
      <h5 class="mb-0 fw-bold"><?= h($u['nombre']) ?></h5>
      <div class="small text-muted">@<?= h($u['usuario']) ?><?php if ($u['cargo']): ?> - <?= h($u['cargo']) ?><?php endif; ?></div>
      <div class="mt-1 d-flex flex-wrap gap-1">
        <?php if ($u['region']): ?><span class="badge bg-primary-subtle text-primary"><i class="bi bi-geo-alt-fill"></i> <?= h($u['region']) ?></span><?php endif; ?>
        <?php if ($u['ciudad']): ?><span class="badge bg-info-subtle text-info-emphasis"><i class="bi bi-geo-alt"></i> <?= h($u['ciudad']) ?></span><?php endif; ?>
        <?php if ($u['zona']): ?><span class="badge bg-light text-dark border"><?= h($u['zona']) ?></span><?php endif; ?>
        <span class="badge bg-light text-dark border"><?= h($u['email']) ?></span>
      </div>
    </div>
    <div class="text-end">
      <div class="small text-muted">Periodo</div>
      <div class="fw-bold"><?= nombreMes($mes) ?> <?= $anio ?></div>
    </div>
  </div>
  <style>.tecnico-avatar-lg{ width:56px; height:56px; border-radius:50%; background:linear-gradient(135deg,#0d6efd,#6610f2); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.5rem; }</style>
  <hr>
  <?php if ($c): ?>
    <div class="row g-2">
      <div class="col-6 col-md-3"><div class="kpi"><div class="lbl">Asignado</div><div class="val"><?= fmtCLP($c['monto_asignado']+$c['monto_carryover']) ?></div></div></div>
      <div class="col-6 col-md-3"><div class="kpi"><div class="lbl">Gastado</div><div class="val text-danger"><?= fmtCLP($c['total_gastado']) ?></div></div></div>
      <div class="col-6 col-md-3"><div class="kpi"><div class="lbl">Saldo</div><div class="val text-<?= $c['saldo_final']<0?'danger':'success' ?>"><?= fmtCLP($c['saldo_final']) ?></div></div></div>
      <div class="col-6 col-md-3"><div class="kpi"><div class="lbl">Estado</div><div class="val"><span class="estado <?= $c['estado'] ?>"><?= $c['estado'] ?></span></div></div></div>
    </div>
    <?php if ($c['observaciones_usuario']): ?>
      <div class="alert alert-light mt-3 mb-0"><strong>Comentario del usuario:</strong> <?= nl2br(htmlspecialchars($c['observaciones_usuario'])) ?></div>
    <?php endif; ?>
  <?php else: ?>
    <div class="alert alert-warning">El usuario aún no ha enviado este cierre.</div>
  <?php endif; ?>
</div></div>

<div class="card mb-3"><div class="card-header">Gastos del periodo</div><div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0">
  <thead class="table-light"><tr><th>Fecha</th><th>Categoría</th><th>Detalle</th><th>Doc</th><th class="text-end">Monto</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($gastos as $g): ?>
    <tr>
      <td class="small"><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
      <td><span class="cat-pill"><?= htmlspecialchars($g['categoria']?:'—') ?></span></td>
      <td class="small"><?= htmlspecialchars($g['descripcion']?:($g['proveedor']?:'—')) ?></td>
      <td class="small"><?= htmlspecialchars($g['tipo_documento']?:'') ?> <?= htmlspecialchars($g['numero_documento']?:'') ?></td>
      <td class="text-end fw-semibold"><?= fmtCLP($g['monto']) ?></td>
      <td><a href="gasto_ver.php?id=<?= $g['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div></div></div>

<?php if ($c && $c['estado'] === 'enviado_jefe'): ?>
<div class="card"><div class="card-body">
  <h6 class="fw-bold">Acción</h6>
  <form method="post">
    <input type="hidden" name="u" value="<?= $uid ?>"><input type="hidden" name="anio" value="<?= $anio ?>"><input type="hidden" name="mes" value="<?= $mes ?>">
    <div class="mb-3"><label class="form-label">Observaciones (se envían al usuario y validador)</label>
      <textarea name="obs" class="form-control" rows="3"></textarea></div>
    <div class="d-flex gap-2">
      <button name="accion" value="aprobar" class="btn btn-success btn-lg flex-grow-1" data-confirm="¿Aprobar y enviar al Validador?">
        <i class="bi bi-check-circle me-1"></i> Aprobar y enviar al Validador</button>
      <button name="accion" value="rechazar" class="btn btn-outline-danger" data-confirm="¿Devolver al usuario?">
        <i class="bi bi-x-circle"></i> Devolver al usuario</button>
    </div>
  </form>
</div></div>
<?php endif; ?>

<div class="mt-3 text-end">
  <a href="exportar_pdf.php?u=<?= $uid ?>&anio=<?= $anio ?>&mes=<?= $mes ?>" target="_blank" class="btn btn-outline-danger">
    <i class="bi bi-file-earmark-pdf"></i> Exportar PDF
  </a>
</div>

<?php include 'includes/foot.php'; ?>
