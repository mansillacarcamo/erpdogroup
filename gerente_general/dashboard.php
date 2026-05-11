<?php
// ============================================================
//  GERENTE GENERAL — Dashboard ejecutivo
//  Lee: cotizaciones, combustible, obras, carchek (ocdogroup.db)
//       + gastos diarios (gastos_diarios/controlgastos.db)
// ============================================================
require_once __DIR__ . '/../config.php';

if (empty($_SESSION['gerente_general'])) {
    header('Location: ../login.php'); exit;
}
$gg = $_SESSION['gerente_general'];

// ---- BD secundaria: gastos diarios ----
$pdoGastos = null;
$cgPath = __DIR__ . '/../gastos_diarios/controlgastos.db';
if (is_file($cgPath)) {
    try {
        $pdoGastos = new PDO('sqlite:' . $cgPath);
        $pdoGastos->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdoGastos->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (Exception $e) { $pdoGastos = null; }
}

// ---- Helpers visuales ----
function h_($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmtCLP_($n) { return '$' . number_format((float)$n, 0, ',', '.'); }
function fmtL_($n)   { return number_format((float)$n, 1, ',', '.') . ' L'; }

// ====================================================================
//  WIDGET 1 — Cotizaciones pendientes de validar
// ====================================================================
$cotPendientes = [];
$cotPendientesTotal = 0;
try {
    $cotPendientesTotal = (int)$pdo->query("
        SELECT COUNT(*) FROM cot_aprobaciones WHERE estado='pendiente'
    ")->fetchColumn();
    $cotPendientes = $pdo->query("
        SELECT ca.id, ca.cot_id, ca.usuario_id, ca.creado_en,
               c.numero, c.fecha, c.cliente_nombre, c.cliente_obra, c.total, c.creada_por,
               u.nombre AS aprobador_nombre
        FROM cot_aprobaciones ca
        JOIN cotizaciones c ON c.id = ca.cot_id
        LEFT JOIN usuarios u ON u.id = ca.usuario_id
        WHERE ca.estado='pendiente'
        ORDER BY ca.creado_en DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* tabla podría no existir aún */ }

// ====================================================================
//  WIDGET 1b — Órdenes de Compra pendientes de validar
// ====================================================================
$ocPendientes = [];
$ocPendientesTotal = 0;
try {
    $ocPendientesTotal = (int)$pdo->query("
        SELECT COUNT(*) FROM oc_aprobaciones WHERE estado='pendiente'
    ")->fetchColumn();
    $ocPendientes = $pdo->query("
        SELECT a.id, a.oc_id, a.usuario_id, a.fecha_envio,
               o.numero, o.fecha, o.proveedor_nombre, o.obra,
               o.total, o.moneda, o.preparada_por,
               u.nombre AS aprobador_nombre
        FROM oc_aprobaciones a
        JOIN ordenes_compra o ON o.id = a.oc_id
        LEFT JOIN usuarios u ON u.id = a.usuario_id
        WHERE a.estado='pendiente'
        ORDER BY a.fecha_envio DESC, a.id DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* tabla podría no existir */ }

// ====================================================================
//  WIDGET 2 — Obras por estado (vía cotizaciones)
// ====================================================================
$obrasEstados = ['adjudicada'=>0, 'negociacion'=>0, 'desierta'=>0];
try {
    $rows = $pdo->query("SELECT estado, COUNT(*) c FROM cotizaciones WHERE estado IN ('adjudicada','negociacion','desierta') GROUP BY estado")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) { $obrasEstados[$r['estado']] = (int)$r['c']; }
} catch (Exception $e) {}

// ====================================================================
//  WIDGET 3 — Combustible (litros por obra + totales)
// ====================================================================
$combTotal = ['hojas'=>0, 'litros'=>0.0, 'diesel'=>0.0, 'gasolina'=>0.0, 'monto'=>0.0];
$combPorObra = [];
$combHasPrecio = false;
try {
    // Detectar si la tabla tiene un campo de precio/monto agregado a futuro
    $colsHoja = $pdo->query("PRAGMA table_info(combustible_hojas)")->fetchAll(PDO::FETCH_COLUMN, 1);
    $combHasPrecio = in_array('precio_litro', $colsHoja, true) || in_array('monto_total', $colsHoja, true);

    $sqlMonto = $combHasPrecio
        ? "SUM(COALESCE(monto_total, total_litros * COALESCE(precio_litro,0)))"
        : "0";

    $r = $pdo->query("
        SELECT COUNT(*) hojas,
               COALESCE(SUM(total_litros),0) litros,
               COALESCE(SUM(CASE WHEN tipo_combustible='diesel'   THEN total_litros END),0) diesel,
               COALESCE(SUM(CASE WHEN tipo_combustible='gasolina' THEN total_litros END),0) gasolina,
               $sqlMonto AS monto
        FROM combustible_hojas
    ")->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $combTotal['hojas']    = (int)$r['hojas'];
        $combTotal['litros']   = (float)$r['litros'];
        $combTotal['diesel']   = (float)$r['diesel'];
        $combTotal['gasolina'] = (float)$r['gasolina'];
        $combTotal['monto']    = (float)$r['monto'];
    }

    $combPorObra = $pdo->query("
        SELECT COALESCE(NULLIF(TRIM(ch.obra_nombre),''), o.nombre, '(sin obra)') AS obra,
               COUNT(*) AS hojas,
               COALESCE(SUM(ch.total_litros),0) AS litros,
               $sqlMonto AS monto
        FROM combustible_hojas ch
        LEFT JOIN obras o ON o.id = ch.obra_id
        GROUP BY obra
        ORDER BY litros DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ====================================================================
//  WIDGET 4 — Validaciones Carchek por obra
// ====================================================================
$carchekTotal = ['total'=>0,'aprob'=>0,'rech'=>0,'obs'=>0];
$carchekPorObra = [];
try {
    $r = $pdo->query("
        SELECT COUNT(*) total,
               SUM(CASE WHEN estado_revision='aprobado'    THEN 1 ELSE 0 END) aprob,
               SUM(CASE WHEN estado_revision='rechazado'   THEN 1 ELSE 0 END) rech,
               SUM(CASE WHEN estado_revision='observacion' THEN 1 ELSE 0 END) obs
        FROM carchek_log
    ")->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $carchekTotal['total'] = (int)$r['total'];
        $carchekTotal['aprob'] = (int)$r['aprob'];
        $carchekTotal['rech']  = (int)$r['rech'];
        $carchekTotal['obs']   = (int)$r['obs'];
    }
    $carchekPorObra = $pdo->query("
        SELECT COALESCE(NULLIF(TRIM(obra_nombre),''),'(sin obra)') AS obra,
               COUNT(*) AS n,
               SUM(CASE WHEN estado_revision='aprobado'    THEN 1 ELSE 0 END) AS aprob,
               SUM(CASE WHEN estado_revision='rechazado'   THEN 1 ELSE 0 END) AS rech,
               SUM(CASE WHEN estado_revision='observacion' THEN 1 ELSE 0 END) AS obs,
               MAX(fecha) AS ultima
        FROM carchek_log
        GROUP BY obra
        ORDER BY n DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ====================================================================
//  WIDGET 5 — Gastos diarios por usuario (controlgastos.db)
// ====================================================================
$gastosHoyTotal   = 0.0;
$gastosMesTotal   = 0.0;
$gastosTopUsuarios = [];
$gastosHoyPorUsr   = [];
if ($pdoGastos) {
    try {
        $hoy = date('Y-m-d');
        $ini = date('Y-m-01');
        $fin = date('Y-m-t');

        $gastosHoyTotal = (float)$pdoGastos->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE fecha=?")->execute([$hoy]) ?: 0;
        $st = $pdoGastos->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE fecha=?");
        $st->execute([$hoy]); $gastosHoyTotal = (float)$st->fetchColumn();

        $st = $pdoGastos->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE fecha BETWEEN ? AND ?");
        $st->execute([$ini, $fin]); $gastosMesTotal = (float)$st->fetchColumn();

        // Top usuarios del mes
        $st = $pdoGastos->prepare("
            SELECT u.id, u.nombre, u.usuario, u.rol,
                   COALESCE(SUM(g.monto),0) AS total_mes,
                   COUNT(g.id) AS n
            FROM usuarios u
            LEFT JOIN gastos g ON g.usuario_id=u.id AND g.fecha BETWEEN ? AND ?
            WHERE u.activo=1 AND u.rol='usuario'
            GROUP BY u.id
            ORDER BY total_mes DESC
            LIMIT 10
        ");
        $st->execute([$ini, $fin]);
        $gastosTopUsuarios = $st->fetchAll(PDO::FETCH_ASSOC);

        // Por usuario hoy
        $st = $pdoGastos->prepare("
            SELECT u.nombre, u.usuario, COALESCE(SUM(g.monto),0) AS total_dia, COUNT(g.id) AS n
            FROM gastos g
            JOIN usuarios u ON u.id=g.usuario_id
            WHERE g.fecha=?
            GROUP BY u.id
            ORDER BY total_dia DESC
            LIMIT 10
        ");
        $st->execute([$hoy]);
        $gastosHoyPorUsr = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* silencioso */ }
}

$titulo = 'Gerencia General';

// ---- Frase motivadora aleatoria ----
$frasesMotivadoras = [
    ['frase' => 'El éxito es la suma de pequeños esfuerzos repetidos día tras día.', 'autor' => 'Robert Collier'],
    ['frase' => 'No esperes el momento perfecto, toma el momento y hazlo perfecto.', 'autor' => 'Anónimo'],
    ['frase' => 'El liderazgo es el arte de hacer que otros hagan algo que tú quieres porque ellos también lo quieren.', 'autor' => 'Dwight Eisenhower'],
    ['frase' => 'La excelencia no es un acto, sino un hábito.', 'autor' => 'Aristóteles'],
    ['frase' => 'Hazlo simple. Hazlo memorable. Hazlo invitante a la mirada. Hazlo divertido de leer.', 'autor' => 'Leo Burnett'],
    ['frase' => 'Las grandes mentes discuten ideas; las mediocres, eventos; las pequeñas, personas.', 'autor' => 'Eleanor Roosevelt'],
    ['frase' => 'No cuentes los días, haz que los días cuenten.', 'autor' => 'Muhammad Ali'],
    ['frase' => 'La mejor manera de predecir el futuro es crearlo.', 'autor' => 'Peter Drucker'],
    ['frase' => 'El que no arriesga, no gana; pero el que arriesga sin cabeza, pierde todo.', 'autor' => 'Anónimo'],
    ['frase' => 'La disciplina es el puente entre las metas y los logros.', 'autor' => 'Jim Rohn'],
    ['frase' => 'Si quieres ir rápido, ve solo. Si quieres llegar lejos, ve acompañado.', 'autor' => 'Proverbio africano'],
    ['frase' => 'Los líderes no crean seguidores, crean más líderes.', 'autor' => 'Tom Peters'],
    ['frase' => 'No se mide el éxito por el dinero que ganas, sino por las vidas que tocas.', 'autor' => 'Michelle Obama'],
    ['frase' => 'Calidad significa hacerlo bien cuando nadie está mirando.', 'autor' => 'Henry Ford'],
    ['frase' => 'El que no innova, no avanza; el que no avanza, retrocede.', 'autor' => 'Anónimo'],
    ['frase' => 'Tu única limitación eres tú mismo.', 'autor' => 'Anónimo'],
    ['frase' => 'Lo difícil se hace; lo imposible se intenta.', 'autor' => 'Anónimo'],
    ['frase' => 'Una empresa fuerte nace de decisiones diarias bien tomadas.', 'autor' => 'Anónimo'],
];
$fraseHoy = $frasesMotivadoras[array_rand($frasesMotivadoras)];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h_($titulo) ?> · DOGroup</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --gg-accent: #6f42c1;
    --gg-accent-dark: #4c2a85;
  }
  body {
    font-family:'Montserrat', system-ui, sans-serif;
    background: #f7f6fb;
    min-height: 100dvh;
  }
  .gg-header {
    background: linear-gradient(135deg, var(--gg-accent), var(--gg-accent-dark));
    color:#fff;
    padding: 22px 24px 18px;
    border-bottom-left-radius: 24px; border-bottom-right-radius: 24px;
    box-shadow: 0 8px 24px rgba(76,42,133,.25);
  }
  .gg-header h3 { font-weight:800; letter-spacing:.5px; margin:0; }
  .gg-header .sub { opacity:.85; font-size:.85rem; }
  .gg-frase {
    margin-top: 16px;
    padding: 10px 14px;
    background: rgba(255,255,255,.10);
    border-left: 3px solid rgba(255,255,255,.55);
    border-radius: 8px;
    font-size: .9rem;
    line-height: 1.45;
    backdrop-filter: blur(2px);
  }
  .gg-frase i.bi-quote { font-size: 1.4rem; opacity:.7; margin-right: 6px; vertical-align:-4px; }
  .gg-frase em { font-style: italic; opacity: .95; }
  .gg-frase strong { font-weight: 700; opacity: .85; }
  .gg-card {
    background:#fff; border:0; border-radius:16px;
    box-shadow: 0 4px 14px rgba(0,0,0,.05);
  }
  .gg-card .card-header {
    background: transparent; border:0;
    padding: 18px 20px 6px;
    font-weight:700; color:#3d2766; letter-spacing:.3px;
  }
  .gg-card .card-header i { color: var(--gg-accent); }
  .kpi {
    text-align:center; padding: 14px 8px;
    background:#fafaff; border-radius: 12px; border:1px solid #ece6ff;
  }
  .kpi .lbl { font-size:.7rem; text-transform:uppercase; letter-spacing:1px; color:#6b6890; font-weight:600; }
  .kpi .val { font-size:1.45rem; font-weight:800; color:#3d2766; line-height:1.1; }
  .kpi.kpi-warn  { background:#fff8ee; border-color:#ffe2bb; }
  .kpi.kpi-warn  .val{ color:#c2570c; }
  .kpi.kpi-ok    { background:#effdf3; border-color:#bbf2cd; }
  .kpi.kpi-ok    .val{ color:#0d7a3b; }
  .kpi.kpi-bad   { background:#fdeef0; border-color:#ffc7cf; }
  .kpi.kpi-bad   .val{ color:#a01a2b; }
  .kpi.kpi-info  { background:#eef4ff; border-color:#c4d8ff; }
  .kpi.kpi-info  .val{ color:#1d4ed8; }
  .badge-soft { background: rgba(111,66,193,.10); color: var(--gg-accent-dark); font-weight:600; }
  .table thead th { font-size:.74rem; text-transform:uppercase; letter-spacing:.5px; color:#6b6890; }
  .empty-state { text-align:center; color:#94a3b8; padding: 30px 10px; font-size:.9rem; }
  .empty-state i { font-size:2rem; opacity:.4; display:block; margin-bottom:6px; }
</style>
</head>
<body>

<div class="gg-header">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-3">
      <i class="bi bi-graph-up-arrow" style="font-size:2rem;"></i>
      <div>
        <h3>Gerencia General</h3>
        <div class="sub">Bienvenido, <strong><?= h_($gg['nombre'] ?: $gg['usuario']) ?></strong> · <?= date('d/m/Y') ?></div>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a href="../inicio.php" class="btn btn-sm btn-light"><i class="bi bi-grid me-1"></i>Volver al sistema</a>
      <a href="logout.php" class="btn btn-sm btn-outline-light"><i class="bi bi-box-arrow-right me-1"></i>Salir</a>
    </div>
  </div>
  <div class="gg-frase">
    <i class="bi bi-quote"></i>
    <span><em><?= h_($fraseHoy['frase']) ?></em> — <strong><?= h_($fraseHoy['autor']) ?></strong></span>
  </div>
</div>

<div class="container py-4">

  <!-- ──────────────── FILA SUPERIOR: KPIs grandes ──────────────── -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl">
      <div class="kpi kpi-warn">
        <div class="lbl"><i class="bi bi-hourglass-split"></i> Cotizaciones por validar</div>
        <div class="val"><?= $cotPendientesTotal ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
      <div class="kpi kpi-warn">
        <div class="lbl"><i class="bi bi-file-earmark-ruled-fill"></i> OC por validar</div>
        <div class="val"><?= $ocPendientesTotal ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
      <div class="kpi kpi-ok">
        <div class="lbl"><i class="bi bi-trophy-fill"></i> Obras adjudicadas</div>
        <div class="val"><?= $obrasEstados['adjudicada'] ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
      <div class="kpi kpi-info">
        <div class="lbl"><i class="bi bi-chat-quote-fill"></i> En negociación</div>
        <div class="val"><?= $obrasEstados['negociacion'] ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
      <div class="kpi kpi-bad">
        <div class="lbl"><i class="bi bi-x-octagon-fill"></i> Desiertas</div>
        <div class="val"><?= $obrasEstados['desierta'] ?></div>
      </div>
    </div>
  </div>

  <div class="row g-3">

    <!-- ──────────────── COTIZACIONES PENDIENTES ──────────────── -->
    <div class="col-12 col-xl-7">
      <div class="card gg-card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span><i class="bi bi-file-earmark-check-fill me-1"></i> Cotizaciones pendientes de validar</span>
          <span class="badge badge-soft"><?= $cotPendientesTotal ?> total</span>
        </div>
        <div class="card-body pt-2">
          <?php if (empty($cotPendientes)): ?>
            <div class="empty-state"><i class="bi bi-check2-circle"></i>Sin cotizaciones pendientes.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead><tr>
                  <th>N°</th><th>Cliente / Obra</th><th>Creada por</th>
                  <th class="text-end">Total</th><th>Solicitada</th>
                </tr></thead>
                <tbody>
                <?php foreach ($cotPendientes as $c): ?>
                  <tr>
                    <td class="fw-bold text-primary">#<?= h_($c['numero']) ?></td>
                    <td>
                      <div class="fw-semibold"><?= h_($c['cliente_nombre']) ?></div>
                      <?php if ($c['cliente_obra']): ?><div class="small text-muted"><?= h_($c['cliente_obra']) ?></div><?php endif; ?>
                    </td>
                    <td class="small"><?= h_($c['creada_por'] ?: '-') ?></td>
                    <td class="text-end fw-bold"><?= fmtCLP_($c['total']) ?></td>
                    <td class="small text-muted"><?= h_($c['creado_en'] ?: $c['fecha']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ──────────────── COMBUSTIBLE ──────────────── -->
    <div class="col-12 col-xl-5">
      <div class="card gg-card h-100">
        <div class="card-header"><i class="bi bi-fuel-pump-fill me-1"></i> Combustible</div>
        <div class="card-body pt-2">
          <div class="row g-2 mb-3">
            <div class="col-6"><div class="kpi"><div class="lbl">Total litros</div><div class="val"><?= fmtL_($combTotal['litros']) ?></div></div></div>
            <div class="col-6"><div class="kpi"><div class="lbl">Hojas</div><div class="val"><?= $combTotal['hojas'] ?></div></div></div>
            <div class="col-6"><div class="kpi"><div class="lbl">Diesel</div><div class="val" style="color:#374151;"><?= fmtL_($combTotal['diesel']) ?></div></div></div>
            <div class="col-6"><div class="kpi"><div class="lbl">Gasolina</div><div class="val" style="color:#d97706;"><?= fmtL_($combTotal['gasolina']) ?></div></div></div>
            <?php if ($combHasPrecio): ?>
            <div class="col-12"><div class="kpi kpi-info"><div class="lbl">Monto total</div><div class="val"><?= fmtCLP_($combTotal['monto']) ?></div></div></div>
            <?php endif; ?>
          </div>
          <div class="fw-semibold small text-muted mb-1">Top obras por consumo</div>
          <?php if (empty($combPorObra)): ?>
            <div class="empty-state"><i class="bi bi-droplet"></i>Sin registros aún.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead><tr><th>Obra</th><th class="text-end">Litros</th><?php if ($combHasPrecio): ?><th class="text-end">Monto</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($combPorObra as $o): ?>
                  <tr>
                    <td><?= h_($o['obra']) ?> <span class="text-muted small">· <?= (int)$o['hojas'] ?> hojas</span></td>
                    <td class="text-end fw-semibold"><?= fmtL_($o['litros']) ?></td>
                    <?php if ($combHasPrecio): ?><td class="text-end"><?= fmtCLP_($o['monto']) ?></td><?php endif; ?>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
          <?php if (!$combHasPrecio): ?>
            <div class="small text-muted mt-2"><i class="bi bi-info-circle"></i> El monto en pesos aparecerá cuando el app de combustible registre precio por litro.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ──────────────── ÓRDENES DE COMPRA PENDIENTES ──────────────── -->
    <div class="col-12">
      <div class="card gg-card">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span><i class="bi bi-file-earmark-ruled-fill me-1"></i> Órdenes de Compra por validar</span>
          <span class="badge badge-soft"><?= $ocPendientesTotal ?> total</span>
        </div>
        <div class="card-body pt-2">
          <?php if (empty($ocPendientes)): ?>
            <div class="empty-state"><i class="bi bi-check2-circle"></i>Sin OC pendientes.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead><tr>
                  <th>N°</th><th>Proveedor / Obra</th><th>Preparada por</th>
                  <th class="text-end">Total</th><th>Enviada</th>
                </tr></thead>
                <tbody>
                <?php foreach ($ocPendientes as $o): ?>
                  <tr>
                    <td class="fw-bold text-primary">#<?= h_($o['numero']) ?></td>
                    <td>
                      <div class="fw-semibold"><?= h_($o['proveedor_nombre']) ?></div>
                      <?php if ($o['obra']): ?><div class="small text-muted"><?= h_($o['obra']) ?></div><?php endif; ?>
                    </td>
                    <td class="small"><?= h_($o['preparada_por'] ?: '-') ?></td>
                    <td class="text-end fw-bold">
                      <?php $mon = strtoupper($o['moneda'] ?: 'CLP'); ?>
                      <?= $mon === 'CLP' ? fmtCLP_($o['total']) : ($mon.' '.number_format((float)$o['total'], 2, ',', '.')) ?>
                    </td>
                    <td class="small text-muted"><?= h_($o['fecha_envio'] ?: $o['fecha']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ──────────────── GASTOS DIARIOS POR USUARIO ──────────────── -->
    <div class="col-12 col-xl-7">
      <div class="card gg-card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span><i class="bi bi-wallet2 me-1"></i> Gastos diarios por usuario</span>
          <div class="d-flex gap-2">
            <span class="badge badge-soft">Hoy <?= fmtCLP_($gastosHoyTotal) ?></span>
            <span class="badge badge-soft">Mes <?= fmtCLP_($gastosMesTotal) ?></span>
          </div>
        </div>
        <div class="card-body pt-2">
          <?php if (!$pdoGastos): ?>
            <div class="empty-state"><i class="bi bi-exclamation-triangle"></i>BD de gastos no disponible.</div>
          <?php else: ?>
            <div class="row g-3">
              <div class="col-md-6">
                <div class="fw-semibold small text-muted mb-1">Hoy · <?= date('d/m/Y') ?></div>
                <?php if (empty($gastosHoyPorUsr)): ?>
                  <div class="empty-state py-3"><i class="bi bi-calendar-x"></i>Sin gastos registrados hoy.</div>
                <?php else: ?>
                  <table class="table table-sm">
                    <tbody>
                    <?php foreach ($gastosHoyPorUsr as $u): ?>
                      <tr>
                        <td>
                          <div class="fw-semibold"><?= h_($u['nombre']) ?></div>
                          <div class="small text-muted"><?= (int)$u['n'] ?> gasto<?= $u['n']==1?'':'s' ?></div>
                        </td>
                        <td class="text-end fw-bold text-danger"><?= fmtCLP_($u['total_dia']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
              <div class="col-md-6">
                <div class="fw-semibold small text-muted mb-1">Top del mes · <?= strtoupper(date('M Y')) ?></div>
                <?php if (empty($gastosTopUsuarios)): ?>
                  <div class="empty-state py-3"><i class="bi bi-graph-down"></i>Sin gastos en el mes.</div>
                <?php else: ?>
                  <table class="table table-sm">
                    <tbody>
                    <?php foreach ($gastosTopUsuarios as $u): if ((float)$u['total_mes']<=0) continue; ?>
                      <tr>
                        <td>
                          <div class="fw-semibold"><?= h_($u['nombre']) ?></div>
                          <div class="small text-muted"><?= (int)$u['n'] ?> gasto<?= $u['n']==1?'':'s' ?></div>
                        </td>
                        <td class="text-end fw-bold"><?= fmtCLP_($u['total_mes']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ──────────────── CARCHEK VALIDACIONES POR OBRA ──────────────── -->
    <div class="col-12 col-xl-5">
      <div class="card gg-card h-100">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span><i class="bi bi-shield-check me-1"></i> Carchek · validaciones por obra</span>
          <span class="badge badge-soft"><?= $carchekTotal['total'] ?> total</span>
        </div>
        <div class="card-body pt-2">
          <div class="row g-2 mb-3">
            <div class="col-4"><div class="kpi kpi-ok"><div class="lbl">Aprobados</div><div class="val"><?= $carchekTotal['aprob'] ?></div></div></div>
            <div class="col-4"><div class="kpi kpi-warn"><div class="lbl">Observación</div><div class="val"><?= $carchekTotal['obs'] ?></div></div></div>
            <div class="col-4"><div class="kpi kpi-bad"><div class="lbl">Rechazados</div><div class="val"><?= $carchekTotal['rech'] ?></div></div></div>
          </div>
          <?php if (empty($carchekPorObra)): ?>
            <div class="empty-state"><i class="bi bi-shield"></i>Sin validaciones registradas.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm mb-0">
                <thead><tr><th>Obra</th><th class="text-center">Total</th><th class="text-center text-success">Apr.</th><th class="text-center text-warning">Obs.</th><th class="text-center text-danger">Rech.</th><th>Última</th></tr></thead>
                <tbody>
                <?php foreach ($carchekPorObra as $o): ?>
                  <tr>
                    <td class="fw-semibold"><?= h_($o['obra']) ?></td>
                    <td class="text-center"><?= (int)$o['n'] ?></td>
                    <td class="text-center text-success"><?= (int)$o['aprob'] ?></td>
                    <td class="text-center text-warning"><?= (int)$o['obs'] ?></td>
                    <td class="text-center text-danger"><?= (int)$o['rech'] ?></td>
                    <td class="small text-muted"><?= h_($o['ultima']) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
