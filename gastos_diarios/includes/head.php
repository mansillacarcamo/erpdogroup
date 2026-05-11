<?php
$titulo = $titulo ?? 'Control de Gastos Diarios';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0d6efd">
<title><?= htmlspecialchars($titulo) ?> · Control de Gastos Diarios</title>
<link rel="manifest" href="manifest.json">
<link rel="apple-touch-icon" href="img/icon-192.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="css/app.css?v=<?= APP_VER ?>" rel="stylesheet">
</head>
<body class="bg-light">
