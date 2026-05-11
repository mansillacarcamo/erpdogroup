<?php
require_once 'config.php';
requireAuth();

// Verificar que es supervisor
$stSup = $pdo->prepare("SELECT os.obra_id, o.codigo, o.nombre FROM obra_supervisores os JOIN obras o ON o.id=os.obra_id WHERE os.usuario_id=? AND os.activo=1");
$stSup->execute([$usuario['id']]);
$misObras = $stSup->fetchAll(PDO::FETCH_ASSOC);
$esAdmin  = ($usuario['rol'] === 'admin') || isDespachoAdmin($usuario, $pdo);
if (empty($misObras) && !$esAdmin) { header('Location: inicio.php'); exit; }

$mes  = $_GET['mes'] ?? date('Y-m');
[$anio, $mesN] = explode('-', $mes);

$obraId = (int)($_GET['obra_id'] ?? ($misObras ? $misObras[0]['obra_id'] : 0));

$msg = null; $msgTipo = 'success';

/* ══ POST ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // Repartir presupuesto a conductor/operador
    if ($accion === 'asignar') {
        try {
            $presId  = (int)($_POST['presupuesto_id'] ?? 0);
            $tipo    = $_POST['tipo_receptor'] ?? 'conductor';
            $recId   = (int)($_POST['receptor_id'] ?? 0);
            $monto   = (float)str_replace(['.','$',' '],['','',''], $_POST['monto'] ?? '0');
            if ($monto <= 0) throw new Exception('El monto debe ser mayor a 0.');

            // Verificar saldo disponible
            $stP = $pdo->prepare("SELECT monto_asignado, obra_id, anio, mes FROM faena_presupuestos WHERE id=? AND supervisor_id=?");
            $stP->execute([$presId, $usuario['id']]);
            $pres = $stP->fetch(PDO::FETCH_ASSOC);
            if (!$pres) throw new Exception('Presupuesto no encontrado.');

            $totalAsignado = (float)$pdo->prepare("SELECT COALESCE(SUM(monto_asignado),0) FROM faena_asignaciones WHERE presupuesto_id=? AND activa=1")->execute([$presId]) ? $pdo->query("SELECT COALESCE(SUM(monto_asignado),0) FROM faena_asignaciones WHERE presupuesto_id=$presId AND activa=1")->fetchColumn() : 0;
            if ($totalAsignado + $monto > $pres['monto_asignado']) throw new Exception('Monto supera el presupuesto disponible ($'.number_format($pres['monto_asignado']-$totalAsignado,0,',','.').')');

            $condId = $tipo === 'conductor' ? $recId : null;
            $operId = $tipo === 'operador'  ? $recId : null;

            // Nombre del receptor
            $nomRec = '';
            if ($condId) { $stR=$pdo->prepare("SELECT nombre FROM despacho_conductores WHERE id=?"); $stR->execute([$condId]); $nomRec=$stR->fetchColumn(); }
            if ($operId) { $stR=$pdo->prepare("SELECT nombre FROM faena_operadores WHERE id=?"); $stR->execute([$operId]); $nomRec=$stR->fetchColumn(); }

            // Desactivar asignación previa del mismo receptor en el mismo mes
            $pdo->prepare("UPDATE faena_asignaciones SET activa=0 WHERE presupuesto_id=? AND conductor_id IS NOT DISTINCT FROM ? AND operador_id IS NOT DISTINCT FROM ? AND activa=1")
                ->execute([$presId, $condId, $operId]);

            $pdo->prepare("INSERT INTO faena_asignaciones (presupuesto_id,supervisor_id,tipo_receptor,conductor_id,operador_id,nombre_receptor,obra_id,anio,mes,monto_asignado,activa)
                VALUES (?,?,?,?,?,?,?,?,?,?,1)")
                ->execute([$presId,$usuario['id'],$tipo,$condId,$operId,$nomRec,$pres['obra_id'],$pres['anio'],$pres['mes'],$monto]);

            $msg = "✅ $monto asignado a $nomRec correctamente.";
        } catch(Exception $e) { $msg = $e->getMessage(); $msgTipo = 'danger'; }
    }

    // Aprobar gasto
    if ($accion === 'aprobar_gasto') {
        $gid = (int)($_POST['gasto_id'] ?? 0);
        $obs = trim($_POST['obs'] ?? '');
        $pdo->prepare("UPDATE gastos_faena SET estado='aprobado',aprobado_supervisor=1,aprobado_supervisor_en=datetime('now','localtime'),aprobado_por_nombre=?,observacion_aprobacion=? WHERE id=?")
            ->execute([$usuario['nombre'],$obs,$gid]);
        $msg = '✅ Gasto aprobado.';
    }

    if ($accion === 'rechazar_gasto') {
        $gid = (int)($_POST['gasto_id'] ?? 0);
        $obs = trim($_POST['obs'] ?? '');
        $pdo->prepare("UPDATE gastos_faena SET estado='rechazado',aprobado_por_nombre=?,observacion_aprobacion=? WHERE id=?")
            ->execute([$usuario['nombre'],$obs,$gid]);
        $msg = 'Gasto rechazado.'; $msgTipo = 'warning';
    }

    header("Location: faena_supervisor.php?mes=$mes&obra_id=$obraId&msg=".urlencode($msg)."&mt=$msgTipo"); exit;
}

if (!empty($_GET['msg'])) { $msg = $_GET['msg']; $msgTipo = $_GET['mt'] ?? 'success'; }

// Presupuesto del supervisor para esta obra/mes
$stPres = $pdo->prepare("SELECT * FROM faena_presupuestos WHERE supervisor_id=? AND obra_id=? AND anio=? AND mes=? LIMIT 1");
$stPres->execute([$usuario['id'],$obraId,$anio,$mesN]);
$presupuesto = $stPres->fetch(PDO::FETCH_ASSOC);

// Asignaciones activas
$asignaciones = [];
if ($presupuesto) {
    $stA = $pdo->prepare("SELECT * FROM faena_asignaciones WHERE presupuesto_id=? AND activa=1 ORDER BY creado_en DESC");
    $stA->execute([$presupuesto['id']]);
    $asignaciones = $stA->fetchAll(PDO::FETCH_ASSOC);
}

$totalAsignado = array_sum(array_column($asignaciones,'monto_asignado'));
$saldoSup = $presupuesto ? (float)$presupuesto['monto_asignado'] - $totalAsignado : 0;

// Gastos del equipo
$stG = $pdo->prepare("
    SELECT g.*, a.ruta AS arch
    FROM gastos_faena g
    LEFT JOIN gastos_faena_archivos a ON a.id=(SELECT MIN(id) FROM gastos_faena_archivos WHERE gasto_id=g.id)
    WHERE g.obra_id=? AND strftime('%Y-%m',g.fecha)=?
      AND (g.supervisor_id=? OR g.asignacion_id IN (SELECT id FROM faena_asignaciones WHERE presupuesto_id=?))
    ORDER BY g.fecha DESC, g.creado_en DESC
");
$stG->execute([$obraId,$mes,$usuario['id'],$presupuesto['id']??0]);
$gastos = $stG->fetchAll(PDO::FETCH_ASSOC);

// Conductores y operadores de la obra
$conductores = $pdo->prepare("SELECT dc.id, dc.nombre FROM despacho_conductores dc JOIN obra_equipo oe ON oe.conductor_id=dc.id WHERE oe.obra_id=? AND oe.activo=1 ORDER BY dc.nombre");
$conductores->execute([$obraId]); $conductores = $conductores->fetchAll(PDO::FETCH_ASSOC);

$operadores = $pdo->prepare("SELECT id, nombre FROM faena_operadores WHERE obra_id=? AND activo=1 ORDER BY nombre");
$operadores->execute([$obraId]); $operadores = $operadores->fetchAll(PDO::FETCH_ASSOC);

$totalGastos = array_sum(array_column($gastos,'monto')) + array_sum(array_column($gastos,'monto_peaje'));
$cntPend     = count(array_filter($gastos, fn($g)=>$g['estado']==='pendiente'));

require_once 'includes/header.php';
?>
<style>
.sup-card{background:#fff;border:1px solid #dee2e6;border-radius:14px;padding:16px;margin-bottom:12px;box-shadow:0 1px 5px rgba(0,0,0,.06)}
.asig-row{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f0f0f0;gap:10px}
.asig-row:last-child{border:none;padding-bottom:0}
.asig-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem;flex-shrink:0}
.asig-av-c{background:#dbeafe;color:#1e40af}
.asig-av-o{background:#d1fae5;color:#065f46}
.gasto-mini{background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:10px 12px;margin-bottom:8px;border-left:4px solid #dee2e6}
.gasto-mini.pendiente{border-left-color:#fbbf24}
.gasto-mini.aprobado{border-left-color:#16a34a}
.gasto-mini.rechazado{border-left-color:#dc2626}
.stat-sup{border-radius:10px;padding:10px 12px;text-align:center;flex:1}
.prog{height:8px;background:#f3f4f6;border-radius:4px;overflow:hidden;margin-top:8px}
.prog-fill{height:100%;border-radius:4px;background:#d97706;transition:width .5s}
</style>

<a href="inicio.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="bi bi-arrow-left me-1"></i>Volver</a>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1"><i class="bi bi-person-check-fill text-warning me-2"></i>Panel Supervisor — Faena</h3>
    <p class="text-muted small mb-0">Gestiona el presupuesto y aprueba los gastos de tu equipo</p>
  </div>
  <div class="d-flex gap-2">
    <input type="month" class="form-control form-control-sm" value="<?= $mes ?>"
           onchange="location.href='?mes='+this.value+'&obra_id=<?= $obraId ?>'">
  </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show">
  <?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Tabs de obras -->
<?php if (count($misObras) > 1 || $esAdmin): ?>
<div class="d-flex gap-1 flex-wrap mb-3">
  <?php foreach ($misObras as $ob): ?>
  <a href="?mes=<?= $mes ?>&obra_id=<?= $ob['obra_id'] ?>"
     class="btn btn-sm <?= $ob['obra_id']==$obraId?'btn-warning fw-bold':'btn-outline-secondary' ?>">
    <?= htmlspecialchars($ob['codigo']) ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">

  <!-- Columna izquierda: presupuesto + asignaciones -->
  <div class="col-lg-5">

    <!-- Presupuesto -->
    <div class="sup-card">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-wallet2 me-1 text-warning"></i>Mi presupuesto <?= $mes ?></h6>
      </div>
      <?php if ($presupuesto): ?>
      <div class="d-flex gap-2 mb-2">
        <div class="stat-sup" style="background:#fff3cd;border:1px solid #fde68a">
          <div style="font-size:1.2rem;font-weight:900;color:#d97706">$<?= number_format($presupuesto['monto_asignado'],0,',','.') ?></div>
          <div style="font-size:.68rem;color:#92400e;font-weight:700">Asignado</div>
        </div>
        <div class="stat-sup" style="background:#f0fff4;border:1px solid #bbf7d0">
          <div style="font-size:1.2rem;font-weight:900;color:#15803d">$<?= number_format($saldoSup,0,',','.') ?></div>
          <div style="font-size:.68rem;color:#065f46;font-weight:700">Disponible</div>
        </div>
        <div class="stat-sup" style="background:#f8f9fa;border:1px solid #dee2e6">
          <div style="font-size:1.2rem;font-weight:900">$<?= number_format($totalGastos,0,',','.') ?></div>
          <div style="font-size:.68rem;color:#6b7280;font-weight:700">Gastado</div>
        </div>
      </div>
      <?php $pct = $presupuesto['monto_asignado']>0 ? min(100,round($totalAsignado/$presupuesto['monto_asignado']*100)) : 0; ?>
      <div class="prog"><div class="prog-fill" style="width:<?= $pct ?>%"></div></div>
      <small class="text-muted"><?= $pct ?>% asignado al equipo</small>
      <?php else: ?>
      <div class="alert alert-warning py-2 mb-0">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Sin presupuesto asignado por el administrador.
      </div>
      <?php endif; ?>
    </div>

    <!-- Equipo: asignaciones -->
    <div class="sup-card">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="fw-bold mb-0"><i class="bi bi-people-fill me-1"></i>Mi equipo</h6>
        <?php if ($presupuesto && $saldoSup > 0): ?>
        <button class="btn btn-sm btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#modalAsignar">
          <i class="bi bi-plus-circle me-1"></i>Asignar
        </button>
        <?php endif; ?>
      </div>

      <?php if (empty($asignaciones)): ?>
      <p class="text-muted small mb-0">Sin asignaciones aún. Reparte el presupuesto a tu equipo.</p>
      <?php else: ?>
      <?php foreach ($asignaciones as $a):
        $gastA = (float)$pdo->prepare("SELECT COALESCE(SUM(g.monto+g.monto_peaje),0) FROM gastos_faena g WHERE g.asignacion_id=?")->execute([$a['id']]) ? $pdo->query("SELECT COALESCE(SUM(monto+monto_peaje),0) FROM gastos_faena WHERE asignacion_id={$a['id']}")->fetchColumn() : 0;
        $saldA = $a['monto_asignado'] - $gastA;
        $avCls = $a['tipo_receptor']==='conductor' ? 'asig-av-c' : 'asig-av-o';
      ?>
      <div class="asig-row">
        <div class="asig-avatar <?= $avCls ?>"><?= strtoupper(substr($a['nombre_receptor'],0,1)) ?></div>
        <div style="flex:1;min-width:0">
          <div class="fw-semibold" style="font-size:.88rem"><?= htmlspecialchars($a['nombre_receptor']) ?></div>
          <div style="font-size:.75rem;color:#6b7280">
            <span class="badge bg-<?= $a['tipo_receptor']==='conductor'?'primary':'success' ?>" style="font-size:.62rem"><?= ucfirst($a['tipo_receptor']) ?></span>
            Saldo: $<?= number_format($saldA,0,',','.') ?>
          </div>
        </div>
        <div style="text-align:right;flex-shrink:0">
          <div class="fw-bold" style="font-size:.88rem">$<?= number_format($a['monto_asignado'],0,',','.') ?></div>
          <div style="font-size:.72rem;color:#9ca3af">gastó $<?= number_format($gastA,0,',','.') ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Columna derecha: gastos del equipo para aprobar -->
  <div class="col-lg-7">
    <div class="sup-card">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="fw-bold mb-0">
          <i class="bi bi-receipt-cutoff me-1"></i>Gastos del equipo
          <?php if ($cntPend > 0): ?>
          <span class="badge bg-warning text-dark ms-1"><?= $cntPend ?> por aprobar</span>
          <?php endif; ?>
        </h6>
        <a href="gastos_faena_export.php?mes=<?= $mes ?>&obra_id=<?= $obraId ?>" target="_blank"
           class="btn btn-sm btn-outline-danger">
          <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </a>
      </div>

      <?php if (empty($gastos)): ?>
      <p class="text-muted small">Sin gastos registrados por el equipo este mes.</p>
      <?php else: ?>
      <?php foreach ($gastos as $g):
        $est = $g['estado'];
        $totG = (float)$g['monto']+(float)$g['monto_peaje'];
      ?>
      <div class="gasto-mini <?= $est ?>">
        <div class="d-flex align-items-start justify-content-between gap-2">
          <div style="flex:1;min-width:0">
            <div class="fw-semibold" style="font-size:.88rem"><?= htmlspecialchars($g['descripcion']) ?></div>
            <div class="d-flex flex-wrap gap-2 small text-muted mt-1">
              <span><i class="bi bi-calendar3" style="font-size:11px"></i><?= date('d/m/Y',strtotime($g['fecha'])) ?></span>
              <span><i class="bi bi-person" style="font-size:11px"></i><?= htmlspecialchars($g['registrado_por_nombre']) ?></span>
              <?php if($g['categoria_nombre']): ?><span><?= htmlspecialchars($g['categoria_nombre']) ?></span><?php endif; ?>
            </div>
            <div class="mt-1">
              <strong>$<?= number_format((float)$g['monto'],0,',','.') ?></strong>
              <?php if((float)$g['monto_peaje']>0): ?>
              <span class="badge bg-primary ms-1" style="font-size:.7rem">
                Peaje $<?= number_format((float)$g['monto_peaje'],0,',','.') ?>
              </span>
              <?php endif; ?>
              <span class="badge ms-1 <?= ['pendiente'=>'bg-warning text-dark','aprobado'=>'bg-success','rechazado'=>'bg-danger'][$est]??'bg-secondary' ?>" style="font-size:.7rem">
                <?= ucfirst($est) ?>
              </span>
            </div>
            <?php if($g['observacion_aprobacion']): ?>
            <div style="font-size:.75rem;color:#6b7280;margin-top:3px;font-style:italic"><?= htmlspecialchars($g['observacion_aprobacion']) ?></div>
            <?php endif; ?>
          </div>

          <!-- Foto -->
          <?php if ($g['arch']): ?>
          <img src="uploads/<?= htmlspecialchars($g['arch']) ?>" style="width:44px;height:44px;border-radius:8px;object-fit:cover;flex-shrink:0;cursor:pointer" onclick="verImg('<?= htmlspecialchars(addslashes($g['arch'])) ?>')">
          <?php endif; ?>

          <!-- Acciones supervisor -->
          <?php if ($est === 'pendiente'): ?>
          <div class="d-flex flex-column gap-1 flex-shrink-0">
            <button class="btn btn-sm btn-success" onclick="accionGasto(<?= $g['id'] ?>,'aprobar_gasto')" title="Aprobar"><i class="bi bi-check-lg"></i></button>
            <button class="btn btn-sm btn-outline-danger" onclick="accionGasto(<?= $g['id'] ?>,'rechazar_gasto')" title="Rechazar"><i class="bi bi-x-lg"></i></button>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <!-- Total -->
      <div class="d-flex justify-content-between align-items-center pt-2 border-top mt-2">
        <span class="text-muted small"><?= count($gastos) ?> gastos · <?= $cntPend ?> pendientes</span>
        <strong>Total: $<?= number_format($totalGastos,0,',','.') ?></strong>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- Modal asignar presupuesto -->
<div class="modal fade" id="modalAsignar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header text-white fw-bold" style="background:#d97706">
        <h5 class="modal-title"><i class="bi bi-cash-coin me-2"></i>Asignar presupuesto</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="accion" value="asignar">
        <input type="hidden" name="presupuesto_id" value="<?= $presupuesto['id'] ?? 0 ?>">
        <div class="modal-body row g-3">
          <div class="col-12">
            <div class="alert alert-info py-2">
              Saldo disponible: <strong>$<?= number_format($saldoSup,0,',','.') ?></strong>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Tipo</label>
            <select name="tipo_receptor" id="tipoRec" class="form-select" onchange="cambiarTipo()">
              <option value="conductor">Conductor</option>
              <option value="operador">Operador</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Persona</label>
            <select name="receptor_id" id="selectCond" class="form-select" required>
              <option value="">— Seleccionar —</option>
              <?php foreach($conductores as $c): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="receptor_id" id="selectOper" class="form-select" required style="display:none">
              <option value="">— Seleccionar —</option>
              <?php foreach($operadores as $o): ?>
              <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Monto a asignar ($CLP)</label>
            <div class="input-group">
              <span class="input-group-text">$</span>
              <input type="text" name="monto" class="form-control" inputmode="numeric"
                     placeholder="0" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-warning fw-bold">Asignar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal aprobar/rechazar -->
<div class="modal fade" id="modalAccion" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header fw-bold" id="accionHdr"></div>
      <form method="POST">
        <input type="hidden" name="accion" id="accionTipo">
        <input type="hidden" name="gasto_id" id="accionId">
        <div class="modal-body">
          <label class="form-label fw-semibold">Observación <small class="text-muted fw-normal">(opcional)</small></label>
          <textarea name="obs" class="form-control" rows="2" placeholder="Motivo..."></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn fw-bold" id="accionBtn">Confirmar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div class="modal fade" id="modalImg" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content bg-transparent border-0">
      <button type="button" class="btn-close btn-close-white ms-auto mb-2" data-bs-dismiss="modal"></button>
      <img id="modalImgSrc" src="" class="img-fluid rounded" style="max-height:80vh;object-fit:contain">
    </div>
  </div>
</div>

<script>
function cambiarTipo(){
  var t=document.getElementById('tipoRec').value;
  document.getElementById('selectCond').style.display=t==='conductor'?'':'none';
  document.getElementById('selectOper').style.display=t==='operador'?'':'none';
  document.getElementById('selectCond').required=t==='conductor';
  document.getElementById('selectOper').required=t==='operador';
}
function accionGasto(id,accion){
  document.getElementById('accionId').value=id;
  document.getElementById('accionTipo').value=accion;
  var h=document.getElementById('accionHdr'),b=document.getElementById('accionBtn');
  if(accion==='aprobar_gasto'){h.innerHTML='<i class="bi bi-check-circle-fill text-success me-2"></i>Aprobar gasto';b.className='btn btn-success fw-bold';b.textContent='Aprobar';}
  else{h.innerHTML='<i class="bi bi-x-circle-fill text-danger me-2"></i>Rechazar gasto';b.className='btn btn-danger fw-bold';b.textContent='Rechazar';}
  new bootstrap.Modal(document.getElementById('modalAccion')).show();
}
function verImg(ruta){
  document.getElementById('modalImgSrc').src='uploads/'+ruta;
  new bootstrap.Modal(document.getElementById('modalImg')).show();
}
</script>

<?php require_once 'includes/footer.php'; ?>
