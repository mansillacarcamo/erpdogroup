<?php
require_once 'config.php';
requireRol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc = $_POST['accion'] ?? '';
    if ($acc === 'crear') {
        $n = trim($_POST['nombre'] ?? '');
        if ($n) {
            try {
                $pdo->prepare("INSERT INTO categorias_gasto (nombre,icono,color) VALUES (?,?,?)")
                    ->execute([$n, $_POST['icono'] ?? 'bi-tag', $_POST['color'] ?? '#3b82f6']);
                flash('exito','Categoría creada.');
            } catch (Exception $e) { flash('error','Ya existe esa categoría.'); }
        }
    } elseif ($acc === 'toggle') {
        $id = (int)$_POST['id'];
        $pdo->prepare("UPDATE categorias_gasto SET activo = 1 - activo WHERE id=?")->execute([$id]);
    } elseif ($acc === 'borrar') {
        $pdo->prepare("DELETE FROM categorias_gasto WHERE id=?")->execute([(int)$_POST['id']]);
        flash('exito','Categoría eliminada.');
    }
    header('Location: admin_categorias.php'); exit;
}

$cats = $pdo->query("SELECT * FROM categorias_gasto ORDER BY nombre")->fetchAll();

$titulo = 'Categorías';
include 'includes/head.php'; include 'includes/nav.php';
?>
<h4 class="mb-3"><i class="bi bi-tags me-2"></i>Categorías de gasto</h4>
<div class="row g-3">
  <div class="col-12 col-md-4">
    <div class="card"><div class="card-header">Nueva categoría</div><div class="card-body">
      <form method="post">
        <input type="hidden" name="accion" value="crear">
        <div class="mb-2"><label class="form-label">Nombre</label><input name="nombre" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Ícono (Bootstrap Icons)</label><input name="icono" class="form-control" placeholder="bi-tag" value="bi-tag"></div>
        <div class="mb-2"><label class="form-label">Color</label><input name="color" type="color" class="form-control form-control-color" value="#3b82f6"></div>
        <button class="btn btn-primary w-100"><i class="bi bi-plus"></i> Crear</button>
      </form>
    </div></div>
  </div>
  <div class="col-12 col-md-8">
    <div class="card"><div class="card-body p-0">
      <table class="table mb-0 align-middle">
        <thead class="table-light"><tr><th>Nombre</th><th>Ícono</th><th>Color</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td><i class="bi <?= h($c['icono']) ?>" style="color: <?= h($c['color']) ?>"></i> <?= h($c['nombre']) ?></td>
            <td class="small text-muted"><?= h($c['icono']) ?></td>
            <td><span class="badge" style="background: <?= h($c['color']) ?>"><?= h($c['color']) ?></span></td>
            <td><?= $c['activo'] ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-secondary">Inactiva</span>' ?></td>
            <td>
              <form method="post" class="d-inline"><input type="hidden" name="accion" value="toggle"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-toggle-on"></i></button></form>
              <form method="post" class="d-inline"><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" data-confirm="¿Eliminar?"><i class="bi bi-trash"></i></button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>
<?php include 'includes/foot.php'; ?>
