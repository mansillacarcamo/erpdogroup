<?php
/**
 * Migración 010 — OC Externa en seguimiento de cotizaciones
 * Permite vincular OC del sistema O subir una OC de empresa externa
 * Fecha: 2026-04-30
 */
return function(PDO $pdo) {

    // Agregar columnas para OC externa en la tabla de asociaciones
    $nuevasCols = [
        "ALTER TABLE cot_oc_asociaciones ADD COLUMN es_externa INTEGER DEFAULT 0",
        "ALTER TABLE cot_oc_asociaciones ADD COLUMN oc_ext_numero TEXT DEFAULT ''",
        "ALTER TABLE cot_oc_asociaciones ADD COLUMN oc_ext_empresa TEXT DEFAULT ''",
        "ALTER TABLE cot_oc_asociaciones ADD COLUMN oc_ext_monto REAL DEFAULT 0",
        "ALTER TABLE cot_oc_asociaciones ADD COLUMN oc_ext_archivo TEXT DEFAULT ''",
        "ALTER TABLE cot_oc_asociaciones ADD COLUMN oc_ext_archivo_nombre TEXT DEFAULT ''",
    ];
    foreach ($nuevasCols as $sql) {
        try { $pdo->exec($sql); } catch(Exception $e) {}
    }

    // Carpeta de uploads para OC externas
    $dir = dirname(__FILE__, 2) . '/uploads/oc_externas';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
};
