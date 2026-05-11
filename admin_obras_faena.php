<?php
require_once 'config.php';
requireAuth();
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'crear') {
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));
        $nombre = trim($_POST['nombre'] ?? '');
        $ciudad = trim($_POST['ciudad'] ?? '');
        if ($codigo && $nombre) {
            try {
                $pdo->prepare("INSERT INTO obras_faena (codigo, nombre, ciudad) VALUES (?,?,?)")
                    ->execute([$codigo, $nombre, $ciudad]);
                flash('exito', 'Obra creada correctamente.');
            } catch(Exception $e) {
                flash('error', 'Error: '.$e->getMessage());
            }
        }
    }
    if ($accion === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $est = $_POST['estado'] ?? 'activa';
        $nuevo = $est === 'activa' ? 'inactiva' : 'activa';
        $pdo->prepare("UPDATE obras_faena SET estado=? WHERE id=?")->execute([$nuevo, $id]);
    }
    header('Location: admin_obras_faena.php'); exit;
}

$obras = $pdo->query("SELECT o.*, (SELECT COUNT(*) FROM gastos_faena g WHERE g.obra_id=o.id) AS total_gastos FROM obras_faena o ORDER BY o.estado DESC, o.codigo")->fetchAll();

$titulo = 'Obras — Gastos por Obra';
include 'includes/head.php';
include 'includes/nav.php';
?>
<a href="/inicio.php" data-volver data-fallback="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver</a>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="fw-bold mb-0"><i class="bi bi-building me-2 text-warning"></i>Obras · Gastos por Obra</h4>
  <button class="btn btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#modalNuevaObra">
    <i class="bi bi-plus-circle-fill me-1"></i>Nueva obra
  </button>
</div>
<?php if ($f = flash_get()): ?><div class="alert alert-<?= $f['tipo'] ?> alert-dismissible fade show"><?= h($f['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<div class="table-responsive">
  <table class="table table-hover align-middle">
    <thead class="table-dark">
      <tr><th>Código</th><th>Nombre</th><th>Ciudad</th><th>Gastos</th><th>Estado</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach($obras as $o): ?>
    <tr>
      <td><strong><?= h($o['codigo']) ?></strong></td>
      <td><?= h($o['nombre']) ?></td>
      <td><?= h($o['ciudad']) ?></td>
      <td><span class="badge bg-secondary"><?= $o['total_gastos'] ?></span></td>
      <td><span class="badge bg-<?= $o['estado']==='activa'?'success':'secondary' ?>"><?= ucfirst($o['estado']) ?></span></td>
      <td>
        <form method="POST" class="d-inline">
          <input type="hidden" name="accion" value="toggle">
          <input type="hidden" name="id" value="<?= $o['id'] ?>">
          <input type="hidden" name="estado" value="<?= $o['estado'] ?>">
          <button class="btn btn-sm btn-outline-secondary"><?= $o['estado']==='activa'?'Desactivar':'Activar' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="modal fade" id="modalNuevaObra" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header fw-bold" style="background:#d97706;color:#fff">
        <h5 class="modal-title"><i class="bi bi-building me-2"></i>Nueva Obra</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="accion" value="crear">
        <div class="modal-body row g-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Código *</label>
            <input type="text" name="codigo" class="form-control text-uppercase" placeholder="OB-001" required>
          </div>
          <div class="col-md-8">
            <label class="form-label fw-semibold">Nombre *</label>
            <input type="text" name="nombre" class="form-control" placeholder="Nombre de la obra" required>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Ciudad</label>
            <input type="text" name="ciudad" class="form-control" placeholder="Ciudad donde está la obra">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-warning fw-bold">Crear obra</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php include 'includes/foot.php'; ?>
