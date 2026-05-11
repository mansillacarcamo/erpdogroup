<?php
/**
 * combustible_hoja_ver.php — Ver, editar o cerrar una hoja
 */
require_once 'config.php';
requireAuth();
requireCombustibleAcceso($usuario, $pdo);

$esAdmin = isCombustibleAdmin($usuario, $pdo);
$miResp  = getResponsableCombustible($usuario, $pdo);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: combustible.php'); exit; }

$st = $pdo->prepare("SELECT * FROM combustible_hojas WHERE id=?");
$st->execute([$id]);
$hoja = $st->fetch(PDO::FETCH_ASSOC);
if (!$hoja) { header('Location: combustible.php'); exit; }

// Permiso: admin o (responsable de la hoja / creador)
$puedeEditar = $esAdmin
            || ($miResp && (int)$miResp['id'] === (int)$hoja['responsable_id'])
            || ((int)$hoja['creado_por'] === (int)$usuario['id']);

if (!$puedeEditar) {
  header('Location: combustible.php?error=sin_permiso');
  exit;
}

// Si está cerrada, solo admin puede editar
$soloLectura = ($hoja['estado'] === 'cerrada' && !$esAdmin);

$msg = null; $error = null;

// ── Procesar acciones ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'eliminar' && $esAdmin) {
      $pdo->prepare("DELETE FROM combustible_hojas WHERE id=?")->execute([$id]);
      header('Location: combustible.php?msg=eliminada');
      exit;
    }

    if ($accion === 'cerrar' && !$soloLectura) {
      $pdo->prepare("UPDATE combustible_hojas SET estado='cerrada', cerrada_en=datetime('now','localtime'), cerrada_por=? WHERE id=?")
          ->execute([$usuario['id'], $id]);
      $msg = 'Hoja cerrada.';
      $hoja['estado'] = 'cerrada';
    }

    if ($accion === 'reabrir' && $esAdmin) {
      $pdo->prepare("UPDATE combustible_hojas SET estado='abierta', cerrada_en=NULL, cerrada_por=NULL WHERE id=?")
          ->execute([$id]);
      $msg = 'Hoja reabierta.';
      $hoja['estado'] = 'abierta';
    }

    if ($accion === 'guardar' && !$soloLectura) {
      $pdo->beginTransaction();

      // Encabezado
      $tipoComb   = in_array($_POST['tipo_combustible'] ?? '', ['diesel','gasolina']) ? $_POST['tipo_combustible'] : 'diesel';
      $tipoFuente = in_array($_POST['tipo_fuente']     ?? '', ['camion','storage','storage2'])  ? $_POST['tipo_fuente']     : 'camion';

      $pdo->prepare("UPDATE combustible_hojas SET
            fecha=?, tipo_combustible=?, tipo_fuente=?,
            miter_inicial=?, miter_final=?, carguio_dia=?,
            saldo_inicial=?, litros_recibidos=?, saldo_final=?,
            observaciones=?, actualizado_en=datetime('now','localtime')
          WHERE id=?")
          ->execute([
            $_POST['fecha'] ?? $hoja['fecha'],
            $tipoComb, $tipoFuente,
            parseMonto($_POST['miter_inicial'] ?? 0),
            parseMonto($_POST['miter_final']   ?? 0),
            parseMonto($_POST['carguio_dia']   ?? 0),
            parseMonto($_POST['saldo_inicial'] ?? 0),
            parseMonto($_POST['litros_recibidos'] ?? 0),
            parseMonto($_POST['saldo_final']   ?? 0),
            trim($_POST['observaciones'] ?? ''),
            $id
          ]);

      // Detalle: borra y reinserta (más simple y correcto que diff)
      $pdo->prepare("DELETE FROM combustible_vales WHERE hoja_id=?")->execute([$id]);

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

      $stIns = $pdo->prepare("INSERT INTO combustible_vales
        (hoja_id, nro_linea, codigo, nro_vale, patente, descripcion, chofer_operador, empresa,
         cantidad_litros, horometro_kilom, hora, partida, firma)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");

      $totalLitros = 0;
      $n = max(count($codigos), count($valeNros), count($patentes));
      for ($i=0; $i<$n; $i++) {
        $cod  = trim($codigos[$i] ?? '');
        $nv   = trim($valeNros[$i] ?? '');
        $pat  = trim($patentes[$i] ?? '');
        $des  = trim($descrip[$i] ?? '');
        $cho  = trim($choferes[$i] ?? '');
        $emp  = trim($empresas[$i] ?? '');
        $cant = parseMonto($cantidades[$i] ?? 0);
        $hor  = trim($horometros[$i] ?? '');
        $hr   = trim($horas[$i] ?? '');
        $par  = trim($partidas[$i] ?? '');
        $fir  = trim($firmas[$i] ?? '');
        if ($cod==='' && $nv==='' && $pat==='' && $des==='' && $cho==='' && $emp==='' && $cant==0) continue;
        $stIns->execute([$id, $i+1, $cod, $nv, $pat, $des, $cho, $emp, $cant, $hor, $hr, $par, $fir]);
        $totalLitros += $cant;
      }

      $pdo->prepare("UPDATE combustible_hojas SET total_litros=? WHERE id=?")
          ->execute([$totalLitros, $id]);

      $pdo->commit();
      $msg = 'Hoja actualizada.';

      // Recargar
      $st = $pdo->prepare("SELECT * FROM combustible_hojas WHERE id=?");
      $st->execute([$id]); $hoja = $st->fetch(PDO::FETCH_ASSOC);
    }

  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
  }
}

// Cargar detalle
$stV = $pdo->prepare("SELECT * FROM combustible_vales WHERE hoja_id=? ORDER BY nro_linea");
$stV->execute([$id]);
$vales = $stV->fetchAll(PDO::FETCH_ASSOC);

// Asegurar mínimo 15 filas para edición
while (count($vales) < 15) $vales[] = [
  'codigo'=>'','nro_vale'=>'','patente'=>'','descripcion'=>'',
  'chofer_operador'=>'','empresa'=>'','cantidad_litros'=>0,
  'horometro_kilom'=>'','hora'=>'','partida'=>'','firma'=>''
];

require_once 'includes/header.php';
?>

<style>
.tabla-vales th { background:#cfe2f3; color:#1a1a2e; font-size:.8rem; vertical-align:middle; text-align:center; }
.tabla-vales td { padding:2px 4px !important; }
.tabla-vales input { border:0; background:transparent; width:100%; padding:6px 4px; font-size:.85rem; }
.tabla-vales input:focus { background:#fff8e1; outline:1px solid #d97706; }
.tabla-vales tr:nth-child(even) td { background:#fafbfc; }
.tabla-vales .col-num { background:#f1f3f5; text-align:center; font-weight:600; color:#666; width:32px; }
.tabla-vales input:disabled { color:#222; opacity:1; }
</style>

<?php if (!empty($_GET['nueva'])): ?>
  <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Hoja creada correctamente.</div>
<?php endif; ?>
<?php if ($msg):   ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div>
    <h3 class="mb-0">
      <i class="bi bi-fuel-pump-fill text-warning me-2"></i>
      Hoja #<?= (int)$hoja['id'] ?>
      <?php if ($hoja['estado']==='cerrada'): ?>
        <span class="badge bg-success ms-2"><i class="bi bi-lock-fill me-1"></i>Cerrada</span>
      <?php else: ?>
        <span class="badge bg-secondary ms-2">Abierta</span>
      <?php endif; ?>
    </h3>
    <small class="text-muted">
      Creada por <?= htmlspecialchars($hoja['creado_por_nombre']) ?>
      el <?= date('d/m/Y H:i', strtotime($hoja['creado_en'])) ?>
    </small>
  </div>
  <div class="d-flex gap-2">
    <a href="combustible_export_excel.php?hoja_id=<?= $id ?>" class="btn btn-success">
      <i class="bi bi-file-earmark-excel-fill me-1"></i>Excel
    </a>
    <a href="combustible.php" class="btn btn-outline-secondary">Volver</a>
  </div>
</div>

<form method="post">
<input type="hidden" name="accion" value="guardar">

<!-- Encabezado -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <div class="row g-3">
      <div class="col-12 col-md-3">
        <label class="form-label small mb-1">Fecha</label>
        <input type="date" name="fecha" value="<?= htmlspecialchars($hoja['fecha']) ?>"
               class="form-control" <?= $soloLectura?'disabled':'' ?>>
      </div>
      <div class="col-12 col-md-5">
        <label class="form-label small mb-1">Responsable</label>
        <input type="text" class="form-control" value="<?= htmlspecialchars($hoja['responsable_nombre']) ?>" disabled>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label small mb-1">Obra</label>
        <input type="text" class="form-control" value="<?= htmlspecialchars($hoja['obra_nombre'] ?: '—') ?>" disabled>
      </div>

      <div class="col-6 col-md-3">
        <label class="form-label small mb-1">Tipo combustible</label>
        <div class="btn-group w-100" role="group">
          <input type="radio" class="btn-check" name="tipo_combustible" id="tcD" value="diesel" <?= $hoja['tipo_combustible']==='diesel'?'checked':'' ?> <?= $soloLectura?'disabled':'' ?>>
          <label class="btn btn-outline-warning" for="tcD"><i class="bi bi-droplet-fill me-1"></i>Diesel</label>
          <input type="radio" class="btn-check" name="tipo_combustible" id="tcG" value="gasolina" <?= $hoja['tipo_combustible']==='gasolina'?'checked':'' ?> <?= $soloLectura?'disabled':'' ?>>
          <label class="btn btn-outline-primary" for="tcG"><i class="bi bi-droplet me-1"></i>Gasolina</label>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label small mb-1">Fuente</label>
        <div class="btn-group w-100" role="group">
          <input type="radio" class="btn-check" name="tipo_fuente" id="tfC" value="camion" <?= $hoja['tipo_fuente']==='camion'?'checked':'' ?> <?= $soloLectura?'disabled':'' ?>>
          <label class="btn btn-outline-dark" for="tfC"><i class="bi bi-truck me-1"></i>Camión</label>
          <input type="radio" class="btn-check" name="tipo_fuente" id="tfS" value="storage" <?= $hoja['tipo_fuente']==='storage'?'checked':'' ?> <?= $soloLectura?'disabled':'' ?>>
          <label class="btn btn-outline-dark" for="tfS"><i class="bi bi-box-seam me-1"></i>Storage 1</label>
          <input type="radio" class="btn-check" name="tipo_fuente" id="tfS2" value="storage2" <?= $hoja['tipo_fuente']==='storage2'?'checked':'' ?> <?= $soloLectura?'disabled':'' ?>>
          <label class="btn btn-outline-dark" for="tfS2"><i class="bi bi-box-seam-fill me-1"></i>Storage 2</label>
        </div>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Kilometraje inicial</label>
        <input type="text" name="miter_inicial" value="<?= formatCLP($hoja['miter_inicial'], 2) ?>" class="form-control text-end" <?= $soloLectura?'disabled':'' ?>>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Kilometraje final</label>
        <input type="text" name="miter_final" value="<?= formatCLP($hoja['miter_final'], 2) ?>" class="form-control text-end" <?= $soloLectura?'disabled':'' ?>>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Carguío día</label>
        <input type="text" name="carguio_dia" value="<?= formatCLP($hoja['carguio_dia'], 2) ?>" class="form-control text-end" <?= $soloLectura?'disabled':'' ?>>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Saldo inicial</label>
        <input type="text" name="saldo_inicial" value="<?= formatCLP($hoja['saldo_inicial'], 2) ?>" class="form-control text-end" <?= $soloLectura?'disabled':'' ?>>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Litros recibidos</label>
        <input type="text" name="litros_recibidos" value="<?= formatCLP($hoja['litros_recibidos'], 2) ?>" class="form-control text-end" <?= $soloLectura?'disabled':'' ?>>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label small mb-1">Saldo final</label>
        <input type="text" name="saldo_final" value="<?= formatCLP($hoja['saldo_final'], 2) ?>" class="form-control text-end" <?= $soloLectura?'disabled':'' ?>>
      </div>
    </div>
  </div>
</div>

<!-- Detalle -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header bg-dark text-white">
    <i class="bi bi-list-ol me-2"></i>Detalle de vales
  </div>
  <div class="table-responsive">
    <table class="table tabla-vales mb-0">
      <thead>
        <tr>
          <th class="col-num">#</th>
          <th>Código</th><th>N° Vale</th><th>Patente</th>
          <th style="min-width:160px">Descripción</th>
          <th style="min-width:160px">Chofer/Operador</th>
          <th>Empresa</th><th style="min-width:100px">Cantidad Litros</th>
          <th>Horómetro/KM</th><th>Hora</th><th>Partida</th><th>Firma</th>
        </tr>
      </thead>
      <tbody id="tbVales">
      <?php foreach ($vales as $i => $v): ?>
        <tr>
          <td class="col-num"><?= $i+1 ?></td>
          <td><input type="text" name="codigo[]"          value="<?= htmlspecialchars($v['codigo']) ?>" <?= $soloLectura?'disabled':'' ?>></td>
          <td><input type="text" name="nro_vale[]"        value="<?= htmlspecialchars($v['nro_vale']) ?>" <?= $soloLectura?'disabled':'' ?>></td>
          <td><input type="text" name="patente[]"         value="<?= htmlspecialchars($v['patente']) ?>" style="text-transform:uppercase" <?= $soloLectura?'disabled':'' ?>></td>
          <td><input type="text" name="descripcion[]"     value="<?= htmlspecialchars($v['descripcion']) ?>" <?= $soloLectura?'disabled':'' ?>></td>
          <td><input type="text" name="chofer_operador[]" value="<?= htmlspecialchars($v['chofer_operador']) ?>" <?= $soloLectura?'disabled':'' ?>></td>
          <td><input type="text" name="empresa[]"         value="<?= htmlspecialchars($v['empresa']) ?>" <?= $soloLectura?'disabled':'' ?>></td>
          <td><input type="text" name="cantidad_litros[]" value="<?= $v['cantidad_litros']>0?formatCLP($v['cantidad_litros'],2):'' ?>" inputmode="decimal" class="text-end qty" <?= $soloLectura?'disabled':'' ?>></td>
          <td><input type="text" name="horometro_kilom[]" value="<?= htmlspecialchars($v['horometro_kilom']) ?>" <?= $soloLectura?'disabled':'' ?>></td>
          <td><input type="text" name="hora[]"            value="<?= htmlspecialchars($v['hora']) ?>" <?= $soloLectura?'disabled':'' ?>></td>
          <td><input type="text" name="partida[]"         value="<?= htmlspecialchars($v['partida']) ?>" <?= $soloLectura?'disabled':'' ?>></td>
          <td><input type="text" name="firma[]"           value="<?= htmlspecialchars($v['firma']) ?>" <?= $soloLectura?'disabled':'' ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#f1f3f5;font-weight:bold">
          <td colspan="7" class="text-end px-3">TOTAL LITROS:</td>
          <td class="text-end px-3" id="totalLitros"><?= formatCLP($hoja['total_litros'],2) ?></td>
          <td colspan="4"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <label class="form-label small mb-1">Observaciones</label>
    <textarea name="observaciones" class="form-control" rows="2" <?= $soloLectura?'disabled':'' ?>><?= htmlspecialchars($hoja['observaciones']) ?></textarea>
  </div>
</div>

<div class="d-flex flex-wrap justify-content-between gap-2 mb-4">
  <div>
    <?php if ($esAdmin): ?>
      <button type="submit" name="accion" value="eliminar" class="btn btn-outline-danger"
              onclick="return confirm('¿Eliminar esta hoja y todos sus vales?')" <?= $soloLectura?'disabled':'' ?>>
        <i class="bi bi-trash me-1"></i>Eliminar
      </button>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-2">
    <?php if ($hoja['estado']==='abierta' && !$soloLectura): ?>
      <button type="submit" name="accion" value="guardar" class="btn btn-warning text-dark fw-semibold">
        <i class="bi bi-save me-1"></i>Guardar cambios
      </button>
      <button type="submit" name="accion" value="cerrar" class="btn btn-success"
              onclick="return confirm('Cerrar la hoja impide editarla. ¿Continuar?')">
        <i class="bi bi-lock me-1"></i>Cerrar hoja
      </button>
    <?php elseif ($hoja['estado']==='cerrada' && $esAdmin): ?>
      <button type="submit" name="accion" value="reabrir" class="btn btn-outline-warning">
        <i class="bi bi-unlock me-1"></i>Reabrir hoja
      </button>
    <?php endif; ?>
  </div>
</div>
</form>

<script>
function parseNum(v){ if(!v) return 0; return parseFloat(String(v).replace(/\./g,'').replace(',','.'))||0; }
function fmt(n){ return n.toLocaleString('es-CL', {minimumFractionDigits:2, maximumFractionDigits:2}); }
document.addEventListener('input', function(e){
  if (!e.target.classList.contains('qty')) return;
  let total = 0;
  document.querySelectorAll('.qty').forEach(i => total += parseNum(i.value));
  document.getElementById('totalLitros').textContent = fmt(total);
});
</script>

<?php require_once 'includes/footer.php'; ?>
