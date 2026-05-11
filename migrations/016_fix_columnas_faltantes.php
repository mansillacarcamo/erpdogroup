<?php
/**
 * Migración 016 — Correcciones de columnas faltantes detectadas en chequeo
 *
 * 1) cotizaciones.creada_por_cargo — usado por cotizacion.php pero no existía
 *    (similar a ordenes_compra.preparada_por_cargo)
 *
 * 2) despacho_reporte_carchek.fecha / json_data / enviado_a — usado por
 *    despacho_externo_portal.php al enviar reporte por email pero no existían
 *
 * Fecha: 2026-04-30
 */
return function(PDO $pdo) {

    // 1) Cargo del creador de la cotización
    try {
        $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN creada_por_cargo TEXT DEFAULT ''");
    } catch (Exception $e) { /* ya existe */ }

    // Rellenar el cargo en cotizaciones existentes
    try {
        $cots = $pdo->query("SELECT id, creada_por FROM cotizaciones
                             WHERE (creada_por_cargo IS NULL OR creada_por_cargo = '')
                               AND creada_por IS NOT NULL AND creada_por != ''")->fetchAll(PDO::FETCH_ASSOC);
        $stU = $pdo->prepare("SELECT cargo FROM usuarios WHERE nombre = ? LIMIT 1");
        $stUp = $pdo->prepare("UPDATE cotizaciones SET creada_por_cargo = ? WHERE id = ?");
        foreach ($cots as $c) {
            $stU->execute([$c['creada_por']]);
            $cargo = $stU->fetchColumn();
            if ($cargo) $stUp->execute([$cargo, $c['id']]);
        }
    } catch (Exception $e) {}

    // 2) Tabla despacho_reporte_carchek — agregar columnas usadas por el envío
    // La tabla ya existe (creada por la migración 002). Solo agregamos columnas faltantes.
    foreach ([
        ['fecha',     "TEXT DEFAULT ''"],
        ['json_data', "TEXT DEFAULT ''"],
        ['enviado_a', "TEXT DEFAULT ''"],
    ] as [$col, $def]) {
        try {
            $pdo->exec("ALTER TABLE despacho_reporte_carchek ADD COLUMN $col $def");
        } catch (Exception $e) { /* ya existe */ }
    }
};
