<?php
require_once 'config.php';
requireAuth();
header('Content-Type: application/json');

$userId = $usuario['id'];
$tipo = $_POST['tipo'] ?? 'todas';

try {
    if ($tipo === 'oc' || $tipo === 'todas') {
        $pdo->prepare("UPDATE oc_notificaciones SET leida = 1 WHERE destinatario_id = ?")->execute([$userId]);
    }
    if ($tipo === 'cot' || $tipo === 'todas') {
        $pdo->prepare("UPDATE cot_notificaciones SET leida = 1 WHERE destinatario_id = ?")->execute([$userId]);
    }
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
