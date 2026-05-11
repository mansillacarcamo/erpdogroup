<?php
/**
 * Gestión de obras: supervisores, equipos de conductores y Carchek por obra.
 * - Admin módulo despacho: puede gestionar TODAS las obras.
 * - Supervisor: solo ve y gestiona SU obra.
 */
require_once 'config.php';
requireAuth();
requireModulo('despacho', $usuario, $pdo);

$esAdmin = isDespachoAdmin($usuario, $pdo);

/* ── ¿Es supervisor de alguna obra? ── */
$misobras = [];
$stMO = $pdo->prepare("SELECT os.obra_id, o.codigo, o.nombre, o.ciudad FROM obra_supervisores os JOIN obras o ON o.id=os.obra_id WHERE os.usuario_id=? AND os.activo=1");
$stMO->execute([$usuario['id']]);
$misobras = $stMO->fetchAll(PDO::FETCH_ASSOC);
$esSupervisor = !empty($misobras);

if (!$esAdmin && !$esSupervisor) {
    header('Location: inicio.php?acceso_denegado=obras_equipos'); exit;
}

$msg = null; $msgTipo = 'success';

/* ══════════════════════════════════════════════════════════
   POST
══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    try {

        /* ── ADMIN: Asignar supervisor a obra ── */
        if ($accion === 'asignar_supervisor' && $esAdmin) {
            $obraId = (int)$_POST['obra_id'];
            $uid    = (int)$_POST['usuario_id'];
            if (!$obraId || !$uid) throw new Exception('Datos incompletos.');
            $pdo->prepare("INSERT OR REPLACE INTO obra_supervisores (obra_id, usuario_id, asignado_por, activo) VALUES (?,?,?,1)")
                ->execute([$obraId, $uid, $usuario['nombre']]);
            $msg = '✅ Supervisor asignado correctamente.';
        }

        /* ── ADMIN: Quitar supervisor ── */
        if ($accion === 'quitar_supervisor' && $esAdmin) {
            $pdo->prepare("DELETE FROM obra_supervisores WHERE obra_id=? AND usuario_id=?")
                ->execute([(int)$_POST['obra_id'], (int)$_POST['usuario_id']]);
            $msg = 'Supervisor removido.';
        }

        /* ── SUPERVISOR o ADMIN: Agregar conductor al equipo ── */
        if ($accion === 'agregar_conductor') {
            $obraId  = (int)$_POST['obra_id'];
            $condId  = (int)$_POST['conductor_id'];
            $ppuId   = (int)($_POST['ppu_id'] ?? 0) ?: null;
            // Verificar que tiene acceso a esta obra
            if (!$esAdmin) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM obra_supervisores WHERE obra_id=? AND usuario_id=? AND activo=1");
                $chk->execute([$obraId, $usuario['id']]);
                if (!$chk->fetchColumn()) throw new Exception('Sin acceso a esta obra.');
            }
            $pdo->prepare("INSERT OR REPLACE INTO obra_equipo (obra_id, conductor_id, supervisor_id, ppu_id, activo, asignado_por) VALUES (?,?,?,?,1,?)")
                ->execute([$obraId, $condId, $usuario['id'], $ppuId, $usuario['nombre']]);
            // Sincronizar obra_id y ppu_id al conductor
            $pdo->prepare("UPDATE despacho_conductores SET obra_id=?, ppu_id=COALESCE(?,ppu_id) WHERE id=?")
                ->execute([$obraId, $ppuId, $condId]);
            // Registrar asignación de camión
            if ($ppuId) {
                $pdo->prepare("UPDATE despacho_asignaciones SET activa=0, fecha_hasta=datetime('now','localtime') WHERE conductor_id=? AND activa=1")
                    ->execute([$condId]);
                $pdo->prepare("INSERT INTO despacho_asignaciones (conductor_id, ppu_id, obra_id, motivo, activa, asignado_por) VALUES (?,?,?,?,1,?)")
                    ->execute([$condId, $ppuId, $obraId, 'Asignación a obra', $usuario['nombre']]);
            }
            $msg = '✅ Conductor agregado al equipo de la obra.';
        }

        /* ── SUPERVISOR o ADMIN: Quitar conductor ── */
        if ($accion === 'quitar_conductor') {
            $obraId = (int)$_POST['obra_id'];
            $condId = (int)$_POST['conductor_id'];
            $pdo->prepare("DELETE FROM obra_equipo WHERE obra_id=? AND conductor_id=?")->execute([$obraId, $condId]);
            $pdo->prepare("UPDATE despacho_conductores SET obra_id=NULL WHERE id=? AND obra_id=?")->execute([$condId, $obraId]);
            $msg = 'Conductor removido del equipo.';
        }

        /* ── SUPERVISOR o ADMIN: Asignar Carchek a obra ── */
        if ($accion === 'asignar_carchek') {
            $obraId    = (int)$_POST['obra_id'];
            $externoId = (int)$_POST['externo_id'];
            if (!$esAdmin) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM obra_supervisores WHERE obra_id=? AND usuario_id=? AND activo=1");
                $chk->execute([$obraId, $usuario['id']]);
                if (!$chk->fetchColumn()) throw new Exception('Sin acceso a esta obra.');
            }
            $pdo->prepare("INSERT OR REPLACE INTO obra_carchek (obra_id, externo_id, asignado_por, activo) VALUES (?,?,?,1)")
                ->execute([$obraId, $externoId, $usuario['nombre']]);
            // Sincronizar obra_id en despacho_externos
            $pdo->prepare("UPDATE despacho_externos SET obra_id=? WHERE id=?")->execute([$obraId, $externoId]);
            $msg = '✅ Carchek asignado a la obra.';
        }

        /* ── SUPERVISOR o ADMIN: Quitar Carchek ── */
        if ($accion === 'quitar_carchek') {
            $obraId = (int)$_POST['obra_id'];
            $pdo->prepare("DELETE FROM obra_carchek WHERE obra_id=?")->execute([$obraId]);
            $msg = 'Carchek removido de la obra.';
        }

    } catch (Exception $e) {
        $msg = $e->getMessage(); $msgTipo = 'danger';
    }
    header("Location: obras_equipos.php?obra_id=".($_POST['obra_id']??'')."&msg=".urlencode($msg)."&mt=$msgTipo"); exit;
}

if (!empty($_GET['msg'])) { $msg = $_GET['msg']; $msgTipo = $_GET['mt'] ?? 'success'; }

/* ══ DATOS ══ */
$todasObras = $pdo->query("SELECT * FROM obras WHERE estado='activa' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);
$todosUsuarios = $pdo->query("SELECT id, nombre, usuario, rol, cargo FROM usuarios ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$todosConductores = $pdo->query("
    SELECT dc.*, dp.ppu, dp.metros_cubicos FROM despacho_conductores dc
    LEFT JOIN despacho_ppu dp ON dp.id=dc.ppu_id
    WHERE dc.activo=1 ORDER BY dc.nombre
")->fetchAll(PDO::FETCH_ASSOC);
$todasPPU = $pdo->query("SELECT * FROM despacho_ppu WHERE activo=1 ORDER BY ppu")->fetchAll(PDO::FETCH_ASSOC);
$todosExternos = $pdo->query("SELECT * FROM despacho_externos WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// Obras que mostrará según rol
$obrasVer = $esAdmin ? $todasObras : array_filter($todasObras, function($o) use ($misobras) {
    return in_array($o['id'], array_column($misobras, 'obra_id'));
});

// Obra seleccionada (filtro por URL)
$obraSelId = (int)($_GET['obra_id'] ?? ($obrasVer ? array_values($obrasVer)[0]['id'] : 0));

// Supervisores, equipo y Carchek por obra
function getEquipoObra($pdo, $obraId) {
    return [
        'supervisores' => $pdo->query("
            SELECT os.*, u.nombre, u.usuario, u.cargo, u.rol
            FROM obra_supervisores os JOIN usuarios u ON u.id=os.usuario_id
            WHERE os.obra_id=$obraId AND os.activo=1 ORDER BY u.nombre
        ")->fetchAll(PDO::FETCH_ASSOC),
        'conductores' => $pdo->query("
            SELECT oe.*, dc.nombre AS conductor_nombre, dc.rut, dp.ppu, dp.metros_cubicos, dp.activo AS ppu_ok
            FROM obra_equipo oe
            JOIN despacho_conductores dc ON dc.id=oe.conductor_id
            LEFT JOIN despacho_ppu dp ON dp.id=oe.ppu_id
            WHERE oe.obra_id=$obraId AND oe.activo=1 ORDER BY dc.nombre
        ")->fetchAll(PDO::FETCH_ASSOC),
        'carchek' => $pdo->query("
            SELECT oc.*, de.nombre, de.empresa, de.usuario, de.email
            FROM obra_carchek oc JOIN despacho_externos de ON de.id=oc.externo_id
            WHERE oc.obra_id=$obraId AND oc.activo=1 LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC),
    ];
}

// Stats tickets de hoy por obra (para encargado operaciones)
function getStatsHoy($pdo, $obraId) {
    $hoy = date('Y-m-d');
    $st = $pdo->prepare("SELECT estado, COUNT(*) c FROM tickets_despacho WHERE obra_id=? AND fecha=? GROUP BY estado");
    $st->execute([$obraId, $hoy]);
    $r = ['total'=>0,'enviado'=>0,'validado'=>0,'borrador'=>0];
    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $r[$row['estado']] = (int)$row['c'];
        $r['total'] += (int)$row['c'];
    }
    return $r;
}

require_once 'includes/header.php';
?>
<a href="/inicio.php" data-volver data-fallback="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver</a>

<style>
.obra-tab { cursor:pointer; padding:10px 16px; border-radius:10px 10px 0 0; font-weight:700; font-size:.9rem; border:1.5px solid transparent; border-bottom:none; color:var(--bs-secondary); transition:all .15s; white-space:nowrap; }
.obra-tab.activa { background:#fff; border-color:#dee2e6; color:#1a1a2e; box-shadow:0 -2px 6px rgba(0,0,0,.06); }
.obra-tab:hover:not(.activa) { background:#f8f9fa; color:#212529; }
.obra-panel { border:1.5px solid #dee2e6; border-radius:0 12px 12px 12px; background:#fff; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
.equipo-card { border:1px solid #dee2e6; border-radius:10px; padding:14px; margin-bottom:10px; background:#fafafa; }
.rol-badge-sup { background:#0d6efd; color:#fff; font-size:.72rem; padding:2px 8px; border-radius:999px; font-weight:700; }
.rol-badge-cond { background:#198754; color:#fff; font-size:.72rem; padding:2px 8px; border-radius:999px; font-weight:700; }
.rol-badge-carchek { background:#d97706; color:#fff; font-size:.72rem; padding:2px 8px; border-radius:999px; font-weight:700; }
.ppu-mini { font-family:monospace; font-weight:900; background:#1b2838; color:#fff; padding:2px 8px; border-radius:5px; letter-spacing:1px; font-size:.85rem; }
.stat-box { border-radius:10px; padding:10px 14px; text-align:center; flex:1; }
.section-lbl { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6c757d; margin-bottom:8px; }
</style>

<!-- Cabecera -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1"><i class="bi bi-building-fill text-primary me-2"></i>Obras · Equipos de Trabajo</h3>
    <p class="text-muted small mb-0">
      <?= $esAdmin ? 'Vista administrador — todas las obras' : 'Vista supervisor — tus obras asignadas' ?>
    </p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($esAdmin): ?>
    <a href="obras.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Nueva obra</a>
    <?php endif; ?>
    <a href="tickets_despacho_admin.php" class="btn btn-outline-dark btn-sm"><i class="bi bi-gear me-1"></i>Configuración</a>
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show">
  <?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (empty($obrasVer)): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>
  <?= $esAdmin ? 'No hay obras activas. <a href="obras.php">Créalas aquí.</a>' : 'No tienes obras asignadas. Contacta al administrador del módulo.' ?>
</div>
<?php else: ?>

<!-- Tabs de obras -->
<div class="d-flex gap-1 flex-wrap mb-0" style="overflow-x:auto">
  <?php foreach ($obrasVer as $o): ?>
  <a href="?obra_id=<?= $o['id'] ?>" class="obra-tab <?= $o['id']==$obraSelId?'activa':'' ?>">
    <i class="bi bi-building me-1"></i><?= htmlspecialchars($o['codigo']) ?>
    <small class="d-none d-md-inline text-muted fw-normal"> — <?= htmlspecialchars($o['nombre']) ?></small>
  </a>
  <?php endforeach; ?>
</div>

<?php
$obraActual = null;
foreach ($obrasVer as $o) { if ($o['id'] == $obraSelId) { $obraActual = $o; break; } }
if ($obraActual):
  $eq = getEquipoObra($pdo, $obraSelId);
  $stats = getStatsHoy($pdo, $obraSelId);
?>
<div class="obra-panel">

  <!-- Título obra -->
  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h4 class="fw-bold mb-1"><?= htmlspecialchars($obraActual['codigo'].' — '.$obraActual['nombre']) ?></h4>
      <?php if ($obraActual['ciudad']): ?><small class="text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($obraActual['ciudad']) ?></small><?php endif; ?>
    </div>
    <!-- Stats del día -->
    <div class="d-flex gap-2">
      <div class="stat-box" style="background:#f0fff4;border:1px solid #bbf7d0">
        <div style="font-size:1.4rem;font-weight:900;color:#15803d"><?= $stats['validado'] ?></div>
        <div style="font-size:.72rem;color:#15803d">Validados hoy</div>
      </div>
      <div class="stat-box" style="background:#fff8e1;border:1px solid #fde68a">
        <div style="font-size:1.4rem;font-weight:900;color:#b45309"><?= $stats['enviado'] ?></div>
        <div style="font-size:.72rem;color:#b45309">Pendientes</div>
      </div>
      <div class="stat-box" style="background:#f8f9fa;border:1px solid #dee2e6">
        <div style="font-size:1.4rem;font-weight:900;color:#374151"><?= $stats['total'] ?></div>
        <div style="font-size:.72rem;color:#6c757d">Total hoy</div>
      </div>
    </div>
  </div>

  <div class="row g-3">

    <!-- ══ COL 1: Supervisores ══ -->
    <?php if ($esAdmin): ?>
    <div class="col-lg-4">
      <div class="section-lbl"><i class="bi bi-person-check me-1"></i>Supervisores</div>
      <?php foreach ($eq['supervisores'] as $s): ?>
      <div class="equipo-card d-flex justify-content-between align-items-start">
        <div>
          <span class="rol-badge-sup">Supervisor</span>
          <strong class="d-block mt-1"><?= htmlspecialchars($s['nombre']) ?></strong>
          <small class="text-muted"><?= htmlspecialchars($s['cargo'] ?? $s['rol']) ?></small>
        </div>
        <form method="POST">
          <input type="hidden" name="accion" value="quitar_supervisor">
          <input type="hidden" name="obra_id" value="<?= $obraSelId ?>">
          <input type="hidden" name="usuario_id" value="<?= $s['usuario_id'] ?>">
          <button class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Quitar supervisor?')"><i class="bi bi-x-lg"></i></button>
        </form>
      </div>
      <?php endforeach; ?>
      <?php if (empty($eq['supervisores'])): ?>
      <p class="text-muted small">Sin supervisores asignados.</p>
      <?php endif; ?>
      <!-- Agregar supervisor -->
      <form method="POST" class="d-flex gap-2 mt-2">
        <input type="hidden" name="accion" value="asignar_supervisor">
        <input type="hidden" name="obra_id" value="<?= $obraSelId ?>">
        <select name="usuario_id" class="form-select form-select-sm" required>
          <option value="">— Agregar supervisor —</option>
          <?php foreach ($todosUsuarios as $u): ?>
          <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?> (<?= $u['cargo']?:$u['rol'] ?>)</option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-primary flex-shrink-0"><i class="bi bi-plus-lg"></i></button>
      </form>
    </div>
    <?php endif; ?>

    <!-- ══ COL 2: Equipo de conductores ══ -->
    <div class="col-lg-<?= $esAdmin?'4':'6' ?>">
      <div class="section-lbl"><i class="bi bi-people me-1"></i>Equipo de conductores</div>
      <?php foreach ($eq['conductores'] as $c): ?>
      <div class="equipo-card d-flex justify-content-between align-items-start">
        <div>
          <span class="rol-badge-cond">Conductor</span>
          <strong class="d-block mt-1"><?= htmlspecialchars($c['conductor_nombre']) ?></strong>
          <?php if ($c['ppu']): ?>
          <span class="ppu-mini"><?= htmlspecialchars($c['ppu']) ?></span>
          <?php if ($c['metros_cubicos']>0): ?><small class="text-muted ms-1"><?= number_format($c['metros_cubicos'],1,',','.') ?> m³</small><?php endif; ?>
          <?php else: ?><small class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Sin camión</small><?php endif; ?>
        </div>
        <div class="d-flex gap-1 flex-column align-items-end">
          <button class="btn btn-sm btn-outline-primary" onclick="abrirReasignarPPU(<?= $c['conductor_id'] ?>, '<?= htmlspecialchars(addslashes($c['conductor_nombre'])) ?>', <?= $obraSelId ?>)">
            <i class="bi bi-arrow-left-right"></i>
          </button>
          <form method="POST">
            <input type="hidden" name="accion" value="quitar_conductor">
            <input type="hidden" name="obra_id" value="<?= $obraSelId ?>">
            <input type="hidden" name="conductor_id" value="<?= $c['conductor_id'] ?>">
            <button class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Quitar del equipo?')"><i class="bi bi-x-lg"></i></button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($eq['conductores'])): ?><p class="text-muted small">Sin conductores en este equipo.</p><?php endif; ?>
      <!-- Agregar conductor -->
      <form method="POST" class="mt-2">
        <input type="hidden" name="accion" value="agregar_conductor">
        <input type="hidden" name="obra_id" value="<?= $obraSelId ?>">
        <div class="d-flex gap-2 mb-2">
          <select name="conductor_id" class="form-select form-select-sm" required>
            <option value="">— Seleccionar conductor —</option>
            <?php foreach ($todosConductores as $c): ?>
            <?php $yaEnObra = false; foreach($eq['conductores'] as $ec){ if($ec['conductor_id']==$c['id']){$yaEnObra=true;break;} } ?>
            <option value="<?= $c['id'] ?>" <?= $yaEnObra?'disabled':'' ?>>
              <?= htmlspecialchars($c['nombre']) ?><?= $c['ppu']?' — '.$c['ppu']:'' ?><?= $yaEnObra?' (ya en obra)':'' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-success flex-shrink-0"><i class="bi bi-person-plus"></i></button>
        </div>
        <select name="ppu_id" class="form-select form-select-sm">
          <option value="">— Asignar camión (opcional) —</option>
          <?php foreach ($todasPPU as $p): ?>
          <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['ppu']) ?> (<?= number_format($p['metros_cubicos'],1,',','.') ?> m³)</option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <!-- ══ COL 3: Carchek ══ -->
    <div class="col-lg-<?= $esAdmin?'4':'6' ?>">
      <div class="section-lbl"><i class="bi bi-qr-code-scan me-1"></i>Validador Carchek</div>
      <?php if ($eq['carchek']): $ck = $eq['carchek']; ?>
      <div class="equipo-card">
        <span class="rol-badge-carchek">Carchek</span>
        <strong class="d-block mt-1"><?= htmlspecialchars($ck['nombre']) ?></strong>
        <small class="text-muted"><?= htmlspecialchars($ck['empresa']) ?></small><br>
        <small><i class="bi bi-person me-1"></i><?= htmlspecialchars($ck['usuario']) ?></small>
        <?php if ($ck['email']): ?><br><small><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($ck['email']) ?></small><?php endif; ?>
        <form method="POST" class="mt-2">
          <input type="hidden" name="accion" value="quitar_carchek">
          <input type="hidden" name="obra_id" value="<?= $obraSelId ?>">
          <button class="btn btn-sm btn-outline-danger w-100" onclick="return confirm('¿Quitar Carchek de esta obra?')">
            <i class="bi bi-x-lg me-1"></i>Quitar Carchek
          </button>
        </form>
      </div>
      <?php else: ?>
      <p class="text-muted small">Sin validador Carchek asignado.</p>
      <form method="POST">
        <input type="hidden" name="accion" value="asignar_carchek">
        <input type="hidden" name="obra_id" value="<?= $obraSelId ?>">
        <div class="d-flex gap-2">
          <select name="externo_id" class="form-select form-select-sm" required>
            <option value="">— Seleccionar Carchek —</option>
            <?php foreach ($todosExternos as $e): ?>
            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?> (<?= htmlspecialchars($e['empresa']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-warning flex-shrink-0 fw-bold"><i class="bi bi-qr-code-scan"></i></button>
        </div>
      </form>
      <?php endif; ?>

      <!-- Historial tickets hoy por conductor -->
      <?php if (!empty($eq['conductores'])): ?>
      <div class="section-lbl mt-3"><i class="bi bi-clock me-1"></i>Actividad hoy</div>
      <?php foreach ($eq['conductores'] as $c):
        $stC = $pdo->prepare("SELECT COUNT(*) FROM tickets_despacho WHERE conductor_id=? AND fecha=? AND estado='validado'");
        $stC->execute([$c['conductor_id'], date('Y-m-d')]);
        $vhoy = (int)$stC->fetchColumn();
        $stCt = $pdo->prepare("SELECT COUNT(*) FROM tickets_despacho WHERE conductor_id=? AND fecha=?");
        $stCt->execute([$c['conductor_id'], date('Y-m-d')]);
        $thoy = (int)$stCt->fetchColumn();
      ?>
      <div class="d-flex align-items-center justify-content-between py-1 border-bottom" style="font-size:.85rem">
        <span><?= htmlspecialchars($c['conductor_nombre']) ?></span>
        <span>
          <span class="badge bg-success"><?= $vhoy ?> val</span>
          <span class="badge bg-secondary ms-1"><?= $thoy ?> total</span>
        </span>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div><!-- /row -->
</div><!-- /obra-panel -->
<?php endif; ?>
<?php endif; ?>

<!-- Modal reasignar PPU a conductor desde esta pantalla -->
<div class="modal fade" id="modalReasignarPPU" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>Cambiar camión</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="accion" value="agregar_conductor">
        <input type="hidden" name="conductor_id" id="rpCondId">
        <input type="hidden" name="obra_id" id="rpObraId">
        <div class="modal-body">
          <p class="mb-3">Conductor: <strong id="rpCondNom"></strong></p>
          <label class="form-label fw-semibold">Nuevo camión</label>
          <select name="ppu_id" class="form-select" required>
            <option value="">— Seleccionar —</option>
            <?php foreach ($todasPPU as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['ppu']) ?> (<?= number_format($p['metros_cubicos'],1,',','.') ?> m³)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary fw-bold"><i class="bi bi-check-circle me-1"></i>Confirmar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function abrirReasignarPPU(condId, condNom, obraId) {
  document.getElementById('rpCondId').value = condId;
  document.getElementById('rpObraId').value = obraId;
  document.getElementById('rpCondNom').textContent = condNom;
  new bootstrap.Modal(document.getElementById('modalReasignarPPU')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
