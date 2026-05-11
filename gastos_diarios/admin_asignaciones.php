<?php
require_once 'config.php';
requireRol('admin');

$p = periodoActual();
$anio = (int)($_GET['anio'] ?? $p['anio']);
$mes  = (int)($_GET['mes']  ?? $p['mes']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (($_POST['monto'] ?? []) as $uid => $monto) {
        $monto = parseMonto($monto);
        $car = parseMonto($_POST['carry'][$uid] ?? 0);
        $obs = trim($_POST['obs'][$uid] ?? '');
        $q = $pdo->prepare("SELECT id FROM asignaciones WHERE usuario_id=? AND anio=? AND mes=?");
        $q->execute([$uid,$anio,$mes]);
        if ($id = $q->fetchColumn()) {
            $pdo->prepare("UPDATE asignaciones SET monto_asignado=?, monto_carryover=?, observaciones=?, asignado_por=? WHERE id=?")
                ->execute([$monto,$car,$obs,$_SESSION['user_id'],$id]);
        } elseif ($monto > 0 || $car > 0) {
            $pdo->prepare("INSERT INTO asignaciones (usuario_id,anio,mes,monto_asignado,monto_carryover,observaciones,asignado_por) VALUES (?,?,?,?,?,?,?)")
                ->execute([$uid,$anio,$mes,$monto,$car,$obs,$_SESSION['user_id']]);
        }
    }
    flash('exito','Asignaciones guardadas.');
    header('Location: admin_asignaciones.php?anio='.$anio.'&mes='.$mes); exit;
}

$users = $pdo->query("SELECT u.id,u.nombre,u.email,u.zona,u.cargo FROM usuarios u WHERE u.rol='usuario' AND u.activo=1 ORDER BY u.nombre")->fetchAll();

// Presupuesto total del mes (definido por el admin en su perfil)
$qp = $pdo->prepare("SELECT monto_total FROM presupuesto_mes WHERE anio=? AND mes=?");
$qp->execute([$anio, $mes]);
$presupuestoMes = (float)($qp->fetchColumn() ?: 0);

// Distribuido actual: suma de monto_asignado + monto_carryover de todos los usuarios del periodo
$qd = $pdo->prepare("SELECT COALESCE(SUM(monto_asignado + monto_carryover),0) FROM asignaciones WHERE anio=? AND mes=?");
$qd->execute([$anio, $mes]);
$totalDistribuido = (float)$qd->fetchColumn();
$disponible = $presupuestoMes - $totalDistribuido;

$titulo = 'Asignaciones';
include 'includes/head.php';
include 'includes/nav.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Asignaciones · <?= nombreMes($mes) ?> <?= $anio ?></h4>
  <form class="d-flex gap-2" method="get">
    <select name="mes" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m===$mes?'selected':'' ?>><?= nombreMes($m) ?></option><?php endfor; ?>
    </select>
    <select name="anio" class="form-select form-select-sm" onchange="this.form.submit()">
      <?php for($y=date('Y')+1;$y>=date('Y')-2;$y--): ?><option value="<?= $y ?>" <?= $y===$anio?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
    </select>
  </form>
</div>

<!-- Panel sticky con presupuesto/distribuido/disponible (se actualiza al editar montos) -->
<div class="card mb-3 border-0 shadow-sm presup-sticky"
     id="panelPresup"
     data-presupuesto="<?= (int)round($presupuestoMes) ?>">
  <div class="card-body py-3">
    <?php if ($presupuestoMes <= 0): ?>
      <div class="alert alert-warning small mb-0 py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>
          <i class="bi bi-exclamation-triangle-fill"></i>
          No has definido el presupuesto del mes. Sin techo, las asignaciones no se controlan contra un total.
        </span>
        <a href="perfil.php" class="btn btn-sm btn-warning">
          <i class="bi bi-wallet-fill me-1"></i>Definir presupuesto
        </a>
      </div>
    <?php else: ?>
      <div class="row g-2 text-center align-items-center">
        <div class="col-6 col-md-3">
          <div class="text-muted small text-uppercase fw-semibold">Presupuesto</div>
          <div class="fw-bold fs-5 text-primary text-nowrap"><?= fmtCLP($presupuestoMes) ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="text-muted small text-uppercase fw-semibold">Distribuido</div>
          <div class="fw-bold fs-5 text-info text-nowrap" id="kpiDistribuido"><?= fmtCLP($totalDistribuido) ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="text-muted small text-uppercase fw-semibold">Disponible</div>
          <div class="fw-bold fs-4 text-nowrap" id="kpiDisponible"
               style="color:<?= $disponible<0?'#dc3545':'#198754' ?>;">
            <?= fmtCLP($disponible) ?>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="progress" style="height:10px;" title="Avance de distribucion">
            <div class="progress-bar" id="kpiBarra"
                 style="width:<?= $presupuestoMes>0 ? min(100, round($totalDistribuido*100/$presupuestoMes,1)) : 0 ?>%; background:<?= $disponible<0?'#dc3545':'#0d6efd' ?>;"></div>
          </div>
          <div class="small mt-1">
            <span id="kpiPct"><?= $presupuestoMes>0 ? round($totalDistribuido*100/$presupuestoMes,1) : 0 ?></span>% usado
          </div>
        </div>
      </div>
      <div id="kpiAlerta" class="alert alert-danger small mt-2 mb-0 py-2" style="display:<?= $disponible<0 ? '' : 'none' ?>;">
        <i class="bi bi-exclamation-triangle-fill"></i>
        Has distribuido <strong id="kpiExceso"><?= $disponible<0 ? fmtCLP(abs($disponible)) : '' ?></strong> mas que el presupuesto.
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
  .presup-sticky{ position:sticky; top:8px; z-index:10; backdrop-filter: blur(6px); background:rgba(255,255,255,0.95); }
</style>

<form method="post">
<div class="card"><div class="card-body p-0">
<div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0">
  <thead class="table-light">
    <tr><th>Usuario</th><th>Zona</th><th>Asignado</th><th>Arrastre</th><th>Gastado</th><th>Saldo</th><th>Observación</th></tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u):
    $a = asignacionPeriodo($pdo, $u['id'], $anio, $mes);
    $g = totalGastadoPeriodo($pdo, $u['id'], $anio, $mes);
    $s = ($a['total'] ?? 0) - $g;
    $obs = '';
    $qo = $pdo->prepare("SELECT observaciones FROM asignaciones WHERE usuario_id=? AND anio=? AND mes=?");
    $qo->execute([$u['id'],$anio,$mes]);
    $obs = $qo->fetchColumn() ?: '';
  ?>
    <tr>
      <td><div class="fw-semibold"><?= h($u['nombre']) ?></div><div class="small text-muted"><?= h($u['email']) ?></div></td>
      <td class="small"><?= h($u['zona']?:'—') ?></td>
      <td><div class="input-group input-group-sm"><span class="input-group-text">$</span>
        <input type="text" name="monto[<?= $u['id'] ?>]" class="form-control input-clp" value="<?= number_format($a['monto_asignado']??0,0,',','.') ?>"></div></td>
      <td><div class="input-group input-group-sm"><span class="input-group-text">$</span>
        <input type="text" name="carry[<?= $u['id'] ?>]" class="form-control input-clp" value="<?= number_format($a['monto_carryover']??0,0,',','.') ?>"></div></td>
      <td class="text-danger"><?= fmtCLP($g) ?></td>
      <td class="<?= $s<0?'text-danger':'text-success' ?> fw-semibold"><?= fmtCLP($s) ?></td>
      <td><input type="text" name="obs[<?= $u['id'] ?>]" class="form-control form-control-sm" value="<?= h($obs) ?>"></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div></div>

<div class="mt-3 d-flex justify-content-end">
  <button class="btn btn-primary btn-lg"><i class="bi bi-check-circle me-1"></i> Guardar asignaciones</button>
</div>
</form>

<script>
// Recalcular en vivo presupuesto/distribuido/disponible al editar montos
(function(){
  var panel = document.getElementById('panelPresup');
  if (!panel) return;
  var presupuesto = parseInt(panel.dataset.presupuesto, 10) || 0;
  if (presupuesto <= 0) return;

  var kpiDistr = document.getElementById('kpiDistribuido');
  var kpiDisp  = document.getElementById('kpiDisponible');
  var kpiPct   = document.getElementById('kpiPct');
  var kpiBarra = document.getElementById('kpiBarra');
  var kpiAlert = document.getElementById('kpiAlerta');
  var kpiExc   = document.getElementById('kpiExceso');

  function parseCLP(v){
    return parseInt(String(v||'').replace(/[^0-9]/g,''), 10) || 0;
  }
  function fmtCLP(n){
    var sign = n < 0 ? '-' : '';
    n = Math.abs(Math.round(n));
    return sign + '$' + n.toLocaleString('es-CL').replace(/,/g,'.');
  }

  function recalc(){
    var sum = 0;
    document.querySelectorAll('input[name^="monto["], input[name^="carry["]').forEach(function(inp){
      sum += parseCLP(inp.value);
    });
    var disp = presupuesto - sum;
    var pct  = Math.min(100, Math.round(sum * 100 / presupuesto * 10) / 10);
    var pctReal = Math.round(sum * 100 / presupuesto * 10) / 10;
    kpiDistr.textContent = fmtCLP(sum);
    kpiDisp.textContent  = fmtCLP(disp);
    kpiDisp.style.color  = disp < 0 ? '#dc3545' : '#198754';
    kpiPct.textContent   = pctReal;
    kpiBarra.style.width = pct + '%';
    kpiBarra.style.background = disp < 0 ? '#dc3545' : '#0d6efd';
    if (disp < 0) {
      kpiAlert.style.display = '';
      kpiExc.textContent = fmtCLP(Math.abs(disp));
    } else {
      kpiAlert.style.display = 'none';
    }
  }

  document.addEventListener('input', function(e){
    if (!e.target || !e.target.name) return;
    if (e.target.name.indexOf('monto[') === 0 || e.target.name.indexOf('carry[') === 0) {
      // pequeño retraso para que se aplique el formato CLP primero
      setTimeout(recalc, 0);
    }
  });
})();
</script>

<?php include 'includes/foot.php'; ?>
