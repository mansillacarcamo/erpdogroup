<?php
/**
 * combustible.php — Listado de hojas de Distribución de Combustible
 * Filtros: mes, obra, responsable, tipo combustible, estado.
 * Acceso: admin del módulo y responsables (ven solo lo suyo).
 */
require_once 'config.php';
requireAuth();
requireCombustibleAcceso($usuario, $pdo);

$esAdmin = isCombustibleAdmin($usuario, $pdo);

// ── Filtros ──────────────────────────────────────────────
$mes        = $_GET['mes']        ?? date('Y-m');
$obraId     = (int)($_GET['obra_id'] ?? 0);
$respId     = (int)($_GET['resp_id'] ?? 0);
$tipoComb   = $_GET['tipo_combustible'] ?? '';
$estado     = $_GET['estado']     ?? '';

[$anio, $mesNum] = explode('-', $mes);
$inicio = "$mes-01";
$fin    = date('Y-m-t', strtotime($inicio));

$where = ["fecha BETWEEN ? AND ?"];
$params = [$inicio, $fin];

// Si NO es admin, filtrar solo sus hojas
if (!$esAdmin) {
  $resp = getResponsableCombustible($usuario, $pdo);
  if (!$resp) {
    // No es responsable ni admin — no debería estar acá
    header('Location: inicio.php');
    exit;
  }
  $where[] = "(responsable_id = ? OR creado_por = ?)";
  $params[] = $resp['id'];
  $params[] = $usuario['id'];
}

if ($obraId)    { $where[] = "obra_id = ?";          $params[] = $obraId; }
if ($respId && $esAdmin) { $where[] = "responsable_id = ?"; $params[] = $respId; }
if ($tipoComb)  { $where[] = "tipo_combustible = ?"; $params[] = $tipoComb; }
if ($estado)    { $where[] = "estado = ?";           $params[] = $estado; }

$whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

$st = $pdo->prepare("SELECT * FROM combustible_hojas $whereSQL ORDER BY fecha DESC, id DESC");
$st->execute($params);
$hojas = $st->fetchAll(PDO::FETCH_ASSOC);

// Enriquecer cada hoja con datos del perfil del responsable (rut, tel, email)
$idsResp = array_filter(array_unique(array_map(fn($h) => (int)$h['responsable_id'], $hojas)));
$mapResp = [];
if ($idsResp) {
    $ph = implode(',', array_fill(0, count($idsResp), '?'));
    $stR = $pdo->prepare("SELECT id, rut, telefono, email, fono, obra_nombre FROM combustible_responsables WHERE id IN ($ph)");
    $stR->execute(array_values($idsResp));
    foreach ($stR->fetchAll(PDO::FETCH_ASSOC) as $r) $mapResp[(int)$r['id']] = $r;
}
foreach ($hojas as &$_h) {
    $r = $mapResp[(int)$_h['responsable_id']] ?? null;
    $_h['resp_rut']   = $r['rut']      ?? '';
    $_h['resp_tel']   = $r['telefono'] ?? '';
    $_h['resp_email'] = $r['email']    ?? '';
    $_h['resp_fono']  = $r['fono']     ?? '';
    $_h['resp_obra']  = $r['obra_nombre'] ?? '';
}
unset($_h);

// Listas para filtros
$obras = $pdo->query("SELECT id, codigo, nombre FROM obras ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$responsables = $pdo->query("SELECT id, nombre, obra_nombre FROM combustible_responsables WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// ── Perfil del responsable a mostrar (solo si esta filtrado o si el usuario es responsable) ──
$respPerfil = null;
$respIdMostrar = 0;
if ($esAdmin && $respId > 0) {
    $respIdMostrar = $respId;
} elseif (!$esAdmin && !empty($resp['id'])) {
    $respIdMostrar = (int)$resp['id'];
}
if ($respIdMostrar > 0) {
    $stPerfil = $pdo->prepare("SELECT cr.*, o.codigo AS obra_codigo, o.nombre AS obra_nombre_full,
                                       u.nombre AS usuario_nombre
                               FROM combustible_responsables cr
                               LEFT JOIN obras    o ON o.id = cr.obra_id
                               LEFT JOIN usuarios u ON u.id = cr.usuario_id
                               WHERE cr.id = ?");
    $stPerfil->execute([$respIdMostrar]);
    $respPerfil = $stPerfil->fetch(PDO::FETCH_ASSOC) ?: null;
}
// KPIs especificos del responsable (cuando se muestra el perfil)
$respKpis = ['hojas'=>0,'litros'=>0,'diesel'=>0,'gasolina'=>0,'ult_hoja'=>null,
             'fuente_camion'=>0,'fuente_storage'=>0,'fuente_storage2'=>0];
if ($respPerfil) {
    $stRk = $pdo->prepare("SELECT COUNT(*) hojas, COALESCE(SUM(total_litros),0) litros,
                                  COALESCE(SUM(CASE WHEN tipo_combustible='diesel'   THEN total_litros END),0) diesel,
                                  COALESCE(SUM(CASE WHEN tipo_combustible='gasolina' THEN total_litros END),0) gasolina,
                                  COALESCE(SUM(CASE WHEN tipo_fuente='camion'   THEN total_litros END),0) fuente_camion,
                                  COALESCE(SUM(CASE WHEN tipo_fuente='storage'  THEN total_litros END),0) fuente_storage,
                                  COALESCE(SUM(CASE WHEN tipo_fuente='storage2' THEN total_litros END),0) fuente_storage2,
                                  MAX(fecha) ult_hoja
                           FROM combustible_hojas
                           WHERE responsable_id=? AND fecha BETWEEN ? AND ?");
    $stRk->execute([$respIdMostrar, $inicio, $fin]);
    if ($r = $stRk->fetch(PDO::FETCH_ASSOC)) {
        $respKpis = array_merge($respKpis, $r);
    }
}

// Resumen del mes
$resumen = ['total_hojas' => count($hojas), 'total_litros' => 0, 'diesel' => 0, 'gasolina' => 0];
foreach ($hojas as $h) {
  $resumen['total_litros'] += (float)$h['total_litros'];
  if ($h['tipo_combustible'] === 'diesel')   $resumen['diesel']   += (float)$h['total_litros'];
  if ($h['tipo_combustible'] === 'gasolina') $resumen['gasolina'] += (float)$h['total_litros'];
}

// ── Datos para gráficos del mes (respeta los mismos filtros que la tabla) ──
$wsqlChart = implode(' AND ', $where);

// Evolución diaria de litros del mes
$stEvol = $pdo->prepare("SELECT fecha, SUM(total_litros) tot
                         FROM combustible_hojas
                         WHERE $wsqlChart
                         GROUP BY fecha ORDER BY fecha");
$stEvol->execute($params);
$evolRows = $stEvol->fetchAll(PDO::FETCH_ASSOC);

// Diesel vs Gasolina (litros)
$stPie = $pdo->prepare("SELECT tipo_combustible, SUM(total_litros) tot
                        FROM combustible_hojas
                        WHERE $wsqlChart
                        GROUP BY tipo_combustible");
$stPie->execute($params);
$pieRows = $stPie->fetchAll(PDO::FETCH_ASSOC);

// Top 5 obras del mes
$stObras = $pdo->prepare("SELECT COALESCE(NULLIF(obra_nombre,''),'(sin obra)') obra, SUM(total_litros) tot
                          FROM combustible_hojas
                          WHERE $wsqlChart
                          GROUP BY obra_nombre ORDER BY tot DESC LIMIT 5");
$stObras->execute($params);
$obrasTopRows = $stObras->fetchAll(PDO::FETCH_ASSOC);

// Top 5 patentes/equipos del mes (el WHERE necesita prefijar columnas con h.)
$wsqlEq = preg_replace('/\b(fecha|responsable_id|creado_por|obra_id|tipo_combustible|estado)\b/', 'h.$1', $wsqlChart);
$stEq = $pdo->prepare("SELECT COALESCE(NULLIF(v.patente,''),'(sin patente)') patente,
                              SUM(v.cantidad_litros) tot
                       FROM combustible_vales v
                       JOIN combustible_hojas h ON h.id = v.hoja_id
                       WHERE $wsqlEq AND v.cantidad_litros > 0
                       GROUP BY v.patente ORDER BY tot DESC LIMIT 5");
$stEq->execute($params);
$equiposTopRows = $stEq->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>
<a href="/inicio.php" data-volver data-fallback="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver</a>


<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <h3 class="mb-0"><i class="bi bi-fuel-pump-fill text-warning me-2"></i>Distribución de Combustible</h3>
  <div class="d-flex gap-2">
    <a href="combustible_hoja_nueva.php" class="btn btn-warning text-dark fw-semibold">
      <i class="bi bi-plus-circle me-1"></i>Nueva hoja
    </a>
    <?php if ($esAdmin): ?>
    <a href="admin_combustible.php" class="btn btn-outline-secondary">
      <i class="bi bi-gear me-1"></i>Administración
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_GET['error']) && $_GET['error']==='sin_permiso'): ?>
  <div class="alert alert-danger">No tienes permisos para esa acción.</div>
<?php endif; ?>

<?php if ($respPerfil):
  $iniPerfil = mb_strtoupper(mb_substr(trim($respPerfil['nombre'] ?: '?'), 0, 1));
  $tienePortal = !empty($respPerfil['usuario']) && !empty($respPerfil['password_hash']);
  $obraTxt = trim((($respPerfil['obra_codigo'] ?? '') ? $respPerfil['obra_codigo'].' · ' : '') . ($respPerfil['obra_nombre_full'] ?? $respPerfil['obra_nombre'] ?? ''));
  $hayFuente = ($respKpis['fuente_camion'] + $respKpis['fuente_storage'] + $respKpis['fuente_storage2']) > 0;
?>
<!-- ────── PERFIL DEL RESPONSABLE (mobile-first) ────── -->
<div class="perfil-comb-card mb-3">

  <!-- HEADER: Identidad -->
  <div class="pc-section pc-header">
    <div class="d-flex align-items-center gap-3">
      <div class="pc-avatar"><?= htmlspecialchars($iniPerfil) ?></div>
      <div class="flex-grow-1 min-w-0">
        <div class="pc-eyebrow">Responsable de Combustible</div>
        <div class="pc-name"><?= htmlspecialchars($respPerfil['nombre']) ?></div>
        <?php if (!empty($respPerfil['rut']) || !empty($respPerfil['usuario'])): ?>
          <div class="pc-meta">
            <?php if (!empty($respPerfil['usuario'])): ?>@<?= htmlspecialchars($respPerfil['usuario']) ?><?php endif; ?>
            <?php if (!empty($respPerfil['usuario']) && !empty($respPerfil['rut'])): ?> · <?php endif; ?>
            <?php if (!empty($respPerfil['rut'])): ?>RUT <?= htmlspecialchars($respPerfil['rut']) ?><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="pc-badges mt-2">
      <?php if (!empty($respPerfil['activo'])): ?>
        <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Activo</span>
      <?php else: ?>
        <span class="badge bg-danger"><i class="bi bi-slash-circle-fill me-1"></i>Inactivo</span>
      <?php endif; ?>
      <?php if ($tienePortal): ?>
        <span class="badge bg-warning text-dark"><i class="bi bi-phone-fill me-1"></i>Portal móvil</span>
      <?php endif; ?>
      <?php if ($respPerfil['usuario_nombre'] ?? null): ?>
        <span class="badge bg-light text-dark border"><i class="bi bi-link-45deg me-1"></i><?= htmlspecialchars($respPerfil['usuario_nombre']) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- CONTACTO: telefono / email / obra -->
  <div class="pc-section">
    <div class="pc-section-title"><i class="bi bi-person-rolodex me-1"></i>Contacto</div>
    <div class="pc-contact-list">
      <?php if (!empty($respPerfil['telefono'])): ?>
        <a href="tel:<?= htmlspecialchars(preg_replace('/\D/','', $respPerfil['telefono'])) ?>" class="pc-contact-item">
          <span class="pc-ci-icon bg-success-subtle"><i class="bi bi-telephone-fill text-success"></i></span>
          <span class="pc-ci-body">
            <span class="pc-ci-label">Teléfono</span>
            <span class="pc-ci-value"><?= htmlspecialchars($respPerfil['telefono']) ?></span>
          </span>
          <i class="bi bi-chevron-right pc-ci-chev"></i>
        </a>
      <?php endif; ?>
      <?php if (!empty($respPerfil['fono'])): ?>
        <a href="tel:<?= htmlspecialchars(preg_replace('/\D/','', $respPerfil['fono'])) ?>" class="pc-contact-item">
          <span class="pc-ci-icon bg-secondary-subtle"><i class="bi bi-telephone text-secondary"></i></span>
          <span class="pc-ci-body">
            <span class="pc-ci-label">Fono adicional</span>
            <span class="pc-ci-value"><?= htmlspecialchars($respPerfil['fono']) ?></span>
          </span>
          <i class="bi bi-chevron-right pc-ci-chev"></i>
        </a>
      <?php endif; ?>
      <?php if (!empty($respPerfil['email'])): ?>
        <a href="mailto:<?= htmlspecialchars($respPerfil['email']) ?>" class="pc-contact-item">
          <span class="pc-ci-icon bg-primary-subtle"><i class="bi bi-envelope-fill text-primary"></i></span>
          <span class="pc-ci-body">
            <span class="pc-ci-label">Email</span>
            <span class="pc-ci-value"><?= htmlspecialchars($respPerfil['email']) ?></span>
          </span>
          <i class="bi bi-chevron-right pc-ci-chev"></i>
        </a>
      <?php endif; ?>
      <?php if ($obraTxt !== ''): ?>
        <div class="pc-contact-item pc-contact-static">
          <span class="pc-ci-icon bg-warning-subtle"><i class="bi bi-building text-warning"></i></span>
          <span class="pc-ci-body">
            <span class="pc-ci-label">Obra asignada</span>
            <span class="pc-ci-value"><?= htmlspecialchars($obraTxt) ?></span>
          </span>
        </div>
      <?php endif; ?>
      <?php if (empty($respPerfil['telefono']) && empty($respPerfil['fono']) && empty($respPerfil['email']) && $obraTxt === ''): ?>
        <div class="text-muted small">— Sin datos de contacto —</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- KPIs DEL MES -->
  <div class="pc-section">
    <div class="pc-section-title">
      <i class="bi bi-bar-chart-fill me-1"></i>Resumen del mes
      <?php if (!empty($respKpis['ult_hoja'])): ?>
        <span class="pc-section-extra">Última hoja: <?= date('d/m/Y', strtotime($respKpis['ult_hoja'])) ?></span>
      <?php endif; ?>
    </div>
    <div class="pc-kpi-grid">
      <div class="pc-kpi">
        <div class="kpi-num"><?= (int)$respKpis['hojas'] ?></div>
        <div class="kpi-lab">Hojas</div>
      </div>
      <div class="pc-kpi kpi-litros">
        <div class="kpi-num"><?= formatCLP($respKpis['litros'], 0) ?></div>
        <div class="kpi-lab">Litros</div>
      </div>
      <div class="pc-kpi kpi-diesel">
        <div class="kpi-num"><?= formatCLP($respKpis['diesel'], 0) ?></div>
        <div class="kpi-lab">Diésel</div>
      </div>
      <div class="pc-kpi kpi-gasolina">
        <div class="kpi-num"><?= formatCLP($respKpis['gasolina'], 0) ?></div>
        <div class="kpi-lab">Gasolina</div>
      </div>
    </div>
  </div>

  <!-- DISTRIBUCION POR FUENTE -->
  <?php if ($hayFuente): ?>
  <div class="pc-section">
    <div class="pc-section-title"><i class="bi bi-truck me-1"></i>Fuente del mes</div>
    <div class="pc-fuente-list">
      <div class="pc-fuente-row fuente-camion">
        <span class="pc-f-icon"><i class="bi bi-truck"></i></span>
        <span class="pc-f-name">Camión</span>
        <span class="pc-f-val"><?= formatCLP($respKpis['fuente_camion'], 0) ?> L</span>
      </div>
      <div class="pc-fuente-row fuente-storage1">
        <span class="pc-f-icon"><i class="bi bi-box-seam"></i></span>
        <span class="pc-f-name">Storage 1</span>
        <span class="pc-f-val"><?= formatCLP($respKpis['fuente_storage'], 0) ?> L</span>
      </div>
      <div class="pc-fuente-row fuente-storage2">
        <span class="pc-f-icon"><i class="bi bi-box-seam-fill"></i></span>
        <span class="pc-f-name">Storage 2</span>
        <span class="pc-f-val"><?= formatCLP($respKpis['fuente_storage2'], 0) ?> L</span>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>
<style>
.perfil-comb-card{
  background: linear-gradient(180deg,#ffffff 0%,#fffaf3 100%);
  border:1px solid #e5e7eb;
  border-left:4px solid #d97706;
  border-radius:14px;
  overflow:hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.pc-section{ padding:14px 16px; }
.pc-section + .pc-section{ border-top:1px solid #f3f4f6; }
.pc-header{ background: linear-gradient(135deg,#fff7ed 0%,#ffedd5 100%); }
.pc-avatar{
  width:60px;height:60px;border-radius:14px;
  display:inline-flex;align-items:center;justify-content:center;
  background: linear-gradient(135deg,#d97706 0%,#92400e 100%);
  color:#fff;font-size:1.6rem;font-weight:800;letter-spacing:1px;
  box-shadow: 0 5px 14px rgba(217,119,6,.28);
  flex-shrink:0;
}
.pc-eyebrow{
  font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;
  color:#92400e;
}
.pc-name{
  font-size:1.25rem;font-weight:800;color:#0f172a;line-height:1.15;
  word-break:break-word;
}
.pc-meta{ font-size:.78rem;color:#6b7280;margin-top:2px; }
.pc-badges{ display:flex;flex-wrap:wrap;gap:6px; }
.pc-section-title{
  font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;
  color:#92400e;margin-bottom:10px;
  display:flex;align-items:center;gap:6px;
}
.pc-section-extra{
  margin-left:auto;font-weight:500;text-transform:none;letter-spacing:0;
  color:#6b7280;font-size:.72rem;
}

/* Lista de contacto tipo "iOS settings" */
.pc-contact-list{ display:flex;flex-direction:column;gap:8px; }
.pc-contact-item{
  display:flex;align-items:center;gap:12px;
  padding:10px 12px;
  background:#fff;border:1px solid #f3f4f6;border-radius:10px;
  text-decoration:none;color:inherit;
  transition: background .15s,border-color .15s,transform .12s;
}
.pc-contact-item:not(.pc-contact-static):hover,
.pc-contact-item:not(.pc-contact-static):focus{
  background:#fffbeb;border-color:#fcd34d;color:inherit;
  transform: translateY(-1px);
}
.pc-contact-item:not(.pc-contact-static):active{ transform: translateY(0); }
.pc-ci-icon{
  width:36px;height:36px;border-radius:10px;flex-shrink:0;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:1rem;
}
.pc-ci-body{ flex:1;min-width:0;display:flex;flex-direction:column; }
.pc-ci-label{ font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.4px; }
.pc-ci-value{ font-size:.95rem;font-weight:600;color:#0f172a;word-break:break-word; }
.pc-ci-chev{ color:#cbd5e1;font-size:.85rem;flex-shrink:0; }

/* KPI grid: 2 columnas en mobile, 4 en desktop */
.pc-kpi-grid{
  display:grid;
  grid-template-columns: repeat(2, minmax(0,1fr));
  gap:8px;
}
@media (min-width: 768px){
  .pc-kpi-grid{ grid-template-columns: repeat(4, minmax(0,1fr)); }
}
.pc-kpi{
  background:#fff;border:1px solid #e5e7eb;border-radius:10px;
  padding:10px 8px;text-align:center;
  display:flex;flex-direction:column;justify-content:center;
}
.pc-kpi .kpi-num{ font-size:1.15rem;font-weight:800;color:#1f2937;line-height:1.1; }
.pc-kpi .kpi-lab{ font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-top:3px; }
.pc-kpi.kpi-litros { border-color:#d97706;background:#fffaf3; }
.pc-kpi.kpi-litros  .kpi-num{ color:#92400e; }
.pc-kpi.kpi-diesel { border-color:#fbbf24;background:#fffbeb; }
.pc-kpi.kpi-diesel  .kpi-num{ color:#92400e; }
.pc-kpi.kpi-gasolina{ border-color:#3b82f6;background:#eff6ff; }
.pc-kpi.kpi-gasolina .kpi-num{ color:#1e3a8a; }

/* Lista de fuente */
.pc-fuente-list{ display:flex;flex-direction:column;gap:6px; }
.pc-fuente-row{
  display:flex;align-items:center;gap:10px;
  padding:10px 12px;border-radius:10px;
  font-weight:600;font-size:.92rem;
  border:1px solid transparent;
}
.pc-fuente-row .pc-f-icon{
  width:30px;height:30px;border-radius:8px;flex-shrink:0;
  display:inline-flex;align-items:center;justify-content:center;
  background: rgba(255,255,255,.65);
}
.pc-fuente-row .pc-f-name{ flex:1;min-width:0; }
.pc-fuente-row .pc-f-val{ font-weight:800; }
.pc-fuente-row.fuente-camion  { background:#e0e7ff;color:#3730a3;border-color:#c7d2fe; }
.pc-fuente-row.fuente-storage1{ background:#dcfce7;color:#166534;border-color:#bbf7d0; }
.pc-fuente-row.fuente-storage2{ background:#cffafe;color:#155e75;border-color:#a5f3fc; }
</style>
<?php endif; ?>

<!-- Tarjetas resumen -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small">Hojas del mes</div>
        <div class="fs-3 fw-bold"><?= $resumen['total_hojas'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="text-muted small">Total litros</div>
        <div class="fs-3 fw-bold"><?= formatCLP($resumen['total_litros'], 2) ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #d97706 !important">
      <div class="card-body">
        <div class="text-muted small">Diesel</div>
        <div class="fs-4 fw-bold"><?= formatCLP($resumen['diesel'], 2) ?> L</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #0d6efd !important">
      <div class="card-body">
        <div class="text-muted small">Gasolina</div>
        <div class="fs-4 fw-bold"><?= formatCLP($resumen['gasolina'], 2) ?> L</div>
      </div>
    </div>
  </div>
</div>

<!-- ╭───────────── GRÁFICOS DEL MES ─────────────╮ -->
<div class="row g-3 mb-3">
  <div class="col-12 col-lg-8">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-graph-up text-warning me-2"></i>Evolución diaria — Litros</h6>
        <canvas id="chartEvol" height="90"></canvas>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-pie-chart-fill text-primary me-2"></i>Diésel vs Gasolina</h6>
        <canvas id="chartTipo" height="180"></canvas>
      </div>
    </div>
  </div>
</div>
<div class="row g-3 mb-3">
  <div class="col-12 col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-trophy-fill text-success me-2"></i>Top 5 obras del mes</h6>
        <canvas id="chartObras" height="160"></canvas>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-truck text-info me-2"></i>Top 5 equipos / patentes</h6>
        <canvas id="chartEquipos" height="160"></canvas>
      </div>
    </div>
  </div>
</div>
<!-- ╰────────────────────────────────────────────╯ -->

<!-- Banner instalar app (solo móvil, se oculta si ya está instalado) -->
<div id="pwaInstallBanner" class="alert alert-warning d-none d-md-none align-items-center justify-content-between" style="border-radius:12px">
  <div>
    <strong><i class="bi bi-phone me-1"></i>Instalar DOGroup en tu teléfono</strong>
    <div class="small text-muted">Acceso directo, sin abrir el navegador</div>
  </div>
  <button id="btnInstallPwa" class="btn btn-warning fw-bold ms-2"><i class="bi bi-download me-1"></i>Instalar</button>
</div>

<!-- Filtros -->
<form method="get" class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <div class="row g-2">
      <div class="col-12 col-md-2">
        <label class="form-label small mb-1">Mes</label>
        <input type="month" name="mes" value="<?= htmlspecialchars($mes) ?>" class="form-control form-control-sm">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label small mb-1">Obra</label>
        <select name="obra_id" class="form-select form-select-sm">
          <option value="0">— Todas —</option>
          <?php foreach ($obras as $o): ?>
            <option value="<?= $o['id'] ?>" <?= $obraId==$o['id']?'selected':'' ?>>
              <?= htmlspecialchars(($o['codigo']?$o['codigo'].' · ':'').$o['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($esAdmin): ?>
      <div class="col-12 col-md-3">
        <label class="form-label small mb-1">Responsable</label>
        <select name="resp_id" class="form-select form-select-sm">
          <option value="0">— Todos —</option>
          <?php foreach ($responsables as $r): ?>
            <option value="<?= $r['id'] ?>" <?= $respId==$r['id']?'selected':'' ?>>
              <?= htmlspecialchars($r['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Combustible</label>
        <select name="tipo_combustible" class="form-select form-select-sm">
          <option value="">— Todos —</option>
          <option value="diesel"   <?= $tipoComb==='diesel'?'selected':'' ?>>Diesel</option>
          <option value="gasolina" <?= $tipoComb==='gasolina'?'selected':'' ?>>Gasolina</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Estado</label>
        <select name="estado" class="form-select form-select-sm">
          <option value="">— Todos —</option>
          <option value="abierta" <?= $estado==='abierta'?'selected':'' ?>>Abierta</option>
          <option value="cerrada" <?= $estado==='cerrada'?'selected':'' ?>>Cerrada</option>
        </select>
      </div>
      <div class="col-12 d-flex justify-content-end gap-2">
        <a href="combustible.php" class="btn btn-sm btn-outline-secondary">Limpiar</a>
        <button class="btn btn-sm btn-dark"><i class="bi bi-funnel me-1"></i>Filtrar</button>
      </div>
    </div>
  </div>
</form>

<!-- Listado agrupado por dia (acordeon) -->
<?php
// Agrupar hojas por fecha (ya vienen ordenadas DESC)
$porDia = [];
foreach ($hojas as $h) {
    $porDia[$h['fecha']][] = $h;
}
$diasNombres = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
?>
<?php if (empty($hojas)): ?>
  <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
    No hay hojas en este filtro.
  </div></div>
<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-2">
  <h6 class="mb-0 text-muted"><i class="bi bi-calendar3 me-1"></i>Hojas del filtro agrupadas por día (<?= count($porDia) ?> día<?= count($porDia)===1?'':'s' ?>)</h6>
  <div class="btn-group btn-group-sm" role="group">
    <button type="button" class="btn btn-outline-secondary" id="btnExpandAll"><i class="bi bi-arrows-expand me-1"></i>Expandir</button>
    <button type="button" class="btn btn-outline-secondary" id="btnCollapseAll"><i class="bi bi-arrows-collapse me-1"></i>Contraer</button>
  </div>
</div>

<div class="accordion combustible-acc" id="accDias">
  <?php $iDia = 0; foreach ($porDia as $fecha => $hojasDia): $iDia++; ?>
    <?php
      $tot       = array_sum(array_map(fn($h) => (float)$h['total_litros'], $hojasDia));
      $totDiesel = array_sum(array_map(fn($h) => $h['tipo_combustible']==='diesel'   ? (float)$h['total_litros'] : 0, $hojasDia));
      $totGasol  = array_sum(array_map(fn($h) => $h['tipo_combustible']==='gasolina' ? (float)$h['total_litros'] : 0, $hojasDia));
      $cerradas  = array_sum(array_map(fn($h) => $h['estado']==='cerrada' ? 1 : 0, $hojasDia));
      $abiertas  = count($hojasDia) - $cerradas;
      $tsDia     = strtotime($fecha);
      $diaSemana = $diasNombres[(int)date('w', $tsDia)];
      $expanded  = $iDia === 1; // primer dia expandido
      $collapseId = 'colDia' . md5($fecha);
    ?>
    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button <?= $expanded?'':'collapsed' ?>" type="button"
                data-bs-toggle="collapse" data-bs-target="#<?= $collapseId ?>"
                aria-expanded="<?= $expanded?'true':'false' ?>" aria-controls="<?= $collapseId ?>">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 w-100 pe-3">
            <div class="d-flex align-items-center gap-3">
              <div class="acc-fecha-box">
                <div class="acc-fecha-dia"><?= date('d', $tsDia) ?></div>
                <div class="acc-fecha-mes"><?= mb_strtoupper(date('M', $tsDia)) ?></div>
              </div>
              <div>
                <div class="fw-bold text-dark"><?= $diaSemana ?> <?= date('d/m/Y', $tsDia) ?></div>
                <div class="small text-muted">
                  <i class="bi bi-files me-1"></i><?= count($hojasDia) ?> hoja<?= count($hojasDia)===1?'':'s' ?>
                  <?php if ($abiertas): ?> · <span class="text-warning"><i class="bi bi-pencil"></i> <?= $abiertas ?> abierta<?= $abiertas===1?'':'s' ?></span><?php endif; ?>
                  <?php if ($cerradas): ?> · <span class="text-success"><i class="bi bi-lock-fill"></i> <?= $cerradas ?> cerrada<?= $cerradas===1?'':'s' ?></span><?php endif; ?>
                </div>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
              <?php if ($totDiesel > 0): ?>
                <span class="badge bg-warning text-dark"><i class="bi bi-fuel-pump-fill me-1"></i>Diésel <?= formatCLP($totDiesel, 0) ?> L</span>
              <?php endif; ?>
              <?php if ($totGasol > 0): ?>
                <span class="badge bg-primary"><i class="bi bi-fuel-pump me-1"></i>Gasolina <?= formatCLP($totGasol, 0) ?> L</span>
              <?php endif; ?>
              <span class="badge bg-dark fs-6"><i class="bi bi-droplet-fill me-1"></i><?= formatCLP($tot, 2) ?> L</span>
            </div>
          </div>
        </button>
      </h2>
      <div id="<?= $collapseId ?>" class="accordion-collapse collapse <?= $expanded?'show':'' ?>">
        <div class="accordion-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th style="width:36px"></th>
                  <th>Obra</th>
                  <th>Responsable</th>
                  <th>Tipo</th>
                  <th>Fuente</th>
                  <th>Origen</th>
                  <th class="text-end">Total Litros</th>
                  <th>Estado</th>
                  <th class="text-end">Acciones</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($hojasDia as $h):
                $viaPortal = empty($h['creado_por']);
              ?>
                <tr>
                  <td class="text-center">
                    <?php if ($viaPortal): ?>
                      <span title="Registrada desde la app móvil del responsable"><i class="bi bi-phone-fill text-warning"></i></span>
                    <?php else: ?>
                      <span title="Registrada desde el sistema"><i class="bi bi-display text-secondary"></i></span>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($h['obra_nombre'] ?: '—') ?></td>
                  <td>
                    <?php if (!empty($h['responsable_nombre'])): ?>
                      <div class="fw-semibold"><?= htmlspecialchars($h['responsable_nombre']) ?></div>
                      <?php $extras = []; ?>
                      <?php if (!empty($h['resp_rut'])):  $extras[] = '<i class="bi bi-card-text me-1"></i>'.htmlspecialchars($h['resp_rut']); endif; ?>
                      <?php if (!empty($h['resp_tel'])):
                          $telLimpio = preg_replace('/\D/','', $h['resp_tel']);
                          $extras[] = '<a href="tel:'.htmlspecialchars($telLimpio).'" class="text-decoration-none text-success" title="Llamar"><i class="bi bi-telephone-fill me-1"></i>'.htmlspecialchars($h['resp_tel']).'</a>';
                      endif; ?>
                      <?php if ($extras): ?>
                        <div class="small text-muted mt-1" style="display:flex;flex-wrap:wrap;gap:.6rem;">
                          <?= implode(' ', $extras) ?>
                        </div>
                      <?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                  </td>
                  <td>
                    <?php if ($h['tipo_combustible']==='diesel'): ?>
                      <span class="badge bg-warning text-dark">Diesel</span>
                    <?php else: ?>
                      <span class="badge bg-primary">Gasolina</span>
                    <?php endif; ?>
                  </td>
                  <td><span class="text-muted small"><?= ['camion'=>'Camión','storage'=>'Storage 1','storage2'=>'Storage 2'][$h['tipo_fuente']] ?? ucfirst($h['tipo_fuente']) ?></span></td>
                  <td>
                    <?php if ($viaPortal): ?>
                      <span class="badge bg-warning text-dark"><i class="bi bi-phone me-1"></i>App responsable</span>
                    <?php else: ?>
                      <span class="badge bg-light text-dark border"><i class="bi bi-display me-1"></i>Sistema</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end fw-semibold"><?= formatCLP($h['total_litros'], 2) ?> L</td>
                  <td>
                    <?php if ($h['estado']==='cerrada'): ?>
                      <span class="badge bg-success"><i class="bi bi-lock-fill me-1"></i>Cerrada</span>
                    <?php else: ?>
                      <span class="badge bg-secondary"><i class="bi bi-pencil me-1"></i>Abierta</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <a href="combustible_hoja_ver.php?id=<?= $h['id'] ?>" class="btn btn-sm btn-outline-dark" title="Ver/editar">
                      <i class="bi bi-eye"></i>
                    </a>
                    <a href="combustible_export_excel.php?hoja_id=<?= $h['id'] ?>"
                       class="btn btn-sm btn-outline-success" title="Exportar Excel de esta hoja">
                      <i class="bi bi-file-earmark-excel-fill"></i>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<style>
.combustible-acc .accordion-item{border:1px solid #e5e7eb;border-radius:10px !important;margin-bottom:8px;overflow:hidden;}
.combustible-acc .accordion-button{
  background: linear-gradient(135deg,#fff 0%,#fafafa 100%);
  padding:.85rem 1rem;
}
.combustible-acc .accordion-button:not(.collapsed){
  background: linear-gradient(135deg,#fff7ed 0%,#ffedd5 100%);
  color:#92400e;
  box-shadow:none;
}
.combustible-acc .accordion-button:focus{box-shadow:none;border-color:#fbbf24;}
.combustible-acc .acc-fecha-box{
  width:54px;height:54px;border-radius:10px;
  background: linear-gradient(135deg,#d97706 0%,#92400e 100%);
  color:#fff;text-align:center;
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  flex-shrink:0;
  box-shadow:0 4px 10px rgba(217,119,6,.25);
}
.combustible-acc .acc-fecha-dia{font-size:1.4rem;font-weight:800;line-height:1;}
.combustible-acc .acc-fecha-mes{font-size:.65rem;font-weight:700;letter-spacing:1px;margin-top:2px;opacity:.95;}
.combustible-acc .accordion-body{background:#fff;}
</style>
<script>
(function(){
  const expandAll = () => {
    document.querySelectorAll('#accDias .accordion-collapse').forEach(el => {
      bootstrap.Collapse.getOrCreateInstance(el, {toggle:false}).show();
    });
  };
  const collapseAll = () => {
    document.querySelectorAll('#accDias .accordion-collapse').forEach(el => {
      bootstrap.Collapse.getOrCreateInstance(el, {toggle:false}).hide();
    });
  };
  document.getElementById('btnExpandAll')?.addEventListener('click', expandAll);
  document.getElementById('btnCollapseAll')?.addEventListener('click', collapseAll);
})();
</script>
<?php endif; ?>

<!-- Acción: exportar todas las hojas filtradas -->
<?php if (!empty($hojas)): ?>
<div class="d-flex justify-content-end mt-3">
  <a href="combustible_export_excel.php?<?= http_build_query(array_filter([
        'mes'=>$mes,'obra_id'=>$obraId?:null,'resp_id'=>$respId?:null,
        'tipo_combustible'=>$tipoComb?:null,'estado'=>$estado?:null,'multi'=>1
      ])) ?>"
     class="btn btn-success">
    <i class="bi bi-file-earmark-excel-fill me-1"></i>
    Exportar Excel · <?= count($hojas) ?> hoja(s) del filtro
  </a>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

<!-- Chart.js para gráficos del módulo -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
  const fmt = n => new Intl.NumberFormat('es-CL').format(Math.round(n));
  const dorado = '#d97706', oscuro = '#1a1a2e', azul = '#0d6efd';

  const evol = <?= json_encode($evolRows ?: []) ?>;
  const pie  = <?= json_encode($pieRows ?: []) ?>;
  const top  = <?= json_encode($obrasTopRows ?: []) ?>;
  const eq   = <?= json_encode($equiposTopRows ?: []) ?>;

  // Evolución diaria
  if (document.getElementById('chartEvol')) {
    new Chart(document.getElementById('chartEvol'), {
      type: 'line',
      data: {
        labels: evol.map(r => {
          const d = new Date(r.fecha + 'T00:00:00');
          return d.toLocaleDateString('es-CL',{day:'2-digit',month:'2-digit'});
        }),
        datasets: [{
          label: 'Litros',
          data: evol.map(r => +r.tot),
          borderColor: dorado,
          backgroundColor: 'rgba(217,119,6,.15)',
          borderWidth: 2.5, fill: true, tension: .3,
          pointBackgroundColor: dorado, pointRadius: 4
        }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => fmt(c.parsed.y) + ' L' } } },
        scales: { y: { beginAtZero: true, ticks: { callback: v => fmt(v) } } }
      }
    });
  }

  // Diésel vs Gasolina
  if (document.getElementById('chartTipo')) {
    new Chart(document.getElementById('chartTipo'), {
      type: 'doughnut',
      data: {
        labels: pie.map(r => r.tipo_combustible.charAt(0).toUpperCase() + r.tipo_combustible.slice(1)),
        datasets: [{
          data: pie.map(r => +r.tot),
          backgroundColor: [dorado, azul],
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { callbacks: { label: c => c.label + ': ' + fmt(c.parsed) + ' L' } }
        }
      }
    });
  }

  // Top obras
  if (document.getElementById('chartObras')) {
    new Chart(document.getElementById('chartObras'), {
      type: 'bar',
      data: {
        labels: top.map(r => r.obra),
        datasets: [{ data: top.map(r => +r.tot), backgroundColor: oscuro, borderRadius: 6 }]
      },
      options: {
        indexAxis: 'y', responsive: true,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => fmt(c.parsed.x) + ' L' } } },
        scales: { x: { ticks: { callback: v => fmt(v) } } }
      }
    });
  }

  // Top equipos
  if (document.getElementById('chartEquipos')) {
    new Chart(document.getElementById('chartEquipos'), {
      type: 'bar',
      data: {
        labels: eq.map(r => r.patente),
        datasets: [{ data: eq.map(r => +r.tot), backgroundColor: azul, borderRadius: 6 }]
      },
      options: {
        indexAxis: 'y', responsive: true,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => fmt(c.parsed.x) + ' L' } } },
        scales: { x: { ticks: { callback: v => fmt(v) } } }
      }
    });
  }

  // ── PWA: capturar evento de instalación ──
  let deferredPrompt = null;
  const banner = document.getElementById('pwaInstallBanner');
  const btn    = document.getElementById('btnInstallPwa');

  // Registrar service worker
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(()=>{});
  }

  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferredPrompt = e;
    if (banner) banner.classList.remove('d-none');
  });

  if (btn) {
    btn.addEventListener('click', async () => {
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      deferredPrompt = null;
      if (banner) banner.classList.add('d-none');
    });
  }

  // Si ya está instalado, ocultar banner
  window.addEventListener('appinstalled', () => {
    if (banner) banner.classList.add('d-none');
  });
})();
</script>
