<?php
require_once 'config.php';
requireAuth();
requireModulo('despacho', $usuario, $pdo);

$esAdminDespacho = isDespachoAdmin($usuario, $pdo);
$rolDespacho     = getRolDespacho($usuario, $pdo);

$MATERIALES = [
  'tierra'        => 'Tierra',
  'integral'      => 'Integral',
  'bajo_6'        => 'Bajo 6',
  'bajo_4'        => 'Bajo 4',
  'bajo_3'        => 'Bajo 3',
  'bajo_2'        => 'Bajo 2',
  'base_chancada' => 'Base Chancada',
  'grava'         => 'Grava',
  'gravilla'      => 'Gravilla',
  'arena'         => 'Arena',
  'arena_tubo'    => 'Arena Tubo',
  'escombros'     => 'Escombros',
  'bolones'       => 'Bolones',
];

$CAMBIOS = ['Mañana (06:00–14:00)', 'Tarde (14:00–22:00)', 'Noche (22:00–06:00)'];

$msg = null; $msgTipo = 'success';

if (!empty($_GET['error']) && $_GET['error'] === 'sin_permiso') {
  $msg = 'No tienes permisos para acceder a esa sección.'; $msgTipo = 'danger';
}

/* ── ¿es receptor? ─── */
$esReceptor = false;
try {
  $stR = $pdo->prepare("SELECT COUNT(*) FROM despacho_receptores WHERE usuario_id = ?");
  $stR->execute([$usuario['id']]);
  $esReceptor = (int)$stR->fetchColumn() > 0;
} catch (Exception $e) {}

/* ── Datos para formulario ─── */
$ppuList       = $pdo->query("SELECT * FROM despacho_ppu WHERE activo=1 ORDER BY ppu")->fetchAll(PDO::FETCH_ASSOC);
$destinoList   = $pdo->query("SELECT * FROM despacho_destinos WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$obrasList     = [];
try { $obrasList = $pdo->query("SELECT id,codigo,nombre FROM obras WHERE estado='activa' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
$conductorList = [];
try { $conductorList = $pdo->query("
  SELECT dc.id, dc.nombre, dc.rut, dc.ppu_id, dc.obra_id,
         dp.ppu, dp.metros_cubicos, o.codigo AS obra_codigo, o.nombre AS obra_nombre
  FROM despacho_conductores dc
  LEFT JOIN despacho_ppu dp ON dp.id = dc.ppu_id
  LEFT JOIN obras o          ON o.id  = dc.obra_id
  WHERE dc.activo = 1 ORDER BY dc.nombre
")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}

/* ── Receptor asignado (para informar) ─── */
$receptores = [];
try {
  $receptores = $pdo->query("SELECT u.id, u.nombre FROM despacho_receptores dr JOIN usuarios u ON u.id=dr.usuario_id ORDER BY u.nombre")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ── POST ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';
  try {
    if ($accion === 'crear') {
      $recId = !empty($receptores) ? (int)$receptores[0]['id'] : null;

      /* Conductor */
      $condId  = (int)($_POST['conductor_id'] ?? 0);
      $condRow = null;
      foreach ($conductorList as $c) { if ((int)$c['id'] === $condId) { $condRow = $c; break; } }

      /* PPU: si hay conductor con PPU asignada, usar esa; sino usar la del form */
      $ppuId = $condRow && $condRow['ppu_id'] ? (int)$condRow['ppu_id'] : (int)($_POST['ppu_id'] ?? 0);
      $ppuRow = null;
      foreach ($ppuList as $p) { if ((int)$p['id'] === $ppuId) { $ppuRow = $p; break; } }

      /* Obra: si hay conductor con obra asignada, usar esa; sino la del form */
      $obraId = $condRow && $condRow['obra_id'] ? (int)$condRow['obra_id'] : (int)($_POST['obra_id'] ?? 0);
      $obraRow = null;
      foreach ($obrasList as $o) { if ((int)$o['id'] === $obraId) { $obraRow = $o; break; } }

      $destId = (int)($_POST['destino_id'] ?? 0);
      $destRow = null;
      foreach ($destinoList as $d) { if ((int)$d['id'] === $destId) { $destRow = $d; break; } }

      /* Salida (origen del carguío): selector de obras */
      $salidaId  = (int)($_POST['salida_id'] ?? 0);
      $salidaRow = null;
      foreach ($obrasList as $o) { if ((int)$o['id'] === $salidaId) { $salidaRow = $o; break; } }
      $salidaNombre = $salidaRow ? ($salidaRow['codigo'].' — '.$salidaRow['nombre']) : '';

      $token = bin2hex(random_bytes(16));
      $pdo->prepare("INSERT INTO tickets_despacho
        (fecha, hora, obra_id, obra_nombre, ppu_id, ppu, metros_cubicos, destino_id, destino,
         salida_id, salida_nombre,
         tipo_material, observaciones, estado, creado_por, creado_por_nombre, receptor_id, token,
         conductor_id, conductor_nombre, conductor_rut)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
          date('Y-m-d'),
          date('H:i:s'),
          $obraId ?: null, $obraRow ? $obraRow['codigo'].' — '.$obraRow['nombre'] : '',
          $ppuId ?: null, $ppuRow ? $ppuRow['ppu'] : '',
          $ppuRow ? (float)$ppuRow['metros_cubicos'] : 0,
          $destId ?: null, $destRow ? $destRow['nombre'] : '',
          $salidaId ?: null, $salidaNombre,
          $_POST['tipo_material'] ?? '',
          trim($_POST['observaciones'] ?? ''),
          'borrador',
          $usuario['id'], $usuario['nombre'],
          $recId, $token,
          $condRow ? (int)$condRow['id'] : null,
          $condRow ? $condRow['nombre'] : '',
          $condRow ? $condRow['rut']    : '',
        ]);
      $msg = 'Ticket creado correctamente.';
    } elseif ($accion === 'enviar') {
      $tid = (int)($_POST['ticket_id'] ?? 0);
      $pdo->prepare("UPDATE tickets_despacho SET estado='enviado', enviado_en=datetime('now','localtime') WHERE id=? AND creado_por=? AND estado='borrador'")
          ->execute([$tid, $usuario['id']]);

      // ── Notificar a TODOS los receptores internos asignados ──
      $ticket = $pdo->prepare("SELECT * FROM tickets_despacho WHERE id=?");
      $ticket->execute([$tid]);
      $trow = $ticket->fetch(PDO::FETCH_ASSOC);
      if ($trow) {
        // Receptores internos
        $recs = $pdo->query("SELECT dr.usuario_id FROM despacho_receptores dr WHERE dr.tipo='interno' OR dr.tipo IS NULL");
        foreach ($recs->fetchAll(PDO::FETCH_ASSOC) as $rec) {
          if ($rec['usuario_id']) {
            $pdo->prepare("INSERT INTO oc_notificaciones (oc_id, destinatario_id, enviado_por, contenido, leida)
              SELECT id, ?, ?, 'despacho_ticket', 0 FROM ordenes_compra LIMIT 1")
              ->execute([$rec['usuario_id'], $usuario['nombre']]);
            // Usamos tabla dedicada de despacho si existe, sino la general
          }
        }
        // Insertar en tabla despacho_notificaciones (nueva, ver abajo) si existe
        try {
          $pdo->prepare("INSERT INTO despacho_notificaciones (ticket_id, destinatario_tipo, destinatario_id, tipo_evento, leida) 
            SELECT id, 'receptor', dr.usuario_id, 'ticket_enviado', 0
            FROM tickets_despacho td, despacho_receptores dr
            WHERE td.id=? AND (dr.tipo='interno' OR dr.tipo IS NULL) AND dr.usuario_id IS NOT NULL")
            ->execute([$tid]);
        } catch (Exception $e2) {}
      }
      $msg = 'Ticket enviado al receptor.';
    } elseif ($accion === 'eliminar') {
      $tid = (int)($_POST['ticket_id'] ?? 0);
      $where = $esAdminDespacho ? "id=?" : "id=? AND creado_por=? AND estado='borrador'";
      $params = $esAdminDespacho ? [$tid] : [$tid, $usuario['id']];
      $pdo->prepare("DELETE FROM tickets_despacho WHERE $where")->execute($params);
      $msg = 'Ticket eliminado.';
    }
  } catch (Exception $e) { $msg = 'Error: '.$e->getMessage(); $msgTipo = 'danger'; }
  header("Location: tickets_despacho.php?msg=".urlencode($msg)."&mt=$msgTipo"); exit;
}

if (!empty($_GET['msg'])) { $msg = $_GET['msg']; $msgTipo = $_GET['mt'] ?? 'success'; }

/* ── Filtros ─── */
$fDesde   = trim($_GET['f_desde']   ?? '');
$fHasta   = trim($_GET['f_hasta']   ?? '');
$fEstado  = trim($_GET['f_estado']  ?? '');
$fPpu     = trim($_GET['f_ppu']     ?? '');
$fCond    = trim($_GET['f_cond']    ?? '');
$fObra    = trim($_GET['f_obra']    ?? '');

$where = [];
$params = [];
if (!$esAdminDespacho) {
  if ($esReceptor) {
    $where[] = "(creado_por = ? OR (receptor_id = ? AND estado IN ('enviado','validado')))";
    $params[] = $usuario['id']; $params[] = $usuario['id'];
  } else {
    $where[] = "creado_por = ?";
    $params[] = $usuario['id'];
  }
}
if ($fDesde !== '')  { $where[] = "fecha >= ?"; $params[] = $fDesde; }
if ($fHasta !== '')  { $where[] = "fecha <= ?"; $params[] = $fHasta; }
if ($fEstado !== '') { $where[] = "estado = ?"; $params[] = $fEstado; }
if ($fPpu !== '')    { $where[] = "UPPER(ppu) LIKE ?"; $params[] = '%'.strtoupper($fPpu).'%'; }
if ($fCond !== '')   { $where[] = "UPPER(conductor_nombre) LIKE ?"; $params[] = '%'.strtoupper($fCond).'%'; }
if ($fObra !== '')   { $where[] = "UPPER(obra_nombre) LIKE ?"; $params[] = '%'.strtoupper($fObra).'%'; }

$sqlWhere = $where ? 'WHERE '.implode(' AND ', $where) : '';
$st = $pdo->prepare("SELECT * FROM tickets_despacho $sqlWhere ORDER BY creado_en DESC");
$st->execute($params);
$propios = $st->fetchAll(PDO::FETCH_ASSOC);

$hayFiltros = ($fDesde !== '' || $fHasta !== '' || $fEstado !== '' || $fPpu !== '' || $fCond !== '' || $fObra !== '');

require_once 'includes/header.php';
?>
<a href="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver atrás</a>
<?php

function badgeEstadoTicket($e, $revision = null) {
  // Si fue validado por Carchek (estado_revision) mostrarlo con prioridad
  if ($revision === 'aprobado') {
    return '<span class="badge text-white" style="background:#16a34a"><i class="bi bi-patch-check-fill me-1"></i>Validado Carchek</span>';
  }
  if ($revision === 'rechazado') {
    return '<span class="badge bg-danger"><i class="bi bi-x-circle-fill me-1"></i>Rechazado Carchek</span>';
  }
  if ($revision === 'con_observaciones') {
    return '<span class="badge bg-info text-dark"><i class="bi bi-exclamation-circle-fill me-1"></i>Con observaciones</span>';
  }
  // Estado interno del sistema
  $map = [
    'borrador' => ['secondary','Borrador','pencil'],
    'enviado'  => ['warning','Enviado','send-fill'],
    'validado' => ['success','Validado','check-circle-fill'],
  ];
  $m = $map[$e] ?? ['light','—','circle'];
  return '<span class="badge bg-'.$m[0].'"><i class="bi bi-'.$m[2].' me-1"></i>'.$m[1].'</span>';
}
?>

<style>
/* ── Rol badge ── */
.despacho-role-badge { font-size:.72rem; padding:.25rem .55rem; border-radius:999px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; }

/* ── Cabecera móvil ── */
.desp-topbar { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:12px; }
.desp-topbar-title { font-size:1.1rem; font-weight:800; color:#1a1a2e; display:flex; align-items:center; gap:8px; }

/* ── Botón Nuevo Ticket — grande, ancho completo en móvil ── */
.btn-nuevo-ticket {
  background: linear-gradient(135deg,#1b2838 0%,#374151 100%);
  color:#fff; border:none; border-radius:14px;
  padding:14px 20px; font-size:1.05rem; font-weight:700;
  display:flex; align-items:center; justify-content:center; gap:10px;
  width:100%; margin-bottom:14px;
  box-shadow:0 4px 16px rgba(27,40,56,.25);
  transition:transform .15s, box-shadow .15s;
}
.btn-nuevo-ticket:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(27,40,56,.35); color:#fff; }
.btn-nuevo-ticket i { font-size:1.3rem; }

/* ── Info usuario colapsable ── */
.desp-user-toggle {
  display:flex; align-items:center; justify-content:space-between;
  background:#f1f3f5; border-radius:10px; padding:10px 14px;
  cursor:pointer; border:none; width:100%; margin-bottom:12px;
  font-size:.9rem; font-weight:600; color:#374151;
  transition:background .15s;
}
.desp-user-toggle:hover { background:#e9ecef; }
.desp-user-toggle .chevron { transition:transform .2s; }
.desp-user-toggle.open .chevron { transform:rotate(180deg); }
.desp-user-info { display:none; background:#f8f9fa; border:1px solid #dee2e6; border-radius:0 0 10px 10px; padding:12px 14px; margin-top:-12px; margin-bottom:12px; }
.desp-user-info.open { display:block; }
.desp-user-info dl { margin:0; display:grid; grid-template-columns:auto 1fr; gap:4px 12px; font-size:.85rem; }
.desp-user-info dt { color:#6c757d; font-weight:600; white-space:nowrap; }
.desp-user-info dd { margin:0; color:#212529; }

/* ── Barra de búsqueda flotante ── */
.desp-search-bar { position:relative; margin-bottom:14px; }
.desp-search-bar input { border-radius:10px; padding-left:38px; font-size:.9rem; border-color:#dee2e6; }
.desp-search-bar .bi-search { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:.95rem; }

/* ── Grilla de obras (cuadros) ── */
.obras-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}
.obras-grid .grupo-despacho.expandido {
  grid-column: 1 / -1;   /* el grupo abierto ocupa toda la fila para mostrar la tabla */
}
.grupo-despacho.cuadro { background:#fff; position:relative; overflow: visible !important; }
.grupo-despacho.cuadro .grupo-header { padding:14px 14px; }
.grupo-despacho.cuadro .grupo-body { max-height: 480px; overflow:auto; }
.grupo-despacho.cuadro.tiene-notif { box-shadow: 0 0 0 2px #dc2626, 0 4px 12px rgba(220,38,38,.18); }
.grupo-despacho.cuadro.tiene-borrador { box-shadow: 0 0 0 2px #6b7280, 0 4px 8px rgba(0,0,0,.08); }
.obra-notif-badge {
  position: absolute;
  top: -10px; right: -10px;
  min-width: 32px; height: 32px;
  padding: 0 8px;
  background: #dc2626;
  color: #fff;
  border-radius: 16px;
  display: inline-flex !important; align-items: center; justify-content: center;
  font-weight: 800; font-size: .9rem;
  box-shadow: 0 2px 10px rgba(220,38,38,.6);
  border: 3px solid #fff;
  animation: pulseNotif 1.6s ease-in-out infinite;
  z-index: 10;
  pointer-events: none;
}
@keyframes pulseNotif {
  0%,100% { transform: scale(1); }
  50%     { transform: scale(1.12); }
}

/* ── Acordeón grupos ── */
.grupo-despacho { border:1px solid #dee2e6; border-radius:12px; overflow:hidden; margin-bottom:10px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
.grupo-header {
  display:flex; align-items:center; gap:10px; flex-wrap:wrap;
  background:#fff; padding:12px 14px; cursor:pointer;
  transition:background .15s; border:none; width:100%; text-align:left;
}
.grupo-header:hover { background:#f8f9fa; }
.grupo-header .g-ppu { font-family:monospace; font-size:1.05rem; font-weight:900; background:#1b2838; color:#fff; padding:4px 11px; border-radius:7px; letter-spacing:2px; }
.grupo-header .g-cond { font-weight:700; font-size:.9rem; color:#212529; }
.grupo-header .g-obra { font-size:.78rem; color:#6c757d; }
.grupo-header .g-count { margin-left:auto; display:flex; gap:5px; align-items:center; flex-wrap:wrap; }
.grupo-body { border-top:1px solid #dee2e6; background:#fdfdfd; display:none; }
.grupo-body.open { display:block; }
.grupo-body table { margin:0; }
.grupo-body td, .grupo-body th { padding:8px 10px; font-size:.82rem; }
.g-chevron { transition:transform .2s; font-size:.85rem; color:#adb5bd; }
.grupo-header.active .g-chevron { transform:rotate(180deg); }
tr.est-enviado { background:#fff8e1; }
tr.est-validado { background:#f0fff4; }
tr.rev-aprobado { background:#dcfce7 !important; }
tr.rev-rechazado { background:#fee2e2 !important; }
tr.rev-observacion { background:#fef3c7 !important; }
.ticket-card.rev-aprobado { border-left-color:#16a34a !important; background:#f0fdf4; }
.ticket-card.rev-rechazado { border-left-color:#dc2626 !important; background:#fef2f2; }
.ticket-card.rev-observacion { border-left-color:#d97706 !important; background:#fffbeb; }

/* ── Botón Enviar a Carchek — prominente ── */
.btn-enviar-carchek {
  display:flex; align-items:center; justify-content:center; gap:8px;
  background:linear-gradient(135deg,#d97706 0%,#b45309 100%);
  color:#fff; border:none; border-radius:10px;
  padding:10px 18px; font-size:.92rem; font-weight:700;
  width:100%; margin-top:6px;
  box-shadow:0 3px 10px rgba(180,83,9,.3);
  transition:transform .15s, box-shadow .15s;
}
.btn-enviar-carchek:hover { transform:translateY(-1px); box-shadow:0 5px 16px rgba(180,83,9,.4); color:#fff; }
.btn-enviar-carchek i { font-size:1.1rem; }

/* ── Tarjeta ticket en móvil (en vez de tabla) ── */
@media (max-width:600px) {
  .tabla-tickets-desktop { display:none !important; }
  .cards-tickets-mobile { display:block !important; }
  .btn-nuevo-ticket { font-size:.97rem; padding:13px 16px; }
}
@media (min-width:601px) {
  .cards-tickets-mobile { display:none !important; }
  .tabla-tickets-desktop { display:block !important; }
}
.ticket-card {
  background:#fff; border:1px solid #e5e7eb; border-radius:12px;
  padding:12px 14px; margin-bottom:8px;
  box-shadow:0 1px 4px rgba(0,0,0,.06);
}
.ticket-card.est-enviado { border-left:4px solid #f59e0b; }
.ticket-card.est-validado { border-left:4px solid #22c55e; }
.ticket-card.est-borrador { border-left:4px solid #9ca3af; }
.ticket-card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }
.ticket-card-body { font-size:.83rem; color:#4b5563; }
.ticket-card-actions { display:flex; gap:8px; margin-top:10px; }
.ticket-card-actions .btn { flex:1; font-size:.82rem; }
</style>

<!-- ══ CABECERA ══ -->
<div class="desp-topbar">
  <div class="desp-topbar-title">
    <i class="bi bi-truck-front-fill text-secondary"></i>
    Despacho
    <?php if ($esAdminDespacho): ?>
    <span class="despacho-role-badge bg-dark text-white">Admin</span>
    <?php elseif ($esReceptor): ?>
    <span class="despacho-role-badge bg-success text-white">Receptor</span>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <!-- Campana notificaciones -->
    <div class="position-relative">
      <button id="despacho-notif-btn" class="btn btn-outline-warning btn-sm position-relative" title="Notificaciones">
        <i class="bi bi-bell-fill"></i>
        <span id="despacho-notif-count" style="display:none;position:absolute;top:-5px;right:-5px;background:#dc3545;color:#fff;font-size:10px;font-weight:700;min-width:18px;height:18px;border-radius:9px;display:none;align-items:center;justify-content:center;padding:0 3px">0</span>
      </button>
      <div id="despacho-notif-panel" style="display:none;position:absolute;top:calc(100% + 8px);right:0;width:min(360px,92vw);background:#fff;border:1px solid rgba(0,0,0,.12);border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.15);z-index:9999;overflow:hidden">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#1a1a2e;color:#fff">
          <strong style="font-size:13px"><span class="realtime-dot"></span> Tiempo real</strong>
          <a href="tickets_despacho.php" style="color:#fbbf24;font-size:12px;text-decoration:none">Ver todos →</a>
        </div>
        <div id="despacho-notif-lista" style="max-height:300px;overflow-y:auto"></div>
      </div>
    </div>
    <?php if ($esAdminDespacho): ?>
    <a href="tickets_despacho_admin.php" class="btn btn-dark btn-sm"><i class="bi bi-gear"></i></a>
    <a href="asignar_camion.php" class="btn btn-warning btn-sm fw-bold" title="Asignar / reasignar camiones">
      <i class="bi bi-arrow-left-right me-1"></i>Camiones
    </a>
    <?php endif; ?>
  </div>
</div>

<?php /* Botón "Nuevo Ticket" eliminado — la creación de tickets queda en el portal del conductor / administrador designado. */ ?>

<!-- ══ INFO USUARIO — COLAPSABLE ══ -->
<button class="desp-user-toggle" onclick="toggleUserInfo(this)">
  <span><i class="bi bi-person-circle me-2"></i><?= htmlspecialchars($usuario['nombre']) ?> — <?= htmlspecialchars($usuario['cargo'] ?? $usuario['rol']) ?></span>
  <i class="bi bi-chevron-down chevron"></i>
</button>
<div class="desp-user-info" id="userInfoPanel">
  <dl>
    <dt>Usuario</dt><dd><?= htmlspecialchars($usuario['usuario'] ?? '—') ?></dd>
    <dt>Rol</dt><dd><?= htmlspecialchars($usuario['rol']) ?></dd>
    <dt>Cargo</dt><dd><?= htmlspecialchars($usuario['cargo'] ?? '—') ?></dd>
    <?php if ($esReceptor): ?><dt>Función</dt><dd>Receptor / Validador de tickets</dd><?php endif; ?>
    <?php if ($esAdminDespacho): ?><dt>Función</dt><dd>Administrador del módulo despacho</dd><?php endif; ?>
  </dl>
</div>

<!-- ══ BÚSQUEDA — reubicada acá ══ -->
<div class="desp-search-bar">
  <i class="bi bi-search"></i>
  <input type="text" id="buscadorTickets" class="form-control" placeholder="Buscar por PPU, conductor, material, obra…" oninput="filtrarTickets(this.value)">
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if (empty($receptores) && $esAdminDespacho): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>No hay un receptor/validador asignado. <a href="tickets_despacho_admin.php#receptores">Configúralo aquí</a>.</div>
<?php endif; ?>


<!-- Encabezado lista única -->
<h5 class="fw-bold mb-3 d-flex align-items-center gap-2 flex-wrap">
  <i class="bi bi-file-earmark-text"></i>
  <?= $esAdminDespacho ? 'Todos los Tickets' : 'Mis Tickets' ?>
  <span class="badge bg-secondary"><?= count($propios) ?></span>
  <?php
    $cntEnv = 0;
    foreach ($propios as $_t) { if ($_t['estado'] === 'enviado') $cntEnv++; }
    if ($cntEnv > 0):
  ?>
  <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i><?= $cntEnv ?> pendiente<?= $cntEnv > 1 ? 's' : '' ?> de validación</span>
  <?php endif; ?>
  <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" data-bs-toggle="collapse" data-bs-target="#filtrosTickets" aria-expanded="<?= $hayFiltros ? 'true' : 'false' ?>">
    <i class="bi bi-funnel me-1"></i>Filtros<?= $hayFiltros ? ' <span class="badge bg-warning text-dark ms-1">activos</span>' : '' ?>
  </button>
</h5>

<!-- Filtros + exportar -->
<div class="collapse <?= $hayFiltros ? 'show' : '' ?> mb-3" id="filtrosTickets">
  <form method="GET" class="card card-body bg-light border" id="formFiltrosTickets">
    <div class="row g-2 align-items-end">
      <div class="col-md-2 col-6">
        <label class="form-label small mb-1">Desde</label>
        <input type="date" name="f_desde" class="form-control form-control-sm" value="<?= htmlspecialchars($fDesde) ?>">
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label small mb-1">Hasta</label>
        <input type="date" name="f_hasta" class="form-control form-control-sm" value="<?= htmlspecialchars($fHasta) ?>">
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label small mb-1">Estado</label>
        <select name="f_estado" class="form-select form-select-sm">
          <option value="">— Todos —</option>
          <option value="borrador" <?= $fEstado==='borrador'?'selected':'' ?>>Borrador</option>
          <option value="enviado"  <?= $fEstado==='enviado' ?'selected':'' ?>>Enviado</option>
          <option value="validado" <?= $fEstado==='validado'?'selected':'' ?>>Validado</option>
        </select>
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label small mb-1">PPU</label>
        <input type="text" name="f_ppu" class="form-control form-control-sm" placeholder="Ej. KU4332" value="<?= htmlspecialchars($fPpu) ?>">
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label small mb-1">Conductor</label>
        <input type="text" name="f_cond" class="form-control form-control-sm" value="<?= htmlspecialchars($fCond) ?>">
      </div>
      <div class="col-md-2 col-6">
        <label class="form-label small mb-1">Obra</label>
        <input type="text" name="f_obra" class="form-control form-control-sm" value="<?= htmlspecialchars($fObra) ?>">
      </div>
    </div>
    <div class="d-flex gap-2 mt-3 flex-wrap">
      <button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-funnel-fill me-1"></i>Aplicar filtros</button>
      <?php if ($hayFiltros): ?>
      <a href="tickets_despacho.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Limpiar</a>
      <?php endif; ?>
      <button type="submit" formaction="tickets_despacho_export.php" formtarget="_blank" class="btn btn-sm btn-danger ms-auto">
        <i class="bi bi-file-earmark-pdf-fill me-1"></i>Exportar PDF (<?= count($propios) ?>)
      </button>
      <button type="button" id="btnPdfSeleccion" disabled class="btn btn-sm btn-outline-danger" style="opacity:.55">
        <i class="bi bi-check2-square me-1"></i>PDF · <span id="cntSeleccion">0</span> seleccionados
      </button>
    </div>
  </form>
</div>

<div class="tab-content">
  <!-- TICKETS — agrupados por PPU + Conductor + Obra -->
  <div id="tabMios">
    <?php if (empty($propios)): ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-truck-front fs-1 d-block mb-2 opacity-50"></i>
      <?= $esAdminDespacho ? 'Aún no hay tickets en el sistema.' : 'Aún no tienes tickets. Crea uno con el botón <em>Nuevo Ticket</em>.' ?>
    </div>
    <?php else:
      /* Agrupar por OBRA (obra_id + obra_nombre) — los tickets dentro se ordenan por fecha desc */
      $grupos = [];
      foreach ($propios as $t) {
        $oid = (int)($t['obra_id'] ?? 0);
        $oNom = $t['obra_nombre'] ?? '';
        $key = $oid > 0 ? 'O'.$oid : 'X_'.($oNom ?: 'Sin obra');
        if (!isset($grupos[$key])) {
          $grupos[$key] = [
            'obra_id'     => $oid,
            'obra_nombre' => $oNom ?: 'Sin obra asignada',
            'm3'          => 0,
            'tickets'     => [],
            'ultima'      => '',
          ];
        }
        $grupos[$key]['tickets'][] = $t;
        $grupos[$key]['m3'] += (float)($t['metros_cubicos'] ?? 0);
        if ($t['fecha'] > $grupos[$key]['ultima']) $grupos[$key]['ultima'] = $t['fecha'];
      }
      // Ordenar grupos por última fecha de ticket (más reciente primero)
      uasort($grupos, function($a, $b){ return strcmp($b['ultima'], $a['ultima']); });
      $gi = 0;
      $primerGrupoAbierto = true;
    ?>
    <div class="obras-grid">
    <?php foreach ($grupos as $gkey => $grupo): $gi++;
      $total   = count($grupo['tickets']);
      $pend=0; $valid=0; $borrdr=0;
      foreach($grupo['tickets'] as $_gx){ if($_gx['estado']==='enviado') $pend++; if($_gx['estado']==='validado') $valid++; if($_gx['estado']==='borrador') $borrdr++; }
      // Por defecto todas cerradas en modo grilla — el usuario abre la que quiera
      $abierto = false;
      // Ordenar tickets internos por fecha desc, luego hora desc
      usort($grupo['tickets'], function($a,$b){
        $r = strcmp($b['fecha'].' '.($b['hora']??''), $a['fecha'].' '.($a['hora']??''));
        return $r;
      });
    ?>
    <?php
      $notifClass = '';
      if ($pend > 0)        $notifClass = ' tiene-notif';
      elseif ($borrdr > 0)  $notifClass = ' tiene-borrador';
    ?>
    <div class="grupo-despacho cuadro<?= $notifClass ?><?= $abierto ? ' expandido' : '' ?>" id="grupo<?= $gi ?>">
      <?php if ($pend > 0): ?>
      <span class="obra-notif-badge" title="<?= $pend ?> ticket(s) pendiente(s) de validación"><i class="bi bi-bell-fill me-1" style="font-size:.7rem"></i><?= $pend ?></span>
      <?php elseif ($borrdr > 0): ?>
      <span class="obra-notif-badge" style="background:#6b7280;animation:none" title="<?= $borrdr ?> borrador(es) por enviar"><?= $borrdr ?></span>
      <?php endif; ?>
      <button class="grupo-header <?= $abierto ? 'active' : '' ?>" onclick="toggleGrupoCuadro(this, 'gBody<?= $gi ?>', 'grupo<?= $gi ?>')">
        <?php if ($grupo['obra_id']): ?>
        <span class="g-ppu" style="background:#0d6efd;color:#fff;font-size:.78rem;letter-spacing:.5px" title="ID Obra"><i class="bi bi-hash me-1"></i>ID <?= $grupo['obra_id'] ?></span>
        <?php else: ?>
        <span class="g-ppu" style="background:#6b7280;color:#fff;font-size:.78rem"><i class="bi bi-question-circle me-1"></i>S/Obra</span>
        <?php endif; ?>
        <div>
          <div class="g-cond" style="font-weight:700"><i class="bi bi-building me-1 text-warning"></i><?= htmlspecialchars($grupo['obra_nombre']) ?></div>
          <?php if ($grupo['m3'] > 0): ?>
          <div class="g-obra"><i class="bi bi-truck me-1"></i><?= number_format($grupo['m3'],1,',','.') ?> m³ totales · <?= count($grupo['tickets']) ?> ticket(s)</div>
          <?php endif; ?>
        </div>
        <div class="g-count">
          <?php if ($borrdr > 0): ?><span class="badge bg-secondary"><?= $borrdr ?> borrador<?= $borrdr>1?'es':'' ?></span><?php endif; ?>
          <?php if ($pend > 0): ?><span class="badge bg-warning text-dark"><?= $pend ?> pendiente<?= $pend>1?'s':'' ?></span><?php endif; ?>
          <?php if ($valid > 0): ?><span class="badge bg-success"><?= $valid ?> validado<?= $valid>1?'s':'' ?></span><?php endif; ?>
          <span class="badge bg-light text-dark border"><?= $total ?> total</span>
          <i class="bi bi-chevron-<?= $abierto ? 'up' : 'down' ?> g-chevron ms-1"></i>
        </div>
      </button>
      <div class="grupo-body <?= $abierto ? 'open' : '' ?>" id="gBody<?= $gi ?>">
        <!-- ESCRITORIO: tabla -->
        <div class="tabla-tickets-desktop table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>#</th><th>Fecha</th><th>Hora</th><th>PPU</th><th>Conductor</th>
                <th>Destino</th><th>Material</th><th class="text-end">m³</th><th>Estado</th>
                <th class="text-end">Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($grupo['tickets'] as $t): ?>
            <tr class="est-<?= $t['estado'] ?><?= !empty($t['estado_revision']) ? ' rev-'.$t['estado_revision'] : '' ?>">
              <td><label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0"><input type="checkbox" class="chk-tk" data-id="<?= (int)$t['id'] ?>"> <small class="text-muted">#<?= str_pad($t['id'],4,'0',STR_PAD_LEFT) ?></small></label></td>
              <td><small><?= htmlspecialchars(date('d-m-Y', strtotime($t['fecha']))) ?></small></td>
              <td><small class="text-muted"><?= htmlspecialchars(substr($t['hora'],0,5)) ?></small></td>
              <td><span class="badge bg-dark" style="font-family:monospace;letter-spacing:.5px"><?= htmlspecialchars($t['ppu'] ?: '—') ?></span></td>
              <td><small><?= htmlspecialchars($t['conductor_nombre'] ?: '—') ?></small></td>
              <td><small><?= htmlspecialchars($t['destino'] ?: '—') ?></small></td>
              <td><span class="badge bg-secondary"><?= htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) ?></span></td>
              <td class="text-end"><small><?= $t['metros_cubicos'] > 0 ? number_format((float)$t['metros_cubicos'],1,',','.') : '—' ?></small></td>
              <td><?= badgeEstadoTicket($t['estado'], $t['estado_revision'] ?? null) ?></td>
              <td class="text-end">
                <a href="tickets_despacho_ver.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                <?php if ($t['estado'] === 'borrador' && ((int)$t['creado_por'] === (int)$usuario['id'] || $esAdminDespacho)): ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="accion" value="enviar">
                  <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                  <button class="btn btn-sm btn-warning fw-bold" onclick="return confirm('¿Enviar a Carchek?')">
                    <i class="bi bi-send-fill me-1"></i>Carchek
                  </button>
                </form>
                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                  <input type="hidden" name="accion" value="eliminar">
                  <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
                <?php if ($t['estado'] === 'enviado' && ($esAdminDespacho || (int)$t['receptor_id'] === (int)$usuario['id'])): ?>
                <a href="validar_ticket.php?t=<?= urlencode($t['token']) ?>" class="btn btn-sm btn-success">
                  <i class="bi bi-qr-code-scan me-1"></i>Validar
                </a>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- MÓVIL: tarjetas -->
        <div class="cards-tickets-mobile p-2">
          <?php foreach ($grupo['tickets'] as $t): ?>
          <div class="ticket-card est-<?= $t['estado'] ?><?= !empty($t['estado_revision']) ? ' rev-'.$t['estado_revision'] : '' ?>">
            <div class="ticket-card-header">
              <div>
                <span class="fw-bold" style="font-size:.93rem">#<?= str_pad($t['id'],4,'0',STR_PAD_LEFT) ?></span>
                <small class="text-muted ms-2"><?= htmlspecialchars($t['fecha']) ?> <?= htmlspecialchars($t['hora']) ?></small>
              </div>
              <?= badgeEstadoTicket($t['estado'], $t['estado_revision'] ?? null) ?>
            </div>
            <div class="ticket-card-body">
              <span class="badge bg-dark me-1"><?= htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) ?></span>
              <?php if ($t['destino']): ?><span class="text-muted small">→ <?= htmlspecialchars($t['destino']) ?></span><?php endif; ?>
              <?php if ($t['observaciones']): ?><div class="text-muted mt-1" style="font-size:.8rem"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($t['observaciones']) ?></div><?php endif; ?>
              <?php if ($esAdminDespacho && $t['creado_por_nombre']): ?><div class="text-muted mt-1" style="font-size:.8rem"><i class="bi bi-person me-1"></i><?= htmlspecialchars($t['creado_por_nombre']) ?></div><?php endif; ?>
            </div>
            <div class="ticket-card-actions">
              <a href="tickets_despacho_ver.php?id=<?= $t['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-eye me-1"></i>Ver</a>
              <?php if ($t['estado'] === 'borrador' && ((int)$t['creado_por'] === (int)$usuario['id'] || $esAdminDespacho)): ?>
              <form method="POST" style="flex:2">
                <input type="hidden" name="accion" value="enviar">
                <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                <button class="btn-enviar-carchek" onclick="return confirm('¿Enviar a Carchek?')">
                  <i class="bi bi-send-fill"></i> Enviar a Carchek
                </button>
              </form>
              <form method="POST" onsubmit="return confirm('¿Eliminar?')">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
              </form>
              <?php elseif ($t['estado'] === 'enviado'): ?>
                <?php if ($esAdminDespacho || (int)$t['receptor_id'] === (int)$usuario['id']): ?>
                <a href="validar_ticket.php?t=<?= urlencode($t['token']) ?>" class="btn btn-success btn-sm w-100 fw-bold"><i class="bi bi-qr-code-scan me-1"></i>Validar Ticket</a>
                <?php else: ?>
                <span class="btn btn-warning btn-sm disabled w-100 fw-bold"><i class="bi bi-hourglass-split me-1"></i>Enviado a Carchek</span>
                <?php endif; ?>
              <?php elseif ($t['estado'] === 'validado'): ?>
              <span class="btn btn-success btn-sm disabled w-100"><i class="bi bi-check-circle me-1"></i>Validado</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div><!-- /.obras-grid -->
    <?php endif; ?>
  </div>

</div>

<!-- MODAL NUEVO TICKET -->
<div class="modal fade" id="modalNuevoTicket" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-secondary text-white">
        <h5 class="modal-title"><i class="bi bi-truck-front-fill me-2"></i>Nuevo Ticket de Despacho</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="accion" value="crear">
        <div class="modal-body">
          <?php
          /* Serializar conductores para JS auto-relleno */
          $condJS = json_encode(array_column(
            array_map(function($c) { return [
              'id'          => $c['id'],
              'nombre'      => $c['nombre'],
              'rut'         => $c['rut'],
              'ppu_id'      => $c['ppu_id'],
              'ppu'         => $c['ppu'],
              'metros_cubicos' => $c['metros_cubicos'],
              'obra_id'     => $c['obra_id'],
              'obra_codigo' => $c['obra_codigo'],
              'obra_nombre' => $c['obra_nombre'],
            ]; }, $conductorList),
            null, 'id'
          ));
          ?>
          <div class="row g-3">
            <!-- Fecha y hora se asignan automáticamente al crear -->
            <!-- CONDUCTOR -->
            <div class="col-md-12">
              <label class="form-label fw-semibold"><i class="bi bi-person-badge me-1"></i>Conductor</label>
              <?php if (empty($conductorList)): ?>
              <div class="alert alert-info py-2 mb-0"><i class="bi bi-info-circle me-1"></i>Sin conductores registrados. <?= $esAdminDespacho ? '<a href="tickets_despacho_admin.php">Agregar en configuración</a>.' : 'Contacte al admin del módulo.' ?></div>
              <?php else: ?>
              <select name="conductor_id" id="selConductor" class="form-select">
                <option value="">— Sin conductor —</option>
                <?php foreach ($conductorList as $c): ?>
                <option value="<?= $c['id'] ?>"
                  data-ppu-id="<?= (int)$c['ppu_id'] ?>"
                  data-obra-id="<?= (int)$c['obra_id'] ?>"
                  data-m3="<?= (float)$c['metros_cubicos'] ?>">
                  <?= htmlspecialchars($c['nombre']) ?>
                  <?= $c['rut'] ? ' — '.htmlspecialchars($c['rut']) : '' ?>
                  <?= $c['ppu'] ? ' | '.htmlspecialchars($c['ppu']) : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted mt-1 d-block" id="condInfo"></small>
              <?php endif; ?>
            </div>

            <!-- PPU -->
            <div class="col-md-5">
              <label class="form-label fw-semibold">PPU (Camión)</label>
              <?php if (empty($ppuList)): ?>
              <div class="alert alert-warning py-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Sin camiones configurados.</div>
              <?php else: ?>
              <select name="ppu_id" id="selPPU" class="form-select">
                <option value="">— Auto por conductor —</option>
                <?php foreach ($ppuList as $p): ?>
                <option value="<?= $p['id'] ?>" data-m3="<?= (float)$p['metros_cubicos'] ?>">
                  <?= htmlspecialchars($p['ppu']) ?><?= $p['descripcion'] ? ' — '.$p['descripcion'] : '' ?> (<?= number_format($p['metros_cubicos'],1,',','.').' m³' ?>)
                </option>
                <?php endforeach; ?>
              </select>
              <small class="text-muted" id="m3Info"></small>
              <?php endif; ?>
            </div>

            <!-- SALIDA (origen del carguío) -->
            <div class="col-md-4">
              <label class="form-label fw-semibold">Salida <small class="text-muted">(origen)</small></label>
              <select name="salida_id" id="selSalida" class="form-select">
                <option value="">— Sin especificar —</option>
                <?php foreach ($obrasList as $o): ?>
                <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- OBRA -->
            <div class="col-md-4">
              <label class="form-label fw-semibold">Obra <small class="text-muted">(destino)</small></label>
              <select name="obra_id" id="selObra" class="form-select">
                <option value="">— Auto por conductor —</option>
                <?php foreach ($obrasList as $o): ?>
                <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- DESTINO -->
            <div class="col-md-3">
              <label class="form-label fw-semibold">Destino</label>
              <?php if (empty($destinoList)): ?>
              <p class="form-control-plaintext text-muted small">Sin destinos.</p>
              <?php else: ?>
              <select name="destino_id" class="form-select">
                <option value="">— Sin destino —</option>
                <?php foreach ($destinoList as $d): ?>
                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php endif; ?>
            </div>

            <!-- MATERIAL -->
            <div class="col-md-6">
              <label class="form-label fw-semibold">Material *</label>
              <select name="tipo_material" class="form-select" required>
                <option value="">— Seleccionar —</option>
                <?php foreach ($MATERIALES as $k => $v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Observaciones</label>
              <input type="text" name="observaciones" class="form-control" placeholder="Opcional...">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-dark" <?= empty($ppuList) ? 'disabled' : '' ?>><i class="bi bi-save me-1"></i>Guardar Ticket</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
/* Acordeón grupos */
function toggleGrupo(btn, bodyId) {
  var body = document.getElementById(bodyId);
  if (!body) return;
  var isOpen = body.classList.contains('open');
  body.classList.toggle('open', !isOpen);
  btn.classList.toggle('active', !isOpen);
}
/* Acordeón grupos en modo grilla (cuadro) — el grupo abierto ocupa toda la fila */
function toggleGrupoCuadro(btn, bodyId, grupoId) {
  var body = document.getElementById(bodyId);
  var grupo = document.getElementById(grupoId);
  if (!body || !grupo) return;
  var isOpen = body.classList.contains('open');
  body.classList.toggle('open', !isOpen);
  btn.classList.toggle('active', !isOpen);
  grupo.classList.toggle('expandido', !isOpen);
}

/* Toggle info usuario */
function toggleUserInfo(btn) {
  btn.classList.toggle('open');
  var panel = document.getElementById('userInfoPanel');
  if (panel) panel.classList.toggle('open');
}

/* Buscador de tickets */
function filtrarTickets(q) {
  q = q.toLowerCase().trim();
  var grupos = document.querySelectorAll('.grupo-despacho');
  grupos.forEach(function(g) {
    var cards = g.querySelectorAll('.ticket-card');
    var filas = g.querySelectorAll('tbody tr');
    var visiblesCards = 0, visiblesFilas = 0;
    cards.forEach(function(c) {
      var txt = (c.dataset.buscar || c.textContent).toLowerCase();
      var ok = !q || txt.includes(q);
      c.style.display = ok ? '' : 'none';
      if (ok) visiblesCards++;
    });
    filas.forEach(function(r) {
      var txt = r.textContent.toLowerCase();
      var ok = !q || txt.includes(q);
      r.style.display = ok ? '' : 'none';
      if (ok) visiblesFilas++;
    });
    g.style.display = (visiblesCards + visiblesFilas === 0 && q) ? 'none' : '';
    // Abrir grupo si hay resultados
    if (q && visiblesCards + visiblesFilas > 0) {
      var body = g.querySelector('.grupo-body');
      var header = g.querySelector('.grupo-header');
      if (body && !body.classList.contains('open')) {
        body.classList.add('open');
        if (header) header.classList.add('active');
      }
    }
  });
}

var CONDUCTORES_DATA = <?= $condJS ?? '{}' ?>;

function actualizarM3() {
  var sel = document.getElementById('selPPU');
  var opt = sel?.options[sel.selectedIndex];
  var m3  = parseFloat(opt?.getAttribute('data-m3') || 0);
  var el  = document.getElementById('m3Info');
  if (el) el.textContent = m3 > 0 ? 'Capacidad: ' + m3.toFixed(1) + ' m³' : '';
}
document.getElementById('selPPU')?.addEventListener('change', actualizarM3);

document.getElementById('selConductor')?.addEventListener('change', function() {
  var cid  = this.value;
  var cond = CONDUCTORES_DATA[cid];
  var infoEl = document.getElementById('condInfo');
  var ppuSel  = document.getElementById('selPPU');
  var obraSel = document.getElementById('selObra');

  if (!cond || !cid) {
    if (infoEl) infoEl.textContent = '';
    return;
  }
  /* Mostrar info del conductor */
  var info = '';
  if (cond.rut)        info += 'RUT: ' + cond.rut + '  ';
  if (cond.ppu)        info += '🚛 ' + cond.ppu + ' (' + parseFloat(cond.metros_cubicos||0).toFixed(1) + ' m³)  ';
  if (cond.obra_nombre) info += '🏗️ ' + (cond.obra_codigo||'') + ' — ' + cond.obra_nombre;
  if (infoEl) infoEl.textContent = info;

  /* Auto-rellenar PPU */
  if (ppuSel && cond.ppu_id) {
    ppuSel.value = cond.ppu_id;
    actualizarM3();
  }
  /* Auto-rellenar Obra */
  if (obraSel && cond.obra_id) {
    obraSel.value = cond.obra_id;
  }
});
</script>

<!-- Indicador tiempo real -->
<div id="rt-indicator" style="position:fixed;bottom:16px;right:16px;background:#1b2838;color:#fff;border-radius:999px;padding:6px 14px;font-size:.72rem;font-weight:700;display:flex;align-items:center;gap:6px;z-index:999;box-shadow:0 2px 10px rgba(0,0,0,.2)">
  <span class="rt-dot" style="width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block;animation:blink 2s infinite"></span>
  En vivo &nbsp;·&nbsp; <span id="rt-enviados">—</span> pendientes &nbsp;·&nbsp; <span id="rt-validados">—</span> validados
</div>
<script src="js/realtime.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  RealtimeTickets.init({
    onNuevoTicket: function(data) {
      if (data.enviados > 0) {
        var b = document.createElement('div');
        b.innerHTML = '<div onclick="location.reload()" style="position:fixed;top:70px;left:50%;transform:translateX(-50%);background:#d97706;color:#fff;border-radius:10px;padding:10px 20px;font-size:.88rem;font-weight:700;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.2);cursor:pointer;white-space:nowrap"><i class="bi bi-arrow-clockwise me-2"></i>' + data.enviados + ' ticket(s) nuevo(s) — Toca para actualizar</div>';
        document.body.appendChild(b);
        setTimeout(function(){ b.remove(); }, 8000);
      }
    }
  });
});

(function () {
  var chks = document.querySelectorAll('.chk-tk');
  var btn  = document.getElementById('btnPdfSeleccion');
  var cnt  = document.getElementById('cntSeleccion');
  var form = document.getElementById('formFiltrosTickets');
  if (!btn || !form) return;
  function recount() {
    var ids = Array.prototype.filter.call(chks, function (c) { return c.checked; }).map(function (c) { return c.dataset.id; });
    cnt.textContent = ids.length;
    btn.disabled = ids.length === 0;
    btn.style.opacity = ids.length === 0 ? '.55' : '1';
    btn.dataset.ids = ids.join(',');
  }
  Array.prototype.forEach.call(chks, function (c) { c.addEventListener('change', recount); });
  btn.addEventListener('click', function () {
    if (!btn.dataset.ids) return;
    form.querySelectorAll('input[name="ids"]').forEach(function (n) { n.remove(); });
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'ids';
    hidden.value = btn.dataset.ids;
    form.appendChild(hidden);
    var origAction = form.action;
    var origTarget = form.target;
    form.action = 'tickets_despacho_export.php';
    form.target = '_blank';
    form.submit();
    form.action = origAction;
    form.target = origTarget;
    hidden.remove();
  });
  recount();
})();
</script>
<?php require_once 'includes/footer.php'; ?>
