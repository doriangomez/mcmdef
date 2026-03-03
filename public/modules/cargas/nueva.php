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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    $file = $_FILES['archivo'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = ['fila' => 0, 'campo' => 'archivo', 'motivo' => 'Error al cargar archivo. Código: ' . $file['error']];
    } else {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            $errors[] = ['fila' => 0, 'campo' => 'archivo', 'motivo' => 'Formato no permitido. Use CSV o XLSX/XLS'];
        } elseif (in_array($extension, ['xlsx', 'xls'], true) && !supports_xlsx_import()) {
            $errors[] = [
                'fila' => 0,
                'campo' => 'archivo',
                'motivo' => 'XLSX/XLS requiere PhpSpreadsheet vía Composer. Alternativa disponible: CSV.',
            ];
        }
    }

    if (empty($errors)) {
        $hash = hash_file('sha256', $file['tmp_name']);
        $exists = $pdo->prepare('SELECT id FROM cargas_cartera WHERE hash_archivo = ? LIMIT 1');
        $exists->execute([$hash]);
        if ($exists->fetch()) {
            $errors[] = ['fila' => 0, 'campo' => 'hash_archivo', 'motivo' => 'Archivo ya cargado previamente'];
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
                count($errors),
                'con_errores',
                'Carga de cartera SAP',
            ]);
            $cargaId = (int)$pdo->lastInsertId();

            if (!empty($errors)) {
                persist_carga_errors($pdo, $cargaId, $errors);
                $msg = 'Se registró la carga con errores de validación. Revise el detalle de errores.';
            } else {
                try {
                    $pdo->beginTransaction();
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
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = ['fila' => 0, 'campo' => 'transacción', 'motivo' => $exception->getMessage()];
                    persist_carga_errors($pdo, $cargaId, $errors);
                    $updateLoad = $pdo->prepare(
                        "UPDATE cargas_cartera
                         SET total_errores = ?, estado = 'con_errores', observaciones = ?, updated_at = NOW()
                         WHERE id = ?"
                    );
                    $updateLoad->execute([count($errors), 'Fallo en procesamiento', $cargaId]);
                }
            }
        }
    }
}

ob_start();
?>
<h1>Nueva carga de cartera</h1>
<?php if($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($errors): ?><div class="alert alert-error"><strong>Errores:</strong><ul><?php foreach($errors as $e): ?><li>Fila <?= $e['fila'] ?> - <?= htmlspecialchars($e['campo']) ?>: <?= htmlspecialchars($e['motivo']) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card">
<form method="post" enctype="multipart/form-data">
    <p><strong>Plantilla esperada (orden exacto):</strong><br>
      nit,nombre_cliente,tipo_documento,numero_documento,fecha_emision,fecha_vencimiento,valor_original,saldo_actual,dias_mora,periodo,canal,regional,asesor_comercial,ejecutivo_cartera,uen,marca
    </p>
    <p>Clave única de documento: <strong>nit + tipo_documento + numero_documento</strong>.</p>
    <p>Días de mora: se usa valor del archivo; si viene vacío, se calcula con la fecha de vencimiento.</p>
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
