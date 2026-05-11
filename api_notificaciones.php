<?php
require_once 'config.php';
requireAuth();
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$userId = $usuario['id'];

try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM oc_aprobaciones WHERE usuario_id = ? AND estado = 'pendiente'");
    $st->execute([$userId]); $ocPend = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM cot_aprobaciones WHERE usuario_id = ? AND estado = 'pendiente'");
    $st->execute([$userId]); $cotPend = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM cot_notificaciones WHERE destinatario_id = ? AND leida = 0");
    $st->execute([$userId]); $cotNotif = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM oc_notificaciones WHERE destinatario_id = ? AND leida = 0");
    $st->execute([$userId]); $ocNotif = (int)$st->fetchColumn();

    // Historial reciente
    $st = $pdo->prepare("
        SELECT 'OC' as tipo, o.numero as ref, n.fecha, n.contenido as msg, n.oc_id as doc_id
        FROM oc_notificaciones n
        JOIN ordenes_compra o ON o.id = n.oc_id
        WHERE n.destinatario_id = ? AND n.leida = 0
        UNION ALL
        SELECT 'COT' as tipo, c.numero as ref, n.fecha, n.contenido as msg, n.cot_id as doc_id
        FROM cot_notificaciones n
        JOIN cotizaciones c ON c.id = n.cot_id
        WHERE n.destinatario_id = ? AND n.leida = 0
        ORDER BY fecha DESC LIMIT 8
    ");
    $st->execute([$userId, $userId]);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'total' => $ocPend + $cotPend + $cotNotif + $ocNotif,
        'oc_pendientes' => $ocPend,
        'cot_pendientes' => $cotPend,
        'no_leidas' => $cotNotif + $ocNotif,
        'items' => $items
    ]);
} catch (Exception $e) {
    echo json_encode(['total' => 0, 'items' => [], 'error' => $e->getMessage()]);
}
