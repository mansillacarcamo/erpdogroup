<?php
require_once 'config.php';

if (!isExternoLoggedIn()) { header('Location: despacho_externo_login.php'); exit; }
$ext = getExternoSession();

$MATERIALES = [
  'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4','bajo_3'=>'Bajo 3',
  'bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada','grava'=>'Grava','gravilla'=>'Gravilla',
  'arena'=>'Arena','arena_tubo'=>'Arena Tubo','escombros'=>'Escombros','bolones'=>'Bolones',
];

$fecha = $_REQUEST['fecha'] ?? date('Y-m-d');
$idsRaw = $_REQUEST['ids'] ?? [];
if (is_string($idsRaw)) $idsRaw = array_filter(array_map('trim', explode(',', $idsRaw)));
$ids = array_values(array_filter(array_map('intval', (array)$idsRaw), fn($x) => $x > 0));
$soloValidados = !empty($_REQUEST['solo_validados']);

$baseSql = "
    SELECT td.*, o.codigo AS obra_codigo, o.nombre AS obra_nombre,
           dp.ppu AS ppu_codigo, dd.nombre AS destino_nombre
    FROM tickets_despacho td
    LEFT JOIN obras o              ON o.id  = td.obra_id
    LEFT JOIN despacho_ppu dp      ON dp.id = td.ppu_id
    LEFT JOIN despacho_destinos dd ON dd.id = td.destino_id
";

if (!empty($ids)) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare($baseSql." WHERE td.id IN ($ph) ORDER BY td.fecha ASC, td.hora ASC");
    $st->execute($ids);
} else {
    $stOb = $pdo->prepare("SELECT obra_id FROM despacho_externos WHERE id=?");
    $stOb->execute([$ext['id']]);
    $extObraId = (int)$stOb->fetchColumn();

    $where = "td.fecha = ?";
    $params = [$fecha];
    if ($soloValidados) {
        $where .= " AND td.estado = 'validado'";
    } else {
        $where .= " AND td.estado IN ('enviado','validado')";
    }
    if ($extObraId > 0) {
        $where .= " AND (td.obra_id = ? OR td.revisado_por_ext_id = ?)";
        $params[] = $extObraId;
        $params[] = $ext['id'];
    } else {
        $where .= " AND td.revisado_por_ext_id = ?";
        $params[] = $ext['id'];
    }
    $st = $pdo->prepare($baseSql." WHERE $where ORDER BY td.fecha ASC, td.hora ASC");
    $st->execute($params);
}
$tickets = $st->fetchAll(PDO::FETCH_ASSOC);

if (empty($tickets)) {
    header('Location: despacho_externo_portal.php?tab=revision&msg='.urlencode('No hay tickets para exportar.').'&mt=danger');
    exit;
}

$totalM3 = 0; $totApr = 0; $totRec = 0; $totObs = 0;
foreach ($tickets as $t) {
    $totalM3 += (float)$t['metros_cubicos'];
    if ($t['estado_revision'] === 'aprobado') $totApr++;
    elseif ($t['estado_revision'] === 'rechazado') $totRec++;
    elseif ($t['estado_revision'] === 'observacion') $totObs++;
}

$ESTADOS_COLOR = ['aprobado'=>'#d1e7dd','rechazado'=>'#f8d7da','observacion'=>'#fff3cd'];
$ESTADOS_LABEL = ['aprobado'=>'✅ Aprobado','rechazado'=>'❌ Rechazado','observacion'=>'⚠️ Observación'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte Carchek <?= htmlspecialchars($fecha) ?></title>
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
@media print{body{padding:10px}button{display:none}.header,th,.stat,.ppu{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
</head>
<body>
<div style="text-align:right;margin-bottom:10px">
  <button onclick="window.print()" style="background:#dc3545;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:12px">🖨️ Imprimir / Guardar PDF</button>
  <a href="despacho_externo_portal.php?tab=revision" style="background:#6c757d;color:#fff;border:none;padding:8px 16px;border-radius:6px;font-size:12px;text-decoration:none;display:inline-block">Volver</a>
</div>
<div class="header">
  <h1>DOGROUP — Reporte Carchek</h1>
  <p>
    Validador: <strong><?= htmlspecialchars($ext['nombre']) ?><?= !empty($ext['empresa']) ? ' ('.htmlspecialchars($ext['empresa']).')' : '' ?></strong>
    &nbsp;·&nbsp; Fecha: <strong><?= date('d/m/Y', strtotime($fecha)) ?></strong>
    &nbsp;·&nbsp; Generado: <?= date('d/m/Y H:i') ?>
    &nbsp;·&nbsp; <?= count($tickets) ?> tickets
  </p>
</div>
<div class="stats">
  <div class="stat"><div class="n"><?= $totApr ?></div><div class="l">Aprobados</div></div>
  <div class="stat"><div class="n"><?= $totRec ?></div><div class="l">Rechazados</div></div>
  <div class="stat"><div class="n"><?= $totObs ?></div><div class="l">Con obs.</div></div>
  <div class="stat"><div class="n"><?= number_format($totalM3,1,',','.') ?></div><div class="l">m³ total</div></div>
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
      <th>Observación</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($tickets as $i => $t):
      $bg = $ESTADOS_COLOR[$t['estado_revision']] ?? ($i%2===0 ? '#f9fafb' : '#ffffff');
      $lbl = $ESTADOS_LABEL[$t['estado_revision']] ?? ($t['estado']==='validado' ? 'Validado' : 'Pendiente');
  ?>
  <tr style="background:<?= $bg ?>">
    <td>#<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?></td>
    <td><?= htmlspecialchars($t['fecha']) ?></td>
    <td><?= substr($t['hora'],0,5) ?></td>
    <td><span class="ppu"><?= htmlspecialchars($t['ppu_codigo'] ?: $t['ppu'] ?: '—') ?></span></td>
    <td><?= htmlspecialchars($t['conductor_nombre'] ?: '—') ?></td>
    <td><?= htmlspecialchars($t['obra_codigo'] ? $t['obra_codigo'].' — '.$t['obra_nombre'] : ($t['obra_nombre'] ?: '—')) ?></td>
    <td><?= htmlspecialchars($t['destino_nombre'] ?: $t['destino'] ?: '—') ?></td>
    <td><?= htmlspecialchars($MATERIALES[$t['tipo_material']] ?? $t['tipo_material']) ?></td>
    <td><?= number_format((float)$t['metros_cubicos'],1,',','.') ?></td>
    <td><?= $lbl ?></td>
    <td><?= htmlspecialchars($t['observacion_revision'] ?: '') ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="total-row">
    <td colspan="8"><strong>TOTAL</strong></td>
    <td><strong><?= number_format($totalM3,1,',','.') ?></strong></td>
    <td colspan="2"><?= count($tickets) ?> tickets</td>
  </tr>
  </tbody>
</table>
<div class="footer">DOGroup &copy; <?= date('Y') ?> &mdash; Reporte Carchek generado el <?= date('d/m/Y H:i') ?></div>
<script>setTimeout(function(){ window.print(); }, 300);</script>
</body>
</html>
