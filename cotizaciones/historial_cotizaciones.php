<?php
require_once '../config.php';
requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $cotId = (int)($_POST['cot_id'] ?? 0);
  if ($_POST['action'] === 'cambiar_estado' && !empty($_POST['estado'])) {
    $pdo->prepare("UPDATE cotizaciones SET estado = ? WHERE id = ?")->execute([$_POST['estado'], $cotId]);
  }
  if ($_POST['action'] === 'eliminar' && in_array($usuario['rol'], ['admin', 'gerente_comercial'])) {
    $pdo->prepare("DELETE FROM cot_items WHERE cot_id = ?")->execute([$cotId]);
    $pdo->prepare("DELETE FROM cotizaciones WHERE id = ?")->execute([$cotId]);
  }
  header('Location: historial_cotizaciones.php?' . http_build_query($_GET)); exit;
}

$buscar = trim($_GET['q'] ?? '');
$filtro = trim($_GET['estado'] ?? '');
$vista = trim($_GET['vista'] ?? 'tabla');

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
$procesosPorCot = [];
if (!empty($cotIds)) {
    $placeholders = implode(',', array_fill(0, count($cotIds), '?'));
    $stmtProc = $pdo->prepare("SELECT cot_id, estado_proceso, COUNT(*) as total FROM cot_procesos WHERE cot_id IN ($placeholders) AND tipo != 'cambio_estado' GROUP BY cot_id, estado_proceso");
    $stmtProc->execute($cotIds);
    while ($row = $stmtProc->fetch(PDO::FETCH_ASSOC)) {
        $procesosPorCot[$row['cot_id']][$row['estado_proceso']] = (int)$row['total'];
    }
}

$estadosProcBadges = [
    'pendiente' => ['warning', 'Pend'],
    'en_ejecucion' => ['primary', 'Ejec'],
    'en_evaluacion' => ['info', 'Eval'],
    'en_revision' => ['secondary', 'Rev'],
    'aprobado' => ['success', 'Aprob'],
    'rechazado' => ['danger', 'Rech'],
    'desierta' => ['dark', 'Des'],
    'completado' => ['success', 'Comp'],
];

$badges = ['pendiente'=>'warning','propuesta'=>'info','negociacion'=>'primary','adjudicada'=>'success','desierta'=>'secondary','cerrado'=>'dark'];

$cotsPorObra = [];
foreach ($cotizaciones as $c) {
    $obra = trim($c['cliente_obra'] ?: 'Sin obra asignada');
    $cotsPorObra[$obra][] = $c;
}

require_once '../includes/header.php';
?>
<a href="../inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver al inicio</a>
<?php

$queryParams = $_GET;
?>

<div class="card shadow-sm border-0">
  <div class="card-header bg-danger text-white py-3">
    <div class="d-flex justify-content-between align-items-center">
      <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Historial de Cotizaciones</h5>
      <div class="d-flex gap-2 align-items-center">
        <a href="../exportar_excel.php?<?= http_build_query(array_filter(['q' => $buscar, 'estado' => $filtro])) ?>" class="btn btn-success btn-sm">
          <i class="bi bi-download me-1"></i>Exportar Excel
        </a>
        <div class="btn-group btn-group-sm">
          <?php
            $paramsTabla = array_merge($queryParams, ['vista' => 'tabla']);
            $paramsObra = array_merge($queryParams, ['vista' => 'obra']);
          ?>
          <a href="?<?= http_build_query($paramsTabla) ?>" class="btn <?= $vista === 'tabla' ? 'btn-light' : 'btn-outline-light' ?>">
            <i class="bi bi-table me-1"></i>Tabla
          </a>
          <a href="?<?= http_build_query($paramsObra) ?>" class="btn <?= $vista === 'obra' ? 'btn-light' : 'btn-outline-light' ?>">
            <i class="bi bi-building me-1"></i>Por Obra
          </a>
        </div>
      </div>
    </div>
  </div>
  <div class="card-body p-4">
    <form class="row g-2 mb-4" method="GET">
      <input type="hidden" name="vista" value="<?= htmlspecialchars($vista) ?>">
      <div class="col-md-6">
        <div class="input-group">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Buscar por N°, cliente, obra..." value="<?= htmlspecialchars($buscar) ?>">
        </div>
      </div>
      <div class="col-md-3">
        <select name="estado" class="form-select">
          <option value="">Todos los estados</option>
          <option value="pendiente" <?= $filtro==='pendiente'?'selected':'' ?>>Pendiente</option>
          <option value="propuesta" <?= $filtro==='propuesta'?'selected':'' ?>>Propuesta</option>
          <option value="negociacion" <?= $filtro==='negociacion'?'selected':'' ?>>Negociación</option>
          <option value="adjudicada" <?= $filtro==='adjudicada'?'selected':'' ?>>Adjudicada</option>
          <option value="desierta" <?= $filtro==='desierta'?'selected':'' ?>>Desierta</option>
          <option value="cerrado" <?= $filtro==='cerrado'?'selected':'' ?>>Cerrado</option>
        </select>
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-danger w-100"><i class="bi bi-filter me-1"></i>Filtrar</button>
      </div>
    </form>

    <?php if (empty($cotizaciones)): ?>
    <div class="text-center text-muted py-5"><i class="bi bi-inbox fs-1"></i><p class="mt-2">No hay cotizaciones</p></div>

    <?php elseif ($vista === 'obra'): ?>
    <!-- ===================== VISTA AGRUPADA POR OBRA ===================== -->
    <div class="mb-3">
      <span class="text-muted"><i class="bi bi-building me-1"></i><?= count($cotsPorObra) ?> obra(s) / faena(s) — <?= count($cotizaciones) ?> cotización(es)</span>
    </div>

    <?php foreach ($cotsPorObra as $obraNombre => $cotsDeLaObra):
      $totalObra = array_sum(array_column($cotsDeLaObra, 'total'));
      $clienteObra = $cotsDeLaObra[0]['cliente_nombre'] ?? '';
      $estadosObra = array_count_values(array_column($cotsDeLaObra, 'estado'));
    ?>
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-dark text-white py-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h6 class="mb-0"><i class="bi bi-building me-2"></i><?= htmlspecialchars($obraNombre) ?></h6>
            <small class="opacity-75"><i class="bi bi-person me-1"></i><?= htmlspecialchars($clienteObra) ?></small>
          </div>
          <div class="text-end">
            <div class="d-flex gap-1 flex-wrap justify-content-end mb-1">
              <?php foreach ($estadosObra as $estNombre => $estCount):
                $estBadge = $badges[$estNombre] ?? 'secondary';
              ?>
              <span class="badge bg-<?= $estBadge ?>" style="font-size:11px;"><?= $estCount ?> <?= ucfirst($estNombre) ?></span>
              <?php endforeach; ?>
            </div>
            <span class="fw-bold" style="font-size:15px;"><?= count($cotsDeLaObra) ?> cotización(es) — Total: $<?= formatCLP($totalObra) ?></span>
          </div>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>N°</th>
                <th>Fecha</th>
                <th>Cliente</th>
                <th class="text-end">Total</th>
                <th>Estado</th>
                <th>Procesos</th>
                <th>Creada por</th>
                <th>Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cotsDeLaObra as $c):
                $badge = $badges[$c['estado']] ?? 'secondary';
              ?>
              <tr>
                <td class="fw-bold"><?= $c['numero'] ?></td>
                <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
                <td><?= htmlspecialchars($c['cliente_nombre']) ?></td>
                <td class="text-end fw-bold">$<?= formatCLP($c['total']) ?></td>
                <td><span class="badge bg-<?= $badge ?>"><?= ucfirst($c['estado']) ?></span></td>
                <td>
                  <?php
                    $procs = $procesosPorCot[$c['id']] ?? [];
                    if (empty($procs)): ?>
                    <small class="text-muted">—</small>
                  <?php else:
                    foreach ($procs as $epKey => $epCount):
                      $epb = $estadosProcBadges[$epKey] ?? ['secondary', '?'];
                  ?>
                    <span class="badge bg-<?= $epb[0] ?>" style="font-size:10px;" title="<?= ucfirst(str_replace('_', ' ', $epKey)) ?>"><?= $epCount ?> <?= $epb[1] ?></span>
                  <?php endforeach; endif; ?>
                </td>
                <td><small><?= htmlspecialchars($c['creada_por']) ?></small></td>
                <td>
                  <div class="d-flex gap-1 flex-wrap">
                    <a href="ver_cotizacion.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Ver"><i class="bi bi-eye"></i></a>
                    <?php if (in_array($c['estado'], ['pendiente', 'corregir'])): ?>
                    <a href="editar_cotizacion.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-warning" title="Editar"><i class="bi bi-pencil"></i></a>
                    <?php endif; ?>
                    <a href="seguimiento_cot.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-success" title="Seguimiento"><i class="bi bi-diagram-3"></i></a>
                    <form method="POST" class="d-inline">
                      <input type="hidden" name="cot_id" value="<?= $c['id'] ?>">
                      <input type="hidden" name="action" value="cambiar_estado">
                      <select name="estado" class="form-select form-select-sm" style="width:auto;display:inline-block" onchange="this.form.submit()">
                        <option value="">Estado</option>
                        <option value="pendiente">Pendiente</option>
                        <option value="propuesta">Propuesta</option>
                        <option value="negociacion">Negociación</option>
                        <option value="adjudicada">Adjudicada</option>
                        <option value="desierta">Desierta</option>
                        <option value="cerrado">Cerrado</option>
                      </select>
                    </form>
                    <?php if (in_array($usuario['rol'], ['admin', 'gerente_comercial'])): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar Cotización N° <?= $c['numero'] ?>?')">
                      <input type="hidden" name="cot_id" value="<?= $c['id'] ?>">
                      <input type="hidden" name="action" value="eliminar">
                      <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                    </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr class="table-warning">
                <td colspan="3" class="fw-bold text-end">Total Obra:</td>
                <td class="text-end fw-bold">$<?= formatCLP($totalObra) ?></td>
                <td colspan="4"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php else: ?>
    <!-- ===================== VISTA TABLA NORMAL ===================== -->
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>N°</th>
            <th>Fecha</th>
            <th>Cliente</th>
            <th>Obra</th>
            <th class="text-end">Total</th>
            <th>Estado</th>
            <th>Procesos</th>
            <th>Creada por</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cotizaciones as $c):
            $badge = $badges[$c['estado']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-bold"><?= $c['numero'] ?></td>
            <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
            <td><?= htmlspecialchars($c['cliente_nombre']) ?></td>
            <td><?= htmlspecialchars($c['cliente_obra']) ?></td>
            <td class="text-end fw-bold">$<?= formatCLP($c['total']) ?></td>
            <td><span class="badge bg-<?= $badge ?>"><?= ucfirst($c['estado']) ?></span></td>
            <td>
              <?php
                $procs = $procesosPorCot[$c['id']] ?? [];
                if (empty($procs)): ?>
                <small class="text-muted">—</small>
              <?php else:
                foreach ($procs as $epKey => $epCount):
                  $epb = $estadosProcBadges[$epKey] ?? ['secondary', '?'];
              ?>
                <span class="badge bg-<?= $epb[0] ?>" style="font-size:10px;" title="<?= ucfirst(str_replace('_', ' ', $epKey)) ?>"><?= $epCount ?> <?= $epb[1] ?></span>
              <?php endforeach; endif; ?>
            </td>
            <td><small><?= htmlspecialchars($c['creada_por']) ?></small></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <a href="ver_cotizacion.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Ver"><i class="bi bi-eye"></i></a>
                <?php if (in_array($c['estado'], ['pendiente', 'corregir'])): ?>
                <a href="editar_cotizacion.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-warning" title="Editar"><i class="bi bi-pencil"></i></a>
                <?php endif; ?>
                <a href="seguimiento_cot.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-success" title="Seguimiento"><i class="bi bi-diagram-3"></i></a>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="cot_id" value="<?= $c['id'] ?>">
                  <input type="hidden" name="action" value="cambiar_estado">
                  <select name="estado" class="form-select form-select-sm" style="width:auto;display:inline-block" onchange="this.form.submit()">
                    <option value="">Estado</option>
                    <option value="pendiente">Pendiente</option>
                    <option value="propuesta">Propuesta</option>
                    <option value="negociacion">Negociación</option>
                    <option value="adjudicada">Adjudicada</option>
                    <option value="desierta">Desierta</option>
                    <option value="cerrado">Cerrado</option>
                  </select>
                </form>
                <?php if (in_array($usuario['rol'], ['admin', 'gerente_comercial'])): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar Cotización N° <?= $c['numero'] ?>?')">
                  <input type="hidden" name="cot_id" value="<?= $c['id'] ?>">
                  <input type="hidden" name="action" value="eliminar">
                  <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <p class="text-muted"><small>Total: <?= count($cotizaciones) ?> cotización(es)</small></p>
  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
