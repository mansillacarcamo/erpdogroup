<?php
/**
 * Migración 018 — Vínculo a OC externa en estados_pago
 *
 * Agrega columna oc_ext_id (referencia a cot_oc_asociaciones.id) para que un
 * estado de pago pueda quedar enlazado a una OC externa subida en el seguimiento
 * de la cotización. Antes de esto, oc_id se limpiaba al guardar y se perdía la
 * referencia al editar.
 *
 * Fecha: 2026-05-09
 */
return function(PDO $pdo) {
    $cols = $pdo->query("PRAGMA table_info(estados_pago)")->fetchAll(PDO::FETCH_ASSOC);
    $existe = false;
    foreach ($cols as $c) { if ($c['name'] === 'oc_ext_id') { $existe = true; break; } }
    if ($existe) return;
    $pdo->exec("ALTER TABLE estados_pago ADD COLUMN oc_ext_id INTEGER NULL");
};
