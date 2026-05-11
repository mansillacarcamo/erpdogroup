<?php
require_once 'config.php';
requireRol('admin');

$p = periodoActual();
$anio = (int)($_GET['anio'] ?? $p['anio']);
$mes  = (int)($_GET['mes']  ?? $p['mes']);
$uid  = isset($_GET['u']) ? (int)$_GET['u'] : 0;

$ini = sprintf('%04d-%02d-01', $anio, $mes);
$fin = date('Y-m-t', strtotime($ini));

// Usuarios (todos los activos rol=usuario, o uno solo si se pidio)
$paramsU = [];
$sqlU = "SELECT u.id, u.nombre, u.usuario, u.email, u.rut, u.cargo, u.zona, u.ciudad, u.region
         FROM usuarios u WHERE u.rol='usuario' AND u.activo=1";
if ($uid > 0) { $sqlU .= " AND u.id = ?"; $paramsU[] = $uid; }
$sqlU .= " ORDER BY u.region, u.ciudad, u.nombre";
$qu = $pdo->prepare($sqlU);
$qu->execute($paramsU);
$usuarios = $qu->fetchAll();

// Gastos del periodo agrupados por usuario
$gxu = [];
if ($uid > 0) {
    $qg = $pdo->prepare("SELECT * FROM gastos WHERE usuario_id=? AND fecha BETWEEN ? AND ? ORDER BY fecha, id");
    $qg->execute([$uid, $ini, $fin]);
} else {
    $qg = $pdo->prepare("SELECT * FROM gastos WHERE fecha BETWEEN ? AND ? ORDER BY usuario_id, fecha, id");
    $qg->execute([$ini, $fin]);
}
foreach ($qg->fetchAll() as $g) $gxu[$g['usuario_id']][] = $g;

// Asignaciones del periodo
$asig = [];
$qa = $pdo->prepare("SELECT * FROM asignaciones WHERE anio=? AND mes=?");
$qa->execute([$anio, $mes]);
foreach ($qa->fetchAll() as $a) $asig[$a['usuario_id']] = $a;

// Nombre de archivo
$periodoStr = sprintf('%04d-%02d', $anio, $mes);
$fname = $uid > 0 ? "gastos_u{$uid}_{$periodoStr}.csv" : "gastos_{$periodoStr}.csv";

// Salida CSV con BOM UTF-8 y separador ; (Excel chileno lo interpreta correctamente)
while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8

$sep = ';';

function w($out, array $cols, $sep = ';') {
    foreach ($cols as &$c) {
        $c = (string)$c;
        if (strpos($c, $sep) !== false || strpos($c, '"') !== false || strpos($c, "\n") !== false) {
            $c = '"' . str_replace('"', '""', $c) . '"';
        }
    }
    fwrite($out, implode($sep, $cols) . "\r\n");
}

// Cabecera del reporte
w($out, ['Reporte de gastos - ' . nombreMes($mes) . ' ' . $anio], $sep);
w($out, ['Generado: ' . date('d/m/Y H:i')], $sep);
w($out, [''], $sep);

// === RESUMEN POR USUARIO ===
w($out, ['RESUMEN POR USUARIO'], $sep);
w($out, ['Nombre','Usuario','RUT','Cargo','Region','Ciudad','Asignado','Arrastre','Total','Gastado','Saldo','% Usado','N gastos'], $sep);

$totalAsignado = 0; $totalGastado = 0; $totalGastosCount = 0;
foreach ($usuarios as $u) {
    $a = $asig[$u['id']] ?? null;
    $monAsig = $a ? (float)$a['monto_asignado'] : 0;
    $monCarry = $a ? (float)$a['monto_carryover'] : 0;
    $totalAsig = $monAsig + $monCarry;
    $gastosU = $gxu[$u['id']] ?? [];
    $sum = array_sum(array_map(fn($g)=>(float)$g['monto'], $gastosU));
    $saldo = $totalAsig - $sum;
    $pct = $totalAsig > 0 ? round($sum * 100 / $totalAsig, 1) : 0;
    w($out, [
        $u['nombre'], $u['usuario'], $u['rut'], $u['cargo'],
        $u['region'], $u['ciudad'],
        round($monAsig), round($monCarry), round($totalAsig),
        round($sum), round($saldo), $pct, count($gastosU)
    ], $sep);
    $totalAsignado += $totalAsig;
    $totalGastado += $sum;
    $totalGastosCount += count($gastosU);
}

w($out, [''], $sep);
w($out, ['TOTAL', '', '', '', '', '', '', '', round($totalAsignado), round($totalGastado),
        round($totalAsignado - $totalGastado),
        $totalAsignado>0 ? round($totalGastado*100/$totalAsignado,1) : 0,
        $totalGastosCount], $sep);

// === DETALLE DE GASTOS ===
w($out, [''], $sep);
w($out, [''], $sep);
w($out, ['DETALLE DE GASTOS'], $sep);
w($out, ['Usuario','Nombre','Fecha','Categoria','Proveedor','N Documento','Tipo Doc','Descripcion','Monto','Estado'], $sep);

foreach ($usuarios as $u) {
    $gastosU = $gxu[$u['id']] ?? [];
    foreach ($gastosU as $g) {
        w($out, [
            $u['usuario'], $u['nombre'],
            date('d/m/Y', strtotime($g['fecha'])),
            $g['categoria'] ?: '',
            $g['proveedor'] ?: '',
            $g['numero_documento'] ?: '',
            $g['tipo_documento'] ?: '',
            $g['descripcion'] ?: '',
            round((float)$g['monto']),
            $g['estado'] ?: ''
        ], $sep);
    }
}

fclose($out);
exit;
