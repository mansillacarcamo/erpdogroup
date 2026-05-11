<?php
require_once 'config.php';
requireAuth();

/* ===========================================================
   MÓDULO ESTADOS DE PAGO
   Tipos: cobro_empresa (Ventas) | pago_proveedor (Pago Proveedores)
   =========================================================== */

$tiposValidos = [
  'cobro_empresa'    => ['titulo' => 'Ventas',     'icono' => 'bi-building-fill-up',   'color' => 'success', 'verbo' => 'Cobrar', 'contraparte_label' => 'Empresa',    'sentido' => 'cobro'],
  'pago_proveedor'   => ['titulo' => 'Pago Proveedores',   'icono' => 'bi-bank',               'color' => 'danger',  'verbo' => 'Pagar',  'contraparte_label' => 'Proveedor',  'sentido' => 'pago'],
];

// Tipo activo desde URL — por defecto Ventas
$tipoActivo = $_GET['tipo'] ?? $_POST['tipo'] ?? 'cobro_empresa';
if (!isset($tiposValidos[$tipoActivo])) {
    $tipoActivo = 'cobro_empresa';
}

$cfg = $tiposValidos[$tipoActivo];

$mensaje = null;
$mensajeTipo = 'success';

/* ============= EXPORTAR CSV (Excel) ============= */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  $filtroEstado = $_GET['estado'] ?? '';
  $filtroMes    = $_GET['mes']    ?? '';
  $filtroAnio   = $_GET['anio']   ?? '';
  $filtroBusca  = trim($_GET['q'] ?? '');
  $where = ['tipo = ?']; $params = [$tipoActivo];
  if ($filtroEstado !== '') { $where[] = 'estado = ?'; $params[] = $filtroEstado; }
  if ($filtroMes !== '')    { $where[] = "strftime('%m', fecha) = ?"; $params[] = str_pad($filtroMes, 2, '0', STR_PAD_LEFT); }
  if ($filtroAnio !== '')   { $where[] = "strftime('%Y', fecha) = ?"; $params[] = $filtroAnio; }
  if ($filtroBusca !== '')  { $where[] = '(contraparte LIKE ? OR obra_nombre LIKE ? OR numero_documento LIKE ? OR descripcion LIKE ?)';
                              $bq = "%$filtroBusca%"; array_push($params, $bq, $bq, $bq, $bq); }
  $st = $pdo->prepare("SELECT * FROM estados_pago WHERE ".implode(' AND ', $where)." ORDER BY fecha DESC, id DESC");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $filename = 'estados_pago_'.$tipoActivo.'_'.date('Ymd_His').'.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Fecha','Documento','Cód.Obra','Obra','Contraparte','RUT','Descripción','Neto','IVA','Total','Vencimiento','Fecha Pago','Estado','Forma Pago','Observaciones','OC','Cotización','Creado por'], ';');
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['fecha'], $r['numero_documento'], $r['obra_codigo'], $r['obra_nombre'],
      $r['contraparte'], $r['contraparte_rut'], $r['descripcion'],
      number_format((float)$r['monto_neto'], 0, ',', '.'),
      number_format((float)$r['iva'], 0, ',', '.'),
      number_format((float)$r['monto_total'], 0, ',', '.'),
      $r['fecha_vencimiento'], $r['fecha_pago'],
      $r['estado'], $r['forma_pago'], $r['observaciones'],
      $r['oc_id']  ? '#'.$r['oc_id']  : '',
      $r['cot_id'] ? '#'.$r['cot_id'] : '',
      $r['creado_por']
    ], ';');
  }
  fclose($out);
  exit;
}

/* ============= PRECARGA DESDE OC (?from_oc=ID) o COTIZACIÓN (?from_cot=ID) ============= */
$precarga = null;
if (!empty($_GET['from_oc'])) {
  $stOC = $pdo->prepare("SELECT * FROM ordenes_compra WHERE id = ?");
  $stOC->execute([(int)$_GET['from_oc']]);
  $ocOrigen = $stOC->fetch(PDO::FETCH_ASSOC);
  if ($ocOrigen) {
    $precarga = [
      'fecha'             => date('Y-m-d'),
      'obra_codigo'       => $ocOrigen['obra_codigo'] ?? '',
      'obra_nombre'       => $ocOrigen['obra'] ?? '',
      'contraparte'       => $ocOrigen['proveedor_nombre'] ?? '',
      'contraparte_rut'   => $ocOrigen['proveedor_rut'] ?? '',
      'numero_documento'  => 'OC '.$ocOrigen['numero'],
      'descripcion'       => 'Pago de OC N° '.$ocOrigen['numero'],
      'monto_neto'        => $ocOrigen['neto'] ?? 0,
      'iva'               => $ocOrigen['iva'] ?? 0,
      'monto_total'       => $ocOrigen['total'] ?? 0,
      'oc_id'             => $ocOrigen['id'],
      'cot_id'            => null,
      'estado'            => 'pendiente',
      'fecha_vencimiento' => '',
      'fecha_pago'        => '',
      'forma_pago'        => '',
      'observaciones'     => '',
    ];
  }
} elseif (!empty($_GET['from_cot'])) {
  $stCot = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
  $stCot->execute([(int)$_GET['from_cot']]);
  $cotOrigen = $stCot->fetch(PDO::FETCH_ASSOC);
  if ($cotOrigen) {
    $netoCot = $cotOrigen['subtotal'] ?? 0;
    $ivaCot  = $cotOrigen['iva'] ?? 0;
    $totCot  = $cotOrigen['total'] ?? 0;
    $precarga = [
      'fecha'             => date('Y-m-d'),
      'obra_codigo'       => '',
      'obra_nombre'       => $cotOrigen['cliente_obra'] ?? '',
      'contraparte'       => $cotOrigen['cliente_nombre'] ?? '',
      'contraparte_rut'   => $cotOrigen['cliente_rut'] ?? '',
      'numero_documento'  => 'COT '.$cotOrigen['numero'],
      'descripcion'       => 'Cobro de Cotización N° '.$cotOrigen['numero'],
      'monto_neto'        => $netoCot,
      'iva'               => $ivaCot,
      'monto_total'       => $totCot,
      'oc_id'             => null,
      'cot_id'            => $cotOrigen['id'],
      'estado'            => 'pendiente',
      'fecha_vencimiento' => '',
      'fecha_pago'        => '',
      'forma_pago'        => '',
      'observaciones'     => '',
    ];
  }
}

/* ============= ACCIONES (CRUD) ============= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $accion = $_POST['accion'] ?? '';
  // Agregar gasto de faena rápido desde el estado de pago
  if ($accion === 'agregar_gasto_faena') {
    $obraIdGF = (int)($_POST['gf_obra_id'] ?? 0);
    $epIdGF   = (int)($_POST['gf_ep_id'] ?? 0);
    $tipoGF   = $_POST['tipo'] ?? $tipoActivo;
    if ($obraIdGF) {
      try {
        $stON = $pdo->prepare("SELECT nombre FROM obras WHERE id = ?");
        $stON->execute([$obraIdGF]);
        $obraNombreGF = (string)$stON->fetchColumn();
        $catId = (int)($_POST['gf_categoria_id'] ?? 0);
        $catNom = '';
        if ($catId) {
          $stCN = $pdo->prepare("SELECT nombre FROM gastos_faena_categorias WHERE id = ?");
          $stCN->execute([$catId]);
          $catNom = (string)$stCN->fetchColumn();
        }
        $monto = (float)str_replace(['.',','], ['','.'], $_POST['gf_monto'] ?? '0');

        // Procesar archivo adjunto (factura/comprobante)
        $tieneArchivo = 0;
        $archInfo = null;
        if (!empty($_FILES['gf_factura']['name']) && $_FILES['gf_factura']['error'] === UPLOAD_ERR_OK) {
          $dirGF = __DIR__ . '/uploads/gastos_faena';
          if (!is_dir($dirGF)) mkdir($dirGF, 0775, true);
          $orig = $_FILES['gf_factura']['name'];
          $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
          if (in_array($ext, ['pdf','jpg','jpeg','png','webp'])) {
            $nuevo = 'gf_'.time().'_'.uniqid().'.'.$ext;
            $dest = $dirGF.'/'.$nuevo;
            if (move_uploaded_file($_FILES['gf_factura']['tmp_name'], $dest)) {
              $tieneArchivo = 1;
              $archInfo = [
                'nombre'    => $orig,
                'ruta'      => 'gastos_faena/'.$nuevo,
                'tipo_mime' => $_FILES['gf_factura']['type'] ?? '',
                'tamanio'   => filesize($dest) ?: 0,
              ];
            }
          }
        }

        $ocIdGF = !empty($_POST['gf_oc_id']) ? (int)$_POST['gf_oc_id'] : null;
        $pdo->prepare("INSERT INTO gastos_faena
          (fecha, obra_id, obra_nombre, categoria_id, categoria_nombre, descripcion, monto, proveedor, nro_documento, tipo_documento, oc_id, tiene_archivo, estado, registrado_por, registrado_por_nombre, creado_en)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now','localtime'))")
          ->execute([
            $_POST['gf_fecha'] ?: date('Y-m-d'),
            $obraIdGF, $obraNombreGF,
            $catId ?: null, $catNom ?: null,
            trim($_POST['gf_descripcion'] ?? ''),
            $monto,
            trim($_POST['gf_proveedor'] ?? ''),
            trim($_POST['gf_nro_documento'] ?? ''),
            trim($_POST['gf_tipo_documento'] ?? ''),
            $ocIdGF,
            $tieneArchivo,
            'aprobado',
            (int)($usuario['id'] ?? 0), (string)($usuario['nombre'] ?? '')
          ]);
        $gastoId = (int)$pdo->lastInsertId();
        if ($archInfo && $gastoId) {
          $pdo->prepare("INSERT INTO gastos_faena_archivos (gasto_id, nombre, ruta, tipo_mime, tamanio, subido_por, subido_en)
                         VALUES (?,?,?,?,?,?,datetime('now','localtime'))")
            ->execute([$gastoId, $archInfo['nombre'], $archInfo['ruta'], $archInfo['tipo_mime'], $archInfo['tamanio'], (int)($usuario['id'] ?? 0)]);
        }
        $mensaje = 'Gasto agregado al proyecto'.($archInfo ? ' (con factura adjunta)' : '').'.';
      } catch (Exception $e) {
        $mensaje = 'Error agregando gasto: '.$e->getMessage();
        $mensajeTipo = 'danger';
      }
    } else {
      $mensaje = 'Falta la obra.';
      $mensajeTipo = 'danger';
    }
    $redir = "estados_pago.php?tipo=$tipoGF&msg=" . urlencode($mensaje) . "&mt=" . ($mensajeTipo ?? 'success');
    if ($epIdGF) $redir .= "&edit=$epIdGF";
    $redir .= "#gastos-proyecto";
    header("Location: $redir");
    exit;
  }
  // Agregar factura emitida (cobro) directo desde la vista del proyecto
  if ($accion === 'agregar_factura_emitida') {
    $obraIdF  = (int)($_POST['ff_obra_id'] ?? 0);
    $epIdF    = (int)($_POST['ff_ep_id'] ?? 0);
    $tipoF    = $_POST['tipo'] ?? 'cobro_empresa';
    if ($obraIdF) {
      try {
        $stON = $pdo->prepare("SELECT codigo, nombre FROM obras WHERE id = ?");
        $stON->execute([$obraIdF]);
        $obraInfo = $stON->fetch(PDO::FETCH_ASSOC) ?: ['codigo'=>'','nombre'=>''];
        // Tomar el cliente y rut del registro padre (si existe) para mantener consistencia
        $contraparte = trim($_POST['ff_contraparte'] ?? '');
        $contraparteRut = trim($_POST['ff_rut'] ?? '');
        if ((!$contraparte || !$contraparteRut) && $epIdF) {
          $stP = $pdo->prepare("SELECT contraparte, contraparte_rut, cot_id FROM estados_pago WHERE id = ?");
          $stP->execute([$epIdF]);
          if ($pr = $stP->fetch(PDO::FETCH_ASSOC)) {
            if (!$contraparte) $contraparte = (string)$pr['contraparte'];
            if (!$contraparteRut) $contraparteRut = (string)$pr['contraparte_rut'];
            $cotIdF = (int)$pr['cot_id'];
          }
        } else { $cotIdF = (int)($_POST['ff_cot_id'] ?? 0); }
        $monto = (float)str_replace(['.',','], ['','.'], $_POST['ff_monto'] ?? '0');
        $iva = round($monto * 0.19);
        $neto = $monto - $iva;
        // Si el usuario indicó si el monto es neto o total
        if (($_POST['ff_monto_tipo'] ?? 'total') === 'neto') {
          $neto = $monto;
          $iva  = round($monto * 0.19);
          $monto = $neto + $iva;
        }
        // Procesar archivo de factura
        $facturaRuta = ''; $facturaNombre = '';
        if (!empty($_FILES['ff_factura']['name']) && $_FILES['ff_factura']['error'] === UPLOAD_ERR_OK) {
          $dirFact = __DIR__ . '/uploads/facturas';
          if (!is_dir($dirFact)) mkdir($dirFact, 0775, true);
          $orig = $_FILES['ff_factura']['name'];
          $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
          if (in_array($ext, ['pdf','jpg','jpeg','png','webp'])) {
            $nuevo = 'ep_'.time().'_'.uniqid().'.'.$ext;
            if (move_uploaded_file($_FILES['ff_factura']['tmp_name'], $dirFact.'/'.$nuevo)) {
              $facturaRuta   = 'facturas/'.$nuevo;
              $facturaNombre = $orig;
            }
          }
        }

        $pdo->prepare("INSERT INTO estados_pago
          (tipo, fecha, obra_id, obra_codigo, obra_nombre, contraparte, contraparte_rut, numero_documento,
           descripcion, monto_neto, iva, monto_total, fecha_pago,
           estado, forma_pago, observaciones, oc_id, cot_id, oc_ext_id, periodo_desde, periodo_hasta, creado_por,
           factura_ruta, factura_nombre)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([
            $tipoF,
            $_POST['ff_fecha'] ?: date('Y-m-d'),
            $obraIdF, $obraInfo['codigo'], $obraInfo['nombre'],
            $contraparte, $contraparteRut,
            trim($_POST['ff_numero'] ?? ''),
            trim($_POST['ff_descripcion'] ?? ''),
            $neto, $iva, $monto,
            null,
            'pendiente', '', '',
            null, $cotIdF ?: null, null,
            $_POST['ff_desde'] ?: null,
            $_POST['ff_hasta'] ?: null,
            (string)($usuario['nombre'] ?? ''),
            $facturaRuta, $facturaNombre
          ]);
        $mensaje = 'Factura emitida agregada al proyecto.';
      } catch (Exception $e) {
        $mensaje = 'Error: '.$e->getMessage();
        $mensajeTipo = 'danger';
      }
    } else {
      $mensaje = 'Falta la obra del proyecto.';
      $mensajeTipo = 'danger';
    }
    $redir = "estados_pago.php?tipo=$tipoF&msg=" . urlencode($mensaje) . "&mt=" . ($mensajeTipo ?? 'success');
    if ($epIdF) $redir .= "&edit=$epIdF";
    $redir .= "#facturas-proyecto";
    header("Location: $redir");
    exit;
  }
  // Editar gasto de faena existente
  if ($accion === 'editar_gasto_faena') {
    $gastoId = (int)($_POST['gf_id'] ?? 0);
    $epIdGF  = (int)($_POST['gf_ep_id'] ?? 0);
    $tipoGF  = $_POST['tipo'] ?? $tipoActivo;
    $mensaje = ''; $mensajeTipo = 'success';
    if ($gastoId) {
      try {
        $catId = (int)($_POST['gf_categoria_id'] ?? 0);
        $catNom = '';
        if ($catId) {
          $stCN = $pdo->prepare("SELECT nombre FROM gastos_faena_categorias WHERE id = ?");
          $stCN->execute([$catId]);
          $catNom = (string)$stCN->fetchColumn();
        }
        $monto = (float)str_replace(['.',','], ['','.'], $_POST['gf_monto'] ?? '0');
        $ocIdGF = !empty($_POST['gf_oc_id']) ? (int)$_POST['gf_oc_id'] : null;

        // Archivo nuevo opcional
        $sqlExtra = ''; $paramsExtra = [];
        if (!empty($_FILES['gf_factura']['name']) && $_FILES['gf_factura']['error'] === UPLOAD_ERR_OK) {
          $dirGF = __DIR__ . '/uploads/gastos_faena';
          if (!is_dir($dirGF)) mkdir($dirGF, 0775, true);
          $orig = $_FILES['gf_factura']['name'];
          $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
          if (in_array($ext, ['pdf','jpg','jpeg','png','webp'])) {
            $nuevo = 'gf_'.time().'_'.uniqid().'.'.$ext;
            $dest = $dirGF.'/'.$nuevo;
            if (move_uploaded_file($_FILES['gf_factura']['tmp_name'], $dest)) {
              $pdo->prepare("INSERT INTO gastos_faena_archivos (gasto_id, nombre, ruta, tipo_mime, tamanio, subido_por, subido_en)
                             VALUES (?,?,?,?,?,?,datetime('now','localtime'))")
                ->execute([$gastoId, $orig, 'gastos_faena/'.$nuevo, $_FILES['gf_factura']['type'] ?? '', filesize($dest) ?: 0, (int)($usuario['id'] ?? 0)]);
              $sqlExtra = ', tiene_archivo = 1';
            }
          }
        }

        $pdo->prepare("UPDATE gastos_faena SET
            fecha = ?, categoria_id = ?, categoria_nombre = ?,
            descripcion = ?, monto = ?, proveedor = ?,
            nro_documento = ?, tipo_documento = ?, oc_id = ?
            $sqlExtra
          WHERE id = ?")
          ->execute([
            $_POST['gf_fecha'] ?: date('Y-m-d'),
            $catId ?: null, $catNom ?: null,
            trim($_POST['gf_descripcion'] ?? ''),
            $monto,
            trim($_POST['gf_proveedor'] ?? ''),
            trim($_POST['gf_nro_documento'] ?? ''),
            trim($_POST['gf_tipo_documento'] ?? ''),
            $ocIdGF,
            $gastoId
          ]);
        $mensaje = 'Gasto actualizado.';
      } catch (Exception $e) {
        $mensaje = 'Error: '.$e->getMessage();
        $mensajeTipo = 'danger';
      }
    } else {
      $mensaje = 'Falta el ID del gasto.';
      $mensajeTipo = 'danger';
    }
    $redir = "estados_pago.php?tipo=$tipoGF&msg=" . urlencode($mensaje) . "&mt=$mensajeTipo";
    if ($epIdGF) $redir .= "&edit=$epIdGF";
    $redir .= "#gastos-proyecto";
    header("Location: $redir");
    exit;
  }
  // Eliminar gasto de faena
  if ($accion === 'eliminar_gasto_faena') {
    $gastoId = (int)($_POST['gf_id'] ?? 0);
    $epIdGF  = (int)($_POST['gf_ep_id'] ?? 0);
    $tipoGF  = $_POST['tipo'] ?? $tipoActivo;
    $mensaje = ''; $mensajeTipo = 'success';
    if ($gastoId) {
      try {
        $pdo->prepare("DELETE FROM gastos_faena_archivos WHERE gasto_id = ?")->execute([$gastoId]);
        $pdo->prepare("DELETE FROM gastos_faena WHERE id = ?")->execute([$gastoId]);
        $mensaje = 'Gasto eliminado.';
      } catch (Exception $e) {
        $mensaje = 'Error eliminando: '.$e->getMessage();
        $mensajeTipo = 'danger';
      }
    }
    $redir = "estados_pago.php?tipo=$tipoGF&msg=" . urlencode($mensaje) . "&mt=$mensajeTipo";
    if ($epIdGF) $redir .= "&edit=$epIdGF";
    $redir .= "#gastos-proyecto";
    header("Location: $redir");
    exit;
  }
  try {
    if ($accion === 'crear' || $accion === 'editar') {
      $tipo = $_POST['tipo'] ?? $tipoActivo;
      if (!isset($tiposValidos[$tipo])) throw new Exception('Tipo inválido');

      $datos = [
        'fecha'             => $_POST['fecha'] ?? date('Y-m-d'),
        'obra_id'           => !empty($_POST['obra_id']) ? (int)$_POST['obra_id'] : null,
        'obra_codigo'       => trim($_POST['obra_codigo'] ?? ''),
        'obra_nombre'       => trim($_POST['obra_nombre'] ?? ''),
        'contraparte'       => trim($_POST['contraparte'] ?? ''),
        'contraparte_rut'   => trim($_POST['contraparte_rut'] ?? ''),
        'numero_documento'  => trim($_POST['numero_documento'] ?? ''),
        'descripcion'       => trim($_POST['descripcion'] ?? ''),
        'monto_neto'        => (float)str_replace(['.', ','], ['', '.'], $_POST['monto_neto'] ?? '0'),
        'iva'               => (float)str_replace(['.', ','], ['', '.'], $_POST['iva'] ?? '0'),
        'monto_total'       => (float)str_replace(['.', ','], ['', '.'], $_POST['monto_total'] ?? '0'),
        'fecha_vencimiento' => $_POST['fecha_vencimiento'] ?? null,
        'fecha_pago'        => $_POST['fecha_pago'] ?? null,
        'estado'            => $_POST['estado'] ?? 'pendiente',
        'forma_pago'        => trim($_POST['forma_pago'] ?? ''),
        'observaciones'     => trim($_POST['observaciones'] ?? ''),
        'periodo_desde'     => $_POST['periodo_desde'] ?? null,
        'periodo_hasta'     => $_POST['periodo_hasta'] ?? null,
      ];
      if ($datos['monto_total'] <= 0 && $datos['monto_neto'] > 0) {
        $datos['iva'] = round($datos['monto_neto'] * 0.19);
        $datos['monto_total'] = $datos['monto_neto'] + $datos['iva'];
      }

      $rawOc = $_POST['oc_id'] ?? '';
      $datos['oc_ext_id'] = null;
      if (preg_match('/^ext_(\d+)$/', $rawOc, $mExt)) {
          $datos['oc_id'] = null;
          $datos['oc_ext_id'] = (int)$mExt[1];
      } else {
          $datos['oc_id']  = !empty($rawOc) ? (int)$rawOc : null;
      }
      $datos['cot_id'] = !empty($_POST['cot_id']) ? (int)$_POST['cot_id'] : null;

      // Subir archivo adjunto (factura / comprobante)
      $facturaRuta = ''; $facturaNombre = '';
      if (!empty($_FILES['factura']['name']) && $_FILES['factura']['error'] === UPLOAD_ERR_OK) {
          $dirFact = __DIR__ . '/uploads/facturas';
          if (!is_dir($dirFact)) mkdir($dirFact, 0775, true);
          $orig = $_FILES['factura']['name'];
          $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
          if (in_array($ext, ['pdf','jpg','jpeg','png','webp'])) {
              $nuevo = 'ep_'.time().'_'.uniqid().'.'.$ext;
              if (move_uploaded_file($_FILES['factura']['tmp_name'], $dirFact.'/'.$nuevo)) {
                  $facturaRuta   = 'facturas/'.$nuevo;
                  $facturaNombre = $orig;
              }
          }
      }

      if ($accion === 'crear') {
        $pdo->prepare("INSERT INTO estados_pago
          (tipo, fecha, obra_id, obra_codigo, obra_nombre, contraparte, contraparte_rut, numero_documento,
           descripcion, monto_neto, iva, monto_total, fecha_vencimiento, fecha_pago,
           estado, forma_pago, observaciones, oc_id, cot_id, oc_ext_id, periodo_desde, periodo_hasta, creado_por,
           factura_ruta, factura_nombre)
          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
          ->execute([
            $tipo, $datos['fecha'], $datos['obra_id'], $datos['obra_codigo'], $datos['obra_nombre'], $datos['contraparte'],
            $datos['contraparte_rut'], $datos['numero_documento'], $datos['descripcion'],
            $datos['monto_neto'], $datos['iva'], $datos['monto_total'],
            $datos['fecha_vencimiento'] ?: null, $datos['fecha_pago'] ?: null,
            $datos['estado'], $datos['forma_pago'], $datos['observaciones'],
            $datos['oc_id'], $datos['cot_id'], $datos['oc_ext_id'],
            $datos['periodo_desde'] ?: null, $datos['periodo_hasta'] ?: null,
            $usuario['nombre'],
            $facturaRuta, $facturaNombre
          ]);
        $mensaje = 'Registro creado exitosamente';
      } else {
        $idEdit = (int)($_POST['id'] ?? 0);
        $sqlUpd = "UPDATE estados_pago SET
          fecha=?, obra_id=?, obra_codigo=?, obra_nombre=?, contraparte=?, contraparte_rut=?, numero_documento=?,
          descripcion=?, monto_neto=?, iva=?, monto_total=?, fecha_vencimiento=?, fecha_pago=?,
          estado=?, forma_pago=?, observaciones=?, oc_id=?, cot_id=?, oc_ext_id=?, periodo_desde=?, periodo_hasta=?";
        $paramsUpd = [
            $datos['fecha'], $datos['obra_id'], $datos['obra_codigo'], $datos['obra_nombre'], $datos['contraparte'],
            $datos['contraparte_rut'], $datos['numero_documento'], $datos['descripcion'],
            $datos['monto_neto'], $datos['iva'], $datos['monto_total'],
            $datos['fecha_vencimiento'] ?: null, $datos['fecha_pago'] ?: null,
            $datos['estado'], $datos['forma_pago'], $datos['observaciones'],
            $datos['oc_id'], $datos['cot_id'], $datos['oc_ext_id'],
            $datos['periodo_desde'] ?: null, $datos['periodo_hasta'] ?: null
        ];
        if ($facturaRuta) {
            $sqlUpd .= ", factura_ruta=?, factura_nombre=?";
            $paramsUpd[] = $facturaRuta;
            $paramsUpd[] = $facturaNombre;
        }
        $sqlUpd .= " WHERE id=?";
        $paramsUpd[] = $idEdit;
        $pdo->prepare($sqlUpd)->execute($paramsUpd);
        $mensaje = 'Registro actualizado exitosamente';
      }
    } elseif ($accion === 'eliminar') {
      $idDel = (int)($_POST['id'] ?? 0);
      $pdo->prepare("DELETE FROM estados_pago WHERE id = ?")->execute([$idDel]);
      $mensaje = 'Registro eliminado';
    } elseif ($accion === 'marcar_pagado') {
      $idM = (int)($_POST['id'] ?? 0);
      $pdo->prepare("UPDATE estados_pago SET estado=?, fecha_pago=? WHERE id=?")
        ->execute([$cfg['sentido'] === 'cobro' ? 'cobrado' : 'pagado', date('Y-m-d'), $idM]);
      $mensaje = $cfg['sentido'] === 'cobro' ? 'Marcado como cobrado' : 'Marcado como pagado';
    }
  } catch (Exception $e) {
    $mensaje = 'Error: ' . $e->getMessage();
    $mensajeTipo = 'danger';
  }
  $continuar = !empty($_POST['guardar_continuar']) ? '&nuevo=1' : '';
  header("Location: estados_pago.php?tipo=$tipoActivo$continuar&msg=" . urlencode($mensaje) . "&mt=$mensajeTipo");
  exit;
}

if (!empty($_GET['msg'])) {
  $mensaje = $_GET['msg'];
  $mensajeTipo = $_GET['mt'] ?? 'success';
}

/* ============= FILTROS ============= */
$filtroEstado = $_GET['estado'] ?? '';
$filtroMes    = $_GET['mes']    ?? '';
$filtroAnio   = $_GET['anio']   ?? '';
$filtroBusca  = trim($_GET['q'] ?? '');

$where = ['tipo = ?'];
$params = [$tipoActivo];
if ($filtroEstado !== '') { $where[] = 'estado = ?'; $params[] = $filtroEstado; }
if ($filtroMes !== '')    { $where[] = "strftime('%m', fecha) = ?"; $params[] = str_pad($filtroMes, 2, '0', STR_PAD_LEFT); }
if ($filtroAnio !== '')   { $where[] = "strftime('%Y', fecha) = ?"; $params[] = $filtroAnio; }
if ($filtroBusca !== '')  { $where[] = '(contraparte LIKE ? OR obra_nombre LIKE ? OR numero_documento LIKE ? OR descripcion LIKE ?)';
                            $bq = "%$filtroBusca%"; array_push($params, $bq, $bq, $bq, $bq); }

$sql = "SELECT * FROM estados_pago WHERE ".implode(' AND ', $where)." ORDER BY fecha DESC, id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$registros = $st->fetchAll(PDO::FETCH_ASSOC);

/* Totales */
$totPendiente = 0; $totListo = 0; $totVencido = 0; $cantidad = count($registros);
foreach ($registros as $r) {
  if ($r['estado'] === 'pendiente') $totPendiente += $r['monto_total'];
  elseif (in_array($r['estado'], ['pagado','cobrado'])) $totListo += $r['monto_total'];
  elseif ($r['estado'] === 'vencido') $totVencido += $r['monto_total'];
}

/* Monto del proyecto por registro = OC externa vinculada (oc_ext_id) o sum de externas
   ligadas a la misma cotización si no se asoció una específica */
$ocExtMontoMap = []; // ep_id => monto del proyecto
foreach ($registros as $r) {
  $monto = 0;
  if (!empty($r['oc_ext_id'])) {
    try {
      $st = $pdo->prepare("SELECT oc_ext_monto FROM cot_oc_asociaciones WHERE id = ?");
      $st->execute([(int)$r['oc_ext_id']]);
      $monto = (float)$st->fetchColumn();
    } catch (Exception $e) { $monto = 0; }
  } elseif (!empty($r['cot_id'])) {
    try {
      $st = $pdo->prepare("SELECT COALESCE(SUM(oc_ext_monto),0) FROM cot_oc_asociaciones WHERE cot_id = ? AND es_externa = 1");
      $st->execute([(int)$r['cot_id']]);
      $monto = (float)$st->fetchColumn();
    } catch (Exception $e) { $monto = 0; }
  }
  $ocExtMontoMap[(int)$r['id']] = $monto;
}

/* Para selector de obras y contrapartes — anota cada obra con la última cotización adjudicada */
try {
  $obrasList = $pdo->query("
      SELECT o.id, o.codigo, o.nombre, o.mandante, o.ciudad,
             MAX(c.fecha) AS ultima_adj_fecha,
             COUNT(c.id) AS adj_count
      FROM obras o
      LEFT JOIN cotizaciones c ON c.obra_id = o.id AND c.estado = 'adjudicada'
      WHERE o.estado = 'activa'
      GROUP BY o.id
      ORDER BY (ultima_adj_fecha IS NULL), ultima_adj_fecha DESC, o.codigo
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $obrasList = []; }
// Identificar la obra con la cotización adjudicada más reciente
$ultimaAdjObraId = 0;
foreach ($obrasList as $o) {
  if (!empty($o['ultima_adj_fecha'])) { $ultimaAdjObraId = (int)$o['id']; break; }
}

try {
  if ($cfg['sentido'] === 'pago') {
    $contraList = $pdo->query("SELECT nombre, rut FROM proveedores ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $contraList = $pdo->query("SELECT nombre, rut FROM clientes ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Exception $e) { $contraList = []; }

/* Lista de OCs y Cotizaciones disponibles para vincular (opcional, en todos los tipos) */
$ocsDisponibles = [];
try {
  $ocsDisponibles = $pdo->query("SELECT id, numero, proveedor_nombre, obra, total, fecha
                                 FROM ordenes_compra
                                 ORDER BY fecha DESC, id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $ocsDisponibles = []; }

/* OC vinculadas por cotización (vía cot_oc_asociaciones), para autocargar al elegir cot */
$cotOcLinks = [];
try {
  $stmtCOL = $pdo->query("
      SELECT a.id, a.cot_id, a.es_externa, a.oc_id,
             a.oc_ext_numero, a.oc_ext_empresa, a.oc_ext_monto,
             oc.numero AS oc_numero, oc.proveedor_nombre, oc.total AS oc_total
      FROM cot_oc_asociaciones a
      LEFT JOIN ordenes_compra oc ON a.oc_id = oc.id AND a.es_externa = 0
      ORDER BY a.fecha DESC, a.id DESC
  ");
  while ($r = $stmtCOL->fetch(PDO::FETCH_ASSOC)) {
    $cotOcLinks[$r['cot_id']][] = $r;
  }
} catch (Exception $e) { $cotOcLinks = []; }

/* Items de cotizaciones, agrupados por cot_id, para autocompletar al elegir una cotización */
$cotItemsMap = [];
try {
  $stmtCI = $pdo->query("SELECT cot_id, descripcion, cantidad, unidad, precio, total
                          FROM cot_items
                          WHERE cot_id IN (SELECT id FROM cotizaciones ORDER BY fecha DESC, id DESC LIMIT 300)
                          ORDER BY id");
  while ($r = $stmtCI->fetch(PDO::FETCH_ASSOC)) {
    $cotItemsMap[$r['cot_id']][] = $r;
  }
} catch (Exception $e) { $cotItemsMap = []; }

/* Lista de OC externas (subidas en seguimiento_cot.php) */
$ocsExternas = [];
try {
  $ocsExternas = $pdo->query("SELECT a.id, a.oc_ext_numero, a.oc_ext_empresa, a.oc_ext_monto, c.cliente_obra, c.id AS cot_id, c.obra_id
                              FROM cot_oc_asociaciones a
                              JOIN cotizaciones c ON c.id = a.cot_id
                              WHERE a.es_externa = 1
                              ORDER BY a.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $ocsExternas = []; }

$cotsDisponibles = [];
try {
  $cotsDisponibles = $pdo->query("SELECT id, numero, cliente_nombre, cliente_obra, cliente_rut, total, fecha, obra_id, estado
                                  FROM cotizaciones
                                  WHERE estado = 'adjudicada'
                                  ORDER BY fecha DESC, id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $cotsDisponibles = []; }

/* Editar */
$registroEditar = null;
if (!empty($_GET['edit'])) {
  $stE = $pdo->prepare("SELECT * FROM estados_pago WHERE id = ?");
  $stE->execute([(int)$_GET['edit']]);
  $registroEditar = $stE->fetch(PDO::FETCH_ASSOC);
}

if ($precarga && !$registroEditar) {
  $registroEditar = $precarga;
}

$mostrarForm = $registroEditar || isset($_GET['nuevo']) || $precarga;

require_once 'includes/header.php';
?>
<a href="/inicio.php" data-volver data-fallback="/inicio.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver</a>
<?php

function fmtMon($m) { return '$' . number_format((float)$m, 0, ',', '.'); }
function badgeEstado($e) {
  $map = [
    'pendiente' => ['warning', 'Pendiente', 'hourglass-split'],
    'pagado'    => ['success', 'Pagado',    'check-circle-fill'],
    'cobrado'   => ['success', 'Cobrado',   'check-circle-fill'],
    'vencido'   => ['danger',  'Vencido',   'exclamation-triangle-fill'],
    'anulado'   => ['secondary','Anulado',  'x-circle'],
    'parcial'   => ['info',    'Parcial',   'pie-chart-fill'],
  ];
  $m = $map[$e] ?? ['secondary', ucfirst($e), 'circle'];
  return '<span class="badge bg-'.$m[0].'"><i class="bi bi-'.$m[2].' me-1"></i>'.$m[1].'</span>';
}
?>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div>
    <h3 class="fw-bold mb-1"><i class="bi bi-cash-coin text-success me-2"></i>Estados de Pago</h3>
    <p class="text-muted mb-0">Gestión de cobros y pagos por obra y mes</p>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="estados_pago_reporte.php" class="btn btn-outline-dark">
      <i class="bi bi-graph-up me-1"></i>Reporte mensual
    </a>
    <?php
      $exportParams = $_GET;
      $exportParams['export'] = 'csv';
      $exportUrl = 'estados_pago.php?' . http_build_query($exportParams);
    ?>
    <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn btn-outline-success">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i>Excel
    </a>
    <button type="button" class="btn btn-outline-danger" onclick="window.print()">
      <i class="bi bi-printer me-1"></i>PDF / Imprimir
    </button>
    <a href="estados_pago.php?tipo=<?= $tipoActivo ?>&nuevo=1" class="btn btn-success">
      <i class="bi bi-plus-circle me-1"></i>Nuevo registro
    </a>
  </div>
</div>

<?php if ($mensaje): ?>
<div class="alert alert-<?= htmlspecialchars($mensajeTipo) ?> alert-dismissible fade show" role="alert">
  <?= htmlspecialchars($mensaje) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Sub-menú de tipos -->
<ul class="nav nav-pills estado-pago-nav mb-3 flex-wrap gap-1">
  <?php foreach ($tiposValidos as $k => $v):
    $active = $k === $tipoActivo ? 'active' : '';
  ?>
  <li class="nav-item">
    <a class="nav-link <?= $active ?> ep-tab ep-tab-<?= $v['color'] ?>"
       href="estados_pago.php?tipo=<?= $k ?>">
      <i class="bi <?= $v['icono'] ?> me-1"></i><?= $v['titulo'] ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<?php
  // Buscar obra del registro abierto (si lo hay) para mostrar su ID al lado del card de Registros
  $obraIdHeader  = (int)($registroEditar['obra_id'] ?? 0);
  $obraCodHeader = $registroEditar['obra_codigo'] ?? '';
  $obraNomHeader = $registroEditar['obra_nombre'] ?? '';
  if (!$obraIdHeader && ($obraCodHeader || $obraNomHeader)) {
    try {
      $stOH = $pdo->prepare("SELECT id FROM obras WHERE codigo = ? OR nombre = ? LIMIT 1");
      $stOH->execute([$obraCodHeader, $obraNomHeader]);
      $obraIdHeader = (int)$stOH->fetchColumn();
    } catch (Exception $e) {}
  }
?>
<!-- Resumen global: registros + obra del registro abierto -->
<div class="row g-3 mb-3">
  <div class="col-md-3 col-6">
    <div class="card border-0 shadow-sm">
      <div class="card-body py-3">
        <small class="text-muted">Registros</small>
        <h4 class="mb-0 fw-bold"><?= $cantidad ?></h4>
      </div>
    </div>
  </div>
  <?php if ($obraIdHeader): ?>
  <div class="col-md-9 col-12">
    <div class="card border-primary border-2 shadow-sm h-100">
      <div class="card-body py-3 d-flex flex-wrap align-items-center gap-3">
        <span class="d-inline-flex align-items-center px-3 py-2 fw-bold text-white"
              style="background:#0d6efd;border-radius:10px;font-size:1.4rem;letter-spacing:.5px"
              title="ID único de la obra. Vincula todas las OC, gastos, tickets, facturas y cotizaciones.">
          <i class="bi bi-hash me-1"></i>ID OBRA: <?= $obraIdHeader ?>
        </span>
        <?php if (!empty($obraCodHeader)): ?>
        <span class="badge bg-dark" style="font-size:1rem;padding:.5rem .75rem">
          <i class="bi bi-tag-fill me-1"></i><?= htmlspecialchars($obraCodHeader) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($obraNomHeader)): ?>
        <span class="fw-semibold text-dark"><i class="bi bi-building me-1"></i><?= htmlspecialchars($obraNomHeader) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if ($mostrarForm): ?>
<?php
// Cards por proyecto (cuando hay registro abierto, sea edición o nuevo con datos)
$resumenProy = ['monto_proy'=>0,'pend'=>0,'cobr'=>0,'venc'=>0,'oc_servicios'=>0,'comb_litros'=>0,'comb_obras'=>0,'gastos_faena'=>0,'gastos_faena_qty'=>0,'gastos_recientes'=>[],'facturado'=>0,'facturas_qty'=>0,'facturas'=>[],'tickets_qty'=>0,'tickets_m3'=>0,'tickets_recientes'=>[],'tickets_por_material'=>[],'avance_material'=>[]];
$obraIdProy = (int)($registroEditar['obra_id'] ?? 0);
$obraCodProy = $registroEditar['obra_codigo'] ?? '';
$obraNomProy = $registroEditar['obra_nombre'] ?? '';
$cotIdProy = (int)($registroEditar['cot_id'] ?? 0);
$ocExtIdProy = (int)($registroEditar['oc_ext_id'] ?? 0);
// Fallback: si no hay obra_id pero hay obra_codigo o nombre, buscarlo en obras
if (!$obraIdProy && ($obraCodProy || $obraNomProy)) {
  try {
    $stOL = $pdo->prepare("SELECT id FROM obras WHERE codigo = ? OR nombre = ? LIMIT 1");
    $stOL->execute([$obraCodProy, $obraNomProy]);
    $obraIdProy = (int)$stOL->fetchColumn();
  } catch (Exception $e) {}
}
if ($obraIdProy || $obraCodProy || $obraNomProy || $cotIdProy) {
  try {
    // Monto del proyecto: OC externa específica, o suma por cot_id
    if ($ocExtIdProy) {
      $st = $pdo->prepare("SELECT oc_ext_monto FROM cot_oc_asociaciones WHERE id = ?");
      $st->execute([$ocExtIdProy]);
      $resumenProy['monto_proy'] = (float)$st->fetchColumn();
    } elseif ($cotIdProy) {
      $st = $pdo->prepare("SELECT COALESCE(SUM(oc_ext_monto),0) FROM cot_oc_asociaciones WHERE cot_id = ? AND es_externa = 1");
      $st->execute([$cotIdProy]);
      $resumenProy['monto_proy'] = (float)$st->fetchColumn();
    }
    // Estados de pago del mismo proyecto (mismo obra_codigo o cot_id) y facturas emitidas
    $w = []; $p = [];
    if ($obraCodProy) { $w[] = "obra_codigo = ?"; $p[] = $obraCodProy; }
    elseif ($cotIdProy) { $w[] = "cot_id = ?"; $p[] = $cotIdProy; }
    if ($w) {
      $sqlR = "SELECT id, fecha, periodo_desde, periodo_hasta, numero_documento, monto_total, estado, tipo, factura_ruta, factura_nombre, descripcion FROM estados_pago WHERE ".implode(' AND ', $w)." ORDER BY fecha DESC, id DESC";
      $stR = $pdo->prepare($sqlR);
      $stR->execute($p);
      foreach ($stR->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['estado'] === 'pendiente') $resumenProy['pend'] += $row['monto_total'];
        elseif (in_array($row['estado'], ['pagado','cobrado'])) $resumenProy['cobr'] += $row['monto_total'];
        elseif ($row['estado'] === 'vencido') $resumenProy['venc'] += $row['monto_total'];
        // Facturado = todos los cobros del proyecto (tipos cobro_*) excepto anulados
        if (strpos($row['tipo'], 'cobro') === 0 && $row['estado'] !== 'anulado') {
          $resumenProy['facturado'] += (float)$row['monto_total'];
          $resumenProy['facturas_qty']++;
          $resumenProy['facturas'][] = $row;
        }
      }
    }
    // OC del sistema (servicios) asociadas a la obra
    if ($obraIdProy || $obraCodProy) {
      $sqlOC = "SELECT COALESCE(SUM(total),0) FROM ordenes_compra WHERE 1=1";
      $pOC = [];
      if ($obraIdProy) { $sqlOC .= " AND obra_id = ?"; $pOC[] = $obraIdProy; }
      else { $sqlOC .= " AND obra_codigo = ?"; $pOC[] = $obraCodProy; }
      $stOC = $pdo->prepare($sqlOC);
      $stOC->execute($pOC);
      $resumenProy['oc_servicios'] = (float)$stOC->fetchColumn();
    }
    // Combustible: sumar litros agrupados por tipo (diesel / gasolina) y calcular monto
    if ($obraIdProy) {
      $stCB = $pdo->prepare("SELECT h.tipo_combustible AS tipo,
                                    COALESCE(SUM(v.cantidad_litros),0) AS lts,
                                    COUNT(DISTINCT h.id) AS hojas
                             FROM combustible_hojas h
                             LEFT JOIN combustible_vales v ON v.hoja_id = h.id
                             WHERE h.obra_id = ?
                             GROUP BY h.tipo_combustible");
      $stCB->execute([$obraIdProy]);
      $resumenProy['comb_por_tipo'] = $stCB->fetchAll(PDO::FETCH_ASSOC);
      $resumenProy['comb_monto'] = 0;
      $preciosCB = ['diesel'=>1100, 'gasolina'=>1300, 'petroleo'=>1100];
      foreach ($resumenProy['comb_por_tipo'] as $t) {
        $resumenProy['comb_litros'] += (float)$t['lts'];
        $resumenProy['comb_obras']  += (int)$t['hojas'];
        $tk = strtolower(trim($t['tipo'] ?? '')) ?: 'diesel';
        $resumenProy['comb_monto']  += (float)$t['lts'] * ($preciosCB[$tk] ?? 1100);
      }
    }
    // Gastos por Obra (gastos_faena) asociados a la obra
    if ($obraIdProy) {
      $stGF = $pdo->prepare("SELECT COALESCE(SUM(monto + COALESCE(monto_peaje,0)), 0) AS total, COUNT(*) AS qty FROM gastos_faena WHERE obra_id = ?");
      $stGF->execute([$obraIdProy]);
      $rowGF = $stGF->fetch(PDO::FETCH_ASSOC);
      $resumenProy['gastos_faena']     = (float)($rowGF['total'] ?? 0);
      $resumenProy['gastos_faena_qty'] = (int)($rowGF['qty'] ?? 0);
      // Lista corta de los últimos gastos
      $stGFR = $pdo->prepare("SELECT g.id, g.fecha, g.categoria_nombre, g.descripcion, g.monto, g.monto_peaje, g.proveedor, g.tiene_archivo, g.oc_id,
                                     oc.numero AS oc_numero,
                                     (SELECT ruta FROM gastos_faena_archivos WHERE gasto_id = g.id ORDER BY id DESC LIMIT 1) AS arch_ruta,
                                     (SELECT nombre FROM gastos_faena_archivos WHERE gasto_id = g.id ORDER BY id DESC LIMIT 1) AS arch_nombre
                              FROM gastos_faena g
                              LEFT JOIN ordenes_compra oc ON oc.id = g.oc_id
                              WHERE g.obra_id = ?
                              ORDER BY g.fecha DESC, g.id DESC LIMIT 8");
      $stGFR->execute([$obraIdProy]);
      $resumenProy['gastos_recientes'] = $stGFR->fetchAll(PDO::FETCH_ASSOC);

      // Tickets de despacho asociados a la obra
      $stTk = $pdo->prepare("SELECT id, fecha, hora, ppu, conductor_nombre, tipo_material, metros_cubicos, destino, estado, estado_revision
                             FROM tickets_despacho WHERE obra_id = ?
                             ORDER BY fecha DESC, hora DESC, id DESC LIMIT 12");
      $stTk->execute([$obraIdProy]);
      $resumenProy['tickets_recientes'] = $stTk->fetchAll(PDO::FETCH_ASSOC);
      $stTkAg = $pdo->prepare("SELECT COUNT(*) qty, COALESCE(SUM(metros_cubicos),0) m3 FROM tickets_despacho WHERE obra_id = ?");
      $stTkAg->execute([$obraIdProy]);
      $rowTk = $stTkAg->fetch(PDO::FETCH_ASSOC);
      $resumenProy['tickets_qty'] = (int)($rowTk['qty'] ?? 0);
      $resumenProy['tickets_m3']  = (float)($rowTk['m3'] ?? 0);
      $stTkM = $pdo->prepare("SELECT COALESCE(NULLIF(tipo_material,''),'(sin material)') AS mat,
                                     COUNT(*) AS qty, COALESCE(SUM(metros_cubicos),0) AS m3
                              FROM tickets_despacho WHERE obra_id = ?
                              GROUP BY tipo_material ORDER BY m3 DESC");
      $stTkM->execute([$obraIdProy]);
      $resumenProy['tickets_por_material'] = $stTkM->fetchAll(PDO::FETCH_ASSOC);

      // Avance del proyecto por material: comparar cot_items (cotizado) con tickets entregados
      $resumenProy['avance_material'] = [];
      if ($cotIdProy && !empty($cotItemsMap[$cotIdProy])) {
        $tickPorMat = [];
        $stTM2 = $pdo->prepare("SELECT LOWER(COALESCE(tipo_material,'')) AS mat, COALESCE(SUM(metros_cubicos),0) m3, COUNT(*) qty
                                FROM tickets_despacho WHERE obra_id = ? GROUP BY tipo_material");
        $stTM2->execute([$obraIdProy]);
        foreach ($stTM2->fetchAll(PDO::FETCH_ASSOC) as $tm) {
          $tickPorMat[$tm['mat']] = ['m3'=>(float)$tm['m3'], 'qty'=>(int)$tm['qty']];
        }
        $materialesLbl = [
          'tierra'=>'Tierra','integral'=>'Integral','bajo_6'=>'Bajo 6','bajo_4'=>'Bajo 4','bajo_3'=>'Bajo 3','bajo_2'=>'Bajo 2',
          'base_chancada'=>'Base Chancada','grava'=>'Grava','gravilla'=>'Gravilla','arena'=>'Arena','arena_tubo'=>'Arena Tubo',
          'escombros'=>'Escombros','bolones'=>'Bolones'
        ];
        foreach ($cotItemsMap[$cotIdProy] as $it) {
          $desc = strtolower((string)$it['descripcion']);
          $cantCot = (float)$it['cantidad'];
          $valorTotal = (float)$it['total'];
          $matchKey = null; $m3Entreg = 0; $tkQty = 0;
          foreach ($materialesLbl as $k => $lbl) {
            if (strpos($desc, $k) !== false || strpos($desc, strtolower($lbl)) !== false) {
              $matchKey = $k;
              if (isset($tickPorMat[$k])) { $m3Entreg = $tickPorMat[$k]['m3']; $tkQty = $tickPorMat[$k]['qty']; }
              break;
            }
          }
          $pct = $cantCot > 0 ? min(100, ($m3Entreg/$cantCot)*100) : 0;
          $valorEjecutado = $cantCot > 0 ? ($m3Entreg/$cantCot) * $valorTotal : 0;
          $valorPendiente = max(0, $valorTotal - $valorEjecutado);
          $resumenProy['avance_material'][] = [
            'descripcion'  => $it['descripcion'],
            'unidad'       => $it['unidad'],
            'cot_cant'     => $cantCot,
            'cot_total'    => $valorTotal,
            'cot_unit'     => (float)$it['precio'],
            'm3_entreg'    => $m3Entreg,
            'tk_qty'       => $tkQty,
            'pct'          => $pct,
            'val_ejec'     => $valorEjecutado,
            'val_pend'     => $valorPendiente,
            'material_key' => $matchKey,
            'material_lbl' => $matchKey ? $materialesLbl[$matchKey] : null,
          ];
        }
      }

      // Documentos del proyecto agrupados por categoría
      $resumenProy['archivos_categoria'] = [];
      // 1) Facturas de gastos de faena (agrupados por categoria_nombre)
      $stArchG = $pdo->prepare("SELECT a.id, a.nombre, a.ruta, a.tipo_mime, a.subido_en,
                                       g.descripcion AS contexto, g.fecha, g.categoria_nombre AS categoria
                                FROM gastos_faena_archivos a
                                JOIN gastos_faena g ON g.id = a.gasto_id
                                WHERE g.obra_id = ?
                                ORDER BY g.fecha DESC, a.id DESC");
      $stArchG->execute([$obraIdProy]);
      foreach ($stArchG->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $cat = $a['categoria'] ?: 'Gasto sin categoría';
        $resumenProy['archivos_categoria'][$cat][] = [
          'nombre'    => $a['nombre'],
          'ruta'      => 'uploads/'.$a['ruta'],
          'fecha'     => $a['fecha'],
          'contexto'  => $a['contexto'],
          'fuente'    => 'Gasto'
        ];
      }
      // 2) OC externa adjuntas (de cot_oc_asociaciones via cotizaciones de esta obra)
      $stArchOE = $pdo->prepare("SELECT a.id, a.oc_ext_archivo AS ruta, a.oc_ext_archivo_nombre AS nombre,
                                        a.oc_ext_numero, a.oc_ext_empresa, a.fecha
                                 FROM cot_oc_asociaciones a
                                 JOIN cotizaciones c ON c.id = a.cot_id
                                 WHERE a.es_externa = 1 AND a.oc_ext_archivo != '' AND c.obra_id = ?
                                 ORDER BY a.id DESC");
      $stArchOE->execute([$obraIdProy]);
      foreach ($stArchOE->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $resumenProy['archivos_categoria']['OC externa'][] = [
          'nombre'    => $a['nombre'] ?: basename($a['ruta']),
          'ruta'      => 'uploads/'.$a['ruta'],
          'fecha'     => $a['fecha'],
          'contexto'  => 'OC '.$a['oc_ext_numero'].' — '.$a['oc_ext_empresa'],
          'fuente'    => 'OC ext.'
        ];
      }
      // 3) Adjuntos de cotizaciones de la obra (cot_archivos)
      $stArchC = $pdo->prepare("SELECT a.id, a.nombre_original AS nombre, a.nombre_archivo, a.fecha,
                                       c.numero AS cot_numero
                                FROM cot_archivos a
                                JOIN cotizaciones c ON c.id = a.cot_id
                                WHERE c.obra_id = ?
                                ORDER BY a.fecha DESC LIMIT 50");
      $stArchC->execute([$obraIdProy]);
      foreach ($stArchC->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $resumenProy['archivos_categoria']['Cotización'][] = [
          'nombre'    => $a['nombre'],
          'ruta'      => 'uploads/'.$a['nombre_archivo'],
          'fecha'     => $a['fecha'],
          'contexto'  => 'COT N° '.$a['cot_numero'],
          'fuente'    => 'Cotización'
        ];
      }
    }
  } catch (Exception $e) {}
}
?>
<!-- FORMULARIO (plegable: arranca cerrado al editar, abierto al crear nuevo) -->
<?php $formularioOpen = !$registroEditar; ?>
<div class="card shadow-sm mb-4">
  <div class="card-header bg-<?= $cfg['color'] ?> text-white d-flex justify-content-between align-items-center"
       style="cursor:pointer" data-bs-toggle="collapse" data-bs-target="#cardFormularioBody"
       aria-expanded="<?= $formularioOpen ? 'true' : 'false' ?>" aria-controls="cardFormularioBody">
    <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i><?= $registroEditar ? 'Editar' : 'Nuevo' ?> — <?= $cfg['titulo'] ?></h5>
    <i class="bi bi-chevron-down" style="transition:transform .2s; transform:rotate(<?= $formularioOpen ? '180' : '0' ?>deg)" id="cardFormularioChevron"></i>
  </div>
  <div class="card-body collapse <?= $formularioOpen ? 'show' : '' ?>" id="cardFormularioBody">
    <form method="POST" action="estados_pago.php?tipo=<?= $tipoActivo ?>" class="row g-3">
      <input type="hidden" name="accion" value="<?= $registroEditar ? 'editar' : 'crear' ?>">
      <input type="hidden" name="tipo" value="<?= $tipoActivo ?>">
      <?php if ($registroEditar): ?>
      <input type="hidden" name="id" value="<?= $registroEditar['id'] ?>">
      <?php endif; ?>

      <div class="col-md-3">
        <label class="form-label fw-semibold">Fecha *</label>
        <input type="date" name="fecha" class="form-control" required value="<?= htmlspecialchars($registroEditar['fecha'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Documento N°</label>
        <input type="text" name="numero_documento" class="form-control" placeholder="Factura / EDP / Boleta" value="<?= htmlspecialchars($registroEditar['numero_documento'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold"><i class="bi bi-calendar-range me-1"></i>Periodo desde</label>
        <input type="date" name="periodo_desde" class="form-control" value="<?= htmlspecialchars($registroEditar['periodo_desde'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Periodo hasta</label>
        <input type="date" name="periodo_hasta" class="form-control" value="<?= htmlspecialchars($registroEditar['periodo_hasta'] ?? '') ?>">
      </div>
      <input type="hidden" name="estado" value="<?= htmlspecialchars($registroEditar['estado'] ?? 'pendiente') ?>">
      <input type="hidden" name="obra_id" id="ep_obra_id" value="<?= (int)($registroEditar['obra_id'] ?? 0) ?>">

      <div class="col-12">
        <div class="alert alert-light border mb-0 py-2 px-3">
          <small class="text-muted d-block mb-2">
            <i class="bi bi-link-45deg me-1"></i>
            <strong>Asociar documento (opcional)</strong> — Selecciona una OC <em>o</em> una Cotización existente para autocompletar los datos. Puedes dejar ambos vacíos.
          </small>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label small fw-semibold mb-1 text-primary">
                <i class="bi bi-receipt me-1"></i>Orden de Compra
              </label>
              <select name="oc_id" id="ep_oc_id" class="form-select form-select-sm">
                <option value="">— Sin vincular —</option>
                <?php if (!empty($ocsDisponibles)): ?>
                <optgroup label="OC del sistema">
                <?php foreach ($ocsDisponibles as $o):
                  $sel = (!empty($registroEditar['oc_id']) && (int)$registroEditar['oc_id'] === (int)$o['id']) ? 'selected' : '';
                ?>
                <option value="<?= $o['id'] ?>"
                        data-numero="<?= htmlspecialchars($o['numero']) ?>"
                        data-prov="<?= htmlspecialchars($o['proveedor_nombre']) ?>"
                        data-obra="<?= htmlspecialchars($o['obra']) ?>"
                        data-total="<?= (float)$o['total'] ?>"
                        data-tipo="interna"
                        <?= $sel ?>>
                  OC N° <?= htmlspecialchars($o['numero']) ?> — <?= htmlspecialchars($o['proveedor_nombre'] ?: 's/proveedor') ?> — $<?= number_format((float)$o['total'], 0, ',', '.') ?>
                </option>
                <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
                <?php if (!empty($ocsExternas)): ?>
                <optgroup label="OC empresa externa">
                <?php foreach ($ocsExternas as $oe): ?>
                <?php $selExt = (!empty($registroEditar['oc_ext_id']) && (int)$registroEditar['oc_ext_id'] === (int)$oe['id']) ? 'selected' : ''; ?>
                <option value="ext_<?= $oe['id'] ?>"
                        data-numero="<?= htmlspecialchars($oe['oc_ext_numero']) ?>"
                        data-prov="<?= htmlspecialchars($oe['oc_ext_empresa']) ?>"
                        data-obra="<?= htmlspecialchars($oe['cliente_obra']) ?>"
                        data-obra-id="<?= (int)($oe['obra_id'] ?? 0) ?>"
                        data-cot-id="<?= (int)($oe['cot_id'] ?? 0) ?>"
                        data-total="<?= (float)$oe['oc_ext_monto'] ?>"
                        data-tipo="externa"
                        <?= $selExt ?>>
                  OC ext. N° <?= htmlspecialchars($oe['oc_ext_numero']) ?> — <?= htmlspecialchars($oe['oc_ext_empresa']) ?> — $<?= number_format((float)$oe['oc_ext_monto'], 0, ',', '.') ?>
                </option>
                <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold mb-1 text-success">
                <i class="bi bi-file-earmark-text me-1"></i>Cotización
              </label>
              <select name="cot_id" id="ep_cot_id" class="form-select form-select-sm">
                <option value="">— Sin vincular —</option>
                <?php foreach ($cotsDisponibles as $c):
                  $sel = (!empty($registroEditar['cot_id']) && (int)$registroEditar['cot_id'] === (int)$c['id']) ? 'selected' : '';
                ?>
                <option value="<?= $c['id'] ?>"
                        data-numero="<?= htmlspecialchars($c['numero']) ?>"
                        data-cliente="<?= htmlspecialchars($c['cliente_nombre']) ?>"
                        data-rut="<?= htmlspecialchars($c['cliente_rut'] ?? '') ?>"
                        data-obra="<?= htmlspecialchars($c['cliente_obra']) ?>"
                        data-obra-id="<?= (int)($c['obra_id'] ?? 0) ?>"
                        data-total="<?= (float)$c['total'] ?>"
                        data-estado="<?= htmlspecialchars($c['estado'] ?? '') ?>"
                        <?= $sel ?>>
                  COT N° <?= htmlspecialchars($c['numero']) ?> — <?= htmlspecialchars($c['cliente_nombre']) ?> — $<?= number_format((float)$c['total'], 0, ',', '.') ?><?= $c['estado']==='adjudicada' ? ' [ADJUDICADA]' : '' ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-2">
        <label class="form-label fw-semibold">Cód. Obra</label>
        <input type="text" name="obra_codigo" id="ep_obra_codigo" class="form-control" value="<?= htmlspecialchars($registroEditar['obra_codigo'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Obra</label>
        <input type="text" name="obra_nombre" id="ep_obra_nombre" class="form-control" list="ep_listaObras" value="<?= htmlspecialchars($registroEditar['obra_nombre'] ?? '') ?>">
        <datalist id="ep_listaObras">
          <?php foreach ($obrasList as $o): ?>
          <option data-codigo="<?= htmlspecialchars($o['codigo']) ?>"
                  data-obra-id="<?= (int)$o['id'] ?>"
                  data-mandante="<?= htmlspecialchars($o['mandante'] ?? '') ?>"
                  value="<?= htmlspecialchars($o['nombre']) ?>"><?= htmlspecialchars($o['codigo']) ?></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold"><?= $cfg['contraparte_label'] ?> *</label>
        <input type="text" name="contraparte" id="ep_contraparte" class="form-control" required list="ep_listaContra" value="<?= htmlspecialchars($registroEditar['contraparte'] ?? '') ?>">
        <datalist id="ep_listaContra">
          <?php foreach ($contraList as $c): ?>
          <option data-rut="<?= htmlspecialchars($c['rut'] ?? '') ?>" value="<?= htmlspecialchars($c['nombre']) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-md-2">
        <label class="form-label fw-semibold">RUT</label>
        <input type="text" name="contraparte_rut" id="ep_contraparte_rut" class="form-control" value="<?= htmlspecialchars($registroEditar['contraparte_rut'] ?? '') ?>">
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Descripción</label>
        <input type="text" name="descripcion" id="ep_descripcion" class="form-control" value="<?= htmlspecialchars($registroEditar['descripcion'] ?? '') ?>">
      </div>

      <div class="col-12" id="ep_docs_obra_panel" style="display:none">
        <div class="card border-info-subtle">
          <div class="card-header bg-info-subtle py-2">
            <span class="fw-semibold small"><i class="bi bi-folder2-open me-1"></i>Documentos asociados a esta obra</span>
          </div>
          <div class="card-body p-2 small" id="ep_docs_obra_body"></div>
        </div>
      </div>

      <div class="col-md-3">
        <label class="form-label fw-semibold">Monto Neto</label>
        <input type="text" name="monto_neto" id="ep_neto" class="form-control text-end" value="<?= number_format((float)($registroEditar['monto_neto'] ?? 0), 0, ',', '.') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">IVA</label>
        <input type="text" name="iva" id="ep_iva" class="form-control text-end" value="<?= number_format((float)($registroEditar['iva'] ?? 0), 0, ',', '.') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Total</label>
        <input type="text" name="monto_total" id="ep_total" class="form-control text-end fw-bold" value="<?= number_format((float)($registroEditar['monto_total'] ?? 0), 0, ',', '.') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Fecha de <?= $cfg['sentido'] === 'cobro' ? 'cobro' : 'pago' ?></label>
        <input type="date" name="fecha_pago" class="form-control" value="<?= htmlspecialchars($registroEditar['fecha_pago'] ?? '') ?>">
      </div>

      <input type="hidden" name="forma_pago" value="<?= htmlspecialchars($registroEditar['forma_pago'] ?? '') ?>">
      <input type="hidden" name="observaciones" value="<?= htmlspecialchars($registroEditar['observaciones'] ?? '') ?>">

      <div class="col-12">
        <div class="alert alert-light border d-flex flex-wrap align-items-center justify-content-between gap-2 mb-0 py-2 px-3" style="background:#fff7ed;border-color:#fdba74">
          <small class="text-muted mb-0">
            <i class="bi bi-shield-check text-warning me-1"></i>
            Revisa los datos antes de guardar. Esta acción registra el estado de pago en la base de datos.
          </small>
          <div class="d-flex flex-wrap gap-2">
            <a href="estados_pago.php?tipo=<?= $tipoActivo ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle me-1"></i>Cancelar</a>
            <?php if (!$registroEditar): ?>
            <button type="submit" name="guardar_continuar" value="1"
                    onclick="return confirm('¿Guardar este registro y abrir uno nuevo para otra obra?')"
                    class="btn btn-sm btn-outline-<?= $cfg['color'] ?> fw-semibold">
              <i class="bi bi-save2 me-1"></i>Guardar y nueva obra
            </button>
            <?php endif; ?>
            <button type="submit" class="btn btn-lg btn-<?= $cfg['color'] ?> fw-bold px-4 shadow-sm"
                    onclick="return confirm('¿Confirmas guardar <?= $registroEditar ? 'los cambios de este registro' : 'este registro' ?>?\n\nUna vez guardado quedará registrado con todos los datos ingresados.')">
              <i class="bi bi-shield-check me-2"></i>
              <?= $registroEditar ? 'Guardar cambios' : 'Crear registro' ?>
            </button>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<?php if ($registroEditar || $resumenProy['monto_proy'] > 0):
  $saldoFacturar = max(0, $resumenProy['monto_proy'] - $resumenProy['facturado']);
  $pctFact = $resumenProy['monto_proy'] > 0 ? min(100, ($resumenProy['facturado']/$resumenProy['monto_proy'])*100) : 0;
  $totalGastosProy = $resumenProy['oc_servicios'] + $resumenProy['gastos_faena'] + ($resumenProy['comb_monto'] ?? 0);
  $margen = $resumenProy['facturado'] - $totalGastosProy;
?>
<!-- TODOS los datos de valores agrupados arriba -->
<div class="card shadow-sm mb-3">
  <div class="card-header bg-light py-2">
    <strong class="small"><i class="bi bi-cash-coin me-1"></i>Resumen económico del proyecto</strong>
  </div>
  <div class="card-body p-3">
    <!-- Fila 1: Datos principales del proyecto (OC ext, facturado, saldo, margen) -->
    <div class="row g-2 mb-2">
      <div class="col-md-3 col-6">
        <div class="card border-primary border-2"><div class="card-body py-2 px-3">
          <small class="text-primary">Monto Proyecto (OC)</small>
          <h5 class="mb-0 fw-bold"><?= fmtMon($resumenProy['monto_proy']) ?></h5>
        </div></div>
      </div>
      <div class="col-md-3 col-6">
        <div class="card border-success border-2"><div class="card-body py-2 px-3">
          <small class="text-success"><i class="bi bi-receipt me-1"></i>Facturado<?php if ($resumenProy['facturas_qty']): ?> <small class="text-muted">(<?= $resumenProy['facturas_qty'] ?>)</small><?php endif; ?></small>
          <h5 class="mb-0 fw-bold"><?= fmtMon($resumenProy['facturado']) ?></h5>
        </div></div>
      </div>
      <div class="col-md-3 col-6">
        <div class="card border-info border-2"><div class="card-body py-2 px-3">
          <small class="text-info">Saldo a facturar</small>
          <h5 class="mb-0 fw-bold"><?= fmtMon($saldoFacturar) ?></h5>
        </div></div>
      </div>
      <div class="col-md-3 col-6">
        <div class="card border-<?= $margen >= 0 ? 'success' : 'danger' ?> border-2"><div class="card-body py-2 px-3">
          <small class="text-<?= $margen >= 0 ? 'success' : 'danger' ?>"><i class="bi bi-graph-up me-1"></i>Margen (Fact. − Gastos)</small>
          <h5 class="mb-0 fw-bold"><?= fmtMon($margen) ?></h5>
        </div></div>
      </div>
    </div>

    <!-- Fila 2: Estados de cobro y todos los gastos (6 mini-cards) -->
    <div class="row g-2 mb-2">
      <div class="col-md-2 col-6">
        <div class="card border-warning border-2"><div class="card-body py-2 px-3">
          <small class="text-warning">Pendiente</small>
          <h6 class="mb-0 fw-bold"><?= fmtMon($resumenProy['pend']) ?></h6>
        </div></div>
      </div>
      <div class="col-md-2 col-6">
        <?php
          // "Cobrado" repurposed: muestra valor ejecutado según tickets entregados (m³ * precio cotizado)
          $valEjecutado = 0;
          foreach (($resumenProy['avance_material'] ?? []) as $am) { $valEjecutado += (float)$am['val_ejec']; }
        ?>
        <div class="card border-success border-2" title="Valor ejecutado en función de los m³ entregados por tickets vs. cotización (se descuenta automáticamente al llegar tickets)"><div class="card-body py-2 px-3">
          <small class="text-success"><i class="bi bi-truck-flatbed me-1"></i>Ejecutado (tickets)</small>
          <h6 class="mb-0 fw-bold"><?= fmtMon($valEjecutado) ?></h6>
          <?php if ($valEjecutado > 0): ?>
          <small class="text-muted" style="font-size:.65rem">Cobrado real: <?= fmtMon($resumenProy['cobr']) ?></small>
          <?php endif; ?>
        </div></div>
      </div>
      <div class="col-md-2 col-6">
        <div class="card border-secondary border-2"><div class="card-body py-2 px-3">
          <small class="text-secondary"><i class="bi bi-receipt me-1"></i>OC Sistema</small>
          <h6 class="mb-0 fw-bold"><?= fmtMon($resumenProy['oc_servicios']) ?></h6>
        </div></div>
      </div>
      <div class="col-md-2 col-6">
        <div class="card border-2" style="border-color:#d97706"><div class="card-body py-2 px-3">
          <small style="color:#d97706"><i class="bi bi-fuel-pump-fill me-1"></i>Combustible</small>
          <h6 class="mb-0 fw-bold"><?= fmtMon($resumenProy['comb_monto'] ?? 0) ?>
            <?php if ($resumenProy['comb_litros'] > 0): ?><small class="text-muted fw-normal" style="font-size:.55em">· <?= number_format($resumenProy['comb_litros'],0,',','.') ?> L</small><?php endif; ?>
          </h6>
        </div></div>
      </div>
      <div class="col-md-2 col-6">
        <div class="card border-2" style="border-color:#0d9488"><div class="card-body py-2 px-3">
          <small style="color:#0d9488"><i class="bi bi-truck-front-fill me-1"></i>Tickets</small>
          <h6 class="mb-0 fw-bold"><?= number_format($resumenProy['tickets_m3'], 1, ',', '.') ?> m³
            <?php if ($resumenProy['tickets_qty']): ?><small class="text-muted fw-normal" style="font-size:.55em">· <?= $resumenProy['tickets_qty'] ?></small><?php endif; ?>
          </h6>
          <?php if (!empty($resumenProy['tickets_por_material'])): ?>
          <div class="mt-1" style="font-size:.65rem;line-height:1.3">
            <?php foreach ($resumenProy['tickets_por_material'] as $tm): ?>
            <div class="text-truncate" title="<?= htmlspecialchars($tm['mat']) ?>: <?= number_format((float)$tm['m3'],1,',','.') ?> m³ · <?= (int)$tm['qty'] ?> ticket(s)">
              <span style="color:#0d9488">●</span> <strong><?= htmlspecialchars($tm['mat']) ?></strong>: <?= number_format((float)$tm['m3'],1,',','.') ?> m³
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div></div>
      </div>
      <div class="col-md-2 col-6">
        <div class="card border-info border-2"><div class="card-body py-2 px-3">
          <small class="text-info"><i class="bi bi-receipt-cutoff me-1"></i>Gastos por Obra</small>
          <h6 class="mb-0 fw-bold"><?= fmtMon($resumenProy['gastos_faena']) ?>
            <?php if ($resumenProy['gastos_faena_qty']): ?><small class="text-muted fw-normal" style="font-size:.55em">· <?= $resumenProy['gastos_faena_qty'] ?></small><?php endif; ?>
          </h6>
        </div></div>
      </div>
    </div>

    <?php if ($resumenProy['monto_proy'] > 0):
      // % de gastos consumidos sobre el monto del proyecto
      $pctGastos = ($totalGastosProy / max($resumenProy['monto_proy'], 0.01)) * 100;
      // Color de gastos según riesgo: <60% verde, 60-80% amarillo, >=80% rojo (presupuesto agotándose)
      if ($pctGastos < 60)      { $gCol = 'bg-success'; $gLabel = 'Rentable'; }
      elseif ($pctGastos < 80)  { $gCol = 'bg-warning'; $gLabel = 'Atención: gastos ≥ 60% del proyecto'; }
      elseif ($pctGastos < 100) { $gCol = 'bg-danger';  $gLabel = 'Riesgo: gastos ≥ 80% — margen muy bajo'; }
      else                       { $gCol = 'bg-dark';    $gLabel = 'No rentable: gastos ≥ 100% del proyecto'; }
    ?>
    <div class="mt-3">
      <div class="d-flex justify-content-between small mb-1">
        <span class="text-muted"><i class="bi bi-graph-down-arrow me-1"></i>Consumo de presupuesto (gastos / proyecto): <strong><?= number_format($pctGastos, 1, ',', '.') ?>%</strong>
          <span class="badge ms-1 <?= $gCol ?>" style="font-size:.65rem;vertical-align:middle"><?= $gLabel ?></span>
        </span>
        <span class="text-muted"><?= fmtMon($totalGastosProy) ?> / <?= fmtMon($resumenProy['monto_proy']) ?></span>
      </div>
      <div class="progress" style="height:14px;background:#e5e7eb">
        <div class="progress-bar <?= $gCol ?> progress-bar-striped" style="width:<?= min(100,$pctGastos) ?>%; transition:width .4s ease, background-color .4s ease">
          <?php if ($pctGastos >= 10): ?><small class="fw-semibold"><?= number_format(min(100,$pctGastos), 0) ?>%</small><?php endif; ?>
        </div>
      </div>
      <div class="d-flex justify-content-between small text-muted mt-1" style="font-size:.7rem">
        <span>0% (sin gastos)</span>
        <span style="color:#16a34a">|60% saludable</span>
        <span style="color:#d97706">|80% atención</span>
        <span style="color:#dc2626">100% sin margen</span>
      </div>
    </div>

    <?php endif; ?>
  </div>
</div>
<?php if (!empty($resumenProy['avance_material'])): ?>
<!-- AVANCE POR MATERIAL — descuenta cotización a medida que llegan tickets -->
<div class="card border-success border-2 mb-3">
  <div class="card-header bg-success text-white py-2">
    <span class="fw-semibold"><i class="bi bi-truck-flatbed me-1"></i>Avance del proyecto por material</span>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light" style="font-size:.85rem">
        <tr>
          <th>Ítem cotizado</th>
          <th>Material detectado</th>
          <th class="text-end">Cot. (m³)</th>
          <th class="text-end">Entregado (tickets)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($resumenProy['avance_material'] as $am): ?>
        <tr>
          <td><small class="fw-semibold"><?= htmlspecialchars($am['descripcion']) ?></small></td>
          <td>
            <?php if ($am['material_lbl']): ?>
            <span class="badge bg-secondary"><?= htmlspecialchars($am['material_lbl']) ?></span>
            <?php else: ?>
            <small class="text-muted">— sin match —</small>
            <?php endif; ?>
          </td>
          <td class="text-end fw-semibold"><?= number_format($am['cot_cant'], 1, ',', '.') ?></td>
          <td class="text-end"><?= number_format($am['m3_entreg'], 1, ',', '.') ?>
            <?php if ($am['tk_qty']): ?><br><small class="text-muted"><?= $am['tk_qty'] ?> ticket(s)</small><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>



<?php endif; ?>


<?php if ($mostrarForm): ?>
<!-- Ítems de la cotización (movido fuera del form para que quede arriba de "Agregar gasto") -->
<div id="ep_items_panel" style="display:none" class="mb-3">
  <div class="card border-success-subtle">
    <div class="card-header bg-success-subtle py-2 d-flex align-items-center justify-content-between">
      <span class="fw-semibold small"><i class="bi bi-list-check me-1"></i>Ítems de la cotización</span>
      <span class="small text-muted" id="ep_items_resumen"></span>
    </div>
    <div class="card-body p-0">
      <table class="table table-sm mb-0" style="font-size:.85rem">
        <thead class="table-light">
          <tr>
            <th>Descripción</th>
            <th class="text-end" style="width:90px">Cant.</th>
            <th style="width:80px">Unidad</th>
            <th class="text-end" style="width:120px">Precio</th>
            <th class="text-end" style="width:130px">Total</th>
          </tr>
        </thead>
        <tbody id="ep_items_tbody"></tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($mostrarForm):
  $catsGastoFaena = [];
  $provGastoFaena = [];
  $ocsGastoFaena  = [];
  $gastoEditar    = null;
  if ($obraIdProy) {
    try { $catsGastoFaena = $pdo->query("SELECT id, nombre FROM gastos_faena_categorias WHERE activo = 1 ORDER BY orden, nombre")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}
    try { $provGastoFaena = $pdo->query("SELECT nombre, rut FROM proveedores ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}
    try {
      $stOCG = $pdo->prepare("SELECT id, numero, proveedor_nombre, total, fecha
                              FROM ordenes_compra
                              WHERE obra_id = ? OR (obra_codigo IS NOT NULL AND obra_codigo != '' AND obra_codigo = ?)
                              ORDER BY fecha DESC, id DESC LIMIT 200");
      $stOCG->execute([$obraIdProy, $obraCodProy]);
      $ocsGastoFaena = $stOCG->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
    if (!empty($_GET['edit_gasto'])) {
      try {
        $stGE = $pdo->prepare("SELECT * FROM gastos_faena WHERE id = ? AND obra_id = ?");
        $stGE->execute([(int)$_GET['edit_gasto'], $obraIdProy]);
        $gastoEditar = $stGE->fetch(PDO::FETCH_ASSOC);
      } catch (Exception $e) {}
    }
  }
?>
<?php if ($obraIdProy && $registroEditar): ?>
<!-- Formulario para agregar / editar gasto del proyecto -->
<?php $esEdicionGasto = !empty($gastoEditar); ?>
<div class="card border-info-subtle mb-3" id="gastos-proyecto">
  <div class="card-header bg-info-subtle py-2 d-flex justify-content-between align-items-center">
    <span class="fw-semibold small">
      <i class="bi bi-<?= $esEdicionGasto ? 'pencil-square' : 'plus-circle' ?> me-1"></i>
      <?= $esEdicionGasto ? 'Editar gasto del proyecto' : 'Agregar gasto al proyecto' ?>
    </span>
    <?php if ($esEdicionGasto): ?>
    <a href="?tipo=<?= $tipoActivo ?>&edit=<?= (int)$registroEditar['id'] ?>#gastos-proyecto" class="small text-decoration-none">
      <i class="bi bi-x-circle me-1"></i>Cancelar edición
    </a>
    <?php endif; ?>
  </div>
  <div class="card-body p-2">
    <form method="POST" action="estados_pago.php?tipo=<?= $tipoActivo ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
      <input type="hidden" name="accion" value="<?= $esEdicionGasto ? 'editar_gasto_faena' : 'agregar_gasto_faena' ?>">
      <input type="hidden" name="tipo" value="<?= $tipoActivo ?>">
      <input type="hidden" name="gf_obra_id" value="<?= $obraIdProy ?>">
      <input type="hidden" name="gf_ep_id" value="<?= (int)$registroEditar['id'] ?>">
      <?php if ($esEdicionGasto): ?>
      <input type="hidden" name="gf_id" value="<?= (int)$gastoEditar['id'] ?>">
      <?php endif; ?>
      <div class="col-md-2">
        <label class="form-label small mb-1">Fecha</label>
        <input type="date" name="gf_fecha" class="form-control form-control-sm" value="<?= htmlspecialchars($gastoEditar['fecha'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Categoría</label>
        <select name="gf_categoria_id" class="form-select form-select-sm">
          <option value="">— Sin categoría —</option>
          <?php foreach ($catsGastoFaena as $c): $sel = ((int)($gastoEditar['categoria_id'] ?? 0) === (int)$c['id']) ? 'selected' : ''; ?>
          <option value="<?= (int)$c['id'] ?>" <?= $sel ?>><?= htmlspecialchars($c['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">Descripción *</label>
        <input type="text" name="gf_descripcion" class="form-control form-control-sm" required placeholder="Detalle del gasto" value="<?= htmlspecialchars($gastoEditar['descripcion'] ?? '') ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Proveedor</label>
        <select name="gf_proveedor" id="gf_proveedor" class="form-select form-select-sm">
          <option value="">— Seleccionar —</option>
          <?php foreach ($provGastoFaena as $p): $sel = (($gastoEditar['proveedor'] ?? '') === $p['nombre']) ? 'selected' : ''; ?>
          <option value="<?= htmlspecialchars($p['nombre']) ?>" <?= $sel ?>><?= htmlspecialchars($p['nombre']) ?><?= !empty($p['rut']) ? ' · '.htmlspecialchars($p['rut']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1"><i class="bi bi-receipt me-1"></i>OC del sistema</label>
        <select name="gf_oc_id" id="gf_oc_id" class="form-select form-select-sm">
          <option value="">— Sin OC —</option>
          <?php foreach ($ocsGastoFaena as $oc): $sel = ((int)($gastoEditar['oc_id'] ?? 0) === (int)$oc['id']) ? 'selected' : ''; ?>
          <option value="<?= (int)$oc['id'] ?>"
                  data-prov="<?= htmlspecialchars($oc['proveedor_nombre']) ?>"
                  data-total="<?= (float)$oc['total'] ?>"
                  data-numero="<?= htmlspecialchars($oc['numero']) ?>" <?= $sel ?>>
            OC N° <?= htmlspecialchars($oc['numero']) ?> — <?= htmlspecialchars($oc['proveedor_nombre']) ?> — $<?= number_format((float)$oc['total'], 0, ',', '.') ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Monto *</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text">$</span>
          <input type="text" name="gf_monto" class="form-control" required placeholder="0" inputmode="numeric"
                 value="<?= $esEdicionGasto ? number_format((float)$gastoEditar['monto'], 0, ',', '.') : '' ?>">
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1"><i class="bi bi-paperclip me-1"></i>Factura / Comprobante <?= $esEdicionGasto ? '<small class="text-muted">(opcional al editar)</small>' : '' ?></label>
        <input type="file" name="gf_factura" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp">
        <small class="text-muted" style="font-size:.7rem">PDF, JPG o PNG · Máx 10MB</small>
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-<?= $esEdicionGasto ? 'warning' : 'info' ?> btn-sm fw-semibold">
          <i class="bi bi-<?= $esEdicionGasto ? 'check2-square' : 'plus-lg' ?>"></i>
          <?= $esEdicionGasto ? 'Actualizar' : 'Agregar gasto' ?>
        </button>
      </div>
    </form>
    <script>
    (function(){
      var selOC = document.getElementById('gf_oc_id');
      if (!selOC) return;
      selOC.addEventListener('change', function(){
        var opt = selOC.options[selOC.selectedIndex];
        if (!opt || !opt.value) return;
        var prov = opt.getAttribute('data-prov') || '';
        var total = opt.getAttribute('data-total') || '';
        var num = opt.getAttribute('data-numero') || '';
        // Setear proveedor si está en el select
        var inProv = document.getElementById('gf_proveedor');
        if (inProv) {
          var match = false;
          Array.prototype.forEach.call(inProv.options, function(o){
            if (o.value.trim().toLowerCase() === prov.trim().toLowerCase()) { inProv.value = o.value; match = true; }
          });
        }
        // Auto-rellenar monto si está vacío
        var inMon = document.querySelector('input[name="gf_monto"]');
        if (inMon && !inMon.value && total) {
          inMon.value = Math.round(parseFloat(total)).toLocaleString('es-CL');
        }
        // Auto-rellenar descripción si está vacía
        var inDesc = document.querySelector('input[name="gf_descripcion"]');
        if (inDesc && !inDesc.value && num) inDesc.value = 'OC N° ' + num;
      });
    })();
    </script>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($resumenProy['gastos_recientes'])): ?>
<div class="card border-info-subtle mb-3">
  <div class="card-header bg-info-subtle py-2 d-flex justify-content-between">
    <span class="fw-semibold small"><i class="bi bi-list-ul me-1"></i>Últimos gastos registrados de esta obra</span>
    <?php if ($obraIdProy): ?>
    <a href="gastos_faena.php?obra=<?= $obraIdProy ?>" target="_blank" class="small text-decoration-none"><i class="bi bi-arrow-up-right me-1"></i>Ver todos</a>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0" style="font-size:.85rem">
      <thead class="table-light">
        <tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Proveedor</th><th>OC</th><th class="text-end">Monto</th><th class="text-center" style="width:50px">Doc.</th><th class="text-center" style="width:90px">Acciones</th></tr>
      </thead>
      <tbody>
        <?php foreach ($resumenProy['gastos_recientes'] as $g):
          $montoTot = (float)$g['monto'] + (float)($g['monto_peaje'] ?? 0);
        ?>
        <tr<?= ((int)($gastoEditar['id'] ?? 0) === (int)$g['id']) ? ' class="table-warning"' : '' ?>>
          <td><small><?= htmlspecialchars(date('d-m-Y', strtotime($g['fecha']))) ?></small></td>
          <td><small><?= htmlspecialchars($g['categoria_nombre'] ?? '') ?></small></td>
          <td><small><?= htmlspecialchars($g['descripcion'] ?? '') ?></small></td>
          <td><small class="text-muted"><?= htmlspecialchars($g['proveedor'] ?? '') ?></small></td>
          <td>
            <?php if (!empty($g['oc_numero'])): ?>
            <a href="/ver.php?id=<?= (int)$g['oc_id'] ?>" target="_blank" class="badge bg-primary text-decoration-none" title="Ver OC">
              N° <?= htmlspecialchars($g['oc_numero']) ?>
            </a>
            <?php else: ?>
            <small class="text-muted">—</small>
            <?php endif; ?>
          </td>
          <td class="text-end fw-semibold"><?= fmtMon($montoTot) ?></td>
          <td class="text-center">
            <?php if (!empty($g['arch_ruta'])): ?>
            <a href="uploads/<?= htmlspecialchars($g['arch_ruta']) ?>" target="_blank" title="<?= htmlspecialchars($g['arch_nombre'] ?? 'Ver factura') ?>">
              <i class="bi bi-file-earmark-check-fill text-success"></i>
            </a>
            <?php else: ?>
            <small class="text-muted">—</small>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <a href="?tipo=<?= $tipoActivo ?>&edit=<?= (int)$registroEditar['id'] ?>&edit_gasto=<?= (int)$g['id'] ?>#gastos-proyecto"
               class="btn btn-sm btn-outline-warning py-0 px-1" title="Editar"><i class="bi bi-pencil"></i></a>
            <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este gasto?')">
              <input type="hidden" name="accion" value="eliminar_gasto_faena">
              <input type="hidden" name="tipo" value="<?= $tipoActivo ?>">
              <input type="hidden" name="gf_id" value="<?= (int)$g['id'] ?>">
              <input type="hidden" name="gf_ep_id" value="<?= (int)$registroEditar['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Eliminar"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($resumenProy['tickets_recientes'])): ?>
<!-- Tickets de despacho de la obra -->
<div class="card border-2 mb-3" style="border-color:#0d9488 !important">
  <div class="card-header py-2 d-flex justify-content-between" style="background:#ccfbf1">
    <span class="fw-semibold small" style="color:#0d9488"><i class="bi bi-truck-front-fill me-1"></i>Tickets de despacho de la obra (<?= $resumenProy['tickets_qty'] ?>)</span>
    <span class="small fw-semibold" style="color:#0d9488"><?= number_format($resumenProy['tickets_m3'], 1, ',', '.') ?> m³ totales</span>
  </div>
  <div class="card-body p-2">
    <?php if (!empty($resumenProy['tickets_por_material'])): ?>
    <div class="mb-2 d-flex flex-wrap gap-2">
      <?php foreach ($resumenProy['tickets_por_material'] as $tm): ?>
      <span class="badge" style="background:#0d9488;font-size:.75rem">
        <?= htmlspecialchars($tm['mat']) ?>: <?= number_format((float)$tm['m3'], 1, ',', '.') ?> m³ (<?= (int)$tm['qty'] ?>)
      </span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0" style="font-size:.85rem">
        <thead class="table-light">
          <tr><th>Fecha</th><th>Hora</th><th>PPU</th><th>Conductor</th><th>Material</th><th class="text-end">m³</th><th>Destino</th><th>Estado</th></tr>
        </thead>
        <tbody>
          <?php foreach ($resumenProy['tickets_recientes'] as $tk): ?>
          <tr>
            <td><small><?= htmlspecialchars(date('d-m-Y', strtotime($tk['fecha']))) ?></small></td>
            <td><small class="text-muted"><?= htmlspecialchars($tk['hora']) ?></small></td>
            <td><strong class="small"><?= htmlspecialchars($tk['ppu']) ?></strong></td>
            <td><small><?= htmlspecialchars($tk['conductor_nombre']) ?></small></td>
            <td><small><?= htmlspecialchars($tk['tipo_material']) ?></small></td>
            <td class="text-end fw-semibold"><?= number_format((float)$tk['metros_cubicos'], 1, ',', '.') ?></td>
            <td><small class="text-muted"><?= htmlspecialchars($tk['destino']) ?></small></td>
            <td><small><?= htmlspecialchars(ucfirst($tk['estado'])) ?></small></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($resumenProy['archivos_categoria'])):
  $iconoFuente = ['Cotización'=>'bi-file-earmark-text', 'OC externa'=>'bi-receipt', 'Gasto'=>'bi-receipt-cutoff'];
  $colorFuente = ['Cotización'=>'#2563eb', 'OC externa'=>'#d97706', 'Gasto'=>'#0891b2'];
?>
<div class="card border-info-subtle mb-3">
  <div class="card-header bg-info-subtle py-2 d-flex justify-content-between">
    <span class="fw-semibold small"><i class="bi bi-folder2-open me-1"></i>Archivos del proyecto por categoría</span>
    <span class="small text-muted"><?= array_sum(array_map('count', $resumenProy['archivos_categoria'])) ?> archivo(s)</span>
  </div>
  <div class="card-body p-2">
    <div class="accordion" id="accArchivosProy">
      <?php $idx = 0; foreach ($resumenProy['archivos_categoria'] as $categoria => $archivos): $idx++; ?>
      <div class="accordion-item">
        <h2 class="accordion-header">
          <button class="accordion-button <?= $idx > 1 ? 'collapsed' : '' ?> py-2 small fw-semibold"
                  type="button" data-bs-toggle="collapse" data-bs-target="#accArch<?= $idx ?>">
            <i class="bi bi-folder-fill me-2 text-warning"></i>
            <?= htmlspecialchars($categoria) ?>
            <span class="badge bg-secondary ms-2"><?= count($archivos) ?></span>
          </button>
        </h2>
        <div id="accArch<?= $idx ?>" class="accordion-collapse collapse <?= $idx === 1 ? 'show' : '' ?>" data-bs-parent="#accArchivosProy">
          <div class="accordion-body p-2">
            <ul class="list-group list-group-flush">
              <?php foreach ($archivos as $arch):
                $col = $colorFuente[$arch['fuente']] ?? '#6b7280';
                $ico = $iconoFuente[$arch['fuente']] ?? 'bi-paperclip';
              ?>
              <li class="list-group-item py-2 px-2 d-flex align-items-center">
                <i class="bi <?= $ico ?> me-2" style="color:<?= $col ?>"></i>
                <div class="flex-grow-1" style="min-width:0">
                  <a href="<?= htmlspecialchars($arch['ruta']) ?>" target="_blank" class="fw-semibold text-decoration-none small d-block text-truncate" title="<?= htmlspecialchars($arch['nombre']) ?>">
                    <?= htmlspecialchars($arch['nombre']) ?>
                  </a>
                  <small class="text-muted d-block" style="font-size:.7rem">
                    <?= htmlspecialchars($arch['contexto']) ?>
                    <?php if ($arch['fecha']): ?>· <?= htmlspecialchars(date('d-m-Y', strtotime($arch['fecha']))) ?><?php endif; ?>
                  </small>
                </div>
                <span class="badge ms-2" style="background:<?= $col ?>1a; color:<?= $col ?>; font-size:.65rem"><?= htmlspecialchars($arch['fuente']) ?></span>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($resumenProy['facturas'])): ?>
<!-- Facturas emitidas (al final, junto al formulario de subida) -->
<div class="card border-success-subtle mb-3">
  <div class="card-header bg-success-subtle py-2 d-flex justify-content-between">
    <span class="fw-semibold small"><i class="bi bi-receipt-cutoff me-1"></i>Facturas emitidas (<?= $resumenProy['facturas_qty'] ?>)</span>
    <span class="small text-success fw-semibold">Total facturado: <?= fmtMon($resumenProy['facturado']) ?></span>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0" style="font-size:.85rem">
      <thead class="table-light">
        <tr>
          <th>Fecha</th>
          <th>Doc N°</th>
          <th>Período</th>
          <th>Descripción</th>
          <th>Estado</th>
          <th class="text-end">Monto</th>
          <th class="text-center" style="width:60px">Factura</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($resumenProy['facturas'] as $f): ?>
        <tr<?= ((int)$f['id'] === (int)($registroEditar['id'] ?? 0)) ? ' class="table-warning"' : '' ?>>
          <td><small><?= htmlspecialchars(date('d-m-Y', strtotime($f['fecha']))) ?></small></td>
          <td><strong><?= htmlspecialchars($f['numero_documento'] ?: '—') ?></strong></td>
          <td>
            <?php if (!empty($f['periodo_desde']) && !empty($f['periodo_hasta'])): ?>
              <small><?= date('d-m-Y', strtotime($f['periodo_desde'])) ?> → <?= date('d-m-Y', strtotime($f['periodo_hasta'])) ?></small>
            <?php else: ?>
              <small class="text-muted">—</small>
            <?php endif; ?>
          </td>
          <td><small><?= htmlspecialchars($f['descripcion']) ?></small></td>
          <td><?= badgeEstado($f['estado']) ?></td>
          <td class="text-end fw-semibold"><?= fmtMon($f['monto_total']) ?></td>
          <td class="text-center">
            <?php if (!empty($f['factura_ruta'])): ?>
            <a href="uploads/<?= htmlspecialchars($f['factura_ruta']) ?>" target="_blank" title="<?= htmlspecialchars($f['factura_nombre'] ?? 'Ver factura') ?>">
              <i class="bi bi-file-earmark-pdf-fill text-danger"></i>
            </a>
            <?php else: ?>
            <small class="text-muted">—</small>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($obraIdProy && $registroEditar && $cfg['sentido'] === 'cobro'): ?>
<!-- Formulario para subir nueva factura emitida del proyecto (al final) -->
<div class="card border-success-subtle mb-3" id="facturas-proyecto">
  <div class="card-header bg-success text-white py-2 d-flex justify-content-between align-items-center">
    <span class="fw-semibold small"><i class="bi bi-cloud-upload me-1"></i>Subir factura emitida del proyecto</span>
  </div>
  <div class="card-body p-2">
    <form method="POST" action="estados_pago.php?tipo=<?= $tipoActivo ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
      <input type="hidden" name="accion" value="agregar_factura_emitida">
      <input type="hidden" name="tipo" value="<?= $tipoActivo ?>">
      <input type="hidden" name="ff_obra_id" value="<?= $obraIdProy ?>">
      <input type="hidden" name="ff_ep_id" value="<?= (int)$registroEditar['id'] ?>">
      <input type="hidden" name="ff_contraparte" value="<?= htmlspecialchars($registroEditar['contraparte'] ?? '') ?>">
      <input type="hidden" name="ff_rut" value="<?= htmlspecialchars($registroEditar['contraparte_rut'] ?? '') ?>">
      <input type="hidden" name="ff_cot_id" value="<?= (int)($registroEditar['cot_id'] ?? 0) ?>">
      <div class="col-md-2">
        <label class="form-label small mb-1">Fecha factura</label>
        <input type="date" name="ff_fecha" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Documento N°</label>
        <input type="text" name="ff_numero" class="form-control form-control-sm" placeholder="Ej: F-001234" required>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1"><i class="bi bi-calendar-range me-1"></i>Periodo desde</label>
        <input type="date" name="ff_desde" class="form-control form-control-sm">
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Periodo hasta</label>
        <input type="date" name="ff_hasta" class="form-control form-control-sm">
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Monto</label>
        <div class="input-group input-group-sm">
          <span class="input-group-text">$</span>
          <input type="text" name="ff_monto" class="form-control" required placeholder="0" inputmode="numeric">
        </div>
        <select name="ff_monto_tipo" class="form-select form-select-sm mt-1">
          <option value="total" selected>Total (incluye IVA)</option>
          <option value="neto">Neto (+ 19% IVA)</option>
        </select>
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-success btn-sm fw-semibold"><i class="bi bi-cloud-arrow-up me-1"></i>Subir factura</button>
      </div>
      <div class="col-md-12">
        <label class="form-label small mb-1"><i class="bi bi-paperclip me-1"></i>Archivo de factura (PDF/imagen)</label>
        <input type="file" name="ff_factura" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp">
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php if (!$registroEditar && isset($_GET['nuevo']) && !$precarga): ?>
<!-- Modal: seleccionar obra al iniciar nuevo registro -->
<div class="modal fade" id="modalElegirObra" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-<?= $cfg['color'] ?> text-white">
        <h5 class="modal-title"><i class="bi bi-building me-2"></i>Asignar obra al estado de pago</h5>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-2"><i class="bi bi-info-circle me-1"></i>Elige primero la obra. Los campos código y nombre se completarán automáticamente, y la lista de cotizaciones se filtrará a las de esa obra.</p>
        <label class="form-label fw-semibold">Obra *</label>
        <select id="ep_modal_obra" class="form-select" required>
          <option value="">— Seleccionar obra —</option>
          <?php
            $obrasAdj = array_filter($obrasList, fn($o)=> !empty($o['ultima_adj_fecha']));
            $obrasSinAdj = array_filter($obrasList, fn($o)=> empty($o['ultima_adj_fecha']));
          ?>
          <?php if (!empty($obrasAdj)): ?>
          <optgroup label="Con cotización adjudicada">
          <?php foreach ($obrasAdj as $o):
            $esUltima = (int)$o['id'] === $ultimaAdjObraId;
            $tag = $esUltima ? '  ⭐ ÚLTIMA ADJUDICADA' : ' · adj. ' . date('d-m-Y', strtotime($o['ultima_adj_fecha']));
          ?>
          <option value="<?= (int)$o['id'] ?>"
                  data-codigo="<?= htmlspecialchars($o['codigo']) ?>"
                  data-nombre="<?= htmlspecialchars($o['nombre']) ?>"
                  data-mandante="<?= htmlspecialchars($o['mandante'] ?? '') ?>"
                  <?= $esUltima ? 'selected' : '' ?>>
            <?= htmlspecialchars($o['codigo']) ?> — <?= htmlspecialchars($o['nombre']) ?><?= !empty($o['mandante']) ? ' · '.htmlspecialchars($o['mandante']) : '' ?><?= $tag ?>
          </option>
          <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
          <?php if (!empty($obrasSinAdj)): ?>
          <optgroup label="Sin cotización adjudicada">
          <?php foreach ($obrasSinAdj as $o): ?>
          <option value="<?= (int)$o['id'] ?>"
                  data-codigo="<?= htmlspecialchars($o['codigo']) ?>"
                  data-nombre="<?= htmlspecialchars($o['nombre']) ?>"
                  data-mandante="<?= htmlspecialchars($o['mandante'] ?? '') ?>">
            <?= htmlspecialchars($o['codigo']) ?> — <?= htmlspecialchars($o['nombre']) ?><?= !empty($o['mandante']) ? ' · '.htmlspecialchars($o['mandante']) : '' ?>
          </option>
          <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="ep_modal_omitir">Omitir</button>
        <button type="button" class="btn btn-<?= $cfg['color'] ?>" id="ep_modal_aceptar"><i class="bi bi-check-lg me-1"></i>Asignar obra</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>


<!-- TABLA -->
<div class="card shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 table-estados-pago align-middle">
        <thead class="table-light">
          <tr>
            <th>Fecha</th>
            <th>Doc N°</th>
            <th><?= $cfg['contraparte_label'] ?></th>
            <th>Obra</th>
            <th>Doc. asoc.</th>
            <th>Descripción</th>
            <th class="text-end">Monto Proyecto</th>
            <th class="text-end">Total</th>
            <th>Vence</th>
            <th>Estado</th>
            <th class="text-end no-print">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($registros)): ?>
          <tr><td colspan="11" class="text-center text-muted py-4">
            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
            No hay registros. Crea el primero con el botón <em>Nuevo registro</em>.
          </td></tr>
          <?php else: foreach ($registros as $r): ?>
          <tr>
            <td><small><?= htmlspecialchars(date('d-m-Y', strtotime($r['fecha']))) ?></small></td>
            <td><strong><?= htmlspecialchars($r['numero_documento'] ?: '—') ?></strong></td>
            <td>
              <?= htmlspecialchars($r['contraparte']) ?>
              <?php if (!empty($r['contraparte_rut'])): ?><br><small class="text-muted"><?= htmlspecialchars($r['contraparte_rut']) ?></small><?php endif; ?>
            </td>
            <td>
              <?php if ($r['obra_codigo']): ?>
                <span class="badge bg-light text-dark border"><?= htmlspecialchars($r['obra_codigo']) ?></span>
              <?php endif; ?>
              <small><?= htmlspecialchars($r['obra_nombre']) ?></small>
            </td>
            <td>
              <?php if (!empty($r['oc_id'])): ?>
                <a href="ver.php?id=<?= (int)$r['oc_id'] ?>" target="_blank" class="badge bg-primary text-decoration-none mb-1" title="Abrir OC">
                  <i class="bi bi-receipt"></i> OC
                </a><br>
              <?php endif; ?>
              <?php if (!empty($r['cot_id'])): ?>
                <a href="cotizaciones/ver.php?id=<?= (int)$r['cot_id'] ?>" target="_blank" class="badge bg-success text-decoration-none" title="Abrir Cotización">
                  <i class="bi bi-file-earmark-text"></i> COT
                </a>
              <?php endif; ?>
              <?php if (empty($r['oc_id']) && empty($r['cot_id'])): ?><small class="text-muted">—</small><?php endif; ?>
            </td>
            <td><small><?= htmlspecialchars($r['descripcion']) ?></small></td>
            <td class="text-end">
              <?php $mp = $ocExtMontoMap[(int)$r['id']] ?? 0; ?>
              <?php if ($mp > 0): ?>
                <span class="text-primary fw-semibold"><?= fmtMon($mp) ?></span>
              <?php else: ?>
                <small class="text-muted">—</small>
              <?php endif; ?>
            </td>
            <td class="text-end fw-bold"><?= fmtMon($r['monto_total']) ?></td>
            <td><small class="text-muted"><?= $r['fecha_vencimiento'] ? htmlspecialchars(date('d-m-Y', strtotime($r['fecha_vencimiento']))) : '—' ?></small></td>
            <td><?= badgeEstado($r['estado']) ?></td>
            <td class="text-end no-print">
              <?php if (in_array($r['estado'], ['pendiente','parcial','vencido'])): ?>
              <form method="POST" action="estados_pago.php?tipo=<?= $tipoActivo ?>" class="d-inline">
                <input type="hidden" name="accion" value="marcar_pagado">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-success" title="Marcar como <?= $cfg['sentido'] === 'cobro' ? 'cobrado' : 'pagado' ?>"
                        onclick="return confirm('¿Marcar este registro como <?= $cfg['sentido'] === 'cobro' ? 'cobrado' : 'pagado' ?>?')">
                  <i class="bi bi-check2-circle"></i>
                </button>
              </form>
              <?php endif; ?>
              <a href="estados_pago.php?tipo=<?= $tipoActivo ?>&edit=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Editar"><i class="bi bi-pencil"></i></a>
              <form method="POST" action="estados_pago.php?tipo=<?= $tipoActivo ?>" class="d-inline" onsubmit="return confirm('¿Eliminar este registro? Esta acción no se puede deshacer.')">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function(){
  // Auto-cálculo de IVA y Total
  var neto = document.getElementById('ep_neto');
  var iva = document.getElementById('ep_iva');
  var total = document.getElementById('ep_total');
  function parseMon(s){ return parseFloat(String(s||'').replace(/\./g,'').replace(',', '.')) || 0; }
  function fmt(n){ return Math.round(n).toLocaleString('es-CL'); }
  if (neto) {
    neto.addEventListener('input', function(){
      var n = parseMon(neto.value);
      var ivaVal = Math.round(n * 0.19);
      iva.value = fmt(ivaVal);
      total.value = fmt(n + ivaVal);
    });
  }
  // Auto-rellenar código obra (y mandante si es cobro) al elegir
  var inObra = document.getElementById('ep_obra_nombre');
  var inCod = document.getElementById('ep_obra_codigo');
  var dlObra = document.getElementById('ep_listaObras');
  var ES_COBRO = <?= json_encode($cfg['sentido'] === 'cobro') ?>;
  var COT_ITEMS = <?= json_encode($cotItemsMap, JSON_UNESCAPED_UNICODE) ?>;
  var COT_OC_LINKS = <?= json_encode($cotOcLinks, JSON_UNESCAPED_UNICODE) ?>;
  var obraIdActual = 0;
  function filtrarPorObra(sel, obraId, obraNombre){
    if (!sel) return;
    var nomLower = (obraNombre || '').trim().toLowerCase();
    Array.prototype.forEach.call(sel.options, function(opt){
      if (!opt.value) { opt.hidden = false; return; }
      var oid = parseInt(opt.getAttribute('data-obra-id') || '0', 10);
      var oNom = (opt.getAttribute('data-obra') || '').trim().toLowerCase();
      var match = (obraId && oid === obraId) || (nomLower && oNom === nomLower);
      opt.hidden = !match;
    });
    if (sel.options[sel.selectedIndex] && sel.options[sel.selectedIndex].hidden) {
      sel.value = '';
    }
  }
  function filtrarCotsPorObra(obraId, obraNombre){
    filtrarPorObra(document.getElementById('ep_cot_id'), obraId, obraNombre);
    // Filtrar también las OC externas; las OC internas no tienen data-obra-id, solo data-obra
    var ocSel = document.getElementById('ep_oc_id');
    if (ocSel) {
      var nomLower = (obraNombre || '').trim().toLowerCase();
      Array.prototype.forEach.call(ocSel.options, function(opt){
        if (!opt.value) { opt.hidden = false; return; }
        var oNom = (opt.getAttribute('data-obra') || '').trim().toLowerCase();
        var oid = parseInt(opt.getAttribute('data-obra-id') || '0', 10);
        var match = (obraId && oid === obraId) || (nomLower && oNom === nomLower);
        opt.hidden = !match;
      });
      if (ocSel.options[ocSel.selectedIndex] && ocSel.options[ocSel.selectedIndex].hidden) {
        ocSel.value = '';
      }
    }
  }
  if (inObra && dlObra) {
    inObra.addEventListener('change', function(){
      var v = inObra.value.trim().toLowerCase();
      Array.prototype.forEach.call(dlObra.options, function(opt){
        if (opt.value.trim().toLowerCase() === v) {
          inCod.value = opt.getAttribute('data-codigo') || '';
          obraIdActual = parseInt(opt.getAttribute('data-obra-id') || '0', 10);
          var hidObraId2 = document.getElementById('ep_obra_id');
          if (hidObraId2) hidObraId2.value = obraIdActual;
          if (ES_COBRO) {
            var inCon = document.getElementById('ep_contraparte');
            if (inCon && !inCon.value) inCon.value = opt.getAttribute('data-mandante') || '';
          }
          filtrarCotsPorObra(obraIdActual, inObra.value);
        }
      });
    });
  }
  // Auto-rellenar RUT al elegir contraparte
  var inCon = document.getElementById('ep_contraparte');
  var inRut = document.getElementById('ep_contraparte_rut');
  var dlCon = document.getElementById('ep_listaContra');
  if (inCon && dlCon) {
    inCon.addEventListener('change', function(){
      var v = inCon.value.trim().toLowerCase();
      Array.prototype.forEach.call(dlCon.options, function(opt){
        if (opt.value.trim().toLowerCase() === v) inRut.value = opt.getAttribute('data-rut') || '';
      });
    });
  }
  function setVal(id, val){ var el = document.getElementById(id); if (el) el.value = val; }
  function setIfEmpty(id, val){ var el = document.getElementById(id); if (el && !el.value) el.value = val; }
  function applyMontos(totalNum){
    var ivaNum = Math.round(totalNum / 1.19 * 0.19);
    var netoNum = totalNum - ivaNum;
    setVal('ep_neto', netoNum.toLocaleString('es-CL'));
    setVal('ep_iva', ivaNum.toLocaleString('es-CL'));
    setVal('ep_total', totalNum.toLocaleString('es-CL'));
  }

  var selOC  = document.getElementById('ep_oc_id');
  var selCOT = document.getElementById('ep_cot_id');

  if (selOC) {
    selOC.addEventListener('change', function(){
      var opt = selOC.options[selOC.selectedIndex];
      if (!opt || !opt.value) return;
      var esExterna = opt.getAttribute('data-tipo') === 'externa';
      var ocTotal = parseFloat(opt.getAttribute('data-total')) || 0;
      applyMontos(ocTotal);
      setIfEmpty('ep_contraparte', opt.getAttribute('data-prov') || '');
      setIfEmpty('ep_obra_nombre', opt.getAttribute('data-obra') || '');
      // Si la obra de la OC coincide con un código en el datalist, llenar el código
      var oNom = (opt.getAttribute('data-obra') || '').trim().toLowerCase();
      if (oNom && dlObra && !inCod.value) {
        Array.prototype.forEach.call(dlObra.options, function(o){
          if (o.value.trim().toLowerCase() === oNom) inCod.value = o.getAttribute('data-codigo') || '';
        });
      }
      var docInput = document.querySelector('input[name="numero_documento"]');
      if (docInput && !docInput.value) {
        var pref = esExterna ? 'OC ext. ' : 'OC ';
        docInput.value = pref + (opt.getAttribute('data-numero') || '');
      }
      // Mostrar ítems de la cotización vinculada (si la OC tiene cot_id) o conservar la cot ya seleccionada
      var cotIdLink = parseInt(opt.getAttribute('data-cot-id') || '0', 10);
      if (cotIdLink && selCOT) {
        var found = false;
        Array.prototype.forEach.call(selCOT.options, function(o){
          if (!found && o.value && parseInt(o.value, 10) === cotIdLink) { selCOT.value = o.value; found = true; }
        });
        if (found) pintarItems(cotIdLink);
      } else if (selCOT && selCOT.value) {
        pintarItems(selCOT.value);
      }
      // El monto siempre lo dicta la OC seleccionada
      applyMontos(ocTotal);
    });
  }


  function fmtCLP(n){ return Math.round(n||0).toLocaleString('es-CL'); }
  function pintarItems(cotId){
    var panel = document.getElementById('ep_items_panel');
    var tbody = document.getElementById('ep_items_tbody');
    var resumen = document.getElementById('ep_items_resumen');
    if (!panel || !tbody) return;
    var items = COT_ITEMS[cotId] || COT_ITEMS[String(cotId)] || [];
    if (!items.length) {
      panel.style.display = 'none';
      tbody.innerHTML = '';
      return;
    }
    var html = '';
    var totalSum = 0;
    items.forEach(function(it){
      var t = parseFloat(it.total) || 0; totalSum += t;
      html += '<tr>'
            + '<td>' + (it.descripcion ? it.descripcion.replace(/</g,'&lt;') : '') + '</td>'
            + '<td class="text-end">' + (it.cantidad ?? '') + '</td>'
            + '<td>' + (it.unidad ? it.unidad.replace(/</g,'&lt;') : '') + '</td>'
            + '<td class="text-end">$' + fmtCLP(it.precio) + '</td>'
            + '<td class="text-end fw-semibold">$' + fmtCLP(t) + '</td>'
            + '</tr>';
    });
    tbody.innerHTML = html;
    if (resumen) resumen.textContent = items.length + ' ítem(s) · Subtotal ítems $' + fmtCLP(totalSum);
    panel.style.display = '';
  }

  if (selCOT) {
    selCOT.addEventListener('change', function(){
      var opt = selCOT.options[selCOT.selectedIndex];
      if (!opt || !opt.value) {
        pintarItems(null);
        return;
      }
      // Si NO hay una OC seleccionada, el monto lo dicta la cot. Si ya hay OC, no sobrescribimos.
      if (!selOC || !selOC.value) {
        applyMontos(parseFloat(opt.getAttribute('data-total')) || 0);
      }
      // Cliente y RUT siempre se sobrescriben para reflejar la cotización elegida
      var inCon = document.getElementById('ep_contraparte');
      var inRut = document.getElementById('ep_contraparte_rut');
      if (inCon) inCon.value = opt.getAttribute('data-cliente') || '';
      if (inRut) inRut.value = opt.getAttribute('data-rut') || '';
      setIfEmpty('ep_obra_nombre', opt.getAttribute('data-obra') || '');
      var docInput = document.querySelector('input[name="numero_documento"]');
      if (docInput && !docInput.value) docInput.value = 'COT ' + (opt.getAttribute('data-numero') || '');
      // Cargar ítems y construir descripción resumida si está vacía
      pintarItems(opt.value);
      var items = COT_ITEMS[opt.value] || [];
      var inDesc = document.getElementById('ep_descripcion');
      if (inDesc && !inDesc.value && items.length) {
        var resumen = items.map(function(it){
          var d = (it.descripcion || '').trim();
          var c = it.cantidad ? ' (x' + it.cantidad + ')' : '';
          return d ? d + c : '';
        }).filter(Boolean).join(' · ');
        inDesc.value = resumen.length > 240 ? resumen.slice(0,237) + '…' : resumen;
      }
    });
  }
  // Si ya hay una cotización seleccionada (modo edición), pintar sus ítems al cargar
  if (selCOT && selCOT.value) pintarItems(selCOT.value);

  // Modal "Asignar obra" al abrir un nuevo registro — esperar a que Bootstrap esté cargado
  function initModalObra(){
    var modalEl = document.getElementById('modalElegirObra');
    if (!modalEl) return;
    if (typeof bootstrap === 'undefined') {
      setTimeout(initModalObra, 60);
      return;
    }
    var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl, {backdrop:'static'});
    bsModal.show();
    var aceptar = document.getElementById('ep_modal_aceptar');
    var sel = document.getElementById('ep_modal_obra');
    if (aceptar && sel) {
      aceptar.addEventListener('click', function(){
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) { sel.focus(); return; }
        var nom = opt.getAttribute('data-nombre') || '';
        var cod = opt.getAttribute('data-codigo') || '';
        var man = opt.getAttribute('data-mandante') || '';
        if (inObra) inObra.value = nom;
        if (inCod) inCod.value = cod;
        obraIdActual = parseInt(opt.value, 10) || 0;
        var hidObraId = document.getElementById('ep_obra_id');
        if (hidObraId) hidObraId.value = obraIdActual;
        if (ES_COBRO) {
          var inCon = document.getElementById('ep_contraparte');
          if (inCon && !inCon.value) inCon.value = man;
        }
        filtrarCotsPorObra(obraIdActual, nom);

        // Buscar cotización adjudicada de esta obra y autoseleccionarla
        var cotsObra = [];
        if (selCOT) {
          var nomLower = nom.trim().toLowerCase();
          var elegida = null;
          Array.prototype.forEach.call(selCOT.options, function(o){
            if (!o.value) return;
            var oid = parseInt(o.getAttribute('data-obra-id') || '0', 10);
            var oNom = (o.getAttribute('data-obra') || '').trim().toLowerCase();
            if ((oid && oid === obraIdActual) || (nomLower && oNom === nomLower)) {
              cotsObra.push(o);
              if (!elegida && o.getAttribute('data-estado') === 'adjudicada') elegida = o;
            }
          });
          if (elegida) {
            selCOT.value = elegida.value;
            selCOT.dispatchEvent(new Event('change'));
            // 1) Intentar autoseleccionar la OC vinculada en cot_oc_asociaciones
            var elegidoOC = null;
            if (selOC) {
              var links = COT_OC_LINKS[elegida.value] || COT_OC_LINKS[String(elegida.value)] || [];
              if (links.length) {
                var primer = links[0];
                var targetVal = primer.es_externa == 1 ? ('ext_' + primer.id) : String(primer.oc_id || '');
                if (targetVal) {
                  Array.prototype.forEach.call(selOC.options, function(o){
                    if (!elegidoOC && o.value === targetVal) elegidoOC = o;
                  });
                }
              }
              // 2) Fallback: si no hay link, tomar la primera OC visible de la obra
              if (!elegidoOC) {
                Array.prototype.forEach.call(selOC.options, function(o){
                  if (!elegidoOC && o.value && !o.hidden) elegidoOC = o;
                });
              }
              if (elegidoOC) {
                selOC.value = elegidoOC.value;
                selOC.dispatchEvent(new Event('change'));
              }
            }
          }
        }

        // Pintar panel "Documentos asociados a esta obra"
        var panelDocs = document.getElementById('ep_docs_obra_panel');
        var bodyDocs  = document.getElementById('ep_docs_obra_body');
        if (panelDocs && bodyDocs) {
          var ocsObra = [];
          if (selOC) {
            var nomLower2 = nom.trim().toLowerCase();
            Array.prototype.forEach.call(selOC.options, function(o){
              if (!o.value) return;
              var oid = parseInt(o.getAttribute('data-obra-id') || '0', 10);
              var oNom = (o.getAttribute('data-obra') || '').trim().toLowerCase();
              if ((oid && oid === obraIdActual) || (nomLower2 && oNom === nomLower2)) {
                ocsObra.push(o);
              }
            });
          }
          if (cotsObra.length || ocsObra.length) {
            var html = '';
            if (cotsObra.length) {
              html += '<div class="mb-2"><strong><i class="bi bi-file-earmark-text me-1"></i>Cotizaciones (' + cotsObra.length + ')</strong><ul class="mb-0 ps-3">';
              cotsObra.forEach(function(o){
                var est = o.getAttribute('data-estado') || '';
                var badge = est === 'adjudicada' ? ' <span class="badge bg-success">Adjudicada</span>' : (est ? ' <span class="badge bg-secondary">'+est+'</span>' : '');
                html += '<li>COT N° ' + (o.getAttribute('data-numero')||'') + ' — ' + (o.getAttribute('data-cliente')||'') + ' — $' + Math.round(parseFloat(o.getAttribute('data-total')||0)).toLocaleString('es-CL') + badge + '</li>';
              });
              html += '</ul></div>';
            }
            if (ocsObra.length) {
              html += '<div><strong><i class="bi bi-receipt me-1"></i>Órdenes de Compra (' + ocsObra.length + ')</strong><ul class="mb-0 ps-3">';
              ocsObra.forEach(function(o){
                var tipo = o.getAttribute('data-tipo') === 'externa' ? ' <span class="badge bg-warning text-dark">Externa</span>' : '';
                html += '<li>OC N° ' + (o.getAttribute('data-numero')||'') + ' — ' + (o.getAttribute('data-prov')||'') + ' — $' + Math.round(parseFloat(o.getAttribute('data-total')||0)).toLocaleString('es-CL') + tipo + '</li>';
              });
              html += '</ul></div>';
            }
            bodyDocs.innerHTML = html;
            panelDocs.style.display = '';
          } else {
            bodyDocs.innerHTML = '<span class="text-muted">Sin cotizaciones u OC vinculadas a esta obra.</span>';
            panelDocs.style.display = '';
          }
        }
        bsModal.hide();
      });
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initModalObra);
  } else {
    initModalObra();
  }

  // Chevron del card "Editar/Nuevo — Ventas" (plegable)
  var cardForm = document.getElementById('cardFormularioBody');
  var chev = document.getElementById('cardFormularioChevron');
  if (cardForm && chev) {
    cardForm.addEventListener('show.bs.collapse', function(){ chev.style.transform = 'rotate(180deg)'; });
    cardForm.addEventListener('hide.bs.collapse', function(){ chev.style.transform = 'rotate(0deg)'; });
  }
})();
</script>

<script>
function prevFactura(input) {
    var f = input.files[0];
    var img = document.getElementById('prevFacturaImg');
    if (!img) return;
    if (f && f.type.startsWith('image/')) {
        var r = new FileReader();
        r.onload = function(e) { img.src = e.target.result; img.style.display='block'; };
        r.readAsDataURL(f);
    } else {
        img.style.display = 'none';
    }
}
</script>
<?php require_once 'includes/footer.php'; ?>
