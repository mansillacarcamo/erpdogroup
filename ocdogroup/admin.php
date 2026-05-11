<?php
require_once 'config.php';
requireAuth();
requireAdmin();

$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($action === 'crear_usuario') {
    $u = trim($_POST['usuario'] ?? '');
    $n = trim($_POST['nombre'] ?? '');
    $ci = trim($_POST['ci'] ?? '');
    $cargo = trim($_POST['cargo'] ?? 'usuario');
    $email = trim($_POST['email'] ?? '');
    $r = $_POST['rol'] ?? 'usuario';
    $p = $_POST['clave'] ?? '';
    if ($u && $n && $p) {
      $hash = password_hash($p, PASSWORD_DEFAULT);
      try {
        $pdo->prepare("INSERT INTO usuarios (usuario, clave, nombre, ci, cargo, email, rol) VALUES (?,?,?,?,?,?,?)")
          ->execute([$u, $hash, $n, $ci, $cargo, $email, $r]);
        $msg = ['success', 'Usuario creado correctamente'];
      } catch (Exception $e) { $msg = ['danger', 'El usuario ya existe']; }
    } else { $msg = ['danger', 'Complete todos los campos obligatorios']; }
  }
  if ($action === 'eliminar') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid !== $usuario['id']) {
      $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$uid]);
      $msg = ['success', 'Usuario eliminado'];
    } else { $msg = ['danger', 'No puede eliminarse a sí mismo']; }
  }
  if ($action === 'reset_clave') {
    $uid = (int)($_POST['uid'] ?? 0);
    $nueva = $_POST['nueva_clave'] ?? '';
    if ($nueva) {
      $pdo->prepare("UPDATE usuarios SET clave = ? WHERE id = ?")->execute([password_hash($nueva, PASSWORD_DEFAULT), $uid]);
      $msg = ['success', 'Contraseña actualizada'];
    }
  }
  if ($action === 'cambiar_rol') {
    $uid = (int)($_POST['uid'] ?? 0);
    $nuevoRol = $_POST['nuevo_rol'] ?? '';
    if ($uid && in_array($nuevoRol, ['usuario', 'admin', 'gerente_comercial'])) {
      if ($uid === $usuario['id']) {
        $msg = ['danger', 'No puede cambiar su propio rol'];
      } else {
        $pdo->prepare("UPDATE usuarios SET rol = ? WHERE id = ?")->execute([$nuevoRol, $uid]);
        $msg = ['success', 'Rol actualizado a ' . ucfirst($nuevoRol)];
      }
    }
  }
  if ($action === 'cambiar_email') {
    $uid = (int)($_POST['uid'] ?? 0);
    $nuevoEmail = trim($_POST['nuevo_email'] ?? '');
    if ($uid) {
      $pdo->prepare("UPDATE usuarios SET email = ? WHERE id = ?")->execute([$nuevoEmail, $uid]);
      $msg = ['success', 'Correo actualizado'];
    }
  }
  if ($action === 'agregar_aprobador') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid) {
      $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobadores WHERE usuario_id = ?");
      $stmtChk->execute([$uid]);
      if ((int)$stmtChk->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO oc_aprobadores (usuario_id, agregado_por) VALUES (?,?)")
          ->execute([$uid, $usuario['nombre']]);
        $msg = ['success', 'Aprobador agregado correctamente'];
      } else {
        $msg = ['warning', 'Este usuario ya es aprobador'];
      }
    }
  }
  if ($action === 'quitar_aprobador') {
    $uid = (int)($_POST['uid'] ?? 0);
    $pdo->prepare("DELETE FROM oc_aprobadores WHERE usuario_id = ?")->execute([$uid]);
    $msg = ['success', 'Aprobador eliminado'];
  }
  if ($action === 'guardar_modulos') {
    $uid        = (int)($_POST['uid'] ?? 0);
    $modulosSel = $_POST['modulos'] ?? [];
    $modValidos = array_keys(modulosDisponibles());
    if ($uid && $uid !== $usuario['id']) {
      $pdo->prepare("DELETE FROM usuario_modulos WHERE usuario_id = ?")->execute([$uid]);
      $stIns = $pdo->prepare("INSERT OR IGNORE INTO usuario_modulos (usuario_id, modulo) VALUES (?,?)");
      foreach ($modulosSel as $m) {
        if (in_array($m, $modValidos)) $stIns->execute([$uid, $m]);
      }
      $msg = ['success', 'Permisos de módulos actualizados'];
    }
    header('Location: admin.php?mod_ok=1#permisos'); exit;
  }
  /* ── Gestión de usuarios del módulo Despacho ── */
  if ($action === 'despacho_set_rol') {
    $uid = (int)($_POST['uid'] ?? 0);
    $rol = in_array($_POST['rol'] ?? '', ['admin_despacho','usuario']) ? $_POST['rol'] : 'usuario';
    if ($uid) {
      $pdo->prepare("INSERT INTO despacho_roles (usuario_id, rol) VALUES (?,?) ON CONFLICT(usuario_id) DO UPDATE SET rol=excluded.rol")
          ->execute([$uid, $rol]);
      /* Asegurar que tenga acceso al módulo */
      $pdo->prepare("INSERT OR IGNORE INTO usuario_modulos (usuario_id, modulo) VALUES (?,?)")->execute([$uid,'despacho']);
      $msg = ['success', 'Rol de despacho actualizado'];
    }
    header('Location: admin.php?desp_ok=1#despacho'); exit;
  }
  if ($action === 'despacho_quitar') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid) {
      $pdo->prepare("DELETE FROM despacho_roles WHERE usuario_id=?")->execute([$uid]);
      $pdo->prepare("DELETE FROM usuario_modulos WHERE usuario_id=? AND modulo='despacho'")->execute([$uid]);
      $msg = ['success', 'Acceso al módulo Despacho eliminado'];
    }
    header('Location: admin.php?desp_ok=1#despacho'); exit;
  }
  if ($action === 'despacho_habilitar') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid) {
      $pdo->prepare("INSERT OR IGNORE INTO usuario_modulos (usuario_id, modulo) VALUES (?,?)")->execute([$uid,'despacho']);
      $pdo->prepare("INSERT OR IGNORE INTO despacho_roles (usuario_id, rol) VALUES (?,?)")->execute([$uid,'usuario']);
      $msg = ['success', 'Acceso al módulo Despacho habilitado'];
    }
    header('Location: admin.php?desp_ok=1#despacho'); exit;
  }
}

$todosUsuarios = $pdo->query("SELECT * FROM usuarios ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$usuarios = array_filter($todosUsuarios, function($u) { return $u['rol'] !== 'admin'; });
$admins = array_filter($todosUsuarios, function($u) { return $u['rol'] === 'admin'; });

$aprobadores = $pdo->query("
    SELECT a.*, u.usuario, u.nombre, u.cargo
    FROM oc_aprobadores a
    JOIN usuarios u ON a.usuario_id = u.id
    ORDER BY a.id
")->fetchAll(PDO::FETCH_ASSOC);

$aprobadorIds = array_column($aprobadores, 'usuario_id');
$usuariosNoAprobadores = [];
foreach ($todosUsuarios as $_u) { if (!in_array($_u['id'], $aprobadorIds)) $usuariosNoAprobadores[] = $_u; }

// Cargar permisos actuales por usuario
$permisosMap = [];
try {
  $stPerm = $pdo->query("SELECT usuario_id, modulo FROM usuario_modulos ORDER BY usuario_id");
  while ($row = $stPerm->fetch(PDO::FETCH_ASSOC)) {
    $permisosMap[$row['usuario_id']][] = $row['modulo'];
  }
} catch (Exception $e) {}

$todosModulos = modulosDisponibles();
$msgModOk  = isset($_GET['mod_ok']);
$msgDespOk = isset($_GET['desp_ok']);

/* ── Datos módulo Despacho ── */
$despRolesMap = []; // usuario_id => rol
try {
  $stDR = $pdo->query("SELECT usuario_id, rol FROM despacho_roles");
  while ($r = $stDR->fetch(PDO::FETCH_ASSOC)) $despRolesMap[$r['usuario_id']] = $r['rol'];
} catch (Exception $e) {}
$despAccesoIds = []; // usuario_ids con acceso al módulo
try {
  $stDA = $pdo->query("SELECT usuario_id FROM usuario_modulos WHERE modulo='despacho'");
  while ($r = $stDA->fetch(PDO::FETCH_ASSOC)) $despAccesoIds[] = (int)$r['usuario_id'];
} catch (Exception $e) {}

require_once 'includes/header.php';
?>

<!-- APROBADORES DE OC -->
<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-warning text-dark py-3">
    <h5 class="mb-0"><i class="bi bi-shield-check me-2"></i>Aprobadores de Órdenes de Compra</h5>
  </div>
  <div class="card-body p-4">
    <p class="text-muted mb-3" style="font-size:13px;">
      <i class="bi bi-info-circle me-1"></i>Estos usuarios deben validar cada Orden de Compra antes de que pueda ser enviada al proveedor. <strong>Todos</strong> los aprobadores listados deben aprobar para que la OC sea válida.
    </p>

    <div class="row g-3 mb-3">
      <?php foreach ($aprobadores as $ap): ?>
      <div class="col-md-4">
        <div class="card border-success">
          <div class="card-body p-3 d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-bold"><i class="bi bi-person-check text-success me-1"></i><?= htmlspecialchars($ap['nombre']) ?></div>
              <small class="text-muted">@<?= htmlspecialchars($ap['usuario']) ?> — <?= htmlspecialchars($ap['cargo'] ?? 'Sin cargo') ?></small>
              <br><small class="text-muted">Agregado por: <?= htmlspecialchars($ap['agregado_por']) ?></small>
            </div>
            <form method="POST" class="ms-2" onsubmit="return confirm('¿Quitar a <?= htmlspecialchars($ap['nombre']) ?> como aprobador?')">
              <input type="hidden" name="action" value="quitar_aprobador">
              <input type="hidden" name="uid" value="<?= $ap['usuario_id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>

      <?php if (empty($aprobadores)): ?>
      <div class="col-12 text-center text-muted py-3">
        <i class="bi bi-exclamation-triangle fs-4 text-warning"></i>
        <p class="mb-0 mt-1">No hay aprobadores configurados. Las OC no podrán ser validadas.</p>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($usuariosNoAprobadores)): ?>
    <div class="card bg-light border-0">
      <div class="card-body p-3">
        <h6 class="fw-bold mb-2"><i class="bi bi-plus-circle me-1"></i>Agregar Aprobador</h6>
        <form method="POST" class="d-flex gap-2 align-items-end">
          <input type="hidden" name="action" value="agregar_aprobador">
          <div class="flex-grow-1">
            <select name="uid" class="form-select form-select-sm" required>
              <option value="">— Seleccionar usuario —</option>
              <?php foreach ($usuariosNoAprobadores as $u): ?>
              <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?> (@<?= htmlspecialchars($u['usuario']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-success btn-sm"><i class="bi bi-plus-lg me-1"></i>Agregar</button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ADMINISTRADORES -->
<div class="card shadow-sm border-0 mb-4">
  <div class="card-header bg-dark text-white py-3">
    <h5 class="mb-0"><i class="bi bi-person-gear me-2"></i>Administradores del Sistema</h5>
  </div>
  <div class="card-body p-4">
    <div class="row g-3">
      <?php foreach ($admins as $adm): ?>
      <div class="col-md-4">
        <div class="card border-dark">
          <div class="card-body p-3">
            <div class="d-flex align-items-center gap-3 mb-2">
              <div class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center" style="width:45px;height:45px;font-size:1.2rem;flex-shrink:0;">
                <i class="bi bi-shield-lock-fill"></i>
              </div>
              <div>
                <div class="fw-bold"><?= htmlspecialchars($adm['nombre']) ?></div>
                <small class="text-muted">@<?= htmlspecialchars($adm['usuario']) ?></small>
              </div>
            </div>
            <div style="font-size:13px;" class="mb-2">
              <div><i class="bi bi-briefcase me-1 text-muted"></i><?= htmlspecialchars($adm['cargo'] ?: 'Sin cargo') ?></div>
              <div><i class="bi bi-envelope me-1 text-muted"></i><?= htmlspecialchars($adm['email'] ?: 'Sin correo') ?></div>
              <div><i class="bi bi-card-text me-1 text-muted"></i>C.I. <?= htmlspecialchars($adm['ci'] ?: '—') ?></div>
            </div>
            <div class="d-flex gap-1">
              <form method="POST" class="d-flex align-items-center gap-1 flex-grow-1">
                <input type="hidden" name="action" value="cambiar_email">
                <input type="hidden" name="uid" value="<?= $adm['id'] ?>">
                <input type="email" name="nuevo_email" class="form-control form-control-sm" value="<?= htmlspecialchars($adm['email'] ?? '') ?>" placeholder="correo@empresa.cl" style="font-size:12px;">
                <button class="btn btn-sm btn-outline-success" type="submit" title="Guardar correo"><i class="bi bi-check-lg"></i></button>
              </form>
              <form method="POST" class="d-inline" onsubmit="var p=prompt('Nueva contraseña:');if(!p)return false;this.querySelector('[name=nueva_clave]').value=p;">
                <input type="hidden" name="action" value="reset_clave">
                <input type="hidden" name="uid" value="<?= $adm['id'] ?>">
                <input type="hidden" name="nueva_clave" value="">
                <button class="btn btn-sm btn-outline-warning" title="Cambiar contraseña"><i class="bi bi-key"></i></button>
              </form>
              <?php if ($adm['id'] !== $usuario['id']): ?>
              <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="cambiar_rol">
                <input type="hidden" name="uid" value="<?= $adm['id'] ?>">
                <input type="hidden" name="nuevo_rol" value="usuario">
                <button class="btn btn-sm btn-outline-secondary" title="Quitar rol admin" onclick="return confirm('¿Quitar rol de Administrador a <?= htmlspecialchars($adm['nombre']) ?>?')"><i class="bi bi-person-dash"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-danger text-white"><h6 class="mb-0"><i class="bi bi-person-plus me-2"></i>Nuevo Usuario</h6></div>
      <div class="card-body">
        <?php if ($msg): ?><div class="alert alert-<?= $msg[0] ?> py-2"><?= $msg[1] ?></div><?php endif; ?>
        <form method="POST">
          <input type="hidden" name="action" value="crear_usuario">
          <div class="mb-2"><label class="form-label fw-semibold">Usuario *</label><input type="text" name="usuario" class="form-control" required></div>
          <div class="mb-2"><label class="form-label fw-semibold">Nombre completo *</label><input type="text" name="nombre" class="form-control" required></div>
          <div class="mb-2"><label class="form-label fw-semibold">C.I. / RUT</label><input type="text" name="ci" class="form-control" placeholder="16.780.528-0"></div>
          <div class="mb-2"><label class="form-label fw-semibold">Cargo</label><input type="text" name="cargo" class="form-control" placeholder="Gerente, Administrativo..."></div>
          <div class="mb-2"><label class="form-label fw-semibold">Correo electrónico</label><input type="email" name="email" class="form-control" placeholder="usuario@empresa.cl"></div>
          <div class="mb-2"><label class="form-label fw-semibold">Contraseña *</label><input type="password" name="clave" class="form-control" required></div>
          <div class="mb-3"><label class="form-label fw-semibold">Rol</label>
            <select name="rol" class="form-select"><option value="usuario">Usuario</option><option value="gerente_comercial">Gerente Comercial</option><option value="admin">Administrador</option></select>
          </div>
          <button class="btn btn-danger w-100"><i class="bi bi-save me-1"></i>Crear</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-danger text-white"><h6 class="mb-0"><i class="bi bi-people me-2"></i>Usuarios registrados</h6></div>
      <div class="card-body p-0">
        <?php if (empty($usuarios)): ?>
        <div class="text-center text-muted py-4"><i class="bi bi-people fs-3"></i><p class="mt-2 mb-0">No hay usuarios registrados</p></div>
        <?php else: ?>
        <table class="table table-hover mb-0">
          <thead class="table-light"><tr><th>Usuario</th><th>Nombre</th><th>Cargo</th><th>Correo</th><th>Rol</th><th>Acciones</th></tr></thead>
          <tbody>
            <?php foreach ($usuarios as $u): ?>
            <tr>
              <td><?= htmlspecialchars($u['usuario']) ?></td>
              <td><?= htmlspecialchars($u['nombre']) ?></td>
              <td><?= htmlspecialchars($u['cargo']) ?></td>
              <td>
                <form method="POST" class="d-flex align-items-center gap-1" style="min-width:200px;">
                  <input type="hidden" name="action" value="cambiar_email">
                  <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                  <input type="email" name="nuevo_email" class="form-control form-control-sm" value="<?= htmlspecialchars($u['email'] ?? '') ?>" placeholder="correo@empresa.cl" style="font-size:12px;">
                  <button class="btn btn-sm <?= !empty($u['email']) ? 'btn-outline-success' : 'btn-outline-primary' ?>" type="submit" title="Guardar correo">
                    <i class="bi bi-check-lg"></i>
                  </button>
                </form>
              </td>
              <td>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="cambiar_rol">
                  <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                  <select name="nuevo_rol" class="form-select form-select-sm d-inline-block" style="width:auto;" onchange="if(confirm('¿Cambiar rol a '+this.options[this.selectedIndex].text+'?'))this.form.submit();else this.value='<?= $u['rol'] ?>';">
                    <option value="usuario" <?= $u['rol']==='usuario'?'selected':'' ?>>Usuario</option>
                    <option value="gerente_comercial" <?= $u['rol']==='gerente_comercial'?'selected':'' ?>>Gerente Comercial</option>
                    <option value="admin" <?= $u['rol']==='admin'?'selected':'' ?>>Administrador</option>
                  </select>
                </form>
              </td>
              <td>
                <form method="POST" class="d-inline" onsubmit="var p=prompt('Nueva contraseña:');if(!p)return false;this.querySelector('[name=nueva_clave]').value=p;">
                  <input type="hidden" name="action" value="reset_clave">
                  <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                  <input type="hidden" name="nueva_clave" value="">
                  <button class="btn btn-sm btn-outline-warning" title="Resetear contraseña"><i class="bi bi-key"></i></button>
                </form>
                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar usuario?')">
                  <input type="hidden" name="action" value="eliminar">
                  <input type="hidden" name="uid" value="<?= $u['id'] ?>">
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
</div>

<!-- PERMISOS DE MÓDULOS -->
<div class="card shadow-sm border-0 mb-4" id="permisos">
  <div class="card-header bg-primary text-white py-3 d-flex align-items-center justify-content-between">
    <h5 class="mb-0"><i class="bi bi-toggles me-2"></i>Permisos de Módulos por Usuario</h5>
    <small class="opacity-75">Admin siempre accede a todo · Sin permisos = acceso completo</small>
  </div>
  <div class="card-body p-4">
    <?php if ($msgModOk): ?>
    <div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>Permisos guardados correctamente. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <p class="text-muted mb-4" style="font-size:13px;">
      <i class="bi bi-info-circle me-1 text-primary"></i>
      Marca los módulos a los que cada usuario podrá acceder. Si dejas <strong>todos desmarcados</strong>, el usuario tendrá acceso a <em>todos los módulos</em> (comportamiento por defecto).
      Los <strong>Administradores</strong> siempre tienen acceso completo sin importar esta configuración.
    </p>

    <div class="row g-4">
      <?php foreach ($todosUsuarios as $u):
        $uid = (int)$u['id'];
        $esAdmin = $u['rol'] === 'admin';
        $modulosUser = $permisosMap[$uid] ?? [];
        $sinRestricciones = empty($modulosUser);
        $colores = ['danger','primary','success','warning','info','dark'];
        $ci = 0;
      ?>
      <div class="col-xl-4 col-md-6">
        <div class="card border h-100 <?= $esAdmin ? 'border-dark' : 'border-primary' ?>" style="border-width:2px !important;">
          <div class="card-header d-flex align-items-center gap-2 py-2 <?= $esAdmin ? 'bg-dark text-white' : 'bg-light' ?>">
            <div class="rounded-circle d-flex align-items-center justify-content-center <?= $esAdmin ? 'bg-secondary' : 'bg-primary' ?> text-white" style="width:34px;height:34px;flex-shrink:0;font-size:14px;">
              <i class="bi <?= $esAdmin ? 'bi-shield-lock-fill' : 'bi-person-fill' ?>"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-bold text-truncate" style="font-size:14px;"><?= htmlspecialchars($u['nombre']) ?></div>
              <small class="<?= $esAdmin ? 'text-white-50' : 'text-muted' ?>">@<?= htmlspecialchars($u['usuario']) ?> · <?= htmlspecialchars($u['cargo'] ?: ucfirst($u['rol'])) ?></small>
            </div>
            <?php if ($esAdmin): ?>
            <span class="badge bg-warning text-dark">ADMIN</span>
            <?php elseif ($sinRestricciones): ?>
            <span class="badge bg-success">Todo</span>
            <?php else: ?>
            <span class="badge bg-primary"><?= count($modulosUser) ?> mód.</span>
            <?php endif; ?>
          </div>
          <div class="card-body p-3">
            <?php if ($esAdmin): ?>
            <p class="text-muted small mb-0 text-center"><i class="bi bi-shield-fill-check text-success me-1"></i>Acceso completo — no requiere configuración</p>
            <?php else: ?>
            <form method="POST" action="admin.php" id="formModulos_<?= $uid ?>">
              <input type="hidden" name="action" value="guardar_modulos">
              <input type="hidden" name="uid"    value="<?= $uid ?>">
              <div class="mb-2">
                <div class="form-check form-switch mb-2">
                  <input class="form-check-input" type="checkbox" id="todo_<?= $uid ?>" onchange="toggleTodos(<?= $uid ?>, this.checked)" <?= $sinRestricciones ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold text-success" for="todo_<?= $uid ?>">
                    <i class="bi bi-unlock me-1"></i>Acceso completo (sin restricciones)
                  </label>
                </div>
                <hr class="my-2">
              </div>
              <div id="checkboxes_<?= $uid ?>" class="row g-2" <?= $sinRestricciones ? 'style="opacity:.45;pointer-events:none"' : '' ?>>
                <?php foreach ($todosModulos as $clave => $mod):
                  $checked = in_array($clave, $modulosUser) ? 'checked' : '';
                  $color = $mod['color'];
                ?>
                <div class="col-6">
                  <div class="form-check p-0">
                    <label class="d-flex align-items-center gap-2 p-2 rounded border cursor-pointer modulo-check-label <?= $checked ? 'border-'.$color.' bg-'.$color.' bg-opacity-10' : 'border-light' ?>"
                           style="cursor:pointer;font-size:13px;" for="mod_<?= $uid ?>_<?= $clave ?>">
                      <input class="form-check-input m-0 modulo-cb" type="checkbox"
                             name="modulos[]" value="<?= $clave ?>"
                             id="mod_<?= $uid ?>_<?= $clave ?>"
                             <?= $checked ?>
                             onchange="actualizarLabel(this)">
                      <i class="bi <?= $mod['icono'] ?> text-<?= $color ?>"></i>
                      <span><?= $mod['label'] ?></span>
                    </label>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
              <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                  <i class="bi bi-save me-1"></i>Guardar permisos
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="marcarTodo(<?= $uid ?>, true)" title="Marcar todos">
                  <i class="bi bi-check-all"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="marcarTodo(<?= $uid ?>, false)" title="Desmarcar todos">
                  <i class="bi bi-x-lg"></i>
                </button>
              </div>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MÓDULO DESPACHO — Gestión independiente de usuarios y roles
     ══════════════════════════════════════════════════════════ -->
<div class="card shadow-sm border-0 mb-4 border-secondary" style="border-width:2px !important;" id="despacho">
  <div class="card-header bg-dark text-white py-3 d-flex align-items-center justify-content-between">
    <div>
      <h5 class="mb-0"><i class="bi bi-truck-front-fill me-2"></i>Módulo Tickets de Despacho — Usuarios y Roles</h5>
      <small class="opacity-75">Habilita el acceso al módulo y asigna roles (Admin módulo / Usuario)</small>
    </div>
    <a href="tickets_despacho_admin.php" class="btn btn-sm btn-outline-light"><i class="bi bi-gear me-1"></i>Configurar PPU / Destinos</a>
  </div>
  <div class="card-body p-4">
    <?php if ($msgDespOk): ?>
    <div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>Acceso al módulo Despacho actualizado. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <p class="text-muted small mb-4"><i class="bi bi-info-circle me-1 text-dark"></i>
      Puedes habilitar el acceso al módulo a cualquier usuario y asignarle un rol:<br>
      <strong>Admin módulo</strong> — puede configurar PPU, destinos, receptores y ver todos los tickets.<br>
      <strong>Usuario</strong> — puede crear y enviar tickets propios.
    </p>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-dark">
          <tr>
            <th>Usuario</th>
            <th>Cargo</th>
            <th class="text-center">Acceso al módulo</th>
            <th class="text-center">Rol en Despacho</th>
            <th class="text-end">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($todosUsuarios as $u):
          $uid     = (int)$u['id'];
          $esAdmG  = $u['rol'] === 'admin';
          $tieneAcc = $esAdmG || in_array($uid, $despAccesoIds);
          $rolDU   = $despRolesMap[$uid] ?? ($esAdmG ? 'admin_despacho' : null);
        ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= htmlspecialchars($u['nombre']) ?></div>
            <small class="text-muted">@<?= htmlspecialchars($u['usuario']) ?></small>
          </td>
          <td><small><?= htmlspecialchars($u['cargo'] ?? '') ?></small></td>
          <td class="text-center">
            <?php if ($esAdmG): ?>
            <span class="badge bg-warning text-dark">Admin sistema</span>
            <?php elseif ($tieneAcc): ?>
            <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Habilitado</span>
            <?php else: ?>
            <span class="badge bg-secondary">Sin acceso</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <?php if ($esAdmG): ?>
            <span class="badge bg-dark"><i class="bi bi-shield-fill me-1"></i>Admin total</span>
            <?php elseif ($rolDU === 'admin_despacho'): ?>
            <span class="badge bg-dark"><i class="bi bi-star-fill me-1"></i>Admin módulo</span>
            <?php elseif ($tieneAcc): ?>
            <span class="badge bg-secondary"><i class="bi bi-person-fill me-1"></i>Usuario</span>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if (!$esAdmG): ?>
            <?php if (!$tieneAcc): ?>
            <!-- Habilitar acceso -->
            <form method="POST" class="d-inline">
              <input type="hidden" name="action" value="despacho_habilitar">
              <input type="hidden" name="uid"    value="<?= $uid ?>">
              <button class="btn btn-sm btn-outline-success" title="Habilitar acceso al módulo">
                <i class="bi bi-plus-circle me-1"></i>Habilitar
              </button>
            </form>
            <?php else: ?>
            <!-- Cambiar rol -->
            <form method="POST" class="d-inline">
              <input type="hidden" name="action" value="despacho_set_rol">
              <input type="hidden" name="uid"    value="<?= $uid ?>">
              <select name="rol" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()" title="Cambiar rol">
                <option value="usuario"       <?= ($rolDU !== 'admin_despacho') ? 'selected' : '' ?>>Usuario</option>
                <option value="admin_despacho"<?= ($rolDU === 'admin_despacho') ? 'selected' : '' ?>>Admin módulo</option>
              </select>
            </form>
            <form method="POST" class="d-inline" onsubmit="return confirm('¿Quitar acceso al módulo Despacho para este usuario?')">
              <input type="hidden" name="action" value="despacho_quitar">
              <input type="hidden" name="uid"    value="<?= $uid ?>">
              <button class="btn btn-sm btn-outline-danger ms-1" title="Revocar acceso">
                <i class="bi bi-x-circle"></i>
              </button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function toggleTodos(uid, todoChecked) {
  var wrap = document.getElementById('checkboxes_' + uid);
  if (todoChecked) {
    wrap.style.opacity = '.45';
    wrap.style.pointerEvents = 'none';
    wrap.querySelectorAll('.modulo-cb').forEach(function(cb){ cb.checked = false; });
  } else {
    wrap.style.opacity = '1';
    wrap.style.pointerEvents = '';
  }
}
function marcarTodo(uid, val) {
  document.getElementById('todo_' + uid).checked = false;
  toggleTodos(uid, false);
  document.querySelectorAll('#checkboxes_' + uid + ' .modulo-cb').forEach(function(cb){
    cb.checked = val;
    actualizarLabel(cb);
  });
}
function actualizarLabel(cb) {
  var label = cb.closest('label');
  if (!label) return;
  // clases de color dinámicas no son posibles en Bootstrap con string, usamos estilo
  if (cb.checked) {
    label.style.background = 'rgba(13,110,253,.08)';
    label.style.borderColor = '#0d6efd';
  } else {
    label.style.background = '';
    label.style.borderColor = '';
  }
}
// Inicializar estados visuales al cargar
document.querySelectorAll('.modulo-cb').forEach(function(cb){ actualizarLabel(cb); });
</script>

<?php require_once 'includes/footer.php'; ?>
