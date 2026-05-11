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
<a href="/inicio.php" data-volver data-fallback="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver</a>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="fw-bold mb-0"><i class="bi bi-truck me-2 text-danger"></i>Proveedores registrados (<?= count($proveedores) ?>)</h4>
  <button type="button" class="btn btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#modalProveedor" data-modo="crear">
    <i class="bi bi-plus-circle-fill me-1"></i>Nuevo proveedor
  </button>
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msg[0] ?> alert-dismissible fade show"><?= htmlspecialchars($msg[1]) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card shadow-sm border-0">
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
              <button type="button" class="btn btn-sm btn-outline-warning btn-editar-proveedor" title="Editar"
                data-bs-toggle="modal" data-bs-target="#modalProveedor"
                data-id="<?= $p['id'] ?>"
                data-nombre="<?= htmlspecialchars($p['nombre'], ENT_QUOTES) ?>"
                data-rut="<?= htmlspecialchars($p['rut'], ENT_QUOTES) ?>"
                data-giro="<?= htmlspecialchars($p['giro'], ENT_QUOTES) ?>"
                data-direccion="<?= htmlspecialchars($p['direccion'], ENT_QUOTES) ?>"
                data-ciudad="<?= htmlspecialchars($p['ciudad'], ENT_QUOTES) ?>"
                data-telefono="<?= htmlspecialchars($p['telefono'], ENT_QUOTES) ?>"
                data-email="<?= htmlspecialchars($p['email'], ENT_QUOTES) ?>"
                data-contacto="<?= htmlspecialchars($p['contacto'], ENT_QUOTES) ?>"
                data-banco="<?= htmlspecialchars($p['banco'], ENT_QUOTES) ?>"
                data-tipo_cuenta="<?= htmlspecialchars($p['tipo_cuenta'], ENT_QUOTES) ?>"
                data-numero_cuenta="<?= htmlspecialchars($p['numero_cuenta'], ENT_QUOTES) ?>"
                data-forma_pago="<?= htmlspecialchars($p['forma_pago'], ENT_QUOTES) ?>">
                <i class="bi bi-pencil"></i>
              </button>
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

<div class="modal fade" id="modalProveedor" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-truck me-2"></i><span id="modalProveedorTitulo">Nuevo Proveedor</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" id="modalProveedorAction" value="crear">
        <input type="hidden" name="proveedor_id" id="modalProveedorId" value="">
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label fw-semibold">Razón Social *</label>
            <input type="text" name="nombre" id="modalProveedorNombre" class="form-control" required>
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label fw-semibold">RUT</label>
              <input type="text" name="rut" id="modalProveedorRut" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Giro</label>
              <input type="text" name="giro" id="modalProveedorGiro" class="form-control">
            </div>
          </div>
          <div class="mb-2 mt-2">
            <label class="form-label fw-semibold">Dirección</label>
            <input type="text" name="direccion" id="modalProveedorDireccion" class="form-control">
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Ciudad</label>
              <input type="text" name="ciudad" id="modalProveedorCiudad" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Teléfono</label>
              <input type="text" name="telefono" id="modalProveedorTelefono" class="form-control">
            </div>
          </div>
          <div class="row g-2 mt-2">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="modalProveedorEmail" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Contacto comercial</label>
              <input type="text" name="contacto" id="modalProveedorContacto" class="form-control">
            </div>
          </div>

          <hr>
          <div class="text-muted small mb-2"><i class="bi bi-bank me-1"></i>Datos para pago</div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Banco</label>
              <input type="text" name="banco" id="modalProveedorBanco" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Tipo cuenta</label>
              <select name="tipo_cuenta" id="modalProveedorTipoCuenta" class="form-select">
                <option value="">--</option>
                <option value="Cta. Corriente">Cta. Corriente</option>
                <option value="Cta. Vista">Cta. Vista</option>
                <option value="Cta. Ahorro">Cta. Ahorro</option>
                <option value="Cta. RUT">Cta. RUT</option>
              </select>
            </div>
          </div>
          <div class="row g-2 mt-2">
            <div class="col-md-7">
              <label class="form-label fw-semibold">N° Cuenta</label>
              <input type="text" name="numero_cuenta" id="modalProveedorNumeroCuenta" class="form-control">
            </div>
            <div class="col-md-5">
              <label class="form-label fw-semibold">Forma de pago</label>
              <select name="forma_pago" id="modalProveedorFormaPago" class="form-select">
                <option value="">--</option>
                <option value="Contado">Contado</option>
                <option value="30 días">30 días</option>
                <option value="45 días">45 días</option>
                <option value="60 días">60 días</option>
                <option value="90 días">90 días</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger fw-bold"><i class="bi bi-save me-1"></i><span id="modalProveedorBtn">Guardar Proveedor</span></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('modalProveedor');
  if (!modal) return;
  const titulo = document.getElementById('modalProveedorTitulo');
  const btnLbl = document.getElementById('modalProveedorBtn');
  const action = document.getElementById('modalProveedorAction');
  const fId    = document.getElementById('modalProveedorId');
  const fNom   = document.getElementById('modalProveedorNombre');
  const fRut   = document.getElementById('modalProveedorRut');
  const fGiro  = document.getElementById('modalProveedorGiro');
  const fDir   = document.getElementById('modalProveedorDireccion');
  const fCiu   = document.getElementById('modalProveedorCiudad');
  const fTel   = document.getElementById('modalProveedorTelefono');
  const fMail  = document.getElementById('modalProveedorEmail');
  const fCon   = document.getElementById('modalProveedorContacto');
  const fBan   = document.getElementById('modalProveedorBanco');
  const fTC    = document.getElementById('modalProveedorTipoCuenta');
  const fNC    = document.getElementById('modalProveedorNumeroCuenta');
  const fFP    = document.getElementById('modalProveedorFormaPago');

  modal.addEventListener('show.bs.modal', function(ev){
    const trigger = ev.relatedTarget;
    const esEditar = trigger && trigger.classList.contains('btn-editar-proveedor');
    if (esEditar) {
      titulo.textContent = 'Editar Proveedor';
      btnLbl.textContent = 'Actualizar Proveedor';
      action.value = 'editar';
      fId.value   = trigger.dataset.id || '';
      fNom.value  = trigger.dataset.nombre || '';
      fRut.value  = trigger.dataset.rut || '';
      fGiro.value = trigger.dataset.giro || '';
      fDir.value  = trigger.dataset.direccion || '';
      fCiu.value  = trigger.dataset.ciudad || '';
      fTel.value  = trigger.dataset.telefono || '';
      fMail.value = trigger.dataset.email || '';
      fCon.value  = trigger.dataset.contacto || '';
      fBan.value  = trigger.dataset.banco || '';
      fTC.value   = trigger.dataset.tipo_cuenta || '';
      fNC.value   = trigger.dataset.numero_cuenta || '';
      fFP.value   = trigger.dataset.forma_pago || '';
    } else {
      titulo.textContent = 'Nuevo Proveedor';
      btnLbl.textContent = 'Guardar Proveedor';
      action.value = 'crear';
      fId.value = '';
      fNom.value = ''; fRut.value = ''; fGiro.value = '';
      fDir.value = ''; fCiu.value = ''; fTel.value = '';
      fMail.value = ''; fCon.value = ''; fBan.value = '';
      fTC.value = ''; fNC.value = ''; fFP.value = '';
    }
  });

  <?php if ($editando): ?>
  document.addEventListener('DOMContentLoaded', function(){
    const btn = document.querySelector('.btn-editar-proveedor[data-id="<?= (int)$editando['id'] ?>"]');
    if (btn) btn.click();
  });
  <?php endif; ?>
})();
</script>

<?php require_once 'includes/footer.php'; ?>
