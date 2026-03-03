<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ExcelImportService.php';
require_once __DIR__ . '/../../../app/libraries/SimpleXLSX.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';

require_role(['admin', 'analista']);
$msg = '';
$errors = [];
$cargaId = null;
$allowedExtensions = ['csv', 'xlsx', 'xls'];
$summary = ['total' => 0, 'validas' => 0, 'con_error' => 0];
$errorReportToken = null;
$estadoCarga = '';
$hayErrores = false;
$hayErrorEstructural = false;
$totalInsertados = 0;
$totalSaldoInsertado = 0.0;

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

    if (empty($errors)) {
        $hash = hash_file('sha256', $file['tmp_name']);
        $exists = $pdo->prepare('SELECT id FROM cargas_cartera WHERE hash_archivo = ? LIMIT 1');
        $exists->execute([$hash]);
        if ($exists->fetch()) {
            $errors[] = build_validation_error(0, 'hash_archivo', $file['name'] ?? '', 'Archivo ya cargado previamente');
            $hayErrores = true;
        } else {
            try {
                $rows = parse_input_file($file['tmp_name'], $extension);
            } catch (Throwable $exception) {
                $errors[] = build_validation_error(0, 'archivo', $file['name'] ?? '', $exception->getMessage());
                $rows = [];
                $hayErrores = true;
                $hayErrorEstructural = true;
            }

            $validation = validate_cartera_rows($rows);
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
                    }
                } catch (Throwable $exception) {
                    $errors[] = build_validation_error(0, 'base_datos', '', 'No fue posible validar duplicados: ' . $exception->getMessage());
                    $hayErrores = true;
                    $hayErrorEstructural = true;
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

            try {
                $pdo->beginTransaction();

                if ($hayErrores) {
                    $pdo->rollBack();
                    $estadoCarga = 'rechazada';
                    $errorReportToken = bin2hex(random_bytes(16));
                    $_SESSION['import_error_reports'][$errorReportToken] = $errors;
                    $msg = $hayErrorEstructural
                        ? 'Carga rechazada por error estructural. No se insertó ningún registro.'
                        : 'Carga rechazada. No se insertó ningún registro.';
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
                    $cargaId = (int)$pdo->lastInsertId();

                    $metrics = process_cartera_records($pdo, $cargaId, $validation['records']);
                    $totalInsertados = (int)$metrics['new_count'];
                    $totalSaldoInsertado = (float)($validation['totals']['saldo'] ?? 0.0);

                    audit_log($pdo, 'cargas_cartera', $cargaId, 'carga_creada', null, 'activa', (int)$_SESSION['user']['id']);
                    $pdo->commit();
                    $estadoCarga = 'exitosa';
                    $msg = 'Carga exitosa. Se insertaron ' . $totalInsertados . ' documentos por valor total de $' . number_format($totalSaldoInsertado, 2, ',', '.') . '.';
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = build_validation_error(0, 'transacción', '', $exception->getMessage());
                $estadoCarga = 'rechazada';
                $msg = 'Carga rechazada. No se insertó ningún registro.';
            }
        }
    }
}

ob_start();
?>
<h1>Nueva carga de cartera</h1>
<?php if($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($errors): ?><div class="alert alert-error"><strong>Errores de validación:</strong><ul><?php foreach($errors as $e): ?><li>Fila <?= (int)($e['fila'] ?? 0) ?> - Campo <?= htmlspecialchars((string)($e['campo'] ?? '')) ?> - Valor "<?= htmlspecialchars((string)($e['valor'] ?? '')) ?>": <?= htmlspecialchars((string)($e['motivo'] ?? '')) ?></li><?php endforeach; ?></ul><?php if (!empty($errorReportToken)): ?><p><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cargas/nueva.php?download_errors=' . $errorReportToken)) ?>">Descargar reporte de errores (CSV)</a></p><?php endif; ?></div><?php endif; ?>
<?php if($estadoCarga === 'exitosa' && $summary['total'] > 0): ?><div class="card"><strong>Resumen:</strong> Total filas: <?= (int)$summary['total'] ?> | Filas con error: <?= (int)$summary['con_error'] ?></div><?php endif; ?>
<div class="card">
<form method="post" enctype="multipart/form-data" id="uploadCarteraForm" novalidate>
    <p><strong>Plantilla esperada (orden exacto):</strong><br>
      #,cuenta,cliente,nit,direccion,contacto,telefono,canal,empleado_de_ventas,regional,nro_documento,nro_ref_de_cliente,tipo,fecha_contabilizacion,fecha_vencimiento,valor_documento,saldo_pendiente,moneda,dias_vencido,actual,1_30_dias,31_60_dias,61_90_dias,91_180_dias,181_360_dias,361_dias
    </p>
    <p>Reglas aplicadas: modelo inmutable (solo INSERT), lote obligatorio, y procesamiento batch de 1000 registros.</p>
    <input type="file" name="archivo" accept=".csv,.xlsx,.xls" required>
    <button class="btn" type="submit" id="uploadSubmitBtn">Validar y procesar</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cargas/historial.php')) ?>">Ver historial</a>
</form>
</div>

<?php if ($cargaId): ?>
    <p><a href="<?= htmlspecialchars(app_url('cargas/detalle.php?id=' . $cargaId)) ?>">Abrir detalle de la carga #<?= $cargaId ?></a></p>
<?php endif; ?>

<?php
$content = ob_get_clean();
render_layout('Carga cartera', $content);
