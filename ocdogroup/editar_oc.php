<?php
require_once 'config.php';
requireAuth();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM ordenes_compra WHERE id = ?");
$stmt->execute([$id]);
$oc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$oc) { header('Location: historial.php'); exit; }

if (!in_array($oc['estado'], ['pendiente', 'corregir'])) {
  header("Location: ver.php?id=$id");
  exit;
}

$stmtItems = $pdo->prepare("SELECT * FROM oc_items WHERE oc_id = ? ORDER BY id");
$stmtItems->execute([$id]);
$itemsExistentes = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $prov_nombre = trim($_POST['prov_nombre'] ?? '');
    $prov_rut = trim($_POST['prov_rut'] ?? '');
    $prov_direccion = trim($_POST['prov_direccion'] ?? '');
    $prov_ciudad = trim($_POST['prov_ciudad'] ?? '');
    $prov_fono = trim($_POST['prov_fono'] ?? '');
    $prov_correo = trim($_POST['prov_correo'] ?? '');
    $prov_atencion = trim($_POST['prov_atencion'] ?? '');
    $obra = trim($_POST['obra'] ?? '');
    $obraCodigo = trim($_POST['obra_codigo'] ?? '');
    $moneda = monedaValida($_POST['moneda'] ?? 'CLP');
    $tipoCambio = max(0.000001, (float)str_replace(',', '.', $_POST['tipo_cambio'] ?? '1'));
    if ($moneda === 'CLP') $tipoCambio = 1;
    $cotizado_por = trim($_POST['cotizado_por'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');
    $plazo = trim($_POST['plazo_entrega'] ?? '');

    if (empty($prov_nombre)) throw new Exception('El nombre del proveedor es obligatorio');

    $descripciones = $_POST['item_desc'] ?? [];
    $cantidades = $_POST['item_cant'] ?? [];
    $unidades = $_POST['item_unidad'] ?? [];
    $descuentos = $_POST['item_dcto'] ?? [];
    $precios = $_POST['item_precio'] ?? [];

    if (empty($descripciones) || empty(trim($descripciones[0] ?? ''))) throw new Exception('Debe agregar al menos un ítem');

    $dec  = decimalesMoneda($moneda);
    $mult = pow(10, $dec);
    $neto = 0;
    $items = [];
    for ($i = 0; $i < count($descripciones); $i++) {
      $desc = trim($descripciones[$i]);
      if (empty($desc)) continue;
      $cant     = max(0.000001, parseMonto($cantidades[$i] ?? 1));
      $unidad   = mb_strtoupper(trim($unidades[$i] ?? 'UN'), 'UTF-8');
      $dcto     = max(0, min(100, parseMonto($descuentos[$i] ?? 0)));
      $precio   = max(0, parseMonto($precios[$i] ?? 0));
      $subtotal = $cant * $precio;
      if ($dcto > 0) $subtotal -= $subtotal * $dcto / 100;
      $subtotal = round($subtotal * $mult) / $mult;
      $neto += $subtotal;
      $items[] = [$cant, $unidad, $desc, $dcto, $precio, $subtotal];
    }
    $neto  = round($neto  * $mult) / $mult;
    $iva   = round($neto * 0.19 * $mult) / $mult;
    $total = round(($neto + $iva) * $mult) / $mult;

    $pdo->prepare("UPDATE ordenes_compra SET fecha=?, proveedor_nombre=?, proveedor_rut=?, proveedor_direccion=?, proveedor_ciudad=?, proveedor_fono=?, proveedor_correo=?, proveedor_atencion=?, obra=?, obra_codigo=?, moneda=?, tipo_cambio=?, cotizado_por=?, observaciones=?, plazo_entrega=?, neto=?, iva=?, total=? WHERE id=?")
      ->execute([$fecha, $prov_nombre, $prov_rut, $prov_direccion, $prov_ciudad, $prov_fono, $prov_correo, $prov_atencion, $obra, $obraCodigo, $moneda, $tipoCambio, $cotizado_por, $observaciones, $plazo, $neto, $iva, $total, $id]);

    $pdo->prepare("DELETE FROM oc_items WHERE oc_id = ?")->execute([$id]);
    $stmtItem = $pdo->prepare("INSERT INTO oc_items (oc_id, cantidad, unidad, descripcion, descuento, precio_unitario, valor_total) VALUES (?,?,?,?,?,?,?)");
    foreach ($items as $item) {
      $stmtItem->execute([$id, $item[0], $item[1], $item[2], $item[3], $item[4], $item[5]]);
    }

    header("Location: ver.php?id=$id&editada=1");
    exit;
  } catch (Exception $e) {
    $error = $e->getMessage();
  }
}

require_once 'includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-11">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-danger text-white py-3">
        <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Editar Orden de Compra — N° <?= $oc['numero'] ?></h5>
      </div>
      <div class="card-body p-4">
        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="formOC">
          <div class="row g-3 mb-4">
            <div class="col-md-3">
              <label class="form-label fw-semibold">Fecha</label>
              <input type="date" name="fecha" class="form-control" value="<?= htmlspecialchars($oc['fecha']) ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Moneda</label>
              <select name="moneda" class="form-select">
                <?php foreach (monedasDisponibles() as $codM => $labM): ?>
                <option value="<?= $codM ?>" <?= ($oc['moneda'] ?? 'CLP') === $codM ? 'selected' : '' ?>><?= htmlspecialchars($labM) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2" id="tipoCambioWrap" style="display:<?= ($oc['moneda'] ?? 'CLP') !== 'CLP' ? '' : 'none' ?>;">
              <label class="form-label fw-semibold">Tipo de Cambio <small class="text-muted">(1 unidad = $ CLP)</small></label>
              <input type="text" name="tipo_cambio" id="tipoCambioInput" class="form-control" value="<?= number_format((float)($oc['tipo_cambio'] ?? 1), 2, ',', '.') ?>" placeholder="Ej: 950,00">
              <small id="tcRefFecha" class="text-success"></small>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Cotizado por</label>
              <input type="text" name="cotizado_por" class="form-control" value="<?= htmlspecialchars($oc['cotizado_por']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Plazo de entrega</label>
              <input type="date" name="plazo_entrega" class="form-control" value="<?= htmlspecialchars($oc['plazo_entrega']) ?>">
            </div>
          </div>

          <h6 class="fw-bold text-danger mb-3"><i class="bi bi-building me-1"></i> Datos del Proveedor</h6>
          <div class="row g-3 mb-4">
            <div class="col-12">
              <label class="form-label fw-semibold"><i class="bi bi-person-check me-1"></i>Seleccionar cliente guardado</label>
              <select id="selectorClienteOC" class="form-select">
                <option value="">-- Escribir manualmente --</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Proveedor (Señores) *</label>
              <input type="text" name="prov_nombre" class="form-control" id="oc_nombre" value="<?= htmlspecialchars($oc['proveedor_nombre']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">R.U.T</label>
              <input type="text" name="prov_rut" class="form-control" id="oc_rut" value="<?= htmlspecialchars($oc['proveedor_rut']) ?>">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Cód. Obra</label>
              <input type="text" name="obra_codigo" id="obra_codigo" class="form-control" value="<?= htmlspecialchars($oc['obra_codigo'] ?? '') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Obra</label>
              <input type="text" name="obra" id="obra_nombre" class="form-control" list="listaObras" value="<?= htmlspecialchars($oc['obra']) ?>">
              <datalist id="listaObras">
                <?php
                  try {
                    $stmtObras = $pdo->query("SELECT codigo, nombre FROM obras WHERE estado='activa' ORDER BY codigo");
                    foreach ($stmtObras as $rowO) {
                      echo '<option data-codigo="'.htmlspecialchars($rowO['codigo']).'" value="'.htmlspecialchars($rowO['nombre']).'">'.htmlspecialchars($rowO['codigo']).'</option>';
                    }
                  } catch (Exception $e) {}
                ?>
              </datalist>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Dirección</label>
              <input type="text" name="prov_direccion" class="form-control" id="oc_direccion" value="<?= htmlspecialchars($oc['proveedor_direccion']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Ciudad</label>
              <input type="text" name="prov_ciudad" class="form-control" id="oc_ciudad" value="<?= htmlspecialchars($oc['proveedor_ciudad']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Fono</label>
              <input type="text" name="prov_fono" class="form-control" id="oc_telefono" value="<?= htmlspecialchars($oc['proveedor_fono']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Correo</label>
              <input type="email" name="prov_correo" class="form-control" id="oc_email" value="<?= htmlspecialchars($oc['proveedor_correo']) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Atención (ATN)</label>
              <input type="text" name="prov_atencion" class="form-control" id="oc_contacto" value="<?= htmlspecialchars($oc['proveedor_atencion']) ?>">
            </div>
          </div>

          <h6 class="fw-bold text-danger mb-3"><i class="bi bi-list-check me-1"></i> Detalle de Productos / Servicios</h6>
          <div class="table-responsive mb-3">
            <table class="table table-bordered" id="tablaItems">
              <thead class="table-light">
                <tr>
                  <th style="width:8%">Cant.</th>
                  <th style="width:8%">Unidad</th>
                  <th style="width:35%">Descripción</th>
                  <th style="width:8%">% Dcto</th>
                  <th style="width:17%">Precio Unit.</th>
                  <th style="width:17%">Valor Total</th>
                  <th style="width:5%"></th>
                </tr>
              </thead>
              <tbody id="itemsBody">
                <?php foreach ($itemsExistentes as $idx => $it): ?>
                <tr class="item-row">
                  <td><input type="text" inputmode="decimal" name="item_cant[]" class="form-control form-control-sm item-cant" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float)$it['cantidad'], 4, ',', ''), '0'), ',')) ?>"></td>
                  <?php
                    $unidadesOC = ['UN','M2','M3','GL','UNIT','HRS','DÍA','MES'];
                    $unidadActual = mb_strtoupper(trim((string)($it['unidad'] ?? 'UN')), 'UTF-8');
                  ?>
                  <td><select name="item_unidad[]" class="form-select form-select-sm" style="width:80px">
                    <?php foreach ($unidadesOC as $u): ?>
                      <option value="<?= $u ?>" <?= $unidadActual === $u ? 'selected' : '' ?>><?= $u ?></option>
                    <?php endforeach; ?>
                    <?php if ($unidadActual !== '' && !in_array($unidadActual, $unidadesOC, true)): ?>
                      <option value="<?= htmlspecialchars($unidadActual) ?>" selected><?= htmlspecialchars($unidadActual) ?></option>
                    <?php endif; ?>
                  </select></td>
                  <td><input type="text" name="item_desc[]" class="form-control form-control-sm" value="<?= htmlspecialchars($it['descripcion']) ?>" required></td>
                  <td><input type="text" inputmode="decimal" name="item_dcto[]" class="form-control form-control-sm item-dcto" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float)$it['descuento'], 2, ',', ''), '0'), ',')) ?>"></td>
                  <td><input type="text" inputmode="decimal" name="item_precio[]" class="form-control form-control-sm item-precio money-input" value="<?= formatCLP($it['precio_unitario']) ?>"></td>
                  <td><span class="item-total fw-bold">$0</span></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-item"><i class="bi bi-trash"></i></button></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <button type="button" class="btn btn-outline-success btn-sm mb-4" id="btnAgregarItem"><i class="bi bi-plus-circle me-1"></i>Agregar ítem</button>

          <div class="row justify-content-end mb-4">
            <div class="col-md-5">
              <table class="table table-sm mb-0">
                <tr><td class="fw-semibold">NETO</td><td class="text-end" id="neto">$0</td></tr>
                <tr><td class="fw-semibold">IVA 19%</td><td class="text-end" id="iva">$0</td></tr>
                <tr class="table-danger"><td class="fw-bold fs-5">TOTAL</td><td class="text-end fw-bold fs-5" id="totalFinal">$0</td></tr>
              </table>
            </div>
          </div>

          <h6 class="fw-bold text-danger mb-3"><i class="bi bi-sticky me-1"></i> Observaciones</h6>
          <div class="mb-4">
            <textarea name="observaciones" class="form-control" rows="2"><?= htmlspecialchars($oc['observaciones']) ?></textarea>
          </div>

          <hr>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-danger btn-lg px-4"><i class="bi bi-save me-2"></i>Guardar Cambios</button>
            <a href="ver.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-lg px-4"><i class="bi bi-x-circle me-2"></i>Cancelar</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="js/oc.js?v=5"></script>
<script src="js/selector_cliente.js"></script>
<script>
  (function(){
    var btn   = document.getElementById('btnAgregarItem');
    var body  = document.getElementById('itemsBody');
    if (!btn || !body) return;
    function enfocarUltimaCant(){
      var rows = body.querySelectorAll('.item-row');
      if (!rows.length) return;
      var ic = rows[rows.length - 1].querySelector('input[name="item_cant[]"]');
      if (!ic) return;
      try { ic.focus(); ic.select(); } catch(e){}
    }
    btn.addEventListener('click', function(){
      requestAnimationFrame(function(){
        requestAnimationFrame(function(){
          enfocarUltimaCant();
          setTimeout(enfocarUltimaCant, 50);
          setTimeout(enfocarUltimaCant, 150);
        });
      });
    });
  })();
</script>
<script>
  iniciarSelectorCliente('selectorClienteOC', {
    nombre: 'oc_nombre', rut: 'oc_rut', direccion: 'oc_direccion',
    ciudad: 'oc_ciudad', telefono: 'oc_telefono', email: 'oc_email', contacto: 'oc_contacto'
  });
  (function(){
    var inObra = document.getElementById('obra_nombre');
    var inCod  = document.getElementById('obra_codigo');
    var dl     = document.getElementById('listaObras');
    if(!inObra || !inCod || !dl) return;
    inObra.addEventListener('change', function(){
      var v = inObra.value.trim().toLowerCase();
      Array.prototype.forEach.call(dl.options, function(opt){
        if (opt.value.trim().toLowerCase() === v) {
          inCod.value = opt.getAttribute('data-codigo') || opt.value;
        }
      });
    });
  })();
</script>
<?php require_once 'includes/footer.php'; ?>
