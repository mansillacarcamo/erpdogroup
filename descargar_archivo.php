<?php
require_once 'config.php';
requireAuth();

$archivoId = (int)($_GET['id'] ?? 0);
if (!$archivoId) { http_response_code(400); exit('ID inválido'); }

$stmt = $pdo->prepare("SELECT * FROM cot_archivos WHERE id = ?");
$stmt->execute([$archivoId]);
$archivo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$archivo) { http_response_code(404); exit('Archivo no encontrado'); }

$ruta = __DIR__ . '/uploads/' . $archivo['nombre_archivo'];
if (!file_exists($ruta)) { http_response_code(404); exit('Archivo no existe en el servidor'); }

$accion = $_GET['accion'] ?? 'descargar';

$mime = $archivo['tipo_mime'] ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta));

if ($accion === 'ver' && in_array($mime, ['image/jpeg','image/png','image/gif','image/webp','image/svg+xml','application/pdf'])) {
    header('Content-Disposition: inline; filename="' . $archivo['nombre_original'] . '"');
} else {
    header('Content-Disposition: attachment; filename="' . $archivo['nombre_original'] . '"');
}

readfile($ruta);
exit;
