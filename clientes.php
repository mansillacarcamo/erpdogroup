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
<a href="/inicio.php" data-volver data-fallback="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver</a>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="fw-bold mb-0"><i class="bi bi-people me-2 text-danger"></i>Clientes registrados (<?= count($clientes) ?>)</h4>
  <button type="button" class="btn btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#modalCliente" data-modo="crear">
    <i class="bi bi-plus-circle-fill me-1"></i>Nuevo cliente
  </button>
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msg[0] ?> alert-dismissible fade show"><?= $msg[1] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card shadow-sm border-0">
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
              <button type="button" class="btn btn-sm btn-outline-warning btn-editar-cliente" title="Editar"
                data-bs-toggle="modal" data-bs-target="#modalCliente"
                data-id="<?= $c['id'] ?>"
                data-nombre="<?= htmlspecialchars($c['nombre'], ENT_QUOTES) ?>"
                data-rut="<?= htmlspecialchars($c['rut'], ENT_QUOTES) ?>"
                data-direccion="<?= htmlspecialchars($c['direccion'], ENT_QUOTES) ?>"
                data-ciudad="<?= htmlspecialchars($c['ciudad'], ENT_QUOTES) ?>"
                data-telefono="<?= htmlspecialchars($c['telefono'], ENT_QUOTES) ?>"
                data-email="<?= htmlspecialchars($c['email'], ENT_QUOTES) ?>"
                data-contacto="<?= htmlspecialchars($c['contacto'], ENT_QUOTES) ?>">
                <i class="bi bi-pencil"></i>
              </button>
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

<div class="modal fade" id="modalCliente" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i><span id="modalClienteTitulo">Nuevo Cliente</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" id="modalClienteAction" value="crear">
        <input type="hidden" name="cliente_id" id="modalClienteId" value="">
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Nombre / Razón Social *</label>
              <input type="text" name="nombre" id="modalClienteNombre" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">RUT</label>
              <input type="text" name="rut" id="modalClienteRut" class="form-control">
            </div>
          </div>
          <div class="row g-2 mt-1">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Dirección</label>
              <input type="text" name="direccion" id="modalClienteDireccion" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Ciudad</label>
              <input type="text" name="ciudad" id="modalClienteCiudad" class="form-control">
            </div>
          </div>
          <div class="row g-2 mt-1">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Teléfono</label>
              <input type="text" name="telefono" id="modalClienteTelefono" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="modalClienteEmail" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Contacto (ATN)</label>
              <input type="text" name="contacto" id="modalClienteContacto" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger fw-bold"><i class="bi bi-save me-1"></i><span id="modalClienteBtn">Guardar</span></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('modalCliente');
  if (!modal) return;
  const titulo = document.getElementById('modalClienteTitulo');
  const btnLbl = document.getElementById('modalClienteBtn');
  const action = document.getElementById('modalClienteAction');
  const fId    = document.getElementById('modalClienteId');
  const fNom   = document.getElementById('modalClienteNombre');
  const fRut   = document.getElementById('modalClienteRut');
  const fDir   = document.getElementById('modalClienteDireccion');
  const fCiu   = document.getElementById('modalClienteCiudad');
  const fTel   = document.getElementById('modalClienteTelefono');
  const fMail  = document.getElementById('modalClienteEmail');
  const fCon   = document.getElementById('modalClienteContacto');

  modal.addEventListener('show.bs.modal', function(ev){
    const trigger = ev.relatedTarget;
    const esEditar = trigger && trigger.classList.contains('btn-editar-cliente');
    if (esEditar) {
      titulo.textContent = 'Editar Cliente';
      btnLbl.textContent = 'Actualizar';
      action.value = 'editar';
      fId.value   = trigger.dataset.id || '';
      fNom.value  = trigger.dataset.nombre || '';
      fRut.value  = trigger.dataset.rut || '';
      fDir.value  = trigger.dataset.direccion || '';
      fCiu.value  = trigger.dataset.ciudad || '';
      fTel.value  = trigger.dataset.telefono || '';
      fMail.value = trigger.dataset.email || '';
      fCon.value  = trigger.dataset.contacto || '';
    } else {
      titulo.textContent = 'Nuevo Cliente';
      btnLbl.textContent = 'Guardar';
      action.value = 'crear';
      fId.value = '';
      fNom.value = ''; fRut.value = ''; fDir.value = '';
      fCiu.value = ''; fTel.value = ''; fMail.value = ''; fCon.value = '';
    }
  });

  <?php if ($editando): ?>
  document.addEventListener('DOMContentLoaded', function(){
    const btn = document.querySelector('.btn-editar-cliente[data-id="<?= (int)$editando['id'] ?>"]');
    if (btn) btn.click();
  });
  <?php endif; ?>
})();
</script>

<?php require_once 'includes/footer.php'; ?>
