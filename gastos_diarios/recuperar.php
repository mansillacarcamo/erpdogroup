<?php
require_once 'config.php';
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $msg = 'Si el correo está registrado, recibirás instrucciones. (Por ahora contacta al administrador.)';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Recuperar contraseña</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="css/app.css" rel="stylesheet">
</head><body>
<div class="login-bg"><div class="login-card">
  <h5 class="fw-bold mb-3"><i class="bi bi-key me-1"></i>Recuperar contraseña</h5>
  <?php if($msg): ?><div class="alert alert-info small"><?= h($msg) ?></div><?php endif; ?>
  <form method="post">
    <div class="mb-3"><label class="form-label small">Tu correo</label>
      <input type="email" name="email" class="form-control" required></div>
    <button class="btn btn-primary w-100">Enviar instrucciones</button>
  </form>
  <hr><div class="text-center small"><a href="login.php" class="text-decoration-none">Volver al login</a></div>
</div></div></body></html>
