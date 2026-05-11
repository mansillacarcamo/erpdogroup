<?php
/**
 * Configuración SMTP — DOGroup
 * ==========================================
 * Plantilla. Copia este archivo como `smtp_config.php` y completa los valores.
 * El archivo real `smtp_config.php` está en .gitignore para no exponer credenciales.
 */

$SMTP_CONFIG = [
    'host'       => 'mail.tudominio.cl',
    'port'       => 465,                                // 465 SSL · 587 TLS
    'user'       => 'correo@tudominio.cl',
    'pass'       => 'TU_PASSWORD_AQUI',
    'from_email' => 'correo@tudominio.cl',
    'from_name'  => 'Sistema DoGroup',
];

// URL del sitio en producción (sin barra final)
$SITE_URL = 'https://tudominio.cl';
