<?php
/**
 * Migración 006 — Log de validaciones Carchek
 *
 * Crea una tabla dedicada que registra cada acción de Carchek:
 * escaneo QR, revisión (aprobado/rechazado/observación).
 * Permite ver el historial del día en tiempo real y enviarlo al final.
 *
 * Fecha: 2026-04-28
 */
return function(PDO $pdo) {

    $pdo->exec("CREATE TABLE IF NOT EXISTS carchek_log (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        externo_id        INTEGER NOT NULL,
        externo_nombre    TEXT NOT NULL DEFAULT '',
        ticket_id         INTEGER NOT NULL,
        fecha             TEXT NOT NULL,
        hora              TEXT NOT NULL,
        ppu               TEXT DEFAULT '',
        conductor_nombre  TEXT DEFAULT '',
        obra_nombre       TEXT DEFAULT '',
        tipo_material     TEXT DEFAULT '',
        metros_cubicos    REAL DEFAULT 0,
        destino           TEXT DEFAULT '',
        accion            TEXT NOT NULL DEFAULT 'validado',
        estado_revision   TEXT DEFAULT NULL,
        observacion       TEXT DEFAULT '',
        registrado_en     DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (externo_id) REFERENCES despacho_externos(id) ON DELETE CASCADE,
        FOREIGN KEY (ticket_id)  REFERENCES tickets_despacho(id)  ON DELETE CASCADE
    )");

    // Índice para consultas rápidas por día y validador
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_carchek_log_fecha ON carchek_log(externo_id, fecha)");
    } catch(Exception $e) {}

    // Poblar con datos existentes de tickets ya validados/revisados
    $pdo->exec("
        INSERT OR IGNORE INTO carchek_log
            (externo_id, externo_nombre, ticket_id, fecha, hora, ppu, conductor_nombre,
             obra_nombre, tipo_material, metros_cubicos, destino,
             accion, estado_revision, observacion, registrado_en)
        SELECT
            de.id, de.nombre,
            td.id, td.fecha, COALESCE(SUBSTR(td.validado_en,12,5), td.hora),
            td.ppu, td.conductor_nombre, td.obra_nombre,
            td.tipo_material, td.metros_cubicos, td.destino,
            'validado',
            td.estado_revision,
            COALESCE(td.observacion_revision,''),
            COALESCE(td.validado_en, datetime('now','localtime'))
        FROM tickets_despacho td
        JOIN despacho_externos de ON de.nombre = td.validado_por_nombre
           OR de.id = td.revisado_por_ext_id
        WHERE td.estado = 'validado'
          AND td.validado_en IS NOT NULL
    ");
};
