<?php
require_once 'config.php';

// Vaciar variables de sesion
$_SESSION = [];

// Borrar cookie de sesion del navegador
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

// Destruir sesion
@session_destroy();

// Limpiar cualquier output previo y redirigir
while (ob_get_level() > 0) { @ob_end_clean(); }
header('Location: login.php');
exit;
