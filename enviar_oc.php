<?php
require_once 'config.php';
requireAuth();
require_once 'email_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: historial.php');
    exit;
}

$oc_id = (int)($_POST['oc_id'] ?? 0);
$dest_email = trim($_POST['dest_email'] ?? '');
$dest_nombre = trim($_POST['dest_nombre'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM ordenes_compra WHERE id = ?");
$stmt->execute([$oc_id]);
$oc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$oc || $oc['estado'] !== 'aprobada') {
    header("Location: ver.php?id={$oc_id}&envio_error=" . urlencode('La OC debe estar aprobada para enviarla.'));
    exit;
}

if (empty($dest_email)) {
    header("Location: ver.php?id={$oc_id}&envio_error=" . urlencode('Ingrese un correo de destino.'));
    exit;
}

$stmtItems = $pdo->prepare("SELECT * FROM oc_items WHERE oc_id = ? ORDER BY id");
$stmtItems->execute([$oc_id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$result = enviarOCPorCorreo($pdo, $oc, $items, $dest_email, $dest_nombre);

if ($result === true) {
    $pdo->prepare("INSERT INTO oc_envios (oc_id, enviado_por, destinatario_email, destinatario_nombre) VALUES (?,?,?,?)")
        ->execute([$oc_id, $usuario['nombre'], $dest_email, $dest_nombre]);
    header("Location: ver.php?id={$oc_id}&envio_ok=1");
} else {
    header("Location: ver.php?id={$oc_id}&envio_error=" . urlencode($result));
}
exit;
