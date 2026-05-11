<?php
/**
 * Migración 007 — Módulo Gastos de Faena
 * Tablas: gastos_faena, gastos_faena_categorias, gastos_faena_archivos
 * Fecha: 2026-04-29
 */
return function(PDO $pdo) {

    // Categorías de gastos
    $pdo->exec("CREATE TABLE IF NOT EXISTS gastos_faena_categorias (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre   TEXT NOT NULL UNIQUE,
        icono    TEXT DEFAULT 'bi-receipt',
        activo   INTEGER DEFAULT 1,
        orden    INTEGER DEFAULT 0
    )");

    // Insertar categorías base
    $cats = [
        ['Combustible',     'bi-fuel-pump-fill',    1],
        ['Alimentación',    'bi-cup-hot-fill',      2],
        ['Herramientas',    'bi-tools',             3],
        ['Mano de obra',    'bi-person-badge-fill', 4],
        ['Materiales',      'bi-box-seam-fill',     5],
        ['Transporte',      'bi-truck-fill',        6],
        ['Equipos',         'bi-gear-fill',         7],
        ['Arriendo',        'bi-building-fill',     8],
        ['Servicios',       'bi-lightning-fill',    9],
        ['Otros',           'bi-three-dots',        10],
    ];
    foreach ($cats as $c) {
        try {
            $pdo->prepare("INSERT OR IGNORE INTO gastos_faena_categorias (nombre, icono, orden) VALUES (?,?,?)")
                ->execute($c);
        } catch(Exception $e) {}
    }

    // Tabla principal de gastos
    $pdo->exec("CREATE TABLE IF NOT EXISTS gastos_faena (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        fecha           TEXT NOT NULL,
        obra_id         INTEGER,
        obra_nombre     TEXT DEFAULT '',
        categoria_id    INTEGER,
        categoria_nombre TEXT DEFAULT '',
        descripcion     TEXT NOT NULL DEFAULT '',
        monto           REAL NOT NULL DEFAULT 0,
        monto_peaje     REAL NOT NULL DEFAULT 0,
        moneda          TEXT DEFAULT 'CLP',
        proveedor       TEXT DEFAULT '',
        nro_documento   TEXT DEFAULT '',
        tipo_documento  TEXT DEFAULT 'boleta',
        tiene_archivo   INTEGER DEFAULT 0,
        estado          TEXT DEFAULT 'pendiente',
        aprobado_por    INTEGER DEFAULT NULL,
        aprobado_por_nombre TEXT DEFAULT '',
        aprobado_en     DATETIME,
        observacion_aprobacion TEXT DEFAULT '',
        registrado_por  INTEGER NOT NULL,
        registrado_por_nombre TEXT DEFAULT '',
        creado_en       DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (obra_id)      REFERENCES obras(id),
        FOREIGN KEY (categoria_id) REFERENCES gastos_faena_categorias(id),
        FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
    )");

    // Agregar monto_peaje si la tabla ya existía sin ella
    try {
        $pdo->exec("ALTER TABLE gastos_faena ADD COLUMN monto_peaje REAL NOT NULL DEFAULT 0");
    } catch(Exception $e) {}

    // Archivos adjuntos (boletas, facturas, fotos)
    $pdo->exec("CREATE TABLE IF NOT EXISTS gastos_faena_archivos (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        gasto_id    INTEGER NOT NULL,
        nombre      TEXT NOT NULL,
        ruta        TEXT NOT NULL,
        tipo_mime   TEXT DEFAULT '',
        tamanio     INTEGER DEFAULT 0,
        subido_por  INTEGER,
        subido_en   DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (gasto_id) REFERENCES gastos_faena(id) ON DELETE CASCADE
    )");

    // Índices para consultas rápidas
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gf_fecha   ON gastos_faena(fecha)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gf_obra    ON gastos_faena(obra_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gf_estado  ON gastos_faena(estado)");
    } catch(Exception $e) {}
};
