<?php
require_once 'config.php';
requireAuth();
$rol = $_SESSION['user_rol'] ?? '';
$miId = (int)($_SESSION['user_id'] ?? 0);

// Si el rol es "usuario", siempre trabaja con su propio cierre (seguridad)
if ($rol === 'usuario') {
    $uid = $miId;
} else {
    $uid = (int)($_GET['u'] ?? $_POST['u'] ?? 0);
    if (!in_array($rol, ['admin','jefe','validador'])) { http_response_code(403); die('Sin permiso.'); }
}

$anio = (int)($_GET['anio'] ?? $_POST['anio'] ?? 0);
$mes  = (int)($_GET['mes']  ?? $_POST['mes']  ?? 0);

$u = $pdo->prepare("SELECT * FROM usuarios WHERE id=?"); $u->execute([$uid]); $u = $u->fetch();
if (!$u) die('Usuario no encontrado.');

// Si es tecnico, precargar el email del jefe zonal como destinatario sugerido
$emailJefeSugerido = '';
$nombreJefe = '';
if ($rol === 'usuario' && !empty($u['jefe_zonal_id'])) {
    $qj = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE id=?");
    $qj->execute([(int)$u['jefe_zonal_id']]);
    if ($j = $qj->fetch()) { $emailJefeSugerido = $j['email'] ?? ''; $nombreJefe = $j['nombre'] ?? ''; }
}

$enviado = false; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $destino = trim($_POST['destino'] ?? '');
    $asunto  = trim($_POST['asunto'] ?? 'Rendición de gastos');
    $cuerpo  = trim($_POST['cuerpo'] ?? '');
    if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) { $error = 'Correo destino inválido.'; }
    else {
        $headers  = "From: ".($_SESSION['user_email'] ?? 'noreply@controlgastos.local')."\r\n";
        $headers .= "Reply-To: ".($_SESSION['user_email'] ?? '')."\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $linkPdf = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'].
                   dirname($_SERVER['REQUEST_URI'])."/exportar_pdf.php?u=$uid&anio=$anio&mes=$mes";
        $html = "<p>".nl2br(h($cuerpo))."</p>".
                "<p>Para ver el PDF: <a href='$linkPdf'>$linkPdf</a></p>";
        if (@mail($destino, $asunto, $html, $headers)) {
            $enviado = true;
            flash('exito',"Correo enviado a $destino.");
        } else {
            $error = 'No se pudo enviar el correo desde este servidor. Usa el botón "Descargar PDF" y adjúntalo manualmente.';
        }
    }
}

$titulo = 'Enviar por correo';
include 'includes/head.php'; include 'includes/nav.php';
?>
<a href="javascript:history.back()" class="text-decoration-none small mb-2 d-inline-block"><i class="bi bi-arrow-left"></i> Volver</a>

<div class="row justify-content-center"><div class="col-12 col-lg-7">
<div class="card">
  <div class="card-header"><i class="bi bi-envelope me-1"></i>Enviar rendición por correo</div>
  <div class="card-body">
    <div class="alert alert-light small">
      Cierre de <strong><?= h($u['nombre']) ?></strong> · <?= nombreMes($mes) ?> <?= $anio ?>
    </div>
    <?php if ($error): ?><div class="alert alert-danger small"><?= h($error) ?></div><?php endif; ?>
    <?php if ($enviado): ?><div class="alert alert-success small">Correo enviado correctamente.</div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="u" value="<?= $uid ?>"><input type="hidden" name="anio" value="<?= $anio ?>"><input type="hidden" name="mes" value="<?= $mes ?>">
      <div class="mb-3"><label class="form-label">Destinatario <?php if ($emailJefeSugerido): ?><span class="text-muted small">(tu Gerente de Finanzas: <?= h($nombreJefe) ?>)</span><?php endif; ?></label>
        <input type="email" name="destino" class="form-control" required placeholder="jefe@empresa.cl" value="<?= h($emailJefeSugerido) ?>"></div>
      <div class="mb-3"><label class="form-label">Asunto</label>
        <input type="text" name="asunto" class="form-control" value="Rendición de gastos · <?= h($u['nombre']) ?> · <?= nombreMes($mes) ?> <?= $anio ?>"></div>
      <div class="mb-3"><label class="form-label">Mensaje</label>
        <textarea name="cuerpo" class="form-control" rows="6"><?php if ($rol === 'usuario'): ?>Estimado(a) <?= h($nombreJefe ?: 'Jefe') ?>,

Adjunto mi rendición de gastos correspondiente a <?= nombreMes($mes) ?> <?= $anio ?> para su revisión y validación.

Puede descargar el PDF desde el enlace del correo.

Saludos cordiales,
<?= h($u['nombre']) ?><?php else: ?>Estimado(a),

Adjunto la rendición de gastos correspondiente al periodo indicado.

Saludos cordiales.<?php endif; ?></textarea></div>
      <div class="d-flex gap-2">
        <button class="btn btn-primary flex-grow-1"><i class="bi bi-send"></i> Enviar</button>
        <a href="exportar_pdf.php?u=<?= $uid ?>&anio=<?= $anio ?>&mes=<?= $mes ?>" target="_blank" class="btn btn-outline-danger">
          <i class="bi bi-file-earmark-pdf"></i> Descargar PDF</a>
      </div>
    </form>
  </div>
</div>
</div></div>
<?php include 'includes/foot.php'; ?>
