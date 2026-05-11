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
      $token = bin2hex(random_bytes(16));
      $pdo->prepare("INSERT INTO tickets_despacho
        (fecha, hora, obra_id, obra_nombre, ppu_id, ppu, metros_cubicos, destino_id, destino,
         tipo_material, observaciones, estado, creado_por, creado_por_nombre, receptor_id, token,
         conductor_id, conductor_nombre, conductor_rut)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
          $_POST['fecha'] ?? date('Y-m-d'),
          $_POST['hora']  ?? date('H:i'),
          $obraId ?: null, $obraRow ? $obraRow['codigo'].' — '.$obraRow['nombre'] : '',
          $ppuId ?: null, $ppuRow ? $ppuRow['ppu'] : '',
          $ppuRow ? (float)$ppuRow['metros_cubicos'] : 0,
          $destId ?: null, $destRow ? $destRow['nombre'] : '',
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
      $pdo->prepare("UPDATE tickets_despacho SET estado='enviado', enviado_en=CURRENT_TIMESTAMP WHERE id=? AND creado_por=? AND estado='borrador'")
          ->execute([$tid, $usuario['id']]);
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

/* ── Listados ─── */
$propios = [];
if ($esAdminDespacho) {
  $propios = $pdo->query("SELECT * FROM tickets_despacho ORDER BY creado_en DESC")->fetchAll(PDO::FETCH_ASSOC);
} else {
  $st = $pdo->prepare("SELECT * FROM tickets_despacho WHERE creado_por=? ORDER BY creado_en DESC");
  $st->execute([$usuario['id']]);
  $propios = $st->fetchAll(PDO::FETCH_ASSOC);
}

$recibidos = [];
if ($esReceptor || $esAdminDespacho) {
  if ($esAdminDespacho) {
    $recibidos = $pdo->query("SELECT * FROM tickets_despacho WHERE estado IN ('enviado','validado') ORDER BY creado_en DESC")->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $st = $pdo->prepare("SELECT * FROM tickets_despacho WHERE receptor_id=? AND estado IN ('enviado','validado') ORDER BY creado_en DESC");
    $st->execute([$usuario['id']]);
    $recibidos = $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

require_once 'includes/header.php';

function badgeEstadoTicket($e) {
  $map = ['borrador'=>['secondary','Borrador','pencil'],'enviado'=>['warning','Enviado','send-fill'],'validado'=>['success','Validado','check-circle-fill']];
  $m = $map[$e] ?? ['light','—','circle'];
  return '<span class="badge bg-'.$m[0].'"><i class="bi bi-'.$m[2].' me-1"></i>'.$m[1].'</span>';
}
?>

<style>
.despacho-role-badge { font-size:.72rem; padding:.25rem .55rem; border-radius:999px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; }

/* Acordeón de grupos */
.grupo-despacho { border:1px solid #dee2e6; border-radius:10px; overflow:hidden; margin-bottom:10px; box-shadow:0 2px 6px rgba(0,0,0,.05); }
.grupo-header {
  display:flex; align-items:center; gap:10px; flex-wrap:wrap;
  background:#fff; padding:10px 14px; cursor:pointer;
  transition:background .15s; border:none; width:100%; text-align:left;
}
.grupo-header:hover { background:#f8f9fa; }
.grupo-header .g-ppu { font-family:monospace; font-size:1.1rem; font-weight:900; background:#1b2838; color:#fff; padding:3px 10px; border-radius:6px; letter-spacing:2px; }
.grupo-header .g-cond { font-weight:700; font-size:.95rem; color:#212529; }
.grupo-header .g-obra { font-size:.8rem; color:#6c757d; }
.grupo-header .g-count { margin-left:auto; display:flex; gap:6px; align-items:center; }
.grupo-body { border-top:1px solid #dee2e6; background:#fdfdfd; display:none; }
.grupo-body.open { display:block; }
.grupo-body table { margin:0; }
.grupo-body td, .grupo-body th { padding:6px 10px; font-size:.82rem; }
.g-chevron { transition:transform .2s; font-size:.85rem; color:#adb5bd; }
.grupo-header.active .g-chevron { transform:rotate(180deg); }

/* Estados colores en fila */
tr.est-enviado { background:#fff8e1; }
tr.est-validado { background:#f0fff4; }
</style>

<!-- Cabecera -->
<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div>
    <h3 class="fw-bold mb-1">
      <i class="bi bi-truck-front-fill text-secondary me-2"></i>Tickets de Despacho
      <?php if ($esAdminDespacho): ?>
      <span class="despacho-role-badge bg-dark text-white ms-2">Admin módulo</span>
      <?php elseif ($esReceptor): ?>
      <span class="despacho-role-badge bg-success text-white ms-2">Receptor</span>
      <?php endif; ?>
    </h3>
    <p class="text-muted mb-0">Registro y seguimiento de despachos de material</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($esAdminDespacho): ?>
    <a href="tickets_despacho_admin.php" class="btn btn-dark"><i class="bi bi-gear me-1"></i>Configurar módulo</a>
    <?php endif; ?>
    <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#modalNuevoTicket">
      <i class="bi bi-plus-circle me-1"></i>Nuevo Ticket
    </button>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if (empty($receptores) && $esAdminDespacho): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>No hay un receptor/validador asignado. <a href="tickets_despacho_admin.php#receptores">Configúralo aquí</a>.</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" id="despTabs">
  <li class="nav-item">
    <a class="nav-link active" data-bs-toggle="tab" href="#tabMios">
      <i class="bi bi-file-earmark-text me-1"></i><?= $esAdminDespacho ? 'Todos los Tickets' : 'Mis Tickets' ?>
      <span class="badge bg-secondary ms-1"><?= count($propios) ?></span>
    </a>
  </li>
  <?php if ($esReceptor || $esAdminDespacho): ?>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" href="#tabRecibidos">
      <i class="bi bi-inbox me-1"></i>Pendientes de Validación
      <span class="badge bg-warning text-dark ms-1"><?php $cntEnv=0; foreach($recibidos as $_r){ if($_r['estado']==='enviado') $cntEnv++; } echo $cntEnv; ?></span>
    </a>
  </li>
  <?php endif; ?>
</ul>

<div class="tab-content">
  <!-- TICKETS PROPIOS / TODOS — agrupados por PPU + Conductor + Obra -->
  <div class="tab-pane fade show active" id="tabMios">
    <?php if (empty($propios)): ?>
    <div class="text-center text-muted py-5">
      <i class="bi bi-truck-front fs-1 d-block mb-2 opacity-50"></i>
      <?= $esAdminDespacho ? 'Aún no hay tickets en el sistema.' : 'Aún no tienes tickets. Crea uno con el botón <em>Nuevo Ticket</em>.' ?>
    </div>
    <?php else:
      /* Agrupar por PPU + conductor_nombre + obra_nombre */
      $grupos = [];
      foreach ($propios as $t) {
        $key = ($t['ppu'] ?: '—').'|'.($t['conductor_nombre'] ?: '—').'|'.($t['obra_nombre'] ?: '—');
        if (!isset($grupos[$key])) {
          $grupos[$key] = [
            'ppu'       => $t['ppu'] ?: '—',
            'conductor' => $t['conductor_nombre'] ?: '—',
            'obra'      => $t['obra_nombre'] ?: '—',
            'm3'        => $t['metros_cubicos'],
            'tickets'   => [],
          ];
        }
        $grupos[$key]['tickets'][] = $t;
      }
      $gi = 0;
    ?>
    <?php foreach ($grupos as $gkey => $grupo): $gi++;
      $total   = count($grupo['tickets']);
      $pend=0; $valid=0; $borrdr=0;
      foreach($grupo['tickets'] as $_gx){ if($_gx['estado']==='enviado') $pend++; if($_gx['estado']==='validado') $valid++; if($_gx['estado']==='borrador') $borrdr++; }
    ?>
    <div class="grupo-despacho">
      <button class="grupo-header" onclick="toggleGrupo(this, 'gBody<?= $gi ?>')">
        <span class="g-ppu"><?= htmlspecialchars($grupo['ppu']) ?></span>
        <div>
          <div class="g-cond"><i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($grupo['conductor']) ?></div>
          <div class="g-obra"><i class="bi bi-building me-1"></i><?= htmlspecialchars($grupo['obra']) ?>
            <?php if ($grupo['m3'] > 0): ?> &nbsp;·&nbsp; <?= number_format($grupo['m3'],1,',','.') ?> m³<?php endif; ?>
          </div>
        </div>
        <div class="g-count">
          <?php if ($borrdr > 0): ?><span class="badge bg-secondary"><?= $borrdr ?> borrador<?= $borrdr>1?'es':'' ?></span><?php endif; ?>
          <?php if ($pend > 0): ?><span class="badge bg-warning text-dark"><?= $pend ?> pendiente<?= $pend>1?'s':'' ?></span><?php endif; ?>
          <?php if ($valid > 0): ?><span class="badge bg-success"><?= $valid ?> validado<?= $valid>1?'s':'' ?></span><?php endif; ?>
          <span class="badge bg-light text-dark border"><?= $total ?> total</span>
          <i class="bi bi-chevron-down g-chevron ms-1"></i>
        </div>
      </button>
      <div class="grupo-body" id="gBody<?= $gi ?>">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>Fecha / Hora</th>
                <?php if ($esAdminDespacho): ?><th>Creado por</th><?php endif; ?>
                <th>Destino</th>
                <th>Material</th>
                <th>Estado</th>
                <th class="text-end">Acciones</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($grupo['tickets'] as $t): ?>
            <tr class="est-<?= $t['estado'] ?>">
              <td><small class="text-muted">#<?= str_pad($t['id'],4,'0',STR_PAD_LEFT) ?></small></td>
              <td><strong><?= htmlspecialchars($t['fecha']) ?></strong> <small class="text-muted"><?= htmlspecialchars($t['hora']) ?></small></td>
              <?php if ($esAdminDespacho): ?><td><small><?= htmlspecialchars($t['creado_por_nombre']) ?></small></td><?php endif; ?>
              <td><small><?= htmlspecialchars($t['destino'] ?: '—') ?></small></td>
              <td><span class="badge bg-dark"><?= htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) ?></span></td>
              <td><?= badgeEstadoTicket($t['estado']) ?></td>
              <td class="text-end">
                <a href="tickets_despacho_ver.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Ver / Imprimir"><i class="bi bi-eye"></i></a>
                <?php if ($t['estado'] === 'borrador' && ((int)$t['creado_por'] === (int)$usuario['id'] || $esAdminDespacho)): ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="accion" value="enviar">
                  <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                  <button class="btn btn-sm btn-primary" onclick="return confirm('¿Enviar este ticket al receptor?')"><i class="bi bi-send-fill"></i></button>
                </form>
                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
                  <input type="hidden" name="accion" value="eliminar">
                  <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- RECIBIDOS / PENDIENTES -->
  <?php if ($esReceptor || $esAdminDespacho): ?>
  <div class="tab-pane fade" id="tabRecibidos">
    <?php if (empty($recibidos)): ?>
    <div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>No hay tickets pendientes de validación.</div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr><th>#</th><th>Fecha / Hora</th><th>Creado por</th><th>Obra</th><th>PPU</th><th>m³</th><th>Material</th><th>Estado</th><th class="text-end">Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($recibidos as $t): ?>
        <tr class="<?= $t['estado']==='enviado' ? 'table-warning' : '' ?>">
          <td><small class="text-muted">#<?= str_pad($t['id'],4,'0',STR_PAD_LEFT) ?></small></td>
          <td><strong><?= htmlspecialchars($t['fecha']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($t['hora']) ?></small></td>
          <td><?= htmlspecialchars($t['creado_por_nombre']) ?></td>
          <td><small><?= htmlspecialchars($t['obra_nombre'] ?: '—') ?></small></td>
          <td><span class="badge bg-dark fs-6"><?= htmlspecialchars($t['ppu'] ?: '—') ?></span></td>
          <td><?= $t['metros_cubicos'] > 0 ? number_format($t['metros_cubicos'],1,',','.').' m³' : '—' ?></td>
          <td><span class="badge bg-secondary"><?= htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) ?></span></td>
          <td><?= badgeEstadoTicket($t['estado']) ?></td>
          <td class="text-end">
            <a href="tickets_despacho_ver.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
            <?php if ($t['estado'] === 'enviado'): ?>
            <a href="validar_ticket.php?t=<?= urlencode($t['token']) ?>" class="btn btn-sm btn-success">
              <i class="bi bi-qr-code-scan me-1"></i>Validar
            </a>
            <?php else: ?>
            <span class="text-success small ms-1"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($t['cambio']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
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
            <div class="col-md-4">
              <label class="form-label fw-semibold">Fecha *</label>
              <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Hora *</label>
              <input type="time" name="hora" class="form-control" value="<?= date('H:i') ?>" required>
            </div>

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

            <!-- OBRA -->
            <div class="col-md-4">
              <label class="form-label fw-semibold">Obra</label>
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
/* Abrir automáticamente el primer grupo */
document.addEventListener('DOMContentLoaded', function() {
  var first = document.querySelector('.grupo-header');
  if (first) first.click();
});

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

<?php require_once 'includes/footer.php'; ?>
