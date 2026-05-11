<?php
require_once 'config.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: historial_cotizaciones.php');
    exit;
}

$cot_id = (int)($_POST['cot_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
$stmt->execute([$cot_id]);
$cot = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cot) { header('Location: historial_cotizaciones.php'); exit; }

$aprobadorIds = $_POST['aprobadores'] ?? [];
if (empty($aprobadorIds)) {
    header("Location: ver_cotizacion.php?id={$cot_id}&aprobacion_error=" . urlencode('Debe seleccionar al menos un validador.'));
    exit;
}

$pdo->prepare("DELETE FROM cot_aprobaciones WHERE cot_id = ?")->execute([$cot_id]);
$pdo->prepare("UPDATE cotizaciones SET estado = 'en_revision' WHERE id = ?")->execute([$cot_id]);

$aprobadoresCreados = 0;
foreach ($aprobadorIds as $uid) {
    $uid = (int)$uid;
    $stmtU = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = ?");
    $stmtU->execute([$uid]);
    $row = $stmtU->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $pdo->prepare("INSERT INTO cot_aprobaciones (cot_id, usuario_id, nombre) VALUES (?,?,?)")
            ->execute([$cot_id, $uid, $row['nombre']]);
        $aprobadoresCreados++;
    }
}

if ($aprobadoresCreados > 0) {
    header("Location: ver_cotizacion.php?id={$cot_id}&aprobacion_enviada=1");
} else {
    header("Location: ver_cotizacion.php?id={$cot_id}&aprobacion_error=" . urlencode('No se pudieron asignar validadores.'));
}
exit;
