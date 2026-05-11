<?php
// ============================================================
//  DB HEALTHCHECK - Test integral de las bases de datos
//  Verifica: integridad, FKs, CRUD ciclo completo, permisos.
//  Uso: php tools/db_healthcheck.php
//  Solo lee + escribe en transaccion con ROLLBACK (no afecta datos reales)
// ============================================================

$BASE = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');

$DBS = [
    'ocdogroup.db'      => $BASE . DIRECTORY_SEPARATOR . 'ocdogroup.db',
    'controlgastos.db'  => $BASE . DIRECTORY_SEPARATOR . 'gastos_diarios' . DIRECTORY_SEPARATOR . 'controlgastos.db',
];

$UPLOAD_DIRS = [
    'uploads/ocs'              => $BASE . '/uploads/ocs',
    'uploads/cot'              => $BASE . '/uploads/cot',
    'uploads/estados_pago'     => $BASE . '/uploads/estados_pago',
    'uploads/gastos_faena'     => $BASE . '/uploads/gastos_faena',
    'uploads/tickets'          => $BASE . '/uploads/tickets',
    'uploads/perfiles'         => $BASE . '/uploads/perfiles',
    'gastos_diarios/uploads'   => $BASE . '/gastos_diarios/uploads',
];

// ── Helpers de salida ──
$pass = 0; $fail = 0; $warn = 0;
function ok($msg)   { global $pass; $pass++; echo "  \033[32m✓\033[0m $msg\n"; }
function err($msg)  { global $fail; $fail++; echo "  \033[31m✗\033[0m $msg\n"; }
function wn($msg)   { global $warn; $warn++; echo "  \033[33m!\033[0m $msg\n"; }
function info($msg) { echo "  · $msg\n"; }
function h1($t)     { echo "\n\033[1;34m== $t ==\033[0m\n"; }
function h2($t)     { echo "\n  \033[1m$t\033[0m\n"; }

// Soporte ANSI en Windows
if (PHP_OS_FAMILY === 'Windows') {
    @sapi_windows_vt100_support(STDOUT, true);
}

echo "\n\033[1;36m╔══════════════════════════════════════════════════════╗\n";
echo "║   DB HEALTHCHECK · Sistemas DOGroup                  ║\n";
echo "║   ".date('Y-m-d H:i:s')."                                  ║\n";
echo "╚══════════════════════════════════════════════════════╝\033[0m\n";

foreach ($DBS as $nombre => $path) {
    h1("Base de datos: $nombre");
    if (!is_file($path)) { err("Archivo no existe: $path"); continue; }
    $sz = filesize($path);
    info("Ruta: $path");
    info("Tamaño: " . number_format($sz/1024, 1) . " KB");
    info("Permisos: " . substr(sprintf('%o', fileperms($path)), -4));

    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Exception $e) {
        err("No se pudo abrir: " . $e->getMessage()); continue;
    }

    // ── 1. PRAGMAS críticos
    h2("1. Configuración del motor");
    // Activar FKs para reflejar el comportamiento real de la app (que las activa en config.php)
    $pdo->exec("PRAGMA foreign_keys = ON");
    $jm   = $pdo->query("PRAGMA journal_mode")->fetchColumn();
    $sync = $pdo->query("PRAGMA synchronous")->fetchColumn();
    $busy = $pdo->query("PRAGMA busy_timeout")->fetchColumn();
    $fks  = $pdo->query("PRAGMA foreign_keys")->fetchColumn();
    if ($jm === 'wal') ok("journal_mode = wal (concurrencia OK)");
    else               wn("journal_mode = $jm (recomendado: wal)");
    if ((int)$sync >= 1) ok("synchronous = $sync (durabilidad OK)");
    else                 wn("synchronous = $sync (riesgo de pérdida tras corte de luz)");
    info("busy_timeout = {$busy}ms");
    if ((int)$fks === 1) ok("foreign_keys ON (validación referencial activa)");
    else                 wn("foreign_keys=0 — verifica que config.php aplique 'PRAGMA foreign_keys=ON'");

    // ── 2. Integridad estructural
    h2("2. Integridad estructural");
    $intRes = $pdo->query("PRAGMA integrity_check")->fetchColumn();
    if ($intRes === 'ok') ok("integrity_check = ok (DB sin corrupción)");
    else                  err("integrity_check FALLIDO: $intRes");

    $qcRes = $pdo->query("PRAGMA quick_check")->fetchColumn();
    if ($qcRes === 'ok') ok("quick_check = ok");
    else                 err("quick_check FALLIDO: $qcRes");

    // ── 3. Foreign Key consistency
    h2("3. Consistencia de Foreign Keys");
    $pdo->exec("PRAGMA foreign_keys = ON");
    $fkViol = $pdo->query("PRAGMA foreign_key_check")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($fkViol)) ok("Sin filas huérfanas (FKs íntegras)");
    else {
        err(count($fkViol) . " violación(es) de FK encontrada(s):");
        foreach (array_slice($fkViol, 0, 5) as $v) {
            info("  Tabla {$v['table']} fila {$v['rowid']} → ref a {$v['parent']} con FK#{$v['fkid']} INVÁLIDA");
        }
    }

    // ── 4. Tablas y conteos
    h2("4. Inventario de tablas");
    $tabs = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    info(count($tabs) . " tablas");
    $totalRows = 0;
    foreach ($tabs as $t) {
        $c = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $totalRows += $c;
    }
    ok("Total filas: " . number_format($totalRows));

    // ── 5. Test CRUD ciclo completo (en transacción con ROLLBACK)
    h2("5. CRUD ciclo completo (TX con ROLLBACK)");
    try {
        $pdo->beginTransaction();
        $marca = '__healthcheck_' . uniqid() . '__';

        // Buscar una tabla simple con columnas TEXT donde podamos hacer el test
        // Usamos categorias_gasto si existe (tiene nombre TEXT UNIQUE)
        $tablaTest = null;
        if (in_array('categorias_gasto', $tabs)) {
            $tablaTest = 'categorias_gasto';
            // INSERT
            $pdo->prepare("INSERT INTO categorias_gasto (nombre, icono, color) VALUES (?,?,?)")
                ->execute([$marca, 'bi-test', '#000000']);
            $idTest = (int)$pdo->lastInsertId();
            if ($idTest > 0) ok("INSERT OK (id=$idTest en categorias_gasto)");
            else { err("INSERT no devolvió ID"); $pdo->rollBack(); continue; }

            // SELECT
            $st = $pdo->prepare("SELECT nombre, icono FROM categorias_gasto WHERE id=?");
            $st->execute([$idTest]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['nombre'] === $marca) ok("SELECT OK (lectura coincide)");
            else err("SELECT FALLO (lectura no coincide)");

            // UPDATE
            $pdo->prepare("UPDATE categorias_gasto SET icono=? WHERE id=?")
                ->execute(['bi-updated', $idTest]);
            $st->execute([$idTest]);
            $r2 = $st->fetch(PDO::FETCH_ASSOC);
            if ($r2 && $r2['icono'] === 'bi-updated') ok("UPDATE OK (cambio persistido)");
            else err("UPDATE FALLO");

            // DELETE
            $pdo->prepare("DELETE FROM categorias_gasto WHERE id=?")->execute([$idTest]);
            $st->execute([$idTest]);
            $r3 = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r3) ok("DELETE OK (fila eliminada)");
            else err("DELETE FALLO");

        } elseif (in_array('obras', $tabs)) {
            $tablaTest = 'obras';
            $pdo->prepare("INSERT INTO obras (codigo, nombre) VALUES (?,?)")
                ->execute(['__HC_'.uniqid(), $marca]);
            $idTest = (int)$pdo->lastInsertId();
            if ($idTest > 0) ok("INSERT OK (id=$idTest en obras)");

            $r = $pdo->prepare("SELECT nombre FROM obras WHERE id=?");
            $r->execute([$idTest]);
            $row = $r->fetch();
            if ($row['nombre'] === $marca) ok("SELECT OK");

            $pdo->prepare("UPDATE obras SET nombre=? WHERE id=?")->execute([$marca.'_v2', $idTest]);
            $r->execute([$idTest]);
            $row = $r->fetch();
            if ($row['nombre'] === $marca.'_v2') ok("UPDATE OK");

            $pdo->prepare("DELETE FROM obras WHERE id=?")->execute([$idTest]);
            ok("DELETE OK");
        }

        // Test de transacción explícito: el rollback debe deshacerlo todo
        $pdo->rollBack();

        // Verificar que efectivamente no quedó nada con la marca
        if ($tablaTest === 'categorias_gasto') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM categorias_gasto WHERE nombre LIKE '__healthcheck_%'");
            $st->execute();
            $left = (int)$st->fetchColumn();
            if ($left === 0) ok("ROLLBACK OK (DB intacta tras test)");
            else err("ROLLBACK FALLO (quedaron $left filas de prueba)");
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        err("Excepción durante CRUD: " . $e->getMessage());
    }

    // ── 6. Indices
    h2("6. Índices");
    $idx = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name NOT LIKE 'sqlite_autoindex_%'")->fetchColumn();
    if ($idx > 0) ok("$idx índices definidos (excluye autoindex)");
    else          wn("Sin índices personalizados");

    // ── 7. Tabla mas grande
    h2("7. Tabla más activa");
    $maxT = ['n'=>'-','c'=>0];
    foreach ($tabs as $t) {
        $c = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        if ($c > $maxT['c']) $maxT = ['n'=>$t, 'c'=>$c];
    }
    info("Más filas: {$maxT['n']} (".number_format($maxT['c'])." filas)");

    $pdo = null;
}

// ── 8. Permisos de carpetas de uploads
h1("Carpetas de uploads (lectura/escritura)");
foreach ($UPLOAD_DIRS as $tag => $dir) {
    if (!is_dir($dir)) {
        wn("$tag → no existe (puede crearse al primer uso)");
        continue;
    }
    $writable = is_writable($dir);
    $ftest = $dir . '/.healthcheck_' . uniqid();
    $can = @file_put_contents($ftest, 'ok') !== false;
    if ($can) @unlink($ftest);
    if ($writable && $can) ok("$tag → escribible ✓");
    elseif ($writable)     wn("$tag → marcado como escribible pero el test falló");
    else                   err("$tag → NO escribible (problema de permisos)");
}

// ── 9. Versiones de PHP / extensiones
h1("Entorno PHP");
info("PHP: " . PHP_VERSION);
$exts = ['pdo_sqlite', 'sqlite3', 'json', 'mbstring'];
foreach ($exts as $e) {
    if (extension_loaded($e)) ok("ext: $e cargada");
    else                       err("ext: $e FALTA");
}
$sqliteVer = SQLite3::version()['versionString'] ?? 'desconocida';
info("SQLite: $sqliteVer");

// ── 10. Resumen
echo "\n\033[1;36m╔══════════════════════════════════════════════════════╗\n";
echo "║   RESUMEN                                            ║\n";
echo "╚══════════════════════════════════════════════════════╝\033[0m\n";
printf("  \033[32m✓ Pasaron : %d\033[0m\n", $pass);
printf("  \033[33m! Avisos  : %d\033[0m\n", $warn);
printf("  \033[31m✗ Fallaron: %d\033[0m\n", $fail);

if ($fail === 0 && $warn <= 1) {
    echo "\n\033[1;32m  ✓ Sistema saludable. Listo para producción.\033[0m\n\n";
    exit(0);
} elseif ($fail === 0) {
    echo "\n\033[1;33m  ! Sistema operativo con advertencias menores.\033[0m\n\n";
    exit(0);
} else {
    echo "\n\033[1;31m  ✗ Hay fallos críticos que revisar antes de producción.\033[0m\n\n";
    exit(2);
}
