<?php
require_once 'config.php';

/* ── Logout ── */
if (isset($_GET['logout'])) {
  unset($_SESSION['despacho_externo']);
  header('Location: despacho_externo_login.php'); exit;
}

/* ── Si ya está logueado, redirigir ── */
if (isExternoLoggedIn()) {
  header('Location: despacho_externo_portal.php'); exit;
}

$error = null;

/* ── POST: login ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $usu  = trim($_POST['usuario']  ?? '');
  $pass = trim($_POST['password'] ?? '');
  if ($usu && $pass) {
    $st = $pdo->prepare("SELECT * FROM despacho_externos WHERE usuario=? AND activo=1");
    $st->execute([$usu]);
    $externo = $st->fetch(PDO::FETCH_ASSOC);
    if ($externo && password_verify($pass, $externo['password_hash'])) {
      /* Cargar nombre de obra si tiene */
      $obraInfo = null;
      if ($externo['obra_id']) {
        $stO = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id=?");
        $stO->execute([$externo['obra_id']]);
        $obraInfo = $stO->fetch(PDO::FETCH_ASSOC);
      }
      $_SESSION['despacho_externo'] = [
        'id'        => $externo['id'],
        'nombre'    => $externo['nombre'],
        'rut'       => $externo['rut'],
        'empresa'   => $externo['empresa'],
        'email'     => $externo['email'],
        'fono'      => $externo['fono'],
        'obra_id'   => $externo['obra_id'],
        'obra_info' => $obraInfo,
        'usuario'   => $externo['usuario'],
        'tipo'      => 'externo',
      ];
      header('Location: despacho_externo_portal.php'); exit;
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
  <title>Portal Validadores — DOGROUP</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
  body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
  .login-card { width: 100%; max-width: 420px; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 50px rgba(0,0,0,.5); }
  .login-header { background: #212529; padding: 2rem; text-align: center; }
  .login-header img { height: 60px; filter: brightness(0) invert(1); margin-bottom: .8rem; }
  .login-header .title { color: #fff; font-size: 1.2rem; font-weight: 800; letter-spacing: 1px; }
  .login-header .sub { color: rgba(255,255,255,.55); font-size: .8rem; margin-top: 3px; }
  .login-body { background: #fff; padding: 2rem; }
  .login-section-label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: .75rem; display: block; }
  .btn-login { background: #212529; color: #fff; font-weight: 700; border: none; width: 100%; padding: .75rem; border-radius: 8px; font-size: 1rem; }
  .btn-login:hover { background: #343a40; color: #fff; }
  .portal-badge { display: inline-block; background: #0d6efd; color: #fff; font-size: .7rem; padding: .2rem .6rem; border-radius: 999px; margin-bottom: 1rem; }
  </style>
</head>
<body>
<div class="login-card">
  <div class="login-header">
    <img src="img/logo.png" alt="DOGROUP">
    <div class="title">DOGROUP</div>
    <div class="sub">Portal de Validadores Externos</div>
  </div>
  <div class="login-body">
    <div class="text-center mb-3">
      <span class="portal-badge"><i class="bi bi-shield-check me-1"></i>Acceso exclusivo validadores</span>
    </div>
    <?php if ($error): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?></div>
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
    <p class="text-center text-muted mt-3 mb-0" style="font-size:.75rem;">¿Problemas para ingresar? Contacta al administrador del módulo.</p>
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
