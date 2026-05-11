<?php
require_once 'config.php';
requireAuth();

$phpSpreadsheetDisponible = false;
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (is_file($autoloadPath) && is_readable($autoloadPath)) {
    try {
        require_once $autoloadPath;
        if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            $phpSpreadsheetDisponible = true;
        }
    } catch (Throwable $e) {
        $phpSpreadsheetDisponible = false;
    }
}

if (!$phpSpreadsheetDisponible) {
    require_once __DIR__ . '/exportar_excel_csv.php';
    exit;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$buscar = trim($_GET['q'] ?? '');
$filtro = trim($_GET['estado'] ?? '');

$sql = "SELECT * FROM cotizaciones WHERE 1=1";
$params = [];
if ($buscar !== '') {
    $sql .= " AND (cliente_nombre LIKE ? OR cliente_obra LIKE ? OR CAST(numero AS TEXT) LIKE ? OR creada_por LIKE ?)";
    $like = "%$buscar%";
    $params = [$like,$like,$like,$like];
}
if ($filtro !== '') {
    $sql .= " AND estado = ?";
    $params[] = $filtro;
}
$sql .= " ORDER BY numero DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cotizaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cotIds = array_column($cotizaciones, 'id');

$itemsPorCot = [];
$ocPorCot = [];
$procesosPorCot = [];

if (!empty($cotIds)) {
    $ph = implode(',', array_fill(0, count($cotIds), '?'));

    $stmtI = $pdo->prepare("SELECT * FROM cot_items WHERE cot_id IN ($ph) ORDER BY cot_id, id");
    $stmtI->execute($cotIds);
    while ($row = $stmtI->fetch(PDO::FETCH_ASSOC)) {
        $itemsPorCot[$row['cot_id']][] = $row;
    }

    $stmtOC = $pdo->prepare("
        SELECT a.cot_id, oc.numero, oc.proveedor_nombre, oc.obra, oc.total, oc.estado, oc.fecha, oc.neto, oc.iva, a.nota
        FROM cot_oc_asociaciones a JOIN ordenes_compra oc ON a.oc_id = oc.id
        WHERE a.cot_id IN ($ph) ORDER BY a.cot_id, oc.numero
    ");
    $stmtOC->execute($cotIds);
    while ($row = $stmtOC->fetch(PDO::FETCH_ASSOC)) {
        $ocPorCot[$row['cot_id']][] = $row;
    }

    $stmtP = $pdo->prepare("SELECT * FROM cot_procesos WHERE cot_id IN ($ph) ORDER BY cot_id, fecha DESC");
    $stmtP->execute($cotIds);
    while ($row = $stmtP->fetch(PDO::FETCH_ASSOC)) {
        $procesosPorCot[$row['cot_id']][] = $row;
    }
}

$mesesEs = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
            7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('DOGroup Sistema')
    ->setTitle('Reporte de Cotizaciones');

$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E7D32']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
];

$headerStyleRed = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'C0392B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
];

$cellBorders = [
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
];

$moneyFormat = '$ #,##0';

// ========== HOJA 1: PROYECTOS (formato del usuario) ==========
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Proyectos');

$headers = ['Año', 'Mes', 'Ciudad', 'Rut', 'Razon social', 'Nombre Obra', 'Cotizacion', 'Monto cotizado Neto', 'Estado', 'Total c/IVA', 'IVA', 'Creada por', 'Fecha', 'N° OC Asociadas', 'Total OC'];
foreach ($headers as $i => $h) {
    $col = chr(65 + $i);
    $sheet->setCellValue($col . '1', $h);
}
$sheet->getStyle('A1:O1')->applyFromArray($headerStyle);
$sheet->setAutoFilter('A1:O1');
$sheet->freezePane('A2');

$row = 2;
foreach ($cotizaciones as $c) {
    $ocs = $ocPorCot[$c['id']] ?? [];
    $totalOCs = array_sum(array_column($ocs, 'total'));
    $fechaTs = strtotime($c['fecha']);
    $anio = date('Y', $fechaTs);
    $mesNum = (int)date('n', $fechaTs);
    $mesNombre = $mesesEs[$mesNum] ?? '';

    $sheet->setCellValue('A' . $row, (int)$anio);
    $sheet->setCellValue('B' . $row, $mesNombre);
    $sheet->setCellValue('C' . $row, $c['cliente_ciudad'] ?? '');
    $sheet->setCellValue('D' . $row, $c['cliente_rut']);
    $sheet->setCellValue('E' . $row, $c['cliente_nombre']);
    $sheet->setCellValue('F' . $row, $c['cliente_obra']);
    $sheet->setCellValue('G' . $row, $c['numero']);
    $sheet->setCellValue('H' . $row, $c['subtotal']);
    $sheet->setCellValue('I' . $row, ucfirst($c['estado']));
    $sheet->setCellValue('J' . $row, $c['total']);
    $sheet->setCellValue('K' . $row, $c['iva']);
    $sheet->setCellValue('L' . $row, $c['creada_por']);
    $sheet->setCellValue('M' . $row, date('d/m/Y', $fechaTs));
    $sheet->setCellValue('N' . $row, count($ocs));
    $sheet->setCellValue('O' . $row, $totalOCs);

    $sheet->getStyle("H$row")->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle("J$row")->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle("K$row")->getNumberFormat()->setFormatCode($moneyFormat);
    $sheet->getStyle("O$row")->getNumberFormat()->setFormatCode($moneyFormat);

    $estadoColores = [
        'pendiente' => 'FFF3CD', 'propuesta' => 'D1ECF1', 'negociacion' => 'CCE5FF',
        'adjudicada' => 'D4EDDA', 'desierta' => 'E2E3E5', 'cerrado' => 'D6D8DB',
    ];
    $colorEstado = $estadoColores[$c['estado']] ?? 'FFFFFF';
    $sheet->getStyle("I$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorEstado);

    if ($c['subtotal'] > 500000) {
        $sheet->getStyle("H$row")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('C6EFCE');
    }

    $sheet->getStyle("A$row:O$row")->applyFromArray($cellBorders);
    $row++;
}

$totalRow = $row;
$sheet->setCellValue('G' . $totalRow, 'TOTALES:');
$sheet->setCellValue('H' . $totalRow, '=SUM(H2:H' . ($row - 1) . ')');
$sheet->setCellValue('J' . $totalRow, '=SUM(J2:J' . ($row - 1) . ')');
$sheet->setCellValue('K' . $totalRow, '=SUM(K2:K' . ($row - 1) . ')');
$sheet->setCellValue('O' . $totalRow, '=SUM(O2:O' . ($row - 1) . ')');
$sheet->getStyle("G$totalRow:O$totalRow")->getFont()->setBold(true);
$sheet->getStyle("H$totalRow")->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle("J$totalRow")->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle("K$totalRow")->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle("O$totalRow")->getNumberFormat()->setFormatCode($moneyFormat);
$sheet->getStyle("G$totalRow:O$totalRow")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8D7DA');

$anchos = ['A'=>8,'B'=>12,'C'=>16,'D'=>16,'E'=>35,'F'=>45,'G'=>12,'H'=>22,'I'=>14,'J'=>18,'K'=>14,'L'=>22,'M'=>12,'N'=>10,'O'=>18];
foreach ($anchos as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// ========== HOJA 2: DETALLE ÍTEMS ==========
$sheetItems = $spreadsheet->createSheet();
$sheetItems->setTitle('Detalle Items');

$hItems = ['N° Cotización', 'Cliente', 'Obra', 'Descripción', 'Detalle', 'Cantidad', 'Unidad', 'Precio Unit.', 'Total Ítem'];
foreach ($hItems as $i => $h) {
    $sheetItems->setCellValue(chr(65 + $i) . '1', $h);
}
$sheetItems->getStyle('A1:I1')->applyFromArray($headerStyleRed);

$row = 2;
foreach ($cotizaciones as $c) {
    foreach ($itemsPorCot[$c['id']] ?? [] as $item) {
        $sheetItems->setCellValue('A' . $row, $c['numero']);
        $sheetItems->setCellValue('B' . $row, $c['cliente_nombre']);
        $sheetItems->setCellValue('C' . $row, $c['cliente_obra']);
        $sheetItems->setCellValue('D' . $row, $item['descripcion']);
        $sheetItems->setCellValue('E' . $row, $item['detalle']);
        $sheetItems->setCellValue('F' . $row, $item['cantidad']);
        $sheetItems->setCellValue('G' . $row, mb_strtoupper((string)$item['unidad'], 'UTF-8'));
        $sheetItems->setCellValue('H' . $row, $item['precio']);
        $sheetItems->setCellValue('I' . $row, $item['total']);
        $sheetItems->getStyle("H$row:I$row")->getNumberFormat()->setFormatCode($moneyFormat);
        $sheetItems->getStyle("A$row:I$row")->applyFromArray($cellBorders);
        $row++;
    }
}
foreach (range('A', 'I') as $col) { $sheetItems->getColumnDimension($col)->setAutoSize(true); }

// ========== HOJA 3: OC ASOCIADAS ==========
$sheetOC = $spreadsheet->createSheet();
$sheetOC->setTitle('OC Asociadas');

$hOC = ['N° Cotización', 'Cliente', 'Obra (Cot)', 'N° OC', 'Proveedor', 'Obra (OC)', 'Fecha OC', 'Neto OC', 'IVA OC', 'Total OC', 'Estado OC', 'Nota'];
foreach ($hOC as $i => $h) {
    $sheetOC->setCellValue(chr(65 + $i) . '1', $h);
}
$sheetOC->getStyle('A1:L1')->applyFromArray($headerStyleRed);

$row = 2;
foreach ($cotizaciones as $c) {
    foreach ($ocPorCot[$c['id']] ?? [] as $oc) {
        $sheetOC->setCellValue('A' . $row, $c['numero']);
        $sheetOC->setCellValue('B' . $row, $c['cliente_nombre']);
        $sheetOC->setCellValue('C' . $row, $c['cliente_obra']);
        $sheetOC->setCellValue('D' . $row, $oc['numero']);
        $sheetOC->setCellValue('E' . $row, $oc['proveedor_nombre']);
        $sheetOC->setCellValue('F' . $row, $oc['obra']);
        $sheetOC->setCellValue('G' . $row, date('d/m/Y', strtotime($oc['fecha'])));
        $sheetOC->setCellValue('H' . $row, $oc['neto']);
        $sheetOC->setCellValue('I' . $row, $oc['iva']);
        $sheetOC->setCellValue('J' . $row, $oc['total']);
        $sheetOC->setCellValue('K' . $row, ucfirst($oc['estado']));
        $sheetOC->setCellValue('L' . $row, $oc['nota']);
        $sheetOC->getStyle("H$row:J$row")->getNumberFormat()->setFormatCode($moneyFormat);
        $sheetOC->getStyle("A$row:L$row")->applyFromArray($cellBorders);
        $row++;
    }
}
if ($row > 2) {
    $sheetOC->setCellValue('G' . $row, 'TOTALES:');
    $sheetOC->setCellValue('H' . $row, '=SUM(H2:H' . ($row-1) . ')');
    $sheetOC->setCellValue('I' . $row, '=SUM(I2:I' . ($row-1) . ')');
    $sheetOC->setCellValue('J' . $row, '=SUM(J2:J' . ($row-1) . ')');
    $sheetOC->getStyle("G$row:L$row")->getFont()->setBold(true);
    $sheetOC->getStyle("H$row:J$row")->getNumberFormat()->setFormatCode($moneyFormat);
}
foreach (range('A', 'L') as $col) { $sheetOC->getColumnDimension($col)->setAutoSize(true); }

// ========== HOJA 4: SEGUIMIENTO ==========
$sheetProc = $spreadsheet->createSheet();
$sheetProc->setTitle('Seguimiento');

$hProc = ['N° Cotización', 'Cliente', 'Obra', 'Tipo', 'Título', 'Descripción', 'Estado Proceso', 'Usuario', 'Fecha'];
foreach ($hProc as $i => $h) {
    $sheetProc->setCellValue(chr(65 + $i) . '1', $h);
}
$sheetProc->getStyle('A1:I1')->applyFromArray($headerStyleRed);

$row = 2;
foreach ($cotizaciones as $c) {
    foreach ($procesosPorCot[$c['id']] ?? [] as $p) {
        $sheetProc->setCellValue('A' . $row, $c['numero']);
        $sheetProc->setCellValue('B' . $row, $c['cliente_nombre']);
        $sheetProc->setCellValue('C' . $row, $c['cliente_obra']);
        $sheetProc->setCellValue('D' . $row, ucfirst(str_replace('_', ' ', $p['tipo'])));
        $sheetProc->setCellValue('E' . $row, $p['titulo']);
        $sheetProc->setCellValue('F' . $row, $p['descripcion']);
        $sheetProc->setCellValue('G' . $row, ucfirst(str_replace('_', ' ', $p['estado_proceso'] ?? 'pendiente')));
        $sheetProc->setCellValue('H' . $row, $p['usuario']);
        $sheetProc->setCellValue('I' . $row, date('d/m/Y H:i', strtotime($p['fecha'])));
        $sheetProc->getStyle("A$row:I$row")->applyFromArray($cellBorders);
        $row++;
    }
}
foreach (range('A', 'I') as $col) { $sheetProc->getColumnDimension($col)->setAutoSize(true); }

// ========== HOJA 5: RESUMEN POR OBRA ==========
$sheetObra = $spreadsheet->createSheet();
$sheetObra->setTitle('Resumen por Obra');

$hObra = ['Obra', 'Ciudad', 'Cliente', 'N° Cotizaciones', 'Total Neto', 'Total c/IVA', 'N° OC', 'Total OC', 'Estados'];
foreach ($hObra as $i => $h) {
    $sheetObra->setCellValue(chr(65 + $i) . '1', $h);
}
$sheetObra->getStyle('A1:I1')->applyFromArray($headerStyle);

$cotsPorObra = [];
foreach ($cotizaciones as $c) {
    $obra = trim($c['cliente_obra'] ?: 'Sin obra asignada');
    $cotsPorObra[$obra][] = $c;
}

$row = 2;
foreach ($cotsPorObra as $obraNombre => $cotsDeLaObra) {
    $totalNetoObra = array_sum(array_column($cotsDeLaObra, 'subtotal'));
    $totalObra = array_sum(array_column($cotsDeLaObra, 'total'));
    $clienteObra = $cotsDeLaObra[0]['cliente_nombre'] ?? '';
    $ciudadObra = $cotsDeLaObra[0]['cliente_ciudad'] ?? '';
    $totalOCObra = 0; $totalOCCount = 0;
    foreach ($cotsDeLaObra as $c) {
        $ocs = $ocPorCot[$c['id']] ?? [];
        $totalOCObra += array_sum(array_column($ocs, 'total'));
        $totalOCCount += count($ocs);
    }
    $estadosObra = array_count_values(array_column($cotsDeLaObra, 'estado'));
    $estadosStr = [];
    foreach ($estadosObra as $est => $cnt) { $estadosStr[] = "$cnt " . ucfirst($est); }

    $sheetObra->setCellValue('A' . $row, $obraNombre);
    $sheetObra->setCellValue('B' . $row, $ciudadObra);
    $sheetObra->setCellValue('C' . $row, $clienteObra);
    $sheetObra->setCellValue('D' . $row, count($cotsDeLaObra));
    $sheetObra->setCellValue('E' . $row, $totalNetoObra);
    $sheetObra->setCellValue('F' . $row, $totalObra);
    $sheetObra->setCellValue('G' . $row, $totalOCCount);
    $sheetObra->setCellValue('H' . $row, $totalOCObra);
    $sheetObra->setCellValue('I' . $row, implode(', ', $estadosStr));
    $sheetObra->getStyle("E$row:F$row")->getNumberFormat()->setFormatCode($moneyFormat);
    $sheetObra->getStyle("H$row")->getNumberFormat()->setFormatCode($moneyFormat);
    $sheetObra->getStyle("A$row:I$row")->applyFromArray($cellBorders);
    $row++;
}
foreach (range('A', 'I') as $col) { $sheetObra->getColumnDimension($col)->setAutoSize(true); }

$spreadsheet->setActiveSheetIndex(0);

$fecha = date('Y-m-d_H-i');
$filename = "Cotizaciones_DOGroup_$fecha.xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
