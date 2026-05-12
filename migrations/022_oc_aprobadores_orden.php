<?php
/**
 * Migración 022 — Agrega columna `orden` a oc_aprobadores
 *
 * Necesaria para el flujo secuencial de validación:
 *   - Gerente Finanzas valida primero (orden = 1)
 *   - Gerente General valida segundo (orden = 2)
 *
 * En BDs antiguas la columna no existe y las consultas con LEFT JOIN
 * que la referencian fallan silenciosamente, dejando los pendientes
 * invisibles en mis_aprobaciones.php y gerente_general/validaciones.php
 *
 * Fecha: 2026-05-11
 */
return function(PDO $pdo) {
    try {
        $pdo->exec("ALTER TABLE oc_aprobadores ADD COLUMN orden INTEGER DEFAULT 1");
    } catch (Exception $e) { /* ya existe */ }
};
