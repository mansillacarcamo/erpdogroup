<?php
/**
 * Migración 004 — DESCRIPCIÓN DEL CAMBIO
 *
 * ─────────────────────────────────────────────────────────
 *  INSTRUCCIONES PARA CREAR UNA MIGRACIÓN NUEVA:
 * ─────────────────────────────────────────────────────────
 *
 *  1. Copia este archivo y renómbralo:
 *       migrations/005_descripcion_corta.php
 *     El número debe ser el siguiente en secuencia.
 *
 *  2. Escribe el SQL dentro del closure de abajo.
 *
 *  3. Sube el archivo al servidor.
 *     Al primer arranque del sistema, se ejecuta automáticamente
 *     una sola vez y queda registrado en migrations_log.
 *
 *  4. NUNCA edites una migración ya ejecutada.
 *     Si cometiste un error, crea una migración nueva que lo corrija.
 *
 * ─────────────────────────────────────────────────────────
 *  EJEMPLOS DE USO:
 * ─────────────────────────────────────────────────────────
 *
 *  Agregar una columna:
 *    $pdo->exec("ALTER TABLE tickets_despacho ADD COLUMN turno TEXT DEFAULT ''");
 *
 *  Crear una tabla nueva:
 *    $pdo->exec("CREATE TABLE IF NOT EXISTS nueva_tabla (
 *        id       INTEGER PRIMARY KEY AUTOINCREMENT,
 *        nombre   TEXT NOT NULL,
 *        activo   INTEGER DEFAULT 1
 *    )");
 *
 *  Agregar un índice:
 *    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tickets_fecha ON tickets_despacho(fecha)");
 *
 *  Insertar datos iniciales:
 *    $pdo->exec("INSERT OR IGNORE INTO despacho_destinos (nombre) VALUES ('Planta Norte')");
 *
 * ─────────────────────────────────────────────────────────
 *  FECHA: 2024-XX-XX
 *  AUTOR: Tu nombre
 *  MOTIVO: Breve descripción de por qué se hace este cambio
 * ─────────────────────────────────────────────────────────
 */
return function(PDO $pdo) {

    // ── Escribe tus cambios aquí ──────────────────────────

    // Ejemplo: agregar columna de turno a tickets
    // try {
    //     $pdo->exec("ALTER TABLE tickets_despacho ADD COLUMN turno TEXT DEFAULT 'día'");
    // } catch(Exception $e) {
    //     // Si la columna ya existe en alguna BD antigua, lo ignoramos
    // }

    // Ejemplo: nueva tabla
    // $pdo->exec("CREATE TABLE IF NOT EXISTS mi_tabla_nueva (
    //     id        INTEGER PRIMARY KEY AUTOINCREMENT,
    //     nombre    TEXT NOT NULL,
    //     creado_en DATETIME DEFAULT (datetime('now','localtime'))
    // )");

};
