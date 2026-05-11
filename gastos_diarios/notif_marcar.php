<?php
/**
 * Marca una notificación como leída.
 */
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false]); exit; }
$uid = (int)$_SESSION['user_id'];
$id  = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) { echo json_encode(['ok'=>false]); exit; }

$pdo->prepare("UPDATE notificaciones SET leida = 1 WHERE id = ? AND usuario_id = ?")->execute([$id, $uid]);
echo json_encode(['ok'=>true]);
