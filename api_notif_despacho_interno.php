<?php
/**
 * API de notificaciones internas del módulo Despacho
 * Cubre: conductor, receptor interno, admin_despacho, externo (Carchek)
 */
require_once 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache');

// ── Determinar quién está logueado ──────────────────────────────────
$modo = 'ninguno';
$uid  = null;
$extId = null;
$condId = null;

if (!empty($_SESSION['usuario'])) {
    $modo = 'interno';
    $uid  = (int)$_SESSION['usuario']['id'];
} elseif (isExternoLoggedIn()) {
    $modo  = 'externo';
    $ext   = getExternoSession();
    $extId = (int)$ext['id'];
} elseif (isConductorLoggedIn()) {
    $modo   = 'conductor';
    $cond   = getConductorSession();
    $condId = (int)$cond['id'];
} else {
    echo json_encode(['error' => 'no_auth', 'total' => 0]); exit;
}

$resultado = ['total' => 0, 'modo' => $modo, 'items' => []];

try {
    if ($modo === 'interno') {
        // ① Tickets enviados pendientes de validación donde es receptor
        $st = $pdo->prepare("
            SELECT td.id, td.fecha, td.hora, td.ppu, td.metros_cubicos,
                   td.tipo_material, td.obra_nombre, td.conductor_nombre,
                   td.enviado_en, td.estado, td.creado_por_nombre,
                   'ticket_pendiente' AS tipo_notif
            FROM tickets_despacho td
            JOIN despacho_receptores dr ON dr.usuario_id = ?
            WHERE td.estado = 'enviado'
            ORDER BY td.enviado_en DESC
        ");
        $st->execute([$uid]);
        $ticketsPend = $st->fetchAll(PDO::FETCH_ASSOC);

        // ② Si es admin_despacho: todos los tickets enviados sin validar
        $esAdmin = false;
        $stAdm = $pdo->prepare("SELECT rol FROM despacho_roles WHERE usuario_id = ?");
        $stAdm->execute([$uid]);
        $rolRow = $stAdm->fetch(PDO::FETCH_ASSOC);
        $esAdmin = ($rolRow && $rolRow['rol'] === 'admin_despacho') 
                   || ($_SESSION['usuario']['rol'] === 'admin');

        if ($esAdmin) {
            $ticketsPend = $pdo->query("
                SELECT td.id, td.fecha, td.hora, td.ppu, td.metros_cubicos,
                       td.tipo_material, td.obra_nombre, td.conductor_nombre,
                       td.enviado_en, td.estado, td.creado_por_nombre,
                       'ticket_pendiente' AS tipo_notif
                FROM tickets_despacho td
                WHERE td.estado = 'enviado'
                ORDER BY td.enviado_en DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        // ③ Tickets revisados (aprobado/rechazado) creados por este usuario (feedback)
        $st2 = $pdo->prepare("
            SELECT td.id, td.fecha, td.hora, td.ppu, td.tipo_material,
                   td.estado, td.validado_por_nombre, td.validado_en,
                   td.observaciones, td.estado_revision, td.observacion_revision,
                   'ticket_revisado' AS tipo_notif
            FROM tickets_despacho td
            WHERE td.creado_por = ?
              AND td.estado = 'validado'
              AND td.validado_en >= datetime('now', '-24 hours')
            ORDER BY td.validado_en DESC
        ");
        $st2->execute([$uid]);
        $ticketsRevisados = $st2->fetchAll(PDO::FETCH_ASSOC);

        $resultado['tickets_pendientes']  = count($ticketsPend);
        $resultado['tickets_revisados']   = count($ticketsRevisados);
        $resultado['total'] = count($ticketsPend) + count($ticketsRevisados);
        $resultado['items'] = array_merge(
            array_map(fn($t) => array_merge($t, ['tipo_notif' => 'ticket_pendiente']), $ticketsPend),
            array_map(fn($t) => array_merge($t, ['tipo_notif' => 'ticket_revisado']),  $ticketsRevisados)
        );

    } elseif ($modo === 'externo') {
        // Carchek (validador externo): tickets enviados pendientes de revisión
        $st = $pdo->prepare("
            SELECT td.id, td.fecha, td.hora, td.ppu, td.metros_cubicos,
                   td.tipo_material, td.obra_nombre, td.conductor_nombre, td.enviado_en
            FROM tickets_despacho td
            JOIN despacho_receptores dr ON dr.id = td.receptor_id
            WHERE dr.tipo = 'externo' AND dr.externo_id = ? AND td.estado = 'enviado'
            ORDER BY td.enviado_en DESC
        ");
        $st->execute([$extId]);
        $tickets = $st->fetchAll(PDO::FETCH_ASSOC);
        $resultado['total']   = count($tickets);
        $resultado['items']   = $tickets;

    } elseif ($modo === 'conductor') {
        // Conductor: sus tickets del día y si fueron validados
        $st = $pdo->prepare("
            SELECT td.id, td.fecha, td.hora, td.ppu, td.tipo_material,
                   td.estado, td.metros_cubicos, td.obra_nombre,
                   td.validado_por_nombre, td.observaciones
            FROM tickets_despacho td
            WHERE td.conductor_id = ?
              AND td.fecha = date('now')
            ORDER BY td.hora DESC
        ");
        $st->execute([$condId]);
        $tickets = $st->fetchAll(PDO::FETCH_ASSOC);
        $resultado['total']       = count(array_filter($tickets, fn($t) => $t['estado'] === 'validado'));
        $resultado['items']       = $tickets;
        $resultado['hoy_total']   = count($tickets);
    }

} catch (Exception $e) {
    $resultado['error'] = $e->getMessage();
}

echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
