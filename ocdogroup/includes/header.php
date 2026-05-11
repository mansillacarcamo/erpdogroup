<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>DOGroup - Órdenes de Compra</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="css/styles.css" rel="stylesheet">
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
      <img src="img/logo.png" alt="DOGroup" class="sidebar-logo-img">
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
      <a href="inicio.php" class="btn btn-menu"><i class="bi bi-grid-fill me-2"></i>Inicio</a>
      <hr class="sidebar-divider">
      <?php if (in_array($usuario['rol'], ['admin', 'gerente_comercial']) || $esValidadorNav): ?>
      <a href="mis_aprobaciones.php" class="btn btn-menu position-relative">
        <i class="bi bi-bell me-2"></i>Aprobaciones
        <?php if ($totalNotif > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:10px;"><?= $totalNotif ?></span>
        <?php endif; ?>
      </a>
      <hr class="sidebar-divider">
      <?php endif; ?>

      <?php if (canAccess('oc', $usuario, $pdo)): ?>
      <a href="index.php" class="btn btn-menu"><i class="bi bi-file-earmark-plus me-2"></i>Nueva OC</a>
      <a href="historial.php" class="btn btn-menu"><i class="bi bi-clock-history me-2"></i>Historial OC</a>
      <hr class="sidebar-divider">
      <?php endif; ?>

      <?php if (canAccess('cot', $usuario, $pdo)): ?>
      <a href="cotizacion.php" class="btn btn-menu"><i class="bi bi-receipt me-2"></i>Nueva Cotización</a>
      <a href="historial_cotizaciones.php" class="btn btn-menu"><i class="bi bi-journal-text me-2"></i>Historial Cot.</a>
      <hr class="sidebar-divider">
      <?php endif; ?>

      <?php if (canAccess('contactos', $usuario, $pdo)): ?>
      <span class="sidebar-section-label"><i class="bi bi-person-vcard me-1"></i>Clientes y Proveedores</span>
      <a href="clientes.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-people me-2"></i>Clientes</a>
      <a href="proveedores.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-truck me-2"></i>Proveedores</a>
      <hr class="sidebar-divider">
      <?php endif; ?>

      <?php if (canAccess('obras', $usuario, $pdo)): ?>
      <a href="obras.php" class="btn btn-menu"><i class="bi bi-building me-2"></i>Obras</a>
      <hr class="sidebar-divider">
      <?php endif; ?>

      <?php if (canAccess('estados_pago', $usuario, $pdo)): ?>
      <span class="sidebar-section-label"><i class="bi bi-cash-coin me-1"></i>Estados de Pago</span>
      <a href="estados_pago.php?tipo=cobro_dogroup" class="btn btn-menu btn-menu-sub"><i class="bi bi-cash-stack me-2"></i>Cobros DOGroup</a>
      <a href="estados_pago.php?tipo=cobro_empresa" class="btn btn-menu btn-menu-sub"><i class="bi bi-building-fill-up me-2"></i>Cobro Empresas</a>
      <a href="estados_pago.php?tipo=cobro_cliente" class="btn btn-menu btn-menu-sub"><i class="bi bi-person-check-fill me-2"></i>Cobro Clientes</a>
      <a href="estados_pago.php?tipo=pago_maquinaria" class="btn btn-menu btn-menu-sub"><i class="bi bi-truck-flatbed me-2"></i>Pago Maquinarias</a>
      <a href="estados_pago.php?tipo=pago_proveedor" class="btn btn-menu btn-menu-sub"><i class="bi bi-bank me-2"></i>Pago Proveedores</a>
      <hr class="sidebar-divider">
      <?php endif; ?>

      <?php if (canAccess('despacho', $usuario, $pdo)): ?>
      <span class="sidebar-section-label"><i class="bi bi-truck-front-fill me-1"></i>Despacho</span>
      <a href="tickets_despacho.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-ticket-perforated me-2"></i>Tickets de Despacho</a>
      <?php
        $esRecepSidebar = false;
        try { $stRS = $pdo->prepare("SELECT COUNT(*) FROM despacho_receptores WHERE usuario_id=?"); $stRS->execute([$usuario['id']]); $esRecepSidebar = (int)$stRS->fetchColumn() > 0; } catch(Exception $e) {}
        if (isDespachoAdmin($usuario, $pdo) || $esRecepSidebar):
      ?>
      <a href="tickets_despacho_cierre.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-calendar2-check me-2"></i>Cierre del Día</a>
      <a href="tickets_despacho_reporte_diario.php" class="btn btn-menu btn-menu-sub"><i class="bi bi-clock-history me-2"></i>Historial Diario</a>
      <?php endif; ?>
      <hr class="sidebar-divider">
      <?php endif; ?>

      <?php if ($usuario['rol'] === 'admin'): ?>
      <a href="admin.php" class="btn btn-menu"><i class="bi bi-gear me-2"></i>Administración</a>
      <?php endif; ?>
      <hr class="sidebar-divider">
      <span class="sidebar-section-label">Otro Software</span>
      <a href="https://app.besimplit.com/accounts/login/?next=/" target="_blank" class="btn btn-menu btn-besimplit"><img src="img/besimplit.svg" alt="Besimplit" class="besimplit-icon"></a>
      <a href="https://www.google.com/android/find/u/2/" target="_blank" class="btn btn-menu"><i class="bi bi-geo-alt-fill me-2"></i>Ver Ubicación Tablet</a>
    </nav>
    <div class="sidebar-footer">
      <span class="sidebar-user"><i class="bi bi-person-circle me-2"></i><?= htmlspecialchars($usuario['nombre']) ?></span>
      <a href="logout.php" class="btn btn-menu btn-salir"><i class="bi bi-box-arrow-right me-2"></i>Salir</a>
    </div>
  </aside>
  <main class="main-content flex-grow-1 py-4">
<?php else: ?>
<main class="container py-4 flex-grow-1">
<?php endif; ?>
<?php if (!empty($usuario)): ?>
<div class="welcome-banner">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
    <span class="welcome-text"><i class="bi bi-hand-wave me-2"></i>Bienvenido, <strong><?= htmlspecialchars($usuario['nombre']) ?></strong></span>
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
  <a href="inicio.php" class="btn btn-volver-atras" id="btnVolverAtras" data-fallback="inicio.php">
    <i class="bi bi-arrow-left-circle me-1"></i>Volver atrás
  </a>
  <a href="inicio.php" class="btn btn-volver-inicio">
    <i class="bi bi-house-door me-1"></i>Inicio
  </a>
</div>
<?php endif; ?>
<div class="container">
<?php endif; ?>
