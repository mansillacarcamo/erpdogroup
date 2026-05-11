<?php
/**
 * Migración 013 — Módulo Distribución de Combustible
 *
 *  - combustible_responsables: usuarios que operan la bomba/storage en obra
 *  - combustible_hojas       : encabezado de la hoja diaria del talonario DO
 *  - combustible_vales       : detalle (15 líneas por hoja, ampliable)
 *  - combustible_admins      : quiénes son admin del módulo
 *
 *  El módulo se llama 'combustible' (registrado vía config.php → modulosDisponibles()).
 *  Las hojas reflejan exactamente el formato del papel "Distribución de Combustible".
 *
 *  Fecha: 2026-04-30
 */
return function(PDO $pdo) {

    // ── RESPONSABLES ─────────────────────────────────────────
    // Cada Responsable está asociado a un usuario del sistema y opcionalmente a una obra.
    $pdo->exec("CREATE TABLE IF NOT EXISTS combustible_responsables (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id   INTEGER,
        nombre       TEXT NOT NULL,
        rut          TEXT DEFAULT '',
        telefono     TEXT DEFAULT '',
        obra_id      INTEGER,
        obra_nombre  TEXT DEFAULT '',
        activo       INTEGER NOT NULL DEFAULT 1,
        creado_por   INTEGER,
        creado_en    DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
        FOREIGN KEY (obra_id)    REFERENCES obras(id),
        FOREIGN KEY (creado_por) REFERENCES usuarios(id)
    )");

    // ── ADMIN DEL MÓDULO ─────────────────────────────────────
    // Permite designar admins del módulo aparte del rol global 'admin'.
    $pdo->exec("CREATE TABLE IF NOT EXISTS combustible_admins (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id  INTEGER NOT NULL UNIQUE,
        creado_por  INTEGER,
        creado_en   DATETIME DEFAULT (datetime('now','localtime')),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
        FOREIGN KEY (creado_por) REFERENCES usuarios(id)
    )");

    // ── HOJA DIARIA (encabezado del formulario impreso) ──────
    $pdo->exec("CREATE TABLE IF NOT EXISTS combustible_hojas (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        fecha             DATE NOT NULL,
        responsable_id    INTEGER,
        responsable_nombre TEXT DEFAULT '',
        obra_id           INTEGER,
        obra_nombre       TEXT DEFAULT '',

        tipo_combustible  TEXT NOT NULL DEFAULT 'diesel',  -- diesel | gasolina
        tipo_fuente       TEXT NOT NULL DEFAULT 'camion',  -- camion | storage

        miter_inicial     REAL DEFAULT 0,
        miter_final       REAL DEFAULT 0,
        carguio_dia       REAL DEFAULT 0,

        saldo_inicial     REAL DEFAULT 0,
        litros_recibidos  REAL DEFAULT 0,
        saldo_final       REAL DEFAULT 0,

        total_litros      REAL DEFAULT 0,  -- suma de cantidad_litros del detalle
        observaciones     TEXT DEFAULT '',

        estado            TEXT NOT NULL DEFAULT 'abierta', -- abierta | cerrada
        cerrada_en        DATETIME,
        cerrada_por       INTEGER,

        creado_por        INTEGER,
        creado_por_nombre TEXT DEFAULT '',
        creado_en         DATETIME DEFAULT (datetime('now','localtime')),
        actualizado_en    DATETIME,

        FOREIGN KEY (responsable_id) REFERENCES combustible_responsables(id),
        FOREIGN KEY (obra_id)        REFERENCES obras(id),
        FOREIGN KEY (creado_por)     REFERENCES usuarios(id),
        FOREIGN KEY (cerrada_por)    REFERENCES usuarios(id)
    )");

    // ── DETALLE: cada línea (vale) de la hoja ────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS combustible_vales (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        hoja_id         INTEGER NOT NULL,
        nro_linea       INTEGER NOT NULL DEFAULT 1,

        codigo          TEXT DEFAULT '',
        nro_vale        TEXT DEFAULT '',
        patente         TEXT DEFAULT '',
        descripcion     TEXT DEFAULT '',
        chofer_operador TEXT DEFAULT '',
        empresa         TEXT DEFAULT '',

        cantidad_litros REAL DEFAULT 0,
        horometro_kilom TEXT DEFAULT '',
        hora            TEXT DEFAULT '',
        partida         TEXT DEFAULT '',
        firma           TEXT DEFAULT '',  -- 'si' | 'no' | nombre, según práctica de la obra

        creado_en       DATETIME DEFAULT (datetime('now','localtime')),

        FOREIGN KEY (hoja_id) REFERENCES combustible_hojas(id) ON DELETE CASCADE
    )");

    // ── REGISTRAR EL MÓDULO 'combustible' COMO MÓDULO RECONOCIDO ──
    // (No es una tabla — solo nos aseguramos que admin pueda asignarlo)
    // Si tu canAccess usa usuario_modulos, pre-creamos accesos para los admin globales:
    try {
        $admins = $pdo->query("SELECT id FROM usuarios WHERE rol='admin'")->fetchAll(PDO::FETCH_COLUMN);
        $stIns = $pdo->prepare("INSERT OR IGNORE INTO usuario_modulos(usuario_id, modulo) VALUES (?, 'combustible')");
        foreach ($admins as $uid) {
            // Solo se inserta si el usuario YA tiene restricciones por módulo definidas;
            // si no tiene restricciones (tabla vacía para él), canAccess() ya le da paso libre.
            $hasAny = $pdo->prepare("SELECT COUNT(*) FROM usuario_modulos WHERE usuario_id=?");
            $hasAny->execute([$uid]);
            if ((int)$hasAny->fetchColumn() > 0) {
                $stIns->execute([$uid]);
            }
        }
    } catch(Exception $e) {}

    // ── ÍNDICES ──────────────────────────────────────────────
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comb_hoja_fecha   ON combustible_hojas(fecha)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comb_hoja_obra    ON combustible_hojas(obra_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comb_hoja_resp    ON combustible_hojas(responsable_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comb_hoja_estado  ON combustible_hojas(estado)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comb_vales_hoja   ON combustible_vales(hoja_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comb_resp_usuario ON combustible_responsables(usuario_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_comb_resp_obra    ON combustible_responsables(obra_id)");
    } catch(Exception $e) {}
};
