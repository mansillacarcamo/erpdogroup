<?php
// Fallback de exportación si PhpSpreadsheet no está disponible.
// Genera un CSV compatible con Excel (BOM UTF-8, separador ";") con el mismo set de datos del reporte.
if (!isset($pdo)) { require_once __DIR__ . '/config.php'; requireAuth(); }

$buscar = trim($_GET['q'] ?? '');
$filtro = trim($_GET['estado'] ?? '');

$sql = "SELECT * FROM cotizaciones WHERE 1=1";
$params = [];
if ($buscar !== '') {
    $sql .= " AND (cliente_nombre LIKE ? OR cliente_obra LIKE ? OR CAST(numero AS TEXT) LIKE ? OR creada_por LIKE ?)";
    $like = "%$buscar%";
    $params = [$like,$like,$like,$like];
}
if ($filtro !== '') {
    $sql .= " AND estado = ?";
    $params[] = $filtro;
}
$sql .= " ORDER BY numero DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cotizaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cotIds = array_column($cotizaciones, 'id');
$ocPorCot = [];
if (!empty($cotIds)) {
    $ph = implode(',', array_fill(0, count($cotIds), '?'));
    $stmtOC = $pdo->prepare("SELECT a.cot_id, oc.total FROM cot_oc_asociaciones a JOIN ordenes_compra oc ON a.oc_id = oc.id WHERE a.cot_id IN ($ph)");
    $stmtOC->execute($cotIds);
    while ($row = $stmtOC->fetch(PDO::FETCH_ASSOC)) {
        $ocPorCot[$row['cot_id']][] = $row;
    }
}

$mesesEs = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

$fecha = date('Y-m-d_H-i');
$filename = "Cotizaciones_DOGroup_$fecha.csv";

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: text/csv; charset=UTF-8');
header("Content-Disposition: attachment; filename=\"$filename\"");
header('Cache-Control: max-age=0');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

$headers = ['Año','Mes','Ciudad','RUT','Razón social','Nombre Obra','Cotización','Monto cotizado Neto','Estado','Total c/IVA','IVA','Creada por','Fecha','N° OC Asociadas','Total OC'];
fputcsv($out, $headers, ';');

foreach ($cotizaciones as $c) {
    $ocs = $ocPorCot[$c['id']] ?? [];
    $totalOCs = array_sum(array_column($ocs, 'total'));
    $ts = strtotime($c['fecha']);
    $fila = [
        (int)date('Y', $ts),
        $mesesEs[(int)date('n', $ts)] ?? '',
        $c['cliente_ciudad'] ?? '',
        $c['cliente_rut'] ?? '',
        $c['cliente_nombre'] ?? '',
        $c['cliente_obra'] ?? '',
        $c['numero'],
        (float)($c['subtotal'] ?? 0),
        ucfirst($c['estado'] ?? ''),
        (float)($c['total'] ?? 0),
        (float)($c['iva'] ?? 0),
        $c['creada_por'] ?? '',
        date('d/m/Y', $ts),
        count($ocs),
        (float)$totalOCs,
    ];
    fputcsv($out, $fila, ';');
}

fclose($out);
exit;
