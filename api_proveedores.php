<?php
require_once 'config.php';
requireAuth();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'listar') {
  $proveedores = $pdo->query(
    "SELECT id, nombre, rut, direccion, ciudad, telefono, email, contacto
     FROM proveedores
     ORDER BY nombre ASC"
  )->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($proveedores);
  exit;
}

if ($action === 'obtener' && isset($_GET['id'])) {
  $stmt = $pdo->prepare("SELECT * FROM proveedores WHERE id = ?");
  $stmt->execute([(int)$_GET['id']]);
  $proveedor = $stmt->fetch(PDO::FETCH_ASSOC);
  echo json_encode($proveedor ?: []);
  exit;
}

echo json_encode([]);
