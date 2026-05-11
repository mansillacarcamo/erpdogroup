<?php
/**
 * api_indicadores.php
 * Devuelve los indicadores económicos en JSON (con caché diaria).
 * Usado por los formularios OC y Cotización para auto-rellenar Tipo de Cambio.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: max-age=3600');

$cacheFile = sys_get_temp_dir() . '/dogroup_indicadores.json';
$hoy = date('Y-m-d');

if (file_exists($cacheFile)) {
  $data = json_decode(file_get_contents($cacheFile), true);
  if ($data && ($data['_fecha_cache'] ?? '') === $hoy) {
    echo json_encode($data);
    exit;
  }
}

$ctx = stream_context_create(['http' => [
  'timeout'       => 6,
  'ignore_errors' => true,
  'header'        => "User-Agent: DOGroup-App/1.0\r\n",
]]);
$raw = @file_get_contents('https://mindicador.cl/api', false, $ctx);

if (!$raw) {
  http_response_code(503);
  echo json_encode(['error' => 'No se pudo obtener los indicadores']);
  exit;
}

$json = json_decode($raw, true);
if (!$json) {
  http_response_code(502);
  echo json_encode(['error' => 'Respuesta inválida de la fuente']);
  exit;
}

$indicadores = [
  '_fecha_cache' => $hoy,
  'uf'    => ['label'=>'UF',  'moneda'=>'UF',  'valor'=>(float)($json['uf']['valor']    ?? 0), 'fecha'=>$json['uf']['fecha']    ?? ''],
  'dolar' => ['label'=>'USD', 'moneda'=>'USD', 'valor'=>(float)($json['dolar']['valor']  ?? 0), 'fecha'=>$json['dolar']['fecha']  ?? ''],
  'euro'  => ['label'=>'EUR', 'moneda'=>'EUR', 'valor'=>(float)($json['euro']['valor']   ?? 0), 'fecha'=>$json['euro']['fecha']   ?? ''],
  'utm'   => ['label'=>'UTM', 'moneda'=>'',   'valor'=>(float)($json['utm']['valor']    ?? 0), 'fecha'=>$json['utm']['fecha']    ?? ''],
];

file_put_contents($cacheFile, json_encode($indicadores));
echo json_encode($indicadores);
