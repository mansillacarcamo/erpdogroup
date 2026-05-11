<?php
require_once 'config.php';
requireAuth();
requireModulo('despacho', $usuario, $pdo);
requireDespachoAdmin($usuario, $pdo);

/* ══════════════════════════════════════════════════════════
   TABLA DE REASIGNACIONES (crear si no existe)
══════════════════════════════════════════════════════════ */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS despacho_asignaciones (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    conductor_id  INTEGER NOT NULL,
    ppu_id        INTEGER NOT NULL,
    obra_id       INTEGER,
    motivo        TEXT    DEFAULT '',
    activa        INTEGER DEFAULT 1,
    asignado_por  TEXT    NOT NULL,
    fecha_desde   DATETIME DEFAULT (datetime('now','localtime')),
    fecha_hasta   DATETIME,
    creado_en     DATETIME DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (conductor_id) REFERENCES despacho_conductores(id) ON DELETE CASCADE,
    FOREIGN KEY (ppu_id)       REFERENCES despacho_ppu(id)
  )
");

$msg = null; $msgTipo = 'success';

/* ══ POST ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';
  try {

    /* ── Asignar camión a conductor ── */
    if ($accion === 'asignar') {
      $condId = (int)($_POST['conductor_id'] ?? 0);
      $ppuId  = (int)($_POST['ppu_id']  ?? 0);
      $obraId = (int)($_POST['obra_id'] ?? 0) ?: null;
      $motivo = trim($_POST['motivo'] ?? '');
      if (!$condId || !$ppuId) throw new Exception('Selecciona conductor y camión.');

      // Desactivar asignaciones previas activas de ese conductor
      $pdo->prepare("UPDATE despacho_asignaciones SET activa=0, fecha_hasta=datetime('now','localtime') WHERE conductor_id=? AND activa=1")
          ->execute([$condId]);

      // Crear nueva asignación
      $pdo->prepare("INSERT INTO despacho_asignaciones (conductor_id, ppu_id, obra_id, motivo, activa, asignado_por) VALUES (?,?,?,?,1,?)")
          ->execute([$condId, $ppuId, $obraId, $motivo, $usuario['nombre']]);

      // Sincronizar con tabla conductores (para compatibilidad con el resto del sistema)
      $pdo->prepare("UPDATE despacho_conductores SET ppu_id=?, obra_id=? WHERE id=?")
          ->execute([$ppuId, $obraId, $condId]);

      // Nombre del conductor y PPU para el mensaje
      $cNom = $pdo->prepare("SELECT nombre FROM despacho_conductores WHERE id=?");
      $cNom->execute([$condId]); $cNom = $cNom->fetchColumn();
      $pNom = $pdo->prepare("SELECT ppu FROM despacho_ppu WHERE id=?");
      $pNom->execute([$ppuId]); $pNom = $pNom->fetchColumn();

      $msg = "✅ $cNom asignado al camión $pNom correctamente.";
    }

    /* ── Liberar camión de conductor (poner en pana) ── */
    if ($accion === 'liberar') {
      $condId = (int)($_POST['conductor_id'] ?? 0);
      $motivo = trim($_POST['motivo'] ?? 'Camión en pana');
      $pdo->prepare("UPDATE despacho_asignaciones SET activa=0, fecha_hasta=datetime('now','localtime') WHERE conductor_id=? AND activa=1")
          ->execute([$condId]);
      $pdo->prepare("UPDATE despacho_conductores SET ppu_id=NULL WHERE id=?")
          ->execute([$condId]);

      $cNom = $pdo->prepare("SELECT nombre FROM despacho_conductores WHERE id=?");
      $cNom->execute([$condId]); $cNom = $cNom->fetchColumn();
      $msg = "🔧 $cNom liberado de su camión. Motivo: $motivo";
    }

    /* ── Marcar camión en pana ── */
    if ($accion === 'pana') {
      $ppuId  = (int)($_POST['ppu_id'] ?? 0);
      $motivo = trim($_POST['motivo'] ?? '');
      // Liberar a todos los conductores con ese camión
      $st = $pdo->prepare("SELECT conductor_id FROM despacho_asignaciones WHERE ppu_id=? AND activa=1");
      $st->execute([$ppuId]);
      $conductores = $st->fetchAll(PDO::FETCH_COLUMN);
      foreach ($conductores as $cid) {
        $pdo->prepare("UPDATE despacho_asignaciones SET activa=0, fecha_hasta=datetime('now','localtime') WHERE conductor_id=? AND activa=1")
            ->execute([$cid]);
        $pdo->prepare("UPDATE despacho_conductores SET ppu_id=NULL WHERE id=?")
            ->execute([$cid]);
      }
      // Marcar PPU como inactiva temporalmente
      $pdo->prepare("UPDATE despacho_ppu SET activo=0 WHERE id=?")
          ->execute([$ppuId]);

      $pNom = $pdo->prepare("SELECT ppu FROM despacho_ppu WHERE id=?");
      $pNom->execute([$ppuId]); $pNom = $pNom->fetchColumn();
      $msg = "🔧 Camión $pNom marcado en pana. ".count($conductores)." conductor(es) liberado(s).";
    }

    /* ── Reactivar camión (salió de pana) ── */
    if ($accion === 'reactivar_ppu') {
      $ppuId = (int)($_POST['ppu_id'] ?? 0);
      $pdo->prepare("UPDATE despacho_ppu SET activo=1 WHERE id=?")
          ->execute([$ppuId]);
      $pNom = $pdo->prepare("SELECT ppu FROM despacho_ppu WHERE id=?");
      $pNom->execute([$ppuId]); $pNom = $pNom->fetchColumn();
      $msg = "✅ Camión $pNom reactivado. Ya puede ser asignado.";
    }

  } catch (Exception $e) {
    $msg = $e->getMessage(); $msgTipo = 'danger';
  }
  header("Location: asignar_camion.php?msg=".urlencode($msg)."&mt=$msgTipo"); exit;
}

if (!empty($_GET['msg'])) { $msg = $_GET['msg']; $msgTipo = $_GET['mt'] ?? 'success'; }

/* ══ DATOS ══ */
$conductores = $pdo->query("
  SELECT dc.*, dp.ppu, dp.metros_cubicos, dp.activo AS ppu_activa,
         o.codigo AS obra_codigo, o.nombre AS obra_nombre
  FROM despacho_conductores dc
  LEFT JOIN despacho_ppu dp ON dp.id = dc.ppu_id
  LEFT JOIN obras o          ON o.id  = dc.obra_id
  WHERE dc.activo = 1
  ORDER BY dc.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$ppus = $pdo->query("SELECT * FROM despacho_ppu ORDER BY activo DESC, ppu")->fetchAll(PDO::FETCH_ASSOC);
$obras = $pdo->query("SELECT id, codigo, nombre FROM obras WHERE estado='activa' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);

// Historial de reasignaciones (últimas 30)
$historial = $pdo->query("
  SELECT da.*, dc.nombre AS conductor_nombre, dp.ppu AS ppu_texto,
         o.codigo AS obra_codigo, o.nombre AS obra_nombre_h
  FROM despacho_asignaciones da
  JOIN despacho_conductores dc ON dc.id = da.conductor_id
  JOIN despacho_ppu dp          ON dp.id = da.ppu_id
  LEFT JOIN obras o             ON o.id  = da.obra_id
  ORDER BY da.creado_en DESC
  LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// Estado actual: qué camión tiene cada conductor
$asignadas = [];
$st = $pdo->query("
  SELECT da.conductor_id, da.ppu_id, dp.ppu, dp.metros_cubicos, dp.activo AS ppu_ok
  FROM despacho_asignaciones da
  JOIN despacho_ppu dp ON dp.id = da.ppu_id
  WHERE da.activa = 1
");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $asignadas[$r['conductor_id']] = $r;
}

require_once 'includes/header.php';
?>
<a href="/inicio.php" data-volver data-fallback="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver</a>


<style>
.cam-card { border:1.5px solid #dee2e6; border-radius:14px; padding:16px; margin-bottom:12px; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.06); transition:box-shadow .2s; }
.cam-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.1); }
.cam-card.en-pana { border-color:#dc3545; background:#fff5f5; }
.cam-card.sin-camion { border-color:#ffc107; background:#fffdf0; }
.cam-card.asignado { border-color:#198754; background:#f0fff4; }
.ppu-tag { font-family:monospace; font-weight:900; font-size:1.05rem; background:#1b2838; color:#fff; padding:4px 12px; border-radius:8px; letter-spacing:2px; }
.ppu-tag.pana { background:#dc3545; }
.estado-dot { width:10px; height:10px; border-radius:50%; display:inline-block; margin-right:6px; }
.dot-ok   { background:#22c55e; }
.dot-pana { background:#dc3545; animation:pulse-red 1.5s infinite; }
.dot-libre{ background:#f59e0b; }
@keyframes pulse-red { 0%,100%{opacity:1} 50%{opacity:.4} }
.hist-row { font-size:.83rem; }
.seccion-title { font-size:1rem; font-weight:700; color:#1a1a2e; border-bottom:2px solid #1a1a2e; padding-bottom:6px; margin-bottom:16px; }
</style>

<!-- Cabecera -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1"><i class="bi bi-arrow-left-right text-warning me-2"></i>Asignación de Camiones</h3>
    <p class="text-muted mb-0 small">Asigna, reasigna o marca en pana los camiones del módulo de despacho</p>
  </div>
  <a href="tickets_despacho_admin.php" class="btn btn-outline-dark btn-sm">
    <i class="bi bi-gear me-1"></i>Volver a Configuración
  </a>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show">
  <?= htmlspecialchars($msg) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3">

  <!-- ══ COL IZQUIERDA: Estado actual ══ -->
  <div class="col-lg-7">
    <div class="seccion-title"><i class="bi bi-person-badge me-2"></i>Estado actual de conductores</div>

    <?php if (empty($conductores)): ?>
    <div class="alert alert-info">No hay conductores registrados. <a href="tickets_despacho_admin.php">Agrégalos en configuración.</a></div>
    <?php endif; ?>

    <?php foreach ($conductores as $c):
      $asig = $asignadas[$c['id']] ?? null;
      $tieneCarmon = $asig && $asig['ppu_ok'];
      $enPana = $asig && !$asig['ppu_ok'];
      $cardClass = $tieneCarmon ? 'asignado' : ($enPana ? 'en-pana' : 'sin-camion');
    ?>
    <div class="cam-card <?= $cardClass ?>">
      <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
        <div>
          <!-- Estado dot -->
          <?php if ($tieneCarmon): ?>
            <span class="estado-dot dot-ok"></span><strong><?= htmlspecialchars($c['nombre']) ?></strong>
            <span class="badge bg-success ms-1">Con camión</span>
          <?php elseif ($enPana): ?>
            <span class="estado-dot dot-pana"></span><strong><?= htmlspecialchars($c['nombre']) ?></strong>
            <span class="badge bg-danger ms-1">Camión en pana</span>
          <?php else: ?>
            <span class="estado-dot dot-libre"></span><strong><?= htmlspecialchars($c['nombre']) ?></strong>
            <span class="badge bg-warning text-dark ms-1">Sin camión</span>
          <?php endif; ?>

          <?php if ($c['rut']): ?>
          <small class="text-muted ms-2">RUT <?= htmlspecialchars($c['rut']) ?></small>
          <?php endif; ?>

          <!-- Camión actual -->
          <?php if ($asig): ?>
          <div class="mt-2">
            <span class="ppu-tag <?= $asig['ppu_ok'] ? '' : 'pana' ?>"><?= htmlspecialchars($asig['ppu']) ?></span>
            <?php if ($asig['metros_cubicos'] > 0): ?>
            <span class="badge bg-info text-dark ms-1"><?= number_format($asig['metros_cubicos'],1,',','.') ?> m³</span>
            <?php endif; ?>
            <?php if (!$asig['ppu_ok']): ?>
            <span class="badge bg-danger ms-1"><i class="bi bi-tools me-1"></i>En pana</span>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($c['obra_nombre']): ?>
          <div class="mt-1"><small class="text-muted"><i class="bi bi-building me-1"></i><?= htmlspecialchars($c['obra_codigo'].' — '.$c['obra_nombre']) ?></small></div>
          <?php endif; ?>
        </div>

        <!-- Acciones rápidas -->
        <div class="d-flex gap-2 flex-wrap">
          <!-- Botón Asignar / Reasignar -->
          <button class="btn btn-sm btn-primary fw-bold"
            onclick="abrirAsignar(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['nombre'])) ?>', <?= $asig ? $asig['ppu_id'] : 0 ?>)">
            <i class="bi bi-arrow-left-right me-1"></i><?= $asig ? 'Reasignar' : 'Asignar camión' ?>
          </button>
          <!-- Liberar conductor (solo si tiene camión) -->
          <?php if ($asig): ?>
          <button class="btn btn-sm btn-outline-warning fw-bold"
            onclick="abrirLiberar(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['nombre'])) ?>')">
            <i class="bi bi-person-dash me-1"></i>Liberar
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- ══ Estado camiones ══ -->
    <div class="seccion-title mt-4"><i class="bi bi-truck-front-fill me-2"></i>Estado de camiones</div>
    <div class="row g-2">
    <?php foreach ($ppus as $p):
      // Ver si está asignado y a quién
      $condAsig = null;
      foreach ($asignadas as $cid => $a) {
        if ($a['ppu_id'] == $p['id']) {
          foreach ($conductores as $c) {
            if ($c['id'] == $cid) { $condAsig = $c; break; }
          }
          break;
        }
      }
    ?>
    <div class="col-sm-6">
      <div class="cam-card p-3 <?= !$p['activo'] ? 'en-pana' : ($condAsig ? 'asignado' : 'sin-camion') ?>">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <span class="ppu-tag <?= !$p['activo'] ? 'pana' : '' ?>"><?= htmlspecialchars($p['ppu']) ?></span>
            <?php if ($p['metros_cubicos'] > 0): ?>
            <span class="badge bg-info text-dark ms-1"><?= number_format($p['metros_cubicos'],1,',','.') ?> m³</span>
            <?php endif; ?>
            <div class="mt-1">
            <?php if (!$p['activo']): ?>
              <span class="badge bg-danger"><i class="bi bi-tools me-1"></i>En pana</span>
            <?php elseif ($condAsig): ?>
              <span class="badge bg-success"><i class="bi bi-person-check me-1"></i><?= htmlspecialchars($condAsig['nombre']) ?></span>
            <?php else: ?>
              <span class="badge bg-warning text-dark">Disponible</span>
            <?php endif; ?>
            </div>
          </div>
          <div class="d-flex flex-column gap-1">
            <?php if ($p['activo']): ?>
            <button class="btn btn-sm btn-outline-danger" onclick="abrirPana(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['ppu'])) ?>')">
              <i class="bi bi-tools"></i> Pana
            </button>
            <?php else: ?>
            <form method="POST">
              <input type="hidden" name="accion" value="reactivar_ppu">
              <input type="hidden" name="ppu_id" value="<?= $p['id'] ?>">
              <button class="btn btn-sm btn-success"><i class="bi bi-check-circle me-1"></i>Reactivar</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>

  <!-- ══ COL DERECHA: Historial ══ -->
  <div class="col-lg-5">
    <div class="seccion-title"><i class="bi bi-clock-history me-2"></i>Historial de asignaciones</div>
    <?php if (empty($historial)): ?>
    <p class="text-muted small">Aún no hay asignaciones registradas.</p>
    <?php else: ?>
    <div class="table-responsive" style="max-height:600px;overflow-y:auto;">
      <table class="table table-sm align-middle" style="font-size:.82rem;">
        <thead class="table-dark sticky-top">
          <tr>
            <th>Conductor</th>
            <th>Camión</th>
            <th>Estado</th>
            <th>Fecha</th>
            <th>Por</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($historial as $h): ?>
        <tr class="hist-row <?= $h['activa'] ? 'table-success' : '' ?>">
          <td><?= htmlspecialchars($h['conductor_nombre']) ?></td>
          <td><span class="ppu-tag" style="font-size:.8rem;padding:2px 7px"><?= htmlspecialchars($h['ppu_texto']) ?></span></td>
          <td>
            <?php if ($h['activa']): ?>
            <span class="badge bg-success">Activa</span>
            <?php else: ?>
            <span class="badge bg-secondary">Finalizada</span>
            <?php endif; ?>
            <?php if ($h['motivo']): ?>
            <br><small class="text-muted fst-italic"><?= htmlspecialchars($h['motivo']) ?></small>
            <?php endif; ?>
          </td>
          <td>
            <small><?= date('d/m H:i', strtotime($h['fecha_desde'])) ?></small>
            <?php if ($h['fecha_hasta']): ?>
            <br><small class="text-muted">→ <?= date('d/m H:i', strtotime($h['fecha_hasta'])) ?></small>
            <?php endif; ?>
          </td>
          <td><small class="text-muted"><?= htmlspecialchars($h['asignado_por']) ?></small></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div><!-- /row -->

<!-- ══ MODAL: Asignar / Reasignar ══ -->
<div class="modal fade" id="modalAsignar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header text-white fw-bold" style="background:#1a1a2e">
        <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i><span id="asignarTitulo">Asignar camión</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="accion" value="asignar">
        <input type="hidden" name="conductor_id" id="asignarCondId">
        <div class="modal-body">
          <div class="alert alert-primary py-2 mb-3">
            <i class="bi bi-person-badge me-1"></i>Conductor: <strong id="asignarCondNom"></strong>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Camión a asignar *</label>
            <select name="ppu_id" id="asignarPpu" class="form-select" required>
              <option value="">— Seleccionar camión —</option>
              <?php foreach ($ppus as $p): ?>
              <option value="<?= $p['id'] ?>" data-m3="<?= $p['metros_cubicos'] ?>" <?= !$p['activo'] ? 'disabled' : '' ?>>
                <?= htmlspecialchars($p['ppu']) ?>
                <?= $p['metros_cubicos'] > 0 ? ' ('.number_format($p['metros_cubicos'],1,',','.').' m³)' : '' ?>
                <?= !$p['activo'] ? ' — EN PANA' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Obra <span class="text-muted fw-normal">(opcional)</span></label>
            <select name="obra_id" class="form-select">
              <option value="">— Sin obra específica —</option>
              <?php foreach ($obras as $o): ?>
              <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Motivo de la reasignación <span class="text-muted fw-normal">(opcional)</span></label>
            <input type="text" name="motivo" class="form-control" placeholder="Ej: Camión anterior en pana, rotación de turno...">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary fw-bold px-4">
            <i class="bi bi-check-circle-fill me-1"></i>Confirmar asignación
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ MODAL: Liberar conductor ══ -->
<div class="modal fade" id="modalLiberar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-warning text-dark fw-bold">
        <h5 class="modal-title"><i class="bi bi-person-dash me-2"></i>Liberar conductor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="accion" value="liberar">
        <input type="hidden" name="conductor_id" id="liberarCondId">
        <div class="modal-body">
          <p>¿Deseas liberar a <strong id="liberarCondNom"></strong> de su camión actual?</p>
          <div class="mb-3">
            <label class="form-label fw-semibold">Motivo</label>
            <input type="text" name="motivo" class="form-control" value="Camión en pana" placeholder="Motivo de la liberación">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-warning fw-bold">
            <i class="bi bi-person-dash me-1"></i>Confirmar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══ MODAL: Pana camión ══ -->
<div class="modal fade" id="modalPana" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-danger text-white fw-bold">
        <h5 class="modal-title"><i class="bi bi-tools me-2"></i>Marcar camión en pana</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="accion" value="pana">
        <input type="hidden" name="ppu_id" id="panaPpuId">
        <div class="modal-body">
          <div class="alert alert-danger py-2 mb-3">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Camión: <strong id="panaPpuNom"></strong><br>
            <small>Todos los conductores asignados a este camión quedarán sin vehículo.</small>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Motivo / descripción de la falla</label>
            <input type="text" name="motivo" class="form-control" placeholder="Ej: Motor fundido, pinchazo, accidente..." required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger fw-bold">
            <i class="bi bi-tools me-1"></i>Confirmar pana
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function abrirAsignar(condId, condNom, ppuActualId) {
  document.getElementById('asignarCondId').value = condId;
  document.getElementById('asignarCondNom').textContent = condNom;
  document.getElementById('asignarTitulo').textContent = ppuActualId ? 'Reasignar camión' : 'Asignar camión';
  var sel = document.getElementById('asignarPpu');
  if (ppuActualId) sel.value = ppuActualId;
  new bootstrap.Modal(document.getElementById('modalAsignar')).show();
}

function abrirLiberar(condId, condNom) {
  document.getElementById('liberarCondId').value = condId;
  document.getElementById('liberarCondNom').textContent = condNom;
  new bootstrap.Modal(document.getElementById('modalLiberar')).show();
}

function abrirPana(ppuId, ppuNom) {
  document.getElementById('panaPpuId').value = ppuId;
  document.getElementById('panaPpuNom').textContent = ppuNom;
  new bootstrap.Modal(document.getElementById('modalPana')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
