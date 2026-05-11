<?php
require_once 'config.php';
requireAuth();
$rol = $_SESSION['user_rol'] ?? '';

$uid  = (int)($_GET['u'] ?? $_SESSION['user_id']);
$p = periodoActual();
$anio = (int)($_GET['anio'] ?? $p['anio']);
$mes  = (int)($_GET['mes']  ?? $p['mes']);

if ($uid != $_SESSION['user_id'] && !in_array($rol,['admin','jefe','validador'])) {
    http_response_code(403); die('Sin permiso.');
}

$u = $pdo->prepare("SELECT * FROM usuarios WHERE id=?"); $u->execute([$uid]); $u = $u->fetch();
if (!$u) die('Usuario no encontrado.');

$ini = sprintf('%04d-%02d-01',$anio,$mes); $fin = date('Y-m-t', strtotime($ini));
$gs = $pdo->prepare("SELECT * FROM gastos WHERE usuario_id=? AND fecha BETWEEN ? AND ? ORDER BY fecha");
$gs->execute([$uid,$ini,$fin]);
$gastos = $gs->fetchAll();
$a = asignacionPeriodo($pdo,$uid,$anio,$mes);
$tot = array_sum(array_map(fn($g)=>(float)$g['monto'], $gastos));

$cc = $pdo->prepare("SELECT * FROM cierres_mensuales WHERE usuario_id=? AND anio=? AND mes=?");
$cc->execute([$uid,$anio,$mes]);
$cierreDoc = $cc->fetch();

$estadoColor = [
  'borrador' => ['#e5e7eb', '#475569', 'Borrador'],
  'enviado_jefe' => ['#dbeafe', '#1d4ed8', 'Enviado al Jefe'],
  'enviado_validador' => ['#e0e7ff', '#4338ca', 'Enviado al Validador'],
  'aprobado' => ['#dcfce7', '#166534', 'Aprobado'],
  'validado' => ['#dcfce7', '#166534', 'Validado'],
  'rechazado' => ['#fee2e2', '#b91c1c', 'Rechazado'],
];

// HTML imprimible (el navegador exporta a PDF con Ctrl+P)
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<title>Cierre <?= htmlspecialchars($u['nombre']) ?> · <?= nombreMes($mes) ?> <?= $anio ?></title>
<style>
  *{ box-sizing:border-box; }
  body{ font-family: 'Segoe UI', Arial, sans-serif; font-size:12px; color:#1f2937; padding:24px; }
  h1{ margin:0 0 4px; font-size:22px; color:#0d6efd; }
  .meta{ color:#64748b; font-size:11px; margin-bottom:18px; }
  .box{ border:1px solid #e2e8f0; border-radius:8px; padding:12px; margin-bottom:14px; }
  .row{ display:flex; gap:14px; flex-wrap:wrap; }
  .col{ flex:1; min-width:140px; }
  .lbl{ color:#64748b; font-size:10px; text-transform:uppercase; letter-spacing:.5px; }
  .val{ font-weight:700; font-size:15px; color:#0f172a; }
  table{ width:100%; border-collapse:collapse; margin-top:8px; }
  th,td{ padding:6px 8px; border-bottom:1px solid #eef2f7; text-align:left; font-size:11px; }
  th{ background:#f1f5f9; font-weight:600; color:#475569; text-transform:uppercase; font-size:10px; letter-spacing:.4px; }
  .right{ text-align:right; }
  .total{ font-weight:700; font-size:13px; color:#dc2626; }
  .firma{ margin-top:60px; display:flex; justify-content:space-around; }
  .firma div{ width:30%; text-align:center; border-top:1px solid #94a3b8; padding-top:6px; font-size:11px; color:#475569; }
  .btn-print{ position:fixed; top:14px; right:14px; padding:10px 16px; background:#0d6efd; color:#fff; border:0; border-radius:8px; cursor:pointer; font-weight:600; }
  @media print { .btn-print{ display:none; } body{ padding:10px; } }
</style></head><body>
<button class="btn-print" onclick="window.print()">🖨 Imprimir / Guardar PDF</button>

<h1>Rendicion de Gastos</h1>
<?php
// Foto carnet del tecnico para el PDF (ruta absoluta para impresion)
$fotoArchivoPdf = $u['foto_perfil'] ?? '';
$fotoPathPdf = $fotoArchivoPdf ? (FOTO_USUARIOS_DIR . DIRECTORY_SEPARATOR . $fotoArchivoPdf) : '';
$fotoDataUri = '';
if ($fotoPathPdf && is_file($fotoPathPdf)) {
    $mimeF = @getimagesize($fotoPathPdf)['mime'] ?? '';
    if ($mimeF) {
        $fotoDataUri = 'data:'.$mimeF.';base64,'.base64_encode(file_get_contents($fotoPathPdf));
    }
}
?>
<table style="width:100%; border-collapse:collapse; margin-bottom:14px;">
  <tr>
    <td class="meta" style="vertical-align:top; padding:10px 12px;">
      <div style="font-size:14px; color:#0f172a; margin-bottom:4px;">
        <strong>Tecnico:</strong> <?= htmlspecialchars($u['nombre']) ?>
        <?php if ($u['usuario']): ?> (@<?= htmlspecialchars($u['usuario']) ?>)<?php endif; ?>
      </div>
      <?php if ($u['cargo']): ?><div><strong>Cargo:</strong> <?= htmlspecialchars($u['cargo']) ?></div><?php endif; ?>
      <?php if (!empty($u['rut'])): ?><div><strong>RUT:</strong> <?= htmlspecialchars($u['rut']) ?></div><?php endif; ?>
      <div>
        <strong>Ubicacion:</strong>
        <?= htmlspecialchars($u['region'] ?: '-') ?>
        <?php if ($u['ciudad']): ?> - <?= htmlspecialchars($u['ciudad']) ?><?php endif; ?>
        <?php if ($u['zona']): ?> (<?= htmlspecialchars($u['zona']) ?>)<?php endif; ?>
      </div>
      <div><strong>Email:</strong> <?= htmlspecialchars($u['email']) ?><?php if ($u['telefono']): ?> - <strong>Tel:</strong> <?= htmlspecialchars($u['telefono']) ?><?php endif; ?></div>
      <div style="margin-top:6px;"><strong>Periodo:</strong> <?= nombreMes($mes) ?> <?= $anio ?> - <strong>Generado:</strong> <?= date('d/m/Y H:i') ?></div>
    </td>
    <td style="width:120px; vertical-align:top; text-align:right; padding-left:10px;">
      <?php if ($fotoDataUri): ?>
        <img src="<?= $fotoDataUri ?>" alt="Foto"
             style="width:110px; height:140px; object-fit:cover; border:2px solid #cbd5e1; border-radius:6px;">
      <?php else: ?>
        <div style="width:110px; height:140px; border:2px dashed #cbd5e1; border-radius:6px; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:11px; text-align:center;">
          Sin foto<br>tamaño carnet
        </div>
      <?php endif; ?>
    </td>
  </tr>
</table>

<?php if ($cierreDoc):
  $est = $cierreDoc['estado'];
  $col = $estadoColor[$est] ?? ['#e5e7eb', '#475569', ucfirst($est)];
?>
<div class="box" style="border-left:4px solid <?= $col[1] ?>; background:<?= $col[0] ?>33;">
  <div style="font-size:11px; color:#475569; margin-bottom:4px;"><strong>ESTADO DE LA RENDICION</strong></div>
  <div style="display:inline-block; padding:3px 10px; border-radius:12px; background:<?= $col[0] ?>; color:<?= $col[1] ?>; font-weight:700; font-size:12px;">
    <?= htmlspecialchars($col[2]) ?>
  </div>
  <?php if ($cierreDoc['enviado_jefe_en']): ?>
    <span style="font-size:11px; color:#475569; margin-left:10px;">
      - Enviado al Jefe: <strong><?= htmlspecialchars(date('d/m/Y H:i', strtotime($cierreDoc['enviado_jefe_en']))) ?></strong>
    </span>
  <?php endif; ?>
  <?php if (!empty($cierreDoc['observaciones_usuario'])): ?>
    <div style="margin-top:6px; font-size:11px;"><strong>Observaciones del tecnico:</strong> <?= nl2br(htmlspecialchars($cierreDoc['observaciones_usuario'])) ?></div>
  <?php endif; ?>
  <?php if (!empty($cierreDoc['observaciones_jefe'])): ?>
    <div style="margin-top:4px; font-size:11px;"><strong>Comentario del Jefe:</strong> <?= nl2br(htmlspecialchars($cierreDoc['observaciones_jefe'])) ?></div>
  <?php endif; ?>
  <?php if (!empty($cierreDoc['observaciones_validador'])): ?>
    <div style="margin-top:4px; font-size:11px;"><strong>Comentario del Validador:</strong> <?= nl2br(htmlspecialchars($cierreDoc['observaciones_validador'])) ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="box">
  <div class="row">
    <div class="col"><div class="lbl">Asignado</div><div class="val"><?= fmtCLP($a['monto_asignado']??0) ?></div></div>
    <div class="col"><div class="lbl">Arrastre</div><div class="val"><?= fmtCLP($a['monto_carryover']??0) ?></div></div>
    <div class="col"><div class="lbl">Total disponible</div><div class="val"><?= fmtCLP($a['total']??0) ?></div></div>
    <div class="col"><div class="lbl">Total gastado</div><div class="val" style="color:#dc2626"><?= fmtCLP($tot) ?></div></div>
    <div class="col"><div class="lbl">Saldo</div><div class="val" style="color:<?= (($a['total']??0)-$tot)<0?'#dc2626':'#16a34a' ?>"><?= fmtCLP(($a['total']??0)-$tot) ?></div></div>
  </div>
</div>

<table>
<thead><tr>
  <th>Fecha</th><th>Categoría</th><th>Proveedor</th><th>Detalle</th><th>Doc</th><th>N°</th><th class="right">Monto</th>
</tr></thead>
<tbody>
<?php foreach ($gastos as $g): ?>
  <tr>
    <td><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
    <td><?= htmlspecialchars($g['categoria']?:'—') ?></td>
    <td><?= htmlspecialchars($g['proveedor']?:'—') ?></td>
    <td><?= htmlspecialchars($g['descripcion']?:'—') ?></td>
    <td><?= htmlspecialchars($g['tipo_documento']?:'—') ?></td>
    <td><?= htmlspecialchars($g['numero_documento']?:'—') ?></td>
    <td class="right"><?= fmtCLP($g['monto']) ?></td>
  </tr>
<?php endforeach; ?>
<?php if (!$gastos): ?><tr><td colspan="7" style="text-align:center; padding:24px; color:#94a3b8;">Sin gastos registrados.</td></tr><?php endif; ?>
</tbody>
<tfoot><tr><td colspan="6" class="right total">TOTAL</td><td class="right total"><?= fmtCLP($tot) ?></td></tr></tfoot>
</table>

<div class="firma">
  <div>Tecnico<br><strong><?= htmlspecialchars($u['nombre']) ?></strong><br><span style="font-size:10px; color:#64748b;"><?= htmlspecialchars($u['ciudad'] ?: $u['region'] ?: '') ?></span></div>
  <div>Jefe Zonal</div>
  <div>Validador</div>
</div>

<script>
window.addEventListener('load', () => setTimeout(()=>window.print(), 600));
</script>
</body></html>
