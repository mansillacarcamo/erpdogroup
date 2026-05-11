<?php
/**
 * Migración 012 — Cargo del preparador en órdenes de compra
 * Fecha: 2026-04-30
 */
return function(PDO $pdo) {
    try {
        $pdo->exec("ALTER TABLE ordenes_compra ADD COLUMN preparada_por_cargo TEXT DEFAULT ''");
    } catch(Exception $e) {}

    // Rellenar cargo en OC existentes
    $ocs = $pdo->query("SELECT id, preparada_por FROM ordenes_compra WHERE preparada_por != ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ocs as $oc) {
        $stU = $pdo->prepare("SELECT cargo FROM usuarios WHERE nombre = ? LIMIT 1");
        $stU->execute([$oc['preparada_por']]);
        $cargo = $stU->fetchColumn();
        if ($cargo) {
            $pdo->prepare("UPDATE ordenes_compra SET preparada_por_cargo=? WHERE id=?")
                ->execute([$cargo, $oc['id']]);
        }
    }
};
