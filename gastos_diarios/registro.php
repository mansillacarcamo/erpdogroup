<?php
require_once 'config.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre  = trim($_POST['nombre'] ?? '');
    $usuario = strtolower(trim($_POST['usuario'] ?? ''));
    $email   = trim($_POST['email'] ?? '');
    $rut     = trim($_POST['rut'] ?? '');
    $tel     = trim($_POST['telefono'] ?? '');
    $cargo   = trim($_POST['cargo'] ?? '');
    $zona    = trim($_POST['zona'] ?? '');
    $ciudad  = trim($_POST['ciudad'] ?? '');
    $region  = trim($_POST['region'] ?? '');
    $clave   = $_POST['clave'] ?? '';
    $clave2  = $_POST['clave2'] ?? '';

    if (!$nombre || !$usuario || !$email || !$clave) $err = 'Completa los campos obligatorios.';
    elseif (!$region || !$ciudad) $err = 'Debes indicar tu Region y Ciudad.';
    elseif (!preg_match('/^[a-z0-9._]{3,30}$/', $usuario)) $err = 'Usuario: solo minusculas, numeros, punto o guion bajo (3-30).';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Correo inválido.';
    elseif (strlen($clave) < 6) $err = 'La contraseña debe tener al menos 6 caracteres.';
    elseif ($clave !== $clave2) $err = 'Las contraseñas no coinciden.';
    else {
        $q = $pdo->prepare("SELECT id FROM usuarios WHERE usuario=? OR email=?");
        $q->execute([$usuario,$email]);
        if ($q->fetch()) $err = 'Ya existe una cuenta con ese usuario o correo.';
        else {
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombre,usuario,email,clave,rol,rut,cargo,zona,ciudad,region,telefono) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$nombre,$usuario,$email,password_hash($clave,PASSWORD_DEFAULT),'usuario',$rut,$cargo,$zona,$ciudad,$region,$tel]);
            $_SESSION['user_id']      = (int)$pdo->lastInsertId();
            $_SESSION['user_nombre']  = $nombre;
            $_SESSION['user_usuario'] = $usuario;
            $_SESSION['user_rol']     = 'usuario';
            $_SESSION['user_email']   = $email;
            flash('exito','¡Bienvenido a Control de Gastos Diarios!');
            header('Location: dashboard.php'); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#0d6efd">
<title>Crear cuenta · Control de Gastos Diarios</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="css/app.css" rel="stylesheet">
</head>
<body>
<div class="login-bg">
  <div class="login-card" style="max-width:520px;">
    <div class="text-center mb-3">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary text-white mb-2"
           style="width:60px;height:60px;font-size:1.6rem;">
        <i class="bi bi-person-plus"></i>
      </div>
      <h4 class="fw-bold mb-0">Crear cuenta</h4>
      <p class="text-muted small">Solicita acceso al sistema</p>
    </div>

    <?php if ($err): ?>
      <div class="alert alert-danger small py-2"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="row g-2">
        <div class="col-12">
          <label class="form-label small fw-semibold">Nombre completo *</label>
          <input type="text" name="nombre" class="form-control" required value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small fw-semibold">Usuario *</label>
          <input type="text" name="usuario" class="form-control" required
                 autocapitalize="none" autocorrect="off" spellcheck="false"
                 pattern="[a-z0-9._]{3,30}" placeholder="ej. jperez"
                 value="<?= htmlspecialchars($_POST['usuario'] ?? '') ?>">
          <div class="form-text small">Minúsculas, números, punto o guión bajo</div>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small fw-semibold">Correo *</label>
          <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small fw-semibold">Teléfono</label>
          <input type="tel" name="telefono" class="form-control" value="<?= htmlspecialchars($_POST['telefono'] ?? '') ?>">
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">RUT</label>
          <input type="text" name="rut" class="form-control" value="<?= htmlspecialchars($_POST['rut'] ?? '') ?>">
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Cargo</label>
          <input type="text" name="cargo" class="form-control" value="<?= htmlspecialchars($_POST['cargo'] ?? '') ?>">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small fw-semibold">Region *</label>
          <select name="region" class="form-select" required>
            <option value="">- Seleccionar region -</option>
            <?php foreach (regionesChile() as $r): ?>
              <option value="<?= htmlspecialchars($r) ?>" <?= (($_POST['region']??'')===$r?'selected':'') ?>><?= htmlspecialchars($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label small fw-semibold">Ciudad *</label>
          <input type="text" name="ciudad" class="form-control" required
                 placeholder="Ej. Talca, Concepcion, Temuco..."
                 value="<?= htmlspecialchars($_POST['ciudad'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold">Zona / Sector <span class="text-muted">(opcional)</span></label>
          <input type="text" name="zona" class="form-control"
                 placeholder="Ej. Norte, Sur, Centro..."
                 value="<?= htmlspecialchars($_POST['zona'] ?? '') ?>">
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Contraseña *</label>
          <input type="password" name="clave" class="form-control" required minlength="6">
        </div>
        <div class="col-6">
          <label class="form-label small fw-semibold">Repetir *</label>
          <input type="password" name="clave2" class="form-control" required minlength="6">
        </div>
      </div>

      <button class="btn btn-primary btn-lg w-100 mt-3">
        <i class="bi bi-check-circle me-1"></i> Crear mi cuenta
      </button>
    </form>

    <hr class="my-4">
    <div class="text-center small">¿Ya tienes cuenta? <a href="login.php" class="fw-semibold text-decoration-none">Inicia sesión</a></div>
  </div>
</div>
</body>
</html>
