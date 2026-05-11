<?php
require_once 'config.php';
requireAuth();
requireModulo('despacho', $usuario, $pdo);

$esAdminDespacho = isDespachoAdmin($usuario, $pdo);
$esReceptor = false;
$stR = $pdo->prepare("SELECT COUNT(*) FROM despacho_receptores WHERE usuario_id=?");
$stR->execute([$usuario['id']]);
$esReceptor = (int)$stR->fetchColumn() > 0;

$MATERIALES = [
  'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4','bajo_3'=>'Bajo 3',
  'bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada','grava'=>'Grava','gravilla'=>'Gravilla',
  'arena'=>'Arena','arena_tubo'=>'Arena Tubo','escombros'=>'Escombros','bolones'=>'Bolones',
];

$idsRaw = $_REQUEST['ids'] ?? [];
if (is_string($idsRaw)) $idsRaw = array_filter(array_map('trim', explode(',', $idsRaw)));
$ids = array_values(array_filter(array_map('intval', (array)$idsRaw), fn($x) => $x > 0));

$where  = [];
$params = [];

if (!$esAdminDespacho) {
  if ($esReceptor) {
    $where[] = "(creado_por = ? OR (receptor_id = ? AND estado IN ('enviado','validado')))";
    $params[] = $usuario['id']; $params[] = $usuario['id'];
  } else {
    $where[] = "creado_por = ?";
    $params[] = $usuario['id'];
  }
}

if (!empty($ids)) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $where[] = "id IN ($ph)";
    foreach ($ids as $id) $params[] = $id;
} else {
    $fDesde  = trim($_REQUEST['f_desde']  ?? '');
    $fHasta  = trim($_REQUEST['f_hasta']  ?? '');
    $fEstado = trim($_REQUEST['f_estado'] ?? '');
    $fPpu    = trim($_REQUEST['f_ppu']    ?? '');
    $fCond   = trim($_REQUEST['f_cond']   ?? '');
    $fObra   = trim($_REQUEST['f_obra']   ?? '');
    if ($fDesde !== '')  { $where[] = "fecha >= ?"; $params[] = $fDesde; }
    if ($fHasta !== '')  { $where[] = "fecha <= ?"; $params[] = $fHasta; }
    if ($fEstado !== '') { $where[] = "estado = ?"; $params[] = $fEstado; }
    if ($fPpu !== '')    { $where[] = "UPPER(ppu) LIKE ?"; $params[] = '%'.strtoupper($fPpu).'%'; }
    if ($fCond !== '')   { $where[] = "UPPER(conductor_nombre) LIKE ?"; $params[] = '%'.strtoupper($fCond).'%'; }
    if ($fObra !== '')   { $where[] = "UPPER(obra_nombre) LIKE ?"; $params[] = '%'.strtoupper($fObra).'%'; }
}

$sqlWhere = $where ? 'WHERE '.implode(' AND ', $where) : '';
$st = $pdo->prepare("SELECT * FROM tickets_despacho $sqlWhere ORDER BY fecha ASC, hora ASC");
$st->execute($params);
$tickets = $st->fetchAll(PDO::FETCH_ASSOC);

if (empty($tickets)) {
  header('Location: tickets_despacho.php?msg='.urlencode('No hay tickets para exportar con los filtros aplicados.').'&mt=danger');
  exit;
}

$totM3 = 0; $cntBor = 0; $cntEnv = 0; $cntVal = 0;
foreach ($tickets as $t) {
  $totM3 += (float)$t['metros_cubicos'];
  if ($t['estado'] === 'borrador') $cntBor++;
  elseif ($t['estado'] === 'enviado') $cntEnv++;
  elseif ($t['estado'] === 'validado') $cntVal++;
}

$ESTADOS_COLOR = [
  'borrador'=>'#f3f4f6','enviado'=>'#fef3c7','validado'=>'#d1e7dd',
];
$ESTADOS_LABEL = [
  'borrador'=>'Borrador','enviado'=>'Enviado','validado'=>'Validado',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Tickets de Despacho</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;font-size:11px;color:#212529;padding:20px}
.header{background:#1b2838;color:#fff;padding:14px 18px;border-radius:8px;margin-bottom:16px}
.header h1{font-size:16px;margin:0}
.header p{font-size:.85em;opacity:.75;margin:3px 0 0}
.stats{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap}
.stat{background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:10px 16px;flex:1;text-align:center;min-width:90px}
.stat .n{font-size:1.4em;font-weight:900}
.stat .l{font-size:.7em;color:#6c757d;text-transform:uppercase;letter-spacing:.4px}
table{width:100%;border-collapse:collapse;font-size:10px}
th{background:#374151;color:#fff;padding:6px 8px;text-align:left}
td{padding:5px 8px;border-bottom:1px solid #f0f0f0}
.total-row td{background:#fff3cd;font-weight:900}
.footer{text-align:center;color:#9ca3af;font-size:.75em;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6}
.ppu{font-family:monospace;font-weight:900;background:#1b2838;color:#fff;padding:1px 6px;border-radius:4px;letter-spacing:1px}
.filtros-box{background:#f8f9fa;border:1px dashed #d1d5db;border-radius:6px;padding:8px 12px;font-size:.78em;color:#374151;margin-bottom:14px}
@media print{body{padding:10px}button,a.btn-link{display:none}.header,th,.stat,.ppu{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
</head>
<body>
<div style="text-align:right;margin-bottom:10px">
  <button onclick="window.print()" style="background:#dc3545;color:#fff;border:0;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:12px">🖨️ Imprimir / Guardar PDF</button>
  <a href="tickets_despacho.php" class="btn-link" style="background:#6c757d;color:#fff;padding:8px 16px;border-radius:6px;font-size:12px;text-decoration:none;display:inline-block">Volver</a>
</div>
<div class="header">
  <h1>DOGROUP — Tickets de Despacho</h1>
  <p>Generado: <?= date('d/m/Y H:i') ?> &nbsp;·&nbsp; <?= count($tickets) ?> tickets &nbsp;·&nbsp; Por: <?= htmlspecialchars($usuario['nombre']) ?></p>
</div>

<?php
$resumenFiltros = [];
if (!empty($ids)) {
  $resumenFiltros[] = 'Selección manual ('.count($ids).' tickets)';
} else {
  if (!empty($_REQUEST['f_desde']))  $resumenFiltros[] = 'Desde: '.htmlspecialchars($_REQUEST['f_desde']);
  if (!empty($_REQUEST['f_hasta']))  $resumenFiltros[] = 'Hasta: '.htmlspecialchars($_REQUEST['f_hasta']);
  if (!empty($_REQUEST['f_estado'])) $resumenFiltros[] = 'Estado: '.htmlspecialchars($_REQUEST['f_estado']);
  if (!empty($_REQUEST['f_ppu']))    $resumenFiltros[] = 'PPU: '.htmlspecialchars($_REQUEST['f_ppu']);
  if (!empty($_REQUEST['f_cond']))   $resumenFiltros[] = 'Conductor: '.htmlspecialchars($_REQUEST['f_cond']);
  if (!empty($_REQUEST['f_obra']))   $resumenFiltros[] = 'Obra: '.htmlspecialchars($_REQUEST['f_obra']);
}
?>
<?php if (!empty($resumenFiltros)): ?>
<div class="filtros-box"><strong>Filtros:</strong> <?= implode(' · ', $resumenFiltros) ?></div>
<?php endif; ?>

<div class="stats">
  <div class="stat"><div class="n"><?= count($tickets) ?></div><div class="l">Tickets</div></div>
  <div class="stat"><div class="n"><?= $cntBor ?></div><div class="l">Borrador</div></div>
  <div class="stat"><div class="n"><?= $cntEnv ?></div><div class="l">Enviados</div></div>
  <div class="stat"><div class="n"><?= $cntVal ?></div><div class="l">Validados</div></div>
  <div class="stat"><div class="n"><?= number_format($totM3,1,',','.') ?></div><div class="l">m³ total</div></div>
</div>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Fecha</th>
      <th>Hora</th>
      <th>PPU</th>
      <th>Conductor</th>
      <th>Obra</th>
      <th>Destino</th>
      <th>Material</th>
      <th>m³</th>
      <th>Estado</th>
      <th>Validado por</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($tickets as $i => $t):
      $bg = $ESTADOS_COLOR[$t['estado']] ?? ($i%2===0 ? '#f9fafb' : '#ffffff');
      $lbl = $ESTADOS_LABEL[$t['estado']] ?? $t['estado'];
  ?>
  <tr style="background:<?= $bg ?>">
    <td>#<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?></td>
    <td><?= htmlspecialchars($t['fecha']) ?></td>
    <td><?= substr($t['hora'],0,5) ?></td>
    <td><span class="ppu"><?= htmlspecialchars($t['ppu'] ?: '—') ?></span></td>
    <td><?= htmlspecialchars($t['conductor_nombre'] ?: '—') ?></td>
    <td><?= htmlspecialchars($t['obra_nombre'] ?: '—') ?></td>
    <td><?= htmlspecialchars($t['destino'] ?: '—') ?></td>
    <td><?= htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) ?></td>
    <td><?= number_format((float)$t['metros_cubicos'],1,',','.') ?></td>
    <td><?= $lbl ?><?= !empty($t['estado_revision']) ? ' · '.htmlspecialchars($t['estado_revision']) : '' ?></td>
    <td><?= htmlspecialchars($t['validado_por_nombre'] ?: '') ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total-row">
    <td colspan="8"><strong>TOTAL</strong></td>
    <td><strong><?= number_format($totM3,1,',','.') ?></strong></td>
    <td colspan="2"><?= count($tickets) ?> tickets</td>
  </tr>
  </tbody>
</table>

<div class="footer">DOGroup &copy; <?= date('Y') ?> &mdash; Tickets de Despacho generado el <?= date('d/m/Y H:i') ?></div>
<script>setTimeout(function(){ window.print(); }, 300);</script>
</body>
</html>
