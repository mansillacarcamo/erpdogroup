<?php
require_once 'config.php';
requireAuth();

$uid = (int)$_SESSION['user_id'];
$asig = asignacionPeriodo($pdo, $uid);
$gastado = totalGastadoPeriodo($pdo, $uid);
$total = (float)($asig['total'] ?? 0);
$saldo = $total - $gastado;
$pct = $total > 0 ? min(100, round($gastado * 100 / $total, 1)) : 0;

$p = periodoActual();
$mesNombre = nombreMes($p['mes']);

// últimos 5 gastos
$ultimos = $pdo->prepare("SELECT * FROM gastos WHERE usuario_id=? ORDER BY fecha DESC, id DESC LIMIT 5");
$ultimos->execute([$uid]);
$ultimos = $ultimos->fetchAll();

// datos del tecnico actual
$mu = $pdo->prepare("SELECT nombre, usuario, ciudad, region, zona, cargo, foto_perfil FROM usuarios WHERE id=?");
$mu->execute([$uid]); $mu = $mu->fetch();

// pop-up diario
$mostrarPopup = false;
$hoy = date('Y-m-d');
$u = $pdo->prepare("SELECT ultimo_popup_fecha FROM usuarios WHERE id=?");
$u->execute([$uid]);
$ult = $u->fetchColumn();
if ($ult !== $hoy) {
    $mostrarPopup = true;
    $pdo->prepare("UPDATE usuarios SET ultimo_popup_fecha=? WHERE id=?")->execute([$hoy, $uid]);
}

$titulo = 'Inicio';
include 'includes/head.php';
include 'includes/nav.php';
?>

<div class="card mb-3 border-0 shadow-sm">
  <div class="card-body py-2 px-3 d-flex align-items-center gap-2 flex-wrap">
    <?php $urlFotoDash = urlFotoUsuario($mu['foto_perfil'] ?? ''); ?>
    <?php if ($urlFotoDash): ?>
      <img src="<?= h($urlFotoDash) ?>" alt="<?= h($mu['nombre']) ?>" class="tecnico-avatar-sm" style="object-fit:cover;">
    <?php else: ?>
      <div class="tecnico-avatar-sm"><?= strtoupper(mb_substr($mu['nombre'] ?: 'U',0,1)) ?></div>
    <?php endif; ?>
    <div class="flex-grow-1">
      <div class="fw-bold"><?= h($mu['nombre']) ?></div>
      <div class="small text-muted">
        <?php if ($mu['cargo']): ?><?= h($mu['cargo']) ?> - <?php endif; ?>
        <?php if ($mu['region']): ?><i class="bi bi-geo-alt-fill"></i> <?= h($mu['region']) ?><?php endif; ?>
        <?php if ($mu['ciudad']): ?> - <?= h($mu['ciudad']) ?><?php endif; ?>
      </div>
    </div>
    <?php if (!$mu['ciudad'] || !$mu['region']): ?>
      <a href="perfil.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-geo-alt"></i> Completar datos</a>
    <?php endif; ?>
  </div>
</div>
<style>.tecnico-avatar-sm{ width:44px; height:44px; border-radius:50%; background:linear-gradient(135deg,#0d6efd,#6610f2); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.2rem; }</style>

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <div class="saldo-box bg-gradient-brand mb-3">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="label">Saldo disponible · <?= $mesNombre ?> <?= $p['anio'] ?></div>
          <div class="monto"><?= fmtCLP($saldo) ?></div>
          <div class="small mt-1">de <?= fmtCLP($total) ?> asignado</div>
        </div>
        <a href="gasto_nuevo.php" class="btn btn-light btn-sm fw-semibold">
          <i class="bi bi-plus-lg"></i> Gasto
        </a>
      </div>
      <div class="progress mt-3" style="height:10px; background:rgba(255,255,255,.25);">
        <div class="progress-bar bg-warning" style="width:<?= $pct ?>%"></div>
      </div>
      <div class="small mt-1">Llevas usado <?= $pct ?>%</div>
    </div>

    <div class="row g-2 mb-3">
      <div class="col-6 col-md-4">
        <div class="kpi"><div class="lbl">Asignado</div><div class="val"><?= fmtCLP($asig['monto_asignado'] ?? 0) ?></div></div>
      </div>
      <div class="col-6 col-md-4">
        <div class="kpi"><div class="lbl">Arrastre</div><div class="val"><?= fmtCLP($asig['monto_carryover'] ?? 0) ?></div></div>
      </div>
      <div class="col-12 col-md-4">
        <div class="kpi"><div class="lbl">Gastado</div><div class="val text-danger"><?= fmtCLP($gastado) ?></div></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-1"></i>Últimos gastos</span>
        <a href="mis_gastos.php" class="small text-decoration-none">Ver todos →</a>
      </div>
      <div class="card-body">
        <?php if (!$ultimos): ?>
          <div class="text-center text-muted py-4">
            <i class="bi bi-receipt" style="font-size:2.5rem; opacity:.3;"></i>
            <div class="mt-2">Aún no has registrado gastos este mes.</div>
            <a href="gasto_nuevo.php" class="btn btn-primary mt-2"><i class="bi bi-plus-lg"></i> Registrar primer gasto</a>
          </div>
        <?php else: foreach ($ultimos as $g): ?>
          <div class="list-group-item gasto px-2 py-2 d-flex justify-content-between align-items-center">
            <div>
              <div class="fw-semibold"><?= htmlspecialchars($g['descripcion'] ?: ($g['categoria'] ?: 'Gasto')) ?></div>
              <div class="small text-muted">
                <?= date('d/m/Y', strtotime($g['fecha'])) ?>
                <?php if ($g['categoria']): ?> · <span class="cat-pill"><?= htmlspecialchars($g['categoria']) ?></span><?php endif; ?>
              </div>
            </div>
            <div class="text-end">
              <div class="fw-bold text-danger">-<?= fmtCLP($g['monto']) ?></div>
              <span class="estado <?= htmlspecialchars($g['estado']) ?>"><?= htmlspecialchars($g['estado']) ?></span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-lightning-charge me-1"></i>Acciones rápidas</div>
      <div class="card-body d-grid gap-2">
        <a href="gasto_nuevo.php" class="btn btn-primary"><i class="bi bi-camera me-1"></i> Registrar gasto con foto</a>
        <a href="mis_gastos.php" class="btn btn-outline-primary"><i class="bi bi-list-ul me-1"></i> Mis gastos del mes</a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-info-circle me-1"></i>Tip</div>
      <div class="card-body small text-muted">
        Toma la foto de la boleta apenas hagas la compra. Así no se te pierde y se ahorra tiempo en el cierre mensual.
      </div>
    </div>
  </div>
</div>

<a href="gasto_nuevo.php" class="fab d-md-none" title="Nuevo gasto"><i class="bi bi-plus-lg"></i></a>

<?php if ($mostrarPopup): ?>
<div class="modal fade" id="popupBienvenida" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:18px;">
      <div class="modal-body text-center p-4">
        <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle bg-gradient-brand text-white"
             style="width:80px;height:80px;font-size:2rem;">
          <i class="bi bi-sun"></i>
        </div>
        <h4 class="fw-bold mb-1">¡Hola, <?= htmlspecialchars($_SESSION['user_nombre']) ?>!</h4>
        <p class="text-muted small mb-3"><?= fechaLarga() ?></p>

        <div class="saldo-box bg-gradient-brand mb-3">
          <div class="label">Tu saldo de hoy</div>
          <div class="monto"><?= fmtCLP($saldo) ?></div>
          <div class="small">de <?= fmtCLP($total) ?> asignado este mes</div>
        </div>

        <?php if ($saldo <= 0): ?>
          <div class="alert alert-danger small">Has consumido todo tu presupuesto. Cierra el mes para continuar.</div>
        <?php elseif ($pct >= 80): ?>
          <div class="alert alert-warning small">Llevas usado el <?= $pct ?>%. ¡Cuida tu presupuesto!</div>
        <?php else: ?>
          <p class="small text-muted">Recuerda registrar cada gasto con su boleta. ¡Que tengas un excelente día!</p>
        <?php endif; ?>

        <button class="btn btn-primary w-100" data-bs-dismiss="modal">Comenzar</button>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  new bootstrap.Modal(document.getElementById('popupBienvenida')).show();
});
</script>
<?php endif; ?>

<?php include 'includes/foot.php'; ?>
