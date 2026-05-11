<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/smtp_config.php';
use PHPMailer\PHPMailer\PHPMailer;

function enviarEmailAprobacion($pdo, $oc, $items, $aprobador, $token) {
    global $SMTP_CONFIG, $SITE_URL;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $SMTP_CONFIG['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $SMTP_CONFIG['user'];
        $mail->Password = $SMTP_CONFIG['pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $SMTP_CONFIG['port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($SMTP_CONFIG['from_email'], $SMTP_CONFIG['from_name']);
        $mail->addAddress($aprobador['email'], $aprobador['nombre']);
        $mail->isHTML(true);
        $mail->Subject = "OC N° {$oc['numero']} - Requiere su aprobación";

        $urlAprobar = "{$SITE_URL}/aprobar_oc.php?token={$token}";

        $itemsHtml = '';
        foreach ($items as $item) {
            $itemsHtml .= "<tr>
                <td style='padding:6px 8px;border:1px solid #ddd;text-align:center;'>{$item['cantidad']}</td>
                <td style='padding:6px 8px;border:1px solid #ddd;text-align:center;'>" . htmlspecialchars(mb_strtoupper((string)$item['unidad'], 'UTF-8')) . "</td>
                <td style='padding:6px 8px;border:1px solid #ddd;'>" . htmlspecialchars($item['descripcion']) . "</td>
                <td style='padding:6px 8px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($item['precio_unitario']) . "</td>
                <td style='padding:6px 8px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($item['valor_total']) . "</td>
            </tr>";
        }

        $mail->Body = "
        <div style='font-family:Arial,sans-serif;max-width:650px;margin:0 auto;'>
            <div style='background:#d97706;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;'>
                <h2 style='margin:0;font-size:18px;'>Orden de Compra N° {$oc['numero']}</h2>
                <p style='margin:4px 0 0;opacity:0.9;font-size:13px;'>Requiere su aprobación</p>
            </div>
            <div style='background:#fff;padding:20px;border:1px solid #ddd;border-top:none;'>
                <p style='font-size:14px;'>Estimado/a <strong>" . htmlspecialchars($aprobador['nombre']) . "</strong>,</p>
                <p style='font-size:13px;color:#555;'>Se ha generado una nueva Orden de Compra que requiere su validación:</p>

                <table style='width:100%;border-collapse:collapse;margin:12px 0;font-size:12px;'>
                    <tr><td style='padding:6px;background:#fef3c7;font-weight:bold;width:30%;border:1px solid #ddd;'>Proveedor:</td><td style='padding:6px;border:1px solid #ddd;'>" . htmlspecialchars($oc['proveedor_nombre']) . "</td></tr>
                    <tr><td style='padding:6px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Obra:</td><td style='padding:6px;border:1px solid #ddd;'>" . htmlspecialchars($oc['obra']) . "</td></tr>
                    <tr><td style='padding:6px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Fecha:</td><td style='padding:6px;border:1px solid #ddd;'>" . date('d/m/Y', strtotime($oc['fecha'])) . "</td></tr>
                    <tr><td style='padding:6px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Preparada por:</td><td style='padding:6px;border:1px solid #ddd;'>" . htmlspecialchars($oc['preparada_por']) . "</td></tr>
                </table>

                <h3 style='font-size:13px;color:#1a1a2e;margin:14px 0 8px;'>Detalle de ítems:</h3>
                <table style='width:100%;border-collapse:collapse;font-size:11px;'>
                    <thead><tr style='background:#1a1a2e;color:#fff;'>
                        <th style='padding:6px;border:1px solid #333;'>Cant.</th>
                        <th style='padding:6px;border:1px solid #333;'>Unidad</th>
                        <th style='padding:6px;border:1px solid #333;'>Descripción</th>
                        <th style='padding:6px;border:1px solid #333;'>P. Unit.</th>
                        <th style='padding:6px;border:1px solid #333;'>Total</th>
                    </tr></thead>
                    <tbody>{$itemsHtml}</tbody>
                    <tfoot>
                        <tr><td colspan='3'></td><td style='padding:6px;border:1px solid #ddd;font-weight:bold;text-align:right;'>NETO</td><td style='padding:6px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($oc['neto']) . "</td></tr>
                        <tr><td colspan='3'></td><td style='padding:6px;border:1px solid #ddd;font-weight:bold;text-align:right;'>IVA 19%</td><td style='padding:6px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($oc['iva']) . "</td></tr>
                        <tr style='background:#fef3c7;'><td colspan='3'></td><td style='padding:6px;border:1px solid #ddd;font-weight:bold;text-align:right;font-size:13px;'>TOTAL</td><td style='padding:6px;border:1px solid #ddd;font-weight:bold;text-align:right;font-size:13px;'>$" . formatCLP($oc['total']) . "</td></tr>
                    </tfoot>
                </table>

                <div style='text-align:center;margin:24px 0 16px;'>
                    <p style='font-size:13px;color:#555;margin-bottom:16px;'>Haga clic en una opción para responder:</p>
                    <a href='{$urlAprobar}' style='display:inline-block;background:#16a34a;color:#fff;padding:12px 30px;border-radius:6px;text-decoration:none;font-weight:bold;font-size:14px;margin:0 6px;'>Revisar y Aprobar</a>
                </div>

                <p style='font-size:11px;color:#999;text-align:center;margin-top:20px;'>
                    Este enlace es personal y exclusivo. No lo comparta.<br>
                    DOGROUP
                </p>
            </div>
        </div>";

        $mail->send();
        return true;
    } catch (\Exception $e) {
        return $e->getMessage();
    }
}

function enviarOCPorCorreo($pdo, $oc, $items, $destinatario_email, $destinatario_nombre = '') {
    global $SMTP_CONFIG;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $SMTP_CONFIG['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $SMTP_CONFIG['user'];
        $mail->Password = $SMTP_CONFIG['pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $SMTP_CONFIG['port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($SMTP_CONFIG['from_email'], $SMTP_CONFIG['from_name']);
        $mail->addAddress($destinatario_email, $destinatario_nombre);
        $mail->isHTML(true);
        $mail->Subject = "Orden de Compra N° {$oc['numero']} - DOGroup";

        $itemsHtml = '';
        foreach ($items as $item) {
            $itemsHtml .= "<tr>
                <td style='padding:5px 6px;border:1px solid #ddd;text-align:center;'>{$item['cantidad']}</td>
                <td style='padding:5px 6px;border:1px solid #ddd;text-align:center;'>" . htmlspecialchars(mb_strtoupper((string)$item['unidad'], 'UTF-8')) . "</td>
                <td style='padding:5px 6px;border:1px solid #ddd;'>" . htmlspecialchars($item['descripcion']) . "</td>
                <td style='padding:5px 6px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($item['precio_unitario']) . "</td>
                <td style='padding:5px 6px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($item['valor_total']) . "</td>
            </tr>";
        }

        $mail->Body = "
        <div style='font-family:Arial,sans-serif;max-width:650px;margin:0 auto;'>
            <div style='background:#d97706;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;'>
                <h2 style='margin:0;'>Orden de Compra N° {$oc['numero']}</h2>
            </div>
            <div style='background:#fff;padding:20px;border:1px solid #ddd;'>
                <table style='width:100%;border-collapse:collapse;font-size:12px;margin-bottom:14px;'>
                    <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Proveedor:</td><td style='padding:5px;border:1px solid #ddd;'>" . htmlspecialchars($oc['proveedor_nombre']) . "</td></tr>
                    <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>RUT:</td><td style='padding:5px;border:1px solid #ddd;'>" . htmlspecialchars($oc['proveedor_rut']) . "</td></tr>
                    <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Obra:</td><td style='padding:5px;border:1px solid #ddd;'>" . htmlspecialchars($oc['obra']) . "</td></tr>
                    <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Fecha:</td><td style='padding:5px;border:1px solid #ddd;'>" . date('d/m/Y', strtotime($oc['fecha'])) . "</td></tr>
                </table>
                <table style='width:100%;border-collapse:collapse;font-size:11px;'>
                    <thead><tr style='background:#1a1a2e;color:#fff;'>
                        <th style='padding:5px;border:1px solid #333;'>Cant.</th>
                        <th style='padding:5px;border:1px solid #333;'>Unidad</th>
                        <th style='padding:5px;border:1px solid #333;'>Descripción</th>
                        <th style='padding:5px;border:1px solid #333;'>P. Unit.</th>
                        <th style='padding:5px;border:1px solid #333;'>Total</th>
                    </tr></thead>
                    <tbody>{$itemsHtml}</tbody>
                    <tfoot>
                        <tr><td colspan='3'></td><td style='padding:5px;border:1px solid #ddd;font-weight:bold;text-align:right;'>NETO</td><td style='padding:5px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($oc['neto']) . "</td></tr>
                        <tr><td colspan='3'></td><td style='padding:5px;border:1px solid #ddd;font-weight:bold;text-align:right;'>IVA</td><td style='padding:5px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($oc['iva']) . "</td></tr>
                        <tr style='background:#fef3c7;'><td colspan='3'></td><td style='padding:5px;border:1px solid #ddd;font-weight:bold;text-align:right;'>TOTAL</td><td style='padding:5px;border:1px solid #ddd;font-weight:bold;text-align:right;'>$" . formatCLP($oc['total']) . "</td></tr>
                    </tfoot>
                </table>
                <p style='font-size:11px;color:#555;margin-top:14px;'>Favor indicar en guías de despacho el número de esta orden de compra.</p>
                <p style='font-size:11px;color:#999;text-align:center;margin-top:16px;'>DOGROUP<br>77.930.045-5</p>
            </div>
        </div>";

        $mail->send();
        return true;
    } catch (\Exception $e) {
        return $e->getMessage();
    }
}

function enviarNotifValidadorOC($pdo, $oc, $validadorEmail, $validadorNombre) {
    global $SMTP_CONFIG, $SITE_URL;
    if (empty($validadorEmail) || empty($SMTP_CONFIG)) return 'Sin configuración SMTP o email vacío';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $SMTP_CONFIG['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $SMTP_CONFIG['user'];
        $mail->Password = $SMTP_CONFIG['pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $SMTP_CONFIG['port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($SMTP_CONFIG['from_email'], $SMTP_CONFIG['from_name']);
        $mail->addAddress($validadorEmail, $validadorNombre);
        $mail->isHTML(true);
        $mail->Subject = "Tiene una OC pendiente de validación — N° {$oc['numero']}";

        $mail->Body = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
            <div style='background:#d97706;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;'>
                <h2 style='margin:0;font-size:18px;'>Orden de Compra N° {$oc['numero']}</h2>
                <p style='margin:4px 0 0;opacity:0.9;font-size:13px;'>Requiere su validación</p>
            </div>
            <div style='background:#fff;padding:20px;border:1px solid #ddd;border-top:none;border-radius:0 0 8px 8px;'>
                <p>Estimado/a <strong>" . htmlspecialchars($validadorNombre) . "</strong>,</p>
                <p>Se le ha asignado la validación de la siguiente Orden de Compra:</p>
                <table style='width:100%;border-collapse:collapse;font-size:13px;margin:12px 0;'>
                    <tr><td style='padding:6px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;width:35%;'>Proveedor:</td><td style='padding:6px;border:1px solid #ddd;'>" . htmlspecialchars($oc['proveedor_nombre']) . "</td></tr>
                    <tr><td style='padding:6px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Obra:</td><td style='padding:6px;border:1px solid #ddd;'>" . htmlspecialchars($oc['obra']) . "</td></tr>
                    <tr><td style='padding:6px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Fecha:</td><td style='padding:6px;border:1px solid #ddd;'>" . date('d/m/Y', strtotime($oc['fecha'])) . "</td></tr>
                    <tr><td style='padding:6px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Total:</td><td style='padding:6px;border:1px solid #ddd;font-weight:bold;font-size:15px;'>$" . formatCLP($oc['total']) . "</td></tr>
                    <tr><td style='padding:6px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Preparada por:</td><td style='padding:6px;border:1px solid #ddd;'>" . htmlspecialchars($oc['preparada_por']) . "</td></tr>
                </table>
                <p style='font-size:13px;'>Ingrese al sistema para revisar y aprobar o rechazar esta orden.</p>
                <p style='font-size:11px;color:#999;text-align:center;margin-top:20px;'>DOGROUP — 77.930.045-5</p>
            </div>
        </div>";

        $mail->send();
        return true;
    } catch (\Exception $e) {
        return $e->getMessage();
    }
}

function enviarNotifValidadorCot($pdo, $cot, $validadorEmail, $validadorNombre) {
    global $SMTP_CONFIG, $SITE_URL;
    if (empty($validadorEmail) || empty($SMTP_CONFIG)) return 'Sin configuración SMTP o email vacío';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $SMTP_CONFIG['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $SMTP_CONFIG['user'];
        $mail->Password = $SMTP_CONFIG['pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $SMTP_CONFIG['port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($SMTP_CONFIG['from_email'], $SMTP_CONFIG['from_name']);
        $mail->addAddress($validadorEmail, $validadorNombre);
        $mail->isHTML(true);
        $mail->Subject = "Tiene una Cotización pendiente de validación — N° {$cot['numero']}";

        $mail->Body = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
            <div style='background:#0d6efd;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;'>
                <h2 style='margin:0;font-size:18px;'>Cotización N° {$cot['numero']}</h2>
                <p style='margin:4px 0 0;opacity:0.9;font-size:13px;'>Requiere su validación</p>
            </div>
            <div style='background:#fff;padding:20px;border:1px solid #ddd;border-top:none;border-radius:0 0 8px 8px;'>
                <p>Estimado/a <strong>" . htmlspecialchars($validadorNombre) . "</strong>,</p>
                <p>Se le ha asignado la validación de la siguiente Cotización:</p>
                <table style='width:100%;border-collapse:collapse;font-size:13px;margin:12px 0;'>
                    <tr><td style='padding:6px;background:#cfe2ff;font-weight:bold;border:1px solid #ddd;width:35%;'>Cliente:</td><td style='padding:6px;border:1px solid #ddd;'>" . htmlspecialchars($cot['cliente_nombre']) . "</td></tr>
                    <tr><td style='padding:6px;background:#cfe2ff;font-weight:bold;border:1px solid #ddd;'>Obra:</td><td style='padding:6px;border:1px solid #ddd;'>" . htmlspecialchars($cot['cliente_obra']) . "</td></tr>
                    <tr><td style='padding:6px;background:#cfe2ff;font-weight:bold;border:1px solid #ddd;'>Fecha:</td><td style='padding:6px;border:1px solid #ddd;'>" . date('d/m/Y', strtotime($cot['fecha'])) . "</td></tr>
                    <tr><td style='padding:6px;background:#cfe2ff;font-weight:bold;border:1px solid #ddd;'>Total:</td><td style='padding:6px;border:1px solid #ddd;font-weight:bold;font-size:15px;'>$" . formatCLP($cot['total']) . "</td></tr>
                    <tr><td style='padding:6px;background:#cfe2ff;font-weight:bold;border:1px solid #ddd;'>Creada por:</td><td style='padding:6px;border:1px solid #ddd;'>" . htmlspecialchars($cot['creada_por']) . "</td></tr>
                </table>
                <p style='font-size:13px;'>Ingrese al sistema para revisar y aprobar o rechazar esta cotización.</p>
                <p style='font-size:11px;color:#999;text-align:center;margin-top:20px;'>DOGROUP — 77.930.045-5</p>
            </div>
        </div>";

        $mail->send();
        return true;
    } catch (\Exception $e) {
        return $e->getMessage();
    }
}

function enviarCotPorCorreo($pdo, $cot, $items, $destinatario_email, $destinatario_nombre, $contenido) {
    global $SMTP_CONFIG;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $SMTP_CONFIG['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $SMTP_CONFIG['user'];
        $mail->Password = $SMTP_CONFIG['pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $SMTP_CONFIG['port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($SMTP_CONFIG['from_email'], $SMTP_CONFIG['from_name']);
        $mail->addAddress($destinatario_email, $destinatario_nombre);
        $mail->isHTML(true);

        if ($contenido === 'proceso') {
            $mail->Subject = "Estado de Cotización N° {$cot['numero']} - DOGroup";
            $mail->Body = generarEmailProcesoCot($pdo, $cot, $items);
        } else {
            $mail->Subject = "Cotización N° {$cot['numero']} - DOGroup";
            $mail->Body = generarEmailCotizacion($cot, $items);
        }

        $mail->send();
        return true;
    } catch (\Exception $e) {
        return $e->getMessage();
    }
}

function generarEmailCotizacion($cot, $items) {
    $itemsHtml = '';
    foreach ($items as $item) {
        $detalle = !empty($item['detalle']) ? "<br><span style='font-size:10px;color:#666;'>" . htmlspecialchars($item['detalle']) . "</span>" : '';
        $itemsHtml .= "<tr>
            <td style='padding:5px 6px;border:1px solid #ddd;'><strong>" . htmlspecialchars($item['descripcion']) . "</strong>{$detalle}</td>
            <td style='padding:5px 6px;border:1px solid #ddd;text-align:center;'>" . rtrim(rtrim(number_format((float)$item['cantidad'], 2, ',', '.'), '0'), ',') . "</td>
            <td style='padding:5px 6px;border:1px solid #ddd;text-align:center;'>" . htmlspecialchars(mb_strtoupper((string)$item['unidad'], 'UTF-8')) . "</td>
            <td style='padding:5px 6px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($item['precio']) . "</td>
            <td style='padding:5px 6px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($item['total']) . "</td>
        </tr>";
    }

    $validoHasta = $cot['valido_hasta'] ? date('d/m/Y', strtotime($cot['valido_hasta'])) : '';

    return "
    <div style='font-family:Arial,sans-serif;max-width:650px;margin:0 auto;'>
        <div style='background:#d97706;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;'>
            <h2 style='margin:0;'>Cotización N° {$cot['numero']}</h2>
            <p style='margin:4px 0 0;opacity:0.9;font-size:13px;'>Fecha: " . date('d/m/Y', strtotime($cot['fecha'])) . ($validoHasta ? " | Válido hasta: {$validoHasta}" : "") . "</p>
        </div>
        <div style='background:#fff;padding:20px;border:1px solid #ddd;border-top:none;'>
            <table style='width:100%;border-collapse:collapse;font-size:12px;margin-bottom:14px;'>
                <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Cliente:</td><td style='padding:5px;border:1px solid #ddd;'>" . htmlspecialchars($cot['cliente_nombre']) . "</td></tr>
                <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Obra:</td><td style='padding:5px;border:1px solid #ddd;'>" . htmlspecialchars($cot['cliente_obra']) . "</td></tr>
                <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>RUT:</td><td style='padding:5px;border:1px solid #ddd;'>" . htmlspecialchars($cot['cliente_rut']) . "</td></tr>
                <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Cotizada por:</td><td style='padding:5px;border:1px solid #ddd;'>" . htmlspecialchars($cot['creada_por']) . "</td></tr>
            </table>

            <h3 style='font-size:13px;color:#1a1a2e;margin:14px 0 8px;'>Detalle de ítems:</h3>
            <table style='width:100%;border-collapse:collapse;font-size:11px;'>
                <thead><tr style='background:#1a1a2e;color:#fff;'>
                    <th style='padding:5px;border:1px solid #333;'>Descripción</th>
                    <th style='padding:5px;border:1px solid #333;'>Cant.</th>
                    <th style='padding:5px;border:1px solid #333;'>Unidad</th>
                    <th style='padding:5px;border:1px solid #333;'>Precio</th>
                    <th style='padding:5px;border:1px solid #333;'>Total</th>
                </tr></thead>
                <tbody>{$itemsHtml}</tbody>
                <tfoot>
                    <tr><td colspan='3'></td><td style='padding:5px;border:1px solid #ddd;font-weight:bold;text-align:right;'>SUB-TOTAL</td><td style='padding:5px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($cot['subtotal']) . "</td></tr>
                    <tr><td colspan='3'></td><td style='padding:5px;border:1px solid #ddd;font-weight:bold;text-align:right;'>IVA 19%</td><td style='padding:5px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($cot['iva']) . "</td></tr>
                    <tr style='background:#fef3c7;'><td colspan='3'></td><td style='padding:5px;border:1px solid #ddd;font-weight:bold;text-align:right;font-size:13px;'>TOTAL</td><td style='padding:5px;border:1px solid #ddd;font-weight:bold;text-align:right;font-size:13px;'>$" . formatCLP($cot['total']) . "</td></tr>
                </tfoot>
            </table>

            " . (!empty($cot['adicionales']) ? "<div style='margin-top:12px;padding:8px;background:#f9f9f9;border:1px solid #eee;font-size:12px;'><strong>Adicionales:</strong><br>" . nl2br(htmlspecialchars($cot['adicionales'])) . "</div>" : "") . "
            " . (!empty($cot['condiciones']) ? "<div style='margin-top:8px;padding:8px;background:#f9f9f9;border:1px solid #eee;font-size:12px;'><strong>Condiciones:</strong><br>" . nl2br(htmlspecialchars($cot['condiciones'])) . "</div>" : "") . "

            <div style='margin-top:16px;padding:10px;background:#fef3c7;border-radius:6px;font-size:12px;text-align:center;'>
                <strong>PAGO EN CUENTA:</strong><br>
                DOGROUP — RUT: 77.930.045-5<br>
                CUENTA CORRIENTE BANCO SANTANDER N° 94278848
            </div>

            <p style='font-size:11px;color:#999;text-align:center;margin-top:16px;'>DOGROUP<br>77.930.045-5<br>Av. Eleuterio Ramírez 802, DP 33, Osorno</p>
        </div>
    </div>";
}

function generarEmailProcesoCot($pdo, $cot, $items) {
    $stmtAprob = $pdo->prepare("SELECT a.*, u.cargo as usuario_cargo FROM cot_aprobaciones a LEFT JOIN usuarios u ON a.usuario_id = u.id WHERE a.cot_id = ? ORDER BY a.id");
    $stmtAprob->execute([$cot['id']]);
    $aprobaciones = $stmtAprob->fetchAll(PDO::FETCH_ASSOC);

    $estadoBadge = [
        'pendiente' => ['#6c757d', 'Pendiente'],
        'en_revision' => ['#0dcaf0', 'En Revisión'],
        'aprobada' => ['#198754', 'Aprobada'],
        'rechazada' => ['#dc3545', 'Rechazada'],
        'corregir' => ['#ffc107', 'Corrección'],
        'propuesta' => ['#0d6efd', 'Propuesta'],
        'negociacion' => ['#0d6efd', 'Negociación'],
        'adjudicada' => ['#198754', 'Adjudicada'],
        'desierta' => ['#6c757d', 'Desierta'],
        'cerrado' => ['#212529', 'Cerrado'],
    ];

    $estInfo = $estadoBadge[$cot['estado']] ?? ['#6c757d', ucfirst($cot['estado'])];

    $itemsHtml = '';
    foreach ($items as $item) {
        $itemsHtml .= "<tr>
            <td style='padding:5px 6px;border:1px solid #ddd;'>" . htmlspecialchars($item['descripcion']) . "</td>
            <td style='padding:5px 6px;border:1px solid #ddd;text-align:center;'>" . rtrim(rtrim(number_format((float)$item['cantidad'], 2, ',', '.'), '0'), ',') . "</td>
            <td style='padding:5px 6px;border:1px solid #ddd;text-align:right;'>$" . formatCLP($item['total']) . "</td>
        </tr>";
    }

    $aprobHtml = '';
    if (!empty($aprobaciones)) {
        $aprobHtml = "<h3 style='font-size:13px;color:#1a1a2e;margin:16px 0 8px;'>Estado de Validación:</h3>";
        foreach ($aprobaciones as $a) {
            $aInfo = $estadoBadge[$a['estado']] ?? ['#6c757d', ucfirst($a['estado'])];
            $fecha = $a['fecha_respuesta'] ? date('d/m/Y H:i', strtotime($a['fecha_respuesta'])) : 'Esperando...';
            $comentario = $a['comentario'] ? "<br><span style='color:#666;font-style:italic;'>\"" . htmlspecialchars($a['comentario']) . "\"</span>" : '';
            $aprobHtml .= "<div style='padding:8px 10px;margin-bottom:6px;border:1px solid #ddd;border-left:4px solid {$aInfo[0]};border-radius:4px;font-size:12px;'>
                <strong>" . htmlspecialchars($a['nombre']) . "</strong>
                <span style='display:inline-block;background:{$aInfo[0]};color:#fff;padding:1px 8px;border-radius:10px;font-size:10px;margin-left:6px;'>{$aInfo[1]}</span>
                <br><small style='color:#888;'>{$fecha}</small>
                {$comentario}
            </div>";
        }
    }

    return "
    <div style='font-family:Arial,sans-serif;max-width:650px;margin:0 auto;'>
        <div style='background:#1a1a2e;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;'>
            <h2 style='margin:0;font-size:18px;'>Estado de Cotización N° {$cot['numero']}</h2>
            <p style='margin:4px 0 0;opacity:0.9;font-size:13px;'>Reporte de proceso y validación</p>
        </div>
        <div style='background:#fff;padding:20px;border:1px solid #ddd;border-top:none;'>
            <div style='text-align:center;margin-bottom:16px;'>
                <span style='display:inline-block;background:{$estInfo[0]};color:#fff;padding:6px 20px;border-radius:20px;font-size:14px;font-weight:bold;'>{$estInfo[1]}</span>
            </div>

            <table style='width:100%;border-collapse:collapse;font-size:12px;margin-bottom:14px;'>
                <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;width:30%;'>Cliente:</td><td style='padding:5px;border:1px solid #ddd;'>" . htmlspecialchars($cot['cliente_nombre']) . "</td></tr>
                <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Obra:</td><td style='padding:5px;border:1px solid #ddd;'>" . htmlspecialchars($cot['cliente_obra']) . "</td></tr>
                <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Fecha:</td><td style='padding:5px;border:1px solid #ddd;'>" . date('d/m/Y', strtotime($cot['fecha'])) . "</td></tr>
                <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Total:</td><td style='padding:5px;border:1px solid #ddd;font-weight:bold;font-size:14px;'>$" . formatCLP($cot['total']) . "</td></tr>
                <tr><td style='padding:5px;background:#fef3c7;font-weight:bold;border:1px solid #ddd;'>Cotizada por:</td><td style='padding:5px;border:1px solid #ddd;'>" . htmlspecialchars($cot['creada_por']) . "</td></tr>
            </table>

            <h3 style='font-size:13px;color:#1a1a2e;margin:14px 0 8px;'>Resumen de ítems:</h3>
            <table style='width:100%;border-collapse:collapse;font-size:11px;'>
                <thead><tr style='background:#1a1a2e;color:#fff;'>
                    <th style='padding:5px;border:1px solid #333;text-align:left;'>Descripción</th>
                    <th style='padding:5px;border:1px solid #333;'>Cant.</th>
                    <th style='padding:5px;border:1px solid #333;'>Total</th>
                </tr></thead>
                <tbody>{$itemsHtml}</tbody>
                <tfoot>
                    <tr style='background:#fef3c7;'><td style='padding:5px;border:1px solid #ddd;font-weight:bold;text-align:right;' colspan='2'>TOTAL</td><td style='padding:5px;border:1px solid #ddd;font-weight:bold;text-align:right;font-size:13px;'>$" . formatCLP($cot['total']) . "</td></tr>
                </tfoot>
            </table>

            {$aprobHtml}

            <p style='font-size:11px;color:#999;text-align:center;margin-top:20px;'>DOGROUP<br>77.930.045-5</p>
        </div>
    </div>";
}
