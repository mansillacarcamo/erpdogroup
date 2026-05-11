<?php
require_once 'config.php';
requireAuth();

$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'crear') {
    $codigo = trim($_POST['codigo'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    if (!$codigo) { $msg = ['danger', 'El código de obra es obligatorio']; }
    elseif (!$nombre) { $msg = ['danger', 'El nombre de la obra es obligatorio']; }
    else {
      try {
        $pdo->prepare("INSERT INTO obras (codigo, nombre, ciudad, mandante, contacto, telefono, email, estado) VALUES (?,?,?,?,?,?,?,?)")
          ->execute([
            $codigo, $nombre,
            trim($_POST['ciudad']??''),
            trim($_POST['mandante']??''),
            trim($_POST['contacto']??''),
            trim($_POST['telefono']??''),
            trim($_POST['email']??''),
            trim($_POST['estado'] ?? 'activa'),
          ]);
        $msg = ['success', 'Obra creada correctamente'];
      } catch (Exception $e) {
        $msg = ['danger', 'No se pudo crear la obra. ¿Código duplicado? ('.$e->getMessage().')'];
      }
    }
  }

  if ($action === 'editar') {
    $id = (int)($_POST['obra_id'] ?? 0);
    $codigo = trim($_POST['codigo'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    if ($id > 0 && $codigo && $nombre) {
      try {
        $pdo->prepare("UPDATE obras SET codigo=?, nombre=?, ciudad=?, mandante=?, contacto=?, telefono=?, email=?, estado=? WHERE id=?")
          ->execute([
            $codigo, $nombre,
            trim($_POST['ciudad']??''),
            trim($_POST['mandante']??''),
            trim($_POST['contacto']??''),
            trim($_POST['telefono']??''),
            trim($_POST['email']??''),
            trim($_POST['estado'] ?? 'activa'),
            $id,
          ]);
        $msg = ['success', 'Obra actualizada'];
      } catch (Exception $e) {
        $msg = ['danger', 'Error al actualizar: '.$e->getMessage()];
      }
    }
  }

  if ($action === 'eliminar') {
    $id = (int)($_POST['obra_id'] ?? 0);
    if ($id > 0) {
      $pdo->prepare("DELETE FROM obras WHERE id = ?")->execute([$id]);
      $msg = ['success', 'Obra eliminada'];
    }
  }
}

$buscar = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM obras";
$params = [];
if ($buscar !== '') {
  $sql .= " WHERE codigo LIKE ? OR nombre LIKE ? OR ciudad LIKE ? OR mandante LIKE ? OR contacto LIKE ?";
  $like = "%$buscar%";
  $params = [$like,$like,$like,$like,$like];
}
$sql .= " ORDER BY estado ASC, codigo ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$obras = $stmt->fetchAll(PDO::FETCH_ASSOC);

$editando = null;
if (isset($_GET['editar'])) {
  $stmtE = $pdo->prepare("SELECT * FROM obras WHERE id = ?");
  $stmtE->execute([(int)$_GET['editar']]);
  $editando = $stmtE->fetch(PDO::FETCH_ASSOC);
}

require_once 'includes/header.php';
?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-danger text-white">
        <h6 class="mb-0"><i class="bi bi-building-add me-2"></i><?= $editando ? 'Editar Obra' : 'Nueva Obra' ?></h6>
      </div>
      <div class="card-body">
        <?php if ($msg): ?><div class="alert alert-<?= $msg[0] ?> py-2"><?= htmlspecialchars($msg[1]) ?></div><?php endif; ?>
        <form method="POST">
          <input type="hidden" name="action" value="<?= $editando ? 'editar' : 'crear' ?>">
          <?php if ($editando): ?><input type="hidden" name="obra_id" value="<?= $editando['id'] ?>"><?php endif; ?>

          <div class="row g-2">
            <div class="col-5">
              <label class="form-label fw-semibold">Código *</label>
              <input type="text" name="codigo" class="form-control" placeholder="Ej: OB-001" value="<?= htmlspecialchars($editando['codigo'] ?? '') ?>" required>
            </div>
            <div class="col-7">
              <label class="form-label fw-semibold">Estado</label>
              <select name="estado" class="form-select">
                <?php $est = $editando['estado'] ?? 'activa'; ?>
                <option value="activa"     <?= $est==='activa'?'selected':'' ?>>Activa</option>
                <option value="pausada"    <?= $est==='pausada'?'selected':'' ?>>Pausada</option>
                <option value="finalizada" <?= $est==='finalizada'?'selected':'' ?>>Finalizada</option>
                <option value="cancelada"  <?= $est==='cancelada'?'selected':'' ?>>Cancelada</option>
              </select>
            </div>
          </div>

          <div class="mb-2 mt-2">
            <label class="form-label fw-semibold">Nombre de la Obra *</label>
            <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($editando['nombre'] ?? '') ?>" required>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Ciudad</label>
            <input type="text" name="ciudad" class="form-control" value="<?= htmlspecialchars($editando['ciudad'] ?? '') ?>">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Mandante</label>
            <input type="text" name="mandante" class="form-control" placeholder="Empresa o cliente final" value="<?= htmlspecialchars($editando['mandante'] ?? '') ?>">
          </div>

          <hr>
          <div class="text-muted small mb-2"><i class="bi bi-person-circle me-1"></i>Contacto en obra</div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Nombre de contacto</label>
            <input type="text" name="contacto" class="form-control" value="<?= htmlspecialchars($editando['contacto'] ?? '') ?>">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Fono</label>
            <input type="text" name="telefono" class="form-control" value="<?= htmlspecialchars($editando['telefono'] ?? '') ?>">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Email</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($editando['email'] ?? '') ?>">
          </div>

          <button class="btn btn-danger w-100"><i class="bi bi-save me-1"></i><?= $editando ? 'Actualizar Obra' : 'Guardar Obra' ?></button>
          <?php if ($editando): ?>
          <a href="obras.php" class="btn btn-outline-secondary w-100 mt-2">Cancelar</a>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-danger text-white">
        <h6 class="mb-0"><i class="bi bi-building me-2"></i>Obras registradas (<?= count($obras) ?>)</h6>
      </div>
      <div class="card-body p-3">
        <form class="mb-3" method="GET">
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" name="q" class="form-control" placeholder="Buscar por código, nombre, ciudad o mandante..." value="<?= htmlspecialchars($buscar) ?>">
            <button class="btn btn-danger">Buscar</button>
          </div>
        </form>
        <?php if (empty($obras)): ?>
        <div class="text-center text-muted py-4"><i class="bi bi-building fs-1"></i><p class="mt-2">No hay obras registradas</p></div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Ciudad</th>
                <th>Mandante</th>
                <th>Contacto</th>
                <th>Estado</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($obras as $o):
                $badge = ['activa'=>'success','pausada'=>'warning','finalizada'=>'secondary','cancelada'=>'danger'][$o['estado']] ?? 'secondary';
              ?>
              <tr>
                <td><span class="badge bg-dark"><?= htmlspecialchars($o['codigo']) ?></span></td>
                <td class="fw-semibold"><?= htmlspecialchars($o['nombre']) ?></td>
                <td><small><?= htmlspecialchars($o['ciudad']) ?></small></td>
                <td><small><?= htmlspecialchars($o['mandante']) ?></small></td>
                <td>
                  <small class="d-block"><?= htmlspecialchars($o['contacto']) ?></small>
                  <small class="text-muted"><?= htmlspecialchars($o['telefono']) ?></small>
                </td>
                <td><span class="badge bg-<?= $badge ?>"><?= ucfirst($o['estado']) ?></span></td>
                <td>
                  <a href="obras.php?editar=<?= $o['id'] ?>" class="btn btn-sm btn-outline-warning" title="Editar"><i class="bi bi-pencil"></i></a>
                  <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar esta obra?')">
                    <input type="hidden" name="action" value="eliminar">
                    <input type="hidden" name="obra_id" value="<?= $o['id'] ?>">
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
