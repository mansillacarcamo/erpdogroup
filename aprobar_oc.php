<?php
require_once 'config.php';

$token = trim($_GET['token'] ?? '');
if (empty($token)) { die('Enlace inválido.'); }

$stmt = $pdo->prepare("SELECT a.*, o.numero, o.fecha, o.proveedor_nombre, o.proveedor_rut, o.obra, o.neto, o.iva, o.total, o.preparada_por, o.moneda, o.observaciones, o.plazo_entrega, o.proveedor_direccion, o.proveedor_ciudad, o.proveedor_fono, o.proveedor_correo FROM oc_aprobaciones a JOIN ordenes_compra o ON a.oc_id = o.id WHERE a.token = ?");
$stmt->execute([$token]);
$aprobacion = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$aprobacion) { die('Enlace inválido o expirado.'); }

$stmtItems = $pdo->prepare("SELECT * FROM oc_items WHERE oc_id = ? ORDER BY id");
$stmtItems->execute([$aprobacion['oc_id']]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$mensaje = null;
$yaRespondio = $aprobacion['estado'] !== 'pendiente';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$yaRespondio) {
    $accion = $_POST['accion'] ?? '';
    $comentario = trim($_POST['comentario'] ?? '');

    if (in_array($accion, ['aprobada', 'rechazada', 'corregir'])) {
        $pdo->prepare("UPDATE oc_aprobaciones SET estado = ?, comentario = ?, fecha_respuesta = datetime('now') WHERE token = ?")
            ->execute([$accion, $comentario, $token]);

        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobaciones WHERE oc_id = ? AND estado = 'aprobada'");
        $stmtCheck->execute([$aprobacion['oc_id']]);
        $aprobadas = (int)$stmtCheck->fetchColumn();

        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobaciones WHERE oc_id = ?");
        $stmtTotal->execute([$aprobacion['oc_id']]);
        $totalAprobadores = (int)$stmtTotal->fetchColumn();

        if ($aprobadas === $totalAprobadores) {
            $pdo->prepare("UPDATE ordenes_compra SET estado = 'aprobada' WHERE id = ?")->execute([$aprobacion['oc_id']]);
        } elseif ($accion === 'rechazada') {
            $pdo->prepare("UPDATE ordenes_compra SET estado = 'rechazada' WHERE id = ?")->execute([$aprobacion['oc_id']]);
        } elseif ($accion === 'corregir') {
            $pdo->prepare("UPDATE ordenes_compra SET estado = 'corregir' WHERE id = ?")->execute([$aprobacion['oc_id']]);
        }

        $yaRespondio = true;
        $aprobacion['estado'] = $accion;
        $mensaje = $accion === 'aprobada' ? 'Ha aprobado la Orden de Compra exitosamente.' :
                  ($accion === 'rechazada' ? 'Ha rechazado la Orden de Compra.' : 'Ha solicitado correcciones en la Orden de Compra.');
    }
}

$moneda = $aprobacion['moneda'] ?: 'CLP';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Aprobación OC N° <?= $aprobacion['numero'] ?> — DOGroup</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    body { background: #f5f5f5; font-family: Arial, sans-serif; }
    .approval-card { max-width: 800px; margin: 30px auto; }
    .header-bar { background: linear-gradient(135deg, #d97706, #b45309); color: #fff; padding: 20px 24px; border-radius: 12px 12px 0 0; }
    .td-label { background: #fef3c7; color: #92400e; font-weight: 700; width: 20%; }
    .items-header th { background: #1a1a2e !important; color: #fff !important; font-size: 11px; }
    .total-row { background: #fef3c7; font-weight: bold; }
    .btn-aprobar { background: #16a34a; border-color: #16a34a; color: #fff; }
    .btn-aprobar:hover { background: #15803d; border-color: #15803d; color: #fff; }
    .btn-rechazar { background: #dc2626; border-color: #dc2626; color: #fff; }
    .btn-rechazar:hover { background: #b91c1c; border-color: #b91c1c; color: #fff; }
    .btn-corregir { background: #d97706; border-color: #d97706; color: #fff; }
    .btn-corregir:hover { background: #b45309; border-color: #b45309; color: #fff; }
    .status-badge { font-size: 1.1rem; }
  </style>
</head>
<body>
<div class="approval-card">
  <div class="header-bar">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h4 class="mb-1"><i class="bi bi-file-earmark-check me-2"></i>Orden de Compra N° <?= $aprobacion['numero'] ?></h4>
        <small>Revisión solicitada a: <?= htmlspecialchars($aprobacion['nombre']) ?></small>
      </div>
      <div class="text-end">
        <img src="img/logo.png" alt="DOGroup" style="height:80px;background:transparent;padding:0;filter:brightness(0) invert(1);">
      </div>
    </div>
  </div>

  <div class="bg-white border border-top-0 rounded-bottom p-4">
    <?php if ($mensaje): ?>
    <div class="alert alert-<?= $aprobacion['estado'] === 'aprobada' ? 'success' : ($aprobacion['estado'] === 'rechazada' ? 'danger' : 'warning') ?> text-center">
      <i class="bi bi-<?= $aprobacion['estado'] === 'aprobada' ? 'check-circle' : ($aprobacion['estado'] === 'rechazada' ? 'x-circle' : 'pencil-square') ?> fs-4 me-2"></i>
      <strong><?= $mensaje ?></strong>
    </div>
    <?php endif; ?>

    <?php if ($yaRespondio && !$mensaje): ?>
    <div class="alert alert-info text-center">
      <i class="bi bi-info-circle fs-4 me-2"></i>
      <strong>Ya respondió esta solicitud.</strong>
      Su respuesta: <span class="badge bg-<?= $aprobacion['estado'] === 'aprobada' ? 'success' : ($aprobacion['estado'] === 'rechazada' ? 'danger' : 'warning') ?> status-badge"><?= ucfirst($aprobacion['estado']) ?></span>
    </div>
    <?php endif; ?>

    <h6 class="fw-bold text-secondary mb-3"><i class="bi bi-building me-1"></i> Datos del Proveedor</h6>
    <table class="table table-sm table-bordered mb-3" style="font-size:12px;">
      <tr><td class="td-label">Proveedor:</td><td><?= htmlspecialchars($aprobacion['proveedor_nombre']) ?></td></tr>
      <tr><td class="td-label">RUT:</td><td><?= htmlspecialchars($aprobacion['proveedor_rut']) ?></td></tr>
      <tr><td class="td-label">Obra:</td><td><?= htmlspecialchars($aprobacion['obra']) ?></td></tr>
      <tr><td class="td-label">Fecha:</td><td><?= date('d/m/Y', strtotime($aprobacion['fecha'])) ?></td></tr>
      <tr><td class="td-label">Preparada por:</td><td><?= htmlspecialchars($aprobacion['preparada_por']) ?></td></tr>
      <?php if ($aprobacion['observaciones']): ?>
      <tr><td class="td-label">Observaciones:</td><td><?= nl2br(htmlspecialchars($aprobacion['observaciones'])) ?></td></tr>
      <?php endif; ?>
    </table>

    <h6 class="fw-bold text-secondary mb-3"><i class="bi bi-list-check me-1"></i> Detalle de Ítems</h6>
    <div class="table-responsive">
      <table class="table table-sm table-bordered" style="font-size:11px;">
        <thead><tr class="items-header">
          <th>Cant.</th><th>Unidad</th><th>Descripción</th><th>P. Unitario</th><th>Total</th>
        </tr></thead>
        <tbody>
          <?php foreach ($items as $item): ?>
          <tr>
            <td class="text-center"><?= $item['cantidad'] ?></td>
            <td class="text-center"><?= htmlspecialchars(mb_strtoupper((string)$item['unidad'], 'UTF-8')) ?></td>
            <td><?= htmlspecialchars($item['descripcion']) ?></td>
            <td class="text-end">$<?= formatCLP($item['precio_unitario']) ?></td>
            <td class="text-end">$<?= formatCLP($item['valor_total']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="3"></td><td class="text-end fw-bold">NETO</td><td class="text-end">$<?= formatCLP($aprobacion['neto']) ?></td></tr>
          <tr><td colspan="3"></td><td class="text-end fw-bold">IVA 19%</td><td class="text-end">$<?= formatCLP($aprobacion['iva']) ?></td></tr>
          <tr class="total-row"><td colspan="3"></td><td class="text-end fw-bold fs-6">TOTAL</td><td class="text-end fw-bold fs-6">$<?= formatCLP($aprobacion['total']) ?></td></tr>
        </tfoot>
      </table>
    </div>

    <?php if (!$yaRespondio): ?>
    <hr>
    <form method="POST" id="formAprobacion">
      <h6 class="fw-bold mb-3"><i class="bi bi-chat-square-text me-1"></i> Comentario (opcional)</h6>
      <textarea name="comentario" class="form-control mb-4" rows="3" placeholder="Escriba un comentario o motivo..."></textarea>

      <div class="d-flex gap-3 justify-content-center flex-wrap">
        <button type="submit" name="accion" value="aprobada" class="btn btn-aprobar btn-lg px-4" onclick="return confirm('¿Confirma APROBAR esta Orden de Compra?')">
          <i class="bi bi-check-circle me-2"></i>Aprobar
        </button>
        <button type="submit" name="accion" value="corregir" class="btn btn-corregir btn-lg px-4" onclick="return confirm('¿Solicitar CORRECCIONES a esta OC?')">
          <i class="bi bi-pencil-square me-2"></i>Solicitar Corrección
        </button>
        <button type="submit" name="accion" value="rechazada" class="btn btn-rechazar btn-lg px-4" onclick="return confirm('¿Confirma RECHAZAR esta Orden de Compra?')">
          <i class="bi bi-x-circle me-2"></i>Rechazar
        </button>
      </div>
    </form>
    <?php endif; ?>

    <div class="text-center mt-4 pt-3 border-top">
      <small class="text-muted">DOGROUP — 77.930.045-5<br>Sistema de Gestión DOGroup</small>
    </div>
  </div>
</div>
</body>
</html>
