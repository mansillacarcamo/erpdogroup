<?php
require_once 'config.php';

$ppuParam = trim($_GET['ppu'] ?? '');
if (!$ppuParam) { header('Location: despacho_externo_login.php'); exit; }

/* Buscar PPU por ID numérico o por texto de patente */
if (ctype_digit($ppuParam)) {
    $stPpu = $pdo->prepare("SELECT * FROM despacho_ppu WHERE id=? AND activo=1");
    $stPpu->execute([(int)$ppuParam]);
} else {
    $stPpu = $pdo->prepare("SELECT * FROM despacho_ppu WHERE UPPER(ppu)=UPPER(?) AND activo=1");
    $stPpu->execute([$ppuParam]);
}
$ppuRow = $stPpu->fetch(PDO::FETCH_ASSOC);
if (!$ppuRow) { header('Location: despacho_externo_login.php?error=ppu_no_encontrada'); exit; }
$ppuId = $ppuRow['id'];

/* Obras para el select */
$obras = $pdo->query("SELECT id, codigo, nombre FROM obras WHERE estado='activa' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);

/* Últimas validaciones de esta PPU */
$historial = $pdo->prepare("SELECT * FROM despacho_ppu_validaciones WHERE ppu_id=? ORDER BY validado_en DESC LIMIT 20");
$historial->execute([$ppuId]);
$historial = $historial->fetchAll(PDO::FETCH_ASSOC);

$msg = null; $msgTipo = 'success';
if (!empty($_GET['msg'])) { $msg = $_GET['msg']; $msgTipo = $_GET['mt'] ?? 'success'; }

/* ── POST: Validar PPU ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  /* Puede validar un externo (Carchek) o un usuario interno */
  $validadorNombre = '';
  $validadorTipo   = 'anonimo';

  if (isExternoLoggedIn()) {
    $ext = getExternoSession();
    $validadorNombre = $ext['nombre'];
    $validadorTipo   = 'carchek';
  } elseif (!empty($_SESSION['usuario'])) {
    $validadorNombre = $_SESSION['usuario']['nombre'];
    $validadorTipo   = 'sistema';
  } else {
    $validadorNombre = trim($_POST['validador_nombre'] ?? '');
    $validadorTipo   = 'manual';
  }

  $obraId     = (int)($_POST['obra_id'] ?? 0);
  $obraRow    = null;
  if ($obraId) {
    $stO = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id=?");
    $stO->execute([$obraId]);
    $obraRow = $stO->fetch(PDO::FETCH_ASSOC);
  }
  $obraNombre = $obraRow ? $obraRow['codigo'].' — '.$obraRow['nombre'] : '';
  $obs        = trim($_POST['observacion'] ?? '');

  $pdo->prepare("INSERT INTO despacho_ppu_validaciones (ppu_id, ppu, obra_id, obra_nombre, validado_por, validado_tipo, observacion) VALUES (?,?,?,?,?,?,?)")
      ->execute([$ppuId, $ppuRow['ppu'], $obraId ?: null, $obraNombre, $validadorNombre, $validadorTipo, $obs]);

  $redirect = 'validar_ppu.php?ppu='.$ppuId.'&msg='.urlencode('✅ Validación registrada correctamente.').'&mt=success';
  header("Location: $redirect"); exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Validar PPU <?= htmlspecialchars($ppuRow['ppu']) ?> — DOGROUP</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
  body { background: #f0f4f8; font-family: 'Segoe UI', sans-serif; }
  .ppu-hero { background: #1b2838; color: #fff; border-radius: 16px; padding: 2rem; text-align: center; }
  .ppu-number { font-size: 3rem; font-weight: 900; letter-spacing: 6px; font-family: monospace; }
  .m3-badge { background: rgba(255,255,255,.15); border-radius: 8px; padding: .4rem 1rem; display: inline-block; margin-top: .5rem; font-size: 1.1rem; }
  .hist-row-carchek  { border-left: 4px solid #0d6efd; }
  .hist-row-sistema  { border-left: 4px solid #198754; }
  .hist-row-manual   { border-left: 4px solid #ffc107; }
  </style>
</head>
<body>
<div class="container py-4" style="max-width:640px;">

  <!-- Header -->
  <div class="d-flex align-items-center gap-3 mb-4">
    <img src="img/logo.png" alt="DOGROUP" style="height:40px;filter:drop-shadow(0 0 4px #0004);">
    <div>
      <h6 class="mb-0 fw-bold text-dark">DOGROUP — Validación de Patente</h6>
      <small class="text-muted"><?= date('d/m/Y H:i') ?></small>
    </div>
  </div>

  <?php if ($msg): ?>
  <div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show">
    <?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- PPU Hero -->
  <div class="ppu-hero mb-4">
    <div class="text-white-50 small mb-1"><i class="bi bi-truck me-1"></i>Camión identificado</div>
    <div class="ppu-number"><?= htmlspecialchars($ppuRow['ppu']) ?></div>
    <?php if ($ppuRow['descripcion']): ?>
    <div class="text-white-50 mt-1"><?= htmlspecialchars($ppuRow['descripcion']) ?></div>
    <?php endif; ?>
    <?php if ((float)$ppuRow['metros_cubicos'] > 0): ?>
    <div class="m3-badge"><i class="bi bi-box me-1"></i><?= number_format((float)$ppuRow['metros_cubicos'],1,',','.') ?> m³</div>
    <?php endif; ?>
  </div>

  <!-- Formulario de validación -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-success text-white fw-bold">
      <i class="bi bi-check-circle-fill me-2"></i>Confirmar Validación
    </div>
    <div class="card-body">
      <form method="POST" id="formValidar">
        <div class="mb-3">
          <label class="form-label fw-semibold">Obra asociada</label>
          <select name="obra_id" class="form-select">
            <option value="">— Sin obra específica —</option>
            <?php foreach ($obras as $o): ?>
            <?php $sel = (!empty($_SESSION['despacho_externo']['obra_id']) && $_SESSION['despacho_externo']['obra_id'] == $o['id']) ? 'selected' : ''; ?>
            <option value="<?= $o['id'] ?>" <?= $sel ?>><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if (!isExternoLoggedIn() && empty($_SESSION['usuario'])): ?>
        <div class="mb-3">
          <label class="form-label fw-semibold">Tu nombre *</label>
          <input type="text" name="validador_nombre" class="form-control" required placeholder="Nombre de quien valida">
        </div>
        <?php else: ?>
        <div class="mb-3 p-2 rounded bg-light border">
          <small class="text-muted">Validando como:</small><br>
          <strong>
            <?= htmlspecialchars(
              isExternoLoggedIn()
                ? getExternoSession()['nombre']
                : ($_SESSION['usuario']['nombre'] ?? '—')
            ) ?>
          </strong>
          <span class="badge bg-<?= isExternoLoggedIn() ? 'primary' : 'success' ?> ms-1">
            <?= isExternoLoggedIn() ? 'Carchek' : 'Sistema' ?>
          </span>
        </div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label fw-semibold">Observación <span class="text-muted fw-normal">(opcional)</span></label>
          <input type="text" name="observacion" class="form-control" placeholder="Ej: Llegada a obra, carga completa...">
        </div>

        <button type="button" class="btn btn-success btn-lg w-100 fw-bold" data-bs-toggle="modal" data-bs-target="#modalConfirm">
          <i class="bi bi-check-circle-fill me-2"></i>Validar Patente <?= htmlspecialchars($ppuRow['ppu']) ?>
        </button>
      </form>
    </div>
  </div>

  <!-- Historial -->
  <?php if (!empty($historial)): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-dark text-white fw-bold">
      <i class="bi bi-clock-history me-2"></i>Historial de Validaciones
    </div>
    <div class="card-body p-0">
      <div class="list-group list-group-flush">
      <?php foreach ($historial as $h):
        $rowClassMap = ['carchek'=>'hist-row-carchek','sistema'=>'hist-row-sistema'];
        $rowClass = isset($rowClassMap[$h['validado_tipo']]) ? $rowClassMap[$h['validado_tipo']] : 'hist-row-manual';
        $tipoBadgeMap = ['carchek'=>'<span class="badge bg-primary">Carchek</span>','sistema'=>'<span class="badge bg-success">Sistema</span>'];
        $tipoBadge = isset($tipoBadgeMap[$h['validado_tipo']]) ? $tipoBadgeMap[$h['validado_tipo']] : '<span class="badge bg-warning text-dark">Manual</span>';
      ?>
      <div class="list-group-item <?= $rowClass ?> py-2">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <strong><?= htmlspecialchars($h['validado_por'] ?: 'Anónimo') ?></strong>
            <?= $tipoBadge ?>
            <?php if ($h['obra_nombre']): ?>
            <br><small class="text-muted"><i class="bi bi-building me-1"></i><?= htmlspecialchars($h['obra_nombre']) ?></small>
            <?php endif; ?>
            <?php if ($h['observacion']): ?>
            <br><small class="text-muted fst-italic"><?= htmlspecialchars($h['observacion']) ?></small>
            <?php endif; ?>
          </div>
          <small class="text-muted text-nowrap ms-2"><?= date('d/m/Y H:i', strtotime($h['validado_en'])) ?></small>
        </div>
      </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <p class="text-center text-muted mt-4 small">DOGROUP &copy; <?= date('Y') ?> — Departamento de Informática DOGroup</p>
</div>

<!-- Modal Confirmar -->
<div class="modal fade" id="modalConfirm" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-success text-white border-0">
        <h5 class="modal-title"><i class="bi bi-truck me-2"></i>Confirmar Validación</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center py-4">
        <div class="display-4 fw-bold mb-2" style="font-family:monospace;letter-spacing:4px;"><?= htmlspecialchars($ppuRow['ppu']) ?></div>
        <?php if ((float)$ppuRow['metros_cubicos'] > 0): ?>
        <div class="badge bg-info text-dark mb-3 px-3 py-2"><i class="bi bi-box me-1"></i><?= number_format((float)$ppuRow['metros_cubicos'],1,',','.') ?> m³</div>
        <?php endif; ?>
        <p class="text-muted mb-0">¿Confirmas la validación de esta patente a las <strong><?= date('H:i') ?></strong>?</p>
      </div>
      <div class="modal-footer border-0 justify-content-center gap-3">
        <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-success px-4 fw-bold" onclick="document.getElementById('formValidar').submit()">
          <i class="bi bi-check-circle-fill me-2"></i>Confirmar
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
