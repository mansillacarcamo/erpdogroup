<?php
require_once 'config.php';
requireAuth();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'listar') {
  $clientes = $pdo->query("SELECT * FROM clientes ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode($clientes);
  exit;
}

if ($action === 'obtener' && isset($_GET['id'])) {
  $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
  $stmt->execute([(int)$_GET['id']]);
  $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
  echo json_encode($cliente ?: []);
  exit;
}

echo json_encode([]);
