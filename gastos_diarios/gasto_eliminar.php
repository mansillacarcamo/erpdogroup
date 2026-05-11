<?php
require_once 'config.php';
requireAuth();
$uid = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);

$q = $pdo->prepare("SELECT * FROM gastos WHERE id=? AND usuario_id=?");
$q->execute([$id, $uid]);
$g = $q->fetch();
if (!$g) { flash('error','Gasto no encontrado.'); header('Location: mis_gastos.php'); exit; }
if ($g['estado'] !== 'registrado') { flash('error','No se puede eliminar un gasto ya enviado/aprobado.'); header('Location: mis_gastos.php'); exit; }

$ar = $pdo->prepare("SELECT * FROM archivos_gasto WHERE gasto_id=?");
$ar->execute([$id]);
foreach ($ar->fetchAll() as $a) {
    $f = __DIR__ . '/uploads/' . $a['nombre_archivo'];
    if (is_file($f)) @unlink($f);
}
$pdo->prepare("DELETE FROM archivos_gasto WHERE gasto_id=?")->execute([$id]);
$pdo->prepare("DELETE FROM gastos WHERE id=?")->execute([$id]);

flash('exito','Gasto eliminado.');
header('Location: mis_gastos.php');
