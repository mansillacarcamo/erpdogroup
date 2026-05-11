<?php
/**
 * Portal de Gastos por Obra — Conductor / Operador
 * Acceso desde despacho_conductor_portal.php o directo
 */
require_once 'config.php';

// Detectar sesión: conductor del sistema de despacho
$esConductor = isConductorLoggedIn();
$esOperador  = !empty($_SESSION['faena_operador']);

if (!$esConductor && !$esOperador) {
    header('Location: despacho_conductor_login.php'); exit;
}

if ($esConductor) {
    $cond     = getConductorSession();
    $uid      = 'c'.$cond['id'];
    $nombre   = $cond['nombre'];
    $obraId   = (int)($cond['obra_id'] ?? 0);
    $tipoUser = 'conductor';
    $condId   = (int)$cond['id'];
    $operadorId = null;
} else {
    $oper     = $_SESSION['faena_operador'];
    $uid      = 'o'.$oper['id'];
    $nombre   = $oper['nombre'];
    $obraId   = (int)($oper['obra_id'] ?? 0);
    $tipoUser = 'operador';
    $condId   = null;
    $operadorId = (int)$oper['id'];
}

$primerNombre = explode(' ', $nombre)[0];
$hoy = date('Y-m-d');
$mes = date('Y-m');

// Asignación activa del mes
$stAsig = $condId
    ? $pdo->prepare("SELECT fa.*, fp.monto_asignado AS pres_total FROM faena_asignaciones fa JOIN faena_presupuestos fp ON fp.id=fa.presupuesto_id WHERE fa.conductor_id=? AND fa.anio=? AND fa.mes=? AND fa.activa=1 LIMIT 1")
    : $pdo->prepare("SELECT fa.*, fp.monto_asignado AS pres_total FROM faena_asignaciones fa JOIN faena_presupuestos fp ON fp.id=fa.presupuesto_id WHERE fa.operador_id=? AND fa.anio=? AND fa.mes=? AND fa.activa=1 LIMIT 1");
$stAsig->execute([$condId ?? $operadorId, date('Y'), date('n')]);
$asignacion = $stAsig->fetch(PDO::FETCH_ASSOC);

// Mis gastos del mes
$stG = $condId
    ? $pdo->prepare("SELECT g.*, a.ruta AS arch FROM gastos_faena g LEFT JOIN gastos_faena_archivos a ON a.id=(SELECT MIN(id) FROM gastos_faena_archivos WHERE gasto_id=g.id) WHERE g.conductor_id=? AND strftime('%Y-%m',g.fecha)=? ORDER BY g.fecha DESC, g.creado_en DESC")
    : $pdo->prepare("SELECT g.*, a.ruta AS arch FROM gastos_faena g LEFT JOIN gastos_faena_archivos a ON a.id=(SELECT MIN(id) FROM gastos_faena_archivos WHERE gasto_id=g.id) WHERE g.operador_id=? AND strftime('%Y-%m',g.fecha)=? ORDER BY g.fecha DESC, g.creado_en DESC");
$stG->execute([$condId ?? $operadorId, $mes]);
$misGastos = $stG->fetchAll(PDO::FETCH_ASSOC);

$totalGastado = array_sum(array_column($misGastos,'monto')) + array_sum(array_column($misGastos,'monto_peaje'));
$saldo = ($asignacion ? (float)$asignacion['monto_asignado'] : 0) - $totalGastado;
$pctUsado = $asignacion && $asignacion['monto_asignado'] > 0
    ? min(100, round($totalGastado / $asignacion['monto_asignado'] * 100))
    : 0;

$CATS = ['Combustible'=>'bi-fuel-pump-fill','Alimentación'=>'bi-cup-hot-fill','Peaje'=>'bi-signpost-2-fill','Herramientas'=>'bi-tools','Otros'=>'bi-three-dots'];

/* ══ POST: Registrar gasto ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion']??'') === 'crear') {
    try {
        $cat    = $_POST['categoria'] ?? '';
        $desc   = trim($_POST['descripcion'] ?? '');
        $monto  = (float)str_replace(['.','$',' '],['','',''], $_POST['monto'] ?? '0');
        $peaje  = (float)str_replace(['.','$',' '],['','',''], $_POST['monto_peaje'] ?? '0');
        $fecha  = $_POST['fecha'] ?? $hoy;
        $prov   = trim($_POST['proveedor'] ?? '');
        if ($monto <= 0 && $peaje <= 0) throw new Exception('Ingresa un monto mayor a 0.');
        if (!$desc) throw new Exception('Describe el gasto.');

        $obraNom = '';
        if ($obraId) {
            $stO = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id=?");
            $stO->execute([$obraId]);
            $oRow = $stO->fetch(PDO::FETCH_ASSOC);
            $obraNom = $oRow ? $oRow['codigo'].' — '.$oRow['nombre'] : '';
        }

        $pdo->prepare("INSERT INTO gastos_faena
            (fecha,obra_id,obra_nombre,categoria_nombre,descripcion,monto,monto_peaje,
             proveedor,tipo_documento,estado,tipo_usuario,conductor_id,operador_id,
             asignacion_id,registrado_por,registrado_por_nombre)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$fecha,$obraId?:null,$obraNom,$cat,$desc,$monto,$peaje,
                       $prov,'boleta','pendiente',$tipoUser,
                       $condId,$operadorId,
                       $asignacion['id']??null,
                       0,$nombre]);

        $gid = (int)$pdo->lastInsertId();

        // Actualizar monto gastado en asignación
        if ($asignacion) {
            $pdo->prepare("UPDATE faena_asignaciones SET monto_gastado=monto_gastado+? WHERE id=?")
                ->execute([$monto+$peaje, $asignacion['id']]);
        }

        // Subir foto
        if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error']===UPLOAD_ERR_OK) {
            $dir = __DIR__.'/uploads/faena';
            if (!is_dir($dir)) mkdir($dir,0775,true);
            $ext = strtolower(pathinfo($_FILES['foto']['name'],PATHINFO_EXTENSION));
            if (in_array($ext,['jpg','jpeg','png','webp','pdf'])) {
                $nv = 'gf'.$gid.'_'.time().'.'.$ext;
                if (move_uploaded_file($_FILES['foto']['tmp_name'],$dir.'/'.$nv)) {
                    $pdo->prepare("INSERT INTO gastos_faena_archivos (gasto_id,nombre,ruta,tipo_mime) VALUES (?,?,?,?)")
                        ->execute([$gid,$_FILES['foto']['name'],'faena/'.$nv,$_FILES['foto']['type']]);
                    $pdo->prepare("UPDATE gastos_faena SET tiene_archivo=1 WHERE id=?")->execute([$gid]);
                }
            }
        }

        header('Location: faena_conductor_portal.php?ok=1'); exit;
    } catch(Exception $e) {
        $errMsg = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#d97706">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Gastos por Obra · <?= htmlspecialchars($primerNombre) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
:root{--amber:#d97706;--amber-d:#b45309;--bg:#fffbf2;--card:#fff;--dark:#1b2838;--success:#16a34a;--danger:#dc2626;--border:#e5e7eb;--radius:14px;--bottom:68px}
html,body{margin:0;padding:0;background:var(--bg);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;min-height:100vh}
/* TOP */
.top{background:linear-gradient(135deg,var(--amber),var(--amber-d));padding:14px 16px 20px;color:#fff}
.top-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.top-av{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.95rem}
.top-name{font-weight:800;font-size:.95rem}
.top-role{font-size:.7rem;opacity:.75}
.top-back{color:rgba(255,255,255,.85);background:none;border:none;font-size:1.1rem;padding:6px;cursor:pointer;border-radius:8px}
/* PRESUPUESTO */
.budget-card{background:rgba(255,255,255,.18);border-radius:12px;padding:14px 16px;backdrop-filter:blur(4px)}
.budget-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px}
.budget-label{font-size:.72rem;opacity:.8;text-transform:uppercase;letter-spacing:.5px}
.budget-saldo{font-size:1.8rem;font-weight:900;line-height:1}
.budget-meta{font-size:.78rem;opacity:.75}
.prog-bar{height:6px;background:rgba(255,255,255,.25);border-radius:3px;overflow:hidden}
.prog-fill{height:100%;border-radius:3px;transition:width .5s ease}
/* CONTENT */
.content{padding:0 0 calc(var(--bottom)+16px)}
/* STATS */
.stats{display:flex;gap:8px;padding:14px 16px 0}
.stat{flex:1;background:var(--card);border-radius:12px;padding:10px 8px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.stat-n{font-size:1.3rem;font-weight:900;line-height:1}
.stat-l{font-size:.62rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:3px}
/* GASTOS */
.section-head{display:flex;align-items:center;justify-content:space-between;padding:16px 16px 8px}
.section-title{font-size:.78rem;font-weight:800;color:#6b7280;text-transform:uppercase;letter-spacing:.5px}
.gasto-list{padding:0 16px;display:flex;flex-direction:column;gap:8px}
.gasto-card{background:var(--card);border-radius:var(--radius);padding:12px 14px;box-shadow:0 1px 4px rgba(0,0,0,.06);border-left:4px solid var(--border);display:flex;gap:12px;align-items:flex-start}
.gasto-card.pendiente{border-left-color:#fbbf24}
.gasto-card.aprobado{border-left-color:var(--success)}
.gasto-card.rechazado{border-left-color:var(--danger)}
.gasto-thumb{width:48px;height:48px;border-radius:8px;object-fit:cover;flex-shrink:0}
.gasto-thumb-ph{width:48px;height:48px;border-radius:8px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:1.2rem;flex-shrink:0}
.gasto-desc{font-weight:700;font-size:.88rem;color:#111}
.gasto-meta{font-size:.75rem;color:#6b7280;margin-top:2px;display:flex;flex-wrap:wrap;gap:6px}
.gasto-monto{font-size:1rem;font-weight:900;color:#111;margin-top:4px}
.gasto-badge{font-size:.62rem;font-weight:700;padding:2px 7px;border-radius:999px;margin-left:6px}
.badge-p{background:#fef3c7;color:#92400e}
.badge-a{background:#dcfce7;color:#14532d}
.badge-r{background:#fee2e2;color:#7f1d1d}
/* EMPTY */
.empty{text-align:center;padding:48px 20px;color:#9ca3af}
.empty i{font-size:3rem;opacity:.3;display:block;margin-bottom:10px}
/* BOTTOM NAV */
.bottom-nav{position:fixed;bottom:0;left:0;right:0;height:var(--bottom);background:var(--card);border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-around;z-index:100;padding:0 8px;padding-bottom:env(safe-area-inset-bottom)}
.nav-btn{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;cursor:pointer;border:none;background:none;color:#9ca3af;font-size:.58rem;font-weight:700;padding:8px 4px;border-radius:10px}
.nav-btn.active{color:var(--amber)}
.nav-btn i{font-size:1.25rem}
.nav-fab{background:var(--amber);color:#fff;border-radius:16px;padding:10px 22px;font-size:.72rem;font-weight:800;flex:0;box-shadow:0 4px 14px rgba(217,119,6,.4)}
.nav-fab i{font-size:1.3rem}
/* SHEET */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;opacity:0;pointer-events:none;transition:opacity .25s}
.overlay.open{opacity:1;pointer-events:all}
.sheet{position:fixed;bottom:0;left:0;right:0;background:var(--card);border-radius:20px 20px 0 0;z-index:300;transform:translateY(100%);transition:transform .3s cubic-bezier(.32,.72,0,1);max-height:92vh;overflow-y:auto;padding-bottom:env(safe-area-inset-bottom)}
.sheet.open{transform:translateY(0)}
.sheet-handle{width:40px;height:4px;background:var(--border);border-radius:2px;margin:12px auto 0}
.sheet-hdr{padding:14px 20px 12px;border-bottom:1px solid var(--border);font-weight:800;font-size:1rem;color:var(--dark);display:flex;align-items:center;gap:8px}
.sheet-body{padding:16px 20px}
.field-lbl{font-size:.75rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;display:block}
.field{width:100%;border:1.5px solid var(--border);border-radius:10px;padding:11px 14px;font-size:.95rem;outline:none;background:#fff;-webkit-appearance:none;margin-bottom:14px}
.field:focus{border-color:var(--amber)}
.field-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.cat-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px}
.cat-btn{border:1.5px solid var(--border);border-radius:10px;padding:10px 6px;text-align:center;cursor:pointer;background:#fff;transition:all .15s;font-size:.75rem;font-weight:600;color:#374151}
.cat-btn.sel{border-color:var(--amber);background:#fef3c7;color:var(--amber-d)}
.cat-btn i{display:block;font-size:1.4rem;margin-bottom:3px}
.btn-submit{width:100%;background:var(--amber);color:#fff;border:none;border-radius:12px;padding:14px;font-size:1rem;font-weight:800;cursor:pointer;margin-top:4px}
.btn-submit:active{opacity:.85}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:14px;font-size:.85rem;font-weight:600}
.alert-d{background:#fee2e2;color:#7f1d1d}
.alert-s{background:#dcfce7;color:#14532d}
/* Preview foto */
#fotoPreview{width:100%;max-height:180px;object-fit:contain;border-radius:10px;border:1px solid var(--border);display:none;margin-top:8px}
</style>
</head>
<body>

<!-- TOP -->
<div class="top">
  <div class="top-row">
    <div style="display:flex;align-items:center;gap:10px">
      <div class="top-av"><?= strtoupper(substr($primerNombre,0,1)) ?></div>
      <div>
        <div class="top-name"><?= htmlspecialchars($primerNombre) ?></div>
        <div class="top-role"><?= ucfirst($tipoUser) ?> · Gastos por Obra</div>
      </div>
    </div>
    <a href="<?= $esConductor?'despacho_conductor_portal.php':'despacho_conductor_login.php' ?>" class="top-back">
      <i class="bi bi-arrow-left"></i>
    </a>
  </div>

  <!-- Presupuesto del mes -->
  <div class="budget-card">
    <?php if ($asignacion): ?>
    <div class="budget-top">
      <div>
        <div class="budget-label">Presupuesto <?= date('M Y') ?></div>
        <div class="budget-saldo">$<?= number_format($saldo,0,',','.') ?></div>
        <div class="budget-meta">saldo disponible</div>
      </div>
      <div style="text-align:right">
        <div class="budget-label">Gastado</div>
        <div style="font-size:1.1rem;font-weight:800">$<?= number_format($totalGastado,0,',','.') ?></div>
        <div class="budget-meta"><?= $pctUsado ?>% del total</div>
      </div>
    </div>
    <div class="prog-bar">
      <div class="prog-fill" style="width:<?= $pctUsado ?>%;background:<?= $pctUsado>=90?'#ef4444':($pctUsado>=70?'#f59e0b':'rgba(255,255,255,.9)') ?>"></div>
    </div>
    <?php else: ?>
    <div style="text-align:center;opacity:.8;font-size:.88rem;padding:6px 0">
      <i class="bi bi-exclamation-triangle me-1"></i>Sin presupuesto asignado este mes
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="content">
  <!-- STATS -->
  <div class="stats">
    <div class="stat">
      <div class="stat-n"><?= count($misGastos) ?></div>
      <div class="stat-l">Gastos</div>
    </div>
    <div class="stat">
      <div class="stat-n" style="color:var(--success)"><?= count(array_filter($misGastos,fn($g)=>$g['estado']==='aprobado')) ?></div>
      <div class="stat-l">Aprobados</div>
    </div>
    <div class="stat">
      <div class="stat-n" style="color:#f59e0b"><?= count(array_filter($misGastos,fn($g)=>$g['estado']==='pendiente')) ?></div>
      <div class="stat-l">Pendientes</div>
    </div>
  </div>

  <!-- ALERTA -->
  <?php if (!empty($_GET['ok'])): ?>
  <div style="margin:10px 16px 0"><div class="alert alert-s"><i class="bi bi-check-circle-fill me-1"></i>Gasto registrado correctamente.</div></div>
  <?php endif; ?>
  <?php if (!empty($errMsg)): ?>
  <div style="margin:10px 16px 0"><div class="alert alert-d"><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($errMsg) ?></div></div>
  <?php endif; ?>

  <!-- LISTA GASTOS -->
  <div class="section-head">
    <span class="section-title">Mis gastos — <?= date('M Y') ?></span>
    <span style="font-size:.75rem;color:#9ca3af">$<?= number_format($totalGastado,0,',','.') ?> total</span>
  </div>

  <?php if (empty($misGastos)): ?>
  <div class="empty">
    <i class="bi bi-receipt-cutoff"></i>
    <strong>Sin gastos registrados</strong><br>
    <small>Toca + para agregar tu primer gasto</small>
  </div>
  <?php else: ?>
  <div class="gasto-list">
    <?php foreach ($misGastos as $g):
      $est   = $g['estado'];
      $bc    = ['pendiente'=>'badge-p','aprobado'=>'badge-a','rechazado'=>'badge-r'][$est]??'badge-p';
      $ico   = ['Combustible'=>'bi-fuel-pump-fill','Alimentación'=>'bi-cup-hot-fill','Peaje'=>'bi-signpost-2-fill','Herramientas'=>'bi-tools'][$g['categoria_nombre']]??'bi-receipt';
      $totG  = (float)$g['monto']+(float)$g['monto_peaje'];
    ?>
    <div class="gasto-card <?= $est ?>">
      <?php if ($g['arch']): ?>
        <?php $extA=strtolower(pathinfo($g['arch'],PATHINFO_EXTENSION)); ?>
        <?php if($extA==='pdf'): ?>
        <div class="gasto-thumb-ph" style="background:#fee2e2"><i class="bi bi-file-earmark-pdf-fill" style="color:#dc2626"></i></div>
        <?php else: ?>
        <img src="uploads/<?= htmlspecialchars($g['arch']) ?>" class="gasto-thumb" alt="">
        <?php endif; ?>
      <?php else: ?>
      <div class="gasto-thumb-ph"><i class="bi <?= $ico ?>"></i></div>
      <?php endif; ?>
      <div style="flex:1;min-width:0">
        <div class="gasto-desc">
          <?= htmlspecialchars($g['descripcion']) ?>
          <span class="gasto-badge <?= $bc ?>"><?= ucfirst($est) ?></span>
        </div>
        <div class="gasto-meta">
          <span><i class="bi bi-calendar3" style="font-size:11px"></i><?= date('d/m',strtotime($g['fecha'])) ?></span>
          <?php if($g['categoria_nombre']): ?><span><?= htmlspecialchars($g['categoria_nombre']) ?></span><?php endif; ?>
          <?php if($g['proveedor']): ?><span><?= htmlspecialchars($g['proveedor']) ?></span><?php endif; ?>
        </div>
        <div class="gasto-monto">
          $<?= number_format((float)$g['monto'],0,',','.') ?>
          <?php if((float)$g['monto_peaje']>0): ?>
          <span style="font-size:.8rem;color:#2563eb;font-weight:700;margin-left:6px">
            + Peaje $<?= number_format((float)$g['monto_peaje'],0,',','.') ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- BOTTOM NAV -->
<nav class="bottom-nav">
  <button class="nav-btn active"><i class="bi bi-receipt-cutoff"></i>Gastos</button>
  <button class="nav-btn nav-fab" onclick="abrirSheet()"><i class="bi bi-plus-lg"></i> Nuevo</button>
  <button class="nav-btn" onclick="location.href='<?= $esConductor?'despacho_conductor_portal.php':'' ?>'">
    <i class="bi bi-truck-front-fill"></i>Despacho
  </button>
</nav>

<!-- OVERLAY -->
<div class="overlay" id="overlay" onclick="cerrarSheet()"></div>

<!-- SHEET: Nuevo gasto -->
<div class="sheet" id="sheet">
  <div class="sheet-handle"></div>
  <div class="sheet-hdr"><i class="bi bi-receipt-cutoff" style="color:var(--amber)"></i>Nuevo gasto de faena</div>
  <div class="sheet-body">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="accion" value="crear">
      <input type="hidden" name="categoria" id="catSelected" value="">

      <!-- Categorías -->
      <label class="field-lbl">Categoría *</label>
      <div class="cat-grid">
        <?php foreach ($CATS as $cat => $ico): ?>
        <div class="cat-btn" onclick="selCat('<?= $cat ?>', this)">
          <i class="bi <?= $ico ?>"></i><?= $cat ?>
        </div>
        <?php endforeach; ?>
      </div>

      <div class="field-row">
        <div>
          <label class="field-lbl">Fecha *</label>
          <input type="date" name="fecha" class="field" value="<?= $hoy ?>" required>
        </div>
        <div>
          <label class="field-lbl">Monto ($) *</label>
          <input type="text" name="monto" class="field" inputmode="numeric" placeholder="0" required>
        </div>
      </div>

      <div>
        <label class="field-lbl">Descripción *</label>
        <textarea name="descripcion" class="field" rows="2" placeholder="¿En qué se gastó?" required style="resize:none"></textarea>
      </div>

      <div>
        <label class="field-lbl">Proveedor / Lugar</label>
        <input type="text" name="proveedor" class="field" placeholder="Ej. Copec, Líder, Peaje Ruta 5...">
      </div>

      <div>
        <label class="field-lbl"><i class="bi bi-camera"></i> Foto boleta</label>
        <input type="file" name="foto" accept="image/*" capture="environment"
               class="field" style="padding:8px" onchange="previewFoto(this)">
        <img id="fotoPreview" src="" alt="preview">
      </div>

      <button type="submit" class="btn-submit">
        <i class="bi bi-save-fill me-1"></i>Guardar gasto
      </button>
    </form>
  </div>
</div>

<script>
function abrirSheet(){ document.getElementById('overlay').classList.add('open'); document.getElementById('sheet').classList.add('open'); document.body.style.overflow='hidden'; }
function cerrarSheet(){ document.getElementById('overlay').classList.remove('open'); document.getElementById('sheet').classList.remove('open'); document.body.style.overflow=''; }
function selCat(cat, el){ document.getElementById('catSelected').value=cat; document.querySelectorAll('.cat-btn').forEach(function(b){b.classList.remove('sel')}); el.classList.add('sel'); }
function previewFoto(input){ var f=input.files[0]; if(!f||!f.type.startsWith('image/')) return; var r=new FileReader(); r.onload=function(e){ var img=document.getElementById('fotoPreview'); img.src=e.target.result; img.style.display='block'; }; r.readAsDataURL(f); }
// Abrir sheet si hay error
<?php if (!empty($errMsg)): ?>setTimeout(abrirSheet, 300);<?php endif; ?>
// Swipe down para cerrar
var sh=document.getElementById('sheet'), sy=0;
sh.querySelector('.sheet-handle').addEventListener('touchstart',function(e){sy=e.touches[0].clientY});
sh.querySelector('.sheet-handle').addEventListener('touchend',function(e){if(e.changedTouches[0].clientY-sy>60)cerrarSheet()});
</script>
</body>
</html>
