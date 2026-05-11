<?php
require_once 'config.php';
requireAuth();

$uid = (int)$_SESSION['user_id'];
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// Verifica que el gasto sea del usuario y esté en estado editable
$q = $pdo->prepare("SELECT * FROM gastos WHERE id=? AND usuario_id=?");
$q->execute([$id, $uid]);
$g = $q->fetch();

if (!$g) { flash('error','Gasto no encontrado.'); header('Location: mis_gastos.php'); exit; }
if ($g['estado'] !== 'registrado') {
    flash('error','No puedes modificar un gasto que ya fue enviado o aprobado.');
    header('Location: mis_gastos.php'); exit;
}

$cats = $pdo->query("SELECT nombre FROM categorias_gasto WHERE activo=1 ORDER BY nombre")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $monto       = parseMonto($_POST['monto'] ?? '');
    $fecha       = $_POST['fecha'] ?? date('Y-m-d');
    $cat         = trim($_POST['categoria'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $proveedor   = trim($_POST['proveedor'] ?? '');
    $numdoc      = trim($_POST['numero_documento'] ?? '');
    $tipodoc     = $_POST['tipo_documento'] ?? 'boleta';

    if ($monto <= 0) { flash('error','El monto debe ser mayor a 0.'); header('Location: gasto_editar.php?id='.$id); exit; }
    if (!$fecha)     { flash('error','Indica la fecha del gasto.');   header('Location: gasto_editar.php?id='.$id); exit; }

    $pdo->prepare("UPDATE gastos SET fecha=?, monto=?, categoria=?, descripcion=?, proveedor=?, numero_documento=?, tipo_documento=? WHERE id=? AND usuario_id=?")
        ->execute([$fecha,$monto,$cat,$descripcion,$proveedor,$numdoc,$tipodoc,$id,$uid]);

    // Nuevos adjuntos (se suman a los existentes)
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
            $nuevo = 'g' . $id . '_' . time() . '_' . $i . '.' . $ext;
            if (move_uploaded_file($tmp, $dir . '/' . $nuevo)) {
                $pdo->prepare("INSERT INTO archivos_gasto (gasto_id,nombre_archivo,nombre_original,tipo) VALUES (?,?,?,?)")
                    ->execute([$id, 'u'.$uid.'/'.$nuevo, $orig, $type]);
            }
        }
    }

    flash('exito','Gasto actualizado correctamente.');
    header('Location: mis_gastos.php');
    exit;
}

// Adjuntos existentes
$ar = $pdo->prepare("SELECT * FROM archivos_gasto WHERE gasto_id=? ORDER BY id");
$ar->execute([$id]);
$archivos = $ar->fetchAll();

$mu = $pdo->prepare("SELECT nombre, usuario, ciudad, region, zona, cargo, foto_perfil FROM usuarios WHERE id=?");
$mu->execute([$uid]); $mu = $mu->fetch();

$titulo = 'Editar gasto';
include 'includes/head.php';
include 'includes/nav.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-lg-8">
    <a href="mis_gastos.php" class="text-decoration-none small mb-2 d-inline-block">
      <i class="bi bi-arrow-left"></i> Volver a mis gastos
    </a>

    <div class="card mb-3 border-0 bg-light">
      <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
        <?php $urlFotoGe = urlFotoUsuario($mu['foto_perfil'] ?? ''); ?>
        <?php if ($urlFotoGe): ?>
          <img src="<?= h($urlFotoGe) ?>" alt="<?= h($mu['nombre']) ?>" class="tecnico-avatar-sm" style="object-fit:cover;">
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

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-pencil-square me-1"></i>Editar gasto</span>
        <span class="estado <?= h($g['estado']) ?>"><?= h($g['estado']) ?></span>
      </div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" autocomplete="off">
          <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Monto (CLP) *</label>
              <div class="input-group input-group-lg">
                <span class="input-group-text">$</span>
                <input type="text" name="monto" class="form-control input-clp" inputmode="numeric" required
                       value="<?= number_format((float)$g['monto'],0,',','.') ?>">
              </div>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Fecha *</label>
              <input type="date" name="fecha" class="form-control form-control-lg"
                     value="<?= h($g['fecha']) ?>" required>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Categoría</label>
              <select name="categoria" class="form-select form-select-lg">
                <option value="">— Seleccionar —</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= h($c['nombre']) ?>" <?= $g['categoria']===$c['nombre']?'selected':'' ?>><?= h($c['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Tipo documento</label>
              <select name="tipo_documento" class="form-select form-select-lg">
                <?php foreach (['boleta'=>'Boleta','factura'=>'Factura','ticket'=>'Ticket','otro'=>'Otro'] as $k=>$v): ?>
                  <option value="<?= $k ?>" <?= $g['tipo_documento']===$k?'selected':'' ?>><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">Proveedor / Comercio</label>
              <input type="text" name="proveedor" class="form-control" value="<?= h($g['proveedor']) ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label fw-semibold">N° Documento</label>
              <input type="text" name="numero_documento" class="form-control" value="<?= h($g['numero_documento']) ?>">
            </div>

            <div class="col-12">
              <label class="form-label fw-semibold">Descripción / Detalle</label>
              <textarea name="descripcion" class="form-control" rows="2"><?= h($g['descripcion']) ?></textarea>
            </div>

            <?php if ($archivos): ?>
            <div class="col-12">
              <label class="form-label fw-semibold"><i class="bi bi-paperclip me-1"></i>Adjuntos actuales</label>
              <div class="d-flex flex-wrap gap-2">
                <?php foreach ($archivos as $a):
                  $url = 'uploads/' . $a['nombre_archivo'];
                  $isImg = preg_match('/\.(jpg|jpeg|png|webp|heic|heif)$/i', $a['nombre_archivo']);
                ?>
                  <div class="position-relative">
                    <a href="<?= h($url) ?>" target="_blank">
                      <?php if ($isImg): ?>
                        <img src="<?= h($url) ?>" class="thumb">
                      <?php else: ?>
                        <div class="thumb d-flex align-items-center justify-content-center bg-light text-secondary">
                          <i class="bi bi-file-earmark-pdf" style="font-size:2rem;"></i>
                        </div>
                      <?php endif; ?>
                    </a>
                    <a href="adjunto_eliminar.php?id=<?= (int)$a['id'] ?>&gasto=<?= (int)$g['id'] ?>"
                       class="btn btn-sm btn-danger position-absolute top-0 end-0"
                       data-confirm="¿Eliminar este adjunto?"
                       style="padding:2px 6px; font-size:.7rem; border-radius:50%;">
                      <i class="bi bi-x"></i>
                    </a>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <?php endif; ?>

            <div class="col-12">
              <label class="form-label fw-semibold">
                <i class="bi bi-camera me-1"></i>Agregar fotos / adjuntos (opcional)
              </label>
              <div class="dropzone">
                <input type="file" name="archivos[]" accept="image/*,application/pdf" capture="environment"
                       multiple class="form-control" data-preview="#preview">
                <div class="small text-muted mt-2">
                  Los archivos actuales no se borran. Puedes agregar más.
                </div>
              </div>
              <div id="preview" class="mt-2 d-flex flex-wrap"></div>
            </div>
          </div>

          <hr class="my-4">
          <div class="d-flex gap-2">
            <button class="btn btn-primary btn-lg flex-grow-1">
              <i class="bi bi-check-circle me-1"></i> Guardar cambios
            </button>
            <a href="mis_gastos.php" class="btn btn-outline-secondary btn-lg">Cancelar</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include 'includes/foot.php'; ?>
