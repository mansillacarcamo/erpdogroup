<?php
require_once 'config.php';
requireRol('validador');

$vid = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid = (int)$_POST['cid']; $obs = trim($_POST['obs'] ?? ''); $acc = $_POST['accion'] ?? '';
    // Traer info del cierre para notificar
    $qcd = $pdo->prepare("SELECT c.*, u.nombre, u.jefe_zonal_id FROM cierres_mensuales c JOIN usuarios u ON u.id=c.usuario_id WHERE c.id=?");
    $qcd->execute([$cid]); $cierreData = $qcd->fetch();

    if ($acc === 'validar') {
        $pdo->prepare("UPDATE cierres_mensuales SET estado='validado', observaciones_validador=?, validado_en=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$obs,$cid]);
        // Notificar tecnico y jefe
        if ($cierreData) {
            try {
                $periodoTxt = nombreMes($cierreData['mes']).' '.$cierreData['anio'];
                $pdo->prepare("INSERT INTO notificaciones (usuario_id,titulo,mensaje,tipo,enlace) VALUES (?,?,?,?,?)")
                    ->execute([(int)$cierreData['usuario_id'], 'Rendicion validada',
                               'Tu rendicion de '.$periodoTxt.' fue validada definitivamente.'.($obs?' Comentario: '.$obs:''),
                               'success', 'cierre_mes.php?anio='.$cierreData['anio'].'&mes='.$cierreData['mes']]);
                if (!empty($cierreData['jefe_zonal_id'])) {
                    $pdo->prepare("INSERT INTO notificaciones (usuario_id,titulo,mensaje,tipo,enlace) VALUES (?,?,?,?,?)")
                        ->execute([(int)$cierreData['jefe_zonal_id'], 'Cierre validado',
                                   'La rendicion de '.($cierreData['nombre']?:'un tecnico').' - '.$periodoTxt.' fue validada.',
                                   'success', 'jefe_dashboard.php']);
                }
            } catch (Exception $e) { /* silencioso */ }
        }
        flash('exito','Cierre validado.');
    } elseif ($acc === 'rechazar') {
        $pdo->prepare("UPDATE cierres_mensuales SET estado='rechazado', observaciones_validador=?, rechazado_en=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$obs,$cid]);
        if ($cierreData) {
            try {
                $periodoTxt = nombreMes($cierreData['mes']).' '.$cierreData['anio'];
                $pdo->prepare("INSERT INTO notificaciones (usuario_id,titulo,mensaje,tipo,enlace) VALUES (?,?,?,?,?)")
                    ->execute([(int)$cierreData['usuario_id'], 'Rendicion rechazada por el Validador',
                               'Tu rendicion de '.$periodoTxt.' fue rechazada.'.($obs?' Motivo: '.$obs:''),
                               'warning', 'cierre_mes.php?anio='.$cierreData['anio'].'&mes='.$cierreData['mes']]);
                if (!empty($cierreData['jefe_zonal_id'])) {
                    $pdo->prepare("INSERT INTO notificaciones (usuario_id,titulo,mensaje,tipo,enlace) VALUES (?,?,?,?,?)")
                        ->execute([(int)$cierreData['jefe_zonal_id'], 'Cierre rechazado por el Validador',
                                   'La rendicion de '.($cierreData['nombre']?:'un tecnico').' - '.$periodoTxt.' fue rechazada.',
                                   'warning', 'jefe_dashboard.php']);
                }
            } catch (Exception $e) { /* silencioso */ }
        }
        flash('exito','Cierre rechazado.');
    }
    header('Location: validador_dashboard.php'); exit;
}

$cs = $pdo->query("SELECT c.*, u.nombre, u.usuario, u.zona, u.ciudad, u.region, u.cargo, u.email, u.validador_id, u.foto_perfil
                   FROM cierres_mensuales c JOIN usuarios u ON u.id=c.usuario_id
                   WHERE u.validador_id=$vid AND c.estado IN ('enviado_validador','validado','rechazado')
                   ORDER BY c.enviado_validador_en DESC")->fetchAll();

$titulo = 'Validador';
include 'includes/head.php'; include 'includes/nav.php';
?>
<h4 class="mb-3"><i class="bi bi-shield-check me-2"></i>Cierres para validar</h4>
<div class="row g-3">
<?php foreach ($cs as $c): ?>
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-calendar-month me-1"></i><?= nombreMes($c['mes']) ?> <?= $c['anio'] ?></span>
        <span class="estado <?= h($c['estado']) ?>"><?= h($c['estado']) ?></span>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-start gap-2 mb-3 pb-2 border-bottom">
          <?php $urlFotoVd = urlFotoUsuario($c['foto_perfil'] ?? ''); ?>
          <?php if ($urlFotoVd): ?>
            <img src="<?= h($urlFotoVd) ?>" alt="<?= h($c['nombre']) ?>" class="tecnico-avatar-sm" style="object-fit:cover;">
          <?php else: ?>
            <div class="tecnico-avatar-sm"><?= strtoupper(mb_substr($c['nombre'],0,1)) ?></div>
          <?php endif; ?>
          <div class="flex-grow-1" style="min-width:0;">
            <div class="fw-bold text-truncate"><?= h($c['nombre']) ?></div>
            <div class="small text-muted text-truncate">@<?= h($c['usuario']) ?><?php if ($c['cargo']): ?> - <?= h($c['cargo']) ?><?php endif; ?></div>
            <div class="mt-1 d-flex flex-wrap gap-1">
              <?php if ($c['region']): ?><span class="badge bg-primary-subtle text-primary small"><i class="bi bi-geo-alt-fill"></i> <?= h($c['region']) ?></span><?php endif; ?>
              <?php if ($c['ciudad']): ?><span class="badge bg-info-subtle text-info-emphasis small"><i class="bi bi-geo-alt"></i> <?= h($c['ciudad']) ?></span><?php endif; ?>
            </div>
          </div>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-4"><div class="kpi"><div class="lbl">Asignado</div><div class="val small"><?= fmtCLP($c['monto_asignado']+$c['monto_carryover']) ?></div></div></div>
          <div class="col-4"><div class="kpi"><div class="lbl">Gastado</div><div class="val small text-danger"><?= fmtCLP($c['total_gastado']) ?></div></div></div>
          <div class="col-4"><div class="kpi"><div class="lbl">Saldo</div><div class="val small text-<?= $c['saldo_final']<0?'danger':'success' ?>"><?= fmtCLP($c['saldo_final']) ?></div></div></div>
        </div>
        <?php if ($c['observaciones_jefe']): ?><div class="alert alert-light small mb-2"><strong>Gerente de Finanzas:</strong> <?= nl2br(htmlspecialchars($c['observaciones_jefe'])) ?></div><?php endif; ?>
        <?php if ($c['observaciones_usuario']): ?><div class="alert alert-light small mb-2"><strong>Usuario:</strong> <?= nl2br(htmlspecialchars($c['observaciones_usuario'])) ?></div><?php endif; ?>

        <div class="d-flex gap-2 flex-wrap">
          <a href="jefe_ver_usuario.php?u=<?= $c['usuario_id'] ?>&anio=<?= $c['anio'] ?>&mes=<?= $c['mes'] ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-eye"></i> Ver gastos</a>
          <a href="exportar_pdf.php?u=<?= $c['usuario_id'] ?>&anio=<?= $c['anio'] ?>&mes=<?= $c['mes'] ?>" target="_blank" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-file-earmark-pdf"></i> PDF</a>
          <a href="enviar_correo.php?u=<?= $c['usuario_id'] ?>&anio=<?= $c['anio'] ?>&mes=<?= $c['mes'] ?>" class="btn btn-sm btn-outline-success">
            <i class="bi bi-envelope"></i> Enviar correo</a>
        </div>

        <?php if ($c['estado'] === 'enviado_validador'): ?>
        <hr>
        <form method="post">
          <input type="hidden" name="cid" value="<?= $c['id'] ?>">
          <textarea name="obs" class="form-control form-control-sm mb-2" rows="2" placeholder="Observaciones..."></textarea>
          <div class="d-flex gap-2">
            <button name="accion" value="validar" class="btn btn-success btn-sm flex-grow-1" data-confirm="¿Validar definitivamente?">
              <i class="bi bi-check2-circle"></i> Validar</button>
            <button name="accion" value="rechazar" class="btn btn-outline-danger btn-sm" data-confirm="¿Rechazar?">
              <i class="bi bi-x-circle"></i> Rechazar</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php if (!$cs): ?><div class="alert alert-info">No hay cierres pendientes de validación.</div><?php endif; ?>

<style>.tecnico-avatar-sm{ width:38px; height:38px; border-radius:50%; background:linear-gradient(135deg,#0d6efd,#6610f2); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; flex-shrink:0; }</style>

<?php include 'includes/foot.php'; ?>
