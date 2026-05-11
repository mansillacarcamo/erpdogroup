<?php
// ============================================================
//  CONTROL DE GASTOS - Config principal
// ============================================================

// Buffer de salida: evita errores "headers already sent" si hay whitespace o BOM
if (ob_get_level() === 0) { ob_start(); }

// Zona horaria fija para que las fechas del mes/período sean consistentes en todos los dispositivos
@date_default_timezone_set('America/Santiago');

// Cache-Control: las páginas con sesión NUNCA deben cachearse (montos, asignaciones, gastos)
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Modo debug: agregar ?debug=1 a cualquier URL para ver errores en pantalla
$__DEBUG = isset($_GET['debug']) || isset($_REQUEST['debug']);
if ($__DEBUG) {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('html_errors', '0');
    error_reporting(0);
}
// Aun en produccion, registrar errores en log del servidor
@ini_set('log_errors', '1');

// ============================================================
//  RED DE SEGURIDAD: si hay error fatal, NUNCA dejar pantalla blanca
// ============================================================
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        // Limpiar cualquier output parcial
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            http_response_code(500);
        }
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Error</title>';
        echo '<style>body{font-family:system-ui,sans-serif;max-width:800px;margin:30px auto;padding:0 15px;color:#333;}'
           . 'h2{color:#dc3545;border-bottom:2px solid #dc3545;padding-bottom:8px;}'
           . 'pre{background:#f8f9fa;padding:12px;border-radius:6px;border:1px solid #dee2e6;overflow-x:auto;}'
           . 'a.btn{display:inline-block;background:#0d6efd;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none;margin-top:15px;}'
           . '.muted{color:#6c757d;font-size:.9rem;}</style></head><body>';
        echo '<h2>Se produjo un error en la aplicacion</h2>';
        echo '<p>El sistema detuvo la pagina por un error inesperado. Detalle tecnico:</p>';
        echo '<pre>' . htmlspecialchars($err['message']) . "\n\nArchivo: "
           . htmlspecialchars(basename($err['file'])) . "\nLinea: " . (int)$err['line'] . '</pre>';
        echo '<p class="muted">Si el problema persiste, copia este mensaje y reportalo al administrador.</p>';
        echo '<a class="btn" href="login.php">Volver al login</a> ';
        echo '<a class="btn" href="?debug=1" style="background:#6c757d">Ver detalles</a>';
        echo '</body></html>';
    }
});

// Convertir warnings/notices en excepciones SOLO en modo debug, para no romper produccion
if ($__DEBUG) {
    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) return false;
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}

date_default_timezone_set('America/Santiago');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Verificar soporte SQLite ---
if (!extension_loaded('pdo_sqlite')) {
    die('<h3>Falta la extension <code>pdo_sqlite</code></h3>'
      . '<p>Activala en el panel de tu hosting (Benza: PHP Selector / Seleccionar version PHP -> Extensiones).</p>');
}

// --- Conexión SQLite (PDO) ---
$DB_PATH = __DIR__ . DIRECTORY_SEPARATOR . 'controlgastos.db';
try {
    $pdo = new PDO('sqlite:' . $DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    // Intentar WAL (mas rapido), si falla usar journal tradicional (algunos hostings lo bloquean)
    try { $pdo->exec('PRAGMA journal_mode = WAL'); }
    catch (Exception $e) { @$pdo->exec('PRAGMA journal_mode = DELETE'); }
} catch (Exception $e) {
    if ($__DEBUG) {
        die('<h3>Error de base de datos</h3><pre>' . htmlspecialchars($e->getMessage())
          . "\n\nRuta: $DB_PATH</pre>");
    }
    die('Error de base de datos. Revisa permisos de escritura en la carpeta o contacta al administrador.');
}

// ============================================================
//  ESQUEMA
// ============================================================
$pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL,
    usuario TEXT UNIQUE,
    email TEXT NOT NULL UNIQUE,
    clave TEXT NOT NULL,
    rol TEXT NOT NULL DEFAULT 'usuario',
    rut TEXT,
    cargo TEXT,
    zona TEXT,
    ciudad TEXT,
    region TEXT,
    telefono TEXT,
    foto_perfil TEXT,
    jefe_zonal_id INTEGER,
    validador_id INTEGER,
    activo INTEGER NOT NULL DEFAULT 1,
    ultimo_popup_fecha TEXT,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (jefe_zonal_id) REFERENCES usuarios(id),
    FOREIGN KEY (validador_id)  REFERENCES usuarios(id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS asignaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL,
    anio INTEGER NOT NULL,
    mes INTEGER NOT NULL,
    monto_asignado REAL NOT NULL DEFAULT 0,
    monto_carryover REAL NOT NULL DEFAULT 0,
    observaciones TEXT,
    asignado_por INTEGER,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(usuario_id, anio, mes),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS gastos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL,
    fecha TEXT NOT NULL,
    monto REAL NOT NULL,
    categoria TEXT,
    descripcion TEXT,
    proveedor TEXT,
    numero_documento TEXT,
    tipo_documento TEXT,
    estado TEXT NOT NULL DEFAULT 'registrado',
    observacion_revision TEXT,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS archivos_gasto (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    gasto_id INTEGER NOT NULL,
    nombre_archivo TEXT NOT NULL,
    nombre_original TEXT,
    tipo TEXT,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (gasto_id) REFERENCES gastos(id) ON DELETE CASCADE
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS cierres_mensuales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL,
    anio INTEGER NOT NULL,
    mes INTEGER NOT NULL,
    monto_asignado REAL NOT NULL DEFAULT 0,
    monto_carryover REAL NOT NULL DEFAULT 0,
    total_gastado REAL NOT NULL DEFAULT 0,
    saldo_final REAL NOT NULL DEFAULT 0,
    estado TEXT NOT NULL DEFAULT 'borrador',
    observaciones_usuario TEXT,
    observaciones_jefe TEXT,
    observaciones_validador TEXT,
    enviado_jefe_en TEXT,
    aprobado_jefe_en TEXT,
    enviado_validador_en TEXT,
    validado_en TEXT,
    rechazado_en TEXT,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(usuario_id, anio, mes),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS notificaciones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario_id INTEGER NOT NULL,
    titulo TEXT NOT NULL,
    mensaje TEXT,
    tipo TEXT DEFAULT 'info',
    leida INTEGER NOT NULL DEFAULT 0,
    enlace TEXT,
    creado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS categorias_gasto (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT NOT NULL UNIQUE,
    icono TEXT,
    color TEXT,
    activo INTEGER NOT NULL DEFAULT 1
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS periodos_cerrados (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    anio INTEGER NOT NULL,
    mes INTEGER NOT NULL,
    cerrado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cerrado_por INTEGER,
    total_gastado REAL DEFAULT 0,
    n_usuarios INTEGER DEFAULT 0,
    n_gastos INTEGER DEFAULT 0,
    UNIQUE(anio, mes)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS presupuesto_mes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    anio INTEGER NOT NULL,
    mes INTEGER NOT NULL,
    monto_total REAL NOT NULL DEFAULT 0,
    actualizado_en TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_por INTEGER,
    UNIQUE(anio, mes)
)");

// === Indices de performance (se crean una sola vez, idempotentes) ===
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_gastos_usuario_fecha ON gastos(usuario_id, fecha)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_gastos_fecha ON gastos(fecha)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_gastos_estado ON gastos(estado)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_asig_usuario_periodo ON asignaciones(usuario_id, anio, mes)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_asig_periodo ON asignaciones(anio, mes)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_archivos_gasto ON archivos_gasto(gasto_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_notif_usuario_leida ON notificaciones(usuario_id, leida)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_cierres_usuario_periodo ON cierres_mensuales(usuario_id, anio, mes)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_usuarios_rol_activo ON usuarios(rol, activo)");

// === Migraciones defensivas: agregar columnas que puedan faltar ===
function _addColSiFalta(PDO $pdo, $tabla, $col, $ddl) {
    try {
        $cols = $pdo->query("PRAGMA table_info($tabla)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE $tabla ADD COLUMN $ddl");
        }
    } catch (Exception $e) { /* silenciar para no romper carga */ }
}

// Usuarios - todas las columnas nuevas que podrian faltar en DB antiguas
_addColSiFalta($pdo, 'usuarios', 'usuario',           'usuario TEXT');
_addColSiFalta($pdo, 'usuarios', 'rut',               'rut TEXT');
_addColSiFalta($pdo, 'usuarios', 'cargo',             'cargo TEXT');
_addColSiFalta($pdo, 'usuarios', 'zona',              'zona TEXT');
_addColSiFalta($pdo, 'usuarios', 'ciudad',            'ciudad TEXT');
_addColSiFalta($pdo, 'usuarios', 'region',            'region TEXT');
_addColSiFalta($pdo, 'usuarios', 'telefono',          'telefono TEXT');
_addColSiFalta($pdo, 'usuarios', 'foto_perfil',       'foto_perfil TEXT');
_addColSiFalta($pdo, 'usuarios', 'jefe_zonal_id',     'jefe_zonal_id INTEGER');
_addColSiFalta($pdo, 'usuarios', 'validador_id',      'validador_id INTEGER');
_addColSiFalta($pdo, 'usuarios', 'activo',            'activo INTEGER NOT NULL DEFAULT 1');
_addColSiFalta($pdo, 'usuarios', 'ultimo_popup_fecha','ultimo_popup_fecha TEXT');

// Si se agrego "usuario", rellenar y crear indice
$cols = $pdo->query("PRAGMA table_info(usuarios)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (in_array('usuario', $cols, true)) {
    $rows = $pdo->query("SELECT id,email,rol FROM usuarios WHERE usuario IS NULL OR usuario=''")->fetchAll();
    foreach ($rows as $r) {
        $base = ($r['rol'] === 'admin') ? 'admin'
              : strtolower(preg_replace('/[^a-zA-Z0-9_.]/','', explode('@',$r['email'])[0]));
        if (!$base) $base = 'user'.$r['id'];
        $u = $base; $i = 1;
        while (true) {
            $chk = $pdo->prepare("SELECT 1 FROM usuarios WHERE usuario=?");
            $chk->execute([$u]);
            if (!$chk->fetchColumn()) break;
            $u = $base.$i++;
        }
        $pdo->prepare("UPDATE usuarios SET usuario=? WHERE id=?")->execute([$u, $r['id']]);
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_usuarios_usuario ON usuarios(usuario)");
}

// Gastos - columnas nuevas
_addColSiFalta($pdo, 'gastos', 'observacion_revision', 'observacion_revision TEXT');
_addColSiFalta($pdo, 'gastos', 'proveedor',            'proveedor TEXT');
_addColSiFalta($pdo, 'gastos', 'numero_documento',     'numero_documento TEXT');
_addColSiFalta($pdo, 'gastos', 'tipo_documento',       'tipo_documento TEXT');

// Cierres mensuales - columnas nuevas
_addColSiFalta($pdo, 'cierres_mensuales', 'enviado_jefe_en',       'enviado_jefe_en TEXT');
_addColSiFalta($pdo, 'cierres_mensuales', 'aprobado_jefe_en',      'aprobado_jefe_en TEXT');
_addColSiFalta($pdo, 'cierres_mensuales', 'enviado_validador_en',  'enviado_validador_en TEXT');
_addColSiFalta($pdo, 'cierres_mensuales', 'validado_en',           'validado_en TEXT');
_addColSiFalta($pdo, 'cierres_mensuales', 'rechazado_en',          'rechazado_en TEXT');
_addColSiFalta($pdo, 'cierres_mensuales', 'observaciones_usuario', 'observaciones_usuario TEXT');
_addColSiFalta($pdo, 'cierres_mensuales', 'observaciones_jefe',    'observaciones_jefe TEXT');
_addColSiFalta($pdo, 'cierres_mensuales', 'observaciones_validador','observaciones_validador TEXT');

// Índices
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_gastos_usuario ON gastos(usuario_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_gastos_fecha ON gastos(fecha)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_asign_user_periodo ON asignaciones(usuario_id, anio, mes)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_cierres_user ON cierres_mensuales(usuario_id, anio, mes)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_notif_user ON notificaciones(usuario_id, leida)");

// ============================================================
//  SEED INICIAL
// ============================================================
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM categorias_gasto")->fetchColumn();
if ($cnt === 0) {
    $cats = [
        ['Combustible',       'bi-fuel-pump',          '#ef4444'],
        ['Alimentación',      'bi-cup-hot',            '#f97316'],
        ['Insumos oficina',   'bi-pencil-square',      '#3b82f6'],
        ['Insumos terreno',   'bi-tools',              '#10b981'],
        ['Transporte',        'bi-truck',              '#8b5cf6'],
        ['Hospedaje',         'bi-house-door',         '#ec4899'],
        ['Peajes',            'bi-sign-turn-right',    '#06b6d4'],
        ['Otros',             'bi-three-dots',         '#64748b'],
    ];
    $stmt = $pdo->prepare("INSERT INTO categorias_gasto (nombre, icono, color) VALUES (?,?,?)");
    foreach ($cats as $c) $stmt->execute($c);
}

$adm = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE rol='admin'")->fetchColumn();
if ((int)$adm === 0) {
    $pdo->prepare("INSERT INTO usuarios (nombre,usuario,email,clave,rol) VALUES (?,?,?,?,?)")
        ->execute([
            '',
            'admin',
            'admin@local',
            password_hash('admin123', PASSWORD_DEFAULT),
            'admin'
        ]);
}

// ============================================================
//  HELPERS
// ============================================================
function requireAuth() {
    if (empty($_SESSION['user_id'])) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        header('Location: login.php');
        exit;
    }
}
function requireRol($roles) {
    requireAuth();
    if (!in_array($_SESSION['user_rol'] ?? '', (array)$roles, true)) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        http_response_code(403);
        die('Acceso denegado. Tu rol actual ('.htmlspecialchars($_SESSION['user_rol'] ?? '').') no tiene permisos para esta seccion.');
    }
}
function fmtCLP($n) {
    return '$' . number_format((float)$n, 0, ',', '.');
}
function parseMonto($s) {
    $s = preg_replace('/\D/', '', (string)$s);
    return (float)$s;
}
function regionesChile() {
    return [
        'Arica y Parinacota','Tarapaca','Antofagasta','Atacama','Coquimbo',
        'Valparaiso','Metropolitana','OHiggins','Maule','Nuble','Biobio',
        'La Araucania','Los Rios','Los Lagos','Aysen','Magallanes'
    ];
}
function periodoActual() {
    return ['anio' => (int)date('Y'), 'mes' => (int)date('n')];
}
function asignacionPeriodo($pdo, $usuario_id, $anio = null, $mes = null) {
    if ($anio === null || $mes === null) {
        $p = periodoActual();
        $anio = $p['anio']; $mes = $p['mes'];
    }
    $q = $pdo->prepare("SELECT * FROM asignaciones WHERE usuario_id=? AND anio=? AND mes=?");
    $q->execute([$usuario_id, $anio, $mes]);
    $r = $q->fetch();
    if (!$r) return ['monto_asignado'=>0,'monto_carryover'=>0,'total'=>0];
    $r['total'] = (float)$r['monto_asignado'] + (float)$r['monto_carryover'];
    return $r;
}
function totalGastadoPeriodo($pdo, $usuario_id, $anio = null, $mes = null) {
    if ($anio === null || $mes === null) {
        $p = periodoActual();
        $anio = $p['anio']; $mes = $p['mes'];
    }
    $ini = sprintf('%04d-%02d-01', $anio, $mes);
    $fin = date('Y-m-t', strtotime($ini));
    $q = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE usuario_id=? AND fecha BETWEEN ? AND ?");
    $q->execute([$usuario_id, $ini, $fin]);
    return (float)$q->fetchColumn();
}
function saldoActual($pdo, $usuario_id) {
    $a = asignacionPeriodo($pdo, $usuario_id);
    $g = totalGastadoPeriodo($pdo, $usuario_id);
    return ($a['total'] ?? 0) - $g;
}
function nombreMes($m) {
    $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    return $meses[(int)$m] ?? '';
}
function nombreDia($w) {
    $dias = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    return $dias[(int)$w] ?? '';
}
function fechaLarga($ts = null) {
    $ts = $ts ?? time();
    return nombreDia(date('w', $ts)) . ' ' . date('d', $ts) . ' de ' . nombreMes(date('n', $ts));
}
function h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}
function flash($tipo, $msg) {
    $_SESSION['flash'][] = ['tipo'=>$tipo, 'msg'=>$msg];
}
function getFlash() {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ============================================================
//  FOTOS DE PERFIL (tamaño carnet)
// ============================================================
define('FOTO_USUARIOS_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'usuarios');
define('FOTO_USUARIOS_URL', 'uploads/usuarios');

/**
 * Procesa la subida de una foto de perfil (tamaño carnet).
 * Devuelve el nombre del archivo guardado o null si no se subió archivo nuevo.
 * Lanza Exception si el archivo es inválido.
 */
function procesarFotoPerfil($fileKey, $usuarioId, $fotoActual = null) {
    if (empty($_FILES[$fileKey]) || ($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$fileKey];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error al subir la foto (codigo '.$f['error'].').');
    }
    if ($f['size'] > 3 * 1024 * 1024) {
        throw new Exception('La foto supera los 3 MB.');
    }
    $info = @getimagesize($f['tmp_name']);
    if (!$info) {
        throw new Exception('El archivo no es una imagen valida.');
    }
    $mime = $info['mime'];
    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($extMap[$mime])) {
        throw new Exception('Formato no permitido. Usa JPG, PNG, WEBP o GIF.');
    }
    $ext = $extMap[$mime];
    if (!is_dir(FOTO_USUARIOS_DIR)) @mkdir(FOTO_USUARIOS_DIR, 0775, true);

    $nombre = 'u'.$usuarioId.'_'.time().'.'.$ext;
    $destino = FOTO_USUARIOS_DIR . DIRECTORY_SEPARATOR . $nombre;
    if (!@move_uploaded_file($f['tmp_name'], $destino)) {
        throw new Exception('No se pudo guardar la foto.');
    }
    // Borrar foto anterior si existe (limpieza)
    if ($fotoActual) {
        $ant = FOTO_USUARIOS_DIR . DIRECTORY_SEPARATOR . $fotoActual;
        if (is_file($ant)) @unlink($ant);
    }
    return $nombre;
}

/**
 * URL para la foto del usuario. Si no tiene foto, devuelve '' (usar iniciales).
 */
function urlFotoUsuario($nombreArchivo) {
    if (!$nombreArchivo) return '';
    $full = FOTO_USUARIOS_DIR . DIRECTORY_SEPARATOR . $nombreArchivo;
    if (!is_file($full)) return '';
    return FOTO_USUARIOS_URL . '/' . rawurlencode($nombreArchivo) . '?v=' . filemtime($full);
}

/**
 * Renderiza un avatar circular. Si hay foto la usa, si no muestra la inicial del nombre.
 * $size: px (default 40). $extraClass: clase extra opcional.
 */
function avatarUsuario($nombre, $fotoArchivo, $size = 40, $extraClass = '') {
    $url = urlFotoUsuario($fotoArchivo);
    $s = (int)$size;
    $style = "width:{$s}px;height:{$s}px;border-radius:50%;object-fit:cover;border:2px solid #e5e7eb;";
    if ($url !== '') {
        return '<img src="'.h($url).'" alt="'.h($nombre).'" class="'.h($extraClass).'" style="'.$style.'">';
    }
    $ini = mb_strtoupper(mb_substr(trim($nombre ?: '?'), 0, 1));
    $styleIni = $style . "display:inline-flex;align-items:center;justify-content:center;background:#e2e8f0;color:#475569;font-weight:700;font-size:".max(12,(int)($s*0.42))."px;";
    return '<span class="'.h($extraClass).'" style="'.$styleIni.'">'.h($ini).'</span>';
}

define('APP_NAME', 'Control de Gastos Diarios');
define('APP_VER', '1.0.8');
