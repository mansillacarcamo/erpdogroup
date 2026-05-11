<?php
require_once 'config.php';
requireAuth();

$uid = (int)$_SESSION['user_id'];
$id = (int)($_GET['id'] ?? 0);
$rol = $_SESSION['user_rol'] ?? '';

$q = $pdo->prepare("SELECT g.*, u.nombre as usuario_nombre, u.email as usuario_email,
                           u.usuario as usuario_login, u.ciudad as usuario_ciudad,
                           u.region as usuario_region, u.zona as usuario_zona, u.cargo as usuario_cargo
                    FROM gastos g JOIN usuarios u ON u.id = g.usuario_id WHERE g.id=?");
$q->execute([$id]);
$g = $q->fetch();

if (!$g) { flash('error','Gasto no encontrado.'); header('Location: dashboard.php'); exit; }

if ($g['usuario_id'] != $uid && !in_array($rol, ['admin','jefe','validador'])) {
    http_response_code(403); die('Sin permiso.');
}

$ar = $pdo->prepare("SELECT * FROM archivos_gasto WHERE gasto_id=? ORDER BY id");
$ar->execute([$id]);
$archivos = $ar->fetchAll();

$puedeEditar = ($g['usuario_id'] == $uid && $g['estado'] === 'registrado');

// El jefe zonal del tecnico (o admin) puede aprobar/rechazar el gasto
$puedeRevisar = false;   // aprobar/rechazar/observar (solo si esta 'registrado')
$puedeObservar = false;  // solo observar (si ya esta aprobado/rechazado)
if (in_array($rol, ['jefe','admin'])) {
    $esSupervisor = false;
    if ($rol === 'admin') {
        $esSupervisor = true;
    } else {
        $qj = $pdo->prepare("SELECT jefe_zonal_id FROM usuarios WHERE id=?");
        $qj->execute([$g['usuario_id']]);
        $esSupervisor = ((int)$qj->fetchColumn() === $uid);
    }
    if ($esSupervisor) {
        if ($g['estado'] === 'registrado') {
            $puedeRevisar = true;
        } elseif (in_array($g['estado'], ['aprobado_jefe','rechazado_jefe'])) {
            $puedeObservar = true;
        }
    }
}

$titulo = 'Detalle gasto';
include 'includes/head.php';
include 'includes/nav.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-lg-8">
    <a href="javascript:history.back()" class="text-decoration-none small mb-2 d-inline-block"><i class="bi bi-arrow-left"></i> Volver</a>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt me-1"></i>Detalle del gasto</span>
        <span class="estado <?= h($g['estado']) ?>"><?= h($g['estado']) ?></span>
      </div>
      <div class="card-body">
        <div class="tecnico-info mb-3 p-3 bg-light rounded">
          <div class="d-flex align-items-center gap-2 mb-1">
            <div class="tecnico-avatar-sm"><?= strtoupper(mb_substr($g['usuario_nombre'],0,1)) ?></div>
            <div>
              <div class="fw-bold"><?= h($g['usuario_nombre']) ?></div>
              <div class="small text-muted">@<?= h($g['usuario_login']) ?><?php if ($g['usuario_cargo']): ?> - <?= h($g['usuario_cargo']) ?><?php endif; ?></div>
            </div>
          </div>
          <div class="d-flex flex-wrap gap-1">
            <?php if ($g['usuario_region']): ?><span class="badge bg-primary-subtle text-primary"><i class="bi bi-geo-alt-fill"></i> <?= h($g['usuario_region']) ?></span><?php endif; ?>
            <?php if ($g['usuario_ciudad']): ?><span class="badge bg-info-subtle text-info-emphasis"><i class="bi bi-geo-alt"></i> <?= h($g['usuario_ciudad']) ?></span><?php endif; ?>
            <?php if ($g['usuario_zona']): ?><span class="badge bg-light text-dark border"><?= h($g['usuario_zona']) ?></span><?php endif; ?>
          </div>
        </div>

        <div class="text-center mb-3">
          <div class="text-muted small">Monto</div>
          <div class="display-5 fw-bold text-danger text-nowrap"><?= fmtCLP($g['monto']) ?></div>
        </div>

        <?php
          // Resumen de estado de revision del jefe
          if ($g['estado'] === 'aprobado_jefe') {
              $revAlert='success'; $revIco='bi-check-circle-fill'; $revTxt='Aprobado por el Jefe Zonal';
          } elseif ($g['estado'] === 'rechazado_jefe') {
              $revAlert='danger';  $revIco='bi-x-circle-fill';     $revTxt='Rechazado por el Jefe Zonal';
          } elseif (!empty($g['observacion_revision'])) {
              $revAlert='warning'; $revIco='bi-chat-left-text-fill'; $revTxt='Con observacion del Jefe Zonal';
          } else {
              $revAlert='secondary'; $revIco='bi-hourglass-split'; $revTxt='Pendiente de revision del Jefe Zonal';
          }
        ?>
        <div class="alert alert-<?= $revAlert ?> d-flex align-items-start gap-2 mb-3 py-2 px-3">
          <i class="bi <?= $revIco ?> fs-4"></i>
          <div class="flex-grow-1">
            <div class="fw-semibold"><?= $revTxt ?></div>
            <?php if (!empty($g['observacion_revision'])): ?>
              <div class="small mt-1"><strong>Observacion:</strong> <?= nl2br(h($g['observacion_revision'])) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <style>
          .tecnico-avatar-sm{ width:38px; height:38px; border-radius:50%; background:linear-gradient(135deg,#0d6efd,#6610f2); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; }
        </style>

        <dl class="row small">
          <dt class="col-5">Fecha</dt>  <dd class="col-7"><?= date('d/m/Y', strtotime($g['fecha'])) ?></dd>
          <dt class="col-5">Categoria</dt><dd class="col-7"><?= h($g['categoria'] ?: '-') ?></dd>
          <dt class="col-5">Proveedor</dt><dd class="col-7"><?= h($g['proveedor'] ?: '-') ?></dd>
          <dt class="col-5">Tipo doc.</dt><dd class="col-7"><?= h($g['tipo_documento'] ?: '-') ?></dd>
          <dt class="col-5">N. doc.</dt>  <dd class="col-7"><?= h($g['numero_documento'] ?: '-') ?></dd>
          <dt class="col-5">Descripcion</dt><dd class="col-7"><?= nl2br(h($g['descripcion'] ?: '-')) ?></dd>
          <?php if ($g['observacion_revision']): ?>
            <dt class="col-5">Observacion</dt><dd class="col-7"><?= nl2br(h($g['observacion_revision'])) ?></dd>
          <?php endif; ?>
        </dl>

        <?php if ($archivos): ?>
          <hr>
          <h6 class="fw-semibold"><i class="bi bi-paperclip me-1"></i>Adjuntos (<?= count($archivos) ?>)</h6>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($archivos as $a):
              $url = 'uploads/' . $a['nombre_archivo'];
              $isImg = preg_match('/\.(jpg|jpeg|png|webp|heic|heif)$/i', $a['nombre_archivo']);
            ?>
              <a href="<?= h($url) ?>" target="_blank" class="text-decoration-none">
                <?php if ($isImg): ?>
                  <img src="<?= h($url) ?>" class="thumb" style="width:120px;height:120px;">
                <?php else: ?>
                  <div class="thumb d-flex align-items-center justify-content-center bg-light text-secondary" style="width:120px;height:120px;">
                    <i class="bi bi-file-earmark-pdf" style="font-size:2.5rem;"></i>
                  </div>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($puedeEditar): ?>
          <hr>
          <div class="d-flex gap-2">
            <a href="gasto_editar.php?id=<?= (int)$g['id'] ?>" class="btn btn-warning flex-grow-1">
              <i class="bi bi-pencil-square me-1"></i> Editar gasto
            </a>
            <a href="gasto_eliminar.php?id=<?= (int)$g['id'] ?>"
               data-confirm="Eliminar este gasto?"
               class="btn btn-outline-danger">
              <i class="bi bi-trash"></i>
            </a>
          </div>
        <?php endif; ?>

        <?php if ($puedeRevisar): ?>
          <hr>
          <div class="alert alert-info small mb-3 py-2">
            <i class="bi bi-info-circle"></i> Como Jefe Zonal puedes <b>aprobar</b>, <b>observar</b> o <b>rechazar</b> este gasto. El tecnico sera notificado.
          </div>
          <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-success flex-grow-1" data-bs-toggle="modal" data-bs-target="#mRevGV" data-acc="aprobar">
              <i class="bi bi-check-circle me-1"></i> Aprobar
            </button>
            <button class="btn btn-info text-white flex-grow-1" data-bs-toggle="modal" data-bs-target="#mRevGV" data-acc="observar">
              <i class="bi bi-chat-left-text me-1"></i> Observar
            </button>
            <button class="btn btn-danger flex-grow-1" data-bs-toggle="modal" data-bs-target="#mRevGV" data-acc="rechazar">
              <i class="bi bi-x-circle me-1"></i> Rechazar
            </button>
          </div>

          <div class="modal fade" id="mRevGV" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <form method="post" action="gasto_revisar.php" id="fRevGV">
                  <input type="hidden" name="accion" id="revAccGV" value="aprobar">
                  <input type="hidden" name="gid" value="<?= (int)$g['id'] ?>">
                  <input type="hidden" name="volver" value="gasto_ver.php?id=<?= (int)$g['id'] ?>">
                  <div class="modal-header" id="revHdrGV">
                    <h5 class="modal-title" id="revTitleGV"><i class="bi bi-clipboard-check me-1"></i>Revisar gasto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <label class="form-label fw-semibold" id="revLblGV">Observacion (opcional)</label>
                    <textarea name="obs" id="revObsGV" class="form-control" rows="3" placeholder="Observacion opcional..."></textarea>
                    <div class="form-text" id="revHelpGV">El tecnico recibira una notificacion.</div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-success" id="revBtnGV"><i class="bi bi-check-circle me-1"></i>Aprobar gasto</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
          <script>
          (function(){
            var map = {
              aprobar:  {title:'Aprobar gasto',  hdr:'bg-success text-white',  close:'btn-close-white', btn:'btn btn-success',       btnHTML:'<i class="bi bi-check-circle me-1"></i>Aprobar gasto',   lbl:'Observacion (opcional)', req:false, ph:'Puedes aprobar sin comentar o con una observacion.', help:'La observacion (si la escribes) sera enviada al tecnico.'},
              observar: {title:'Dejar observacion', hdr:'bg-info text-white',  close:'btn-close-white', btn:'btn btn-info text-white', btnHTML:'<i class="bi bi-send me-1"></i>Enviar observacion',      lbl:'Observacion *',         req:true,  ph:'Escribe la observacion para el tecnico...',        help:'El estado del gasto NO se modifica.'},
              rechazar: {title:'Rechazar gasto', hdr:'bg-danger text-white',   close:'btn-close-white', btn:'btn btn-danger',         btnHTML:'<i class="bi bi-x-circle me-1"></i>Rechazar gasto',       lbl:'Motivo del rechazo *',  req:true,  ph:'Ej: falta boleta, monto no corresponde...',        help:'El tecnico recibira el motivo del rechazo.'}
            };
            var hdr = document.getElementById('revHdrGV');
            var closeBtn = hdr.querySelector('.btn-close');
            document.getElementById('mRevGV').addEventListener('show.bs.modal', function(ev){
              var trigger = ev.relatedTarget;
              var acc = trigger ? trigger.getAttribute('data-acc') : 'aprobar';
              var c = map[acc] || map.aprobar;
              document.getElementById('revAccGV').value = acc;
              hdr.className = 'modal-header ' + c.hdr;
              closeBtn.className = 'btn-close ' + c.close;
              document.getElementById('revTitleGV').innerHTML = '<i class="bi bi-clipboard-check me-1"></i>' + c.title;
              document.getElementById('revLblGV').textContent = c.lbl;
              var obs = document.getElementById('revObsGV');
              obs.required = c.req; obs.placeholder = c.ph; obs.value = '';
              document.getElementById('revHelpGV').textContent = c.help;
              var b = document.getElementById('revBtnGV');
              b.className = c.btn; b.innerHTML = c.btnHTML;
            });
          })();
          </script>
        <?php elseif ($puedeObservar): ?>
          <hr>
          <div class="alert alert-info small mb-3 py-2">
            <i class="bi bi-info-circle"></i> Este gasto ya fue revisado. Aun asi puedes dejar una <b>observacion</b> adicional para el tecnico.
          </div>
          <button class="btn btn-outline-info w-100" data-bs-toggle="modal" data-bs-target="#mObsGV">
            <i class="bi bi-chat-left-text me-1"></i> Dejar observacion
          </button>

          <div class="modal fade" id="mObsGV" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <form method="post" action="gasto_revisar.php">
                  <input type="hidden" name="accion" value="observar">
                  <input type="hidden" name="gid" value="<?= (int)$g['id'] ?>">
                  <input type="hidden" name="volver" value="gasto_ver.php?id=<?= (int)$g['id'] ?>">
                  <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="bi bi-chat-left-text me-1"></i>Dejar observacion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                  </div>
                  <div class="modal-body">
                    <label class="form-label fw-semibold">Observacion *</label>
                    <textarea name="obs" class="form-control" rows="3" required placeholder="Escribe la observacion..."></textarea>
                    <div class="form-text">El tecnico recibira una notificacion. El estado del gasto no cambia.</div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-info text-white"><i class="bi bi-send me-1"></i>Enviar observacion</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include 'includes/foot.php'; ?>
