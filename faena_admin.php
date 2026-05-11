<?php
/**
 * Administración del módulo Gastos / Faena
 * - Admin / Gerente Finanzas: pueden todo (supervisores, operadores, presupuestos)
 * - Gerente Comercial / Gerente Finanzas: presupuestos a supervisores
 */
require_once 'config.php';
requireAuth();

$rol = $usuario['rol'] ?? '';
$esAdmin   = $rol === 'admin';
$esGerente = in_array($rol, ['admin','gerente_finanzas','gerente_comercial']);
if (!$esGerente) { header('Location: inicio.php'); exit; }

$tab = $_GET['tab'] ?? ($esAdmin ? 'supervisores' : 'presupuestos');
$msg = null; $msgTipo = 'success';
if (!empty($_GET['msg'])) { $msg = $_GET['msg']; $msgTipo = $_GET['mt'] ?? 'success'; }

/* ══ POST ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';
  try {

    /* ── SUPERVISORES (vincula usuario_id ↔ obra) ── */
    if ($accion === 'crear_supervisor') {
      if (!$esAdmin) throw new Exception('Solo admin.');
      $uid = (int)($_POST['usuario_id'] ?? 0);
      $oid = (int)($_POST['obra_id']    ?? 0);
      if (!$uid || !$oid) throw new Exception('Selecciona usuario y obra.');
      $exist = $pdo->prepare("SELECT id FROM obra_supervisores WHERE usuario_id=? AND obra_id=?");
      $exist->execute([$uid, $oid]);
      if ($exist->fetchColumn()) throw new Exception('Ese usuario ya es supervisor de esa obra.');
      $pdo->prepare("INSERT INTO obra_supervisores (obra_id, usuario_id, asignado_por, activo) VALUES (?,?,?,1)")
          ->execute([$oid, $uid, $usuario['id']]);
      $msg = 'Supervisor agregado.';
    }
    if ($accion === 'toggle_supervisor') {
      if (!$esAdmin) throw new Exception('Solo admin.');
      $id = (int)$_POST['id'];
      $pdo->prepare("UPDATE obra_supervisores SET activo = 1 - activo WHERE id=?")->execute([$id]);
      $msg = 'Estado actualizado.';
    }
    if ($accion === 'eliminar_supervisor') {
      if (!$esAdmin) throw new Exception('Solo admin.');
      $id = (int)$_POST['id'];
      $pdo->prepare("DELETE FROM obra_supervisores WHERE id=?")->execute([$id]);
      $msg = 'Supervisor eliminado.';
    }

    /* ── OPERADORES ── */
    if ($accion === 'crear_operador') {
      if (!$esAdmin) throw new Exception('Solo admin.');
      $nom = trim($_POST['nombre'] ?? '');
      $usu = trim($_POST['usuario'] ?? '');
      $pass= trim($_POST['password'] ?? '');
      if (!$nom || !$usu || !$pass) throw new Exception('Nombre, usuario y contraseña son obligatorios.');
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $pdo->prepare("INSERT INTO faena_operadores (nombre,rut,usuario,password_hash,email,fono,obra_id,supervisor_id,activo) VALUES (?,?,?,?,?,?,?,?,1)")
          ->execute([$nom, trim($_POST['rut'] ?? ''), $usu, $hash, trim($_POST['email'] ?? ''), trim($_POST['fono'] ?? ''),
                     (int)($_POST['obra_id'] ?? 0) ?: null, (int)($_POST['supervisor_id'] ?? 0) ?: null]);
      $msg = "Operador '$nom' creado.";
    }
    if ($accion === 'editar_operador') {
      if (!$esAdmin) throw new Exception('Solo admin.');
      $id = (int)$_POST['id'];
      $pdo->prepare("UPDATE faena_operadores SET nombre=?,rut=?,email=?,fono=?,obra_id=?,supervisor_id=?,usuario=? WHERE id=?")
          ->execute([trim($_POST['nombre'] ?? ''), trim($_POST['rut'] ?? ''), trim($_POST['email'] ?? ''), trim($_POST['fono'] ?? ''),
                     (int)($_POST['obra_id'] ?? 0) ?: null, (int)($_POST['supervisor_id'] ?? 0) ?: null,
                     trim($_POST['usuario'] ?? ''), $id]);
      if (!empty(trim($_POST['password'] ?? ''))) {
        $hash = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE faena_operadores SET password_hash=? WHERE id=?")->execute([$hash, $id]);
      }
      $msg = 'Operador actualizado.';
    }
    if ($accion === 'toggle_operador') {
      if (!$esAdmin) throw new Exception('Solo admin.');
      $id = (int)$_POST['id'];
      $pdo->prepare("UPDATE faena_operadores SET activo = 1 - activo WHERE id=?")->execute([$id]);
      $msg = 'Estado actualizado.';
    }
    if ($accion === 'eliminar_operador') {
      if (!$esAdmin) throw new Exception('Solo admin.');
      $id = (int)$_POST['id'];
      $pdo->prepare("DELETE FROM faena_operadores WHERE id=?")->execute([$id]);
      $msg = 'Operador eliminado.';
    }

    /* ── PRESUPUESTOS (gerente asigna $ a supervisor por obra/mes) ── */
    if ($accion === 'crear_presupuesto') {
      $supId = (int)($_POST['supervisor_id'] ?? 0);
      $obraId = (int)($_POST['obra_id'] ?? 0);
      $mes   = trim($_POST['mes'] ?? date('Y-m'));
      $monto = (float)str_replace(['.','$',' '],['','',''], $_POST['monto'] ?? '0');
      [$anio, $mesN] = array_pad(explode('-', $mes), 2, 0);
      $anio = (int)$anio; $mesN = (int)$mesN;
      if (!$supId || !$obraId || !$anio || !$mesN || $monto <= 0) throw new Exception('Completa todos los campos con un monto válido.');

      $exist = $pdo->prepare("SELECT id FROM faena_presupuestos WHERE supervisor_id=? AND obra_id=? AND anio=? AND mes=?");
      $exist->execute([$supId, $obraId, $anio, $mesN]);
      if ($pid = $exist->fetchColumn()) {
        $pdo->prepare("UPDATE faena_presupuestos SET monto_asignado=?, asignado_por=? WHERE id=?")
            ->execute([$monto, $usuario['id'], $pid]);
        $msg = 'Presupuesto actualizado.';
      } else {
        $pdo->prepare("INSERT INTO faena_presupuestos (supervisor_id, obra_id, anio, mes, monto_asignado, asignado_por) VALUES (?,?,?,?,?,?)")
            ->execute([$supId, $obraId, $anio, $mesN, $monto, $usuario['id']]);
        $msg = 'Presupuesto creado.';
      }
    }
    if ($accion === 'eliminar_presupuesto') {
      $id = (int)$_POST['id'];
      $pdo->prepare("DELETE FROM faena_presupuestos WHERE id=?")->execute([$id]);
      $msg = 'Presupuesto eliminado.';
    }

  } catch(Exception $e) { $msg = 'Error: '.$e->getMessage(); $msgTipo = 'danger'; }
  header("Location: faena_admin.php?tab=$tab&msg=".urlencode($msg)."&mt=$msgTipo"); exit;
}

/* ══ Datos ══ */
$obras = $pdo->query("SELECT id, codigo, nombre FROM obras ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);
$usuariosTodos = $pdo->query("SELECT id, nombre, usuario, rol FROM usuarios ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

$supervisores = $pdo->query("
  SELECT s.*, u.nombre AS usuario_nombre, u.usuario AS usuario_login, u.cargo,
         o.codigo AS obra_codigo, o.nombre AS obra_nombre
  FROM obra_supervisores s
  JOIN usuarios u ON u.id = s.usuario_id
  JOIN obras    o ON o.id = s.obra_id
  ORDER BY s.activo DESC, u.nombre, o.codigo
")->fetchAll(PDO::FETCH_ASSOC);

$operadores = $pdo->query("
  SELECT op.*, o.codigo AS obra_codigo, o.nombre AS obra_nombre,
         u.nombre AS supervisor_nombre
  FROM faena_operadores op
  LEFT JOIN obras o     ON o.id = op.obra_id
  LEFT JOIN usuarios u  ON u.id = op.supervisor_id
  ORDER BY op.activo DESC, op.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$mesFiltro = $_GET['mes'] ?? date('Y-m');
[$anioF, $mesNF] = array_pad(explode('-', $mesFiltro), 2, 0);
$presupuestos = $pdo->prepare("
  SELECT fp.*, u.nombre AS supervisor_nombre, u.cargo,
         o.codigo AS obra_codigo, o.nombre AS obra_nombre,
         (SELECT COALESCE(SUM(monto_asignado),0) FROM faena_asignaciones WHERE presupuesto_id=fp.id AND activa=1) AS total_repartido
  FROM faena_presupuestos fp
  JOIN usuarios u ON u.id = fp.supervisor_id
  JOIN obras    o ON o.id = fp.obra_id
  WHERE fp.anio=? AND fp.mes=?
  ORDER BY o.codigo, u.nombre
");
$presupuestos->execute([(int)$anioF, (int)$mesNF]);
$presupuestos = $presupuestos->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>
<a href="inicio.php" class="btn btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Volver</a>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h3 class="fw-bold mb-0"><i class="bi bi-gear-fill text-warning me-2"></i>Administración Gastos / Faena</h3>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3">
  <?php if ($esAdmin): ?>
  <li class="nav-item"><a class="nav-link <?= $tab==='supervisores'?'active':'' ?>" href="?tab=supervisores"><i class="bi bi-person-badge me-1"></i>Supervisores</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab==='operadores'?'active':'' ?>" href="?tab=operadores"><i class="bi bi-tools me-1"></i>Operadores</a></li>
  <?php endif; ?>
  <li class="nav-item"><a class="nav-link <?= $tab==='presupuestos'?'active':'' ?>" href="?tab=presupuestos"><i class="bi bi-cash-stack me-1"></i>Presupuestos</a></li>
</ul>

<?php /* ──────────────── SUPERVISORES ──────────────── */ ?>
<?php if ($tab === 'supervisores' && $esAdmin): ?>
<div class="card shadow-sm mb-4">
  <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-person-badge me-1"></i>Supervisores de obra</strong>
    <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#mSup"><i class="bi bi-plus-lg me-1"></i>Asignar supervisor</button>
  </div>
  <div class="card-body p-0">
    <?php if (empty($supervisores)): ?>
    <p class="text-center text-muted py-4 mb-0">Sin supervisores asignados.</p>
    <?php else: ?>
    <table class="table table-hover mb-0">
      <thead class="table-light"><tr><th>Usuario</th><th>Cargo</th><th>Obra</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($supervisores as $s): ?>
      <tr class="<?= !$s['activo']?'text-muted opacity-60':'' ?>">
        <td><strong><?= htmlspecialchars($s['usuario_nombre']) ?></strong> <small class="text-muted">@<?= htmlspecialchars($s['usuario_login']) ?></small></td>
        <td><small><?= htmlspecialchars($s['cargo'] ?: '—') ?></small></td>
        <td><small><strong><?= htmlspecialchars($s['obra_codigo']) ?></strong> — <?= htmlspecialchars($s['obra_nombre']) ?></small></td>
        <td><?= $s['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
        <td class="text-end">
          <form method="POST" class="d-inline"><input type="hidden" name="accion" value="toggle_supervisor"><input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-<?= $s['activo']?'pause':'play' ?>-fill"></i></button></form>
          <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar?')"><input type="hidden" name="accion" value="eliminar_supervisor"><input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="mSup" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST">
    <div class="modal-header bg-warning text-dark"><h5 class="modal-title"><i class="bi bi-person-badge me-2"></i>Asignar Supervisor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="accion" value="crear_supervisor">
      <div class="mb-3"><label class="form-label">Usuario *</label>
        <select name="usuario_id" class="form-select" required>
          <option value="">— Seleccionar —</option>
          <?php foreach ($usuariosTodos as $u): ?>
          <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?> (<?= htmlspecialchars($u['rol']) ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <div class="mb-3"><label class="form-label">Obra *</label>
        <select name="obra_id" class="form-select" required>
          <option value="">— Seleccionar —</option>
          <?php foreach ($obras as $o): ?><option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button><button class="btn btn-warning"><i class="bi bi-check-lg me-1"></i>Asignar</button></div>
  </form>
</div></div></div>
<?php endif; ?>

<?php /* ──────────────── OPERADORES ──────────────── */ ?>
<?php if ($tab === 'operadores' && $esAdmin): ?>
<div class="card shadow-sm mb-4">
  <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-tools me-1"></i>Operadores</strong>
    <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#mOp"><i class="bi bi-plus-lg me-1"></i>Crear operador</button>
  </div>
  <div class="card-body p-0">
    <?php if (empty($operadores)): ?>
    <p class="text-center text-muted py-4 mb-0">Sin operadores registrados.</p>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover mb-0">
      <thead class="table-light"><tr><th>Nombre</th><th>RUT</th><th>Obra</th><th>Supervisor</th><th>Usuario</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($operadores as $op): ?>
      <tr class="<?= !$op['activo']?'text-muted opacity-60':'' ?>">
        <td><strong><?= htmlspecialchars($op['nombre']) ?></strong></td>
        <td><small><?= htmlspecialchars($op['rut'] ?: '—') ?></small></td>
        <td><small><?= $op['obra_codigo'] ? '<strong>'.htmlspecialchars($op['obra_codigo']).'</strong> — '.htmlspecialchars($op['obra_nombre']) : '—' ?></small></td>
        <td><small><?= htmlspecialchars($op['supervisor_nombre'] ?: '—') ?></small></td>
        <td><code><?= htmlspecialchars($op['usuario']) ?></code></td>
        <td><?= $op['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-info" onclick='editOp(<?= json_encode($op) ?>)'><i class="bi bi-pencil"></i></button>
          <form method="POST" class="d-inline"><input type="hidden" name="accion" value="toggle_operador"><input type="hidden" name="id" value="<?= $op['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-<?= $op['activo']?'pause':'play' ?>-fill"></i></button></form>
          <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar?')"><input type="hidden" name="accion" value="eliminar_operador"><input type="hidden" name="id" value="<?= $op['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="mOp" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST">
    <div class="modal-header bg-info text-white"><h5 class="modal-title" id="opTitulo"><i class="bi bi-tools me-2"></i>Nuevo Operador</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
      <input type="hidden" name="accion" id="opAccion" value="crear_operador">
      <input type="hidden" name="id" id="opId" value="">
      <div class="col-md-6"><label class="form-label small">Nombre *</label><input type="text" name="nombre" id="opNom" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label small">RUT</label><input type="text" name="rut" id="opRut" class="form-control"></div>
      <div class="col-md-7"><label class="form-label small">Email</label><input type="email" name="email" id="opEmail" class="form-control"></div>
      <div class="col-md-5"><label class="form-label small">Teléfono</label><input type="text" name="fono" id="opFono" class="form-control"></div>
      <div class="col-md-6"><label class="form-label small">Obra</label>
        <select name="obra_id" id="opObra" class="form-select">
          <option value="">— Sin asignar —</option>
          <?php foreach ($obras as $o): ?><option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-6"><label class="form-label small">Supervisor</label>
        <select name="supervisor_id" id="opSup" class="form-select">
          <option value="">— Sin asignar —</option>
          <?php
            $supsUsr = [];
            foreach ($supervisores as $s) { if ($s['activo']) $supsUsr[$s['usuario_id']] = $s['usuario_nombre']; }
            foreach ($supsUsr as $sid => $sn): ?>
          <option value="<?= $sid ?>"><?= htmlspecialchars($sn) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-md-6"><label class="form-label small">Usuario *</label><input type="text" name="usuario" id="opUsu" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label small">Contraseña <span id="opPassNota">(*)</span></label><input type="text" name="password" id="opPass" class="form-control"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button><button class="btn btn-info text-white"><i class="bi bi-check-lg me-1"></i>Guardar</button></div>
  </form>
</div></div></div>

<script>
function editOp(o) {
  document.getElementById('opAccion').value = 'editar_operador';
  document.getElementById('opId').value     = o.id;
  document.getElementById('opNom').value    = o.nombre || '';
  document.getElementById('opRut').value    = o.rut || '';
  document.getElementById('opEmail').value  = o.email || '';
  document.getElementById('opFono').value   = o.fono || '';
  document.getElementById('opObra').value   = o.obra_id || '';
  document.getElementById('opSup').value    = o.supervisor_id || '';
  document.getElementById('opUsu').value    = o.usuario || '';
  document.getElementById('opPass').value   = '';
  document.getElementById('opPass').required = false;
  document.getElementById('opPassNota').textContent = '(dejar vacío para no cambiar)';
  document.getElementById('opTitulo').innerHTML = '<i class="bi bi-tools me-2"></i>Editar — '+o.nombre;
  new bootstrap.Modal(document.getElementById('mOp')).show();
}
document.getElementById('mOp')?.addEventListener('show.bs.modal', function(e){
  if (!e.relatedTarget) return;
  document.getElementById('opAccion').value = 'crear_operador';
  document.getElementById('opId').value = '';
  ['opNom','opRut','opEmail','opFono','opUsu','opPass'].forEach(id => document.getElementById(id).value='');
  document.getElementById('opObra').value = '';
  document.getElementById('opSup').value = '';
  document.getElementById('opPass').required = true;
  document.getElementById('opPassNota').textContent = '(*)';
  document.getElementById('opTitulo').innerHTML = '<i class="bi bi-tools me-2"></i>Nuevo Operador';
});
</script>
<?php endif; ?>

<?php /* ──────────────── PRESUPUESTOS ──────────────── */ ?>
<?php if ($tab === 'presupuestos'): ?>
<div class="card shadow-sm mb-4">
  <div class="card-header bg-success text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
    <strong><i class="bi bi-cash-stack me-1"></i>Presupuestos por supervisor</strong>
    <div class="d-flex gap-2 align-items-center">
      <input type="month" class="form-control form-control-sm" value="<?= htmlspecialchars($mesFiltro) ?>" onchange="location.href='?tab=presupuestos&mes='+this.value">
      <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#mPres"><i class="bi bi-plus-lg me-1"></i>Asignar</button>
    </div>
  </div>
  <div class="card-body p-0">
    <?php if (empty($presupuestos)): ?>
    <p class="text-center text-muted py-4 mb-0">Sin presupuestos para <?= htmlspecialchars($mesFiltro) ?>.</p>
    <?php else: ?>
    <div class="table-responsive"><table class="table table-hover mb-0">
      <thead class="table-light"><tr><th>Supervisor</th><th>Obra</th><th class="text-end">Asignado</th><th class="text-end">Repartido</th><th class="text-end">Saldo</th><th class="text-end">Acciones</th></tr></thead>
      <tbody>
      <?php foreach ($presupuestos as $p): $saldo = (float)$p['monto_asignado'] - (float)$p['total_repartido']; ?>
      <tr>
        <td><strong><?= htmlspecialchars($p['supervisor_nombre']) ?></strong> <small class="text-muted d-block"><?= htmlspecialchars($p['cargo'] ?: '') ?></small></td>
        <td><small><strong><?= htmlspecialchars($p['obra_codigo']) ?></strong> — <?= htmlspecialchars($p['obra_nombre']) ?></small></td>
        <td class="text-end"><strong>$<?= number_format((float)$p['monto_asignado'],0,',','.') ?></strong></td>
        <td class="text-end"><small>$<?= number_format((float)$p['total_repartido'],0,',','.') ?></small></td>
        <td class="text-end <?= $saldo < 0 ? 'text-danger' : 'text-success' ?>"><strong>$<?= number_format($saldo,0,',','.') ?></strong></td>
        <td class="text-end">
          <button class="btn btn-sm btn-outline-success" onclick='editPres(<?= json_encode($p) ?>)'><i class="bi bi-pencil"></i></button>
          <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar presupuesto?')"><input type="hidden" name="accion" value="eliminar_presupuesto"><input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="mPres" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST">
    <div class="modal-header bg-success text-white"><h5 class="modal-title" id="presTitulo"><i class="bi bi-cash-stack me-2"></i>Asignar Presupuesto</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <input type="hidden" name="accion" value="crear_presupuesto">
      <div class="mb-3"><label class="form-label">Supervisor *</label>
        <select name="supervisor_id" id="presSup" class="form-select" required>
          <option value="">— Seleccionar —</option>
          <?php
            $supsActivos = [];
            foreach ($supervisores as $s) { if ($s['activo']) $supsActivos[$s['usuario_id']] = $s; }
            foreach ($supsActivos as $sid => $s): ?>
          <option value="<?= $sid ?>"><?= htmlspecialchars($s['usuario_nombre']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="mb-3"><label class="form-label">Obra *</label>
        <select name="obra_id" id="presObra" class="form-select" required>
          <option value="">— Seleccionar —</option>
          <?php foreach ($obras as $o): ?><option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="row g-2">
        <div class="col-7"><label class="form-label">Mes *</label><input type="month" name="mes" id="presMes" class="form-control" value="<?= htmlspecialchars($mesFiltro) ?>" required></div>
        <div class="col-5"><label class="form-label">Monto $ *</label><input type="text" name="monto" id="presMonto" class="form-control" inputmode="numeric" required></div>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button><button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Guardar</button></div>
  </form>
</div></div></div>

<script>
function editPres(p) {
  document.getElementById('presSup').value   = p.supervisor_id;
  document.getElementById('presObra').value  = p.obra_id;
  document.getElementById('presMes').value   = p.anio + '-' + String(p.mes).padStart(2,'0');
  document.getElementById('presMonto').value = Number(p.monto_asignado).toLocaleString('es-CL');
  document.getElementById('presTitulo').innerHTML = '<i class="bi bi-cash-stack me-2"></i>Editar — ' + p.supervisor_nombre;
  new bootstrap.Modal(document.getElementById('mPres')).show();
}
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
