<?php
/**
 * Reporte de despacho diario — Vista previa + Excel + PDF + Envío email
 * Acceso: Admin despacho, supervisor de obra, encargado de operaciones, Carchek, Conductor
 */
require_once 'config.php';

$esInterno   = !empty($_SESSION['usuario']);
$esExterno   = isExternoLoggedIn();
$esConductor = isConductorLoggedIn();

// Permitir acceso a usuario interno, Carchek externo o conductor
if (!$esInterno && !$esExterno && !$esConductor) {
    header('Location: login.php'); exit;
}

if ($esInterno) {
    requireModulo('despacho', $usuario, $pdo);
    $esAdmin = isDespachoAdmin($usuario, $pdo);
    $nombreSesion = $usuario['nombre'];
} elseif ($esExterno) {
    $ext = getExternoSession();
    $esAdmin = false;
    $nombreSesion = $ext['nombre'].' (Carchek)';
} else {
    // Conductor
    $cond = getConductorSession();
    $esAdmin = false;
    $nombreSesion = $cond['nombre'].' (Conductor)';
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

$MATERIALES = [
    'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4',
    'bajo_3'=>'Bajo 3','bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada',
    'grava'=>'Grava','gravilla'=>'Gravilla','arena'=>'Arena','arena_tubo'=>'Arena Tubo',
    'escombros'=>'Escombros','bolones'=>'Bolones',
];

$fecha     = $_GET['fecha'] ?? $_POST['fecha'] ?? date('Y-m-d');
$obraFiltro = (int)($_GET['obra_id'] ?? $_POST['obra_id'] ?? 0);

/* Si es Carchek, solo su obra */
if ($esExterno) {
    $extRow = $pdo->prepare("SELECT obra_id FROM despacho_externos WHERE id=?");
    $extRow->execute([$ext['id']]);
    $extObraId = (int)$extRow->fetchColumn();
    if ($extObraId) $obraFiltro = $extObraId;
}

/* Tickets del día */
$where = "fecha=? AND estado='validado'";
$params = [$fecha];
if ($obraFiltro) { $where .= " AND obra_id=?"; $params[] = $obraFiltro; }

/* Si es conductor, mostrar solo sus tickets del día (validados + enviados + borrador) */
if ($esConductor) {
    $where  = "fecha=? AND conductor_id=?";
    $params = [$fecha, $cond['id']];
}

$stT = $pdo->prepare("SELECT * FROM tickets_despacho WHERE $where ORDER BY obra_nombre, hora");
$stT->execute($params);
$tickets = $stT->fetchAll(PDO::FETCH_ASSOC);

/* Totales */
$totalM3    = array_sum(array_column($tickets, 'metros_cubicos'));
$totalViajes = count($tickets);

/* Resúmenes */
$porObra = $porConductor = $porMaterial = $porPPU = [];
foreach ($tickets as $t) {
    $ob  = $t['obra_nombre'] ?: 'Sin obra';
    $con = $t['conductor_nombre'] ?: 'Sin conductor';
    $mat = $MATERIALES[$t['tipo_material']] ?? $t['tipo_material'];
    $ppu = $t['ppu'] ?: '—';
    $m3  = (float)$t['metros_cubicos'];
    foreach ([
        'porObra'      => [$ob,  &$porObra],
        'porConductor' => [$con, &$porConductor],
        'porMaterial'  => [$mat, &$porMaterial],
        'porPPU'       => [$ppu, &$porPPU],
    ] as [$key, &$arr]) {
        $arr[$key]['viajes'] = ($arr[$key]['viajes'] ?? 0) + 1;
        $arr[$key]['m3']     = ($arr[$key]['m3'] ?? 0) + $m3;
    }
}

/* Obras disponibles para filtro */
$obras = $pdo->query("SELECT id, codigo, nombre FROM obras WHERE estado='activa' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC);

/* Usuarios internos con email para destinatarios */
$usuariosEmail = $pdo->query("SELECT id, nombre, email, cargo FROM usuarios WHERE email IS NOT NULL AND email != '' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

/* Encargado de operaciones configurado — preseleccionado por defecto */
$encargadoOper = null;
try {
    $stEO = $pdo->query("
        SELECT eo.usuario_id, eo.email_ext,
               u.nombre, u.email, u.cargo
        FROM despacho_encargado_operaciones eo
        LEFT JOIN usuarios u ON u.id = eo.usuario_id
        LIMIT 1
    ");
    $encargadoOper = $stEO->fetch(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

/* Encargado de costos también preseleccionado */
$encargadoCostos = null;
try {
    $stEC = $pdo->query("
        SELECT ec.usuario_id, u.nombre, u.email, u.cargo
        FROM despacho_encargado_costos ec
        JOIN usuarios u ON u.id = ec.usuario_id
        WHERE u.email IS NOT NULL AND u.email != ''
        LIMIT 1
    ");
    $encargadoCostos = $stEC->fetch(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

/* IDs preseleccionados por defecto */
$preseleccionados = [];
if ($encargadoOper && $encargadoOper['usuario_id']) $preseleccionados[] = (int)$encargadoOper['usuario_id'];
if ($encargadoCostos && $encargadoCostos['usuario_id']) $preseleccionados[] = (int)$encargadoCostos['usuario_id'];
$preseleccionados = array_unique($preseleccionados);

/* ══════════════════════════════════════════════════════════
   EXPORTAR EXCEL
══════════════════════════════════════════════════════════ */
if (isset($_GET['excel'])) {
    require_once 'vendor/autoload.php';
    $spread = new Spreadsheet();
    $spread->getProperties()->setTitle("Despacho $fecha");

    /* Hoja 1: Detalle */
    $sh = $spread->getActiveSheet();
    $sh->setTitle('Detalle');

    // Encabezado empresa
    $sh->setCellValue('A1', 'DOGROUP — Reporte de Despacho');
    $sh->setCellValue('A2', 'Fecha: '.date('d/m/Y', strtotime($fecha)));
    $sh->setCellValue('A3', 'Generado por: '.$nombreSesion.' · '.date('d/m/Y H:i'));
    $sh->mergeCells('A1:M1'); $sh->mergeCells('A2:M2'); $sh->mergeCells('A3:M3');
    foreach(['A1','A2','A3'] as $c) {
        $sh->getStyle($c)->getFont()->setBold(true);
        $sh->getStyle($c)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }
    $sh->getStyle('A1')->getFont()->setSize(14);

    // Cabecera tabla
    $headers = ['#','Fecha','Hora','Salida','Obra','Conductor','RUT','PPU','m³','Destino','Material','Cambio/Turno','Validado por','Obs.'];
    $cols = range('A','N');
    foreach ($headers as $i => $h) {
        $cell = $cols[$i].'5';
        $sh->setCellValue($cell, $h);
        $sh->getStyle($cell)->applyFromArray([
            'font'      => ['bold'=>true,'color'=>['rgb'=>'FFFFFF']],
            'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'1B2838']],
            'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
            'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'FFFFFF']]],
        ]);
    }

    // Datos
    $row = 6;
    foreach ($tickets as $i => $t) {
        $bg = ($i % 2 === 0) ? 'F8F9FA' : 'FFFFFF';
        $data = [
            str_pad($t['id'],5,'0',STR_PAD_LEFT),
            $t['fecha'],
            substr($t['hora'],0,5),
            $t['salida_nombre'] ?? '' ?: '—',
            $t['obra_nombre'] ?: '—',
            $t['conductor_nombre'] ?: '—',
            $t['conductor_rut'] ?: '—',
            $t['ppu'] ?: '—',
            number_format((float)$t['metros_cubicos'],1,',','.'),
            $t['destino'] ?: '—',
            $MATERIALES[$t['tipo_material']] ?? $t['tipo_material'],
            $t['cambio'] ?: '—',
            $t['validado_por_nombre'] ?: '—',
            $t['observaciones'] ?: '',
        ];
        foreach ($data as $j => $val) {
            $cell = $cols[$j].$row;
            $sh->setCellValue($cell, $val);
            $sh->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($bg);
            $sh->getStyle($cell)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('DEE2E6');
        }
        $row++;
    }

    // Totales
    $sh->setCellValue('A'.$row, 'TOTAL');
    $sh->setCellValue('H'.$row, $totalViajes.' viajes');
    $sh->setCellValue('I'.$row, number_format($totalM3,1,',','.'));
    $sh->getStyle("A{$row}:N{$row}")->getFont()->setBold(true);
    $sh->getStyle("A{$row}:N{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');

    // Anchos columna
    $anchos = [8,12,8,28,28,24,14,10,8,18,16,18,22,22];
    foreach ($anchos as $i => $w) $sh->getColumnDimension($cols[$i])->setWidth($w);

    /* Hoja 2: Resumen por conductor */
    $sh2 = $spread->createSheet();
    $sh2->setTitle('Por conductor');
    $sh2->setCellValue('A1','Conductor');
    $sh2->setCellValue('B1','Viajes');
    $sh2->setCellValue('C1','m³ Total');
    $sh2->getStyle('A1:C1')->applyFromArray(['font'=>['bold'=>true],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'1B2838']],'font'=>['color'=>['rgb'=>'FFFFFF'],'bold'=>true]]);
    $r2 = 2;
    arsort($porConductor);
    foreach ($porConductor as $nom => $d) {
        $sh2->setCellValue("A$r2", $nom);
        $sh2->setCellValue("B$r2", $d['viajes']);
        $sh2->setCellValue("C$r2", number_format($d['m3'],1,',','.'));
        $r2++;
    }
    $sh2->getColumnDimension('A')->setWidth(28);
    $sh2->getColumnDimension('B')->setWidth(10);
    $sh2->getColumnDimension('C')->setWidth(14);

    /* Hoja 3: Resumen por obra */
    $sh3 = $spread->createSheet();
    $sh3->setTitle('Por obra');
    $sh3->setCellValue('A1','Obra'); $sh3->setCellValue('B1','Viajes'); $sh3->setCellValue('C1','m³');
    $sh3->getStyle('A1:C1')->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],'fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'1B2838']]]);
    $r3 = 2;
    foreach ($porObra as $nom => $d) {
        $sh3->setCellValue("A$r3",$nom); $sh3->setCellValue("B$r3",$d['viajes']); $sh3->setCellValue("C$r3",number_format($d['m3'],1,',','.'));
        $r3++;
    }
    $sh3->getColumnDimension('A')->setWidth(34); $sh3->getColumnDimension('B')->setWidth(10); $sh3->getColumnDimension('C')->setWidth(14);

    $spread->setActiveSheetIndex(0);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="despacho_'.$fecha.'.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spread);
    $writer->save('php://output');
    exit;
}

/* ══════════════════════════════════════════════════════════
   EXPORTAR PDF (HTML → respuesta limpia para imprimir)
══════════════════════════════════════════════════════════ */
if (isset($_GET['pdf'])) {
    header('Content-Type: text/html; charset=utf-8');
    $fechaLabel = date('d/m/Y', strtotime($fecha));
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
    <title>Despacho '.$fechaLabel.'</title>
    <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;font-size:11px;color:#212529;padding:20px}
    .header{background:#1b2838;color:#fff;padding:14px 18px;border-radius:8px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center}
    .header h1{font-size:16px;margin:0}.header p{font-size:10px;opacity:.8;margin:2px 0}
    .stats{display:flex;gap:12px;margin-bottom:16px}
    .stat{background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:10px 16px;text-align:center;flex:1}
    .stat .n{font-size:22px;font-weight:900;color:#1b2838}.stat .l{font-size:9px;color:#6c757d;text-transform:uppercase;letter-spacing:.5px}
    table{width:100%;border-collapse:collapse;margin-bottom:16px;font-size:10px}
    th{background:#1b2838;color:#fff;padding:6px 8px;text-align:left;font-size:9px;text-transform:uppercase;letter-spacing:.3px}
    td{padding:5px 8px;border-bottom:1px solid #f0f0f0}
    tr:nth-child(even) td{background:#f9f9f9}
    .ppu{font-family:monospace;font-weight:900;background:#1b2838;color:#fff;padding:1px 5px;border-radius:3px;font-size:9px;letter-spacing:1px}
    .resumen{display:flex;gap:12px}
    .resumen-box{flex:1;border:1px solid #dee2e6;border-radius:8px;overflow:hidden}
    .resumen-box thead th{background:#374151}
    .footer{text-align:center;color:#9ca3af;font-size:9px;margin-top:20px;padding-top:10px;border-top:1px solid #dee2e6}
    @media print{body{padding:10px}.header{-webkit-print-color-adjust:exact;print-color-adjust:exact}th{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
    </style></head><body>';

    echo '<div class="header">
      <div><h1>DOGROUP — Reporte de Despacho</h1>
      <p>Fecha: '.$fechaLabel.' &nbsp;·&nbsp; Generado por: '.htmlspecialchars($nombreSesion).' &nbsp;·&nbsp; '.date('d/m/Y H:i').'</p></div>
      <div style="text-align:right"><p style="font-size:18px;font-weight:900;letter-spacing:1px">'.$totalViajes.' viajes</p>
      <p>'.number_format($totalM3,1,',','.').' m³ total</p></div>
    </div>';

    // Stats
    echo '<div class="stats">';
    foreach ($porObra as $n=>$d) echo '<div class="stat"><div class="n">'.$d['viajes'].'</div><div class="l">'.htmlspecialchars(substr($n,0,20)).'</div></div>';
    echo '</div>';

    // Tabla detalle
    echo '<table><thead><tr><th>#</th><th>Hora</th><th>Salida</th><th>Obra</th><th>Conductor</th><th>PPU</th><th>m³</th><th>Material</th><th>Destino</th><th>Validado por</th></tr></thead><tbody>';
    foreach ($tickets as $t) {
        echo '<tr>
          <td>'.str_pad($t['id'],5,'0',STR_PAD_LEFT).'</td>
          <td>'.substr($t['hora'],0,5).'</td>
          <td>'.htmlspecialchars($t['salida_nombre'] ?? '' ?:'—').'</td>
          <td>'.htmlspecialchars($t['obra_nombre']?:'—').'</td>
          <td>'.htmlspecialchars($t['conductor_nombre']?:'—').'</td>
          <td><span class="ppu">'.htmlspecialchars($t['ppu']?:'—').'</span></td>
          <td>'.number_format((float)$t['metros_cubicos'],1,',','.').'</td>
          <td>'.htmlspecialchars($MATERIALES[$t['tipo_material']]??$t['tipo_material']).'</td>
          <td>'.htmlspecialchars($t['destino']?:'—').'</td>
          <td>'.htmlspecialchars($t['validado_por_nombre']?:'—').'</td>
        </tr>';
    }
    echo '</tbody></table>';

    // Resúmenes
    echo '<div class="resumen">';
    foreach ([['Por conductor',$porConductor],['Por material',$porMaterial],['Por PPU',$porPPU]] as [$titulo,$data]) {
        echo '<div class="resumen-box"><table><thead><tr><th>'.$titulo.'</th><th>Viajes</th><th>m³</th></tr></thead><tbody>';
        arsort($data);
        foreach ($data as $n=>$d) echo '<tr><td>'.htmlspecialchars($n).'</td><td>'.$d['viajes'].'</td><td>'.number_format($d['m3'],1,',','.').'</td></tr>';
        echo '</tbody></table></div>';
    }
    echo '</div>';
    echo '<div class="footer">DOGROUP &copy; '.date('Y').' &mdash; Departamento de Informática DOGroup &mdash; Reporte generado el '.date('d/m/Y H:i').'</div>';
    echo '<script>window.print();</script></body></html>';
    exit;
}

/* ══════════════════════════════════════════════════════════
   POST: Enviar email
══════════════════════════════════════════════════════════ */
$msgEnvio = null; $msgTipo = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'enviar_email') {
    try {
        require_once 'vendor/autoload.php';
        require_once 'smtp_config.php';
        global $SMTP_CONFIG;

        $destinatarios = $_POST['destinatarios'] ?? [];
        $emailExtra    = trim($_POST['email_extra'] ?? '');
        $asuntoCustom  = trim($_POST['asunto'] ?? '');
        $notaCustom    = trim($_POST['nota'] ?? '');
        $fechaRep      = $_POST['fecha'] ?? date('Y-m-d');

        if (empty($destinatarios) && empty($emailExtra))
            throw new Exception('Selecciona al menos un destinatario.');

        // Generar Excel en memoria
        $spread = new Spreadsheet();
        $sh = $spread->getActiveSheet();
        $sh->setTitle('Despacho');
        $cols = range('A','N');
        $headers = ['#','Fecha','Hora','Salida','Obra','Conductor','RUT','PPU','m³','Destino','Material','Turno','Validado por','Obs.'];
        foreach ($headers as $i => $h) {
            $sh->setCellValue($cols[$i].'1', $h);
            $sh->getStyle($cols[$i].'1')->getFont()->setBold(true);
        }
        $r = 2;
        foreach ($tickets as $t) {
            $sh->setCellValue("A$r", str_pad($t['id'],5,'0',STR_PAD_LEFT));
            $sh->setCellValue("B$r", $t['fecha']);
            $sh->setCellValue("C$r", substr($t['hora'],0,5));
            $sh->setCellValue("D$r", $t['salida_nombre'] ?? '' ?:'—');
            $sh->setCellValue("E$r", $t['obra_nombre']?:'—');
            $sh->setCellValue("F$r", $t['conductor_nombre']?:'—');
            $sh->setCellValue("G$r", $t['conductor_rut']?:'—');
            $sh->setCellValue("H$r", $t['ppu']?:'—');
            $sh->setCellValue("I$r", number_format((float)$t['metros_cubicos'],1,',','.'));
            $sh->setCellValue("J$r", $t['destino']?:'—');
            $sh->setCellValue("K$r", $MATERIALES[$t['tipo_material']]??$t['tipo_material']);
            $sh->setCellValue("L$r", $t['cambio']?:'—');
            $sh->setCellValue("M$r", $t['validado_por_nombre']?:'—');
            $sh->setCellValue("N$r", $t['observaciones']?:'');
            $r++;
        }
        $tmpFile = sys_get_temp_dir().'/despacho_'.date('Ymd_His').'.xlsx';
        (new Xlsx($spread))->save($tmpFile);

        // HTML del email
        $fechaLabel = date('d/m/Y', strtotime($fechaRep));
        $tablaHtml = '';
        foreach ($tickets as $t) {
            $tablaHtml .= '<tr>
              <td style="padding:5px 8px;border:1px solid #e5e7eb">'.str_pad($t['id'],5,'0',STR_PAD_LEFT).'</td>
              <td style="padding:5px 8px;border:1px solid #e5e7eb">'.substr($t['hora'],0,5).'</td>
              <td style="padding:5px 8px;border:1px solid #e5e7eb">'.htmlspecialchars($t['salida_nombre'] ?? '' ?:'—').'</td>
              <td style="padding:5px 8px;border:1px solid #e5e7eb">'.htmlspecialchars($t['obra_nombre']?:'—').'</td>
              <td style="padding:5px 8px;border:1px solid #e5e7eb">'.htmlspecialchars($t['conductor_nombre']?:'—').'</td>
              <td style="padding:5px 8px;border:1px solid #e5e7eb;font-family:monospace;font-weight:900">'.htmlspecialchars($t['ppu']?:'—').'</td>
              <td style="padding:5px 8px;border:1px solid #e5e7eb;text-align:center">'.number_format((float)$t['metros_cubicos'],1,',','.').'</td>
              <td style="padding:5px 8px;border:1px solid #e5e7eb">'.htmlspecialchars($MATERIALES[$t['tipo_material']]??$t['tipo_material']).'</td>
              <td style="padding:5px 8px;border:1px solid #e5e7eb">'.htmlspecialchars($t['validado_por_nombre']?:'—').'</td>
            </tr>';
        }

        $htmlBody = '
        <div style="font-family:Segoe UI,Arial,sans-serif;max-width:900px;margin:0 auto">
          <div style="background:#1b2838;color:#fff;padding:20px 28px;border-radius:10px 10px 0 0">
            <h2 style="margin:0;font-size:1.2rem">DOGROUP — Reporte de Despacho</h2>
            <p style="margin:4px 0 0;opacity:.75;font-size:.9rem">Fecha: <strong>'.$fechaLabel.'</strong> &nbsp;·&nbsp; '.$totalViajes.' viajes &nbsp;·&nbsp; '.number_format($totalM3,1,',','.').' m³</p>
          </div>
          <div style="background:#f9fafb;padding:18px 28px">
            '.($notaCustom ? '<div style="background:#fffbeb;border-left:4px solid #d97706;padding:10px 14px;border-radius:4px;margin-bottom:16px;font-size:.9rem">'.nl2br(htmlspecialchars($notaCustom)).'</div>' : '').'
            <div style="display:flex;gap:12px;margin-bottom:16px">
              <div style="background:#d1fae5;border-radius:8px;padding:12px 18px;text-align:center;flex:1">
                <div style="font-size:1.8rem;font-weight:900;color:#065f46">'.$totalViajes.'</div>
                <div style="font-size:.75rem;color:#065f46">Viajes validados</div>
              </div>
              <div style="background:#dbeafe;border-radius:8px;padding:12px 18px;text-align:center;flex:1">
                <div style="font-size:1.8rem;font-weight:900;color:#1e40af">'.number_format($totalM3,1,',','.').'</div>
                <div style="font-size:.75rem;color:#1e40af">m³ totales</div>
              </div>
              <div style="background:#f3f4f6;border-radius:8px;padding:12px 18px;text-align:center;flex:1">
                <div style="font-size:1.8rem;font-weight:900;color:#374151">'.count($porConductor).'</div>
                <div style="font-size:.75rem;color:#6b7280">Conductores</div>
              </div>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:.85rem;margin-bottom:16px">
              <thead>
                <tr style="background:#374151;color:#fff">
                  <th style="padding:8px;text-align:left">#</th>
                  <th style="padding:8px;text-align:left">Hora</th>
                  <th style="padding:8px;text-align:left">Salida</th>
                  <th style="padding:8px;text-align:left">Obra</th>
                  <th style="padding:8px;text-align:left">Conductor</th>
                  <th style="padding:8px;text-align:left">PPU</th>
                  <th style="padding:8px;text-align:center">m³</th>
                  <th style="padding:8px;text-align:left">Material</th>
                  <th style="padding:8px;text-align:left">Validado por</th>
                </tr>
              </thead>
              <tbody>'.$tablaHtml.'</tbody>
            </table>
            <p style="font-size:.8rem;color:#9ca3af;text-align:center">El detalle completo está adjunto en el archivo Excel.</p>
          </div>
          <div style="background:#1b2838;color:#fff;padding:12px 28px;border-radius:0 0 10px 10px;font-size:.8rem;opacity:.8;text-align:center">
            DOGROUP &copy; '.date('Y').' &mdash; Generado por '.htmlspecialchars($nombreSesion).' el '.date('d/m/Y H:i').'
          </div>
        </div>';

        // Enviar
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = $SMTP_CONFIG['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $SMTP_CONFIG['user'];
        $mail->Password   = $SMTP_CONFIG['pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = $SMTP_CONFIG['port'];
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($SMTP_CONFIG['from_email'], $SMTP_CONFIG['from_name']);
        $mail->isHTML(true);
        $mail->Subject = $asuntoCustom ?: "Reporte Despacho $fechaLabel — DOGROUP";
        $mail->Body    = $htmlBody;
        $mail->addAttachment($tmpFile, "despacho_$fechaRep.xlsx");

        $enviados = [];
        // Destinatarios del sistema
        foreach ($destinatarios as $uid) {
            $uRow = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE id=? AND email!=''");
            $uRow->execute([$uid]);
            $u = $uRow->fetch(PDO::FETCH_ASSOC);
            if ($u) { $mail->addAddress($u['email'], $u['nombre']); $enviados[] = $u['nombre']; }
        }
        // Email adicional libre
        if ($emailExtra && filter_var($emailExtra, FILTER_VALIDATE_EMAIL)) {
            $mail->addAddress($emailExtra);
            $enviados[] = $emailExtra;
        }
        if (empty($enviados)) throw new Exception('No se encontraron emails válidos.');

        $mail->send();
        @unlink($tmpFile);

        // Registrar en BD
        $pdo->prepare("INSERT OR REPLACE INTO despacho_cierre_dia (fecha, total_tickets, total_m3, resumen_json, cerrado_por, cerrado_por_nombre, enviado, enviado_en)
            VALUES (?,?,?,?,?,?,1,datetime('now','localtime'))
            ON CONFLICT(fecha) DO UPDATE SET enviado=1, enviado_en=datetime('now','localtime')")
            ->execute([$fechaRep, $totalViajes, $totalM3, json_encode(['por_obra'=>$porObra,'por_conductor'=>$porConductor]), $esInterno?$usuario['id']:0, $nombreSesion]);

        $msgEnvio = '✅ Reporte enviado a: '.implode(', ', $enviados);
    } catch(Exception $e) {
        $msgEnvio = '❌ Error al enviar: '.$e->getMessage();
        $msgTipo = 'danger';
    }
}

require_once 'includes/header.php';
?>
<style>
.preview-card { border:1.5px solid #dee2e6; border-radius:14px; background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.07); overflow:hidden; margin-bottom:16px; }
.preview-header { background:#1b2838; color:#fff; padding:16px 20px; }
.stat-box-rep { border-radius:10px; padding:12px 16px; text-align:center; flex:1; }
.ppu-rep { font-family:monospace; font-weight:900; background:#1b2838; color:#fff; padding:2px 7px; border-radius:4px; font-size:.82rem; letter-spacing:1px; }
.resumen-tbl th { background:#374151; color:#fff; padding:6px 10px; font-size:.78rem; }
.resumen-tbl td { padding:5px 10px; border-bottom:1px solid #f0f0f0; font-size:.85rem; }
.resumen-tbl tr:nth-child(even) td { background:#f9fafb; }
.btn-accion { border-radius:10px; padding:10px 18px; font-weight:700; font-size:.92rem; border:none; display:flex; align-items:center; gap:8px; cursor:pointer; transition:transform .12s,box-shadow .12s; }
.btn-accion:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(0,0,0,.15); }
</style>

<!-- Cabecera -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1"><i class="bi bi-file-earmark-bar-graph-fill text-success me-2"></i>Reporte de Despacho</h3>
    <p class="text-muted small mb-0">Vista previa · Exportar · Enviar al personal de operaciones</p>
  </div>
  <a href="<?= $esConductor ? 'despacho_conductor_portal.php' : ($esExterno ? 'despacho_externo_portal.php' : 'tickets_despacho.php') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Volver
  </a>
</div>

<!-- Filtros -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="GET" class="d-flex gap-3 align-items-end flex-wrap">
      <div>
        <label class="form-label fw-semibold mb-1 small">Fecha</label>
        <input type="date" name="fecha" class="form-control form-control-sm" value="<?= $fecha ?>" max="<?= date('Y-m-d') ?>">
      </div>
      <?php if ($esAdmin && !empty($obras)): ?>
      <div>
        <label class="form-label fw-semibold mb-1 small">Obra</label>
        <select name="obra_id" class="form-select form-select-sm">
          <option value="0">Todas las obras</option>
          <?php foreach ($obras as $o): ?>
          <option value="<?= $o['id'] ?>" <?= $o['id']==$obraFiltro?'selected':'' ?>><?= htmlspecialchars($o['codigo'].' — '.$o['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrar</button>
    </form>
  </div>
</div>

<?php if ($msgEnvio): ?>
<div class="alert alert-<?= $msgTipo ?> alert-dismissible fade show">
  <?= htmlspecialchars($msgEnvio) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (empty($tickets)): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>No hay tickets validados para el <strong><?= date('d/m/Y', strtotime($fecha)) ?></strong>.</div>
<?php else: ?>

<!-- ══ VISTA PREVIA ══ -->
<div class="preview-card">
  <div class="preview-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h5 class="mb-0 fw-bold">Reporte <?= date('d/m/Y', strtotime($fecha)) ?></h5>
      <small style="opacity:.75"><?= $totalViajes ?> viajes validados · <?= number_format($totalM3,1,',','.') ?> m³ total</small>
    </div>
    <!-- Botones de exportación rápida -->
    <div class="d-flex gap-2 flex-wrap">
      <a href="?fecha=<?= $fecha ?>&obra_id=<?= $obraFiltro ?>&excel=1" class="btn-accion" style="background:#1d6f42;color:#fff">
        <i class="bi bi-file-earmark-excel-fill" style="font-size:1.1rem"></i> Descargar Excel
      </a>
      <a href="?fecha=<?= $fecha ?>&obra_id=<?= $obraFiltro ?>&pdf=1" target="_blank" class="btn-accion" style="background:#dc3545;color:#fff">
        <i class="bi bi-file-earmark-pdf-fill" style="font-size:1.1rem"></i> Ver/Imprimir PDF
      </a>
    </div>
  </div>

  <div class="p-3">
    <!-- Stats -->
    <div class="d-flex gap-2 flex-wrap mb-3">
      <div class="stat-box-rep" style="background:#f0fff4;border:1px solid #bbf7d0">
        <div style="font-size:1.8rem;font-weight:900;color:#15803d"><?= $totalViajes ?></div>
        <div style="font-size:.72rem;color:#15803d;font-weight:600">Viajes</div>
      </div>
      <div class="stat-box-rep" style="background:#dbeafe;border:1px solid #bfdbfe">
        <div style="font-size:1.8rem;font-weight:900;color:#1d4ed8"><?= number_format($totalM3,1,',','.') ?></div>
        <div style="font-size:.72rem;color:#1d4ed8;font-weight:600">m³ totales</div>
      </div>
      <div class="stat-box-rep" style="background:#f3f4f6;border:1px solid #dee2e6">
        <div style="font-size:1.8rem;font-weight:900;color:#374151"><?= count($porConductor) ?></div>
        <div style="font-size:.72rem;color:#6c757d;font-weight:600">Conductores</div>
      </div>
      <div class="stat-box-rep" style="background:#fff7ed;border:1px solid #fed7aa">
        <div style="font-size:1.8rem;font-weight:900;color:#c2410c"><?= count($porObra) ?></div>
        <div style="font-size:.72rem;color:#c2410c;font-weight:600">Obras</div>
      </div>
    </div>

    <!-- Tabla detalle -->
    <div class="table-responsive mb-3" style="max-height:400px;overflow-y:auto">
      <table class="table table-sm table-hover align-middle" style="font-size:.83rem">
        <thead class="table-dark sticky-top">
          <tr>
            <th>#</th><th>Hora</th><th>Salida</th><th>Obra</th><th>Conductor</th><th>PPU</th>
            <th>m³</th><th>Material</th><th>Destino</th><th>Validado por</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($tickets as $t): ?>
        <tr>
          <td><small class="text-muted"><?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?></small></td>
          <td><?= substr($t['hora'],0,5) ?></td>
          <td><small><?= htmlspecialchars($t['salida_nombre'] ?? '' ?:'—') ?></small></td>
          <td><small><?= htmlspecialchars($t['obra_nombre']?:'—') ?></small></td>
          <td><?= htmlspecialchars($t['conductor_nombre']?:'—') ?></td>
          <td><span class="ppu-rep"><?= htmlspecialchars($t['ppu']?:'—') ?></span></td>
          <td><strong><?= number_format((float)$t['metros_cubicos'],1,',','.') ?></strong></td>
          <td><span class="badge bg-dark" style="font-size:.75rem"><?= htmlspecialchars($MATERIALES[$t['tipo_material']]??$t['tipo_material']) ?></span></td>
          <td><small><?= htmlspecialchars($t['destino']?:'—') ?></small></td>
          <td><small><?= htmlspecialchars($t['validado_por_nombre']?:'—') ?></small></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Resúmenes -->
    <div class="row g-2">
      <?php foreach ([
        ['Por conductor', $porConductor],
        ['Por material',  $porMaterial],
        ['Por obra',      $porObra],
      ] as [$titulo, $data]): arsort($data); ?>
      <div class="col-md-4">
        <table class="resumen-tbl w-100 table table-sm">
          <thead><tr><th><?= $titulo ?></th><th class="text-center">Viajes</th><th class="text-center">m³</th></tr></thead>
          <tbody>
          <?php foreach ($data as $n => $d): ?>
          <tr>
            <td><?= htmlspecialchars($n) ?></td>
            <td class="text-center"><?= $d['viajes'] ?></td>
            <td class="text-center"><?= number_format($d['m3'],1,',','.') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ══ FORMULARIO ENVÍO EMAIL ══ -->
<div class="preview-card">
  <div class="preview-header">
    <h5 class="mb-0 fw-bold"><i class="bi bi-send-fill me-2"></i>Enviar reporte al personal de operaciones</h5>
    <small style="opacity:.75">Se adjuntará el Excel automáticamente</small>
  </div>
  <div class="p-4">
    <form method="POST">
      <input type="hidden" name="accion" value="enviar_email">
      <input type="hidden" name="fecha" value="<?= $fecha ?>">
      <input type="hidden" name="obra_id" value="<?= $obraFiltro ?>">

      <div class="row g-3">
        <!-- Destinatarios del sistema -->
        <div class="col-md-6">
          <label class="form-label fw-semibold">
            <i class="bi bi-people me-1"></i>Destinatarios
          </label>

          <?php
          // Mostrar encargado de operaciones destacado si está configurado
          $emailEncOper = $encargadoOper['email'] ?? $encargadoOper['email_ext'] ?? '';
          if ($encargadoOper): ?>
          <div class="alert py-2 mb-2" style="background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:10px">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-person-check-fill text-primary" style="font-size:1.2rem"></i>
              <div class="flex-grow-1">
                <div class="fw-bold" style="font-size:.9rem">
                  <?= htmlspecialchars($encargadoOper['nombre'] ?? 'Encargado externo') ?>
                  <span class="badge bg-primary ms-1" style="font-size:.7rem">Enc. Operaciones</span>
                </div>
                <small class="text-muted">
                  <?= htmlspecialchars($emailEncOper ?: 'Sin email configurado') ?>
                </small>
              </div>
              <?php if ($encargadoOper['usuario_id']): ?>
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="destinatarios[]"
                       value="<?= $encargadoOper['usuario_id'] ?>"
                       id="dest_encoper" checked>
              </div>
              <?php elseif ($emailEncOper): ?>
              <input type="hidden" name="email_extra_oper" value="<?= htmlspecialchars($emailEncOper) ?>">
              <span class="badge bg-success">✓ Incluido</span>
              <?php endif; ?>
            </div>
          </div>
          <?php else: ?>
          <div class="alert alert-warning py-2 mb-2" style="font-size:.82rem;border-radius:8px">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Sin encargado de operaciones configurado.
            <a href="tickets_despacho_admin.php" class="alert-link">Configurar →</a>
          </div>
          <?php endif; ?>

          <?php if ($encargadoCostos && $encargadoCostos['usuario_id'] != ($encargadoOper['usuario_id'] ?? null)): ?>
          <div class="alert py-2 mb-2" style="background:#f0fff4;border:1.5px solid #bbf7d0;border-radius:10px">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-person-check-fill text-success" style="font-size:1.2rem"></i>
              <div class="flex-grow-1">
                <div class="fw-bold" style="font-size:.9rem">
                  <?= htmlspecialchars($encargadoCostos['nombre']) ?>
                  <span class="badge bg-success ms-1" style="font-size:.7rem">Enc. Costos</span>
                </div>
                <small class="text-muted"><?= htmlspecialchars($encargadoCostos['email']) ?></small>
              </div>
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="destinatarios[]"
                       value="<?= $encargadoCostos['usuario_id'] ?>"
                       id="dest_costos" checked>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Otros usuarios del sistema -->
          <?php
          $otrosUsuarios = array_filter($usuariosEmail, function($u) use ($preseleccionados) {
              return !in_array((int)$u['id'], $preseleccionados);
          });
          if (!empty($otrosUsuarios)): ?>
          <div style="font-size:.78rem;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px;margin:10px 0 6px">
            Agregar otros usuarios
          </div>
          <div class="border rounded p-2" style="max-height:140px;overflow-y:auto;background:#f8f9fa">
            <?php foreach ($otrosUsuarios as $u): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="destinatarios[]"
                     value="<?= $u['id'] ?>" id="dest_<?= $u['id'] ?>">
              <label class="form-check-label small" for="dest_<?= $u['id'] ?>">
                <strong><?= htmlspecialchars($u['nombre']) ?></strong>
                <span class="text-muted"> · <?= htmlspecialchars($u['email']) ?></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Email adicional y configuración -->
        <div class="col-md-6">
          <div class="mb-3">
            <label class="form-label fw-semibold">
              <i class="bi bi-envelope-plus me-1"></i>Email adicional
              <small class="text-muted fw-normal">(externo al sistema)</small>
            </label>
            <input type="email" name="email_extra" class="form-control"
                   placeholder="correo@empresa.cl"
                   value="<?= htmlspecialchars($encargadoOper['email_ext'] ?? '') ?>">
            <?php if (!empty($encargadoOper['email_ext'])): ?>
            <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Email externo del encargado de operaciones precargado</small>
            <?php endif; ?>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Asunto del email</label>
            <input type="text" name="asunto" class="form-control"
                   value="Reporte Despacho <?= date('d/m/Y', strtotime($fecha)) ?> — DOGROUP">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Nota adicional <small class="text-muted fw-normal">(opcional)</small></label>
            <textarea name="nota" class="form-control" rows="2" placeholder="Observaciones del día, incidencias, etc..."></textarea>
          </div>
        </div>

        <!-- Botón enviar -->
        <div class="col-12">
          <div class="alert alert-info py-2 mb-3" style="font-size:.85rem">
            <i class="bi bi-paperclip me-1"></i>
            Se adjuntará el Excel con el detalle completo (<?= $totalViajes ?> tickets · <?= number_format($totalM3,1,',','.') ?> m³).
            El reporte también quedará registrado en el historial del sistema.
          </div>
          <button type="submit" class="btn-accion w-100 justify-content-center" style="background:#1a1a2e;color:#fff;font-size:1.05rem;padding:14px;">
            <i class="bi bi-send-fill" style="font-size:1.2rem"></i>
            Enviar reporte por email
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
