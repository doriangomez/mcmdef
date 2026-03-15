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
$totalActualizados = 0;
$totalCerrados = 0;
$totalSaldoInsertado = 0.0;

$kpiStmt = $pdo->query(
    'SELECT
        COUNT(*) AS total_cargas,
        COALESCE(SUM(total_documentos), 0) AS total_documentos,
        COALESCE(SUM(total_saldo), 0) AS total_saldo
     FROM cargas_cartera'
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
     ORDER BY c.fecha_carga DESC, c.id DESC
     LIMIT 1'
);
$ultimaExitosa = $ultimaExitosaStmt ? ($ultimaExitosaStmt->fetch(PDO::FETCH_ASSOC) ?: null) : null;

$historialRecienteStmt = $pdo->query(
    'SELECT c.id, c.fecha_carga, c.nombre_archivo, c.total_documentos, c.total_saldo, c.estado, u.nombre AS usuario
     FROM cargas_cartera c
     LEFT JOIN usuarios u ON u.id = c.usuario_id
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

    if (empty($errors)) {
        $hash = hash('sha256', hash_file('sha256', $file['tmp_name']) . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX));
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
                    $totalInsertados = (int)($metrics['new_count'] ?? 0);
                    $totalActualizados = (int)($metrics['updated_count'] ?? 0);
                    $totalCerrados = (int)($metrics['closed_count'] ?? 0);
                    $totalSaldoInsertado = (float)($validation['totals']['saldo'] ?? 0.0);

                    audit_log($pdo, 'cargas_cartera', $cargaId, 'carga_creada', null, 'activa', (int)$_SESSION['user']['id']);
                    $pdo->commit();
                    $estadoCarga = 'exitosa';
                    $msg = 'Carga exitosa. Nuevos: ' . $totalInsertados . ', actualizados: ' . $totalActualizados . ', cerrados: ' . $totalCerrados . '. Valor total del corte: $' . number_format($totalSaldoInsertado, 2, ',', '.') . '.';
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

ob_start();
?>
<h1>Nueva carga de cartera</h1>
<?php if($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($errors): ?><div class="alert alert-error"><strong>Errores de validación:</strong><ul><?php foreach($errors as $e): ?><li>Fila <?= (int)($e['fila'] ?? 0) ?> - Campo <?= htmlspecialchars((string)($e['campo'] ?? '')) ?> - Valor "<?= htmlspecialchars((string)($e['valor'] ?? '')) ?>": <?= htmlspecialchars((string)($e['motivo'] ?? '')) ?></li><?php endforeach; ?></ul><?php if (!empty($errorReportToken)): ?><p><a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('cargas/nueva.php?download_errors=' . $errorReportToken)) ?>">Descargar reporte de errores (CSV)</a></p><?php endif; ?></div><?php endif; ?>
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
  <form method="post" enctype="multipart/form-data" id="uploadCarteraForm" novalidate>
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
