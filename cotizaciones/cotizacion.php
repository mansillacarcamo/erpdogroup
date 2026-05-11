<?php
require_once '../config.php';
requireAuth();

$error = null;
$siguienteNum = siguienteNumeroCot($pdo);
$obrasDisponibles = $pdo->query("SELECT id, codigo, nombre, ciudad FROM obras WHERE estado IS NULL OR estado = '' OR estado = 'activa' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $numeroCustom = trim($_POST['numero_cot'] ?? '');
    $usaCustom = ($numeroCustom !== '' && ctype_digit($numeroCustom) && (int)$numeroCustom > 0);
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
    $moneda = monedaValida($_POST['moneda'] ?? 'CLP');
    $tipoCambio = max(0.000001, (float)str_replace(',', '.', $_POST['tipo_cambio'] ?? '1'));
    if ($moneda === 'CLP') $tipoCambio = 1;

    if (empty($cli_nombre)) throw new Exception('El nombre del cliente es obligatorio');

    $descripciones = $_POST['item_desc'] ?? [];
    $detalles = $_POST['item_detalle'] ?? [];
    $cantidades = $_POST['item_cant'] ?? [];
    $unidades = $_POST['item_unidad'] ?? [];
    $precios = $_POST['item_precio'] ?? [];

    if (empty($descripciones) || empty(trim($descripciones[0] ?? ''))) throw new Exception('Debe agregar al menos un ítem');

    $dec  = decimalesMoneda($moneda);
    $mult = pow(10, $dec);
    $subtotal = 0;
    $items = [];
    for ($i = 0; $i < count($descripciones); $i++) {
      $desc = trim($descripciones[$i]);
      if (empty($desc)) continue;
      $detalle   = trim($detalles[$i] ?? '');
      $cant      = max(0.000001, parseMonto($cantidades[$i] ?? 1));
      $unidad    = mb_strtoupper(trim($unidades[$i] ?? 'UN'), 'UTF-8');
      $precio    = max(0, parseMonto($precios[$i] ?? 0));
      $totalItem = round($cant * $precio * $mult) / $mult;
      $subtotal += $totalItem;
      $items[] = [$desc, $detalle, $cant, $unidad, $precio, $totalItem];
    }
    $subtotal = round($subtotal * $mult) / $mult;
    $gastosPct     = max(0, min(100, (float)($_POST['gastos_pct']   ?? 8)));
    $utilidadPct   = max(0, min(100, (float)($_POST['utilidad_pct'] ?? 10)));
    $gastosMonto   = round($subtotal * ($gastosPct   / 100) * $mult) / $mult;
    $utilidadMonto = round($subtotal * ($utilidadPct / 100) * $mult) / $mult;
    $totalNeto     = round(($subtotal + $gastosMonto + $utilidadMonto) * $mult) / $mult;
    $iva           = round($totalNeto * 0.19 * $mult) / $mult;
    $total         = round(($totalNeto + $iva) * $mult) / $mult;

    // Generación atómica del correlativo + INSERT (con reintentos en colisión por concurrencia)
    $stmtIns  = $pdo->prepare("INSERT INTO cotizaciones (numero, fecha, valido_hasta, cliente_nombre, cliente_obra, cliente_ciudad, cliente_rut, cliente_telefono, cliente_email, adicionales, condiciones, subtotal, gastos_pct, gastos_monto, utilidad_pct, utilidad_monto, total_neto, iva, total, creada_por, creada_por_cargo, creada_por_telefono, creada_por_email, moneda, tipo_cambio) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmtItem = $pdo->prepare("INSERT INTO cot_items (cot_id, descripcion, detalle, cantidad, unidad, precio, total) VALUES (?,?,?,?,?,?,?)");
    $maxIntentos = $usaCustom ? 1 : 5;
    $cotId = null; $numero = null;
    for ($intento = 0; $intento < $maxIntentos; $intento++) {
      try {
        $pdo->beginTransaction();
        if ($usaCustom) {
          $numero = (int)$numeroCustom;
          $stCheck = $pdo->prepare("SELECT 1 FROM cotizaciones WHERE numero = ?");
          $stCheck->execute([$numero]);
          if ($stCheck->fetchColumn()) { $pdo->rollBack(); throw new Exception("Ya existe una cotización con el N° $numero. Use otro número."); }
        } else {
          $numero = siguienteNumeroCot($pdo);
        }
        $stmtIns->execute([$numero, $fecha, $valido, $cli_nombre, $cli_obra, $cli_ciudad, $cli_rut, $cli_telefono, $cli_email, $adicionales, $condiciones, $subtotal, $gastosPct, $gastosMonto, $utilidadPct, $utilidadMonto, $totalNeto, $iva, $total, $usuario['nombre'], $usuario['cargo'] ?? '', $creada_por_tel, $creada_por_email, $moneda, $tipoCambio]);
        $cotId = $pdo->lastInsertId();
        foreach ($items as $item) {
          $stmtItem->execute([$cotId, $item[0], $item[1], $item[2], $item[3], $item[4], $item[5]]);
        }
        $pdo->commit();
        break;
      } catch (PDOException $pe) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!$usaCustom && stripos($pe->getMessage(), 'UNIQUE') !== false && $intento < $maxIntentos - 1) {
          usleep(50000 + random_int(0, 50000));
          continue;
        }
        throw $pe;
      }
    }

    header("Location: ver_cotizacion.php?id=$cotId&nueva=1");
    exit;
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
  }
}

require_once '../includes/header.php';
?>
<a href="historial_cotizaciones.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver al historial</a>


<div class="row justify-content-center">
  <div class="col-lg-11">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-danger text-white py-3">
        <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Nueva Cotización</h5>
      </div>
      <div class="card-body p-4">
        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="formCot">
          <div class="row g-3 mb-4">
            <div class="col-md-3">
              <label class="form-label fw-semibold">N° Cotización</label>
              <div class="input-group">
                <input type="number" name="numero_cot" id="numeroCot" class="form-control" value="<?= $siguienteNum ?>" min="1">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('numeroCot').value='<?= $siguienteNum ?>'" title="Restaurar número sugerido"><i class="bi bi-arrow-counterclockwise"></i></button>
              </div>
              <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Correlativo sugerido: <strong><?= $siguienteNum ?></strong> (editable)</small>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Fecha</label>
              <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Válido hasta</label>
              <input type="date" name="valido_hasta" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Moneda</label>
              <select name="moneda" class="form-select">
                <?php foreach (monedasDisponibles() as $codM => $labM): ?>
                <option value="<?= $codM ?>" <?= $codM === 'CLP' ? 'selected' : '' ?>><?= htmlspecialchars($labM) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2" id="cotTipoCambioWrap" style="display:none;">
              <label class="form-label fw-semibold">Tipo de Cambio <small class="text-muted">(1 unidad = $ CLP)</small></label>
              <input type="text" name="tipo_cambio" id="cotTipoCambioInput" class="form-control" value="1" placeholder="Cargando...">
              <small id="cotTcRefFecha" class="text-success"></small>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Cotizado por</label>
              <input type="text" class="form-control" value="<?= htmlspecialchars($usuario['nombre']) ?>" disabled>
            </div>
          </div>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Teléfono contacto</label>
              <input type="text" name="creada_por_tel" class="form-control" placeholder="+56 9 7707 3751">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email contacto</label>
              <input type="email" name="creada_por_email" class="form-control" placeholder="correo@dogroup.cl">
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
              <input type="text" name="cli_nombre" class="form-control" id="cot_nombre" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Obra</label>
              <?php if (!empty($obrasDisponibles)): ?>
                <select id="cot_obra_select" class="form-select">
                  <option value="">-- Seleccione --</option>
                  <?php foreach ($obrasDisponibles as $ob): ?>
                  <option
                    value="<?= htmlspecialchars($ob['nombre']) ?>"
                    data-ciudad="<?= htmlspecialchars($ob['ciudad'] ?? '') ?>"
                    data-codigo="<?= htmlspecialchars($ob['codigo'] ?? '') ?>">
                    <?= htmlspecialchars(trim((($ob['codigo'] ?? '') ? $ob['codigo'].' · ' : '').$ob['nombre'])) ?>
                  </option>
                  <?php endforeach; ?>
                  <option value="__otro__">+ Otra (escribir)</option>
                </select>
                <input type="text" name="cli_obra" id="cot_obra_input" class="form-control mt-2 d-none" placeholder="Escriba el nombre de la obra">
              <?php else: ?>
                <input type="text" name="cli_obra" class="form-control">
                <small class="text-muted"><i class="bi bi-info-circle me-1"></i>No hay obras registradas. Agréguelas en <a href="../obras.php">Obras</a>.</small>
              <?php endif; ?>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Ciudad</label>
              <input type="text" name="cli_ciudad" class="form-control" id="cot_ciudad" placeholder="Ej: Osorno">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">RUT</label>
              <input type="text" name="cli_rut" class="form-control" id="cot_rut">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Teléfono</label>
              <input type="text" name="cli_telefono" class="form-control" id="cot_telefono">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">E-mail</label>
              <input type="email" name="cli_email" class="form-control" id="cot_email">
            </div>
          </div>

          <h6 class="fw-bold text-danger mb-3"><i class="bi bi-list-check me-1"></i> Detalle de Productos / Servicios</h6>
          <div class="table-responsive mb-3">
            <table class="table table-bordered" id="tablaCotItems">
              <thead class="table-light">
                <tr>
                  <th style="width:10%">Cant.</th>
                  <th style="width:10%">Unidad</th>
                  <th style="width:40%">Descripción</th>
                  <th style="width:18%">Precio Unit.</th>
                  <th style="width:18%">Valor Total</th>
                  <th style="width:4%"></th>
                </tr>
              </thead>
              <tbody id="cotItemsBody">
                <tr class="cot-item-row">
                  <td><input type="text" inputmode="decimal" name="item_cant[]" class="form-control form-control-sm cot-cant" value="1"></td>
                  <td><select name="item_unidad[]" class="form-select form-select-sm" style="width:80px"><option value="UN">UN</option><option value="M2">M2</option><option value="M3">M3</option><option value="GL">GL</option><option value="UNIT">UNIT</option><option value="HRS">HRS</option><option value="DÍA">DÍA</option><option value="MES">MES</option></select></td>
                  <td><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder="Descripción del producto o servicio" required></td>
                  <td><input type="text" inputmode="decimal" name="item_precio[]" class="form-control form-control-sm cot-precio money-input" value="0"></td>
                  <td><span class="cot-total fw-bold">$0</span></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-cot"><i class="bi bi-trash"></i></button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <button type="button" class="btn btn-outline-success btn-sm mb-4" id="btnAgregarCotItem"><i class="bi bi-plus-circle me-1"></i>Agregar ítem</button>

          <div class="row justify-content-end mb-4">
            <div class="col-md-6">
              <table class="table table-sm mb-0 border rounded">
                <tbody>
                  <tr>
                    <td class="fw-semibold">SUB-TOTAL</td>
                    <td class="text-end" id="cotSubtotal">$0</td>
                  </tr>
                  <tr class="table-light">
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <span class="fw-semibold">Gastos Generales</span>
                        <div class="input-group input-group-sm" style="width:90px">
                          <input type="number" id="gastosInput" name="gastos_pct"
                            class="form-control form-control-sm text-end"
                            value="8" min="0" max="100" step="0.5">
                          <span class="input-group-text">%</span>
                        </div>
                      </div>
                    </td>
                    <td class="text-end fw-semibold" id="cotGastos">$0</td>
                  </tr>
                  <tr class="table-light">
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <span class="fw-semibold">Utilidades</span>
                        <div class="input-group input-group-sm" style="width:90px">
                          <input type="number" id="utilInput" name="utilidad_pct"
                            class="form-control form-control-sm text-end"
                            value="10" min="0" max="100" step="0.5">
                          <span class="input-group-text">%</span>
                        </div>
                      </div>
                    </td>
                    <td class="text-end fw-semibold" id="cotUtilidad">$0</td>
                  </tr>
                  <tr class="table-secondary">
                    <td class="fw-bold">TOTAL NETO</td>
                    <td class="text-end fw-bold" id="cotTotalNeto">$0</td>
                  </tr>
                  <tr>
                    <td class="fw-semibold">IVA 19%</td>
                    <td class="text-end" id="cotIva">$0</td>
                  </tr>
                  <tr class="table-warning">
                    <td class="fw-bold fs-5">TOTAL</td>
                    <td class="text-end fw-bold fs-5" id="cotTotalFinal">$0</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <h6 class="fw-bold text-danger mb-3"><i class="bi bi-plus-square me-1"></i> Adicionales</h6>
          <div class="mb-3">
            <textarea name="adicionales" class="form-control" rows="2" placeholder="Ej: Con Orden de compra"></textarea>
          </div>

          <h6 class="fw-bold text-danger mb-3"><i class="bi bi-file-text me-1"></i> Condiciones Comerciales</h6>
          <div class="mb-4">
            <textarea name="condiciones" class="form-control" rows="2" placeholder="Términos y condiciones adicionales..."></textarea>
          </div>

          <hr>
          <button type="submit" class="btn btn-danger btn-lg px-4"><i class="bi bi-save me-2"></i>Generar Cotización</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="../js/cotizacion.js?v=8"></script>
<script src="../js/selector_cliente.js"></script>
<script>
  iniciarSelectorCliente('selectorClienteCot', {
    nombre: 'cot_nombre', rut: 'cot_rut', telefono: 'cot_telefono', email: 'cot_email'
  });

  (function () {
    var sel = document.getElementById('cot_obra_select');
    var inp = document.getElementById('cot_obra_input');
    if (!sel || !inp) return;
    sel.addEventListener('change', function () {
      var opt = sel.options[sel.selectedIndex];
      if (sel.value === '__otro__') {
        inp.classList.remove('d-none');
        inp.value = '';
        inp.focus();
        inp.setAttribute('name', 'cli_obra');
        sel.removeAttribute('name');
      } else {
        inp.classList.add('d-none');
        inp.value = '';
        inp.removeAttribute('name');
        sel.setAttribute('name', 'cli_obra');
        var ciudad = opt ? opt.getAttribute('data-ciudad') : '';
        var ciudadInp = document.getElementById('cot_ciudad');
        if (ciudad && ciudadInp && !ciudadInp.value) ciudadInp.value = ciudad;
      }
    });
    sel.setAttribute('name', 'cli_obra');
  })();
</script>
<?php require_once '../includes/footer.php'; ?>
