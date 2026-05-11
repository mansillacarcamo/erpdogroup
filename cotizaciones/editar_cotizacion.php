<?php
require_once '../config.php';
requireAuth();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
$stmt->execute([$id]);
$cot = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cot) { header('Location: historial_cotizaciones.php'); exit; }

if (!in_array($cot['estado'], ['pendiente', 'corregir'])) {
  header("Location: ver_cotizacion.php?id=$id");
  exit;
}

$stmtItems = $pdo->prepare("SELECT * FROM cot_items WHERE cot_id = ? ORDER BY id");
$stmtItems->execute([$id]);
$itemsExistentes = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $nuevoNumero = (int)($_POST['numero'] ?? $cot['numero']);
    if ($nuevoNumero < 1) throw new Exception('El número de cotización debe ser mayor a 0');
    if ($nuevoNumero !== (int)$cot['numero']) {
      $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM cotizaciones WHERE numero = ? AND id != ?");
      $stmtDup->execute([$nuevoNumero, $id]);
      if ((int)$stmtDup->fetchColumn() > 0) throw new Exception('Ya existe una cotización con el N° ' . $nuevoNumero);
    }

    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $valido = $_POST['valido_hasta'] ?? '';
    $cli_nombre = trim($_POST['cli_nombre'] ?? '');
    $cli_obra = trim($_POST['cli_obra'] ?? '');
    $cli_ciudad = trim($_POST['cli_ciudad'] ?? '');
    $cli_rut = trim($_POST['cli_rut'] ?? '');
    $cli_telefono = trim($_POST['cli_telefono'] ?? '');
    $cli_email = trim($_POST['cli_email'] ?? '');
    $adicionales = trim($_POST['adicionales'] ?? '');
    $condiciones = trim($_POST['condiciones'] ?? '');
    $creada_por_tel = trim($_POST['creada_por_tel'] ?? '');
    $creada_por_email = trim($_POST['creada_por_email'] ?? '');
    $moneda = monedaValida($_POST['moneda'] ?? ($cot['moneda'] ?? 'CLP'));

    if (empty($cli_nombre)) throw new Exception('El nombre del cliente es obligatorio');

    $descripciones = $_POST['item_desc'] ?? [];
    $detalles = $_POST['item_detalle'] ?? [];
    $cantidades = $_POST['item_cant'] ?? [];
    $unidades = $_POST['item_unidad'] ?? [];
    $precios = $_POST['item_precio'] ?? [];

    if (empty($descripciones) || empty(trim($descripciones[0] ?? ''))) throw new Exception('Debe agregar al menos un ítem');

    $subtotal = 0;
    $items = [];
    for ($i = 0; $i < count($descripciones); $i++) {
      $desc = trim($descripciones[$i]);
      if (empty($desc)) continue;
      $detalle = trim($detalles[$i] ?? '');
      $cant = max(0.01, parseMonto($cantidades[$i] ?? 1));
      $unidad = mb_strtoupper(trim($unidades[$i] ?? 'UN'), 'UTF-8');
      $precio = round(max(0, parseMonto($precios[$i] ?? 0)), 2);
      $totalItem = (int)round($cant * $precio);
      $subtotal += $totalItem;
      $items[] = [$desc, $detalle, $cant, $unidad, $precio, $totalItem];
    }

    $subtotal = (int)round($subtotal);
    $iva = (int)round($subtotal * 0.19);
    $total = (int)($subtotal + $iva);

    $pdo->prepare("UPDATE cotizaciones SET numero=?, fecha=?, valido_hasta=?, cliente_nombre=?, cliente_obra=?, cliente_ciudad=?, cliente_rut=?, cliente_telefono=?, cliente_email=?, adicionales=?, condiciones=?, subtotal=?, iva=?, total=?, creada_por_telefono=?, creada_por_email=?, moneda=? WHERE id=?")
      ->execute([$nuevoNumero, $fecha, $valido, $cli_nombre, $cli_obra, $cli_ciudad, $cli_rut, $cli_telefono, $cli_email, $adicionales, $condiciones, $subtotal, $iva, $total, $creada_por_tel, $creada_por_email, $moneda, $id]);

    $pdo->prepare("DELETE FROM cot_items WHERE cot_id = ?")->execute([$id]);
    $stmtItem = $pdo->prepare("INSERT INTO cot_items (cot_id, descripcion, detalle, cantidad, unidad, precio, total) VALUES (?,?,?,?,?,?,?)");
    foreach ($items as $item) {
      $stmtItem->execute([$id, $item[0], $item[1], $item[2], $item[3], $item[4], $item[5]]);
    }

    header("Location: ver_cotizacion.php?id=$id&editada=1");
    exit;
  } catch (Exception $e) {
    $error = $e->getMessage();
  }
}

require_once '../includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-11">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-danger text-white py-3">
        <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Editar Cotización — N° <?= $cot['numero'] ?></h5>
      </div>
      <div class="card-body p-4">
        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="formCot">
          <div class="row g-3 mb-4">
            <div class="col-md-3">
              <label class="form-label fw-semibold">N° Cotización</label>
              <input type="number" name="numero" class="form-control" value="<?= $cot['numero'] ?>" min="1" required>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Fecha</label>
              <input type="date" name="fecha" class="form-control" value="<?= htmlspecialchars($cot['fecha']) ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Válido hasta</label>
              <input type="date" name="valido_hasta" class="form-control" value="<?= htmlspecialchars($cot['valido_hasta']) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Moneda</label>
              <select name="moneda" class="form-select">
                <?php $monedaActual = $cot['moneda'] ?? 'CLP'; foreach (monedasDisponibles() as $codM => $labM): ?>
                <option value="<?= $codM ?>" <?= $monedaActual === $codM ? 'selected' : '' ?>><?= htmlspecialchars($labM) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Cotizado por</label>
              <input type="text" class="form-control" value="<?= htmlspecialchars($cot['creada_por']) ?>" disabled>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Teléfono contacto</label>
              <input type="text" name="creada_por_tel" class="form-control" value="<?= htmlspecialchars($cot['creada_por_telefono']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email contacto</label>
              <input type="email" name="creada_por_email" class="form-control" value="<?= htmlspecialchars($cot['creada_por_email']) ?>">
            </div>
          </div>

          <h6 class="fw-bold text-danger mb-3"><i class="bi bi-person me-1"></i> Datos del Cliente</h6>
          <div class="row g-3 mb-4">
            <div class="col-12">
              <label class="form-label fw-semibold"><i class="bi bi-person-check me-1"></i>Seleccionar cliente guardado</label>
              <select id="selectorClienteCot" class="form-select">
                <option value="">-- Escribir manualmente --</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Nombre / Razón Social *</label>
              <input type="text" name="cli_nombre" class="form-control" id="cot_nombre" value="<?= htmlspecialchars($cot['cliente_nombre']) ?>" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Obra</label>
              <input type="text" name="cli_obra" class="form-control" value="<?= htmlspecialchars($cot['cliente_obra']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Ciudad</label>
              <input type="text" name="cli_ciudad" class="form-control" id="cot_ciudad" value="<?= htmlspecialchars($cot['cliente_ciudad'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">RUT</label>
              <input type="text" name="cli_rut" class="form-control" id="cot_rut" value="<?= htmlspecialchars($cot['cliente_rut']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Teléfono</label>
              <input type="text" name="cli_telefono" class="form-control" id="cot_telefono" value="<?= htmlspecialchars($cot['cliente_telefono']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">E-mail</label>
              <input type="email" name="cli_email" class="form-control" id="cot_email" value="<?= htmlspecialchars($cot['cliente_email']) ?>">
            </div>
          </div>

          <h6 class="fw-bold text-danger mb-3"><i class="bi bi-list-check me-1"></i> Detalle de Productos / Servicios</h6>
          <div class="table-responsive mb-3">
            <table class="table table-bordered" id="tablaCotItems">
              <thead class="table-light">
                <tr>
                  <th style="width:40%">Descripción</th>
                  <th style="width:10%">Cant.</th>
                  <th style="width:10%">Unidad</th>
                  <th style="width:15%">Precio</th>
                  <th style="width:15%">Total</th>
                  <th style="width:5%"></th>
                </tr>
              </thead>
              <tbody id="cotItemsBody">
                <?php foreach ($itemsExistentes as $it): ?>
                <tr class="cot-item-row">
                  <td><input type="text" name="item_desc[]" class="form-control form-control-sm" value="<?= htmlspecialchars($it['descripcion']) ?>" required></td>
                  <td><input type="text" inputmode="decimal" name="item_cant[]" class="form-control form-control-sm cot-cant money-input" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float)$it['cantidad'], 2, ',', '.'), '0'), ',')) ?>"></td>
                  <?php
                    $unidadesCot = ['UN','M2','M3','GL','UNIT','HRS','DÍA','MES'];
                    $unidadActualCot = mb_strtoupper(trim((string)($it['unidad'] ?? 'UN')), 'UTF-8');
                  ?>
                  <td><select name="item_unidad[]" class="form-select form-select-sm" style="width:80px">
                    <?php foreach ($unidadesCot as $u): ?>
                      <option value="<?= $u ?>" <?= $unidadActualCot === $u ? 'selected' : '' ?>><?= $u ?></option>
                    <?php endforeach; ?>
                    <?php if ($unidadActualCot !== '' && !in_array($unidadActualCot, $unidadesCot, true)): ?>
                      <option value="<?= htmlspecialchars($unidadActualCot) ?>" selected><?= htmlspecialchars($unidadActualCot) ?></option>
                    <?php endif; ?>
                  </select></td>
                  <td><input type="text" inputmode="decimal" name="item_precio[]" class="form-control form-control-sm cot-precio money-input" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float)$it['precio'], 2, ',', '.'), '0'), ',')) ?>"></td>
                  <td><span class="cot-total fw-bold">$0</span></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-cot"><i class="bi bi-trash"></i></button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <input type="hidden" name="item_detalle[]" value="">
          <button type="button" class="btn btn-outline-success btn-sm mb-4" id="btnAgregarCotItem"><i class="bi bi-plus-circle me-1"></i>Agregar ítem</button>

          <div class="row justify-content-end mb-4">
            <div class="col-md-5">
              <table class="table table-sm mb-0">
                <tr><td class="fw-semibold">SUB-TOTAL</td><td class="text-end" id="cotSubtotal">$0</td></tr>
                <tr><td class="fw-semibold">IVA 19%</td><td class="text-end" id="cotIva">$0</td></tr>
                <tr class="table-warning"><td class="fw-bold fs-5">TOTAL</td><td class="text-end fw-bold fs-5" id="cotTotalFinal">$0</td></tr>
              </table>
            </div>
          </div>

          <h6 class="fw-bold text-danger mb-3"><i class="bi bi-plus-square me-1"></i> Adicionales</h6>
          <div class="mb-3">
            <textarea name="adicionales" class="form-control" rows="2"><?= htmlspecialchars($cot['adicionales']) ?></textarea>
          </div>

          <h6 class="fw-bold text-danger mb-3"><i class="bi bi-file-text me-1"></i> Condiciones Comerciales</h6>
          <div class="mb-4">
            <textarea name="condiciones" class="form-control" rows="2"><?= htmlspecialchars($cot['condiciones']) ?></textarea>
          </div>

          <hr>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-danger btn-lg px-4"><i class="bi bi-save me-2"></i>Guardar Cambios</button>
            <a href="ver_cotizacion.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-lg px-4"><i class="bi bi-x-circle me-2"></i>Cancelar</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="../js/cotizacion.js?v=7"></script>
<script src="../js/selector_cliente.js"></script>
<script>
  iniciarSelectorCliente('selectorClienteCot', {
    nombre: 'cot_nombre', rut: 'cot_rut', telefono: 'cot_telefono', email: 'cot_email'
  }, '../api_clientes.php');
</script>
<?php require_once '../includes/footer.php'; ?>
