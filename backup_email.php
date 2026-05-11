<?php
/**
 * Notificacion de backup por correo
 * Uso (desde backup_auto.ps1):
 *   php backup_email.php --estado=ok --archivo=ruta.zip --tamano="2.34 MB"
 *   php backup_email.php --estado=error --mensaje="texto del error"
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/smtp_config.php';

use PHPMailer\PHPMailer\PHPMailer;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Solo CLI');
}

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)=(.*)$/', $a, $m)) $args[$m[1]] = $m[2];
}

$estado   = $args['estado']   ?? 'ok';
$archivo  = $args['archivo']  ?? '';
$tamano   = $args['tamano']   ?? '';
$mensaje  = $args['mensaje']  ?? '';
$fecha    = date('d/m/Y H:i:s');
$destino  = 'deptoinformatica@dogroup.cl';

if ($estado === 'ok') {
    $asunto = 'Backup DoGroup OK — ' . date('d/m/Y H:i');
    $color  = '#10b981';
    $titulo = 'Backup realizado con exito';
    $detalle = "
        <tr><td style='padding:6px 10px;border-bottom:1px solid #eee;font-weight:600;'>Estado</td>
            <td style='padding:6px 10px;border-bottom:1px solid #eee;color:#10b981;'>OK</td></tr>
        <tr><td style='padding:6px 10px;border-bottom:1px solid #eee;font-weight:600;'>Fecha y hora</td>
            <td style='padding:6px 10px;border-bottom:1px solid #eee;'>{$fecha}</td></tr>
        <tr><td style='padding:6px 10px;border-bottom:1px solid #eee;font-weight:600;'>Archivo generado</td>
            <td style='padding:6px 10px;border-bottom:1px solid #eee;font-family:Consolas,monospace;font-size:12px;'>" . htmlspecialchars($archivo) . "</td></tr>
        <tr><td style='padding:6px 10px;font-weight:600;'>Tamano</td>
            <td style='padding:6px 10px;'>" . htmlspecialchars($tamano) . "</td></tr>";
} else {
    $asunto = 'Backup DoGroup ERROR — ' . date('d/m/Y H:i');
    $color  = '#b91c1c';
    $titulo = 'Backup fallo';
    $detalle = "
        <tr><td style='padding:6px 10px;border-bottom:1px solid #eee;font-weight:600;'>Estado</td>
            <td style='padding:6px 10px;border-bottom:1px solid #eee;color:#b91c1c;'>ERROR</td></tr>
        <tr><td style='padding:6px 10px;border-bottom:1px solid #eee;font-weight:600;'>Fecha y hora</td>
            <td style='padding:6px 10px;border-bottom:1px solid #eee;'>{$fecha}</td></tr>
        <tr><td style='padding:6px 10px;font-weight:600;'>Mensaje</td>
            <td style='padding:6px 10px;color:#b91c1c;'>" . htmlspecialchars($mensaje) . "</td></tr>";
}

$body = "
<!doctype html><html><body style='font-family:Segoe UI,Arial,sans-serif;background:#f3f4f6;margin:0;padding:24px;'>
  <div style='max-width:560px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);'>
    <div style='background:{$color};color:#fff;padding:18px 24px;'>
      <h2 style='margin:0;font-size:18px;'>{$titulo}</h2>
      <div style='font-size:13px;opacity:.9;margin-top:2px;'>Sistema DoGroup — Copia de seguridad automatica</div>
    </div>
    <table cellpadding='0' cellspacing='0' style='width:100%;border-collapse:collapse;font-size:14px;'>
      {$detalle}
    </table>
    <div style='background:#f9fafb;padding:14px 24px;font-size:12px;color:#6b7280;border-top:1px solid #eee;'>
      Este es un correo automatico. Los backups se almacenan en la carpeta <code>backups/</code> del servidor.
    </div>
  </div>
</body></html>";

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $SMTP_CONFIG['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $SMTP_CONFIG['user'];
    $mail->Password   = $SMTP_CONFIG['pass'];
    $mail->SMTPSecure = ((int)$SMTP_CONFIG['port'] === 465)
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)$SMTP_CONFIG['port'];
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom($SMTP_CONFIG['from_email'], $SMTP_CONFIG['from_name']);
    $mail->addAddress($destino);
    $mail->isHTML(true);
    $mail->Subject = $asunto;
    $mail->Body    = $body;
    $mail->AltBody = strip_tags(str_replace(['</tr>','</td>'], "\n", $body));

    $mail->send();
    echo "Mail enviado a {$destino}\n";
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Error enviando correo: " . $e->getMessage() . "\n");
    exit(1);
}
