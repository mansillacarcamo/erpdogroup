<?php
// Endpoint para que el Jefe Zonal apruebe o rechace un gasto individual
require_once 'config.php';
requireAuth();
$rol = $_SESSION['user_rol'] ?? '';
if (!in_array($rol, ['jefe','admin','validador'])) {
    http_response_code(403); die('Sin permiso.');
}

$gid    = (int)($_POST['gid'] ?? $_GET['gid'] ?? 0);
$accion = $_POST['accion'] ?? '';
$obs    = trim($_POST['obs'] ?? '');
$volver = $_POST['volver'] ?? $_GET['volver'] ?? 'jefe_dashboard.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); die('Metodo no permitido.');
}

// Validar gasto y permiso del jefe sobre el tecnico
$g = $pdo->prepare("SELECT g.*, u.jefe_zonal_id, u.validador_id, u.nombre as tecnico_nombre
                    FROM gastos g JOIN usuarios u ON u.id=g.usuario_id WHERE g.id=?");
$g->execute([$gid]);
$g = $g->fetch();
if (!$g) { flash('error','Gasto no encontrado.'); header('Location: '.$volver); exit; }

// Solo el jefe del tecnico (o admin/validador) puede aprobar/rechazar
if ($rol === 'jefe' && (int)$g['jefe_zonal_id'] !== (int)$_SESSION['user_id']) {
    http_response_code(403); die('Sin permiso sobre este tecnico.');
}

$estadoDestino = null;
$tituloNotif = '';
$tipoNotif = 'info';
$periodo = substr($g['fecha'], 0, 7); // YYYY-MM
$periodoAnio = (int)substr($periodo, 0, 4);
$periodoMes  = (int)substr($periodo, 5, 2);
$periodoTxt = nombreMes($periodoMes).' '.$periodoAnio;

$soloObservacion = false;
$fechaTxt = date('d/m/Y', strtotime($g['fecha']));
$montoTxt = fmtCLP($g['monto']);

if ($accion === 'aprobar') {
    $estadoDestino = 'aprobado_jefe';
    $tituloNotif = 'Gasto aprobado';
    $mensajeNotif = 'Tu gasto del '.$fechaTxt.' por '.$montoTxt.' fue aprobado por el Jefe.'
                  . ($obs ? ' Observacion: '.$obs : '');
    $tipoNotif = 'success';
} elseif ($accion === 'rechazar') {
    if ($obs === '') {
        flash('error','Debes indicar un motivo al rechazar.');
        header('Location: '.$volver); exit;
    }
    $estadoDestino = 'rechazado_jefe';
    $tituloNotif = 'Gasto rechazado';
    $mensajeNotif = 'Tu gasto del '.$fechaTxt.' por '.$montoTxt.' fue rechazado por el Jefe. Motivo: '.$obs;
    $tipoNotif = 'warning';
} elseif ($accion === 'observar') {
    if ($obs === '') {
        flash('error','Debes escribir la observacion.');
        header('Location: '.$volver); exit;
    }
    $soloObservacion = true;
    $tituloNotif = 'Observacion de tu Jefe';
    $mensajeNotif = 'Tu Jefe dejo una observacion en el gasto del '.$fechaTxt.' por '.$montoTxt.': '.$obs;
    $tipoNotif = 'info';
} else {
    flash('error','Accion invalida.');
    header('Location: '.$volver); exit;
}

try {
    if ($soloObservacion) {
        // Solo observacion: no cambia el estado del gasto
        $pdo->prepare("UPDATE gastos SET observacion_revision=? WHERE id=?")
            ->execute([$obs, $gid]);
    } else {
        $pdo->prepare("UPDATE gastos SET estado=?, observacion_revision=? WHERE id=?")
            ->execute([$estadoDestino, $obs ?: null, $gid]);
    }

    // Notificar al tecnico
    $pdo->prepare("INSERT INTO notificaciones (usuario_id,titulo,mensaje,tipo,enlace) VALUES (?,?,?,?,?)")
        ->execute([(int)$g['usuario_id'], $tituloNotif, $mensajeNotif, $tipoNotif, 'gasto_ver.php?id='.$gid]);

    $msg = [
        'aprobar'  => 'Gasto aprobado'.($obs?' con observacion':'').'. Tecnico notificado.',
        'rechazar' => 'Gasto rechazado y tecnico notificado.',
        'observar' => 'Observacion guardada y tecnico notificado.',
    ][$accion];
    flash('exito', $msg);
} catch (Exception $e) {
    flash('error','Error al procesar: '.$e->getMessage());
}

header('Location: '.$volver); exit;
