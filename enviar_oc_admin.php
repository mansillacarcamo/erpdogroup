<?php
require_once 'config.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: historial.php');
    exit;
}

$oc_id = (int)($_POST['oc_id'] ?? 0);
$dest_id = (int)($_POST['dest_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM ordenes_compra WHERE id = ?");
$stmt->execute([$oc_id]);
$oc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$oc) {
    header('Location: historial.php');
    exit;
}

if (!$dest_id) {
    header("Location: ver.php?id={$oc_id}&envio_error=" . urlencode('Debe seleccionar un destinatario.'));
    exit;
}

$stmtDest = $pdo->prepare("
    SELECT DISTINCT u.* FROM usuarios u
    LEFT JOIN oc_aprobadores a ON u.id = a.usuario_id
    WHERE u.id = ? AND (u.rol = 'admin' OR a.usuario_id IS NOT NULL)
");
$stmtDest->execute([$dest_id]);
$dest = $stmtDest->fetch(PDO::FETCH_ASSOC);

if (!$dest) {
    header("Location: ver.php?id={$oc_id}&envio_error=" . urlencode('El usuario seleccionado no es administrador ni validador.'));
    exit;
}

$pdo->prepare("INSERT INTO oc_notificaciones (oc_id, destinatario_id, enviado_por, contenido) VALUES (?,?,?,?)")
    ->execute([$oc_id, $dest_id, $usuario['nombre'], 'proceso']);

header("Location: ver.php?id={$oc_id}&envio_admin_ok=" . urlencode("Estado de proceso enviado al perfil de {$dest['nombre']}."));
exit;
