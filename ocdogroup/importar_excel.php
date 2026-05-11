<?php
require_once 'config.php';
requireAuth();
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$msg = null;
$preview = null;
$importados = 0;
$errores = [];

$mesesMap = [
    'enero'=>1,'febrero'=>2,'marzo'=>3,'abril'=>4,'mayo'=>5,'junio'=>6,
    'julio'=>7,'agosto'=>8,'septiembre'=>9,'octubre'=>10,'noviembre'=>11,'diciembre'=>12,
    'january'=>1,'february'=>2,'march'=>3,'april'=>4,'may'=>5,'june'=>6,
    'july'=>7,'august'=>8,'september'=>9,'october'=>10,'november'=>11,'december'=>12,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'previsualizar' && !empty($_FILES['archivo']['tmp_name'])) {
        try {
            $spreadsheet = IOFactory::load($_FILES['archivo']['tmp_name']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);

            if (empty($rows)) throw new Exception('El archivo está vacío');

            $header = array_shift($rows);
            $headerNorm = array_map(function($h) {
                return strtolower(trim(preg_replace('/\s+/', ' ', $h ?? '')));
            }, $header);

            $colMap = [];
            $mapeos = [
                'año' => ['año', 'ano', 'year'],
                'mes' => ['mes', 'month'],
                'ciudad' => ['ciudad', 'city'],
                'rut' => ['rut'],
                'razon_social' => ['razon social', 'razón social', 'razon_social', 'cliente', 'nombre'],
                'obra' => ['nombre obra', 'obra', 'project'],
                'cotizacion' => ['cotizacion', 'cotización', 'n° cotizacion', 'numero', 'nro'],
                'monto_neto' => ['monto cotizado neto', 'neto', 'monto neto', 'subtotal', 'monto cotizado'],
                'estado' => ['estado', 'status'],
            ];

            foreach ($mapeos as $key => $aliases) {
                foreach ($headerNorm as $colLetter => $colName) {
                    foreach ($aliases as $alias) {
                        if (strpos($colName, $alias) !== false) {
                            $colMap[$key] = $colLetter;
                            break 2;
                        }
                    }
                }
            }

            if (empty($colMap['razon_social']) && empty($colMap['cotizacion'])) {
                throw new Exception('No se encontraron las columnas necesarias. Se requiere al menos "Razon social" o "Cotizacion".');
            }

            $preview = [];
            foreach ($rows as $r) {
                $razon = trim($r[$colMap['razon_social'] ?? ''] ?? '');
                if (empty($razon)) continue;

                $preview[] = [
                    'anio' => trim($r[$colMap['año'] ?? ''] ?? ''),
                    'mes' => trim($r[$colMap['mes'] ?? ''] ?? ''),
                    'ciudad' => trim($r[$colMap['ciudad'] ?? ''] ?? ''),
                    'rut' => trim($r[$colMap['rut'] ?? ''] ?? ''),
                    'razon_social' => $razon,
                    'obra' => trim($r[$colMap['obra'] ?? ''] ?? ''),
                    'cotizacion' => trim($r[$colMap['cotizacion'] ?? ''] ?? ''),
                    'monto_neto' => trim($r[$colMap['monto_neto'] ?? ''] ?? '0'),
                    'estado' => trim($r[$colMap['estado'] ?? ''] ?? 'pendiente'),
                ];
            }

            if (empty($preview)) throw new Exception('No se encontraron filas con datos válidos');

            $tmpFile = tempnam(sys_get_temp_dir(), 'excel_import_');
            copy($_FILES['archivo']['tmp_name'], $tmpFile);
            $_SESSION['import_file'] = $tmpFile;
            $_SESSION['import_colmap'] = $colMap;

            $msg = ['success', count($preview) . ' registros encontrados. Revisa la previsualización y confirma la importación.'];
        } catch (Exception $e) {
            $msg = ['danger', 'Error: ' . $e->getMessage()];
        }
    }

    if ($action === 'importar' && !empty($_SESSION['import_file'])) {
        try {
            $spreadsheet = IOFactory::load($_SESSION['import_file']);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
            $header = array_shift($rows);
            $colMap = $_SESSION['import_colmap'];

            $estadosValidos = ['pendiente','propuesta','negociacion','adjudicada','desierta','cerrado'];

            $stmtCheck = $pdo->prepare("SELECT id FROM cotizaciones WHERE numero = ?");
            $stmtInsert = $pdo->prepare("INSERT INTO cotizaciones (numero, fecha, cliente_nombre, cliente_obra, cliente_ciudad, cliente_rut, subtotal, iva, total, estado, creada_por) VALUES (?,?,?,?,?,?,?,?,?,?,?)");

            foreach ($rows as $idx => $r) {
                $razon = trim($r[$colMap['razon_social'] ?? ''] ?? '');
                if (empty($razon)) continue;

                $numCot = (int)trim($r[$colMap['cotizacion'] ?? ''] ?? '0');
                if ($numCot <= 0) {
                    $numCot = siguienteNumeroCot($pdo);
                }

                $stmtCheck->execute([$numCot]);
                if ($stmtCheck->fetch()) {
                    $errores[] = "Fila " . ($idx + 2) . ": Cotización N° $numCot ya existe, se omitió.";
                    continue;
                }

                $anio = trim($r[$colMap['año'] ?? ''] ?? date('Y'));
                $mesRaw = strtolower(trim($r[$colMap['mes'] ?? ''] ?? ''));
                $mesNum = $mesesMap[$mesRaw] ?? (is_numeric($mesRaw) ? (int)$mesRaw : (int)date('n'));
                if ($mesNum < 1 || $mesNum > 12) $mesNum = (int)date('n');
                $fecha = sprintf('%04d-%02d-01', (int)$anio ?: date('Y'), $mesNum);

                $montoRaw = $r[$colMap['monto_neto'] ?? ''] ?? 0;
                $montoNeto = (float)preg_replace('/[^0-9.\-]/', '', str_replace(',', '.', (string)$montoRaw));
                $iva = round($montoNeto * 0.19);
                $total = $montoNeto + $iva;

                $estadoRaw = strtolower(trim($r[$colMap['estado'] ?? ''] ?? 'pendiente'));
                $estado = in_array($estadoRaw, $estadosValidos) ? $estadoRaw : 'negociacion';
                if ($estadoRaw === 'negociación') $estado = 'negociacion';

                $stmtInsert->execute([
                    $numCot,
                    $fecha,
                    $razon,
                    trim($r[$colMap['obra'] ?? ''] ?? ''),
                    trim($r[$colMap['ciudad'] ?? ''] ?? ''),
                    trim($r[$colMap['rut'] ?? ''] ?? ''),
                    $montoNeto,
                    $iva,
                    $total,
                    $estado,
                    $usuario['nombre']
                ]);
                $importados++;
            }

            unlink($_SESSION['import_file']);
            unset($_SESSION['import_file'], $_SESSION['import_colmap']);

            $msgText = "$importados cotización(es) importada(s) correctamente.";
            if (!empty($errores)) {
                $msgText .= ' ' . count($errores) . ' omitida(s).';
            }
            $msg = ['success', $msgText];
        } catch (Exception $e) {
            $msg = ['danger', 'Error al importar: ' . $e->getMessage()];
        }
    }

    if ($action === 'cancelar') {
        if (!empty($_SESSION['import_file']) && file_exists($_SESSION['import_file'])) {
            unlink($_SESSION['import_file']);
        }
        unset($_SESSION['import_file'], $_SESSION['import_colmap']);
        $msg = ['info', 'Importación cancelada.'];
    }
}

require_once 'includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-11">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-success text-white py-3">
        <div class="d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="bi bi-file-earmark-excel me-2"></i>Importar Cotizaciones desde Excel</h5>
          <a href="historial_cotizaciones.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al Historial</a>
        </div>
      </div>
      <div class="card-body p-4">
        <?php if ($msg): ?>
        <div class="alert alert-<?= $msg[0] ?> alert-dismissible fade show">
          <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($msg[1]) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (!empty($errores)): ?>
        <div class="alert alert-warning">
          <strong><i class="bi bi-exclamation-triangle me-1"></i>Registros omitidos:</strong>
          <ul class="mb-0 mt-1">
            <?php foreach ($errores as $err): ?>
            <li style="font-size:13px;"><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <?php if (empty($preview) && empty($_SESSION['import_file'])): ?>
        <div class="row justify-content-center">
          <div class="col-md-8">
            <div class="border rounded p-4 text-center bg-light">
              <i class="bi bi-cloud-arrow-up fs-1 text-success"></i>
              <h6 class="mt-3 fw-bold">Subir archivo Excel</h6>
              <p class="text-muted mb-3">El archivo debe tener las columnas: <strong>Año, Mes, Ciudad, Rut, Razón Social, Nombre Obra, Cotización, Monto cotizado Neto, Estado</strong></p>
              <form method="POST" enctype="multipart/form-data" class="d-inline">
                <input type="hidden" name="action" value="previsualizar">
                <div class="mb-3">
                  <input type="file" name="archivo" class="form-control" accept=".xlsx,.xls,.csv" required>
                </div>
                <button class="btn btn-success"><i class="bi bi-eye me-1"></i>Previsualizar datos</button>
              </form>

              <hr class="my-4">
              <div class="text-start" style="font-size:13px;">
                <strong><i class="bi bi-info-circle me-1"></i>Formato esperado:</strong>
                <table class="table table-sm table-bordered mt-2" style="font-size:12px;">
                  <thead class="table-success">
                    <tr><th>Año</th><th>Mes</th><th>Ciudad</th><th>Rut</th><th>Razon social</th><th>Nombre Obra</th><th>Cotizacion</th><th>Monto cotizado Neto</th><th>Estado</th></tr>
                  </thead>
                  <tbody>
                    <tr><td>2026</td><td>Marzo</td><td>Osorno</td><td>76.236.777-7</td><td>ARARAT SPA</td><td>Pilauco Demolicion</td><td>1148</td><td>17.140.005</td><td>Negociacion</td></tr>
                  </tbody>
                </table>
                <ul class="text-muted mb-0">
                  <li>Si el N° de cotización ya existe, esa fila se omite</li>
                  <li>Si no se indica N° de cotización, se asigna el correlativo siguiente</li>
                  <li>Los estados válidos son: Pendiente, Propuesta, Negociacion, Adjudicada, Desierta, Cerrado</li>
                </ul>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($preview)): ?>
        <div class="alert alert-info">
          <i class="bi bi-eye me-1"></i>Se encontraron <strong><?= count($preview) ?></strong> registros. Revisa los datos y confirma la importación.
        </div>

        <div class="table-responsive mb-3" style="max-height:400px;overflow-y:auto;">
          <table class="table table-sm table-bordered table-hover align-middle" style="font-size:12px;">
            <thead class="table-success sticky-top">
              <tr>
                <th>#</th>
                <th>Año</th>
                <th>Mes</th>
                <th>Ciudad</th>
                <th>Rut</th>
                <th>Razón Social</th>
                <th>Obra</th>
                <th>N° Cot</th>
                <th>Monto Neto</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($preview as $i => $p): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($p['anio']) ?></td>
                <td><?= htmlspecialchars($p['mes']) ?></td>
                <td><?= htmlspecialchars($p['ciudad']) ?></td>
                <td><?= htmlspecialchars($p['rut']) ?></td>
                <td><?= htmlspecialchars($p['razon_social']) ?></td>
                <td><?= htmlspecialchars($p['obra']) ?></td>
                <td class="fw-bold"><?= htmlspecialchars($p['cotizacion']) ?: '<span class="text-muted">Auto</span>' ?></td>
                <td class="text-end"><?= htmlspecialchars($p['monto_neto']) ?></td>
                <td><?= htmlspecialchars($p['estado']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="d-flex gap-2">
          <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="importar">
            <button class="btn btn-success btn-lg" onclick="return confirm('¿Confirmar importación de <?= count($preview) ?> registros?')">
              <i class="bi bi-check-circle me-1"></i>Confirmar Importación (<?= count($preview) ?>)
            </button>
          </form>
          <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="cancelar">
            <button class="btn btn-outline-secondary btn-lg"><i class="bi bi-x-circle me-1"></i>Cancelar</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once 'includes/footer.php'; ?>
