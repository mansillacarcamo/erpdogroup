<?php
require_once 'config.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $ocId = (int)($_POST['oc_id'] ?? 0);
  if ($_POST['action'] === 'cambiar_estado' && !empty($_POST['estado'])) {
    $pdo->prepare("UPDATE ordenes_compra SET estado = ? WHERE id = ?")->execute([$_POST['estado'], $ocId]);
  }
  if ($_POST['action'] === 'eliminar' && in_array($usuario['rol'], ['admin', 'gerente_comercial'])) {
    $pdo->prepare("DELETE FROM oc_items WHERE oc_id = ?")->execute([$ocId]);
    $pdo->prepare("DELETE FROM ordenes_compra WHERE id = ?")->execute([$ocId]);
  }
  header('Location: historial.php'); exit;
}

$buscar = trim($_GET['q'] ?? '');
$filtro = trim($_GET['estado'] ?? '');

$sql = "SELECT * FROM ordenes_compra WHERE 1=1";
$params = [];
if ($buscar !== '') {
  $sql .= " AND (proveedor_nombre LIKE ? OR obra LIKE ? OR CAST(numero AS TEXT) LIKE ? OR preparada_por LIKE ?)";
  $like = "%$buscar%";
  $params = [$like,$like,$like,$like];
}
if ($filtro !== '') {
  $sql .= " AND estado = ?";
  $params[] = $filtro;
}
$sql .= " ORDER BY numero DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>
<a href="/inicio.php" data-volver data-fallback="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver</a>


<div class="card shadow-sm border-0">
  <div class="card-header bg-danger text-white py-3">
    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Historial de Órdenes de Compra</h5>
  </div>
  <div class="card-body p-4">
    <form class="row g-2 mb-4" method="GET">
      <div class="col-md-6">
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Buscar por N°, proveedor, obra..." value="<?= htmlspecialchars($buscar) ?>">
        </div>
      </div>
      <div class="col-md-3">
        <select name="estado" class="form-select">
          <option value="">Todos los estados</option>
          <option value="pendiente" <?= $filtro==='pendiente'?'selected':'' ?>>Pendiente</option>
          <option value="en_revision" <?= $filtro==='en_revision'?'selected':'' ?>>En Revisión</option>
          <option value="aprobada" <?= $filtro==='aprobada'?'selected':'' ?>>Aprobada</option>
          <option value="rechazada" <?= $filtro==='rechazada'?'selected':'' ?>>Rechazada</option>
          <option value="corregir" <?= $filtro==='corregir'?'selected':'' ?>>Requiere Corrección</option>
          <option value="completada" <?= $filtro==='completada'?'selected':'' ?>>Completada</option>
          <option value="anulada" <?= $filtro==='anulada'?'selected':'' ?>>Anulada</option>
        </select>
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-danger w-100"><i class="bi bi-filter me-1"></i>Filtrar</button>
      </div>
    </form>

    <?php if (empty($ordenes)): ?>
    <div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1"></i><p class="mt-2">No hay órdenes de compra</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>N° OC</th>
            <th>Fecha</th>
            <th>Proveedor</th>
            <th>Obra</th>
            <th class="text-end">Total</th>
            <th>Estado</th>
            <th>Preparada</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ordenes as $o):
            $badges = ['pendiente'=>'warning','en_revision'=>'info','aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning','completada'=>'primary','anulada'=>'secondary'];
            $badge = isset($badges[$o['estado']]) ? $badges[$o['estado']] : 'secondary';
            $iconos = ['pendiente'=>'hourglass-split','en_revision'=>'eye','aprobada'=>'check-circle-fill','rechazada'=>'x-circle-fill','corregir'=>'pencil-fill','completada'=>'check2-all','anulada'=>'dash-circle'];
            $iconEstado = isset($iconos[$o['estado']]) ? $iconos[$o['estado']] : 'circle';
            $labels = ['en_revision'=>'En Revisión','corregir'=>'Corrección'];
            $labelEstado = isset($labels[$o['estado']]) ? $labels[$o['estado']] : ucfirst($o['estado']);
          ?>
          <tr>
            <td class="fw-bold"><?= $o['numero'] ?></td>
            <td><?= date('d/m/Y', strtotime($o['fecha'])) ?></td>
            <td><?= htmlspecialchars($o['proveedor_nombre']) ?></td>
            <td><?= htmlspecialchars($o['obra']) ?></td>
            <td class="text-end fw-bold">$<?= formatCLP($o['total']) ?></td>
            <td>
              <span class="badge bg-<?= $badge ?>"><i class="bi bi-<?= $iconEstado ?> me-1"></i><?= $labelEstado ?></span>
            </td>
            <td><small><?= htmlspecialchars($o['preparada_por']) ?></small></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <a href="ver.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-primary" title="Ver"><i class="bi bi-eye"></i></a>
                <?php if (in_array($o['estado'], ['pendiente', 'corregir'])): ?>
                <a href="editar_oc.php?id=<?= $o['id'] ?>" class="btn btn-sm btn-outline-warning" title="Editar"><i class="bi bi-pencil"></i></a>
                <?php endif; ?>
                <?php if (in_array($usuario['rol'], ['admin', 'gerente_comercial'])): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar OC N° <?= $o['numero'] ?>?')">
                  <input type="hidden" name="oc_id" value="<?= $o['id'] ?>">
                  <input type="hidden" name="action" value="eliminar">
                  <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-muted"><small>Total: <?= count($ordenes) ?> orden(es)</small></p>
    <?php endif; ?>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
