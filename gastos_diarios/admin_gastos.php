<?php
require_once 'config.php';
requireRol('admin');

// Manejar cierre/reapertura del periodo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acc  = $_POST['accion'] ?? '';
    $anioP = (int)($_POST['anio'] ?? 0);
    $mesP  = (int)($_POST['mes'] ?? 0);
    if ($acc === 'cerrar_periodo' && $anioP && $mesP) {
        $iniP = sprintf('%04d-%02d-01', $anioP, $mesP);
        $finP = date('Y-m-t', strtotime($iniP));
        $resumen = $pdo->prepare("SELECT COALESCE(SUM(monto),0) as total, COUNT(*) as ng, COUNT(DISTINCT usuario_id) as nu FROM gastos WHERE fecha BETWEEN ? AND ?");
        $resumen->execute([$iniP, $finP]);
        $r = $resumen->fetch();
        try {
            $pdo->prepare("INSERT INTO periodos_cerrados (anio,mes,cerrado_por,total_gastado,n_usuarios,n_gastos) VALUES (?,?,?,?,?,?)")
                ->execute([$anioP, $mesP, $_SESSION['user_id'], (float)$r['total'], (int)$r['nu'], (int)$r['ng']]);
            flash('exito', 'Mes ' . nombreMes($mesP) . ' ' . $anioP . ' cerrado correctamente.');
        } catch (Exception $e) {
            flash('error', 'No se pudo cerrar: ' . $e->getMessage());
        }
    } elseif ($acc === 'reabrir_periodo' && $anioP && $mesP) {
        $pdo->prepare("DELETE FROM periodos_cerrados WHERE anio=? AND mes=?")->execute([$anioP, $mesP]);
        flash('exito', 'Mes ' . nombreMes($mesP) . ' ' . $anioP . ' reabierto.');
    }
    header('Location: admin_gastos.php?anio=' . $anioP . '&mes=' . $mesP);
    exit;
}

$p = periodoActual();
$anio = (int)($_GET['anio'] ?? $p['anio']);
$mes  = (int)($_GET['mes']  ?? $p['mes']);
$q    = trim($_GET['q'] ?? '');
$freg = trim($_GET['region'] ?? '');

$ini = sprintf('%04d-%02d-01',$anio,$mes);
$fin = date('Y-m-t', strtotime($ini));

// Traer usuarios con rol 'usuario' y sumar sus gastos
$sql = "SELECT u.id, u.nombre, u.usuario, u.cargo, u.zona, u.ciudad, u.region, u.foto_perfil,
               (SELECT COUNT(*) FROM gastos g WHERE g.usuario_id=u.id AND g.fecha BETWEEN ? AND ?) as ngastos,
               (SELECT COALESCE(SUM(g.monto),0) FROM gastos g WHERE g.usuario_id=u.id AND g.fecha BETWEEN ? AND ?) as gastado
        FROM usuarios u
        WHERE u.activo=1 AND u.rol='usuario'";
$params = [$ini,$fin,$ini,$fin];

if ($q !== '') {
    $sql .= " AND (u.nombre LIKE ? OR u.usuario LIKE ? OR u.ciudad LIKE ? OR u.region LIKE ? OR u.zona LIKE ?)";
    $like = "%$q%"; array_push($params,$like,$like,$like,$like,$like);
}
if ($freg !== '') { $sql .= " AND u.region = ?"; $params[] = $freg; }

$sql .= " ORDER BY u.region, u.ciudad, u.nombre";

$st = $pdo->prepare($sql); $st->execute($params);
$tecnicos = $st->fetchAll();

// Pre-cargar gastos detallados agrupados por usuario
$gxu = [];
$gd = $pdo->prepare("SELECT g.*, (SELECT COUNT(*) FROM archivos_gasto a WHERE a.gasto_id=g.id) as nadj
                     FROM gastos g WHERE g.fecha BETWEEN ? AND ? ORDER BY g.fecha DESC, g.id DESC");
$gd->execute([$ini,$fin]);
foreach ($gd->fetchAll() as $r) {
    $gxu[$r['usuario_id']][] = $r;
}

// Asignaciones
$asig = [];
$qa = $pdo->prepare("SELECT * FROM asignaciones WHERE anio=? AND mes=?");
$qa->execute([$anio,$mes]);
foreach ($qa->fetchAll() as $r) $asig[$r['usuario_id']] = $r;

// Regiones disponibles
$regionesDisp = $pdo->query("SELECT DISTINCT region FROM usuarios WHERE activo=1 AND rol='usuario' AND region IS NOT NULL AND region<>'' ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);

$totalGlobal = 0; foreach ($tecnicos as $t) $totalGlobal += (float)$t['gastado'];
$totalGastosCount = array_sum(array_map(fn($t)=>(int)$t['ngastos'], $tecnicos));

// Suma total de montos asignados del periodo (asignado + arrastre)
$totalAsignadoMes = 0;
foreach ($tecnicos as $t) {
    $a = $asig[$t['id']] ?? null;
    if ($a) $totalAsignadoMes += (float)$a['monto_asignado'] + (float)$a['monto_carryover'];
}
$saldoTotal = $totalAsignadoMes - $totalGlobal;
$pctGlobal  = $totalAsignadoMes > 0 ? round($totalGlobal * 100 / $totalAsignadoMes, 1) : 0;

// Top usuario que mas gasto
$topUser = null;
foreach ($tecnicos as $t) {
    if (!$topUser || (float)$t['gastado'] > (float)$topUser['gastado']) $topUser = $t;
}

// Top categoria ("obra") que mas gasto en el periodo
$topCatQ = $pdo->prepare("SELECT categoria, SUM(monto) as total, COUNT(*) as n
                          FROM gastos
                          WHERE fecha BETWEEN ? AND ? AND categoria IS NOT NULL AND categoria <> ''
                          GROUP BY categoria ORDER BY total DESC LIMIT 1");
$topCatQ->execute([$ini, $fin]);
$topCat = $topCatQ->fetch();

// Estado del periodo (cerrado o no)
$pcQ = $pdo->prepare("SELECT pc.*, u.nombre as cerrado_por_nombre FROM periodos_cerrados pc
                      LEFT JOIN usuarios u ON u.id=pc.cerrado_por
                      WHERE pc.anio=? AND pc.mes=?");
$pcQ->execute([$anio, $mes]);
$periodoCerrado = $pcQ->fetch();

// Datos para el grafico (top 10 usuarios por gasto, ordenados desc)
$tecnicosOrden = $tecnicos;
usort($tecnicosOrden, fn($a,$b) => (float)$b['gastado'] <=> (float)$a['gastado']);
$tecnicosTop = array_slice($tecnicosOrden, 0, 10);
$chartLabels = array_map(fn($t)=>$t['nombre'] ?: ('@'.$t['usuario']), $tecnicosTop);
$chartData   = array_map(fn($t)=>(float)$t['gastado'], $tecnicosTop);

$titulo = 'Gastos por usuario';
include 'includes/head.php'; include 'includes/nav.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0"><i class="bi bi-people-fill me-2"></i>Gastos por usuario - <?= nombreMes($mes) ?> <?= $anio ?></h4>
    <div class="small text-muted">
      <?= count($tecnicos) ?> usuario<?= count($tecnicos)===1?'':'s' ?> -
      <?= $totalGastosCount ?> gasto<?= $totalGastosCount===1?'':'s' ?> -
      Total <span class="fw-bold text-danger"><?= fmtCLP($totalGlobal) ?></span>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="exportar_excel.php?anio=<?= $anio ?>&mes=<?= $mes ?>" class="btn btn-outline-success">
      <i class="bi bi-file-earmark-excel-fill me-1"></i> Exportar Excel
    </a>
    <?php if ($periodoCerrado): ?>
      <span class="badge bg-success fs-6 d-flex align-items-center gap-1">
        <i class="bi bi-lock-fill"></i> Mes cerrado
        <span class="small fw-normal opacity-75 ms-1">
          <?= h(date('d/m/Y H:i', strtotime($periodoCerrado['cerrado_en']))) ?>
        </span>
      </span>
      <button class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#mReabrir">
        <i class="bi bi-unlock"></i> Reabrir
      </button>
    <?php else: ?>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#mCerrar">
        <i class="bi bi-lock-fill me-1"></i> Cerrar mes
      </button>
    <?php endif; ?>
  </div>
</div>

<?php if ($periodoCerrado): ?>
<div class="alert alert-success py-2 px-3 mb-3 d-flex align-items-center gap-2 flex-wrap">
  <i class="bi bi-check-circle-fill"></i>
  <span><strong>Mes cerrado</strong> - Total gastado: <strong><?= fmtCLP($periodoCerrado['total_gastado']) ?></strong>
  - <?= (int)$periodoCerrado['n_gastos'] ?> gastos de <?= (int)$periodoCerrado['n_usuarios'] ?> usuario(s)</span>
  <?php if (!empty($periodoCerrado['cerrado_por_nombre'])): ?>
    <span class="small text-muted">- Cerrado por <?= h($periodoCerrado['cerrado_por_nombre']) ?></span>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- Totales globales del mes -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100" style="background:linear-gradient(135deg,#dbeafe 0%,#eff6ff 100%);">
      <div class="card-body">
        <div class="small text-muted text-uppercase fw-semibold" style="letter-spacing:.5px;">Total asignado</div>
        <div class="fs-4 fw-bold text-primary"><?= fmtCLP($totalAsignadoMes) ?></div>
        <div class="small text-muted">Suma de todos los presupuestos</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100" style="background:linear-gradient(135deg,#fee2e2 0%,#fef2f2 100%);">
      <div class="card-body">
        <div class="small text-muted text-uppercase fw-semibold" style="letter-spacing:.5px;">Total gastado</div>
        <div class="fs-4 fw-bold text-danger"><?= fmtCLP($totalGlobal) ?></div>
        <div class="small text-muted"><?= $totalGastosCount ?> gasto<?= $totalGastosCount===1?'':'s' ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100" style="background:linear-gradient(135deg,#dcfce7 0%,#f0fdf4 100%);">
      <div class="card-body">
        <div class="small text-muted text-uppercase fw-semibold" style="letter-spacing:.5px;">Saldo total</div>
        <div class="fs-4 fw-bold text-<?= $saldoTotal<0?'danger':'success' ?>"><?= fmtCLP($saldoTotal) ?></div>
        <div class="small text-muted"><?= $pctGlobal ?>% usado</div>
      </div>
    </div>
  </div>
</div>

<!-- Top categoria ("obra que gasto mas") -->
<?php if ($topCat && (float)$topCat['total'] > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
  <i class="bi bi-bullseye fs-4 text-warning"></i>
  <div>
    <div class="small text-muted">Categoria con mayor gasto del mes</div>
    <div><strong class="fs-5"><?= h($topCat['categoria']) ?></strong>
      - <span class="fw-bold text-warning"><?= fmtCLP($topCat['total']) ?></span>
      <span class="small text-muted">(<?= (int)$topCat['n'] ?> gasto<?= $topCat['n']==1?'':'s' ?>)</span></div>
  </div>
</div>
<?php endif; ?>

<!-- Progreso global del presupuesto -->
<?php if ($totalAsignadoMes > 0): ?>
<div class="card mb-3">
  <div class="card-body py-3">
    <div class="d-flex justify-content-between small mb-1">
      <span class="text-muted">Avance del presupuesto del mes</span>
      <span class="fw-bold"><?= fmtCLP($totalGlobal) ?> / <?= fmtCLP($totalAsignadoMes) ?> (<?= $pctGlobal ?>%)</span>
    </div>
    <div class="progress" style="height:14px;">
      <div class="progress-bar bg-<?= $pctGlobal>=90?'danger':($pctGlobal>=70?'warning':'success') ?>" style="width:<?= min(100,$pctGlobal) ?>%">
        <?= $pctGlobal ?>%
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Modal cerrar mes -->
<div class="modal fade" id="mCerrar" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="accion" value="cerrar_periodo">
        <input type="hidden" name="anio" value="<?= $anio ?>">
        <input type="hidden" name="mes" value="<?= $mes ?>">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title"><i class="bi bi-lock-fill me-1"></i>Cerrar mes</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>Vas a cerrar el periodo <strong><?= nombreMes($mes) ?> <?= $anio ?></strong>.</p>
          <ul class="small mb-3">
            <li>Total gastado: <strong><?= fmtCLP($totalGlobal) ?></strong></li>
            <li>Usuarios con gasto: <strong><?= count(array_filter($tecnicos, fn($t)=>(float)$t['gastado']>0)) ?></strong></li>
            <li>Cantidad de gastos: <strong><?= $totalGastosCount ?></strong></li>
          </ul>
          <div class="alert alert-info small mb-0">
            <i class="bi bi-info-circle"></i> El cierre quedara registrado con tu usuario y la fecha actual. Podras reabrirlo si es necesario.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-success"><i class="bi bi-lock-fill me-1"></i>Confirmar cierre</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal reabrir mes -->
<?php if ($periodoCerrado): ?>
<div class="modal fade" id="mReabrir" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="accion" value="reabrir_periodo">
        <input type="hidden" name="anio" value="<?= $anio ?>">
        <input type="hidden" name="mes" value="<?= $mes ?>">
        <div class="modal-header bg-warning">
          <h5 class="modal-title"><i class="bi bi-unlock me-1"></i>Reabrir mes</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>El periodo <strong><?= nombreMes($mes) ?> <?= $anio ?></strong> sera reabierto.</p>
          <p class="small text-muted mb-0">El registro de cierre actual se eliminara.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-warning"><i class="bi bi-unlock me-1"></i>Reabrir</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<form class="card mb-3" method="get">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label small mb-1"><i class="bi bi-search"></i> Buscar usuario</label>
        <input name="q" value="<?= h($q) ?>" class="form-control" placeholder="Nombre, usuario, ciudad...">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-1">Region</label>
        <select name="region" class="form-select" onchange="this.form.submit()">
          <option value="">Todas</option>
          <?php foreach ($regionesDisp as $r): ?>
            <option value="<?= h($r) ?>" <?= $freg===$r?'selected':'' ?>><?= h($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-3 col-md-2">
        <label class="form-label small mb-1">Mes</label>
        <select name="mes" class="form-select" onchange="this.form.submit()">
          <?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m===$mes?'selected':'' ?>><?= nombreMes($m) ?></option><?php endfor; ?>
        </select>
      </div>
      <div class="col-3 col-md-2">
        <label class="form-label small mb-1">Año</label>
        <select name="anio" class="form-select" onchange="this.form.submit()">
          <?php for($y=date('Y');$y>=date('Y')-2;$y--): ?><option value="<?= $y ?>" <?= $y===$anio?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
        </select>
      </div>
      <div class="col-12 col-md-1 d-grid">
        <button class="btn btn-primary"><i class="bi bi-funnel"></i></button>
      </div>
    </div>
  </div>
</form>

<?php if (!$tecnicos): ?>
  <div class="alert alert-info"><i class="bi bi-info-circle"></i> No hay tecnicos que coincidan con los filtros.</div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($tecnicos as $t):
      $a = $asig[$t['id']] ?? null;
      $asignado = $a ? ((float)$a['monto_asignado'] + (float)$a['monto_carryover']) : 0;
      $saldo = $asignado - (float)$t['gastado'];
      $pct = $asignado>0 ? min(100, round($t['gastado']*100/$asignado,0)) : 0;
      $gastos = $gxu[$t['id']] ?? [];
    ?>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="card shadow-sm h-100 tecnico-gasto-card">
          <div class="card-header bg-gradient-brand text-white tecnico-toggle collapsed"
               role="button"
               data-bs-toggle="collapse"
               data-bs-target="#body<?= $t['id'] ?>"
               aria-expanded="false"
               aria-controls="body<?= $t['id'] ?>">
            <div class="d-flex align-items-center gap-2">
              <?php $urlFotoAg = urlFotoUsuario($t['foto_perfil'] ?? ''); ?>
              <?php if ($urlFotoAg): ?>
                <img src="<?= h($urlFotoAg) ?>" alt="<?= h($t['nombre']) ?>" class="tecnico-avatar" style="object-fit:cover;">
              <?php else: ?>
                <div class="tecnico-avatar"><?= strtoupper(mb_substr($t['nombre'],0,1)) ?></div>
              <?php endif; ?>
              <div class="flex-grow-1" style="min-width:0;">
                <div class="fw-bold text-truncate"><?= h($t['nombre']) ?></div>
                <div class="small opacity-75 text-truncate">@<?= h($t['usuario']) ?><?php if ($t['cargo']): ?> - <?= h($t['cargo']) ?><?php endif; ?></div>
              </div>
              <span class="badge bg-light text-dark"><?= (int)$t['ngastos'] ?> gasto<?= $t['ngastos']==1?'':'s' ?></span>
              <i class="bi bi-chevron-down toggle-chevron ms-1"></i>
            </div>
            <div class="mt-2 d-flex flex-wrap gap-1">
              <?php if ($t['region']): ?><span class="badge bg-white bg-opacity-25"><i class="bi bi-geo-alt-fill"></i> <?= h($t['region']) ?></span><?php endif; ?>
              <?php if ($t['ciudad']): ?><span class="badge bg-white bg-opacity-25"><i class="bi bi-geo-alt"></i> <?= h($t['ciudad']) ?></span><?php endif; ?>
              <?php if ($t['zona']): ?><span class="badge bg-white bg-opacity-25"><?= h($t['zona']) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="collapse" id="body<?= $t['id'] ?>">
          <div class="card-body p-3">
            <div class="row g-2 text-center mb-2">
              <div class="col-4">
                <div class="small text-muted">Asignado</div>
                <div class="fw-bold text-nowrap"><?= fmtCLP($asignado) ?></div>
              </div>
              <div class="col-4">
                <div class="small text-muted">Gastado</div>
                <div class="fw-bold text-danger text-nowrap"><?= fmtCLP($t['gastado']) ?></div>
              </div>
              <div class="col-4">
                <div class="small text-muted">Saldo</div>
                <div class="fw-bold text-nowrap text-<?= $saldo<0?'danger':'success' ?>"><?= fmtCLP($saldo) ?></div>
              </div>
            </div>
            <div class="progress" style="height:5px;">
              <div class="progress-bar bg-<?= $pct>=90?'danger':($pct>=70?'warning':'primary') ?>" style="width:<?= $pct ?>%"></div>
            </div>

            <?php if ($gastos): ?>
              <button class="btn btn-sm btn-outline-primary w-100 mt-3" type="button"
                      data-bs-toggle="collapse" data-bs-target="#g<?= $t['id'] ?>"
                      aria-expanded="false">
                <i class="bi bi-chevron-down"></i> Ver <?= count($gastos) ?> gasto<?= count($gastos)==1?'':'s' ?>
              </button>
              <div class="collapse mt-2" id="g<?= $t['id'] ?>">
                <div class="list-group list-group-flush gastos-lista">
                <?php foreach ($gastos as $g): ?>
                  <div class="list-group-item px-2 py-2">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                      <div style="min-width:0;" class="flex-grow-1">
                        <div class="small fw-semibold text-truncate">
                          <?= h($g['descripcion'] ?: ($g['proveedor'] ?: ($g['categoria'] ?: 'Gasto'))) ?>
                        </div>
                        <div class="small text-muted">
                          <?= date('d/m/Y', strtotime($g['fecha'])) ?>
                          <?php if ($g['categoria']): ?> - <?= h($g['categoria']) ?><?php endif; ?>
                          <?php if ($g['nadj']): ?> - <i class="bi bi-paperclip"></i><?= (int)$g['nadj'] ?><?php endif; ?>
                        </div>
                      </div>
                      <div class="text-end flex-shrink-0">
                        <div class="fw-bold text-danger small text-nowrap"><?= fmtCLP($g['monto']) ?></div>
                        <a href="gasto_ver.php?id=<?= $g['id'] ?>" class="btn btn-sm btn-link p-0"><i class="bi bi-eye"></i></a>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
                </div>
              </div>
            <?php else: ?>
              <div class="text-center text-muted small mt-3 py-2">
                <i class="bi bi-inbox"></i> Sin gastos este mes
              </div>
            <?php endif; ?>

            <div class="d-flex gap-1 mt-3">
              <a href="jefe_ver_usuario.php?u=<?= $t['id'] ?>&anio=<?= $anio ?>&mes=<?= $mes ?>" class="btn btn-sm btn-outline-primary flex-grow-1">
                <i class="bi bi-eye"></i> Detalle
              </a>
              <a href="exportar_pdf.php?u=<?= $t['id'] ?>&anio=<?= $anio ?>&mes=<?= $mes ?>" target="_blank" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-file-earmark-pdf"></i>
              </a>
            </div>
          </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($totalGlobal > 0): ?>
<!-- Gráfico de gastos por usuario (al final del dashboard) -->
<div class="card mb-3 mt-4">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-bar-chart-line-fill text-primary"></i>
    <span>Gasto por usuario</span>
    <span class="small text-muted ms-auto">Top <?= count($tecnicosTop) ?></span>
  </div>
  <div class="card-body">
    <canvas id="chartGastosUsuario" style="max-height:340px;"></canvas>
  </div>
</div>
<?php endif; ?>

<style>
.tecnico-gasto-card{ border-radius:14px; overflow:hidden; border:1px solid #e5e7eb; transition:transform .15s, box-shadow .15s; }
.tecnico-gasto-card:hover{ transform: translateY(-2px); box-shadow:0 10px 24px rgba(15,23,42,.1); }
.tecnico-gasto-card .card-header{ border:0; padding:.9rem 1rem; }
.tecnico-avatar{ width:42px; height:42px; border-radius:50%; background:rgba(255,255,255,.3); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.15rem; flex-shrink:0; border:2px solid rgba(255,255,255,.5); }
.bg-gradient-brand{ background:linear-gradient(135deg,#0d6efd 0%,#6610f2 100%); }
.gastos-lista{ max-height:280px; overflow-y:auto; border-radius:10px; }
.gastos-lista .list-group-item{ border-color:#f1f5f9; }
.tecnico-toggle{ cursor:pointer; user-select:none; transition:filter .15s; }
.tecnico-toggle:hover{ filter:brightness(1.08); }
.tecnico-toggle .toggle-chevron{ transition:transform .25s ease; font-size:1.1rem; }
.tecnico-toggle:not(.collapsed) .toggle-chevron{ transform:rotate(180deg); }
</style>

<?php if ($totalGlobal > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
  var ctx = document.getElementById('chartGastosUsuario');
  if (!ctx) return;
  var labels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
  var data   = <?= json_encode($chartData) ?>;
  var fmtCLP = function(v){ return '$' + Math.round(v).toLocaleString('es-CL').replace(/,/g,'.'); };
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Gasto del mes',
        data: data,
        backgroundColor: 'rgba(13,110,253,0.7)',
        borderColor: 'rgba(13,110,253,1)',
        borderWidth: 1,
        borderRadius: 6
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: function(c){ return fmtCLP(c.raw); } } }
      },
      scales: {
        x: { ticks: { callback: function(v){ return fmtCLP(v); } }, beginAtZero: true },
        y: { ticks: { autoSkip: false } }
      }
    }
  });
})();
</script>
<?php endif; ?>

<?php include 'includes/foot.php'; ?>
