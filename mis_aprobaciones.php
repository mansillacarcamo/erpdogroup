<?php
require_once 'config.php';
requireAuth();

$esAdmin = $usuario['rol'] === 'admin';
$esValidador = false;
try {
    $stmtEsVal = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobadores WHERE usuario_id = ?");
    $stmtEsVal->execute([$usuario['id']]);
    $esValidador = (int)$stmtEsVal->fetchColumn() > 0;
} catch (Exception $e) {}

if (!$esAdmin && !$esValidador) {
    header('Location: index.php');
    exit;
}

$msg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'aprobar_cot') {
        $aprobId = (int)($_POST['aprob_id'] ?? 0);
        $accion = $_POST['accion'] ?? '';
        $comentario = trim($_POST['comentario'] ?? '');

        if ($aprobId && in_array($accion, ['aprobada', 'rechazada', 'corregir'])) {
            $stmtCheck = $pdo->prepare("SELECT * FROM cot_aprobaciones WHERE id = ? AND usuario_id = ? AND estado = 'pendiente'");
            $stmtCheck->execute([$aprobId, $usuario['id']]);
            $aprob = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($aprob) {
                $pdo->prepare("UPDATE cot_aprobaciones SET estado = ?, comentario = ?, fecha_respuesta = datetime('now') WHERE id = ?")
                    ->execute([$accion, $comentario ?: null, $aprobId]);

                $stmtAprobadas = $pdo->prepare("SELECT COUNT(*) FROM cot_aprobaciones WHERE cot_id = ? AND estado = 'aprobada'");
                $stmtAprobadas->execute([$aprob['cot_id']]);
                $aprobadas = (int)$stmtAprobadas->fetchColumn();

                $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM cot_aprobaciones WHERE cot_id = ?");
                $stmtTotal->execute([$aprob['cot_id']]);
                $totalAprobadores = (int)$stmtTotal->fetchColumn();

                if ($aprobadas === $totalAprobadores) {
                    $pdo->prepare("UPDATE cotizaciones SET estado = 'aprobada' WHERE id = ?")->execute([$aprob['cot_id']]);
                    $msg = ['success', 'Cotización aprobada por todos los validadores.'];
                } elseif ($accion === 'aprobada') {
                    $msg = ['info', 'Su aprobación fue registrada. Falta la validación de otro aprobador.'];
                } elseif ($accion === 'rechazada') {
                    $pdo->prepare("UPDATE cotizaciones SET estado = 'rechazada' WHERE id = ?")->execute([$aprob['cot_id']]);
                    $msg = ['danger', 'Cotización rechazada.'];
                } elseif ($accion === 'corregir') {
                    $pdo->prepare("UPDATE cotizaciones SET estado = 'corregir' WHERE id = ?")->execute([$aprob['cot_id']]);
                    $msg = ['warning', 'Se solicitó corrección de la cotización.'];
                }
            }
        }
    }

    if ($action === 'aprobar_oc') {
        $aprobId = (int)($_POST['aprob_id'] ?? 0);
        $accion = $_POST['accion'] ?? '';
        $comentario = trim($_POST['comentario'] ?? '');

        if ($aprobId && in_array($accion, ['aprobada', 'rechazada', 'corregir'])) {
            $stmtCheck = $pdo->prepare("SELECT * FROM oc_aprobaciones WHERE id = ? AND usuario_id = ? AND estado = 'pendiente'");
            $stmtCheck->execute([$aprobId, $usuario['id']]);
            $aprob = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($aprob) {
                $pdo->prepare("UPDATE oc_aprobaciones SET estado = ?, comentario = ?, fecha_respuesta = datetime('now') WHERE id = ?")
                    ->execute([$accion, $comentario ?: null, $aprobId]);

                $stmtAprobadas = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobaciones WHERE oc_id = ? AND estado = 'aprobada'");
                $stmtAprobadas->execute([$aprob['oc_id']]);
                $aprobadas = (int)$stmtAprobadas->fetchColumn();

                $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobaciones WHERE oc_id = ?");
                $stmtTotal->execute([$aprob['oc_id']]);
                $totalAprobadores = (int)$stmtTotal->fetchColumn();

                if ($aprobadas === $totalAprobadores) {
                    $pdo->prepare("UPDATE ordenes_compra SET estado = 'aprobada' WHERE id = ?")->execute([$aprob['oc_id']]);
                    $msg = ['success', 'OC aprobada por todos los validadores. Ya puede ser enviada al proveedor.'];
                } elseif ($accion === 'aprobada') {
                    $msg = ['info', 'Su aprobación fue registrada. Falta la validación de otro aprobador.'];
                } elseif ($accion === 'rechazada') {
                    $pdo->prepare("UPDATE ordenes_compra SET estado = 'rechazada' WHERE id = ?")->execute([$aprob['oc_id']]);
                    $msg = ['danger', 'OC rechazada.'];
                } elseif ($accion === 'corregir') {
                    $pdo->prepare("UPDATE ordenes_compra SET estado = 'corregir' WHERE id = ?")->execute([$aprob['oc_id']]);
                    $msg = ['warning', 'Se solicitó corrección de la OC.'];
                }
            }
        }
    }
}

$pendientes = [];
$historial = [];
try {
    $stmtPend = $pdo->prepare("
        SELECT a.*, o.numero, o.fecha, o.proveedor_nombre, o.obra, o.neto, o.iva, o.total, o.preparada_por, o.moneda
        FROM oc_aprobaciones a
        JOIN ordenes_compra o ON a.oc_id = o.id
        WHERE a.usuario_id = ? AND a.estado = 'pendiente'
        ORDER BY a.creado_en DESC
    ");
    $stmtPend->execute([$usuario['id']]);
    $pendientes = $stmtPend->fetchAll(PDO::FETCH_ASSOC);

    $stmtHist = $pdo->prepare("
        SELECT a.*, o.numero, o.fecha, o.proveedor_nombre, o.obra, o.total, o.preparada_por, o.estado as oc_estado, o.moneda
        FROM oc_aprobaciones a
        JOIN ordenes_compra o ON a.oc_id = o.id
        WHERE a.usuario_id = ? AND a.estado != 'pendiente'
        ORDER BY a.fecha_respuesta DESC
        LIMIT 50
    ");
    $stmtHist->execute([$usuario['id']]);
    $historial = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pendientes = [];
    $historial = [];
}

$cotPendientes = [];
$cotHistorial = [];
try {
    $stmtCotPend = $pdo->prepare("
        SELECT a.*, c.numero, c.fecha, c.cliente_nombre, c.cliente_obra, c.subtotal, c.iva, c.total, c.creada_por
        FROM cot_aprobaciones a
        JOIN cotizaciones c ON a.cot_id = c.id
        WHERE a.usuario_id = ? AND a.estado = 'pendiente'
        ORDER BY a.creado_en DESC
    ");
    $stmtCotPend->execute([$usuario['id']]);
    $cotPendientes = $stmtCotPend->fetchAll(PDO::FETCH_ASSOC);

    $stmtCotHist = $pdo->prepare("
        SELECT a.*, c.numero, c.fecha, c.cliente_nombre, c.cliente_obra, c.total, c.creada_por, c.estado as cot_estado
        FROM cot_aprobaciones a
        JOIN cotizaciones c ON a.cot_id = c.id
        WHERE a.usuario_id = ? AND a.estado != 'pendiente'
        ORDER BY a.fecha_respuesta DESC
        LIMIT 50
    ");
    $stmtCotHist->execute([$usuario['id']]);
    $cotHistorial = $stmtCotHist->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $cotPendientes = [];
    $cotHistorial = [];
}

$notifRecibidas = [];
try {
    $stmtNotif = $pdo->prepare("
        SELECT n.*, c.numero, c.fecha, c.cliente_nombre, c.cliente_obra, c.total, c.creada_por, c.estado as cot_estado
        FROM cot_notificaciones n
        JOIN cotizaciones c ON n.cot_id = c.id
        WHERE n.destinatario_id = ?
        ORDER BY n.leida ASC, n.fecha DESC
    ");
    $stmtNotif->execute([$usuario['id']]);
    $notifRecibidas = $stmtNotif->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $notifRecibidas = [];
}

$notifOCRecibidas = [];
try {
    $stmtNotifOC = $pdo->prepare("
        SELECT n.*, o.numero, o.fecha, o.proveedor_nombre, o.obra, o.total, o.preparada_por, o.estado as oc_estado, o.moneda
        FROM oc_notificaciones n
        JOIN ordenes_compra o ON n.oc_id = o.id
        WHERE n.destinatario_id = ?
        ORDER BY n.leida ASC, n.fecha DESC
    ");
    $stmtNotifOC->execute([$usuario['id']]);
    $notifOCRecibidas = $stmtNotifOC->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $notifOCRecibidas = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'marcar_leida') {
    $nid = (int)($_POST['notif_id'] ?? 0);
    $pdo->prepare("UPDATE cot_notificaciones SET leida = 1 WHERE id = ? AND destinatario_id = ?")->execute([$nid, $usuario['id']]);
    header('Location: mis_aprobaciones.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'marcar_leida_oc') {
    $nid = (int)($_POST['notif_id'] ?? 0);
    $pdo->prepare("UPDATE oc_notificaciones SET leida = 1 WHERE id = ? AND destinatario_id = ?")->execute([$nid, $usuario['id']]);
    header('Location: mis_aprobaciones.php');
    exit;
}

require_once 'includes/header.php';
?>

<?php if ($msg): ?>
<div class="alert alert-<?= $msg[0] ?> alert-dismissible fade show">
  <i class="bi bi-info-circle me-1"></i><?= $msg[1] ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- COTIZACIONES RECIBIDAS -->
<?php
  $notifNoLeidas = array_filter($notifRecibidas, function($n) { return !$n['leida']; });
  $notifLeidas = array_filter($notifRecibidas, function($n) { return $n['leida']; });
?>
<?php if (!empty($notifRecibidas)): ?>
<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-primary text-white py-3">
    <h5 class="mb-0"><i class="bi bi-inbox me-2"></i>Cotizaciones / OC Recibidas (<?= count($notifNoLeidas) ?> sin leer)</h5>
  </div>
  <div class="card-body p-4">
    <?php if (empty($notifNoLeidas) && empty($notifLeidas)): ?>
    <div class="text-center text-muted py-4">
      <i class="bi bi-inbox fs-1"></i>
      <p class="mt-2 mb-0">No tiene cotizaciones recibidas</p>
    </div>
    <?php else: ?>

    <?php foreach ($notifNoLeidas as $nf):
      $tipoContenido = $nf['contenido'] === 'proceso' ? 'Estado/Proceso' : 'Vista Previa';
      $tipoBadge = $nf['contenido'] === 'proceso' ? 'info' : 'primary';
      $estadoBadges = ['pendiente'=>'secondary','en_revision'=>'info','aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning','propuesta'=>'primary','negociacion'=>'primary','adjudicada'=>'success','desierta'=>'secondary','cerrado'=>'dark'];
      $estBadge = $estadoBadges[$nf['cot_estado']] ?? 'secondary';
    ?>
    <div class="card border-primary mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 mb-2">
              <h5 class="mb-0"><span class="badge bg-dark">Cotización N° <?= $nf['numero'] ?></span></h5>
              <span class="badge bg-<?= $tipoBadge ?>"><?= $tipoContenido ?></span>
              <span class="badge bg-<?= $estBadge ?>"><?= ucfirst(str_replace('_', ' ', $nf['cot_estado'])) ?></span>
              <span class="badge bg-danger">Nueva</span>
            </div>
            <table class="table table-sm mb-2" style="font-size:13px;">
              <tr><td class="fw-bold" style="width:130px;">Cliente:</td><td><?= htmlspecialchars($nf['cliente_nombre']) ?></td></tr>
              <tr><td class="fw-bold">Obra:</td><td><?= htmlspecialchars($nf['cliente_obra']) ?></td></tr>
              <tr><td class="fw-bold">Fecha:</td><td><?= date('d/m/Y', strtotime($nf['fecha'])) ?></td></tr>
              <tr><td class="fw-bold">Total:</td><td class="fw-bold text-success fs-5">$<?= number_format($nf['total'], 0, ',', '.') ?></td></tr>
              <tr><td class="fw-bold">Enviada por:</td><td><?= htmlspecialchars($nf['enviado_por']) ?></td></tr>
            </table>
            <div class="d-flex gap-2">
              <a href="cotizaciones/ver_cotizacion.php?id=<?= $nf['cot_id'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1"></i>Ver Cotización</a>
              <?php if ($nf['contenido'] === 'proceso'): ?>
              <a href="cotizaciones/seguimiento_cot.php?id=<?= $nf['cot_id'] ?>" class="btn btn-outline-info btn-sm"><i class="bi bi-diagram-3 me-1"></i>Ver Proceso</a>
              <?php endif; ?>
              <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="marcar_leida">
                <input type="hidden" name="notif_id" value="<?= $nf['id'] ?>">
                <button class="btn btn-outline-success btn-sm"><i class="bi bi-check2 me-1"></i>Marcar leída</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($notifLeidas)): ?>
    <hr>
    <h6 class="text-muted mb-3"><i class="bi bi-check-all me-1"></i>Leídas anteriormente</h6>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead class="table-light">
          <tr><th>Cot. N°</th><th>Cliente</th><th>Total</th><th>Tipo</th><th>Enviada por</th><th>Fecha</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($notifLeidas as $nf):
            $estBadge2 = $estadoBadges[$nf['cot_estado']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-bold"><?= $nf['numero'] ?></td>
            <td><?= htmlspecialchars($nf['cliente_nombre']) ?></td>
            <td class="fw-bold">$<?= number_format($nf['total'], 0, ',', '.') ?></td>
            <td><span class="badge bg-<?= $nf['contenido'] === 'proceso' ? 'info' : 'primary' ?>" style="font-size:10px;"><?= $nf['contenido'] === 'proceso' ? 'Proceso' : 'Vista' ?></span></td>
            <td><small><?= htmlspecialchars($nf['enviado_por']) ?></small></td>
            <td><small><?= date('d/m/Y H:i', strtotime($nf['fecha'])) ?></small></td>
            <td><span class="badge bg-<?= $estBadge2 ?>"><?= ucfirst(str_replace('_', ' ', $nf['cot_estado'])) ?></span></td>
            <td><a href="cotizaciones/ver_cotizacion.php?id=<?= $nf['cot_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- OC RECIBIDAS -->
<?php
  $notifOCNoLeidas = array_filter($notifOCRecibidas, function($n) { return !$n['leida']; });
  $notifOCLeidas = array_filter($notifOCRecibidas, function($n) { return $n['leida']; });
?>
<?php if (!empty($notifOCRecibidas)): ?>
<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-dark text-white py-3">
    <h5 class="mb-0"><i class="bi bi-inbox me-2"></i>OC — Estado de Proceso Recibido (<?= count($notifOCNoLeidas) ?> sin leer)</h5>
  </div>
  <div class="card-body p-4">
    <?php foreach ($notifOCNoLeidas as $noc):
      $monedaNoc = $noc['moneda'] ?: 'CLP';
      $estadoBadgesOC = ['pendiente'=>'secondary','en_revision'=>'info','aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning'];
      $estBadgeOC = $estadoBadgesOC[$noc['oc_estado']] ?? 'secondary';
    ?>
    <div class="card border-dark mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 mb-2">
              <h5 class="mb-0"><span class="badge bg-dark">OC N° <?= $noc['numero'] ?></span></h5>
              <span class="badge bg-info">Estado/Proceso</span>
              <span class="badge bg-<?= $estBadgeOC ?>"><?= ucfirst(str_replace('_', ' ', $noc['oc_estado'])) ?></span>
              <span class="badge bg-danger">Nueva</span>
            </div>
            <table class="table table-sm mb-2" style="font-size:13px;">
              <tr><td class="fw-bold" style="width:130px;">Proveedor:</td><td><?= htmlspecialchars($noc['proveedor_nombre']) ?></td></tr>
              <tr><td class="fw-bold">Obra:</td><td><?= htmlspecialchars($noc['obra']) ?></td></tr>
              <tr><td class="fw-bold">Fecha:</td><td><?= date('d/m/Y', strtotime($noc['fecha'])) ?></td></tr>
              <tr><td class="fw-bold">Total:</td><td class="fw-bold text-success fs-5"><?= $monedaNoc ?> $<?= number_format($noc['total'], 0, ',', '.') ?></td></tr>
              <tr><td class="fw-bold">Enviada por:</td><td><?= htmlspecialchars($noc['enviado_por']) ?></td></tr>
            </table>
            <div class="d-flex gap-2">
              <a href="ver.php?id=<?= $noc['oc_id'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1"></i>Ver OC</a>
              <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="marcar_leida_oc">
                <input type="hidden" name="notif_id" value="<?= $noc['id'] ?>">
                <button class="btn btn-outline-success btn-sm"><i class="bi bi-check2 me-1"></i>Marcar leída</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($notifOCLeidas)): ?>
    <hr>
    <h6 class="text-muted mb-3"><i class="bi bi-check-all me-1"></i>Leídas anteriormente</h6>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle">
        <thead class="table-light">
          <tr><th>OC N°</th><th>Proveedor</th><th>Total</th><th>Enviada por</th><th>Fecha</th><th>Estado</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($notifOCLeidas as $noc):
            $estBadgeOC2 = $estadoBadgesOC[$noc['oc_estado']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-bold"><?= $noc['numero'] ?></td>
            <td><?= htmlspecialchars($noc['proveedor_nombre']) ?></td>
            <td class="fw-bold">$<?= number_format($noc['total'], 0, ',', '.') ?></td>
            <td><small><?= htmlspecialchars($noc['enviado_por']) ?></small></td>
            <td><small><?= date('d/m/Y H:i', strtotime($noc['fecha'])) ?></small></td>
            <td><span class="badge bg-<?= $estBadgeOC2 ?>"><?= ucfirst(str_replace('_', ' ', $noc['oc_estado'])) ?></span></td>
            <td><a href="ver.php?id=<?= $noc['oc_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- PENDIENTES -->
<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-warning text-dark py-3">
    <h5 class="mb-0"><i class="bi bi-bell me-2"></i>OC Pendientes de Aprobación (<?= count($pendientes) ?>)</h5>
  </div>
  <div class="card-body p-4">
    <?php if (empty($pendientes)): ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-check-circle fs-1 text-success"></i>
      <p class="mt-2 mb-0">No tiene órdenes de compra pendientes de aprobación</p>
    </div>
    <?php else: ?>
    <?php foreach ($pendientes as $p):
      $moneda = $p['moneda'] ?: 'CLP';
    ?>
    <div class="card border-warning mb-3">
      <div class="card-body">
        <div class="row align-items-center">
          <div class="col-md-7">
            <div class="d-flex align-items-center mb-2">
              <h5 class="mb-0 me-3">
                <span class="badge bg-dark">OC N° <?= $p['numero'] ?></span>
              </h5>
              <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pendiente</span>
            </div>
            <table class="table table-sm mb-2" style="font-size:13px;">
              <tr><td class="fw-bold" style="width:130px;">Proveedor:</td><td><?= htmlspecialchars($p['proveedor_nombre']) ?></td></tr>
              <tr><td class="fw-bold">Obra:</td><td><?= htmlspecialchars($p['obra']) ?></td></tr>
              <tr><td class="fw-bold">Fecha OC:</td><td><?= date('d/m/Y', strtotime($p['fecha'])) ?></td></tr>
              <tr><td class="fw-bold">Preparada por:</td><td><?= htmlspecialchars($p['preparada_por']) ?></td></tr>
              <tr><td class="fw-bold">Total:</td><td class="fw-bold text-success fs-5"><?= $moneda ?> $<?= number_format($p['total'], 0, ',', '.') ?></td></tr>
            </table>
            <a href="ver.php?id=<?= $p['oc_id'] ?>" class="btn btn-outline-primary btn-sm" target="_blank"><i class="bi bi-eye me-1"></i>Ver OC completa</a>
          </div>
          <div class="col-md-5">
            <div class="card bg-light border-0">
              <div class="card-body p-3">
                <h6 class="fw-bold mb-3 text-center"><i class="bi bi-shield-check me-1"></i>Su Decisión</h6>
                <form method="POST">
                  <input type="hidden" name="action" value="aprobar_oc">
                  <input type="hidden" name="aprob_id" value="<?= $p['id'] ?>">
                  <div class="mb-3">
                    <textarea name="comentario" class="form-control form-control-sm" rows="2" placeholder="Comentario (opcional)"></textarea>
                  </div>
                  <div class="d-flex gap-2 justify-content-center flex-wrap">
                    <button type="submit" name="accion" value="aprobada" class="btn btn-success" onclick="return confirm('¿Confirma APROBAR esta OC N° <?= $p['numero'] ?>?')">
                      <i class="bi bi-check-circle me-1"></i>Aprobar
                    </button>
                    <button type="submit" name="accion" value="corregir" class="btn btn-warning" onclick="return confirm('¿Solicitar CORRECCIONES?')">
                      <i class="bi bi-pencil me-1"></i>Corregir
                    </button>
                    <button type="submit" name="accion" value="rechazada" class="btn btn-danger" onclick="return confirm('¿Confirma RECHAZAR esta OC N° <?= $p['numero'] ?>?')">
                      <i class="bi bi-x-circle me-1"></i>Rechazar
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- HISTORIAL -->
<div class="card shadow-sm border-0">
  <div class="card-header bg-dark text-white py-3">
    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Historial de Mis Aprobaciones</h5>
  </div>
  <div class="card-body p-4">
    <?php if (empty($historial)): ?>
    <div class="text-center text-muted py-4">
      <i class="bi bi-inbox fs-2"></i>
      <p class="mt-2 mb-0">No hay aprobaciones registradas aún</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>OC N°</th>
            <th>Proveedor</th>
            <th>Obra</th>
            <th class="text-end">Total</th>
            <th>Mi Decisión</th>
            <th>Comentario</th>
            <th>Fecha Respuesta</th>
            <th>Estado OC</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historial as $h):
            $badgeDecisionMap = ['aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning'];
            $badgeDecision = isset($badgeDecisionMap[$h['estado']]) ? $badgeDecisionMap[$h['estado']] : 'secondary';
            $iconDecisionMap = ['aprobada'=>'check-circle-fill','rechazada'=>'x-circle-fill','corregir'=>'pencil-fill'];
            $iconDecision = isset($iconDecisionMap[$h['estado']]) ? $iconDecisionMap[$h['estado']] : 'circle';
            $badgeOCMap = ['aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning','en_revision'=>'info','pendiente'=>'secondary'];
            $badgeOC = isset($badgeOCMap[$h['oc_estado']]) ? $badgeOCMap[$h['oc_estado']] : 'secondary';
          ?>
          <tr>
            <td><a href="ver.php?id=<?= $h['oc_id'] ?>" class="fw-bold text-decoration-none"><?= $h['numero'] ?></a></td>
            <td><?= htmlspecialchars($h['proveedor_nombre']) ?></td>
            <td><small><?= htmlspecialchars($h['obra']) ?></small></td>
            <td class="text-end fw-bold">$<?= number_format($h['total'], 0, ',', '.') ?></td>
            <td><span class="badge bg-<?= $badgeDecision ?>"><i class="bi bi-<?= $iconDecision ?> me-1"></i><?= ucfirst($h['estado']) ?></span></td>
            <td><small class="text-muted"><?= htmlspecialchars($h['comentario'] ?? '—') ?></small></td>
            <td><small><?= $h['fecha_respuesta'] ? date('d/m/Y H:i', strtotime($h['fecha_respuesta'])) : '—' ?></small></td>
            <td><span class="badge bg-<?= $badgeOC ?>"><?= ucfirst($h['oc_estado']) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- COTIZACIONES PENDIENTES -->
<div class="card shadow-sm border-0 mb-4 mt-4">
  <div class="card-header bg-info text-white py-3">
    <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Cotizaciones Pendientes de Validación (<?= count($cotPendientes) ?>)</h5>
  </div>
  <div class="card-body p-4">
    <?php if (empty($cotPendientes)): ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-check-circle fs-1 text-success"></i>
      <p class="mt-2 mb-0">No tiene cotizaciones pendientes de validación</p>
    </div>
    <?php else: ?>
    <?php foreach ($cotPendientes as $p): ?>
    <div class="card border-info mb-3">
      <div class="card-body">
        <div class="row align-items-center">
          <div class="col-md-7">
            <div class="d-flex align-items-center mb-2">
              <h5 class="mb-0 me-3">
                <span class="badge bg-dark">Cotización N° <?= $p['numero'] ?></span>
              </h5>
              <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pendiente</span>
            </div>
            <table class="table table-sm mb-2" style="font-size:13px;">
              <tr><td class="fw-bold" style="width:130px;">Cliente:</td><td><?= htmlspecialchars($p['cliente_nombre']) ?></td></tr>
              <tr><td class="fw-bold">Obra:</td><td><?= htmlspecialchars($p['cliente_obra']) ?></td></tr>
              <tr><td class="fw-bold">Fecha:</td><td><?= date('d/m/Y', strtotime($p['fecha'])) ?></td></tr>
              <tr><td class="fw-bold">Creada por:</td><td><?= htmlspecialchars($p['creada_por']) ?></td></tr>
              <tr><td class="fw-bold">Total:</td><td class="fw-bold text-success fs-5">$<?= number_format($p['total'], 0, ',', '.') ?></td></tr>
            </table>
            <a href="cotizaciones/ver_cotizacion.php?id=<?= $p['cot_id'] ?>" class="btn btn-outline-primary btn-sm" target="_blank"><i class="bi bi-eye me-1"></i>Ver Cotización completa</a>
          </div>
          <div class="col-md-5">
            <div class="card bg-light border-0">
              <div class="card-body p-3">
                <h6 class="fw-bold mb-3 text-center"><i class="bi bi-shield-check me-1"></i>Su Decisión</h6>
                <form method="POST">
                  <input type="hidden" name="action" value="aprobar_cot">
                  <input type="hidden" name="aprob_id" value="<?= $p['id'] ?>">
                  <div class="mb-3">
                    <textarea name="comentario" class="form-control form-control-sm" rows="2" placeholder="Observación (opcional)"></textarea>
                  </div>
                  <div class="d-flex gap-2 justify-content-center flex-wrap">
                    <button type="submit" name="accion" value="aprobada" class="btn btn-success" onclick="return confirm('¿Confirma APROBAR esta Cotización N° <?= $p['numero'] ?>?')">
                      <i class="bi bi-check-circle me-1"></i>Aprobar
                    </button>
                    <button type="submit" name="accion" value="corregir" class="btn btn-warning" onclick="return confirm('¿Solicitar CORRECCIONES?')">
                      <i class="bi bi-pencil me-1"></i>Corregir
                    </button>
                    <button type="submit" name="accion" value="rechazada" class="btn btn-danger" onclick="return confirm('¿Confirma RECHAZAR esta Cotización N° <?= $p['numero'] ?>?')">
                      <i class="bi bi-x-circle me-1"></i>Rechazar
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- HISTORIAL COTIZACIONES -->
<div class="card shadow-sm border-0">
  <div class="card-header bg-secondary text-white py-3">
    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Historial de Validación de Cotizaciones</h5>
  </div>
  <div class="card-body p-4">
    <?php if (empty($cotHistorial)): ?>
    <div class="text-center text-muted py-4">
      <i class="bi bi-inbox fs-2"></i>
      <p class="mt-2 mb-0">No hay validaciones de cotizaciones registradas aún</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>Cot. N°</th>
            <th>Cliente</th>
            <th>Obra</th>
            <th class="text-end">Total</th>
            <th>Mi Decisión</th>
            <th>Observación</th>
            <th>Fecha Respuesta</th>
            <th>Estado Cot.</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cotHistorial as $h):
            $badgeDecisionMap = ['aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning'];
            $badgeDecision = isset($badgeDecisionMap[$h['estado']]) ? $badgeDecisionMap[$h['estado']] : 'secondary';
            $iconDecisionMap = ['aprobada'=>'check-circle-fill','rechazada'=>'x-circle-fill','corregir'=>'pencil-fill'];
            $iconDecision = isset($iconDecisionMap[$h['estado']]) ? $iconDecisionMap[$h['estado']] : 'circle';
            $badgeCotMap = ['aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning','en_revision'=>'info','pendiente'=>'secondary'];
            $badgeCot = isset($badgeCotMap[$h['cot_estado']]) ? $badgeCotMap[$h['cot_estado']] : 'secondary';
          ?>
          <tr>
            <td><a href="cotizaciones/ver_cotizacion.php?id=<?= $h['cot_id'] ?>" class="fw-bold text-decoration-none"><?= $h['numero'] ?></a></td>
            <td><?= htmlspecialchars($h['cliente_nombre']) ?></td>
            <td><small><?= htmlspecialchars($h['cliente_obra']) ?></small></td>
            <td class="text-end fw-bold">$<?= number_format($h['total'], 0, ',', '.') ?></td>
            <td><span class="badge bg-<?= $badgeDecision ?>"><i class="bi bi-<?= $iconDecision ?> me-1"></i><?= ucfirst($h['estado']) ?></span></td>
            <td><small class="text-muted"><?= htmlspecialchars($h['comentario'] ?? '—') ?></small></td>
            <td><small><?= $h['fecha_respuesta'] ? date('d/m/Y H:i', strtotime($h['fecha_respuesta'])) : '—' ?></small></td>
            <td><span class="badge bg-<?= $badgeCot ?>"><?= ucfirst(str_replace('_', ' ', $h['cot_estado'])) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
