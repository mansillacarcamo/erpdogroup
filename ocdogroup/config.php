<?php
// DEBUG temporal: mostrar errores en pantalla (quitar cuando el sistema esté estable)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();

$pdo = new PDO('sqlite:' . __DIR__ . '/ocdogroup.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec("
  CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario TEXT UNIQUE NOT NULL,
    clave TEXT NOT NULL,
    nombre TEXT NOT NULL,
    ci TEXT,
    cargo TEXT DEFAULT 'usuario',
    rol TEXT NOT NULL DEFAULT 'usuario',
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE IF NOT EXISTS ordenes_compra (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    numero INTEGER UNIQUE NOT NULL,
    fecha TEXT NOT NULL,
    proveedor_nombre TEXT NOT NULL,
    proveedor_rut TEXT,
    proveedor_direccion TEXT,
    proveedor_ciudad TEXT,
    proveedor_fono TEXT,
    proveedor_correo TEXT,
    proveedor_atencion TEXT,
    obra TEXT,
    moneda TEXT DEFAULT 'CLP',
    cotizado_por TEXT,
    observaciones TEXT,
    plazo_entrega TEXT,
    neto REAL DEFAULT 0,
    iva REAL DEFAULT 0,
    total REAL DEFAULT 0,
    estado TEXT DEFAULT 'pendiente',
    preparada_por TEXT NOT NULL,
    aprobada_por TEXT,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE IF NOT EXISTS oc_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oc_id INTEGER NOT NULL,
    cantidad REAL DEFAULT 1,
    unidad TEXT DEFAULT 'UN',
    descripcion TEXT NOT NULL,
    descuento REAL DEFAULT 0,
    precio_unitario REAL DEFAULT 0,
    valor_total REAL DEFAULT 0,
    FOREIGN KEY (oc_id) REFERENCES ordenes_compra(id) ON DELETE CASCADE
  );
  CREATE TABLE IF NOT EXISTS cotizaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    numero INTEGER UNIQUE NOT NULL,
    fecha TEXT NOT NULL,
    valido_hasta TEXT,
    cliente_nombre TEXT NOT NULL,
    cliente_obra TEXT,
    cliente_rut TEXT,
    cliente_telefono TEXT,
    cliente_email TEXT,
    adicionales TEXT,
    condiciones TEXT,
    subtotal REAL DEFAULT 0,
    iva REAL DEFAULT 0,
    total REAL DEFAULT 0,
    estado TEXT DEFAULT 'pendiente',
    creada_por TEXT NOT NULL,
    creada_por_telefono TEXT,
    creada_por_email TEXT,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
  );
  CREATE TABLE IF NOT EXISTS cot_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cot_id INTEGER NOT NULL,
    descripcion TEXT NOT NULL,
    detalle TEXT,
    cantidad REAL DEFAULT 1,
    unidad TEXT DEFAULT 'UN',
    precio REAL DEFAULT 0,
    total REAL DEFAULT 0,
    FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
  );
  CREATE TABLE IF NOT EXISTS clientes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    rut TEXT,
    direccion TEXT,
    ciudad TEXT,
    telefono TEXT,
    email TEXT,
    contacto TEXT,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
  );
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS cot_procesos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cot_id INTEGER NOT NULL,
    tipo TEXT NOT NULL,
    titulo TEXT NOT NULL,
    descripcion TEXT,
    estado_proceso TEXT DEFAULT 'pendiente',
    estado_anterior TEXT,
    estado_nuevo TEXT,
    usuario TEXT NOT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
  );
  CREATE TABLE IF NOT EXISTS cot_asociaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cot_id_origen INTEGER NOT NULL,
    cot_id_destino INTEGER NOT NULL,
    tipo TEXT DEFAULT 'relacionada',
    nota TEXT,
    usuario TEXT NOT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cot_id_origen) REFERENCES cotizaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (cot_id_destino) REFERENCES cotizaciones(id) ON DELETE CASCADE
  );
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS oc_aprobaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oc_id INTEGER NOT NULL,
    usuario_id INTEGER NOT NULL,
    nombre TEXT NOT NULL,
    estado TEXT DEFAULT 'pendiente',
    comentario TEXT,
    fecha_respuesta DATETIME,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (oc_id) REFERENCES ordenes_compra(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
  );
");

try {
  $info = $pdo->query("PRAGMA table_info(oc_aprobaciones)")->fetchAll(PDO::FETCH_ASSOC);
  $cols = array_column($info, 'name');
  if (in_array('email', $cols) || !in_array('usuario_id', $cols)) {
    $pdo->exec("DROP TABLE IF EXISTS oc_aprobaciones");
    $pdo->exec("
      CREATE TABLE oc_aprobaciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        oc_id INTEGER NOT NULL,
        usuario_id INTEGER NOT NULL,
        nombre TEXT NOT NULL,
        estado TEXT DEFAULT 'pendiente',
        comentario TEXT,
        fecha_respuesta DATETIME,
        creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (oc_id) REFERENCES ordenes_compra(id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
      );
    ");
  }
} catch (Exception $e) {}

$pdo->exec("
  CREATE TABLE IF NOT EXISTS oc_envios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oc_id INTEGER NOT NULL,
    enviado_por TEXT NOT NULL,
    destinatario_email TEXT NOT NULL,
    destinatario_nombre TEXT,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (oc_id) REFERENCES ordenes_compra(id) ON DELETE CASCADE
  );
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS oc_aprobadores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL UNIQUE,
    agregado_por TEXT NOT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
  );
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS cot_envios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cot_id INTEGER NOT NULL,
    enviado_por TEXT NOT NULL,
    destinatario_email TEXT NOT NULL,
    destinatario_nombre TEXT,
    contenido TEXT DEFAULT 'cotizacion',
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE
  );
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS cot_notificaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cot_id INTEGER NOT NULL,
    destinatario_id INTEGER NOT NULL,
    enviado_por TEXT NOT NULL,
    contenido TEXT DEFAULT 'cotizacion',
    leida INTEGER DEFAULT 0,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (destinatario_id) REFERENCES usuarios(id)
  );
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS cot_aprobaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cot_id INTEGER NOT NULL,
    usuario_id INTEGER NOT NULL,
    nombre TEXT NOT NULL,
    estado TEXT DEFAULT 'pendiente',
    comentario TEXT,
    fecha_respuesta DATETIME,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
  );
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS oc_notificaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    oc_id INTEGER NOT NULL,
    destinatario_id INTEGER NOT NULL,
    enviado_por TEXT NOT NULL,
    contenido TEXT DEFAULT 'proceso',
    leida INTEGER DEFAULT 0,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (oc_id) REFERENCES ordenes_compra(id) ON DELETE CASCADE,
    FOREIGN KEY (destinatario_id) REFERENCES usuarios(id)
  );
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS cot_oc_asociaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cot_id INTEGER NOT NULL,
    oc_id INTEGER NOT NULL,
    nota TEXT,
    usuario TEXT NOT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (oc_id) REFERENCES ordenes_compra(id) ON DELETE CASCADE
  );
");

$pdo->exec("
  CREATE TABLE IF NOT EXISTS cot_archivos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cot_id INTEGER NOT NULL,
    proc_id INTEGER,
    nombre_original TEXT NOT NULL,
    nombre_archivo TEXT NOT NULL,
    tipo_mime TEXT,
    tamano INTEGER DEFAULT 0,
    usuario TEXT NOT NULL,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cot_id) REFERENCES cotizaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (proc_id) REFERENCES cot_procesos(id) ON DELETE SET NULL
  );
");

$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) { mkdir($uploadsDir, 0755, true); }

try { $pdo->exec("ALTER TABLE cot_procesos ADD COLUMN estado_proceso TEXT DEFAULT 'pendiente'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN email TEXT DEFAULT ''"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN cliente_ciudad TEXT DEFAULT ''"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cotizaciones ADD COLUMN moneda TEXT DEFAULT 'CLP'"); } catch (Exception $e) {}

/* ============================================================
   Módulo OBRAS
   ============================================================ */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS obras (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo TEXT UNIQUE NOT NULL,
    nombre TEXT NOT NULL,
    ciudad TEXT,
    mandante TEXT,
    contacto TEXT,
    telefono TEXT,
    email TEXT,
    estado TEXT DEFAULT 'activa',
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
  );
");

/* ============================================================
   Módulo PROVEEDORES
   ============================================================ */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS proveedores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    rut TEXT,
    giro TEXT,
    direccion TEXT,
    ciudad TEXT,
    telefono TEXT,
    email TEXT,
    contacto TEXT,
    banco TEXT,
    tipo_cuenta TEXT,
    numero_cuenta TEXT,
    forma_pago TEXT,
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
  );
");

/* Asociaciones para estado de pago (vinculan OC y Cotización con obra/proveedor) */
try { $pdo->exec("ALTER TABLE ordenes_compra ADD COLUMN obra_id INTEGER"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE ordenes_compra ADD COLUMN obra_codigo TEXT DEFAULT ''"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE ordenes_compra ADD COLUMN proveedor_id INTEGER"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cotizaciones    ADD COLUMN obra_id INTEGER"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE ordenes_compra  ADD COLUMN tipo_cambio REAL DEFAULT 1"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cotizaciones    ADD COLUMN tipo_cambio REAL DEFAULT 1"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE ordenes_compra  ADD COLUMN obra_codigo TEXT DEFAULT ''"); } catch (Exception $e) {}

/* ============================================================
   Módulo PERMISOS DE MÓDULOS POR USUARIO
   Si un usuario no tiene filas en esta tabla → accede a TODO.
   Admin siempre accede a todo sin excepción.
   ============================================================ */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS usuario_modulos (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL,
    modulo     TEXT    NOT NULL,
    UNIQUE(usuario_id, modulo),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
  );
");

/* ============================================================
   Módulo ESTADOS DE PAGO (cobros / pagos)
   tipo: cobro_empresa | cobro_cliente | pago_maquinaria | pago_proveedor
   ============================================================ */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS estados_pago (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tipo TEXT NOT NULL,
    fecha DATE NOT NULL,
    obra_id INTEGER,
    obra_codigo TEXT DEFAULT '',
    obra_nombre TEXT DEFAULT '',
    contraparte TEXT DEFAULT '',
    contraparte_rut TEXT DEFAULT '',
    proveedor_id INTEGER,
    cliente_id INTEGER,
    oc_id INTEGER,
    cot_id INTEGER,
    numero_documento TEXT DEFAULT '',
    descripcion TEXT DEFAULT '',
    monto_neto REAL DEFAULT 0,
    iva REAL DEFAULT 0,
    monto_total REAL DEFAULT 0,
    fecha_vencimiento DATE,
    fecha_pago DATE,
    estado TEXT DEFAULT 'pendiente',
    forma_pago TEXT DEFAULT '',
    observaciones TEXT DEFAULT '',
    creado_por TEXT DEFAULT '',
    creado_en DATETIME DEFAULT CURRENT_TIMESTAMP
  );
");
try { $pdo->exec("ALTER TABLE estados_pago ADD COLUMN oc_id INTEGER"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE estados_pago ADD COLUMN cot_id INTEGER"); } catch (Exception $e) {}


$stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = ?");
$stmt->execute(['admin']);
if ((int)$stmt->fetchColumn() === 0) {
  $hash = password_hash('admin123', PASSWORD_DEFAULT);
  $pdo->prepare("INSERT INTO usuarios (usuario, clave, nombre, ci, cargo, rol) VALUES (?,?,?,?,?,?)")
    ->execute(['admin', $hash, 'Jonathan Maldonado Vera', '16.780.528-0', 'Gerente General', 'admin']);
}


if (!empty($_SESSION['usuario'])) {
  $stmtSesion = $pdo->prepare("SELECT rol, nombre, cargo FROM usuarios WHERE id = ?");
  $stmtSesion->execute([$_SESSION['usuario']['id']]);
  $datosActuales = $stmtSesion->fetch(PDO::FETCH_ASSOC);
  if ($datosActuales) {
    $_SESSION['usuario']['rol'] = $datosActuales['rol'];
    $_SESSION['usuario']['nombre'] = $datosActuales['nombre'];
    $_SESSION['usuario']['cargo'] = $datosActuales['cargo'];
  }
}
$usuario = $_SESSION['usuario'] ?? null;

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

/**
 * Formato chileno: miles con punto, decimales con coma.
 * @param int $dec Número de decimales (0 = entero, 2 = cotizaciones con centavos).
 */
function formatCLP($v, $dec = 0) {
  return number_format((float)$v, (int)$dec, ',', '.');
}

/** Formatea un valor monetario según la moneda:
 *  CLP → sin decimales  |  USD/EUR → 2 decimales  |  UF → 4 decimales */
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

/* ============================================================
   Módulo TICKETS DE DESPACHO
   ============================================================ */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS despacho_ppu (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    ppu           TEXT UNIQUE NOT NULL,
    descripcion   TEXT DEFAULT '',
    metros_cubicos REAL DEFAULT 0,
    activo        INTEGER DEFAULT 1,
    creado_en     DATETIME DEFAULT CURRENT_TIMESTAMP
  );
");
$pdo->exec("
  CREATE TABLE IF NOT EXISTS despacho_destinos (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre     TEXT NOT NULL,
    descripcion TEXT DEFAULT '',
    activo     INTEGER DEFAULT 1,
    creado_en  DATETIME DEFAULT CURRENT_TIMESTAMP
  );
");
$pdo->exec("
  CREATE TABLE IF NOT EXISTS despacho_receptores (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL UNIQUE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
  );
");
/* Roles dentro del módulo: 'admin_despacho' puede configurar PPU/destinos/receptores,
   'usuario' solo crea tickets. El admin global del sistema siempre tiene acceso total. */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS despacho_roles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL UNIQUE,
    rol        TEXT    NOT NULL DEFAULT 'usuario',
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
  );
");
$pdo->exec("
  CREATE TABLE IF NOT EXISTS tickets_despacho (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    fecha           DATE NOT NULL,
    hora            TIME NOT NULL,
    obra_id         INTEGER,
    obra_nombre     TEXT DEFAULT '',
    ppu_id          INTEGER,
    ppu             TEXT DEFAULT '',
    metros_cubicos  REAL DEFAULT 0,
    destino_id      INTEGER,
    destino         TEXT DEFAULT '',
    tipo_material   TEXT NOT NULL,
    cambio          TEXT DEFAULT '',
    estado          TEXT DEFAULT 'borrador',
    creado_por      INTEGER NOT NULL,
    creado_por_nombre TEXT DEFAULT '',
    receptor_id     INTEGER,
    token           TEXT UNIQUE,
    enviado_en      DATETIME,
    validado_en     DATETIME,
    validado_por    INTEGER,
    validado_por_nombre TEXT DEFAULT '',
    observaciones   TEXT DEFAULT '',
    creado_en       DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creado_por) REFERENCES usuarios(id)
  );
");
/* Usuarios externos (receptores/validadores independientes del sistema principal) */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS despacho_externos (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre        TEXT    NOT NULL,
    rut           TEXT    DEFAULT '',
    email         TEXT    DEFAULT '',
    empresa       TEXT    DEFAULT '',
    obra_id       INTEGER,
    fono          TEXT    DEFAULT '',
    usuario       TEXT    UNIQUE NOT NULL,
    password_hash TEXT    NOT NULL,
    activo        INTEGER DEFAULT 1,
    creado_en     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (obra_id) REFERENCES obras(id) ON DELETE SET NULL
  );
");
/* Ampliar despacho_receptores para aceptar externos */
try { $pdo->exec("ALTER TABLE despacho_receptores ADD COLUMN tipo      TEXT    DEFAULT 'interno'"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE despacho_receptores ADD COLUMN externo_id INTEGER DEFAULT NULL");    } catch(Exception $e) {}

/* Encargado de costos: recibe el reporte diario de despacho */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS despacho_encargado_costos (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL UNIQUE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
  );
");
/* Conductores del módulo de despacho */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS despacho_conductores (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre         TEXT    NOT NULL,
    rut            TEXT    DEFAULT '',
    ppu_id         INTEGER,
    obra_id        INTEGER,
    activo         INTEGER DEFAULT 1,
    creado_en      DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ppu_id)  REFERENCES despacho_ppu(id) ON DELETE SET NULL,
    FOREIGN KEY (obra_id) REFERENCES obras(id) ON DELETE SET NULL
  );
");
/* Columnas conductor en tickets_despacho */
try { $pdo->exec("ALTER TABLE tickets_despacho ADD COLUMN conductor_id      INTEGER DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE tickets_despacho ADD COLUMN conductor_nombre  TEXT    DEFAULT ''");   } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE tickets_despacho ADD COLUMN conductor_rut     TEXT    DEFAULT ''");   } catch(Exception $e) {}
/* Credenciales de acceso para conductores */
try { $pdo->exec("ALTER TABLE despacho_conductores ADD COLUMN usuario       TEXT    DEFAULT ''");          } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE despacho_conductores ADD COLUMN password_hash TEXT    DEFAULT ''");          } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE despacho_conductores ADD COLUMN email         TEXT    DEFAULT ''");          } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE despacho_conductores ADD COLUMN fono          TEXT    DEFAULT ''");          } catch(Exception $e) {}
try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_cond_usuario ON despacho_conductores(usuario) WHERE usuario != ''"); } catch(Exception $e) {}

/* Validaciones de PPU (escaneo de patente por Carchek) */
$pdo->exec("CREATE TABLE IF NOT EXISTS despacho_ppu_validaciones (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  ppu_id          INTEGER NOT NULL,
  ppu             TEXT    NOT NULL,
  obra_id         INTEGER,
  obra_nombre     TEXT    DEFAULT '',
  validado_por    TEXT    DEFAULT '',
  validado_tipo   TEXT    DEFAULT 'externo',
  validado_en     DATETIME DEFAULT CURRENT_TIMESTAMP,
  observacion     TEXT    DEFAULT ''
)");

/* Tracking de reportes enviados por Carchek */
$pdo->exec("CREATE TABLE IF NOT EXISTS despacho_reporte_carchek (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  externo_id  INTEGER NOT NULL,
  fecha_reporte TEXT  NOT NULL,
  enviado_en  DATETIME DEFAULT CURRENT_TIMESTAMP,
  destinatarios TEXT DEFAULT ''
)");

/* Encargado de Operaciones (receptor de revisiones de validadores externos) */
$pdo->exec("CREATE TABLE IF NOT EXISTS despacho_encargado_operaciones (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id INTEGER,
  email_ext  TEXT DEFAULT ''
)");

/* Revisión de tickets por validador externo */
try { $pdo->exec("ALTER TABLE tickets_despacho ADD COLUMN estado_revision       TEXT    DEFAULT NULL");          } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE tickets_despacho ADD COLUMN observacion_revision  TEXT    DEFAULT ''");            } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE tickets_despacho ADD COLUMN revisado_por_ext_id   INTEGER DEFAULT NULL");          } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE tickets_despacho ADD COLUMN revisado_por_ext_nom  TEXT    DEFAULT ''");            } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE tickets_despacho ADD COLUMN revisado_en           TEXT    DEFAULT NULL");           } catch(Exception $e) {}

/* Registro de cierres diarios */
$pdo->exec("
  CREATE TABLE IF NOT EXISTS despacho_cierre_dia (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    fecha             DATE    NOT NULL UNIQUE,
    total_tickets     INTEGER DEFAULT 0,
    total_m3          REAL    DEFAULT 0,
    resumen_json      TEXT    DEFAULT '{}',
    cerrado_por       INTEGER,
    cerrado_por_nombre TEXT   DEFAULT '',
    cerrado_en        DATETIME DEFAULT CURRENT_TIMESTAMP,
    enviado           INTEGER DEFAULT 0,
    enviado_en        DATETIME,
    FOREIGN KEY (cerrado_por) REFERENCES usuarios(id)
  );
");

/** Catálogo de módulos del sistema */
function modulosDisponibles() {
  return [
    'oc'            => ['label' => 'Órdenes de Compra',     'icono' => 'bi-file-earmark-ruled',  'color' => 'danger'],
    'cot'           => ['label' => 'Cotizaciones',           'icono' => 'bi-receipt',             'color' => 'primary'],
    'contactos'     => ['label' => 'Clientes y Proveedores', 'icono' => 'bi-person-vcard',        'color' => 'success'],
    'obras'         => ['label' => 'Obras',                  'icono' => 'bi-building',            'color' => 'warning'],
    'estados_pago'  => ['label' => 'Estados de Pago',       'icono' => 'bi-cash-coin',           'color' => 'info'],
    'despacho'      => ['label' => 'Tickets de Despacho',   'icono' => 'bi-truck-front-fill',    'color' => 'secondary'],
    'admin'         => ['label' => 'Administración',        'icono' => 'bi-shield-lock',         'color' => 'dark'],
  ];
}

/** Centinela de bloqueo total. Si el usuario tiene SOLO esta fila en usuario_modulos,
 *  no puede acceder a ningun modulo (salvo admins, que siempre pueden todo). */
if (!defined('MOD_BLOQUEADO')) define('MOD_BLOQUEADO', '__bloqueado__');

/** Indica si el usuario tiene su acceso completamente bloqueado. */
function accesoBloqueado($usuario, $pdo) {
  if (!$usuario) return true;
  if ($usuario['rol'] === 'admin') return false;
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM usuario_modulos WHERE usuario_id = ? AND modulo = ?");
    $st->execute([$usuario['id'], MOD_BLOQUEADO]);
    return (int)$st->fetchColumn() > 0;
  } catch (Exception $e) { return false; }
}

/**
 * Verifica si el usuario tiene acceso a un módulo.
 * Admin: siempre puede todo.
 * Bloqueado (centinela __bloqueado__): no accede a NADA.
 * Sin filas en usuario_modulos: accede a todo (compatible con usuarios existentes).
 * Con filas (sin centinela): sólo los módulos asignados.
 */
function canAccess($modulo, $usuario, $pdo) {
  if (!$usuario) return false;
  if ($usuario['rol'] === 'admin') return true;
  try {
    if (accesoBloqueado($usuario, $pdo)) return false;
    $stTotal = $pdo->prepare("SELECT COUNT(*) FROM usuario_modulos WHERE usuario_id = ? AND modulo != ?");
    $stTotal->execute([$usuario['id'], MOD_BLOQUEADO]);
    if ((int)$stTotal->fetchColumn() === 0) return true; // sin restricciones
    $stMod = $pdo->prepare("SELECT COUNT(*) FROM usuario_modulos WHERE usuario_id = ? AND modulo = ?");
    $stMod->execute([$usuario['id'], $modulo]);
    return (int)$stMod->fetchColumn() > 0;
  } catch (Exception $e) { return true; }
}

/** Redirige a inicio.php si el usuario no tiene acceso al módulo */
function requireModulo($modulo, $usuario, $pdo) {
  if (!canAccess($modulo, $usuario, $pdo)) {
    header('Location: inicio.php?acceso_denegado=' . urlencode($modulo));
    exit;
  }
}

/* ============================================================
   Funciones específicas del Módulo Despacho
   ============================================================ */

/**
 * Devuelve true si el usuario es admin global del sistema
 * O si tiene rol 'admin_despacho' asignado en despacho_roles.
 */
function isDespachoAdmin($usuario, $pdo) {
  if ($usuario['rol'] === 'admin') return true;
  try {
    $st = $pdo->prepare("SELECT rol FROM despacho_roles WHERE usuario_id = ?");
    $st->execute([$usuario['id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row && $row['rol'] === 'admin_despacho';
  } catch (Exception $e) { return false; }
}

/**
 * Devuelve true si el usuario tiene acceso al módulo despacho
 * (ya sea como usuario normal, receptor, admin_despacho o admin global).
 */
function canAccessDespacho($usuario, $pdo) {
  return canAccess('despacho', $usuario, $pdo);
}

/**
 * Devuelve el usuario externo de despacho desde la sesión, o null si no hay.
 */
function getExternoSession() {
  return $_SESSION['despacho_externo'] ?? null;
}

/**
 * True si hay un validador externo activo en sesión.
 */
function isExternoLoggedIn() {
  return !empty($_SESSION['despacho_externo']);
}

/**
 * Devuelve el conductor logueado desde la sesión, o null.
 */
function getConductorSession() {
  return $_SESSION['despacho_conductor'] ?? null;
}

/**
 * True si hay un conductor activo en sesión.
 */
function isConductorLoggedIn() {
  return !empty($_SESSION['despacho_conductor']);
}

/**
 * Detiene la ejecución y redirige si el usuario no es admin de despacho.
 */
function requireDespachoAdmin($usuario, $pdo) {
  if (!isDespachoAdmin($usuario, $pdo)) {
    header('Location: tickets_despacho.php?error=sin_permiso');
    exit;
  }
}

/**
 * Devuelve el rol del usuario dentro del módulo despacho.
 * Retorna: 'admin_despacho', 'usuario', o null si no tiene acceso.
 */
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
