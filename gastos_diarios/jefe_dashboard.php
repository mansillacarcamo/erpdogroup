<?php
require_once 'config.php';
requireRol('jefe');

$jid = (int)$_SESSION['user_id'];
$p = periodoActual();
$anio = (int)($_GET['anio'] ?? $p['anio']);
$mes  = (int)($_GET['mes']  ?? $p['mes']);
$q    = trim($_GET['q'] ?? '');
$fregion = trim($_GET['region'] ?? '');
$festado = trim($_GET['estado'] ?? '');

$sql = "SELECT * FROM usuarios WHERE jefe_zonal_id=? AND rol='usuario' AND activo=1";
$params = [$jid];
if ($q !== '') {
    $sql .= " AND (nombre LIKE ? OR usuario LIKE ? OR ciudad LIKE ? OR region LIKE ? OR zona LIKE ?)";
    $like = "%$q%"; array_push($params,$like,$like,$like,$like,$like);
}
if ($fregion !== '') { $sql .= " AND region = ?"; $params[] = $fregion; }
$sql .= " ORDER BY region, ciudad, nombre";

$equipo = $pdo->prepare($sql);
$equipo->execute($params);
$equipo = $equipo->fetchAll();

// Regiones disponibles (solo del equipo del jefe)
$regs = $pdo->prepare("SELECT DISTINCT region FROM usuarios WHERE jefe_zonal_id=? AND rol='usuario' AND activo=1 AND region IS NOT NULL AND region<>'' ORDER BY region");
$regs->execute([$jid]);
$regionesDisponibles = $regs->fetchAll(PDO::FETCH_COLUMN);

// Agrupar por region
$grupos = [];
foreach ($equipo as $u) {
    $k = $u['region'] ?: 'Sin region';
    if (!isset($grupos[$k])) $grupos[$k] = [];
    $grupos[$k][] = $u;
}

// Pre-cargar asignaciones, totales y estado de cierre
$est_cierre = [];
$cs = $pdo->prepare("SELECT c.usuario_id, c.estado FROM cierres_mensuales c
                     JOIN usuarios u ON u.id=c.usuario_id
                     WHERE u.jefe_zonal_id=? AND c.anio=? AND c.mes=?");
$cs->execute([$jid,$anio,$mes]);
foreach ($cs->fetchAll() as $r) $est_cierre[$r['usuario_id']] = $r['estado'];

// Contadores globales
$totalTecnicos = count($equipo);
$pendientes = count(array_filter($est_cierre, fn($e)=>$e==='enviado_jefe'));

$titulo = 'Mi equipo';
include 'includes/head.php'; include 'includes/nav.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0"><i class="bi bi-people me-2"></i>Mi equipo - <?= nombreMes($mes) ?> <?= $anio ?></h4>
    <div class="small text-muted"><?= $totalTecnicos ?> tecnico<?= $totalTecnicos===1?'':'s' ?> bajo tu supervision<?= $pendientes?' - <span class="text-warning fw-semibold">'.$pendientes.' pendiente(s) de revision</span>':'' ?></div>
  </div>
</div>

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
          <option value="">Todas las regiones</option>
          <?php foreach ($regionesDisponibles as $r): ?>
            <option value="<?= h($r) ?>" <?= $fregion===$r?'selected':'' ?>><?= h($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-3 col-md-2">
        <label class="form-label small mb-1">Mes</label>
        <select name="mes" class="form-select" onchange="this.form.submit()">
          <?php for($m=1;$m<=12;$m++): ?>
            <option value="<?= $m ?>" <?= $m===$mes?'selected':'' ?>><?= nombreMes($m) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-3 col-md-2">
        <label class="form-label small mb-1">Año</label>
        <select name="anio" class="form-select" onchange="this.form.submit()">
          <?php for($y=date('Y');$y>=date('Y')-2;$y--): ?>
            <option value="<?= $y ?>" <?= $y===$anio?'selected':'' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-12 col-md-1 d-grid">
        <button class="btn btn-primary"><i class="bi bi-funnel"></i></button>
      </div>
    </div>
  </div>
</form>

<?php if (!$equipo): ?>
  <div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    <?= $q || $fregion ? 'No se encontraron tecnicos con esos filtros.' : 'No tienes tecnicos asignados aun. Pidele al administrador que te asigne tu equipo.' ?>
  </div>
<?php else: ?>
  <?php foreach ($grupos as $region => $us): ?>
    <div class="mb-4">
      <h6 class="text-uppercase text-muted mb-2 border-bottom pb-1">
        <i class="bi bi-geo-alt-fill text-primary"></i> <?= h($region) ?>
        <span class="badge bg-secondary ms-1"><?= count($us) ?></span>
      </h6>
      <div class="row g-3">
        <?php foreach ($us as $u):
          $a   = asignacionPeriodo($pdo,$u['id'],$anio,$mes);
          $gtot= totalGastadoPeriodo($pdo,$u['id'],$anio,$mes);
          $s   = ($a['total']??0) - $gtot;
          $pct = ($a['total']??0) > 0 ? round($gtot*100/$a['total'],0) : 0;
          $est = $est_cierre[$u['id']] ?? '';
          if ($festado !== '' && $est !== $festado) continue;
        ?>
          <div class="col-12 col-md-6 col-lg-4">
            <div class="card h-100 shadow-sm tecnico-card">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
                  <?= avatarUsuario($u['nombre'], $u['foto_perfil'] ?? '', 44) ?>
                  <div class="flex-grow-1" style="min-width:0;">
                    <div class="fw-bold text-truncate" style="font-size:1.05rem;"><?= h($u['nombre']) ?></div>
                    <div class="small text-muted text-truncate">
                      <i class="bi bi-person"></i> @<?= h($u['usuario']) ?>
                      <?php if ($u['cargo']): ?> - <?= h($u['cargo']) ?><?php endif; ?>
                    </div>
                    <div class="small mt-1">
                      <?php if ($u['ciudad']): ?>
                        <span class="badge bg-primary-subtle text-primary"><i class="bi bi-geo-alt"></i> <?= h($u['ciudad']) ?></span>
                      <?php endif; ?>
                      <?php if ($u['zona']): ?>
                        <span class="badge bg-light text-dark border"><?= h($u['zona']) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>
                  <?php if ($est): ?><span class="estado <?= h($est) ?> ms-2"><?= h($est) ?></span><?php endif; ?>
                </div>

                <div class="row g-2 small">
                  <div class="col-4"><div class="text-muted">Asignado</div><div class="fw-semibold text-nowrap"><?= fmtCLP($a['total']??0) ?></div></div>
                  <div class="col-4"><div class="text-muted">Gastado</div><div class="fw-semibold text-danger text-nowrap"><?= fmtCLP($gtot) ?></div></div>
                  <div class="col-4"><div class="text-muted">Saldo</div><div class="fw-semibold text-nowrap text-<?= $s<0?'danger':'success' ?>"><?= fmtCLP($s) ?></div></div>
                </div>
                <div class="progress mt-2" style="height:6px;">
                  <div class="progress-bar bg-<?= $pct>=90?'danger':($pct>=70?'warning':'primary') ?>" style="width:<?= min(100,$pct) ?>%"></div>
                </div>

                <div class="mt-3 d-flex gap-2">
                  <a href="jefe_ver_usuario.php?u=<?= $u['id'] ?>&anio=<?= $anio ?>&mes=<?= $mes ?>" class="btn btn-sm btn-outline-primary flex-grow-1">
                    <i class="bi bi-eye"></i> Ver gastos
                  </a>
                  <?php if ($est === 'enviado_jefe'): ?>
                    <a href="jefe_revisar_cierre.php?u=<?= $u['id'] ?>&anio=<?= $anio ?>&mes=<?= $mes ?>" class="btn btn-sm btn-warning">
                      <i class="bi bi-clipboard-check"></i> Revisar
                    </a>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<style>
.tecnico-card{ border-radius:14px; border:1px solid #e5e7eb; transition:transform .15s, box-shadow .15s; }
.tecnico-card:hover{ transform: translateY(-2px); box-shadow:0 8px 20px rgba(15,23,42,.08); }
</style>

<?php include 'includes/foot.php'; ?>
