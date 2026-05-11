<?php
/**
 * Migración 019 — Periodo de cobro en estados_pago
 *
 * Agrega periodo_desde y periodo_hasta para registrar el rango de fechas que
 * cubre cada estado de pago (por ejemplo, EDP de servicios prestados entre dos
 * fechas).
 *
 * Fecha: 2026-05-09
 */
return function(PDO $pdo) {
    $cols = $pdo->query("PRAGMA table_info(estados_pago)")->fetchAll(PDO::FETCH_ASSOC);
    $existentes = array_column($cols, 'name');
    if (!in_array('periodo_desde', $existentes, true)) {
        $pdo->exec("ALTER TABLE estados_pago ADD COLUMN periodo_desde DATE NULL");
    }
    if (!in_array('periodo_hasta', $existentes, true)) {
        $pdo->exec("ALTER TABLE estados_pago ADD COLUMN periodo_hasta DATE NULL");
    }
};
