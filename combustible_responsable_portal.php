<?php
/**
 * combustible_responsable_portal.php
 *
 * Portal móvil-first para Responsables de Combustible (sesión independiente).
 * Equivalente a despacho_conductor_portal.php pero para combustible.
 *
 * Permite:
 *  - Ver sus hojas (las creadas por él, su responsable_id)
 *  - Crear una nueva hoja con sus 15 vales
 *  - Marcar una hoja como cerrada
 */
require_once 'config.php';
requireCombResponsable();

$resp = getCombResponsableSession();
$msg = null; $error = null;

/* ── POST: crear hoja ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_hoja') {
  try {
    $pdo->beginTransaction();

    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) throw new Exception('Fecha inválida.');

    // El responsable opera SIEMPRE con su propio id y obra
    $respId  = (int)$resp['id'];
    $obraId  = $resp['obra_id'] ? (int)$resp['obra_id'] : null;
    $obraNombre = '';
    if ($obraId) {
      $stO = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id=?");
      $stO->execute([$obraId]);
      if ($o = $stO->fetch(PDO::FETCH_ASSOC)) {
        $obraNombre = ($o['codigo'] ? $o['codigo'].' · ' : '').$o['nombre'];
      }
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

    // Encabezado — creado_por queda en NULL porque no es un usuario interno
    $pdo->prepare("INSERT INTO combustible_hojas
      (fecha, responsable_id, responsable_nombre, obra_id, obra_nombre,
       tipo_combustible, tipo_fuente,
       miter_inicial, miter_final, carguio_dia,
       saldo_inicial, litros_recibidos, saldo_final,
       total_litros, observaciones, estado,
       creado_por, creado_por_nombre)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'abierta',?,?)")
      ->execute([
        $fecha, $respId, $resp['nombre'], $obraId, $obraNombre,
        $tipoComb, $tipoFuente,
        $miterIni, $miterFin, $carguio,
        $saldoIni, $litrosRe, $saldoFin,
        0, $observ,
        null, $resp['nombre']  // sin creado_por interno
      ]);
    $hojaId = (int)$pdo->lastInsertId();

    // Vales (15 líneas máx)
    $codigos    = $_POST['codigo']         ?? [];
    $valeNros   = $_POST['nro_vale']       ?? [];
    $patentes   = $_POST['patente']        ?? [];
    $descrip    = $_POST['descripcion']    ?? [];
    $choferes   = $_POST['chofer_operador']?? [];
    $empresas   = $_POST['empresa']        ?? [];
    $cantidades = $_POST['cantidad_litros']?? [];
    $horometros = $_POST['horometro_kilom']?? [];
    $horas      = $_POST['hora']           ?? [];
    $partidas   = $_POST['partida']        ?? [];

    $stIns = $pdo->prepare("INSERT INTO combustible_vales
      (hoja_id, nro_linea, codigo, nro_vale, patente, descripcion,
       chofer_operador, empresa, cantidad_litros,
       horometro_kilom, hora, partida)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

    $totalLitros = 0;
    for ($i = 0; $i < 15; $i++) {
      $cant = parseMonto($cantidades[$i] ?? 0);
      // Insertar la línea solo si tiene algún dato relevante
      $hayDato = !empty(trim($codigos[$i] ?? ''))
              || !empty(trim($valeNros[$i] ?? ''))
              || !empty(trim($patentes[$i] ?? ''))
              || !empty(trim($descrip[$i] ?? ''))
              || !empty(trim($choferes[$i] ?? ''))
              || $cant > 0;
      if (!$hayDato) continue;

      $stIns->execute([
        $hojaId, $i + 1,
        trim($codigos[$i] ?? ''),
        trim($valeNros[$i] ?? ''),
        trim($patentes[$i] ?? ''),
        trim($descrip[$i] ?? ''),
        trim($choferes[$i] ?? ''),
        trim($empresas[$i] ?? ''),
        $cant,
        trim($horometros[$i] ?? ''),
        trim($horas[$i] ?? ''),
        trim($partidas[$i] ?? ''),
      ]);
      $totalLitros += $cant;
    }

    // Actualizar total
    $pdo->prepare("UPDATE combustible_hojas SET total_litros = ? WHERE id = ?")
        ->execute([$totalLitros, $hojaId]);

    $pdo->commit();
    header("Location: combustible_responsable_portal.php?ok=1"); exit;
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
  }
}

/* ── POST: cerrar hoja ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cerrar_hoja') {
  try {
    $hid = (int)($_POST['hoja_id'] ?? 0);
    $pdo->prepare("UPDATE combustible_hojas
                   SET estado = 'cerrada', cerrada_en = datetime('now','localtime')
                   WHERE id = ? AND responsable_id = ? AND estado = 'abierta'")
        ->execute([$hid, $resp['id']]);
    header("Location: combustible_responsable_portal.php?cerrada=1"); exit;
  } catch (Exception $e) { $error = $e->getMessage(); }
}

/* ── Datos para la pantalla ── */
$mes = $_GET['mes'] ?? date('Y-m');

$stHojas = $pdo->prepare("
  SELECT h.*, (SELECT COUNT(*) FROM combustible_vales v WHERE v.hoja_id = h.id AND v.cantidad_litros > 0) AS n_vales
  FROM combustible_hojas h
  WHERE h.responsable_id = ? AND strftime('%Y-%m', h.fecha) = ?
  ORDER BY h.fecha DESC, h.id DESC
");
$stHojas->execute([$resp['id'], $mes]);
$hojas = $stHojas->fetchAll(PDO::FETCH_ASSOC);

// KPIs del mes
$totalLitros = 0; $totalHojas = count($hojas); $diesel = 0; $gasolina = 0;
foreach ($hojas as $h) {
  $totalLitros += (float)$h['total_litros'];
  if ($h['tipo_combustible'] === 'diesel')   $diesel   += (float)$h['total_litros'];
  if ($h['tipo_combustible'] === 'gasolina') $gasolina += (float)$h['total_litros'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Portal Combustible — <?= htmlspecialchars($resp['nombre']) ?></title>
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="#d97706">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="DOGroup Combustible">
  <link rel="apple-touch-icon" href="img/icon-192.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --dorado: #d97706; --oscuro: #1a1a2e; --muted: #6b7280; }
    body { background: #f7f7f9; padding-bottom: 80px; }
    .topbar { background: linear-gradient(135deg, var(--oscuro), #2d1b0e 70%, var(--dorado) 130%); color: #fff; padding: 16px 18px; box-shadow: 0 4px 12px rgba(0,0,0,.2); position: sticky; top: 0; z-index: 100; }
    .topbar .nombre { font-weight: 700; font-size: 1rem; line-height: 1.1; }
    .topbar .obra { color: #ffd591; font-size: .8rem; margin-top: 2px; }
    .topbar .logout { background: rgba(255,255,255,.12); color: #fff; border: 1px solid rgba(255,255,255,.25); border-radius: 8px; padding: 6px 12px; font-size: .8rem; text-decoration: none; }
    .kpi-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; padding: 12px; }
    .kpi-card { background: #fff; border-radius: 12px; padding: 12px; box-shadow: 0 2px 6px rgba(0,0,0,.06); border-left: 4px solid var(--dorado); }
    .kpi-card.diesel  { border-left-color: #d97706; }
    .kpi-card.gas     { border-left-color: #0d6efd; }
    .kpi-card.hojas   { border-left-color: #16a34a; }
    .kpi-card.total   { border-left-color: var(--oscuro); }
    .kpi-card .lbl { color: var(--muted); font-size: .7rem; text-transform: uppercase; font-weight: 700; letter-spacing: .3px; }
    .kpi-card .val { font-size: 1.4rem; font-weight: 800; color: var(--oscuro); margin-top: 2px; }
    .filtros { padding: 0 12px 12px; }
    .hoja-card { background: #fff; border-radius: 12px; padding: 14px; margin: 8px 12px; box-shadow: 0 2px 6px rgba(0,0,0,.06); }
    .hoja-card .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
    .hoja-card .fecha { font-weight: 700; color: var(--oscuro); }
    .hoja-card .litros { color: var(--dorado); font-weight: 800; font-size: 1.1rem; }
    .hoja-card .meta { font-size: .82rem; color: var(--muted); display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .tag { padding: 2px 8px; border-radius: 12px; font-size: .68rem; font-weight: 700; text-transform: uppercase; }
    .tag-diesel { background: #fef3c7; color: #92400e; }
    .tag-gasolina { background: #dbeafe; color: #1e40af; }
    .tag-camion { background: #e0e7ff; color: #3730a3; }
    .tag-storage { background: #dcfce7; color: #166534; }
    .tag-storage2 { background: #cffafe; color: #155e75; }
    .tag-abierta { background: #fef9c3; color: #854d0e; }
    .tag-cerrada { background: #e5e7eb; color: #374151; }
    .empty { text-align: center; padding: 50px 20px; color: var(--muted); }
    .empty i { font-size: 3rem; color: var(--dorado); opacity: .4; }

    .fab { position: fixed; bottom: 20px; right: 20px; width: 60px; height: 60px; border-radius: 50%; background: var(--dorado); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 6px 16px rgba(217,119,6,.5); font-size: 1.6rem; text-decoration: none; z-index: 200; border: none; }
    .fab:hover { background: #b45309; color: #fff; }

    /* Sheet (modal móvil) */
    .sheet-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.5); display: none; z-index: 300; }
    .sheet-overlay.open { display: block; }
    .sheet { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-radius: 16px 16px 0 0; max-height: 92vh; overflow-y: auto; transform: translateY(100%); transition: transform .25s; z-index: 301; }
    .sheet.open { transform: translateY(0); }
    .sheet-handle { width: 40px; height: 4px; background: #d1d5db; border-radius: 2px; margin: 8px auto; }
    .sheet-header { padding: 8px 18px 14px; border-bottom: 1px solid #f0f0f3; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: #fff; z-index: 5; }
    .sheet-title { font-weight: 700; color: var(--oscuro); }
    .sheet-close { background: transparent; border: 0; font-size: 1.5rem; color: var(--muted); }
    .sheet-body { padding: 14px 18px 24px; }

    .field { margin-bottom: 12px; }
    .field label { font-size: .78rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; display: block; margin-bottom: 4px; }
    .field input, .field select, .field textarea { width: 100%; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 12px; font-size: .95rem; background: #f9fafb; }
    .field input:focus, .field select:focus { outline: none; border-color: var(--dorado); background: #fff; box-shadow: 0 0 0 3px rgba(217,119,6,.15); }

    .vales-section { background: #f9fafb; border-radius: 12px; padding: 12px; margin-top: 10px; }
    .vale-row { background: #fff; border-radius: 10px; padding: 10px; margin-bottom: 8px; border: 1px solid #e5e7eb; }
    .vale-row .vh { font-size: .72rem; font-weight: 700; color: var(--dorado); margin-bottom: 6px; text-transform: uppercase; }
    .vale-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .vale-grid .full { grid-column: 1 / -1; }
    .vale-mini input { padding: 6px 8px; font-size: .85rem; }
    .vale-mini label { font-size: .68rem; }

    .btn-submit { background: var(--dorado); color: #fff; border: 0; width: 100%; padding: 14px; border-radius: 12px; font-weight: 700; font-size: 1rem; box-shadow: 0 6px 14px rgba(217,119,6,.3); }
    .btn-submit:hover { background: #b45309; }

    .row-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .row-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
    .accordion-vale-toggle { width: 100%; background: #fff; border: 1px dashed #d97706; color: #d97706; padding: 10px; border-radius: 10px; font-weight: 600; }
  </style>
</head>
<body>

<!-- Top bar -->
<div class="topbar d-flex justify-content-between align-items-center">
  <div>
    <div class="nombre"><i class="bi bi-fuel-pump-fill me-1"></i><?= htmlspecialchars($resp['nombre']) ?></div>
    <div class="obra"><?= htmlspecialchars($resp['obra_nombre'] ?: 'Sin obra asignada') ?></div>
  </div>
  <a href="combustible_responsable_login.php?logout=1" class="logout">
    <i class="bi bi-box-arrow-right"></i> Salir
  </a>
</div>

<!-- KPIs -->
<div class="kpi-grid">
  <div class="kpi-card total">
    <div class="lbl">Litros del mes</div>
    <div class="val"><?= number_format($totalLitros, 0, ',', '.') ?></div>
  </div>
  <div class="kpi-card hojas">
    <div class="lbl">Hojas</div>
    <div class="val"><?= $totalHojas ?></div>
  </div>
  <div class="kpi-card diesel">
    <div class="lbl">Diésel</div>
    <div class="val"><?= number_format($diesel, 0, ',', '.') ?></div>
  </div>
  <div class="kpi-card gas">
    <div class="lbl">Gasolina</div>
    <div class="val"><?= number_format($gasolina, 0, ',', '.') ?></div>
  </div>
</div>

<!-- Filtro de mes -->
<form method="get" class="filtros">
  <div class="d-flex gap-2 align-items-center">
    <input type="month" name="mes" value="<?= htmlspecialchars($mes) ?>" class="form-control form-control-sm" onchange="this.form.submit()">
    <span class="text-muted small">Mes a mostrar</span>
  </div>
</form>

<!-- Mensajes -->
<?php if (isset($_GET['ok'])): ?>
<div class="alert alert-success mx-3 py-2"><i class="bi bi-check-circle-fill me-1"></i>Hoja registrada.</div>
<?php endif; ?>
<?php if (isset($_GET['cerrada'])): ?>
<div class="alert alert-info mx-3 py-2"><i class="bi bi-lock-fill me-1"></i>Hoja cerrada.</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger mx-3 py-2"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Listado -->
<?php if (empty($hojas)): ?>
  <div class="empty">
    <i class="bi bi-inbox d-block mb-2"></i>
    <p class="mb-1">No hay hojas en este mes</p>
    <small>Toca el botón <strong>+</strong> para registrar la primera</small>
  </div>
<?php else: foreach ($hojas as $h): ?>
  <div class="hoja-card">
    <div class="top">
      <span class="fecha"><i class="bi bi-calendar3 me-1"></i><?= date('d/m/Y', strtotime($h['fecha'])) ?></span>
      <span class="litros"><?= number_format($h['total_litros'], 0, ',', '.') ?> L</span>
    </div>
    <div class="meta">
      <span class="tag tag-<?= $h['tipo_combustible'] ?>"><?= ucfirst($h['tipo_combustible']) ?></span>
      <span class="tag tag-<?= $h['tipo_fuente'] ?>"><?= ['camion'=>'Camión','storage'=>'Storage 1','storage2'=>'Storage 2'][$h['tipo_fuente']] ?? ucfirst($h['tipo_fuente']) ?></span>
      <span class="tag tag-<?= $h['estado'] ?>"><?= ucfirst($h['estado']) ?></span>
      <span><i class="bi bi-receipt"></i> <?= $h['n_vales'] ?> vales</span>
    </div>
    <?php if ($h['estado'] === 'abierta'): ?>
    <form method="POST" class="mt-2" onsubmit="return confirm('¿Cerrar esta hoja? Ya no podrás editarla.')">
      <input type="hidden" name="accion" value="cerrar_hoja">
      <input type="hidden" name="hoja_id" value="<?= $h['id'] ?>">
      <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
        <i class="bi bi-lock-fill me-1"></i>Cerrar hoja
      </button>
    </form>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>

<!-- FAB nueva hoja -->
<button class="fab" onclick="abrirNueva()" title="Nueva hoja">
  <i class="bi bi-plus-lg"></i>
</button>

<!-- Sheet: Nueva hoja -->
<div class="sheet-overlay" id="overlay" onclick="cerrarSheet()"></div>
<div class="sheet" id="sheetNueva">
  <div class="sheet-handle"></div>
  <div class="sheet-header">
    <div class="sheet-title"><i class="bi bi-plus-circle-fill text-warning me-1"></i>Nueva hoja</div>
    <button class="sheet-close" onclick="cerrarSheet()">&times;</button>
  </div>
  <div class="sheet-body">
    <form method="POST" id="formNueva">
      <input type="hidden" name="accion" value="crear_hoja">

      <div class="row-grid-2">
        <div class="field">
          <label>Fecha</label>
          <input type="date" name="fecha" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="field">
          <label>Combustible</label>
          <select name="tipo_combustible" required>
            <option value="diesel">Diesel</option>
            <option value="gasolina">Gasolina</option>
          </select>
        </div>
      </div>

      <div class="field">
        <label>Fuente</label>
        <select name="tipo_fuente" required>
          <option value="camion">Camión</option>
          <option value="storage">Storage 1</option>
          <option value="storage2">Storage 2</option>
        </select>
      </div>

      <div class="row-grid-3">
        <div class="field">
          <label>Kilometraje inicial</label>
          <input type="text" name="miter_inicial" inputmode="decimal" placeholder="0">
        </div>
        <div class="field">
          <label>Kilometraje final</label>
          <input type="text" name="miter_final" inputmode="decimal" placeholder="0">
        </div>
        <div class="field">
          <label>Carguío del día</label>
          <input type="text" name="carguio_dia" inputmode="decimal" placeholder="0">
        </div>
      </div>

      <div class="row-grid-3">
        <div class="field">
          <label>Saldo inicial</label>
          <input type="text" name="saldo_inicial" inputmode="decimal" placeholder="0">
        </div>
        <div class="field">
          <label>Litros recibidos</label>
          <input type="text" name="litros_recibidos" inputmode="decimal" placeholder="0">
        </div>
        <div class="field">
          <label>Saldo final</label>
          <input type="text" name="saldo_final" inputmode="decimal" placeholder="0">
        </div>
      </div>

      <div class="field">
        <label>Observaciones</label>
        <textarea name="observaciones" rows="2" placeholder="Opcional..."></textarea>
      </div>

      <!-- Vales -->
      <div class="vales-section">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <strong style="color:var(--oscuro)">Vales del día</strong>
          <span class="text-muted small" id="totVales">0 L</span>
        </div>

        <div id="valesContainer">
          <?php for ($i = 0; $i < 3; $i++): ?>
          <div class="vale-row vale-mini">
            <div class="vh">Vale #<?= $i + 1 ?></div>
            <div class="vale-grid">
              <div class="field">
                <label>Código</label>
                <input type="text" name="codigo[]">
              </div>
              <div class="field">
                <label>N° Vale</label>
                <input type="text" name="nro_vale[]">
              </div>
              <div class="field">
                <label>Patente</label>
                <input type="text" name="patente[]" style="text-transform:uppercase">
              </div>
              <div class="field">
                <label>Litros</label>
                <input type="text" name="cantidad_litros[]" inputmode="decimal" class="lit-input" placeholder="0">
              </div>
              <div class="field full">
                <label>Descripción</label>
                <input type="text" name="descripcion[]">
              </div>
              <div class="field full">
                <label>Chofer / Operador</label>
                <input type="text" name="chofer_operador[]">
              </div>
              <div class="field">
                <label>Empresa</label>
                <input type="text" name="empresa[]">
              </div>
              <div class="field">
                <label>Horómetro/Km</label>
                <input type="text" name="horometro_kilom[]">
              </div>
              <div class="field">
                <label>Hora</label>
                <input type="time" name="hora[]">
              </div>
              <div class="field">
                <label>Partida</label>
                <input type="text" name="partida[]">
              </div>
            </div>
          </div>
          <?php endfor; ?>
        </div>

        <button type="button" class="accordion-vale-toggle" onclick="agregarVale()">
          <i class="bi bi-plus-circle me-1"></i>Agregar otro vale
        </button>
      </div>

      <button type="submit" class="btn-submit mt-3">
        <i class="bi bi-save me-1"></i>Guardar hoja
      </button>
    </form>
  </div>
</div>

<script>
let valesActuales = 3;
const MAX_VALES = 15;

function abrirNueva() {
  document.getElementById('overlay').classList.add('open');
  document.getElementById('sheetNueva').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function cerrarSheet() {
  document.querySelectorAll('.sheet.open').forEach(s => s.classList.remove('open'));
  document.getElementById('overlay').classList.remove('open');
  document.body.style.overflow = '';
}

function agregarVale() {
  if (valesActuales >= MAX_VALES) {
    alert('Máximo ' + MAX_VALES + ' vales por hoja.');
    return;
  }
  valesActuales++;
  const html = `
    <div class="vale-row vale-mini">
      <div class="vh">Vale #${valesActuales}</div>
      <div class="vale-grid">
        <div class="field"><label>Código</label><input type="text" name="codigo[]"></div>
        <div class="field"><label>N° Vale</label><input type="text" name="nro_vale[]"></div>
        <div class="field"><label>Patente</label><input type="text" name="patente[]" style="text-transform:uppercase"></div>
        <div class="field"><label>Litros</label><input type="text" name="cantidad_litros[]" inputmode="decimal" class="lit-input" placeholder="0"></div>
        <div class="field full"><label>Descripción</label><input type="text" name="descripcion[]"></div>
        <div class="field full"><label>Chofer / Operador</label><input type="text" name="chofer_operador[]"></div>
        <div class="field"><label>Empresa</label><input type="text" name="empresa[]"></div>
        <div class="field"><label>Horómetro/Km</label><input type="text" name="horometro_kilom[]"></div>
        <div class="field"><label>Hora</label><input type="time" name="hora[]"></div>
        <div class="field"><label>Partida</label><input type="text" name="partida[]"></div>
      </div>
    </div>`;
  document.getElementById('valesContainer').insertAdjacentHTML('beforeend', html);
  // Re-bind eventos
  bindLitInputs();
}

function calcularTotal() {
  let total = 0;
  document.querySelectorAll('.lit-input').forEach(inp => {
    const v = parseFloat((inp.value || '0').replace(/\./g, '').replace(',', '.')) || 0;
    total += v;
  });
  document.getElementById('totVales').textContent =
    new Intl.NumberFormat('es-CL').format(Math.round(total)) + ' L';
}

function bindLitInputs() {
  document.querySelectorAll('.lit-input').forEach(inp => {
    inp.removeEventListener('input', calcularTotal);
    inp.addEventListener('input', calcularTotal);
  });
}
bindLitInputs();

// Service worker
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js').catch(()=>{});
}
</script>

</body>
</html>
