<?php
// Endpoint AJAX para notificaciones del usuario logueado
require_once 'config.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$uid = (int)$_SESSION['user_id'];
$accion = $_POST['accion'] ?? $_GET['accion'] ?? 'listar';

try {
    if ($accion === 'marcar_leida') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE notificaciones SET leida=1 WHERE id=? AND usuario_id=?")
            ->execute([$id, $uid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($accion === 'marcar_todas') {
        $pdo->prepare("UPDATE notificaciones SET leida=1 WHERE usuario_id=? AND leida=0")
            ->execute([$uid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // listar: retorna todas las no leidas
    $q = $pdo->prepare("SELECT id, titulo, mensaje, tipo, enlace, creado_en
                       FROM notificaciones
                       WHERE usuario_id=? AND leida=0
                       ORDER BY creado_en DESC");
    $q->execute([$uid]);
    echo json_encode(['ok' => true, 'items' => $q->fetchAll()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
