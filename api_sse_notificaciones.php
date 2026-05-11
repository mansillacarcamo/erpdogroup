<?php
require_once 'config.php';

// SSE - Server Sent Events para notificaciones en tiempo real
if (empty($_SESSION['usuario'])) {
    http_response_code(401);
    exit;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

$userId = $_SESSION['usuario']['id'];
$lastCheck = isset($_GET['last']) ? (int)$_GET['last'] : 0;

function enviarEvento($tipo, $data) {
    echo "event: $tipo\n";
    echo "data: " . json_encode($data) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

// Consultar notificaciones nuevas
try {
    $total = 0;
    $items = [];

    // OC pendientes de aprobación
    $st = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobaciones WHERE usuario_id = ? AND estado = 'pendiente'");
    $st->execute([$userId]);
    $ocPend = (int)$st->fetchColumn();

    // Cotizaciones pendientes
    $st = $pdo->prepare("SELECT COUNT(*) FROM cot_aprobaciones WHERE usuario_id = ? AND estado = 'pendiente'");
    $st->execute([$userId]);
    $cotPend = (int)$st->fetchColumn();

    // Notificaciones no leídas de cotizaciones
    $st = $pdo->prepare("SELECT COUNT(*) FROM cot_notificaciones WHERE destinatario_id = ? AND leida = 0");
    $st->execute([$userId]);
    $cotNotif = (int)$st->fetchColumn();

    // Notificaciones no leídas de OC
    $st = $pdo->prepare("SELECT COUNT(*) FROM oc_notificaciones WHERE destinatario_id = ? AND leida = 0");
    $st->execute([$userId]);
    $ocNotif = (int)$st->fetchColumn();

    $total = $ocPend + $cotPend + $cotNotif + $ocNotif;

    // Últimas 5 notificaciones con detalle
    $st = $pdo->prepare("
        SELECT 'oc' as tipo, o.numero as ref, n.fecha, n.contenido as msg
        FROM oc_notificaciones n
        JOIN ordenes_compra o ON o.id = n.oc_id
        WHERE n.destinatario_id = ? AND n.leida = 0
        UNION ALL
        SELECT 'cot' as tipo, c.numero as ref, n.fecha, n.contenido as msg
        FROM cot_notificaciones n
        JOIN cotizaciones c ON c.id = n.cot_id
        WHERE n.destinatario_id = ? AND n.leida = 0
        ORDER BY fecha DESC LIMIT 5
    ");
    $st->execute([$userId, $userId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);

    enviarEvento('notificaciones', [
        'total' => $total,
        'oc_pendientes' => $ocPend,
        'cot_pendientes' => $cotPend,
        'items' => $items,
        'ts' => time()
    ]);

} catch (Exception $e) {
    enviarEvento('error', ['msg' => 'Error consultando notificaciones']);
}
