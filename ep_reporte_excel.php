<?php
/**
 * Reporte Excel — Estados de Pago
 * Filtros: tipo, mes, semana, estado
 * Genera un .xlsx con PhpSpreadsheet
 */
require_once 'config.php';
requireAuth();

require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$tipo    = $_GET['tipo']   ?? 'todos';
$periodo = $_GET['periodo'] ?? 'mes';      // mes | semana | todos
$mes     = $_GET['mes']    ?? date('Y-m');
$semana  = (int)($_GET['semana'] ?? date('W'));
$anioSem = (int)($_GET['anio']   ?? date('Y'));
$estado  = $_GET['estado']  ?? '';

$tiposValidos = [
    'cobro_empresa'  => 'Ventas',
    'pago_proveedor' => 'Pago Proveedores',
];

// Construir WHERE
$where  = ['1=1']; $params = [];

if ($tipo !== 'todos' && isset($tiposValidos[$tipo])) {
    $where[] = 'tipo = ?'; $params[] = $tipo;
}
if ($estado) { $where[] = 'estado = ?'; $params[] = $estado; }

if ($periodo === 'mes') {
    $where[] = "strftime('%Y-%m', fecha) = ?"; $params[] = $mes;
} elseif ($periodo === 'semana') {
    $where[] = "strftime('%W', fecha) = ? AND strftime('%Y', fecha) = ?";
    $params[] = str_pad($semana, 2, '0', STR_PAD_LEFT);
    $params[] = $anioSem;
}

$st = $pdo->prepare("SELECT * FROM estados_pago WHERE ".implode(' AND ',$where)." ORDER BY fecha DESC, tipo ASC, id DESC");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Título del reporte
$tituloFiltro = match($periodo) {
    'mes'    => 'Mes '.date('M Y', strtotime($mes.'-01')),
    'semana' => 'Semana '.$semana.' / '.$anioSem,
    default  => 'Todo el período'
};
$tituloTipo = $tipo === 'todos' ? 'Todos los tipos' : ($tiposValidos[$tipo] ?? $tipo);

// Agrupar por tipo para resumen
$porTipo = [];
foreach ($rows as $r) {
    $t = $tiposValidos[$r['tipo']] ?? $r['tipo'];
    if (!isset($porTipo[$t])) $porTipo[$t] = ['count'=>0,'neto'=>0,'iva'=>0,'total'=>0];
    $porTipo[$t]['count']++;
    $porTipo[$t]['neto']  += (float)$r['monto_neto'];
    $porTipo[$t]['iva']   += (float)$r['iva'];
    $porTipo[$t]['total'] += (float)$r['monto_total'];
}

$totalNeto  = array_sum(array_column($rows,'monto_neto'));
$totalIva   = array_sum(array_column($rows,'iva'));
$totalTotal = array_sum(array_column($rows,'monto_total'));

// ── Crear Excel ──
$spread = new Spreadsheet();
$spread->getProperties()
    ->setTitle("Estados de Pago")
    ->setCreator("DOGroup")
    ->setDescription("Reporte $tituloFiltro - $tituloTipo");

$COLORES = [
    'header'   => '1B2838',
    'subheader'=> '374151',
    'ventas'   => 'D1FAE5',
    'pagos'    => 'FEE2E2',
    'total'    => 'FFF3CD',
    'alt'      => 'F9FAFB',
];

// ══ HOJA 1: DETALLE ══
$sh = $spread->getActiveSheet()->setTitle('Detalle');
$cols = range('A', 'O');

// Título principal
$sh->mergeCells('A1:O1');
$sh->setCellValue('A1', 'DOGROUP — Reporte Estados de Pago');
$sh->getStyle('A1')->applyFromArray([
    'font' => ['bold'=>true,'size'=>14,'color'=>['rgb'=>'FFFFFF']],
    'fill' => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$COLORES['header']]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
]);
$sh->getRowDimension(1)->setRowHeight(28);

// Subtítulo
$sh->mergeCells('A2:O2');
$sh->setCellValue('A2', "$tituloFiltro · $tituloTipo · Generado el ".date('d/m/Y H:i')." por ".$usuario['nombre']);
$sh->getStyle('A2')->applyFromArray([
    'font' => ['size'=>10,'color'=>['rgb'=>'FFFFFF'],'italic'=>true],
    'fill' => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$COLORES['subheader']]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
]);
$sh->getRowDimension(2)->setRowHeight(18);

// Encabezados
$headers = ['#','Tipo','Fecha','Empresa/Proveedor','RUT','N° Documento','Descripción','Obra','Neto','IVA','Total','Vencimiento','Fecha Pago','Estado','Forma Pago'];
foreach ($headers as $i => $h) {
    $cell = $cols[$i].'4';
    $sh->setCellValue($cell, $h);
    $sh->getStyle($cell)->applyFromArray([
        'font'      => ['bold'=>true,'color'=>['rgb'=>'FFFFFF'],'size'=>10],
        'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$COLORES['header']]],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
        'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'4B5563']]],
    ]);
}
$sh->getRowDimension(4)->setRowHeight(22);

// Datos
$row = 5;
foreach ($rows as $i => $r) {
    $esVenta = $r['tipo'] === 'cobro_empresa';
    $bg = $i % 2 === 0 ? ($esVenta ? $COLORES['ventas'] : $COLORES['pagos']) : $COLORES['alt'];

    $fmtEstado = ['pendiente'=>'⏳ Pendiente','pagado'=>'✅ Pagado','vencido'=>'🔴 Vencido','parcial'=>'⚠️ Parcial'][$r['estado']] ?? $r['estado'];

    $data = [
        $i+1,
        $tiposValidos[$r['tipo']] ?? $r['tipo'],
        $r['fecha'],
        $r['contraparte'],
        $r['contraparte_rut'],
        $r['numero_documento'],
        $r['descripcion'],
        $r['obra_nombre'],
        (float)$r['monto_neto'],
        (float)$r['iva'],
        (float)$r['monto_total'],
        $r['fecha_vencimiento'],
        $r['fecha_pago'],
        $fmtEstado,
        $r['forma_pago'],
    ];

    foreach ($data as $j => $val) {
        $cell = $cols[$j].$row;
        $sh->setCellValue($cell, $val);
        $sh->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bg);
        $sh->getStyle($cell)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');
        // Formato moneda para columnas neto/iva/total
        if (in_array($j, [8,9,10])) {
            $sh->getStyle($cell)->getNumberFormat()->setFormatCode('$#,##0');
            $sh->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
    }
    $row++;
}

// Fila totales
$totalData = ['','','','','','','TOTALES','', $totalNeto, $totalIva, $totalTotal, '', '', count($rows).' registros', ''];
foreach ($totalData as $j => $val) {
    $cell = $cols[$j].$row;
    $sh->setCellValue($cell, $val ?: '');
    $sh->getStyle($cell)->applyFromArray([
        'font' => ['bold'=>true,'size'=>10],
        'fill' => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$COLORES['total']]],
        'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_MEDIUM,'color'=>['rgb'=>'92400E']]],
    ]);
    if (in_array($j,[8,9,10])) {
        $sh->getStyle($cell)->getNumberFormat()->setFormatCode('$#,##0');
        $sh->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}

// Anchos de columna
$anchos = [5,18,12,28,14,16,32,22,14,12,14,12,12,14,14];
foreach ($anchos as $i => $w) $sh->getColumnDimension($cols[$i])->setWidth($w);

// Freeze panes
$sh->freezePane('A5');

// ══ HOJA 2: RESUMEN POR TIPO ══
$sh2 = $spread->createSheet()->setTitle('Resumen');
$sh2->mergeCells('A1:F1');
$sh2->setCellValue('A1', 'RESUMEN — '.$tituloFiltro);
$sh2->getStyle('A1')->applyFromArray(['font'=>['bold'=>true,'size'=>13,'color'=>['rgb'=>'FFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$COLORES['header']]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);

$hdrs2 = ['Tipo','Registros','Neto','IVA','Total','% del Total'];
foreach ($hdrs2 as $i => $h) {
    $c2 = range('A','F')[$i];
    $sh2->setCellValue($c2.'3', $h);
    $sh2->getStyle($c2.'3')->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$COLORES['subheader']]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
}
$r2 = 4;
foreach ($porTipo as $tnombre => $td) {
    $pct = $totalTotal > 0 ? round($td['total']/$totalTotal*100,1) : 0;
    $row2data = [$tnombre, $td['count'], $td['neto'], $td['iva'], $td['total'], $pct.'%'];
    foreach ($row2data as $j => $v) {
        $cx = range('A','F')[$j];
        $sh2->setCellValue($cx.$r2, $v);
        if (in_array($j,[2,3,4])) $sh2->getStyle($cx.$r2)->getNumberFormat()->setFormatCode('$#,##0');
    }
    $r2++;
}
// Total resumen
$sh2->setCellValue('A'.$r2,'TOTAL'); $sh2->setCellValue('B'.$r2,count($rows));
$sh2->setCellValue('C'.$r2,$totalNeto); $sh2->setCellValue('D'.$r2,$totalIva);
$sh2->setCellValue('E'.$r2,$totalTotal); $sh2->setCellValue('F'.$r2,'100%');
$sh2->getStyle('A'.$r2.':F'.$r2)->getFont()->setBold(true);
$sh2->getStyle('A'.$r2.':F'.$r2)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($COLORES['total']);
foreach (['C','D','E'] as $cx) $sh2->getStyle($cx.$r2)->getNumberFormat()->setFormatCode('$#,##0');
foreach (['A'=>30,'B'=>12,'C'=>16,'D'=>14,'E'=>16,'F'=>12] as $col=>$w) $sh2->getColumnDimension($col)->setWidth($w);

// ══ HOJA 3: POR SEMANA (si el filtro es mensual) ══
if ($periodo === 'mes' || $periodo === 'todos') {
    $sh3 = $spread->createSheet()->setTitle('Por Semana');
    $sh3->mergeCells('A1:E1');
    $sh3->setCellValue('A1','Distribución Semanal — '.$tituloFiltro);
    $sh3->getStyle('A1')->applyFromArray(['font'=>['bold'=>true,'size'=>12,'color'=>['rgb'=>'FFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$COLORES['header']]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);

    // Agrupar por semana
    $porSemana = [];
    foreach ($rows as $r) {
        $sem = 'Sem '.date('W', strtotime($r['fecha'])).' ('.date('d/m', strtotime('monday this week', strtotime($r['fecha']))).' - '.date('d/m', strtotime('sunday this week', strtotime($r['fecha']))).')';
        if (!isset($porSemana[$sem])) $porSemana[$sem] = ['count'=>0,'total'=>0,'ventas'=>0,'pagos'=>0];
        $porSemana[$sem]['count']++;
        $porSemana[$sem]['total'] += (float)$r['monto_total'];
        if ($r['tipo'] === 'cobro_empresa')  $porSemana[$sem]['ventas'] += (float)$r['monto_total'];
        if ($r['tipo'] === 'pago_proveedor') $porSemana[$sem]['pagos']  += (float)$r['monto_total'];
    }

    $sh3->setCellValue('A3','Semana'); $sh3->setCellValue('B3','Registros');
    $sh3->setCellValue('C3','Ventas'); $sh3->setCellValue('D3','Pago Proveedores');
    $sh3->setCellValue('E3','Total');
    $sh3->getStyle('A3:E3')->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$COLORES['subheader']]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);

    $r3 = 4;
    foreach ($porSemana as $sem => $sd) {
        $sh3->setCellValue('A'.$r3, $sem);
        $sh3->setCellValue('B'.$r3, $sd['count']);
        $sh3->setCellValue('C'.$r3, $sd['ventas']);
        $sh3->setCellValue('D'.$r3, $sd['pagos']);
        $sh3->setCellValue('E'.$r3, $sd['total']);
        foreach (['C','D','E'] as $cx) $sh3->getStyle($cx.$r3)->getNumberFormat()->setFormatCode('$#,##0');
        $r3++;
    }
    foreach (['A'=>34,'B'=>12,'C'=>18,'D'=>20,'E'=>18] as $col=>$w) $sh3->getColumnDimension($col)->setWidth($w);
}

$spread->setActiveSheetIndex(0);

// Descargar
$filename = 'ep_'.($tipo==='todos'?'todos':$tipo).'_'.$periodo.'_'.date('Ymd_His').'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');
(new Xlsx($spread))->save('php://output');
exit;
