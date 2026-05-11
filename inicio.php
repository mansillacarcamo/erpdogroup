<?php
require_once 'config.php';
requireAuth();

/* ── Indicadores económicos (mindicador.cl) con caché diaria ─────── */
function fetchIndicadores($forzar = false) {
  $cacheFile = sys_get_temp_dir() . '/dogroup_indicadores.json';
  $hoy = date('Y-m-d');
  if (!$forzar && file_exists($cacheFile)) {
    $data = json_decode(file_get_contents($cacheFile), true);
    if ($data && ($data['_fecha_cache'] ?? '') === $hoy) return $data;
  }
  $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true,
    'header' => "User-Agent: DOGroup-App/1.0\r\n"]]);
  $raw = @file_get_contents('https://mindicador.cl/api', false, $ctx);
  if (!$raw) return null;
  $json = json_decode($raw, true);
  if (!$json) return null;
  $indicadores = [
    '_fecha_cache' => $hoy,
    'uf'   => ['label'=>'UF',  'moneda'=>'UF',  'valor'=>$json['uf']['valor']    ?? 0, 'fecha'=>$json['uf']['fecha']    ?? '', 'icono'=>'bi-bar-chart-line-fill', 'color'=>'#0d6efd'],
    'dolar'=> ['label'=>'USD', 'moneda'=>'USD', 'valor'=>$json['dolar']['valor']  ?? 0, 'fecha'=>$json['dolar']['fecha']  ?? '', 'icono'=>'bi-currency-dollar',      'color'=>'#198754'],
    'euro' => ['label'=>'EUR', 'moneda'=>'EUR', 'valor'=>$json['euro']['valor']   ?? 0, 'fecha'=>$json['euro']['fecha']   ?? '', 'icono'=>'bi-currency-euro',        'color'=>'#6f42c1'],
    'utm'  => ['label'=>'UTM', 'moneda'=>'',   'valor'=>$json['utm']['valor']    ?? 0, 'fecha'=>$json['utm']['fecha']    ?? '', 'icono'=>'bi-percent',              'color'=>'#dc3545'],
  ];
  file_put_contents($cacheFile, json_encode($indicadores));
  return $indicadores;
}
$forzarActualizacion = isset($_GET['refresh_ind']);
$indicadores = fetchIndicadores($forzarActualizacion);
if ($forzarActualizacion) {
  header('Location: inicio.php');
  exit;
}

$esValidador = false;
try {
  $stmtVal = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobadores WHERE usuario_id = ?");
  $stmtVal->execute([$usuario['id']]);
  $esValidador = (int)$stmtVal->fetchColumn() > 0;
} catch (Exception $e) {}

$puedeAprobar = in_array($usuario['rol'], ['admin', 'gerente_comercial']) || $esValidador;

$totOC = 0; $totCot = 0; $totClientes = 0; $totObras = 0; $totProv = 0;
$pendOC = 0; $pendCot = 0;
$totDespacho = 0; $despPend = 0;
$epPend = ['cobro_empresa' => 0, 'cobro_cliente' => 0, 'pago_proveedor' => 0];
try {
  $totOC       = (int)$pdo->query("SELECT COUNT(*) FROM ordenes_compra")->fetchColumn();
  $totCot      = (int)$pdo->query("SELECT COUNT(*) FROM cotizaciones")->fetchColumn();
  $totClientes = (int)$pdo->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
  $totObras    = (int)$pdo->query("SELECT COUNT(*) FROM obras")->fetchColumn();
  $totProv     = (int)$pdo->query("SELECT COUNT(*) FROM proveedores")->fetchColumn();
  $stEP = $pdo->query("SELECT tipo, COUNT(*) c FROM estados_pago WHERE estado IN ('pendiente','parcial','vencido') GROUP BY tipo");
  while ($row = $stEP->fetch(PDO::FETCH_ASSOC)) { if (isset($epPend[$row['tipo']])) $epPend[$row['tipo']] = (int)$row['c']; }
  $totDespacho = (int)$pdo->query("SELECT COUNT(*) FROM tickets_despacho")->fetchColumn();
  $despPend    = (int)$pdo->query("SELECT COUNT(*) FROM tickets_despacho WHERE estado='enviado'")->fetchColumn();
  if ($puedeAprobar) {
    $st1 = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobaciones WHERE usuario_id = ? AND estado = 'pendiente'");
    $st1->execute([$usuario['id']]); $pendOC = (int)$st1->fetchColumn();
    $st2 = $pdo->prepare("SELECT COUNT(*) FROM cot_aprobaciones WHERE usuario_id = ? AND estado = 'pendiente'");
    $st2->execute([$usuario['id']]); $pendCot = (int)$st2->fetchColumn();
  }
} catch (Exception $e) {}

require_once 'includes/header.php';
?>

<div class="dashboard-hero">
  <h1 class="dashboard-title">Panel principal</h1>
  <p class="dashboard-subtitle">Selecciona un módulo para comenzar a trabajar</p>
</div>

<?php if ($indicadores): ?>
<div class="indicadores-bar no-print">
  <?php foreach ($indicadores as $clave => $ind):
    if (!is_array($ind)) continue; // salta _fecha_cache y otros metadatos
    $fecha = $ind['fecha'] ? date('d/m/Y', strtotime($ind['fecha'])) : '';
    $valor = $ind['valor'];
    $esEntero = in_array($clave, ['utm']);
    $valFmt = $esEntero
      ? '$' . number_format($valor, 0, ',', '.')
      : '$' . number_format($valor, 2, ',', '.');
  ?>
  <div class="indicador-item" style="--ind-color:<?= $ind['color'] ?>">
    <div class="ind-icono"><i class="bi <?= $ind['icono'] ?>"></i></div>
    <div class="ind-datos">
      <span class="ind-label"><?= $ind['label'] ?></span>
      <span class="ind-valor"><?= $valFmt ?></span>
      <?php if ($fecha): ?><span class="ind-fecha"><?= $fecha ?></span><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="indicador-item ind-fuente">
    <div class="ind-icono"><i class="bi bi-cloud-check" style="color:#198754"></i></div>
    <div class="ind-datos">
      <span class="ind-label" style="color:#999">Actualizado</span>
      <span class="ind-valor" style="font-size:12px;color:#555;"><?= date('d/m/Y') ?></span>
      <a href="?refresh_ind=1" class="ind-fecha" style="color:#0d6efd;text-decoration:none;" title="Forzar actualización">
        <i class="bi bi-arrow-clockwise me-1"></i>Actualizar ahora
      </a>
    </div>
  </div>
</div>
<?php else: ?>
<div class="indicadores-bar no-print" style="justify-content:center;">
  <small class="text-muted"><i class="bi bi-wifi-off me-1"></i>Indicadores no disponibles (sin conexión a internet)</small>
</div>
<?php endif; ?>

<div class="dashboard-area">
  <button type="button" class="dashboard-area-toggle" data-area="comercial" aria-expanded="false">
    <i class="bi bi-briefcase-fill me-2"></i>Área Comercial
    <i class="bi bi-chevron-right chevron"></i>
  </button>
  <div class="dashboard-area-content modules-grid" data-area="comercial">

  <!-- 1. OBRAS -->
  <?php if (canAccess('obras', $usuario, $pdo) || $usuario['rol']==='admin'): ?>
  <div class="module-card module-obras">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-building-fill"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Obras</h3>
        <p class="module-desc">Proyectos en ejecución y mandantes</p>
      </div>
      <span class="module-badge"><?= $totObras ?></span>
    </div>
    <div class="module-actions">
      <a href="obras.php" class="module-btn module-btn-primary">
        <i class="bi bi-building"></i><span>Ver Obras</span>
      </a>
      <a href="obras.php?nuevo=1" class="module-btn">
        <i class="bi bi-plus-circle"></i><span>Registrar Obra</span>
      </a>
    </div>
  </div>
  <?php endif; ?>

  <!-- 2. CLIENTES Y PROVEEDORES -->
  <div class="module-card module-cli">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-people-fill"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Clientes y Proveedores</h3>
        <p class="module-desc">Directorio comercial: clientes y proveedores</p>
      </div>
      <span class="module-badge"><?= $totClientes ?> / <?= $totProv ?></span>
    </div>
    <div class="module-actions">
      <div class="module-sub-section">
        <span class="module-sub-label"><i class="bi bi-people me-1"></i>CLIENTES <?= $totClientes ?></span>
        <a href="clientes.php" class="module-btn module-btn-primary">
          <i class="bi bi-person-lines-fill"></i><span>Ver Clientes</span>
        </a>
        <a href="clientes.php?nuevo=1" class="module-btn">
          <i class="bi bi-person-plus"></i><span>Agregar Cliente</span>
        </a>
      </div>
      <div class="module-sub-section">
        <span class="module-sub-label"><i class="bi bi-truck me-1"></i>PROVEEDORES <?= $totProv ?></span>
        <a href="proveedores.php" class="module-btn module-btn-primary">
          <i class="bi bi-list-ul"></i><span>Ver Proveedores</span>
        </a>
        <a href="proveedores.php?nuevo=1" class="module-btn">
          <i class="bi bi-plus-circle"></i><span>Agregar Proveedor</span>
        </a>
      </div>
    </div>
  </div>

  <!-- 3. COTIZACIONES -->
  <div class="module-card module-cot">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-file-text-fill"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Cotizaciones</h3>
        <p class="module-desc">Genera cotizaciones y haz seguimiento</p>
      </div>
      <span class="module-badge"><?= $totCot ?></span>
    </div>
    <div class="module-actions">
      <a href="cotizaciones/cotizacion.php" class="module-btn module-btn-primary">
        <i class="bi bi-plus-circle"></i><span>Nueva Cotización</span>
      </a>
      <a href="cotizaciones/historial_cotizaciones.php" class="module-btn">
        <i class="bi bi-journal-text"></i><span>Historial</span>
      </a>
      <a href="cotizaciones/historial_cotizaciones.php?estado=adjudicada" class="module-btn">
        <i class="bi bi-graph-up"></i><span>Seguimiento</span>
      </a>
      <?php if ($puedeAprobar && $pendCot > 0): ?>
      <a href="mis_aprobaciones.php" class="module-btn module-btn-alert">
        <i class="bi bi-bell-fill"></i><span>Aprobaciones</span>
        <span class="module-counter"><?= $pendCot ?></span>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- 4. ÓRDENES DE COMPRA -->
  <div class="module-card module-oc">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Órdenes de Compra</h3>
        <p class="module-desc">Crea, gestiona y consulta tus OC</p>
      </div>
      <span class="module-badge"><?= $totOC ?></span>
    </div>
    <div class="module-actions">
      <a href="oc_nueva.php" class="module-btn module-btn-primary">
        <i class="bi bi-plus-circle"></i><span>Nueva OC</span>
      </a>
      <a href="historial.php" class="module-btn">
        <i class="bi bi-clock-history"></i><span>Historial</span>
      </a>
      <?php if ($puedeAprobar && $pendOC > 0): ?>
      <a href="mis_aprobaciones.php" class="module-btn module-btn-alert">
        <i class="bi bi-bell-fill"></i><span>Aprobaciones</span>
        <span class="module-counter"><?= $pendOC ?></span>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- 5. ESTADOS DE PAGO -->
  <?php if (canAccess('estados_pago', $usuario, $pdo)): ?>
  <div class="module-card module-ep">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-cash-stack"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Estados de Pago</h3>
        <p class="module-desc">Cobros y pagos por obra y mes</p>
      </div>
      <span class="module-badge"><?= array_sum($epPend) ?: 0 ?></span>
    </div>
    <div class="module-actions">
      <a href="estados_pago.php?tipo=cobro_empresa" class="module-btn module-btn-primary">
        <i class="bi bi-graph-up-arrow"></i><span>Ventas</span>
      </a>
      <a href="estados_pago.php?tipo=pago_proveedor" class="module-btn">
        <i class="bi bi-bank"></i><span>Pago Proveedores</span>
      </a>
      <a href="ep_reporte_excel.php?tipo=todos&periodo=mes&mes=<?= date('Y-m') ?>" class="module-btn">
        <i class="bi bi-file-earmark-excel-fill" style="color:#16a34a"></i><span>Reporte Excel</span>
      </a>
      </div>
  </div>
  <?php endif; ?>

  </div>
</div>

<div class="dashboard-area">
  <button type="button" class="dashboard-area-toggle" data-area="operaciones" aria-expanded="false">
    <i class="bi bi-gear-wide-connected me-2"></i>Área Operaciones
    <i class="bi bi-chevron-right chevron"></i>
  </button>
  <div class="dashboard-area-content modules-grid" data-area="operaciones">

  <!-- 6. TICKETS DE DESPACHO -->
  <?php if (canAccess('despacho', $usuario, $pdo)): ?>
  <div class="module-card module-despacho">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-truck-front-fill"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Tickets de Despacho</h3>
        <p class="module-desc">Registro y seguimiento de despachos</p>
      </div>
      <span class="module-badge"><?= $despPend > 0 ? $despPend : $totDespacho ?></span>
    </div>
    <div class="module-actions">
      <a href="tickets_despacho.php" class="module-btn module-btn-primary">
        <i class="bi bi-ticket-perforated-fill"></i><span>Ver Tickets</span>
        <?php if ($despPend > 0): ?><span class="module-counter"><?= $despPend ?></span><?php endif; ?>
      </a>
      <a href="despacho_conductor_portal.php" class="module-btn">
        <i class="bi bi-truck-front"></i><span>Portal Conductor</span>
      </a>
      <?php
        $esSuperDisp = false;
        try { $stSD=$pdo->prepare("SELECT COUNT(*) FROM obra_supervisores WHERE usuario_id=? AND activo=1"); $stSD->execute([$usuario['id']]); $esSuperDisp=(bool)$stSD->fetchColumn(); } catch(Exception $e){}
        if ($esSuperDisp || $usuario['rol']==='admin'):
      ?>
      <a href="obras_equipos.php" class="module-btn">
        <i class="bi bi-building"></i><span>Obras · Equipos</span>
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- 6.b DISTRIBUCIÓN DE COMBUSTIBLE -->
  <?php
    $verCombInicio = isCombustibleResponsable($usuario, $pdo) || isCombustibleAdmin($usuario, $pdo) || canAccess('combustible', $usuario, $pdo);
    $combHojasMes = 0; $combLitrosMes = 0;
    if ($verCombInicio) {
      try {
        $stCM = $pdo->query("SELECT COUNT(*), COALESCE(SUM(total_litros),0)
                             FROM combustible_hojas
                             WHERE strftime('%Y-%m', fecha) = strftime('%Y-%m', 'now', 'localtime')");
        $rowCM = $stCM->fetch(PDO::FETCH_NUM);
        $combHojasMes = (int)$rowCM[0]; $combLitrosMes = (float)$rowCM[1];
      } catch(Exception $e) {}
    }
    if ($verCombInicio):
  ?>
  <div class="module-card module-combustible" style="border-left:4px solid #d97706">
    <div class="module-card-header">
      <div class="module-icon" style="background:#d97706;color:#fff"><i class="bi bi-fuel-pump-fill"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Distribución de Combustible</h3>
        <p class="module-desc">Hojas diarias · Diésel y Gasolina · Gráficos del mes</p>
      </div>
      <span class="module-badge"><?= $combHojasMes ?></span>
    </div>
    <div class="module-actions">
      <a href="combustible.php" class="module-btn module-btn-primary">
        <i class="bi bi-list-ul"></i><span>Ver hojas</span>
      </a>
      <a href="combustible_hoja_nueva.php" class="module-btn">
        <i class="bi bi-plus-circle"></i><span>Nueva hoja</span>
      </a>
      <a href="combustible_export_excel.php?multi=1&mes=<?= date('Y-m') ?>" class="module-btn">
        <i class="bi bi-file-earmark-excel-fill"></i><span>Excel del mes</span>
      </a>
      <?php if (isCombustibleAdmin($usuario, $pdo)): ?>
      <a href="admin_combustible.php" class="module-btn">
        <i class="bi bi-gear-fill"></i><span>Administrar</span>
      </a>
      <?php endif; ?>
    </div>
    <?php if ($combLitrosMes > 0): ?>
    <div class="px-3 pb-3 small text-muted">
      <i class="bi bi-droplet-fill text-warning"></i>
      <strong><?= number_format($combLitrosMes, 0, ',', '.') ?> L</strong> distribuidos este mes
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  </div>
</div>

<div class="dashboard-area">
  <button type="button" class="dashboard-area-toggle" data-area="rrhh" aria-expanded="false">
    <i class="bi bi-people-fill me-2"></i>Recursos Humanos
    <i class="bi bi-chevron-right chevron"></i>
  </button>
  <div class="dashboard-area-content modules-grid" data-area="rrhh">
    <div class="module-card" style="grid-column: 1 / -1; text-align:center; color:#94a3b8;">
      <div class="module-card-header" style="justify-content:center;">
        <i class="bi bi-hourglass-split me-2"></i>
        <h3 class="module-title" style="color:#94a3b8;">Próximamente</h3>
      </div>
    </div>
  </div>
</div>

<div class="dashboard-area">
  <button type="button" class="dashboard-area-toggle" data-area="finanzas" aria-expanded="false">
    <i class="bi bi-cash-stack me-2"></i>Área Finanzas
    <i class="bi bi-chevron-right chevron"></i>
  </button>
  <div class="dashboard-area-content modules-grid" data-area="finanzas">

  <!-- 7. GASTOS DE FAENA -->
  <div class="module-card module-faena">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-receipt-cutoff"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Gastos por Obra</h3>
        <p class="module-desc">Presupuesto por obra · Conductores y Operadores</p>
      </div>
      <span class="module-badge"><i class="bi bi-wallet2"></i></span>
    </div>
    <div class="module-actions">
      <a href="gastos_faena.php" class="module-btn module-btn-primary">
        <i class="bi bi-receipt-cutoff"></i><span>Ver Gastos</span>
      </a>
      <?php
        $esSuperFaena = false;
        try { $stSF=$pdo->prepare("SELECT COUNT(*) FROM obra_supervisores WHERE usuario_id=? AND activo=1"); $stSF->execute([$usuario['id']]); $esSuperFaena=(bool)$stSF->fetchColumn(); } catch(Exception $e){}
        if ($esSuperFaena || $usuario['rol']==='admin'):
      ?>
      <a href="faena_supervisor.php" class="module-btn">
        <i class="bi bi-person-check-fill"></i><span>Panel Supervisor</span>
      </a>
      <?php endif; ?>
      <?php if (in_array($usuario['rol'] ?? '', ['admin','gerente_finanzas','gerente_comercial'])): ?>
      <a href="faena_admin.php" class="module-btn">
        <i class="bi bi-gear-fill"></i><span>Administrar</span>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- GASTOS DIARIOS — sistema controlgastos integrado -->
  <div class="module-card module-faena">
    <div class="module-card-header">
      <div class="module-icon" style="background:#0d9488"><i class="bi bi-calendar3"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Gastos diarios</h3>
        <p class="module-desc">Registro y revisión de gastos personales del día a día</p>
      </div>
      <span class="module-badge"><i class="bi bi-wallet"></i></span>
    </div>
    <div class="module-actions">
      <a href="/gastos_diarios/sso.php" class="module-btn module-btn-primary">
        <i class="bi bi-box-arrow-up-right"></i><span>Abrir Gastos diarios</span>
      </a>
    </div>
  </div>

  </div>
</div>

<?php if ($usuario['rol'] === 'admin'): ?>
<div class="dashboard-area">
  <button type="button" class="dashboard-area-toggle" data-area="sistema" aria-expanded="false">
    <i class="bi bi-shield-lock me-2"></i>Sistema
    <i class="bi bi-chevron-right chevron"></i>
  </button>
  <div class="dashboard-area-content modules-grid" data-area="sistema">

  <!-- 8. ADMINISTRACIÓN -->
  <div class="module-card module-adm">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-shield-lock"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Administración</h3>
        <p class="module-desc">Usuarios, roles y configuración</p>
      </div>
      <span class="module-badge"><i class="bi bi-gear-fill"></i></span>
    </div>
    <div class="module-actions">
      <a href="admin.php" class="module-btn module-btn-primary">
        <i class="bi bi-gear"></i><span>Panel Admin</span>
      </a>
      <a href="admin.php#usuarios" class="module-btn">
        <i class="bi bi-person-badge"></i><span>Usuarios</span>
      </a>
    </div>
  </div>

  </div>
</div>
<?php endif; ?>

<!-- Banner Instalar app DOGroup (visible en móvil cuando es instalable) -->
<div id="dogroupInstallBanner" class="d-none d-md-none position-fixed" style="bottom:16px;left:16px;right:16px;background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;border-radius:14px;padding:14px 16px;box-shadow:0 8px 24px rgba(0,0,0,.3);z-index:1100;display:flex;align-items:center;gap:12px">
  <img src="img/icon-192.png" alt="DOGroup" style="width:42px;height:42px;border-radius:8px;background:#fff;padding:4px">
  <div style="flex:1;min-width:0">
    <div style="font-weight:700;font-size:.95rem">Instalar DOGroup</div>
    <div style="font-size:.75rem;color:#ffd591">Acceso directo en tu teléfono</div>
  </div>
  <button id="btnDogroupInstall" class="btn btn-warning fw-bold btn-sm"><i class="bi bi-download"></i></button>
  <button id="btnDogroupClose" class="btn btn-link text-white p-0" style="font-size:1.2rem"><i class="bi bi-x"></i></button>
</div>

<script>
(function(){
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(()=>{});
  }
  let deferred = null;
  const banner = document.getElementById('dogroupInstallBanner');
  const btn    = document.getElementById('btnDogroupInstall');
  const close  = document.getElementById('btnDogroupClose');

  // No mostrar si el usuario lo cerró antes (sessionStorage solo dura la sesión)
  const dismissed = sessionStorage.getItem('dogroup_install_dismissed');

  window.addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferred = e;
    if (banner && !dismissed) banner.classList.remove('d-none');
  });

  if (btn) btn.addEventListener('click', async () => {
    if (!deferred) return;
    deferred.prompt();
    await deferred.userChoice;
    deferred = null;
    if (banner) banner.classList.add('d-none');
  });

  if (close) close.addEventListener('click', () => {
    sessionStorage.setItem('dogroup_install_dismissed', '1');
    if (banner) banner.classList.add('d-none');
  });

  window.addEventListener('appinstalled', () => {
    if (banner) banner.classList.add('d-none');
  });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
