<?php
require_once 'config.php';
requireAuth();

$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'crear') {
    $nombre = trim($_POST['nombre'] ?? '');
    if (!$nombre) { $msg = ['danger', 'El nombre del proveedor es obligatorio']; }
    else {
      try {
        $pdo->prepare("INSERT INTO proveedores (nombre, rut, giro, direccion, ciudad, telefono, email, contacto, banco, tipo_cuenta, numero_cuenta, forma_pago) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([
            $nombre,
            trim($_POST['rut']??''),
            trim($_POST['giro']??''),
            trim($_POST['direccion']??''),
            trim($_POST['ciudad']??''),
            trim($_POST['telefono']??''),
            trim($_POST['email']??''),
            trim($_POST['contacto']??''),
            trim($_POST['banco']??''),
            trim($_POST['tipo_cuenta']??''),
            trim($_POST['numero_cuenta']??''),
            trim($_POST['forma_pago']??''),
          ]);
        $msg = ['success', 'Proveedor creado correctamente'];
      } catch (Exception $e) {
        $msg = ['danger', 'Error al crear: '.$e->getMessage()];
      }
    }
  }

  if ($action === 'editar') {
    $id = (int)($_POST['proveedor_id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    if ($id > 0 && $nombre) {
      $pdo->prepare("UPDATE proveedores SET nombre=?, rut=?, giro=?, direccion=?, ciudad=?, telefono=?, email=?, contacto=?, banco=?, tipo_cuenta=?, numero_cuenta=?, forma_pago=? WHERE id=?")
        ->execute([
          $nombre,
          trim($_POST['rut']??''),
          trim($_POST['giro']??''),
          trim($_POST['direccion']??''),
          trim($_POST['ciudad']??''),
          trim($_POST['telefono']??''),
          trim($_POST['email']??''),
          trim($_POST['contacto']??''),
          trim($_POST['banco']??''),
          trim($_POST['tipo_cuenta']??''),
          trim($_POST['numero_cuenta']??''),
          trim($_POST['forma_pago']??''),
          $id,
        ]);
      $msg = ['success', 'Proveedor actualizado'];
    }
  }

  if ($action === 'eliminar') {
    $id = (int)($_POST['proveedor_id'] ?? 0);
    if ($id > 0) {
      $pdo->prepare("DELETE FROM proveedores WHERE id = ?")->execute([$id]);
      $msg = ['success', 'Proveedor eliminado'];
    }
  }
}

$buscar = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM proveedores";
$params = [];
if ($buscar !== '') {
  $sql .= " WHERE nombre LIKE ? OR rut LIKE ? OR ciudad LIKE ? OR email LIKE ? OR contacto LIKE ?";
  $like = "%$buscar%";
  $params = [$like,$like,$like,$like,$like];
}
$sql .= " ORDER BY nombre ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$editando = null;
if (isset($_GET['editar'])) {
  $stmtE = $pdo->prepare("SELECT * FROM proveedores WHERE id = ?");
  $stmtE->execute([(int)$_GET['editar']]);
  $editando = $stmtE->fetch(PDO::FETCH_ASSOC);
}

require_once 'includes/header.php';
?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-danger text-white">
        <h6 class="mb-0"><i class="bi bi-truck me-2"></i><?= $editando ? 'Editar Proveedor' : 'Nuevo Proveedor' ?></h6>
      </div>
      <div class="card-body">
        <?php if ($msg): ?><div class="alert alert-<?= $msg[0] ?> py-2"><?= htmlspecialchars($msg[1]) ?></div><?php endif; ?>
        <form method="POST">
          <input type="hidden" name="action" value="<?= $editando ? 'editar' : 'crear' ?>">
          <?php if ($editando): ?><input type="hidden" name="proveedor_id" value="<?= $editando['id'] ?>"><?php endif; ?>

          <div class="mb-2">
            <label class="form-label fw-semibold">Razón Social *</label>
            <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>" required>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label fw-semibold">RUT</label>
              <input type="text" name="rut" class="form-control" value="<?= htmlspecialchars($editando['rut'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Giro</label>
              <input type="text" name="giro" class="form-control" value="<?= htmlspecialchars($editando['giro'] ?? '') ?>">
            </div>
          </div>
          <div class="mb-2 mt-2">
            <label class="form-label fw-semibold">Dirección</label>
            <input type="text" name="direccion" class="form-control" value="<?= htmlspecialchars($editando['direccion'] ?? '') ?>">
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label fw-semibold">Ciudad</label>
              <input type="text" name="ciudad" class="form-control" value="<?= htmlspecialchars($editando['ciudad'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Teléfono</label>
              <input type="text" name="telefono" class="form-control" value="<?= htmlspecialchars($editando['telefono'] ?? '') ?>">
            </div>
          </div>
          <div class="mb-2 mt-2">
            <label class="form-label fw-semibold">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editando['email'] ?? '') ?>">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Contacto comercial</label>
            <input type="text" name="contacto" class="form-control" value="<?= htmlspecialchars($editando['contacto'] ?? '') ?>">
          </div>

          <hr>
          <div class="text-muted small mb-2"><i class="bi bi-bank me-1"></i>Datos para pago</div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label fw-semibold">Banco</label>
              <input type="text" name="banco" class="form-control" value="<?= htmlspecialchars($editando['banco'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Tipo cuenta</label>
              <select name="tipo_cuenta" class="form-select">
                <?php $tc = $editando['tipo_cuenta'] ?? ''; ?>
                <option value="" <?= $tc===''?'selected':'' ?>>--</option>
                <option value="Cta. Corriente" <?= $tc==='Cta. Corriente'?'selected':'' ?>>Cta. Corriente</option>
                <option value="Cta. Vista"     <?= $tc==='Cta. Vista'?'selected':'' ?>>Cta. Vista</option>
                <option value="Cta. Ahorro"    <?= $tc==='Cta. Ahorro'?'selected':'' ?>>Cta. Ahorro</option>
                <option value="Cta. RUT"       <?= $tc==='Cta. RUT'?'selected':'' ?>>Cta. RUT</option>
              </select>
            </div>
          </div>
          <div class="row g-2 mt-2">
            <div class="col-7">
              <label class="form-label fw-semibold">N° Cuenta</label>
              <input type="text" name="numero_cuenta" class="form-control" value="<?= htmlspecialchars($editando['numero_cuenta'] ?? '') ?>">
            </div>
            <div class="col-5">
              <label class="form-label fw-semibold">Forma de pago</label>
              <select name="forma_pago" class="form-select">
                <?php $fp = $editando['forma_pago'] ?? ''; ?>
                <option value="" <?= $fp===''?'selected':'' ?>>--</option>
                <option value="Contado"      <?= $fp==='Contado'?'selected':'' ?>>Contado</option>
                <option value="30 días"      <?= $fp==='30 días'?'selected':'' ?>>30 días</option>
                <option value="45 días"      <?= $fp==='45 días'?'selected':'' ?>>45 días</option>
                <option value="60 días"      <?= $fp==='60 días'?'selected':'' ?>>60 días</option>
                <option value="90 días"      <?= $fp==='90 días'?'selected':'' ?>>90 días</option>
              </select>
            </div>
          </div>

          <button class="btn btn-danger w-100 mt-3"><i class="bi bi-save me-1"></i><?= $editando ? 'Actualizar Proveedor' : 'Guardar Proveedor' ?></button>
          <?php if ($editando): ?>
          <a href="proveedores.php" class="btn btn-outline-secondary w-100 mt-2">Cancelar</a>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-danger text-white">
        <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Proveedores registrados (<?= count($proveedores) ?>)</h6>
      </div>
      <div class="card-body p-3">
        <form class="mb-3" method="GET">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" name="q" class="form-control" placeholder="Buscar proveedor..." value="<?= htmlspecialchars($buscar) ?>">
            <button class="btn btn-danger">Buscar</button>
          </div>
        </form>
        <?php if (empty($proveedores)): ?>
        <div class="text-center text-muted py-4"><i class="bi bi-truck fs-1"></i><p class="mt-2">No hay proveedores registrados</p></div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Razón Social</th>
                <th>RUT</th>
                <th>Ciudad</th>
                <th>Contacto</th>
                <th>Forma Pago</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($proveedores as $p): ?>
              <tr>
                <td>
                  <span class="fw-semibold"><?= htmlspecialchars($p['nombre']) ?></span>
                  <?php if (!empty($p['giro'])): ?><br><small class="text-muted"><?= htmlspecialchars($p['giro']) ?></small><?php endif; ?>
                </td>
                <td><small><?= htmlspecialchars($p['rut']) ?></small></td>
                <td><small><?= htmlspecialchars($p['ciudad']) ?></small></td>
                <td>
                  <small class="d-block"><?= htmlspecialchars($p['contacto']) ?></small>
                  <small class="text-muted"><?= htmlspecialchars($p['telefono']) ?></small>
                </td>
                <td><small><?= htmlspecialchars($p['forma_pago']) ?></small></td>
                <td>
                  <a href="proveedores.php?editar=<?= $p['id'] ?>" class="btn btn-sm btn-outline-warning" title="Editar"><i class="bi bi-pencil"></i></a>
                  <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este proveedor?')">
                    <input type="hidden" name="action" value="eliminar">
                    <input type="hidden" name="proveedor_id" value="<?= $p['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
