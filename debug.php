<?php
// ============================================================
//  DIAGNOSTICO - Subir este archivo al hosting y abrirlo
//  URL:  https://tudominio.cl/debug.php
//  BORRAR despues de usar por seguridad.
// ============================================================

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Diagnostico controlgastos</title>
<style>
body{font-family:system-ui,sans-serif;max-width:900px;margin:20px auto;padding:0 15px;color:#222;}
h1{border-bottom:3px solid #0d6efd;padding-bottom:8px;}
h2{margin-top:30px;color:#0d6efd;}
.ok{color:#198754;font-weight:bold;}
.err{color:#dc3545;font-weight:bold;}
.warn{color:#fd7e14;font-weight:bold;}
pre{background:#f5f5f5;padding:10px;border-radius:6px;overflow-x:auto;}
table{border-collapse:collapse;width:100%;margin:10px 0;}
td,th{padding:6px 10px;border-bottom:1px solid #e5e5e5;text-align:left;}
th{background:#f8f9fa;}
code{background:#f0f0f0;padding:2px 6px;border-radius:3px;}
</style>
</head>
<body>
<h1>Diagnostico Control de Gastos</h1>
<p class="warn">BORRA ESTE ARCHIVO cuando termines. No lo dejes en produccion.</p>

<h2>1. Version de PHP</h2>
<?php
$ver = PHP_VERSION;
$ok  = version_compare($ver, '7.4.0', '>=');
echo "<p>PHP instalado: <code>$ver</code> ";
echo $ok ? '<span class="ok">OK (requiere 7.4+)</span>' : '<span class="err">MUY ANTIGUO - necesitas PHP 7.4 o superior</span>';
echo "</p>";
?>

<h2>2. Extensiones PHP requeridas</h2>
<table>
<tr><th>Extension</th><th>Uso</th><th>Estado</th></tr>
<?php
$exts = [
    'pdo'        => 'PDO base',
    'pdo_sqlite' => 'Conexion SQLite (OBLIGATORIO)',
    'sqlite3'    => 'SQLite alternativo',
    'mbstring'   => 'Funciones mb_substr',
    'gd'         => 'Imagenes (fotos de perfil)',
    'fileinfo'   => 'Tipo MIME archivos subidos',
    'json'       => 'JSON',
    'session'    => 'Sesiones',
];
foreach ($exts as $e => $uso) {
    $cargada = extension_loaded($e);
    $cls = $cargada ? 'ok' : ($e==='sqlite3' ? 'warn' : 'err');
    $txt = $cargada ? 'INSTALADA' : 'FALTA';
    echo "<tr><td><code>$e</code></td><td>$uso</td><td class='$cls'>$txt</td></tr>";
}
?>
</table>

<h2>3. Rutas y permisos</h2>
<?php
$base = __DIR__;
echo "<p>Ruta del proyecto: <code>" . htmlspecialchars($base) . "</code></p>";

$rutas = [
    'Carpeta base'      => $base,
    'DB (controlgastos.db)' => $base . DIRECTORY_SEPARATOR . 'controlgastos.db',
    'uploads/'          => $base . DIRECTORY_SEPARATOR . 'uploads',
    'uploads/usuarios/' => $base . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'usuarios',
    'uploads/adjuntos/' => $base . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'adjuntos',
];
echo "<table><tr><th>Ruta</th><th>Existe</th><th>Escribible</th></tr>";
foreach ($rutas as $k => $p) {
    $ex = file_exists($p);
    $w  = $ex && is_writable($p);
    $exC = $ex ? 'ok' : 'warn';
    $wC  = $w  ? 'ok' : 'err';
    echo "<tr><td>$k</td>";
    echo "<td class='$exC'>" . ($ex?'SI':'NO') . "</td>";
    echo "<td class='$wC'>"  . ($w ?'SI':'NO') . "</td></tr>";
}
echo "</table>";
?>

<h2>4. Intentar abrir/crear la base de datos SQLite</h2>
<?php
$DB_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'controlgastos.db';
try {
    $pdo = new PDO('sqlite:' . $DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p class='ok'>Conexion SQLite OK.</p>";

    $tablas = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    if ($tablas) {
        echo "<p>Tablas encontradas (" . count($tablas) . "):</p><ul>";
        foreach ($tablas as $t) echo "<li><code>$t</code></li>";
        echo "</ul>";
    } else {
        echo "<p class='warn'>La base de datos esta vacia (sin tablas). Al abrir index.php se crearan las tablas.</p>";
    }

    // Test journal WAL (falla en algunos hostings)
    try {
        $pdo->exec('PRAGMA journal_mode = WAL');
        echo "<p class='ok'>Modo WAL OK.</p>";
    } catch (Exception $e) {
        echo "<p class='warn'>No se pudo activar WAL: " . htmlspecialchars($e->getMessage()) . " (se puede desactivar)</p>";
    }
} catch (Throwable $e) {
    echo "<p class='err'>ERROR SQLite: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<h2>5. Prueba de sesiones</h2>
<?php
@session_start();
$_SESSION['debug_test'] = date('H:i:s');
if (!empty($_SESSION['debug_test'])) {
    echo "<p class='ok'>Sesiones OK. Valor guardado: " . htmlspecialchars($_SESSION['debug_test']) . "</p>";
    echo "<p>Carpeta de sesiones: <code>" . htmlspecialchars(session_save_path() ?: 'default') . "</code></p>";
} else {
    echo "<p class='err'>Sesiones NO funcionan.</p>";
}
?>

<h2>6. Intentar cargar config.php completo</h2>
<?php
try {
    ob_start();
    require __DIR__ . '/config.php';
    ob_end_clean();
    echo "<p class='ok'>config.php se cargo sin errores fatales.</p>";
    if (defined('APP_VER')) echo "<p>APP_VER: <code>" . APP_VER . "</code></p>";
} catch (Throwable $e) {
    echo "<p class='err'>ERROR al cargar config.php:</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "\n\nArchivo: " . $e->getFile() . "\nLinea: " . $e->getLine() . "</pre>";
}
?>

<h2>7. Log de errores recientes</h2>
<?php
$logPaths = [
    __DIR__ . '/error_log',
    __DIR__ . '/php_errorlog',
    ini_get('error_log'),
];
$found = false;
foreach ($logPaths as $lp) {
    if ($lp && file_exists($lp) && is_readable($lp)) {
        $found = true;
        $contenido = file($lp);
        $last = array_slice($contenido, -30);
        echo "<p>Log: <code>" . htmlspecialchars($lp) . "</code></p>";
        echo "<pre>" . htmlspecialchars(implode('', $last)) . "</pre>";
        break;
    }
}
if (!$found) {
    echo "<p class='warn'>No se encontro archivo de log de errores en las rutas estandar.</p>";
    echo "<p>Revisa en el panel de Benza hosting (cPanel -> Error Log / Errores).</p>";
}
?>

<h2>8. phpinfo resumido</h2>
<table>
<tr><td>Usuario Apache</td><td><?= htmlspecialchars(get_current_user()) ?></td></tr>
<tr><td>SAPI</td><td><?= htmlspecialchars(php_sapi_name()) ?></td></tr>
<tr><td>OS</td><td><?= htmlspecialchars(PHP_OS) ?></td></tr>
<tr><td>Memory limit</td><td><?= htmlspecialchars(ini_get('memory_limit')) ?></td></tr>
<tr><td>Max upload</td><td><?= htmlspecialchars(ini_get('upload_max_filesize')) ?></td></tr>
<tr><td>Post max</td><td><?= htmlspecialchars(ini_get('post_max_size')) ?></td></tr>
<tr><td>display_errors</td><td><?= htmlspecialchars(ini_get('display_errors')) ?></td></tr>
<tr><td>error_log</td><td><?= htmlspecialchars(ini_get('error_log')) ?></td></tr>
</table>

<p style="margin-top:30px;padding:12px;background:#fff3cd;border-radius:6px;">
<strong>Recordatorio:</strong> borra este archivo (<code>debug.php</code>) una vez que termines de diagnosticar.
</p>
</body>
</html>
