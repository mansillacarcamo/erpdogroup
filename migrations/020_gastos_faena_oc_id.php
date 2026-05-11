<?php
/**
 * Migración 020 — Vincular gastos_faena a una OC del sistema
 *
 * Permite asociar un gasto de faena a una orden de compra registrada por
 * nosotros (servicios, arriendos, materiales, etc.) para tener trazabilidad
 * desde el estado de pago hasta la OC original.
 *
 * Fecha: 2026-05-09
 */
return function(PDO $pdo) {
    $cols = $pdo->query("PRAGMA table_info(gastos_faena)")->fetchAll(PDO::FETCH_ASSOC);
    $existentes = array_column($cols, 'name');
    if (!in_array('oc_id', $existentes, true)) {
        $pdo->exec("ALTER TABLE gastos_faena ADD COLUMN oc_id INTEGER NULL");
    }
};
