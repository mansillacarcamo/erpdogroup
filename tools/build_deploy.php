<?php
// ============================================================
//  BUILD DEPLOY - Genera el ZIP listo para subir a Benzahosting
//  Uso: php tools/build_deploy.php
//  Salida: ../deploy_dogroup_YYYYMMDD_HHMMSS.zip
// ============================================================

$BASE = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
$OUT  = dirname($BASE) . DIRECTORY_SEPARATOR . 'deploy_dogroup_' . date('Ymd_His') . '.zip';

// Patrones a EXCLUIR (rutas relativas desde $BASE)
$EXCLUDE_DIRS  = [
    // Copias viejas del proyecto (NO subir, causan conflictos de routing)
    'dogroupsistema',
    'ocdogroup',
    // Backups locales
    'backups',
    'gastos_diarios/backups',
    // Control de versiones / IDE
    '.git',
    '.vscode',
    '.idea',
    '.claude',
    // Vacios o no aplicables a Linux
    'cgi-bin',
];
$EXCLUDE_FILES = [
    // Drafts / dev
    '_commitmsg.txt',
    '_generate_icons.php',
    '_refactor_cot.php',
    'gastos_diarios/dev_login.php',
    'dev_login.php',
    'cookies.txt',
    'test_error.php',
    // Composer installer (no debe ir a produccion, ya tenemos vendor/)
    'composer.phar',
    'composer-setup.php',
    // Scripts de desarrollo Windows / Laragon (no aplican en Linux)
    'PASO1_instalar_laragon.bat',
    'PASO2_copiar_proyecto.bat',
    'LEER_PRIMERO.txt',
    'iniciar-localhost.ps1',
    'restaurar.ps1',
    'backup_auto.ps1',
    'empaquetar-benhosting.ps1',
    // Documentacion interna que expone arquitectura
    'INSTRUCCIONES_BACKUP.html',
    'DOCUMENTACION_SISTEMA.html',
    // Configs git/deploy
    '.deployignore',
    '.gitignore',
    '.gitattributes',
    '.cpanel.yml',
];
$EXCLUDE_GLOBS = [
    '/^INSTALACION_.*\.md$/i',
    '/\.db-wal$/i',
    '/\.db-shm$/i',
    '/\.DS_Store$/',
    '/^Thumbs\.db$/i',
    '/^deploy_.*\.zip$/',
    '/\.log$/',
    '/\.bat$/i',
    '/\.ps1$/i',
];

function shouldExclude($rel, $excludeDirs, $excludeFiles, $excludeGlobs) {
    $rel = str_replace('\\', '/', $rel);
    foreach ($excludeDirs as $d) {
        if ($rel === $d || strpos($rel, $d . '/') === 0) return true;
    }
    if (in_array($rel, $excludeFiles, true)) return true;
    foreach ($excludeGlobs as $g) {
        if (preg_match($g, basename($rel))) return true;
    }
    return false;
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ERROR: extension zip no disponible\n");
    exit(1);
}

echo "Empaquetando proyecto desde: $BASE\n";
echo "Destino: $OUT\n\n";

$zip = new ZipArchive();
if ($zip->open($OUT, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "ERROR: no se pudo crear el ZIP\n");
    exit(2);
}

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($BASE, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$totalArch = 0;
$totalSkip = 0;
$totalSize = 0;
foreach ($it as $file) {
    $rel = ltrim(substr($file->getPathname(), strlen($BASE)), '/\\');
    $rel = str_replace('\\', '/', $rel);
    if ($file->isDir()) {
        if (shouldExclude($rel, $EXCLUDE_DIRS, $EXCLUDE_FILES, $EXCLUDE_GLOBS)) {
            // No skip aquí: el iterador igual recorre el contenido. Marcamos para que cada hijo se filtre.
            continue;
        }
        // Crear directorios vacíos para preservar estructura (uploads/)
        $zip->addEmptyDir($rel);
        continue;
    }
    if (shouldExclude($rel, $EXCLUDE_DIRS, $EXCLUDE_FILES, $EXCLUDE_GLOBS)) {
        $totalSkip++;
        continue;
    }
    $zip->addFile($file->getPathname(), $rel);
    $totalArch++;
    $totalSize += $file->getSize();
}

if (!$zip->close()) {
    fwrite(STDERR, "ERROR al cerrar ZIP\n");
    exit(3);
}

echo "Archivos en el ZIP : $totalArch\n";
echo "Archivos excluidos : $totalSkip\n";
echo "Tamano contenido   : " . number_format($totalSize/1024/1024, 1) . " MB\n";
echo "Tamano del ZIP     : " . number_format(filesize($OUT)/1024/1024, 1) . " MB\n";
echo "\nListo: $OUT\n";
