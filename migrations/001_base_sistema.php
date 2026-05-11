<?php
/**
 * Migración 001 — Tablas base del sistema
 * Usuarios, OC, cotizaciones, clientes, proveedores, obras, estados de pago
 * Fecha: 2024-01-01
 */
return function(PDO $pdo) {

    // Usuarios
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre        TEXT NOT NULL,
        usuario       TEXT NOT NULL UNIQUE,
        clave         TEXT NOT NULL,
        rol           TEXT NOT NULL DEFAULT 'usuario',
        cargo         TEXT DEFAULT '',
        email         TEXT DEFAULT '',
        activo        INTEGER DEFAULT 1,
        creado_en     DATETIME DEFAULT (datetime('now','localtime'))
    )");

    // Órdenes de Compra
    $pdo->exec("CREATE TABLE IF NOT EXISTS ordenes_compra (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        numero        TEXT NOT NULL UNIQUE,
        fecha         TEXT NOT NULL,
        proveedor     TEXT DEFAULT '',
        proveedor_id  INTEGER,
        obra_id       INTEGER,
        obra_codigo   TEXT DEFAULT '',
        moneda        TEXT DEFAULT 'CLP',
        tipo_cambio   REAL DEFAULT 1,
        subtotal      REAL DEFAULT 0,
        iva           REAL DEFAULT 0,
        total         REAL DEFAULT 0,
        estado        TEXT DEFAULT 'borrador',
        creado_por    INTEGER,
        creado_en     DATETIME DEFAULT (datetime('now','localtime')),
        observaciones TEXT DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS oc_items (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        oc_id         INTEGER NOT NULL,
        descripcion   TEXT NOT NULL,
        cantidad      REAL DEFAULT 1,
        unidad        TEXT DEFAULT '',
        precio_unit   REAL DEFAULT 0,
        total         REAL DEFAULT 0,
        FOREIGN KEY (oc_id) REFERENCES ordenes_compra(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS oc_aprobaciones (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        oc_id         INTEGER NOT NULL,
        usuario_id    INTEGER NOT NULL,
        estado        TEXT DEFAULT 'pendiente',
        comentario    TEXT DEFAULT '',
        respondido_en DATETIME,
        FOREIGN KEY (oc_id) REFERENCES ordenes_compra(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS oc_aprobadores (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id    INTEGER NOT NULL UNIQUE,
        orden         INTEGER DEFAULT 1,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS oc_envios (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        oc_id         INTEGER NOT NULL,
        enviado_a     TEXT NOT NULL,
        enviado_en    DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (oc_id) REFERENCES ordenes_compra(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS oc_notificaciones (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        oc_id         INTEGER NOT NULL,
        destinatario_id INTEGER NOT NULL,
        enviado_por   TEXT DEFAULT '',
        contenido     TEXT DEFAULT '',
        leida         INTEGER DEFAULT 0,
        creado_en     DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (oc_id) REFERENCES ordenes_compra(id) ON DELETE CASCADE
    )");

    // Cotizaciones
    $pdo->exec("CREATE TABLE IF NOT EXISTS cotizaciones (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        numero          TEXT NOT NULL UNIQUE,
        fecha           TEXT NOT NULL,
        cliente_id      INTEGER,
        cliente_nombre  TEXT DEFAULT '',
        cliente_rut     TEXT DEFAULT '',
        cliente_email   TEXT DEFAULT '',
        cliente_ciudad  TEXT DEFAULT '',
        obra_id         INTEGER,
        moneda          TEXT DEFAULT 'CLP',
        tipo_cambio     REAL DEFAULT 1,
        subtotal        REAL DEFAULT 0,
        iva             REAL DEFAULT 0,
        total           REAL DEFAULT 0,
        estado          TEXT DEFAULT 'borrador',
        validez_dias    INTEGER DEFAULT 30,
        creado_por      INTEGER,
        creado_en       DATETIME DEFAULT (datetime('now','localtime')),
        observaciones   TEXT DEFAULT ''
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cot_items (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        cot_id        INTEGER NOT NULL,
        descripcion   TEXT NOT NULL,
        cantidad      REAL DEFAULT 1,
        unidad        TEXT DEFAULT '',
        precio_unit   REAL DEFAULT 0,
        total         REAL DEFAULT 0,
        FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cot_procesos (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        cot_id          INTEGER NOT NULL,
        descripcion     TEXT DEFAULT '',
        estado_proceso  TEXT DEFAULT 'pendiente',
        FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cot_asociaciones (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        cot_id    INTEGER NOT NULL,
        oc_id     INTEGER,
        FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cot_oc_asociaciones (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        cot_id    INTEGER NOT NULL,
        oc_id     INTEGER NOT NULL,
        UNIQUE(cot_id, oc_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cot_aprobaciones (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        cot_id        INTEGER NOT NULL,
        usuario_id    INTEGER NOT NULL,
        estado        TEXT DEFAULT 'pendiente',
        comentario    TEXT DEFAULT '',
        respondido_en DATETIME,
        FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cot_envios (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        cot_id        INTEGER NOT NULL,
        enviado_a     TEXT NOT NULL,
        enviado_en    DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cot_notificaciones (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        cot_id          INTEGER NOT NULL,
        destinatario_id INTEGER NOT NULL,
        enviado_por     TEXT DEFAULT '',
        contenido       TEXT DEFAULT '',
        leida           INTEGER DEFAULT 0,
        creado_en       DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cot_archivos (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        cot_id      INTEGER NOT NULL,
        nombre      TEXT NOT NULL,
        ruta        TEXT NOT NULL,
        subido_por  INTEGER,
        subido_en   DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
    )");

    // Clientes
    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre    TEXT NOT NULL,
        rut       TEXT DEFAULT '',
        email     TEXT DEFAULT '',
        telefono  TEXT DEFAULT '',
        ciudad    TEXT DEFAULT '',
        direccion TEXT DEFAULT '',
        activo    INTEGER DEFAULT 1
    )");

    // Proveedores
    $pdo->exec("CREATE TABLE IF NOT EXISTS proveedores (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre    TEXT NOT NULL,
        rut       TEXT DEFAULT '',
        email     TEXT DEFAULT '',
        telefono  TEXT DEFAULT '',
        ciudad    TEXT DEFAULT '',
        direccion TEXT DEFAULT '',
        activo    INTEGER DEFAULT 1
    )");

    // Obras
    $pdo->exec("CREATE TABLE IF NOT EXISTS obras (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        codigo      TEXT NOT NULL UNIQUE,
        nombre      TEXT NOT NULL,
        ciudad      TEXT DEFAULT '',
        direccion   TEXT DEFAULT '',
        estado      TEXT DEFAULT 'activa',
        creado_en   DATETIME DEFAULT (datetime('now','localtime'))
    )");

    // Estados de pago
    $pdo->exec("CREATE TABLE IF NOT EXISTS estados_pago (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        numero        TEXT NOT NULL,
        obra_id       INTEGER,
        oc_id         INTEGER,
        cot_id        INTEGER,
        descripcion   TEXT DEFAULT '',
        monto         REAL DEFAULT 0,
        moneda        TEXT DEFAULT 'CLP',
        estado        TEXT DEFAULT 'pendiente',
        fecha_emision TEXT,
        fecha_pago    TEXT,
        creado_por    INTEGER,
        creado_en     DATETIME DEFAULT (datetime('now','localtime'))
    )");

    // Módulos por usuario
    $pdo->exec("CREATE TABLE IF NOT EXISTS usuario_modulos (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id INTEGER NOT NULL,
        modulo     TEXT NOT NULL,
        activo     INTEGER DEFAULT 1,
        UNIQUE(usuario_id, modulo),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    )");
};
