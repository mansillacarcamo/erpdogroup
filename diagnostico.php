<?php
/**
 * DIAGNÓSTICO — Solo para uso temporal, eliminar después de resolver el problema
 * Accede a: tudominio.cl/diagnostico.php
 */

// Sin autenticación para poder diagnosticar desde afuera
$info = [];

// PHP
$info['php_version']   = phpversion();
$info['pdo_sqlite']    = extension_loaded('pdo_sqlite') ? '✅ OK' : '❌ NO DISPONIBLE';
$info['document_root'] = $_SERVER['DOCUMENT_ROOT'] ?? 'no definido';
$info['dir_actual']    = __DIR__;
$info['dir_padre']     = dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);

// Rutas a probar
$rutas = [
    dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/controlgastos.db' => 'Carpeta padre de public_html (recomendada)',
    __DIR__ . '/controlgastos.db'                                        => 'Misma carpeta del script (public_html)',
    sys_get_temp_dir() . '/controlgastos_test.db'                        => 'Carpeta temporal del servidor',
];

echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Diagnóstico</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:800px;margin:30px auto;padding:0 15px}';
echo 'h2{color:#0d6efd}.ok{color:#198754;font-weight:700}.err{color:#dc3545;font-weight:700}';
echo 'table{width:100%;border-collapse:collapse}td,th{padding:8px 12px;border:1px solid #dee2e6;font-size:.9rem}';
echo 'th{background:#f8f9fa;font-weight:700}.code{font-family:monospace;font-size:.85rem;background:#f8f9fa;padding:2px 6px;border-radius:3px}</style></head><body>';
echo '<h2>🔍 Diagnóstico del Sistema</h2>';

// Info PHP
echo '<h3>PHP</h3><table>';
foreach ($info as $k => $v) {
    echo '<tr><td><strong>'.htmlspecialchars($k).'</strong></td><td class="code">'.htmlspecialchars($v).'</td></tr>';
}
echo '</table>';

// Probar rutas SQLite
echo '<h3 style="margin-top:20px">Rutas SQLite</h3><table>';
echo '<tr><th>Ruta</th><th>Descripción</th><th>Directorio existe</th><th>Escritura</th><th>SQLite</th></tr>';
foreach ($rutas as $ruta => $desc) {
    $dir      = dirname($ruta);
    $dirExiste = is_dir($dir) ? '<span class="ok">✅</span>' : '<span class="err">❌</span>';
    $dirWrite  = (is_dir($dir) && is_writable($dir)) ? '<span class="ok">✅</span>' : '<span class="err">❌</span>';
    $sqliteOk  = '—';
    if (is_dir($dir) && is_writable($dir)) {
        try {
            $testPdo = new PDO('sqlite:' . $ruta);
            $testPdo->exec('CREATE TABLE IF NOT EXISTS _test (id INTEGER PRIMARY KEY)');
            $testPdo->exec('DROP TABLE _test');
            @unlink($ruta);
            $sqliteOk = '<span class="ok">✅ Funciona</span>';
        } catch(Exception $e) {
            $sqliteOk = '<span class="err">❌ '.$e->getMessage().'</span>';
        }
    }
    echo '<tr>';
    echo '<td class="code">'.htmlspecialchars($ruta).'</td>';
    echo '<td>'.htmlspecialchars($desc).'</td>';
    echo '<td>'.$dirExiste.'</td><td>'.$dirWrite.'</td><td>'.$sqliteOk.'</td>';
    echo '</tr>';
}
echo '</table>';

// Carpeta uploads
echo '<h3 style="margin-top:20px">Carpeta uploads</h3><table>';
$uDir = __DIR__ . '/uploads';
echo '<tr><td>Ruta</td><td class="code">'.htmlspecialchars($uDir).'</td></tr>';
echo '<tr><td>Existe</td><td>'.(is_dir($uDir)?'<span class="ok">✅</span>':'<span class="err">❌ No existe — crear manualmente</span>').'</td></tr>';
echo '<tr><td>Escritura</td><td>'.(is_writable($uDir)?'<span class="ok">✅</span>':'<span class="err">❌ Sin permiso de escritura — chmod 775</span>').'</td></tr>';
echo '</table>';

echo '<p style="margin-top:20px;color:#6c757d;font-size:.85rem">⚠️ Elimina este archivo (<code>diagnostico.php</code>) después de resolver el problema.</p>';
echo '</body></html>';
