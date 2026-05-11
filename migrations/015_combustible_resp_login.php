<?php
/**
 * Migración 015 — Perfil de acceso independiente para Responsables de Combustible
 *
 * Agrega credenciales propias a `combustible_responsables` siguiendo el patrón
 * de `despacho_conductores`: usuario + password_hash + email + fono.
 *
 * Esto permite que los responsables ingresen al sistema con su propio login
 * (combustible_responsable_login.php → combustible_responsable_portal.php),
 * sin necesidad de un usuario interno del sistema.
 *
 * Fecha: 2026-04-30
 */
return function(PDO $pdo) {

    // Las columnas se agregan de forma idempotente
    foreach ([
        ['usuario',       "TEXT DEFAULT ''"],
        ['password_hash', "TEXT DEFAULT ''"],
        ['email',         "TEXT DEFAULT ''"],
        ['fono',          "TEXT DEFAULT ''"],
    ] as [$col, $def]) {
        try {
            $pdo->exec("ALTER TABLE combustible_responsables ADD COLUMN $col $def");
        } catch (Exception $e) { /* ya existe */ }
    }

    // Índice único parcial: solo si usuario no está vacío
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_comb_resp_usuario_unq
                    ON combustible_responsables(usuario)
                    WHERE usuario IS NOT NULL AND usuario != ''");
    } catch (Exception $e) {}
};
