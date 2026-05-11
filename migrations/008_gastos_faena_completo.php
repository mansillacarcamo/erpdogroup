<?php
/**
 * Migración 008 — Gastos de Faena completo
 * - Presupuestos por supervisor/mes
 * - Asignaciones supervisor → conductor/operador
 * - Columnas adicionales en gastos_faena
 * - Operadores de faena (nuevo tipo de usuario)
 * Fecha: 2026-04-29
 */
return function(PDO $pdo) {

    // Agregar columnas faltantes a gastos_faena
    $nuevasCols = [
        "ALTER TABLE gastos_faena ADD COLUMN monto_peaje REAL NOT NULL DEFAULT 0",
        "ALTER TABLE gastos_faena ADD COLUMN tipo_usuario TEXT DEFAULT 'interno'",
        "ALTER TABLE gastos_faena ADD COLUMN conductor_id INTEGER DEFAULT NULL",
        "ALTER TABLE gastos_faena ADD COLUMN operador_id INTEGER DEFAULT NULL",
        "ALTER TABLE gastos_faena ADD COLUMN supervisor_id INTEGER DEFAULT NULL",
        "ALTER TABLE gastos_faena ADD COLUMN aprobado_supervisor INTEGER DEFAULT 0",
        "ALTER TABLE gastos_faena ADD COLUMN aprobado_supervisor_en DATETIME",
        "ALTER TABLE gastos_faena ADD COLUMN asignacion_id INTEGER DEFAULT NULL",
    ];
    foreach ($nuevasCols as $sql) {
        try { $pdo->exec($sql); } catch(Exception $e) {}
    }

    // Operadores de faena (distintos de conductores)
    $pdo->exec("CREATE TABLE IF NOT EXISTS faena_operadores (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre        TEXT NOT NULL,
        rut           TEXT DEFAULT '',
        usuario       TEXT NOT NULL UNIQUE,
        password_hash TEXT DEFAULT '',
        email         TEXT DEFAULT '',
        fono          TEXT DEFAULT '',
        obra_id       INTEGER DEFAULT NULL,
        supervisor_id INTEGER DEFAULT NULL,
        activo        INTEGER DEFAULT 1,
        creado_en     DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (obra_id)      REFERENCES obras(id),
        FOREIGN KEY (supervisor_id) REFERENCES usuarios(id)
    )");

    // Presupuestos mensuales: admin → supervisor
    $pdo->exec("CREATE TABLE IF NOT EXISTS faena_presupuestos (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        supervisor_id  INTEGER NOT NULL,
        obra_id        INTEGER,
        anio           INTEGER NOT NULL,
        mes            INTEGER NOT NULL,
        monto_asignado REAL NOT NULL DEFAULT 0,
        monto_gastado  REAL NOT NULL DEFAULT 0,
        asignado_por   INTEGER,
        creado_en      DATETIME DEFAULT (datetime('now','localtime')),
        UNIQUE(supervisor_id, anio, mes),
        FOREIGN KEY (supervisor_id) REFERENCES usuarios(id),
        FOREIGN KEY (obra_id)       REFERENCES obras(id),
        FOREIGN KEY (asignado_por)  REFERENCES usuarios(id)
    )");

    // Asignaciones supervisor → conductor/operador
    $pdo->exec("CREATE TABLE IF NOT EXISTS faena_asignaciones (
        id             INTEGER PRIMARY KEY AUTOINCREMENT,
        presupuesto_id INTEGER NOT NULL,
        supervisor_id  INTEGER NOT NULL,
        tipo_receptor  TEXT NOT NULL DEFAULT 'conductor',
        conductor_id   INTEGER DEFAULT NULL,
        operador_id    INTEGER DEFAULT NULL,
        nombre_receptor TEXT DEFAULT '',
        obra_id        INTEGER,
        anio           INTEGER NOT NULL,
        mes            INTEGER NOT NULL,
        monto_asignado REAL NOT NULL DEFAULT 0,
        monto_gastado  REAL NOT NULL DEFAULT 0,
        activa         INTEGER DEFAULT 1,
        creado_en      DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (presupuesto_id) REFERENCES faena_presupuestos(id),
        FOREIGN KEY (supervisor_id)  REFERENCES usuarios(id),
        FOREIGN KEY (conductor_id)   REFERENCES despacho_conductores(id),
        FOREIGN KEY (operador_id)    REFERENCES faena_operadores(id)
    )");

    // Índices
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fp_sup_mes ON faena_presupuestos(supervisor_id,anio,mes)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_fa_asig    ON faena_asignaciones(presupuesto_id,activa)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gf_asig    ON gastos_faena(asignacion_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gf_cond    ON gastos_faena(conductor_id)");
    } catch(Exception $e) {}
};
