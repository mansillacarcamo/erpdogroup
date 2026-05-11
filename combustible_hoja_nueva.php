<?php
/**
 * combustible_hoja_nueva.php — Crear hoja diaria + 15 vales
 * Replica el formato del talonario "Distribución de Combustible".
 */
require_once 'config.php';
requireAuth();
requireCombustibleAcceso($usuario, $pdo);

$esAdmin = isCombustibleAdmin($usuario, $pdo);
$miResp  = getResponsableCombustible($usuario, $pdo);

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $pdo->beginTransaction();

    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
      throw new Exception('Fecha inválida.');
    }

    // Responsable: si es admin, lo elige; si no, es el suyo
    if ($esAdmin) {
      $respId = (int)($_POST['responsable_id'] ?? 0);
    } else {
      $respId = $miResp ? (int)$miResp['id'] : 0;
    }
    if (!$respId) throw new Exception('Debe seleccionar un Responsable.');

    $stR = $pdo->prepare("SELECT * FROM combustible_responsables WHERE id=?");
    $stR->execute([$respId]);
    $resp = $stR->fetch(PDO::FETCH_ASSOC);
    if (!$resp) throw new Exception('Responsable no encontrado.');

    // Obra: si es admin la elige, si no, hereda la del responsable
    $obraId = $esAdmin ? (int)($_POST['obra_id'] ?? 0) : (int)($resp['obra_id'] ?? 0);
    $obraNombre = '';
    if ($obraId) {
      $stO = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id=?");
      $stO->execute([$obraId]);
      if ($o = $stO->fetch(PDO::FETCH_ASSOC)) {
        $obraNombre = ($o['codigo'] ? $o['codigo'].' · ' : '').$o['nombre'];
      }
    } else {
      $obraNombre = trim($_POST['obra_libre'] ?? '');
    }

    $tipoComb   = in_array($_POST['tipo_combustible'] ?? '', ['diesel','gasolina']) ? $_POST['tipo_combustible'] : 'diesel';
    $tipoFuente = in_array($_POST['tipo_fuente']     ?? '', ['camion','storage','storage2'])  ? $_POST['tipo_fuente']     : 'camion';

    $miterIni = parseMonto($_POST['miter_inicial']    ?? 0);
    $miterFin = parseMonto($_POST['miter_final']      ?? 0);
    $carguio  = parseMonto($_POST['carguio_dia']      ?? 0);
    $saldoIni = parseMonto($_POST['saldo_inicial']    ?? 0);
    $litrosRe = parseMonto($_POST['litros_recibidos'] ?? 0);
    $saldoFin = parseMonto($_POST['saldo_final']      ?? 0);
    $observ   = trim($_POST['observaciones'] ?? '');

    // Insertar encabezado
    $pdo->prepare("INSERT INTO combustible_hojas
      (fecha, responsable_id, responsable_nombre, obra_id, obra_nombre,
       tipo_combustible, tipo_fuente,
       miter_inicial, miter_final, carguio_dia,
       saldo_inicial, litros_recibidos, saldo_final,
       total_litros, observaciones, estado,
       creado_por, creado_por_nombre)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'abierta',?,?)")
      ->execute([
        $fecha, $respId, $resp['nombre'], $obraId ?: null, $obraNombre,
        $tipoComb, $tipoFuente,
        $miterIni, $miterFin, $carguio,
        $saldoIni, $litrosRe, $saldoFin,
        0, $observ,
        $usuario['id'], $usuario['nombre']
      ]);
    $hojaId = (int)$pdo->lastInsertId();

    // Detalle: 15 líneas (procesar solo las que tengan algo)
    $codigos     = $_POST['codigo']         ?? [];
    $valeNros    = $_POST['nro_vale']       ?? [];
    $patentes    = $_POST['patente']        ?? [];
    $descrip     = $_POST['descripcion']    ?? [];
    $choferes    = $_POST['chofer_operador']?? [];
    $empresas    = $_POST['empresa']        ?? [];
    $cantidades  = $_POST['cantidad_litros']?? [];
    $horometros  = $_POST['horometro_kilom']?? [];
    $horas       = $_POST['hora']           ?? [];
    $partidas    = $_POST['partida']        ?? [];
    $firmas      = $_POST['firma']          ?? [];

    $totalLitros = 0;
    $stIns = $pdo->prepare("INSERT INTO combustible_vales
      (hoja_id, nro_linea, codigo, nro_vale, patente, descripcion,
       chofer_operador, empresa, cantidad_litros, horometro_kilom, hora, partida, firma)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $n = max(count($codigos), count($valeNros), count($patentes));
    for ($i=0; $i<$n; $i++) {
      $cod   = trim($codigos[$i] ?? '');
      $nv    = trim($valeNros[$i] ?? '');
      $pat   = trim($patentes[$i] ?? '');
      $des   = trim($descrip[$i] ?? '');
      $cho   = trim($choferes[$i] ?? '');
      $emp   = trim($empresas[$i] ?? '');
      $cant  = parseMonto($cantidades[$i] ?? 0);
      $hor   = trim($horometros[$i] ?? '');
      $hr    = trim($horas[$i] ?? '');
      $par   = trim($partidas[$i] ?? '');
      $fir   = trim($firmas[$i] ?? '');

      // Línea vacía → saltar
      if ($cod==='' && $nv==='' && $pat==='' && $des==='' && $cho==='' && $emp==='' && $cant==0) continue;

      $stIns->execute([$hojaId, $i+1, $cod, $nv, $pat, $des, $cho, $emp, $cant, $hor, $hr, $par, $fir]);
      $totalLitros += $cant;
    }

    // Actualizar total
    $pdo->prepare("UPDATE combustible_hojas SET total_litros=?, actualizado_en=datetime('now','localtime') WHERE id=?")
        ->execute([$totalLitros, $hojaId]);

    $pdo->commit();
    header("Location: combustible_hoja_ver.php?id=$hojaId&nueva=1");
    exit;

  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
  }
}

// Datos para selects
$obras = $pdo->query("SELECT id, codigo, nombre FROM obras WHERE estado='activa' OR estado IS NULL OR estado='' ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$responsables = $pdo->query("SELECT id, nombre, obra_id, obra_nombre FROM combustible_responsables WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/header.php';
?>
<a href="/inicio.php" data-volver data-fallback="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver</a>


<style>
.tabla-vales th { background: #cfe2f3; color: #1a1a2e; font-size: .8rem; vertical-align: middle; text-align: center; }
.tabla-vales td { padding: 2px 4px !important; }
.tabla-vales input { border: 0; background: transparent; width: 100%; padding: 6px 4px; font-size: .85rem; }
.tabla-vales input:focus { background: #fff8e1; outline: 1px solid #d97706; }
.tabla-vales tr:nth-child(even) td { background: #fafbfc; }
.tabla-vales .col-num { background: #f1f3f5; text-align:center; font-weight:600; color:#666; width:32px; }
.cabecera-hoja .form-check-input:checked { background-color:#d97706; border-color:#d97706; }
</style>

<h3 class="mb-3"><i class="bi bi-fuel-pump-fill text-warning me-2"></i>Nueva hoja de combustible</h3>

<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post" id="formHoja">
  <!-- Encabezado tipo formulario -->
  <div class="card border-0 shadow-sm mb-3 cabecera-hoja">
    <div class="card-body">
      <div class="row g-3">

        <div class="col-12 col-md-3">
          <label class="form-label small mb-1">Fecha</label>
          <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" class="form-control" required>
        </div>

        <div class="col-12 col-md-5">
          <label class="form-label small mb-1">Responsable</label>
          <?php if ($esAdmin): ?>
            <select name="responsable_id" class="form-select" required>
              <option value="">— Seleccione —</option>
              <?php foreach ($responsables as $r): ?>
                <option value="<?= $r['id'] ?>" data-obra="<?= $r['obra_id'] ?>">
                  <?= htmlspecialchars($r['nombre'].($r['obra_nombre']?' · '.$r['obra_nombre']:'')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($miResp): ?>
            <input type="text" class="form-control" value="<?= htmlspecialchars($miResp['nombre']) ?>" disabled>
            <small class="text-muted">Asignado automáticamente</small>
          <?php else: ?>
            <div class="alert alert-warning small mb-0">No estás registrado como responsable. Contacta al admin.</div>
          <?php endif; ?>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label small mb-1">Obra</label>
          <?php if ($esAdmin): ?>
            <select name="obra_id" class="form-select" id="selObra">
              <option value="0">— Sin obra / texto libre —</option>
              <?php foreach ($obras as $o): ?>
                <option value="<?= $o['id'] ?>"><?= htmlspecialchars(($o['codigo']?$o['codigo'].' · ':'').$o['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="obra_libre" placeholder="O escriba nombre de obra"
                   class="form-control mt-1" style="display:none" id="obraLibre">
          <?php else: ?>
            <input type="text" class="form-control" value="<?= htmlspecialchars($miResp['obra_nombre'] ?? 'Sin obra') ?>" disabled>
          <?php endif; ?>
        </div>

        <div class="col-6 col-md-3">
          <label class="form-label small mb-1">Tipo combustible</label>
          <div class="btn-group w-100" role="group">
            <input type="radio" class="btn-check" name="tipo_combustible" id="tcD" value="diesel" checked>
            <label class="btn btn-outline-warning" for="tcD"><i class="bi bi-droplet-fill me-1"></i>Diesel</label>
            <input type="radio" class="btn-check" name="tipo_combustible" id="tcG" value="gasolina">
            <label class="btn btn-outline-primary" for="tcG"><i class="bi bi-droplet me-1"></i>Gasolina</label>
          </div>
        </div>

        <div class="col-6 col-md-3">
          <label class="form-label small mb-1">Fuente</label>
          <div class="btn-group w-100" role="group">
            <input type="radio" class="btn-check" name="tipo_fuente" id="tfC" value="camion" checked>
            <label class="btn btn-outline-dark" for="tfC"><i class="bi bi-truck me-1"></i>Camión</label>
            <input type="radio" class="btn-check" name="tipo_fuente" id="tfS" value="storage">
            <label class="btn btn-outline-dark" for="tfS"><i class="bi bi-box-seam me-1"></i>Storage 1</label>
            <input type="radio" class="btn-check" name="tipo_fuente" id="tfS2" value="storage2">
            <label class="btn btn-outline-dark" for="tfS2"><i class="bi bi-box-seam-fill me-1"></i>Storage 2</label>
          </div>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Kilometraje inicial</label>
          <input type="text" name="miter_inicial" class="form-control text-end" inputmode="decimal">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Kilometraje final</label>
          <input type="text" name="miter_final" class="form-control text-end" inputmode="decimal">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Carguío día</label>
          <input type="text" name="carguio_dia" class="form-control text-end" inputmode="decimal">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Saldo inicial</label>
          <input type="text" name="saldo_inicial" class="form-control text-end" inputmode="decimal">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Litros recibidos</label>
          <input type="text" name="litros_recibidos" class="form-control text-end" inputmode="decimal">
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label small mb-1">Saldo final</label>
          <input type="text" name="saldo_final" class="form-control text-end" inputmode="decimal">
        </div>
      </div>
    </div>
  </div>

  <!-- Tabla de vales -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
      <span><i class="bi bi-list-ol me-2"></i>Detalle de vales</span>
      <button type="button" class="btn btn-sm btn-warning text-dark" id="btnAddRow">
        <i class="bi bi-plus-circle"></i> Agregar fila
      </button>
    </div>
    <div class="table-responsive">
      <table class="table tabla-vales mb-0">
        <thead>
          <tr>
            <th class="col-num">#</th>
            <th>Código</th>
            <th>N° Vale</th>
            <th>Patente</th>
            <th style="min-width:160px">Descripción</th>
            <th style="min-width:160px">Chofer / Operador</th>
            <th>Empresa</th>
            <th style="min-width:100px">Cantidad Litros</th>
            <th>Horómetro / KM</th>
            <th>Hora</th>
            <th>Partida</th>
            <th>Firma</th>
          </tr>
        </thead>
        <tbody id="tbVales">
        <?php for ($i=1; $i<=15; $i++): ?>
          <tr>
            <td class="col-num"><?= $i ?></td>
            <td><input type="text" name="codigo[]"></td>
            <td><input type="text" name="nro_vale[]"></td>
            <td><input type="text" name="patente[]" style="text-transform:uppercase"></td>
            <td><input type="text" name="descripcion[]"></td>
            <td><input type="text" name="chofer_operador[]"></td>
            <td><input type="text" name="empresa[]"></td>
            <td><input type="text" name="cantidad_litros[]" inputmode="decimal" class="text-end qty"></td>
            <td><input type="text" name="horometro_kilom[]"></td>
            <td><input type="text" name="hora[]" placeholder="HH:MM"></td>
            <td><input type="text" name="partida[]"></td>
            <td><input type="text" name="firma[]"></td>
          </tr>
        <?php endfor; ?>
        </tbody>
        <tfoot>
          <tr style="background:#f1f3f5;font-weight:bold">
            <td colspan="7" class="text-end px-3">TOTAL LITROS:</td>
            <td class="text-end px-3" id="totalLitros">0,00</td>
            <td colspan="4"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <label class="form-label small mb-1">Observaciones</label>
      <textarea name="observaciones" class="form-control" rows="2"></textarea>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mb-4">
    <a href="combustible.php" class="btn btn-outline-secondary">Cancelar</a>
    <button class="btn btn-warning text-dark fw-semibold">
      <i class="bi bi-save me-1"></i>Guardar hoja
    </button>
  </div>
</form>

<script>
// Total litros en tiempo real
function parseNum(v){ if(!v) return 0; return parseFloat(String(v).replace(/\./g,'').replace(',','.'))||0; }
function fmt(n){ return n.toLocaleString('es-CL', {minimumFractionDigits:2, maximumFractionDigits:2}); }

document.addEventListener('input', function(e){
  if (!e.target.classList.contains('qty')) return;
  let total = 0;
  document.querySelectorAll('.qty').forEach(i => total += parseNum(i.value));
  document.getElementById('totalLitros').textContent = fmt(total);
});

// Mostrar/ocultar campo libre de obra
const selObra = document.getElementById('selObra');
const inpLibre = document.getElementById('obraLibre');
if (selObra) {
  selObra.addEventListener('change', () => {
    inpLibre.style.display = (selObra.value === '0') ? 'block' : 'none';
  });
}

// Botón agregar fila
document.getElementById('btnAddRow')?.addEventListener('click', () => {
  const tb = document.getElementById('tbVales');
  const idx = tb.rows.length + 1;
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td class="col-num">${idx}</td>
    <td><input type="text" name="codigo[]"></td>
    <td><input type="text" name="nro_vale[]"></td>
    <td><input type="text" name="patente[]" style="text-transform:uppercase"></td>
    <td><input type="text" name="descripcion[]"></td>
    <td><input type="text" name="chofer_operador[]"></td>
    <td><input type="text" name="empresa[]"></td>
    <td><input type="text" name="cantidad_litros[]" inputmode="decimal" class="text-end qty"></td>
    <td><input type="text" name="horometro_kilom[]"></td>
    <td><input type="text" name="hora[]" placeholder="HH:MM"></td>
    <td><input type="text" name="partida[]"></td>
    <td><input type="text" name="firma[]"></td>`;
  tb.appendChild(tr);
});
</script>

<?php require_once 'includes/footer.php'; ?>
