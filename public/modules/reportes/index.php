<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ExportService.php';

$tipo = $_GET['tipo'] ?? 'vigente_vencida';
$periodo = trim($_GET['periodo'] ?? '');
$canal = trim($_GET['canal'] ?? '');
$regional = trim($_GET['regional'] ?? '');
$uen = trim($_GET['uen'] ?? '');
$asesor = trim($_GET['asesor'] ?? '');
$estadoCompromiso = trim($_GET['estado_compromiso'] ?? '');

$docWhere = [];
$docParams = [];
$docWhere[] = "d.estado_documento = 'activo'";
if ($periodo !== '') {
    $docWhere[] = "DATE_FORMAT(d.fecha_contabilizacion, '%Y-%m') = ?";
    $docParams[] = $periodo;
}
if ($canal !== '') {
    $docWhere[] = 'c.canal LIKE ?';
    $docParams[] = '%' . $canal . '%';
}
if ($regional !== '') {
    $docWhere[] = 'c.regional LIKE ?';
    $docParams[] = '%' . $regional . '%';
}
if ($uen !== '') {
    $docWhere[] = 'c.uen LIKE ?';
    $docParams[] = '%' . $uen . '%';
}
if ($asesor !== '') {
    $docWhere[] = 'c.empleado_ventas LIKE ?';
    $docParams[] = '%' . $asesor . '%';
}

$docWhereSql = '';
if (!empty($docWhere)) {
    $docWhereSql = ' WHERE ' . implode(' AND ', $docWhere);
}

$rows = [];
if ($tipo === 'vigente_vencida') {
    $stmt = $pdo->prepare(
        'SELECT d.estado_documento AS categoria, SUM(d.saldo_pendiente) AS total
         FROM cartera_documentos d
         INNER JOIN clientes c ON c.id = d.cliente_id'
        . $docWhereSql .
        ' GROUP BY d.estado_documento'
    );
    $stmt->execute($docParams);
    $rows = $stmt->fetchAll();
} elseif ($tipo === 'mora_rangos') {
    $stmt = $pdo->prepare(
        "SELECT CASE
                  WHEN d.dias_vencido = 0 THEN '0'
                  WHEN d.dias_vencido BETWEEN 1 AND 30 THEN '1-30'
                  WHEN d.dias_vencido BETWEEN 31 AND 60 THEN '31-60'
                  WHEN d.dias_vencido BETWEEN 61 AND 90 THEN '61-90'
                  ELSE '91+'
                END AS categoria,
                SUM(d.saldo_pendiente) AS total
         FROM cartera_documentos d
         INNER JOIN clientes c ON c.id = d.cliente_id"
        . $docWhereSql .
        ' GROUP BY categoria'
    );
    $stmt->execute($docParams);
    $rows = $stmt->fetchAll();
} elseif ($tipo === 'canal') {
    $stmt = $pdo->prepare(
        'SELECT COALESCE(c.canal, "sin_canal") AS categoria, SUM(d.saldo_pendiente) AS total
         FROM cartera_documentos d
         INNER JOIN clientes c ON c.id = d.cliente_id'
        . $docWhereSql .
        ' GROUP BY c.canal'
    );
    $stmt->execute($docParams);
    $rows = $stmt->fetchAll();
} elseif ($tipo === 'uen') {
    $stmt = $pdo->prepare(
        'SELECT COALESCE(c.uen, "sin_uen") AS categoria, SUM(d.saldo_pendiente) AS total
         FROM cartera_documentos d
         INNER JOIN clientes c ON c.id = d.cliente_id'
        . $docWhereSql .
        ' GROUP BY c.uen'
    );
    $stmt->execute($docParams);
    $rows = $stmt->fetchAll();
} elseif ($tipo === 'regional') {
    $stmt = $pdo->prepare(
        'SELECT COALESCE(c.regional, "sin_regional") AS categoria, SUM(d.saldo_pendiente) AS total
         FROM cartera_documentos d
         INNER JOIN clientes c ON c.id = d.cliente_id'
        . $docWhereSql .
        ' GROUP BY c.regional'
    );
    $stmt->execute($docParams);
    $rows = $stmt->fetchAll();
} elseif ($tipo === 'asesor') {
    $stmt = $pdo->prepare(
        'SELECT COALESCE(c.empleado_ventas, "sin_asesor") AS categoria, SUM(d.saldo_pendiente) AS total
         FROM cartera_documentos d
         INNER JOIN clientes c ON c.id = d.cliente_id'
        . $docWhereSql .
        ' GROUP BY c.empleado_ventas'
    );
    $stmt->execute($docParams);
    $rows = $stmt->fetchAll();
} elseif ($tipo === 'compromisos') {
    $gestionWhere = ['g.compromiso_pago IS NOT NULL'];
    $gestionParams = [];
    if ($estadoCompromiso !== '') {
        $gestionWhere[] = 'estado_compromiso = ?';
        $gestionParams[] = $estadoCompromiso;
    }
    $gestionWhereSql = ' WHERE ' . implode(' AND ', $gestionWhere);

    $stmt = $pdo->prepare(
        "SELECT estado_compromiso AS categoria, COUNT(*) AS total
         FROM (
             SELECT
                 CASE
                     WHEN d.saldo_pendiente <= 0 THEN 'cumplido'
                     WHEN g.compromiso_pago < CURDATE() THEN 'incumplido'
                     ELSE 'pendiente'
                 END AS estado_compromiso
             FROM bitacora_gestion g
             INNER JOIN cartera_documentos d ON d.id = g.id_documento
         ) t"
        . $gestionWhereSql .
        ' GROUP BY estado_compromiso'
    );
    $stmt->execute($gestionParams);
    $rows = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(DATE_FORMAT(d.fecha_contabilizacion, '%Y-%m'), 'sin_periodo') AS categoria, SUM(d.saldo_pendiente) AS total
         FROM cartera_documentos d
         INNER JOIN clientes c ON c.id = d.cliente_id
        " . $docWhereSql . "
         GROUP BY DATE_FORMAT(d.fecha_contabilizacion, '%Y-%m')
         ORDER BY DATE_FORMAT(d.fecha_contabilizacion, '%Y-%m') DESC"
    );
    $stmt->execute($docParams);
    $rows = $stmt->fetchAll();
}

if (isset($_GET['export']) && in_array(current_user()['rol'], ['admin', 'analista'], true)) {
    export_csv('reporte_' . $tipo . '.csv', $rows);
    exit;
}

ob_start(); ?>
<h1>Reportes operativos</h1>
<form class="card"><div class="row">
<select name="tipo">
<option value="vigente_vencida" <?= $tipo === 'vigente_vencida' ? 'selected' : '' ?>>Cartera vigente y vencida</option>
<option value="mora_rangos" <?= $tipo === 'mora_rangos' ? 'selected' : '' ?>>Mora por rangos</option>
<option value="canal" <?= $tipo === 'canal' ? 'selected' : '' ?>>Cartera por canal</option>
<option value="uen" <?= $tipo === 'uen' ? 'selected' : '' ?>>Cartera por UEN</option>
<option value="regional" <?= $tipo === 'regional' ? 'selected' : '' ?>>Cartera por regional</option>
<option value="asesor" <?= $tipo === 'asesor' ? 'selected' : '' ?>>Cartera por asesor</option>
<option value="compromisos" <?= $tipo === 'compromisos' ? 'selected' : '' ?>>Compromisos registrados y estado</option>
<option value="comparativo_periodo" <?= $tipo === 'comparativo_periodo' ? 'selected' : '' ?>>Comparativo por periodo</option>
</select>
<input name="periodo" placeholder="Periodo (opcional)" value="<?= htmlspecialchars($periodo) ?>">
<input name="canal" placeholder="Canal (opcional)" value="<?= htmlspecialchars($canal) ?>">
<input name="regional" placeholder="Regional (opcional)" value="<?= htmlspecialchars($regional) ?>">
<input name="uen" placeholder="UEN (opcional)" value="<?= htmlspecialchars($uen) ?>">
<input name="asesor" placeholder="Asesor (opcional)" value="<?= htmlspecialchars($asesor) ?>">
<select name="estado_compromiso">
  <option value="">Estado compromiso (reportes de compromiso)</option>
  <option value="pendiente" <?= $estadoCompromiso === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
  <option value="cumplido" <?= $estadoCompromiso === 'cumplido' ? 'selected' : '' ?>>Cumplido</option>
  <option value="incumplido" <?= $estadoCompromiso === 'incumplido' ? 'selected' : '' ?>>Incumplido</option>
</select>
<button class="btn">Ver</button>
<a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('reportes/index.php')) ?>">Limpiar</a>
<?php if (in_array(current_user()['rol'], ['admin', 'analista'], true)): ?><button class="btn btn-secondary" name="export" value="1">Exportar CSV</button><?php endif; ?>
</div></form>
<table class="table"><tr><th>Categoría</th><th>Total</th></tr><?php foreach($rows as $r): ?><tr><td><?= htmlspecialchars((string)$r['categoria']) ?></td><td><?= number_format((float)$r['total'],2,',','.') ?></td></tr><?php endforeach; ?></table>
<?php
$content = ob_get_clean();
render_layout('Reportes', $content);
