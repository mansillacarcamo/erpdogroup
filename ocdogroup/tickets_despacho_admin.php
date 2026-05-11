<?php
require_once 'config.php';
requireAuth();
requireModulo('despacho', $usuario, $pdo);
requireDespachoAdmin($usuario, $pdo);

$msg = null; $msgTipo = 'success';

/* ── POST ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';
  try {
    /* ── PPU ── */
    if ($accion === 'crear_ppu') {
      $ppu = strtoupper(trim($_POST['ppu'] ?? ''));
      $desc = trim($_POST['descripcion'] ?? '');
      $m3   = max(0, (float)str_replace(',', '.', $_POST['metros_cubicos'] ?? '0'));
      if (!$ppu) throw new Exception('La PPU es obligatoria');
      $pdo->prepare("INSERT INTO despacho_ppu (ppu, descripcion, metros_cubicos) VALUES (?,?,?)")
          ->execute([$ppu, $desc, $m3]);
      $newPpuId = $pdo->lastInsertId();
      $msg = "Camión $ppu creado.";
      header('Location: tickets_despacho_admin.php?ver_qr='.$newPpuId.'&msg='.urlencode($msg));
      exit;
    }
    if ($accion === 'editar_ppu') {
      $id   = (int)$_POST['id'];
      $ppu  = strtoupper(trim($_POST['ppu'] ?? ''));
      $desc = trim($_POST['descripcion'] ?? '');
      $m3   = max(0, (float)str_replace(',', '.', $_POST['metros_cubicos'] ?? '0'));
      $pdo->prepare("UPDATE despacho_ppu SET ppu=?, descripcion=?, metros_cubicos=? WHERE id=?")
          ->execute([$ppu, $desc, $m3, $id]);
      $msg = "Camión $ppu actualizado.";
    }
    if ($accion === 'toggle_ppu') {
      $id = (int)$_POST['id'];
      $pdo->prepare("UPDATE despacho_ppu SET activo = 1 - activo WHERE id=?")->execute([$id]);
      $msg = 'Estado camión actualizado.';
    }
    if ($accion === 'eliminar_ppu') {
      $id = (int)$_POST['id'];
      $pdo->prepare("DELETE FROM despacho_ppu WHERE id=?")->execute([$id]);
      $msg = 'Camión eliminado.';
    }
    /* ── DESTINOS ── */
    if ($accion === 'crear_destino') {
      $nom = trim($_POST['nombre'] ?? '');
      if (!$nom) throw new Exception('El nombre es obligatorio');
      $pdo->prepare("INSERT INTO despacho_destinos (nombre, descripcion) VALUES (?,?)")
          ->execute([$nom, trim($_POST['descripcion'] ?? '')]);
      $msg = "Destino '$nom' creado.";
    }
    if ($accion === 'editar_destino') {
      $id  = (int)$_POST['id'];
      $nom = trim($_POST['nombre'] ?? '');
      $pdo->prepare("UPDATE despacho_destinos SET nombre=?, descripcion=? WHERE id=?")
          ->execute([$nom, trim($_POST['descripcion'] ?? ''), $id]);
      $msg = 'Destino actualizado.';
    }
    if ($accion === 'toggle_destino') {
      $id = (int)$_POST['id'];
      $pdo->prepare("UPDATE despacho_destinos SET activo = 1 - activo WHERE id=?")->execute([$id]);
      $msg = 'Estado destino actualizado.';
    }
    if ($accion === 'eliminar_destino') {
      $id = (int)$_POST['id'];
      $pdo->prepare("DELETE FROM despacho_destinos WHERE id=?")->execute([$id]);
      $msg = 'Destino eliminado.';
    }
    /* ── RECEPTORES ── */
    if ($accion === 'agregar_receptor') {
      $uid = (int)$_POST['uid'];
      $pdo->prepare("INSERT OR IGNORE INTO despacho_receptores (usuario_id) VALUES (?)")->execute([$uid]);
      $msg = 'Receptor asignado.';
    }
    if ($accion === 'quitar_receptor') {
      $uid = (int)$_POST['uid'];
      $pdo->prepare("DELETE FROM despacho_receptores WHERE usuario_id=?")->execute([$uid]);
      $msg = 'Receptor eliminado.';
    }
    /* ── CONDUCTORES ── */
    if ($accion === 'crear_conductor') {
      $nom    = trim($_POST['nombre']   ?? '');
      $rut    = trim($_POST['rut']      ?? '');
      $email  = trim($_POST['email']    ?? '');
      $fono   = trim($_POST['fono']     ?? '');
      $usu    = trim($_POST['usuario']  ?? '');
      $pass   = trim($_POST['password'] ?? '');
      if (!$nom) throw new Exception('El nombre es obligatorio');
      $ppuId  = (int)($_POST['ppu_id']  ?? 0) ?: null;
      $obraId = (int)($_POST['obra_id'] ?? 0) ?: null;
      $hash   = $pass ? password_hash($pass, PASSWORD_DEFAULT) : '';
      /* Verificar usuario único */
      if ($usu) {
        $stChk = $pdo->prepare("SELECT COUNT(*) FROM despacho_conductores WHERE usuario=?");
        $stChk->execute([$usu]);
        if ((int)$stChk->fetchColumn() > 0) throw new Exception("El usuario '$usu' ya está en uso.");
      }
      $pdo->prepare("INSERT INTO despacho_conductores (nombre, rut, email, fono, ppu_id, obra_id, usuario, password_hash) VALUES (?,?,?,?,?,?,?,?)")
          ->execute([$nom, $rut, $email, $fono, $ppuId, $obraId, $usu, $hash]);
      $msg = "Conductor '$nom' creado.";
    }
    if ($accion === 'editar_conductor') {
      $id     = (int)$_POST['id'];
      $nom    = trim($_POST['nombre']   ?? '');
      $rut    = trim($_POST['rut']      ?? '');
      $email  = trim($_POST['email']    ?? '');
      $fono   = trim($_POST['fono']     ?? '');
      $usu    = trim($_POST['usuario']  ?? '');
      $pass   = trim($_POST['password'] ?? '');
      $ppuId  = (int)($_POST['ppu_id']  ?? 0) ?: null;
      $obraId = (int)($_POST['obra_id'] ?? 0) ?: null;
      /* Verificar usuario único excluyendo este mismo */
      if ($usu) {
        $stChk = $pdo->prepare("SELECT COUNT(*) FROM despacho_conductores WHERE usuario=? AND id!=?");
        $stChk->execute([$usu, $id]);
        if ((int)$stChk->fetchColumn() > 0) throw new Exception("El usuario '$usu' ya está en uso.");
      }
      $pdo->prepare("UPDATE despacho_conductores SET nombre=?, rut=?, email=?, fono=?, ppu_id=?, obra_id=?, usuario=? WHERE id=?")
          ->execute([$nom, $rut, $email, $fono, $ppuId, $obraId, $usu, $id]);
      if ($pass) {
        $pdo->prepare("UPDATE despacho_conductores SET password_hash=? WHERE id=?")
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
      }
      $msg = "Conductor '$nom' actualizado.";
    }
    if ($accion === 'toggle_conductor') {
      $id = (int)$_POST['id'];
      $pdo->prepare("UPDATE despacho_conductores SET activo = 1 - activo WHERE id=?")->execute([$id]);
      $msg = 'Estado conductor actualizado.';
    }
    if ($accion === 'eliminar_conductor') {
      $id = (int)$_POST['id'];
      $pdo->prepare("DELETE FROM despacho_conductores WHERE id=?")->execute([$id]);
      $msg = 'Conductor eliminado.';
    }
    /* ── Encargado de Costos ── */
    if ($accion === 'agregar_costos') {
      $uid = (int)$_POST['uid'];
      $pdo->prepare("INSERT OR IGNORE INTO despacho_encargado_costos (usuario_id) VALUES (?)")->execute([$uid]);
      $msg = 'Encargado de costos asignado.';
    }
    if ($accion === 'quitar_costos') {
      $uid = (int)$_POST['uid'];
      $pdo->prepare("DELETE FROM despacho_encargado_costos WHERE usuario_id=?")->execute([$uid]);
      $msg = 'Encargado de costos eliminado.';
    }
    /* ── ENCARGADO OPERACIONES ── */
    if ($accion === 'guardar_operaciones') {
      $uid   = (int)($_POST['uid']   ?? 0);
      $email = trim($_POST['email_ext'] ?? '');
      $pdo->exec("DELETE FROM despacho_encargado_operaciones");
      if ($uid || $email) {
        $pdo->prepare("INSERT INTO despacho_encargado_operaciones (usuario_id, email_ext) VALUES (?,?)")
            ->execute([$uid ?: null, $email]);
      }
      $msg = 'Encargado de operaciones guardado.';
    }
    /* ── EXTERNOS ── */
    if ($accion === 'crear_externo') {
      $usu  = trim($_POST['usuario']  ?? '');
      $nom  = trim($_POST['nombre']   ?? '');
      $pass = trim($_POST['password'] ?? '');
      if (!$nom || !$usu || !$pass) throw new Exception('Nombre, usuario y contraseña son obligatorios.');
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $obraId = (int)($_POST['obra_id'] ?? 0) ?: null;
      $pdo->prepare("INSERT INTO despacho_externos (nombre,rut,email,empresa,obra_id,fono,usuario,password_hash) VALUES (?,?,?,?,?,?,?,?)")
          ->execute([$nom, trim($_POST['rut'] ?? ''), trim($_POST['email'] ?? ''), trim($_POST['empresa'] ?? ''), $obraId, trim($_POST['fono'] ?? ''), $usu, $hash]);
      /* Agregar como receptor externo automáticamente */
      $newId = (int)$pdo->lastInsertId();
      $pdo->prepare("INSERT OR IGNORE INTO despacho_receptores (usuario_id, tipo, externo_id) VALUES (0, 'externo', ?)")->execute([$newId]);
      $msg = "Validador externo '$nom' creado.";
    }
    if ($accion === 'editar_externo') {
      $id     = (int)$_POST['id'];
      $nom    = trim($_POST['nombre']   ?? '');
      $usu    = trim($_POST['usuario']  ?? '');
      $obraId = (int)($_POST['obra_id'] ?? 0) ?: null;
      $pdo->prepare("UPDATE despacho_externos SET nombre=?,rut=?,email=?,empresa=?,obra_id=?,fono=?,usuario=? WHERE id=?")
          ->execute([$nom, trim($_POST['rut'] ?? ''), trim($_POST['email'] ?? ''), trim($_POST['empresa'] ?? ''), $obraId, trim($_POST['fono'] ?? ''), $usu, $id]);
      /* Cambiar contraseña si se ingresó */
      if (!empty(trim($_POST['password'] ?? ''))) {
        $hash = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE despacho_externos SET password_hash=? WHERE id=?")->execute([$hash, $id]);
      }
      $msg = "Validador '$nom' actualizado.";
    }
    if ($accion === 'toggle_externo') {
      $id = (int)$_POST['id'];
      $pdo->prepare("UPDATE despacho_externos SET activo = 1 - activo WHERE id=?")->execute([$id]);
      $msg = 'Estado actualizado.';
    }
    if ($accion === 'eliminar_externo') {
      $id = (int)$_POST['id'];
      $pdo->prepare("DELETE FROM despacho_receptores WHERE tipo='externo' AND externo_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM despacho_externos WHERE id=?")->execute([$id]);
      $msg = 'Validador externo eliminado.';
    }
  } catch (Exception $e) { $msg = 'Error: '.$e->getMessage(); $msgTipo = 'danger'; }
  header("Location: tickets_despacho_admin.php?msg=".urlencode($msg)."&mt=$msgTipo"); exit;
}

if (!empty($_GET['msg'])) { $msg = $_GET['msg']; $msgTipo = $_GET['mt'] ?? 'success'; }

/* ── Datos ─── */
$ppuList     = $pdo->query("SELECT * FROM despacho_ppu ORDER BY activo DESC, ppu")->fetchAll(PDO::FETCH_ASSOC);
$destinoList = $pdo->query("SELECT * FROM despacho_destinos ORDER BY activo DESC, nombre")->fetchAll(PDO::FETCH_ASSOC);
$receptores   = $pdo->query("SELECT dr.usuario_id, u.nombre, u.usuario FROM despacho_receptores dr JOIN usuarios u ON u.id=dr.usuario_id")->fetchAll(PDO::FETCH_ASSOC);
$recIds       = array_column($receptores, 'usuario_id');
$encCostos    = $pdo->query("SELECT ec.usuario_id, u.nombre, u.usuario, u.email FROM despacho_encargado_costos ec JOIN usuarios u ON u.id=ec.usuario_id")->fetchAll(PDO::FETCH_ASSOC);
$costosIds    = array_column($encCostos, 'usuario_id');
$todosUsr     = $pdo->query("SELECT id, nombre, usuario, email FROM usuarios ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$encOper      = $pdo->query("SELECT eo.*, u.nombre AS u_nombre, u.email AS u_email FROM despacho_encargado_operaciones eo LEFT JOIN usuarios u ON u.id=eo.usuario_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$conductores  = $pdo->query("
  SELECT dc.id, dc.nombre, dc.rut, dc.email, dc.fono, dc.usuario, dc.ppu_id, dc.obra_id, dc.activo,
         dc.password_hash,
         dp.ppu, dp.metros_cubicos,
         o.codigo AS obra_codigo, o.nombre AS obra_nombre
  FROM despacho_conductores dc
  LEFT JOIN despacho_ppu dp ON dp.id = dc.ppu_id
  LEFT JOIN obras o          ON o.id  = dc.obra_id
  ORDER BY dc.activo DESC, dc.nombre
")->fetchAll(PDO::FETCH_ASSOC);
$obrasList    = [];
try { $obrasList = $pdo->query("SELECT id, codigo, nombre FROM obras WHERE estado='activa' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
$externos = $pdo->query("
  SELECT de.*, o.codigo AS obra_codigo, o.nombre AS obra_nombre
  FROM despacho_externos de
  LEFT JOIN obras o ON o.id = de.obra_id
  ORDER BY de.activo DESC, de.nombre
")->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h3 class="fw-bold mb-1"><i class="bi bi-gear-fill text-secondary me-2"></i>Configuración — Ticket de Despacho</h3>
    <p class="text-muted mb-0">Gestiona camiones (PPU), destinos y receptores asignados</p>
  </div>
  <a href="tickets_despacho.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Volver al módulo</a>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-4">

  <!-- ── CAMIONES (PPU) ── -->
  <div class="col-12">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-dark text-white d-flex align-items-center justify-content-between">
        <h5 class="mb-0"><i class="bi bi-truck-front-fill me-2"></i>Camiones / PPU</h5>
        <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#modalAddPPU"><i class="bi bi-plus-lg me-1"></i>Agregar</button>
      </div>
      <div class="card-body p-0">
        <?php if (empty($ppuList)): ?>
        <p class="text-center text-muted py-4 mb-0">No hay camiones registrados.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>PPU</th><th>Descripción</th><th class="text-center">m³</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($ppuList as $p): ?>
            <tr>
              <td><strong class="fs-5"><?= htmlspecialchars($p['ppu']) ?></strong></td>
              <td><?= htmlspecialchars($p['descripcion'] ?: '—') ?></td>
              <td class="text-center"><span class="badge bg-info text-dark fs-6"><?= number_format($p['metros_cubicos'],1,',','.') ?> m³</span></td>
              <td><?= $p['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
              <td class="text-end">
                <button class="btn btn-sm btn-dark" title="Ver / Descargar QR"
                  onclick="abrirQRModal(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['ppu'])) ?>', '<?= htmlspecialchars(addslashes($p['descripcion'] ?? '')) ?>', <?= (float)$p['metros_cubicos'] ?>)">
                  <i class="bi bi-qr-code"></i>
                </button>
                <button class="btn btn-sm btn-outline-primary" onclick="editPPU(<?= htmlspecialchars(json_encode($p)) ?>)"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="accion" value="toggle_ppu">
                  <input type="hidden" name="id" value="<?= $p['id'] ?>">
                  <button class="btn btn-sm <?= $p['activo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $p['activo'] ? 'Desactivar' : 'Activar' ?>"><i class="bi bi-<?= $p['activo'] ? 'pause' : 'play' ?>-fill"></i></button>
                </form>
                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar esta PPU?')">
                  <input type="hidden" name="accion" value="eliminar_ppu">
                  <input type="hidden" name="id" value="<?= $p['id'] ?>">
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

  <!-- ── CONDUCTORES ── -->
  <div class="col-12">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between">
        <h5 class="mb-0"><i class="bi bi-person-badge-fill me-2"></i>Conductores</h5>
        <div class="d-flex gap-2">
          <a href="despacho_conductor_login.php" class="btn btn-sm btn-outline-light" target="_blank"><i class="bi bi-box-arrow-in-right me-1"></i>Portal conductor</a>
          <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#modalAddConductor"><i class="bi bi-plus-lg me-1"></i>Agregar</button>
        </div>
      </div>
      <div class="card-body p-0">
        <?php if (empty($conductores)): ?>
        <p class="text-center text-muted py-4 mb-0">No hay conductores registrados.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr><th>Nombre</th><th>RUT</th><th>Camión</th><th>Obra</th><th>Contacto</th><th>Usuario portal</th><th>Estado</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php foreach ($conductores as $c): ?>
            <tr class="<?= !$c['activo'] ? 'opacity-50' : '' ?>">
              <td><strong><?= htmlspecialchars($c['nombre']) ?></strong></td>
              <td><?= $c['rut'] ? htmlspecialchars($c['rut']) : '<span class="text-muted">—</span>' ?></td>
              <td>
                <?php if ($c['ppu']): ?>
                <span class="badge bg-dark"><?= htmlspecialchars($c['ppu']) ?></span>
                <?php if ($c['metros_cubicos'] > 0): ?><br><small class="text-muted"><?= number_format($c['metros_cubicos'],1,',','.') ?> m³</small><?php endif; ?>
                <?php else: ?><span class="text-muted small">Sin asignar</span><?php endif; ?>
              </td>
              <td>
                <?php if ($c['obra_codigo']): ?>
                <small><strong><?= htmlspecialchars($c['obra_codigo']) ?></strong><br><?= htmlspecialchars($c['obra_nombre']) ?></small>
                <?php else: ?><span class="text-muted small">Sin asignar</span><?php endif; ?>
              </td>
              <td>
                <?php if ($c['email']): ?><small><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($c['email']) ?></small><br><?php endif; ?>
                <?php if ($c['fono']):  ?><small><i class="bi bi-phone me-1"></i><?= htmlspecialchars($c['fono']) ?></small><?php endif; ?>
                <?php if (!$c['email'] && !$c['fono']): ?><span class="text-muted small">—</span><?php endif; ?>
              </td>
              <td>
                <?php if ($c['usuario']): ?>
                <code><?= htmlspecialchars($c['usuario']) ?></code>
                <span class="badge bg-success ms-1" title="Con acceso al portal"><i class="bi bi-check-circle-fill"></i></span>
                <?php else: ?>
                <span class="text-muted small"><i class="bi bi-x-circle me-1"></i>Sin acceso</span>
                <?php endif; ?>
              </td>
              <td><?= $c['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-primary" onclick="editConductor(<?= htmlspecialchars(json_encode($c)) ?>)"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="accion" value="toggle_conductor">
                  <input type="hidden" name="id" value="<?= $c['id'] ?>">
                  <button class="btn btn-sm <?= $c['activo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"><i class="bi bi-<?= $c['activo'] ? 'pause' : 'play' ?>-fill"></i></button>
                </form>
                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este conductor?')">
                  <input type="hidden" name="accion" value="eliminar_conductor">
                  <input type="hidden" name="id" value="<?= $c['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <div class="p-3 bg-light border-top">
          <small class="text-muted"><i class="bi bi-info-circle me-1"></i>
            Portal de conductores: <code><?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].'/despacho_conductor_login.php' ?></code>
            — Solo acceden conductores con usuario y contraseña asignados.
          </small>
        </div>
      </div>
    </div>
  </div>

  <!-- ── DESTINOS ── -->
  <div class="col-md-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-secondary text-white d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="bi bi-geo-alt-fill me-2"></i>Destinos</h6>
        <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#modalAddDestino"><i class="bi bi-plus-lg me-1"></i>Agregar</button>
      </div>
      <div class="card-body p-0">
        <?php if (empty($destinoList)): ?>
        <p class="text-center text-muted py-4 mb-0">No hay destinos registrados.</p>
        <?php else: ?>
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light"><tr><th>Nombre</th><th>Estado</th><th class="text-end">Acc.</th></tr></thead>
          <tbody>
          <?php foreach ($destinoList as $d): ?>
          <tr>
            <td><?= htmlspecialchars($d['nombre']) ?><?= $d['descripcion'] ? '<br><small class="text-muted">'.htmlspecialchars($d['descripcion']).'</small>' : '' ?></td>
            <td><?= $d['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary" onclick="editDestino(<?= htmlspecialchars(json_encode($d)) ?>)"><i class="bi bi-pencil"></i></button>
              <form method="POST" class="d-inline">
                <input type="hidden" name="accion" value="toggle_destino">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <button class="btn btn-sm <?= $d['activo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"><i class="bi bi-<?= $d['activo'] ? 'pause' : 'play' ?>-fill"></i></button>
              </form>
              <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                <input type="hidden" name="accion" value="eliminar_destino">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── RECEPTORES ── -->
  <div class="col-md-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-success text-white">
        <h6 class="mb-0"><i class="bi bi-person-check-fill me-2"></i>Receptores / Validadores</h6>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">Los receptores reciben los tickets enviados y pueden validarlos.</p>
        <?php foreach ($receptores as $r): ?>
        <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2">
          <div><i class="bi bi-person-fill text-success me-1"></i><strong><?= htmlspecialchars($r['nombre']) ?></strong> <small class="text-muted">@<?= htmlspecialchars($r['usuario']) ?></small></div>
          <form method="POST" class="d-inline" onsubmit="return confirm('¿Quitar receptor?')">
            <input type="hidden" name="accion" value="quitar_receptor">
            <input type="hidden" name="uid" value="<?= $r['usuario_id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if (empty($receptores)): ?>
        <div class="alert alert-warning py-2"><i class="bi bi-exclamation-triangle me-1"></i>Sin receptores asignados.</div>
        <?php endif; ?>
        <hr>
        <form method="POST" class="d-flex gap-2">
          <input type="hidden" name="accion" value="agregar_receptor">
          <select name="uid" class="form-select form-select-sm" required>
            <option value="">— Agregar receptor —</option>
            <?php foreach ($todosUsr as $u): if (in_array($u['id'], $recIds)) continue; ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?> (@<?= htmlspecialchars($u['usuario']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-success"><i class="bi bi-plus-lg"></i></button>
        </form>
      </div>
    </div>
  </div>
  <!-- ── VALIDADORES / RECEPTORES EXTERNOS ── -->
  <div class="col-12">
    <div class="card shadow-sm border-0 border-primary" style="border-width:2px !important;">
      <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between">
        <div>
          <h5 class="mb-0"><i class="bi bi-person-vcard-fill me-2"></i>Carchek</h5>
          <small class="opacity-75">Usuarios externos con credenciales propias — no son usuarios del sistema principal</small>
        </div>
        <div class="d-flex gap-2">
          <a href="despacho_externo_login.php" class="btn btn-sm btn-outline-light" target="_blank"><i class="bi bi-box-arrow-in-right me-1"></i>Portal externo</a>
          <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#modalAddExterno"><i class="bi bi-plus-lg me-1"></i>Crear cuenta</button>
        </div>
      </div>
      <div class="card-body p-0">
        <?php if (empty($externos)): ?>
        <p class="text-center text-muted py-4 mb-0">No hay validadores externos registrados.</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr><th>Nombre</th><th>RUT</th><th>Empresa</th><th>Obra asignada</th><th>Correo</th><th>Fono</th><th>Usuario</th><th>Estado</th><th class="text-end">Acciones</th></tr>
            </thead>
            <tbody>
            <?php foreach ($externos as $ex): ?>
            <tr class="<?= !$ex['activo'] ? 'text-muted opacity-60' : '' ?>">
              <td><strong><?= htmlspecialchars($ex['nombre']) ?></strong></td>
              <td><?= $ex['rut'] ? htmlspecialchars($ex['rut']) : '<span class="text-muted">—</span>' ?></td>
              <td><?= $ex['empresa'] ? htmlspecialchars($ex['empresa']) : '<span class="text-muted">—</span>' ?></td>
              <td>
                <?php if ($ex['obra_codigo']): ?>
                <small><strong><?= htmlspecialchars($ex['obra_codigo']) ?></strong> — <?= htmlspecialchars($ex['obra_nombre']) ?></small>
                <?php else: ?><span class="text-muted small">Sin asignar</span><?php endif; ?>
              </td>
              <td><small><?= $ex['email'] ? '<a href="mailto:'.htmlspecialchars($ex['email']).'">'.htmlspecialchars($ex['email']).'</a>' : '<span class="text-muted">—</span>' ?></small></td>
              <td><small><?= $ex['fono'] ? htmlspecialchars($ex['fono']) : '<span class="text-muted">—</span>' ?></small></td>
              <td><code><?= htmlspecialchars($ex['usuario']) ?></code></td>
              <td><?= $ex['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-primary" onclick="editExterno(<?= htmlspecialchars(json_encode($ex)) ?>)" title="Editar"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="accion" value="toggle_externo">
                  <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                  <button class="btn btn-sm <?= $ex['activo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $ex['activo'] ? 'Desactivar' : 'Activar' ?>"><i class="bi bi-<?= $ex['activo'] ? 'pause' : 'play' ?>-fill"></i></button>
                </form>
                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este validador externo?')">
                  <input type="hidden" name="accion" value="eliminar_externo">
                  <input type="hidden" name="id" value="<?= $ex['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <div class="p-3 bg-light border-top">
          <small class="text-muted"><i class="bi bi-info-circle me-1"></i>
            El validador externo accede en: <code><?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].'/despacho_externo_login.php' ?></code>
          </small>
        </div>
      </div>
    </div>
  </div>

  <!-- ── ENCARGADO DE COSTOS ── -->
  <div class="col-12 mt-2">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-warning text-dark">
        <h6 class="mb-0"><i class="bi bi-person-check-fill me-2"></i>Encargado de Operaciones</h6>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          El encargado de operaciones recibirá por email: el reporte diario de despacho al cierre del día <strong>y</strong> los reportes de revisión (aprobado / rechazado / con observaciones) enviados por Carchek.
        </p>
        <?php foreach ($encCostos as $ec): ?>
        <div class="d-flex align-items-center justify-content-between border rounded p-2 mb-2 bg-warning bg-opacity-10">
          <div>
            <i class="bi bi-person-fill text-warning me-1"></i>
            <strong><?= htmlspecialchars($ec['nombre']) ?></strong>
            <small class="text-muted ms-1">@<?= htmlspecialchars($ec['usuario']) ?></small>
            <?php if (!empty($ec['email'])): ?>
            <small class="text-muted ms-1"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($ec['email']) ?></small>
            <?php endif; ?>
          </div>
          <form method="POST" class="d-inline" onsubmit="return confirm('¿Quitar encargado de operaciones?')">
            <input type="hidden" name="accion" value="quitar_costos">
            <input type="hidden" name="uid" value="<?= $ec['usuario_id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if (empty($encCostos)): ?>
        <div class="alert alert-warning py-2 mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Sin encargado de operaciones asignado.</div>
        <?php endif; ?>
        <hr>
        <form method="POST" class="d-flex gap-2">
          <input type="hidden" name="accion" value="agregar_costos">
          <select name="uid" class="form-select form-select-sm" required>
            <option value="">— Agregar encargado de operaciones —</option>
            <?php foreach ($todosUsr as $u): if (in_array($u['id'], $costosIds)) continue; ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?> (@<?= htmlspecialchars($u['usuario']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-warning"><i class="bi bi-plus-lg"></i></button>
        </form>
      </div>
    </div>
  </div>

</div>

<!-- Modal Externo -->
<div class="modal fade" id="modalAddExterno" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header bg-primary text-white"><h5 class="modal-title" id="extModalTitle">Nuevo Validador Externo</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <form method="POST" id="formExterno">
    <div class="modal-body">
      <input type="hidden" name="accion" id="extAccion" value="crear_externo">
      <input type="hidden" name="id"     id="extId">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold">Nombre completo *</label>
          <input type="text" name="nombre" id="extNombre" class="form-control" required placeholder="Juan Pérez González">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">RUT</label>
          <input type="text" name="rut" id="extRut" class="form-control" placeholder="12.345.678-9">
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">Fono / Celular</label>
          <input type="text" name="fono" id="extFono" class="form-control" placeholder="+56 9 1234 5678">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Empresa</label>
          <input type="text" name="empresa" id="extEmpresa" class="form-control" placeholder="Empresa del validador">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Correo electrónico</label>
          <input type="email" name="email" id="extEmail" class="form-control" placeholder="correo@empresa.cl">
        </div>
        <div class="col-md-12">
          <label class="form-label fw-semibold">Obra asignada</label>
          <select name="obra_id" id="extObra" class="form-select">
            <option value="">— Sin obra —</option>
            <?php foreach ($obrasList as $o): ?>
            <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><hr class="my-1"><p class="text-muted small mb-0"><i class="bi bi-shield-lock me-1"></i>Credenciales de acceso al portal externo</p></div>
        <div class="col-md-5">
          <label class="form-label fw-semibold">Usuario (login) *</label>
          <input type="text" name="usuario" id="extUsuario" class="form-control" required placeholder="usuario_externo" autocomplete="off">
          <small class="text-muted">Sin espacios, solo letras/números/guion</small>
        </div>
        <div class="col-md-5">
          <label class="form-label fw-semibold">Contraseña <span id="passRequired">*</span></label>
          <div class="input-group">
            <input type="password" name="password" id="extPass" class="form-control" placeholder="Contraseña" autocomplete="new-password">
            <button type="button" class="btn btn-outline-secondary" onclick="togglePassVis('extPass')"><i class="bi bi-eye"></i></button>
          </div>
          <small class="text-muted" id="passHint">Requerido para nuevo usuario</small>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button type="button" class="btn btn-outline-secondary w-100" onclick="generarPass()" title="Generar contraseña aleatoria"><i class="bi bi-shuffle me-1"></i>Auto</button>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
      <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
    </div>
  </form>
</div></div></div>

<!-- Modal Conductor -->
<div class="modal fade" id="modalAddConductor" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header bg-primary text-white"><h5 class="modal-title" id="condModalTitle">Nuevo Conductor</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <form method="POST" id="formConductor">
    <div class="modal-body row g-3">
      <input type="hidden" name="accion" id="condAccion" value="crear_conductor">
      <input type="hidden" name="id"     id="condId">

      <!-- Datos personales -->
      <div class="col-12"><p class="fw-semibold text-muted mb-0 small text-uppercase"><i class="bi bi-person me-1"></i>Datos del conductor</p></div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Nombre completo *</label>
        <input type="text" name="nombre" id="condNombre" class="form-control" required placeholder="Juan Pérez González">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">RUT</label>
        <input type="text" name="rut" id="condRut" class="form-control" placeholder="12.345.678-9">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Fono / Celular</label>
        <input type="text" name="fono" id="condFono" class="form-control" placeholder="+56 9 ...">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Correo electrónico</label>
        <input type="email" name="email" id="condEmail" class="form-control" placeholder="correo@empresa.cl">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Obra asignada</label>
        <select name="obra_id" id="condObra" class="form-select">
          <option value="">— Sin asignar —</option>
          <?php foreach ($obrasList as $o): ?>
          <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-12">
        <label class="form-label fw-semibold">Camión asignado (PPU)</label>
        <select name="ppu_id" id="condPPU" class="form-select">
          <option value="">— Sin asignar —</option>
          <?php foreach ($ppuList as $p): ?>
          <option value="<?= $p['id'] ?>" data-m3="<?= (float)$p['metros_cubicos'] ?>">
            <?= htmlspecialchars($p['ppu']) ?> (<?= number_format($p['metros_cubicos'],1,',','.').' m³' ?>)
          </option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted" id="condM3Info"></small>
      </div>

      <!-- Credenciales -->
      <div class="col-12"><hr class="my-1"><p class="fw-semibold text-muted mb-0 small text-uppercase"><i class="bi bi-shield-lock me-1"></i>Acceso al portal de conductores <span class="text-muted fw-normal">(opcional)</span></p></div>
      <div class="col-md-5">
        <label class="form-label fw-semibold">Usuario (login)</label>
        <input type="text" name="usuario" id="condUsuario" class="form-control" placeholder="conductor_juan" autocomplete="off">
        <small class="text-muted">Sin espacios. Dejar vacío para no habilitar acceso.</small>
      </div>
      <div class="col-md-5">
        <label class="form-label fw-semibold">Contraseña <span id="condPassRequired"></span></label>
        <div class="input-group">
          <input type="password" name="password" id="condPass" class="form-control" placeholder="Contraseña" autocomplete="new-password">
          <button type="button" class="btn btn-outline-secondary" onclick="togglePassVisCond()"><i class="bi bi-eye"></i></button>
        </div>
        <small class="text-muted" id="condPassHint">Requerida si asigna usuario</small>
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button type="button" class="btn btn-outline-secondary w-100" onclick="generarPassCond()"><i class="bi bi-shuffle me-1"></i>Auto</button>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
      <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
    </div>
  </form>
</div></div></div>

<!-- Modal PPU -->
<div class="modal fade" id="modalAddPPU" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header bg-dark text-white"><h5 class="modal-title" id="ppuModalTitle">Nuevo Camión / PPU</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <form method="POST" id="formPPU">
    <div class="modal-body row g-3">
      <input type="hidden" name="accion" id="ppuAccion" value="crear_ppu">
      <input type="hidden" name="id" id="ppuId">
      <div class="col-md-5"><label class="form-label fw-semibold">PPU *</label><input type="text" name="ppu" id="ppuPPU" class="form-control text-uppercase" required placeholder="ABCD12"></div>
      <div class="col-md-3"><label class="form-label fw-semibold">m³ *</label><input type="number" name="metros_cubicos" id="ppuM3" class="form-control" step="0.1" min="0" value="0" required></div>
      <div class="col-12"><label class="form-label fw-semibold">Descripción</label><input type="text" name="descripcion" id="ppuDesc" class="form-control" placeholder="Camión Volvo, etc."></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-dark">Guardar</button></div>
  </form>
</div></div></div>

<!-- Modal Destino -->
<div class="modal fade" id="modalAddDestino" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header bg-secondary text-white"><h5 class="modal-title" id="destModalTitle">Nuevo Destino</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <form method="POST" id="formDestino">
    <div class="modal-body row g-3">
      <input type="hidden" name="accion" id="destAccion" value="crear_destino">
      <input type="hidden" name="id" id="destId">
      <div class="col-12"><label class="form-label fw-semibold">Nombre *</label><input type="text" name="nombre" id="destNombre" class="form-control" required></div>
      <div class="col-12"><label class="form-label fw-semibold">Descripción</label><input type="text" name="descripcion" id="destDesc" class="form-control" placeholder="Opcional..."></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-secondary">Guardar</button></div>
  </form>
</div></div></div>

<script>
function editPPU(p) {
  document.getElementById('ppuAccion').value = 'editar_ppu';
  document.getElementById('ppuId').value     = p.id;
  document.getElementById('ppuPPU').value    = p.ppu;
  document.getElementById('ppuM3').value     = p.metros_cubicos;
  document.getElementById('ppuDesc').value   = p.descripcion || '';
  document.getElementById('ppuModalTitle').textContent = 'Editar PPU — ' + p.ppu;
  new bootstrap.Modal(document.getElementById('modalAddPPU')).show();
}
function editDestino(d) {
  document.getElementById('destAccion').value  = 'editar_destino';
  document.getElementById('destId').value      = d.id;
  document.getElementById('destNombre').value  = d.nombre;
  document.getElementById('destDesc').value    = d.descripcion || '';
  document.getElementById('destModalTitle').textContent = 'Editar Destino';
  new bootstrap.Modal(document.getElementById('modalAddDestino')).show();
}
function editExterno(ex) {
  document.getElementById('extAccion').value  = 'editar_externo';
  document.getElementById('extId').value      = ex.id;
  document.getElementById('extNombre').value  = ex.nombre;
  document.getElementById('extRut').value     = ex.rut    || '';
  document.getElementById('extFono').value    = ex.fono   || '';
  document.getElementById('extEmpresa').value = ex.empresa|| '';
  document.getElementById('extEmail').value   = ex.email  || '';
  document.getElementById('extUsuario').value = ex.usuario|| '';
  document.getElementById('extObra').value    = ex.obra_id|| '';
  document.getElementById('extPass').value    = '';
  document.getElementById('extPass').required = false;
  document.getElementById('passRequired').textContent = '';
  document.getElementById('passHint').textContent = 'Dejar vacío para no cambiar la contraseña';
  document.getElementById('extModalTitle').textContent = 'Editar Validador — ' + ex.nombre;
  new bootstrap.Modal(document.getElementById('modalAddExterno')).show();
}
function togglePassVis(id) {
  var el = document.getElementById(id);
  el.type = el.type === 'password' ? 'text' : 'password';
}
function generarPass() {
  var chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#';
  var pass = '';
  for (var i = 0; i < 10; i++) pass += chars[Math.floor(Math.random() * chars.length)];
  var el = document.getElementById('extPass');
  el.value = pass;
  el.type = 'text';
  navigator.clipboard?.writeText(pass).then(() => alert('Contraseña generada y copiada: ' + pass));
}
/* Reset modal externo al crear nuevo */
document.getElementById('modalAddExterno')?.addEventListener('show.bs.modal', function(e) {
  if (!e.relatedTarget) return;
  document.getElementById('extAccion').value  = 'crear_externo';
  document.getElementById('extId').value      = '';
  ['extNombre','extRut','extFono','extEmpresa','extEmail','extUsuario','extPass'].forEach(function(id){ document.getElementById(id).value = ''; });
  document.getElementById('extObra').value = '';
  document.getElementById('extPass').required = true;
  document.getElementById('passRequired').textContent = '*';
  document.getElementById('passHint').textContent = 'Requerido para nuevo usuario';
  document.getElementById('extModalTitle').textContent = 'Nuevo Validador Externo';
});
function editConductor(c) {
  document.getElementById('condAccion').value  = 'editar_conductor';
  document.getElementById('condId').value      = c.id;
  document.getElementById('condNombre').value  = c.nombre;
  document.getElementById('condRut').value     = c.rut    || '';
  document.getElementById('condFono').value    = c.fono   || '';
  document.getElementById('condEmail').value   = c.email  || '';
  document.getElementById('condPPU').value     = c.ppu_id || '';
  document.getElementById('condObra').value    = c.obra_id|| '';
  document.getElementById('condUsuario').value = c.usuario|| '';
  document.getElementById('condPass').value    = '';
  document.getElementById('condPassRequired').textContent = '';
  document.getElementById('condPassHint').textContent = c.usuario ? 'Dejar vacío para no cambiar contraseña' : 'Requerida si asigna usuario';
  document.getElementById('condModalTitle').textContent = 'Editar Conductor — ' + c.nombre;
  actualizarM3Conductor();
  new bootstrap.Modal(document.getElementById('modalAddConductor')).show();
}
function actualizarM3Conductor() {
  var sel = document.getElementById('condPPU');
  var opt = sel?.options[sel.selectedIndex];
  var m3  = parseFloat(opt?.getAttribute('data-m3') || 0);
  var el  = document.getElementById('condM3Info');
  if (el) el.textContent = m3 > 0 ? 'Capacidad: ' + m3.toFixed(1) + ' m³' : '';
}
document.getElementById('condPPU')?.addEventListener('change', actualizarM3Conductor);
function togglePassVisCond() {
  var el = document.getElementById('condPass');
  el.type = el.type === 'password' ? 'text' : 'password';
}
function generarPassCond() {
  var chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#';
  var pass = '';
  for (var i = 0; i < 10; i++) pass += chars[Math.floor(Math.random() * chars.length)];
  var el = document.getElementById('condPass');
  el.value = pass;
  el.type  = 'text';
  navigator.clipboard?.writeText(pass).then(() => alert('Contraseña generada y copiada: ' + pass));
}
/* Reset modal al crear nuevo */
document.getElementById('modalAddConductor')?.addEventListener('show.bs.modal', function(e) {
  if (!e.relatedTarget) return;
  document.getElementById('condAccion').value  = 'crear_conductor';
  document.getElementById('condId').value      = '';
  ['condNombre','condRut','condFono','condEmail','condUsuario','condPass'].forEach(function(id){ var el=document.getElementById(id); if(el) el.value=''; });
  if(document.getElementById('condPPU'))  document.getElementById('condPPU').value  = '';
  if(document.getElementById('condObra')) document.getElementById('condObra').value = '';
  document.getElementById('condPassRequired').textContent = '';
  document.getElementById('condPassHint').textContent = 'Requerida si asigna usuario';
  document.getElementById('condModalTitle').textContent = 'Nuevo Conductor';
  document.getElementById('condM3Info').textContent = '';
});

/* ── QR Modal ── */
var _qrModalInstance = null;
var _qrObj           = null;

function abrirQRModal(ppuId, ppuLabel, desc, m3) {
  var baseUrl = window.location.origin;
  var url     = baseUrl + '/validar_ppu.php?ppu=' + ppuId;

  document.getElementById('qrModalTitle').textContent   = 'QR Patente — ' + ppuLabel;
  document.getElementById('qrModalPPU').textContent     = ppuLabel;
  document.getElementById('qrModalDesc').textContent    = desc || '';
  document.getElementById('qrModalM3').textContent      = m3 > 0 ? m3.toFixed(1).replace('.', ',') + ' m³' : '';
  document.getElementById('qrModalLink').href           = url;
  document.getElementById('qrModalLink').textContent    = url;

  // Guardar para descarga
  document.getElementById('btnDescargaQR').setAttribute('data-ppu-label', ppuLabel);

  // Regenerar QR
  var cont = document.getElementById('qrModalCanvas');
  cont.innerHTML = '';
  _qrObj = null;
  setTimeout(function() {
    _qrObj = new QRCode(cont, {
      text: url,
      width: 200, height: 200,
      colorDark: '#1b2838', colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.H
    });
  }, 100);

  if (!_qrModalInstance) {
    _qrModalInstance = new bootstrap.Modal(document.getElementById('modalQRPatente'));
  }
  _qrModalInstance.show();
}

function descargarQRModal() {
  var cont   = document.getElementById('qrModalCanvas');
  var label  = document.getElementById('btnDescargaQR').getAttribute('data-ppu-label');
  var canvas = cont.querySelector('canvas');
  var img    = cont.querySelector('img');
  var src    = canvas ? canvas.toDataURL('image/png') : (img ? img.src : null);
  if (!src) { alert('El QR aún se está generando, inténtalo de nuevo.'); return; }

  var pad = 20, size = canvas ? canvas.width : 200;
  var out = document.createElement('canvas');
  out.width  = size + pad * 2;
  out.height = size + pad * 2 + 36;
  var ctx = out.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, out.width, out.height);
  if (canvas) { ctx.drawImage(canvas, pad, pad); }
  else { var tmp = new Image(); tmp.src = src; ctx.drawImage(tmp, pad, pad); }
  ctx.fillStyle = '#1b2838';
  ctx.font = 'bold 18px monospace';
  ctx.textAlign = 'center';
  ctx.fillText(label, out.width / 2, out.height - 10);

  var a = document.createElement('a');
  a.download = 'QR_PPU_' + label + '.png';
  a.href = out.toDataURL('image/png');
  a.click();
}
</script>

<!-- ── Modal QR Patente ── -->
<div class="modal fade" id="modalQRPatente" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title" id="qrModalTitle">QR Patente</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center py-4">
        <div id="qrModalCanvas" class="d-flex justify-content-center mb-3"></div>
        <div class="fw-bold fs-3 font-monospace mb-1" id="qrModalPPU"></div>
        <div class="text-muted small mb-1" id="qrModalDesc"></div>
        <span class="badge bg-info text-dark mb-3" id="qrModalM3"></span>
        <div class="alert alert-success py-2 small">
          <i class="bi bi-check-circle me-1"></i>
          Al escanear este QR, Carchek podrá validar el camión directamente.<br>
          Queda registro de quién validó, cuándo y en qué obra.
        </div>
        <div class="d-flex gap-2 justify-content-center mb-2">
          <a id="qrModalLink" href="#" target="_blank" class="btn btn-outline-success btn-sm">
            <i class="bi bi-box-arrow-up-right me-1"></i>Abrir página de validación
          </a>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button type="button" class="btn btn-dark" id="btnDescargaQR" onclick="descargarQRModal()">
          <i class="bi bi-download me-1"></i>Descargar QR
        </button>
      </div>
    </div>
  </div>
</div>

<?php
// Auto-abrir QR si se acaba de crear una PPU
$verQrId = isset($_GET['ver_qr']) ? (int)$_GET['ver_qr'] : 0;
$verQrMsg = $_GET['msg'] ?? '';
if ($verQrMsg) {
  echo '<script>document.addEventListener("DOMContentLoaded", function() {
    var al = document.createElement("div");
    al.className = "alert alert-success alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3";
    al.style.zIndex = 9999;
    al.innerHTML = \'<i class="bi bi-check-circle me-1"></i>\' + '.json_encode(htmlspecialchars($verQrMsg)).'+\'<button type="button" class="btn-close" data-bs-dismiss="alert"></button>\';
    document.body.appendChild(al);
    setTimeout(function(){ al.remove(); }, 4000);
  });</script>';
}
if ($verQrId > 0) {
  foreach ($ppuList as $p) {
    if ((int)$p['id'] === $verQrId) {
      echo '<script>document.addEventListener("DOMContentLoaded", function() {
        setTimeout(function() {
          abrirQRModal('.json_encode($p['id']).', '.json_encode($p['ppu']).', '.json_encode($p['descripcion'] ?? '').', '.json_encode((float)$p['metros_cubicos']).');
        }, 400);
      });</script>';
      break;
    }
  }
}
?>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<?php require_once 'includes/footer.php'; ?>
