<?php
/**
 * Migración 014 — Campo SALIDA en tickets_despacho
 *
 * Agrega los campos para registrar el ORIGEN del carguío (la obra desde
 * donde sale el material). Igual que `obra_id`/`obra_nombre` ya existentes
 * para el destino, ahora `salida_id`/`salida_nombre` referencian la tabla obras.
 *
 * Fecha: 2026-04-30
 */
return function(PDO $pdo) {

    // Agregar columnas (idempotente — try/catch por si ya existen)
    try {
        $pdo->exec("ALTER TABLE tickets_despacho ADD COLUMN salida_id INTEGER");
    } catch(Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE tickets_despacho ADD COLUMN salida_nombre TEXT DEFAULT ''");
    } catch(Exception $e) {}

    // Índice para reportes filtrados por salida
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tickets_salida ON tickets_despacho(salida_id)");
    } catch(Exception $e) {}
};
