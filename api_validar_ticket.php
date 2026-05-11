<?php
/**
 * API AJAX — Validación de tickets por Carchek (externo) o receptor interno
 * Responde JSON. No recarga la página — el escáner queda activo.
 */
require_once 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$esExterno  = isExternoLoggedIn();
$esInterno  = !empty($_SESSION['usuario']);
$esConductor = isConductorLoggedIn();

if (!$esExterno && !$esInterno) {
    echo json_encode(['ok'=>false,'error'=>'No autenticado']); exit;
}

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

// ── Buscar ticket por token o PPU ──────────────────────────────────
if ($accion === 'buscar') {
    $tok = trim($_POST['token'] ?? $_GET['t'] ?? '');
    $ppu = trim($_POST['ppu'] ?? $_GET['ppu'] ?? '');

    try {
        if ($tok) {
            $st = $pdo->prepare("
                SELECT td.*, 
                       dp.metros_cubicos AS m3_camion
                FROM tickets_despacho td
                LEFT JOIN despacho_ppu dp ON dp.id = td.ppu_id
                WHERE td.token = ?
            ");
            $st->execute([$tok]);
        } elseif ($ppu) {
            // Buscar por PPU el ticket más reciente en estado enviado
            $st = $pdo->prepare("
                SELECT td.*,
                       dp.metros_cubicos AS m3_camion
                FROM tickets_despacho td
                LEFT JOIN despacho_ppu dp ON dp.id = td.ppu_id
                WHERE UPPER(td.ppu) = UPPER(?) AND td.estado = 'enviado'
                ORDER BY td.enviado_en DESC LIMIT 1
            ");
            $st->execute([$ppu]);
        } else {
            echo json_encode(['ok'=>false,'error'=>'Sin token ni PPU']); exit;
        }

        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) {
            echo json_encode(['ok'=>false,'error'=>'Ticket no encontrado para esta PPU/QR.']); exit;
        }

        $MATERIALES = [
            'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4',
            'bajo_3'=>'Bajo 3','bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada',
            'grava'=>'Grava','gravilla'=>'Gravilla','arena'=>'Arena','arena_tubo'=>'Arena Tubo',
            'escombros'=>'Escombros','bolones'=>'Bolones',
        ];

        echo json_encode([
            'ok'      => true,
            'ticket'  => [
                'id'              => $t['id'],
                'numero'          => str_pad($t['id'],5,'0',STR_PAD_LEFT),
                'fecha'           => $t['fecha'],
                'hora'            => substr($t['hora'],0,5),
                'ppu'             => $t['ppu'],
                'metros_cubicos'  => $t['metros_cubicos'],
                'tipo_material'   => $MATERIALES[$t['tipo_material']] ?? $t['tipo_material'],
                'obra_nombre'     => $t['obra_nombre'],
                'destino'         => $t['destino'],
                'conductor_nombre'=> $t['conductor_nombre'],
                'conductor_rut'   => $t['conductor_rut'],
                'creado_por_nombre'=> $t['creado_por_nombre'],
                'estado'          => $t['estado'],
                'token'           => $t['token'],
                'validado_por_nombre' => $t['validado_por_nombre'],
                'validado_en'     => $t['validado_en'],
                'observaciones'   => $t['observaciones'],
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── Validar ticket ─────────────────────────────────────────────────
if ($accion === 'validar') {
    $tok    = trim($_POST['token'] ?? '');
    $cambio = trim($_POST['cambio'] ?? '');

    if (!$tok) { echo json_encode(['ok'=>false,'error'=>'Token inválido']); exit; }

    try {
        if ($esExterno) {
            $ext = getExternoSession();
            $nombre = $ext['nombre'].' (Carchek)';
            $validadorId = 0;
        } else {
            $nombre = $_SESSION['usuario']['nombre'];
            $validadorId = (int)$_SESSION['usuario']['id'];
        }

        $extObj = $esExterno ? getExternoSession() : null;
        if ($esExterno) {
            $st = $pdo->prepare("
                UPDATE tickets_despacho
                SET estado='validado',
                    validado_en=datetime('now','localtime'),
                    validado_por=?,
                    validado_por_nombre=?,
                    cambio=?,
                    estado_revision='aprobado',
                    revisado_por_ext_id=?,
                    revisado_por_ext_nom=?,
                    revisado_en=datetime('now','localtime')
                WHERE token=? AND estado='enviado'
            ");
            $st->execute([$validadorId, $nombre, $cambio, $extObj['id'], $extObj['nombre'], $tok]);
        } else {
            $st = $pdo->prepare("
                UPDATE tickets_despacho
                SET estado='validado',
                    validado_en=datetime('now','localtime'),
                    validado_por=?,
                    validado_por_nombre=?,
                    cambio=?
                WHERE token=? AND estado='enviado'
            ");
            $st->execute([$validadorId, $nombre, $cambio, $tok]);
        }

        if ($st->rowCount() === 0) {
            // Verificar si ya estaba validado
            $chk = $pdo->prepare("SELECT estado, validado_por_nombre, validado_en FROM tickets_despacho WHERE token=?");
            $chk->execute([$tok]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['estado'] === 'validado') {
                echo json_encode([
                    'ok' => false,
                    'ya_validado' => true,
                    'error' => 'Este ticket ya fue validado por '.$row['validado_por_nombre']
                ]);
            } else {
                echo json_encode(['ok'=>false,'error'=>'No se pudo validar. Ticket no encontrado o no está en estado enviado.']);
            }
            exit;
        }

        // Traer datos del ticket recién validado para el registro
        $chk = $pdo->prepare("SELECT id, ppu, tipo_material, obra_nombre, conductor_nombre, metros_cubicos, destino, fecha, hora FROM tickets_despacho WHERE token=?");
        $chk->execute([$tok]);
        $t = $chk->fetch(PDO::FETCH_ASSOC);

        // Registrar en carchek_log
        if ($t && $esExterno) {
            try {
                $ext = getExternoSession();
                $pdo->prepare("INSERT INTO carchek_log
                    (externo_id, externo_nombre, ticket_id, fecha, hora, ppu, conductor_nombre,
                     obra_nombre, tipo_material, metros_cubicos, destino, accion, estado_revision)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,'validado', 'aprobado')")
                    ->execute([
                        $ext['id'], $ext['nombre'],
                        $t['id'], $t['fecha'], date('H:i'),
                        $t['ppu'], $t['conductor_nombre'],
                        $t['obra_nombre'], $t['tipo_material'],
                        (float)$t['metros_cubicos'], $t['destino'],
                    ]);
            } catch(Exception $e2) {}
        }

        // Registrar en tabla de validaciones PPU si corresponde
        if ($t && $t['ppu']) {
            try {
                $ppuRow = $pdo->prepare("SELECT id FROM despacho_ppu WHERE ppu=? LIMIT 1");
                $ppuRow->execute([$t['ppu']]);
                $ppuId = $ppuRow->fetchColumn();
                if ($ppuId) {
                    $obraRow = $pdo->prepare("SELECT id, nombre FROM obras WHERE nombre LIKE ? LIMIT 1");
                    $obraRow->execute(['%'.explode('—',$t['obra_nombre'])[0].'%']);
                    $obraR = $obraRow->fetch(PDO::FETCH_ASSOC);
                    $pdo->prepare("INSERT INTO despacho_ppu_validaciones (ppu_id, ppu, obra_id, obra_nombre, validado_por, validado_tipo) VALUES (?,?,?,?,?,?)")
                        ->execute([$ppuId, $t['ppu'], $obraR['id'] ?? null, $t['obra_nombre'], $nombre, $esExterno ? 'externo' : 'interno']);
                }
            } catch(Exception $e2) { /* no bloquear si falla el log */ }
        }

        echo json_encode([
            'ok'      => true,
            'mensaje' => 'Ticket validado correctamente',
            'ticket'  => [
                'id'               => $t['id'] ?? 0,
                'ppu'              => $t['ppu'] ?? '',
                'conductor_nombre' => $t['conductor_nombre'] ?? '',
                'validado_por'     => $nombre,
                'validado_en'      => date('d/m/Y H:i'),
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok'=>false,'error'=>'Acción no reconocida']);
