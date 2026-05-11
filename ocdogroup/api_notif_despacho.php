<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isExternoLoggedIn()) {
  echo json_encode(['error' => 'no_auth']); exit;
}
$ext = getExternoSession();

/* Tickets enviados pendientes de validación para este Carchek */
$st = $pdo->prepare("
  SELECT td.id, td.fecha, td.hora, td.ppu, td.metros_cubicos,
         td.tipo_material, td.obra_nombre, td.conductor_nombre, td.enviado_en
  FROM tickets_despacho td
  JOIN despacho_receptores dr ON dr.id = td.receptor_id
  WHERE dr.tipo = 'externo' AND dr.externo_id = ? AND td.estado = 'enviado'
  ORDER BY td.enviado_en DESC
");
$st->execute([$ext['id']]);
$tickets = $st->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  'total'   => count($tickets),
  'tickets' => $tickets,
]);
