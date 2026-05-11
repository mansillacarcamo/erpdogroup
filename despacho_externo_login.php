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

// ── Variables del layout ──
$titulo_pagina = 'Carchek · DO Group';
$sistema_nombre = 'DO GROUP';
$sistema_tag    = 'CARCHEK';
$accent         = '#10b981';
$accent_dark    = '#047857';
$accent_light   = '#34d399';
$headline_html  = 'Verificamos<br><span class="accent">cada entrega</span><br>con precisión.';
$brand_sub_html = 'Portal de <strong>validación externa</strong> para inspectores y receptores. Confirma despachos en segundos.';
$features = [
  ['icon'=>'bi-shield-check',    'title'=>'VALIDACIÓN',  'desc'=>'Confirma recepción de tickets de despacho.'],
  ['icon'=>'bi-camera-fill',     'title'=>'EVIDENCIA',   'desc'=>'Adjunta fotos del estado de entrega.'],
  ['icon'=>'bi-pen-fill',        'title'=>'FIRMA DIGITAL', 'desc'=>'Aprueba con firma digital legalmente válida.'],
];
$form_user_field = 'usuario';
$form_pass_field = 'password';
$form_user_value = $_POST['usuario'] ?? '';
$err = $error;
$assets_prefix = '';

require __DIR__ . '/includes/login_layout.php';
exit;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Carchek</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <?php
    $pwaManifest   = 'manifest_carchek.json';
    $pwaThemeColor = '#d97706';
    $pwaTitle      = 'Carchek';
    include 'includes/pwa_login_meta.php';
  ?>
  <style>
  body { background: linear-gradient(135deg, #92400e 0%, #d97706 50%, #f59e0b 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
  .login-card { width: 100%; max-width: 380px; border-radius: 24px; overflow: hidden; box-shadow: 0 25px 60px rgba(0,0,0,.4); background:#fff; }
  .login-header { padding: 2.4rem 1.5rem 1.6rem; text-align: center; background:#fff; }
  .app-icon { width: 130px; height: 130px; margin: 0 auto; background: #fff; border-radius: 26px; display: flex; align-items: center; justify-content: center; box-shadow: 0 14px 32px rgba(0,0,0,.18); border: 1px solid #f1f5f9; padding: 14px; }
  .app-icon img { width: 100%; height: auto; }
  .role-name { font-size: 1.6rem; font-weight: 900; color: #1b2838; letter-spacing: .5px; margin-top: 1rem; }
  .role-sub { color: #d97706; font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 4px; }
  .login-body { padding: 1.4rem 1.6rem 2rem; }
  .btn-login { background: #d97706; color: #fff; font-weight: 700; border: none; width: 100%; padding: .85rem; border-radius: 12px; font-size: 1rem; box-shadow: 0 6px 14px rgba(217,119,6,.35); }
  .btn-login:hover { background: #b45309; color: #fff; }
  .input-group-text { background:#fef3c7; color:#92400e; border-color:#fde68a }
  .form-control:focus { border-color:#d97706; box-shadow:0 0 0 .15rem rgba(217,119,6,.15) }
  </style>
</head>
<body>
<div class="login-card">
  <div class="login-header">
    <div class="app-icon"><img src="img/logo.png?v=2" alt="DOGROUP"></div>
    <div class="role-name">Carchek</div>
    <div class="role-sub"><i class="bi bi-shield-check me-1"></i>Validador de tickets</div>
  </div>
  <div class="login-body">
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
