<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>DOGroup - Órdenes de Compra</title>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#1a1a2e">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="DOGroup">
  <link rel="apple-touch-icon" href="/img/icon-192.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <?php $cssVer = @filemtime(__DIR__.'/../css/styles.css') ?: time(); ?>
  <link href="/css/styles.css?v=<?= $cssVer ?>" rel="stylesheet">
  <link href="/css/notificaciones.css?v=<?= $cssVer ?>" rel="stylesheet">
  <!-- Alpine.js - reactividad ligera sin build tools -->
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</head>
<body class="body-app min-vh-100">
<?php if (!empty($usuario)):
  $notifCount = 0;
  $notifCotCount = 0;
  $notifRecibCount = 0;
  try {
    $stmtNotif = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobaciones WHERE usuario_id = ? AND estado = 'pendiente'");
    $stmtNotif->execute([$usuario['id']]);
    $notifCount = (int)$stmtNotif->fetchColumn();
    $stmtNotifCot = $pdo->prepare("SELECT COUNT(*) FROM cot_aprobaciones WHERE usuario_id = ? AND estado = 'pendiente'");
    $stmtNotifCot->execute([$usuario['id']]);
    $notifCotCount = (int)$stmtNotifCot->fetchColumn();
    $stmtNotifRecib = $pdo->prepare("SELECT COUNT(*) FROM cot_notificaciones WHERE destinatario_id = ? AND leida = 0");
    $stmtNotifRecib->execute([$usuario['id']]);
    $notifRecibCount = (int)$stmtNotifRecib->fetchColumn();
    $notifOCRecibCount = 0;
    $stmtNotifOCRecib = $pdo->prepare("SELECT COUNT(*) FROM oc_notificaciones WHERE destinatario_id = ? AND leida = 0");
    $stmtNotifOCRecib->execute([$usuario['id']]);
    $notifOCRecibCount = (int)$stmtNotifOCRecib->fetchColumn();
  } catch (Exception $e) { }
  $totalNotif = $notifCount + $notifCotCount + $notifRecibCount + $notifOCRecibCount;
?>
<button type="button" class="sidebar-toggle" id="sidebarToggle" title="Mostrar / ocultar menú"><i class="bi bi-list"></i></button>
<div class="sidebar-overlay d-md-none" id="sidebarOverlay"></div>
<div class="app-layout d-flex">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <img src="/img/logo.png" alt="DOGroup" class="sidebar-logo-img">
      <button type="button" class="sidebar-close d-md-none ms-auto" id="sidebarClose"><i class="bi bi-x-lg"></i></button>
    </div>
    <nav class="sidebar-nav">
      <?php
        $esValidadorNav = false;
        try {
          $stmtValNav = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobadores WHERE usuario_id = ?");
          $stmtValNav->execute([$usuario['id']]);
          $esValidadorNav = (int)$stmtValNav->fetchColumn() > 0;
        } catch (Exception $e) {}
      ?>
      <a href="/inicio.php" class="btn btn-menu"><i class="bi bi-grid-fill me-2"></i>Inicio</a>
      <?php if (in_array($usuario['rol'], ['admin', 'gerente_comercial']) || $esValidadorNav): ?>
      <a href="/mis_aprobaciones.php" class="btn btn-menu position-relative">
        <i class="bi bi-bell me-2"></i>Aprobaciones
        <?php if ($totalNotif > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:10px;"><?= $totalNotif ?></span>
        <?php endif; ?>
      </a>
      <?php endif; ?>

      <?php
        // ÁREA COMERCIAL
        $verComercial = canAccess('obras', $usuario, $pdo)
                     || canAccess('contactos', $usuario, $pdo)
                     || canAccess('cot', $usuario, $pdo)
                     || canAccess('oc', $usuario, $pdo)
                     || canAccess('estados_pago', $usuario, $pdo);
        if ($verComercial):
      ?>
      <button type="button" class="sidebar-area-toggle" data-area="comercial" aria-expanded="false">
        <i class="bi bi-briefcase-fill me-2"></i>Área Comercial
        <i class="bi bi-chevron-right chevron"></i>
      </button>
      <div class="sidebar-area-content" data-area="comercial">

      <?php if (canAccess('obras', $usuario, $pdo)): ?>
      <a href="/obras.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-building me-2"></i>Obras</a>
      <?php endif; ?>

      <?php if (canAccess('contactos', $usuario, $pdo)): ?>
      <span class="sidebar-section-label"><i class="bi bi-person-vcard me-1"></i>Clientes y Proveedores</span>
      <a href="/clientes.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-people me-2"></i>Clientes</a>
      <a href="/proveedores.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-truck me-2"></i>Proveedores</a>
      <?php endif; ?>

      <?php if (canAccess('cot', $usuario, $pdo)): ?>
      <span class="sidebar-section-label"><i class="bi bi-receipt me-1"></i>Cotizaciones</span>
      <a href="/cotizaciones/cotizacion.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-plus-circle me-2"></i>Nueva Cotización</a>
      <a href="/cotizaciones/historial_cotizaciones.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-journal-text me-2"></i>Historial</a>
      <a href="/cotizaciones/historial_cotizaciones.php?estado=adjudicada" class="btn btn-menu btn-menu-sub"><i class="bi bi-graph-up me-2"></i>Seguimiento</a>
      <?php
        $pendCotSB = 0;
        try { $stPCS=$pdo->prepare("SELECT COUNT(*) FROM cot_aprobaciones WHERE estado='pendiente' AND usuario_id=?"); $stPCS->execute([$usuario['id']]); $pendCotSB=(int)$stPCS->fetchColumn(); } catch(Exception $e){}
        if ($pendCotSB > 0):
      ?>
      <a href="/mis_aprobaciones.php" class="btn btn-menu btn-menu-sub btn-menu-alert">
        <i class="bi bi-bell-fill me-2"></i>Aprobaciones
        <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 7px;font-size:.7rem;font-weight:700;margin-left:auto"><?= $pendCotSB ?></span>
      </a>
      <?php endif; ?>
      <?php endif; ?>

      <?php if (canAccess('oc', $usuario, $pdo)): ?>
      <span class="sidebar-section-label"><i class="bi bi-file-earmark-text me-1"></i>Órdenes de Compra</span>
      <a href="/index.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-file-earmark-plus me-2"></i>Nueva OC</a>
      <a href="/historial.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-clock-history me-2"></i>Historial OC</a>
      <?php endif; ?>

      <?php if (canAccess('estados_pago', $usuario, $pdo)): ?>
      <span class="sidebar-section-label"><i class="bi bi-cash-coin me-1"></i>Estados de Pago</span>
      <a href="/estados_pago.php?tipo=cobro_empresa" class="btn btn-menu btn-menu-sub"><i class="bi bi-graph-up-arrow me-2"></i>Ventas</a>
      <a href="/estados_pago.php?tipo=pago_proveedor" class="btn btn-menu btn-menu-sub"><i class="bi bi-bank me-2"></i>Pago Proveedores</a>
      <a href="/ep_reporte_excel.php?tipo=todos&periodo=mes&mes=<?= date('Y-m') ?>" class="btn btn-menu btn-menu-sub"><i class="bi bi-file-earmark-excel-fill me-2 text-success"></i>Reporte Excel</a>
      <?php endif; ?>

      </div>
      <?php endif; /* fin Comercial */ ?>

      <?php
        // ÁREA OPERACIONES
        $verCombustible = isCombustibleResponsable($usuario, $pdo) || isCombustibleAdmin($usuario, $pdo) || canAccess('combustible', $usuario, $pdo);
        $esAdminComb = isCombustibleAdmin($usuario, $pdo);
        $verOperaciones = canAccess('despacho', $usuario, $pdo) || $verCombustible;
        if ($verOperaciones):
      ?>
      <button type="button" class="sidebar-area-toggle" data-area="operaciones" aria-expanded="false">
        <i class="bi bi-gear-wide-connected me-2"></i>Área Operaciones
        <i class="bi bi-chevron-right chevron"></i>
      </button>
      <div class="sidebar-area-content" data-area="operaciones">

      <?php if (canAccess('despacho', $usuario, $pdo)): ?>
      <span class="sidebar-section-label"><i class="bi bi-truck-front-fill me-1"></i>Despacho</span>
      <a href="/tickets_despacho.php" class="btn btn-menu btn-menu-sub position-relative">
        <i class="bi bi-ticket-perforated me-2"></i>Tickets de Despacho
        <span id="despacho-badge" style="display:none;position:absolute;top:4px;right:6px;background:#f59e0b;color:#fff;font-size:10px;font-weight:700;min-width:17px;height:17px;border-radius:9px;align-items:center;justify-content:center;padding:0 3px">0</span>
      </a>
      <?php
        $verObrasMenu = false;
        try {
          if (isDespachoAdmin($usuario, $pdo)) $verObrasMenu = true;
          if (!$verObrasMenu) {
            $stOM = $pdo->prepare("SELECT COUNT(*) FROM obra_supervisores WHERE usuario_id=? AND activo=1");
            $stOM->execute([$usuario['id']]); if ($stOM->fetchColumn()) $verObrasMenu = true;
          }
          if (!$verObrasMenu) {
            $stOE = $pdo->prepare("SELECT COUNT(*) FROM despacho_encargado_operaciones WHERE usuario_id=?");
            $stOE->execute([$usuario['id']]); if ($stOE->fetchColumn()) $verObrasMenu = true;
          }
        } catch(Exception $e){}
        if ($verObrasMenu):
      ?>
      <a href="/obras_equipos.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-building me-2"></i>Obras · Equipos</a>
      <a href="/encargado_obras.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-clipboard2-data me-2"></i>Resumen por obra</a>
      <a href="/reporte_despacho.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-file-earmark-bar-graph me-2"></i>Reporte diario</a>
      <?php endif; ?>
      <?php
        $esRecepSidebar = false;
        try { $stRS = $pdo->prepare("SELECT COUNT(*) FROM despacho_receptores WHERE usuario_id=?"); $stRS->execute([$usuario['id']]); $esRecepSidebar = (int)$stRS->fetchColumn() > 0; } catch(Exception $e) {}
        if (isDespachoAdmin($usuario, $pdo) || $esRecepSidebar):
      ?>
      <a href="/tickets_despacho_cierre.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-calendar2-check me-2"></i>Cierre del Día</a>
      <a href="/tickets_despacho_reporte_diario.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-clock-history me-2"></i>Historial Diario</a>
      <?php endif; ?>
      <?php endif; ?>

      <?php if ($verCombustible): ?>
      <span class="sidebar-section-label"><i class="bi bi-fuel-pump-fill me-1"></i>Distribución Combustible</span>
      <a href="/combustible.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-fuel-pump-fill me-2"></i>Hojas de combustible</a>
      <a href="/combustible_hoja_nueva.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-plus-circle me-2"></i>Nueva hoja</a>
      <?php if ($esAdminComb): ?>
      <a href="/admin_combustible.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-gear me-2"></i>Admin Combustible</a>
      <?php endif; ?>
      <?php endif; ?>

      </div>
      <?php endif; /* fin Operaciones */ ?>

      <?php if (($usuario['rol']??'')==='admin'): ?>
      <button type="button" class="sidebar-area-toggle" data-area="rrhh" aria-expanded="false">
        <i class="bi bi-people-fill me-2"></i>Recursos Humanos
        <i class="bi bi-chevron-right chevron"></i>
      </button>
      <div class="sidebar-area-content" data-area="rrhh">
        <span class="sidebar-section-label" style="opacity:.6"><i class="bi bi-hourglass-split me-1"></i>Próximamente</span>
      </div>
      <?php endif; ?>

      <?php
        // ÁREA FINANZAS
        $esSuperFaenaSB = false;
        try { $stSFSB=$pdo->prepare("SELECT COUNT(*) FROM obra_supervisores WHERE usuario_id=? AND activo=1"); $stSFSB->execute([$usuario['id']]); $esSuperFaenaSB=(bool)$stSFSB->fetchColumn(); } catch(Exception $e){}
      ?>
      <button type="button" class="sidebar-area-toggle" data-area="finanzas" aria-expanded="false">
        <i class="bi bi-cash-stack me-2"></i>Área Finanzas
        <i class="bi bi-chevron-right chevron"></i>
      </button>
      <div class="sidebar-area-content" data-area="finanzas">
        <a href="/finanzas_dashboard.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-graph-up-arrow me-2"></i>Dashboard Financiero</a>
        <a href="/gastos_faena.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-receipt-cutoff me-2"></i>Gastos por Obra</a>
        <a href="/gastos_diarios/sso.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-calendar3 me-2"></i>Gastos diarios</a>
        <?php if ($esSuperFaenaSB || ($usuario['rol']??'')==='admin'): ?>
        <a href="/faena_supervisor.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-person-check-fill me-2"></i>Panel Supervisor</a>
        <?php endif; ?>
      </div>

      <?php if (($usuario['rol']??'')==='admin'): ?>
      <button type="button" class="sidebar-area-toggle" data-area="sistema" aria-expanded="false">
        <i class="bi bi-shield-lock me-2"></i>Sistema
        <i class="bi bi-chevron-right chevron"></i>
      </button>
      <div class="sidebar-area-content" data-area="sistema">
        <a href="/admin.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-gear me-2"></i>Administración</a>
        <a href="/backup.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-database-fill-add me-2"></i>Copias de Seguridad</a>
      </div>
      <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
      <span class="sidebar-user"><i class="bi bi-person-circle me-2"></i><?= htmlspecialchars($usuario['nombre']) ?></span>
      <a href="/logout.php" class="btn btn-menu btn-salir"><i class="bi bi-box-arrow-right me-2"></i>Salir</a>
    </div>
  </aside>
  <main class="main-content flex-grow-1 py-4">
<?php else: ?>
<main class="container py-4 flex-grow-1">
<?php endif; ?>
<?php if (!empty($usuario)): ?>
<div class="welcome-banner">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <div class="d-flex align-items-center gap-3">
      <span class="welcome-text"><i class="bi bi-hand-wave me-2"></i>Bienvenido, <strong><?= htmlspecialchars($usuario['nombre']) ?></strong></span>
      <!-- Campana notificaciones en tiempo real -->
      <div class="position-relative" style="z-index:1000">
        <button id="notif-btn" title="Notificaciones">
          <i class="bi bi-bell-fill"></i>
          <span id="notif-badge" style="display:none">0</span>
        </button>
        <div id="notif-panel">
          <div class="notif-header">
            <h6><span class="realtime-dot"></span>Notificaciones</h6>
            <div id="notif-resumen"></div>
          </div>
          <div id="notif-lista" class="notif-lista"></div>
          <div class="notif-footer">
            <a href="/mis_aprobaciones.php">Ver todas las aprobaciones →</a>
          </div>
        </div>
      </div>
    </div>
    <span class="welcome-date"><i class="bi bi-calendar3 me-1"></i><?php
      $dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
      $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
      echo $dias[date('w')].' '.date('d').' de '.$meses[(int)date('n')].' de '.date('Y');
    ?></span>
  </div>
</div>
<?php
  $paginaActual = basename($_SERVER['PHP_SELF']);
  $sinBotonVolver = ['inicio.php', 'login.php', 'logout.php'];
  if (!in_array($paginaActual, $sinBotonVolver)):
?>
<div class="container nav-volver-wrap no-print">
  <a href="/inicio.php" class="btn btn-volver-inicio">
    <i class="bi bi-house-door me-1"></i>Inicio
  </a>
</div>
<?php endif; ?>
<div class="container">
<?php endif; ?>
<!-- Script notificaciones en tiempo real -->
<?php if (!empty($usuario)): ?>
<script src="/js/notificaciones.js"></script>
<?php 
$pagActual = basename($_SERVER['PHP_SELF'] ?? '');
$paginasDespacho = ['tickets_despacho.php','tickets_despacho_admin.php','tickets_despacho_ver.php','tickets_despacho_cierre.php','tickets_despacho_reporte_diario.php'];
if (in_array($pagActual, $paginasDespacho)):
?>
<script src="/js/despacho_notif.js"></script>
<?php endif; ?>
<?php endif; ?>
