<?php
/**
 * Migración 017 — Permitir oc_id NULL en cot_oc_asociaciones
 *
 * Las OC externas (es_externa = 1) no apuntan a una OC del sistema, por lo que
 * oc_id debe poder ser NULL. Originalmente la tabla tenía oc_id NOT NULL +
 * FOREIGN KEY a ordenes_compra(id) — ambas constraints rompían el INSERT de OC
 * externas.
 *
 * Fecha: 2026-05-09
 */
return function(PDO $pdo) {

    $cols = $pdo->query("PRAGMA table_info(cot_oc_asociaciones)")->fetchAll(PDO::FETCH_ASSOC);
    $ocIdCol = null;
    foreach ($cols as $c) { if ($c['name'] === 'oc_id') { $ocIdCol = $c; break; } }
    // Si la columna ya permite NULL, no hacemos nada
    if ($ocIdCol && (int)$ocIdCol['notnull'] === 0) return;

    $pdo->exec("PRAGMA foreign_keys = OFF");
    $pdo->exec("ALTER TABLE cot_oc_asociaciones RENAME TO cot_oc_asociaciones_old");
    $pdo->exec("CREATE TABLE cot_oc_asociaciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cot_id INTEGER NOT NULL,
        oc_id INTEGER NULL,
        nota TEXT,
        usuario TEXT NOT NULL,
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
        es_externa INTEGER DEFAULT 0,
        oc_ext_numero TEXT DEFAULT '',
        oc_ext_empresa TEXT DEFAULT '',
        oc_ext_monto REAL DEFAULT 0,
        oc_ext_archivo TEXT DEFAULT '',
        oc_ext_archivo_nombre TEXT DEFAULT '',
        FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE,
        FOREIGN KEY (oc_id) REFERENCES ordenes_compra(id) ON DELETE CASCADE
    )");
    $pdo->exec("INSERT INTO cot_oc_asociaciones
        (id, cot_id, oc_id, nota, usuario, fecha, es_externa,
         oc_ext_numero, oc_ext_empresa, oc_ext_monto, oc_ext_archivo, oc_ext_archivo_nombre)
        SELECT id, cot_id,
               CASE WHEN COALESCE(oc_id,0) = 0 THEN NULL ELSE oc_id END,
               nota, usuario, fecha, es_externa,
               oc_ext_numero, oc_ext_empresa, oc_ext_monto, oc_ext_archivo, oc_ext_archivo_nombre
        FROM cot_oc_asociaciones_old");
    $pdo->exec("DROP TABLE cot_oc_asociaciones_old");
    $pdo->exec("PRAGMA foreign_keys = ON");
};
