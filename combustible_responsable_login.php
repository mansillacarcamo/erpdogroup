<?php
/**
 * combustible_responsable_login.php
 * Portal de acceso independiente para Responsables de Combustible.
 * Funciona igual que despacho_conductor_login.php pero para
 * combustible_responsables (con usuario + password_hash propio).
 */
require_once 'config.php';

/* ── Logout ── */
if (isset($_GET['logout'])) {
  unset($_SESSION['comb_responsable']);
  header('Location: combustible_responsable_login.php'); exit;
}

/* ── Si ya está logueado, redirigir al portal ── */
if (isCombResponsableLoggedIn()) {
  header('Location: combustible_responsable_portal.php'); exit;
}

$error = null;

/* ── POST: login ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $usu  = trim($_POST['usuario']  ?? '');
  $pass = trim($_POST['password'] ?? '');
  if ($usu && $pass) {
    try {
      $st = $pdo->prepare("
        SELECT cr.*, o.codigo AS obra_codigo, o.nombre AS obra_nombre_real
        FROM combustible_responsables cr
        LEFT JOIN obras o ON o.id = cr.obra_id
        WHERE cr.usuario = ? AND cr.activo = 1
        LIMIT 1
      ");
      $st->execute([$usu]);
      $resp = $st->fetch(PDO::FETCH_ASSOC);

      if ($resp && !empty($resp['password_hash']) && password_verify($pass, $resp['password_hash'])) {
        $_SESSION['comb_responsable'] = [
          'id'           => (int)$resp['id'],
          'nombre'       => $resp['nombre'],
          'rut'          => $resp['rut'] ?? '',
          'email'        => $resp['email'] ?? '',
          'fono'         => $resp['fono'] ?? '',
          'obra_id'      => $resp['obra_id'] ? (int)$resp['obra_id'] : null,
          'obra_codigo'  => $resp['obra_codigo'] ?? '',
          'obra_nombre'  => $resp['obra_nombre'] ?: $resp['obra_nombre_real'] ?? '',
          'usuario'      => $resp['usuario'],
        ];
        header('Location: combustible_responsable_portal.php'); exit;
      } else {
        $error = 'Usuario o contraseña incorrectos.';
      }
    } catch (Exception $e) {
      $error = 'Error en el sistema. Contacta al administrador.';
    }
  } else {
    $error = 'Complete todos los campos.';
  }
}

// ── Variables del layout ──
$titulo_pagina = 'Combustible · DO Group';
$sistema_nombre = 'DO GROUP';
$sistema_tag    = 'COMBUSTIBLE';
$accent         = '#d97706';
$accent_dark    = '#92400e';
$accent_light   = '#fbbf24';
$headline_html  = 'Distribuimos<br><span class="accent">energía</span><br>cada día.';
$brand_sub_html = 'Portal del <strong>responsable de combustible</strong>. Registra hojas, vales y carga diaria desde tu celular.';
$features = [
  ['icon'=>'bi-fuel-pump-fill',  'title'=>'HOJAS DIARIAS', 'desc'=>'Registra cargas de diésel y gasolina por día.'],
  ['icon'=>'bi-receipt-cutoff',  'title'=>'VALES Y EQUIPOS','desc'=>'Hasta 15 vales por hoja con patente y operador.'],
  ['icon'=>'bi-graph-up',        'title'=>'CONTROL TOTAL', 'desc'=>'Saldo de bidones y trazabilidad por obra.'],
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
  <title>Combustible</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <?php
    $pwaManifest   = 'manifest_combustible.json';
    $pwaThemeColor = '#b45309';
    $pwaTitle      = 'Combustible';
    include 'includes/pwa_login_meta.php';
  ?>
  <style>
    body {
      background: linear-gradient(135deg, #78350f 0%, #b45309 50%, #d97706 100%);
      min-height: 100vh;
      display: flex; align-items: center; justify-content: center;
      padding: 16px;
    }
    .login-card { width: 100%; max-width: 380px; border-radius: 24px; overflow: hidden; box-shadow: 0 25px 60px rgba(0,0,0,.45); background:#fff; }
    .login-header { padding: 2.4rem 1.5rem 1.6rem; text-align: center; background:#fff; }
    .app-icon { width: 130px; height: 130px; margin: 0 auto; background: #fff; border-radius: 26px; display: flex; align-items: center; justify-content: center; box-shadow: 0 14px 32px rgba(0,0,0,.18); border: 1px solid #f1f5f9; padding: 14px; }
    .app-icon img { width: 100%; height: auto; }
    .role-name { font-size: 1.55rem; font-weight: 900; color: #1b2838; letter-spacing: .5px; margin-top: 1rem; }
    .role-sub { color: #b45309; font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 4px; }
    .login-body { padding: 1.4rem 1.6rem 2rem; }
    .btn-login { background: #b45309; color: #fff; font-weight: 700; border: 0; width: 100%; padding: .85rem; border-radius: 12px; font-size: 1rem; box-shadow: 0 6px 14px rgba(180,83,9,.35); }
    .btn-login:hover { background: #92400e; color: #fff; }
    .input-group-text { background: #fef3c7; border-color: #fde68a; color:#92400e; }
    .form-control:focus { border-color: #b45309; box-shadow: 0 0 0 .15rem rgba(180,83,9,.15); }
    .back-link { color: rgba(255,255,255,.75); text-decoration: none; font-size: .82rem; }
    .back-link:hover { color: #fff; }
  </style>
</head>
<body>
<div style="position:fixed;top:18px;left:18px;">
  <a href="login.php" class="back-link"><i class="bi bi-arrow-left me-1"></i>Volver al inicio</a>
</div>
<div class="login-card">
  <div class="login-header">
    <div class="app-icon"><img src="img/logo.png?v=2" alt="DOGROUP"></div>
    <div class="role-name">Combustible</div>
    <div class="role-sub"><i class="bi bi-fuel-pump-fill me-1"></i>Hojas de combustible</div>
  </div>
  <div class="login-body">
    <?php if ($error): ?>
    <div class="alert alert-danger py-2"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST" autocomplete="off">
      <div class="mb-3">
        <label class="form-label fw-semibold">Usuario</label>
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
          <input type="text" name="usuario" class="form-control" placeholder="Tu usuario asignado" autofocus required
                 value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>">
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
      <button type="submit" class="btn-login"><i class="bi bi-fuel-pump-fill me-2"></i>Ingresar</button>
    </form>
    <p class="text-center text-muted mt-3 mb-0" style="font-size:.75rem;">
      ¿Problemas para ingresar? Contacta al administrador del módulo.
    </p>
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
