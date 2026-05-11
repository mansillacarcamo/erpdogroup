<?php
require_once 'config.php';
if (!empty($_SESSION['usuario'])) { header('Location: inicio.php'); exit; }
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ?");
  $stmt->execute([trim($_POST['usuario'] ?? '')]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($row && password_verify($_POST['clave'] ?? '', $row['clave'])) {
    $_SESSION['usuario'] = ['id'=>$row['id'],'usuario'=>$row['usuario'],'nombre'=>$row['nombre'],'ci'=>$row['ci'],'cargo'=>$row['cargo'],'rol'=>$row['rol']];
    header('Location: inicio.php'); exit;
  } else { $error = 'Usuario o contraseña incorrectos'; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>DOGroup - Órdenes de Compra</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="css/styles.css" rel="stylesheet">
</head>
<body class="body-login min-vh-100">
  <div class="login-wrapper">
    <div class="login-panel-left">
      <div class="login-panel-content">
        <div class="login-icons-grid">
          <div class="login-icon-item"><i class="bi bi-file-earmark-text"></i></div>
          <div class="login-icon-item"><i class="bi bi-receipt"></i></div>
          <div class="login-icon-item"><i class="bi bi-cart-check"></i></div>
          <div class="login-icon-item"><i class="bi bi-clipboard-data"></i></div>
          <div class="login-icon-item"><i class="bi bi-truck"></i></div>
          <div class="login-icon-item"><i class="bi bi-calculator"></i></div>
        </div>
        <h2 class="login-panel-titulo">Sistema de Cotización y Órdenes de Compra</h2>
        <p class="login-panel-desc">Gestiona tus órdenes de compra, cotizaciones y clientes en un solo lugar.</p>
        <div class="login-panel-features">
          <div><i class="bi bi-check-circle-fill me-2"></i>Órdenes de compra con formato profesional</div>
          <div><i class="bi bi-check-circle-fill me-2"></i>Cotizaciones con cálculo automático</div>
          <div><i class="bi bi-check-circle-fill me-2"></i>Base de datos de clientes</div>
          <div><i class="bi bi-check-circle-fill me-2"></i>Impresión y PDF listo para enviar</div>
        </div>
      </div>
    </div>
    <div class="login-panel-right">
      <div class="login-form-container">
        <div class="text-center mb-4">
          <img src="img/logo.png" alt="DOGroup" class="login-logo-img">
        </div>
        <div class="card shadow-lg border-0 card-login">
          <div class="card-body p-4">
            <?php if ($error): ?>
            <div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="POST">
              <div class="mb-3">
                <label class="form-label fw-semibold">Usuario</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-person"></i></span>
                  <input type="text" name="usuario" class="form-control" placeholder="Ingrese usuario" required autofocus>
                </div>
              </div>
              <div class="mb-4">
                <label class="form-label fw-semibold">Contraseña</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-lock"></i></span>
                  <input type="password" name="clave" class="form-control" placeholder="Ingrese contraseña" required>
                </div>
              </div>
              <button type="submit" class="btn btn-danger w-100 btn-lg"><i class="bi bi-box-arrow-in-right me-2"></i>Ingresar</button>
            </form>
          </div>
        </div>
        <!-- Accesos externos -->
        <div class="mt-4 pt-3 border-top">
          <p class="text-center fw-semibold mb-3" style="font-size:.78rem;letter-spacing:.8px;text-transform:uppercase;color:#6c757d;">
            <i class="bi bi-box-arrow-in-right me-1"></i>Portales de acceso externo
          </p>
          <div class="d-flex gap-2">
            <a href="despacho_conductor_login.php" class="btn w-100 fw-bold"
              style="background:linear-gradient(135deg,#1b2838,#374151);color:#fff;border:none;padding:.65rem .5rem;border-radius:10px;font-size:.88rem;box-shadow:0 3px 10px rgba(27,40,56,.3);">
              <i class="bi bi-truck-front-fill d-block mb-1" style="font-size:1.4rem;"></i>
              Portal Conductores
            </a>
            <a href="despacho_externo_login.php" class="btn w-100 fw-bold"
              style="background:linear-gradient(135deg,#198754,#20c997);color:#fff;border:none;padding:.65rem .5rem;border-radius:10px;font-size:.88rem;box-shadow:0 3px 10px rgba(25,135,84,.3);">
              <i class="bi bi-shield-check d-block mb-1" style="font-size:1.4rem;"></i>
              Portal Carchek
            </a>
          </div>
        </div>

        <p class="text-center mt-3 text-muted mb-1"><small>DOGROUP &copy; <?= date('Y') ?></small></p>
        <p class="text-center text-muted"><small>Todos los derechos reservados &middot; Desarrollado por <strong>César Mansilla</strong> / <strong>Bynari SpA</strong> / <a href="https://www.bynari.cl" target="_blank" rel="noopener" class="text-decoration-none">www.bynari.cl</a></small></p>
      </div>
    </div>
  </div>
</body>
</html>
