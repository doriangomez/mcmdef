<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ExcelImportService.php';
require_once __DIR__ . '/../../../app/libraries/SimpleXLSX.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';
require_once __DIR__ . '/../../../app/services/PeriodoControlService.php';

require_role(['admin', 'analista']);
$msg = '';
$errors = [];
$validationResult = ['ok' => true, 'structural_error' => false, 'errors' => [], 'records' => [], 'totals' => ['saldo' => 0.0, 'buckets' => 0.0, 'documentos' => 0]];
$cargaId = null;
$allowedExtensions = ['csv', 'xlsx', 'xls'];
$summary = ['total' => 0, 'validas' => 0, 'con_error' => 0];
$errorReportToken = null;
$estadoCarga = '';
$hayErrores = false;
$hayErrorEstructural = false;
$totalInsertados = 0;
$totalActualizados = 0;
$totalCerrados = 0;
$totalSaldoInsertado = 0.0;
$periodoDetectadoCartera = null;


function detect_periodo_from_records(array $records): ?string
{
    $counter = [];
    foreach ($records as $record) {
        $fecha = (string)($record['fecha_contabilizacion'] ?? '');
        if (strlen($fecha) < 7) {
            continue;
        }
        $periodo = substr($fecha, 0, 7);
        if (!isset($counter[$periodo])) {
            $counter[$periodo] = 0;
        }
        $counter[$periodo]++;
    }

    if (empty($counter)) {
        return null;
    }

    arsort($counter);
    return (string)array_key_first($counter);
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function sync_validation_result(array &$validationResult, array $errors, bool $structuralError = false, ?array $baseResult = null): void
{
    if ($baseResult !== null) {
        $validationResult = array_merge($validationResult, $baseResult);
    }

    $validationResult['errors'] = $errors;
    $validationResult['ok'] = empty($errors);
    $validationResult['structural_error'] = $structuralError || (bool)($validationResult['structural_error'] ?? false);
}

function finalize_validation_result(
    array $validationResult,
    array $errors,
    bool $structuralError = false,
    string $fallbackMessage = 'El archivo cargado no coincide con la plantilla esperada. Verifique la estructura e intente nuevamente.'
): array {
    $mergedErrors = [];
    foreach ([$validationResult['errors'] ?? [], $errors] as $errorGroup) {
        if (!is_array($errorGroup)) {
            continue;
        }
        foreach ($errorGroup as $error) {
            if (!is_array($error)) {
                continue;
            }

            $signature = json_encode([
                'fila' => (int)($error['fila'] ?? 0),
                'campo' => (string)($error['campo'] ?? ''),
                'valor' => (string)($error['valor'] ?? ''),
                'motivo' => (string)($error['motivo'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($signature === false || isset($mergedErrors[$signature])) {
                continue;
            }

            $mergedErrors[$signature] = $error;
        }
    }

    if (empty($mergedErrors) && ($structuralError || (($validationResult['ok'] ?? true) === false))) {
        $fallbackError = build_validation_error(0, 'archivo', '', $fallbackMessage);
        $signature = json_encode($fallbackError, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($signature !== false) {
            $mergedErrors[$signature] = $fallbackError;
        }
    }

    $validationResult['errors'] = array_values($mergedErrors);
    $validationResult['ok'] = empty($validationResult['errors']);
    $validationResult['structural_error'] = $structuralError || (bool)($validationResult['structural_error'] ?? false);

    return $validationResult;
}

if (isset($_SESSION['flash_carga_ok'])) {
    $msg = (string)$_SESSION['flash_carga_ok'];
    unset($_SESSION['flash_carga_ok']);
}

if (isset($_SESSION['flash_carga_error']) && is_array($_SESSION['flash_carga_error'])) {
    $flashError = $_SESSION['flash_carga_error'];
    unset($_SESSION['flash_carga_error']);

    $msg = (string)($flashError['message'] ?? 'Carga rechazada. No se insertó ningún registro.');
    $estadoCarga = 'rechazada';
    $hayErrores = true;
    $hayErrorEstructural = (bool)($flashError['structural_error'] ?? false);
    $errorReportToken = is_string($flashError['error_report_token'] ?? null) ? $flashError['error_report_token'] : null;

    if (isset($flashError['validation_result']) && is_array($flashError['validation_result'])) {
        $validationResult = finalize_validation_result(
            $flashError['validation_result'],
            $flashError['validation_result']['errors'] ?? [],
            $hayErrorEstructural
        );
        $errors = $validationResult['errors'] ?? [];
    }
}

$kpiStmt = $pdo->query(
    'SELECT
        COUNT(*) AS total_cargas,
        COALESCE(SUM(total_documentos), 0) AS total_documentos,
        COALESCE(SUM(total_saldo), 0) AS total_saldo
     FROM cargas_cartera
     WHERE activo = 1
       AND estado = "activa"'
);
$kpiData = $kpiStmt ? ($kpiStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

$ultimaCargaStmt = $pdo->query(
    'SELECT c.fecha_carga, c.total_documentos, c.total_saldo, c.estado, u.nombre AS usuario
     FROM cargas_cartera c
     LEFT JOIN usuarios u ON u.id = c.usuario_id
     ORDER BY c.fecha_carga DESC, c.id DESC
     LIMIT 1'
);
$ultimaCarga = $ultimaCargaStmt ? ($ultimaCargaStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;

$ultimaExitosaStmt = $pdo->query(
    'SELECT c.fecha_carga, c.total_documentos, c.total_saldo, u.nombre AS usuario
     FROM cargas_cartera c
     LEFT JOIN usuarios u ON u.id = c.usuario_id
     WHERE c.estado = "activa"
       AND c.activo = 1
     ORDER BY c.fecha_carga DESC, c.id DESC
     LIMIT 1'
);
$ultimaExitosa = $ultimaExitosaStmt ? ($ultimaExitosaStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;

$historialRecienteStmt = $pdo->query(
    'SELECT c.id, c.fecha_carga, c.nombre_archivo, c.total_documentos, c.total_saldo, c.estado, u.nombre AS usuario
     FROM cargas_cartera c
     LEFT JOIN usuarios u ON u.id = c.usuario_id
     WHERE c.activo = 1
       AND c.estado = "activa"
     ORDER BY c.fecha_carga DESC, c.id DESC
     LIMIT 20'
);
$historialReciente = $historialRecienteStmt ? $historialRecienteStmt->fetchAll(PDO::FETCH_ASSOC) : [];

if (isset($_GET['download_errors'])) {
    $token = (string)($_GET['download_errors'] ?? '');
    $stored = $_SESSION['import_error_reports'][$token] ?? null;
    if (!is_array($stored)) {
        http_response_code(404);
        exit('Reporte no disponible o expirado.');
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_errores_validacion.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Fila', 'Campo', 'Valor', 'Descripción del error']);
    foreach ($stored as $row) {
        fputcsv($out, [
            (int)($row['fila'] ?? 0),
            (string)($row['campo'] ?? ''),
            (string)($row['valor'] ?? ''),
            (string)($row['motivo'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    $file = $_FILES['archivo'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = build_validation_error(0, 'archivo', (string)($file['error'] ?? ''), 'Error al cargar archivo. Código: ' . $file['error']);
    } else {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = build_validation_error(0, 'archivo', $file['name'] ?? '', 'Formato no permitido. Use CSV o XLSX/XLS');
        }
    }

    if (!empty($errors)) {
        $hayErrores = true;
        $hayErrorEstructural = true;
        sync_validation_result($validationResult, $errors, true);
    }

    if (empty($errors)) {
        $hash = hash_file('sha256', (string)$file['tmp_name']) ?: '';
        if ($hash === '') {
            $errors[] = build_validation_error(0, 'archivo', (string)($file['name'] ?? ''), 'No fue posible calcular hash SHA-256 del archivo cargado.');
            $hayErrores = true;
            $hayErrorEstructural = true;
            sync_validation_result($validationResult, $errors, true);
        }
            try {
                $rows = parse_input_file($file['tmp_name'], $extension);
            } catch (Throwable $exception) {
                $errors[] = build_validation_error(0, 'archivo', $file['name'] ?? '', $exception->getMessage());
                $rows = [];
                $hayErrores = true;
                $hayErrorEstructural = true;
                sync_validation_result($validationResult, $errors, true);
            }

            $validation = validate_cartera_rows($rows);
            $validationResult = $validation;
            $errors = array_merge($errors, $validation['errors'] ?? []);
            $hayErrorEstructural = (bool)($validation['structural_error'] ?? false);
            if ($hayErrorEstructural || !empty($errors)) {
                $hayErrores = true;
            }

            if (!$hayErrorEstructural && empty($errors)) {
                try {
                    if (!table_exists($pdo, 'cartera_documentos')) {
                        throw new RuntimeException('La tabla cartera_documentos no existe. Ejecute sql/schema.sql antes de cargar archivos.');
                    }

                    $duplicateErrors = validate_duplicate_keys_in_db($pdo, $validation['records']);
                    if (!empty($duplicateErrors)) {
                        $errors = array_merge($errors, $duplicateErrors);
                        $hayErrores = true;
                        ensure_validation_feedback($validationResult, $errors, $hayErrorEstructural);
                    }

                    $periodos = [];
                    foreach ($validation['records'] as $record) {
                        $periodo = substr((string)($record['fecha_contabilizacion'] ?? ''), 0, 7);
                        if (periodo_normalizar($periodo) !== '') {
                            $periodos[$periodo] = true;
                        }
                    }
                    if (!empty($periodos)) {
                        ksort($periodos);
                        $periodoDetectadoCartera = array_key_last($periodos);
                        $chronologyError = periodo_control_validar_cronologia_cartera($pdo, $periodoDetectadoCartera);
                        if ($chronologyError !== null) {
                            $errors[] = build_validation_error(0, 'periodo', $periodoDetectadoCartera, $chronologyError);
                            $hayErrores = true;
                            ensure_validation_feedback($validationResult, $errors, $hayErrorEstructural);
                        }
                    }
                } catch (Throwable $exception) {
                    $errors[] = build_validation_error(0, 'base_datos', '', 'No fue posible validar duplicados: ' . $exception->getMessage());
                    $hayErrores = true;
                    $hayErrorEstructural = true;
                    ensure_validation_feedback($validationResult, $errors, true);
                }
            }

            if (!$hayErrorEstructural) {
                $recordCount = 0;
                for ($i = 1, $totalRows = count($rows); $i < $totalRows; $i++) {
                    $hasData = false;
                    foreach ($rows[$i] as $value) {
                        if (trim((string)$value) !== '') {
                            $hasData = true;
                            break;
                        }
                    }
                    if ($hasData) {
                        $recordCount++;
                    }
                }
                $summary = [
                    'total' => $recordCount,
                    'con_error' => count(array_unique(array_map(static fn($e): int => (int)($e['fila'] ?? 0), array_filter($errors, static fn($e): bool => (int)($e['fila'] ?? 0) > 1)))),
                    'validas' => max(0, $recordCount - count(array_unique(array_map(static fn($e): int => (int)($e['fila'] ?? 0), array_filter($errors, static fn($e): bool => (int)($e['fila'] ?? 0) > 1))))),
                ];
            }

            $periodoDetectado = null;
            if (!$hayErrorEstructural && isset($validation['records']) && is_array($validation['records'])) {
                $periodoDetectado = detect_periodo_from_records($validation['records']);
                if ($periodoDetectado === null) {
                    $errors[] = build_validation_error(0, 'periodo', '', 'No fue posible detectar el periodo del archivo desde fecha_contabilizacion.');
                    $hayErrores = true;
                    $hayErrorEstructural = true;
                    ensure_validation_feedback($validationResult, $errors, true);
                }
            }

            $ultimoPeriodo = null;
            $hasHashSha = column_exists($pdo, 'cargas_cartera', 'hash_sha256');
            $tieneCamposVersionado = $hasHashSha && column_exists($pdo, 'cargas_cartera', 'periodo_detectado') && column_exists($pdo, 'cargas_cartera', 'version') && column_exists($pdo, 'cargas_cartera', 'activo');

            if (!$hayErrorEstructural && !$hayErrores && $periodoDetectado !== null && $tieneCamposVersionado) {
                $lastStmt = $pdo->query("SELECT MAX(periodo_detectado) FROM cargas_cartera WHERE estado = 'activa' AND activo = 1 AND periodo_detectado IS NOT NULL AND periodo_detectado <> ''");
                $ultimoPeriodo = (string)($lastStmt->fetchColumn() ?: '');
                if ($ultimoPeriodo !== '' && $periodoDetectado < $ultimoPeriodo) {
                    $errors[] = build_validation_error(0, 'periodo', $periodoDetectado, 'Advertencia: el periodo detectado es anterior al último cargado (' . $ultimoPeriodo . '). La carga fue bloqueada.');
                    $hayErrores = true;
                    ensure_validation_feedback($validationResult, $errors, $hayErrorEstructural);
                }
            }

            if ($hayErrores) {
                $estadoCarga = 'rechazada';
                $validationResult = finalize_validation_result($validationResult, $errors, $hayErrorEstructural);
                $errors = $validationResult['errors'] ?? [];
                $errorReportToken = bin2hex(random_bytes(16));
                ensure_validation_feedback($validationResult, $errors, $hayErrorEstructural);
                $_SESSION['import_error_reports'][$errorReportToken] = $errors;
                $msg = $hayErrorEstructural
                    ? 'Carga rechazada por error estructural. No se insertó ningún registro.'
                    : 'Carga rechazada. No se insertó ningún registro.';
                $_SESSION['flash_carga_error'] = [
                    'message' => $msg,
                    'structural_error' => $hayErrorEstructural,
                    'validation_result' => $validationResult,
                    'error_report_token' => $errorReportToken,
                ];
                header('Location: ' . app_url('cargas/nueva.php?status=error'));
                exit;
            } else {
                ensure_client_management_schema($pdo);

                try {
                    if (!$pdo->inTransaction()) {
                        $pdo->beginTransaction();
                    }

                    if ($tieneCamposVersionado) {
                        $insertLoad = $pdo->prepare(
                            'INSERT INTO cargas_cartera
                             (fecha_carga, usuario_id, nombre_archivo, total_documentos, total_saldo, hash_archivo, hash_sha256, periodo_detectado, version, activo, estado, created_at)
                             VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
                        );
                        $insertLoad->execute([
                            $_SESSION['user']['id'],
                            $file['name'],
                            count($validation['records']),
                            (float)($validation['totals']['saldo'] ?? 0),
                            $hash,
                            $hash,
                            $periodoDetectado,
                            1,
                            1,
                            'activa',
                        ]);
                    } else {
                        $insertLoad = $pdo->prepare(
                            'INSERT INTO cargas_cartera
                             (fecha_carga, usuario_id, nombre_archivo, total_documentos, total_saldo, hash_archivo, estado, created_at)
                             VALUES (NOW(), ?, ?, ?, ?, ?, ?, NOW())'
                        );
                        $insertLoad->execute([
                            $_SESSION['user']['id'],
                            $file['name'],
                            count($validation['records']),
                            (float)($validation['totals']['saldo'] ?? 0),
                            $hash,
                            'activa',
                        ]);
                    }
                    $cargaId = (int)$pdo->lastInsertId();

                    if ($tieneCamposVersionado && $periodoDetectado !== null) {
                        $deactivateStmt = $pdo->prepare('UPDATE cargas_cartera SET activo = 0 WHERE periodo_detectado = ? AND id <> ?');
                        $deactivateStmt->execute([$periodoDetectado, $cargaId]);
                    }

                    $metrics = process_cartera_records($pdo, $cargaId, $validation['records']);
                    if ($tieneCamposVersionado && $periodoDetectado !== null) {
                        periodo_control_registrar_cartera($pdo, $periodoDetectado, true);
                    }
                    $totalInsertados = (int)($metrics['new_count'] ?? 0);
                    $totalActualizados = (int)($metrics['updated_count'] ?? 0);
                    $totalCerrados = (int)($metrics['closed_count'] ?? 0);
                    $totalSaldoInsertado = (float)($validation['totals']['saldo'] ?? 0.0);

                    audit_log($pdo, 'cargas_cartera', $cargaId, 'carga_creada', null, 'activa', (int)$_SESSION['user']['id']);

                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    $estadoCarga = 'exitosa';
                    $_SESSION['flash_carga_ok'] = 'Carga exitosa. Nuevos: ' . $totalInsertados . ', actualizados: ' . $totalActualizados . ', cerrados: ' . $totalCerrados . '. Valor total del corte: $' . number_format($totalSaldoInsertado, 2, ',', '.') . '.';
                    header('Location: ' . app_url('cargas/nueva.php?status=ok'));
                    exit;
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = build_validation_error(0, 'base_de_datos', '', $exception->getMessage());
                    $estadoCarga = 'rechazada';
                    $validationResult = finalize_validation_result($validationResult, $errors, $hayErrorEstructural);
                    $errors = $validationResult['errors'] ?? [];
                    $msg = 'Carga rechazada. No se insertó ningún registro.';
                    $errorReportToken = bin2hex(random_bytes(16));
                    $_SESSION['import_error_reports'][$errorReportToken] = $errors;
                    $_SESSION['flash_carga_error'] = [
                        'message' => $msg,
                        'structural_error' => $hayErrorEstructural,
                        'validation_result' => $validationResult,
                        'error_report_token' => $errorReportToken,
                    ];
                    header('Location: ' . app_url('cargas/nueva.php?status=error'));
                    exit;
                }
            }
    }
}

$validationResult = finalize_validation_result($validationResult, $errors, $hayErrorEstructural || $hayErrores);
$validationErrors = $validationResult['errors'] ?? [];

if (!empty($validationErrors)) {
    if ($estadoCarga === '') {
        $estadoCarga = 'rechazada';
    }
    if ($msg === '') {
        $msg = (bool)($validationResult['structural_error'] ?? false)
            ? 'Carga rechazada por error estructural. No se insertó ningún registro.'
            : 'Carga rechazada. No se insertó ningún registro.';
    }
}

ob_start();
?>
<h1>Nueva carga de cartera</h1>
<?php if($msg): ?><div class="alert <?= $estadoCarga === 'rechazada' ? 'alert-error' : 'alert-ok' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if(!empty($validationErrors)): ?><div class="alert alert-error"><strong>Errores de validación:</strong><ul><?php foreach($validationErrors as $e): ?><li>Fila <?= (int)($e['fila'] ?? 0) ?> - Campo <?= htmlspecialchars((string)($e['campo'] ?? '')) ?> - Valor "<?= htmlspecialchars((string)($e['valor'] ?? '')) ?>": <?= htmlspecialchars((string)($e['motivo'] ?? '')) ?></li><?php endforeach; ?></ul><?php if (!empty($errorReportToken)): ?><p><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cargas/nueva.php?download_errors=' . $errorReportToken)) ?>">Descargar reporte de errores (CSV)</a></p><?php endif; ?></div><?php endif; ?>
<?php if ($ultimaExitosa): ?>
  <div class="card carga-highlight-success">
    <i class="fa-solid fa-circle-check"></i>
    <p>
      <strong>Última carga exitosa:</strong>
      <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$ultimaExitosa['fecha_carga']))) ?>
      por <?= htmlspecialchars((string)($ultimaExitosa['usuario'] ?? 'Usuario no identificado')) ?>
      con <?= (int)$ultimaExitosa['total_documentos'] ?> documentos por valor de
      $<?= number_format((float)$ultimaExitosa['total_saldo'], 2, ',', '.') ?>.
    </p>
  </div>
<?php endif; ?>
<section class="control-kpi-grid" aria-label="Resumen rápido de cargas">
  <article class="control-kpi-card">
    <div class="control-kpi-icon"><i class="fa-solid fa-layer-group"></i></div>
    <p class="control-kpi-label">Total cargas realizadas</p>
    <p class="control-kpi-value"><?= number_format((int)($kpiData['total_cargas'] ?? 0), 0, ',', '.') ?></p>
  </article>
  <article class="control-kpi-card">
    <div class="control-kpi-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
    <p class="control-kpi-label">Última carga</p>
    <p class="control-kpi-value control-kpi-value-sm">
      <?php if ($ultimaCarga): ?>
        <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$ultimaCarga['fecha_carga']))) ?>
      <?php else: ?>
        Sin registros
      <?php endif; ?>
    </p>
    <p class="control-kpi-subtext">
      Usuario: <?= htmlspecialchars((string)($ultimaCarga['usuario'] ?? 'N/A')) ?>
    </p>
  </article>
  <article class="control-kpi-card">
    <div class="control-kpi-icon"><i class="fa-solid fa-file-lines"></i></div>
    <p class="control-kpi-label">Documentos cargados históricos</p>
    <p class="control-kpi-value"><?= number_format((int)($kpiData['total_documentos'] ?? 0), 0, ',', '.') ?></p>
  </article>
  <article class="control-kpi-card">
    <div class="control-kpi-icon"><i class="fa-solid fa-sack-dollar"></i></div>
    <p class="control-kpi-label">Saldo histórico cargado</p>
    <p class="control-kpi-value">$<?= number_format((float)($kpiData['total_saldo'] ?? 0), 2, ',', '.') ?></p>
  </article>
</section>
<?php if($estadoCarga === 'exitosa' && $summary['total'] > 0): ?><div class="card"><strong>Resumen:</strong> Total filas: <?= (int)$summary['total'] ?> | Filas con error: <?= (int)$summary['con_error'] ?></div><?php endif; ?>
<section class="card carga-control-form-card">
  <div class="card-header carga-form-header">
    <h3>Centro de control de cargas</h3>
    <span class="badge badge-info">Batch seguro</span>
  </div>
  <form method="post" enctype="multipart/form-data" id="uploadCarteraForm" class="form-carga" novalidate>
      <p class="carga-template"><strong>Plantilla esperada (orden exacto):</strong><br>
        #,cuenta,cliente,nit,direccion,contacto,telefono,canal,empleado_de_ventas,regional,nro_documento,nro_ref_de_cliente,tipo,fecha_contabilizacion,fecha_vencimiento,valor_documento,saldo_pendiente,moneda,dias_vencido,actual,1_30_dias,31_60_dias,61_90_dias,91_180_dias,181_360_dias,361_dias
      </p>
      <p class="carga-rules">Reglas aplicadas: upsert por llave lógica (cuenta+nro_documento+tipo), cierre de documentos no reportados en el nuevo corte y procesamiento batch de 1000 registros.</p>
      <div class="carga-form-actions">
        <input type="file" name="archivo" accept=".csv,.xlsx,.xls" required>
        <button class="btn carga-btn-primary" type="submit" id="uploadSubmitBtn"><i class="fa-solid fa-play"></i> Validar y procesar</button>
        <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cargas/historial.php')) ?>">Ver historial completo</a>
      </div>
      <p class="carga-helper-text">Procesamiento batch seguro con validación estructural y control de duplicados.</p>
  </form>
</section>

<section class="card">
  <div class="card-header">
    <h3>Historial reciente de cargas</h3>
  </div>
  <?php if (!empty($historialReciente)): ?>
    <table class="table table-cargas-recientes">
      <thead>
      <tr>
        <th>ID</th>
        <th>Fecha y hora</th>
        <th>Usuario</th>
        <th>Archivo</th>
        <th>Documentos</th>
        <th>Saldo</th>
        <th>Estado</th>
        <th>Acción</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($historialReciente as $item): ?>
        <tr>
          <td><?= (int)$item['id'] ?></td>
          <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$item['fecha_carga']))) ?></td>
          <td><?= htmlspecialchars((string)($item['usuario'] ?? 'N/A')) ?></td>
          <td><?= htmlspecialchars((string)$item['nombre_archivo']) ?></td>
          <td><?= number_format((int)$item['total_documentos'], 0, ',', '.') ?></td>
          <td>$<?= number_format((float)$item['total_saldo'], 2, ',', '.') ?></td>
          <td><?= (($item['estado'] ?? '') === 'activa') ? ui_badge('Activa', 'success') : ui_badge('Rechazada', 'danger') ?></td>
          <td><a href="<?= htmlspecialchars(app_url('cargas/detalle.php?id=' . (int)$item['id'])) ?>">Ver detalle</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="carga-empty-state">
      <i class="fa-regular fa-folder-open"></i>
      <p>No hay cargas registradas.</p>
    </div>
  <?php endif; ?>
</section>

<?php if ($cargaId): ?>
    <p><a href="<?= htmlspecialchars(app_url('cargas/detalle.php?id=' . $cargaId)) ?>">Abrir detalle de la carga #<?= $cargaId ?></a></p>
<?php endif; ?>

<?php
$content = ob_get_clean();
render_layout('Carga cartera', $content);
