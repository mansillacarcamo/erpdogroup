<?php
/**
 * Migración 005 — Agregar gastos generales y utilidades a cotizaciones
 *
 * Nuevos campos:
 *   gastos_pct     REAL  — porcentaje gastos generales (default 8)
 *   gastos_monto   REAL  — monto calculado de gastos generales
 *   utilidad_pct   REAL  — porcentaje utilidades (default 10)
 *   utilidad_monto REAL  — monto calculado de utilidades
 *   total_neto     REAL  — subtotal + gastos + utilidades (antes del IVA)
 *
 * Fecha: 2026-04-28
 */
return function(PDO $pdo) {
    // Agregar columnas nuevas — try/catch para no romper si ya existen
    $cols = [
        "ALTER TABLE cotizaciones ADD COLUMN gastos_pct     REAL DEFAULT 8",
        "ALTER TABLE cotizaciones ADD COLUMN gastos_monto   REAL DEFAULT 0",
        "ALTER TABLE cotizaciones ADD COLUMN utilidad_pct   REAL DEFAULT 10",
        "ALTER TABLE cotizaciones ADD COLUMN utilidad_monto REAL DEFAULT 0",
        "ALTER TABLE cotizaciones ADD COLUMN total_neto     REAL DEFAULT 0",
    ];
    foreach ($cols as $sql) {
        try { $pdo->exec($sql); } catch (Exception $e) { /* columna ya existe */ }
    }

    // Recalcular total_neto para cotizaciones existentes con los % por defecto
    $pdo->exec("
        UPDATE cotizaciones SET
            gastos_pct     = 8,
            gastos_monto   = ROUND(subtotal * 0.08, 0),
            utilidad_pct   = 10,
            utilidad_monto = ROUND(subtotal * 0.10, 0),
            total_neto     = ROUND(subtotal * 1.18, 0)
        WHERE gastos_monto = 0 OR gastos_monto IS NULL
    ");
};
