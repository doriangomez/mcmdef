<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ExportService.php';

function cartera_mora_sql(string $range): ?string
{
    if ($range === '0-30') {
        return 'd.dias_vencido BETWEEN 0 AND 30';
    }
    if ($range === '31-60') {
        return 'd.dias_vencido BETWEEN 31 AND 60';
    }
    if ($range === '61-90') {
        return 'd.dias_vencido BETWEEN 61 AND 90';
    }
    if ($range === '91-180') {
        return 'd.dias_vencido BETWEEN 91 AND 180';
    }
    if ($range === '181-360') {
        return 'd.dias_vencido BETWEEN 181 AND 360';
    }
    if ($range === '360+') {
        return 'd.dias_vencido > 360';
    }

    return null;
}

function cartera_mora_badge(int $dias): string
{
    if ($dias <= 30) {
        return ui_badge((string)$dias, 'success');
    }
    if ($dias <= 60) {
        return ui_badge((string)$dias, 'warning');
    }
    if ($dias <= 90) {
        return '<span class="badge badge-mora-orange">' . $dias . '</span>';
    }

    return ui_badge((string)$dias, 'danger');
}

$filters = [
    'cliente' => trim($_GET['cliente'] ?? ''),
    'cliente_id' => (int)($_GET['cliente_id'] ?? 0),
    'numero' => trim($_GET['numero'] ?? ''),
    'tipo' => trim($_GET['tipo'] ?? ''),
    'canal' => trim($_GET['canal'] ?? ''),
    'regional' => trim($_GET['regional'] ?? ''),
    'mora_rango' => trim($_GET['mora_rango'] ?? ''),
    'estado' => trim($_GET['estado'] ?? 'activo'),
    'vista' => trim($_GET['vista'] ?? 'documento'),
];

if (!in_array($filters['vista'], ['documento', 'cliente'], true)) {
    $filters['vista'] = 'documento';
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'clientes') {
    $q = trim($_GET['q'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    if ($q === '') {
        echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT c.id, c.nombre, c.nit
         FROM clientes c
         WHERE c.nombre LIKE ? OR c.nit LIKE ?
         ORDER BY c.nombre ASC
         LIMIT 12'
    );
    $term = '%' . $q . '%';
    $stmt->execute([$term, $term]);
    echo json_encode(['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    exit;
}

$where = [];
$params = [];
if ($filters['cliente_id'] > 0) {
    $where[] = 'd.cliente_id = ?';
    $params[] = $filters['cliente_id'];
} elseif ($filters['cliente'] !== '') {
    $where[] = '(d.cliente LIKE ? OR c.nit LIKE ?)';
    $params[] = '%' . $filters['cliente'] . '%';
    $params[] = '%' . $filters['cliente'] . '%';
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
    $where[] = 'd.canal = ?';
    $params[] = $filters['canal'];
}
if ($filters['regional'] !== '') {
    $where[] = 'd.regional = ?';
    $params[] = $filters['regional'];
}
if ($filters['estado'] !== '') {
    $where[] = 'd.estado_documento = ?';
    $params[] = $filters['estado'];
}
$moraSql = cartera_mora_sql($filters['mora_rango']);
if ($moraSql !== null) {
    $where[] = $moraSql;
}

$sqlBase = ' FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id';
if (!empty($where)) {
    $sqlBase .= ' WHERE ' . implode(' AND ', $where);
}

$estadoWhere = $filters['estado'] === '' ? '' : ' WHERE estado_documento = ' . $pdo->quote($filters['estado']);
$tipoOptions = $pdo->query("SELECT DISTINCT tipo FROM cartera_documentos $estadoWhere ORDER BY tipo")->fetchAll(PDO::FETCH_COLUMN);
$canalOptions = $pdo->query("SELECT DISTINCT canal FROM cartera_documentos $estadoWhere ORDER BY canal")->fetchAll(PDO::FETCH_COLUMN);
$regionalOptions = $pdo->query("SELECT DISTINCT regional FROM cartera_documentos $estadoWhere ORDER BY regional")->fetchAll(PDO::FETCH_COLUMN);

$kpiStmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS total_documentos,
        COUNT(DISTINCT d.cliente_id) AS total_clientes,
        COALESCE(SUM(d.saldo_pendiente), 0) AS saldo_total,
        COALESCE(AVG(d.dias_vencido), 0) AS promedio_mora
    ' . $sqlBase
);
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$allowedSortDocumento = [
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
$allowedSortCliente = [
    'cliente' => 'cliente',
    'saldo' => 'saldo_total',
    'mora' => 'promedio_mora',
    'documentos' => 'total_documentos',
];

$allowedSort = $filters['vista'] === 'cliente' ? $allowedSortCliente : $allowedSortDocumento;
$sort = $_GET['sort'] ?? ($filters['vista'] === 'cliente' ? 'saldo' : 'id');
$dir = strtolower($_GET['dir'] ?? 'desc');
if (!isset($allowedSort[$sort])) {
    $sort = $filters['vista'] === 'cliente' ? 'saldo' : 'id';
}
if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = 'desc';
}
$orderBy = $allowedSort[$sort] . ' ' . strtoupper($dir);

$page = max(1, (int)($_GET['page'] ?? 1));
$size = (int)($_GET['size'] ?? 100);
$size = max(1, min(200, $size));
$offset = ($page - 1) * $size;

if ($filters['vista'] === 'cliente') {
    $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM (SELECT d.cliente_id ' . $sqlBase . ' GROUP BY d.cliente_id) z');
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();
} else {
    $totalStmt = $pdo->prepare('SELECT COUNT(*)' . $sqlBase);
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();
}

$totalPages = max(1, (int)ceil($total / $size));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $size;
}

$rows = [];
if ($filters['vista'] === 'cliente') {
    $dataStmt = $pdo->prepare(
        'SELECT
            d.cliente_id,
            d.cliente AS cliente,
            c.nit,
            COUNT(*) AS total_documentos,
            COALESCE(SUM(d.saldo_pendiente), 0) AS saldo_total,
            COALESCE(AVG(d.dias_vencido), 0) AS promedio_mora
        ' . $sqlBase . '
        GROUP BY d.cliente_id, d.cliente, c.nit
        ORDER BY ' . $orderBy . '
        LIMIT ' . $size . ' OFFSET ' . $offset
    );
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    $docPreviewStmt = $pdo->prepare(
        'SELECT d.cliente_id, d.tipo, d.nro_documento
         ' . $sqlBase . '
         ORDER BY d.id DESC
         LIMIT 1500'
    );
    $docPreviewStmt->execute($params);
    $docsRaw = $docPreviewStmt->fetchAll(PDO::FETCH_ASSOC);
    $docsByClient = [];
    foreach ($docsRaw as $docRaw) {
        $cid = (int)$docRaw['cliente_id'];
        if (!isset($docsByClient[$cid])) {
            $docsByClient[$cid] = [];
        }
        if (count($docsByClient[$cid]) < 3) {
            $docsByClient[$cid][] = trim((string)$docRaw['tipo']) . ' – ' . trim((string)$docRaw['nro_documento']);
        }
    }
} else {
    $dataStmt = $pdo->prepare(
        'SELECT d.id, d.cliente_id, c.nit, d.cliente AS nombre, d.tipo, d.nro_documento, d.saldo_pendiente, d.dias_vencido, d.estado_documento, d.fecha_vencimiento'
        . $sqlBase
        . ' ORDER BY ' . $orderBy
        . ' LIMIT ' . $size . ' OFFSET ' . $offset
    );
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['export']) && in_array(current_user()['rol'], ['admin', 'analista'], true)) {
    $exportStmt = $pdo->prepare(
        'SELECT c.nit, d.cliente, d.tipo, d.nro_documento, d.fecha_vencimiento, d.saldo_pendiente, d.dias_vencido, d.estado_documento, d.canal, d.regional'
        . $sqlBase
        . ' ORDER BY d.id DESC'
    );
    $exportStmt->execute($params);
    export_csv('cartera_filtrada.csv', $exportStmt->fetchAll());
    exit;
}

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
<style>
  .cartera-filter-card { padding: 16px; }
  .cartera-filter-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(170px,1fr)); gap:10px; align-items:end; }
  .cartera-filter-grid .wide { grid-column: span 2; }
  .cartera-metrics { display:grid; grid-template-columns: repeat(auto-fit,minmax(180px,1fr)); gap: 12px; margin-bottom: 16px; }
  .metric-card { background:#fff; border:1px solid #dbe4f1; border-radius:16px; padding:12px 14px; box-shadow: var(--shadow-xs); }
  .metric-label { margin:0; color:#64748b; font-size:12px; font-weight:600; text-transform: uppercase; letter-spacing:.04em; }
  .metric-value { margin:4px 0 0; font-size:22px; font-weight:700; color:#0f2a50; }
  .table-responsive { overflow-x:auto; }
  .action-links { display:flex; flex-wrap:wrap; gap:6px; }
  .action-links a { font-size:12px; }
  .badge-mora-orange { background: rgba(249, 115, 22, 0.18); color:#c2410c; border:1px solid rgba(194, 65, 12, 0.22); display:inline-flex; border-radius:999px; padding:5px 10px; font-size:12px; font-weight:600; }
  .auto-wrap { position:relative; }
  .autocomplete-list { position:absolute; z-index:15; left:0; right:0; top:calc(100% + 4px); background:#fff; border:1px solid #d0dcec; border-radius:12px; box-shadow: var(--shadow-soft); max-height:240px; overflow:auto; display:none; }
  .autocomplete-item { padding:8px 10px; cursor:pointer; border-bottom:1px solid #edf2fa; }
  .autocomplete-item:last-child { border-bottom:none; }
  .autocomplete-item:hover { background:#f4f8ff; }
  .autocomplete-name { font-weight:600; color:#0f172a; }
  .autocomplete-sub { color:#64748b; font-size:12px; }
</style>

<h1>Consulta de cartera</h1>
<form class="card cartera-filter-card" method="get" id="filtrosCartera">
  <div class="cartera-filter-grid">
    <div class="auto-wrap wide">
      <label>Cliente / NIT</label>
      <input name="cliente" id="clienteInput" autocomplete="off" placeholder="Buscar por cliente o NIT" value="<?= htmlspecialchars($filters['cliente']) ?>">
      <input type="hidden" name="cliente_id" id="clienteIdInput" value="<?= (int)$filters['cliente_id'] ?>">
      <div class="autocomplete-list" id="clienteSuggest"></div>
    </div>
    <div>
      <label>Número documento</label>
      <input name="numero" placeholder="Ej: 12968" value="<?= htmlspecialchars($filters['numero']) ?>">
    </div>
    <div>
      <label>Tipo</label>
      <select name="tipo">
        <option value="">Todos</option>
        <?php foreach ($tipoOptions as $option): if (trim((string)$option) === '') { continue; } ?>
          <option value="<?= htmlspecialchars($option) ?>" <?= $filters['tipo'] === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Canal</label>
      <select name="canal">
        <option value="">Todos</option>
        <?php foreach ($canalOptions as $option): if (trim((string)$option) === '') { continue; } ?>
          <option value="<?= htmlspecialchars($option) ?>" <?= $filters['canal'] === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Regional</label>
      <select name="regional">
        <option value="">Todas</option>
        <?php foreach ($regionalOptions as $option): if (trim((string)$option) === '') { continue; } ?>
          <option value="<?= htmlspecialchars($option) ?>" <?= $filters['regional'] === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Días mora</label>
      <select name="mora_rango">
        <option value="">Todos</option>
        <option value="0-30" <?= $filters['mora_rango'] === '0-30' ? 'selected' : '' ?>>0 - 30</option>
        <option value="31-60" <?= $filters['mora_rango'] === '31-60' ? 'selected' : '' ?>>31 - 60</option>
        <option value="61-90" <?= $filters['mora_rango'] === '61-90' ? 'selected' : '' ?>>61 - 90</option>
        <option value="91-180" <?= $filters['mora_rango'] === '91-180' ? 'selected' : '' ?>>91 - 180</option>
        <option value="181-360" <?= $filters['mora_rango'] === '181-360' ? 'selected' : '' ?>>181 - 360</option>
        <option value="360+" <?= $filters['mora_rango'] === '360+' ? 'selected' : '' ?>>360+</option>
      </select>
    </div>
    <div>
      <label>Vista</label>
      <select name="vista">
        <option value="documento" <?= $filters['vista'] === 'documento' ? 'selected' : '' ?>>Por documento</option>
        <option value="cliente" <?= $filters['vista'] === 'cliente' ? 'selected' : '' ?>>Agrupada por cliente</option>
      </select>
    </div>
    <div>
      <label>Estado</label>
      <select name="estado">
        <option value="activo" <?= $filters['estado'] === 'activo' ? 'selected' : '' ?>>Activos</option>
        <option value="inactivo" <?= $filters['estado'] === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
        <option value="" <?= $filters['estado'] === '' ? 'selected' : '' ?>>Todos</option>
      </select>
    </div>
    <div>
      <label>Tamaño de página</label>
      <select name="size">
        <?php foreach ([50, 100, 150, 200] as $pageSize): ?>
          <option value="<?= $pageSize ?>" <?= $size === $pageSize ? 'selected' : '' ?>><?= $pageSize ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</form>

<div class="cartera-metrics">
  <div class="metric-card"><p class="metric-label">Total clientes encontrados</p><p class="metric-value"><?= number_format((int)($kpi['total_clientes'] ?? 0), 0, ',', '.') ?></p></div>
  <div class="metric-card"><p class="metric-label">Total documentos</p><p class="metric-value"><?= number_format((int)($kpi['total_documentos'] ?? 0), 0, ',', '.') ?></p></div>
  <div class="metric-card"><p class="metric-label">Saldo total cartera</p><p class="metric-value">$ <?= number_format((float)($kpi['saldo_total'] ?? 0), 0, ',', '.') ?></p></div>
  <div class="metric-card"><p class="metric-label">Promedio días de mora</p><p class="metric-value"><?= number_format((float)($kpi['promedio_mora'] ?? 0), 1, ',', '.') ?></p></div>
</div>

<div class="table-responsive">
<table class="table">
<?php if ($filters['vista'] === 'cliente'): ?>
<tr>
  <th><a href="<?= htmlspecialchars($buildSortUrl('cliente')) ?>">Cliente</a></th>
  <th>NIT</th>
  <th><a href="<?= htmlspecialchars($buildSortUrl('saldo')) ?>">Total cartera</a></th>
  <th><a href="<?= htmlspecialchars($buildSortUrl('documentos')) ?>"># Documentos</a></th>
  <th><a href="<?= htmlspecialchars($buildSortUrl('mora')) ?>">Prom. mora</a></th>
  <th>Documentos asociados</th>
  <th>Acciones</th>
</tr>
<?php foreach ($rows as $r): ?>
  <?php $clienteId = (int)$r['cliente_id']; ?>
  <tr>
    <td><?= htmlspecialchars($r['cliente']) ?></td>
    <td><?= htmlspecialchars((string)$r['nit']) ?></td>
    <td>$ <?= number_format((float)$r['saldo_total'], 2, ',', '.') ?></td>
    <td><?= (int)$r['total_documentos'] ?></td>
    <td><?= cartera_mora_badge((int)round((float)$r['promedio_mora'])) ?></td>
    <td>
      <?php if (!empty($docsByClient[$clienteId])): ?>
        <?= htmlspecialchars(implode(' · ', $docsByClient[$clienteId])) ?>
      <?php else: ?>
        —
      <?php endif; ?>
    </td>
    <td>
      <div class="action-links">
        <a href="<?= htmlspecialchars(app_url('cartera/cliente.php?id=' . $clienteId)) ?>">Ver historial</a>
        <a href="<?= htmlspecialchars(app_url('cartera/lista.php?vista=documento&cliente_id=' . $clienteId . '&cliente=' . urlencode((string)$r['cliente']))) ?>">Ver documentos asociados</a>
      </div>
    </td>
  </tr>
<?php endforeach; ?>
<?php else: ?>
<tr><th><a href="<?= htmlspecialchars($buildSortUrl('id')) ?>">ID</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('nit')) ?>">NIT</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('cliente')) ?>">Cliente</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('tipo')) ?>">Tipo</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('numero')) ?>">Número</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('saldo')) ?>">Saldo</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('mora')) ?>">Días mora</a></th><th><a href="<?= htmlspecialchars($buildSortUrl('estado')) ?>">Estado</a></th><th>Acciones</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
  <td><?= (int)$r['id'] ?></td>
  <td><?= htmlspecialchars($r['nit']) ?></td>
  <td><?= htmlspecialchars($r['nombre']) ?></td>
  <td><?= htmlspecialchars($r['tipo']) ?></td>
  <td><?= htmlspecialchars($r['nro_documento']) ?></td>
  <td>$ <?= number_format((float)$r['saldo_pendiente'],2,',','.') ?></td>
  <td><?= cartera_mora_badge((int)$r['dias_vencido']) ?></td>
  <td><?= htmlspecialchars($r['estado_documento']) ?></td>
  <td>
    <div class="action-links">
      <a href="<?= htmlspecialchars(app_url('cartera/documento.php?id_documento=' . (int)$r['id'])) ?>">Ver detalle</a>
      <a href="<?= htmlspecialchars(app_url('cartera/cliente.php?id=' . (int)$r['cliente_id'])) ?>">Ver comportamiento mora</a>
    </div>
  </td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</table>
</div>
<div class="pagination"><span>Total registros: <?= $total ?> | Página <?= $page ?> de <?= $totalPages ?> | Tamaño página: <?= $size ?></span></div>

<script>
(function () {
  var form = document.getElementById('filtrosCartera');
  if (!form) return;
  var debounceTimer = null;
  var clientInput = document.getElementById('clienteInput');
  var clientIdInput = document.getElementById('clienteIdInput');
  var suggest = document.getElementById('clienteSuggest');

  function submitDebounced(delay) {
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(function () { form.submit(); }, delay || 260);
  }

  form.querySelectorAll('select').forEach(function (el) {
    el.addEventListener('change', function () { submitDebounced(60); });
  });

  form.querySelectorAll('input[name="numero"]').forEach(function (el) {
    el.addEventListener('input', function () { submitDebounced(420); });
  });

  function closeSuggestions() {
    suggest.style.display = 'none';
    suggest.innerHTML = '';
  }

  function renderSuggestions(items) {
    if (!items.length) {
      closeSuggestions();
      return;
    }
    suggest.innerHTML = '';
    items.forEach(function (item) {
      var row = document.createElement('div');
      row.className = 'autocomplete-item';
      row.innerHTML = '<div class="autocomplete-name">' + item.nombre + '</div><div class="autocomplete-sub">NIT: ' + item.nit + '</div>';
      row.addEventListener('click', function () {
        clientInput.value = item.nombre + ' - ' + item.nit;
        clientIdInput.value = item.id;
        closeSuggestions();
        form.submit();
      });
      suggest.appendChild(row);
    });
    suggest.style.display = 'block';
  }

  var suggestTimer = null;
  clientInput.addEventListener('input', function () {
    var q = clientInput.value.trim();
    clientIdInput.value = '';
    if (q.length < 2) {
      closeSuggestions();
      submitDebounced(450);
      return;
    }

    window.clearTimeout(suggestTimer);
    suggestTimer = window.setTimeout(function () {
      fetch('<?= htmlspecialchars(app_url('cartera/lista.php')) ?>?ajax=clientes&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (payload) { renderSuggestions(payload.items || []); });
    }, 180);

    submitDebounced(700);
  });

  document.addEventListener('click', function (event) {
    if (!event.target.closest('.auto-wrap')) {
      closeSuggestions();
    }
  });
})();
</script>
<?php
$content = ob_get_clean();
render_layout('Cartera', $content);
