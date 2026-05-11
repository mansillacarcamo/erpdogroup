<?php
/**
 * Migración 002 — Módulo de despacho completo
 * PPU, conductores, tickets, externos, validaciones, cierre, reportes
 * Fecha: 2024-02-01
 */
return function(PDO $pdo) {

    // Camiones / PPU
    $pdo->exec("CREATE TABLE IF NOT EXISTS despacho_ppu (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        ppu             TEXT NOT NULL UNIQUE,
        descripcion     TEXT DEFAULT '',
        metros_cubicos  REAL DEFAULT 0,
        activo          INTEGER DEFAULT 1,
        creado_en       DATETIME DEFAULT (datetime('now','localtime'))
    )");

    // Conductores
    $pdo->exec("CREATE TABLE IF NOT EXISTS despacho_conductores (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre        TEXT NOT NULL,
        rut           TEXT DEFAULT '',
        usuario       TEXT DEFAULT '',
        password_hash TEXT DEFAULT '',
        email         TEXT DEFAULT '',
        fono          TEXT DEFAULT '',
        ppu_id        INTEGER,
        obra_id       INTEGER,
        activo        INTEGER DEFAULT 1,
        creado_en     DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (ppu_id)  REFERENCES despacho_ppu(id),
        FOREIGN KEY (obra_id) REFERENCES obras(id)
    )");

    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_cond_usuario ON despacho_conductores(usuario) WHERE usuario != ''");
    } catch(Exception $e) {}

    // Destinos
    $pdo->exec("CREATE TABLE IF NOT EXISTS despacho_destinos (
        id     INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT NOT NULL UNIQUE,
        activo INTEGER DEFAULT 1
    )");

    // Receptores (validadores internos)
    $pdo->exec("CREATE TABLE IF NOT EXISTS despacho_receptores (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id INTEGER NOT NULL UNIQUE,
        tipo       TEXT DEFAULT 'interno',
        externo_id INTEGER DEFAULT NULL,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    )");

    // Roles en el módulo despacho
    $pdo->exec("CREATE TABLE IF NOT EXISTS despacho_roles (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id INTEGER NOT NULL UNIQUE,
        rol        TEXT NOT NULL DEFAULT 'receptor',
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    )");

    // Externos / Carchek
    $pdo->exec("CREATE TABLE IF NOT EXISTS despacho_externos (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre        TEXT NOT NULL,
        empresa       TEXT DEFAULT '',
        usuario       TEXT NOT NULL UNIQUE,
        password_hash TEXT DEFAULT '',
        email         TEXT DEFAULT '',
        fono          TEXT DEFAULT '',
        obra_id       INTEGER,
        activo        INTEGER DEFAULT 1,
        creado_en     DATETIME DEFAULT (datetime('now','localtime'))
    )");

    // Encargado de operaciones
    $pdo->exec("CREATE TABLE IF NOT EXISTS despacho_encargado_operaciones (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id INTEGER,
        email_ext  TEXT DEFAULT '',
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");

    // Encargado de costos
    $pdo->exec("CREATE TABLE IF NOT EXISTS despacho_encargado_costos (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id INTEGER NOT NULL UNIQUE,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");

    // Tickets de despacho
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets_despacho (
        id                    INTEGER PRIMARY KEY AUTOINCREMENT,
        token                 TEXT UNIQUE DEFAULT (lower(hex(randomblob(16)))),
        fecha                 TEXT NOT NULL,
        hora                  TEXT NOT NULL,
        ppu                   TEXT DEFAULT '',
        ppu_id                INTEGER,
        metros_cubicos        REAL DEFAULT 0,
        tipo_material         TEXT DEFAULT '',
        obra_id               INTEGER,
        obra_nombre           TEXT DEFAULT '',
        destino               TEXT DEFAULT '',
        conductor_id          INTEGER DEFAULT NULL,
        conductor_nombre      TEXT DEFAULT '',
        conductor_rut         TEXT DEFAULT '',
        receptor_id           INTEGER,
        estado                TEXT DEFAULT 'borrador',
        cambio                TEXT DEFAULT '',
        observaciones         TEXT DEFAULT '',
        estado_revision       TEXT DEFAULT NULL,
        observacion_revision  TEXT DEFAULT '',
        revisado_por_ext_id   INTEGER DEFAULT NULL,
        revisado_por_ext_nom  TEXT DEFAULT '',
        revisado_en           TEXT DEFAULT NULL,
        creado_por            INTEGER,
        creado_por_nombre     TEXT DEFAULT '',
        creado_en             DATETIME DEFAULT (datetime('now','localtime')),
        enviado_en            DATETIME,
        validado_por          INTEGER,
        validado_por_nombre   TEXT DEFAULT '',
        validado_en           DATETIME,
        FOREIGN KEY (obra_id)      REFERENCES obras(id),
        FOREIGN KEY (conductor_id) REFERENCES despacho_conductores(id)
    )");

    // Validaciones PPU (historial de escaneos Carchek)
    $pdo->exec("CREATE TABLE IF NOT EXISTS despacho_ppu_validaciones (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        ppu_id        INTEGER,
        ppu           TEXT DEFAULT '',
        obra_id       INTEGER,
        obra_nombre   TEXT DEFAULT '',
        validado_por  TEXT DEFAULT '',
        validado_tipo TEXT DEFAULT 'externo',
        validado_en   DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (ppu_id)  REFERENCES despacho_ppu(id),
        FOREIGN KEY (obra_id) REFERENCES obras(id)
    )");

    // Cierre del día
    $pdo->exec("CREATE TABLE IF NOT EXISTS despacho_cierre_dia (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        fecha               TEXT NOT NULL UNIQUE,
        total_tickets       INTEGER DEFAULT 0,
        total_m3            REAL DEFAULT 0,
        resumen_json        TEXT DEFAULT '',
        cerrado_por         INTEGER,
        cerrado_por_nombre  TEXT DEFAULT '',
        cerrado_en          DATETIME DEFAULT (datetime('now','localtime')),
        enviado             INTEGER DEFAULT 0,
        enviado_en          DATETIME,
        FOREIGN KEY (cerrado_por) REFERENCES usuarios(id)
    )");

    // Reporte Carchek
    $pdo->exec("CREATE TABLE IF NOT EXISTS despacho_reporte_carchek (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        externo_id  INTEGER,
        fecha       TEXT NOT NULL,
        json_data   TEXT DEFAULT '',
        enviado_a   TEXT DEFAULT '',
        enviado_en  DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (externo_id) REFERENCES despacho_externos(id)
    )");

    // Asignaciones conductor ↔ camión (historial)
    $pdo->exec("CREATE TABLE IF NOT EXISTS despacho_asignaciones (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        conductor_id  INTEGER NOT NULL,
        ppu_id        INTEGER NOT NULL,
        obra_id       INTEGER,
        motivo        TEXT DEFAULT '',
        activa        INTEGER DEFAULT 1,
        asignado_por  TEXT DEFAULT '',
        fecha_desde   DATETIME DEFAULT (datetime('now','localtime')),
        fecha_hasta   DATETIME,
        creado_en     DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (conductor_id) REFERENCES despacho_conductores(id) ON DELETE CASCADE,
        FOREIGN KEY (ppu_id)       REFERENCES despacho_ppu(id)
    )");
};
