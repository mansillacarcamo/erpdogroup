<?php
/**
 * admin_combustible.php — Administración del módulo
 * - Gestión de Responsables (CRUD)
 * - Gestión de Admins del módulo
 * Solo accesible para admin del módulo (rol global o combustible_admins).
 */
require_once 'config.php';
requireAuth();
requireCombustibleAdmin($usuario, $pdo);

$msg = null; $error = null;

// ── Procesar acciones ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $accion = $_POST['accion'] ?? '';

    // ── Crear / editar responsable ──
    if ($accion === 'resp_guardar') {
      $idEd = (int)($_POST['resp_id'] ?? 0);
      $usuarioId = (int)($_POST['usuario_id'] ?? 0) ?: null;
      $nombre = trim($_POST['nombre'] ?? '');
      $rut    = trim($_POST['rut'] ?? '');
      $tel    = trim($_POST['telefono'] ?? '');
      $email  = trim($_POST['email'] ?? '');
      $fono   = trim($_POST['fono'] ?? $_POST['telefono'] ?? '');
      // Credenciales propias del portal (independientes del usuario interno)
      $usuLogin = trim($_POST['usuario_login'] ?? '');
      $passLogin = (string)($_POST['password_login'] ?? '');
      $obraId = (int)($_POST['obra_id'] ?? 0) ?: null;
      $obraNom = '';
      if ($obraId) {
        $stO = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id=?");
        $stO->execute([$obraId]);
        if ($o = $stO->fetch(PDO::FETCH_ASSOC)) {
          $obraNom = ($o['codigo']?$o['codigo'].' · ':'').$o['nombre'];
        }
      } else {
        $obraNom = trim($_POST['obra_libre'] ?? '');
      }

      if ($nombre === '') throw new Exception('El nombre es obligatorio.');

      // Validar usuario único (si se ingresó)
      if ($usuLogin !== '') {
        if ($idEd > 0) {
          $stChk = $pdo->prepare("SELECT COUNT(*) FROM combustible_responsables WHERE usuario = ? AND id != ?");
          $stChk->execute([$usuLogin, $idEd]);
        } else {
          $stChk = $pdo->prepare("SELECT COUNT(*) FROM combustible_responsables WHERE usuario = ?");
          $stChk->execute([$usuLogin]);
        }
        if ((int)$stChk->fetchColumn() > 0) {
          throw new Exception("El usuario '$usuLogin' ya está en uso por otro responsable.");
        }
      }

      if ($idEd > 0) {
        $pdo->prepare("UPDATE combustible_responsables
                       SET usuario_id=?, nombre=?, rut=?, telefono=?, fono=?, email=?, usuario=?, obra_id=?, obra_nombre=?
                       WHERE id=?")
            ->execute([$usuarioId, $nombre, $rut, $tel, $fono, $email, $usuLogin, $obraId, $obraNom, $idEd]);
        // Si se ingresó nueva contraseña, actualizar hash. Si está vacía, NO tocar el hash anterior.
        if ($passLogin !== '') {
          $pdo->prepare("UPDATE combustible_responsables SET password_hash=? WHERE id=?")
              ->execute([password_hash($passLogin, PASSWORD_DEFAULT), $idEd]);
        }
        $msg = 'Responsable actualizado.' . ($passLogin !== '' ? ' Contraseña cambiada.' : '');
      } else {
        $hash = $passLogin !== '' ? password_hash($passLogin, PASSWORD_DEFAULT) : '';
        $pdo->prepare("INSERT INTO combustible_responsables
                       (usuario_id, nombre, rut, telefono, fono, email, usuario, password_hash, obra_id, obra_nombre, creado_por)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$usuarioId, $nombre, $rut, $tel, $fono, $email, $usuLogin, $hash, $obraId, $obraNom, $usuario['id']]);
        $msg = 'Responsable creado.' . ($usuLogin !== '' && $passLogin !== '' ? ' Credenciales del portal asignadas.' : '');
      }
    }

    // ── Activar / desactivar responsable ──
    if ($accion === 'resp_toggle') {
      $idT = (int)($_POST['resp_id'] ?? 0);
      $pdo->prepare("UPDATE combustible_responsables SET activo = 1 - activo WHERE id=?")->execute([$idT]);
      $msg = 'Estado actualizado.';
    }

    // ── Eliminar responsable ──
    if ($accion === 'resp_eliminar') {
      $idDel = (int)($_POST['resp_id'] ?? 0);
      // Validar que no tenga hojas asociadas
      $stCk = $pdo->prepare("SELECT COUNT(*) FROM combustible_hojas WHERE responsable_id=?");
      $stCk->execute([$idDel]);
      if ((int)$stCk->fetchColumn() > 0) {
        throw new Exception('No se puede eliminar: tiene hojas asociadas. Desactívalo en su lugar.');
      }
      $pdo->prepare("DELETE FROM combustible_responsables WHERE id=?")->execute([$idDel]);
      $msg = 'Responsable eliminado.';
    }

    // ── Agregar admin del módulo ──
    if ($accion === 'admin_agregar') {
      $uid = (int)($_POST['usuario_id'] ?? 0);
      if ($uid > 0) {
        $pdo->prepare("INSERT OR IGNORE INTO combustible_admins (usuario_id, creado_por) VALUES (?,?)")
            ->execute([$uid, $usuario['id']]);
        $msg = 'Admin agregado.';
      }
    }

    // ── Quitar admin ──
    if ($accion === 'admin_quitar') {
      $uid = (int)($_POST['usuario_id'] ?? 0);
      $pdo->prepare("DELETE FROM combustible_admins WHERE usuario_id=?")->execute([$uid]);
      $msg = 'Admin removido.';
    }

  } catch (Exception $e) {
    $error = $e->getMessage();
  }
}

// ── Cargar datos ────────────────────────────────────────
$responsables = $pdo->query("
  SELECT r.*, u.nombre AS usr_nombre, u.usuario AS usr_login
  FROM combustible_responsables r
  LEFT JOIN usuarios u ON u.id = r.usuario_id
  ORDER BY r.activo DESC, r.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$admins = $pdo->query("
  SELECT ca.usuario_id, u.nombre, u.usuario, u.cargo, u.rol
  FROM combustible_admins ca
  JOIN usuarios u ON u.id = ca.usuario_id
  ORDER BY u.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$usuariosAll = $pdo->query("SELECT id, usuario, nombre, cargo, rol FROM usuarios ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$obras = $pdo->query("SELECT id, codigo, nombre FROM obras ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Para edición rápida: si hay ?edit=ID, traer ese responsable
$resEdit = null;
if (!empty($_GET['edit'])) {
  $stE = $pdo->prepare("SELECT * FROM combustible_responsables WHERE id=?");
  $stE->execute([(int)$_GET['edit']]);
  $resEdit = $stE->fetch(PDO::FETCH_ASSOC) ?: null;
}

require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h3 class="mb-0">
    <i class="bi bi-gear-fill me-2"></i>Admin · Distribución de Combustible
  </h3>
  <a href="combustible.php" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Volver al módulo
  </a>
</div>

<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-resp" type="button">
      <i class="bi bi-people-fill me-1"></i>Responsables
      <span class="badge bg-secondary ms-1"><?= count($responsables) ?></span>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-admins" type="button">
      <i class="bi bi-shield-lock-fill me-1"></i>Admins del módulo
      <span class="badge bg-secondary ms-1"><?= count($admins) ?></span>
    </button>
  </li>
</ul>

<div class="tab-content">

  <!-- ─────────── TAB RESPONSABLES ─────────── -->
  <div class="tab-pane fade show active" id="tab-resp">
    <div class="row g-3">

      <!-- Form -->
      <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-warning text-dark fw-bold">
            <i class="bi bi-<?= $resEdit?'pencil':'plus-circle' ?> me-1"></i>
            <?= $resEdit ? 'Editar responsable #'.$resEdit['id'] : 'Nuevo responsable' ?>
          </div>
          <div class="card-body">
            <form method="post">
              <input type="hidden" name="accion" value="resp_guardar">
              <input type="hidden" name="resp_id" value="<?= $resEdit['id'] ?? 0 ?>">

              <div class="mb-2">
                <label class="form-label small mb-1">Vincular a usuario del sistema (opcional)</label>
                <select name="usuario_id" class="form-select form-select-sm">
                  <option value="0">— Ninguno (solo registro) —</option>
                  <?php foreach ($usuariosAll as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= ($resEdit['usuario_id'] ?? 0)==$u['id']?'selected':'' ?>>
                      <?= htmlspecialchars($u['nombre'].' ('.$u['usuario'].')') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <small class="text-muted">Si lo vinculas, ese usuario podrá registrar hojas desde su sesión.</small>
              </div>

              <div class="mb-2">
                <label class="form-label small mb-1">Nombre del responsable *</label>
                <input type="text" name="nombre" class="form-control form-control-sm" required
                       value="<?= htmlspecialchars($resEdit['nombre'] ?? '') ?>">
              </div>

              <div class="row g-2">
                <div class="col-6">
                  <label class="form-label small mb-1">RUT</label>
                  <input type="text" name="rut" class="form-control form-control-sm"
                         value="<?= htmlspecialchars($resEdit['rut'] ?? '') ?>">
                </div>
                <div class="col-6">
                  <label class="form-label small mb-1">Teléfono</label>
                  <input type="text" name="telefono" class="form-control form-control-sm"
                         value="<?= htmlspecialchars($resEdit['telefono'] ?? '') ?>">
                </div>
              </div>

              <div class="mt-2">
                <label class="form-label small mb-1">Obra asignada</label>
                <select name="obra_id" class="form-select form-select-sm" id="selObraResp">
                  <option value="0">— Sin obra / texto libre —</option>
                  <?php foreach ($obras as $o): ?>
                    <option value="<?= $o['id'] ?>" <?= ($resEdit['obra_id'] ?? 0)==$o['id']?'selected':'' ?>>
                      <?= htmlspecialchars(($o['codigo']?$o['codigo'].' · ':'').$o['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <input type="text" name="obra_libre" placeholder="O escriba nombre de obra"
                       class="form-control form-control-sm mt-1"
                       value="<?= htmlspecialchars($resEdit['obra_id']?'':$resEdit['obra_nombre'] ?? '') ?>"
                       <?= ($resEdit['obra_id'] ?? 0) ? 'style="display:none"' : '' ?>
                       id="obraLibreResp">
              </div>

              <!-- ── Credenciales del Portal de Combustible ── -->
              <div class="mt-3 p-3" style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px">
                <div class="d-flex align-items-center mb-2">
                  <i class="bi bi-key-fill text-warning me-2 fs-5"></i>
                  <strong style="color:#92400e">Credenciales del Portal Combustible</strong>
                </div>
                <small class="text-muted d-block mb-2">
                  Permite que el responsable acceda al sistema con su propio usuario y clave desde
                  <code>combustible_responsable_login.php</code>, sin necesidad de un usuario interno.
                </small>

                <div class="row g-2">
                  <div class="col-6">
                    <label class="form-label small mb-1">Email</label>
                    <input type="email" name="email" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($resEdit['email'] ?? '') ?>"
                           placeholder="opcional">
                  </div>
                  <div class="col-6">
                    <label class="form-label small mb-1">Fono</label>
                    <input type="text" name="fono" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($resEdit['fono'] ?? '') ?>"
                           placeholder="opcional">
                  </div>
                </div>

                <div class="row g-2 mt-1">
                  <div class="col-6">
                    <label class="form-label small mb-1">
                      <i class="bi bi-person-fill"></i> Usuario login
                    </label>
                    <input type="text" name="usuario_login" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($resEdit['usuario'] ?? '') ?>"
                           placeholder="ej: jperez" autocomplete="off">
                  </div>
                  <div class="col-6">
                    <label class="form-label small mb-1">
                      <i class="bi bi-lock-fill"></i> Contraseña
                      <?php if ($resEdit && !empty($resEdit['password_hash'])): ?>
                      <span class="badge bg-success" style="font-size:.6rem">Ya tiene</span>
                      <?php endif; ?>
                    </label>
                    <input type="text" name="password_login" class="form-control form-control-sm"
                           placeholder="<?= $resEdit && !empty($resEdit['password_hash']) ? 'Vacío = mantener' : 'mínimo 4 caracteres' ?>"
                           autocomplete="new-password">
                  </div>
                </div>

                <?php if ($resEdit && !empty($resEdit['usuario'])): ?>
                <div class="mt-2 small">
                  <i class="bi bi-info-circle text-warning me-1"></i>
                  Acceso: <code><?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/combustible_responsable_login.php</code>
                </div>
                <?php endif; ?>
              </div>

              <div class="d-flex justify-content-end gap-2 mt-3">
                <?php if ($resEdit): ?>
                  <a href="admin_combustible.php" class="btn btn-sm btn-outline-secondary">Cancelar</a>
                <?php endif; ?>
                <button class="btn btn-sm btn-warning text-dark fw-semibold">
                  <i class="bi bi-save me-1"></i><?= $resEdit?'Actualizar':'Crear' ?>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Listado -->
      <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-dark text-white">
            <i class="bi bi-list-check me-1"></i>Responsables registrados
          </div>
          <div class="card-body p-0">
            <?php if (empty($responsables)): ?>
              <div class="text-center text-muted py-4">
                <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                Aún no hay responsables registrados.
              </div>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Nombre</th>
                    <th>Obra</th>
                    <th>Usuario sistema</th>
                    <th>Portal Combustible</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($responsables as $r): ?>
                  <tr class="<?= $r['activo']?'':'text-muted' ?>">
                    <td>
                      <div class="fw-semibold"><?= htmlspecialchars($r['nombre']) ?></div>
                      <?php if ($r['rut']): ?><small class="text-muted"><?= htmlspecialchars($r['rut']) ?></small><?php endif; ?>
                    </td>
                    <td><small><?= htmlspecialchars($r['obra_nombre'] ?: '—') ?></small></td>
                    <td>
                      <?php if ($r['usr_login']): ?>
                        <span class="badge bg-info-subtle text-dark"><i class="bi bi-link-45deg"></i> <?= htmlspecialchars($r['usr_login']) ?></span>
                      <?php else: ?>
                        <small class="text-muted">—</small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($r['usuario']) && !empty($r['password_hash'])): ?>
                        <span class="badge" style="background:#d97706;color:#fff">
                          <i class="bi bi-key-fill"></i> <?= htmlspecialchars($r['usuario']) ?>
                        </span>
                      <?php elseif (!empty($r['usuario'])): ?>
                        <span class="badge bg-warning text-dark" title="Sin contraseña asignada">
                          <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($r['usuario']) ?>
                        </span>
                      <?php else: ?>
                        <small class="text-muted"><i class="bi bi-dash"></i> sin acceso</small>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($r['activo']): ?>
                        <span class="badge bg-success">Activo</span>
                      <?php else: ?>
                        <span class="badge bg-secondary">Inactivo</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <a href="?edit=<?= $r['id'] ?>" class="btn btn-sm btn-outline-warning" title="Editar">
                        <i class="bi bi-pencil"></i>
                      </a>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="accion" value="resp_toggle">
                        <input type="hidden" name="resp_id" value="<?= $r['id'] ?>">
                        <button class="btn btn-sm btn-outline-secondary" title="<?= $r['activo']?'Desactivar':'Activar' ?>">
                          <i class="bi bi-<?= $r['activo']?'toggle-on':'toggle-off' ?>"></i>
                        </button>
                      </form>
                      <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este responsable?')">
                        <input type="hidden" name="accion" value="resp_eliminar">
                        <input type="hidden" name="resp_id" value="<?= $r['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
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
  </div>

  <!-- ─────────── TAB ADMINS ─────────── -->
  <div class="tab-pane fade" id="tab-admins">
    <div class="row g-3">
      <div class="col-12 col-lg-5">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-warning text-dark fw-bold">
            <i class="bi bi-shield-plus me-1"></i>Agregar admin del módulo
          </div>
          <div class="card-body">
            <form method="post">
              <input type="hidden" name="accion" value="admin_agregar">
              <label class="form-label small mb-1">Usuario</label>
              <select name="usuario_id" class="form-select form-select-sm mb-2" required>
                <option value="">— Seleccione usuario —</option>
                <?php
                  $idsAdmin = array_column($admins, 'usuario_id');
                  foreach ($usuariosAll as $u):
                    if (in_array($u['id'], $idsAdmin)) continue;
                    if ($u['rol']==='admin') continue; // ya tiene acceso por rol global
                ?>
                  <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre'].' · '.$u['cargo']) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-warning text-dark fw-semibold w-100">
                <i class="bi bi-person-plus me-1"></i>Agregar como admin
              </button>
              <small class="d-block text-muted mt-2">
                Los usuarios con rol global <code>admin</code> ya tienen acceso completo al módulo automáticamente.
              </small>
            </form>
          </div>
        </div>
      </div>

      <div class="col-12 col-lg-7">
        <div class="card border-0 shadow-sm">
          <div class="card-header bg-dark text-white">
            <i class="bi bi-shield-check me-1"></i>Admins del módulo
          </div>
          <div class="card-body p-0">
            <?php
              // Mostrar también los admin globales (informativo)
              $stG = $pdo->query("SELECT id, nombre, usuario, cargo FROM usuarios WHERE rol='admin' ORDER BY nombre");
              $globales = $stG->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr><th>Usuario</th><th>Cargo</th><th>Tipo</th><th class="text-end">Acción</th></tr>
              </thead>
              <tbody>
              <?php foreach ($globales as $g): ?>
                <tr class="text-muted">
                  <td><?= htmlspecialchars($g['nombre']) ?> <small>(<?= htmlspecialchars($g['usuario']) ?>)</small></td>
                  <td><small><?= htmlspecialchars($g['cargo']) ?></small></td>
                  <td><span class="badge bg-danger">Admin global</span></td>
                  <td class="text-end"><small class="text-muted">Automático</small></td>
                </tr>
              <?php endforeach; ?>
              <?php foreach ($admins as $a): ?>
                <tr>
                  <td><?= htmlspecialchars($a['nombre']) ?> <small>(<?= htmlspecialchars($a['usuario']) ?>)</small></td>
                  <td><small><?= htmlspecialchars($a['cargo']) ?></small></td>
                  <td><span class="badge bg-warning text-dark">Admin del módulo</span></td>
                  <td class="text-end">
                    <form method="post" class="d-inline" onsubmit="return confirm('¿Quitar acceso de admin?')">
                      <input type="hidden" name="accion" value="admin_quitar">
                      <input type="hidden" name="usuario_id" value="<?= $a['usuario_id'] ?>">
                      <button class="btn btn-sm btn-outline-danger" title="Quitar"><i class="bi bi-x-circle"></i></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
const selO = document.getElementById('selObraResp');
const inpO = document.getElementById('obraLibreResp');
selO?.addEventListener('change', () => {
  inpO.style.display = (selO.value === '0') ? 'block' : 'none';
});
</script>

<?php require_once 'includes/footer.php'; ?>
