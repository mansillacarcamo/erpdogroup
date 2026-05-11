<?php
/**
 * Migración 017 — Pago en cuenta editable por cotización
 * Fecha: 2026-04-30
 */
return function(PDO $pdo) {
    try {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN pago_cuenta TEXT DEFAULT ''");
    } catch(Exception $e) {}
};
