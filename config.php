<?php
/**
 * ============================================================
 *  config.php — DOGroup
 *
 *  Este archivo SOLO contiene:
 *    1. Configuración de entorno (timezone, errores)
 *    2. Conexión a la BD
 *    3. Ejecución del runner de migraciones
 *    4. Funciones de la aplicación
 *
 *  ⚠️  REGLA FUNDAMENTAL:
 *  NUNCA escribas CREATE TABLE, ALTER TABLE ni cambios
 *  a la BD en este archivo. Todo cambio a la BD va en
 *  un archivo nuevo dentro de /migrations/
 * ============================================================
 */

// ── Zona horaria Chile ──────────────────────────────────────
date_default_timezone_set('America/Santiago');
// Importante: putenv afecta a SQLite, que usa la TZ del proceso
// para resolver datetime('now','localtime'). Sin esto, en hostings
// con SO en UTC las fechas quedan desfasadas en 3-4 horas.
putenv('TZ=America/Santiago');

// ── Manejo de errores (OFF en producción) ──────────────────
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ── Sesión ──────────────────────────────────────────────────
session_start();

// ── Conexión a la BD ────────────────────────────────────────
$pdo = new PDO('sqlite:' . __DIR__ . '/ocdogroup.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("PRAGMA journal_mode=WAL");   // Mejor concurrencia
$pdo->exec("PRAGMA foreign_keys=ON");    // Integridad referencial
$pdo->exec("PRAGMA busy_timeout=5000");  // Esperar 5s si la BD está bloqueada (concurrencia)
$pdo->exec("PRAGMA synchronous=NORMAL"); // Suficiente con WAL, mejora throughput

// ── Carpeta de uploads ──────────────────────────────────────
$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);

// ── Migraciones ─────────────────────────────────────────────
// Ejecuta solo las migraciones nuevas. Nunca repite una ya aplicada.
require_once __DIR__ . '/migrate.php';
runMigrations($pdo);

// ── Usuario activo ──────────────────────────────────────────
$usuario = $_SESSION['usuario'] ?? null;

// ════════════════════════════════════════════════════════════
//  FUNCIONES DE LA APLICACIÓN
// ════════════════════════════════════════════════════════════

function requireAuth() {
  if (empty($_SESSION['usuario'])) { header('Location: login.php'); exit; }
}

function requireAdmin() {
  if (empty($_SESSION['usuario']) || $_SESSION['usuario']['rol'] !== 'admin') { header('Location: index.php'); exit; }
}

function siguienteNumeroOC($pdo) {
  $stmt = $pdo->query("SELECT MAX(numero) FROM ordenes_compra");
  $max = (int)$stmt->fetchColumn();
  return $max > 0 ? $max + 1 : 4174;
}

function siguienteNumeroCot($pdo) {
  $stmt = $pdo->query("SELECT MAX(numero) FROM cotizaciones");
  $max = (int)$stmt->fetchColumn();
  return $max > 0 ? $max + 1 : 1149;
}

function formatCLP($v, $dec = 0) {
  return number_format((float)$v, (int)$dec, ',', '.');
}

function decimalesMoneda($moneda) {
  switch (strtoupper((string)($moneda ?: 'CLP'))) {
    case 'UF':  return 4;
    case 'USD':
    case 'EUR': return 2;
    case 'CLP':
    default:    return 0;
  }
}

function formatMoneda($v, $moneda = 'CLP') {
  return number_format((float)$v, decimalesMoneda($moneda), ',', '.');
}

function monedasDisponibles() {
  return [
    'CLP' => 'Peso Chileno (CLP)',
    'USD' => 'Dólar (USD)',
    'EUR' => 'Euro (EUR)',
    'UF'  => 'UF',
  ];
}

function monedaValida($m) {
  $codigos = array_keys(monedasDisponibles());
  $m = strtoupper((string)$m);
  return in_array($m, $codigos, true) ? $m : 'CLP';
}

function simboloMoneda($m) {
  switch (strtoupper((string)($m ?: 'CLP'))) {
    case 'EUR': return '€';
    case 'UF':  return 'UF ';
    case 'USD': return 'US$';
    case 'CLP':
    default:    return '$';
  }
}

function modulosDisponibles() {
  return [
    'oc'            => ['label' => 'Órdenes de Compra',     'icono' => 'bi-file-earmark-ruled',  'color' => 'danger'],
    'cot'           => ['label' => 'Cotizaciones',           'icono' => 'bi-receipt',             'color' => 'primary'],
    'contactos'     => ['label' => 'Clientes y Proveedores', 'icono' => 'bi-person-vcard',        'color' => 'success'],
    'obras'         => ['label' => 'Obras',                  'icono' => 'bi-building',            'color' => 'warning'],
    'estados_pago'  => ['label' => 'Estados de Pago',       'icono' => 'bi-cash-coin',           'color' => 'info'],
    'despacho'      => ['label' => 'Tickets de Despacho',   'icono' => 'bi-truck-front-fill',    'color' => 'secondary'],
    'combustible'   => ['label' => 'Distribución Combustible','icono' => 'bi-fuel-pump-fill',     'color' => 'warning'],
    'admin'         => ['label' => 'Administración',        'icono' => 'bi-shield-lock',         'color' => 'dark'],
  ];
}

function canAccess($modulo, $usuario, $pdo) {
  if (!$usuario) return false;
  if ($usuario['rol'] === 'admin') return true;
  try {
    $stTotal = $pdo->prepare("SELECT COUNT(*) FROM usuario_modulos WHERE usuario_id = ?");
    $stTotal->execute([$usuario['id']]);
    if ((int)$stTotal->fetchColumn() === 0) return true; // sin restricciones
    $stMod = $pdo->prepare("SELECT COUNT(*) FROM usuario_modulos WHERE usuario_id = ? AND modulo = ?");
    $stMod->execute([$usuario['id'], $modulo]);
    return (int)$stMod->fetchColumn() > 0;
  } catch (Exception $e) { return true; }
}

function requireModulo($modulo, $usuario, $pdo) {
  if (!canAccess($modulo, $usuario, $pdo)) {
    header('Location: inicio.php?acceso_denegado=' . urlencode($modulo));
    exit;
  }
}

function isDespachoAdmin($usuario, $pdo) {
  if ($usuario['rol'] === 'admin') return true;
  try {
    $st = $pdo->prepare("SELECT rol FROM despacho_roles WHERE usuario_id = ?");
    $st->execute([$usuario['id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row && $row['rol'] === 'admin_despacho';
  } catch (Exception $e) { return false; }
}

function canAccessDespacho($usuario, $pdo) {
  return canAccess('despacho', $usuario, $pdo);
}

function getExternoSession() {
  return $_SESSION['despacho_externo'] ?? null;
}

function isExternoLoggedIn() {
  return !empty($_SESSION['despacho_externo']);
}

function getConductorSession() {
  return $_SESSION['despacho_conductor'] ?? null;
}

function isConductorLoggedIn() {
  return !empty($_SESSION['despacho_conductor']);
}

// ── Sesión independiente del Responsable de Combustible ──
function getCombResponsableSession() {
  return $_SESSION['comb_responsable'] ?? null;
}

function isCombResponsableLoggedIn() {
  return !empty($_SESSION['comb_responsable']);
}

function requireCombResponsable() {
  if (!isCombResponsableLoggedIn()) {
    header('Location: combustible_responsable_login.php'); exit;
  }
}

// ── Sesión independiente del Jefe de Obra (segunda validación de tickets) ──
function getJefeObraSession() {
  return $_SESSION['jefe_obra'] ?? null;
}

function isJefeObraLoggedIn() {
  return !empty($_SESSION['jefe_obra']);
}

function requireJefeObra() {
  if (!isJefeObraLoggedIn()) {
    header('Location: jefe_obra_login.php'); exit;
  }
}

function requireDespachoAdmin($usuario, $pdo) {
  if (!isDespachoAdmin($usuario, $pdo)) {
    header('Location: tickets_despacho.php?error=sin_permiso');
    exit;
  }
}

function getRolDespacho($usuario, $pdo) {
  if ($usuario['rol'] === 'admin') return 'admin_despacho';
  try {
    $st = $pdo->prepare("SELECT rol FROM despacho_roles WHERE usuario_id = ?");
    $st->execute([$usuario['id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['rol'] : 'usuario';
  } catch (Exception $e) { return 'usuario'; }
}

function parseMonto($v) {
  if (is_null($v)) return 0.0;
  $s = trim((string)$v);
  if ($s === '') return 0.0;
  $s = preg_replace('/[^\d,.\-]/', '', $s);
  // Formato chileno usado en toda la UI: el punto siempre es separador de miles
  // y la coma siempre es separador decimal. Esto coincide con el getNum() de los JS
  // (replace(/\./g,'').replace(',', '.')).
  $s = str_replace('.', '', $s);
  $s = str_replace(',', '.', $s);
  return (float)$s;
}

// Cantidades de ítems: el punto se trata como decimal cuando no hay coma,
// así "1.5" significa 1,5 y no 15. Si hay coma, ésa es la decimal y los puntos
// son separadores de miles. Equivalente a getNumCant() del JS.
function parseCantidad($v) {
  if (is_null($v)) return 0.0;
  $s = trim((string)$v);
  if ($s === '') return 0.0;
  $s = preg_replace('/[^\d,.\-]/', '', $s);
  if (strpos($s, ',') !== false) {
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  }
  return (float)$s;
}

// ════════════════════════════════════════════════════════════
//  MÓDULO COMBUSTIBLE — helpers de permisos
// ════════════════════════════════════════════════════════════

/**
 * ¿El usuario es admin del módulo de combustible?
 *  - admin global → siempre sí
 *  - registrado en combustible_admins → sí
 */
function isCombustibleAdmin($usuario, $pdo) {
  if (!$usuario) return false;
  if (($usuario['rol'] ?? '') === 'admin') return true;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM combustible_admins WHERE usuario_id = ?");
    $st->execute([$usuario['id']]);
    return (int)$st->fetchColumn() > 0;
  } catch (Exception $e) { return false; }
}

/**
 * ¿El usuario es responsable de combustible (puede registrar vales)?
 *  - admin del módulo → sí
 *  - registrado en combustible_responsables (activo=1) → sí
 */
function isCombustibleResponsable($usuario, $pdo) {
  if (!$usuario) return false;
  if (isCombustibleAdmin($usuario, $pdo)) return true;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM combustible_responsables
                         WHERE usuario_id = ? AND activo = 1");
    $st->execute([$usuario['id']]);
    return (int)$st->fetchColumn() > 0;
  } catch (Exception $e) { return false; }
}

/**
 * Devuelve el registro del responsable del usuario actual (o null).
 */
function getResponsableCombustible($usuario, $pdo) {
  if (!$usuario) return null;
  try {
    $st = $pdo->prepare("SELECT * FROM combustible_responsables
                         WHERE usuario_id = ? AND activo = 1 LIMIT 1");
    $st->execute([$usuario['id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  } catch (Exception $e) { return null; }
}

function requireCombustibleAdmin($usuario, $pdo) {
  if (!isCombustibleAdmin($usuario, $pdo)) {
    header('Location: combustible.php?error=sin_permiso');
    exit;
  }
}

function requireCombustibleAcceso($usuario, $pdo) {
  if (!isCombustibleResponsable($usuario, $pdo) && !isCombustibleAdmin($usuario, $pdo)) {
    header('Location: inicio.php?acceso_denegado=combustible');
    exit;
  }
}

function tiposCombustible() {
  return ['diesel' => 'Diesel', 'gasolina' => 'Gasolina'];
}

function tiposFuenteCombustible() {
  return ['camion' => 'Camión', 'storage' => 'Storage'];
}

