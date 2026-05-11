<?php
require_once 'config.php';
requireRol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Blindaje: si algo explota, mostrar error claro en lugar de pantalla blanca
    try {
    $acc = $_POST['accion'] ?? '';

    if ($acc === 'crear') {
        $nombre  = trim($_POST['nombre'] ?? '');
        $usuario = strtolower(trim($_POST['usuario'] ?? ''));
        $clave   = $_POST['clave'] ?? '123456';
        $rol     = $_POST['rol'] ?? 'usuario';
        $val     = $_POST['validador_id'] ?: null;
        $cargo   = trim($_POST['cargo'] ?? '');
        $zona    = trim($_POST['zona'] ?? '');
        $ciudad  = trim($_POST['ciudad'] ?? '');
        $region  = trim($_POST['region'] ?? '');
        $rut     = trim($_POST['rut'] ?? '');
        $tel     = trim($_POST['telefono'] ?? '');
        $jefe    = null; // se asigna al editar
        $email   = $usuario . '@local'; // placeholder: la columna email es NOT NULL UNIQUE

        if (!$nombre || !$usuario) {
            flash('error','Nombre y usuario son obligatorios.');
        } elseif (!preg_match('/^[a-z0-9._]{3,30}$/', $usuario)) {
            flash('error','El usuario solo puede tener minúsculas, números, punto (.) o guión bajo (_). Entre 3 y 30 caracteres.');
        } else {
            $chk = $pdo->prepare("SELECT usuario, email FROM usuarios WHERE usuario=? OR email=? LIMIT 1");
            $chk->execute([$usuario, $email]);
            $dup = $chk->fetch();
            if ($dup) {
                $motivo = 'El usuario "'.$usuario.'" ya existe';
                flash('error', 'No se pudo crear: '.$motivo.'.');
            } else {
                try {
                    $pdo->prepare("INSERT INTO usuarios (nombre,usuario,email,clave,rol,rut,cargo,zona,ciudad,region,telefono,jefe_zonal_id,validador_id)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$nombre,$usuario,$email,password_hash($clave,PASSWORD_DEFAULT),$rol,$rut,$cargo,$zona,$ciudad,$region,$tel,$jefe,$val]);
                    $nuevoId = (int)$pdo->lastInsertId();
                    if ($nuevoId <= 0) {
                        flash('error','El INSERT no devolvio un ID. Revisa permisos de escritura sobre controlgastos.db.');
                    } else {
                        // Foto carnet (opcional al crear)
                        try {
                            $nuevaFoto = procesarFotoPerfil('foto_perfil', $nuevoId, null);
                            if ($nuevaFoto) {
                                $pdo->prepare("UPDATE usuarios SET foto_perfil=? WHERE id=?")->execute([$nuevaFoto, $nuevoId]);
                            }
                        } catch (Exception $ef) {
                            flash('error','Usuario creado (ID '.$nuevoId.'), pero la foto no se subio: '.$ef->getMessage());
                        }
                        flash('exito','Usuario "'.$usuario.'" creado correctamente (ID '.$nuevoId.').');
                    }
                } catch (Exception $e) {
                    flash('error','Error al crear usuario: '.$e->getMessage());
                }
            }
        }

    } elseif ($acc === 'asignar') {
        $id    = (int)$_POST['id'];
        $anio  = (int)$_POST['anio'];
        $mes   = (int)$_POST['mes'];
        $monto = parseMonto($_POST['monto'] ?? 0);
        $carry = parseMonto($_POST['carry'] ?? 0);
        $obs   = trim($_POST['obs'] ?? '');
        if ($mes < 1 || $mes > 12 || $anio < 2020) {
            flash('error','Periodo inválido.');
        } else {
            $q = $pdo->prepare("SELECT id FROM asignaciones WHERE usuario_id=? AND anio=? AND mes=?");
            $q->execute([$id,$anio,$mes]);
            if ($aid = $q->fetchColumn()) {
                $pdo->prepare("UPDATE asignaciones SET monto_asignado=?, monto_carryover=?, observaciones=?, asignado_por=? WHERE id=?")
                    ->execute([$monto,$carry,$obs,$_SESSION['user_id'],$aid]);
            } else {
                $pdo->prepare("INSERT INTO asignaciones (usuario_id,anio,mes,monto_asignado,monto_carryover,observaciones,asignado_por) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$id,$anio,$mes,$monto,$carry,$obs,$_SESSION['user_id']]);
            }
            flash('exito','Monto asignado a '.nombreMes($mes).' '.$anio.'.');
        }

    } elseif ($acc === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('error','ID invalido.');
        } elseif ($id === (int)($_SESSION['user_id'] ?? 0)) {
            flash('error','No puedes eliminar tu propio usuario mientras estas conectado con el.');
        } else {
            try {
                $info = $pdo->prepare("SELECT usuario, nombre FROM usuarios WHERE id=?");
                $info->execute([$id]);
                $datos = $info->fetch();
                if (!$datos) {
                    flash('error','El usuario no existe (ya fue eliminado).');
                } else {
                    // Conteo previo para informar al admin que se elimina (cascade completo)
                    $cg = $pdo->prepare("SELECT COUNT(*) FROM gastos WHERE usuario_id=?");
                    $cg->execute([$id]);
                    $nGastos = (int)$cg->fetchColumn();
                    $cc = $pdo->prepare("SELECT COUNT(*) FROM cierres_mensuales WHERE usuario_id=?");
                    $cc->execute([$id]);
                    $nCierres = (int)$cc->fetchColumn();

                    // Romper referencias jefe/validador hacia este usuario
                    $pdo->prepare("UPDATE usuarios SET jefe_zonal_id=NULL WHERE jefe_zonal_id=?")->execute([$id]);
                    $pdo->prepare("UPDATE usuarios SET validador_id=NULL WHERE validador_id=?")->execute([$id]);
                    $pdo->beginTransaction();
                    try {
                        // archivos_gasto se borra solo (FK ON DELETE CASCADE sobre gastos)
                        $pdo->prepare("DELETE FROM gastos WHERE usuario_id=?")->execute([$id]);
                        $pdo->prepare("DELETE FROM cierres_mensuales WHERE usuario_id=?")->execute([$id]);
                        $pdo->prepare("DELETE FROM asignaciones WHERE usuario_id=?")->execute([$id]);
                        $pdo->prepare("DELETE FROM notificaciones WHERE usuario_id=?")->execute([$id]);
                        $pdo->prepare("DELETE FROM usuarios WHERE id=?")->execute([$id]);
                        $pdo->commit();
                        // Borrar foto de perfil del disco si existe
                        try {
                            $rutaFoto = __DIR__ . '/img/perfiles/u_' . $id;
                            foreach (['.jpg','.jpeg','.png','.webp'] as $ext) {
                                if (is_file($rutaFoto.$ext)) @unlink($rutaFoto.$ext);
                            }
                            // Foto perfil nueva (uploads/usuarios)
                            if (defined('FOTO_USUARIOS_DIR') && !empty($datos['foto_perfil'])) {
                                $fp = FOTO_USUARIOS_DIR . DIRECTORY_SEPARATOR . $datos['foto_perfil'];
                                if (is_file($fp)) @unlink($fp);
                            }
                            // Adjuntos de gastos del usuario (uploads/u{id})
                            $dirAdj = __DIR__ . '/uploads/u' . $id;
                            if (is_dir($dirAdj)) {
                                foreach (glob($dirAdj.'/*') ?: [] as $fA) { @unlink($fA); }
                                @rmdir($dirAdj);
                            }
                        } catch (Exception $eF) {}
                        $detalle = $nGastos > 0 || $nCierres > 0
                                 ? ' (incluyendo '.$nGastos.' gasto(s) y '.$nCierres.' cierre(s) del historial)'
                                 : '';
                        flash('exito','Usuario "'.$datos['usuario'].'" eliminado correctamente'.$detalle.'.');
                    } catch (Exception $eT) {
                        $pdo->rollBack();
                        flash('error','No se pudo eliminar: '.$eT->getMessage());
                    }
                }
            } catch (Exception $e) {
                flash('error','Error al eliminar usuario: '.$e->getMessage());
            }
        }

    } elseif ($acc === 'editar') {
        $id      = (int)$_POST['id'];
        $usuario = strtolower(trim($_POST['usuario'] ?? ''));
        if (!preg_match('/^[a-z0-9._]{3,30}$/', $usuario)) {
            flash('error','Usuario inválido al editar.');
        } else {
            try {
                $pdo->prepare("UPDATE usuarios SET nombre=?, usuario=?, email=?, rol=?, rut=?, cargo=?, zona=?, ciudad=?, region=?, telefono=?, jefe_zonal_id=?, validador_id=?, activo=? WHERE id=?")
                    ->execute([
                        trim($_POST['nombre'] ?? ''), $usuario, trim($_POST['email'] ?? ''),
                        $_POST['rol'] ?? 'usuario',
                        trim($_POST['rut'] ?? ''), trim($_POST['cargo'] ?? ''),
                        trim($_POST['zona'] ?? ''), trim($_POST['ciudad'] ?? ''), trim($_POST['region'] ?? ''),
                        trim($_POST['telefono'] ?? ''),
                        $_POST['jefe_zonal_id'] ?: null, $_POST['validador_id'] ?: null,
                        isset($_POST['activo']) ? 1 : 0, $id
                    ]);
                if (!empty($_POST['nueva_clave'])) {
                    $pdo->prepare("UPDATE usuarios SET clave=? WHERE id=?")
                        ->execute([password_hash($_POST['nueva_clave'],PASSWORD_DEFAULT),$id]);
                }
                // Foto carnet (opcional al editar)
                try {
                    $fa = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id=?");
                    $fa->execute([$id]);
                    $fotoActual = $fa->fetchColumn();
                    $nuevaFoto = procesarFotoPerfil('foto_perfil', $id, $fotoActual);
                    if ($nuevaFoto) {
                        $pdo->prepare("UPDATE usuarios SET foto_perfil=? WHERE id=?")->execute([$nuevaFoto, $id]);
                    }
                } catch (Exception $ef) {
                    flash('error','Datos actualizados, pero la foto no se subio: '.$ef->getMessage());
                }
                flash('exito','Usuario actualizado.');
            } catch (Exception $e) {
                flash('error','Error al actualizar: '.$e->getMessage());
            }
        }
    }
    } catch (Throwable $errGlobal) {
        flash('error', 'Error inesperado al guardar: ' . $errGlobal->getMessage()
            . ' (en ' . basename($errGlobal->getFile()) . ':' . $errGlobal->getLine() . ')');
    }

    if (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }
    header('Location: admin_usuarios.php');
    exit;
}

$jefes = $pdo->query("SELECT id,nombre FROM usuarios WHERE rol='jefe' AND activo=1 ORDER BY nombre")->fetchAll();
$vals  = $pdo->query("SELECT id,nombre FROM usuarios WHERE rol='validador' AND activo=1 ORDER BY nombre")->fetchAll();
$p = periodoActual();
$anioDef = $p['anio']; $mesDef = $p['mes'];

$users = $pdo->query("SELECT u.*,
    (SELECT nombre FROM usuarios j WHERE j.id=u.jefe_zonal_id) as jefe_nombre,
    (SELECT nombre FROM usuarios v WHERE v.id=u.validador_id) as val_nombre
    FROM usuarios u ORDER BY u.rol, u.nombre")->fetchAll();

$asigs = [];
$qa = $pdo->prepare("SELECT * FROM asignaciones WHERE anio=? AND mes=?");
$qa->execute([$anioDef, $mesDef]);
foreach ($qa->fetchAll() as $a) $asigs[$a['usuario_id']] = $a;

// Conteo de historial por usuario (para mostrar impacto al eliminar)
$histGastos = [];
foreach ($pdo->query("SELECT usuario_id, COUNT(*) c FROM gastos GROUP BY usuario_id") as $r) {
    $histGastos[(int)$r['usuario_id']] = (int)$r['c'];
}
$histCierres = [];
foreach ($pdo->query("SELECT usuario_id, COUNT(*) c FROM cierres_mensuales GROUP BY usuario_id") as $r) {
    $histCierres[(int)$r['usuario_id']] = (int)$r['c'];
}

$titulo = 'Usuarios';
include 'includes/head.php';
include 'includes/nav.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-people me-2"></i>Usuarios (<?= count($users) ?>)</h4>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#mNuevo">
    <i class="bi bi-plus-lg"></i> Nuevo usuario
  </button>
</div>

<div class="card"><div class="card-body p-0">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Nombre</th><th>Usuario</th><th>Email</th><th>Rol</th><th>Region / Ciudad</th>
          <th>Asignado <?= nombreMes($mesDef) ?></th>
          <th>Estado</th><th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <?= avatarUsuario($u['nombre'] ?: $u['usuario'], $u['foto_perfil'] ?? '', 40) ?>
              <div>
                <div><?= h($u['nombre']) ?></div>
                <?php if ($u['cargo']): ?><div class="small text-muted"><?= h($u['cargo']) ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td><span class="badge bg-light text-dark border"><?= h($u['usuario']) ?></span></td>
          <td class="small"><?= h($u['email']) ?></td>
          <td><span class="badge bg-secondary"><?= h($u['rol']) ?></span></td>
          <td class="small">
            <div><?= h($u['region'] ?: '-') ?></div>
            <?php if ($u['ciudad']): ?><div class="text-muted"><i class="bi bi-geo-alt"></i> <?= h($u['ciudad']) ?></div><?php endif; ?>
            <?php if ($u['zona']): ?><div class="text-muted small">Zona: <?= h($u['zona']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if ($u['rol'] === 'usuario'):
              $a = $asigs[$u['id']] ?? null;
              $total = $a ? ((float)$a['monto_asignado'] + (float)$a['monto_carryover']) : 0;
            ?>
              <span class="fw-semibold text-<?= $total>0?'success':'muted' ?>"><?= $a ? fmtCLP($total) : '—' ?></span>
            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
          </td>
          <td><?= $u['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-danger">Inactivo</span>' ?></td>
          <td class="text-nowrap">
            <?php if ($u['rol'] === 'usuario'): ?>
              <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#mA<?= $u['id'] ?>" title="Asignar monto">
                <i class="bi bi-cash-coin"></i>
              </button>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mE<?= $u['id'] ?>" title="Editar">
              <i class="bi bi-pencil"></i>
            </button>
            <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
              <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#mD<?= $u['id'] ?>" title="Eliminar usuario">
                <i class="bi bi-trash"></i>
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div></div>

<!-- ====================================================== -->
<!-- MODAL NUEVO USUARIO                                     -->
<!-- ====================================================== -->
<div class="modal fade" id="mNuevo" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post" id="formNuevo" enctype="multipart/form-data">
        <input type="hidden" name="accion" value="crear">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><i class="bi bi-person-plus me-1"></i>Nuevo usuario</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <!-- Foto carnet -->
          <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
            <div id="prevFotoNuevo" class="d-flex align-items-center justify-content-center bg-light text-muted"
                 style="width:90px;height:115px;border:2px dashed #cbd5e1;border-radius:6px;flex-shrink:0;">
              <i class="bi bi-person-bounding-box" style="font-size:2rem;"></i>
            </div>
            <div class="flex-grow-1">
              <label class="form-label fw-semibold mb-1">Foto carnet del técnico</label>
              <input type="file" name="foto_perfil" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm foto-preview-input" data-preview="prevFotoNuevo">
              <div class="form-text small">Opcional · JPG/PNG/WEBP · máx 3 MB</div>
            </div>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Nombre completo *</label>
              <input name="nombre" class="form-control" required autofocus>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Usuario *</label>
              <input name="usuario" class="form-control input-user" placeholder="jperez" required
                     autocapitalize="none" autocorrect="off" spellcheck="false"
                     title="Minúsculas, números, . o _ (3-30)">
              <div class="form-text small">minúsculas, sin espacios</div>
            </div>
            <div class="col-md-12">
              <div class="card border-primary bg-primary bg-opacity-10 mb-2">
                <div class="card-body py-2 px-3">
                  <label class="form-label fw-bold mb-1 text-primary"><i class="bi bi-person-badge"></i> Perfil del usuario *</label>
                  <select name="rol" id="rolNuevo" class="form-select form-select-lg fw-semibold">
                    <option value="usuario">Usuario (registra sus gastos)</option>
                    <option value="admin">Administrador (gestion completa del sistema)</option>
                  </select>
                  <div class="form-text small mt-1" id="rolNuevoHelp">
                    <i class="bi bi-info-circle"></i> Selecciona el rol que tendra este usuario al ingresar al sistema.
                  </div>
                </div>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">RUT</label>
              <input name="rut" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Teléfono</label>
              <input name="telefono" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Cargo</label>
              <input name="cargo" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Region</label>
              <select name="region" class="form-select">
                <option value="">- Seleccionar -</option>
                <?php foreach (regionesChile() as $r): ?>
                  <option value="<?= h($r) ?>"><?= h($r) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Ciudad</label>
              <input name="ciudad" class="form-control" placeholder="Ej. Talca, Concepcion, Temuco...">
            </div>
            <div class="col-md-12">
              <label class="form-label">Contraseña inicial</label>
              <input name="clave" type="text" class="form-control" value="123456">
              <div class="form-text small">El usuario podrá cambiarla luego desde su perfil.</div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Crear usuario</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ====================================================== -->
<!-- MODALES DE CADA USUARIO (editar + asignar)              -->
<!-- ====================================================== -->
<?php foreach ($users as $u): ?>

  <!-- Editar usuario #<?= $u['id'] ?> -->
  <div class="modal fade" id="mE<?= $u['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="accion" value="editar">
          <input type="hidden" name="id" value="<?= $u['id'] ?>">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-pencil me-1"></i>Editar · <?= h($u['nombre'] ?: $u['usuario']) ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <!-- Foto carnet -->
            <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
              <?php $urlFotoU = urlFotoUsuario($u['foto_perfil'] ?? ''); ?>
              <?php if ($urlFotoU): ?>
                <img id="prevFotoE<?= $u['id'] ?>" src="<?= h($urlFotoU) ?>" alt="Foto"
                     style="width:90px;height:115px;object-fit:cover;border:2px solid #e5e7eb;border-radius:6px;flex-shrink:0;">
              <?php else: ?>
                <div id="prevFotoE<?= $u['id'] ?>" class="d-flex align-items-center justify-content-center bg-light text-muted"
                     style="width:90px;height:115px;border:2px dashed #cbd5e1;border-radius:6px;flex-shrink:0;">
                  <i class="bi bi-person-bounding-box" style="font-size:2rem;"></i>
                </div>
              <?php endif; ?>
              <div class="flex-grow-1">
                <label class="form-label fw-semibold mb-1">Foto carnet <span class="text-muted small">(opcional, reemplaza la actual)</span></label>
                <input type="file" name="foto_perfil" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm foto-preview-input" data-preview="prevFotoE<?= $u['id'] ?>">
                <div class="form-text small">JPG/PNG/WEBP · máx 3 MB</div>
              </div>
            </div>
            <div class="row g-2">
              <div class="col-md-6"><label class="form-label">Nombre</label>
                <input name="nombre" class="form-control" value="<?= h($u['nombre']) ?>" required></div>
              <div class="col-md-3"><label class="form-label">Usuario</label>
                <input name="usuario" class="form-control input-user" value="<?= h($u['usuario']) ?>" required
                       autocapitalize="none" autocorrect="off" spellcheck="false"></div>
              <div class="col-md-3"><label class="form-label">Email</label>
                <input name="email" type="email" class="form-control" value="<?= h($u['email']) ?>" required></div>
              <div class="col-md-4"><label class="form-label">Rol</label>
                <select name="rol" class="form-select">
                  <?php foreach (['usuario','admin'] as $r): ?>
                    <option value="<?= $r ?>" <?= $u['rol']===$r?'selected':'' ?>><?= $r ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4"><label class="form-label">RUT</label>
                <input name="rut" class="form-control" value="<?= h($u['rut']) ?>"></div>
              <div class="col-md-4"><label class="form-label">Teléfono</label>
                <input name="telefono" class="form-control" value="<?= h($u['telefono']) ?>"></div>
              <div class="col-md-6"><label class="form-label">Cargo</label>
                <input name="cargo" class="form-control" value="<?= h($u['cargo']) ?>"></div>
              <div class="col-md-6"><label class="form-label">Region</label>
                <select name="region" class="form-select">
                  <option value="">- Seleccionar -</option>
                  <?php foreach (regionesChile() as $r): ?>
                    <option value="<?= h($r) ?>" <?= ($u['region']??'')===$r?'selected':'' ?>><?= h($r) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6"><label class="form-label">Ciudad</label>
                <input name="ciudad" class="form-control" value="<?= h($u['ciudad']) ?>" placeholder="Ej. Talca, Concepcion..."></div>
              <div class="col-md-6"><label class="form-label">Zona / Sector</label>
                <input name="zona" class="form-control" value="<?= h($u['zona']) ?>" placeholder="Norte, Sur, Centro..."></div>
              <div class="col-md-8"><label class="form-label">Nueva contraseña <span class="text-muted small">(vacío = no cambia)</span></label>
                <input name="nueva_clave" type="text" class="form-control"></div>
              <div class="col-md-4 d-flex align-items-end">
                <div class="form-check">
                  <input type="checkbox" class="form-check-input" name="activo" id="ac<?= $u['id'] ?>" <?= $u['activo']?'checked':'' ?>>
                  <label class="form-check-label" for="ac<?= $u['id'] ?>">Usuario activo</label>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Guardar cambios</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
  <!-- Eliminar usuario #<?= $u['id'] ?> -->
  <div class="modal fade" id="mD<?= $u['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="accion" value="eliminar">
          <input type="hidden" name="id" value="<?= $u['id'] ?>">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Eliminar usuario</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <?php $nG = $histGastos[$u['id']] ?? 0; $nC = $histCierres[$u['id']] ?? 0; ?>
            <div class="alert alert-danger small mb-3">
              <strong>Esta accion no se puede deshacer.</strong>
              <?php if ($nG > 0 || $nC > 0): ?>
                Se eliminaran tambien <strong><?= $nG ?> gasto<?= $nG===1?'':'s' ?></strong>,
                <strong><?= $nC ?> cierre<?= $nC===1?'':'s' ?> mensual<?= $nC===1?'':'es' ?></strong>,
                las asignaciones, notificaciones y todos los archivos adjuntos del usuario.
                Si prefieres preservar la trazabilidad, <em>desactiva</em> al usuario desde Editar.
              <?php else: ?>
                Tambien se eliminan asignaciones, notificaciones y archivos del usuario.
              <?php endif; ?>
            </div>
            <p class="mb-1">Vas a eliminar permanentemente a:</p>
            <p class="mb-3">
              <strong><?= h($u['nombre']) ?></strong>
              <span class="badge bg-light text-dark border ms-1"><?= h($u['usuario']) ?></span>
              <span class="badge bg-secondary ms-1"><?= h($u['rol']) ?></span>
            </p>
            <label class="form-label small">Para confirmar, escribe el nombre de usuario <code><?= h($u['usuario']) ?></code>:</label>
            <input type="text" class="form-control confirm-del" data-target="<?= h($u['usuario']) ?>" placeholder="<?= h($u['usuario']) ?>" autocomplete="off">
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button class="btn btn-danger btn-del" disabled><i class="bi bi-trash me-1"></i>Eliminar definitivamente</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($u['rol'] === 'usuario'):
    $a = $asigs[$u['id']] ?? null;
  ?>
  <!-- Asignar monto a #<?= $u['id'] ?> -->
  <div class="modal fade" id="mA<?= $u['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form method="post">
          <input type="hidden" name="accion" value="asignar">
          <input type="hidden" name="id" value="<?= $u['id'] ?>">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i>Asignar monto · <?= h($u['nombre']) ?></h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-light small mb-3">
              <strong><?= h($u['usuario']) ?></strong> · <?= h($u['email']) ?>
              <?php if ($u['zona']): ?> · Zona: <?= h($u['zona']) ?><?php endif; ?>
            </div>
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label small">Mes</label>
                <select name="mes" class="form-select">
                  <?php for($m=1;$m<=12;$m++): ?>
                    <option value="<?= $m ?>" <?= $m===$mesDef?'selected':'' ?>><?= nombreMes($m) ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small">Año</label>
                <select name="anio" class="form-select">
                  <?php for($y=date('Y')+1;$y>=date('Y')-1;$y--): ?>
                    <option value="<?= $y ?>" <?= $y===$anioDef?'selected':'' ?>><?= $y ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label small fw-semibold">Monto a asignar *</label>
                <div class="input-group input-group-lg">
                  <span class="input-group-text">$</span>
                  <input type="text" name="monto" class="form-control input-clp" inputmode="numeric"
                         value="<?= $a ? number_format($a['monto_asignado'],0,',','.') : '' ?>"
                         placeholder="ej. 500.000" required>
                </div>
              </div>
              <div class="col-12">
                <label class="form-label small">Arrastre mes anterior (opcional)</label>
                <div class="input-group">
                  <span class="input-group-text">$</span>
                  <input type="text" name="carry" class="form-control input-clp" inputmode="numeric"
                         value="<?= $a ? number_format($a['monto_carryover'],0,',','.') : '0' ?>">
                </div>
              </div>
              <div class="col-12">
                <label class="form-label small">Observación</label>
                <input type="text" name="obs" class="form-control" value="<?= h($a['observaciones'] ?? '') ?>" placeholder="opcional">
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button class="btn btn-success"><i class="bi bi-check-circle me-1"></i>Guardar asignación</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

<?php endforeach; ?>

<script>
// Normalizar campo "usuario" a minúsculas y sin caracteres inválidos en tiempo real
document.querySelectorAll('.input-user').forEach(el => {
  el.addEventListener('input', () => {
    const old = el.selectionStart;
    el.value = el.value.toLowerCase().replace(/[^a-z0-9._]/g, '');
    el.setSelectionRange(old, old);
  });
});

// Mostrar pista contextual segun el rol seleccionado al crear usuario
(function(){
  var sel = document.getElementById('rolNuevo');
  var help = document.getElementById('rolNuevoHelp');
  if (!sel || !help) return;
  var msgs = {
    usuario: '<i class="bi bi-person"></i> <b>Usuario:</b> registra sus propios gastos y consulta su saldo asignado.',
    admin:   '<i class="bi bi-gear-fill"></i> <b>Administrador:</b> gestiona usuarios, asignaciones, categorias y todos los datos del sistema.'
  };
  function actualizar(){
    help.innerHTML = msgs[sel.value] || msgs.usuario;
    var soloTec = sel.value === 'usuario';
    document.querySelectorAll('.jefe-tecnico-only').forEach(function(el){
      el.style.display = soloTec ? '' : 'none';
    });
  }
  sel.addEventListener('change', actualizar);
  actualizar();
})();

// Habilitar el boton "Eliminar definitivamente" solo cuando se escribe correctamente el usuario
document.querySelectorAll('.confirm-del').forEach(inp => {
  inp.addEventListener('input', function(){
    const target = (this.dataset.target || '').toLowerCase();
    const ok = this.value.trim().toLowerCase() === target;
    const btn = this.closest('form').querySelector('.btn-del');
    if (btn) btn.disabled = !ok;
  });
});

// Previsualizar foto carnet en los modales de crear/editar usuario
document.querySelectorAll('.foto-preview-input').forEach(inp => {
  inp.addEventListener('change', function(){
    const file = this.files && this.files[0];
    if (!file) return;
    if (file.size > 3*1024*1024) { alert('La foto supera los 3 MB.'); this.value = ''; return; }
    const targetId = this.dataset.preview;
    const old = document.getElementById(targetId);
    if (!old) return;
    const url = URL.createObjectURL(file);
    const img = document.createElement('img');
    img.id = targetId;
    img.src = url;
    img.alt = 'Foto';
    img.setAttribute('style','width:90px;height:115px;object-fit:cover;border:2px solid #0d6efd;border-radius:6px;flex-shrink:0;');
    old.parentNode.replaceChild(img, old);
  });
});
</script>

<?php include 'includes/foot.php'; ?>
