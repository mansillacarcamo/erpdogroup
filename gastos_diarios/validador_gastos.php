<?php
require_once 'config.php';
requireRol('validador');

$vid = (int)$_SESSION['user_id'];
$p = periodoActual();
$anio = (int)($_GET['anio'] ?? $p['anio']);
$mes  = (int)($_GET['mes']  ?? $p['mes']);
$q    = trim($_GET['q'] ?? '');
$freg = trim($_GET['region'] ?? '');

$ini = sprintf('%04d-%02d-01',$anio,$mes);
$fin = date('Y-m-t', strtotime($ini));

$sql = "SELECT u.id, u.nombre, u.usuario, u.cargo, u.zona, u.ciudad, u.region, u.foto_perfil,
               (SELECT COUNT(*) FROM gastos g WHERE g.usuario_id=u.id AND g.fecha BETWEEN ? AND ?) as ngastos,
               (SELECT COALESCE(SUM(g.monto),0) FROM gastos g WHERE g.usuario_id=u.id AND g.fecha BETWEEN ? AND ?) as gastado
        FROM usuarios u
        WHERE u.activo=1 AND u.rol='usuario' AND u.validador_id=?";
$params = [$ini,$fin,$ini,$fin,$vid];

if ($q !== '') {
    $sql .= " AND (u.nombre LIKE ? OR u.usuario LIKE ? OR u.ciudad LIKE ? OR u.region LIKE ? OR u.zona LIKE ?)";
    $like = "%$q%"; array_push($params,$like,$like,$like,$like,$like);
}
if ($freg !== '') { $sql .= " AND u.region = ?"; $params[] = $freg; }
$sql .= " ORDER BY u.region, u.ciudad, u.nombre";

$st = $pdo->prepare($sql); $st->execute($params);
$tecnicos = $st->fetchAll();

$gxu = [];
$gd = $pdo->prepare("SELECT g.*, (SELECT COUNT(*) FROM archivos_gasto a WHERE a.gasto_id=g.id) as nadj
                     FROM gastos g
                     JOIN usuarios u ON u.id=g.usuario_id
                     WHERE u.validador_id=? AND g.fecha BETWEEN ? AND ? ORDER BY g.fecha DESC");
$gd->execute([$vid,$ini,$fin]);
foreach ($gd->fetchAll() as $r) $gxu[$r['usuario_id']][] = $r;

$asig = [];
$qa = $pdo->prepare("SELECT a.* FROM asignaciones a JOIN usuarios u ON u.id=a.usuario_id
                     WHERE u.validador_id=? AND a.anio=? AND a.mes=?");
$qa->execute([$vid,$anio,$mes]);
foreach ($qa->fetchAll() as $r) $asig[$r['usuario_id']] = $r;

$regs = $pdo->prepare("SELECT DISTINCT region FROM usuarios WHERE validador_id=? AND region IS NOT NULL AND region<>'' ORDER BY region");
$regs->execute([$vid]);
$regionesDisp = $regs->fetchAll(PDO::FETCH_COLUMN);

$totalGlobal = 0; foreach ($tecnicos as $t) $totalGlobal += (float)$t['gastado'];
$totalGastosCount = array_sum(array_map(fn($t)=>(int)$t['ngastos'], $tecnicos));

$titulo = 'Gastos por usuario';
include 'includes/head.php'; include 'includes/nav.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0"><i class="bi bi-people-fill me-2"></i>Gastos de mis usuarios - <?= nombreMes($mes) ?> <?= $anio ?></h4>
    <div class="small text-muted">
      <?= count($tecnicos) ?> usuario<?= count($tecnicos)===1?'':'s' ?> a mi cargo -
      <?= $totalGastosCount ?> gasto<?= $totalGastosCount===1?'':'s' ?> -
      Total <span class="fw-bold text-danger"><?= fmtCLP($totalGlobal) ?></span>
    </div>
  </div>
</div>

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
  <div class="alert alert-info"><i class="bi bi-info-circle"></i> No tienes tecnicos asignados o no hay coincidencias con los filtros.</div>
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
              <?php $urlFotoVg = urlFotoUsuario($t['foto_perfil'] ?? ''); ?>
              <?php if ($urlFotoVg): ?>
                <img src="<?= h($urlFotoVg) ?>" alt="<?= h($t['nombre']) ?>" class="tecnico-avatar" style="object-fit:cover;">
              <?php else: ?>
                <div class="tecnico-avatar"><?= strtoupper(mb_substr($t['nombre'],0,1)) ?></div>
              <?php endif; ?>
              <div class="flex-grow-1" style="min-width:0;">
                <div class="fw-bold text-truncate"><?= h($t['nombre']) ?></div>
                <div class="small opacity-75 text-truncate">@<?= h($t['usuario']) ?><?php if ($t['cargo']): ?> - <?= h($t['cargo']) ?><?php endif; ?></div>
              </div>
              <span class="badge bg-light text-dark"><?= (int)$t['ngastos'] ?></span>
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
              <div class="col-4"><div class="small text-muted">Asignado</div><div class="fw-bold text-nowrap"><?= fmtCLP($asignado) ?></div></div>
              <div class="col-4"><div class="small text-muted">Gastado</div><div class="fw-bold text-danger text-nowrap"><?= fmtCLP($t['gastado']) ?></div></div>
              <div class="col-4"><div class="small text-muted">Saldo</div><div class="fw-bold text-nowrap text-<?= $saldo<0?'danger':'success' ?>"><?= fmtCLP($saldo) ?></div></div>
            </div>
            <div class="progress" style="height:5px;">
              <div class="progress-bar bg-<?= $pct>=90?'danger':($pct>=70?'warning':'primary') ?>" style="width:<?= $pct ?>%"></div>
            </div>

            <?php if ($gastos): ?>
              <button class="btn btn-sm btn-outline-primary w-100 mt-3" type="button"
                      data-bs-toggle="collapse" data-bs-target="#g<?= $t['id'] ?>">
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

<?php include 'includes/foot.php'; ?>
