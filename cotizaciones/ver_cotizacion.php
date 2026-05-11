<?php
require_once '../config.php';
requireAuth();

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_pago_cuenta') {
    $nuevo = trim($_POST['pago_cuenta'] ?? '');
    $pdo->prepare("UPDATE cotizaciones SET pago_cuenta = ? WHERE id = ?")->execute([$nuevo, $id]);
    header("Location: ver_cotizacion.php?id=$id&pago_ok=1");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
$stmt->execute([$id]);
$cot = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cot) { header('Location: historial_cotizaciones.php'); exit; }

$stmtItems = $pdo->prepare("SELECT * FROM cot_items WHERE cot_id = ? ORDER BY id");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$stmtAprob = $pdo->prepare("SELECT a.*, u.usuario as usuario_login, u.cargo as usuario_cargo FROM cot_aprobaciones a LEFT JOIN usuarios u ON a.usuario_id = u.id WHERE a.cot_id = ? ORDER BY a.id");
$stmtAprob->execute([$id]);
$aprobaciones = $stmtAprob->fetchAll(PDO::FETCH_ASSOC);

$todasAprobadas = false;
if (count($aprobaciones) > 0) {
    $countAprobadas = 0;
    foreach ($aprobaciones as $_a) { if ($_a['estado'] === 'aprobada') $countAprobadas++; }
    $todasAprobadas = $countAprobadas === count($aprobaciones);
}
$cotAprobada = $cot['estado'] === 'aprobada';
$enRevision = $cot['estado'] === 'en_revision';

$aprobadoresDisponibles = $pdo->query("
    SELECT a.usuario_id, u.nombre, u.usuario, u.cargo
    FROM oc_aprobadores a
    JOIN usuarios u ON a.usuario_id = u.id
    ORDER BY u.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$stmtEnvios = $pdo->prepare("SELECT * FROM cot_envios WHERE cot_id = ? ORDER BY fecha DESC");
$stmtEnvios->execute([$id]);
$envios = $stmtEnvios->fetchAll(PDO::FETCH_ASSOC);

$destinatariosDisponibles = $pdo->query("
    SELECT DISTINCT u.id, u.usuario, u.nombre, u.cargo, u.rol,
        CASE WHEN a.usuario_id IS NOT NULL THEN 1 ELSE 0 END as es_validador
    FROM usuarios u
    LEFT JOIN oc_aprobadores a ON u.id = a.usuario_id
    WHERE u.rol = 'admin' OR a.usuario_id IS NOT NULL
    ORDER BY u.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$stmtNotifEnvios = $pdo->prepare("SELECT n.*, u.nombre as dest_nombre, u.usuario as dest_usuario FROM cot_notificaciones n JOIN usuarios u ON n.destinatario_id = u.id WHERE n.cot_id = ? ORDER BY n.fecha DESC");
$stmtNotifEnvios->execute([$id]);
$notifEnvios = $stmtNotifEnvios->fetchAll(PDO::FETCH_ASSOC);

$isNueva = isset($_GET['nueva']);

$fechaFmt = date('d-m-Y', strtotime($cot['fecha']));
$validoFmt = $cot['valido_hasta'] ? date('d-m-Y', strtotime($cot['valido_hasta'])) : '';
$monedaCot  = $cot['moneda'] ?? 'CLP';
$simMonCot  = simboloMoneda($monedaCot);
$tipoCambioCot = (float)($cot['tipo_cambio'] ?? 1) ?: 1;
function fmtCot($v) { global $monedaCot; return formatMoneda($v, $monedaCot); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cotización N° <?= $cot['numero'] ?> — DOGroup</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="../css/styles.css" rel="stylesheet">
</head>
<body>
<?php if ($isNueva): ?>
<div class="alert alert-success text-center m-3 no-print"><i class="bi bi-check-circle me-2"></i>Cotización N° <?= $cot['numero'] ?> creada exitosamente</div>
<?php endif; ?>
<?php if (!empty($_GET['editada'])): ?>
<div class="alert alert-success text-center m-3 no-print"><i class="bi bi-pencil-square me-2"></i>Cotización N° <?= $cot['numero'] ?> editada exitosamente</div>
<?php endif; ?>
<?php if (!empty($_GET['pago_ok'])): ?>
<div class="alert alert-success text-center m-3 no-print"><i class="bi bi-check-circle me-2"></i>Pago en cuenta actualizado.</div>
<?php endif; ?>

<div class="no-print text-center py-3 bg-light border-bottom">
  <a href="historial_cotizaciones.php" class="btn btn-outline-dark me-2"><i class="bi bi-arrow-left me-1"></i>Volver al historial</a>
  <a href="cotizacion.php" class="btn btn-outline-danger me-2"><i class="bi bi-plus-circle me-1"></i>Nueva Cotización</a>
  <a href="historial_cotizaciones.php" class="btn btn-outline-secondary me-2"><i class="bi bi-journal-text me-1"></i>Historial</a>
  <a href="seguimiento_cot.php?id=<?= $id ?>" class="btn btn-outline-success me-2"><i class="bi bi-diagram-3 me-1"></i>Seguimiento</a>
  <?php if (in_array($cot['estado'], ['pendiente', 'corregir'])): ?>
  <a href="editar_cotizacion.php?id=<?= $id ?>" class="btn btn-warning me-2"><i class="bi bi-pencil-square me-1"></i>Editar Cotización</a>
  <?php endif; ?>
  <button onclick="imprimirCot()" class="btn btn-danger me-2"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>

  <?php if ($cot['estado'] === 'pendiente' || $cot['estado'] === 'corregir'): ?>
  <?php if (!empty($aprobadoresDisponibles)): ?>
  <button class="btn btn-warning me-2" data-bs-toggle="modal" data-bs-target="#modalEnviarValidacion"><i class="bi bi-send-check me-1"></i>Enviar Validación</button>
  <?php else: ?>
  <button class="btn btn-warning me-2" disabled title="No hay validadores configurados"><i class="bi bi-send-check me-1"></i>Enviar Validación</button>
  <?php endif; ?>
  <?php endif; ?>

</div>

<?php if (!empty($_GET['aprobacion_enviada'])): ?>
<div class="alert alert-success text-center m-3 no-print">
  <i class="bi bi-check-circle me-2"></i>Solicitud de validación enviada al perfil de los validadores seleccionados.
</div>
<?php endif; ?>
<?php if (!empty($_GET['aprobacion_error'])): ?>
<div class="alert alert-danger text-center m-3 no-print"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($_GET['aprobacion_error']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['envio_ok'])): ?>
<div class="alert alert-success text-center m-3 no-print"><i class="bi bi-check-circle me-2"></i>Cotización enviada por correo exitosamente.</div>
<?php endif; ?>
<?php if (!empty($_GET['envio_error'])): ?>
<div class="alert alert-danger text-center m-3 no-print"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($_GET['envio_error']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['envio_admin_ok'])): ?>
<div class="alert alert-success text-center m-3 no-print"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_GET['envio_admin_ok']) ?></div>
<?php endif; ?>

<?php if (count($aprobaciones) > 0): ?>
<div class="no-print" style="max-width:800px;margin:12px auto;">
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-dark text-white py-2"><h6 class="mb-0"><i class="bi bi-shield-check me-2"></i>Estado de Validación — Cotización N° <?= $cot['numero'] ?></h6></div>
    <div class="card-body p-3">
      <div class="row g-3">
        <?php foreach ($aprobaciones as $a):
          $badgeMap = ['aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning'];
          $badgeClass = isset($badgeMap[$a['estado']]) ? $badgeMap[$a['estado']] : 'secondary';
          $iconMap = ['aprobada'=>'check-circle-fill','rechazada'=>'x-circle-fill','corregir'=>'pencil-fill'];
          $iconClass = isset($iconMap[$a['estado']]) ? $iconMap[$a['estado']] : 'hourglass-split';
        ?>
        <div class="col-md-6">
          <div class="border rounded p-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <div>
                <strong><?= htmlspecialchars($a['nombre']) ?></strong>
                <br><small class="text-muted"><i class="bi bi-person me-1"></i>@<?= htmlspecialchars($a['usuario_login'] ?? '—') ?> — <?= htmlspecialchars($a['usuario_cargo'] ?? '') ?></small>
              </div>
              <span class="badge bg-<?= $badgeClass ?>"><i class="bi bi-<?= $iconClass ?> me-1"></i><?= ucfirst($a['estado']) ?></span>
            </div>
            <?php if ($a['fecha_respuesta']): ?>
            <small class="text-muted"><i class="bi bi-calendar-check me-1"></i>Respondió: <?= date('d/m/Y H:i', strtotime($a['fecha_respuesta'])) ?></small>
            <?php else: ?>
            <small class="text-muted"><i class="bi bi-hourglass me-1"></i>Esperando respuesta...</small>
            <?php endif; ?>
            <?php if ($a['comentario']): ?>
            <div class="mt-2 p-2 bg-light rounded" style="font-size:12px;"><i class="bi bi-chat-text me-1"></i><?= htmlspecialchars($a['comentario']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($envios)): ?>
<div class="no-print" style="max-width:800px;margin:12px auto;">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-success text-white py-2"><h6 class="mb-0"><i class="bi bi-envelope-check me-2"></i>Historial de Envíos por Correo</h6></div>
    <div class="card-body p-3">
      <?php foreach ($envios as $env):
        $tipoEnvio = $env['contenido'] === 'proceso' ? 'Estado/Proceso' : 'Vista Previa';
        $tipoBadge = $env['contenido'] === 'proceso' ? 'info' : 'primary';
      ?>
      <div class="border rounded p-2 mb-2" style="font-size:13px;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <i class="bi bi-send-fill text-success me-1"></i>
            <strong>Enviada a:</strong> <?= htmlspecialchars($env['destinatario_nombre'] ?: $env['destinatario_email']) ?>
            <small class="text-muted">(<?= htmlspecialchars($env['destinatario_email']) ?>)</small>
            <span class="badge bg-<?= $tipoBadge ?> ms-1" style="font-size:10px;"><?= $tipoEnvio ?></span>
          </div>
          <small class="text-muted"><i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($env['fecha'])) ?></small>
        </div>
        <small class="text-muted"><i class="bi bi-person me-1"></i>Enviada por: <strong><?= htmlspecialchars($env['enviado_por']) ?></strong></small>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($notifEnvios)): ?>
<div class="no-print" style="max-width:800px;margin:12px auto;">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-primary text-white py-2"><h6 class="mb-0"><i class="bi bi-send-fill me-2"></i>Envíos a Administradores</h6></div>
    <div class="card-body p-3">
      <?php foreach ($notifEnvios as $nf):
        $tipoEnvio = $nf['contenido'] === 'proceso' ? 'Estado/Proceso' : 'Vista Previa';
        $tipoBadge = $nf['contenido'] === 'proceso' ? 'info' : 'primary';
        $leidaBadge = $nf['leida'] ? '<span class="badge bg-success ms-1" style="font-size:10px;">Leída</span>' : '<span class="badge bg-warning text-dark ms-1" style="font-size:10px;">No leída</span>';
      ?>
      <div class="border rounded p-2 mb-2" style="font-size:13px;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <i class="bi bi-person-badge text-primary me-1"></i>
            <strong>Enviada a:</strong> <?= htmlspecialchars($nf['dest_nombre']) ?> <small class="text-muted">(@<?= htmlspecialchars($nf['dest_usuario']) ?>)</small>
            <span class="badge bg-<?= $tipoBadge ?> ms-1" style="font-size:10px;"><?= $tipoEnvio ?></span>
            <?= $leidaBadge ?>
          </div>
          <small class="text-muted"><i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($nf['fecha'])) ?></small>
        </div>
        <small class="text-muted"><i class="bi bi-person me-1"></i>Enviada por: <strong><?= htmlspecialchars($nf['enviado_por']) ?></strong></small>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="cot-print-page">
  <!-- ENCABEZADO -->
  <div class="cot-header">
    <div class="cot-header-left">
      <img src="../img/logo.png" alt="DOGroup" class="cot-logo-img">
      <div class="cot-empresa-datos">
        <strong>DOGROUP</strong><br>
        Av. Eleuterio Ramírez 802, DP 33, Osorno<br>
        77.930.045-5<br>
        <a href="https://www.dogroup.cl" target="_blank" style="color:#d97706;text-decoration:none">www.dogroup.cl</a><br>
        <span style="display:flex;align-items:center;gap:8px;margin-top:3px;flex-wrap:wrap">
          <a href="https://www.linkedin.com/company/inmobiliaria-don-orlando" target="_blank"
             style="color:#0077b5;text-decoration:none;font-size:10px;display:flex;align-items:center;gap:3px">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="#0077b5" viewBox="0 0 16 16">
              <path d="M0 1.146C0 .513.526 0 1.175 0h13.65C15.474 0 16 .513 16 1.146v13.708c0 .633-.526 1.146-1.175 1.146H1.175C.526 16 0 15.487 0 14.854zm4.943 12.248V6.169H2.542v7.225zm-1.2-8.212c.837 0 1.358-.554 1.358-1.248-.015-.709-.52-1.248-1.342-1.248S2.4 3.226 2.4 3.934c0 .694.521 1.248 1.327 1.248zm4.908 8.212V9.359c0-.216.016-.432.08-.586.173-.431.568-.878 1.232-.878.869 0 1.216.662 1.216 1.634v3.865h2.401V9.25c0-2.22-1.184-3.252-2.764-3.252-1.274 0-1.845.7-2.165 1.193v.025h-.016l.016-.025V6.169h-2.4c.03.678 0 7.225 0 7.225z"/>
            </svg>
            Inmobiliaria Don Orlando
          </a>
          <a href="https://www.instagram.com/don_orlando_inmobiliaria" target="_blank"
             style="color:#e1306c;text-decoration:none;font-size:10px;display:flex;align-items:center;gap:3px">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="#e1306c" viewBox="0 0 16 16">
              <path d="M8 0C5.829 0 5.556.01 4.703.048 3.85.088 3.269.222 2.76.42a3.9 3.9 0 0 0-1.417.923A3.9 3.9 0 0 0 .42 2.76C.222 3.268.087 3.85.048 4.7.01 5.555 0 5.827 0 8.001c0 2.172.01 2.444.048 3.297.04.852.174 1.433.372 1.942.205.526.478.972.923 1.417.444.445.89.719 1.416.923.51.198 1.09.333 1.942.372C5.555 15.99 5.827 16 8 16s2.444-.01 3.298-.048c.851-.04 1.434-.174 1.943-.372a3.9 3.9 0 0 0 1.416-.923c.445-.445.718-.891.923-1.417.197-.509.332-1.09.372-1.942C15.99 10.445 16 10.173 16 8s-.01-2.445-.048-3.299c-.04-.851-.175-1.433-.372-1.941a3.9 3.9 0 0 0-.923-1.417A3.9 3.9 0 0 0 13.24.42c-.51-.198-1.092-.333-1.943-.372C10.443.01 10.172 0 7.998 0zm.003 1.442c2.136 0 2.389.007 3.232.046.78.035 1.204.166 1.486.275.373.145.64.319.92.599s.453.546.598.92c.11.281.24.705.275 1.485.039.843.047 1.096.047 3.231s-.008 2.389-.047 3.232c-.035.78-.166 1.203-.275 1.485a2.5 2.5 0 0 1-.599.919c-.28.28-.546.453-.92.598-.28.11-.704.24-1.485.276-.843.038-1.096.047-3.232.047s-2.39-.009-3.233-.047c-.78-.036-1.203-.166-1.485-.276a2.5 2.5 0 0 1-.92-.598 2.5 2.5 0 0 1-.6-.92c-.109-.281-.24-.705-.275-1.485-.038-.843-.046-1.096-.046-3.233s.008-2.388.046-3.231c.036-.78.166-1.204.276-1.486.145-.373.319-.64.599-.92s.546-.453.92-.598c.282-.11.705-.24 1.485-.276.843-.038 1.096-.046 3.231-.046m0 2.409a4.155 4.155 0 1 0 0 8.31 4.155 4.155 0 0 0 0-8.31M8 10.93a2.685 2.685 0 1 1 0-5.37 2.685 2.685 0 0 1 0 5.37m5.273-7.492a.97.97 0 1 0 0 1.94.97.97 0 0 0 0-1.94"/>
            </svg>
            @don_orlando_inmobiliaria
          </a>
        </span>
      </div>
    </div>
    <div class="cot-header-right">
      <div class="cot-titulo-badge">COTIZACIÓN</div>
      <table class="cot-info-table" style="border:1px solid #f3e8d0;border-radius:6px;overflow:hidden;background:#fffbf2">
        <tr><td class="cot-info-label">NÚMERO</td><td class="cot-info-value"><?= $cot['numero'] ?></td></tr>
        <tr><td class="cot-info-label">FECHA</td><td class="cot-info-value"><?= $fechaFmt ?></td></tr>
        <?php if ($validoFmt): ?>
        <tr><td class="cot-info-label">VÁLIDO HASTA</td><td class="cot-info-value"><?= $validoFmt ?></td></tr>
        <?php endif; ?>
        <tr><td class="cot-info-label">MONEDA</td><td class="cot-info-value"><?= htmlspecialchars($monedaCot) ?><?php if ($monedaCot !== 'CLP'): ?> <small class="text-muted">(TC: $<?= number_format($tipoCambioCot, 2, ',', '.') ?>)</small><?php endif; ?></td></tr>
      </table>
    </div>
  </div>

  <!-- DATOS CLIENTE -->
  <div class="cot-seccion">
    <div class="cot-seccion-titulo">DATOS DEL CLIENTE</div>
    <table class="table table-sm table-bordered cot-tabla-cliente mb-0">
      <tr><td class="cot-td-label">Nombre:</td><td><?= htmlspecialchars($cot['cliente_nombre']) ?></td></tr>
      <tr><td class="cot-td-label">Obra:</td><td><?= htmlspecialchars($cot['cliente_obra']) ?></td></tr>
      <tr><td class="cot-td-label">RUT:</td><td><?= htmlspecialchars($cot['cliente_rut']) ?></td></tr>
      <tr><td class="cot-td-label">Teléfono:</td><td><?= htmlspecialchars($cot['cliente_telefono']) ?></td></tr>
      <tr><td class="cot-td-label">E-mail:</td><td><?= htmlspecialchars($cot['cliente_email']) ?></td></tr>
    </table>
  </div>

  <!-- TABLA ITEMS -->
  <table class="table table-sm table-bordered cot-tabla-items">
    <thead>
      <tr class="cot-items-header">
        <th>DESCRIPCIÓN</th>
        <th style="width:8%">CANTIDAD</th>
        <th style="width:8%">UNIDADES</th>
        <th style="width:14%">PRECIO</th>
        <th style="width:14%">TOTAL</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <tr>
        <td>
          <strong><?= htmlspecialchars($item['descripcion']) ?></strong>
          <?php if (!empty($item['detalle'])): ?>
          <div class="cot-detalle-texto"><?= nl2br(htmlspecialchars($item['detalle'])) ?></div>
          <?php endif; ?>
        </td>
        <td class="text-center"><?= rtrim(rtrim(number_format($item['cantidad'],2,',','.'),'0'),',') ?></td>
        <td class="text-center"><?= htmlspecialchars(mb_strtoupper((string)$item['unidad'], 'UTF-8')) ?></td>
        <td class="text-end"><?= $simMonCot ?><?= fmtCot($item['precio']) ?></td>
        <td class="text-end"><?= $simMonCot ?><?= fmtCot($item['total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- ADICIONALES -->
  <?php if (!empty($cot['adicionales'])): ?>
  <div class="cot-adicionales">
    <strong>ADICIONALES</strong>
    <p class="mb-0"><?= nl2br(htmlspecialchars($cot['adicionales'])) ?></p>
  </div>
  <?php endif; ?>

  <!-- TOTALES -->
  <div class="cot-totales">
    <table class="cot-totales-tabla">
      <tr><td colspan="2">SUB-TOTAL</td><td class="text-end"><?= $simMonCot ?><?= fmtCot($cot['subtotal']) ?></td></tr>
      <?php
        $gastosPct   = (float)($cot['gastos_pct']   ?? 8);
        $utilidadPct = (float)($cot['utilidad_pct'] ?? 10);
        $gastosMonto = (float)($cot['gastos_monto'] ?? round($cot['subtotal'] * $gastosPct / 100, 0));
        $utilMonto   = (float)($cot['utilidad_monto'] ?? round($cot['subtotal'] * $utilidadPct / 100, 0));
        $totalNeto   = (float)($cot['total_neto']   ?? round($gastosMonto + $utilMonto + $cot['subtotal'], 0));
      ?>
      <tr>
        <td colspan="2">GASTOS GENERALES <?= number_format($gastosPct, 1, ',', '.') ?>%</td>
        <td class="text-end"><?= $simMonCot ?><?= fmtCot($gastosMonto) ?></td>
      </tr>
      <tr>
        <td colspan="2">UTILIDADES <?= number_format($utilidadPct, 1, ',', '.') ?>%</td>
        <td class="text-end"><?= $simMonCot ?><?= fmtCot($utilMonto) ?></td>
      </tr>
      <tr style="border-top:2px solid #dee2e6">
        <td colspan="2" class="fw-bold">TOTAL NETO</td>
        <td class="text-end fw-bold"><?= $simMonCot ?><?= fmtCot($totalNeto) ?></td>
      </tr>
      <tr><td colspan="2">IVA 19%</td><td class="text-end"><?= $simMonCot ?><?= fmtCot($cot['iva']) ?></td></tr>
      <tr class="cot-total-final"><td colspan="2">TOTAL</td><td class="text-end"><?= $simMonCot ?><?= fmtCot($cot['total']) ?></td></tr>
      <?php if ($monedaCot !== 'CLP' && $tipoCambioCot > 1): ?>
      <tr><td colspan="2" class="text-muted small">Equiv. CLP (TC: $<?= number_format($tipoCambioCot, 2, ',', '.') ?>)</td><td class="text-end text-muted small">$<?= formatCLP($cot['total'] * $tipoCambioCot) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- PREPARADA POR / APROBADA POR -->
  <div style="margin:10px 0;padding:10px 14px;border:1px solid #f3e8d0;border-radius:8px;background:#fffbf2;font-size:11px">
    <div style="display:flex;gap:24px;flex-wrap:wrap">

      <!-- Preparada por -->
      <div style="flex:1;min-width:140px">
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#92400e;border-bottom:1px solid #f3e8d0;padding-bottom:3px;margin-bottom:5px">
          Preparada por
        </div>
        <div style="font-weight:800;color:#1b2838"><?= htmlspecialchars($cot['creada_por']) ?></div>
        <?php if (!empty($cot['creada_por_cargo'])): ?>
        <div style="color:#6b7280;font-size:10px"><?= htmlspecialchars($cot['creada_por_cargo']) ?></div>
        <?php endif; ?>
        <?php if ($cot['creada_por_telefono']): ?>
        <div style="color:#6b7280;font-size:10px"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($cot['creada_por_telefono']) ?></div>
        <?php endif; ?>
        <?php if ($cot['creada_por_email']): ?>
        <div style="color:#6b7280;font-size:10px"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($cot['creada_por_email']) ?></div>
        <?php endif; ?>
      </div>

      <!-- Aprobada por (solo si está aprobada) -->
      <?php
        $aprobacionesOK = array_filter($aprobaciones, fn($a) => $a['estado'] === 'aprobada');
        if (!empty($aprobacionesOK)):
      ?>
      <div style="flex:2;min-width:240px">
        <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #bbf7d0;padding-bottom:3px;margin-bottom:5px;gap:8px">
          <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#14532d">
            Aprobada por
          </div>
          <?php if ($cotAprobada): ?>
          <div style="display:inline-block;border:2px solid #16a34a;border-radius:6px;padding:2px 8px;transform:rotate(-6deg);color:#16a34a;font-weight:800;font-size:10px;letter-spacing:1px;line-height:1.1">
            <i class="bi bi-check-circle-fill"></i> APROBADO
          </div>
          <?php endif; ?>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:14px">
          <?php foreach ($aprobacionesOK as $ap): ?>
          <div style="flex:1;min-width:130px">
            <div style="font-weight:800;color:#1b2838"><?= htmlspecialchars($ap['nombre']) ?></div>
            <?php if (!empty($ap['usuario_cargo'])): ?>
            <div style="color:#6b7280;font-size:10px"><?= htmlspecialchars($ap['usuario_cargo']) ?></div>
            <?php endif; ?>
            <?php if (!empty($ap['fecha_respuesta'])): ?>
            <div style="color:#16a34a;font-size:10px">
              ✓ <?= date('d/m/Y', strtotime($ap['fecha_respuesta'])) ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php elseif (!empty($aprobaciones)): ?>
      <div style="flex:1;min-width:140px">
        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#b45309;border-bottom:1px solid #fde68a;padding-bottom:3px;margin-bottom:5px">
          Pendiente de aprobación
        </div>
        <?php foreach ($aprobaciones as $ap): ?>
        <div style="margin-bottom:3px">
          <div style="font-weight:700;color:#374151;font-size:10px"><?= htmlspecialchars($ap['nombre']) ?></div>
          <?php if (!empty($ap['usuario_cargo'])): ?>
          <div style="color:#9ca3af;font-size:9px"><?= htmlspecialchars($ap['usuario_cargo']) ?></div>
          <?php endif; ?>
          <div style="color:#d97706;font-size:9px">⏳ En revisión</div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- CONDICIONES -->
  <div class="cot-condiciones">
    <strong>Términos y condiciones</strong>
    <?php if (!empty($cot['condiciones'])): ?>
    <p class="mb-0"><?= nl2br(htmlspecialchars($cot['condiciones'])) ?></p>
    <?php endif; ?>
  </div>

  <!-- PAGO -->
  <?php
    $pagoCuentaDefault = "DOGROUP RUT:77.930.045-5\nCUENTA CORRIENTE BANCO SANTANDER N° 94278848";
    $pagoCuentaActual  = trim((string)($cot['pago_cuenta'] ?? ''));
    if ($pagoCuentaActual === '') $pagoCuentaActual = $pagoCuentaDefault;
  ?>
  <div class="cot-pago">
    <div class="d-flex justify-content-between align-items-start gap-2">
      <div style="flex:1">
        <strong>PAGO EN CUENTA:</strong><br>
        <span id="pagoCuentaTexto"><?= nl2br(htmlspecialchars($pagoCuentaActual)) ?></span>
      </div>
      <button type="button" id="btnEditarPagoCuenta" class="btn btn-sm btn-outline-secondary no-print" title="Editar pago en cuenta">
        <i class="bi bi-pencil"></i>
      </button>
    </div>
    <form method="POST" id="formPagoCuenta" class="mt-2 no-print" style="display:none">
      <input type="hidden" name="accion" value="guardar_pago_cuenta">
      <textarea name="pago_cuenta" class="form-control form-control-sm" rows="3"><?= htmlspecialchars($pagoCuentaActual) ?></textarea>
      <div class="d-flex gap-2 mt-2">
        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-save me-1"></i>Guardar</button>
        <button type="button" id="btnCancelarPagoCuenta" class="btn btn-sm btn-outline-secondary">Cancelar</button>
      </div>
    </form>
  </div>

  <!-- TÉRMINOS Y CONDICIONES -->
  <div class="cot-terminos">
    <div class="cot-terminos-titulo">Términos y Condiciones de la Cotización de Movimientos de Tierra y Arriendo de Maquinaria</div>

    <div class="cot-termino-item">
      <strong>1. Objeto de la Cotización:</strong>
      La presente cotización tiene como objeto el suministro de servicios de movimientos de tierra y arriendo de maquinaria, de acuerdo con las especificaciones solicitadas por el cliente. El alcance de los servicios está sujeto a las condiciones detalladas en esta cotización.
    </div>

    <div class="cot-termino-item">
      <strong>2. Validez de la Cotización:</strong>
      La cotización tiene una validez de 30 días a partir de la fecha de emisión. Si no se confirma el servicio dentro de este plazo, la cotización podrá ser modificada o cancelada a discreción de la empresa.
    </div>

    <div class="cot-termino-item">
      <strong>3. Precios y Forma de Pago:</strong>
      Los precios indicados en esta cotización son finales y no incluyen impuestos, salvo que se indique expresamente lo contrario. Los pagos deberán realizarse según los términos acordados entre las partes, especificados en el contrato final. El cliente se compromete a realizar los pagos en los plazos establecidos. En caso de atraso en el pago, se aplicarán intereses por mora conforme a la legislación vigente.
    </div>

    <div class="cot-termino-item">
      <strong>4. Arriendo de Maquinaria:</strong>
      El arriendo de maquinaria se considera por el tiempo especificado en la cotización. Cualquier extensión del tiempo será acordada previamente entre las partes y podrá implicar cargos adicionales. El cliente se compromete a utilizar la maquinaria de acuerdo con las instrucciones de funcionamiento y en condiciones adecuadas, responsabilizándose por cualquier daño ocasionado por uso inapropiado. El mantenimiento de la maquinaria durante el período de arriendo será responsabilidad de la empresa, salvo que el daño haya sido causado por mal uso o negligencia del cliente.
    </div>

    <div class="cot-termino-item">
      <strong>5. Condiciones de Ejecución de los Servicios:</strong>
      El inicio de los trabajos está condicionado a la firma del contrato formal, recepción de los pagos iniciales y entrega de los permisos necesarios, si corresponden. Las fechas de ejecución de los trabajos se definirán de común acuerdo entre las partes, y la empresa se compromete a realizar los trabajos dentro del tiempo estimado, siempre y cuando no existan factores externos que puedan afectar la programación, tales como condiciones climáticas, imprevistos técnicos, o demoras en la entrega de permisos. El cliente deberá asegurar el acceso adecuado a los terrenos donde se realicen los trabajos. Cualquier impedimento en este acceso que retrase el proceso será considerado como un factor de fuerza mayor.
    </div>

    <div class="cot-termino-item">
      <strong>6. Responsabilidad:</strong>
      La empresa no será responsable por daños indirectos, pérdidas económicas o retrasos en la ejecución de los servicios que se deban a causas ajenas a su control, tales como condiciones meteorológicas adversas, huelgas, accidentes, u otros imprevistos. La empresa se compromete a realizar los trabajos con el debido cuidado y conforme a las normas de seguridad aplicables. Sin embargo, el cliente será responsable de los daños ocasionados a la propiedad o a terceros derivados de las condiciones del terreno o la intervención de otras partes ajenas a la empresa.
    </div>

    <div class="cot-termino-item">
      <strong>7. Modificaciones a la Cotización:</strong>
      Cualquier modificación en el alcance de los servicios, en el tipo de maquinaria a arrendar, o en los plazos establecidos deberá ser acordada por ambas partes y reflejada en un documento adicional. Estos cambios podrán generar ajustes en el precio total de la cotización.
    </div>

    <div class="cot-termino-item">
      <strong>8. Garantía de los Servicios:</strong>
      La empresa ofrece una garantía para los trabajos realizados, exclusivamente en relación con defectos de ejecución o mal funcionamiento de la maquinaria. La garantía no cubre daños por mal uso o condiciones fuera de lo especificado en el contrato.
    </div>

    <div class="cot-termino-item">
      <strong>9. Confidencialidad:</strong>
      Ambas partes se comprometen a mantener la confidencialidad de la información compartida durante la negociación y ejecución de los servicios, así como de los detalles relacionados con la cotización y los términos del contrato.
    </div>

    <div class="cot-termino-item">
      <strong>10. Resolución de Conflictos:</strong>
      Cualquier controversia que surja de la interpretación o ejecución de esta cotización será resuelta mediante negociación directa entre las partes. Si no se llegara a un acuerdo, las partes podrán recurrir a los tribunales competentes según la legislación vigente.
    </div>

    <div class="cot-termino-item">
      <strong>11. Aceptación de la Cotización:</strong>
      La aceptación de esta cotización implica la conformidad con los términos y condiciones descritos en este documento. La firma del contrato formal constituirá el acuerdo definitivo entre las partes.
    </div>

    <div class="cot-termino-item">
      <strong>12. Fuerza Mayor:</strong>
      Ninguna de las partes será responsable por el incumplimiento de sus obligaciones si este se debe a circunstancias fuera de su control razonable, como condiciones meteorológicas extremas, desastres naturales, guerras, actos de autoridades gubernamentales, etc.
    </div>
  </div>
</div>

<?php if ($cot['estado'] === 'pendiente' || $cot['estado'] === 'corregir'): ?>
<div class="modal fade" id="modalEnviarValidacion" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title"><i class="bi bi-send-check me-2"></i>Enviar Validación — Cotización N° <?= $cot['numero'] ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="solicitar_aprobacion_cot.php">
        <input type="hidden" name="cot_id" value="<?= $id ?>">
        <div class="modal-body">
          <p class="text-muted mb-3"><i class="bi bi-info-circle me-1"></i>Seleccione los validadores que deben aprobar esta cotización. <strong>Todos</strong> los seleccionados deberán aprobar para que sea válida.</p>
          <?php if (!empty($aprobadoresDisponibles)): ?>
          <div class="list-group">
            <?php foreach ($aprobadoresDisponibles as $ap): ?>
            <label class="list-group-item d-flex align-items-center gap-3">
              <input type="checkbox" name="aprobadores[]" value="<?= $ap['usuario_id'] ?>" class="form-check-input flex-shrink-0" checked>
              <div>
                <strong><?= htmlspecialchars($ap['nombre']) ?></strong>
                <br><small class="text-muted">@<?= htmlspecialchars($ap['usuario']) ?> — <?= htmlspecialchars($ap['cargo'] ?? 'Sin cargo') ?></small>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>No hay validadores configurados. El administrador debe agregarlos desde el panel de administración.</div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php if (!empty($aprobadoresDisponibles)): ?>
          <button type="submit" class="btn btn-warning" onclick="return confirm('¿Enviar solicitud de validación a los validadores seleccionados?')"><i class="bi bi-send-check me-1"></i>Enviar</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function imprimirCot() {
  var style = document.getElementById('printPageStyle');
  if (!style) {
    style = document.createElement('style');
    style.id = 'printPageStyle';
    document.head.appendChild(style);
  }
  style.textContent = '@media print { @page { size: letter !important; margin: 0 !important; } }';
  var originalTitle = document.title;
  document.title = ' ';
  setTimeout(function() {
    window.print();
    setTimeout(function() { document.title = originalTitle; }, 500);
  }, 100);
}

(function () {
  var btnEdit   = document.getElementById('btnEditarPagoCuenta');
  var btnCancel = document.getElementById('btnCancelarPagoCuenta');
  var form      = document.getElementById('formPagoCuenta');
  var texto     = document.getElementById('pagoCuentaTexto');
  if (!btnEdit || !form || !texto) return;
  btnEdit.addEventListener('click', function () {
    form.style.display = '';
    texto.style.display = 'none';
    btnEdit.style.display = 'none';
    var ta = form.querySelector('textarea');
    if (ta) { ta.focus(); ta.selectionStart = ta.selectionEnd = ta.value.length; }
  });
  if (btnCancel) btnCancel.addEventListener('click', function () {
    form.style.display = 'none';
    texto.style.display = '';
    btnEdit.style.display = '';
  });
})();
</script>
</body>
</html>
