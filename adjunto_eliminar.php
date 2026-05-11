<?php
require_once 'config.php';
requireAuth();

$uid = (int)$_SESSION['user_id'];
$aid = (int)($_GET['id'] ?? 0);
$gid = (int)($_GET['gasto'] ?? 0);

$q = $pdo->prepare("SELECT a.*, g.usuario_id, g.estado FROM archivos_gasto a
                    JOIN gastos g ON g.id = a.gasto_id WHERE a.id=?");
$q->execute([$aid]);
$a = $q->fetch();

if (!$a || $a['usuario_id'] != $uid) { flash('error','Adjunto no encontrado.'); header('Location: mis_gastos.php'); exit; }
if ($a['estado'] !== 'registrado') { flash('error','No puedes modificar adjuntos de un gasto enviado.'); header('Location: mis_gastos.php'); exit; }

$f = __DIR__ . '/uploads/' . $a['nombre_archivo'];
if (is_file($f)) @unlink($f);
$pdo->prepare("DELETE FROM archivos_gasto WHERE id=?")->execute([$aid]);

flash('exito','Adjunto eliminado.');
header('Location: gasto_editar.php?id=' . ($gid ?: $a['gasto_id']));
