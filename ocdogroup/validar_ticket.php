<?php
require_once 'config.php';

/* Permite acceso a usuarios externos del portal sin requireAuth */
$esExterno   = isExternoLoggedIn();
$extSession  = getExternoSession();

if ($esExterno) {
  /* Redirigir al portal externo que tiene la lógica completa */
  $t = $_GET['t'] ?? '';
  header('Location: despacho_externo_portal.php'.($t ? '?t='.urlencode($t) : ''));
  exit;
}

requireAuth();
requireModulo('despacho', $usuario, $pdo);


$MATERIALES = [
  'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4','bajo_3'=>'Bajo 3',
  'bajo_2'=>'Bajo 2','base_chancada'=>'Base Chancada','grava'=>'Grava','gravilla'=>'Gravilla',
  'arena'=>'Arena','arena_tubo'=>'Arena Tubo','escombros'=>'Escombros','bolones'=>'Bolones',
];

$esAdminDespacho = isDespachoAdmin($usuario, $pdo);
$esReceptor = false;
$stR = $pdo->prepare("SELECT COUNT(*) FROM despacho_receptores WHERE usuario_id=?");
$stR->execute([$usuario['id']]);
$esReceptor = (int)$stR->fetchColumn() > 0;

if (!$esAdminDespacho && !$esReceptor) {
  header('Location: tickets_despacho.php?error=sin_permiso'); exit;
}

$token = trim($_GET['t'] ?? '');
$ticket = null;
$error  = null;

if ($token) {
  $st = $pdo->prepare("SELECT * FROM tickets_despacho WHERE token=?");
  $st->execute([$token]);
  $ticket = $st->fetch(PDO::FETCH_ASSOC);
  if (!$ticket) $error = 'Token inválido o ticket no encontrado.';
  elseif ($ticket['estado'] === 'borrador') $error = 'Este ticket aún no ha sido enviado.';
  elseif ($ticket['estado'] === 'validado') $error = null; // mostrar como ya validado, no es error
}

/* ── POST: Validar ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validar'])) {
  $tok = trim($_POST['token'] ?? '');
  if (!$tok) { $error = 'Token inválido.'; }
  else {
    $stUp = $pdo->prepare("UPDATE tickets_despacho SET estado='validado', validado_en=CURRENT_TIMESTAMP, validado_por=?, validado_por_nombre=? WHERE token=? AND estado='enviado'");
    $stUp->execute([$usuario['id'], $usuario['nombre'], $tok]);
    if ($stUp->rowCount() > 0) {
      header("Location: validar_ticket.php?t=".urlencode($tok)."&ok=1"); exit;
    } else {
      $error = 'No se pudo validar el ticket (ya estaba validado o no existe).';
    }
  }
}

$ok = isset($_GET['ok']);

require_once 'includes/header.php';
?>

<style>
.validar-wrap { max-width: 640px; margin: 0 auto; }
.scanner-box { border: 3px dashed #6c757d; border-radius: 12px; padding: 1.5rem; background: #f8f9fa; }
#reader { width: 100%; border-radius: 8px; overflow: hidden; }
.ticket-info-row { display: flex; gap: .5rem; align-items: center; padding: .4rem 0; border-bottom: 1px solid #f0f0f0; }
.ticket-info-row .lbl { width: 40%; font-size: .85rem; color: #666; font-weight: 600; }
.ticket-info-row .val { font-size: .95rem; }
</style>

<div class="validar-wrap">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="fw-bold mb-0"><i class="bi bi-qr-code-scan text-success me-2"></i>Validar Ticket de Despacho</h4>
    <a href="tickets_despacho.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver</a>
  </div>

  <?php if ($ok && $ticket): ?>
  <!-- VALIDADO OK -->
  <div class="card border-success shadow-sm">
    <div class="card-body text-center py-5">
      <i class="bi bi-check-circle-fill text-success" style="font-size:4rem;"></i>
      <h3 class="mt-3 fw-bold text-success">¡Ticket Validado!</h3>
      <p class="text-muted">El ticket <strong>#<?= str_pad($ticket['id'],5,'0',STR_PAD_LEFT) ?></strong> fue validado exitosamente.</p>
      <div class="mt-4 d-flex gap-2 justify-content-center">
        <a href="tickets_despacho_ver.php?id=<?= $ticket['id'] ?>" class="btn btn-outline-success"><i class="bi bi-eye me-1"></i>Ver ticket</a>
        <a href="tickets_despacho.php" class="btn btn-secondary">Ir al módulo</a>
      </div>
    </div>
  </div>

  <?php elseif (!$token): ?>
  <!-- SIN TOKEN: mostrar escáner QR -->
  <div class="scanner-box mb-3">
    <p class="text-center text-muted mb-3"><i class="bi bi-camera me-1"></i>Escanea el QR del ticket con tu cámara</p>
    <div id="reader"></div>
    <div id="scanResult" class="mt-2 text-center text-muted small"></div>
  </div>
  <hr>
  <p class="text-center text-muted small">O ingresa el enlace del QR manualmente:</p>
  <form action="" method="GET" class="d-flex gap-2">
    <input type="text" name="t" class="form-control" placeholder="Token del ticket...">
    <button class="btn btn-secondary">Buscar</button>
  </form>

  <?php elseif ($error): ?>
  <div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i><?= htmlspecialchars($error) ?></div>
  <a href="tickets_despacho.php" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Volver</a>

  <?php elseif ($ticket && $ticket['estado'] === 'validado'): ?>
  <!-- YA VALIDADO -->
  <div class="card border-success shadow-sm mb-3">
    <div class="card-header bg-success text-white"><i class="bi bi-check-circle-fill me-1"></i>Ticket ya validado</div>
    <div class="card-body">
      <div class="ticket-info-row"><div class="lbl">Ticket</div><div class="val">#<?= str_pad($ticket['id'],5,'0',STR_PAD_LEFT) ?></div></div>
      <div class="ticket-info-row"><div class="lbl">PPU</div><div class="val"><strong><?= htmlspecialchars($ticket['ppu']) ?></strong></div></div>
      <div class="ticket-info-row"><div class="lbl">Material</div><div class="val"><?= htmlspecialchars($MATERIALES[$ticket['tipo_material']] ?? $ticket['tipo_material']) ?></div></div>
      <div class="ticket-info-row"><div class="lbl">Validado por</div><div class="val"><?= htmlspecialchars($ticket['validado_por_nombre']) ?></div></div>
      <div class="ticket-info-row"><div class="lbl">Fecha validación</div><div class="val"><?= htmlspecialchars($ticket['validado_en']) ?></div></div>
    </div>
  </div>
  <a href="tickets_despacho_ver.php?id=<?= $ticket['id'] ?>" class="btn btn-outline-secondary"><i class="bi bi-eye me-1"></i>Ver ticket completo</a>

  <?php elseif ($ticket): ?>
  <!-- FORMULARIO DE VALIDACIÓN -->
  <div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-warning text-dark fw-bold"><i class="bi bi-exclamation-circle me-1"></i>Ticket pendiente de validación — #<?= str_pad($ticket['id'],5,'0',STR_PAD_LEFT) ?></div>
    <div class="card-body">
      <div class="ticket-info-row"><div class="lbl">Fecha / Hora</div><div class="val"><strong><?= htmlspecialchars($ticket['fecha']) ?></strong> <?= htmlspecialchars($ticket['hora']) ?></div></div>
      <div class="ticket-info-row"><div class="lbl">Obra</div><div class="val"><?= htmlspecialchars($ticket['obra_nombre'] ?: '—') ?></div></div>
      <div class="ticket-info-row"><div class="lbl">PPU (Camión)</div><div class="val"><strong class="fs-5"><?= htmlspecialchars($ticket['ppu'] ?: '—') ?></strong><?= $ticket['metros_cubicos'] > 0 ? ' <span class="badge bg-info text-dark ms-1">'.number_format($ticket['metros_cubicos'],1,',','.').' m³</span>' : '' ?></div></div>
      <div class="ticket-info-row"><div class="lbl">Destino</div><div class="val"><?= htmlspecialchars($ticket['destino'] ?: '—') ?></div></div>
      <div class="ticket-info-row"><div class="lbl">Material</div><div class="val"><span class="badge bg-dark fs-6"><?= htmlspecialchars($MATERIALES[$ticket['tipo_material']] ?? $ticket['tipo_material']) ?></span></div></div>
      <div class="ticket-info-row"><div class="lbl">Creado por</div><div class="val"><?= htmlspecialchars($ticket['creado_por_nombre']) ?></div></div>
      <?php if ($ticket['observaciones']): ?>
      <div class="ticket-info-row"><div class="lbl">Observaciones</div><div class="val"><?= htmlspecialchars($ticket['observaciones']) ?></div></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow border-success">
    <div class="card-header bg-success text-white fw-bold"><i class="bi bi-check-circle me-1"></i>Confirmar Validación</div>
    <form method="POST">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <div class="card-body">
        <p class="text-muted mb-0">¿Confirmas que has revisado el ticket y deseas validarlo?</p>
      </div>
      <div class="card-footer d-flex justify-content-end gap-2">
        <a href="tickets_despacho.php" class="btn btn-secondary">Cancelar</a>
        <button type="submit" name="validar" class="btn btn-success btn-lg px-4">
          <i class="bi bi-check-circle-fill me-2"></i>Confirmar Validación
        </button>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if (!$token): ?>
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const html5QrCode = new Html5Qrcode("reader");
html5QrCode.start(
  { facingMode: "environment" },
  { fps: 10, qrbox: { width: 250, height: 250 } },
  function(decodedText) {
    document.getElementById('scanResult').innerHTML = '<i class="bi bi-check-circle text-success me-1"></i>QR detectado, redirigiendo...';
    html5QrCode.stop().then(() => {
      if (decodedText.includes('validar_ticket.php')) {
        window.location.href = decodedText;
      } else {
        const url = new URL(decodedText);
        const t = url.searchParams.get('t');
        if (t) window.location.href = 'validar_ticket.php?t=' + encodeURIComponent(t);
        else document.getElementById('scanResult').innerHTML = '<span class="text-danger">QR no válido para este sistema.</span>';
      }
    });
  },
  function(err) { }
).catch(err => {
  document.getElementById('reader').innerHTML = '<div class="alert alert-warning"><i class="bi bi-camera-video-off me-1"></i>No se pudo acceder a la cámara. Ingresa el token manualmente.</div>';
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
