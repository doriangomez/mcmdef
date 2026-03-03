<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ExportService.php';

$filters = [
    'cliente' => trim($_GET['cliente'] ?? ''),
    'nit' => trim($_GET['nit'] ?? ''),
    'numero' => trim($_GET['numero'] ?? ''),
    'tipo' => trim($_GET['tipo'] ?? ''),
    'canal' => trim($_GET['canal'] ?? ''),
    'regional' => trim($_GET['regional'] ?? ''),
    'asesor' => trim($_GET['asesor'] ?? ''),
    'ejecutivo' => trim($_GET['ejecutivo'] ?? ''),
    'uen' => trim($_GET['uen'] ?? ''),
    'marca' => trim($_GET['marca'] ?? ''),
    'periodo' => trim($_GET['periodo'] ?? ''),
    'mora_rango' => trim($_GET['mora_rango'] ?? ''),
];

$where = [];
$params = [];
if ($filters['cliente'] !== '') {
    $where[] = '(cl.nombre LIKE ? OR cl.nit LIKE ?)';
    $params[] = '%' . $filters['cliente'] . '%';
    $params[] = '%' . $filters['cliente'] . '%';
}
if ($filters['nit'] !== '') {
    $where[] = 'cl.nit LIKE ?';
    $params[] = '%' . $filters['nit'] . '%';
}
if ($filters['numero'] !== '') {
    $where[] = 'd.numero_documento LIKE ?';
    $params[] = '%' . $filters['numero'] . '%';
}
if ($filters['tipo'] !== '') {
    $where[] = 'd.tipo_documento = ?';
    $params[] = $filters['tipo'];
}
if ($filters['canal'] !== '') {
    $where[] = 'cl.canal LIKE ?';
    $params[] = '%' . $filters['canal'] . '%';
}
if ($filters['regional'] !== '') {
    $where[] = 'cl.regional LIKE ?';
    $params[] = '%' . $filters['regional'] . '%';
}
if ($filters['asesor'] !== '') {
    $where[] = 'cl.asesor_comercial LIKE ?';
    $params[] = '%' . $filters['asesor'] . '%';
}
if ($filters['ejecutivo'] !== '') {
    $where[] = 'cl.ejecutivo_cartera LIKE ?';
    $params[] = '%' . $filters['ejecutivo'] . '%';
}
if ($filters['uen'] !== '') {
    $where[] = 'cl.uen LIKE ?';
    $params[] = '%' . $filters['uen'] . '%';
}
if ($filters['marca'] !== '') {
    $where[] = 'cl.marca LIKE ?';
    $params[] = '%' . $filters['marca'] . '%';
}
if ($filters['periodo'] !== '') {
    $where[] = 'd.periodo = ?';
    $params[] = $filters['periodo'];
}
if ($filters['mora_rango'] !== '') {
    if ($filters['mora_rango'] === '0') {
        $where[] = 'd.dias_mora = 0';
    } elseif ($filters['mora_rango'] === '1-30') {
        $where[] = 'd.dias_mora BETWEEN 1 AND 30';
    } elseif ($filters['mora_rango'] === '31-60') {
        $where[] = 'd.dias_mora BETWEEN 31 AND 60';
    } elseif ($filters['mora_rango'] === '61-90') {
        $where[] = 'd.dias_mora BETWEEN 61 AND 90';
    } elseif ($filters['mora_rango'] === '91+') {
        $where[] = 'd.dias_mora >= 91';
    }
}

$allowedSort = [
    'id' => 'd.id',
    'nit' => 'cl.nit',
    'cliente' => 'cl.nombre',
    'tipo' => 'd.tipo_documento',
    'numero' => 'd.numero_documento',
    'saldo' => 'd.saldo_actual',
    'mora' => 'd.dias_mora',
    'estado' => 'd.estado_documento',
    'periodo' => 'd.periodo',
];
$sort = $_GET['sort'] ?? 'id';
$dir = strtolower($_GET['dir'] ?? 'desc');
if (!isset($allowedSort[$sort])) {
    $sort = 'id';
}
if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = 'desc';
}
$orderBy = $allowedSort[$sort] . ' ' . strtoupper($dir);

$sqlBase = ' FROM documentos d INNER JOIN clientes cl ON cl.id = d.cliente_id';
if (!empty($where)) {
    $sqlBase .= ' WHERE ' . implode(' AND ', $where);
}

if (isset($_GET['export']) && in_array(current_user()['rol'], ['admin', 'analista'], true)) {
    $exportStmt = $pdo->prepare(
        'SELECT cl.nit, cl.nombre AS cliente, d.tipo_documento, d.numero_documento, d.fecha_vencimiento, d.saldo_actual, d.dias_mora, d.periodo, d.estado_documento, cl.canal, cl.regional, cl.asesor_comercial, cl.ejecutivo_cartera, cl.uen, cl.marca'
        . $sqlBase
        . ' ORDER BY ' . $orderBy
    );
    $exportStmt->execute($params);
    export_csv('cartera_filtrada.csv', $exportStmt->fetchAll());
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$size = 20;
$offset = ($page - 1) * $size;

$totalStmt = $pdo->prepare('SELECT COUNT(*)' . $sqlBase);
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $size));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $size;
}

$dataStmt = $pdo->prepare(
    'SELECT d.id, cl.id AS cliente_id, cl.nit, cl.nombre, d.tipo_documento, d.numero_documento, d.saldo_actual, d.dias_mora, d.estado_documento, d.periodo'
    . $sqlBase
    . ' ORDER BY ' . $orderBy
    . ' LIMIT ' . $size . ' OFFSET ' . $offset
);
$dataStmt->execute($params);
$rows = $dataStmt->fetchAll();

$queryWithoutPage = $_GET;
unset($queryWithoutPage['page'], $queryWithoutPage['export']);
$buildSortUrl = static function (string $column) use ($queryWithoutPage, $sort, $dir): string {
    $nextDir = 'asc';
    if ($sort === $column && $dir === 'asc') {
        $nextDir = 'desc';
    }
    $params = array_merge($queryWithoutPage, ['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
    return app_url('cartera/lista.php?' . http_build_query($params));
};

ob_start(); ?>
<h1>Consulta de cartera</h1>
<form class="card" method="get">
  <div class="row">
    <input name="cliente" placeholder="Cliente / NIT" value="<?= htmlspecialchars($filters['cliente']) ?>">
    <input name="nit" placeholder="NIT exacto/parcial" value="<?= htmlspecialchars($filters['nit']) ?>">
    <select name="tipo">
      <option value="">Tipo documento</option>
      <option value="Factura" <?= $filters['tipo'] === 'Factura' ? 'selected' : '' ?>>Factura</option>
      <option value="NC" <?= $filters['tipo'] === 'NC' ? 'selected' : '' ?>>NC</option>
    </select>
    <input name="numero" placeholder="Número documento" value="<?= htmlspecialchars($filters['numero']) ?>">
    <input name="canal" placeholder="Canal" value="<?= htmlspecialchars($filters['canal']) ?>">
    <input name="regional" placeholder="Regional" value="<?= htmlspecialchars($filters['regional']) ?>">
    <input name="asesor" placeholder="Asesor comercial" value="<?= htmlspecialchars($filters['asesor']) ?>">
    <input name="ejecutivo" placeholder="Ejecutivo cartera" value="<?= htmlspecialchars($filters['ejecutivo']) ?>">
    <input name="uen" placeholder="UEN" value="<?= htmlspecialchars($filters['uen']) ?>">
    <input name="marca" placeholder="Marca" value="<?= htmlspecialchars($filters['marca']) ?>">
    <input name="periodo" placeholder="Periodo (ej. 2026-03)" value="<?= htmlspecialchars($filters['periodo']) ?>">
    <select name="mora_rango">
      <option value="">Días de mora</option>
      <option value="0" <?= $filters['mora_rango'] === '0' ? 'selected' : '' ?>>0</option>
      <option value="1-30" <?= $filters['mora_rango'] === '1-30' ? 'selected' : '' ?>>1 - 30</option>
      <option value="31-60" <?= $filters['mora_rango'] === '31-60' ? 'selected' : '' ?>>31 - 60</option>
      <option value="61-90" <?= $filters['mora_rango'] === '61-90' ? 'selected' : '' ?>>61 - 90</option>
      <option value="91+" <?= $filters['mora_rango'] === '91+' ? 'selected' : '' ?>>91+</option>
    </select>
    <button class="btn" type="submit">Filtrar</button>
    <a class="btn btn-muted" href="<?= htmlspecialchars(app_url('cartera/lista.php')) ?>">Limpiar</a>
    <?php if (in_array(current_user()['rol'], ['admin', 'analista'], true)): ?>
      <button class="btn btn-muted" name="export" value="1" type="submit">Exportar CSV</button>
    <?php endif; ?>
  </div>
</form>

<table class="table">
  <tr>
    <th><a href="<?= htmlspecialchars($buildSortUrl('id')) ?>">ID</a></th>
    <th><a href="<?= htmlspecialchars($buildSortUrl('nit')) ?>">NIT</a></th>
    <th><a href="<?= htmlspecialchars($buildSortUrl('cliente')) ?>">Cliente</a></th>
    <th><a href="<?= htmlspecialchars($buildSortUrl('tipo')) ?>">Tipo</a></th>
    <th><a href="<?= htmlspecialchars($buildSortUrl('numero')) ?>">Número</a></th>
    <th><a href="<?= htmlspecialchars($buildSortUrl('saldo')) ?>">Saldo</a></th>
    <th><a href="<?= htmlspecialchars($buildSortUrl('mora')) ?>">Días mora</a></th>
    <th><a href="<?= htmlspecialchars($buildSortUrl('estado')) ?>">Estado</a></th>
    <th><a href="<?= htmlspecialchars($buildSortUrl('periodo')) ?>">Periodo</a></th>
    <th>Detalle</th>
  </tr>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><?= (int)$r['id'] ?></td>
      <td><?= htmlspecialchars($r['nit']) ?></td>
      <td><?= htmlspecialchars($r['nombre']) ?></td>
      <td><?= htmlspecialchars($r['tipo_documento']) ?></td>
      <td><?= htmlspecialchars($r['numero_documento']) ?></td>
      <td><?= number_format((float)$r['saldo_actual'], 2, ',', '.') ?></td>
      <td><?= (int)$r['dias_mora'] ?></td>
      <td><?= htmlspecialchars($r['estado_documento']) ?></td>
      <td><?= htmlspecialchars((string)$r['periodo']) ?></td>
      <td>
        <a href="<?= htmlspecialchars(app_url('cartera/documento.php?id_documento=' . (int)$r['id'])) ?>">Documento</a> |
        <a href="<?= htmlspecialchars(app_url('cartera/cliente.php?id_cliente=' . (int)$r['cliente_id'])) ?>">Cliente</a>
      </td>
    </tr>
  <?php endforeach; ?>
</table>

<?php
$paginationQuery = $queryWithoutPage;
?>
<div class="pagination">
  <span>Total registros: <?= $total ?> | Página <?= $page ?> de <?= $totalPages ?></span>
  <div>
    <?php if ($page > 1): ?>
      <a class="btn btn-muted" href="<?= htmlspecialchars(app_url('cartera/lista.php?' . http_build_query(array_merge($paginationQuery, ['page' => $page - 1])))) ?>">Anterior</a>
    <?php endif; ?>
    <?php if ($page < $totalPages): ?>
      <a class="btn btn-muted" href="<?= htmlspecialchars(app_url('cartera/lista.php?' . http_build_query(array_merge($paginationQuery, ['page' => $page + 1])))) ?>">Siguiente</a>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
render_layout('Cartera', $content);
