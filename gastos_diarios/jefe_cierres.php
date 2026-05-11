<?php
require_once 'config.php';
requireRol('jefe');

$jid = (int)$_SESSION['user_id'];
$q   = trim($_GET['q'] ?? '');
$freg= trim($_GET['region'] ?? '');
$fest= trim($_GET['estado'] ?? '');

$sql = "SELECT c.*, u.nombre, u.usuario, u.ciudad, u.region, u.zona, u.cargo
        FROM cierres_mensuales c
        JOIN usuarios u ON u.id=c.usuario_id
        WHERE u.jefe_zonal_id=? AND c.estado IN ('enviado_jefe','aprobado_jefe','enviado_validador','validado','rechazado')";
$params = [$jid];
if ($q !== '') {
    $sql .= " AND (u.nombre LIKE ? OR u.usuario LIKE ? OR u.ciudad LIKE ?)";
    $like = "%$q%"; array_push($params,$like,$like,$like);
}
if ($freg !== '') { $sql .= " AND u.region = ?"; $params[] = $freg; }
if ($fest !== '') { $sql .= " AND c.estado = ?"; $params[] = $fest; }
$sql .= " ORDER BY c.enviado_jefe_en DESC, u.nombre";

$cs = $pdo->prepare($sql); $cs->execute($params);
$cierres = $cs->fetchAll();

$regs = $pdo->prepare("SELECT DISTINCT region FROM usuarios WHERE jefe_zonal_id=? AND region IS NOT NULL AND region<>'' ORDER BY region");
$regs->execute([$jid]);
$regionesDisponibles = $regs->fetchAll(PDO::FETCH_COLUMN);

$titulo = 'Cierres del equipo';
include 'includes/head.php'; include 'includes/nav.php';
?>

<h4 class="mb-3"><i class="bi bi-clipboard-check me-2"></i>Cierres mensuales del equipo</h4>

<form class="card mb-3" method="get">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label small mb-1"><i class="bi bi-search"></i> Buscar tecnico</label>
        <input name="q" value="<?= h($q) ?>" class="form-control" placeholder="Nombre, usuario, ciudad...">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-1">Region</label>
        <select name="region" class="form-select" onchange="this.form.submit()">
          <option value="">Todas</option>
          <?php foreach ($regionesDisponibles as $r): ?>
            <option value="<?= h($r) ?>" <?= $freg===$r?'selected':'' ?>><?= h($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-1">Estado</label>
        <select name="estado" class="form-select" onchange="this.form.submit()">
          <option value="">Todos</option>
          <?php foreach (['enviado_jefe'=>'Pendiente revision','aprobado_jefe'=>'Aprobado por mi','enviado_validador'=>'En validador','validado'=>'Validado','rechazado'=>'Rechazado'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $fest===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
      </div>
    </div>
  </div>
</form>

<div class="card"><div class="card-body p-0">
<div class="table-responsive">
<table class="table align-middle mb-0">
  <thead class="table-light">
    <tr>
      <th>Tecnico</th>
      <th>Ubicacion</th>
      <th>Periodo</th>
      <th class="text-end">Asignado</th>
      <th class="text-end">Gastado</th>
      <th class="text-end">Saldo</th>
      <th>Estado</th>
      <th class="text-end"></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($cierres as $c): ?>
    <tr>
      <td>
        <div class="fw-semibold"><?= h($c['nombre']) ?></div>
        <div class="small text-muted">@<?= h($c['usuario']) ?><?php if ($c['cargo']): ?> - <?= h($c['cargo']) ?><?php endif; ?></div>
      </td>
      <td class="small">
        <?php if ($c['region']): ?><div><i class="bi bi-geo-alt-fill text-primary"></i> <?= h($c['region']) ?></div><?php endif; ?>
        <?php if ($c['ciudad']): ?><div class="text-muted"><?= h($c['ciudad']) ?></div><?php endif; ?>
        <?php if ($c['zona']): ?><div class="text-muted">Zona: <?= h($c['zona']) ?></div><?php endif; ?>
      </td>
      <td class="text-nowrap"><?= nombreMes($c['mes']) ?> <?= $c['anio'] ?></td>
      <td class="text-end text-nowrap"><?= fmtCLP(($c['monto_asignado']+$c['monto_carryover'])) ?></td>
      <td class="text-end text-nowrap text-danger"><?= fmtCLP($c['total_gastado']) ?></td>
      <td class="text-end text-nowrap <?= $c['saldo_final']<0?'text-danger':'text-success' ?>"><?= fmtCLP($c['saldo_final']) ?></td>
      <td><span class="estado <?= h($c['estado']) ?>"><?= h($c['estado']) ?></span></td>
      <td class="text-end">
        <?php if ($c['estado']==='enviado_jefe'): ?>
          <a href="jefe_revisar_cierre.php?u=<?= $c['usuario_id'] ?>&anio=<?= $c['anio'] ?>&mes=<?= $c['mes'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-clipboard-check"></i> Revisar</a>
        <?php else: ?>
          <a href="jefe_revisar_cierre.php?u=<?= $c['usuario_id'] ?>&anio=<?= $c['anio'] ?>&mes=<?= $c['mes'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> Ver</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$cierres): ?>
    <tr><td colspan="8" class="text-center text-muted py-4">
      <i class="bi bi-inbox" style="font-size:2rem; opacity:.3;"></i>
      <div class="mt-2">No hay cierres con esos filtros.</div>
    </td></tr>
  <?php endif; ?>
  </tbody>
</table>
</div></div></div>

<?php include 'includes/foot.php'; ?>
