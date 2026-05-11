<?php
/**
 * Migración 004 — Corregir FK creado_por en tickets_despacho
 *
 * PROBLEMA:
 *   tickets_despacho.creado_por tiene FK → usuarios(id) y NOT NULL
 *   Cuando un conductor crea un ticket, envía creado_por=0 porque
 *   los conductores NO son usuarios del sistema (tabla aparte).
 *   Esto rompe la FK constraint → SQLSTATE 23000 error 19.
 *
 * SOLUCIÓN:
 *   Recrear la tabla sin la FK de creado_por (ya existe conductor_id
 *   que apunta a despacho_conductores — esa es la referencia correcta).
 *   creado_por queda como INTEGER simple para compatibilidad.
 *
 * Fecha: 2026-04-28
 * Autor: Sistema DOGroup
 */
return function(PDO $pdo) {

    // Paso 1: Crear tabla temporal sin FK en creado_por
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets_despacho_v2 (
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
        destino_id            INTEGER,
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
        creado_por            INTEGER DEFAULT NULL,
        creado_por_nombre     TEXT DEFAULT '',
        creado_en             DATETIME DEFAULT (datetime('now','localtime')),
        enviado_en            DATETIME,
        validado_por          INTEGER DEFAULT NULL,
        validado_por_nombre   TEXT DEFAULT '',
        validado_en           DATETIME
    )");

    // Paso 2: Copiar todos los datos existentes
    $pdo->exec("INSERT INTO tickets_despacho_v2
        SELECT id, token, fecha, hora, ppu, ppu_id, metros_cubicos, tipo_material,
               obra_id, obra_nombre, destino, destino_id, conductor_id, conductor_nombre,
               conductor_rut, receptor_id, estado, cambio, observaciones,
               estado_revision, observacion_revision, revisado_por_ext_id,
               revisado_por_ext_nom, revisado_en, creado_por, creado_por_nombre,
               creado_en, enviado_en, validado_por, validado_por_nombre, validado_en
        FROM tickets_despacho");

    // Paso 3: Renombrar tablas
    $pdo->exec("DROP TABLE tickets_despacho");
    $pdo->exec("ALTER TABLE tickets_despacho_v2 RENAME TO tickets_despacho");
};
