<?php
/**
 * Migración 003 — Obras: supervisores, equipos y Carchek por obra
 * Fecha: 2024-03-01
 */
return function(PDO $pdo) {

    $pdo->exec("CREATE TABLE IF NOT EXISTS obra_supervisores (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        obra_id      INTEGER NOT NULL,
        usuario_id   INTEGER NOT NULL,
        asignado_por TEXT DEFAULT '',
        activo       INTEGER DEFAULT 1,
        creado_en    DATETIME DEFAULT (datetime('now','localtime')),
        UNIQUE(obra_id, usuario_id),
        FOREIGN KEY (obra_id)    REFERENCES obras(id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS obra_equipo (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        obra_id       INTEGER NOT NULL,
        conductor_id  INTEGER NOT NULL,
        supervisor_id INTEGER,
        ppu_id        INTEGER,
        activo        INTEGER DEFAULT 1,
        asignado_por  TEXT DEFAULT '',
        creado_en     DATETIME DEFAULT (datetime('now','localtime')),
        UNIQUE(obra_id, conductor_id),
        FOREIGN KEY (obra_id)      REFERENCES obras(id) ON DELETE CASCADE,
        FOREIGN KEY (conductor_id) REFERENCES despacho_conductores(id) ON DELETE CASCADE,
        FOREIGN KEY (ppu_id)       REFERENCES despacho_ppu(id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS obra_carchek (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        obra_id      INTEGER NOT NULL UNIQUE,
        externo_id   INTEGER NOT NULL,
        asignado_por TEXT DEFAULT '',
        activo       INTEGER DEFAULT 1,
        creado_en    DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (obra_id)    REFERENCES obras(id) ON DELETE CASCADE,
        FOREIGN KEY (externo_id) REFERENCES despacho_externos(id) ON DELETE CASCADE
    )");
};
