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
<a href="/inicio.php" data-volver data-fallback="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver</a>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="fw-bold mb-0"><i class="bi bi-building me-2 text-danger"></i>Obras registradas (<?= count($obras) ?>)</h4>
  <button type="button" class="btn btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#modalObra" data-modo="crear">
    <i class="bi bi-plus-circle-fill me-1"></i>Nueva obra
  </button>
</div>

<?php if ($msg): ?><div class="alert alert-<?= $msg[0] ?> alert-dismissible fade show"><?= htmlspecialchars($msg[1]) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="card shadow-sm border-0">
  <div class="card-body p-3">
    <form class="mb-2" method="GET">
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="text" name="q" class="form-control" placeholder="Buscar por código, nombre, ciudad o mandante..." value="<?= htmlspecialchars($buscar) ?>">
        <button class="btn btn-danger">Buscar</button>
      </div>
    </form>
    <div class="alert alert-info py-2 px-3 mb-3 small"><i class="bi bi-info-circle me-1"></i><strong>Esta es la obra ID</strong> — el identificador (columna <span class="badge bg-primary"><i class="bi bi-hash"></i>ID Obra</span>) que vincula todas las OC, gastos, tickets, facturas y cotizaciones a este proyecto.</div>
    <?php if (empty($obras)): ?>
    <div class="text-center text-muted py-4"><i class="bi bi-building fs-1"></i><p class="mt-2">No hay obras registradas</p></div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th title="Identificador único de obra usado por todo el sistema">ID Obra</th>
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
            <td><span class="badge bg-primary" title="Esta es la obra ID — se usa en todas las OC, gastos, tickets, etc."><i class="bi bi-hash"></i><?= (int)$o['id'] ?></span></td>
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
              <button type="button" class="btn btn-sm btn-outline-warning btn-editar-obra" title="Editar"
                data-bs-toggle="modal" data-bs-target="#modalObra"
                data-id="<?= $o['id'] ?>"
                data-codigo="<?= htmlspecialchars($o['codigo'], ENT_QUOTES) ?>"
                data-nombre="<?= htmlspecialchars($o['nombre'], ENT_QUOTES) ?>"
                data-ciudad="<?= htmlspecialchars($o['ciudad'], ENT_QUOTES) ?>"
                data-mandante="<?= htmlspecialchars($o['mandante'], ENT_QUOTES) ?>"
                data-contacto="<?= htmlspecialchars($o['contacto'], ENT_QUOTES) ?>"
                data-telefono="<?= htmlspecialchars($o['telefono'], ENT_QUOTES) ?>"
                data-email="<?= htmlspecialchars($o['email'], ENT_QUOTES) ?>"
                data-estado="<?= htmlspecialchars($o['estado'], ENT_QUOTES) ?>">
                <i class="bi bi-pencil"></i>
              </button>
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

<div class="modal fade" id="modalObra" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-building-add me-2"></i><span id="modalObraTitulo">Nueva Obra</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" id="modalObraAction" value="crear">
        <input type="hidden" name="obra_id" id="modalObraId" value="">
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-5">
              <label class="form-label fw-semibold">Código *</label>
              <input type="text" name="codigo" id="modalObraCodigo" class="form-control" placeholder="Ej: OB-001" required>
            </div>
            <div class="col-md-7">
              <label class="form-label fw-semibold">Estado</label>
              <select name="estado" id="modalObraEstado" class="form-select">
                <option value="activa">Activa</option>
                <option value="pausada">Pausada</option>
                <option value="finalizada">Finalizada</option>
                <option value="cancelada">Cancelada</option>
              </select>
            </div>
          </div>
          <div class="mb-2 mt-2">
            <label class="form-label fw-semibold">Nombre de la Obra *</label>
            <input type="text" name="nombre" id="modalObraNombre" class="form-control" required>
          </div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Ciudad</label>
              <input type="text" name="ciudad" id="modalObraCiudad" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Mandante</label>
              <input type="text" name="mandante" id="modalObraMandante" class="form-control" placeholder="Empresa o cliente final">
            </div>
          </div>
          <hr>
          <div class="text-muted small mb-2"><i class="bi bi-person-circle me-1"></i>Contacto en obra</div>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Nombre de contacto</label>
              <input type="text" name="contacto" id="modalObraContacto" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Fono</label>
              <input type="text" name="telefono" id="modalObraTelefono" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="modalObraEmail" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger fw-bold"><i class="bi bi-save me-1"></i><span id="modalObraBtn">Guardar Obra</span></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const modal = document.getElementById('modalObra');
  if (!modal) return;
  const titulo = document.getElementById('modalObraTitulo');
  const btnLbl = document.getElementById('modalObraBtn');
  const action = document.getElementById('modalObraAction');
  const fId    = document.getElementById('modalObraId');
  const fCod   = document.getElementById('modalObraCodigo');
  const fNom   = document.getElementById('modalObraNombre');
  const fCiu   = document.getElementById('modalObraCiudad');
  const fMan   = document.getElementById('modalObraMandante');
  const fCon   = document.getElementById('modalObraContacto');
  const fTel   = document.getElementById('modalObraTelefono');
  const fMail  = document.getElementById('modalObraEmail');
  const fEst   = document.getElementById('modalObraEstado');

  modal.addEventListener('show.bs.modal', function(ev){
    const trigger = ev.relatedTarget;
    const esEditar = trigger && trigger.classList.contains('btn-editar-obra');
    if (esEditar) {
      titulo.textContent = 'Editar Obra';
      btnLbl.textContent = 'Actualizar Obra';
      action.value = 'editar';
      fId.value   = trigger.dataset.id || '';
      fCod.value  = trigger.dataset.codigo || '';
      fNom.value  = trigger.dataset.nombre || '';
      fCiu.value  = trigger.dataset.ciudad || '';
      fMan.value  = trigger.dataset.mandante || '';
      fCon.value  = trigger.dataset.contacto || '';
      fTel.value  = trigger.dataset.telefono || '';
      fMail.value = trigger.dataset.email || '';
      fEst.value  = trigger.dataset.estado || 'activa';
    } else {
      titulo.textContent = 'Nueva Obra';
      btnLbl.textContent = 'Guardar Obra';
      action.value = 'crear';
      fId.value = '';
      fCod.value = ''; fNom.value = ''; fCiu.value = '';
      fMan.value = ''; fCon.value = ''; fTel.value = '';
      fMail.value = ''; fEst.value = 'activa';
    }
  });

  <?php if ($editando): ?>
  document.addEventListener('DOMContentLoaded', function(){
    const btn = document.querySelector('.btn-editar-obra[data-id="<?= (int)$editando['id'] ?>"]');
    if (btn) btn.click();
  });
  <?php elseif (isset($_GET['nuevo'])): ?>
  document.addEventListener('DOMContentLoaded', function(){
    const m = bootstrap.Modal.getOrCreateInstance(modal);
    m.show();
  });
  <?php endif; ?>
})();
</script>

<?php require_once 'includes/footer.php'; ?>
