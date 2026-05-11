<?php
require_once 'config.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: historial_cotizaciones.php');
    exit;
}

$cot_id = (int)($_POST['cot_id'] ?? 0);
$admin_id = (int)($_POST['admin_id'] ?? 0);
$contenido = in_array($_POST['contenido'] ?? '', ['cotizacion', 'proceso']) ? $_POST['contenido'] : 'cotizacion';

$stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
$stmt->execute([$cot_id]);
$cot = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cot) {
    header('Location: historial_cotizaciones.php');
    exit;
}

if (!$admin_id) {
    header("Location: ver_cotizacion.php?id={$cot_id}&envio_error=" . urlencode('Debe seleccionar un destinatario.'));
    exit;
}

$stmtDest = $pdo->prepare("
    SELECT DISTINCT u.* FROM usuarios u
    LEFT JOIN oc_aprobadores a ON u.id = a.usuario_id
    WHERE u.id = ? AND (u.rol = 'admin' OR a.usuario_id IS NOT NULL)
");
$stmtDest->execute([$admin_id]);
$dest = $stmtDest->fetch(PDO::FETCH_ASSOC);

if (!$dest) {
    header("Location: ver_cotizacion.php?id={$cot_id}&envio_error=" . urlencode('El usuario seleccionado no es administrador ni validador.'));
    exit;
}

$pdo->prepare("INSERT INTO cot_notificaciones (cot_id, destinatario_id, enviado_por, contenido) VALUES (?,?,?,?)")
    ->execute([$cot_id, $admin_id, $usuario['nombre'], $contenido]);

header("Location: ver_cotizacion.php?id={$cot_id}&envio_admin_ok=" . urlencode("Estado de proceso enviado al perfil de {$dest['nombre']}."));
exit;
