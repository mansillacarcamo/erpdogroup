<?php
require_once 'config.php';
while (ob_get_level() > 0) { @ob_end_clean(); }
if (!empty($_SESSION['user_id'])) {
    $rol = $_SESSION['user_rol'] ?? '';
    if ($rol === 'admin')          header('Location: admin_usuarios.php');
    elseif ($rol === 'jefe')       header('Location: jefe_dashboard.php');
    elseif ($rol === 'validador')  header('Location: validador_dashboard.php');
    else                           header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
