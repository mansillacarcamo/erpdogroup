<?php
require_once 'config.php';

if (!isConductorLoggedIn()) {
  header('Location: despacho_conductor_login.php'); exit;
}
$cond = getConductorSession();

if (isset($_GET['logout'])) { session_destroy(); header('Location: despacho_conductor_login.php'); exit; }

$MATERIALES = [
  'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4',
  'bajo_3'=>'Bajo 3','bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada',
  'grava'=>'Grava','gravilla'=>'Gravilla','arena'=>'Arena','arena_tubo'=>'Arena Tubo',
  'escombros'=>'Escombros','bolones'=>'Bolones',
];

$msgTicket = null; $msgTipoTicket = 'success';

/* ── POST: Crear ticket ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_ticket') {
  try {
    $ppuId   = (int)($_POST['ppu_id']  ?? $cond['ppu_id']  ?? 0);
    $obraId  = (int)($_POST['obra_id'] ?? $cond['obra_id'] ?? 0);
    $salidaId = (int)($_POST['salida_id'] ?? 0);
    $destId  = (int)($_POST['destino_id'] ?? 0);
    $materia = $_POST['tipo_material'] ?? '';
    $fecha   = date('Y-m-d');
    $hora    = date('H:i:s');
    $obs     = trim($_POST['observaciones'] ?? '');
    if (!$ppuId)   throw new Exception('Selecciona un camión (PPU).');
    if (!$destId)  throw new Exception('Selecciona un destino.');
    if (!$materia) throw new Exception('Selecciona el tipo de material.');
    $ppuRow = $pdo->prepare("SELECT * FROM despacho_ppu WHERE id=?"); $ppuRow->execute([$ppuId]); $ppuRow = $ppuRow->fetch(PDO::FETCH_ASSOC);
    $obraRow = null;
    if ($obraId) { $stO = $pdo->prepare("SELECT * FROM obras WHERE id=?"); $stO->execute([$obraId]); $obraRow = $stO->fetch(PDO::FETCH_ASSOC); }
    $salidaRow = null;
    if ($salidaId) { $stS = $pdo->prepare("SELECT * FROM obras WHERE id=?"); $stS->execute([$salidaId]); $salidaRow = $stS->fetch(PDO::FETCH_ASSOC); }
    $salidaNombre = $salidaRow ? ($salidaRow['codigo'].' — '.$salidaRow['nombre']) : '';
    $destRow = $pdo->prepare("SELECT * FROM despacho_destinos WHERE id=?"); $destRow->execute([$destId]); $destRow = $destRow->fetch(PDO::FETCH_ASSOC);
    $recRow = null;
    if ($obraId) { $stRec = $pdo->prepare("SELECT dr.id FROM despacho_receptores dr JOIN despacho_externos de ON de.id=dr.externo_id WHERE dr.tipo='externo' AND de.activo=1 AND de.obra_id=? LIMIT 1"); $stRec->execute([$obraId]); $recRow = $stRec->fetch(PDO::FETCH_ASSOC); }
    if (!$recRow) { $recRow = $pdo->query("SELECT dr.id FROM despacho_receptores dr JOIN despacho_externos de ON de.id=dr.externo_id WHERE dr.tipo='externo' AND de.activo=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC); }
    if (!$recRow) throw new Exception('No hay Carchek configurado. Contacta al administrador.');
    $token = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO tickets_despacho (fecha,hora,obra_id,obra_nombre,salida_id,salida_nombre,ppu_id,ppu,metros_cubicos,destino_id,destino,tipo_material,observaciones,estado,creado_por,creado_por_nombre,receptor_id,token,conductor_id,conductor_nombre,conductor_rut) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$fecha,$hora,$obraId?:null,$obraRow?$obraRow['codigo'].' — '.$obraRow['nombre']:'',$salidaId?:null,$salidaNombre,$ppuId,$ppuRow?$ppuRow['ppu']:'',$ppuRow?(float)$ppuRow['metros_cubicos']:0,$destId,$destRow?$destRow['nombre']:'',$materia,$obs,'borrador',0,$cond['nombre'],$recRow['id'],$token,$cond['id'],$cond['nombre'],$cond['rut']]);
    header("Location: despacho_conductor_portal.php?ok=1"); exit;
  } catch (Exception $e) { $msgTicket = $e->getMessage(); $msgTipoTicket = 'danger'; }
}

/* ── POST: Enviar ticket ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'enviar_ticket') {
  $tid = (int)($_POST['ticket_id'] ?? 0);
  try {
    $stT = $pdo->prepare("SELECT * FROM tickets_despacho WHERE id=? AND conductor_id=? AND estado='borrador'"); $stT->execute([$tid,$cond['id']]); $tRow = $stT->fetch(PDO::FETCH_ASSOC);
    if (!$tRow) throw new Exception('Ticket no encontrado o ya enviado.');
    $pdo->prepare("UPDATE tickets_despacho SET estado='enviado', enviado_en=datetime('now','localtime') WHERE id=?")->execute([$tid]);
    header("Location: despacho_conductor_portal.php?enviado=1"); exit;
  } catch (Exception $e) { $msgTicket = $e->getMessage(); $msgTipoTicket = 'danger'; }
}

/* ── POST: Editar ticket ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar_ticket') {
  $tid = (int)($_POST['ticket_id'] ?? 0);
  try {
    $stT = $pdo->prepare("SELECT * FROM tickets_despacho WHERE id=? AND conductor_id=? AND estado='borrador'"); $stT->execute([$tid,$cond['id']]);
    if (!$stT->fetch()) throw new Exception('Ticket no encontrado.');
    $ppuId=(int)($_POST['ppu_id']??0); $destId=(int)($_POST['destino_id']??0); $obraId=(int)($_POST['obra_id']??0); $salidaId=(int)($_POST['salida_id']??0);
    if (!$ppuId||!$destId||!$_POST['tipo_material']) throw new Exception('Completa los campos requeridos.');
    $ppuRow=$pdo->prepare("SELECT * FROM despacho_ppu WHERE id=?"); $ppuRow->execute([$ppuId]); $ppuRow=$ppuRow->fetch(PDO::FETCH_ASSOC);
    $destRow=$pdo->prepare("SELECT * FROM despacho_destinos WHERE id=?"); $destRow->execute([$destId]); $destRow=$destRow->fetch(PDO::FETCH_ASSOC);
    $obraRow=null; if($obraId){$stO=$pdo->prepare("SELECT * FROM obras WHERE id=?");$stO->execute([$obraId]);$obraRow=$stO->fetch(PDO::FETCH_ASSOC);}
    $salidaRow=null; if($salidaId){$stS=$pdo->prepare("SELECT * FROM obras WHERE id=?");$stS->execute([$salidaId]);$salidaRow=$stS->fetch(PDO::FETCH_ASSOC);}
    $salidaNombre = $salidaRow ? ($salidaRow['codigo'].' — '.$salidaRow['nombre']) : '';
    $pdo->prepare("UPDATE tickets_despacho SET obra_id=?,obra_nombre=?,salida_id=?,salida_nombre=?,ppu_id=?,ppu=?,metros_cubicos=?,destino_id=?,destino=?,tipo_material=?,observaciones=? WHERE id=? AND conductor_id=? AND estado='borrador'")
      ->execute([$obraId?:null,$obraRow?$obraRow['codigo'].' — '.$obraRow['nombre']:'',$salidaId?:null,$salidaNombre,$ppuId,$ppuRow?$ppuRow['ppu']:'',$ppuRow?(float)$ppuRow['metros_cubicos']:0,$destId,$destRow?$destRow['nombre']:'',$_POST['tipo_material'],trim($_POST['observaciones']??''),$tid,$cond['id']]);
    header("Location: despacho_conductor_portal.php?editado=1"); exit;
  } catch (Exception $e) { $msgTicket = $e->getMessage(); $msgTipoTicket = 'danger'; }
}

$ppuList     = $pdo->query("SELECT * FROM despacho_ppu WHERE activo=1 ORDER BY ppu")->fetchAll(PDO::FETCH_ASSOC);
$destinoList = $pdo->query("SELECT * FROM despacho_destinos WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$obrasList   = $pdo->query("SELECT id, codigo, nombre FROM obras WHERE estado='activa' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);

$stTickets = $pdo->prepare("SELECT td.*, dp.ppu AS ppu_codigo, dp.metros_cubicos, dd.nombre AS destino_nombre, de.nombre AS carchek_nombre, de.empresa AS carchek_empresa FROM tickets_despacho td LEFT JOIN despacho_ppu dp ON dp.id=td.ppu_id LEFT JOIN despacho_destinos dd ON dd.id=td.destino_id LEFT JOIN despacho_receptores dr ON dr.id=td.receptor_id AND dr.tipo='externo' LEFT JOIN despacho_externos de ON de.id=dr.externo_id WHERE td.conductor_id=? ORDER BY td.fecha DESC, td.hora DESC");
$stTickets->execute([$cond['id']]);
$tickets = $stTickets->fetchAll(PDO::FETCH_ASSOC);

$hoy = date('Y-m-d');
$ticketsHoy = array_filter($tickets, fn($t) => $t['fecha'] === $hoy);
$validadosHoy = count(array_filter($ticketsHoy, fn($t) => $t['estado'] === 'validado'));
$pendientesHoy = count(array_filter($ticketsHoy, fn($t) => in_array($t['estado'], ['borrador','enviado'])));
$m3Hoy = array_sum(array_column(array_filter($ticketsHoy, fn($t) => $t['estado']==='validado'), 'metros_cubicos'));
$primerNombre = explode(' ', $cond['nombre'])[0];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#1b2838">
<title>DOGroup · Conductor</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --bg:#f0f4f8;
  --card:#fff;
  --dark:#1b2838;
  --primary:#2563eb;
  --success:#16a34a;
  --warning:#d97706;
  --danger:#dc2626;
  --muted:#6b7280;
  --border:#e5e7eb;
  --radius:14px;
  --bottom-h:72px;
}
html,body{margin:0;padding:0;background:var(--bg);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh;overflow-x:hidden}

/* ── TOP BAR ── */
.topbar{position:sticky;top:0;z-index:100;background:var(--dark);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.topbar-left{display:flex;align-items:center;gap:10px}
.topbar-avatar{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;font-weight:700;flex-shrink:0}
.topbar-name{color:#fff;font-weight:700;font-size:.95rem;line-height:1.2}
.topbar-role{color:rgba(255,255,255,.55);font-size:.75rem}
.topbar-logout{color:rgba(255,255,255,.7);background:none;border:none;padding:6px;cursor:pointer;font-size:1.1rem;border-radius:8px;transition:background .15s}
.topbar-logout:hover{background:rgba(255,255,255,.1)}

/* ── SCROLL CONTENT ── */
.content{padding:0 0 calc(var(--bottom-h) + 16px)}

/* ── HERO: camión asignado ── */
.hero{background:linear-gradient(135deg,#1b2838 0%,#2d3f55 100%);padding:20px 16px 24px;color:#fff}
.hero-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;opacity:.6;margin-bottom:4px}
.hero-ppu{font-size:2.2rem;font-weight:900;letter-spacing:3px;font-family:monospace;display:flex;align-items:center;gap:12px}
.hero-ppu-badge{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:4px 14px}
.hero-meta{display:flex;gap:16px;margin-top:12px;flex-wrap:wrap}
.hero-meta-item{font-size:.82rem;opacity:.8;display:flex;align-items:center;gap:5px}

/* ── STATS HOY ── */
.stats-row{display:flex;gap:10px;padding:16px 16px 0}
.stat-box{flex:1;background:var(--card);border-radius:var(--radius);padding:12px 10px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.stat-num{font-size:1.7rem;font-weight:900;line-height:1}
.stat-lbl{font-size:.68rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:4px}
.stat-num.green{color:var(--success)}
.stat-num.orange{color:var(--warning)}
.stat-num.blue{color:var(--primary)}

/* ── SECCIÓN TÍTULO ── */
.section-head{display:flex;align-items:center;justify-content:space-between;padding:20px 16px 10px}
.section-title{font-size:.85rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}

/* ── TICKET CARDS ── */
.ticket-list{padding:0 16px;display:flex;flex-direction:column;gap:14px}
.grupo-fecha-cond{background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.grupo-fecha-header{width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;background:#f8fafc;border:none;border-bottom:1px solid #e5e7eb;cursor:pointer;text-align:left;flex-wrap:wrap}
.grupo-fecha-cond.abierto .grupo-fecha-header{background:#dbeafe}
.grupo-fecha-info{display:flex;flex-direction:column;line-height:1.2}
.grupo-fecha-info strong{font-size:.95rem;color:#0f172a}
.grupo-fecha-info small{color:#64748b;font-size:.72rem}
.grupo-fecha-stats{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.g-pill{font-size:.7rem;padding:3px 8px;border-radius:999px;font-weight:700}
.g-pill-borrador{background:#e5e7eb;color:#374151}
.g-pill-enviado{background:#fef3c7;color:#92400e}
.g-pill-validado{background:#d1fae5;color:#065f46}
.g-pill-m3{background:#dbeafe;color:#1e40af}
.g-fecha-chev{transition:transform .2s;color:#64748b}
.grupo-fecha-cond.abierto .g-fecha-chev{transform:rotate(180deg)}
.grupo-fecha-body{display:none;padding:10px 12px;flex-direction:column;gap:10px}
.grupo-fecha-cond.abierto .grupo-fecha-body{display:flex}
.ticket-card{background:var(--card);border-radius:var(--radius);padding:14px 16px;box-shadow:0 1px 4px rgba(0,0,0,.06);border-left:4px solid transparent;transition:transform .1s,box-shadow .1s;cursor:pointer}
.ticket-card:active{transform:scale(.98);box-shadow:0 1px 2px rgba(0,0,0,.08)}
.ticket-card.borrador{border-left-color:#9ca3af}
.ticket-card.enviado{border-left-color:var(--warning)}
.ticket-card.validado{border-left-color:var(--success)}
.ticket-card-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.ticket-num{font-family:monospace;font-weight:900;font-size:.9rem;color:var(--dark)}
.ticket-badge{font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:999px}
.badge-borrador{background:#f3f4f6;color:#374151}
.badge-enviado{background:#fef3c7;color:#92400e}
.badge-validado{background:#dcfce7;color:#14532d}
.ticket-info{font-size:.82rem;color:#374151;display:flex;flex-wrap:wrap;gap:8px}
.ticket-info-item{display:flex;align-items:center;gap:4px}
.ticket-info-item i{font-size:.8rem;color:var(--muted)}
.ticket-ppu{display:inline-block;font-family:monospace;font-weight:900;font-size:.85rem;background:var(--dark);color:#fff;padding:1px 8px;border-radius:5px;letter-spacing:1px}
.ticket-actions{display:flex;gap:8px;margin-top:12px}
.ticket-actions .btn-act{flex:1;border:none;border-radius:10px;padding:9px 12px;font-size:.82rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:opacity .15s}
.ticket-actions .btn-act:active{opacity:.8}
.btn-send{background:var(--success);color:#fff}
.btn-edit{background:#f3f4f6;color:#374151}
.btn-view{background:#eff6ff;color:var(--primary)}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:48px 24px;color:var(--muted)}
.empty-icon{font-size:3.5rem;margin-bottom:12px;opacity:.4}
.empty-title{font-size:1rem;font-weight:700;margin-bottom:6px}
.empty-sub{font-size:.85rem}

/* ── BOTTOM NAV ── */
.bottom-nav{position:fixed;bottom:0;left:0;right:0;height:var(--bottom-h);background:var(--card);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-around;padding:0 8px;z-index:200;padding-bottom:env(safe-area-inset-bottom)}
.nav-item{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;cursor:pointer;padding:8px 4px;border-radius:10px;transition:background .15s;border:none;background:none;color:var(--muted);font-size:.65rem;font-weight:600}
.nav-item.active{color:var(--primary)}
.nav-item i{font-size:1.3rem}
.nav-item.fab{background:var(--primary);color:#fff;border-radius:16px;padding:10px 22px;font-size:.75rem;box-shadow:0 4px 14px rgba(37,99,235,.35);flex:0}
.nav-item.fab i{font-size:1.4rem}

/* ── SHEET MODAL ── */
.sheet-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:300;opacity:0;transition:opacity .25s;pointer-events:none}
.sheet-overlay.open{opacity:1;pointer-events:all}
.sheet{position:fixed;bottom:0;left:0;right:0;background:var(--card);border-radius:20px 20px 0 0;z-index:400;transform:translateY(100%);transition:transform .3s cubic-bezier(.32,.72,0,1);max-height:92vh;overflow-y:auto;padding-bottom:env(safe-area-inset-bottom)}
.sheet.open{transform:translateY(0)}
.sheet-handle{width:40px;height:4px;background:var(--border);border-radius:2px;margin:12px auto 0}
.sheet-header{padding:16px 20px 12px;border-bottom:1px solid var(--border)}
.sheet-title{font-size:1rem;font-weight:800;color:var(--dark)}
.sheet-body{padding:16px 20px}
.field-group{margin-bottom:16px}
.field-label{font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px;display:block}
.field-input{width:100%;border:1.5px solid var(--border);border-radius:10px;padding:11px 14px;font-size:.95rem;outline:none;background:#fff;transition:border-color .15s;-webkit-appearance:none}
.field-input:focus{border-color:var(--primary)}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.btn-submit{width:100%;background:var(--primary);color:#fff;border:none;border-radius:12px;padding:14px;font-size:1rem;font-weight:800;cursor:pointer;margin-top:8px;transition:opacity .15s}
.btn-submit:active{opacity:.85}
.btn-submit.green{background:var(--success)}
.btn-submit:disabled{opacity:.5}

/* ── ALERTAS ── */
.alert-bar{margin:10px 16px 0;padding:12px 14px;border-radius:10px;font-size:.85rem;font-weight:600;display:flex;align-items:center;gap:8px}
.alert-bar.success{background:#dcfce7;color:#14532d}
.alert-bar.danger{background:#fee2e2;color:#7f1d1d}
.alert-bar.info{background:#dbeafe;color:#1e3a5f}
.alert-bar.warning{background:#fef3c7;color:#78350f}

/* ── CONFIRM DIALOG ── */
.confirm-box{background:var(--card);border-radius:20px;padding:24px 20px;text-align:center;max-width:320px;margin:auto;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:500;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.confirm-icon{font-size:3rem;margin-bottom:12px}
.confirm-title{font-weight:800;font-size:1.1rem;margin-bottom:6px}
.confirm-sub{font-size:.85rem;color:var(--muted);margin-bottom:20px}
.confirm-btns{display:flex;gap:10px}
.confirm-btns button{flex:1;border:none;border-radius:10px;padding:12px;font-size:.9rem;font-weight:700;cursor:pointer}
.confirm-cancel{background:#f3f4f6;color:#374151}
.confirm-ok{background:var(--success);color:#fff}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <div class="topbar-left">
    <div class="topbar-avatar"><?= strtoupper(substr($primerNombre,0,1)) ?></div>
    <div>
      <div class="topbar-name"><?= htmlspecialchars($primerNombre) ?></div>
      <div class="topbar-role">Conductor · DOGroup</div>
    </div>
  </div>
  <button class="topbar-logout" onclick="location.href='?logout=1'" title="Salir">
    <i class="bi bi-box-arrow-right"></i>
  </button>
</div>

<div class="content">

  <!-- HERO CAMIÓN -->
  <?php if ($cond['ppu']): ?>
  <div class="hero">
    <div class="hero-label">Mi camión asignado</div>
    <div class="hero-ppu"><i class="bi bi-truck-front-fill" style="font-size:1.6rem;opacity:.8"></i><span class="hero-ppu-badge"><?= htmlspecialchars($cond['ppu']) ?></span></div>
    <div class="hero-meta">
      <?php if ($cond['metros_cubicos'] > 0): ?>
      <div class="hero-meta-item"><i class="bi bi-box-seam"></i><?= number_format((float)$cond['metros_cubicos'],1,',','.') ?> m³ cap.</div>
      <?php endif; ?>
      <?php if (!empty($cond['obra_codigo'])): ?>
      <div class="hero-meta-item"><i class="bi bi-building"></i><?= htmlspecialchars($cond['obra_codigo'].' — '.($cond['obra_nombre']??'')) ?></div>
      <?php endif; ?>
      <div class="hero-meta-item"><i class="bi bi-calendar-check"></i><?= date('d/m/Y') ?></div>
    </div>
  </div>
  <?php else: ?>
  <div class="hero" style="padding:16px">
    <div class="alert-bar warning" style="margin:0;background:rgba(217,119,6,.2);color:#fbbf24;border-radius:10px">
      <i class="bi bi-exclamation-triangle"></i> Sin camión asignado — contacta al administrador
    </div>
  </div>
  <?php endif; ?>

  <!-- STATS HOY -->
  <div class="stats-row">
    <div class="stat-box">
      <div class="stat-num green"><?= $validadosHoy ?></div>
      <div class="stat-lbl">Validados hoy</div>
    </div>
    <div class="stat-box">
      <div class="stat-num orange"><?= $pendientesHoy ?></div>
      <div class="stat-lbl">Pendientes</div>
    </div>
    <div class="stat-box">
      <div class="stat-num blue"><?= $m3Hoy > 0 ? number_format($m3Hoy,1,',','.') : '0' ?></div>
      <div class="stat-lbl">m³ hoy</div>
    </div>
  </div>

  <!-- ALERTAS -->
  <?php if (!empty($_GET['ok'])): ?>
  <div class="alert-bar success"><i class="bi bi-check-circle-fill"></i> Ticket creado. Presiona <strong>Enviar</strong> cuando esté listo.</div>
  <?php elseif (!empty($_GET['enviado'])): ?>
  <div class="alert-bar info"><i class="bi bi-send-fill"></i> Ticket enviado a Carchek correctamente.</div>
  <?php elseif (!empty($_GET['editado'])): ?>
  <div class="alert-bar warning"><i class="bi bi-pencil-fill"></i> Ticket actualizado.</div>
  <?php endif; ?>
  <?php if ($msgTicket): ?>
  <div class="alert-bar <?= $msgTipoTicket ?>"><i class="bi bi-exclamation-circle"></i> <?= htmlspecialchars($msgTicket) ?></div>
  <?php endif; ?>

  <!-- TICKETS -->
  <div class="section-head">
    <span class="section-title">Mis tickets</span>
    <span style="font-size:.8rem;color:var(--muted)"><?= count($tickets) ?> total</span>
  </div>

  <?php if (empty($tickets)): ?>
  <div class="empty-state">
    <div class="empty-icon"><i class="bi bi-ticket-perforated"></i></div>
    <div class="empty-title">Sin tickets todavía</div>
    <div class="empty-sub">Toca el botón + para crear tu primer ticket de despacho</div>
  </div>
  <?php else:
    /* Agrupar tickets por fecha — los días anteriores van plegables */
    $gruposFecha = [];
    foreach ($tickets as $t) {
      $f = $t['fecha'] ?: '—';
      if (!isset($gruposFecha[$f])) $gruposFecha[$f] = ['fecha'=>$f, 'tickets'=>[], 'm3'=>0, 'count'=>0];
      $gruposFecha[$f]['tickets'][] = $t;
      $gruposFecha[$f]['count']++;
      if ($t['estado'] === 'validado') $gruposFecha[$f]['m3'] += (float)($t['metros_cubicos'] ?? 0);
    }
    krsort($gruposFecha);
    $diasEs = ['Sun'=>'Dom','Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mié','Thu'=>'Jue','Fri'=>'Vie','Sat'=>'Sáb'];
    $mesesEs = ['Jan'=>'Ene','Feb'=>'Feb','Mar'=>'Mar','Apr'=>'Abr','May'=>'May','Jun'=>'Jun','Jul'=>'Jul','Aug'=>'Ago','Sep'=>'Sep','Oct'=>'Oct','Nov'=>'Nov','Dec'=>'Dic'];
  ?>
  <div class="ticket-list">
    <?php $gi = 0; foreach ($gruposFecha as $gFecha => $grupo): $gi++;
      $esGrupoHoy = ($gFecha === $hoy);
      $abierto    = $esGrupoHoy; // hoy abierto por defecto, días anteriores cerrados
      $ts = $gFecha !== '—' ? strtotime($gFecha) : null;
      if ($ts) {
        $dia = $diasEs[date('D', $ts)] ?? date('D', $ts);
        $mes = $mesesEs[date('M', $ts)] ?? date('M', $ts);
        $fechaLabel = $dia.' '.date('d', $ts).' '.$mes;
      } else { $fechaLabel = $gFecha; }
      $countByEstado = ['borrador'=>0,'enviado'=>0,'validado'=>0];
      foreach ($grupo['tickets'] as $tg) { if (isset($countByEstado[$tg['estado']])) $countByEstado[$tg['estado']]++; }
    ?>
    <div class="grupo-fecha-cond <?= $abierto ? 'abierto' : '' ?>" id="grpF<?= $gi ?>">
      <button type="button" class="grupo-fecha-header" onclick="document.getElementById('grpF<?= $gi ?>').classList.toggle('abierto')">
        <div class="grupo-fecha-info">
          <strong><?= $esGrupoHoy ? '<i class="bi bi-sun-fill text-warning me-1"></i>Hoy' : htmlspecialchars($fechaLabel) ?></strong>
          <small class="text-muted"><?= htmlspecialchars($gFecha) ?></small>
        </div>
        <div class="grupo-fecha-stats">
          <?php if ($countByEstado['borrador']): ?><span class="g-pill g-pill-borrador"><?= $countByEstado['borrador'] ?> bor.</span><?php endif; ?>
          <?php if ($countByEstado['enviado']): ?><span class="g-pill g-pill-enviado"><?= $countByEstado['enviado'] ?> env.</span><?php endif; ?>
          <?php if ($countByEstado['validado']): ?><span class="g-pill g-pill-validado"><?= $countByEstado['validado'] ?> val.</span><?php endif; ?>
          <?php if ($grupo['m3'] > 0): ?><span class="g-pill g-pill-m3"><?= number_format($grupo['m3'],1,',','.') ?> m³</span><?php endif; ?>
          <i class="bi bi-chevron-down g-fecha-chev"></i>
        </div>
      </button>
      <div class="grupo-fecha-body">
    <?php foreach ($grupo['tickets'] as $t):
      $esBorrador = $t['estado'] === 'borrador';
      $esEnviado  = $t['estado'] === 'enviado';
      $esValidado = $t['estado'] === 'validado';
      $matLabel   = $MATERIALES[$t['tipo_material']] ?? $t['tipo_material'];
      $fechaLabel = $t['fecha'] ? date('d/m', strtotime($t['fecha'])) : '—';
      $horaLabel  = $t['hora'] ? substr($t['hora'],0,5) : '';
      $esHoy      = $t['fecha'] === $hoy;
    ?>
    <div class="ticket-card <?= $t['estado'] ?>" data-ticket-id="<?= $t['id'] ?>">
      <div class="ticket-card-top">
        <div style="display:flex;align-items:center;gap:8px">
          <span class="ticket-num">#<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?></span>
          <?php if ($esHoy): ?><span style="font-size:.68rem;background:#dbeafe;color:#1e40af;padding:2px 7px;border-radius:999px;font-weight:700">Hoy</span><?php endif; ?>
        </div>
        <span class="ticket-badge badge-<?= $t['estado'] ?>">
          <?php if ($esBorrador): ?><i class="bi bi-pencil"></i> Borrador
          <?php elseif ($esEnviado): ?><i class="bi bi-hourglass-split"></i> Enviado
          <?php elseif ($esValidado): ?><i class="bi bi-check-circle-fill"></i> Validado
          <?php endif; ?>
        </span>
      </div>
      <div class="ticket-info">
        <div class="ticket-info-item"><span class="ticket-ppu"><?= htmlspecialchars($t['ppu_codigo'] ?: $t['ppu'] ?: '—') ?></span></div>
        <div class="ticket-info-item"><i class="bi bi-calendar3"></i><?= $fechaLabel ?> <?= $horaLabel ?></div>
        <div class="ticket-info-item"><i class="bi bi-layers"></i><?= htmlspecialchars($matLabel) ?></div>
        <?php if ($t['metros_cubicos'] > 0): ?>
        <div class="ticket-info-item"><i class="bi bi-box-seam"></i><?= number_format((float)$t['metros_cubicos'],1,',','.') ?> m³</div>
        <?php endif; ?>
        <?php if ($t['destino_nombre'] ?? $t['destino']): ?>
        <div class="ticket-info-item"><i class="bi bi-geo-alt"></i><?= htmlspecialchars($t['destino_nombre'] ?? $t['destino']) ?></div>
        <?php endif; ?>
        <?php if ($esValidado && $t['validado_por_nombre']): ?>
        <div class="ticket-info-item" style="color:var(--success)"><i class="bi bi-person-check"></i><?= htmlspecialchars($t['validado_por_nombre']) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($esBorrador): ?>
      <div class="ticket-actions">
        <button class="btn-act btn-send" onclick="confirmarEnvio(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['carchek_nombre']?:'Carchek')) ?>', '<?= htmlspecialchars(addslashes($t['carchek_empresa']??'')) ?>')">
          <i class="bi bi-send-fill"></i> Enviar a Carchek
        </button>
        <button class="btn-act btn-edit" onclick="abrirEditar(<?= htmlspecialchars(json_encode($t)) ?>)">
          <i class="bi bi-pencil-fill"></i>
        </button>
      </div>
      <?php elseif ($esEnviado): ?>
      <div style="margin-top:10px;font-size:.78rem;color:var(--warning);display:flex;align-items:center;gap:5px">
        <i class="bi bi-hourglass-split"></i> Esperando validación de Carchek
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
      </div><!-- /grupo-fecha-body -->
    </div><!-- /grupo-fecha-cond -->
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div style="height:20px"></div>
</div>

<!-- BOTTOM NAV -->
<nav class="bottom-nav">
  <button class="nav-item active">
    <i class="bi bi-ticket-perforated-fill"></i>Tickets
  </button>
  <button class="nav-item fab" onclick="abrirCrear()">
    <i class="bi bi-plus-lg"></i> Nuevo
  </button>
  <button class="nav-item" onclick="location.href='faena_conductor_portal.php'">
    <i class="bi bi-receipt-cutoff"></i>Gastos
  </button>
  <button class="nav-item" onclick="location.href='reporte_despacho.php'">
    <i class="bi bi-bar-chart-fill"></i>Reporte
  </button>
</nav>

<!-- OVERLAY -->
<div class="sheet-overlay" id="overlay" onclick="cerrarSheet()"></div>

<!-- SHEET: Crear ticket -->
<div class="sheet" id="sheetCrear">
  <div class="sheet-handle"></div>
  <div class="sheet-header">
    <div class="sheet-title"><i class="bi bi-ticket-perforated me-2"></i>Nuevo ticket de despacho</div>
  </div>
  <div class="sheet-body">
    <form method="POST" id="formCrear">
      <input type="hidden" name="accion" value="crear_ticket">

      <!-- Fecha y hora se asignan automáticamente al guardar -->
      <div class="field-group">
        <label class="field-label">Camión PPU *</label>
        <select name="ppu_id" id="crearPpu" class="field-input" required>
          <option value="">— Seleccionar —</option>
          <?php foreach ($ppuList as $p): ?>
          <option value="<?= $p['id'] ?>" data-m3="<?= (float)$p['metros_cubicos'] ?>" <?= ($cond['ppu_id']==$p['id'])?'selected':'' ?>>
            <?= htmlspecialchars($p['ppu']) ?><?= $p['metros_cubicos']>0?' ('.$p['metros_cubicos'].' m³)':'' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field-group">
        <label class="field-label">Tipo de material *</label>
        <select name="tipo_material" class="field-input" required>
          <option value="">— Seleccionar —</option>
          <?php foreach ($MATERIALES as $k=>$v): ?>
          <option value="<?= $k ?>"><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field-group">
        <label class="field-label">Destino *</label>
        <select name="destino_id" class="field-input" required>
          <option value="">— Seleccionar —</option>
          <?php foreach ($destinoList as $d): ?>
          <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field-group">
        <label class="field-label">Salida <small style="color:var(--muted)">(origen)</small></label>
        <select name="salida_id" class="field-input">
          <option value="">— Sin especificar —</option>
          <?php foreach ($obrasList as $o): ?>
          <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field-group">
        <label class="field-label">Obra <small style="color:var(--muted)">(destino)</small></label>
        <select name="obra_id" class="field-input">
          <option value="">— Sin obra —</option>
          <?php foreach ($obrasList as $o): ?>
          <option value="<?= $o['id'] ?>" <?= ($cond['obra_id']==$o['id'])?'selected':'' ?>><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field-group">
        <label class="field-label">Observaciones</label>
        <textarea name="observaciones" class="field-input" rows="2" placeholder="Opcional..."></textarea>
      </div>

      <button type="submit" class="btn-submit" <?= (empty($ppuList)||empty($destinoList))?'disabled':'' ?>>
        <i class="bi bi-save me-1"></i> Crear ticket
      </button>
    </form>
  </div>
</div>

<!-- SHEET: Editar ticket -->
<div class="sheet" id="sheetEditar">
  <div class="sheet-handle"></div>
  <div class="sheet-header">
    <div class="sheet-title"><i class="bi bi-pencil-fill me-2"></i>Editar ticket <span id="editNum" style="color:var(--muted)"></span></div>
  </div>
  <div class="sheet-body">
    <form method="POST" id="formEditar">
      <input type="hidden" name="accion" value="editar_ticket">
      <input type="hidden" name="ticket_id" id="editId">
      <!-- Fecha y hora del ticket no son editables: las fija el sistema -->
      <div class="field-group"><label class="field-label">Camión PPU *</label>
        <select name="ppu_id" id="ePpu" class="field-input" required>
          <option value="">— Seleccionar —</option>
          <?php foreach ($ppuList as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['ppu']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field-group"><label class="field-label">Material *</label>
        <select name="tipo_material" id="eMat" class="field-input" required>
          <option value="">— Seleccionar —</option>
          <?php foreach ($MATERIALES as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field-group"><label class="field-label">Destino *</label>
        <select name="destino_id" id="eDest" class="field-input" required>
          <option value="">— Seleccionar —</option>
          <?php foreach ($destinoList as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field-group"><label class="field-label">Salida <small style="color:var(--muted)">(origen)</small></label>
        <select name="salida_id" id="eSalida" class="field-input">
          <option value="">— Sin especificar —</option>
          <?php foreach ($obrasList as $o): ?><option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field-group"><label class="field-label">Obra <small style="color:var(--muted)">(destino)</small></label>
        <select name="obra_id" id="eObra" class="field-input">
          <option value="">— Sin obra —</option>
          <?php foreach ($obrasList as $o): ?><option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field-group"><label class="field-label">Observaciones</label>
        <textarea name="observaciones" id="eObs" class="field-input" rows="2"></textarea>
      </div>
      <button type="submit" class="btn-submit" style="background:var(--warning)"><i class="bi bi-save me-1"></i> Guardar cambios</button>
    </form>
  </div>
</div>

<!-- CONFIRM: Enviar a Carchek -->
<div class="sheet-overlay" id="confirmOverlay" onclick="cerrarConfirm()" style="z-index:450"></div>
<div class="confirm-box" id="confirmBox" style="display:none">
  <div class="confirm-icon">🚛</div>
  <div class="confirm-title">Enviar a Carchek</div>
  <div class="confirm-sub" id="confirmSub">¿Confirmas el envío?<br>No podrás editar después.</div>
  <div class="confirm-btns">
    <button class="confirm-cancel" onclick="cerrarConfirm()">Cancelar</button>
    <form method="POST" style="flex:1">
      <input type="hidden" name="accion" value="enviar_ticket">
      <input type="hidden" name="ticket_id" id="confirmTicketId">
      <button type="submit" class="confirm-ok" style="width:100%;padding:12px;border:none;border-radius:10px;font-size:.9rem;font-weight:700;cursor:pointer">Sí, enviar</button>
    </form>
  </div>
</div>

<script>
function abrirCrear() {
  abrirSheet('sheetCrear');
}
function abrirEditar(t) {
  document.getElementById('editId').value    = t.id;
  document.getElementById('editNum').textContent = '#'+String(t.id).padStart(5,'0');
  document.getElementById('ePpu').value     = t.ppu_id  || '';
  document.getElementById('eMat').value     = t.tipo_material || '';
  document.getElementById('eDest').value    = t.destino_id || '';
  document.getElementById('eObra').value    = t.obra_id  || '';
  var eSal = document.getElementById('eSalida'); if (eSal) eSal.value = t.salida_id || '';
  document.getElementById('eObs').value     = t.observaciones || '';
  abrirSheet('sheetEditar');
}
function abrirSheet(id) {
  document.getElementById('overlay').classList.add('open');
  document.getElementById(id).classList.add('open');
  document.body.style.overflow = 'hidden';
}
function cerrarSheet() {
  document.querySelectorAll('.sheet.open').forEach(s => s.classList.remove('open'));
  document.getElementById('overlay').classList.remove('open');
  document.body.style.overflow = '';
}
function confirmarEnvio(tid, nombre, empresa) {
  document.getElementById('confirmTicketId').value = tid;
  document.getElementById('confirmSub').innerHTML = 'Enviar a <strong>'+nombre+'</strong>'+(empresa?' ('+empresa+')':'')+'<br><small style="color:#9ca3af">No podrás editar después.</small>';
  document.getElementById('confirmBox').style.display = 'block';
  document.getElementById('confirmOverlay').style.opacity = '1';
  document.getElementById('confirmOverlay').style.pointerEvents = 'all';
}
function cerrarConfirm() {
  document.getElementById('confirmBox').style.display = 'none';
  document.getElementById('confirmOverlay').style.opacity = '0';
  document.getElementById('confirmOverlay').style.pointerEvents = 'none';
}
// Swipe down para cerrar sheet
document.querySelectorAll('.sheet-handle').forEach(function(h){
  var startY, sheet;
  h.addEventListener('touchstart', function(e){ startY=e.touches[0].clientY; sheet=h.closest('.sheet'); });
  h.addEventListener('touchend',   function(e){ if(e.changedTouches[0].clientY - startY > 60) cerrarSheet(); });
});
</script>
<script src="js/realtime.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  RealtimeTickets.init({
    onValidado: function(data, cambios) {
      // Los tickets se actualizan automáticamente por el JS del realtime
      // Actualizar stats
      var el = document.querySelector('.stat-num.green');
      if (el) el.textContent = data.validados;
      var el2 = document.querySelector('.stat-num.orange');
      if (el2) el2.textContent = data.enviados;
    },
    onUpdate: function(data) {
      // Actualizar m³ si cambia
      if (data.mis_tickets) {
        var m3 = data.mis_tickets
          .filter(function(t){ return t.estado === 'validado'; })
          .reduce(function(sum,t){ return sum + (parseFloat(t.metros_cubicos)||0); }, 0);
        var el = document.querySelector('.stat-num.blue');
        if (el) el.textContent = m3 > 0 ? m3.toFixed(1).replace('.',',') : '0';
      }
    }
  });
});
</script>
</body>
</html>
