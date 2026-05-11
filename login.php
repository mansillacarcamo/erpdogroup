<?php
require_once 'config.php';
if (!empty($_SESSION['usuario'])) { header('Location: inicio.php'); exit; }

$err_app = ''; // qué modal abrir si hay error
$err_msg = ''; // mensaje de error a mostrar
$last_user = $_POST['usuario'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $app = $_POST['app_login'] ?? 'gestion';
  $user = trim($_POST['usuario'] ?? '');
  $pass = $_POST['clave'] ?? $_POST['password'] ?? '';

  try {
    switch ($app) {

      case 'gestion':
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ?");
        $stmt->execute([$user]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($pass, $row['clave'])) {
          $_SESSION['usuario'] = ['id'=>$row['id'],'usuario'=>$row['usuario'],'nombre'=>$row['nombre'],'ci'=>$row['ci'],'cargo'=>$row['cargo'],'rol'=>$row['rol']];
          header('Location: inicio.php'); exit;
        }
        $err_app = 'gestion'; $err_msg = 'Usuario o contraseña incorrectos';
        break;

      case 'conductor':
        $st = $pdo->prepare("
          SELECT dc.*, dp.ppu, dp.metros_cubicos,
                 o.codigo AS obra_codigo, o.nombre AS obra_nombre
          FROM despacho_conductores dc
          LEFT JOIN despacho_ppu dp ON dp.id = dc.ppu_id
          LEFT JOIN obras o          ON o.id  = dc.obra_id
          WHERE dc.usuario=? AND dc.activo=1");
        $st->execute([$user]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if ($c && $c['password_hash'] && password_verify($pass, $c['password_hash'])) {
          $_SESSION['despacho_conductor'] = [
            'id'=>$c['id'],'nombre'=>$c['nombre'],'rut'=>$c['rut'],'email'=>$c['email'],'fono'=>$c['fono'],
            'ppu_id'=>$c['ppu_id'],'ppu'=>$c['ppu'],'metros_cubicos'=>$c['metros_cubicos'],
            'obra_id'=>$c['obra_id'],'obra_codigo'=>$c['obra_codigo'],'obra_nombre'=>$c['obra_nombre'],
            'usuario'=>$c['usuario'],
          ];
          header('Location: despacho_conductor_portal.php'); exit;
        }
        $err_app = 'conductor'; $err_msg = 'Usuario o contraseña incorrectos';
        break;

      case 'externo':
        $st = $pdo->prepare("SELECT * FROM despacho_externos WHERE usuario=? AND activo=1");
        $st->execute([$user]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if ($e && password_verify($pass, $e['password_hash'])) {
          $obraInfo = null;
          if ($e['obra_id']) {
            $stO = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id=?");
            $stO->execute([$e['obra_id']]);
            $obraInfo = $stO->fetch(PDO::FETCH_ASSOC);
          }
          $_SESSION['despacho_externo'] = [
            'id'=>$e['id'],'nombre'=>$e['nombre'],'rut'=>$e['rut'],'empresa'=>$e['empresa'],
            'email'=>$e['email'],'fono'=>$e['fono'],'obra_id'=>$e['obra_id'],'obra_info'=>$obraInfo,
            'usuario'=>$e['usuario'],'tipo'=>'externo',
          ];
          header('Location: despacho_externo_portal.php'); exit;
        }
        $err_app = 'externo'; $err_msg = 'Usuario o contraseña incorrectos';
        break;

      case 'combustible':
        $st = $pdo->prepare("
          SELECT cr.*, o.codigo AS obra_codigo, o.nombre AS obra_nombre_real
          FROM combustible_responsables cr
          LEFT JOIN obras o ON o.id = cr.obra_id
          WHERE cr.usuario = ? AND cr.activo = 1 LIMIT 1");
        $st->execute([$user]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r && !empty($r['password_hash']) && password_verify($pass, $r['password_hash'])) {
          $_SESSION['comb_responsable'] = [
            'id'=>(int)$r['id'],'nombre'=>$r['nombre'],'rut'=>$r['rut'] ?? '','email'=>$r['email'] ?? '',
            'fono'=>$r['fono'] ?? '','obra_id'=>$r['obra_id'] ? (int)$r['obra_id'] : null,
            'obra_codigo'=>$r['obra_codigo'] ?? '','obra_nombre'=>$r['obra_nombre'] ?: $r['obra_nombre_real'] ?? '',
            'usuario'=>$r['usuario'],
          ];
          header('Location: combustible_responsable_portal.php'); exit;
        }
        $err_app = 'combustible'; $err_msg = 'Usuario o contraseña incorrectos';
        break;

      case 'gastos':
        $cgPath = __DIR__ . '/gastos_diarios/controlgastos.db';
        if (!is_file($cgPath)) { $err_app = 'gastos'; $err_msg = 'Sistema no disponible'; break; }
        $cg = new PDO('sqlite:' . $cgPath);
        $cg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $st = $cg->prepare("SELECT * FROM usuarios WHERE (usuario=? OR email=?) AND activo=1");
        $st->execute([$user, $user]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if ($u && password_verify($pass, $u['clave'])) {
          $_SESSION['user_id']      = (int)$u['id'];
          $_SESSION['user_nombre']  = $u['nombre'];
          $_SESSION['user_usuario'] = $u['usuario'];
          $_SESSION['user_rol']     = $u['rol'];
          $_SESSION['user_email']   = $u['email'];
          $_SESSION['bienvenida_pendiente'] = true;
          $dest = 'gastos_diarios/dashboard.php';
          if ($u['rol'] === 'admin') $dest = 'gastos_diarios/admin_usuarios.php';
          elseif ($u['rol'] === 'jefe') $dest = 'gastos_diarios/jefe_dashboard.php';
          elseif ($u['rol'] === 'validador') $dest = 'gastos_diarios/validador_dashboard.php';
          header('Location: ' . $dest); exit;
        }
        $err_app = 'gastos'; $err_msg = 'Usuario o contraseña incorrectos';
        break;

      case 'gerente_general':
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ?");
        $stmt->execute([$user]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && password_verify($pass, $row['clave'])) {
          if (!in_array($row['rol'], ['gerente_general','admin'], true)) {
            $err_app = 'gerente_general';
            $err_msg = 'Este usuario no tiene permisos de Gerente General.';
            break;
          }
          $_SESSION['gerente_general'] = [
            'id'=>$row['id'], 'usuario'=>$row['usuario'], 'nombre'=>$row['nombre'],
            'rol'=>$row['rol'], 'cargo'=>$row['cargo'] ?? ''
          ];
          header('Location: gerente_general/dashboard.php'); exit;
        }
        $err_app = 'gerente_general'; $err_msg = 'Usuario o contraseña incorrectos';
        break;
    }
  } catch (Exception $ex) {
    $err_app = $app; $err_msg = 'Error en el sistema. Contacta al administrador.';
  }
}

// Helper para renderizar campos de form (DRY)
function modalLoginForm($app, $accent, $accent_dark, $err_app, $err_msg, $last_user) {
  $h = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
  $userValue = ($err_app === $app) ? $h($last_user) : '';
  $err = ($err_app === $app) ? $err_msg : '';
  ob_start(); ?>
    <?php if ($err): ?>
      <div class="alert-err"><i class="bi bi-exclamation-triangle me-1"></i><?= $h($err) ?></div>
    <?php endif; ?>
    <form method="POST" autocomplete="on">
      <input type="hidden" name="app_login" value="<?= $app ?>">
      <label class="form-label-accent" style="color:<?= $accent ?>;">Usuario</label>
      <div class="input-wrap">
        <span class="ico" style="color:<?= $accent ?>;"><i class="bi bi-person"></i></span>
        <input type="text" name="usuario" class="input-accent" required
               style="border-color:<?= $accent ?>40;"
               autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
               placeholder="Tu usuario" value="<?= $userValue ?>">
      </div>
      <label class="form-label-accent" style="color:<?= $accent ?>;">Contraseña</label>
      <div class="input-wrap">
        <span class="ico" style="color:<?= $accent ?>;"><i class="bi bi-lock"></i></span>
        <input type="password" name="clave" class="input-accent" required
               style="border-color:<?= $accent ?>40;" placeholder="••••••••">
      </div>
      <button type="submit" class="btn-accent" style="background:linear-gradient(135deg,<?= $accent ?>,<?= $accent_dark ?>); box-shadow:0 6px 18px <?= $accent ?>59;">
        <i class="bi bi-arrow-right-circle me-1"></i> Ingresar
      </button>
    </form>
  <?php
  return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>DOGroup - Inicio</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{ box-sizing:border-box; }
html, body { height:100%; }
body{
  margin:0; min-height:100dvh;
  font-family:'Montserrat', system-ui, -apple-system, sans-serif;
  background:#000; color:#fff;
  position: relative; overflow-x:hidden;
}
body::before{
  content:""; position:fixed; inset:0;
  background: url('img/fondo_login.jpg') center 12% / cover no-repeat;
  filter: brightness(1.25) contrast(1.05) saturate(1.10);
  z-index:-2;
}
body::after{
  content:""; position:fixed; inset:0;
  background: linear-gradient(135deg, rgba(10,10,10,.35) 0%, rgba(10,10,10,.20) 50%, rgba(10,10,10,.40) 100%);
  z-index:-1;
}
.apps-shell{
  min-height:100dvh;
  display:flex; flex-direction:column; align-items:center; justify-content:flex-end;
  padding: 60px 24px 80px;
}
.apps-title{
  text-align:center; font-size:.78rem; letter-spacing:.8px; text-transform:uppercase;
  color: rgba(255,255,255,.85);
  text-shadow: 0 1px 4px rgba(0,0,0,.6);
  margin-bottom: 14px; font-weight:600;
}
.apps-grid{
  display:grid; grid-template-columns: repeat(3, 1fr); gap: 10px;
  width: 100%; max-width: 540px;
}
@media (min-width: 720px){
  .apps-grid{ grid-template-columns: repeat(6, 1fr); max-width: 760px; gap: 8px; }
}
.app-tile{
  display:flex; flex-direction:column; align-items:center; justify-content:center;
  gap:6px; padding:12px 4px; border:none; border-radius:14px;
  color:#fff; font-weight:700; letter-spacing:.3px;
  text-decoration:none; cursor:pointer;
  transition: transform .18s cubic-bezier(.4,0,.2,1), filter .18s;
  height: 86px; width: 100%;
  text-align:center; box-sizing: border-box;
  font-size:.74rem;
}
.app-tile:hover{ color:#fff; transform: translateY(-3px) scale(1.03); filter: brightness(1.08); }
.app-tile:active{ transform: translateY(0) scale(.98); }
.app-tile i{ font-size:1.5rem; line-height:1; }
.app-tile span{ font-size:.68rem; line-height:1.1; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block; }
@media (max-width: 380px){
  .apps-grid{ gap:6px; }
  .app-tile{ height:76px; padding:10px 2px; }
  .app-tile i{ font-size:1.3rem; }
  .app-tile span{ font-size:.6rem; }
}

.login-bottom-credit{
  position: fixed; left:0; right:0; bottom:14px;
  text-align:center; font-size:.8rem;
  color: rgba(255,255,255,.85);
  text-shadow: 0 1px 4px rgba(0,0,0,.7);
  z-index: 5; letter-spacing:.3px;
}
.login-bottom-credit strong{ color:#fbbf24; font-weight:700; }

/* Modales unificados — color por app */
.modal-content.app-login-modal{
  background: linear-gradient(160deg, #1a1a1a 0%, #0d0d0d 100%);
  border-radius:18px;
  box-shadow: 0 20px 60px rgba(0,0,0,.6), inset 0 1px 0 rgba(255,255,255,.05);
  color: #f1f5f9;
  border: 1px solid rgba(255,255,255,.10);
}
.app-login-modal .modal-header{
  border:0;
  border-top-left-radius:18px; border-top-right-radius:18px;
  padding: 18px 22px;
  color:#fff;
}
.app-login-modal .modal-body{ padding: 26px 26px 30px; }

.form-label-accent{
  font-size:.72rem; font-weight:600;
  letter-spacing:1.5px; text-transform:uppercase;
  margin-bottom:6px; display:block;
}
.input-accent{
  background:#0a0a0a !important;
  border:1px solid rgba(255,255,255,.15) !important;
  color:#fff !important;
  border-radius:10px !important;
  padding: 12px 14px !important;
  font-size:.95rem !important;
  width:100%;
  transition: border-color .2s, box-shadow .2s;
}
.input-accent::placeholder{ color:#525252; }
.input-accent:focus{
  outline:none;
  box-shadow: 0 0 0 3px rgba(255,255,255,.08) !important;
}
.input-wrap{ position:relative; margin-bottom:18px; }
.input-wrap .ico{
  position:absolute; left:14px; top:50%; transform:translateY(-50%);
  font-size:1rem; pointer-events:none;
}
.input-wrap .input-accent{ padding-left: 40px !important; }
.btn-accent{
  width:100%; padding:13px;
  color:#fff; font-weight:700; letter-spacing:1.5px;
  text-transform:uppercase; font-size:.85rem;
  border:0; border-radius:10px; cursor:pointer;
  transition: transform .15s, filter .2s;
  margin-top:6px;
}
.btn-accent:hover{ transform: translateY(-1px); filter:brightness(1.05); }
.alert-err{
  background: rgba(239,68,68,.10);
  border:1px solid rgba(239,68,68,.30);
  color:#fca5a5;
  padding:10px 14px; border-radius:8px;
  font-size:.85rem; margin-bottom:18px;
}
</style>
</head>
<body>

<div class="apps-shell">
  <div style="flex:1;"></div>
  <p class="apps-title"><i class="bi bi-grid-fill me-1"></i>Aplicaciones de gestión</p>
  <div class="apps-grid">
    <button type="button" class="app-tile" data-bs-toggle="modal" data-bs-target="#mLoginGestion"
      style="background:linear-gradient(135deg,#dc3545,#7c1322);box-shadow:0 3px 10px rgba(220,53,69,.35);">
      <i class="bi bi-buildings-fill"></i><span>Gestión DO</span>
    </button>
    <button type="button" class="app-tile" data-bs-toggle="modal" data-bs-target="#mLoginConductor"
      style="background:linear-gradient(135deg,#1b2838,#374151);box-shadow:0 3px 10px rgba(27,40,56,.3);">
      <i class="bi bi-truck-front-fill"></i><span>Conductores</span>
    </button>
    <button type="button" class="app-tile" data-bs-toggle="modal" data-bs-target="#mLoginExterno"
      style="background:linear-gradient(135deg,#198754,#20c997);box-shadow:0 3px 10px rgba(25,135,84,.3);">
      <i class="bi bi-shield-check"></i><span>Carchek</span>
    </button>
    <button type="button" class="app-tile" data-bs-toggle="modal" data-bs-target="#mLoginCombustible"
      style="background:linear-gradient(135deg,#1a1a2e,#d97706);box-shadow:0 3px 10px rgba(217,119,6,.3);">
      <i class="bi bi-fuel-pump-fill"></i><span>Combustible</span>
    </button>
    <button type="button" class="app-tile" data-bs-toggle="modal" data-bs-target="#mLoginGastos"
      style="background:linear-gradient(135deg,#0a0a0a,#0d9488);box-shadow:0 3px 10px rgba(13,148,136,.3);">
      <i class="bi bi-wallet2"></i><span>Gastos</span>
    </button>
    <button type="button" class="app-tile" data-bs-toggle="modal" data-bs-target="#mLoginGerente"
      style="background:linear-gradient(135deg,#6f42c1,#4c2a85);box-shadow:0 3px 10px rgba(111,66,193,.35);">
      <i class="bi bi-graph-up-arrow"></i><span>Gerente General</span>
    </button>
  </div>
</div>

<div class="login-bottom-credit">
  Desarrollado por <strong>Departamento de Informática DOGroup</strong>
</div>

<!-- ═══════════ 6 MODALES DE LOGIN (uno por app) ═══════════ -->
<?php
$apps = [
  ['id'=>'mLoginGestion',     'app'=>'gestion',         'titulo'=>'GESTIÓN DO',     'sub'=>'Acceso al sistema',      'icon'=>'bi-buildings-fill',   'accent'=>'#dc3545', 'accent_dark'=>'#7c1322'],
  ['id'=>'mLoginConductor',   'app'=>'conductor',       'titulo'=>'CONDUCTORES',    'sub'=>'Portal de despacho',     'icon'=>'bi-truck-front-fill','accent'=>'#3b82f6', 'accent_dark'=>'#1d4ed8'],
  ['id'=>'mLoginExterno',     'app'=>'externo',         'titulo'=>'CARCHEK',        'sub'=>'Validación externa',     'icon'=>'bi-shield-check',    'accent'=>'#10b981', 'accent_dark'=>'#047857'],
  ['id'=>'mLoginCombustible', 'app'=>'combustible',     'titulo'=>'COMBUSTIBLE',    'sub'=>'Portal del responsable', 'icon'=>'bi-fuel-pump-fill',  'accent'=>'#d97706', 'accent_dark'=>'#92400e'],
  ['id'=>'mLoginGastos',      'app'=>'gastos',          'titulo'=>'CONTROL GASTOS', 'sub'=>'Gastos diarios',         'icon'=>'bi-wallet2',         'accent'=>'#d4af37', 'accent_dark'=>'#a78329'],
  ['id'=>'mLoginGerente',     'app'=>'gerente_general', 'titulo'=>'GERENTE GENERAL','sub'=>'Panel ejecutivo',        'icon'=>'bi-graph-up-arrow',  'accent'=>'#6f42c1', 'accent_dark'=>'#4c2a85'],
];
foreach ($apps as $a):
?>
<div class="modal fade" id="<?= $a['id'] ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content app-login-modal" style="border-color:<?= $a['accent'] ?>40;">
      <div class="modal-header" style="background:linear-gradient(135deg,<?= $a['accent'] ?>,<?= $a['accent_dark'] ?>);">
        <div class="d-flex align-items-center gap-2">
          <i class="bi <?= $a['icon'] ?>" style="font-size:1.5rem;"></i>
          <div>
            <div class="fw-bold" style="letter-spacing:1.5px;"><?= $a['titulo'] ?></div>
            <div style="font-size:.72rem;opacity:.9;"><?= $a['sub'] ?></div>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <?= modalLoginForm($a['app'], $a['accent'], $a['accent_dark'], $err_app, $err_msg, $last_user) ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($err_app): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  var ids = {gestion:'mLoginGestion', conductor:'mLoginConductor', externo:'mLoginExterno', combustible:'mLoginCombustible', gastos:'mLoginGastos', gerente_general:'mLoginGerente'};
  var id = ids[<?= json_encode($err_app) ?>];
  if (id) new bootstrap.Modal(document.getElementById(id)).show();
});
</script>
<?php endif; ?>
</body>
</html>
