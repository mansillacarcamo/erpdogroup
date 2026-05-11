<?php
require_once 'config.php';
requireAuth();

$esAdmin = ($usuario['rol'] === 'admin') || isDespachoAdmin($usuario, $pdo);
$mes     = $_GET['mes']     ?? date('Y-m');
$obraId  = (int)($_GET['obra_id'] ?? 0);
$est     = $_GET['estado']  ?? '';

$where  = "WHERE strftime('%Y-%m', fecha) = ?";
$params = [$mes];
if (!$esAdmin) { $where .= " AND registrado_por=?"; $params[] = $usuario['id']; }
if ($obraId)   { $where .= " AND obra_id=?";        $params[] = $obraId; }
if ($est)      { $where .= " AND estado=?";          $params[] = $est; }

$st = $pdo->prepare("SELECT * FROM gastos_faena $where ORDER BY fecha ASC, creado_en ASC");
$st->execute($params);
$gastos = $st->fetchAll(PDO::FETCH_ASSOC);

$totalMonto = array_sum(array_column($gastos,'monto'));
$totalPeaje = array_sum(array_column($gastos,'monto_peaje'));
$totalGral  = $totalMonto + $totalPeaje;

$obraLabel = '';
if ($obraId) {
    $stO = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id=?");
    $stO->execute([$obraId]); $oRow = $stO->fetch(PDO::FETCH_ASSOC);
    $obraLabel = $oRow ? $oRow['codigo'].' — '.$oRow['nombre'] : '';
}

$COLORES = ['aprobado'=>'#d1e7dd','rechazado'=>'#f8d7da','pendiente'=>'#fff3cd'];
$LABELS  = ['aprobado'=>'✅ Aprobado','rechazado'=>'❌ Rechazado','pendiente'=>'⏳ Pendiente'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Gastos por Obra <?= $mes ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;font-size:11px;color:#212529;padding:20px}
.header{background:#1b2838;color:#fff;padding:14px 18px;border-radius:8px;margin-bottom:14px}
.header h1{font-size:15px;margin:0}.header p{font-size:.82em;opacity:.7;margin:3px 0 0}
.stats{display:flex;gap:10px;margin-bottom:14px}
.stat{background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:8px 14px;flex:1;text-align:center}
.stat .n{font-size:1.3em;font-weight:900}.stat .l{font-size:.68em;color:#6c757d;text-transform:uppercase}
table{width:100%;border-collapse:collapse;font-size:10px;margin-bottom:14px}
th{background:#1b2838;color:#fff;padding:6px 7px;text-align:left}
td{padding:5px 7px;border-bottom:1px solid #f0f0f0}
.tot td{background:#fff3cd;font-weight:900}
.footer{text-align:center;color:#9ca3af;font-size:.72em;margin-top:16px;padding-top:10px;border-top:1px solid #dee2e6}
@media print{body{padding:10px}button{display:none}.header,th{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
</head>
<body>
<div style="text-align:right;margin-bottom:10px">
  <button onclick="window.print()" style="background:#dc3545;color:#fff;border:none;padding:7px 14px;border-radius:6px;cursor:pointer;font-size:11px">🖨️ Imprimir / PDF</button>
</div>
<div class="header">
  <h1>Gastos por Obra — <?= $mes ?> · DOGroup</h1>
  <p><?= $obraLabel ?: 'Todas las obras' ?> &nbsp;·&nbsp; Generado el <?= date('d/m/Y H:i') ?> por <?= htmlspecialchars($usuario['nombre']) ?></p>
</div>
<div class="stats">
  <div class="stat"><div class="n">$<?= number_format($totalMonto,0,',','.') ?></div><div class="l">Gastos</div></div>
  <div class="stat"><div class="n">$<?= number_format($totalPeaje,0,',','.') ?></div><div class="l">Peajes</div></div>
  <div class="stat"><div class="n">$<?= number_format($totalGral,0,',','.') ?></div><div class="l">Total</div></div>
  <div class="stat"><div class="n"><?= count($gastos) ?></div><div class="l">Registros</div></div>
</div>
<table>
  <thead>
    <tr>
      <th>#</th><th>Fecha</th><th>Obra</th><th>Categoría</th><th>Descripción</th>
      <th>Proveedor</th><th>Tipo</th><th>Monto</th><th>Peaje</th><th>Total</th><th>Estado</th><th>Por</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach($gastos as $i=>$g):
    $bg  = $COLORES[$g['estado']] ?? '#fff';
    $tot = (float)$g['monto'] + (float)$g['monto_peaje'];
  ?>
  <tr style="background:<?= $bg ?>">
    <td><?= $i+1 ?></td>
    <td><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
    <td><?= htmlspecialchars($g['obra_nombre'] ?: '—') ?></td>
    <td><?= htmlspecialchars($g['categoria_nombre'] ?: '—') ?></td>
    <td><?= htmlspecialchars($g['descripcion']) ?></td>
    <td><?= htmlspecialchars($g['proveedor'] ?: '—') ?></td>
    <td><?= htmlspecialchars($g['tipo_documento'] ?: '—') ?></td>
    <td>$<?= number_format((float)$g['monto'],0,',','.') ?></td>
    <td><?= (float)$g['monto_peaje']>0?'$'.number_format((float)$g['monto_peaje'],0,',','.'):'—' ?></td>
    <td><strong>$<?= number_format($tot,0,',','.') ?></strong></td>
    <td><?= $LABELS[$g['estado']] ?? $g['estado'] ?></td>
    <td><?= htmlspecialchars($g['registrado_por_nombre']) ?></td>
  </tr>
  <?php endforeach; ?>
  <tr class="tot">
    <td colspan="7"><strong>TOTAL</strong></td>
    <td><strong>$<?= number_format($totalMonto,0,',','.') ?></strong></td>
    <td><strong>$<?= number_format($totalPeaje,0,',','.') ?></strong></td>
    <td><strong>$<?= number_format($totalGral,0,',','.') ?></strong></td>
    <td colspan="2"><?= count($gastos) ?> registros</td>
  </tr>
  </tbody>
</table>
<div class="footer">DOGroup &copy; <?= date('Y') ?> · Departamento de Informática DOGroup · Reporte generado el <?= date('d/m/Y H:i') ?></div>
<script>window.print();</script>
</body>
</html>
