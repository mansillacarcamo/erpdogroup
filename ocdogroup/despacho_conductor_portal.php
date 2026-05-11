<?php
require_once 'config.php';

/* ── Autenticación ── */
if (!isConductorLoggedIn()) {
  header('Location: despacho_conductor_login.php'); exit;
}
$cond = getConductorSession();

/* ── Logout ── */
if (isset($_GET['logout'])) {
  session_destroy();
  header('Location: despacho_conductor_login.php'); exit;
}

$MATERIALES = [
  'tierra'       => 'Tierra',
  'integral'     => 'Integral',
  'bajo_6'       => 'Bajo 6',
  'bajo_4'       => 'Bajo 4',
  'bajo_3'       => 'Bajo 3',
  'bajo_2'       => 'Bajo 2',
  'base_chancada'=> 'Base Chancada',
  'grava'        => 'Grava',
  'gravilla'     => 'Gravilla',
  'arena'        => 'Arena',
  'arena_tubo'   => 'Arena Tubo',
  'escombros'    => 'Escombros',
  'bolones'      => 'Bolones',
];

$msgTicket = null; $msgTipoTicket = 'success';

/* ── POST: Crear ticket ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_ticket') {
  try {
    $ppuId   = (int)($_POST['ppu_id']  ?? $cond['ppu_id']  ?? 0);
    $obraId  = (int)($_POST['obra_id'] ?? $cond['obra_id'] ?? 0);
    $destId  = (int)($_POST['destino_id'] ?? 0);
    $materia = $_POST['tipo_material'] ?? '';
    $fecha   = $_POST['fecha'] ?? date('Y-m-d');
    $hora    = $_POST['hora']  ?? date('H:i');
    $obs     = trim($_POST['observaciones'] ?? '');

    if (!$ppuId)   throw new Exception('Selecciona un camión (PPU).');
    if (!$destId)  throw new Exception('Selecciona un destino.');
    if (!$materia) throw new Exception('Selecciona el tipo de material.');

    /* Datos PPU */
    $ppuRow = $pdo->prepare("SELECT * FROM despacho_ppu WHERE id=?");
    $ppuRow->execute([$ppuId]);
    $ppuRow = $ppuRow->fetch(PDO::FETCH_ASSOC);

    /* Datos Obra */
    $obraRow = null;
    if ($obraId) {
      $stO = $pdo->prepare("SELECT * FROM obras WHERE id=?");
      $stO->execute([$obraId]);
      $obraRow = $stO->fetch(PDO::FETCH_ASSOC);
    }

    /* Datos Destino */
    $destRow = $pdo->prepare("SELECT * FROM despacho_destinos WHERE id=?");
    $destRow->execute([$destId]);
    $destRow = $destRow->fetch(PDO::FETCH_ASSOC);

    /* Buscar Carchek: primero por obra, si no el primero disponible */
    $recRow = null;
    if ($obraId) {
      $stRec = $pdo->prepare("
        SELECT dr.id FROM despacho_receptores dr
        JOIN despacho_externos de ON de.id = dr.externo_id
        WHERE dr.tipo = 'externo' AND de.activo = 1 AND de.obra_id = ?
        LIMIT 1
      ");
      $stRec->execute([$obraId]);
      $recRow = $stRec->fetch(PDO::FETCH_ASSOC);
    }
    if (!$recRow) {
      $recRow = $pdo->query("
        SELECT dr.id FROM despacho_receptores dr
        JOIN despacho_externos de ON de.id = dr.externo_id
        WHERE dr.tipo = 'externo' AND de.activo = 1
        LIMIT 1
      ")->fetch(PDO::FETCH_ASSOC);
    }
    if (!$recRow) throw new Exception('No hay Carchek configurado. Contacta al administrador.');
    $recId = $recRow['id'];

    /* Token único */
    $token = bin2hex(random_bytes(16));

    $pdo->prepare("INSERT INTO tickets_despacho
      (fecha, hora, obra_id, obra_nombre, ppu_id, ppu, metros_cubicos, destino_id, destino,
       tipo_material, observaciones, estado, creado_por, creado_por_nombre, receptor_id, token,
       conductor_id, conductor_nombre, conductor_rut)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([
        $fecha, $hora,
        $obraId ?: null, $obraRow ? $obraRow['codigo'].' — '.$obraRow['nombre'] : '',
        $ppuId, $ppuRow ? $ppuRow['ppu'] : '',
        $ppuRow ? (float)$ppuRow['metros_cubicos'] : 0,
        $destId, $destRow ? $destRow['nombre'] : '',
        $materia, $obs,
        'borrador',
        0, $cond['nombre'],
        $recId, $token,
        $cond['id'], $cond['nombre'], $cond['rut'],
      ]);

    header("Location: despacho_conductor_portal.php?ok=1"); exit;
  } catch (Exception $e) {
    $msgTicket = $e->getMessage(); $msgTipoTicket = 'danger';
  }
}

/* ── POST: Editar ticket (solo borrador) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar_ticket') {
  $tid = (int)($_POST['ticket_id'] ?? 0);
  try {
    $stT = $pdo->prepare("SELECT * FROM tickets_despacho WHERE id=? AND conductor_id=? AND estado='borrador'");
    $stT->execute([$tid, $cond['id']]);
    if (!$stT->fetch()) throw new Exception('Ticket no encontrado o ya enviado.');

    $ppuId  = (int)($_POST['ppu_id']  ?? 0);
    $obraId = (int)($_POST['obra_id'] ?? 0);
    $destId = (int)($_POST['destino_id'] ?? 0);
    if (!$ppuId)              throw new Exception('Selecciona un camión.');
    if (!$destId)             throw new Exception('Selecciona un destino.');
    if (!$_POST['tipo_material']) throw new Exception('Selecciona el material.');

    $ppuRow  = $pdo->prepare("SELECT * FROM despacho_ppu WHERE id=?");    $ppuRow->execute([$ppuId]);  $ppuRow  = $ppuRow->fetch(PDO::FETCH_ASSOC);
    $destRow = $pdo->prepare("SELECT * FROM despacho_destinos WHERE id=?"); $destRow->execute([$destId]); $destRow = $destRow->fetch(PDO::FETCH_ASSOC);
    $obraRow = null;
    if ($obraId) { $stO = $pdo->prepare("SELECT * FROM obras WHERE id=?"); $stO->execute([$obraId]); $obraRow = $stO->fetch(PDO::FETCH_ASSOC); }

    $pdo->prepare("UPDATE tickets_despacho SET
      fecha=?, hora=?, obra_id=?, obra_nombre=?, ppu_id=?, ppu=?, metros_cubicos=?,
      destino_id=?, destino=?, tipo_material=?, observaciones=?
      WHERE id=? AND conductor_id=? AND estado='borrador'")
      ->execute([
        $_POST['fecha'] ?? date('Y-m-d'),
        $_POST['hora']  ?? date('H:i'),
        $obraId ?: null, $obraRow ? $obraRow['codigo'].' — '.$obraRow['nombre'] : '',
        $ppuId, $ppuRow ? $ppuRow['ppu'] : '',
        $ppuRow ? (float)$ppuRow['metros_cubicos'] : 0,
        $destId, $destRow ? $destRow['nombre'] : '',
        $_POST['tipo_material'],
        trim($_POST['observaciones'] ?? ''),
        $tid, $cond['id'],
      ]);

    header("Location: despacho_conductor_portal.php?editado=1"); exit;
  } catch (Exception $e) {
    $msgTicket = $e->getMessage(); $msgTipoTicket = 'danger';
  }
}

/* ── POST: Enviar ticket al Carchek ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'enviar_ticket') {
  $tid = (int)($_POST['ticket_id'] ?? 0);
  try {
    /* Verificar que el ticket pertenece a este conductor y está en borrador */
    $stT = $pdo->prepare("SELECT * FROM tickets_despacho WHERE id=? AND conductor_id=? AND estado='borrador'");
    $stT->execute([$tid, $cond['id']]);
    $tRow = $stT->fetch(PDO::FETCH_ASSOC);
    if (!$tRow) throw new Exception('Ticket no encontrado o ya enviado.');
    $pdo->prepare("UPDATE tickets_despacho SET estado='enviado', enviado_en=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$tid]);
    header("Location: despacho_conductor_portal.php?enviado=1"); exit;
  } catch (Exception $e) {
    $msgTicket = $e->getMessage(); $msgTipoTicket = 'danger';
  }
}

/* ── Listas para el formulario ── */
$ppuList     = $pdo->query("SELECT * FROM despacho_ppu WHERE activo=1 ORDER BY ppu")->fetchAll(PDO::FETCH_ASSOC);
$destinoList = $pdo->query("SELECT * FROM despacho_destinos WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$obrasList   = $pdo->query("SELECT id, codigo, nombre FROM obras WHERE estado='activa' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);

/* ── Tickets del conductor ── */
$stTickets = $pdo->prepare("
  SELECT td.*,
         o.codigo  AS obra_codigo,
         o.nombre  AS obra_nombre_rel,
         dp.ppu    AS ppu_codigo,
         dp.metros_cubicos,
         dd.nombre AS destino_nombre,
         de.nombre AS carchek_nombre,
         de.empresa AS carchek_empresa
  FROM tickets_despacho td
  LEFT JOIN obras             o  ON o.id  = td.obra_id
  LEFT JOIN despacho_ppu      dp ON dp.id = td.ppu_id
  LEFT JOIN despacho_destinos dd ON dd.id = td.destino_id
  LEFT JOIN despacho_receptores dr ON dr.id = td.receptor_id AND dr.tipo = 'externo'
  LEFT JOIN despacho_externos de   ON de.id = dr.externo_id
  WHERE td.conductor_id = ?
  ORDER BY td.fecha DESC, td.hora DESC
");
$stTickets->execute([$cond['id']]);
$tickets = $stTickets->fetchAll(PDO::FETCH_ASSOC);

/* ── Estadísticas rápidas ── */
$totTickets = count($tickets);
$totM3      = 0;
$pendientes = 0;
$validados  = 0;
foreach ($tickets as $t) {
  $totM3 += (float)($t['metros_cubicos'] ?? 0);
  if ($t['estado'] === 'pendiente' || $t['estado'] === 'enviado') $pendientes++;
  if ($t['estado'] === 'validado') $validados++;
}

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Portal Conductor — DOGROUP</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <style>
  body { background: #f0f4f8; }
  .navbar-brand img { height: 36px; filter: brightness(0) invert(1); }
  .stat-card { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.07); }
  .truck-info { background: #1b2838; color: #fff; border-radius: 12px; padding: 1.25rem 1.5rem; }
  .truck-ppu  { font-size: 2rem; font-weight: 900; letter-spacing: 2px; }
  .estado-badge-enviado   { background: #fff3cd; color: #664d03; }
  .estado-badge-validado  { background: #d1e7dd; color: #0a3622; }
  .estado-badge-borrador  { background: #e2e3e5; color: #41464b; }
  .estado-badge-pendiente { background: #cfe2ff; color: #084298; }
  .ticket-row { cursor: pointer; transition: background .15s; }
  .ticket-row:hover { background: #e8f0fe; }
  @media print { .no-print { display: none !important; } }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark no-print" style="background:#1b2838;">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">
      <img src="img/logo.png" alt="DOGROUP"> <span class="fw-bold">DOGROUP</span>
    </a>
    <div class="d-flex align-items-center gap-2">
      <span class="text-white small d-none d-md-inline"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($cond['nombre']) ?></span>
      <a href="login.php" class="btn btn-sm btn-outline-light"><i class="bi bi-house-fill me-1"></i>Inicio sistema</a>
      <a href="?logout=1" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-right me-1"></i>Salir</a>
    </div>
  </div>
</nav>

<div class="container py-4">

  <!-- Encabezado bienvenida -->
  <div class="row mb-4 align-items-center">
    <div class="col-md-8">
      <h4 class="fw-bold mb-1">Hola, <?= htmlspecialchars(explode(' ', $cond['nombre'])[0]) ?> 👋</h4>
      <p class="text-muted mb-0">Portal de seguimiento de tus tickets de despacho</p>
    </div>
    <div class="col-md-4 text-md-end mt-3 mt-md-0">
      <small class="text-muted"><i class="bi bi-clock me-1"></i><?= date('l, d \d\e F Y') ?></small>
    </div>
  </div>

  <!-- Info del camión asignado -->
  <?php if ($cond['ppu']): ?>
  <div class="truck-info mb-4 d-flex flex-wrap align-items-center gap-4">
    <div>
      <small class="d-block text-white-50 mb-1 small text-uppercase">Mi camión asignado</small>
      <div class="truck-ppu"><i class="bi bi-truck me-2"></i><?= htmlspecialchars($cond['ppu']) ?></div>
    </div>
    <?php if ($cond['metros_cubicos'] > 0): ?>
    <div>
      <small class="d-block text-white-50 mb-1 small text-uppercase">Capacidad</small>
      <div class="fs-4 fw-bold"><?= number_format((float)$cond['metros_cubicos'], 1, ',', '.') ?> m³</div>
    </div>
    <?php endif; ?>
    <?php if ($cond['obra_codigo']): ?>
    <div>
      <small class="d-block text-white-50 mb-1 small text-uppercase">Obra asignada</small>
      <div class="fs-5 fw-semibold"><?= htmlspecialchars($cond['obra_codigo']) ?> — <?= htmlspecialchars($cond['obra_nombre']) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Estadísticas -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="card stat-card text-center p-3">
        <div class="fs-2 fw-bold text-primary"><?= $totTickets ?></div>
        <small class="text-muted">Tickets totales</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card stat-card text-center p-3">
        <div class="fs-2 fw-bold text-warning"><?= $pendientes ?></div>
        <small class="text-muted">Pendientes</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card stat-card text-center p-3">
        <div class="fs-2 fw-bold text-success"><?= $validados ?></div>
        <small class="text-muted">Validados</small>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card stat-card text-center p-3">
        <div class="fs-2 fw-bold text-info"><?= number_format($totM3, 1, ',', '.') ?></div>
        <small class="text-muted">m³ totales</small>
      </div>
    </div>
  </div>

  <?php if (!empty($_GET['ok'])): ?>
  <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle-fill me-1"></i>Ticket creado correctamente. Presiona <strong>Enviar</strong> cuando esté listo para Carchek.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endif; ?>
  <?php if (!empty($_GET['enviado'])): ?>
  <div class="alert alert-info alert-dismissible fade show"><i class="bi bi-send-fill me-1"></i>Ticket enviado a <strong>Carchek</strong> correctamente.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endif; ?>
  <?php if (!empty($_GET['editado'])): ?>
  <div class="alert alert-warning alert-dismissible fade show"><i class="bi bi-pencil-fill me-1"></i>Ticket actualizado correctamente.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endif; ?>

  <?php if ($msgTicket): ?>
  <div class="alert alert-<?= $msgTipoTicket ?> alert-dismissible fade show"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($msgTicket) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endif; ?>

  <!-- Lista de tickets -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h5 class="mb-0 fw-bold"><i class="bi bi-list-check me-2"></i>Mis Tickets</h5>
      <div class="d-flex gap-2">
        <input type="text" id="buscarTicket" class="form-control form-control-sm" placeholder="Buscar..." oninput="filtrarTickets()" style="max-width:180px;">
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearTicket">
          <i class="bi bi-plus-lg me-1"></i>Nuevo Ticket
        </button>
      </div>
    </div>
    <div class="card-body p-0">
      <?php if (empty($tickets)): ?>
      <p class="text-center text-muted py-5 mb-0"><i class="bi bi-inbox fs-3 d-block mb-2"></i>Aún no tienes tickets registrados</p>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="tablaTickets">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Fecha</th>
              <th>Hora</th>
              <th>Obra</th>
              <th>Destino</th>
              <th>Material</th>
              <th>m³</th>
              <th>Estado</th>
              <th class="text-end no-print">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($tickets as $t):
            $estadoMap = ['validado'=>'estado-badge-validado','enviado'=>'estado-badge-enviado','borrador'=>'estado-badge-borrador'];
            $estadoBadge = isset($estadoMap[$t['estado']]) ? $estadoMap[$t['estado']] : 'estado-badge-pendiente';
            $estadoLabelMap = ['validado'=>'<i class="bi bi-check-circle-fill me-1"></i>Validado','enviado'=>'<i class="bi bi-send-fill me-1"></i>Enviado','borrador'=>'<i class="bi bi-pencil me-1"></i>Borrador'];
            $estadoLabel = isset($estadoLabelMap[$t['estado']]) ? $estadoLabelMap[$t['estado']] : '<i class="bi bi-hourglass me-1"></i>Pendiente';
          ?>
          <tr class="ticket-row" onclick="window.open('tickets_despacho_ver.php?id=<?= $t['id'] ?>','_blank')">
            <td><span class="badge bg-secondary"><?= $t['id'] ?></span></td>
            <td><?= $t['fecha'] ? date('d/m/Y', strtotime($t['fecha'])) : '—' ?></td>
            <td><?= $t['hora'] ? substr($t['hora'], 0, 5) : '—' ?></td>
            <td>
              <?php if ($t['obra_codigo']): ?>
              <strong><?= htmlspecialchars($t['obra_codigo']) ?></strong><br>
              <small class="text-muted"><?= htmlspecialchars($t['obra_nombre_rel'] ?: $t['obra_nombre']) ?></small>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= htmlspecialchars($t['destino_nombre'] ?? '—') ?></td>
            <td><?= htmlspecialchars($t['tipo_material'] ?? '—') ?></td>
            <td><?= $t['metros_cubicos'] > 0 ? number_format((float)$t['metros_cubicos'], 1, ',', '.') : '—' ?></td>
            <td><span class="badge rounded-pill px-3 py-2 <?= $estadoBadge ?>"><?= $estadoLabel ?></span></td>
            <td class="text-end no-print" onclick="event.stopPropagation()">
              <div class="d-flex gap-1 justify-content-end">
                <?php if ($t['estado'] === 'borrador'): ?>
                <button class="btn btn-sm btn-warning" title="Editar ticket"
                  onclick="abrirEditar(<?= htmlspecialchars(json_encode($t)) ?>)">
                  <i class="bi bi-pencil-fill"></i>
                </button>
                <button class="btn btn-sm btn-success" title="Enviar a Carchek"
                  onclick="confirmarEnvio(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['carchek_nombre'] ?: 'Carchek'), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($t['carchek_empresa'] ?? ''), ENT_QUOTES) ?>')">
                  <i class="bi bi-send-fill me-1"></i>Enviar
                </button>
                <?php endif; ?>
                <a href="tickets_despacho_ver.php?id=<?= $t['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Ver ticket">
                  <i class="bi bi-eye"></i>
                </a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Info de contacto -->
  <div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><i class="bi bi-person-lines-fill me-2"></i>Mis datos</h6></div>
    <div class="card-body">
      <div class="row g-2">
        <div class="col-md-3"><small class="text-muted d-block">RUT</small><strong><?= htmlspecialchars($cond['rut'] ?: '—') ?></strong></div>
        <div class="col-md-3"><small class="text-muted d-block">Correo</small><strong><?= htmlspecialchars($cond['email'] ?: '—') ?></strong></div>
        <div class="col-md-3"><small class="text-muted d-block">Fono</small><strong><?= htmlspecialchars($cond['fono'] ?: '—') ?></strong></div>
        <div class="col-md-3"><small class="text-muted d-block">Usuario</small><code><?= htmlspecialchars($cond['usuario']) ?></code></div>
      </div>
    </div>
  </div>

  <p class="text-center text-muted mt-4 mb-0" style="font-size:.75rem;">
    Todos los derechos reservados — desarrollado por Cesar Mansilla / Bynari SpA / www.bynari.cl
  </p>
</div>

<!-- Modal Confirmar Envío -->
<div class="modal fade" id="modalConfirmarEnvio" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-success text-white border-0">
        <h5 class="modal-title"><i class="bi bi-send-fill me-2"></i>Enviar Ticket a Carchek</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center py-4">
        <div class="mb-3">
          <i class="bi bi-person-check-fill text-success" style="font-size:3rem;"></i>
        </div>
        <p class="mb-1 text-muted small">Se enviará el ticket a:</p>
        <h4 class="fw-bold mb-1" id="envNombre"></h4>
        <p class="text-muted small mb-0" id="envEmpresa"></p>
        <hr class="my-3">
        <p class="text-muted small mb-0">Una vez enviado, <strong>no podrás editarlo</strong>. ¿Confirmas el envío?</p>
      </div>
      <div class="modal-footer border-0 justify-content-center gap-3">
        <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancelar</button>
        <form method="POST" id="formEnviarTicket" class="d-inline">
          <input type="hidden" name="accion" value="enviar_ticket">
          <input type="hidden" name="ticket_id" id="envTicketId">
          <button type="submit" class="btn btn-success px-4 fw-bold">
            <i class="bi bi-send-fill me-2"></i>Sí, enviar
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Editar Ticket (solo borradores) -->
<div class="modal fade" id="modalEditarTicket" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title"><i class="bi bi-pencil-fill me-2"></i>Editar Ticket <span id="editTicketNum"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" id="formEditarTicket">
        <input type="hidden" name="accion" value="editar_ticket">
        <input type="hidden" name="ticket_id" id="editTicketId">
        <div class="modal-body row g-3">

          <div class="col-md-6">
            <label class="form-label fw-semibold">Fecha *</label>
            <input type="date" name="fecha" id="editFecha" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Hora *</label>
            <input type="time" name="hora" id="editHora" class="form-control" required>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Camión PPU *</label>
            <select name="ppu_id" id="editPpu" class="form-select" required>
              <option value="">— Selecciona PPU —</option>
              <?php foreach ($ppuList as $p): ?>
              <option value="<?= $p['id'] ?>" data-m3="<?= (float)$p['metros_cubicos'] ?>">
                <?= htmlspecialchars($p['ppu']) ?>
                <?php if ($p['metros_cubicos'] > 0): ?>(<?= number_format($p['metros_cubicos'],1,',','.') ?> m³)<?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Obra</label>
            <select name="obra_id" id="editObra" class="form-select">
              <option value="">— Sin obra —</option>
              <?php foreach ($obrasList as $o): ?>
              <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Destino *</label>
            <select name="destino_id" id="editDestino" class="form-select" required>
              <option value="">— Selecciona destino —</option>
              <?php foreach ($destinoList as $d): ?>
              <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label fw-semibold">Tipo de material *</label>
            <select name="tipo_material" id="editMaterial" class="form-select" required>
              <option value="">— Selecciona material —</option>
              <?php foreach ($MATERIALES as $key => $label): ?>
              <option value="<?= $key ?>"><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label fw-semibold">Observaciones</label>
            <textarea name="observaciones" id="editObs" class="form-control" rows="2"></textarea>
          </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-warning fw-bold"><i class="bi bi-save me-1"></i>Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal Crear Ticket -->
<div class="modal fade" id="modalCrearTicket" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-ticket-perforated me-2"></i>Nuevo Ticket de Despacho</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="accion" value="crear_ticket">
        <div class="modal-body row g-3">

          <!-- Fecha y hora -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">Fecha *</label>
            <input type="date" name="fecha" id="campoFecha" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Hora *</label>
            <input type="time" name="hora" id="campoHora" class="form-control" required>
          </div>

          <!-- PPU (pre-completado con el camión del conductor) -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">Camión PPU *</label>
            <?php if (empty($ppuList)): ?>
            <div class="alert alert-warning py-2 mb-0">Sin camiones disponibles.</div>
            <?php else: ?>
            <select name="ppu_id" class="form-select" required id="condPpuSel">
              <option value="">— Selecciona PPU —</option>
              <?php foreach ($ppuList as $p): ?>
              <option value="<?= $p['id'] ?>"
                data-m3="<?= (float)$p['metros_cubicos'] ?>"
                <?= ($cond['ppu_id'] && $cond['ppu_id'] == $p['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['ppu']) ?>
                <?php if ($p['metros_cubicos'] > 0): ?>(<?= number_format($p['metros_cubicos'],1,',','.') ?> m³)<?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
            <small class="text-muted" id="m3Info"></small>
            <?php endif; ?>
          </div>

          <!-- Obra (pre-completada con la obra del conductor) -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">Obra</label>
            <?php if (empty($obrasList)): ?>
            <input type="text" class="form-control" disabled placeholder="Sin obras registradas">
            <?php else: ?>
            <select name="obra_id" class="form-select">
              <option value="">— Sin obra asignada —</option>
              <?php foreach ($obrasList as $o): ?>
              <option value="<?= $o['id'] ?>"
                <?= ($cond['obra_id'] && $cond['obra_id'] == $o['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
          </div>

          <!-- Destino -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">Destino *</label>
            <?php if (empty($destinoList)): ?>
            <div class="alert alert-warning py-2 mb-0">Sin destinos disponibles. Contacte al administrador.</div>
            <?php else: ?>
            <select name="destino_id" class="form-select" required>
              <option value="">— Selecciona destino —</option>
              <?php foreach ($destinoList as $d): ?>
              <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
          </div>

          <!-- Tipo de material -->
          <div class="col-md-6">
            <label class="form-label fw-semibold">Tipo de material *</label>
            <select name="tipo_material" class="form-select" required>
              <option value="">— Selecciona material —</option>
              <?php foreach ($MATERIALES as $key => $label): ?>
              <option value="<?= $key ?>"><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Observaciones -->
          <div class="col-12">
            <label class="form-label fw-semibold">Observaciones</label>
            <textarea name="observaciones" class="form-control" rows="2" placeholder="Observaciones adicionales (opcional)..."></textarea>
          </div>

          <!-- Info conductor -->
          <div class="col-12">
            <div class="p-2 rounded bg-light border small text-muted">
              <i class="bi bi-person-badge me-1"></i>
              <strong>Conductor:</strong> <?= htmlspecialchars($cond['nombre']) ?>
              <?= $cond['rut'] ? ' · RUT: '.htmlspecialchars($cond['rut']) : '' ?>
            </div>
          </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary" <?= (empty($ppuList) || empty($destinoList)) ? 'disabled' : '' ?>>
            <i class="bi bi-save me-1"></i>Crear Ticket
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function filtrarTickets() {
  var val = document.getElementById('buscarTicket').value.toLowerCase();
  document.querySelectorAll('#tablaTickets tbody tr').forEach(function(row) {
    row.style.display = row.textContent.toLowerCase().includes(val) ? '' : 'none';
  });
}
/* Mostrar m³ al cambiar PPU */
document.getElementById('condPpuSel')?.addEventListener('change', function() {
  var opt = this.options[this.selectedIndex];
  var m3  = parseFloat(opt?.getAttribute('data-m3') || 0);
  var el  = document.getElementById('m3Info');
  if (el) el.textContent = m3 > 0 ? 'Capacidad: ' + m3.toFixed(1).replace('.',',') + ' m³' : '';
});
/* Abrir modal confirmación de envío */
function confirmarEnvio(ticketId, nombre, empresa) {
  document.getElementById('envTicketId').value = ticketId;
  document.getElementById('envNombre').textContent  = nombre  || 'Carchek';
  document.getElementById('envEmpresa').textContent = empresa || '';
  new bootstrap.Modal(document.getElementById('modalConfirmarEnvio')).show();
}

/* Abrir modal editar con datos del ticket */
function abrirEditar(t) {
  document.getElementById('editTicketId').value  = t.id;
  document.getElementById('editTicketNum').textContent = '#' + String(t.id).padStart(5,'0');
  document.getElementById('editFecha').value     = t.fecha    || '';
  document.getElementById('editHora').value      = (t.hora    || '').substring(0,5);
  document.getElementById('editPpu').value       = t.ppu_id   || '';
  document.getElementById('editObra').value      = t.obra_id  || '';
  document.getElementById('editDestino').value   = t.destino_id || '';
  document.getElementById('editMaterial').value  = t.tipo_material || '';
  document.getElementById('editObs').value       = t.observaciones || '';
  new bootstrap.Modal(document.getElementById('modalEditarTicket')).show();
}

/* Al abrir el modal: poner fecha y hora actual del dispositivo */
document.getElementById('modalCrearTicket')?.addEventListener('show.bs.modal', function() {
  var now = new Date();
  var yy  = now.getFullYear();
  var mo  = String(now.getMonth()+1).padStart(2,'0');
  var dd  = String(now.getDate()).padStart(2,'0');
  var hh  = String(now.getHours()).padStart(2,'0');
  var mm  = String(now.getMinutes()).padStart(2,'0');
  var campoFecha = document.getElementById('campoFecha');
  var campoHora  = document.getElementById('campoHora');
  if (campoFecha) campoFecha.value = yy+'-'+mo+'-'+dd;
  if (campoHora)  campoHora.value  = hh+':'+mm;
  document.getElementById('condPpuSel')?.dispatchEvent(new Event('change'));
});
</script>
</body>
</html>
