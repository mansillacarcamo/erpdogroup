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
$epPend = ['cobro_dogroup'=>0,'cobro_empresa'=>0,'cobro_cliente'=>0,'pago_maquinaria'=>0,'pago_proveedor'=>0];
$totDespacho = 0; $despPend = 0;
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

<div class="modules-grid">

  <div class="module-card module-oc">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-file-earmark-ruled"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Órdenes de Compra</h3>
        <p class="module-desc">Crea, gestiona y consulta tus OC</p>
      </div>
      <span class="module-badge"><?= $totOC ?></span>
    </div>
    <div class="module-actions">
      <a href="index.php" class="module-btn module-btn-primary">
        <i class="bi bi-plus-circle"></i><span>Nueva OC</span>
      </a>
      <a href="historial.php" class="module-btn">
        <i class="bi bi-clock-history"></i><span>Historial</span>
      </a>
      <?php if ($puedeAprobar): ?>
      <a href="mis_aprobaciones.php" class="module-btn module-btn-warn">
        <i class="bi bi-bell"></i><span>Aprobaciones</span>
        <?php if ($pendOC > 0): ?><span class="module-pill"><?= $pendOC ?></span><?php endif; ?>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="module-card module-cot">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-receipt"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Cotizaciones</h3>
        <p class="module-desc">Genera cotizaciones y haz seguimiento</p>
      </div>
      <span class="module-badge"><?= $totCot ?></span>
    </div>
    <div class="module-actions">
      <a href="cotizacion.php" class="module-btn module-btn-primary">
        <i class="bi bi-plus-circle"></i><span>Nueva Cotización</span>
      </a>
      <a href="historial_cotizaciones.php" class="module-btn">
        <i class="bi bi-journal-text"></i><span>Historial</span>
      </a>
      <a href="seguimiento_cot.php" class="module-btn">
        <i class="bi bi-graph-up-arrow"></i><span>Seguimiento</span>
      </a>
      <?php if ($puedeAprobar && $pendCot > 0): ?>
      <a href="mis_aprobaciones.php" class="module-btn module-btn-warn">
        <i class="bi bi-bell"></i><span>Aprobaciones</span>
        <span class="module-pill"><?= $pendCot ?></span>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <div class="module-card module-cli">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-person-vcard"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Clientes y Proveedores</h3>
        <p class="module-desc">Directorio comercial: clientes y proveedores</p>
      </div>
      <span class="module-badge" title="Clientes / Proveedores"><?= $totClientes ?> / <?= $totProv ?></span>
    </div>

    <div class="module-subgroup">
      <div class="module-subgroup-title"><i class="bi bi-people-fill me-1"></i>Clientes <span class="module-pill-soft"><?= $totClientes ?></span></div>
      <div class="module-actions">
        <a href="clientes.php" class="module-btn module-btn-primary">
          <i class="bi bi-people"></i><span>Ver Clientes</span>
        </a>
        <a href="clientes.php?nuevo=1" class="module-btn">
          <i class="bi bi-person-plus"></i><span>Agregar Cliente</span>
        </a>
      </div>
    </div>

    <div class="module-subgroup">
      <div class="module-subgroup-title"><i class="bi bi-truck me-1"></i>Proveedores <span class="module-pill-soft"><?= $totProv ?></span></div>
      <div class="module-actions">
        <a href="proveedores.php" class="module-btn module-btn-primary">
          <i class="bi bi-list-ul"></i><span>Ver Proveedores</span>
        </a>
        <a href="proveedores.php?nuevo=1" class="module-btn">
          <i class="bi bi-plus-circle"></i><span>Agregar Proveedor</span>
        </a>
      </div>
    </div>
  </div>

  <div class="module-card module-obra">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-building"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Obras</h3>
        <p class="module-desc">Proyectos en ejecución y mandantes</p>
      </div>
      <span class="module-badge"><?= $totObras ?></span>
    </div>
    <div class="module-actions">
      <a href="obras.php" class="module-btn module-btn-primary">
        <i class="bi bi-building-gear"></i><span>Ver Obras</span>
      </a>
      <a href="obras.php?nuevo=1" class="module-btn">
        <i class="bi bi-plus-circle"></i><span>Nueva Obra</span>
      </a>
    </div>
  </div>

  <div class="module-card module-ep">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-cash-coin"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Estados de Pago</h3>
        <p class="module-desc">Cobros y pagos por obra y mes</p>
      </div>
      <span class="module-badge" title="Pendientes totales"><?= array_sum($epPend) ?></span>
    </div>
    <div class="module-actions">
      <a href="estados_pago.php?tipo=cobro_dogroup" class="module-btn module-btn-primary">
        <i class="bi bi-cash-stack"></i><span>Cobros DOGroup</span>
        <?php if ($epPend['cobro_dogroup'] > 0): ?><span class="module-pill"><?= $epPend['cobro_dogroup'] ?></span><?php endif; ?>
      </a>
      <a href="estados_pago.php?tipo=cobro_empresa" class="module-btn">
        <i class="bi bi-building-fill-up"></i><span>Cobro Empresas</span>
        <?php if ($epPend['cobro_empresa'] > 0): ?><span class="module-pill"><?= $epPend['cobro_empresa'] ?></span><?php endif; ?>
      </a>
      <a href="estados_pago.php?tipo=cobro_cliente" class="module-btn">
        <i class="bi bi-person-check-fill"></i><span>Cobro Clientes</span>
        <?php if ($epPend['cobro_cliente'] > 0): ?><span class="module-pill"><?= $epPend['cobro_cliente'] ?></span><?php endif; ?>
      </a>
      <a href="estados_pago.php?tipo=pago_maquinaria" class="module-btn">
        <i class="bi bi-truck-flatbed"></i><span>Pago Maquinarias</span>
        <?php if ($epPend['pago_maquinaria'] > 0): ?><span class="module-pill"><?= $epPend['pago_maquinaria'] ?></span><?php endif; ?>
      </a>
      <a href="estados_pago.php?tipo=pago_proveedor" class="module-btn">
        <i class="bi bi-bank"></i><span>Pago Proveedores</span>
        <?php if ($epPend['pago_proveedor'] > 0): ?><span class="module-pill"><?= $epPend['pago_proveedor'] ?></span><?php endif; ?>
      </a>
    </div>
  </div>

  <div class="module-card module-despacho">
    <div class="module-card-header">
      <div class="module-icon"><i class="bi bi-truck-front-fill"></i></div>
      <div class="module-head-text">
        <h3 class="module-title">Tickets de Despacho</h3>
        <p class="module-desc">Registro y seguimiento de despachos</p>
      </div>
      <?php if ($despPend > 0): ?><span class="module-badge" title="Pendientes"><?= $despPend ?></span><?php else: ?><span class="module-badge"><?= $totDespacho ?></span><?php endif; ?>
    </div>
    <div class="module-actions">
      <a href="tickets_despacho.php" class="module-btn module-btn-primary">
        <i class="bi bi-ticket-perforated"></i><span>Ver Tickets</span>
        <?php if ($despPend > 0): ?><span class="module-pill"><?= $despPend ?></span><?php endif; ?>
      </a>
      <a href="despacho_conductor_login.php" class="module-btn" target="_blank">
        <i class="bi bi-truck"></i><span>Portal Conductor</span>
      </a>
      <?php if ($usuario['rol'] === 'admin'): ?>
      <a href="tickets_despacho_admin.php" class="module-btn">
        <i class="bi bi-gear"></i><span>Configurar</span>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($usuario['rol'] === 'admin'): ?>
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
  <?php endif; ?>

</div>

<?php require_once 'includes/footer.php'; ?>
