<?php
require_once 'config.php';
requireAuth();

$uid = (int)$_SESSION['user_id'];
$cats = $pdo->query("SELECT nombre FROM categorias_gasto WHERE activo=1 ORDER BY nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $monto       = parseMonto($_POST['monto'] ?? '');
    $fecha       = $_POST['fecha'] ?? date('Y-m-d');
    $cat         = trim($_POST['categoria'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $proveedor   = trim($_POST['proveedor'] ?? '');
    $numdoc      = trim($_POST['numero_documento'] ?? '');
    $tipodoc     = $_POST['tipo_documento'] ?? 'boleta';

    if ($monto <= 0) { flash('error','El monto debe ser mayor a 0.'); header('Location: gasto_nuevo.php'); exit; }
    if (!$fecha)     { flash('error','Indica la fecha del gasto.');   header('Location: gasto_nuevo.php'); exit; }

    $pdo->prepare("INSERT INTO gastos (usuario_id,fecha,monto,categoria,descripcion,proveedor,numero_documento,tipo_documento,estado)
                   VALUES (?,?,?,?,?,?,?,?, 'registrado')")
        ->execute([$uid,$fecha,$monto,$cat,$descripcion,$proveedor,$numdoc,$tipodoc]);
    $gid = (int)$pdo->lastInsertId();

    if (!empty($_FILES['archivos']['name'][0])) {
        $dir = __DIR__ . '/uploads/u' . $uid;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $cnt = count($_FILES['archivos']['name']);
        for ($i=0; $i<$cnt; $i++) {
            if ($_FILES['archivos']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $orig = $_FILES['archivos']['name'][$i];
            $tmp  = $_FILES['archivos']['tmp_name'][$i];
            $type = $_FILES['archivos']['type'][$i];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','webp','heic','heif','pdf'];
            if (!in_array($ext, $allowed)) continue;
            $nuevo = 'g' . $gid . '_' . time() . '_' . $i . '.' . $ext;
            if (move_uploaded_file($tmp, $dir . '/' . $nuevo)) {
                $pdo->prepare("INSERT INTO archivos_gasto (gasto_id,nombre_archivo,nombre_original,tipo) VALUES (?,?,?,?)")
                    ->execute([$gid, 'u'.$uid.'/'.$nuevo, $orig, $type]);
            }
        }
    }

    /* ── Notificación tipo WhatsApp para todos los admins ── */
    try {
      $stU = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = ?");
      $stU->execute([$uid]);
      $nombreUser = (string)$stU->fetchColumn();
      $resumen = sprintf(
        '%s registró un gasto de $%s · %s%s%s',
        $nombreUser,
        number_format($monto, 0, ',', '.'),
        $cat ?: 'Sin categoría',
        $descripcion ? " — \"".mb_strimwidth($descripcion, 0, 70, '…')."\"" : '',
        $proveedor   ? ' · '.$proveedor : ''
      );
      $admins = $pdo->query("SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1")->fetchAll(PDO::FETCH_COLUMN);
      $stN = $pdo->prepare("INSERT INTO notificaciones (usuario_id, titulo, mensaje, tipo, leida, enlace, creado_en)
                            VALUES (?, ?, ?, 'gasto_nuevo', 0, ?, datetime('now','localtime'))");
      foreach ($admins as $aid) {
        $stN->execute([(int)$aid, '💰 Nuevo gasto registrado', $resumen, 'gasto_ver.php?id='.$gid]);
      }
    } catch (Exception $e) { /* no bloquear el flujo si la notificación falla */ }

    flash('exito','Gasto registrado correctamente.');
    header('Location: mis_gastos.php');
    exit;
}

$mu = $pdo->prepare("SELECT nombre, usuario, ciudad, region, zona, cargo, foto_perfil FROM usuarios WHERE id=?");
$mu->execute([$uid]); $mu = $mu->fetch();

// Saldo actual del usuario en el periodo
$asigGN  = asignacionPeriodo($pdo, $uid);
$gastGN  = totalGastadoPeriodo($pdo, $uid);
$totalGN = (float)($asigGN['total'] ?? 0);
$saldoGN = $totalGN - $gastGN;
$pGN     = periodoActual();

$titulo = 'Registrar gasto';
include 'includes/head.php';
include 'includes/nav.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-lg-8">
    <div class="card mb-3 border-0 bg-light">
      <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
        <?php $urlFotoGn = urlFotoUsuario($mu['foto_perfil'] ?? ''); ?>
        <?php if ($urlFotoGn): ?>
          <img src="<?= h($urlFotoGn) ?>" alt="<?= h($mu['nombre']) ?>" class="tecnico-avatar-sm" style="object-fit:cover;">
        <?php else: ?>
          <div class="tecnico-avatar-sm"><?= strtoupper(mb_substr($mu['nombre'] ?: 'U',0,1)) ?></div>
        <?php endif; ?>
        <div class="flex-grow-1">
          <div class="fw-bold small"><?= h($mu['nombre']) ?></div>
          <div class="small text-muted">
            <?php if ($mu['region']): ?><i class="bi bi-geo-alt-fill"></i> <?= h($mu['region']) ?><?php endif; ?>
            <?php if ($mu['ciudad']): ?> - <?= h($mu['ciudad']) ?><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <style>.tecnico-avatar-sm{ width:38px; height:38px; border-radius:50%; background:linear-gradient(135deg,#0d6efd,#6610f2); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; }</style>

    <!-- Saldo del periodo: muestra cuanto queda y cuanto quedara tras este gasto -->
    <div class="card mb-3 saldo-gasto-card border-0">
      <div class="card-body py-3 px-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <div class="small text-muted text-uppercase fw-semibold" style="letter-spacing:.5px;">
              Saldo disponible · <?= nombreMes($pGN['mes']) ?> <?= $pGN['anio'] ?>
            </div>
            <div class="fs-3 fw-bold text-<?= $saldoGN<=0 ? 'danger' : ($saldoGN < $totalGN*0.2 ? 'warning' : 'success') ?>" id="saldoActualBox">
              <?= fmtCLP($saldoGN) ?>
            </div>
            <div class="small text-muted">
              de <?= fmtCLP($totalGN) ?> asignado · gastado <?= fmtCLP($gastGN) ?>
            </div>
          </div>
          <div class="text-end" id="saldoTrasBox" style="display:none;">
            <div class="small text-muted text-uppercase fw-semibold" style="letter-spacing:.5px;">
              Saldo tras este gasto
            </div>
            <div class="fs-4 fw-bold" id="saldoTras">—</div>
            <div class="small text-muted" id="saldoTrasInfo"></div>
          </div>
        </div>
      </div>
    </div>
    <style>
      .saldo-gasto-card{ background:linear-gradient(135deg,#e0f2fe 0%,#f0f9ff 100%); border-left:4px solid #0d6efd !important; }
    </style>

    <div class="card">
      <div class="card-header"><i class="bi bi-plus-circle me-1"></i>Registrar nuevo gasto</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" autocomplete="off">

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Monto (CLP) *</label>
              <div class="input-group input-group-lg">
                <span class="input-group-text">$</span>
                <input type="text" name="monto" class="form-control input-clp" inputmode="numeric" required placeholder="0">
              </div>
              <div class="form-text">Ingresa el monto total de la boleta.</div>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Fecha *</label>
              <input type="date" name="fecha" class="form-control form-control-lg" value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Categoría</label>
              <select name="categoria" class="form-select form-select-lg">
                <option value="">— Seleccionar —</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= htmlspecialchars($c['nombre']) ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Tipo documento</label>
              <select name="tipo_documento" class="form-select form-select-lg">
                <option value="boleta">Boleta</option>
                <option value="factura">Factura</option>
                <option value="ticket">Ticket</option>
                <option value="otro">Otro</option>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Proveedor / Comercio</label>
              <input type="text" name="proveedor" class="form-control" placeholder="Ej. Copec, Líder...">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">N° Documento</label>
              <input type="text" name="numero_documento" class="form-control" placeholder="N° boleta/factura">
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold">Descripción / Detalle</label>
              <textarea name="descripcion" class="form-control" rows="2" placeholder="¿En qué se gastó? (opcional)"></textarea>
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold">
                <i class="bi bi-camera me-1"></i>Foto de la boleta
              </label>
              <div class="dropzone">
                <input type="file" name="archivos[]" accept="image/*,application/pdf" capture="environment"
                       multiple class="form-control" data-preview="#preview">
                <div class="small text-muted mt-2">
                  Toca para elegir o tomar una foto desde tu cámara. Puedes adjuntar varias.
                </div>
              </div>
              <div id="preview" class="mt-2 d-flex flex-wrap"></div>
            </div>
          </div>

          <hr class="my-4">
          <div class="d-flex gap-2">
            <button class="btn btn-primary btn-lg flex-grow-1">
              <i class="bi bi-check-circle me-1"></i> Guardar gasto
            </button>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-lg">Cancelar</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
// Calcular en vivo el saldo que quedara tras ingresar este gasto
(function(){
  var saldoActual = <?= (int)round($saldoGN) ?>;
  var inp = document.querySelector('input[name="monto"]');
  var box = document.getElementById('saldoTrasBox');
  var out = document.getElementById('saldoTras');
  var info = document.getElementById('saldoTrasInfo');
  if (!inp || !box || !out) return;

  function fmtCLP(n){
    var sign = n < 0 ? '-' : '';
    n = Math.abs(Math.round(n));
    return sign + '$' + n.toLocaleString('es-CL').replace(/,/g,'.');
  }

  function actualizar(){
    var raw = (inp.value || '').replace(/[^0-9]/g,'');
    var monto = parseInt(raw, 10) || 0;
    if (monto <= 0) { box.style.display = 'none'; return; }
    box.style.display = '';
    var nuevo = saldoActual - monto;
    out.textContent = fmtCLP(nuevo);
    out.className = 'fs-4 fw-bold text-' + (nuevo < 0 ? 'danger' : (nuevo < <?= (int)round($totalGN*0.2) ?> ? 'warning' : 'success'));
    if (nuevo < 0) {
      info.textContent = 'Excede tu presupuesto en ' + fmtCLP(Math.abs(nuevo));
    } else {
      info.textContent = 'Te quedaran disponibles';
    }
  }

  inp.addEventListener('input', actualizar);
  inp.addEventListener('change', actualizar);
  actualizar();
})();
</script>

<?php include 'includes/foot.php'; ?>
