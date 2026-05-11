<?php
require_once 'config.php';
use PHPMailer\PHPMailer\PHPMailer;

if (!isExternoLoggedIn()) {
  header('Location: despacho_externo_login.php'); exit;
}
$ext = getExternoSession();

/* ── Logout ── */
if (isset($_GET['logout'])) {
  session_destroy();
  header('Location: despacho_externo_login.php'); exit;
}

$MATERIALES = [
  'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4','bajo_3'=>'Bajo 3',
  'bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada','grava'=>'Grava','gravilla'=>'Gravilla',
  'arena'=>'Arena','arena_tubo'=>'Arena Tubo','escombros'=>'Escombros','bolones'=>'Bolones',
];

$CAMBIOS = ['Mañana (06:00–14:00)', 'Tarde (14:00–22:00)', 'Noche (22:00–06:00)'];

$msg = null; $msgTipo = 'success';
if (!empty($_GET['msg'])) { $msg = $_GET['msg']; $msgTipo = $_GET['mt'] ?? 'success'; }

/* ════════════════════════════════════════════════════════
   POST
   ════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';

  /* ── Validar ticket (cambio/turno via QR) ── */
  if ($accion === 'validar') {
    $tok    = trim($_POST['token']  ?? '');
    $cambio = trim($_POST['cambio'] ?? '');
    try {
      if (!$tok) throw new Exception('Token de ticket no válido.');
      $stUp = $pdo->prepare("UPDATE tickets_despacho SET estado='validado', validado_en=CURRENT_TIMESTAMP, validado_por=0, validado_por_nombre=?, cambio=? WHERE token=? AND estado='enviado'");
      $stUp->execute([$ext['nombre'].' (ext.)', $cambio, $tok]);
      if ($stUp->rowCount() > 0) {
        header("Location: despacho_externo_portal.php?msg=".urlencode('Ticket validado correctamente.')."&mt=success"); exit;
      } else {
        throw new Exception('Ticket no encontrado, ya validado o no en estado enviado.');
      }
    } catch(Exception $e) { $msg = $e->getMessage(); $msgTipo = 'danger'; }
  }

  /* ── Revisar ticket (aprobado / rechazado / observacion) ── */
  if ($accion === 'revisar_ticket') {
    $tid      = (int)($_POST['ticket_id'] ?? 0);
    $estado   = $_POST['estado_revision'] ?? '';
    $obs      = trim($_POST['observacion'] ?? '');
    if (!in_array($estado, ['aprobado','rechazado','observacion'])) {
      $msg = 'Estado de revisión inválido.'; $msgTipo = 'danger';
    } else {
      $pdo->prepare("UPDATE tickets_despacho SET estado_revision=?, observacion_revision=?, revisado_por_ext_id=?, revisado_por_ext_nom=?, revisado_en=CURRENT_TIMESTAMP WHERE id=?")
          ->execute([$estado, $obs, $ext['id'], $ext['nombre'], $tid]);
      header("Location: despacho_externo_portal.php?tab=revision&msg=".urlencode('Revisión guardada.')."&mt=success"); exit;
    }
  }

  /* ── Enviar reporte de revisión al encargado de operaciones ── */
  if ($accion === 'enviar_reporte_revision') {
    $fechaRep = $_POST['fecha_reporte'] ?? date('Y-m-d');
    try {
      /* Tickets revisados ese día por este validador */
      $stRep = $pdo->prepare("
        SELECT td.*, o.codigo AS obra_codigo, o.nombre AS obra_nombre, dp.ppu AS ppu_codigo, dd.nombre AS destino_nombre
        FROM tickets_despacho td
        LEFT JOIN obras o          ON o.id  = td.id_obra
        LEFT JOIN despacho_ppu dp  ON dp.id = td.ppu_id
        LEFT JOIN despacho_destinos dd ON dd.id = td.destino_id
        WHERE td.revisado_por_ext_id = ? AND DATE(td.revisado_en) = ?
        ORDER BY td.id ASC
      ");
      $stRep->execute([$ext['id'], $fechaRep]);
      $ticketsRev = $stRep->fetchAll(PDO::FETCH_ASSOC);

      if (empty($ticketsRev)) throw new Exception('No hay tickets revisados para esa fecha.');

      /* Estadísticas */
      $totAprobados=0; $totRechazados=0; $totObservados=0;
      foreach($ticketsRev as $_tr){ if($_tr['estado_revision']==='aprobado') $totAprobados++; elseif($_tr['estado_revision']==='rechazado') $totRechazados++; elseif($_tr['estado_revision']==='observacion') $totObservados++; }

      /* Encargado de operaciones (usa la misma tabla que el cierre de día) */
      $stEnc = $pdo->query("SELECT ec.usuario_id, u.nombre, u.email FROM despacho_encargado_costos ec JOIN usuarios u ON u.id=ec.usuario_id WHERE u.email != '' AND u.email IS NOT NULL");
      $destEmail = [];
      foreach ($stEnc->fetchAll(PDO::FETCH_ASSOC) as $enc) {
        $destEmail[] = ['email' => $enc['email'], 'nombre' => $enc['nombre']];
      }
      if (empty($destEmail)) throw new Exception('No hay encargado de operaciones con correo configurado. Asígnenlo en la administración del módulo.');

      /* Construir HTML del reporte */
      $htmlRows = '';
      foreach ($ticketsRev as $t) {
        $badgeMap = ['aprobado'=>'#d1e7dd','rechazado'=>'#f8d7da','observacion'=>'#fff3cd'];
        $badge = isset($badgeMap[$t['estado_revision']]) ? $badgeMap[$t['estado_revision']] : '#f8f9fa';
        $labelMap = ['aprobado'=>'✅ Aprobado','rechazado'=>'❌ Rechazado','observacion'=>'⚠️ Observación'];
        $label = isset($labelMap[$t['estado_revision']]) ? $labelMap[$t['estado_revision']] : '—';
        $htmlRows .= "<tr style='background:{$badge}'>
          <td style='padding:6px 10px;'>#{$t['id']}</td>
          <td style='padding:6px 10px;'>{$t['fecha']} {$t['hora']}</td>
          <td style='padding:6px 10px;font-weight:bold;'>{$t['ppu_codigo']}</td>
          <td style='padding:6px 10px;'>" . htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) . "</td>
          <td style='padding:6px 10px;'>" . htmlspecialchars($t['obra_codigo'] ?: '—') . "</td>
          <td style='padding:6px 10px;'>" . htmlspecialchars($t['destino_nombre'] ?: '—') . "</td>
          <td style='padding:6px 10px;font-weight:bold;'>$label</td>
          <td style='padding:6px 10px;font-size:.85em;'>" . htmlspecialchars($t['observacion_revision'] ?: '—') . "</td>
        </tr>";
      }

      $htmlBody = "
      <div style='font-family:Segoe UI,Arial,sans-serif;max-width:900px;margin:0 auto;'>
        <div style='background:#1b2838;color:#fff;padding:20px 30px;border-radius:8px 8px 0 0;'>
          <h2 style='margin:0;font-size:1.3rem;'>DOGROUP — Reporte de Revisión Diaria</h2>
          <p style='margin:4px 0 0;opacity:.7;font-size:.9rem;'>Fecha revisada: <strong>{$fechaRep}</strong></p>
        </div>
        <div style='background:#f4f6f9;padding:20px 30px;'>
          <h4 style='color:#333;'>Validador: {$ext['nombre']}</h4>
          <p style='color:#666;margin:0;'>{$ext['empresa']} · RUT: {$ext['rut']}</p>
          <div style='display:flex;gap:16px;margin:16px 0;'>
            <div style='background:#d1e7dd;padding:12px 20px;border-radius:8px;text-align:center;flex:1;'>
              <div style='font-size:1.6rem;font-weight:900;color:#0a3622;'>$totAprobados</div>
              <div style='font-size:.8rem;color:#0a3622;'>Aprobados</div>
            </div>
            <div style='background:#f8d7da;padding:12px 20px;border-radius:8px;text-align:center;flex:1;'>
              <div style='font-size:1.6rem;font-weight:900;color:#842029;'>$totRechazados</div>
              <div style='font-size:.8rem;color:#842029;'>Rechazados</div>
            </div>
            <div style='background:#fff3cd;padding:12px 20px;border-radius:8px;text-align:center;flex:1;'>
              <div style='font-size:1.6rem;font-weight:900;color:#664d03;'>$totObservados</div>
              <div style='font-size:.8rem;color:#664d03;'>Con observación</div>
            </div>
            <div style='background:#e2e3e5;padding:12px 20px;border-radius:8px;text-align:center;flex:1;'>
              <div style='font-size:1.6rem;font-weight:900;color:#343a40;'>" . count($ticketsRev) . "</div>
              <div style='font-size:.8rem;color:#343a40;'>Total</div>
            </div>
          </div>
          <table style='width:100%;border-collapse:collapse;font-size:.88rem;'>
            <thead>
              <tr style='background:#343a40;color:#fff;'>
                <th style='padding:8px 10px;'>#</th>
                <th style='padding:8px 10px;'>Fecha/Hora</th>
                <th style='padding:8px 10px;'>PPU</th>
                <th style='padding:8px 10px;'>Material</th>
                <th style='padding:8px 10px;'>Obra</th>
                <th style='padding:8px 10px;'>Destino</th>
                <th style='padding:8px 10px;'>Estado</th>
                <th style='padding:8px 10px;'>Observación</th>
              </tr>
            </thead>
            <tbody>$htmlRows</tbody>
          </table>
          <p style='color:#999;font-size:.75rem;margin-top:20px;'>Generado el " . date('d/m/Y H:i') . " — DOGROUP / Bynari SpA</p>
        </div>
      </div>";

      /* Enviar por PHPMailer */
      $mailer = new PHPMailer(true);
      $mailer->isMail();
      $mailer->CharSet = 'UTF-8';
      $mailer->isHTML(true);
      $mailer->Subject = "Reporte Revisión Despacho {$fechaRep} — {$ext['nombre']}";
      $mailer->Body    = $htmlBody;
      $mailer->setFrom('no-reply@dogroup.cl', 'DOGROUP Despacho');
      foreach ($destEmail as $d) $mailer->addAddress($d['email'], $d['nombre']);
      $mailer->send();

      /* Registrar envío */
      $pdo->prepare("INSERT INTO despacho_reporte_carchek (externo_id, fecha_reporte, destinatarios) VALUES (?,?,?)")
          ->execute([$ext['id'], $fechaRep, implode(', ', array_column($destEmail,'email'))]);

      header("Location: despacho_externo_portal.php?tab=revision&msg=".urlencode("Reporte enviado a: ".implode(', ', array_column($destEmail,'email')))."&mt=success"); exit;
    } catch(Exception $e) {
      $msg = 'Error al enviar: '.$e->getMessage(); $msgTipo = 'danger';
    }
  }
}

/* ── Buscar ticket por token ── */
$ticketBuscado = null;
$tokenBuscar   = trim($_GET['t'] ?? '');
if ($tokenBuscar) {
  $st = $pdo->prepare("SELECT * FROM tickets_despacho WHERE token=?");
  $st->execute([$tokenBuscar]);
  $ticketBuscado = $st->fetch(PDO::FETCH_ASSOC);
}

/* ── Tickets enviados pendientes de validación ── */
$stPend = $pdo->prepare("
  SELECT td.*, dp.ppu AS ppu_codigo, dp.metros_cubicos, o.codigo AS obra_codigo, o.nombre AS obra_nombre, dd.nombre AS destino_nombre
  FROM tickets_despacho td
  JOIN despacho_receptores dr ON dr.id = td.receptor_id
  LEFT JOIN despacho_ppu      dp ON dp.id = td.ppu_id
  LEFT JOIN obras             o  ON o.id  = td.obra_id
  LEFT JOIN despacho_destinos dd ON dd.id = td.destino_id
  WHERE dr.tipo = 'externo' AND dr.externo_id = ? AND td.estado = 'enviado'
  ORDER BY td.fecha DESC, td.hora DESC
");
$stPend->execute([$ext['id']]);
$pendientes = $stPend->fetchAll(PDO::FETCH_ASSOC);

/* ── Fecha seleccionada para revisión ── */
$fechaRevision = $_GET['fecha_rev'] ?? date('Y-m-d');

/* ── Tickets del día para revisar (validados en esa fecha) ── */
$stRevDia = $pdo->prepare("
  SELECT td.*, dp.ppu AS ppu_codigo, dp.metros_cubicos, o.codigo AS obra_codigo, o.nombre AS obra_nombre, dd.nombre AS destino_nombre
  FROM tickets_despacho td
  JOIN despacho_receptores dr ON dr.id = td.receptor_id
  LEFT JOIN despacho_ppu      dp ON dp.id = td.ppu_id
  LEFT JOIN obras             o  ON o.id  = td.obra_id
  LEFT JOIN despacho_destinos dd ON dd.id = td.destino_id
  WHERE dr.tipo = 'externo' AND dr.externo_id = ? AND td.fecha = ? AND td.estado = 'validado'
  ORDER BY td.hora ASC
");
$stRevDia->execute([$ext['id'], $fechaRevision]);
$ticketsDia = $stRevDia->fetchAll(PDO::FETCH_ASSOC);

$totRevAprobados=0; $totRevRechazados=0; $totRevObservados=0; $totRevSinRev=0;
foreach($ticketsDia as $_td){
  if($_td['estado_revision']==='aprobado') $totRevAprobados++;
  elseif($_td['estado_revision']==='rechazado') $totRevRechazados++;
  elseif($_td['estado_revision']==='observacion') $totRevObservados++;
  elseif(empty($_td['estado_revision'])) $totRevSinRev++;
}

/* ── ¿Reporte ya enviado hoy? ── */
$reporteEnviado = false;
$reporteEnviadoEn = '';
try {
  $stRE = $pdo->prepare("SELECT enviado_en, destinatarios FROM despacho_reporte_carchek WHERE externo_id=? AND fecha_reporte=? ORDER BY id DESC LIMIT 1");
  $stRE->execute([$ext['id'], $fechaRevision]);
  $rowRE = $stRE->fetch(PDO::FETCH_ASSOC);
  if ($rowRE) { $reporteEnviado = true; $reporteEnviadoEn = $rowRE['enviado_en']; }
} catch(Exception $e) {}

/* ── Tab activo ── */
$tabActivo = $_GET['tab'] ?? ($tokenBuscar ? 'validar' : 'revision');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Portal Validador — <?= htmlspecialchars($ext['nombre']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
  body { background: #f0f4f8; font-family: 'Segoe UI', sans-serif; }
  .portal-header { background: #212529; color: #fff; padding: 1rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
  .portal-header img { height: 40px; filter: brightness(0) invert(1); }
  .ticket-mini { border-radius: 8px; border: 1px solid #dee2e6; padding: .8rem 1rem; background: #fff; }
  .ppu-badge { font-size: 1rem; font-weight: 800; font-family: monospace; letter-spacing: 2px; }
  .scanner-box { border: 3px dashed #6c757d; border-radius: 12px; padding: 1.2rem; background: #f8f9fa; }
  .rev-card { border-radius: 10px; border: 1px solid #dee2e6; background: #fff; transition: box-shadow .15s; }
  .rev-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }
  .rev-aprobado   { border-left: 5px solid #198754 !important; }
  .rev-rechazado  { border-left: 5px solid #dc3545 !important; }
  .rev-observacion{ border-left: 5px solid #ffc107 !important; }
  .rev-sin        { border-left: 5px solid #6c757d !important; }
  .stat-pill { padding: .5rem 1rem; border-radius: 999px; font-weight: 700; font-size: .85rem; display: inline-flex; align-items: center; gap: .4rem; }
  /* Acordeón por PPU */
  .grupo-rev { border:1px solid #dee2e6; border-radius:10px; overflow:hidden; margin-bottom:10px; box-shadow:0 2px 6px rgba(0,0,0,.05); }
  .grupo-rev-header {
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
    background:#fff; padding:10px 16px; cursor:pointer;
    border:none; width:100%; text-align:left; transition:background .15s;
  }
  .grupo-rev-header:hover { background:#f8f9fa; }
  .grupo-rev-header .g-ppu { font-family:monospace; font-size:1.15rem; font-weight:900; background:#1b2838; color:#fff; padding:3px 12px; border-radius:6px; letter-spacing:2px; }
  .grupo-rev-header .g-cond { font-weight:700; font-size:.92rem; color:#212529; }
  .grupo-rev-header .g-sub  { font-size:.78rem; color:#6c757d; }
  .grupo-rev-header .g-badges { margin-left:auto; display:flex; gap:5px; align-items:center; flex-wrap:wrap; }
  .grupo-rev-body { border-top:1px solid #dee2e6; background:#fdfdfd; display:none; padding:12px; }
  .grupo-rev-body.open { display:block; }
  .g-chevron { transition:transform .2s; font-size:.85rem; color:#adb5bd; }
  .grupo-rev-header.active .g-chevron { transform:rotate(180deg); }
  </style>
</head>
<body>

<!-- HEADER -->
<div class="portal-header">
  <div class="d-flex align-items-center gap-3">
    <img src="img/logo.png" alt="DOGROUP">
    <div>
      <div style="font-size:.7rem;opacity:.6;">Portal Validadores — DOGROUP</div>
      <div style="font-size:1rem;font-weight:700;"><?= htmlspecialchars($ext['nombre']) ?></div>
      <div style="font-size:.78rem;opacity:.7;">
        <?= $ext['empresa'] ? htmlspecialchars($ext['empresa']).' · ' : '' ?>
        <?= $ext['rut']     ? 'RUT: '.htmlspecialchars($ext['rut'])   : '' ?>
        <?= $ext['obra_info'] ? ' · <i class="bi bi-building me-1"></i>'.htmlspecialchars($ext['obra_info']['codigo'].' — '.$ext['obra_info']['nombre']) : '' ?>
      </div>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a href="login.php" class="btn btn-outline-light btn-sm"><i class="bi bi-house-fill me-1"></i>Inicio sistema</a>
    <a href="?logout=1" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Salir</a>
  </div>
</div>

<div class="container-fluid py-4" style="max-width:920px;">

  <?php if ($msg): ?>
  <div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show">
    <i class="bi bi-<?= $msgTipo==='success'?'check-circle':'exclamation-circle' ?> me-1"></i><?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <!-- TABS -->
  <ul class="nav nav-tabs mb-4" id="portalTabs">
    <li class="nav-item">
      <a class="nav-link <?= $tabActivo==='revision'?'active':'' ?>" href="?tab=revision">
        <i class="bi bi-clipboard-check me-1"></i>Revisión del día
        <?php if ($totRevSinRev > 0): ?><span class="badge bg-warning text-dark ms-1"><?= $totRevSinRev ?> pendientes</span><?php endif; ?>
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tabActivo==='validar'?'active':'' ?>" href="?tab=validar">
        <i class="bi bi-qr-code-scan me-1"></i>Validar QR
        <?php if (count($pendientes)>0): ?><span class="badge bg-success ms-1"><?= count($pendientes) ?></span><?php endif; ?>
      </a>
    </li>
  </ul>

  <!-- ════ TAB: REVISIÓN DEL DÍA ════ -->
  <?php if ($tabActivo === 'revision'): ?>

  <!-- Selector de fecha + estadísticas -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form method="GET" class="d-flex flex-wrap gap-3 align-items-end mb-3">
        <input type="hidden" name="tab" value="revision">
        <div>
          <label class="form-label fw-semibold small mb-1">Fecha a revisar</label>
          <input type="date" name="fecha_rev" value="<?= $fechaRevision ?>" class="form-control" style="max-width:200px;" onchange="this.form.submit()">
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <span class="stat-pill bg-success bg-opacity-10 text-success"><i class="bi bi-check-circle-fill"></i><?= $totRevAprobados ?> aprobados</span>
          <span class="stat-pill bg-danger bg-opacity-10 text-danger"><i class="bi bi-x-circle-fill"></i><?= $totRevRechazados ?> rechazados</span>
          <span class="stat-pill bg-warning bg-opacity-10 text-warning"><i class="bi bi-exclamation-circle-fill"></i><?= $totRevObservados ?> obs.</span>
          <span class="stat-pill bg-secondary bg-opacity-10 text-secondary"><i class="bi bi-hourglass"></i><?= $totRevSinRev ?> sin revisar</span>
        </div>
      </form>

      <!-- Botón enviar reporte -->
      <?php if (!empty($ticketsDia)): ?>
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <?php if ($reporteEnviado): ?>
        <div class="d-flex align-items-center gap-2">
          <span class="badge bg-success fs-6 px-3 py-2">
            <i class="bi bi-check-circle-fill me-1"></i>Aviso enviado
          </span>
          <small class="text-muted">el <?= date('d/m/Y H:i', strtotime($reporteEnviadoEn)) ?></small>
          <form method="POST" onsubmit="return confirm('¿Volver a enviar el reporte del <?= $fechaRevision ?>?')">
            <input type="hidden" name="accion" value="enviar_reporte_revision">
            <input type="hidden" name="fecha_reporte" value="<?= $fechaRevision ?>">
            <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Reenviar</button>
          </form>
        </div>
        <?php else: ?>
        <form method="POST" onsubmit="return confirm('¿Enviar reporte de revisión del <?= $fechaRevision ?> al encargado de operaciones?')">
          <input type="hidden" name="accion" value="enviar_reporte_revision">
          <input type="hidden" name="fecha_reporte" value="<?= $fechaRevision ?>">
          <button class="btn btn-info text-white fw-bold">
            <i class="bi bi-send-fill me-2"></i>Enviar reporte al encargado de operaciones
          </button>
        </form>
        <small class="text-muted"><?= count($ticketsDia) ?> ticket(s) del <?= date('d/m/Y', strtotime($fechaRevision)) ?></small>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Lista de tickets para revisar — agrupada por PPU -->
  <?php if (empty($ticketsDia)): ?>
  <div class="text-center py-5 text-muted">
    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
    No hay tickets validados para el <?= date('d/m/Y', strtotime($fechaRevision)) ?>
  </div>
  <?php else:
    /* Agrupar por PPU + conductor */
    $gruposRev = [];
    foreach ($ticketsDia as $t) {
      $key = ($t['ppu_codigo'] ?: '—').'|'.($t['conductor_nombre'] ?: '—');
      if (!isset($gruposRev[$key])) {
        $gruposRev[$key] = [
          'ppu'       => $t['ppu_codigo'] ?: '—',
          'conductor' => $t['conductor_nombre'] ?: '—',
          'rut'       => $t['conductor_rut'] ?? '',
          'm3'        => $t['metros_cubicos'] ?? 0,
          'obra'      => ($t['obra_codigo'] ? $t['obra_codigo'].' — '.$t['obra_nombre'] : ''),
          'tickets'   => [],
        ];
      }
      $gruposRev[$key]['tickets'][] = $t;
    }
    $gi = 0;
  ?>
  <?php foreach ($gruposRev as $gkey => $grupo): $gi++;
    $gAprobados=0; $gRechazados=0; $gObservados=0; $gSinRev=0;
    foreach($grupo['tickets'] as $_gx){
      if($_gx['estado_revision']==='aprobado') $gAprobados++;
      elseif($_gx['estado_revision']==='rechazado') $gRechazados++;
      elseif($_gx['estado_revision']==='observacion') $gObservados++;
      elseif(empty($_gx['estado_revision'])) $gSinRev++;
    }
    $gTotal      = count($grupo['tickets']);
  ?>
  <div class="grupo-rev">
    <button class="grupo-rev-header" onclick="toggleGrupoRev(this,'gRevBody<?= $gi ?>')">
      <span class="g-ppu"><i class="bi bi-truck me-1"></i><?= htmlspecialchars($grupo['ppu']) ?></span>
      <div>
        <div class="g-cond"><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($grupo['conductor']) ?>
          <?php if ($grupo['rut']): ?><span class="text-muted fw-normal small ms-1">(<?= htmlspecialchars($grupo['rut']) ?>)</span><?php endif; ?>
        </div>
        <div class="g-sub">
          <?php if ($grupo['obra']): ?><i class="bi bi-building me-1"></i><?= htmlspecialchars($grupo['obra']) ?><?php endif; ?>
          <?php if ($grupo['m3'] > 0): ?>&nbsp;·&nbsp;<?= number_format((float)$grupo['m3'],1,',','.') ?> m³<?php endif; ?>
        </div>
      </div>
      <div class="g-badges">
        <?php if ($gSinRev > 0): ?>  <span class="badge bg-secondary"><?= $gSinRev ?> sin revisar</span><?php endif; ?>
        <?php if ($gAprobados > 0): ?><span class="badge bg-success"><?= $gAprobados ?> aprobado<?= $gAprobados>1?'s':'' ?></span><?php endif; ?>
        <?php if ($gRechazados > 0): ?><span class="badge bg-danger"><?= $gRechazados ?> rechazado<?= $gRechazados>1?'s':'' ?></span><?php endif; ?>
        <?php if ($gObservados > 0): ?><span class="badge bg-warning text-dark"><?= $gObservados ?> obs.</span><?php endif; ?>
        <span class="badge bg-light text-dark border"><?= $gTotal ?> total</span>
        <i class="bi bi-chevron-down g-chevron ms-1"></i>
      </div>
    </button>

    <div class="grupo-rev-body" id="gRevBody<?= $gi ?>">
      <div class="d-flex flex-column gap-3">
      <?php foreach ($grupo['tickets'] as $t):
        $revClassMap = ['aprobado'=>'rev-aprobado','rechazado'=>'rev-rechazado','observacion'=>'rev-observacion'];
        $revClass = isset($revClassMap[$t['estado_revision']]) ? $revClassMap[$t['estado_revision']] : 'rev-sin';
        $revLabelMap = [
          'aprobado'   =>'<span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Aprobado</span>',
          'rechazado'  =>'<span class="badge bg-danger"><i class="bi bi-x-circle-fill me-1"></i>Rechazado</span>',
          'observacion'=>'<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle-fill me-1"></i>Con obs.</span>',
        ];
        $revLabel = isset($revLabelMap[$t['estado_revision']]) ? $revLabelMap[$t['estado_revision']] : '<span class="badge bg-secondary">Sin revisar</span>';
      ?>
      <div class="rev-card p-3 <?= $revClass ?>">
        <div class="row g-2 align-items-start">
          <!-- Info ticket -->
          <div class="col-md-5">
            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
              <span class="text-muted small">#<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?></span>
              <strong><?= date('d/m/Y', strtotime($t['fecha'])) ?></strong>
              <span class="text-muted small"><?= substr($t['hora'],0,5) ?></span>
              <?= $revLabel ?>
            </div>
            <div class="d-flex flex-wrap gap-1 mt-1">
              <span class="badge bg-secondary"><?= htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) ?></span>
              <?php if ($t['destino_nombre']): ?><span class="badge bg-light text-dark border"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($t['destino_nombre']) ?></span><?php endif; ?>
            </div>
            <?php if (!empty($t['observacion_revision']) && $t['estado_revision']): ?>
            <div class="mt-2 p-2 rounded" style="background:#fffbe6;border:1px solid #ffd;font-size:.82rem;">
              <i class="bi bi-chat-text me-1 text-warning"></i><?= htmlspecialchars($t['observacion_revision']) ?>
            </div>
            <?php endif; ?>
          </div>
          <!-- Formulario revisión -->
          <div class="col-md-7">
            <form method="POST" id="formRev<?= $t['id'] ?>" class="row g-2">
              <input type="hidden" name="accion" value="revisar_ticket">
              <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
              <div class="col-12">
                <div class="btn-group w-100" role="group">
                  <input type="radio" class="btn-check rev-radio" name="estado_revision" value="aprobado"
                    id="apr<?= $t['id'] ?>" data-tid="<?= $t['id'] ?>"
                    <?= ($t['estado_revision']==='aprobado') ? 'checked' : '' ?>>
                  <label class="btn btn-outline-success" for="apr<?= $t['id'] ?>"><i class="bi bi-check-circle-fill me-1"></i>Aprobar</label>
                  <input type="radio" class="btn-check rev-radio" name="estado_revision" value="observacion"
                    id="obs<?= $t['id'] ?>" data-tid="<?= $t['id'] ?>"
                    <?= ($t['estado_revision']==='observacion') ? 'checked' : '' ?>>
                  <label class="btn btn-outline-warning" for="obs<?= $t['id'] ?>"><i class="bi bi-exclamation-triangle-fill me-1"></i>Observación</label>
                  <input type="radio" class="btn-check rev-radio" name="estado_revision" value="rechazado"
                    id="rec<?= $t['id'] ?>" data-tid="<?= $t['id'] ?>"
                    <?= ($t['estado_revision']==='rechazado') ? 'checked' : '' ?>>
                  <label class="btn btn-outline-danger" for="rec<?= $t['id'] ?>"><i class="bi bi-x-circle-fill me-1"></i>Rechazar</label>
                </div>
              </div>
              <div class="col-12" id="obsBox<?= $t['id'] ?>" style="<?= in_array($t['estado_revision'],['observacion','rechazado']) ? '' : 'display:none' ?>">
                <div class="input-group input-group-sm">
                  <input type="text" name="observacion" id="obsInput<?= $t['id'] ?>"
                    class="form-control form-control-sm"
                    placeholder="Escribe la observación y presiona Enter..."
                    value="<?= htmlspecialchars($t['observacion_revision'] ?? '') ?>">
                  <button type="submit" class="btn btn-dark btn-sm px-3"><i class="bi bi-check-lg"></i></button>
                </div>
              </div>
            </form>
            <div class="text-end mt-1">
              <a href="tickets_despacho_ver.php?id=<?= $t['id'] ?>" target="_blank" class="btn btn-link btn-sm p-0 text-muted">
                <i class="bi bi-eye me-1"></i>Ver ticket completo
              </a>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- ════ TAB: VALIDAR QR ════ -->
  <?php elseif ($tabActivo === 'validar'): ?>

  <!-- Solo cámara -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-success text-white fw-bold"><i class="bi bi-qr-code-scan me-2"></i>Escanear QR</div>
    <div class="card-body text-center">
      <p class="text-muted small mb-3">Apunta la cámara al QR del ticket o de la patente del camión.</p>
      <div class="scanner-box mx-auto" style="max-width:360px;">
        <div id="reader" style="width:100%;"></div>
        <div id="scanResult" class="text-center mt-2 fw-semibold"></div>
      </div>
    </div>
  </div>

  <!-- Ticket encontrado por token en URL -->
  <?php if ($ticketBuscado): ?>
  <div class="card border-<?= $ticketBuscado['estado']==='validado'?'success':'warning' ?> shadow-sm mb-4">
    <div class="card-header bg-<?= $ticketBuscado['estado']==='validado'?'success':'warning' ?> <?= $ticketBuscado['estado']==='validado'?'text-white':'text-dark' ?> fw-bold">
      <i class="bi bi-ticket-perforated me-1"></i>Ticket #<?= str_pad($ticketBuscado['id'],5,'0',STR_PAD_LEFT) ?>
    </div>
    <div class="card-body">
      <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="fw-semibold small text-muted">Fecha / Hora</div><div><?= $ticketBuscado['fecha'] ?> <?= substr($ticketBuscado['hora'],0,5) ?></div></div>
        <div class="col-6 col-md-3">
          <div class="fw-semibold small text-muted">PPU</div>
          <div class="ppu-badge"><?= htmlspecialchars($ticketBuscado['ppu'] ?: '—') ?></div>
          <?php if ((float)$ticketBuscado['metros_cubicos'] > 0): ?>
          <div class="badge bg-info text-dark mt-1"><i class="bi bi-box me-1"></i><?= number_format((float)$ticketBuscado['metros_cubicos'],1,',','.') ?> m³</div>
          <?php endif; ?>
        </div>
        <div class="col-6 col-md-3"><div class="fw-semibold small text-muted">Material</div><div><?= htmlspecialchars($MATERIALES[$ticketBuscado['tipo_material']] ?? $ticketBuscado['tipo_material']) ?></div></div>
        <div class="col-6 col-md-3"><div class="fw-semibold small text-muted">Obra</div><div><small><?= htmlspecialchars($ticketBuscado['obra_nombre'] ?: '—') ?></small></div></div>
        <?php if (!empty($ticketBuscado['conductor_nombre'])): ?>
        <div class="col-md-6"><div class="fw-semibold small text-muted">Conductor</div>
          <div><?= htmlspecialchars($ticketBuscado['conductor_nombre']) ?> <small class="text-muted"><?= $ticketBuscado['conductor_rut'] ? '('.htmlspecialchars($ticketBuscado['conductor_rut']).')' : '' ?></small></div></div>
        <?php endif; ?>
        <div class="col-md-6"><div class="fw-semibold small text-muted">Creado por</div><div><?= htmlspecialchars($ticketBuscado['creado_por_nombre']) ?></div></div>
      </div>
      <?php if ($ticketBuscado['estado'] === 'enviado'): ?>
      <form method="POST">
        <input type="hidden" name="accion" value="validar">
        <input type="hidden" name="token" value="<?= htmlspecialchars($ticketBuscado['token']) ?>">
        <input type="hidden" name="cambio" value="">
        <button type="submit" class="btn btn-success btn-lg w-100"><i class="bi bi-check-circle-fill me-2"></i>Confirmar Validación</button>
      </form>
      <?php else: ?>
      <div class="alert alert-success mb-0"><i class="bi bi-check-circle-fill me-1"></i>Ya validado por <strong><?= htmlspecialchars($ticketBuscado['validado_por_nombre']) ?></strong></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Pendientes de validar -->
  <?php if (!empty($pendientes)): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-warning text-dark fw-bold">
      <i class="bi bi-hourglass-split me-1"></i>Pendientes de validación <span class="badge bg-dark ms-1"><?= count($pendientes) ?></span>
    </div>
    <div class="card-body p-0">
      <?php foreach ($pendientes as $t): ?>
      <div class="ticket-mini d-flex flex-wrap align-items-center justify-content-between gap-2 m-3">
        <div>
          <span class="text-muted small">#<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?></span>
          <span class="ms-2 fw-bold"><?= date('d/m/Y', strtotime($t['fecha'])) ?> <?= substr($t['hora'],0,5) ?></span>
          <span class="ppu-badge ms-2"><?= htmlspecialchars($t['ppu_codigo'] ?: '—') ?></span>
          <?php if ((float)$t['metros_cubicos'] > 0): ?>
          <span class="badge bg-info text-dark ms-1"><i class="bi bi-box me-1"></i><?= number_format((float)$t['metros_cubicos'],1,',','.') ?> m³</span>
          <?php endif; ?>
          <span class="badge bg-secondary ms-1"><?= htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) ?></span>
          <?php if ($t['obra_codigo']): ?><br><small class="text-muted ms-1"><i class="bi bi-building me-1"></i><?= htmlspecialchars($t['obra_codigo']) ?> — <?= htmlspecialchars($t['obra_nombre']) ?></small><?php endif; ?>
          <?php if (!empty($t['conductor_nombre'])): ?><small class="text-muted ms-2"><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($t['conductor_nombre']) ?></small><?php endif; ?>
        </div>
        <button class="btn btn-success btn-sm fw-bold"
          onclick="abrirScannerValidar('<?= htmlspecialchars($t['token']) ?>', '<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?>', '<?= htmlspecialchars(addslashes($t['ppu_codigo'])) ?>', <?= (int)$t['ppu_id'] ?>)">
          <i class="bi bi-qr-code-scan me-1"></i>Validar
        </button>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>

  <p class="text-center text-muted mt-4 small">DOGROUP &copy; <?= date('Y') ?> — Portal Validadores Externos</p>
</div>

<!-- ══ Modal Scanner de Validación inline ══ -->
<div class="modal fade" id="modalValidarScan" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header text-white fw-bold" style="background:#198754;">
        <h5 class="modal-title"><i class="bi bi-qr-code-scan me-2"></i>Escanear QR del Ticket</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="detenerScanValidar()"></button>
      </div>
      <div class="modal-body text-center p-3">
        <div id="scanValidarInfo" class="mb-3 p-2 rounded" style="background:#f8f9fa;">
          <span class="text-muted small">Ticket <strong id="scanValidarNum"></strong> — PPU <strong id="scanValidarPPU"></strong></span>
        </div>
        <!-- Cámara -->
        <div id="scanValidarBox" style="border:3px dashed #198754;border-radius:10px;overflow:hidden;background:#000;min-height:200px;">
          <div id="readerValidar" style="width:100%;"></div>
        </div>
        <p class="text-muted small mt-2 mb-0"><i class="bi bi-camera me-1"></i>Apunta la cámara al QR de la <strong>PPU</strong> o al QR del <strong>ticket</strong></p>
        <!-- Estado del escaneo -->
        <div id="scanValidarStatus" class="mt-2"></div>
        <!-- Popup de confirmación (oculto hasta verificar) -->
        <div id="scanValidarConfirm" style="display:none;" class="mt-3">
          <div class="alert alert-success py-2">
            <i class="bi bi-check-circle-fill me-1"></i><strong>QR verificado correctamente</strong>
          </div>
          <form method="POST" id="formValidarInline">
            <input type="hidden" name="accion" value="validar">
            <input type="hidden" name="token" id="scanValidarToken" value="">
            <input type="hidden" name="cambio" value="">
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-outline-secondary w-50" data-bs-dismiss="modal" onclick="detenerScanValidar()">Cancelar</button>
              <button type="submit" class="btn btn-success w-50 fw-bold">
                <i class="bi bi-check-circle-fill me-1"></i>Confirmar Validación
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal confirmación QR Patente escaneado -->
<div class="modal fade" id="modalScanPPU" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header border-0" style="background:#1b2838;">
        <h5 class="modal-title text-white"><i class="bi bi-qr-code-scan me-2 text-success"></i>Patente detectada</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="reiniciarScanner()"></button>
      </div>
      <div class="modal-body text-center py-4">
        <i class="bi bi-truck text-primary mb-3 d-block" style="font-size:3rem;"></i>
        <p class="text-muted small mb-1">Camión identificado:</p>
        <div class="badge bg-dark px-4 py-2 mb-2" style="font-size:1.4rem;letter-spacing:3px;font-family:monospace;" id="popPpuId"></div>
        <p class="text-muted small d-none" id="popPpuUrl"></p>
        <hr class="my-3">
        <p class="mb-0 text-muted small">¿Confirmas que es el camión correcto?<br>Se abrirá la página de validación.</p>
      </div>
      <div class="modal-footer border-0 justify-content-center gap-3">
        <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal" onclick="reiniciarScanner()">
          <i class="bi bi-arrow-left me-1"></i>Volver
        </button>
        <button type="button" class="btn btn-success px-4 fw-bold" onclick="confirmarIrPPU()">
          <i class="bi bi-check-circle-fill me-2"></i>Validar patente
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Popup notificación ticket entrante -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:9999;" id="notifArea"></div>

<!-- Modal alerta ticket nuevo -->
<div class="modal fade" id="modalTicketNuevo" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header text-white border-0" style="background:#1b2838;">
        <h5 class="modal-title"><i class="bi bi-bell-fill me-2 text-warning"></i>Ticket Pendiente de Validación</h5>
      </div>
      <div class="modal-body text-center py-4">
        <div class="mb-3">
          <i class="bi bi-truck text-primary" style="font-size:3.5rem;"></i>
        </div>
        <div class="badge bg-dark mb-2" style="font-size:1.6rem;letter-spacing:3px;font-family:monospace;" id="notifPPU"></div>
        <p class="text-muted mb-1" id="notifM3"></p>
        <p class="mb-1" id="notifMaterial"></p>
        <p class="text-muted small mb-1" id="notifObra"></p>
        <p class="text-muted small mb-0" id="notifConductor"></p>
        <hr>
        <span class="badge bg-warning text-dark px-3 py-2 fs-6"><i class="bi bi-hourglass-split me-1"></i>Pendiente de validación</span>
      </div>
      <div class="modal-footer border-0 justify-content-center gap-3">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="snoozeNotif()">
          <i class="bi bi-bell-slash me-1"></i>Ignorar
        </button>
        <button type="button" class="btn btn-success fw-bold px-4" data-bs-dismiss="modal" onclick="irAValidar()">
          <i class="bi bi-qr-code-scan me-2"></i>Ir a validar
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<?php if ($tabActivo === 'validar'): ?>
<script>/* noop - html5-qrcode ya cargado arriba */</script>
<script>
var scannerActivo = true;
const html5QrCode = new Html5Qrcode("reader");
html5QrCode.start(
  { facingMode: "environment" },
  { fps: 10, qrbox: { width: 250, height: 250 } },
  function(decodedText) {
    if (!scannerActivo) return;
    /* No interferir si el modal de validación inline está abierto */
    if (typeof _inlineModalActivo !== 'undefined' && _inlineModalActivo) return;
    scannerActivo = false;
    html5QrCode.stop().then(function() {
      try {
        var url = new URL(decodedText);
        var ppu = url.searchParams.get('ppu');
        var tok = url.searchParams.get('t');
        if (ppu) {
          /* QR de Patente → reconstruir URL con servidor actual (evita localhost) */
          var ppuUrl = window.location.origin + '/validar_ppu.php?ppu=' + encodeURIComponent(ppu);
          mostrarPopupPPU(ppu, ppuUrl);
        } else if (tok) {
          /* QR de Ticket → ir a validar */
          document.getElementById('scanResult').innerHTML = '<i class="bi bi-check-circle text-success me-1"></i>Ticket detectado, cargando...';
          window.location.href = 'despacho_externo_portal.php?tab=validar&t=' + encodeURIComponent(tok);
        }
        /* Ignorar otros QRs desconocidos (no navegar) */
      } catch(e) {
        /* Token plano */
        document.getElementById('scanResult').innerHTML = '<i class="bi bi-check-circle text-success me-1"></i>QR detectado, cargando...';
        window.location.href = 'despacho_externo_portal.php?tab=validar&t=' + encodeURIComponent(decodedText);
      }
    });
  },
  function() {}
).catch(function() {
  document.getElementById('reader').innerHTML =
    '<div class="alert alert-warning py-3 text-center"><i class="bi bi-camera-video-off fs-3 d-block mb-2"></i>Cámara no disponible en este dispositivo.<br><small>Usa la lista de pendientes abajo.</small></div>';
});

/* Popup confirmación para QR de Patente */
var ppuUrlPendiente = '';
function mostrarPopupPPU(ppuId, fullUrl) {
  /* Si el modal inline está activo, no navegar ni mostrar popup de PPU */
  if (_inlineModalActivo) return;
  /* Siempre reconstruir con el servidor actual para evitar URLs de localhost */
  var safeUrl = window.location.origin + '/validar_ppu.php?ppu=' + encodeURIComponent(ppuId);
  ppuUrlPendiente = safeUrl;
  document.getElementById('popPpuId').textContent = '#' + ppuId;
  document.getElementById('popPpuUrl').textContent = safeUrl;
  var modal = new bootstrap.Modal(document.getElementById('modalScanPPU'));
  modal.show();
}
function confirmarIrPPU() {
  window.location.href = ppuUrlPendiente;
}
</script>
<?php endif; ?>
<script>
/* Reiniciar scanner si el usuario cancela el popup de PPU */
function reiniciarScanner() {
  if (typeof html5QrCode !== 'undefined') {
    scannerActivo = true;
    try {
      html5QrCode.start(
        { facingMode: "environment" },
        { fps: 10, qrbox: { width: 250, height: 250 } },
        function(decodedText) {
          if (!scannerActivo) return;
          scannerActivo = false;
          html5QrCode.stop();
          try {
            var url = new URL(decodedText);
            var ppu = url.searchParams.get('ppu');
            var tok = url.searchParams.get('t');
            if (ppu) {
              var ppuUrl = window.location.origin + '/validar_ppu.php?ppu=' + encodeURIComponent(ppu);
              mostrarPopupPPU(ppu, ppuUrl);
            }
            else if (tok) window.location.href = 'despacho_externo_portal.php?tab=validar&t=' + encodeURIComponent(tok);
            else window.location.href = decodedText;
          } catch(e) { window.location.href = 'despacho_externo_portal.php?tab=validar&t=' + encodeURIComponent(decodedText); }
        },
        function() {}
      ).catch(function(){});
    } catch(e) {}
  }
}

/* Descargar QR de patente como imagen PNG */
function descargarQR(ppuId, ppuLabel) {
  var container = document.getElementById('qr_' + ppuId);
  var canvas = container ? container.querySelector('canvas') : null;
  if (!canvas) {
    var img = container ? container.querySelector('img') : null;
    if (img) {
      var a = document.createElement('a');
      a.href = img.src; a.download = 'QR_' + ppuLabel + '.png'; a.click();
    }
    return;
  }
  /* Crear canvas con fondo blanco y padding */
  var pad = 16;
  var out = document.createElement('canvas');
  out.width  = canvas.width  + pad * 2;
  out.height = canvas.height + pad * 2 + 30;
  var ctx = out.getContext('2d');
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, out.width, out.height);
  ctx.drawImage(canvas, pad, pad);
  ctx.fillStyle = '#1b2838';
  ctx.font = 'bold 18px monospace';
  ctx.textAlign = 'center';
  ctx.fillText(ppuLabel, out.width / 2, out.height - 8);
  var a = document.createElement('a');
  a.download = 'QR_PPU_' + ppuLabel + '.png';
  a.href = out.toDataURL('image/png');
  a.click();
}

/* ══ POLLING: detectar tickets nuevos cada 15s ══ */
var ultimoTotal   = <?= count($pendientes) ?>;
var snoozeHasta   = 0;
var modalNuevo    = null;
var ticketPendUrl = '';

document.addEventListener('DOMContentLoaded', function() {
  modalNuevo = new bootstrap.Modal(document.getElementById('modalTicketNuevo'));
  iniciarPolling();
});

function iniciarPolling() {
  setInterval(consultarTickets, 15000);
}

function consultarTickets() {
  fetch('api_notif_despacho.php')
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (data.error) return;
      var nuevoTotal = data.total;

      /* Si hay más tickets que antes y no estamos en snooze */
      if (nuevoTotal > ultimoTotal && Date.now() > snoozeHasta) {
        /* Tomar el más reciente (primero de la lista) */
        var t = data.tickets[0];
        mostrarNotif(t);
      }

      /* Actualizar contador en tab Validar si existe */
      var badge = document.querySelector('#portalTabs a[href*="validar"] .badge');
      if (badge) badge.textContent = nuevoTotal;

      ultimoTotal = nuevoTotal;
    })
    .catch(function(){});
}

function mostrarNotif(t) {
  var MATERIALES = {
    'tierra':'Tierra','integral':'Integral','bajo_6':'Bajo 6','bajo_4':'Bajo 4',
    'bajo_3':'Bajo 3','bajo_2':'Bajo 2','base_chancada':'Base Chancada','grava':'Grava',
    'gravilla':'Gravilla','arena':'Arena','arena_tubo':'Arena Tubo',
    'escombros':'Escombros','bolones':'Bolones'
  };

  document.getElementById('notifPPU').textContent      = t.ppu || '—';
  document.getElementById('notifM3').textContent       = t.metros_cubicos > 0 ? t.metros_cubicos + ' m³' : '';
  document.getElementById('notifMaterial').textContent = MATERIALES[t.tipo_material] || t.tipo_material || '—';
  document.getElementById('notifObra').textContent     = t.obra_nombre ? '🏗 ' + t.obra_nombre : '';
  document.getElementById('notifConductor').textContent= t.conductor_nombre ? '👤 ' + t.conductor_nombre : '';

  ticketPendUrl = '?tab=validar';
  modalNuevo.show();

  /* Sonido de notificación */
  try {
    var ctx = new (window.AudioContext || window.webkitAudioContext)();
    [440, 550, 660].forEach(function(freq, i) {
      var osc = ctx.createOscillator();
      var gain = ctx.createGain();
      osc.connect(gain); gain.connect(ctx.destination);
      osc.frequency.value = freq;
      osc.type = 'sine';
      gain.gain.setValueAtTime(0.3, ctx.currentTime + i * 0.15);
      gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + i * 0.15 + 0.3);
      osc.start(ctx.currentTime + i * 0.15);
      osc.stop(ctx.currentTime + i * 0.15 + 0.3);
    });
  } catch(e) {}
}

function snoozeNotif() {
  /* Ignorar notificaciones por 5 minutos */
  snoozeHasta = Date.now() + 5 * 60 * 1000;
}

function irAValidar() {
  window.location.href = ticketPendUrl;
}

/* ── Scanner de Validación inline ── */
var _scanValidarQR       = null;
var _scanValidarActivo   = false;
var _scanValidarToken    = '';
var _scanValidarPpuId    = 0;
var _inlineModalActivo   = false; /* guard: evita que el tab-scanner interfiera */

function abrirScannerValidar(token, numTicket, ppuLabel, ppuId) {
  _scanValidarToken  = token;
  _scanValidarPpuId  = parseInt(ppuId) || 0;
  _scanValidarActivo = true;
  _inlineModalActivo = true;

  /* Pausar el scanner del tab "Validar QR" si estuviera activo */
  if (typeof scannerActivo !== 'undefined') { scannerActivo = false; }

  document.getElementById('scanValidarNum').textContent  = '#' + numTicket;
  document.getElementById('scanValidarPPU').textContent  = ppuLabel;
  document.getElementById('scanValidarStatus').innerHTML = '';
  document.getElementById('scanValidarConfirm').style.display = 'none';
  document.getElementById('scanValidarBox').style.display     = '';
  document.getElementById('scanValidarToken').value           = '';

  var modal = new bootstrap.Modal(document.getElementById('modalValidarScan'));
  modal.show();

  /* Iniciar cámara */
  document.getElementById('readerValidar').innerHTML = '';
  _scanValidarQR = new Html5Qrcode('readerValidar');
  _scanValidarQR.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 220, height: 220 } },
    function(decodedText) {
      if (!_scanValidarActivo) return;

      var coincide = false;
      try {
        var u = new URL(decodedText);
        var tokenQR = u.searchParams.get('t');
        var ppuQR   = u.searchParams.get('ppu');

        /* QR del ticket: contiene ?t=TOKEN */
        if (tokenQR && tokenQR.trim() === _scanValidarToken.trim()) {
          coincide = true;
        }
        /* QR de PPU con ID conocido */
        if (ppuQR && _scanValidarPpuId > 0 && parseInt(ppuQR) === _scanValidarPpuId) {
          coincide = true;
        }
        /* QR de PPU sin ID almacenado (ppu_id=0): aceptar cualquier QR de PPU */
        if (ppuQR && _scanValidarPpuId === 0) {
          coincide = true;
        }
      } catch(e) {
        /* QR plano: comparar directo con token */
        if (decodedText.trim() === _scanValidarToken.trim()) coincide = true;
      }

      if (coincide) {
        /* ✅ QR verificado → mostrar confirmación SIN navegar */
        _scanValidarActivo = false;
        _scanValidarQR.stop().catch(function(){});
        document.getElementById('scanValidarBox').style.display     = 'none';
        document.getElementById('scanValidarToken').value           = _scanValidarToken;
        document.getElementById('scanValidarStatus').innerHTML      = '';
        document.getElementById('scanValidarConfirm').style.display = '';
      } else {
        document.getElementById('scanValidarStatus').innerHTML =
          '<div class="alert alert-warning py-1 small"><i class="bi bi-exclamation-triangle me-1"></i>QR no corresponde. Escanea el QR de la PPU del camión.</div>';
        _scanValidarActivo = true;
      }
    },
    function() {}
  ).catch(function() {
    document.getElementById('readerValidar').innerHTML =
      '<div class="alert alert-warning m-2 py-2 small"><i class="bi bi-camera-video-off me-1"></i>Cámara no disponible. Verifica permisos.</div>';
  });
}

function detenerScanValidar() {
  _scanValidarActivo = false;
  _inlineModalActivo = false;
  if (_scanValidarQR) { _scanValidarQR.stop().catch(function(){}); _scanValidarQR = null; }
}

/* Detener cámara al cerrar el modal */
document.addEventListener('DOMContentLoaded', function() {
  var el = document.getElementById('modalValidarScan');
  if (el) el.addEventListener('hidden.bs.modal', function() { detenerScanValidar(); });
});

/* ── Acordeón revisión por PPU ── */
function toggleGrupoRev(btn, bodyId) {
  var body = document.getElementById(bodyId);
  if (!body) return;
  var isOpen = body.classList.contains('open');
  body.classList.toggle('open', !isOpen);
  btn.classList.toggle('active', !isOpen);
}
document.addEventListener('DOMContentLoaded', function() {
  /* Abrir primer grupo automáticamente */
  var firstGrupo = document.querySelector('.grupo-rev-header');
  if (firstGrupo) firstGrupo.click();
  /* Abrir grupos con tickets sin revisar */
  document.querySelectorAll('.grupo-rev').forEach(function(g) {
    var hasSinRev = g.querySelector('.badge.bg-secondary');
    if (hasSinRev) {
      var btn  = g.querySelector('.grupo-rev-header');
      var body = g.querySelector('.grupo-rev-body');
      if (btn && body && !body.classList.contains('open')) {
        body.classList.add('open');
        btn.classList.add('active');
      }
    }
  });
});

/* ── Auto-guardar revisión al hacer clic en radio ── */
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.rev-radio').forEach(function(radio) {
    radio.addEventListener('change', function() {
      var tid     = this.getAttribute('data-tid');
      var val     = this.value;
      var obsBox  = document.getElementById('obsBox' + tid);
      var obsInp  = document.getElementById('obsInput' + tid);
      var form    = document.getElementById('formRev' + tid);

      if (val === 'aprobado') {
        /* Ocultar campo obs y enviar inmediatamente */
        if (obsBox) obsBox.style.display = 'none';
        form.submit();
      } else {
        /* Mostrar campo de observación y enfocar */
        if (obsBox) { obsBox.style.display = ''; }
        if (obsInp) {
          obsInp.focus();
          /* Enviar al presionar Enter */
          obsInp.onkeydown = function(e) {
            if (e.key === 'Enter') { e.preventDefault(); form.submit(); }
          };
        }
      }
    });
  });
});
</script>
</body>
</html>
