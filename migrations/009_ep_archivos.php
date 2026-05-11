<?php
/**
 * Migración 009 — Archivos adjuntos en Estados de Pago
 * Agrega soporte para subir facturas en Ventas y Pago Proveedores
 * Fecha: 2026-04-30
 */
return function(PDO $pdo) {

    // Columna de ruta del archivo adjunto principal
    try {
        $pdo->exec("ALTER TABLE estados_pago ADD COLUMN factura_ruta TEXT DEFAULT ''");
    } catch(Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE estados_pago ADD COLUMN factura_nombre TEXT DEFAULT ''");
    } catch(Exception $e) {}

    // Tabla de múltiples archivos por estado de pago
    $pdo->exec("CREATE TABLE IF NOT EXISTS ep_archivos (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        ep_id       INTEGER NOT NULL,
        nombre      TEXT NOT NULL,
        ruta        TEXT NOT NULL,
        tipo_mime   TEXT DEFAULT '',
        tamanio     INTEGER DEFAULT 0,
        subido_por  TEXT DEFAULT '',
        subido_en   DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (ep_id) REFERENCES estados_pago(id) ON DELETE CASCADE
    )");

    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ep_arch ON ep_archivos(ep_id)");
    } catch(Exception $e) {}
};
