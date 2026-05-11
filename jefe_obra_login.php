<?php
require_once 'config.php';

if (isset($_GET['logout'])) {
  unset($_SESSION['jefe_obra']);
  header('Location: jefe_obra_login.php'); exit;
}

if (isJefeObraLoggedIn()) {
  header('Location: jefe_obra_portal.php'); exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $usu  = trim($_POST['usuario']  ?? '');
  $pass = trim($_POST['password'] ?? '');
  if ($usu && $pass) {
    $st = $pdo->prepare("SELECT * FROM jefes_obra WHERE usuario=? AND activo=1");
    $st->execute([$usu]);
    $jefe = $st->fetch(PDO::FETCH_ASSOC);
    if ($jefe && password_verify($pass, $jefe['password_hash'])) {
      $obraInfo = null;
      if ($jefe['obra_id']) {
        $stO = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id=?");
        $stO->execute([$jefe['obra_id']]);
        $obraInfo = $stO->fetch(PDO::FETCH_ASSOC);
      }
      $_SESSION['jefe_obra'] = [
        'id'        => $jefe['id'],
        'nombre'    => $jefe['nombre'],
        'rut'       => $jefe['rut'],
        'email'     => $jefe['email'],
        'telefono'  => $jefe['telefono'],
        'obra_id'   => $jefe['obra_id'],
        'obra_info' => $obraInfo,
        'usuario'   => $jefe['usuario'],
        'tipo'      => 'jefe_obra',
      ];
      header('Location: jefe_obra_portal.php'); exit;
    } else {
      $error = 'Usuario o contraseña incorrectos.';
    }
  } else {
    $error = 'Complete todos los campos.';
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Jefe de Obra</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <?php
    $pwaManifest   = 'manifest_jefe_obra.json';
    $pwaThemeColor = '#15803d';
    $pwaTitle      = 'Jefe de Obra';
    include 'includes/pwa_login_meta.php';
  ?>
  <style>
  body { background: linear-gradient(135deg, #14532d 0%, #15803d 50%, #16a34a 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
  .login-card { width: 100%; max-width: 380px; border-radius: 24px; overflow: hidden; box-shadow: 0 25px 60px rgba(0,0,0,.4); background:#fff; }
  .login-header { padding: 2.4rem 1.5rem 1.6rem; text-align: center; background:#fff; }
  .app-icon { width: 130px; height: 130px; margin: 0 auto; background: #fff; border-radius: 26px; display: flex; align-items: center; justify-content: center; box-shadow: 0 14px 32px rgba(0,0,0,.18); border: 1px solid #f1f5f9; padding: 14px; }
  .app-icon img { width: 100%; height: auto; }
  .role-name { font-size: 1.55rem; font-weight: 900; color: #1b2838; letter-spacing: .5px; margin-top: 1rem; }
  .role-sub { color: #15803d; font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 4px; }
  .login-body { padding: 1.4rem 1.6rem 2rem; }
  .btn-login { background: #15803d; color: #fff; font-weight: 700; border: 0; width: 100%; padding: .85rem; border-radius: 12px; font-size: 1rem; box-shadow: 0 6px 14px rgba(21,128,61,.3); }
  .btn-login:hover { background: #166534; color: #fff; }
  .input-group-text { background:#dcfce7; color:#14532d; border-color:#bbf7d0 }
  .form-control:focus { border-color:#15803d; box-shadow:0 0 0 .15rem rgba(21,128,61,.15) }
  </style>
</head>
<body>
<div class="login-card">
  <div class="login-header">
    <div class="app-icon"><img src="img/logo.png?v=2" alt="DOGROUP"></div>
    <div class="role-name">Jefe de Obra</div>
    <div class="role-sub"><i class="bi bi-shield-fill-check me-1"></i>Segunda validación</div>
  </div>
  <div class="login-body">
    <?php if ($error): ?>
    <div class="alert alert-danger py-2 mb-3"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
      <div class="mb-3">
        <label class="form-label fw-semibold">Usuario</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
          <input type="text" name="usuario" class="form-control" placeholder="Tu usuario asignado" autofocus required value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>">
        </div>
      </div>
      <div class="mb-4">
        <label class="form-label fw-semibold">Contraseña</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
          <input type="password" name="password" id="passInput" class="form-control" placeholder="••••••••" required>
          <button type="button" class="btn btn-outline-secondary" onclick="togglePass()"><i class="bi bi-eye"></i></button>
        </div>
      </div>
      <button type="submit" class="btn-login"><i class="bi bi-box-arrow-in-right me-2"></i>Ingresar</button>
    </form>
    <p class="text-center text-muted mt-3 mb-0" style="font-size:.75rem;">¿Problemas para ingresar? Contacta al administrador.</p>
  </div>
</div>
<script>
function togglePass() {
  var el = document.getElementById('passInput');
  el.type = el.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>
