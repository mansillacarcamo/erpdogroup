<?php
/**
 * combustible_export_excel.php
 *  - ?hoja_id=N        → exporta una sola hoja (1 sheet)
 *  - ?multi=1 + filtros → exporta varias hojas, cada una en su propia sheet
 *
 * Formato: replica el papel "Distribución de Combustible".
 */
require_once 'config.php';
requireAuth();
requireCombustibleAcceso($usuario, $pdo);
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$esAdmin = isCombustibleAdmin($usuario, $pdo);
$miResp  = getResponsableCombustible($usuario, $pdo);

// ── Determinar qué hojas exportar ────────────────────────
$hojaId = (int)($_GET['hoja_id'] ?? 0);
$multi  = !empty($_GET['multi']);

$hojas = [];

if ($hojaId) {
  $st = $pdo->prepare("SELECT * FROM combustible_hojas WHERE id=?");
  $st->execute([$hojaId]);
  $hojas = $st->fetchAll(PDO::FETCH_ASSOC);
} elseif ($multi) {
  $mes      = $_GET['mes']      ?? date('Y-m');
  $obraId   = (int)($_GET['obra_id']  ?? 0);
  $respId   = (int)($_GET['resp_id']  ?? 0);
  $tipoComb = $_GET['tipo_combustible'] ?? '';
  $estado   = $_GET['estado'] ?? '';

  $inicio = "$mes-01";
  $fin    = date('Y-m-t', strtotime($inicio));

  $where = ["fecha BETWEEN ? AND ?"];
  $params = [$inicio, $fin];
  if (!$esAdmin) {
    if (!$miResp) { header('Location: combustible.php'); exit; }
    $where[] = "(responsable_id = ? OR creado_por = ?)";
    $params[] = $miResp['id'];
    $params[] = $usuario['id'];
  }
  if ($obraId)    { $where[] = "obra_id = ?";          $params[] = $obraId; }
  if ($respId)    { $where[] = "responsable_id = ?";   $params[] = $respId; }
  if ($tipoComb)  { $where[] = "tipo_combustible = ?"; $params[] = $tipoComb; }
  if ($estado)    { $where[] = "estado = ?";           $params[] = $estado; }

  $sql = "SELECT * FROM combustible_hojas WHERE ".implode(" AND ",$where)." ORDER BY fecha, id";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $hojas = $st->fetchAll(PDO::FETCH_ASSOC);
}

if (empty($hojas)) {
  header('Location: combustible.php?error=sin_datos');
  exit;
}

// Verificar permiso individual si no es admin
if (!$esAdmin) {
  $hojas = array_filter($hojas, function($h) use ($miResp, $usuario) {
    return ($miResp && (int)$miResp['id'] === (int)$h['responsable_id'])
        || ((int)$h['creado_por'] === (int)$usuario['id']);
  });
  if (empty($hojas)) { header('Location: combustible.php?error=sin_permiso'); exit; }
}

// ── Construir spreadsheet ────────────────────────────────
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0); // empezamos limpio

$NEGRO  = 'FF1A1A2E';
$DORADO = 'FFD97706';
$AZUL   = 'FFCFE2F3';   // azul claro del papel
$GRISCL = 'FFF1F3F5';

$stmtVales = $pdo->prepare("SELECT * FROM combustible_vales WHERE hoja_id=? ORDER BY nro_linea");

$indice = 0;
foreach ($hojas as $h) {
  $sheet = $spreadsheet->createSheet($indice);
  $titulo = 'Hoja '.$h['id'].' '.date('d-m-Y', strtotime($h['fecha']));
  $titulo = mb_substr($titulo, 0, 31); // límite Excel
  $sheet->setTitle($titulo);

  // Cargar vales
  $stmtVales->execute([$h['id']]);
  $vales = $stmtVales->fetchAll(PDO::FETCH_ASSOC);

  // ─── Encabezado ─────────────────────────────────────
  // Fila 1: título principal
  $sheet->mergeCells('A1:L1');
  $sheet->setCellValue('A1', 'DISTRIBUCIÓN DE COMBUSTIBLE');
  $sheet->getStyle('A1')->applyFromArray([
    'font' => ['bold'=>true, 'size'=>16, 'color'=>['argb'=>$NEGRO]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER, 'vertical'=>Alignment::VERTICAL_CENTER],
    'fill' => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['argb'=>'FFFFFFFF']],
    'borders' => ['bottom'=>['borderStyle'=>Border::BORDER_THICK, 'color'=>['argb'=>$DORADO]]]
  ]);
  $sheet->getRowDimension(1)->setRowHeight(28);

  // Fila 2-3: bloque de checkboxes simulados con celdas
  $tipoCombDiesel    = $h['tipo_combustible']==='diesel'   ? '☑' : '☐';
  $tipoCombGasolina  = $h['tipo_combustible']==='gasolina' ? '☑' : '☐';
  $fuenteCamion      = $h['tipo_fuente']==='camion'   ? '☑' : '☐';
  $fuenteStorage1    = $h['tipo_fuente']==='storage'  ? '☑' : '☐';
  $fuenteStorage2    = $h['tipo_fuente']==='storage2' ? '☑' : '☐';

  // A2: Diesel / A3: Gasolina
  $sheet->setCellValue('A2', "$tipoCombDiesel  Diesel");
  $sheet->setCellValue('A3', "$tipoCombGasolina  Gasolina");
  // C2: Camión / C3: Storage 1 / C4: Storage 2
  $sheet->setCellValue('C2', "$fuenteCamion  Camión");
  $sheet->setCellValue('C3', "$fuenteStorage1  Storage 1");
  $sheet->setCellValue('C4', "$fuenteStorage2  Storage 2");

  // E2-F3: Kilometraje
  $sheet->setCellValue('E2', 'Kilometraje inicial');  $sheet->setCellValue('F2', (float)$h['miter_inicial']);
  $sheet->setCellValue('E3', 'Kilometraje final');    $sheet->setCellValue('F3', (float)$h['miter_final']);
  $sheet->setCellValue('E4', 'Carguío día');    $sheet->setCellValue('F4', (float)$h['carguio_dia']);

  // H2-I3: Saldos
  $sheet->setCellValue('H2', 'Saldo inicial');  $sheet->setCellValue('I2', (float)$h['saldo_inicial']);
  $sheet->setCellValue('H3', 'Litros');         $sheet->setCellValue('I3', (float)$h['litros_recibidos']);
  $sheet->setCellValue('H4', 'Saldo final');    $sheet->setCellValue('I4', (float)$h['saldo_final']);

  $sheet->getStyle('E2:E4')->getFont()->setBold(true);
  $sheet->getStyle('H2:H4')->getFont()->setBold(true);
  $sheet->getStyle('F2:F4')->getNumberFormat()->setFormatCode('#,##0.00');
  $sheet->getStyle('I2:I4')->getNumberFormat()->setFormatCode('#,##0.00');

  // Fila 5: Fecha / Responsable / Obra
  $sheet->setCellValue('A5', 'Fecha:');     $sheet->setCellValue('B5', date('d-m-Y', strtotime($h['fecha'])));
  $sheet->setCellValue('D5', 'Responsable:'); $sheet->mergeCells('E5:G5');
  $sheet->setCellValue('E5', $h['responsable_nombre']);
  $sheet->setCellValue('H5', 'Obra:');      $sheet->mergeCells('I5:L5');
  $sheet->setCellValue('I5', $h['obra_nombre']);
  $sheet->getStyle('A5:L5')->getFont()->setBold(true);
  $sheet->getStyle('A5')->getFont()->setBold(true);
  $sheet->getStyle('B5')->getFont()->setBold(false);
  $sheet->getStyle('E5')->getFont()->setBold(false);
  $sheet->getStyle('I5')->getFont()->setBold(false);
  $sheet->getRowDimension(5)->setRowHeight(20);

  // ─── Tabla de detalle: encabezado en fila 7 ─────────
  $headers = ['#','Código','N° Vale','Patente','Descripción','Chofer/Operador','Empresa','Cantidad Litros','Horómetro/KM','Hora','Partida','Firma'];
  $col = 'A';
  foreach ($headers as $hd) {
    $sheet->setCellValue($col.'7', $hd);
    $col++;
  }
  $sheet->getStyle('A7:L7')->applyFromArray([
    'font' => ['bold'=>true, 'color'=>['argb'=>$NEGRO]],
    'fill' => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['argb'=>$AZUL]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER, 'vertical'=>Alignment::VERTICAL_CENTER, 'wrapText'=>true],
    'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['argb'=>$NEGRO]]]
  ]);
  $sheet->getRowDimension(7)->setRowHeight(28);

  // ─── Filas de detalle ───────────────────────────────
  $totalLitros = 0;
  $maxFilas = max(15, count($vales));
  for ($i=0; $i<$maxFilas; $i++) {
    $r = $i + 8;
    $v = $vales[$i] ?? null;

    $sheet->setCellValue("A$r", $i+1);
    if ($v) {
      $sheet->setCellValue("B$r", $v['codigo']);
      $sheet->setCellValue("C$r", $v['nro_vale']);
      $sheet->setCellValue("D$r", $v['patente']);
      $sheet->setCellValue("E$r", $v['descripcion']);
      $sheet->setCellValue("F$r", $v['chofer_operador']);
      $sheet->setCellValue("G$r", $v['empresa']);
      $sheet->setCellValue("H$r", (float)$v['cantidad_litros']);
      $sheet->setCellValue("I$r", $v['horometro_kilom']);
      $sheet->setCellValue("J$r", $v['hora']);
      $sheet->setCellValue("K$r", $v['partida']);
      $sheet->setCellValue("L$r", $v['firma']);
      $totalLitros += (float)$v['cantidad_litros'];
    }
  }

  $ultimaFila = 7 + $maxFilas;

  // Estilo del cuerpo
  $rangoCuerpo = "A8:L$ultimaFila";
  $sheet->getStyle($rangoCuerpo)->applyFromArray([
    'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['argb'=>'FF999999']]],
    'font' => ['size'=>10],
    'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER]
  ]);
  $sheet->getStyle("A8:A$ultimaFila")->applyFromArray([
    'fill' => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['argb'=>$GRISCL]],
    'font' => ['bold'=>true],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER]
  ]);
  $sheet->getStyle("H8:H$ultimaFila")->getNumberFormat()->setFormatCode('#,##0.00');
  $sheet->getStyle("H8:H$ultimaFila")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

  // Total
  $rTot = $ultimaFila + 1;
  $sheet->mergeCells("A$rTot:G$rTot");
  $sheet->setCellValue("A$rTot", 'TOTAL LITROS');
  $sheet->setCellValue("H$rTot", $totalLitros);
  $sheet->getStyle("A$rTot:L$rTot")->applyFromArray([
    'font' => ['bold'=>true, 'size'=>11],
    'fill' => ['fillType'=>Fill::FILL_SOLID, 'startColor'=>['argb'=>$DORADO]],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_RIGHT, 'vertical'=>Alignment::VERTICAL_CENTER],
    'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['argb'=>$NEGRO]]]
  ]);
  $sheet->getStyle("H$rTot")->getNumberFormat()->setFormatCode('#,##0.00');
  $sheet->getStyle("H$rTot")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
  $sheet->getRowDimension($rTot)->setRowHeight(22);

  // Observaciones
  if (!empty($h['observaciones'])) {
    $rObs = $rTot + 2;
    $sheet->setCellValue("A$rObs", 'Observaciones:');
    $sheet->mergeCells("A".($rObs+1).":L".($rObs+2));
    $sheet->setCellValue("A".($rObs+1), $h['observaciones']);
    $sheet->getStyle("A$rObs")->getFont()->setBold(true);
    $sheet->getStyle("A".($rObs+1).":L".($rObs+2))->applyFromArray([
      'borders' => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN, 'color'=>['argb'=>'FF999999']]],
      'alignment' => ['vertical'=>Alignment::VERTICAL_TOP, 'wrapText'=>true]
    ]);
  }

  // Pie con metadata
  $rMeta = $rTot + 5;
  $sheet->setCellValue("A$rMeta", 'Generado: '.date('d-m-Y H:i').' · Estado: '.strtoupper($h['estado']).' · Creada por: '.$h['creado_por_nombre']);
  $sheet->mergeCells("A$rMeta:L$rMeta");
  $sheet->getStyle("A$rMeta")->applyFromArray([
    'font' => ['italic'=>true, 'size'=>9, 'color'=>['argb'=>'FF777777']]
  ]);

  // Anchos de columna
  $widths = ['A'=>5,'B'=>12,'C'=>10,'D'=>11,'E'=>26,'F'=>22,'G'=>16,'H'=>13,'I'=>14,'J'=>8,'K'=>12,'L'=>12];
  foreach ($widths as $c=>$w) $sheet->getColumnDimension($c)->setWidth($w);

  // Configuración de impresión
  $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
  $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
  $sheet->getPageSetup()->setFitToWidth(1);
  $sheet->getPageSetup()->setFitToHeight(0);
  $sheet->getPageMargins()->setTop(0.4);
  $sheet->getPageMargins()->setBottom(0.4);
  $sheet->getPageMargins()->setLeft(0.4);
  $sheet->getPageMargins()->setRight(0.4);

  $indice++;
}

$spreadsheet->setActiveSheetIndex(0);

// ── Output ──────────────────────────────────────────────
if (count($hojas) === 1) {
  $h = $hojas[0];
  $nombre = 'combustible_'.date('Ymd', strtotime($h['fecha'])).'_hoja'.$h['id'].'.xlsx';
} else {
  $nombre = 'combustible_'.date('Ymd_His').'_'.count($hojas).'hojas.xlsx';
}

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$nombre.'"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
