<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ExcelImportService.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';

require_role(['admin', 'analista']);
$msg = '';
$errors = [];
$cargaId = null;
$allowedExtensions = ['csv', 'xlsx', 'xls'];
$summary = ['total' => 0, 'validas' => 0, 'con_error' => 0];
$errorReportToken = null;

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
        } elseif (in_array($extension, ['xlsx', 'xls'], true) && !supports_xlsx_import()) {
            $errors[] = build_validation_error(0, 'archivo', $file['name'] ?? '', 'XLSX/XLS requiere PhpSpreadsheet vía Composer. Alternativa disponible: CSV.');
        }
    }

    if (empty($errors)) {
        $hash = hash_file('sha256', $file['tmp_name']);
        $exists = $pdo->prepare('SELECT id FROM cargas_cartera WHERE hash_archivo = ? LIMIT 1');
        $exists->execute([$hash]);
        if ($exists->fetch()) {
            $errors[] = build_validation_error(0, 'hash_archivo', $file['name'] ?? '', 'Archivo ya cargado previamente');
        } else {
            $rows = parse_input_file($file['tmp_name']);
            $validation = validate_cartera_rows($rows);
            $errors = $validation['errors'];
            if (empty($errors)) {
                $errors = array_merge($errors, validate_duplicate_keys_in_db($pdo, $validation['records']));
            }
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
                'validas' => 0,
            ];
            $summary['validas'] = max(0, $summary['total'] - $summary['con_error']);

            try {
                $pdo->beginTransaction();

                if (!empty($errors)) {
                    $pdo->rollBack();
                    $errorReportToken = bin2hex(random_bytes(16));
                    $_SESSION['import_error_reports'][$errorReportToken] = $errors;
                    $msg = 'Validación fallida. No se insertó ningún registro. Descargue el reporte para revisar errores.';
                } else {
                    $insertLoad = $pdo->prepare(
                        'INSERT INTO cargas_cartera
                         (nombre_archivo, hash_archivo, usuario_id, fecha_carga, total_registros, total_errores, total_nuevos, total_actualizados, estado, observaciones, created_at, updated_at)
                         VALUES (?, ?, ?, NOW(), ?, ?, 0, 0, ?, ?, NOW(), NOW())'
                    );
                    $insertLoad->execute([
                        $file['name'],
                        $hash,
                        $_SESSION['user']['id'],
                        $recordCount,
                        0,
                        'procesado',
                        'Carga de cartera SAP',
                    ]);
                    $cargaId = (int)$pdo->lastInsertId();

                    $metrics = process_cartera_records($pdo, $cargaId, $validation['records']);

                    $updateLoad = $pdo->prepare(
                        "UPDATE cargas_cartera
                         SET total_nuevos = ?, total_actualizados = ?, estado = 'procesado', observaciones = ?, updated_at = NOW()
                         WHERE id = ?"
                    );
                    $updateLoad->execute([
                        $metrics['new_count'],
                        $metrics['updated_count'],
                        'Carga procesada correctamente',
                        $cargaId,
                    ]);
                    audit_log($pdo, 'cargas_cartera', $cargaId, 'estado', 'con_errores', 'procesado', (int)$_SESSION['user']['id']);
                    $pdo->commit();
                    $msg = 'Carga procesada. ID #' . $cargaId
                        . ' | Nuevos: ' . $metrics['new_count']
                        . ' | Actualizados: ' . $metrics['updated_count'];
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = build_validation_error(0, 'transacción', '', $exception->getMessage());
            }
        }
    }
}

ob_start();
?>
<h1>Nueva carga de cartera</h1>
<?php if($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($errors): ?><div class="alert alert-error"><strong>Errores de validación:</strong><ul><?php foreach($errors as $e): ?><li>Fila <?= (int)($e['fila'] ?? 0) ?> - Campo <?= htmlspecialchars((string)($e['campo'] ?? '')) ?> - Valor "<?= htmlspecialchars((string)($e['valor'] ?? '')) ?>": <?= htmlspecialchars((string)($e['motivo'] ?? '')) ?></li><?php endforeach; ?></ul><?php if (!empty($errorReportToken)): ?><p><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cargas/nueva.php?download_errors=' . $errorReportToken)) ?>">Descargar reporte de errores (CSV)</a></p><?php endif; ?></div><?php endif; ?>
<?php if($summary['total'] > 0): ?><div class="card"><strong>Resumen:</strong> Total filas: <?= (int)$summary['total'] ?> | Filas válidas: <?= (int)$summary['validas'] ?> | Filas con error: <?= (int)$summary['con_error'] ?></div><?php endif; ?>
<div class="card">
<form method="post" enctype="multipart/form-data">
    <p><strong>Plantilla esperada (orden exacto):</strong><br>
      #,cuenta,cliente,nit,direccion,contacto,telefono,canal,empleado_de_ventas,regional,nro_documento,nro_ref_de_cliente,tipo,fecha_contabilizacion,fecha_vencimiento,valor_documento,saldo_pendiente,moneda,dias_vencido,actual,1_30_dias,31_60_dias,61_90_dias,91_180_dias,181_360_dias,361_plus_dias
    </p>
    <p>Clave única de documento: <strong>cuenta + nro_documento + tipo + fecha_contabilizacion</strong>.</p>
    <p>Días vencido: se usa valor del archivo; si viene vacío, se calcula con fecha_vencimiento. Se permite saldo_pendiente negativo y nro_ref_de_cliente puede venir vacío.</p>
    <input type="file" name="archivo" accept=".csv,.xlsx,.xls" required>
    <button class="btn" type="submit">Validar y procesar</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cargas/historial.php')) ?>">Ver historial</a>
</form>
</div>
<?php if ($cargaId): ?>
    <p><a href="<?= htmlspecialchars(app_url('cargas/detalle.php?id=' . $cargaId)) ?>">Abrir detalle de la carga #<?= $cargaId ?></a></p>
<?php endif; ?>
<?php
$content = ob_get_clean();
render_layout('Carga cartera', $content);
