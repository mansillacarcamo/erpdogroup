<?php
require_once 'config.php';

if (!empty($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['usuario'] ?? '');
    $clave = $_POST['clave'] ?? '';
    if ($login && $clave) {
        // Permite ingresar por usuario o por email (compatibilidad)
        $q = $pdo->prepare("SELECT * FROM usuarios WHERE (usuario=? OR email=?) AND activo=1");
        $q->execute([$login, $login]);
        $u = $q->fetch();
        if ($u && password_verify($clave, $u['clave'])) {
            $_SESSION['user_id']      = (int)$u['id'];
            $_SESSION['user_nombre']  = $u['nombre'];
            $_SESSION['user_usuario'] = $u['usuario'];
            $_SESSION['user_rol']     = $u['rol'];
            $_SESSION['user_email']   = $u['email'];
            // Mostrar saludo de bienvenida en la siguiente pagina
            $_SESSION['bienvenida_pendiente'] = true;
            // Limpiar cualquier output previo y redirigir segun rol
            while (ob_get_level() > 0) { @ob_end_clean(); }
            switch ($u['rol']) {
                case 'admin':     header('Location: admin_usuarios.php'); break;
                case 'jefe':      header('Location: jefe_dashboard.php'); break;
                case 'validador': header('Location: validador_dashboard.php'); break;
                default:          header('Location: dashboard.php');
            }
            exit;
        }
        $err = 'Credenciales incorrectas';
    } else {
        $err = 'Completa todos los campos';
    }
}
$titulo = 'Iniciar sesión';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0a0a0a">
<title>Iniciar sesión · DO Group</title>
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="img/icon-192.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="css/app.css" rel="stylesheet">
<style>
:root{
  --gold:       #d4af37;
  --gold-dark:  #a78329;
  --gold-light: #e7c869;
  --bg-deep:    #0a0a0a;
  --bg-card:    #141414;
  --line:       rgba(212,175,55,.25);
}
*{ box-sizing:border-box; }
html, body { height:100%; }
body{
  margin:0;
  font-family:'Montserrat', system-ui, -apple-system, sans-serif;
  background: #000;
  color:#f1f5f9;
  min-height:100dvh;
  overflow-x:hidden;
}
.login-shell{
  min-height:100dvh;
  display:flex;
  align-items:stretch;
  position:relative;
  /* Imagen de fondo (obra/grua) + overlay oscuro + degradado de respaldo */
  background:
    linear-gradient(135deg, rgba(10,10,10,.92) 0%, rgba(20,20,20,.85) 50%, rgba(10,10,10,.95) 100%),
    url('img/bg-construccion.jpg') center/cover no-repeat,
    linear-gradient(180deg, #0a0a0a 0%, #1a1a1a 100%);
}
/* Capa de "humo" / acentos dorados */
.login-shell::before{
  content:''; position:absolute; inset:0;
  background:
    radial-gradient(ellipse 80% 60% at 30% 10%, rgba(212,175,55,.10) 0%, transparent 60%),
    radial-gradient(ellipse 60% 50% at 80% 90%, rgba(212,175,55,.06) 0%, transparent 60%),
    radial-gradient(circle at 20% 30%, rgba(255,255,255,.03) 0%, transparent 40%);
  pointer-events:none;
}
/* Decoraciones doradas (lineas oblicuas estilo flyer) */
.login-shell::after{
  content:''; position:absolute; right:-50px; bottom:-50px;
  width:300px; height:300px;
  background: linear-gradient(135deg, transparent 45%, var(--gold) 47%, var(--gold) 50%, transparent 52%);
  opacity:.35; transform:rotate(-15deg);
  pointer-events:none;
}

.login-wrap{
  width:100%;
  display:flex; align-items:center; justify-content:center;
  padding:40px 24px;
  min-height:100dvh;
  position:relative; z-index:1;
}

/* === Lado izquierdo: branding === */
.brand-side{
  display:flex; flex-direction:column; justify-content:center;
}
.brand-logo-wrap{
  display:flex; align-items:center; gap:14px; margin-bottom:30px;
}
.brand-logo{
  width:64px; height:64px;
  background:#fff; border-radius:12px;
  display:flex; align-items:center; justify-content:center;
  padding:6px; box-shadow:0 4px 24px rgba(212,175,55,.25);
}
.brand-logo img{ width:100%; height:100%; object-fit:contain; }
.brand-name{
  font-size:1.6rem; font-weight:800; letter-spacing:2px; color:#fff;
  line-height:1;
}
.brand-divider{
  height:1px; flex-grow:1;
  background: linear-gradient(90deg, var(--gold) 0%, transparent 100%);
  margin: 0 6px;
  max-width:120px;
}
.brand-tag{ color:var(--gold); font-size:.78rem; letter-spacing:3px; font-weight:600; }

.brand-headline{
  font-size: clamp(2rem, 4vw, 3.2rem);
  line-height:1.05; font-weight:800;
  margin: 0 0 18px;
  color:#fff;
}
.brand-headline .accent{
  background: linear-gradient(135deg, var(--gold-light) 0%, var(--gold) 50%, var(--gold-dark) 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}
.brand-sub{
  font-size:1rem; color:#a3a3a3; max-width:440px; margin:0 0 28px;
}
.brand-sub strong{ color:var(--gold); font-weight:600; }
.brand-line{
  height:2px; width:80px;
  background: linear-gradient(90deg, var(--gold) 0%, transparent 100%);
  margin: 0 0 24px;
}

.feat{
  display:flex; gap:14px; align-items:flex-start;
  padding: 10px 0;
}
.feat-ico{
  width:40px; height:40px; border-radius:50%;
  border:1.5px solid var(--gold);
  display:flex; align-items:center; justify-content:center;
  color:var(--gold); font-size:1.1rem; flex-shrink:0;
}
.feat-txt h6{
  margin:0; color:#fff; font-weight:700;
  letter-spacing:1px; font-size:.85rem;
}
.feat-txt p{
  margin:2px 0 0; color:#a3a3a3; font-size:.82rem; line-height:1.35;
}

/* === Lado derecho: card del formulario === */
.form-side{
  display:flex; align-items:center; justify-content:center;
}
.login-card{
  width:100%; max-width:420px;
  background: linear-gradient(160deg, #1a1a1a 0%, #0d0d0d 100%);
  border:1px solid var(--line);
  border-radius:18px;
  padding:36px 30px;
  box-shadow:
    0 20px 60px rgba(0,0,0,.6),
    inset 0 1px 0 rgba(255,255,255,.05);
  position:relative;
}
.login-card::before{
  content:''; position:absolute; top:0; left:25px; right:25px; height:1px;
  background: linear-gradient(90deg, transparent, var(--gold), transparent);
}
.login-card h4{
  color:#fff; font-weight:700; letter-spacing:.5px;
  font-size:1.4rem; margin-bottom:6px;
}
.login-card .subtitle{ color:#a3a3a3; font-size:.85rem; margin-bottom:26px; }

.form-label-gold{
  color:var(--gold); font-size:.72rem; font-weight:600;
  letter-spacing:1.5px; text-transform:uppercase;
  margin-bottom:6px; display:block;
}

.input-gold{
  background:#0a0a0a !important;
  border:1px solid rgba(212,175,55,.25) !important;
  color:#fff !important;
  border-radius:10px !important;
  padding: 12px 14px !important;
  font-size:.95rem !important;
  width:100%;
  transition: border-color .2s, box-shadow .2s;
}
.input-gold::placeholder{ color:#525252; }
.input-gold:focus{
  outline:none;
  border-color: var(--gold) !important;
  box-shadow: 0 0 0 3px rgba(212,175,55,.15) !important;
}

.input-wrap{ position:relative; margin-bottom:18px; }
.input-wrap .ico{
  position:absolute; left:14px; top:50%; transform:translateY(-50%);
  color: var(--gold); font-size:1rem; pointer-events:none;
}
.input-wrap .input-gold{ padding-left: 40px !important; }

.btn-gold{
  width:100%; padding:13px;
  background: linear-gradient(135deg, var(--gold) 0%, var(--gold-dark) 100%);
  color:#0a0a0a; font-weight:700; letter-spacing:1.5px;
  text-transform:uppercase; font-size:.85rem;
  border:0; border-radius:10px;
  cursor:pointer;
  box-shadow: 0 6px 18px rgba(212,175,55,.25);
  transition: transform .15s, box-shadow .2s, filter .2s;
  margin-top:6px;
}
.btn-gold:hover{
  transform: translateY(-1px);
  box-shadow: 0 10px 24px rgba(212,175,55,.35);
  filter:brightness(1.05);
}
.btn-gold:active{ transform: translateY(0); }

.alert-err{
  background: rgba(239,68,68,.1);
  border:1px solid rgba(239,68,68,.3);
  color:#fca5a5;
  padding:10px 14px; border-radius:8px;
  font-size:.85rem; margin-bottom:18px;
}

.login-card hr{ border-color: rgba(212,175,55,.15); margin: 26px 0 18px; }

.link-gold{ color: var(--gold); text-decoration:none; font-weight:600; }
.link-gold:hover{ color: var(--gold-light); }
.muted-link{ color:#737373; text-decoration:none; }
.muted-link:hover{ color: var(--gold); }

.footer-mini{
  position:absolute; bottom:18px; left:0; right:0;
  text-align:center; color:#525252; font-size:.7rem; letter-spacing:1px;
}
</style>
</head>
<body>
<div class="login-shell">
  <a href="../login.php" class="btn-back-cgastos" title="Volver al sistema principal">
    <i class="bi bi-arrow-left"></i> Volver al inicio
  </a>
  <style>
  .btn-back-cgastos{
    position:absolute; top:20px; left:20px; z-index:3;
    background: rgba(255,255,255,.08);
    border:1px solid rgba(212,175,55,.30);
    color:#cbd5e1;
    padding:7px 14px; border-radius:30px;
    font-size:.78rem; font-weight:600; letter-spacing:.5px;
    text-decoration:none;
    display:inline-flex; align-items:center; gap:6px;
    transition: all .2s;
  }
  .btn-back-cgastos:hover{ background: var(--gold); color:#0a0a0a; border-color: transparent; }
  </style>
  <div class="login-wrap">

    <!-- Card del formulario -->
    <div class="form-side">
      <div class="login-card">
        <h4>Bienvenido</h4>
        <div class="subtitle">Inicia sesión para continuar</div>

        <?php if ($err): ?>
          <div class="alert-err"><i class="bi bi-exclamation-triangle me-1"></i><?= h($err) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
          <label class="form-label-gold">Usuario</label>
          <div class="input-wrap">
            <span class="ico"><i class="bi bi-person"></i></span>
            <input type="text" name="usuario" class="input-gold" required
                   autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
                   placeholder="Tu usuario" value="<?= h($_POST['usuario'] ?? '') ?>">
          </div>

          <label class="form-label-gold">Contraseña</label>
          <div class="input-wrap">
            <span class="ico"><i class="bi bi-lock"></i></span>
            <input type="password" name="clave" class="input-gold" required placeholder="••••••••">
          </div>

          <button class="btn-gold" type="submit">
            <i class="bi bi-arrow-right-circle me-1"></i> Ingresar
          </button>
        </form>

        <hr>
        <div class="text-center" style="color:#a3a3a3; font-size:.85rem;">
          ¿Aún no tienes cuenta? <a href="registro.php" class="link-gold">Regístrate</a>
        </div>
        <div class="text-center mt-2" style="font-size:.8rem;">
          <a href="recuperar.php" class="muted-link">¿Olvidaste tu contraseña?</a>
        </div>
      </div>
    </div>

  </div>

  <div class="footer-mini">
    DO GROUP &middot; CONSTRUYENDO EL FUTURO
  </div>
</div>
</body>
</html>
