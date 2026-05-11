<?php
/**
 * index.php - Punto de entrada del sistema DOGroup
 * Redirige al dashboard o al login segun haya sesion activa.
 * Headers no-cache fuertes para evitar que un Service Worker viejo
 * sirva una version antigua que rediriga a gastos_diarios.
 */

// Headers anti-cache (deben enviarse ANTES de cualquier require)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// Si llega aqui es porque el Service Worker no intercepto (correcto).
// Cargar config solo si esta disponible para verificar sesion.
$tieneSesion = false;
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    $tieneSesion = !empty($_SESSION['usuario']);
}

if ($tieneSesion) {
    header('Location: inicio.php');
} else {
    header('Location: login.php');
}
exit;
