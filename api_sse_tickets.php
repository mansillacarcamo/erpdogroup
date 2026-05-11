<?php
/**
 * api_sse_tickets.php — Polling en tiempo real para tickets de despacho
 * Devuelve JSON limpio. El cliente hace polling cada 8s.
 */
require_once 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Detectar tipo de sesión
$modo     = null;
$filtroId = 0;
$extObraId = 0;

if (!empty($_SESSION['usuario'])) {
    $modo     = 'interno';
    $filtroId = (int)$_SESSION['usuario']['id'];
} elseif (isExternoLoggedIn()) {
    $modo     = 'carchek';
    $ext      = getExternoSession();
    $filtroId = (int)$ext['id'];
    $stOb = $pdo->prepare("SELECT obra_id FROM despacho_externos WHERE id=?");
    $stOb->execute([$filtroId]);
    $extObraId = (int)$stOb->fetchColumn();
} elseif (isConductorLoggedIn()) {
    $modo     = 'conductor';
    $cond     = getConductorSession();
    $filtroId = (int)$cond['id'];
} else {
    echo json_encode(['error'=>'no_auth']); exit;
}

$lastHash = $_GET['hash'] ?? '';
$hoy      = date('Y-m-d');

try {
    // Traer tickets según el rol
    if ($modo === 'conductor') {
        $st = $pdo->prepare("
            SELECT id, estado, ppu, tipo_material, metros_cubicos,
                   validado_por_nombre, validado_en, hora
            FROM tickets_despacho
            WHERE conductor_id = ? AND fecha = ?
            ORDER BY id DESC
        ");
        $st->execute([$filtroId, $hoy]);
    } elseif ($modo === 'carchek') {
        $st = $pdo->prepare("
            SELECT id, estado, ppu, tipo_material, metros_cubicos,
                   conductor_nombre, estado_revision, token
            FROM tickets_despacho
            WHERE fecha = ? AND estado IN ('validado','enviado')
              AND (revisado_por_ext_id = ?
                   OR EXISTS (SELECT 1 FROM despacho_receptores dr WHERE dr.id=receptor_id AND dr.tipo='externo' AND dr.externo_id=?)
                   OR (? > 0 AND obra_id = ?))
            ORDER BY id DESC
        ");
        $st->execute([$hoy, $filtroId, $filtroId, $extObraId, $extObraId]);
    } else {
        // Interno: todos del día
        $st = $pdo->prepare("SELECT id, estado, ppu, conductor_nombre FROM tickets_despacho WHERE fecha = ? ORDER BY id DESC LIMIT 100");
        $st->execute([$hoy]);
    }

    $tickets = $st->fetchAll(PDO::FETCH_ASSOC);
    $hash    = md5(json_encode($tickets));

    $validados  = count(array_filter($tickets, fn($t) => $t['estado'] === 'validado'));
    $enviados   = count(array_filter($tickets, fn($t) => $t['estado'] === 'enviado'));
    $borradores = count(array_filter($tickets, fn($t) => $t['estado'] === 'borrador'));
    $maxId      = $tickets ? max(array_column($tickets, 'id')) : 0;

    $payload = [
        'hash'       => $hash,
        'max_id'     => $maxId,
        'validados'  => $validados,
        'enviados'   => $enviados,
        'borradores' => $borradores,
        'total'      => count($tickets),
        'modo'       => $modo,
        'ts'         => date('H:i'),
        'cambios'    => ($hash !== $lastHash) ? array_column($tickets,'id') : [],
    ];

    if ($modo === 'conductor') {
        $payload['mis_tickets'] = $tickets;
    }
    if ($modo === 'carchek') {
        $payload['pendientes'] = array_filter($tickets, fn($t) => $t['estado'] === 'enviado');
        $payload['pendientes'] = array_values($payload['pendientes']);
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;

} catch(Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'hash' => '']);
    exit;
}
