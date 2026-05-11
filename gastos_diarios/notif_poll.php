<?php
/**
 * Endpoint de polling: devuelve las notificaciones no leídas del usuario logueado
 * en formato JSON. Lo consume el toast tipo WhatsApp del navegador.
 */
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) { echo json_encode(['ok'=>false,'pendientes'=>[]]); exit; }

$uid = (int)$_SESSION['user_id'];
$st  = $pdo->prepare("SELECT id, titulo, mensaje, tipo, enlace, creado_en
                      FROM notificaciones
                      WHERE usuario_id = ? AND leida = 0
                      ORDER BY id DESC LIMIT 20");
$st->execute([$uid]);
$pend = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok'=>true, 'pendientes'=>$pend], JSON_UNESCAPED_UNICODE);
