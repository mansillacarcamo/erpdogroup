<?php
// Arregla permisos para que Apache/PHP pueda leer todos los archivos.
// Uso: subir este archivo al hosting y abrir https://TUDOMINIO/fix_perms.php
// Borra este archivo cuando termine.

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

$base = __DIR__;
$it   = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$dirs = 0; $files = 0; $errDirs = 0; $errFiles = 0;

@chmod($base, 0755);

foreach ($it as $path => $info) {
    if ($info->isDir()) {
        if (@chmod($path, 0755)) { $dirs++; } else { $errDirs++; }
    } else {
        if (@chmod($path, 0644)) { $files++; } else { $errFiles++; }
    }
}

$especiales = [
    'ocdogroup.db' => 0664,
    'uploads'      => 0775,
];
foreach ($especiales as $rel => $mode) {
    $p = $base . '/' . $rel;
    if (file_exists($p)) {
        @chmod($p, $mode);
        if (is_dir($p)) {
            $itUp = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($itUp as $sub) {
                @chmod($sub, $sub->isDir() ? 0775 : 0664);
            }
        }
    }
}

echo "=== Arreglo de permisos COMPLETADO ===\n\n";
echo "Carpetas ajustadas a 755: $dirs  (errores: $errDirs)\n";
echo "Archivos ajustados a 644: $files (errores: $errFiles)\n";
echo "ocdogroup.db -> 664 (si existe)\n";
echo "uploads/ -> 775 recursivo (si existe)\n\n";

echo "--- Verificación rápida ---\n";
$probar = [
    'vendor/autoload.php',
    'vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Spreadsheet.php',
    'ocdogroup.db',
    'config.php',
];
foreach ($probar as $rel) {
    $p = $base . '/' . $rel;
    if (!file_exists($p)) { echo "FALTA  $rel\n"; continue; }
    $perm = substr(sprintf('%o', fileperms($p)), -4);
    $leer = is_readable($p) ? 'legible' : 'NO legible';
    echo "OK    $rel  (perm=$perm, $leer)\n";
}

echo "\nYa puedes entrar al sistema y probar Exportar Excel.\n";
echo "BORRA este archivo (fix_perms.php) cuando termines.\n";
