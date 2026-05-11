<?php
/**
 * ============================================================
 *  RUNNER DE MIGRACIONES — DOGroup
 * ============================================================
 *  Cómo funciona:
 *  1. Crea la tabla migrations_log si no existe
 *  2. Lee todos los archivos en /migrations/*.php (ordenados)
 *  3. Compara contra migrations_log
 *  4. Ejecuta solo los que NO están en el log
 *  5. Registra cada ejecución con fecha y hora
 *
 *  Para agregar un cambio a la BD:
 *  → Crea un archivo migrations/004_descripcion.php
 *  → Retorna un closure que recibe $pdo y ejecuta el SQL
 *  → Sube el archivo — se aplica automáticamente al próximo arranque
 *  → NUNCA edites migraciones ya ejecutadas
 * ============================================================
 */

function runMigrations(PDO $pdo): void {

    // Tabla de control — se crea una sola vez
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations_log (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            archivo      TEXT NOT NULL UNIQUE,
            ejecutada_en DATETIME DEFAULT (datetime('now','localtime')),
            duracion_ms  INTEGER DEFAULT 0,
            estado       TEXT DEFAULT 'ok'
        )
    ");

    $dir = __DIR__ . '/migrations';
    if (!is_dir($dir)) return;

    // Leer archivos de migración ordenados por nombre
    $archivos = glob($dir . '/*.php');
    if (empty($archivos)) return;
    sort($archivos);

    // Qué migraciones ya se ejecutaron
    $ejecutadas = $pdo->query("SELECT archivo FROM migrations_log WHERE estado='ok'")
                      ->fetchAll(PDO::FETCH_COLUMN);
    $ejecutadas = array_flip($ejecutadas);

    foreach ($archivos as $ruta) {
        $nombre = basename($ruta);

        // Saltar si ya se ejecutó
        if (isset($ejecutadas[$nombre])) continue;

        // Cargar y ejecutar la migración
        $inicio = microtime(true);
        $estado = 'ok';
        $error  = '';

        try {
            $migration = require $ruta;

            if (!is_callable($migration)) {
                throw new Exception("El archivo $nombre no retorna un callable.");
            }

            $pdo->beginTransaction();
            $migration($pdo);
            $pdo->commit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $estado = 'error';
            $error  = $e->getMessage();
            // Registrar el error pero no frenar todo el sistema
            error_log("[MIGRACIÓN ERROR] $nombre: $error");
        }

        $duracion = (int)((microtime(true) - $inicio) * 1000);

        // Registrar en el log
        $pdo->prepare("
            INSERT OR IGNORE INTO migrations_log (archivo, duracion_ms, estado)
            VALUES (?, ?, ?)
        ")->execute([$nombre, $duracion, $estado]);
    }
}
