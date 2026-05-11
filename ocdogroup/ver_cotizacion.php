<?php
require_once 'config.php';
requireAuth();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
$stmt->execute([$id]);
$cot = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cot) { header('Location: historial_cotizaciones.php'); exit; }

$stmtItems = $pdo->prepare("SELECT * FROM cot_items WHERE cot_id = ? ORDER BY id");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$stmtAprob = $pdo->prepare("SELECT a.*, u.usuario as usuario_login, u.cargo as usuario_cargo FROM cot_aprobaciones a LEFT JOIN usuarios u ON a.usuario_id = u.id WHERE a.cot_id = ? ORDER BY a.id");
$stmtAprob->execute([$id]);
$aprobaciones = $stmtAprob->fetchAll(PDO::FETCH_ASSOC);

$todasAprobadas = false;
if (count($aprobaciones) > 0) {
    $countAprobadas = 0;
    foreach ($aprobaciones as $_a) { if ($_a['estado'] === 'aprobada') $countAprobadas++; }
    $todasAprobadas = $countAprobadas === count($aprobaciones);
}
$cotAprobada = $cot['estado'] === 'aprobada';
$enRevision = $cot['estado'] === 'en_revision';

$aprobadoresDisponibles = $pdo->query("
    SELECT a.usuario_id, u.nombre, u.usuario, u.cargo
    FROM oc_aprobadores a
    JOIN usuarios u ON a.usuario_id = u.id
    ORDER BY u.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$stmtEnvios = $pdo->prepare("SELECT * FROM cot_envios WHERE cot_id = ? ORDER BY fecha DESC");
$stmtEnvios->execute([$id]);
$envios = $stmtEnvios->fetchAll(PDO::FETCH_ASSOC);

$destinatariosDisponibles = $pdo->query("
    SELECT DISTINCT u.id, u.usuario, u.nombre, u.cargo, u.rol,
        CASE WHEN a.usuario_id IS NOT NULL THEN 1 ELSE 0 END as es_validador
    FROM usuarios u
    LEFT JOIN oc_aprobadores a ON u.id = a.usuario_id
    WHERE u.rol = 'admin' OR a.usuario_id IS NOT NULL
    ORDER BY u.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$stmtNotifEnvios = $pdo->prepare("SELECT n.*, u.nombre as dest_nombre, u.usuario as dest_usuario FROM cot_notificaciones n JOIN usuarios u ON n.destinatario_id = u.id WHERE n.cot_id = ? ORDER BY n.fecha DESC");
$stmtNotifEnvios->execute([$id]);
$notifEnvios = $stmtNotifEnvios->fetchAll(PDO::FETCH_ASSOC);

$isNueva = isset($_GET['nueva']);

$fechaFmt = date('d-m-Y', strtotime($cot['fecha']));
$validoFmt = $cot['valido_hasta'] ? date('d-m-Y', strtotime($cot['valido_hasta'])) : '';
$monedaCot  = $cot['moneda'] ?? 'CLP';
$simMonCot  = simboloMoneda($monedaCot);
$tipoCambioCot = (float)($cot['tipo_cambio'] ?? 1) ?: 1;
function fmtCot($v) { global $monedaCot; return formatMoneda($v, $monedaCot); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cotización N° <?= $cot['numero'] ?> — DOGroup</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="css/styles.css" rel="stylesheet">
</head>
<body>
<?php if ($isNueva): ?>
<div class="alert alert-success text-center m-3 no-print"><i class="bi bi-check-circle me-2"></i>Cotización N° <?= $cot['numero'] ?> creada exitosamente</div>
<?php endif; ?>
<?php if (!empty($_GET['editada'])): ?>
<div class="alert alert-success text-center m-3 no-print"><i class="bi bi-pencil-square me-2"></i>Cotización N° <?= $cot['numero'] ?> editada exitosamente</div>
<?php endif; ?>

<div class="no-print text-center py-3 bg-light border-bottom">
  <a href="mis_aprobaciones.php" class="btn btn-outline-dark me-2" id="btnVolverInteligente" data-fallback="mis_aprobaciones.php"><i class="bi bi-arrow-left me-1"></i>Volver</a>
  <a href="cotizacion.php" class="btn btn-outline-danger me-2"><i class="bi bi-plus-circle me-1"></i>Nueva Cotización</a>
  <a href="historial_cotizaciones.php" class="btn btn-outline-secondary me-2"><i class="bi bi-journal-text me-1"></i>Historial</a>
  <a href="seguimiento_cot.php?id=<?= $id ?>" class="btn btn-outline-success me-2"><i class="bi bi-diagram-3 me-1"></i>Seguimiento</a>
  <?php if (in_array($cot['estado'], ['pendiente', 'corregir'])): ?>
  <a href="editar_cotizacion.php?id=<?= $id ?>" class="btn btn-warning me-2"><i class="bi bi-pencil-square me-1"></i>Editar Cotización</a>
  <?php endif; ?>
  <div class="d-inline-block me-2">
    <select id="paperSize" class="form-select form-select-sm d-inline-block" style="width:auto;">
      <option value="letter">Carta</option>
      <option value="oficio">Oficio</option>
    </select>
  </div>
  <button onclick="imprimirCot()" class="btn btn-danger me-2"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>
  <a href="estados_pago.php?tipo=cobro_cliente&from_cot=<?= $id ?>" class="btn btn-success me-2" title="Crear estado de pago a partir de esta cotización"><i class="bi bi-cash-coin me-1"></i>Generar Estado de Pago</a>

  <?php if ($cot['estado'] === 'pendiente' || $cot['estado'] === 'corregir'): ?>
  <?php if (!empty($aprobadoresDisponibles)): ?>
  <button class="btn btn-warning me-2" data-bs-toggle="modal" data-bs-target="#modalEnviarValidacion"><i class="bi bi-send-check me-1"></i>Enviar Validación</button>
  <?php else: ?>
  <button class="btn btn-warning me-2" disabled title="No hay validadores configurados"><i class="bi bi-send-check me-1"></i>Enviar Validación</button>
  <?php endif; ?>
  <?php endif; ?>

  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalEnviarCot"><i class="bi bi-diagram-3 me-1"></i>Enviar Estado de Proceso</button>
</div>

<?php if (!empty($_GET['aprobacion_enviada'])): ?>
<div class="alert alert-success text-center m-3 no-print">
  <i class="bi bi-check-circle me-2"></i>Solicitud de validación enviada al perfil de los validadores seleccionados.
</div>
<?php endif; ?>
<?php if (!empty($_GET['aprobacion_error'])): ?>
<div class="alert alert-danger text-center m-3 no-print"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($_GET['aprobacion_error']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['envio_ok'])): ?>
<div class="alert alert-success text-center m-3 no-print"><i class="bi bi-check-circle me-2"></i>Cotización enviada por correo exitosamente.</div>
<?php endif; ?>
<?php if (!empty($_GET['envio_error'])): ?>
<div class="alert alert-danger text-center m-3 no-print"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($_GET['envio_error']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['envio_admin_ok'])): ?>
<div class="alert alert-success text-center m-3 no-print"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($_GET['envio_admin_ok']) ?></div>
<?php endif; ?>

<?php if (count($aprobaciones) > 0): ?>
<div class="no-print" style="max-width:800px;margin:12px auto;">
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-dark text-white py-2"><h6 class="mb-0"><i class="bi bi-shield-check me-2"></i>Estado de Validación — Cotización N° <?= $cot['numero'] ?></h6></div>
    <div class="card-body p-3">
      <div class="row g-3">
        <?php foreach ($aprobaciones as $a):
          $badgeMap = ['aprobada'=>'success','rechazada'=>'danger','corregir'=>'warning'];
          $badgeClass = isset($badgeMap[$a['estado']]) ? $badgeMap[$a['estado']] : 'secondary';
          $iconMap = ['aprobada'=>'check-circle-fill','rechazada'=>'x-circle-fill','corregir'=>'pencil-fill'];
          $iconClass = isset($iconMap[$a['estado']]) ? $iconMap[$a['estado']] : 'hourglass-split';
        ?>
        <div class="col-md-6">
          <div class="border rounded p-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <div>
                <strong><?= htmlspecialchars($a['nombre']) ?></strong>
                <br><small class="text-muted"><i class="bi bi-person me-1"></i>@<?= htmlspecialchars($a['usuario_login'] ?? '—') ?> — <?= htmlspecialchars($a['usuario_cargo'] ?? '') ?></small>
              </div>
              <span class="badge bg-<?= $badgeClass ?>"><i class="bi bi-<?= $iconClass ?> me-1"></i><?= ucfirst($a['estado']) ?></span>
            </div>
            <?php if ($a['fecha_respuesta']): ?>
            <small class="text-muted"><i class="bi bi-calendar-check me-1"></i>Respondió: <?= date('d/m/Y H:i', strtotime($a['fecha_respuesta'])) ?></small>
            <?php else: ?>
            <small class="text-muted"><i class="bi bi-hourglass me-1"></i>Esperando respuesta...</small>
            <?php endif; ?>
            <?php if ($a['comentario']): ?>
            <div class="mt-2 p-2 bg-light rounded" style="font-size:12px;"><i class="bi bi-chat-text me-1"></i><?= htmlspecialchars($a['comentario']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($envios)): ?>
<div class="no-print" style="max-width:800px;margin:12px auto;">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-success text-white py-2"><h6 class="mb-0"><i class="bi bi-envelope-check me-2"></i>Historial de Envíos por Correo</h6></div>
    <div class="card-body p-3">
      <?php foreach ($envios as $env):
        $tipoEnvio = $env['contenido'] === 'proceso' ? 'Estado/Proceso' : 'Vista Previa';
        $tipoBadge = $env['contenido'] === 'proceso' ? 'info' : 'primary';
      ?>
      <div class="border rounded p-2 mb-2" style="font-size:13px;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <i class="bi bi-send-fill text-success me-1"></i>
            <strong>Enviada a:</strong> <?= htmlspecialchars($env['destinatario_nombre'] ?: $env['destinatario_email']) ?>
            <small class="text-muted">(<?= htmlspecialchars($env['destinatario_email']) ?>)</small>
            <span class="badge bg-<?= $tipoBadge ?> ms-1" style="font-size:10px;"><?= $tipoEnvio ?></span>
          </div>
          <small class="text-muted"><i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($env['fecha'])) ?></small>
        </div>
        <small class="text-muted"><i class="bi bi-person me-1"></i>Enviada por: <strong><?= htmlspecialchars($env['enviado_por']) ?></strong></small>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($notifEnvios)): ?>
<div class="no-print" style="max-width:800px;margin:12px auto;">
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-primary text-white py-2"><h6 class="mb-0"><i class="bi bi-send-fill me-2"></i>Envíos a Administradores</h6></div>
    <div class="card-body p-3">
      <?php foreach ($notifEnvios as $nf):
        $tipoEnvio = $nf['contenido'] === 'proceso' ? 'Estado/Proceso' : 'Vista Previa';
        $tipoBadge = $nf['contenido'] === 'proceso' ? 'info' : 'primary';
        $leidaBadge = $nf['leida'] ? '<span class="badge bg-success ms-1" style="font-size:10px;">Leída</span>' : '<span class="badge bg-warning text-dark ms-1" style="font-size:10px;">No leída</span>';
      ?>
      <div class="border rounded p-2 mb-2" style="font-size:13px;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <i class="bi bi-person-badge text-primary me-1"></i>
            <strong>Enviada a:</strong> <?= htmlspecialchars($nf['dest_nombre']) ?> <small class="text-muted">(@<?= htmlspecialchars($nf['dest_usuario']) ?>)</small>
            <span class="badge bg-<?= $tipoBadge ?> ms-1" style="font-size:10px;"><?= $tipoEnvio ?></span>
            <?= $leidaBadge ?>
          </div>
          <small class="text-muted"><i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($nf['fecha'])) ?></small>
        </div>
        <small class="text-muted"><i class="bi bi-person me-1"></i>Enviada por: <strong><?= htmlspecialchars($nf['enviado_por']) ?></strong></small>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="cot-print-page">
  <!-- ENCABEZADO -->
  <div class="cot-header">
    <div class="cot-header-left">
      <img src="img/logo.png" alt="DOGroup" class="cot-logo-img">
      <div class="cot-empresa-datos">
        <strong>DOGROUP</strong><br>
        Av. Eleuterio Ramírez 802, DP 33, Osorno<br>
        77.930.045-5<br>
        <?= htmlspecialchars($cot['creada_por_telefono']) ?><br>
        <?= htmlspecialchars($cot['creada_por_email']) ?>
      </div>
    </div>
    <div class="cot-header-right">
      <div class="cot-titulo-badge">COTIZACIÓN</div>
      <table class="cot-info-table">
        <tr><td class="cot-info-label">NÚMERO</td><td class="cot-info-value"><?= $cot['numero'] ?></td></tr>
        <tr><td class="cot-info-label">FECHA</td><td class="cot-info-value"><?= $fechaFmt ?></td></tr>
        <?php if ($validoFmt): ?>
        <tr><td class="cot-info-label">VÁLIDO HASTA</td><td class="cot-info-value"><?= $validoFmt ?></td></tr>
        <?php endif; ?>
        <tr><td class="cot-info-label">MONEDA</td><td class="cot-info-value"><?= htmlspecialchars($monedaCot) ?><?php if ($monedaCot !== 'CLP'): ?> <small class="text-muted">(TC: $<?= number_format($tipoCambioCot, 2, ',', '.') ?>)</small><?php endif; ?></td></tr>
      </table>
    </div>
  </div>

  <!-- DATOS CLIENTE -->
  <div class="cot-seccion">
    <div class="cot-seccion-titulo">DATOS DEL CLIENTE</div>
    <table class="table table-sm table-bordered cot-tabla-cliente mb-0">
      <tr><td class="cot-td-label">Nombre:</td><td><?= htmlspecialchars($cot['cliente_nombre']) ?></td></tr>
      <tr><td class="cot-td-label">Obra:</td><td><?= htmlspecialchars($cot['cliente_obra']) ?></td></tr>
      <tr><td class="cot-td-label">RUT:</td><td><?= htmlspecialchars($cot['cliente_rut']) ?></td></tr>
      <tr><td class="cot-td-label">Teléfono:</td><td><?= htmlspecialchars($cot['cliente_telefono']) ?></td></tr>
      <tr><td class="cot-td-label">E-mail:</td><td><?= htmlspecialchars($cot['cliente_email']) ?></td></tr>
    </table>
  </div>

  <!-- TABLA ITEMS -->
  <table class="table table-sm table-bordered cot-tabla-items">
    <thead>
      <tr class="cot-items-header">
        <th>DESCRIPCIÓN</th>
        <th style="width:8%">CANTIDAD</th>
        <th style="width:8%">UNIDADES</th>
        <th style="width:14%">PRECIO</th>
        <th style="width:14%">TOTAL</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <tr>
        <td>
          <strong><?= htmlspecialchars($item['descripcion']) ?></strong>
          <?php if (!empty($item['detalle'])): ?>
          <div class="cot-detalle-texto"><?= nl2br(htmlspecialchars($item['detalle'])) ?></div>
          <?php endif; ?>
        </td>
        <td class="text-center"><?= rtrim(rtrim(number_format($item['cantidad'],2,',','.'),'0'),',') ?></td>
        <td class="text-center"><?= htmlspecialchars(mb_strtoupper((string)$item['unidad'], 'UTF-8')) ?></td>
        <td class="text-end"><?= $simMonCot ?><?= fmtCot($item['precio']) ?></td>
        <td class="text-end"><?= $simMonCot ?><?= fmtCot($item['total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- ADICIONALES -->
  <?php if (!empty($cot['adicionales'])): ?>
  <div class="cot-adicionales">
    <strong>ADICIONALES</strong>
    <p class="mb-0"><?= nl2br(htmlspecialchars($cot['adicionales'])) ?></p>
  </div>
  <?php endif; ?>

  <!-- TOTALES -->
  <div class="cot-totales">
    <table class="cot-totales-tabla">
      <tr><td colspan="2">SUB-TOTAL</td><td class="text-end"><?= $simMonCot ?><?= fmtCot($cot['subtotal']) ?></td></tr>
      <tr><td colspan="2">IVA 19%</td><td class="text-end"><?= $simMonCot ?><?= fmtCot($cot['iva']) ?></td></tr>
      <tr class="cot-total-final"><td colspan="2">TOTAL</td><td class="text-end"><?= $simMonCot ?><?= fmtCot($cot['total']) ?></td></tr>
      <?php if ($monedaCot !== 'CLP' && $tipoCambioCot > 1): ?>
      <tr><td colspan="2" class="text-muted small">Equiv. CLP (TC: $<?= number_format($tipoCambioCot, 2, ',', '.') ?>)</td><td class="text-end text-muted small">$<?= formatCLP($cot['total'] * $tipoCambioCot) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- CONTACTO -->
  <div class="cot-contacto">
    <strong><?= htmlspecialchars(strtoupper($cot['creada_por'])) ?></strong>
    <?php if ($cot['creada_por_telefono']): ?> <?= htmlspecialchars($cot['creada_por_telefono']) ?><?php endif; ?><br>
    <?php if ($cot['creada_por_email']): ?><?= htmlspecialchars($cot['creada_por_email']) ?><?php endif; ?>
  </div>

  <!-- CONDICIONES -->
  <div class="cot-condiciones">
    <strong>Términos y condiciones</strong>
    <?php if (!empty($cot['condiciones'])): ?>
    <p class="mb-0"><?= nl2br(htmlspecialchars($cot['condiciones'])) ?></p>
    <?php endif; ?>
  </div>

  <!-- PAGO -->
  <div class="cot-pago">
    <strong>PAGO EN CUENTA:</strong><br>
    DOGROUP RUT:77.930.045-5<br>
    CUENTA CORRIENTE BANCO SANTANDER N° 94278848
  </div>

  <!-- TÉRMINOS Y CONDICIONES -->
  <div class="cot-terminos">
    <div class="cot-terminos-titulo">Términos y Condiciones de la Cotización de Movimientos de Tierra y Arriendo de Maquinaria</div>

    <div class="cot-termino-item">
      <strong>1. Objeto de la Cotización:</strong>
      La presente cotización tiene como objeto el suministro de servicios de movimientos de tierra y arriendo de maquinaria, de acuerdo con las especificaciones solicitadas por el cliente. El alcance de los servicios está sujeto a las condiciones detalladas en esta cotización.
    </div>

    <div class="cot-termino-item">
      <strong>2. Validez de la Cotización:</strong>
      La cotización tiene una validez de 30 días a partir de la fecha de emisión. Si no se confirma el servicio dentro de este plazo, la cotización podrá ser modificada o cancelada a discreción de la empresa.
    </div>

    <div class="cot-termino-item">
      <strong>3. Precios y Forma de Pago:</strong>
      Los precios indicados en esta cotización son finales y no incluyen impuestos, salvo que se indique expresamente lo contrario. Los pagos deberán realizarse según los términos acordados entre las partes, especificados en el contrato final. El cliente se compromete a realizar los pagos en los plazos establecidos. En caso de atraso en el pago, se aplicarán intereses por mora conforme a la legislación vigente.
    </div>

    <div class="cot-termino-item">
      <strong>4. Arriendo de Maquinaria:</strong>
      El arriendo de maquinaria se considera por el tiempo especificado en la cotización. Cualquier extensión del tiempo será acordada previamente entre las partes y podrá implicar cargos adicionales. El cliente se compromete a utilizar la maquinaria de acuerdo con las instrucciones de funcionamiento y en condiciones adecuadas, responsabilizándose por cualquier daño ocasionado por uso inapropiado. El mantenimiento de la maquinaria durante el período de arriendo será responsabilidad de la empresa, salvo que el daño haya sido causado por mal uso o negligencia del cliente.
    </div>

    <div class="cot-termino-item">
      <strong>5. Condiciones de Ejecución de los Servicios:</strong>
      El inicio de los trabajos está condicionado a la firma del contrato formal, recepción de los pagos iniciales y entrega de los permisos necesarios, si corresponden. Las fechas de ejecución de los trabajos se definirán de común acuerdo entre las partes, y la empresa se compromete a realizar los trabajos dentro del tiempo estimado, siempre y cuando no existan factores externos que puedan afectar la programación, tales como condiciones climáticas, imprevistos técnicos, o demoras en la entrega de permisos. El cliente deberá asegurar el acceso adecuado a los terrenos donde se realicen los trabajos. Cualquier impedimento en este acceso que retrase el proceso será considerado como un factor de fuerza mayor.
    </div>

    <div class="cot-termino-item">
      <strong>6. Responsabilidad:</strong>
      La empresa no será responsable por daños indirectos, pérdidas económicas o retrasos en la ejecución de los servicios que se deban a causas ajenas a su control, tales como condiciones meteorológicas adversas, huelgas, accidentes, u otros imprevistos. La empresa se compromete a realizar los trabajos con el debido cuidado y conforme a las normas de seguridad aplicables. Sin embargo, el cliente será responsable de los daños ocasionados a la propiedad o a terceros derivados de las condiciones del terreno o la intervención de otras partes ajenas a la empresa.
    </div>

    <div class="cot-termino-item">
      <strong>7. Modificaciones a la Cotización:</strong>
      Cualquier modificación en el alcance de los servicios, en el tipo de maquinaria a arrendar, o en los plazos establecidos deberá ser acordada por ambas partes y reflejada en un documento adicional. Estos cambios podrán generar ajustes en el precio total de la cotización.
    </div>

    <div class="cot-termino-item">
      <strong>8. Garantía de los Servicios:</strong>
      La empresa ofrece una garantía para los trabajos realizados, exclusivamente en relación con defectos de ejecución o mal funcionamiento de la maquinaria. La garantía no cubre daños por mal uso o condiciones fuera de lo especificado en el contrato.
    </div>

    <div class="cot-termino-item">
      <strong>9. Confidencialidad:</strong>
      Ambas partes se comprometen a mantener la confidencialidad de la información compartida durante la negociación y ejecución de los servicios, así como de los detalles relacionados con la cotización y los términos del contrato.
    </div>

    <div class="cot-termino-item">
      <strong>10. Resolución de Conflictos:</strong>
      Cualquier controversia que surja de la interpretación o ejecución de esta cotización será resuelta mediante negociación directa entre las partes. Si no se llegara a un acuerdo, las partes podrán recurrir a los tribunales competentes según la legislación vigente.
    </div>

    <div class="cot-termino-item">
      <strong>11. Aceptación de la Cotización:</strong>
      La aceptación de esta cotización implica la conformidad con los términos y condiciones descritos en este documento. La firma del contrato formal constituirá el acuerdo definitivo entre las partes.
    </div>

    <div class="cot-termino-item">
      <strong>12. Fuerza Mayor:</strong>
      Ninguna de las partes será responsable por el incumplimiento de sus obligaciones si este se debe a circunstancias fuera de su control razonable, como condiciones meteorológicas extremas, desastres naturales, guerras, actos de autoridades gubernamentales, etc.
    </div>
  </div>
</div>

<div class="modal fade" id="modalEnviarCot" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i>Enviar Estado de Proceso — Cot. N° <?= $cot['numero'] ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="enviar_cot_admin.php">
        <input type="hidden" name="cot_id" value="<?= $id ?>">
        <input type="hidden" name="contenido" value="proceso">
        <div class="modal-body">
          <p class="text-muted mb-3"><i class="bi bi-info-circle me-1"></i>Se enviará el estado actual de la cotización, resumen de ítems y el estado de validación al perfil del destinatario.</p>
          <div class="mb-3">
            <label class="form-label fw-semibold"><i class="bi bi-person-badge me-1"></i>Enviar a *</label>
            <?php
              $destFiltrados = array_filter($destinatariosDisponibles, function($d) use ($usuario) { return $d['id'] != $usuario['id']; });
            ?>
            <?php if (!empty($destFiltrados)): ?>
            <select name="admin_id" class="form-select" required>
              <option value="">— Seleccionar destinatario —</option>
              <?php foreach ($destFiltrados as $dest):
                $tags = [];
                if ($dest['rol'] === 'admin') $tags[] = 'Admin';
                if ($dest['es_validador']) $tags[] = 'Validador';
                $tagStr = implode(' / ', $tags);
              ?>
              <option value="<?= $dest['id'] ?>"><?= htmlspecialchars($dest['nombre']) ?> (@<?= htmlspecialchars($dest['usuario']) ?>) — <?= $tagStr ?></option>
              <?php endforeach; ?>
            </select>
            <?php else: ?>
            <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>No hay administradores o validadores disponibles.</div>
            <?php endif; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php if (!empty($destFiltrados)): ?>
          <button type="submit" class="btn btn-success" onclick="return confirm('¿Enviar estado de proceso al destinatario seleccionado?')"><i class="bi bi-send me-1"></i>Enviar</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($cot['estado'] === 'pendiente' || $cot['estado'] === 'corregir'): ?>
<div class="modal fade" id="modalEnviarValidacion" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning text-dark">
        <h5 class="modal-title"><i class="bi bi-send-check me-2"></i>Enviar Validación — Cotización N° <?= $cot['numero'] ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="solicitar_aprobacion_cot.php">
        <input type="hidden" name="cot_id" value="<?= $id ?>">
        <div class="modal-body">
          <p class="text-muted mb-3"><i class="bi bi-info-circle me-1"></i>Seleccione los validadores que deben aprobar esta cotización. <strong>Todos</strong> los seleccionados deberán aprobar para que sea válida.</p>
          <?php if (!empty($aprobadoresDisponibles)): ?>
          <div class="list-group">
            <?php foreach ($aprobadoresDisponibles as $ap): ?>
            <label class="list-group-item d-flex align-items-center gap-3">
              <input type="checkbox" name="aprobadores[]" value="<?= $ap['usuario_id'] ?>" class="form-check-input flex-shrink-0" checked>
              <div>
                <strong><?= htmlspecialchars($ap['nombre']) ?></strong>
                <br><small class="text-muted">@<?= htmlspecialchars($ap['usuario']) ?> — <?= htmlspecialchars($ap['cargo'] ?? 'Sin cargo') ?></small>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>No hay validadores configurados. El administrador debe agregarlos desde el panel de administración.</div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <?php if (!empty($aprobadoresDisponibles)): ?>
          <button type="submit" class="btn btn-warning" onclick="return confirm('¿Enviar solicitud de validación a los validadores seleccionados?')"><i class="bi bi-send-check me-1"></i>Enviar</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
  var btn = document.getElementById('btnVolverInteligente');
  if (!btn) return;
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    var fallback = btn.getAttribute('data-fallback') || 'mis_aprobaciones.php';

    if (window.history.length > 1 && document.referrer) {
      try {
        var refOrigin = new URL(document.referrer).origin;
        if (refOrigin === window.location.origin) {
          window.history.back();
          return;
        }
      } catch (err) {}
    }

    if (window.opener && !window.opener.closed) {
      try {
        window.opener.focus();
        window.close();
        setTimeout(function() {
          if (!window.closed) window.location.href = fallback;
        }, 150);
        return;
      } catch (err) {}
    }

    window.location.href = fallback;
  });
})();

function imprimirCot() {
  var size = document.getElementById('paperSize').value;
  var style = document.getElementById('printPageStyle');
  if (!style) {
    style = document.createElement('style');
    style.id = 'printPageStyle';
    document.head.appendChild(style);
  }
  if (size === 'oficio') {
    style.textContent = '@media print { @page { size: 216mm 330mm !important; margin: 0 !important; } }';
  } else {
    style.textContent = '@media print { @page { size: letter !important; margin: 0 !important; } }';
  }
  var originalTitle = document.title;
  document.title = ' ';
  setTimeout(function() {
    window.print();
    setTimeout(function() { document.title = originalTitle; }, 500);
  }, 100);
}
</script>
</body>
</html>
