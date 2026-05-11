<?php
if (!isset($_SESSION)) session_start();
$rol = $_SESSION['user_rol'] ?? '';
$nombre = $_SESSION['user_nombre'] ?? 'Invitado';
$nick = $_SESSION['user_usuario'] ?? '';
$current = basename($_SERVER['PHP_SELF']);

// Foto del usuario actual (para el avatar del sidebar)
$fotoActualUser = '';
$notifCount = 0;
if (!empty($_SESSION['user_id']) && isset($pdo)) {
    try {
        $qf = $pdo->prepare("SELECT foto_perfil FROM usuarios WHERE id=?");
        $qf->execute([(int)$_SESSION['user_id']]);
        $fotoActualUser = $qf->fetchColumn() ?: '';
        $qn = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id=? AND leida=0");
        $qn->execute([(int)$_SESSION['user_id']]);
        $notifCount = (int)$qn->fetchColumn();
    } catch (Exception $e) { $fotoActualUser = ''; }
}
$urlFotoUser = function_exists('urlFotoUsuario') ? urlFotoUsuario($fotoActualUser) : '';

function sidelink($href, $label, $icon, $current) {
    $active = ($current === $href) ? 'active' : '';
    return "<li><a class='side-link $active' href='$href'>
              <span class='side-icon'><i class='bi $icon'></i></span>
              <span class='side-label'>$label</span>
            </a></li>";
}

$links = [];
if ($rol === 'usuario') {
    $links[] = ['dashboard.php',   'Inicio',           'bi-house-door-fill'];
    $links[] = ['gasto_nuevo.php', 'Registrar gasto',  'bi-plus-circle-fill'];
    $links[] = ['mis_gastos.php',  'Mis gastos',       'bi-list-ul'];
} elseif ($rol === 'jefe') {
    $links[] = ['jefe_dashboard.php', 'Mi equipo',          'bi-people-fill'];
    $links[] = ['jefe_cierres.php',   'Cierres del equipo', 'bi-clipboard-check-fill'];
} elseif ($rol === 'validador') {
    $links[] = ['validador_dashboard.php', 'Cierres',            'bi-shield-check'];
    $links[] = ['validador_gastos.php',    'Gastos por usuario', 'bi-people-fill'];
} elseif ($rol === 'admin') {
    $links[] = ['admin_usuarios.php',     'Usuarios',           'bi-people-fill'];
    $links[] = ['admin_asignaciones.php', 'Asignaciones',       'bi-cash-coin'];
    $links[] = ['admin_gastos.php',       'Gastos por usuario', 'bi-person-vcard'];
    $links[] = ['admin_categorias.php',   'Categorias',         'bi-tags-fill'];
}
?>

<!-- Topbar móvil -->
<header class="mobile-topbar d-lg-none">
  <button type="button" class="btn-burger" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Menú">
    <i class="bi bi-list"></i>
  </button>
  <div class="mobile-brand">
    <img src="img/logo.png" alt="DO Group" class="mobile-brand-logo"> Control de Gastos Diarios
  </div>
  <button type="button" class="btn-burger position-relative" id="btnNotifTop" aria-label="Notificaciones">
    <i class="bi bi-bell-fill"></i>
    <?php if ($notifCount > 0): ?>
      <span class="position-absolute translate-middle badge rounded-pill bg-danger" style="top:8px;right:4px;font-size:10px;">
        <?= $notifCount > 9 ? '9+' : $notifCount ?>
      </span>
    <?php endif; ?>
  </button>
  <a href="perfil.php" class="btn-burger" aria-label="Perfil">
    <i class="bi bi-person-circle"></i>
  </a>
  <a href="/inicio.php" class="btn-burger" aria-label="Volver al sistema" title="Volver al sistema principal">
    <i class="bi bi-house-up-fill"></i>
  </a>
</header>

<!-- Sidebar -->
<aside class="sidebar offcanvas-lg offcanvas-start" id="sidebar" tabindex="-1">
  <div class="sidebar-brand">
    <div class="sidebar-logo"><img src="img/logo.png" alt="DO Group"></div>
    <div>
      <div class="sidebar-title">Control de Gastos Diarios</div>
      <div class="sidebar-subtitle">v<?= APP_VER ?></div>
    </div>
    <button class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#sidebar"></button>
  </div>

  <nav class="sidebar-nav">
    <a href="/inicio.php"
       style="display:flex;align-items:center;justify-content:center;gap:10px;
              margin:12px 14px;padding:12px 14px;border-radius:10px;
              background:linear-gradient(135deg,#ff8c42,#d97706);color:#fff;
              text-decoration:none;font-weight:800;letter-spacing:.3px;
              box-shadow:0 4px 12px rgba(217,119,6,.4);transition:transform .15s,box-shadow .15s"
       onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 16px rgba(217,119,6,.55)'"
       onmouseout="this.style.transform='';this.style.boxShadow='0 4px 12px rgba(217,119,6,.4)'">
      <i class="bi bi-house-up-fill" style="font-size:1.2rem"></i>
      <span>Volver al sistema</span>
    </a>

    <div class="side-section">Menú</div>
    <ul class="side-list">
      <?php foreach ($links as $l) echo sidelink($l[0], $l[1], $l[2], $current); ?>
    </ul>

    <div class="side-section">Cuenta</div>
    <ul class="side-list">
      <?= sidelink('perfil.php', 'Mi perfil', 'bi-person-fill-gear', $current) ?>
    </ul>

  </nav>

  <div class="sidebar-user">
    <?php if ($urlFotoUser): ?>
      <img class="user-avatar" src="<?= h($urlFotoUser) ?>" alt="<?= h($nombre) ?>"
           style="object-fit:cover;padding:0;">
    <?php else: ?>
      <div class="user-avatar"><?= strtoupper(substr($nick ?: $nombre ?: '?', 0, 1)) ?></div>
    <?php endif; ?>
    <div class="user-info">
      <div class="user-name"><?= h($nick ?: $nombre) ?></div>
      <div class="user-rol"><?= h(ucfirst($rol)) ?></div>
    </div>
    <a href="perfil.php" class="user-edit" title="Editar perfil"><i class="bi bi-gear"></i></a>
  </div>
</aside>

<!-- Contenido principal -->
<main class="main-content">
<div class="container-fluid p-3 p-md-4">

<?php if (!empty($_SESSION['user_id'])): ?>
<div class="alert alert-light border d-flex justify-content-between align-items-center mb-3 py-2 px-3 d-print-none" style="background:#f0f9ff;border-color:#0d9488 !important;font-size:.85rem">
  <span><i class="bi bi-person-check-fill text-success me-2"></i>
    Sesión activa: <strong><?= htmlspecialchars($nombre) ?></strong>
    <span class="text-muted">(<?= htmlspecialchars($nick) ?> · rol <?= htmlspecialchars($rol) ?>)</span>
  </span>
  <span class="text-muted small"><?= htmlspecialchars(date('d-m-Y H:i')) ?></span>
</div>
<?php endif; ?>
<?php
// Boton volver atras: oculto en las pantallas "home" de cada rol
$paginasHome = ['dashboard.php','jefe_dashboard.php','validador_dashboard.php','admin_usuarios.php','login.php','registro.php','recuperar.php'];
if (!in_array($current, $paginasHome, true)):
?>
<div class="mb-3 d-print-none">
  <button type="button" class="btn btn-sm btn-outline-secondary btn-volver" onclick="window.history.length > 1 ? window.history.back() : (window.location.href='dashboard.php')">
    <i class="bi bi-arrow-left"></i> Volver
  </button>
</div>
<?php endif; ?>
<?php @include __DIR__ . '/bienvenida.php'; ?>
<?php
foreach (getFlash() as $f):
    $m = ['exito'=>'success','error'=>'danger','warn'=>'warning','info'=>'info'][$f['tipo']] ?? 'info';
?>
<div class="alert alert-<?= $m ?> alert-dismissible fade show" role="alert">
  <?= h($f['msg']) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>
