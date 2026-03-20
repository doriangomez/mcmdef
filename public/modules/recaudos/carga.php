<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/RecaudoImportService.php';
require_once __DIR__ . '/../../../app/services/AuditService.php';

require_role(['admin', 'analista']);

$msg = '';
$errorMsg = '';
$processResult = null;
$detail = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_load') {
    $cargaId = (int)($_POST['carga_id'] ?? 0);
    if ($cargaId <= 0) {
        $errorMsg = 'La carga indicada no es válida.';
    } else {
        try {
            $pdo->beginTransaction();
            recaudo_delete_load($pdo, $cargaId);
            audit_log($pdo, 'cargas_recaudo', $cargaId, 'carga_recaudo_eliminada', 'activa', 'eliminada', (int)($_SESSION['user']['id'] ?? 0));
            $pdo->commit();
            $msg = 'La carga fue eliminada correctamente.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMsg = 'No fue posible eliminar la carga: ' . $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'upload_recaudo') {
    $file = $_FILES['archivo_recaudo'] ?? null;

    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errorMsg = 'Debe seleccionar un archivo Excel o CSV válido.';
    } else {
        $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            $errorMsg = 'Formato no permitido. Solo se aceptan archivos .csv y .xlsx.';
        } else {
            try {
                recaudo_log('Inicio del proceso de carga', [
                    'archivo' => (string)($file['name'] ?? ''),
                    'usuario_id' => (int)($_SESSION['user']['id'] ?? 0),
                ]);

                $rows = parse_input_file((string)$file['tmp_name'], $extension);
                $processResult = recaudo_prepare_rows($rows);
                $summary = $processResult['summary'];

                recaudo_log('Número de filas leídas', ['filas_leidas' => (int)($summary['total_leidas'] ?? 0)]);

                if (($summary['procesadas_ok'] ?? 0) === 0) {
                    $msg = 'El archivo fue leído, pero no se encontraron filas válidas para insertar.';
                    recaudo_log('Número de insertados', ['insertados' => 0]);
                    recaudo_log('Número de errores', ['errores' => (int)($summary['con_error'] ?? 0)]);
                } else {
                    $pdo->beginTransaction();
                    $cargaId = recaudo_insert_load($pdo, $file, $processResult, (int)($_SESSION['user']['id'] ?? 0));
                    audit_log($pdo, 'cargas_recaudo', $cargaId, 'carga_recaudo_creada', null, 'activa', (int)($_SESSION['user']['id'] ?? 0));
                    $pdo->commit();

                    recaudo_log('Número de insertados', ['insertados' => (int)($summary['procesadas_ok'] ?? 0), 'carga_id' => $cargaId]);
                    recaudo_log('Número de errores', ['errores' => (int)($summary['con_error'] ?? 0), 'carga_id' => $cargaId]);

                    $msg = 'La carga de recaudos finalizó correctamente.';
                    $detail = recaudo_fetch_load_detail($pdo, $cargaId);
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errorMsg = 'No fue posible procesar el archivo: ' . $e->getMessage();
            }
        }
    }
}

$detalleCargaId = (int)($_GET['detalle_carga_id'] ?? 0);
if ($detail === null && $detalleCargaId > 0) {
    $detail = recaudo_fetch_load_detail($pdo, $detalleCargaId);
}

$history = recaudo_fetch_history($pdo);

ob_start();
?>
<section class="page-header">
  <div>
    <h2>Carga de recaudos</h2>
    <p>Módulo nuevo y mínimo: carga archivo, valida documento/valor pagado e inserta solo los registros válidos.</p>
  </div>
</section>

<?php if ($msg !== ''): ?>
  <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php if ($errorMsg !== ''): ?>
  <div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<section class="card" style="margin-bottom: 24px;">
  <h3>Subir archivo</h3>
  <form method="post" enctype="multipart/form-data" class="form-carga">
    <input type="hidden" name="action" value="upload_recaudo">
    <label>
      Archivo de recaudos (.xlsx o .csv)
      <input type="file" name="archivo_recaudo" accept=".csv,.xlsx" required>
    </label>
    <button class="btn" type="submit">Cargar archivo</button>
  </form>
  <p class="muted" style="margin-top:12px;">Columnas mínimas: documento y valor pagado. Si el archivo no trae encabezados, se usa la columna 1 como documento y la 2 como valor.</p>
</section>

<?php if ($processResult !== null): ?>
  <?php $summary = $processResult['summary']; ?>
  <section class="card" style="margin-bottom: 24px;">
    <h3>Resultado del proceso</h3>
    <div class="stats-grid">
      <article class="gd-kpi-card"><span>Total de filas leídas</span><strong><?= (int)($summary['total_leidas'] ?? 0) ?></strong></article>
      <article class="gd-kpi-card"><span>Filas procesadas correctamente</span><strong><?= (int)($summary['procesadas_ok'] ?? 0) ?></strong></article>
      <article class="gd-kpi-card"><span>Filas con error</span><strong><?= (int)($summary['con_error'] ?? 0) ?></strong></article>
      <article class="gd-kpi-card"><span>Filas vacías ignoradas</span><strong><?= (int)($summary['vacias_ignoradas'] ?? 0) ?></strong></article>
    </div>

    <?php if (!empty($processResult['errors'])): ?>
      <h4>Ejemplos de errores</h4>
      <ul>
        <?php foreach ($processResult['errors'] as $error): ?>
          <li>Fila <?= (int)($error['fila'] ?? 0) ?>: <?= htmlspecialchars((string)($error['motivo'] ?? '')) ?><?php if (($error['valor'] ?? '') !== ''): ?> (valor: <code><?= htmlspecialchars((string)$error['valor']) ?></code>)<?php endif; ?>.</li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if (!empty($detail['meta'])): ?>
  <section class="card" style="margin-bottom: 24px;">
    <h3>Detalle de la carga #<?= (int)($detail['meta']['id'] ?? 0) ?></h3>
    <p><strong>Archivo:</strong> <?= htmlspecialchars((string)($detail['meta']['archivo'] ?? '')) ?></p>
    <p><strong>Periodo:</strong> <?= htmlspecialchars((string)($detail['meta']['periodo'] ?? '')) ?></p>
    <p><strong>Registros insertados:</strong> <?= (int)($detail['meta']['total_registros'] ?? 0) ?></p>
    <p><strong>Total cargado:</strong> $<?= number_format((float)($detail['meta']['total_recaudo'] ?? 0), 2, ',', '.') ?></p>

    <div class="table-responsive" style="margin-top: 16px;">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Recibo</th>
            <th>Fecha</th>
            <th>Documento</th>
            <th>Cliente</th>
            <th>Vendedor</th>
            <th>Valor pagado</th>
            <th>Periodo</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($detail['rows'] as $row): ?>
            <tr>
              <td><?= (int)($row['id'] ?? 0) ?></td>
              <td><?= htmlspecialchars((string)($row['nro_recibo'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)($row['fecha_aplicacion'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)($row['documento_aplicado'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)($row['cliente'] ?? '')) ?></td>
              <td><?= htmlspecialchars((string)($row['vendedor'] ?? '')) ?></td>
              <td>$<?= number_format((float)($row['importe_aplicado'] ?? 0), 2, ',', '.') ?></td>
              <td><?= htmlspecialchars((string)($row['periodo'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($detail['rows'])): ?>
            <tr><td colspan="8">No hay registros insertados para esta carga.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if (!empty($detail['errors'])): ?>
      <h4 style="margin-top: 16px;">Errores guardados</h4>
      <ul>
        <?php foreach ($detail['errors'] as $error): ?>
          <li>Fila <?= (int)($error['fila'] ?? 0) ?> · <?= htmlspecialchars((string)($error['campo'] ?? '')) ?> · <?= htmlspecialchars((string)($error['motivo'] ?? '')) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="card">
  <h3>Historial reciente</h3>
  <div class="table-responsive">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Archivo</th>
          <th>Periodo</th>
          <th>Registros</th>
          <th>Total</th>
          <th>Versión</th>
          <th>Usuario</th>
          <th>Fecha</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($history as $item): ?>
          <tr>
            <td><?= (int)($item['id'] ?? 0) ?></td>
            <td><?= htmlspecialchars((string)($item['archivo'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($item['periodo'] ?? '')) ?></td>
            <td><?= (int)($item['total_registros'] ?? 0) ?></td>
            <td>$<?= number_format((float)($item['total_recaudo'] ?? 0), 2, ',', '.') ?></td>
            <td><?= (int)($item['version'] ?? 1) ?></td>
            <td><?= htmlspecialchars((string)($item['usuario'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string)($item['fecha_carga'] ?? '')) ?></td>
            <td>
              <a href="<?= htmlspecialchars(app_url('recaudos/carga.php?detalle_carga_id=' . (int)($item['id'] ?? 0))) ?>">Ver</a>
              <form method="post" class="inline-form" style="display:inline-block; margin-left:8px;" onsubmit="return confirm('¿Eliminar esta carga de recaudo?');">
                <input type="hidden" name="action" value="delete_load">
                <input type="hidden" name="carga_id" value="<?= (int)($item['id'] ?? 0) ?>">
                <button class="btn btn-secondary" type="submit">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($history)): ?>
          <tr><td colspan="9">Aún no hay cargas registradas.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>
<?php
$content = ob_get_clean();
render_layout('Recaudos', $content);
