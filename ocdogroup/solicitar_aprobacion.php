<?php
require_once 'config.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: historial.php');
    exit;
}

$oc_id = (int)($_POST['oc_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM ordenes_compra WHERE id = ?");
$stmt->execute([$oc_id]);
$oc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$oc) { header('Location: historial.php'); exit; }

$pdo->prepare("DELETE FROM oc_aprobaciones WHERE oc_id = ?")->execute([$oc_id]);
$pdo->prepare("UPDATE ordenes_compra SET estado = 'en_revision' WHERE id = ?")->execute([$oc_id]);

$stmtAprobadores = $pdo->query("
    SELECT a.usuario_id, u.nombre
    FROM oc_aprobadores a
    JOIN usuarios u ON a.usuario_id = u.id
    ORDER BY a.id
");
$aprobadores = $stmtAprobadores->fetchAll(PDO::FETCH_ASSOC);

$aprobadoresCreados = 0;
foreach ($aprobadores as $aprobador) {
    $pdo->prepare("INSERT INTO oc_aprobaciones (oc_id, usuario_id, nombre) VALUES (?,?,?)")
        ->execute([$oc_id, $aprobador['usuario_id'], $aprobador['nombre']]);
    $aprobadoresCreados++;
}

if ($aprobadoresCreados > 0) {
    header("Location: ver.php?id={$oc_id}&aprobacion_enviada=1");
} else {
    header("Location: ver.php?id={$oc_id}&aprobacion_error=" . urlencode('No hay aprobadores configurados en el sistema.'));
}
exit;
