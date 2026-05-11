<?php
require_once 'config.php';
requireAuth();
$uid = (int)$_SESSION['user_id'];

// Admin: guardar presupuesto total del mes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_presupuesto'
    && ($_SESSION['user_rol'] ?? '') === 'admin') {
    $anioP = (int)($_POST['anio'] ?? 0);
    $mesP  = (int)($_POST['mes']  ?? 0);
    $monto = parseMonto($_POST['monto_total'] ?? 0);
    if ($anioP && $mesP >= 1 && $mesP <= 12) {
        $q = $pdo->prepare("SELECT id FROM presupuesto_mes WHERE anio=? AND mes=?");
        $q->execute([$anioP, $mesP]);
        if ($id = $q->fetchColumn()) {
            $pdo->prepare("UPDATE presupuesto_mes SET monto_total=?, actualizado_en=CURRENT_TIMESTAMP, actualizado_por=? WHERE id=?")
                ->execute([$monto, $uid, $id]);
        } else {
            $pdo->prepare("INSERT INTO presupuesto_mes (anio,mes,monto_total,actualizado_por) VALUES (?,?,?,?)")
                ->execute([$anioP, $mesP, $monto, $uid]);
        }
        flash('exito', 'Presupuesto de ' . nombreMes($mesP) . ' ' . $anioP . ' actualizado: ' . fmtCLP($monto));
    } else {
        flash('error', 'Periodo invalido.');
    }
    header('Location: perfil.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Si el form no envia zona/ciudad/region (caso admin), preservar los valores actuales
    if (isset($_POST['zona']) && isset($_POST['ciudad']) && isset($_POST['region'])) {
        $pdo->prepare("UPDATE usuarios SET nombre=?, telefono=?, rut=?, cargo=?, zona=?, ciudad=?, region=? WHERE id=?")
            ->execute([
                trim($_POST['nombre'] ?? ''),
                trim($_POST['telefono'] ?? ''),
                trim($_POST['rut'] ?? ''),
                trim($_POST['cargo'] ?? ''),
                trim($_POST['zona'] ?? ''),
                trim($_POST['ciudad'] ?? ''),
                trim($_POST['region'] ?? ''),
                $uid
            ]);
    } else {
        $pdo->prepare("UPDATE usuarios SET nombre=?, telefono=?, rut=?, cargo=? WHERE id=?")
            ->execute([
                trim($_POST['nombre'] ?? ''),
                trim($_POST['telefono'] ?? ''),
                trim($_POST['rut'] ?? ''),
                trim($_POST['cargo'] ?? ''),
                $uid
            ]);
    }

    // Foto de perfil (tamaño carnet)
    try {
        $fotoActual = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id=?");
        $fotoActual->execute([$uid]);
        $fotoActual = $fotoActual->fetchColumn();
        $nuevaFoto = procesarFotoPerfil('foto_perfil', $uid, $fotoActual);
        if ($nuevaFoto) {
            $pdo->prepare("UPDATE usuarios SET foto_perfil=? WHERE id=?")->execute([$nuevaFoto, $uid]);
            flash('exito','Foto de perfil actualizada.');
        }
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }

    if (!empty($_POST['nueva_clave'])) {
        if ($_POST['nueva_clave'] !== ($_POST['clave_rep'] ?? '')) {
            flash('error','Las contraseñas no coinciden.');
        } else {
            $pdo->prepare("UPDATE usuarios SET clave=? WHERE id=?")
                ->execute([password_hash($_POST['nueva_clave'],PASSWORD_DEFAULT),$uid]);
            flash('exito','Contraseña actualizada.');
        }
    }
    $_SESSION['user_nombre'] = trim($_POST['nombre'] ?? '');
    flash('exito','Perfil actualizado.');
    header('Location: perfil.php'); exit;
}
$u = $pdo->prepare("SELECT * FROM usuarios WHERE id=?"); $u->execute([$uid]); $u = $u->fetch();

// Asignacion del periodo actual (solo lectura: la asigna el Administrador)
$p = periodoActual();
$asig = asignacionPeriodo($pdo, $uid, $p['anio'], $p['mes']);
$gastadoAct = totalGastadoPeriodo($pdo, $uid, $p['anio'], $p['mes']);
$saldoAct = ($asig['total'] ?? 0) - $gastadoAct;

// Si es admin: presupuesto total del mes y resumen de distribucion
$esAdmin = ($u['rol'] === 'admin');
$presupuestoMes = 0;
$totalDistribuido = 0;
$disponibleDistribuir = 0;
$nUsuariosAsignados = 0;
$totalGastadoMes = 0;
if ($esAdmin) {
    $qp = $pdo->prepare("SELECT monto_total FROM presupuesto_mes WHERE anio=? AND mes=?");
    $qp->execute([$p['anio'], $p['mes']]);
    $presupuestoMes = (float)($qp->fetchColumn() ?: 0);

    $qd = $pdo->prepare("SELECT COALESCE(SUM(monto_asignado + monto_carryover),0) as tot, COUNT(*) as n FROM asignaciones WHERE anio=? AND mes=?");
    $qd->execute([$p['anio'], $p['mes']]);
    $rd = $qd->fetch();
    $totalDistribuido    = (float)$rd['tot'];
    $nUsuariosAsignados  = (int)$rd['n'];
    $disponibleDistribuir = $presupuestoMes - $totalDistribuido;

    $ini = sprintf('%04d-%02d-01', $p['anio'], $p['mes']);
    $fin = date('Y-m-t', strtotime($ini));
    $qg = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE fecha BETWEEN ? AND ?");
    $qg->execute([$ini, $fin]);
    $totalGastadoMes = (float)$qg->fetchColumn();
}

$titulo = 'Mi perfil';
include 'includes/head.php'; include 'includes/nav.php';
?>
<div class="row justify-content-center"><div class="col-12 col-lg-7">

<?php if ($esAdmin): ?>
<!-- Panel del administrador: presupuesto total a distribuir -->
<?php
  $pctDistr = $presupuestoMes > 0 ? round($totalDistribuido*100/$presupuestoMes, 1) : 0;
?>
<div class="card mb-3 border-0 shadow-sm">
  <div class="card-header text-white d-flex justify-content-between align-items-center"
       style="background:linear-gradient(135deg,#0d6efd 0%,#6610f2 100%);">
    <span><i class="bi bi-wallet-fill me-1"></i>Presupuesto a distribuir - <?= nombreMes($p['mes']) ?> <?= $p['anio'] ?></span>
    <button type="button" class="btn btn-light btn-sm" data-bs-toggle="modal" data-bs-target="#mPresup">
      <i class="bi bi-pencil"></i> <?= $presupuestoMes>0 ? 'Editar' : 'Definir' ?>
    </button>
  </div>
  <div class="card-body">
    <div class="row g-2 text-center mb-3">
      <div class="col-12 col-md-3">
        <div class="text-muted small text-uppercase">Total a distribuir</div>
        <div class="fw-bold fs-4 text-primary text-nowrap"><?= fmtCLP($presupuestoMes) ?></div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-muted small text-uppercase">Distribuido</div>
        <div class="fw-bold fs-5 text-info text-nowrap"><?= fmtCLP($totalDistribuido) ?></div>
        <div class="small text-muted"><?= $nUsuariosAsignados ?> usuario(s)</div>
      </div>
      <div class="col-6 col-md-3">
        <div class="text-muted small text-uppercase">Disponible</div>
        <div class="fw-bold fs-5 text-nowrap text-<?= $disponibleDistribuir<0?'danger':'success' ?>"><?= fmtCLP($disponibleDistribuir) ?></div>
        <div class="small text-muted">por asignar</div>
      </div>
      <div class="col-12 col-md-3">
        <div class="text-muted small text-uppercase">Gastado por equipo</div>
        <div class="fw-bold fs-5 text-danger text-nowrap"><?= fmtCLP($totalGastadoMes) ?></div>
      </div>
    </div>
    <?php if ($presupuestoMes > 0): ?>
      <div class="d-flex justify-content-between small text-muted mb-1">
        <span>Avance de distribucion</span>
        <span class="fw-bold"><?= $pctDistr ?>%</span>
      </div>
      <div class="progress" style="height:10px;">
        <div class="progress-bar bg-<?= $pctDistr>=100?'danger':($pctDistr>=80?'warning':'primary') ?>" style="width:<?= min(100,$pctDistr) ?>%"></div>
      </div>
      <?php if ($disponibleDistribuir < 0): ?>
        <div class="alert alert-danger small mt-3 mb-0 py-2">
          <i class="bi bi-exclamation-triangle-fill"></i>
          Has distribuido <?= fmtCLP(abs($disponibleDistribuir)) ?> mas que el presupuesto. Aumenta el total o reduce alguna asignacion.
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="alert alert-warning small mb-0 py-2">
        <i class="bi bi-info-circle-fill"></i>
        Aun no defines el presupuesto del mes. Haz click en <strong>Definir</strong> para empezar a distribuirlo entre los usuarios.
      </div>
    <?php endif; ?>
    <div class="d-flex gap-2 mt-3">
      <a href="admin_asignaciones.php" class="btn btn-outline-primary btn-sm flex-grow-1">
        <i class="bi bi-cash-coin"></i> Ir a asignaciones
      </a>
      <a href="admin_gastos.php" class="btn btn-outline-secondary btn-sm flex-grow-1">
        <i class="bi bi-bar-chart-line"></i> Ver gastos del mes
      </a>
    </div>
  </div>
</div>

<!-- Modal: definir/editar presupuesto -->
<div class="modal fade" id="mPresup" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="accion" value="guardar_presupuesto">
        <input type="hidden" name="anio" value="<?= $p['anio'] ?>">
        <input type="hidden" name="mes"  value="<?= $p['mes'] ?>">
        <div class="modal-header text-white" style="background:linear-gradient(135deg,#0d6efd 0%,#6610f2 100%);">
          <h5 class="modal-title"><i class="bi bi-wallet-fill me-1"></i>Presupuesto del mes</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="small text-muted">Define el monto total a distribuir entre los usuarios para
            <strong><?= nombreMes($p['mes']) ?> <?= $p['anio'] ?></strong>.</p>
          <label class="form-label fw-semibold">Monto total (CLP)</label>
          <div class="input-group input-group-lg">
            <span class="input-group-text">$</span>
            <input type="text" name="monto_total" class="form-control input-clp" inputmode="numeric" required
                   value="<?= $presupuestoMes>0 ? number_format($presupuestoMes,0,',','.') : '' ?>" placeholder="Ej. 5.000.000">
          </div>
          <div class="form-text small">El sistema usara esto como referencia. Las asignaciones que superen este monto seran marcadas como excedidas.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary"><i class="bi bi-check-circle me-1"></i>Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php else: ?>
<div class="card mb-3 border-0 shadow-sm">
  <div class="card-header bg-light d-flex justify-content-between align-items-center">
    <span><i class="bi bi-cash-coin me-1 text-success"></i>Monto asignado - <?= nombreMes($p['mes']) ?> <?= $p['anio'] ?></span>
    <span class="badge bg-secondary"><i class="bi bi-lock-fill"></i> Solo lectura</span>
  </div>
  <div class="card-body">
    <div class="row g-2 text-center">
      <div class="col-4">
        <div class="text-muted small text-uppercase">Asignado</div>
        <div class="fw-bold fs-5 text-nowrap"><?= fmtCLP($asig['total'] ?? 0) ?></div>
      </div>
      <div class="col-4">
        <div class="text-muted small text-uppercase">Gastado</div>
        <div class="fw-bold fs-5 text-danger text-nowrap"><?= fmtCLP($gastadoAct) ?></div>
      </div>
      <div class="col-4">
        <div class="text-muted small text-uppercase">Saldo</div>
        <div class="fw-bold fs-5 text-nowrap text-<?= $saldoAct<0?'danger':'success' ?>"><?= fmtCLP($saldoAct) ?></div>
      </div>
    </div>
    <div class="alert alert-info small mt-3 mb-0 py-2">
      <i class="bi bi-info-circle-fill"></i>
      El monto asignado es establecido por el Administrador. Si necesitas un ajuste, solicitalo.
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card"><div class="card-header"><i class="bi bi-person-circle me-1"></i>Mi perfil</div>
<div class="card-body">
<form method="post" enctype="multipart/form-data">
  <!-- Foto de perfil tamaño carnet -->
  <div class="text-center mb-4 pb-3 border-bottom">
    <?php $urlFoto = urlFotoUsuario($u['foto_perfil'] ?? ''); ?>
    <div class="position-relative d-inline-block mb-2">
      <?php if ($urlFoto): ?>
        <img id="previewFoto" src="<?= h($urlFoto) ?>" alt="Foto de perfil"
             style="width:220px;height:280px;object-fit:cover;border:4px solid #e5e7eb;border-radius:12px;box-shadow:0 4px 14px rgba(0,0,0,0.12);">
      <?php else: ?>
        <div id="previewFoto" class="d-flex align-items-center justify-content-center bg-light text-muted"
             style="width:220px;height:280px;border:4px dashed #cbd5e1;border-radius:12px;">
          <div class="text-center"><i class="bi bi-person-bounding-box" style="font-size:4.5rem;"></i><div class="small mt-2">Sin foto</div></div>
        </div>
      <?php endif; ?>
    </div>
    <div>
      <label for="fotoInput" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-camera"></i> <?= $urlFoto ? 'Cambiar foto' : 'Subir foto carnet' ?>
      </label>
      <input id="fotoInput" type="file" name="foto_perfil" accept="image/jpeg,image/png,image/webp" class="d-none">
      <div class="form-text small mt-1">Formato tamaño carnet · JPG/PNG · máx 3 MB</div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-8">
      <label class="form-label">Nombre</label>
      <input name="nombre" class="form-control" value="<?= h($u['nombre']) ?>" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Usuario</label>
      <input class="form-control" value="<?= h($u['usuario']) ?>" disabled>
    </div>
    <div class="col-md-6">
      <label class="form-label">Email</label>
      <input class="form-control" value="<?= h($u['email']) ?>" disabled>
    </div>
    <div class="col-md-6">
      <label class="form-label">Teléfono</label>
      <input name="telefono" class="form-control" value="<?= h($u['telefono']) ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">RUT</label>
      <input name="rut" class="form-control" value="<?= h($u['rut']) ?>">
    </div>
    <?php if (!$esAdmin): ?>
    <div class="col-md-6">
      <label class="form-label">Region</label>
      <select name="region" class="form-select">
        <option value="">- Seleccionar -</option>
        <?php foreach (regionesChile() as $r): ?>
          <option value="<?= h($r) ?>" <?= ($u['region']??'')===$r?'selected':'' ?>><?= h($r) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-6">
      <label class="form-label">Ciudad</label>
      <input name="ciudad" class="form-control" value="<?= h($u['ciudad']) ?>" placeholder="Ej. Talca, Concepcion...">
    </div>
    <div class="col-md-6">
      <label class="form-label">Zona / Sector</label>
      <input name="zona" class="form-control" value="<?= h($u['zona']) ?>" placeholder="Norte, Sur, Centro...">
    </div>
    <?php endif; ?>
    <div class="col-md-6">
      <label class="form-label">Cargo</label>
      <input name="cargo" class="form-control" value="<?= h($u['cargo']) ?>">
    </div>
  </div>
  <hr>
  <h6 class="fw-bold">Cambiar contraseña</h6>
  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Nueva contraseña</label>
      <input name="nueva_clave" type="password" class="form-control" minlength="6" autocomplete="new-password">
    </div>
    <div class="col-md-6">
      <label class="form-label">Repetir</label>
      <input name="clave_rep" type="password" class="form-control" minlength="6" autocomplete="new-password">
    </div>
  </div>
  <button class="btn btn-primary w-100 mt-3"><i class="bi bi-check-circle"></i> Guardar</button>
</form>
</div></div>
</div></div>

<script>
// Previsualizar la foto carnet antes de subirla
(function(){
  var input = document.getElementById('fotoInput');
  if (!input) return;
  input.addEventListener('change', function(){
    var file = this.files && this.files[0];
    if (!file) return;
    if (file.size > 3*1024*1024) { alert('La foto supera los 3 MB.'); this.value = ''; return; }
    var url = URL.createObjectURL(file);
    var old = document.getElementById('previewFoto');
    var img = document.createElement('img');
    img.id = 'previewFoto';
    img.src = url;
    img.alt = 'Foto de perfil';
    img.setAttribute('style','width:220px;height:280px;object-fit:cover;border:4px solid #0d6efd;border-radius:12px;box-shadow:0 4px 14px rgba(0,0,0,0.12);');
    old.parentNode.replaceChild(img, old);
  });
})();
</script>

<?php include 'includes/foot.php'; ?>
