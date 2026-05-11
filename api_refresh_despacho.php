<?php
/**
 * Endpoint de auto-refresh para los portales de Despacho.
 * Devuelve un "watermark" (timestamp + hash) que cambia cuando hay
 * actividad relevante en los tickets. El cliente hace polling y al
 * detectar cambios refresca su vista.
 *
 * Scopes:
 *   - conductor: cambios en los tickets del conductor logueado
 *   - externo:   cambios en los tickets del validador externo (Carchek)
 *   - interno:   cambios en tickets de un usuario interno (creador o receptor)
 */
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$scope = $_GET['scope'] ?? '';

try {
  if ($scope === 'conductor') {
    if (!isConductorLoggedIn()) { echo json_encode(['error'=>'no_auth']); exit; }
    $cond = getConductorSession();
    $st = $pdo->prepare("
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN estado='borrador' THEN 1 ELSE 0 END) AS borradores,
        SUM(CASE WHEN estado='enviado'  THEN 1 ELSE 0 END) AS enviados,
        SUM(CASE WHEN estado='validado' THEN 1 ELSE 0 END) AS validados,
        SUM(CASE WHEN estado_revision='aprobado'    THEN 1 ELSE 0 END) AS aprobados,
        SUM(CASE WHEN estado_revision='rechazado'   THEN 1 ELSE 0 END) AS rechazados,
        SUM(CASE WHEN estado_revision='observacion' THEN 1 ELSE 0 END) AS observados,
        MAX(COALESCE(revisado_en, validado_en, enviado_en, creado_en)) AS last_change
      FROM tickets_despacho
      WHERE conductor_id = ?
    ");
    $st->execute([(int)$cond['id']]);
  }
  elseif ($scope === 'externo') {
    if (!isExternoLoggedIn()) { echo json_encode(['error'=>'no_auth']); exit; }
    $ext = getExternoSession();
    $st = $pdo->prepare("
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN td.estado='enviado' THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN td.estado='validado' AND td.estado_revision IS NULL THEN 1 ELSE 0 END) AS por_revisar,
        SUM(CASE WHEN td.estado_revision='aprobado'    THEN 1 ELSE 0 END) AS aprobados,
        SUM(CASE WHEN td.estado_revision='rechazado'   THEN 1 ELSE 0 END) AS rechazados,
        SUM(CASE WHEN td.estado_revision='observacion' THEN 1 ELSE 0 END) AS observados,
        MAX(COALESCE(td.revisado_en, td.validado_en, td.enviado_en, td.creado_en)) AS last_change
      FROM tickets_despacho td
      JOIN despacho_receptores dr ON dr.id = td.receptor_id
      WHERE dr.tipo='externo' AND dr.externo_id = ?
    ");
    $st->execute([(int)$ext['id']]);
  }
  elseif ($scope === 'interno') {
    if (empty($_SESSION['usuario'])) { echo json_encode(['error'=>'no_auth']); exit; }
    $uid = (int)$_SESSION['usuario']['id'];
    $st = $pdo->prepare("
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN estado='enviado'  THEN 1 ELSE 0 END) AS enviados,
        SUM(CASE WHEN estado='validado' THEN 1 ELSE 0 END) AS validados,
        SUM(CASE WHEN estado_revision='aprobado'    THEN 1 ELSE 0 END) AS aprobados,
        SUM(CASE WHEN estado_revision='rechazado'   THEN 1 ELSE 0 END) AS rechazados,
        SUM(CASE WHEN estado_revision='observacion' THEN 1 ELSE 0 END) AS observados,
        MAX(COALESCE(revisado_en, validado_en, enviado_en, creado_en)) AS last_change
      FROM tickets_despacho
      WHERE creado_por = ? OR receptor_id = ?
    ");
    $st->execute([$uid, $uid]);
  }
  elseif ($scope === 'operaciones') {
    /* Watermark global de operaciones: ve cualquier validación/revisión + reportes nuevos.
       Se permite al usuario interno asignado como Encargado de Operaciones o admins. */
    if (empty($_SESSION['usuario'])) { echo json_encode(['error'=>'no_auth']); exit; }
    $usu = $_SESSION['usuario'];
    $autorizado = ($usu['rol'] === 'admin') || isEncargadoOperaciones($usu, $pdo);
    if (!$autorizado) { echo json_encode(['error'=>'sin_permiso']); exit; }

    $row = ['total'=>0,'validados_hoy'=>0,'aprobados_hoy'=>0,'rechazados_hoy'=>0,'observados_hoy'=>0,'sin_rev_hoy'=>0,'last_change'=>null];
    $hoy = date('Y-m-d');

    $stT = $pdo->prepare("
      SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN DATE(validado_en)=? THEN 1 ELSE 0 END) AS validados_hoy,
        SUM(CASE WHEN DATE(validado_en)=? AND estado_revision='aprobado'    THEN 1 ELSE 0 END) AS aprobados_hoy,
        SUM(CASE WHEN DATE(validado_en)=? AND estado_revision='rechazado'   THEN 1 ELSE 0 END) AS rechazados_hoy,
        SUM(CASE WHEN DATE(validado_en)=? AND estado_revision='observacion' THEN 1 ELSE 0 END) AS observados_hoy,
        SUM(CASE WHEN DATE(validado_en)=? AND (estado_revision IS NULL OR estado_revision='') THEN 1 ELSE 0 END) AS sin_rev_hoy,
        MAX(COALESCE(revisado_en, validado_en, enviado_en, creado_en)) AS last_change
      FROM tickets_despacho
      WHERE estado='validado' AND DATE(validado_en) >= DATE(?, '-30 days')
    ");
    $stT->execute([$hoy,$hoy,$hoy,$hoy,$hoy,$hoy]);
    $row = $stT->fetch(PDO::FETCH_ASSOC) ?: $row;

    /* Watermark adicional: cantidad de reportes Carchek + cierres diarios */
    try {
      $cntRC = (int)$pdo->query("SELECT COUNT(*) FROM despacho_reporte_carchek")->fetchColumn();
      $cntCD = (int)$pdo->query("SELECT COUNT(*) FROM despacho_cierre_dia")->fetchColumn();
      $row['reportes_carchek'] = $cntRC;
      $row['cierres_diarios']  = $cntCD;
    } catch(Exception $e) {}

    $st = null;
  }
  else {
    echo json_encode(['error'=>'scope_invalido']); exit;
  }

  if ($st) { $row = $st->fetch(PDO::FETCH_ASSOC) ?: []; }

  /* Watermark = string compacto que cambia cuando hay actividad nueva.
     Lo formamos a partir del último timestamp + contadores clave. */
  $watermark = md5(($row['last_change'] ?? '') . '|' . json_encode($row));

  echo json_encode([
    'ok'        => true,
    'scope'     => $scope,
    'watermark' => $watermark,
    'last_change' => $row['last_change'] ?? null,
    'stats'     => $row,
    'server_ts' => date('c'),
  ]);
} catch (Exception $e) {
  echo json_encode(['error' => 'srv_error', 'msg' => $e->getMessage()]);
}
