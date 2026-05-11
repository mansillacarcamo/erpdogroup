<?php
require_once 'config.php';
use PHPMailer\PHPMailer\PHPMailer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

if (!isExternoLoggedIn()) {
  header('Location: despacho_externo_login.php'); exit;
}
$ext = getExternoSession();

/* ── Logout ── */
if (isset($_GET['logout'])) {
  session_destroy();
  header('Location: despacho_externo_login.php'); exit;
}

$MATERIALES = [
  'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4','bajo_3'=>'Bajo 3',
  'bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada','grava'=>'Grava','gravilla'=>'Gravilla',
  'arena'=>'Arena','arena_tubo'=>'Arena Tubo','escombros'=>'Escombros','bolones'=>'Bolones',
];

$CAMBIOS = ['Mañana (06:00–14:00)', 'Tarde (14:00–22:00)', 'Noche (22:00–06:00)'];

$msg = null; $msgTipo = 'success';
if (!empty($_GET['msg'])) { $msg = $_GET['msg']; $msgTipo = $_GET['mt'] ?? 'success'; }

/* ════════════════════════════════════════════════════════
   POST
   ════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';

  /* ── Validar ticket (cambio/turno via QR) ── */
  if ($accion === 'validar') {
    $tok    = trim($_POST['token']  ?? '');
    $cambio = trim($_POST['cambio'] ?? '');
    try {
      if (!$tok) throw new Exception('Token de ticket no válido.');
      $stUp = $pdo->prepare("UPDATE tickets_despacho SET estado='validado', validado_en=datetime('now','localtime'), validado_por=0, validado_por_nombre=?, cambio=?, estado_revision='aprobado', revisado_por_ext_id=?, revisado_por_ext_nom=?, revisado_en=datetime('now','localtime') WHERE token=? AND estado='enviado'");
      $stUp->execute([$ext['nombre'].' (ext.)', $cambio, $ext['id'], $ext['nombre'], $tok]);
      if ($stUp->rowCount() > 0) {
        // Registrar en carchek_log para que quede historial
        try {
          $tRow = $pdo->prepare("SELECT id, fecha, hora, ppu, conductor_nombre, obra_nombre, tipo_material, metros_cubicos, destino FROM tickets_despacho WHERE token=?");
          $tRow->execute([$tok]);
          $tDat = $tRow->fetch(PDO::FETCH_ASSOC);
          if ($tDat) {
            $existe = $pdo->prepare("SELECT id FROM carchek_log WHERE ticket_id=? AND externo_id=?");
            $existe->execute([$tDat['id'], $ext['id']]);
            if ($existe->fetchColumn()) {
              $pdo->prepare("UPDATE carchek_log SET accion='validado', estado_revision='aprobado', registrado_en=datetime('now','localtime') WHERE ticket_id=? AND externo_id=?")
                  ->execute([$tDat['id'], $ext['id']]);
            } else {
              $pdo->prepare("INSERT INTO carchek_log (externo_id,externo_nombre,ticket_id,fecha,hora,ppu,conductor_nombre,obra_nombre,tipo_material,metros_cubicos,destino,accion,estado_revision,observacion) VALUES (?,?,?,?,?,?,?,?,?,?,?,'validado','aprobado','')")
                  ->execute([$ext['id'],$ext['nombre'],$tDat['id'],$tDat['fecha'],date('H:i'),$tDat['ppu'],$tDat['conductor_nombre'],$tDat['obra_nombre'],$tDat['tipo_material'],(float)$tDat['metros_cubicos'],$tDat['destino']]);
            }
          }
        } catch(Exception $eLog) {}
        header("Location: despacho_externo_portal.php?msg=".urlencode('Ticket validado correctamente.')."&mt=success"); exit;
      } else {
        throw new Exception('Ticket no encontrado, ya validado o no en estado enviado.');
      }
    } catch(Exception $e) { $msg = $e->getMessage(); $msgTipo = 'danger'; }
  }

  /* ── Revisar ticket (aprobado / rechazado / observacion) ── */
  if ($accion === 'revisar_ticket') {
    $tid    = (int)($_POST['ticket_id'] ?? 0);
    $estado = $_POST['estado_revision'] ?? '';
    $obs    = trim($_POST['observacion'] ?? '');
    if (!in_array($estado, ['aprobado','rechazado','observacion'])) {
      $msg = 'Estado de revisión inválido.'; $msgTipo = 'danger';
    } else {
      $pdo->prepare("UPDATE tickets_despacho SET estado_revision=?, observacion_revision=?, revisado_por_ext_id=?, revisado_por_ext_nom=?, revisado_en=datetime('now','localtime') WHERE id=?")
          ->execute([$estado, $obs, $ext['id'], $ext['nombre'], $tid]);
      // Registrar en carchek_log
      try {
        $tRow = $pdo->prepare("SELECT fecha, hora, ppu, conductor_nombre, obra_nombre, tipo_material, metros_cubicos, destino FROM tickets_despacho WHERE id=?");
        $tRow->execute([$tid]);
        $tDat = $tRow->fetch(PDO::FETCH_ASSOC);
        if ($tDat) {
          $existe = $pdo->prepare("SELECT id FROM carchek_log WHERE ticket_id=? AND externo_id=?");
          $existe->execute([$tid, $ext['id']]);
          if ($existe->fetchColumn()) {
            $pdo->prepare("UPDATE carchek_log SET estado_revision=?, observacion=?, registrado_en=datetime('now','localtime') WHERE ticket_id=? AND externo_id=?")
                ->execute([$estado, $obs, $tid, $ext['id']]);
          } else {
            $pdo->prepare("INSERT INTO carchek_log (externo_id,externo_nombre,ticket_id,fecha,hora,ppu,conductor_nombre,obra_nombre,tipo_material,metros_cubicos,destino,accion,estado_revision,observacion) VALUES (?,?,?,?,?,?,?,?,?,?,?,'revisado',?,?)")
                ->execute([$ext['id'],$ext['nombre'],$tid,$tDat['fecha'],date('H:i'),$tDat['ppu'],$tDat['conductor_nombre'],$tDat['obra_nombre'],$tDat['tipo_material'],(float)$tDat['metros_cubicos'],$tDat['destino'],$estado,$obs]);
          }
        }
      } catch(Exception $eLog) {}
      header("Location: despacho_externo_portal.php?tab=revision&msg=".urlencode('Revisión guardada.')."&mt=success"); exit;
    }
  }

  /* ── Enviar reporte de revisión al encargado de operaciones ── */
  if ($accion === 'enviar_reporte_revision') {
    $fechaRep = $_POST['fecha_reporte'] ?? date('Y-m-d');
    try {
      require_once 'vendor/autoload.php';
      require_once 'smtp_config.php';
      global $SMTP_CONFIG;

      $MATERIALES_LOC = [
        'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4',
        'bajo_3'=>'Bajo 3','bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada',
        'grava'=>'Grava','gravilla'=>'Gravilla','arena'=>'Arena','arena_tubo'=>'Arena Tubo',
        'escombros'=>'Escombros','bolones'=>'Bolones',
      ];

      /* ── Tickets del día validados por este Carchek ── */
      $stRep = $pdo->prepare("
        SELECT td.*, o.codigo AS obra_codigo, o.nombre AS obra_nombre,
               dp.ppu AS ppu_codigo, dd.nombre AS destino_nombre
        FROM tickets_despacho td
        LEFT JOIN obras o              ON o.id  = td.obra_id
        LEFT JOIN despacho_ppu dp      ON dp.id = td.ppu_id
        LEFT JOIN despacho_destinos dd ON dd.id = td.destino_id
        WHERE td.fecha = ? AND td.estado = 'validado'
          AND (td.revisado_por_ext_id = ? OR td.validado_por_nombre LIKE ?)
        ORDER BY td.hora ASC
      ");
      $stRep->execute([$fechaRep, $ext['id'], '%'.$ext['nombre'].'%']);
      $ticketsRev = $stRep->fetchAll(PDO::FETCH_ASSOC);

      // Si no hay con filtro estricto, buscar todos los validados del día de su obra
      if (empty($ticketsRev)) {
        $extObraId = null;
        $stOb = $pdo->prepare("SELECT obra_id FROM despacho_externos WHERE id=?");
        $stOb->execute([$ext['id']]);
        $extObraId = $stOb->fetchColumn();
        if ($extObraId) {
          $stRep2 = $pdo->prepare("
            SELECT td.*, o.codigo AS obra_codigo, o.nombre AS obra_nombre,
                   dp.ppu AS ppu_codigo, dd.nombre AS destino_nombre
            FROM tickets_despacho td
            LEFT JOIN obras o              ON o.id  = td.obra_id
            LEFT JOIN despacho_ppu dp      ON dp.id = td.ppu_id
            LEFT JOIN despacho_destinos dd ON dd.id = td.destino_id
            WHERE td.fecha = ? AND td.estado = 'validado' AND td.obra_id = ?
            ORDER BY td.hora ASC
          ");
          $stRep2->execute([$fechaRep, $extObraId]);
          $ticketsRev = $stRep2->fetchAll(PDO::FETCH_ASSOC);
        }
      }

      if (empty($ticketsRev)) throw new Exception('No hay tickets validados para el '.date('d/m/Y', strtotime($fechaRep)).'.');

      /* ── Estadísticas ── */
      $totAprobados=$totRechazados=$totObservados=0;
      $totalM3 = 0;
      foreach($ticketsRev as $_tr) {
        if($_tr['estado_revision']==='aprobado')    $totAprobados++;
        elseif($_tr['estado_revision']==='rechazado')  $totRechazados++;
        elseif($_tr['estado_revision']==='observacion') $totObservados++;
        $totalM3 += (float)$_tr['metros_cubicos'];
      }

      /* ── Destinatarios: encargado operaciones + costos ── */
      $destEmail = [];

      // 1. Encargado de operaciones
      $stEO = $pdo->query("
        SELECT eo.email_ext, u.nombre, u.email
        FROM despacho_encargado_operaciones eo
        LEFT JOIN usuarios u ON u.id = eo.usuario_id
      ");
      foreach ($stEO->fetchAll(PDO::FETCH_ASSOC) as $enc) {
        $em = $enc['email'] ?: $enc['email_ext'];
        if ($em) $destEmail[] = ['email'=>$em, 'nombre'=>$enc['nombre'] ?: 'Enc. Operaciones'];
      }

      // 2. Encargado de costos
      $stEC = $pdo->query("SELECT u.nombre, u.email FROM despacho_encargado_costos ec JOIN usuarios u ON u.id=ec.usuario_id WHERE u.email != '' AND u.email IS NOT NULL");
      foreach ($stEC->fetchAll(PDO::FETCH_ASSOC) as $enc) {
        $destEmail[] = ['email'=>$enc['email'], 'nombre'=>$enc['nombre']];
      }

      // Deduplicar
      $destEmail = array_values(array_unique($destEmail, SORT_REGULAR));

      if (empty($destEmail)) throw new Exception('No hay encargado de operaciones configurado con email. Configúralo en Administración → Módulo Despacho.');

      /* ── Generar Excel con PhpSpreadsheet ── */

      $spread = new Spreadsheet();
      $sh = $spread->getActiveSheet();
      $sh->setTitle('Validaciones '.$fechaRep);

      // Encabezado
      $sh->setCellValue('A1', 'DOGROUP — Reporte Carchek');
      $sh->setCellValue('A2', 'Validador: '.$ext['nombre'].' ('.$ext['empresa'].')');
      $sh->setCellValue('A3', 'Fecha: '.date('d/m/Y', strtotime($fechaRep)).' — Generado: '.date('d/m/Y H:i'));
      $sh->mergeCells('A1:K1'); $sh->mergeCells('A2:K2'); $sh->mergeCells('A3:K3');
      $sh->getStyle('A1')->getFont()->setBold(true)->setSize(13);
      $sh->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1B2838');
      $sh->getStyle('A1')->getFont()->getColor()->setRGB('FFFFFF');

      // Headers tabla
      $hdrs = ['#','Hora','PPU','Conductor','Salida','Obra','Destino','Material','m³','Estado','Observación'];
      $cols = range('A','K');
      foreach($hdrs as $i=>$h){
        $sh->setCellValue($cols[$i].'5', $h);
        $sh->getStyle($cols[$i].'5')->applyFromArray([
          'font'      => ['bold'=>true,'color'=>['rgb'=>'FFFFFF']],
          'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'374151']],
          'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
        ]);
      }

      $rowNum = 6;
      foreach($ticketsRev as $i=>$t){
        $bg = $i%2===0 ? 'F9FAFB' : 'FFFFFF';
        $revBg = ['aprobado'=>'D1E7DD','rechazado'=>'F8D7DA','observacion'=>'FFF3CD'][$t['estado_revision']] ?? $bg;
        $revLbl= ['aprobado'=>'Aprobado','rechazado'=>'Rechazado','observacion'=>'Observación'][$t['estado_revision']] ?? '—';
        $data  = [
          str_pad($t['id'],5,'0',STR_PAD_LEFT),
          substr($t['hora'],0,5),
          $t['ppu_codigo'] ?: $t['ppu'] ?: '—',
          $t['conductor_nombre'] ?: '—',
          $t['salida_nombre'] ?? '' ?: '—',
          $t['obra_codigo'] ? $t['obra_codigo'].' — '.$t['obra_nombre'] : '—',
          $t['destino_nombre'] ?: $t['destino'] ?: '—',
          $MATERIALES_LOC[$t['tipo_material']] ?? $t['tipo_material'],
          number_format((float)$t['metros_cubicos'],1,',','.'),
          $revLbl,
          $t['observacion_revision'] ?: '—',
        ];
        foreach($data as $j=>$val){
          $cell = $cols[$j].$rowNum;
          $sh->setCellValue($cell, $val);
          $useBg = ($j>=8) ? $revBg : $bg;
          $sh->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($useBg);
          $sh->getStyle($cell)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E5E7EB');
        }
        $rowNum++;
      }

      // Totales
      $sh->setCellValue('A'.$rowNum, 'TOTAL');
      $sh->setCellValue('G'.$rowNum, count($ticketsRev).' tickets');
      $sh->setCellValue('H'.$rowNum, number_format($totalM3,1,',','.'));
      $sh->getStyle('A'.$rowNum.':J'.$rowNum)->getFont()->setBold(true);
      $sh->getStyle('A'.$rowNum.':J'.$rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3CD');

      // Anchos
      foreach([8,8,10,22,28,18,16,8,14,28] as $i=>$w) $sh->getColumnDimension($cols[$i])->setWidth($w);

      $tmpFile = sys_get_temp_dir().'/carchek_'.date('Ymd_His').'.xlsx';
      (new Xlsx($spread))->save($tmpFile);

      /* ── Construir email HTML ── */
      $htmlRows = '';
      foreach($ticketsRev as $t){
        $bg = ['aprobado'=>'#d1e7dd','rechazado'=>'#f8d7da','observacion'=>'#fff3cd'][$t['estado_revision']] ?? '#f9fafb';
        $lbl= ['aprobado'=>'✅ Aprobado','rechazado'=>'❌ Rechazado','observacion'=>'⚠️ Obs.'][$t['estado_revision']] ?? '—';
        $htmlRows .= "<tr style='background:{$bg}'>
          <td style='padding:5px 8px'>#".str_pad($t['id'],5,'0',STR_PAD_LEFT)."</td>
          <td style='padding:5px 8px'>".substr($t['hora'],0,5)."</td>
          <td style='padding:5px 8px;font-family:monospace;font-weight:900'>".htmlspecialchars($t['ppu_codigo']?:$t['ppu']?:'—')."</td>
          <td style='padding:5px 8px'>".htmlspecialchars($t['conductor_nombre']?:'—')."</td>
          <td style='padding:5px 8px'>".htmlspecialchars($MATERIALES_LOC[$t['tipo_material']]??$t['tipo_material'])."</td>
          <td style='padding:5px 8px;text-align:center'>".number_format((float)$t['metros_cubicos'],1,',','.')."</td>
          <td style='padding:5px 8px;font-weight:700'>{$lbl}</td>
          <td style='padding:5px 8px;font-size:.82em'>".htmlspecialchars($t['observacion_revision']?:'—')."</td>
        </tr>";
      }

      $fechaLabel = date('d/m/Y', strtotime($fechaRep));
      $htmlBody = "
      <div style='font-family:Segoe UI,Arial,sans-serif;max-width:900px;margin:0 auto'>
        <div style='background:#1b2838;color:#fff;padding:18px 28px;border-radius:10px 10px 0 0'>
          <h2 style='margin:0;font-size:1.2rem'>DOGROUP — Reporte Carchek</h2>
          <p style='margin:4px 0 0;opacity:.7;font-size:.88rem'>Fecha: <strong>{$fechaLabel}</strong> &nbsp;·&nbsp; Validador: <strong>{$ext['nombre']}</strong> ({$ext['empresa']})</p>
        </div>
        <div style='background:#f9fafb;padding:18px 28px'>
          <div style='display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap'>
            <div style='background:#d1e7dd;border-radius:8px;padding:10px 18px;text-align:center;flex:1;min-width:80px'>
              <div style='font-size:1.8rem;font-weight:900;color:#065f46'>{$totAprobados}</div>
              <div style='font-size:.72rem;color:#065f46;font-weight:700'>Aprobados</div>
            </div>
            <div style='background:#f8d7da;border-radius:8px;padding:10px 18px;text-align:center;flex:1;min-width:80px'>
              <div style='font-size:1.8rem;font-weight:900;color:#7f1d1d'>{$totRechazados}</div>
              <div style='font-size:.72rem;color:#7f1d1d;font-weight:700'>Rechazados</div>
            </div>
            <div style='background:#fff3cd;border-radius:8px;padding:10px 18px;text-align:center;flex:1;min-width:80px'>
              <div style='font-size:1.8rem;font-weight:900;color:#78350f'>{$totObservados}</div>
              <div style='font-size:.72rem;color:#78350f;font-weight:700'>Con obs.</div>
            </div>
            <div style='background:#dbeafe;border-radius:8px;padding:10px 18px;text-align:center;flex:1;min-width:80px'>
              <div style='font-size:1.8rem;font-weight:900;color:#1e40af'>".number_format($totalM3,1,',','.')."</div>
              <div style='font-size:.72rem;color:#1e40af;font-weight:700'>m³ total</div>
            </div>
          </div>
          <table style='width:100%;border-collapse:collapse;font-size:.85rem'>
            <thead>
              <tr style='background:#374151;color:#fff'>
                <th style='padding:7px 8px'>#</th>
                <th style='padding:7px 8px'>Hora</th>
                <th style='padding:7px 8px'>PPU</th>
                <th style='padding:7px 8px'>Conductor</th>
                <th style='padding:7px 8px'>Material</th>
                <th style='padding:7px 8px;text-align:center'>m³</th>
                <th style='padding:7px 8px'>Estado</th>
                <th style='padding:7px 8px'>Observación</th>
              </tr>
            </thead>
            <tbody>{$htmlRows}</tbody>
          </table>
          <p style='font-size:.78rem;color:#9ca3af;margin-top:16px;text-align:center'>Excel adjunto con el detalle completo &nbsp;·&nbsp; DOGROUP ".date('Y')." &nbsp;·&nbsp; Generado el ".date('d/m/Y H:i')."</p>
        </div>
      </div>";

      /* ── Enviar con SMTP ── */
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
      $mail->Subject = "Reporte Carchek {$fechaLabel} — ".count($ticketsRev)." tickets";
      $mail->Body    = $htmlBody;
      $mail->addAttachment($tmpFile, "carchek_{$fechaRep}.xlsx");

      $enviados = [];
      foreach ($destEmail as $d) {
        $mail->addAddress($d['email'], $d['nombre']);
        $enviados[] = $d['nombre'] ?: $d['email'];
      }
      $mail->send();
      @unlink($tmpFile);

      /* ── Registrar envío en BD ── */
      try {
        $pdo->prepare("INSERT INTO despacho_reporte_carchek (externo_id, fecha, json_data, enviado_a, enviado_en) VALUES (?,?,?,?,datetime('now','localtime'))")
            ->execute([$ext['id'], $fechaRep, json_encode(['total'=>count($ticketsRev),'m3'=>$totalM3,'aprobados'=>$totAprobados]), implode(', ', array_column($destEmail,'email'))]);
      } catch(Exception $e2) {}

      header("Location: despacho_externo_portal.php?tab=revision&msg=".urlencode("✅ Reporte enviado a: ".implode(', ', $enviados))."&mt=success"); exit;
    } catch(Exception $e) {
      $msg = 'Error al enviar: '.$e->getMessage(); $msgTipo = 'danger';
    }
  }
}

/* ── Buscar ticket por token ── */
$ticketBuscado = null;
$tokenBuscar   = trim($_GET['t'] ?? '');
if ($tokenBuscar) {
  $st = $pdo->prepare("SELECT * FROM tickets_despacho WHERE token=?");
  $st->execute([$tokenBuscar]);
  $ticketBuscado = $st->fetch(PDO::FETCH_ASSOC);
}

/* ── Tickets enviados pendientes de validación ── */
$stPend = $pdo->prepare("
  SELECT td.*, dp.ppu AS ppu_codigo, dp.metros_cubicos, o.codigo AS obra_codigo, o.nombre AS obra_nombre, dd.nombre AS destino_nombre
  FROM tickets_despacho td
  JOIN despacho_receptores dr ON dr.id = td.receptor_id
  LEFT JOIN despacho_ppu      dp ON dp.id = td.ppu_id
  LEFT JOIN obras             o  ON o.id  = td.obra_id
  LEFT JOIN despacho_destinos dd ON dd.id = td.destino_id
  WHERE dr.tipo = 'externo' AND dr.externo_id = ? AND td.estado = 'enviado'
  ORDER BY td.fecha DESC, td.hora DESC
");
$stPend->execute([$ext['id']]);
$pendientes = $stPend->fetchAll(PDO::FETCH_ASSOC);

/* ── Fecha seleccionada para revisión ── */
$fechaRevision = $_GET['fecha_rev'] ?? date('Y-m-d');

/* ── Tickets del día para Carchek ──────────────────────────────────
   Muestra: validados + enviados de su obra o asignados a él.
   Tres formas de pertenecer a este Carchek:
   1. receptor_id apunta a un despacho_receptores con externo_id = este Carchek
   2. revisado_por_ext_id = este Carchek
   3. obra_id = obra asignada a este Carchek
─────────────────────────────────────────────────────────────────── */
try {
    // Obtener obra_id del Carchek
    $stObExt = $pdo->prepare("SELECT obra_id FROM despacho_externos WHERE id=?");
    $stObExt->execute([$ext['id']]);
    $extObraId = (int)$stObExt->fetchColumn();

    $stRevDia = $pdo->prepare("
        SELECT td.*,
               dp.ppu    AS ppu_codigo,
               dp.metros_cubicos AS m3_camion,
               o.codigo  AS obra_codigo,
               o.nombre  AS obra_nombre_obj,
               dd.nombre AS destino_nombre
        FROM tickets_despacho td
        LEFT JOIN despacho_ppu      dp ON dp.id  = td.ppu_id
        LEFT JOIN obras             o  ON o.id   = td.obra_id
        LEFT JOIN despacho_destinos dd ON dd.id  = td.destino_id
        WHERE td.fecha = ?
          AND td.estado IN ('validado','enviado')
          AND (
                td.revisado_por_ext_id = ?
             OR EXISTS (
                  SELECT 1 FROM despacho_receptores dr
                  WHERE dr.id = td.receptor_id
                    AND dr.tipo = 'externo'
                    AND dr.externo_id = ?
               )
             OR (? > 0 AND td.obra_id = ?)
          )
        ORDER BY td.hora ASC
    ");
    $stRevDia->execute([$fechaRevision, $ext['id'], $ext['id'], $extObraId, $extObraId]);
    $ticketsDia = $stRevDia->fetchAll(PDO::FETCH_ASSOC);

    // Si no hay resultados con filtros estrictos, mostrar todos los validados de la fecha
    if (empty($ticketsDia)) {
        $stRevDia2 = $pdo->prepare("
            SELECT td.*,
                   dp.ppu AS ppu_codigo, dp.metros_cubicos AS m3_camion,
                   o.codigo AS obra_codigo, o.nombre AS obra_nombre_obj,
                   dd.nombre AS destino_nombre
            FROM tickets_despacho td
            LEFT JOIN despacho_ppu      dp ON dp.id = td.ppu_id
            LEFT JOIN obras             o  ON o.id  = td.obra_id
            LEFT JOIN despacho_destinos dd ON dd.id = td.destino_id
            WHERE td.fecha = ? AND td.estado IN ('validado','enviado')
            ORDER BY td.hora ASC
        ");
        $stRevDia2->execute([$fechaRevision]);
        $ticketsDia = $stRevDia2->fetchAll(PDO::FETCH_ASSOC);
    }
} catch(Exception $e) {
    $ticketsDia = [];
}

$totRevAprobados=0; $totRevRechazados=0; $totRevObservados=0; $totRevSinRev=0;
foreach($ticketsDia as $_td){
    if($_td['estado_revision']==='aprobado')    $totRevAprobados++;
    elseif($_td['estado_revision']==='rechazado')  $totRevRechazados++;
    elseif($_td['estado_revision']==='observacion') $totRevObservados++;
    elseif(empty($_td['estado_revision']))          $totRevSinRev++;
}

/* ── ¿Reporte ya enviado hoy? ── */
$reporteEnviado = false;
$reporteEnviadoEn = '';
try {
  $stRE = $pdo->prepare("SELECT enviado_en, destinatarios FROM despacho_reporte_carchek WHERE externo_id=? AND fecha_reporte=? ORDER BY id DESC LIMIT 1");
  $stRE->execute([$ext['id'], $fechaRevision]);
  $rowRE = $stRE->fetch(PDO::FETCH_ASSOC);
  if ($rowRE) { $reporteEnviado = true; $reporteEnviadoEn = $rowRE['enviado_en']; }
} catch(Exception $e) {}

/* ── Log de validaciones del día ── */
$logHoy = [];
try {
    $stLogH = $pdo->prepare("
        SELECT cl.*, td.estado_revision AS rev_actual
        FROM carchek_log cl
        LEFT JOIN tickets_despacho td ON td.id = cl.ticket_id
        WHERE cl.externo_id = ? AND cl.fecha = ?
        ORDER BY cl.registrado_en DESC
    ");
    $stLogH->execute([$ext['id'], $fechaRevision]);
    $logHoy = $stLogH->fetchAll(PDO::FETCH_ASSOC);

    // Fallback: construir desde tickets si el log está vacío
    if (empty($logHoy)) {
        $stLogH2 = $pdo->prepare("
            SELECT id AS ticket_id, fecha, hora, ppu, conductor_nombre, obra_nombre,
                   tipo_material, metros_cubicos, destino,
                   estado_revision, observacion_revision AS observacion,
                   COALESCE(validado_en, revisado_en, creado_en) AS registrado_en,
                   'validado' AS accion
            FROM tickets_despacho
            WHERE fecha = ? AND estado IN ('validado','enviado')
            ORDER BY COALESCE(validado_en, enviado_en) DESC
        ");
        $stLogH2->execute([$fechaRevision]);
        $logHoy = $stLogH2->fetchAll(PDO::FETCH_ASSOC);
    }
} catch(Exception $e) { $logHoy = []; }

/* ── Tab activo ── */
$tabActivo = $_GET['tab'] ?? ($tokenBuscar ? 'validar' : 'revision');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#d97706">
<title>Carchek · DOGroup</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{
  --bg:#fffbf2;--card:#fff;--dark:#1b2838;
  --amber:#d97706;--amber-light:#fef3c7;--amber-dark:#92400e;
  --success:#16a34a;--danger:#dc2626;--info:#2563eb;
  --muted:#6b7280;--border:#e5e7eb;--radius:14px;
  --bottom-h:68px;
}
html,body{margin:0;padding:0;background:var(--bg);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh;overflow-x:hidden}

/* TOPBAR */
.topbar{position:sticky;top:0;z-index:100;background:var(--amber);padding:11px 16px;display:flex;align-items:center;justify-content:space-between}
.topbar-left{display:flex;align-items:center;gap:10px}
.topbar-avatar{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;font-weight:800;flex-shrink:0}
.topbar-name{color:#fff;font-weight:800;font-size:.92rem;line-height:1.2}
.topbar-role{color:rgba(255,255,255,.7);font-size:.68rem}
.topbar-btn{color:rgba(255,255,255,.85);background:none;border:none;padding:6px;cursor:pointer;font-size:1.1rem;border-radius:8px}

/* CONTENT */
.content{padding:0 0 calc(var(--bottom-h) + 16px)}

/* HERO */
.hero{background:var(--dark);padding:16px;color:#fff}
.hero-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.hero-name{font-size:1rem;font-weight:800}
.hero-obra{font-size:.75rem;opacity:.65;margin-top:3px;display:flex;align-items:center;gap:5px}
.hero-stats{display:flex;gap:10px;margin-top:12px}
.hero-stat{background:rgba(255,255,255,.1);border-radius:10px;padding:8px 14px;text-align:center;flex:1}
.hero-stat-n{font-size:1.4rem;font-weight:900}
.hero-stat-l{font-size:.6rem;opacity:.65;text-transform:uppercase;letter-spacing:.4px;margin-top:2px}

/* ALERT BAR */
.alert-bar{margin:10px 16px 0;padding:11px 14px;border-radius:10px;font-size:.83rem;font-weight:600;display:flex;align-items:center;gap:8px}
.alert-bar.success{background:#dcfce7;color:#14532d}
.alert-bar.danger{background:#fee2e2;color:#7f1d1d}
.alert-bar.info{background:#dbeafe;color:#1e3a5f}

/* SECTION HEAD */
.section-head{display:flex;align-items:center;justify-content:space-between;padding:18px 16px 8px}
.section-title{font-size:.78rem;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}

/* TICKET CARDS (revisión) */
.ticket-list{padding:0 16px;display:flex;flex-direction:column;gap:10px}
.rev-card{background:var(--card);border-radius:var(--radius);padding:14px;box-shadow:0 1px 4px rgba(0,0,0,.06);border-left:4px solid var(--border)}
.rev-card.aprobado{border-left-color:var(--success)}
.rev-card.rechazado{border-left-color:var(--danger)}
.rev-card.observacion{border-left-color:var(--amber)}
.rev-card.sin-revisar{border-left-color:#9ca3af}
.rev-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.rev-num{font-family:monospace;font-weight:900;font-size:.85rem;color:var(--dark)}
.rev-badge{font-size:.65rem;font-weight:700;padding:3px 9px;border-radius:999px}
.badge-aprobado{background:#dcfce7;color:#14532d}
.badge-rechazado{background:#fee2e2;color:#7f1d1d}
.badge-observacion{background:#fef3c7;color:#92400e}
.badge-sinrev{background:#f3f4f6;color:#374151}
.rev-info{font-size:.8rem;color:#374151;display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}
.rev-ppu{font-family:monospace;font-weight:900;background:var(--dark);color:#fff;padding:1px 7px;border-radius:4px;font-size:.78rem;letter-spacing:1px}
.rev-actions{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px}
.rev-btn{border:none;border-radius:9px;padding:9px 6px;font-size:.72rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:4px;transition:opacity .15s}
.rev-btn:active{opacity:.75}
.btn-aprobar{background:#dcfce7;color:#14532d}
.btn-aprobar.active{background:var(--success);color:#fff}
.btn-obs{background:#fef3c7;color:#92400e}
.btn-obs.active{background:var(--amber);color:#fff}
.btn-rechazar{background:#fee2e2;color:#7f1d1d}
.btn-rechazar.active{background:var(--danger);color:#fff}
.obs-input-wrap{margin-top:8px;display:none}
.obs-input{width:100%;border:1.5px solid var(--border);border-radius:9px;padding:9px 12px;font-size:.85rem;outline:none}
.obs-input:focus{border-color:var(--amber)}
.obs-submit{width:100%;background:var(--amber);color:#fff;border:none;border-radius:9px;padding:9px;font-size:.82rem;font-weight:700;cursor:pointer;margin-top:6px}

/* GRUPO PPU (acordeón) */
.grupo-card{background:var(--card);border-radius:var(--radius);overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);margin:0 16px 10px}
.grupo-header{display:flex;align-items:center;gap:10px;padding:12px 14px;cursor:pointer;border:none;background:none;width:100%;text-align:left;flex-wrap:wrap}
.grupo-ppu{font-family:monospace;font-weight:900;font-size:1rem;background:var(--dark);color:#fff;padding:3px 10px;border-radius:6px;letter-spacing:2px}
.grupo-info{flex:1;min-width:0}
.grupo-cond{font-weight:700;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.grupo-sub{font-size:.72rem;color:var(--muted)}
.grupo-badges{display:flex;gap:4px;flex-wrap:wrap}
.g-badge{font-size:.62rem;font-weight:700;padding:2px 7px;border-radius:999px}
.g-badge-ok{background:#dcfce7;color:#14532d}
.g-badge-pend{background:#f3f4f6;color:#374151}
.g-badge-rej{background:#fee2e2;color:#7f1d1d}
.g-badge-obs{background:#fef3c7;color:#92400e}
.grupo-body{display:none;border-top:1px solid var(--border);padding:10px 14px;display:flex;flex-direction:column;gap:8px}
.grupo-body.open{display:flex}

/* ENVIAR REPORTE */
.send-bar{margin:0 16px 12px;background:var(--card);border-radius:var(--radius);padding:14px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.send-bar form button,.btn-send-rep{width:100%;background:var(--amber);color:#fff;border:none;border-radius:10px;padding:12px;font-size:.9rem;font-weight:800;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px}
.send-bar form button:active,.btn-send-rep:active{opacity:.85}

/* SCANNER APP */
.scanner-app{padding:0 16px}
.scanner-frame{background:#111;border-radius:16px;overflow:hidden;min-height:260px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;position:relative}
.scanner-label{position:absolute;bottom:12px;left:0;right:0;text-align:center;font-size:.72rem;color:rgba(255,255,255,.7);font-weight:600}
.btn-validar-grande{width:100%;padding:15px;font-size:1rem;font-weight:800;border-radius:13px;border:none;background:var(--success);color:#fff;display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;transition:opacity .15s}
.btn-validar-grande:active{opacity:.8}
.btn-validar-grande:disabled{opacity:.5}
.tk-row{display:flex;padding:8px 0;border-bottom:1px solid var(--border);font-size:.85rem;gap:10px}
.tk-lbl{color:var(--muted);font-weight:600;width:38%;flex-shrink:0;font-size:.8rem}
.tk-val{color:#212529;font-weight:500}
.paso-exito-wrap{text-align:center;padding:32px 16px}
.paso-exito-wrap .big-check{font-size:4.5rem;animation:bounceIn .5s ease}
@keyframes bounceIn{0%{transform:scale(.3);opacity:0}60%{transform:scale(1.1)}80%{transform:scale(.95)}100%{transform:scale(1);opacity:1}}
.pend-item-card{background:var(--card);border-radius:10px;padding:11px 13px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.05);margin-bottom:8px}
.pend-item-card:active{opacity:.8}

/* BOTTOM NAV */
.bottom-nav{position:fixed;bottom:0;left:0;right:0;height:var(--bottom-h);background:var(--card);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-around;padding:0 8px;z-index:200;padding-bottom:env(safe-area-inset-bottom)}
.nav-btn{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;cursor:pointer;padding:8px 4px;border-radius:10px;border:none;background:none;color:var(--muted);font-size:.6rem;font-weight:600;transition:color .15s}
.nav-btn.active{color:var(--amber)}
.nav-btn i{font-size:1.25rem}
.nav-badge{position:absolute;top:-4px;right:-4px;background:var(--danger);color:#fff;font-size:.55rem;font-weight:800;min-width:16px;height:16px;border-radius:8px;display:flex;align-items:center;justify-content:center;padding:0 3px}
.nav-icon-wrap{position:relative;display:inline-block}
</style>
</head>
<body>

<!-- TOPBAR -->
<div class="topbar">
  <div class="topbar-left">
    <div class="topbar-avatar"><?= strtoupper(substr($ext['nombre'],0,1)) ?></div>
    <div>
      <div class="topbar-name"><?= htmlspecialchars(explode(' ',$ext['nombre'])[0]) ?> · Carchek</div>
      <div class="topbar-role">DOGroup <?= $ext['empresa'] ? '· '.htmlspecialchars($ext['empresa']) : '' ?></div>
    </div>
  </div>
  <button class="topbar-btn" onclick="location.href='?logout=1'"><i class="bi bi-box-arrow-right"></i></button>
</div>

<div class="content">

  <!-- HERO -->
  <div class="hero">
    <div class="hero-top">
      <div>
        <div class="hero-name"><?= htmlspecialchars($ext['nombre']) ?></div>
        <?php if (!empty($ext['obra_info'])): ?>
        <div class="hero-obra"><i class="bi bi-building" style="font-size:14px"></i><?= htmlspecialchars($ext['obra_info']['codigo'].' — '.$ext['obra_info']['nombre']) ?></div>
        <?php endif; ?>
      </div>
      <input type="date" id="fechaPickerTop" value="<?= $fechaRevision ?>"
        onchange="location.href='?tab='+tabActivo+'&fecha_rev='+this.value"
        style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:8px;padding:6px 10px;font-size:.78rem;cursor:pointer">
    </div>
    <div class="hero-stats">
      <div class="hero-stat">
        <div class="hero-stat-n" style="color:#86efac"><?= $totRevAprobados ?></div>
        <div class="hero-stat-l">Aprobados</div>
      </div>
      <div class="hero-stat">
        <div class="hero-stat-n" style="color:#fde68a"><?= $totRevObservados ?></div>
        <div class="hero-stat-l">Con obs.</div>
      </div>
      <div class="hero-stat">
        <div class="hero-stat-n" style="color:#fca5a5"><?= $totRevRechazados ?></div>
        <div class="hero-stat-l">Rechazados</div>
      </div>
      <div class="hero-stat">
        <div class="hero-stat-n" style="color:rgba(255,255,255,.7)"><?= $totRevSinRev ?></div>
        <div class="hero-stat-l">Pendientes</div>
      </div>
    </div>
  </div>

  <?php if ($msg): ?>
  <div class="alert-bar <?= $msgTipo === 'success' ? 'success' : ($msgTipo === 'danger' ? 'danger' : 'info') ?>">
    <i class="bi bi-<?= $msgTipo==='success'?'check-circle-fill':'exclamation-circle' ?>"></i>
    <?= htmlspecialchars($msg) ?>
  </div>
  <?php endif; ?>

  <!-- ══ TAB: REVISIÓN ══ -->
  <?php if ($tabActivo === 'revision'): ?>

  <?php if (!empty($ticketsDia)): ?>
  <!-- Botón enviar reporte / exportar Excel -->
  <div class="section-head" style="padding-bottom:6px">
    <span class="section-title"><?= count($ticketsDia) ?> tickets del <?= date('d/m', strtotime($fechaRevision)) ?></span>
    <?php if ($reporteEnviado): ?>
    <span style="font-size:.72rem;color:var(--success);font-weight:700"><i class="bi bi-check-circle-fill me-1"></i>Enviado <?= date('H:i', strtotime($reporteEnviadoEn)) ?></span>
    <?php endif; ?>
  </div>
  <form id="formExportPDF" method="POST" action="despacho_externo_export.php" target="_blank" style="padding:0 16px 8px">
    <input type="hidden" name="fecha" value="<?= htmlspecialchars($fechaRevision) ?>">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button type="submit" name="solo_validados" value="1" style="flex:1;min-width:140px;background:#dc2626;color:#fff;border:0;border-radius:10px;padding:9px 12px;font-size:.78rem;font-weight:700;display:flex;align-items:center;justify-content:center;gap:6px">
        <i class="bi bi-file-earmark-pdf-fill"></i> PDF · Validados
      </button>
      <button type="button" id="btnPdfSeleccion" disabled style="flex:1;min-width:140px;background:#374151;color:#fff;border:0;border-radius:10px;padding:9px 12px;font-size:.78rem;font-weight:700;display:flex;align-items:center;justify-content:center;gap:6px;opacity:.55">
        <i class="bi bi-check2-square"></i> PDF · <span id="cntSeleccion">0</span> seleccionados
      </button>
    </div>
  </form>
  <div class="send-bar">
    <?php
      // Mostrar a quién se enviará
      $destPreview = [];
      $stDP = $pdo->query("SELECT eo.email_ext, u.nombre, u.email FROM despacho_encargado_operaciones eo LEFT JOIN usuarios u ON u.id=eo.usuario_id");
      foreach($stDP->fetchAll(PDO::FETCH_ASSOC) as $dp) {
        $em = $dp['email'] ?: $dp['email_ext'];
        if($em) $destPreview[] = $dp['nombre'] ?: $em;
      }
      $stDP2 = $pdo->query("SELECT u.nombre FROM despacho_encargado_costos ec JOIN usuarios u ON u.id=ec.usuario_id WHERE u.email != ''");
      foreach($stDP2->fetchAll(PDO::FETCH_ASSOC) as $dp) $destPreview[] = $dp['nombre'];
      $destPreview = array_unique($destPreview);
    ?>
    <?php if (!empty($destPreview)): ?>
    <div style="font-size:.75rem;color:var(--muted);margin-bottom:8px;display:flex;align-items:center;gap:6px">
      <i class="bi bi-person-check-fill" style="color:var(--success)"></i>
      Se enviará a: <strong><?= htmlspecialchars(implode(' · ', $destPreview)) ?></strong>
    </div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="accion" value="enviar_reporte_revision">
      <input type="hidden" name="fecha_reporte" value="<?= $fechaRevision ?>">
      <button type="submit">
        <i class="bi bi-send-fill" style="font-size:16px"></i>
        <?= $reporteEnviado ? 'Reenviar reporte · '.date('d/m', strtotime($fechaRevision)) : 'Enviar reporte al encargado' ?>
      </button>
    </form>
    <?php if ($reporteEnviado): ?>
    <div style="text-align:center;font-size:.72rem;color:var(--success);margin-top:6px;font-weight:600">
      <i class="bi bi-check-circle-fill me-1"></i>Último envío: <?= date('H:i', strtotime($reporteEnviadoEn)) ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Grupos por PPU -->
  <?php if (empty($ticketsDia)): ?>
  <div style="text-align:center;padding:56px 24px;color:var(--muted)">
    <div style="font-size:3rem;opacity:.3"><i class="bi bi-clipboard-x"></i></div>
    <div style="font-weight:700;margin-top:10px">Sin tickets para esta fecha</div>
    <div style="font-size:.83rem;margin-top:4px">Selecciona otra fecha arriba</div>
  </div>
  <?php else:
    $gruposRev = [];
    foreach ($ticketsDia as $t) {
      $key = ($t['ppu_codigo']?:'—').'|'.($t['conductor_nombre']?:'—');
      if (!isset($gruposRev[$key])) {
        $gruposRev[$key] = ['ppu'=>$t['ppu_codigo']?:'—','conductor'=>$t['conductor_nombre']?:'—','rut'=>$t['conductor_rut']??'','m3'=>$t['metros_cubicos']??0,'obra'=>$t['obra_codigo']?$t['obra_codigo'].' — '.$t['obra_nombre']:'','tickets'=>[]];
      }
      $gruposRev[$key]['tickets'][] = $t;
    }
    $gi = 0;
  ?>
  <div style="height:4px"></div>
  <?php foreach ($gruposRev as $grupo): $gi++;
    $gApr=$gRec=$gObs=$gSin=0;
    foreach($grupo['tickets'] as $gx){
      if($gx['estado_revision']==='aprobado') $gApr++;
      elseif($gx['estado_revision']==='rechazado') $gRec++;
      elseif($gx['estado_revision']==='observacion') $gObs++;
      else $gSin++;
    }
  ?>
  <div class="grupo-card">
    <button class="grupo-header" onclick="toggleGrupo(this,'gb<?= $gi ?>')">
      <span class="grupo-ppu"><?= htmlspecialchars($grupo['ppu']) ?></span>
      <div class="grupo-info">
        <div class="grupo-cond"><?= htmlspecialchars($grupo['conductor']) ?></div>
        <div class="grupo-sub"><?= $grupo['obra'] ? htmlspecialchars($grupo['obra']) : '' ?><?= $grupo['m3']>0?' · '.number_format($grupo['m3'],1,',','.').' m³':'' ?></div>
      </div>
      <div class="grupo-badges">
        <?php if($gApr>0): ?><span class="g-badge g-badge-ok"><?= $gApr ?> ✓</span><?php endif; ?>
        <?php if($gSin>0): ?><span class="g-badge g-badge-pend"><?= $gSin ?> pend.</span><?php endif; ?>
        <?php if($gRec>0): ?><span class="g-badge g-badge-rej"><?= $gRec ?> ✗</span><?php endif; ?>
        <?php if($gObs>0): ?><span class="g-badge g-badge-obs"><?= $gObs ?> obs.</span><?php endif; ?>
      </div>
    </button>

    <div class="grupo-body" id="gb<?= $gi ?>">
      <?php foreach ($grupo['tickets'] as $t):
        $revMap = ['aprobado'=>'aprobado','rechazado'=>'rechazado','observacion'=>'observacion'];
        $revCls = $revMap[$t['estado_revision']] ?? 'sin-revisar';
        $badgeCls = ['aprobado'=>'badge-aprobado','rechazado'=>'badge-rechazado','observacion'=>'badge-observacion'][$t['estado_revision']] ?? 'badge-sinrev';
        $badgeTxt = ['aprobado'=>'✓ Aprobado','rechazado'=>'✗ Rechazado','observacion'=>'! Obs.'][$t['estado_revision']] ?? 'Sin revisar';
      ?>
      <div class="rev-card <?= $revCls ?>">
        <div class="rev-top">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-family:monospace;font-weight:900;font-size:.85rem;color:var(--dark)">
            <input type="checkbox" class="chk-rev" data-id="<?= (int)$t['id'] ?>" style="width:16px;height:16px;cursor:pointer">
            #<?= str_pad($t['id'],5,'0',STR_PAD_LEFT) ?> · <?= substr($t['hora'],0,5) ?>
          </label>
          <span class="rev-badge <?= $badgeCls ?>"><?= $badgeTxt ?></span>
        </div>
        <div class="rev-info">
          <span style="background:#f3f4f6;padding:1px 7px;border-radius:5px;font-size:.75rem"><?= htmlspecialchars($MATERIALES[$t['tipo_material']]??$t['tipo_material']) ?></span>
          <?php if($t['metros_cubicos']>0): ?><span><?= number_format((float)$t['metros_cubicos'],1,',','.') ?> m³</span><?php endif; ?>
          <?php if($t['destino_nombre']??$t['destino']): ?><span><i class="bi bi-geo-alt" style="font-size:12px"></i><?= htmlspecialchars($t['destino_nombre']??$t['destino']) ?></span><?php endif; ?>
        </div>
        <div class="rev-actions">
          <button type="button" class="rev-btn btn-aprobar <?= $t['estado_revision']==='aprobado'?'active':'' ?>"
            onclick="setRevision(<?= $t['id'] ?>, 'aprobado', this)">
            <i class="bi bi-check-circle-fill" style="font-size:14px"></i> Aprobar
          </button>
          <button type="button" class="rev-btn btn-obs <?= $t['estado_revision']==='observacion'?'active':'' ?>"
            onclick="setRevision(<?= $t['id'] ?>, 'observacion', this)">
            <i class="bi bi-exclamation-triangle-fill" style="font-size:14px"></i> Obs.
          </button>
          <button type="button" class="rev-btn btn-rechazar <?= $t['estado_revision']==='rechazado'?'active':'' ?>"
            onclick="setRevision(<?= $t['id'] ?>, 'rechazado', this)">
            <i class="bi bi-x-circle-fill" style="font-size:14px"></i> Rechazar
          </button>
        </div>
        <div class="obs-input-wrap" id="obsWrap<?= $t['id'] ?>" style="<?= in_array($t['estado_revision'],['observacion','rechazado'])?'display:block':'' ?>">
          <input type="text" class="obs-input" id="obsInp<?= $t['id'] ?>"
            placeholder="Escribe la observación..."
            value="<?= htmlspecialchars($t['observacion_revision']??'') ?>">
          <button type="button" class="obs-submit" onclick="guardarObs(<?= $t['id'] ?>)">
            <i class="bi bi-check-lg"></i> Guardar observación
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- ══ TAB: VALIDAR QR ══ -->
  <?php elseif ($tabActivo === 'validar'): ?>

  <style>
  .btn-validar-grande{width:100%;padding:15px;font-size:1rem;font-weight:800;border-radius:13px;border:none;background:#16a34a;color:#fff;display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer}
  .btn-validar-grande:disabled{opacity:.5}
  @keyframes blink{0%,100%{opacity:1}50%{opacity:.2}}
  </style>

  <div class="scanner-app">

    <!-- PASO 1: Escáner -->
    <div id="paso-scanner">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0 8px">
        <span class="section-title" style="padding:0">Escáner Carchek</span>
        <span style="font-size:.7rem;color:var(--success);font-weight:700;display:flex;align-items:center;gap:4px">
          <span style="width:7px;height:7px;border-radius:50%;background:var(--success);display:inline-block;animation:blink 1.5s infinite"></span>Activo
        </span>
      </div>
      <div class="scanner-frame">
        <div id="carchek-reader" style="width:100%;border-radius:12px;overflow:hidden"></div>
        <div class="scanner-label">Apunta al QR de la patente del camión</div>
      </div>
      <div id="scan-status" style="text-align:center;font-size:.82rem;font-weight:600;min-height:22px;margin-bottom:10px"></div>

      <?php if (!empty($pendientes)): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 0 8px">
        <span class="section-title" style="padding:0">Pendientes</span>
        <span id="badge-pendientes" style="background:var(--danger);color:#fff;font-size:.65rem;font-weight:800;padding:2px 8px;border-radius:999px"><?= count($pendientes) ?></span>
      </div>
      <?php foreach ($pendientes as $t): ?>
      <div class="pend-item-card pend-item" data-token="<?= htmlspecialchars($t['token']) ?>" onclick="buscarPorToken(this.dataset.token)">
        <div>
          <span style="font-family:monospace;font-weight:900;background:var(--dark);color:#fff;padding:2px 8px;border-radius:5px;letter-spacing:1px;font-size:.85rem"><?= htmlspecialchars($t['ppu_codigo']?:'—') ?></span>
          <span style="background:#fef3c7;color:#92400e;font-size:.7rem;font-weight:700;padding:2px 7px;border-radius:5px;margin-left:6px"><?= htmlspecialchars($MATERIALES[$t['tipo_material']]??$t['tipo_material']) ?></span>
          <div style="font-size:.72rem;color:var(--muted);margin-top:4px"><?= date('d/m H:i',strtotime($t['fecha'].' '.$t['hora'])) ?><?= $t['conductor_nombre']?' · '.htmlspecialchars($t['conductor_nombre']):'' ?></div>
        </div>
        <i class="bi bi-chevron-right" style="color:var(--muted)"></i>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- PASO 2: Datos ticket -->
    <div id="paso-ticket" style="display:none">
      <div style="background:var(--card);border-radius:var(--radius);overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:12px">
        <div style="background:var(--amber);padding:12px 16px;color:#fff">
          <div style="font-size:.7rem;opacity:.8;text-transform:uppercase;letter-spacing:.5px">Ticket encontrado</div>
          <div style="font-weight:800;font-size:1rem" id="tk-titulo">—</div>
        </div>
        <div>
          <div class="tk-row" style="padding:8px 14px"><div class="tk-lbl">PPU</div><div class="tk-val"><strong id="tk-ppu" style="font-family:monospace;font-size:1.1rem;letter-spacing:2px">—</strong></div></div>
          <div class="tk-row" style="padding:8px 14px"><div class="tk-lbl">Conductor</div><div class="tk-val" id="tk-conductor">—</div></div>
          <div class="tk-row" style="padding:8px 14px"><div class="tk-lbl">Material</div><div class="tk-val" id="tk-material">—</div></div>
          <div class="tk-row" style="padding:8px 14px"><div class="tk-lbl">Obra</div><div class="tk-val" id="tk-obra">—</div></div>
          <div class="tk-row" style="padding:8px 14px"><div class="tk-lbl">m³</div><div class="tk-val" id="tk-m3">—</div></div>
          <div class="tk-row" style="padding:8px 14px;border:none"><div class="tk-lbl">Hora</div><div class="tk-val" id="tk-fecha">—</div></div>
        </div>
        <div style="padding:12px 14px">
          <div id="tk-estado-msg" style="margin-bottom:8px"></div>
          <div id="tk-acciones"></div>
        </div>
      </div>
      <button class="btn btn-outline-secondary w-100" style="border-radius:10px;padding:11px;font-weight:600" onclick="volverEscanear()">
        <i class="bi bi-arrow-counterclockwise me-2"></i>Cancelar — volver al escáner
      </button>
    </div>

    <!-- PASO 3: Éxito -->
    <div id="paso-exito" style="display:none">
      <div class="paso-exito-wrap">
        <div class="big-check">✅</div>
        <h2 style="font-weight:900;color:var(--success);margin:8px 0 4px">¡Validado!</h2>
        <div id="exito-info" style="color:var(--muted);font-size:.9rem;margin-bottom:10px"></div>
        <div id="exito-detalle" style="background:#dcfce7;color:#14532d;border-radius:10px;padding:10px 14px;font-size:.82rem;margin-bottom:16px"></div>
        <div style="color:var(--muted);font-size:.85rem;margin-bottom:14px">
          Volviendo al escáner en <strong id="cuenta">5</strong>s…
        </div>
        <button class="btn-validar-grande" onclick="volverEscanear()">
          <i class="bi bi-qr-code-scan" style="font-size:20px"></i> Escanear siguiente camión
        </button>
      </div>
    </div>

  </div>

  <!-- ══ TAB: REGISTRO ══ -->
  <?php elseif ($tabActivo === 'registro'): ?>

  <style>
  .log-card{background:var(--card);border-radius:12px;padding:12px 14px;box-shadow:0 1px 4px rgba(0,0,0,.06);border-left:4px solid transparent;margin-bottom:8px}
  .log-card.aprobado{border-left-color:var(--success)}
  .log-card.rechazado{border-left-color:var(--danger)}
  .log-card.observacion{border-left-color:var(--amber)}
  .log-card.validado{border-left-color:var(--info)}
  .log-hora{font-size:.72rem;color:var(--muted);font-weight:600;margin-bottom:4px}
  .log-ppu{font-family:monospace;font-weight:900;background:var(--dark);color:#fff;padding:1px 8px;border-radius:4px;letter-spacing:1px;font-size:.85rem}
  .log-badge{font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:6px}
  .log-badge.aprobado{background:#dcfce7;color:#14532d}
  .log-badge.rechazado{background:#fee2e2;color:#7f1d1d}
  .log-badge.observacion{background:#fef3c7;color:#92400e}
  .log-badge.validado{background:#dbeafe;color:#1e40af}
  .log-totales{display:flex;gap:8px;padding:0 16px;margin-bottom:12px}
  .log-tot-box{flex:1;background:var(--card);border-radius:10px;padding:10px 8px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06)}
  .log-tot-n{font-size:1.5rem;font-weight:900}
  .log-tot-l{font-size:.6rem;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.4px}
  </style>

  <div class="section-head" style="padding-bottom:8px">
    <span class="section-title">Mi registro de hoy</span>
    <span style="font-size:.78rem;color:var(--muted)"><?= date('d/m/Y', strtotime($fechaRevision)) ?></span>
  </div>

  <?php
    $lApr = count(array_filter($logHoy, fn($l) => $l['estado_revision']==='aprobado'));
    $lRec = count(array_filter($logHoy, fn($l) => $l['estado_revision']==='rechazado'));
    $lObs = count(array_filter($logHoy, fn($l) => $l['estado_revision']==='observacion'));
    $lM3  = array_sum(array_column($logHoy,'metros_cubicos'));
  ?>

  <!-- Totales del registro -->
  <div class="log-totales">
    <div class="log-tot-box">
      <div class="log-tot-n green"><?= count($logHoy) ?></div>
      <div class="log-tot-l">Total</div>
    </div>
    <div class="log-tot-box">
      <div class="log-tot-n" style="color:var(--success)"><?= $lApr ?></div>
      <div class="log-tot-l">Aprobados</div>
    </div>
    <div class="log-tot-box">
      <div class="log-tot-n" style="color:var(--danger)"><?= $lRec ?></div>
      <div class="log-tot-l">Rechazados</div>
    </div>
    <div class="log-tot-box">
      <div class="log-tot-n" style="color:var(--info)"><?= number_format($lM3,1,',','.') ?></div>
      <div class="log-tot-l">m³</div>
    </div>
  </div>

  <!-- Botón enviar reporte desde el registro -->
  <?php if (!empty($logHoy)): ?>
  <div class="send-bar" style="margin-bottom:0">
    <?php
      $destPrev2 = [];
      try {
        $stDP3 = $pdo->query("SELECT eo.email_ext, u.nombre, u.email FROM despacho_encargado_operaciones eo LEFT JOIN usuarios u ON u.id=eo.usuario_id");
        foreach($stDP3->fetchAll(PDO::FETCH_ASSOC) as $dp) { $em=$dp['email']?:$dp['email_ext']; if($em) $destPrev2[]=$dp['nombre']?:$em; }
        $stDP4 = $pdo->query("SELECT u.nombre FROM despacho_encargado_costos ec JOIN usuarios u ON u.id=ec.usuario_id WHERE u.email != ''");
        foreach($stDP4->fetchAll(PDO::FETCH_ASSOC) as $dp) $destPrev2[]=$dp['nombre'];
      } catch(Exception $e){}
      $destPrev2 = array_unique($destPrev2);
    ?>
    <?php if (!empty($destPrev2)): ?>
    <div style="font-size:.75rem;color:var(--muted);margin-bottom:8px;display:flex;align-items:center;gap:6px">
      <i class="bi bi-person-check-fill" style="color:var(--success)"></i>
      Se enviará a: <strong><?= htmlspecialchars(implode(' · ', $destPrev2)) ?></strong>
    </div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="accion" value="enviar_reporte_revision">
      <input type="hidden" name="fecha_reporte" value="<?= $fechaRevision ?>">
      <button type="submit">
        <i class="bi bi-send-fill" style="font-size:16px"></i>
        Enviar reporte del día al encargado
      </button>
    </form>
  </div>
  <?php endif; ?>

  <div class="section-head" style="padding-top:16px;padding-bottom:8px">
    <span class="section-title">Detalle</span>
    <span style="font-size:.78rem;color:var(--muted)"><?= count($logHoy) ?> registros</span>
  </div>

  <?php if (empty($logHoy)): ?>
  <div style="text-align:center;padding:48px 24px;color:var(--muted)">
    <div style="font-size:3rem;opacity:.3"><i class="bi bi-journal-x"></i></div>
    <div style="font-weight:700;margin-top:10px">Sin registros hoy</div>
    <div style="font-size:.83rem;margin-top:4px">Las validaciones que hagas aparecerán aquí</div>
  </div>
  <?php else: ?>
  <div style="padding:0 16px">
    <?php foreach ($logHoy as $lg):
      $est = $lg['estado_revision'] ?: 'validado';
      $icon = ['aprobado'=>'✅','rechazado'=>'❌','observacion'=>'⚠️','validado'=>'🔵'][$est] ?? '🔵';
      $hora = $lg['registrado_en'] ? date('H:i', strtotime($lg['registrado_en'])) : date('H:i');
      $MATERIALES_DISP = ['tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4','bajo_3'=>'Bajo 3','bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada','grava'=>'Grava','gravilla'=>'Gravilla','arena'=>'Arena','arena_tubo'=>'Arena Tubo','escombros'=>'Escombros','bolones'=>'Bolones'];
    ?>
    <div class="log-card <?= $est ?>">
      <div class="log-hora"><?= $hora ?> hrs · #<?= str_pad($lg['ticket_id'],5,'0',STR_PAD_LEFT) ?></div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span class="log-ppu"><?= htmlspecialchars($lg['ppu'] ?: '—') ?></span>
        <span class="log-badge <?= $est ?>"><?= $icon ?> <?= ucfirst($est) ?></span>
      </div>
      <div style="font-size:.82rem;color:#374151;margin-top:6px;display:flex;flex-wrap:wrap;gap:8px">
        <?php if ($lg['conductor_nombre']): ?>
        <span><i class="bi bi-person me-1" style="font-size:12px;color:var(--muted)"></i><?= htmlspecialchars($lg['conductor_nombre']) ?></span>
        <?php endif; ?>
        <?php if ($lg['tipo_material']): ?>
        <span><i class="bi bi-layers me-1" style="font-size:12px;color:var(--muted)"></i><?= htmlspecialchars($MATERIALES_DISP[$lg['tipo_material']] ?? $lg['tipo_material']) ?></span>
        <?php endif; ?>
        <?php if ($lg['metros_cubicos'] > 0): ?>
        <span><i class="bi bi-box-seam me-1" style="font-size:12px;color:var(--muted)"></i><?= number_format((float)$lg['metros_cubicos'],1,',','.') ?> m³</span>
        <?php endif; ?>
        <?php if ($lg['obra_nombre']): ?>
        <span><i class="bi bi-building me-1" style="font-size:12px;color:var(--muted)"></i><?= htmlspecialchars($lg['obra_nombre']) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($lg['observacion']): ?>
      <div style="margin-top:6px;font-size:.78rem;background:#fef3c7;color:#78350f;border-radius:6px;padding:4px 8px">
        <i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($lg['observacion']) ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ══ TAB: REPORTE ══ -->
  <?php else: ?>
  <script>location.href='reporte_despacho.php'</script>
  <?php endif; ?>

</div>

<!-- BOTTOM NAV -->
<nav class="bottom-nav">
  <button class="nav-btn <?= $tabActivo==='revision'?'active':'' ?>" onclick="location.href='?tab=revision'">
    <div class="nav-icon-wrap">
      <i class="bi bi-clipboard-check-fill"></i>
      <?php if($totRevSinRev>0): ?><span class="nav-badge"><?= $totRevSinRev ?></span><?php endif; ?>
    </div>
    Revisión
  </button>
  <button class="nav-btn <?= $tabActivo==='validar'?'active':'' ?>" onclick="location.href='?tab=validar'">
    <div class="nav-icon-wrap">
      <i class="bi bi-qr-code-scan"></i>
      <?php if(count($pendientes)>0): ?><span class="nav-badge"><?= count($pendientes) ?></span><?php endif; ?>
    </div>
    Escanear
  </button>
  <button class="nav-btn <?= $tabActivo==='registro'?'active':'' ?>" onclick="location.href='?tab=registro'">
    <div class="nav-icon-wrap">
      <i class="bi bi-journal-check"></i>
    </div>
    Registro
  </button>
  <button class="nav-btn" onclick="location.href='reporte_despacho.php'">
    <i class="bi bi-bar-chart-fill"></i>
    Reporte
  </button>
</nav>

<script>
var tabActivo = '<?= $tabActivo ?>';

/* Acordeón grupos */
function toggleGrupo(btn, id) {
  var body = document.getElementById(id);
  if (!body) return;
  var open = body.classList.contains('open');
  document.querySelectorAll('.grupo-body.open').forEach(function(b){ b.classList.remove('open'); });
  if (!open) body.classList.add('open');
}

/* Abrir primer grupo */
document.addEventListener('DOMContentLoaded', function() {
  var first = document.querySelector('.grupo-body');
  if (first) first.classList.add('open');
});

/* Revisión AJAX */
function setRevision(tid, estado, btn) {
  var card = btn.closest('.rev-card');
  card.querySelectorAll('.rev-btn').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  card.className = 'rev-card ' + estado;
  var wrap = document.getElementById('obsWrap'+tid);
  if (wrap) wrap.style.display = (estado==='observacion'||estado==='rechazado') ? 'block' : 'none';
  if (estado === 'aprobado') enviarRevision(tid, estado, '');
}

function guardarObs(tid) {
  var obs = document.getElementById('obsInp'+tid)?.value || '';
  var card = document.getElementById('obsWrap'+tid)?.closest('.rev-card');
  var est  = card?.classList.contains('rechazado') ? 'rechazado' : 'observacion';
  enviarRevision(tid, est, obs);
}

function enviarRevision(tid, estado, obs) {
  var fd = new FormData();
  fd.append('accion','revisar_ticket');
  fd.append('ticket_id', tid);
  fd.append('estado_revision', estado);
  fd.append('observacion', obs);
  fetch('despacho_externo_portal.php', { method:'POST', credentials:'same-origin', body: fd })
    .then(function(r){ return r.text(); })
    .then(function(){ /* guardado OK */ })
    .catch(function(){});
}

/* Fecha picker en el hero */
document.getElementById('fechaPickerTop')?.addEventListener('change', function() {
  location.href = '?tab='+tabActivo+'&fecha_rev='+this.value;
});
</script>

<script src="js/realtime.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var tabActual = '<?= $tabActivo ?>';
  RealtimeTickets.init({
    onUpdate: function(data) {
      // Actualizar stats del hero
      var statsMap = {
        'rt-validados': data.validados,
        'rt-enviados':  data.enviados,
        'rt-total':     data.total
      };
      Object.keys(statsMap).forEach(function(id){
        var el = document.getElementById(id);
        if (el) el.textContent = statsMap[id];
      });
      // Actualizar badge nav
      var badgePend = document.getElementById('badge-pendientes');
      if (badgePend) {
        badgePend.textContent = data.enviados || 0;
        badgePend.style.display = data.enviados > 0 ? '' : 'none';
      }
    },
    onValidado: function(data) {
      // Si estamos en el tab de validar, actualizar pendientes
      if (tabActual === 'validar' && data.pendientes) {
        var n = data.pendientes.length;
        var badge = document.getElementById('badge-pendientes');
        if (badge) { badge.textContent = n; badge.style.display = n>0?'':'none'; }
      }
    }
  });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<?php if ($tabActivo === 'validar'): ?>
<script>
/* ═══════════════════════════════════════════════════════════════
   CARCHEK — Flujo sin recarga
   PASO 1: Escáner activo
   PASO 2: Muestra datos del ticket encontrado
   PASO 3: ✅ Éxito → contador 5s → vuelve automáticamente al PASO 1
═══════════════════════════════════════════════════════════════ */

var qrScanner    = null;
var tokenActual  = '';
var cuentaTimer  = null;
var escaneando   = false;

/* ── Arranque ── */
document.addEventListener('DOMContentLoaded', function() {
  mostrarPaso('scanner');
  setTimeout(arrancarCamara, 300);
});

/* ── Cámara ── */
function arrancarCamara() {
  var el = document.getElementById('carchek-reader');
  if (!el) return;
  el.innerHTML = '';
  escaneando = false;

  if (qrScanner) {
    try { qrScanner.clear(); } catch(e) {}
  }

  try {
    qrScanner = new Html5Qrcode('carchek-reader');
  } catch(e) {
    el.innerHTML = '<div class="alert alert-warning m-3 text-center"><i class="bi bi-camera-video-off fs-3 d-block mb-2"></i>Cámara no disponible.<br><small>Usa la lista de pendientes abajo.</small></div>';
    return;
  }

  qrScanner.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 240, height: 240 } },
    function onQR(texto) {
      if (escaneando) return;
      escaneando = true;
      setStatus('🔍 QR detectado…', '#d97706');

      qrScanner.stop().catch(function(){}).finally(function() {
        procesarQR(texto);
      });
    },
    function() {}
  ).catch(function() {
    document.getElementById('carchek-reader').innerHTML =
      '<div class="alert alert-warning m-3 text-center"><i class="bi bi-camera-video-off fs-3 d-block mb-2"></i>Cámara no disponible.<br><small>Usa la lista de pendientes abajo.</small></div>';
  });
}

function detenerCamara() {
  if (qrScanner) {
    try { qrScanner.stop().catch(function(){}); } catch(e) {}
  }
}

/* ── Procesar QR escaneado ── */
function procesarQR(texto) {
  var ppu = '', tok = '';
  try {
    var url = new URL(texto);
    // ¿Es URL de validar_ppu.php? → extraer la patente del parámetro ppu
    if (url.pathname.includes('validar_ppu.php')) {
      ppu = url.searchParams.get('ppu') || '';
    } else {
      ppu = url.searchParams.get('ppu') || '';
      tok = url.searchParams.get('t')   || '';
    }
  } catch(e) {
    var t = texto.trim();
    if (/^[a-f0-9]{32}$/i.test(t)) tok = t;
    else ppu = t.toUpperCase();
  }

  if (ppu)      buscarPorPPU(ppu);
  else if (tok) buscarPorToken(tok);
  else {
    setStatus('❌ QR no reconocido', '#dc3545');
    setTimeout(volverEscanear, 2500);
  }
}

/* ── Búsquedas ── */
function buscarPorPPU(ppu) {
  setStatus('🔍 Buscando ticket para PPU ' + ppu + '…', '#d97706');
  apiPost('buscar', 'ppu=' + encodeURIComponent(ppu), function(data) {
    if (data.ok) mostrarDatosTicket(data.ticket);
    else {
      setStatus('⚠️ ' + (data.error || 'Sin ticket activo para esta PPU'), '#dc3545');
      setTimeout(volverEscanear, 3000);
    }
  });
}

function buscarPorToken(tok) {
  setStatus('🔍 Cargando ticket…', '#d97706');
  apiPost('buscar', 'token=' + encodeURIComponent(tok), function(data) {
    if (data.ok) mostrarDatosTicket(data.ticket);
    else {
      setStatus('⚠️ ' + (data.error || 'Ticket no encontrado'), '#dc3545');
      setTimeout(volverEscanear, 3000);
    }
  });
}

/* ── PASO 2: Mostrar datos del ticket ── */
function mostrarDatosTicket(t) {
  tokenActual = t.token;

  set('tk-titulo',    'Ticket #' + t.numero);
  set('tk-ppu',       t.ppu || '—');
  set('tk-conductor', t.conductor_nombre || '—');
  set('tk-material',  t.tipo_material || '—');
  set('tk-obra',      ((t.salida_nombre ? '↗ ' + t.salida_nombre + ' → ' : '') + (t.obra_nombre || '—')));
  set('tk-destino',   t.destino || '—');
  set('tk-m3',        t.metros_cubicos > 0 ? t.metros_cubicos + ' m³' : '—');
  set('tk-fecha',     t.fecha + ' ' + t.hora);

  var msgEl = ge('tk-estado-msg');
  var acEl  = ge('tk-acciones');

  if (t.estado === 'validado') {
    msgEl.innerHTML = '<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle-fill me-1"></i>Ya validado por <strong>' + esc(t.validado_por_nombre || '—') + '</strong></div>';
    acEl.innerHTML  = '<button class="btn btn-outline-secondary w-100 mt-2" onclick="volverEscanear()"><i class="bi bi-arrow-counterclockwise me-2"></i>Escanear otro camión</button>';
  } else if (t.estado === 'enviado') {
    msgEl.innerHTML = '<div class="alert alert-warning py-2 mb-0"><i class="bi bi-hourglass-split me-1"></i>Pendiente de validación</div>';
    acEl.innerHTML  = '<button class="btn-validar-grande mt-2" onclick="confirmarValidacion()"><i class="bi bi-check-circle-fill"></i> Confirmar Validación</button>';
  } else {
    msgEl.innerHTML = '<div class="alert alert-secondary py-2 mb-0">Estado: ' + esc(t.estado) + '</div>';
    acEl.innerHTML  = '<button class="btn btn-outline-secondary w-100 mt-2" onclick="volverEscanear()">Volver al escáner</button>';
  }

  mostrarPaso('ticket');
}

/* ── Confirmar validación via AJAX ── */
function confirmarValidacion() {
  if (!tokenActual) return;
  var btn = document.querySelector('#paso-ticket .btn-validar-grande');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Validando…'; }

  apiPost('validar', 'token=' + encodeURIComponent(tokenActual), function(data) {
    if (data.ok) {
      // Quitar ticket de la lista de pendientes visualmente
      var items = document.querySelectorAll('.pend-item[data-token="' + tokenActual + '"]');
      items.forEach(function(el) { el.remove(); });
      // Actualizar badge de pendientes
      var restantes = document.querySelectorAll('.pend-item').length;
      var badge = ge('badge-pendientes');
      if (badge) badge.textContent = restantes;

      mostrarExito(data.ticket);

    } else if (data.ya_validado) {
      ge('tk-estado-msg').innerHTML = '<div class="alert alert-warning py-2">' + esc(data.error) + '</div>';
      ge('tk-acciones').innerHTML   = '<button class="btn btn-outline-secondary w-100" onclick="volverEscanear()"><i class="bi bi-arrow-counterclockwise me-2"></i>Escanear otro camión</button>';
    } else {
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> Confirmar Validación'; }
      ge('tk-estado-msg').innerHTML = '<div class="alert alert-danger py-2"><i class="bi bi-x-circle me-1"></i>' + esc(data.error || 'Error al validar') + '</div>';
    }
  });
}

/* ── PASO 3: Éxito + contador regresivo ── */
function mostrarExito(t) {
  ge('exito-info').innerHTML =
    'PPU <strong style="font-family:monospace;font-size:1.1rem">' + esc(t.ppu || '') + '</strong>' +
    (t.conductor_nombre ? ' · ' + esc(t.conductor_nombre) : '') +
    '<br>Validado por <strong>' + esc(t.validado_por || '') + '</strong>';

  ge('exito-detalle').innerHTML =
    '<i class="bi bi-check-circle-fill me-1 text-success"></i>Registro guardado correctamente · ' + esc(t.validado_en || '');

  mostrarPaso('exito');

  // Contador regresivo 5s → vuelve solo al escáner
  var n = 5;
  if (cuentaTimer) clearInterval(cuentaTimer);
  set('cuenta', n);
  cuentaTimer = setInterval(function() {
    n--;
    var el = ge('cuenta');
    if (el) el.textContent = n;
    if (n <= 0) {
      clearInterval(cuentaTimer);
      cuentaTimer = null;
      volverEscanear();
    }
  }, 1000);
}

/* ── Volver al escáner ── */
function volverEscanear() {
  if (cuentaTimer) { clearInterval(cuentaTimer); cuentaTimer = null; }
  tokenActual  = '';
  escaneando   = false;
  setStatus('', '');

  mostrarPaso('scanner');
  detenerCamara();
  setTimeout(arrancarCamara, 400);
}

/* ── Cambiar entre pasos ── */
function mostrarPaso(paso) {
  ['scanner', 'ticket', 'exito'].forEach(function(p) {
    var el = ge('paso-' + p);
    if (el) el.style.display = (p === paso) ? '' : 'none';
  });
}

/* ── Utilidades ── */
function apiPost(accion, body, cb) {
  fetch('api_validar_ticket.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'accion=' + accion + '&' + body
  })
  .then(function(r) { return r.json(); })
  .then(cb)
  .catch(function() { cb({ ok: false, error: 'Error de red. Intenta de nuevo.' }); });
}

function ge(id)  { return document.getElementById(id); }
function set(id, v) { var e = ge(id); if (e) e.textContent = v; }
function esc(s)  { var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function setStatus(msg, color) { var e = ge('scan-status'); if (e) { e.textContent = msg; e.style.color = color; } }
</script>
<?php endif; ?>
<script>
(function () {
  var chks = document.querySelectorAll('.chk-rev');
  var btn = document.getElementById('btnPdfSeleccion');
  var cnt = document.getElementById('cntSeleccion');
  var form = document.getElementById('formExportPDF');
  if (!btn || !form) return;
  function recount() {
    var ids = Array.prototype.filter.call(chks, function (c) { return c.checked; }).map(function (c) { return c.dataset.id; });
    cnt.textContent = ids.length;
    btn.disabled = ids.length === 0;
    btn.style.opacity = ids.length === 0 ? '.55' : '1';
    btn.dataset.ids = ids.join(',');
  }
  Array.prototype.forEach.call(chks, function (c) { c.addEventListener('change', recount); });
  btn.addEventListener('click', function () {
    if (!btn.dataset.ids) return;
    form.querySelectorAll('input[name="ids"]').forEach(function (n) { n.remove(); });
    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'ids';
    hidden.value = btn.dataset.ids;
    form.appendChild(hidden);
    form.querySelector('button[name="solo_validados"]').name = '_disabled_solo_val';
    form.submit();
    form.querySelector('button[name="_disabled_solo_val"]').name = 'solo_validados';
  });
  recount();
})();
</script>
</body>
</html>
