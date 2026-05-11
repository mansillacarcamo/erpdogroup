<?php
require_once 'config.php';
requireAuth();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM ordenes_compra WHERE id = ?");
$stmt->execute([$id]);
$oc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$oc) { header('Location: historial.php'); exit; }

$stmtItems = $pdo->prepare("SELECT * FROM oc_items WHERE oc_id = ? ORDER BY id");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$stmtUser = $pdo->prepare("SELECT * FROM usuarios WHERE nombre = ?");
$stmtUser->execute([$oc['preparada_por']]);
$preparador = $stmtUser->fetch(PDO::FETCH_ASSOC);

$isNueva = isset($_GET['nueva']);
$moneda    = $oc['moneda'] ?: 'CLP';
$simMon    = simboloMoneda($moneda);
$tipoCambio = (float)($oc['tipo_cambio'] ?? 1) ?: 1;

// Función local de formato según moneda de esta OC
function fmtOC($v) {
  global $moneda;
  return formatMoneda($v, $moneda);
}

$stmtAprob = $pdo->prepare("SELECT a.*, u.usuario as usuario_login, u.cargo as usuario_cargo, u.ci as usuario_ci FROM oc_aprobaciones a LEFT JOIN usuarios u ON a.usuario_id = u.id WHERE a.oc_id = ? ORDER BY a.id");
$stmtAprob->execute([$id]);
$aprobaciones = $stmtAprob->fetchAll(PDO::FETCH_ASSOC);

$todasAprobadas = false;
if (count($aprobaciones) > 0) {
    $countAprobadas = 0;
    foreach ($aprobaciones as $_a) { if ($_a['estado'] === 'aprobada') $countAprobadas++; }
    $todasAprobadas = $countAprobadas === count($aprobaciones);
}
$ocAprobada = $oc['estado'] === 'aprobada';
$enRevision = $oc['estado'] === 'en_revision';

$stmtEnvios = $pdo->prepare("SELECT * FROM oc_envios WHERE oc_id = ? ORDER BY fecha DESC");
$stmtEnvios->execute([$id]);
$envios = $stmtEnvios->fetchAll(PDO::FETCH_ASSOC);

$destinatariosOC = $pdo->query("
    SELECT DISTINCT u.id, u.usuario, u.nombre, u.cargo, u.rol,
        CASE WHEN a.usuario_id IS NOT NULL THEN 1 ELSE 0 END as es_validador
    FROM usuarios u
    LEFT JOIN oc_aprobadores a ON u.id = a.usuario_id
    WHERE u.rol = 'admin' OR a.usuario_id IS NOT NULL
    ORDER BY u.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$stmtNotifOC = $pdo->prepare("SELECT n.*, u.nombre as dest_nombre FROM oc_notificaciones n JOIN usuarios u ON n.destinatario_id = u.id WHERE n.oc_id = ? ORDER BY n.fecha DESC");
$stmtNotifOC->execute([$id]);
$notifOCEnvios = $stmtNotifOC->fetchAll(PDO::FETCH_ASSOC);

$mesesEs = ['Jan'=>'ene','Feb'=>'feb','Mar'=>'mar','Apr'=>'abr','May'=>'may','Jun'=>'jun','Jul'=>'jul','Aug'=>'ago','Sep'=>'sep','Oct'=>'oct','Nov'=>'nov','Dec'=>'dic'];
function fechaCorta($fecha, $mesesEs) {
  if (!$fecha) return '';
  $f = date('d-M-y', strtotime($fecha));
  foreach ($mesesEs as $en => $es) $f = str_replace($en, $es, $f);
  return $f;
}
$fechaFmt = fechaCorta($oc['fecha'], $mesesEs);
$plazoFmt = fechaCorta($oc['plazo_entrega'] ?? '', $mesesEs);

/* Helper para obtener email; si no hay, lo arma con usuario+@dogroup.cl */
function emailUsuario($u) {
  if (!$u) return '';
  if (!empty($u['email'])) return $u['email'];
  if (!empty($u['usuario'])) return strtolower(preg_replace('/[^a-z0-9]/i','',$u['usuario'])).'@dogroup.cl';
  return '';
}
$emailPreparador = emailUsuario($preparador);

/* Aprobador a mostrar siempre en la sección APROBACIÓN.
   Prioridad:
   1) Si la OC ya está aprobada → el aprobador real.
   2) Si no, el aprobador por defecto: usuario "gerencia" / email gerencia@dogroup.cl
   3) Fallback: primer admin del sistema. */
$aprobadorDefault = null;
try {
  $stmtDef = $pdo->query("SELECT * FROM usuarios WHERE LOWER(email) = 'gerencia@dogroup.cl' OR LOWER(usuario) = 'gerencia' LIMIT 1");
  $aprobadorDefault = $stmtDef->fetch(PDO::FETCH_ASSOC);
  if (!$aprobadorDefault) {
    $aprobadorDefault = $pdo->query("SELECT * FROM usuarios WHERE rol = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
  }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>OC N° <?= $oc['numero'] ?> — DOGroup</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="css/styles.css" rel="stylesheet">
</head>
<body>
<?php if ($isNueva): ?>
<div class="alert alert-success text-center m-3 no-print"><i class="bi bi-check-circle me-2"></i>Orden de Compra N° <?= $oc['numero'] ?> creada exitosamente</div>
<?php endif; ?>
<?php if (!empty($_GET['editada'])): ?>
<div class="alert alert-success text-center m-3 no-print"><i class="bi bi-pencil-square me-2"></i>Orden de Compra N° <?= $oc['numero'] ?> editada exitosamente</div>
<?php endif; ?>

<div class="no-print text-center py-3 bg-light border-bottom">
  <a href="oc_nueva.php" class="btn btn-outline-danger me-2"><i class="bi bi-plus-circle me-1"></i>Nueva OC</a>
  <?php if (in_array($oc['estado'], ['pendiente', 'corregir'])): ?>
  <a href="editar_oc.php?id=<?= $id ?>" class="btn btn-warning me-2"><i class="bi bi-pencil-square me-1"></i>Editar OC</a>
  <?php endif; ?>
  <button onclick="imprimirOC()" class="btn btn-danger me-2"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>

  <?php if ($oc['estado'] === 'pendiente' || $oc['estado'] === 'corregir'): ?>
  <form method="POST" action="solicitar_aprobacion.php" class="d-inline">
    <input type="hidden" name="oc_id" value="<?= $id ?>">
    <button type="submit" class="btn btn-warning me-2" onclick="return confirm('¿Enviar solicitud de aprobación a los validadores?')"><i class="bi bi-send-check me-1"></i>Solicitar Aprobación</button>
  </form>
  <?php endif; ?>

</div>

<?php if (!empty($_GET['aprobacion_enviada'])): ?>
<div class="alert alert-success text-center m-3 no-print">
  <i class="bi bi-check-circle me-2"></i>Solicitud de aprobación enviada al perfil de los validadores.
</div>
<?php endif; ?>
<?php if (!empty($_GET['aprobacion_error'])): ?>
<div class="alert alert-danger text-center m-3 no-print"><i class="bi bi-exclamation-triangle me-2"></i>Error al enviar: <?= htmlspecialchars($_GET['aprobacion_error']) ?></div>
<?php endif; ?>
<?php if (!empty($_GET['envio_ok'])): ?>
<div class="alert alert-success text-center m-3 no-print"><i class="bi bi-check-circle me-2"></i>Orden de Compra enviada por correo exitosamente.</div>
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
    <div class="card-header bg-dark text-white py-2"><h6 class="mb-0"><i class="bi bi-shield-check me-2"></i>Estado de Aprobación</h6></div>
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
                <br><small class="text-muted"><i class="bi bi-person me-1"></i>Usuario: <?= htmlspecialchars($a['usuario_login'] ?? '—') ?></small>
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

  <?php if (!empty($envios)): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-success text-white py-2"><h6 class="mb-0"><i class="bi bi-envelope-check me-2"></i>Historial de Envíos al Proveedor</h6></div>
    <div class="card-body p-3">
      <?php foreach ($envios as $env): ?>
      <div class="border rounded p-2 mb-2" style="font-size:13px;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <i class="bi bi-send-fill text-success me-1"></i>
            <strong>Enviada a:</strong> <?= htmlspecialchars($env['destinatario_nombre'] ?: $env['destinatario_email']) ?>
            <small class="text-muted">(<?= htmlspecialchars($env['destinatario_email']) ?>)</small>
          </div>
          <small class="text-muted"><i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($env['fecha'])) ?></small>
        </div>
        <small class="text-muted"><i class="bi bi-person me-1"></i>Enviada por: <strong><?= htmlspecialchars($env['enviado_por']) ?></strong></small>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($notifOCEnvios)): ?>
  <div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-info text-white py-2"><h6 class="mb-0"><i class="bi bi-people me-2"></i>Envíos a Perfiles</h6></div>
    <div class="card-body p-3">
      <?php foreach ($notifOCEnvios as $noc): ?>
      <div class="border rounded p-2 mb-2" style="font-size:13px;">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <i class="bi bi-person-fill text-info me-1"></i>
            <strong>Enviado a:</strong> <?= htmlspecialchars($noc['dest_nombre']) ?>
          </div>
          <small class="text-muted"><i class="bi bi-clock me-1"></i><?= date('d/m/Y H:i', strtotime($noc['fecha'])) ?></small>
        </div>
        <small class="text-muted"><i class="bi bi-person me-1"></i>Enviado por: <strong><?= htmlspecialchars($noc['enviado_por']) ?></strong></small>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="oc-print-page">
  <!-- ENCABEZADO EMPRESA -->
  <div class="oc-empresa-header">
    <div class="oc-empresa-left">
      <div class="oc-logo"><img src="img/logo.png" alt="DOGroup"></div>
      <div class="oc-empresa-info">
        <strong class="oc-empresa-razon">DOGROUP</strong><br>
        77.930.045-5<br>
        Av. Eleuterio Ramírez 6496 Dpto. 33<br>
        Osorno<br>
        <span class="oc-subtitulo-oc">ORDEN DE COMPRA POR PRODUCTOS</span>
      </div>
    </div>
    <div class="oc-titulo-bloque">
      <div class="oc-numero-grande">N° <?= $oc['numero'] ?></div>
      <div class="oc-fecha-titulo">FECHA <?= strtoupper($fechaFmt) ?></div>
    </div>
  </div>

  <!-- DATOS PROVEEDOR -->
  <table class="table table-sm table-bordered oc-tabla-datos mb-1">
    <tr>
      <td class="td-label">SEÑORES</td>
      <td colspan="4"><?= htmlspecialchars($oc['proveedor_nombre']) ?></td>
    </tr>
    <tr>
      <td class="td-label">R.U.T:</td>
      <td><?= htmlspecialchars($oc['proveedor_rut']) ?></td>
      <td class="td-label" style="width:8%">COD</td>
      <td colspan="2"><?= htmlspecialchars($oc['obra_codigo'] ?? '') ?></td>
    </tr>
    <tr>
      <td class="td-label">DIRECCIÓN</td>
      <td colspan="4"><?= htmlspecialchars($oc['proveedor_direccion']) ?></td>
    </tr>
    <tr>
      <td class="td-label">CIUDAD</td>
      <td><?= htmlspecialchars($oc['proveedor_ciudad']) ?></td>
      <td class="td-label" colspan="2">COTIZADO POR</td>
      <td><?= htmlspecialchars($oc['cotizado_por']) ?></td>
    </tr>
    <tr>
      <td class="td-label">FONO</td>
      <td><?= htmlspecialchars($oc['proveedor_fono']) ?></td>
      <td class="td-label" colspan="2">MONEDA</td>
      <td><?= htmlspecialchars($moneda) ?><?php if ($moneda !== 'CLP' && $tipoCambio > 0): ?> <small class="text-muted">(TC: $<?= number_format($tipoCambio, 2, ',', '.') ?>)</small><?php endif; ?></td>
    </tr>
    <tr>
      <td class="td-label">CORREO</td>
      <td><?= htmlspecialchars($oc['proveedor_correo']) ?></td>
      <?php if ($moneda !== 'CLP' && $tipoCambio > 1): ?>
      <td class="td-label" colspan="2">EQUIV. CLP</td>
      <td><?= '$' . formatCLP($oc['total'] * $tipoCambio) ?></td>
      <?php else: ?>
      <td colspan="3"></td>
      <?php endif; ?>
    </tr>
    <tr>
      <td class="td-label">ATN</td>
      <td colspan="4"><?= htmlspecialchars($oc['proveedor_atencion']) ?></td>
    </tr>
  </table>

  <table class="table table-sm table-bordered oc-tabla-datos mb-2">
    <tr>
      <td class="td-label" style="width:18%">OBSERVACIONES:</td>
      <td><?= nl2br(htmlspecialchars($oc['observaciones'])) ?>&nbsp;</td>
    </tr>
    <tr>
      <td class="td-label">PLAZO DE ENTREGA :</td>
      <td><?= htmlspecialchars(strtoupper($plazoFmt)) ?>&nbsp;</td>
    </tr>
  </table>

  <!-- TABLA ITEMS -->
  <table class="table table-sm table-bordered oc-tabla-items">
    <thead>
      <tr class="oc-items-header">
        <th style="width:7%">CANTIDAD</th>
        <th style="width:7%">UNIDAD</th>
        <th>DESCRIPCIÓN</th>
        <th style="width:8%">% Dcto</th>
        <th style="width:15%">PRECIO UNITARIO</th>
        <th style="width:15%">VALOR TOTAL</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <tr>
        <td class="text-center"><?= rtrim(rtrim(number_format($item['cantidad'],2,',','.'),'0'),',') ?></td>
        <td class="text-center"><?= htmlspecialchars(mb_strtoupper((string)$item['unidad'], 'UTF-8')) ?></td>
        <td><?= htmlspecialchars($item['descripcion']) ?></td>
        <td class="text-center"><?= $item['descuento'] > 0 ? rtrim(rtrim(number_format($item['descuento'],2,',','.'),'0'),',') : '' ?></td>
        <td class="text-end"><?= $simMon ?><?= fmtOC($item['precio_unitario']) ?></td>
        <td class="text-end"><?= $simMon ?><?= fmtOC($item['valor_total']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4" rowspan="3" class="border-0"></td>
        <td class="text-end fw-bold"><?= htmlspecialchars($moneda) ?>&nbsp;&nbsp;NETO</td>
        <td class="text-end"><?= fmtOC($oc['neto']) ?></td>
      </tr>
      <tr>
        <td class="text-end fw-bold"><?= htmlspecialchars($moneda) ?>&nbsp;&nbsp;IVA 19%</td>
        <td class="text-end"><?= fmtOC($oc['iva']) ?></td>
      </tr>
      <tr class="oc-total-row">
        <td class="text-end fw-bold"><?= htmlspecialchars($moneda) ?>&nbsp;&nbsp;TOTAL</td>
        <td class="text-end fw-bold"><?= fmtOC($oc['total']) ?></td>
      </tr>
    </tfoot>
  </table>

  <div class="oc-nota mt-2">
    <strong>FAVOR INDICAR EN GUIAS DE DESPACHO EL NUMERO DE ESTA ORDEN COMPRA</strong>
  </div>

  <?php
    $aprobacionesAprobadas = [];
    foreach ($aprobaciones as $_a) { if ($_a['estado'] === 'aprobada') $aprobacionesAprobadas[] = $_a; }
    $fechaAprobacion = '';
    if (!empty($aprobacionesAprobadas)) {
      $fechas = [];
      foreach ($aprobacionesAprobadas as $_a) { $fechas[] = $_a['fecha_respuesta']; }
      sort($fechas);
      $fechaAprobacion = strtoupper(fechaCorta(end($fechas), $mesesEs));
    }
    $aprobadorReal = !empty($aprobacionesAprobadas) ? $aprobacionesAprobadas[0] : null;
    /* Datos a mostrar en la celda APROBADA POR (siempre algo) */
    $aprobNombre = $aprobadorReal ? $aprobadorReal['nombre'] : ($aprobadorDefault['nombre'] ?? 'Jonathan Maldonado');
    $aprobCargo  = $aprobadorReal
      ? ($aprobadorReal['usuario_cargo'] ?? '')
      : ($aprobadorDefault['cargo'] ?? 'Gerente General');
  ?>

  <!-- BLOQUE FINAL: APROBACIÓN | TÉRMINOS | CONFIRMACIÓN -->
  <table class="table table-sm table-bordered oc-bloque-final mb-0 mt-2">
    <thead>
      <tr>
        <th class="td-label text-center" style="width:25%">APROBACION</th>
        <th class="td-label text-center" style="width:50%">Terminos y condiciones</th>
        <th class="td-label text-center" style="width:25%">CONFIRMACION DE PEDIDO</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <!-- COLUMNA APROBACIÓN -->
        <td class="p-0 align-top">
          <table class="table table-sm mb-0 oc-aprobacion-tabla">
            <tr><td class="td-label text-center">FECHA</td></tr>
            <tr>
              <td class="text-center oc-aprob-valor" style="height:34px;">
                <?php if ($ocAprobada && $fechaAprobacion): ?>
                  <?= htmlspecialchars($fechaAprobacion) ?>
                <?php else: ?>&nbsp;<?php endif; ?>
              </td>
            </tr>

            <tr><td class="td-label text-center">PREPARADA POR</td></tr>
            <tr>
              <td class="text-center oc-aprob-valor">
                <strong><?= htmlspecialchars($oc['preparada_por']) ?></strong><br>
                <?php
                  $cargoPre = $oc['preparada_por_cargo'] ?? ($preparador['cargo'] ?? '');
                ?>
                <?php if ($cargoPre): ?>
                <small style="color:#6b7280"><?= htmlspecialchars($cargoPre) ?></small>
                <?php endif; ?>
              </td>
            </tr>

            <tr><td class="td-label text-center">APROBADA POR</td></tr>
            <tr>
              <td class="text-center oc-aprob-valor" style="min-height:48px;padding:6px 4px">
                <?php if ($ocAprobada && !empty($aprobacionesAprobadas)): ?>
                  <?php foreach ($aprobacionesAprobadas as $_apr): ?>
                  <div style="margin-bottom:4px;line-height:1.3">
                    <strong style="font-size:.85rem"><?= htmlspecialchars($_apr['nombre']) ?></strong>
                    <?php if (!empty($_apr['usuario_cargo'])): ?>
                    <br><small style="color:#6b7280;font-size:.75rem"><?= htmlspecialchars($_apr['usuario_cargo']) ?></small>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                <?php elseif (!$ocAprobada): ?>
                  <span style="color:#9ca3af;font-size:.8rem;font-style:italic">Pendiente de aprobación</span>
                <?php else: ?>
                  <strong><?= htmlspecialchars($aprobNombre) ?></strong>
                  <?php if ($aprobCargo): ?>
                  <br><small style="color:#6b7280"><?= htmlspecialchars($aprobCargo) ?></small>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>

            <tr><td class="td-label text-center">FIRMA</td></tr>
            <tr>
              <td class="text-center oc-firma-cell" style="height:90px;vertical-align:middle">
                <?php if ($ocAprobada): ?>
                <!-- Sello APROBADO -->
                <div style="display:inline-block;border:3px solid #16a34a;border-radius:8px;padding:6px 14px;transform:rotate(-8deg);margin-top:4px">
                  <div style="border:2px solid #16a34a;border-radius:5px;padding:4px 10px">
                    <div style="font-size:.65rem;font-weight:900;color:#16a34a;letter-spacing:2px;line-height:1">✓ APROBADO</div>
                    <div style="font-size:.58rem;color:#15803d;letter-spacing:.5px">DOGROUP</div>
                    <?php if ($fechaAprobacion): ?>
                    <div style="font-size:.55rem;color:#6b7280;margin-top:1px"><?= htmlspecialchars($fechaAprobacion) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
                <?php else: ?>
                <!-- Sello PENDIENTE -->
                <div style="display:inline-block;border:3px solid #d97706;border-radius:8px;padding:6px 12px;transform:rotate(-8deg);margin-top:4px;opacity:.7">
                  <div style="border:2px solid #d97706;border-radius:5px;padding:4px 8px">
                    <div style="font-size:.6rem;font-weight:900;color:#d97706;letter-spacing:1px;line-height:1">⏳ PENDIENTE</div>
                    <div style="font-size:.55rem;color:#b45309;letter-spacing:.5px">DOGROUP</div>
                  </div>
                </div>
                <?php endif; ?>
              </td>
            </tr>
          </table>
        </td>
        <td class="align-top oc-terminos">
          <ol class="mb-2 ps-3">
            <li>La 1a. Copia de esta orden de compra debe ser devuelta firmada en señal de conformidad con los términos estipulados.</li>
            <li>Las facturas deberan ajustarse tanto en cantidades como en calidad de los artículos pedidos y no se aceptaran modificaciones sin autorización escrita.</li>
            <li>Los plazos de pago de las facturas será de 45 dias a partir de la fecha de la facturación.</li>
          </ol>
          <strong>Proceso de facturación</strong>
          <ol class="mb-0 ps-3">
            <li><strong>Emisor:</strong> Deberá generar la factura basada en el respectivo estado de pago aprobado.</li>
            <li><strong>Receptor:</strong> Se recibe el documento mediante correo electrónico (finanzas@dogroup.cl, andresgodoy@dogroup.cl).</li>
            <li>Se verifica que los datos de la factura coincidan con la orden de compra, el contrato o estado de pago y la recepción conforme (Guía de despacho).</li>
            <li>Se revisan montos, impuestos y fechas de vencimiento.</li>
            <li>La factura será visada por la persona con autorizada para aprobar el gasto en la empresa.</li>
            <li>Una vez aprobada, se agenda en la finanzas según los plazos acordados.</li>
            <li>Se ejecuta el pago (transferencia electrónica).</li>
            <li>El emisor verifica la recepción de los fondos.</li>
          </ol>
        </td>

        <!-- COLUMNA CONFIRMACIÓN DE PEDIDO -->
        <td class="p-0 align-top">
          <table class="table table-sm mb-0 oc-aprobacion-tabla oc-aprobacion-confirmacion">
            <tr><td class="oc-conf-label text-center">FECHA</td></tr>
            <tr><td class="text-center oc-conf-vacio" style="height:48px;">&nbsp;</td></tr>

            <tr><td class="oc-conf-label text-center">ACEPTADO POR</td></tr>
            <tr><td class="text-center oc-conf-vacio" style="height:60px;">&nbsp;</td></tr>

            <tr><td class="oc-conf-label text-center">FIRMA ACEPTACION</td></tr>
            <tr><td class="text-center oc-conf-vacio" style="height:160px;">&nbsp;</td></tr>
          </table>
        </td>
      </tr>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="3" class="text-start oc-pie-empresa"><strong>DOGROUP</strong></td>
      </tr>
    </tfoot>
  </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>

function imprimirOC() {
  var style = document.getElementById('printPageStyle');
  if (!style) {
    style = document.createElement('style');
    style.id = 'printPageStyle';
    document.head.appendChild(style);
  }
  style.textContent = '@media print { @page { size: letter !important; margin: 0 !important; } }';
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
