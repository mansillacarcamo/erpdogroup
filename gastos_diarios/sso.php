<?php
/**
 * SSO bridge: si el usuario ya tiene sesión iniciada en gestion.dogroup.cl
 * (gestion guarda $_SESSION['usuario'] como array con id, usuario, rol, etc.),
 * y su rol es admin/gerente_finanzas/jefe/validador, lo auto-loguea en el
 * sistema de Gastos diarios mapeando su rol al usuario equivalente.
 *
 * Si no hay sesión válida en gestion, redirige al login normal de Gastos diarios.
 */

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$gestion = $_SESSION['usuario'] ?? null;
$rol     = $gestion['rol'] ?? '';

// Mapeo de roles de gestion → rol equivalente en controlgastos
$rolMap = [
  'admin'            => 'admin',
  'gerente_finanzas' => 'admin',
  'gerente_comercial'=> 'admin',
  'jefe'             => 'jefe',
  'validador'        => 'validador',
];

$dbFile = __DIR__ . '/controlgastos.db';
if (!$gestion || !isset($rolMap[$rol]) || !file_exists($dbFile)) {
  header('Location: login.php');
  exit;
}

try {
  $pdo = new PDO('sqlite:' . $dbFile);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $rolCG = $rolMap[$rol];
  // Buscar al mismo usuario por nombre/email/usuario; si no existe, usar el primer usuario con ese rol.
  $usrGestion = trim((string)($gestion['usuario'] ?? ''));
  $nombreGes  = trim((string)($gestion['nombre']  ?? ''));
  $emailGes   = trim((string)($gestion['email']   ?? ''));

  $u = null;
  if ($usrGestion) {
    $st = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ? AND activo = 1 LIMIT 1");
    $st->execute([$usrGestion]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
  if (!$u && $emailGes) {
    $st = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? AND activo = 1 LIMIT 1");
    $st->execute([$emailGes]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
  if (!$u && $nombreGes) {
    $st = $pdo->prepare("SELECT * FROM usuarios WHERE nombre = ? AND activo = 1 LIMIT 1");
    $st->execute([$nombreGes]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
  if (!$u) {
    // Fallback: primer usuario con el rol mapeado
    $st = $pdo->prepare("SELECT * FROM usuarios WHERE rol = ? AND activo = 1 ORDER BY id ASC LIMIT 1");
    $st->execute([$rolCG]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
  if (!$u) {
    header('Location: login.php?msg=' . urlencode('No hay usuario equivalente en Gastos diarios.'));
    exit;
  }

  // Setear sesión de controlgastos
  $_SESSION['user_id']      = (int)$u['id'];
  $_SESSION['user_nombre']  = $u['nombre'];
  $_SESSION['user_usuario'] = $u['usuario'];
  $_SESSION['user_rol']     = $u['rol'];
  $_SESSION['user_email']   = $u['email'] ?? '';
  $_SESSION['user_via_sso'] = 1;
  // Mostrar saludo de bienvenida en la siguiente pagina (1 vez por sesion)
  $_SESSION['bienvenida_pendiente'] = true;

  // Redirigir según rol de controlgastos
  $dest = 'dashboard.php';
  if ($u['rol'] === 'admin')          $dest = 'admin_usuarios.php';
  elseif ($u['rol'] === 'jefe')       $dest = 'jefe_dashboard.php';
  elseif ($u['rol'] === 'validador')  $dest = 'validador_dashboard.php';
  header('Location: ' . $dest);
  exit;
} catch (Exception $e) {
  header('Location: login.php?msg=' . urlencode('Error SSO: ' . $e->getMessage()));
  exit;
}
