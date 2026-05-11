<?php
require_once 'config.php';
requireAuth();
requireModulo('oc', $usuario, $pdo);

$error = null;
$siguienteNumOC = siguienteNumeroOC($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $numeroCustom = trim($_POST['numero_oc'] ?? '');
    $usaCustom = ($numeroCustom !== '' && ctype_digit($numeroCustom) && (int)$numeroCustom > 0);
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
      $cant   = max(0.000001, parseCantidad($cantidades[$i] ?? 1));
      $unidad = mb_strtoupper(trim($unidades[$i] ?? 'UN'), 'UTF-8');
      $dcto   = max(0, min(100, parseCantidad($descuentos[$i] ?? 0)));
      $precio = max(0, parseMonto($precios[$i] ?? 0));
      $subtotal = $cant * $precio;
      if ($dcto > 0) $subtotal -= $subtotal * $dcto / 100;
      $subtotal = round($subtotal * $mult) / $mult;
      $neto += $subtotal;
      $items[] = [$cant, $unidad, $desc, $dcto, $precio, $subtotal];
    }
    $neto  = round($neto  * $mult) / $mult;
    $iva   = round($neto * 0.19 * $mult) / $mult;
    $total = round(($neto + $iva) * $mult) / $mult;

    // Generación atómica del correlativo + INSERT (con reintentos en colisión por concurrencia)
    $stmtIns  = $pdo->prepare("INSERT INTO ordenes_compra (numero, fecha, proveedor_nombre, proveedor_rut, proveedor_direccion, proveedor_ciudad, proveedor_fono, proveedor_correo, proveedor_atencion, obra, obra_codigo, moneda, tipo_cambio, cotizado_por, observaciones, plazo_entrega, neto, iva, total, preparada_por, preparada_por_cargo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmtItem = $pdo->prepare("INSERT INTO oc_items (oc_id, cantidad, unidad, descripcion, descuento, precio_unitario, valor_total) VALUES (?,?,?,?,?,?,?)");
    $maxIntentos = $usaCustom ? 1 : 5;
    $ocId = null; $numero = null;
    for ($intento = 0; $intento < $maxIntentos; $intento++) {
      try {
        $pdo->beginTransaction();
        if ($usaCustom) {
          $numero = (int)$numeroCustom;
          $stCheck = $pdo->prepare("SELECT 1 FROM ordenes_compra WHERE numero = ?");
          $stCheck->execute([$numero]);
          if ($stCheck->fetchColumn()) { $pdo->rollBack(); throw new Exception("Ya existe una OC con el N° $numero. Use otro número."); }
        } else {
          $numero = siguienteNumeroOC($pdo);
        }
        $stmtIns->execute([$numero, $fecha, $prov_nombre, $prov_rut, $prov_direccion, $prov_ciudad, $prov_fono, $prov_correo, $prov_atencion, $obra, $obraCodigo, $moneda, $tipoCambio, $cotizado_por, $observaciones, $plazo, $neto, $iva, $total, $usuario['nombre'], $usuario['cargo'] ?? '']);
        $ocId = $pdo->lastInsertId();
        foreach ($items as $item) {
          $stmtItem->execute([$ocId, $item[0], $item[1], $item[2], $item[3], $item[4], $item[5]]);
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

    header("Location: ver.php?id=$ocId&nueva=1");
    exit;
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
  }
}

require_once 'includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-11">
    <div class="card shadow-sm border-0">
      <div class="card-header bg-danger text-white py-3">
        <h5 class="mb-0"><i class="bi bi-file-earmark-plus me-2"></i>Nueva Orden de Compra</h5>
      </div>
      <div class="card-body p-4">
        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="formOC">
          <div class="row g-3 mb-4">
            <div class="col-md-3">
              <label class="form-label fw-semibold">N° Orden de Compra</label>
              <div class="input-group">
                <input type="number" name="numero_oc" id="numeroOC" class="form-control" value="<?= $siguienteNumOC ?>" min="1">
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('numeroOC').value='<?= $siguienteNumOC ?>'" title="Restaurar número sugerido"><i class="bi bi-arrow-counterclockwise"></i></button>
              </div>
              <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Sugerido: <strong><?= $siguienteNumOC ?></strong> (editable)</small>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Fecha</label>
              <input type="date" name="fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Moneda</label>
              <select name="moneda" class="form-select">
                <?php foreach (monedasDisponibles() as $codM => $labM): ?>
                <option value="<?= $codM ?>" <?= $codM === 'CLP' ? 'selected' : '' ?>><?= htmlspecialchars($labM) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2" id="tipoCambioWrap" style="display:none;">
              <label class="form-label fw-semibold">Tipo de Cambio <small class="text-muted">(1 unidad = $ CLP)</small></label>
              <input type="text" name="tipo_cambio" id="tipoCambioInput" class="form-control" value="1" placeholder="Cargando...">
              <small id="tcRefFecha" class="text-success"></small>
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Cotizado por</label>
              <?php
                $partes = explode(' ', $usuario['nombre']);
                $inicialNombre = strtoupper(substr($partes[0], 0, 1));
                $apellido = strtoupper($partes[1] ?? '');
                $cotizadoDefault = $inicialNombre . $apellido;
              ?>
              <input type="text" name="cotizado_por" class="form-control" value="<?= htmlspecialchars($cotizadoDefault) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Plazo de entrega</label>
              <input type="date" name="plazo_entrega" class="form-control" value="">
            </div>
          </div>

          <h6 class="fw-bold text-danger mb-3"><i class="bi bi-building me-1"></i> Datos del Proveedor</h6>
          <?php
            $proveedoresOC = [];
            try {
              $proveedoresOC = $pdo->query("SELECT id, nombre, rut, direccion, ciudad, telefono, email, contacto FROM proveedores ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
          ?>
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Proveedor (Señores) *</label>
              <?php if (!empty($proveedoresOC)): ?>
                <select id="prov_select" class="form-select">
                  <option value="">-- Seleccione proveedor --</option>
                  <?php foreach ($proveedoresOC as $p): ?>
                  <option value="<?= (int)$p['id'] ?>"
                          data-nombre="<?= htmlspecialchars($p['nombre']) ?>"
                          data-rut="<?= htmlspecialchars($p['rut'] ?? '') ?>"
                          data-direccion="<?= htmlspecialchars($p['direccion'] ?? '') ?>"
                          data-ciudad="<?= htmlspecialchars($p['ciudad'] ?? '') ?>"
                          data-telefono="<?= htmlspecialchars($p['telefono'] ?? '') ?>"
                          data-email="<?= htmlspecialchars($p['email'] ?? '') ?>"
                          data-contacto="<?= htmlspecialchars($p['contacto'] ?? '') ?>">
                    <?= htmlspecialchars($p['nombre']) ?><?= !empty($p['rut']) ? ' — '.htmlspecialchars($p['rut']) : '' ?>
                  </option>
                  <?php endforeach; ?>
                  <option value="__otro__">+ Otro (escribir manualmente)</option>
                </select>
                <input type="text" name="prov_nombre" id="oc_nombre" class="form-control mt-2 d-none" placeholder="Nombre del proveedor">
                <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Administra proveedores en <a href="proveedores.php">Proveedores</a> (<?= count($proveedoresOC) ?> registrados)</small>
              <?php else: ?>
                <input type="text" name="prov_nombre" class="form-control" id="oc_nombre" required>
                <small class="text-muted"><i class="bi bi-info-circle me-1"></i>No hay proveedores guardados. <a href="proveedores.php">Agregar proveedor</a></small>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">R.U.T</label>
              <input type="text" name="prov_rut" class="form-control" id="oc_rut">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Cód. Obra</label>
              <?php
                $obrasOC = [];
                try { $obrasOC = $pdo->query("SELECT codigo, nombre, ciudad FROM obras WHERE estado IS NULL OR estado='' OR estado='activa' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
              ?>
              <?php if (!empty($obrasOC)): ?>
                <select id="obra_codigo_select" class="form-select">
                  <option value="">-- Seleccione --</option>
                  <?php foreach ($obrasOC as $ob): ?>
                  <option value="<?= htmlspecialchars($ob['codigo']) ?>"
                          data-nombre="<?= htmlspecialchars($ob['nombre']) ?>"
                          data-ciudad="<?= htmlspecialchars($ob['ciudad'] ?? '') ?>">
                    <?= htmlspecialchars($ob['codigo']) ?>
                  </option>
                  <?php endforeach; ?>
                  <option value="__otro__">+ Otro (escribir)</option>
                </select>
                <input type="text" name="obra_codigo" id="obra_codigo" class="form-control mt-2 d-none" placeholder="Ej: OB-001">
              <?php else: ?>
                <input type="text" name="obra_codigo" id="obra_codigo" class="form-control" placeholder="Ej: OB-001">
              <?php endif; ?>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Obra</label>
              <?php if (!empty($obrasOC)): ?>
                <select id="obra_nombre_select" class="form-select">
                  <option value="">-- Seleccione obra --</option>
                  <?php foreach ($obrasOC as $ob): ?>
                  <option value="<?= htmlspecialchars($ob['nombre']) ?>"
                          data-codigo="<?= htmlspecialchars($ob['codigo']) ?>"
                          data-ciudad="<?= htmlspecialchars($ob['ciudad'] ?? '') ?>">
                    <?= htmlspecialchars(trim(($ob['codigo'] ? $ob['codigo'].' · ' : '').$ob['nombre'])) ?>
                  </option>
                  <?php endforeach; ?>
                  <option value="__otro__">+ Otra (escribir)</option>
                </select>
                <input type="text" name="obra" id="obra_nombre" class="form-control mt-2 d-none" placeholder="Escriba el nombre de la obra">
              <?php else: ?>
                <input type="text" name="obra" id="obra_nombre" class="form-control">
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Dirección</label>
              <input type="text" name="prov_direccion" class="form-control" id="oc_direccion">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Ciudad</label>
              <input type="text" name="prov_ciudad" class="form-control" id="oc_ciudad">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Fono</label>
              <input type="text" name="prov_fono" class="form-control" id="oc_telefono">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Correo</label>
              <input type="email" name="prov_correo" class="form-control" id="oc_email">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Atención (ATN)</label>
              <input type="text" name="prov_atencion" class="form-control" id="oc_contacto">
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
                <tr class="item-row">
                  <td><input type="text" inputmode="decimal" name="item_cant[]" class="form-control form-control-sm item-cant" value="1"></td>
                  <td><select name="item_unidad[]" class="form-select form-select-sm" style="width:80px">
                    <option value="UN" selected>UN</option>
                    <option value="M2">M2</option>
                    <option value="M3">M3</option>
                    <option value="GL">GL</option>
                    <option value="UNIT">UNIT</option>
                    <option value="HRS">HRS</option>
                    <option value="DÍA">DÍA</option>
                    <option value="MES">MES</option>
                  </select></td>
                  <td><input type="text" name="item_desc[]" class="form-control form-control-sm" placeholder="Descripción del producto o servicio" required></td>
                  <td><input type="text" inputmode="decimal" name="item_dcto[]" class="form-control form-control-sm item-dcto" value="0"></td>
                  <td><input type="text" inputmode="decimal" name="item_precio[]" class="form-control form-control-sm item-precio money-input" value="0"></td>
                  <td><span class="item-total fw-bold">$0</span></td>
                  <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-item"><i class="bi bi-trash"></i></button></td>
                </tr>
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
            <textarea name="observaciones" class="form-control" rows="2"></textarea>
          </div>

          <hr>
          <button type="submit" class="btn btn-danger btn-lg px-4"><i class="bi bi-save me-2"></i>Generar Orden de Compra</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script src="js/oc.js?v=5"></script>
<script src="js/selector_cliente.js"></script>
<script>
  // Garantiza el focus en Cantidad al agregar un nuevo item (independiente del cache de oc.js)
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
      // Esperar a que oc.js inserte la fila, luego enfocar Cant.
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
  // Selector de proveedor: rellena los campos relacionados al elegir uno guardado,
  // o muestra el input libre si el usuario elige "Otro".
  (function(){
    var sel = document.getElementById('prov_select');
    var inNombre = document.getElementById('oc_nombre');
    if (!sel || !inNombre) return;
    var campos = {
      rut: 'oc_rut', direccion: 'oc_direccion', ciudad: 'oc_ciudad',
      telefono: 'oc_telefono', email: 'oc_email', contacto: 'oc_contacto'
    };
    function limpiar() {
      Object.keys(campos).forEach(function(k){
        var el = document.getElementById(campos[k]);
        if (el) el.value = '';
      });
    }
    sel.addEventListener('change', function(){
      var opt = sel.options[sel.selectedIndex];
      if (sel.value === '__otro__') {
        inNombre.classList.remove('d-none');
        inNombre.value = '';
        inNombre.setAttribute('required','required');
        inNombre.focus();
        limpiar();
        return;
      }
      inNombre.classList.add('d-none');
      inNombre.removeAttribute('required');
      if (!sel.value) { inNombre.value = ''; limpiar(); return; }
      inNombre.value = opt.getAttribute('data-nombre') || '';
      Object.keys(campos).forEach(function(k){
        var el = document.getElementById(campos[k]);
        if (el) el.value = opt.getAttribute('data-' + k) || '';
      });
    });
  })();
  // Selectores sincronizados de Cód. Obra y Obra
  (function(){
    var selCod = document.getElementById('obra_codigo_select');
    var inCod  = document.getElementById('obra_codigo');
    var selNom = document.getElementById('obra_nombre_select');
    var inNom  = document.getElementById('obra_nombre');
    var inCiu  = document.getElementById('oc_ciudad');

    if (selCod && inCod) selCod.setAttribute('name','obra_codigo');
    if (selNom && inNom) selNom.setAttribute('name','obra');

    function activarOtroCodigo() {
      if (!selCod || !inCod) return;
      selCod.value = '__otro__';
      inCod.classList.remove('d-none');
      inCod.setAttribute('name','obra_codigo');
      selCod.removeAttribute('name');
    }
    function activarOtroNombre() {
      if (!selNom || !inNom) return;
      selNom.value = '__otro__';
      inNom.classList.remove('d-none');
      inNom.setAttribute('name','obra');
      selNom.removeAttribute('name');
    }
    function ocultarOtroCodigo() {
      if (!selCod || !inCod) return;
      inCod.classList.add('d-none');
      inCod.value = '';
      inCod.removeAttribute('name');
      selCod.setAttribute('name','obra_codigo');
    }
    function ocultarOtroNombre() {
      if (!selNom || !inNom) return;
      inNom.classList.add('d-none');
      inNom.value = '';
      inNom.removeAttribute('name');
      selNom.setAttribute('name','obra');
    }
    function buscarEnSelect(sel, val) {
      var found = false;
      if (!sel) return false;
      Array.prototype.forEach.call(sel.options, function(o){ if (o.value === val) found = true; });
      return found;
    }

    if (selCod) {
      selCod.addEventListener('change', function(){
        var opt = selCod.options[selCod.selectedIndex];
        if (selCod.value === '__otro__') {
          activarOtroCodigo();
          inCod.value = '';
          inCod.focus();
        } else {
          ocultarOtroCodigo();
          var nom = opt ? opt.getAttribute('data-nombre') : '';
          var ciu = opt ? opt.getAttribute('data-ciudad') : '';
          if (selNom && nom) {
            if (buscarEnSelect(selNom, nom)) { selNom.value = nom; ocultarOtroNombre(); }
            else { activarOtroNombre(); inNom.value = nom; }
          } else if (inNom && nom) {
            inNom.value = nom;
          }
          if (ciu && inCiu && !inCiu.value) inCiu.value = ciu;
        }
      });
    }

    if (selNom) {
      selNom.addEventListener('change', function(){
        var opt = selNom.options[selNom.selectedIndex];
        if (selNom.value === '__otro__') {
          activarOtroNombre();
          inNom.value = '';
          inNom.focus();
        } else {
          ocultarOtroNombre();
          var cod = opt ? opt.getAttribute('data-codigo') : '';
          var ciu = opt ? opt.getAttribute('data-ciudad') : '';
          if (selCod && cod) {
            if (buscarEnSelect(selCod, cod)) { selCod.value = cod; ocultarOtroCodigo(); }
            else { activarOtroCodigo(); inCod.value = cod; }
          } else if (inCod && cod) {
            inCod.value = cod;
          }
          if (ciu && inCiu && !inCiu.value) inCiu.value = ciu;
        }
      });
    }
  })();
</script>
<?php require_once 'includes/footer.php'; ?>
