<?php
require_once 'config.php';

/* ── Logout ── */
if (isset($_GET['logout'])) {
  unset($_SESSION['despacho_conductor']);
  header('Location: despacho_conductor_login.php'); exit;
}

/* ── Si ya está logueado, redirigir ── */
if (isConductorLoggedIn()) {
  header('Location: despacho_conductor_portal.php'); exit;
}

$error = null;

/* ── POST: login ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $usu  = trim($_POST['usuario']  ?? '');
  $pass = trim($_POST['password'] ?? '');
  if ($usu && $pass) {
    $st = $pdo->prepare("
      SELECT dc.*, dp.ppu, dp.metros_cubicos,
             o.codigo AS obra_codigo, o.nombre AS obra_nombre
      FROM despacho_conductores dc
      LEFT JOIN despacho_ppu dp ON dp.id = dc.ppu_id
      LEFT JOIN obras o          ON o.id  = dc.obra_id
      WHERE dc.usuario=? AND dc.activo=1
    ");
    $st->execute([$usu]);
    $conductor = $st->fetch(PDO::FETCH_ASSOC);
    if ($conductor && $conductor['password_hash'] && password_verify($pass, $conductor['password_hash'])) {
      $_SESSION['despacho_conductor'] = [
        'id'           => $conductor['id'],
        'nombre'       => $conductor['nombre'],
        'rut'          => $conductor['rut'],
        'email'        => $conductor['email'],
        'fono'         => $conductor['fono'],
        'ppu_id'       => $conductor['ppu_id'],
        'ppu'          => $conductor['ppu'],
        'metros_cubicos' => $conductor['metros_cubicos'],
        'obra_id'      => $conductor['obra_id'],
        'obra_codigo'  => $conductor['obra_codigo'],
        'obra_nombre'  => $conductor['obra_nombre'],
        'usuario'      => $conductor['usuario'],
      ];
      header('Location: despacho_conductor_portal.php'); exit;
    } else {
      $error = 'Usuario o contraseña incorrectos.';
    }
  } else {
    $error = 'Complete todos los campos.';
  }
}

// ── Variables del layout ──
$titulo_pagina = 'Conductores · DO Group';
$sistema_nombre = 'DO GROUP';
$sistema_tag    = 'CONDUCTORES';
$accent         = '#3b82f6';
$accent_dark    = '#1d4ed8';
$accent_light   = '#60a5fa';
$headline_html  = 'Manejamos<br><span class="accent">el despacho</span><br>en movimiento.';
$brand_sub_html = 'Portal exclusivo para <strong>conductores</strong>. Registra tus rutas, cargas y entregas desde tu celular.';
$features = [
  ['icon'=>'bi-truck-front-fill', 'title'=>'RUTAS Y CARGAS', 'desc'=>'Recibe asignaciones y reporta tu posición.'],
  ['icon'=>'bi-camera-fill',      'title'=>'EVIDENCIA',       'desc'=>'Sube fotos de despacho y firma del receptor.'],
  ['icon'=>'bi-clock-history',    'title'=>'HISTORIAL',       'desc'=>'Consulta tus reportes y entregas anteriores.'],
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
  <title>Conductor</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <?php
    $pwaManifest   = 'manifest_conductor.json';
    $pwaThemeColor = '#1b2838';
    $pwaTitle      = 'Conductor';
    include 'includes/pwa_login_meta.php';
  ?>
  <style>
  body { background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #1a5276 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
  .login-card { width: 100%; max-width: 380px; border-radius: 24px; overflow: hidden; box-shadow: 0 25px 60px rgba(0,0,0,.5); background:#fff; }
  .login-header { padding: 2.4rem 1.5rem 1.6rem; text-align: center; background:#fff; }
  .app-icon { width: 130px; height: 130px; margin: 0 auto; background: #fff; border-radius: 26px; display: flex; align-items: center; justify-content: center; box-shadow: 0 14px 32px rgba(0,0,0,.18); border: 1px solid #f1f5f9; padding: 14px; }
  .app-icon img { width: 100%; height: auto; }
  .role-name { font-size: 1.6rem; font-weight: 900; color: #1b2838; letter-spacing: .5px; margin-top: 1rem; }
  .role-sub { color: #1a5276; font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; margin-top: 4px; }
  .login-body { padding: 1.4rem 1.6rem 2rem; }
  .btn-login { background: #1b2838; color: #fff; font-weight: 700; border: none; width: 100%; padding: .85rem; border-radius: 12px; font-size: 1rem; box-shadow: 0 6px 14px rgba(27,40,56,.35); }
  .btn-login:hover { background: #0f172a; color: #fff; }
  .input-group-text { background:#e2e8f0; color:#1b2838; border-color:#cbd5e1 }
  .form-control:focus { border-color:#1b2838; box-shadow:0 0 0 .15rem rgba(27,40,56,.15) }
  </style>
</head>
<body>
<div class="login-card">
  <div class="login-header">
    <div class="app-icon"><img src="img/logo.png?v=2" alt="DOGROUP"></div>
    <div class="role-name">Conductor</div>
    <div class="role-sub"><i class="bi bi-truck me-1"></i>Tickets de despacho</div>
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
      <button type="submit" class="btn-login"><i class="bi bi-truck me-2"></i>Ingresar</button>
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
