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
    'mora_rango' => trim($_GET['mora_rango'] ?? ''),
    'estado' => trim($_GET['estado'] ?? 'activo'),
];

$where = [];
$params = [];
if ($filters['cliente'] !== '') {
    $where[] = '(d.cliente LIKE ? OR c.nit LIKE ?)';
    $params[] = '%' . $filters['cliente'] . '%';
    $params[] = '%' . $filters['cliente'] . '%';
}
if ($filters['nit'] !== '') {
    $where[] = 'c.nit LIKE ?';
    $params[] = '%' . $filters['nit'] . '%';
}
if ($filters['numero'] !== '') {
    $where[] = 'd.nro_documento LIKE ?';
    $params[] = '%' . $filters['numero'] . '%';
}
if ($filters['tipo'] !== '') {
    $where[] = 'd.tipo = ?';
    $params[] = $filters['tipo'];
}
if ($filters['canal'] !== '') {
    $where[] = 'd.canal LIKE ?';
    $params[] = '%' . $filters['canal'] . '%';
}
if ($filters['regional'] !== '') {
    $where[] = 'd.regional LIKE ?';
    $params[] = '%' . $filters['regional'] . '%';
}
if ($filters['estado'] !== '') {
    $where[] = 'd.estado_documento = ?';
    $params[] = $filters['estado'];
}
if ($filters['mora_rango'] !== '') {
    if ($filters['mora_rango'] === '0') {
        $where[] = 'd.dias_vencido = 0';
    } elseif ($filters['mora_rango'] === '1-30') {
        $where[] = 'd.dias_vencido BETWEEN 1 AND 30';
    } elseif ($filters['mora_rango'] === '31-60') {
        $where[] = 'd.dias_vencido BETWEEN 31 AND 60';
    } elseif ($filters['mora_rango'] === '61-90') {
        $where[] = 'd.dias_vencido BETWEEN 61 AND 90';
    } elseif ($filters['mora_rango'] === '91+') {
        $where[] = 'd.dias_vencido >= 91';
    }
}

$allowedSort = [
    'id' => 'd.id',
    'nit' => 'c.nit',
    'cliente' => 'd.cliente',
    'tipo' => 'd.tipo',
    'numero' => 'd.nro_documento',
    'saldo' => 'd.saldo_pendiente',
    'mora' => 'd.dias_vencido',
    'estado' => 'd.estado_documento',
    'vencimiento' => 'd.fecha_vencimiento',
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

$sqlBase = ' FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id';
if (!empty($where)) {
    $sqlBase .= ' WHERE ' . implode(' AND ', $where);
}

if (isset($_GET['export']) && in_array(current_user()['rol'], ['admin', 'analista'], true)) {
    $exportStmt = $pdo->prepare(
        'SELECT c.nit, d.cliente, d.tipo, d.nro_documento, d.fecha_vencimiento, d.saldo_pendiente, d.dias_vencido, d.estado_documento, d.canal, d.regional'
        . $sqlBase
        . ' ORDER BY ' . $orderBy
    );
    $exportStmt->execute($params);
    export_csv('cartera_filtrada.csv', $exportStmt->fetchAll());
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$size = (int)($_GET['size'] ?? 100);
$size = max(1, min(100, $size));
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
    'SELECT d.id, d.cliente_id, c.nit, d.cliente AS nombre, d.tipo, d.nro_documento, d.saldo_pendiente, d.dias_vencido, d.estado_documento, d.fecha_vencimiento'
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
<form class="card" method="get"><div class="row">
    <input name="cliente" placeholder="Cliente / NIT" value="<?= htmlspecialchars($filters['cliente']) ?>">
    <input name="nit" placeholder="NIT" value="<?= htmlspecialchars($filters['nit']) ?>">
    <input name="numero" placeholder="Número" value="<?= htmlspecialchars($filters['numero']) ?>">
    <input name="tipo" placeholder="Tipo" value="<?= htmlspecialchars($filters['tipo']) ?>">
    <input name="canal" placeholder="Canal" value="<?= htmlspecialchars($filters['canal']) ?>">
    <input name="regional" placeholder="Regional" value="<?= htmlspecialchars($filters['regional']) ?>">
    <select name="mora_rango"><option value="">Días mora</option><option value="0" <?= $filters['mora_rango']==='0'?'selected':'' ?>>0</option><option value="1-30" <?= $filters['mora_rango']==='1-30'?'selected':'' ?>>1-30</option><option value="31-60" <?= $filters['mora_rango']==='31-60'?'selected':'' ?>>31-60</option><option value="61-90" <?= $filters['mora_rango']==='61-90'?'selected':'' ?>>61-90</option><option value="91+" <?= $filters['mora_rango']==='91+'?'selected':'' ?>>91+</option></select>
    <select name="estado"><option value="activo" <?= $filters['estado']==='activo'?'selected':'' ?>>Activos</option><option value="inactivo" <?= $filters['estado']==='inactivo'?'selected':'' ?>>Inactivos</option><option value="" <?= $filters['estado']===''?'selected':'' ?>>Todos</option></select>
    <button class="btn" type="submit">Filtrar</button>
</div></form>
<table class="table"><tr><th><a href="<?= htmlspecialchars($buildSortUrl('id')) ?>">ID</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('nit')) ?>">NIT</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('cliente')) ?>">Cliente</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('tipo')) ?>">Tipo</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('numero')) ?>">Número</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('saldo')) ?>">Saldo</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('mora')) ?>">Mora</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('estado')) ?>">Estado</a></th></tr>
<?php foreach ($rows as $r): ?><tr><td><?= (int)$r['id'] ?></td><td><?= htmlspecialchars($r['nit']) ?></td><td><?= htmlspecialchars($r['nombre']) ?></td><td><?= htmlspecialchars($r['tipo']) ?></td><td><?= htmlspecialchars($r['nro_documento']) ?></td><td><?= number_format((float)$r['saldo_pendiente'],2,',','.') ?></td><td><?= (int)$r['dias_vencido'] ?></td><td><?= htmlspecialchars($r['estado_documento']) ?></td></tr><?php endforeach; ?>
</table>
<div class="pagination"><span>Total registros: <?= $total ?> | Página <?= $page ?> de <?= $totalPages ?> | Tamaño página: <?= $size ?></span></div>
<?php
$content = ob_get_clean();
render_layout('Cartera', $content);
