<?php
require_once 'config.php';
requireAuth();

$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'crear') {
    $nombre = trim($_POST['nombre'] ?? '');
    if ($nombre) {
      try {
        $pdo->prepare("INSERT INTO clientes (nombre, rut, direccion, ciudad, telefono, email, contacto) VALUES (?,?,?,?,?,?,?)")
          ->execute([$nombre, trim($_POST['rut']??''), trim($_POST['direccion']??''), trim($_POST['ciudad']??''), trim($_POST['telefono']??''), trim($_POST['email']??''), trim($_POST['contacto']??'')]);
        $msg = ['success', 'Cliente creado correctamente'];
      } catch (Exception $e) { $msg = ['danger', 'Error al crear cliente']; }
    } else { $msg = ['danger', 'El nombre es obligatorio']; }
  }

  if ($action === 'editar') {
    $id = (int)($_POST['cliente_id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    if ($nombre && $id > 0) {
      $pdo->prepare("UPDATE clientes SET nombre=?, rut=?, direccion=?, ciudad=?, telefono=?, email=?, contacto=? WHERE id=?")
        ->execute([$nombre, trim($_POST['rut']??''), trim($_POST['direccion']??''), trim($_POST['ciudad']??''), trim($_POST['telefono']??''), trim($_POST['email']??''), trim($_POST['contacto']??''), $id]);
      $msg = ['success', 'Cliente actualizado'];
    }
  }

  if ($action === 'eliminar') {
    $id = (int)($_POST['cliente_id'] ?? 0);
    $pdo->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id]);
    $msg = ['success', 'Cliente eliminado'];
  }
}

$buscar = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM clientes";
$params = [];
if ($buscar !== '') {
  $sql .= " WHERE nombre LIKE ? OR rut LIKE ? OR ciudad LIKE ? OR email LIKE ?";
  $like = "%$buscar%";
  $params = [$like,$like,$like,$like];
}
$sql .= " ORDER BY nombre ASC";
$clientes = $pdo->prepare($sql);
$clientes->execute($params);
$clientes = $clientes->fetchAll(PDO::FETCH_ASSOC);

$editando = null;
if (isset($_GET['editar'])) {
  $stmtE = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
  $stmtE->execute([(int)$_GET['editar']]);
  $editando = $stmtE->fetch(PDO::FETCH_ASSOC);
}

require_once 'includes/header.php';
?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-danger text-white">
        <h6 class="mb-0"><i class="bi bi-person-plus me-2"></i><?= $editando ? 'Editar Cliente' : 'Nuevo Cliente' ?></h6>
      </div>
      <div class="card-body">
        <?php if ($msg): ?><div class="alert alert-<?= $msg[0] ?> py-2"><?= $msg[1] ?></div><?php endif; ?>
        <form method="POST">
          <input type="hidden" name="action" value="<?= $editando ? 'editar' : 'crear' ?>">
          <?php if ($editando): ?><input type="hidden" name="cliente_id" value="<?= $editando['id'] ?>"><?php endif; ?>
          <div class="mb-2">
            <label class="form-label fw-semibold">Nombre / Razón Social *</label>
            <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>" required>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">RUT</label>
            <input type="text" name="rut" class="form-control" value="<?= htmlspecialchars($editando['rut'] ?? '') ?>">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Dirección</label>
            <input type="text" name="direccion" class="form-control" value="<?= htmlspecialchars($editando['direccion'] ?? '') ?>">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Ciudad</label>
            <input type="text" name="ciudad" class="form-control" value="<?= htmlspecialchars($editando['ciudad'] ?? '') ?>">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Teléfono</label>
            <input type="text" name="telefono" class="form-control" value="<?= htmlspecialchars($editando['telefono'] ?? '') ?>">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editando['email'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Contacto (ATN)</label>
            <input type="text" name="contacto" class="form-control" value="<?= htmlspecialchars($editando['contacto'] ?? '') ?>">
          </div>
          <button class="btn btn-danger w-100"><i class="bi bi-save me-1"></i><?= $editando ? 'Actualizar' : 'Guardar' ?></button>
          <?php if ($editando): ?>
          <a href="clientes.php" class="btn btn-outline-secondary w-100 mt-2">Cancelar</a>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-danger text-white">
        <h6 class="mb-0"><i class="bi bi-people me-2"></i>Clientes registrados (<?= count($clientes) ?>)</h6>
      </div>
      <div class="card-body p-3">
        <form class="mb-3" method="GET">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" name="q" class="form-control" placeholder="Buscar cliente..." value="<?= htmlspecialchars($buscar) ?>">
            <button class="btn btn-danger">Buscar</button>
          </div>
        </form>
        <?php if (empty($clientes)): ?>
        <div class="text-center text-muted py-4"><i class="bi bi-people fs-1"></i><p class="mt-2">No hay clientes registrados</p></div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover table-sm align-middle mb-0">
            <thead class="table-light">
              <tr><th>Nombre</th><th>RUT</th><th>Ciudad</th><th>Teléfono</th><th>Email</th><th>Acciones</th></tr>
            </thead>
            <tbody>
              <?php foreach ($clientes as $c): ?>
              <tr>
                <td class="fw-semibold"><?= htmlspecialchars($c['nombre']) ?></td>
                <td><small><?= htmlspecialchars($c['rut']) ?></small></td>
                <td><small><?= htmlspecialchars($c['ciudad']) ?></small></td>
                <td><small><?= htmlspecialchars($c['telefono']) ?></small></td>
                <td><small><?= htmlspecialchars($c['email']) ?></small></td>
                <td>
                  <a href="clientes.php?editar=<?= $c['id'] ?>" class="btn btn-sm btn-outline-warning" title="Editar"><i class="bi bi-pencil"></i></a>
                  <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este cliente?')">
                    <input type="hidden" name="action" value="eliminar">
                    <input type="hidden" name="cliente_id" value="<?= $c['id'] ?>">
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
