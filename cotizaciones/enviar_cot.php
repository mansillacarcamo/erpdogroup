<?php
require_once '../config.php';
requireAuth();
require_once '../email_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: historial_cotizaciones.php');
    exit;
}

$cot_id = (int)($_POST['cot_id'] ?? 0);
$dest_email = trim($_POST['dest_email'] ?? '');
$dest_nombre = trim($_POST['dest_nombre'] ?? '');
$contenido = $_POST['contenido'] ?? 'cotizacion';

$stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
$stmt->execute([$cot_id]);
$cot = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cot) {
    header('Location: historial_cotizaciones.php');
    exit;
}

if (empty($dest_email)) {
    header("Location: ver_cotizacion.php?id={$cot_id}&envio_error=" . urlencode('Ingrese un correo de destino.'));
    exit;
}

$stmtItems = $pdo->prepare("SELECT * FROM cot_items WHERE cot_id = ? ORDER BY id");
$stmtItems->execute([$cot_id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$result = enviarCotPorCorreo($pdo, $cot, $items, $dest_email, $dest_nombre, $contenido);

if ($result === true) {
    $pdo->prepare("INSERT INTO cot_envios (cot_id, enviado_por, destinatario_email, destinatario_nombre, contenido) VALUES (?,?,?,?,?)")
        ->execute([$cot_id, $usuario['nombre'], $dest_email, $dest_nombre, $contenido]);
    header("Location: ver_cotizacion.php?id={$cot_id}&envio_ok=1");
} else {
    header("Location: ver_cotizacion.php?id={$cot_id}&envio_error=" . urlencode($result));
}
exit;
