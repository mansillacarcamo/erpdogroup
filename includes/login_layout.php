<?php
/**
 * includes/login_layout.php — Layout unificado de login (formato Gastos)
 *
 * Variables esperadas (definir en el archivo que hace include):
 *   $titulo_pagina  string   Titulo del <title>
 *   $sistema_nombre string   "GESTION DO" / "CONDUCTORES" / "CARCHEK" / "COMBUSTIBLE"
 *   $sistema_tag    string   Subtitulo en mayusculas pequenas
 *   $accent         string   Color hex principal (ej "#dc3545")
 *   $accent_dark    string   Color hex oscuro (ej "#7c1322")
 *   $accent_light   string   Color hex claro (ej "#e7c869")
 *   $headline_html  string   HTML grande (ej 'Innovamos<br><span class="accent">para construir</span><br>el futuro.')
 *   $brand_sub_html string   Parrafo descriptivo (HTML permitido con <strong>)
 *   $features       array    [ ['icon'=>'bi-...', 'title'=>'...', 'desc'=>'...'], ... ]
 *   $form_action    string   URL del POST (default '')
 *   $form_user_field string  Nombre del input usuario (default 'usuario')
 *   $form_pass_field string  Nombre del input password (default 'password')
 *   $form_user_value string  Valor preset (default '')
 *   $err            string   Mensaje de error (vacio si no hay)
 *   $assets_prefix  string   "" o "../" para encontrar img/, css/. (default '')
 *   $extra_links_html string HTML opcional debajo del form (links registro/recuperar)
 */
$volver_url       = $volver_url       ?? 'login.php';
$titulo_pagina    = $titulo_pagina    ?? 'Iniciar sesión · DO Group';
$sistema_nombre   = $sistema_nombre   ?? 'DO GROUP';
$sistema_tag      = $sistema_tag      ?? 'SISTEMA';
$accent           = $accent           ?? '#d4af37';
$accent_dark      = $accent_dark      ?? '#a78329';
$accent_light     = $accent_light     ?? '#e7c869';
$headline_html    = $headline_html    ?? 'Innovamos<br><span class="accent">para construir</span><br>el futuro.';
$brand_sub_html   = $brand_sub_html   ?? 'Plataforma <strong>DO Group</strong>.';
$features         = $features         ?? [];
$form_action      = $form_action      ?? '';
$form_user_field  = $form_user_field  ?? 'usuario';
$form_pass_field  = $form_pass_field  ?? 'password';
$form_user_value  = $form_user_value  ?? '';
$err              = $err              ?? '';
$assets_prefix    = $assets_prefix    ?? '';
$extra_links_html = $extra_links_html ?? '';
$logo_path        = $assets_prefix . 'img/logo.png';
$bg_path          = $assets_prefix . 'img/fondo_login.jpg';

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0a0a0a">
<title><?= h($titulo_pagina) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --accent:       <?= $accent ?>;
  --accent-dark:  <?= $accent_dark ?>;
  --accent-light: <?= $accent_light ?>;
  --bg-deep:      #0a0a0a;
  --bg-card:      #141414;
  --line:         <?= $accent ?>40;
}
*{ box-sizing:border-box; }
html, body { height:100%; }
body{
  margin:0;
  font-family:'Montserrat', system-ui, -apple-system, sans-serif;
  background:#000;
  color:#f1f5f9;
  min-height:100dvh;
  overflow-x:hidden;
}
.login-shell{
  min-height:100dvh;
  display:flex;
  align-items:stretch;
  position:relative;
  background:
    linear-gradient(135deg, rgba(10,10,10,.92) 0%, rgba(20,20,20,.85) 50%, rgba(10,10,10,.95) 100%),
    url('<?= h($bg_path) ?>') center/cover no-repeat,
    linear-gradient(180deg, #0a0a0a 0%, #1a1a1a 100%);
}
.login-shell::before{
  content:''; position:absolute; inset:0;
  background:
    radial-gradient(ellipse 80% 60% at 30% 10%, <?= $accent ?>1A 0%, transparent 60%),
    radial-gradient(ellipse 60% 50% at 80% 90%, <?= $accent ?>10 0%, transparent 60%),
    radial-gradient(circle at 20% 30%, rgba(255,255,255,.03) 0%, transparent 40%);
  pointer-events:none;
}
.login-shell::after{
  content:''; position:absolute; right:-50px; bottom:-50px;
  width:300px; height:300px;
  background: linear-gradient(135deg, transparent 45%, var(--accent) 47%, var(--accent) 50%, transparent 52%);
  opacity:.30; transform:rotate(-15deg);
  pointer-events:none;
}
.login-wrap{
  width:100%; padding:40px 24px;
  position:relative; z-index:1;
  display:flex; align-items:center; justify-content:center;
  min-height:100dvh;
}
.form-side{ display:flex; align-items:center; justify-content:center; width:100%; }
.login-card{
  width:100%; max-width:420px;
  background: linear-gradient(160deg, #1a1a1a 0%, #0d0d0d 100%);
  border:1px solid var(--line);
  border-radius:18px;
  padding:36px 30px;
  box-shadow: 0 20px 60px rgba(0,0,0,.6), inset 0 1px 0 rgba(255,255,255,.05);
  position:relative;
}
.login-card::before{
  content:''; position:absolute; top:0; left:25px; right:25px; height:1px;
  background: linear-gradient(90deg, transparent, var(--accent), transparent);
}
.login-card h4{ color:#fff; font-weight:700; letter-spacing:.5px; font-size:1.4rem; margin-bottom:6px; }
.login-card .subtitle{ color:#a3a3a3; font-size:.85rem; margin-bottom:26px; }

.form-label-accent{
  color:var(--accent); font-size:.72rem; font-weight:600;
  letter-spacing:1.5px; text-transform:uppercase;
  margin-bottom:6px; display:block;
}
.input-accent{
  background:#0a0a0a !important;
  border:1px solid <?= $accent ?>40 !important;
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
  border-color: var(--accent) !important;
  box-shadow: 0 0 0 3px <?= $accent ?>26 !important;
}
.input-wrap{ position:relative; margin-bottom:18px; }
.input-wrap .ico{
  position:absolute; left:14px; top:50%; transform:translateY(-50%);
  color: var(--accent); font-size:1rem; pointer-events:none;
}
.input-wrap .input-accent{ padding-left: 40px !important; }

.btn-accent{
  width:100%; padding:13px;
  background: linear-gradient(135deg, var(--accent) 0%, var(--accent-dark) 100%);
  color:#0a0a0a; font-weight:700; letter-spacing:1.5px;
  text-transform:uppercase; font-size:.85rem;
  border:0; border-radius:10px; cursor:pointer;
  box-shadow: 0 6px 18px <?= $accent ?>40;
  transition: transform .15s, box-shadow .2s, filter .2s;
  margin-top:6px;
}
.btn-accent:hover{ transform: translateY(-1px); box-shadow: 0 10px 24px <?= $accent ?>59; filter:brightness(1.05); }
.btn-accent:active{ transform: translateY(0); }

.alert-err{
  background: rgba(239,68,68,.10);
  border:1px solid rgba(239,68,68,.30);
  color:#fca5a5;
  padding:10px 14px; border-radius:8px;
  font-size:.85rem; margin-bottom:18px;
}
.login-card hr{ border-color: <?= $accent ?>26; margin: 26px 0 18px; }
.link-accent{ color: var(--accent); text-decoration:none; font-weight:600; }
.link-accent:hover{ color: var(--accent-light); }

.footer-mini{
  position:absolute; bottom:18px; left:0; right:0;
  text-align:center; color:#525252; font-size:.7rem; letter-spacing:1px;
  z-index: 2;
}
.footer-mini strong{ color: var(--accent); font-weight:600; }

/* Pequeño boton "volver" arriba */
.btn-back{
  position:absolute; top:20px; left:20px; z-index:3;
  background: rgba(255,255,255,.08);
  border:1px solid rgba(255,255,255,.15);
  color:#cbd5e1;
  padding:7px 14px; border-radius:30px;
  font-size:.78rem; font-weight:600; letter-spacing:.5px;
  text-decoration:none;
  display:inline-flex; align-items:center; gap:6px;
  transition: all .2s;
}
.btn-back:hover{ background: var(--accent); color:#0a0a0a; border-color: transparent; }
</style>
</head>
<body>
<div class="login-shell">
  <a href="<?= h($volver_url) ?>" class="btn-back" title="Volver al sistema principal">
    <i class="bi bi-arrow-left"></i> Volver al inicio
  </a>

  <div class="login-wrap">

    <!-- Card del formulario centrada -->
    <div class="form-side">
      <div class="login-card">
        <div class="text-center mb-3">
          <div class="brand-tag" style="margin-bottom:4px;"><?= h($sistema_tag) ?></div>
        </div>

        <?php if ($err): ?>
          <div class="alert-err"><i class="bi bi-exclamation-triangle me-1"></i><?= h($err) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= h($form_action) ?>" autocomplete="on">
          <label class="form-label-accent">Usuario</label>
          <div class="input-wrap">
            <span class="ico"><i class="bi bi-person"></i></span>
            <input type="text" name="<?= h($form_user_field) ?>" class="input-accent" required
                   autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
                   placeholder="Tu usuario" value="<?= h($form_user_value) ?>">
          </div>

          <label class="form-label-accent">Contraseña</label>
          <div class="input-wrap">
            <span class="ico"><i class="bi bi-lock"></i></span>
            <input type="password" name="<?= h($form_pass_field) ?>" class="input-accent" required placeholder="••••••••">
          </div>

          <button class="btn-accent" type="submit">
            <i class="bi bi-arrow-right-circle me-1"></i> Ingresar
          </button>
        </form>

        <?php if ($extra_links_html): ?>
          <hr>
          <?= $extra_links_html ?>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <div class="footer-mini">
    DO GROUP &middot; Desarrollado por <strong>Departamento de Informática DOGroup</strong>
  </div>
</div>
</body>
</html>
