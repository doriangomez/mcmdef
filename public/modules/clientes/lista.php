<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ClientService.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';

ensure_client_management_schema($pdo);

$filtro = trim((string)($_GET['q'] ?? ''));
$estado = trim((string)($_GET['estado'] ?? ''));
$canal = trim((string)($_GET['canal'] ?? ''));
$regional = trim((string)($_GET['regional'] ?? ''));
$scope = portfolio_client_scope_sql('c');

$where = ['1=1'];
$params = [];
if ($scope['sql'] !== '') {
    $where[] = ltrim($scope['sql'], ' AND');
    $params = array_merge($params, $scope['params']);
}
if ($filtro !== '') {
    $where[] = '(COALESCE(NULLIF(c.nombre_cliente, ""), c.nombre) LIKE ? OR COALESCE(NULLIF(c.nro_identificacion, ""), c.nit) LIKE ?)';
    $params[] = '%' . $filtro . '%';
    $params[] = '%' . $filtro . '%';
}
if ($estado !== '') {
    $where[] = 'c.estado = ?';
    $params[] = $estado;
}
if ($canal !== '') {
    $where[] = 'COALESCE(c.canal, "") = ?';
    $params[] = $canal;
}
if ($regional !== '') {
    $where[] = 'COALESCE(c.regional, "") = ?';
    $params[] = $regional;
}

$sql = 'SELECT
            c.id,
            c.cuenta,
            COALESCE(NULLIF(c.nombre_cliente, ""), c.nombre) AS nombre_cliente,
            COALESCE(NULLIF(c.nro_identificacion, ""), c.nit) AS nro_identificacion,
            c.fecha_activacion,
            c.estado,
            c.canal,
            c.regional,
            COALESCE(SUM(CASE WHEN d.estado_documento = "activo" THEN d.saldo_pendiente ELSE 0 END), 0) AS total_cartera,
            GREATEST(
                COALESCE(MAX(h.fecha_evento), "1900-01-01 00:00:00"),
                COALESCE(MAX(d.created_at), "1900-01-01 00:00:00"),
                COALESCE(c.updated_at, c.created_at, "1900-01-01 00:00:00")
            ) AS ultima_actividad
        FROM clientes c
        LEFT JOIN cartera_documentos d ON d.cliente_id = c.id
        LEFT JOIN cliente_historial h ON h.cliente_id = c.id
        WHERE ' . implode(' AND ', $where) . '
        GROUP BY c.id, c.cuenta, c.nombre_cliente, c.nombre, c.nro_identificacion, c.nit, c.fecha_activacion, c.estado, c.canal, c.regional
        ORDER BY total_cartera DESC, ultima_actividad DESC, nombre_cliente ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$kpiStmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS total_clientes,
        COALESCE(SUM(t.total_cartera), 0) AS cartera_total
     FROM (
        SELECT c.id, COALESCE(SUM(CASE WHEN d.estado_documento = "activo" THEN d.saldo_pendiente ELSE 0 END), 0) AS total_cartera
        FROM clientes c
        LEFT JOIN cartera_documentos d ON d.cliente_id = c.id
        WHERE ' . implode(' AND ', $where) . '
        GROUP BY c.id
     ) t'
);
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_clientes' => 0, 'cartera_total' => 0];

$filterOptionsWhere = ['1=1'];
$filterOptionsParams = [];
if ($scope['sql'] !== '') {
    $filterOptionsWhere[] = ltrim($scope['sql'], ' AND');
    $filterOptionsParams = array_merge($filterOptionsParams, $scope['params']);
}
if ($estado !== '') {
    $filterOptionsWhere[] = 'c.estado = ?';
    $filterOptionsParams[] = $estado;
}
$filterOptionsStmt = $pdo->prepare(
    'SELECT DISTINCT canal, regional
     FROM clientes c
     WHERE ' . implode(' AND ', $filterOptionsWhere)
);
$filterOptionsStmt->execute($filterOptionsParams);
$filterRows = $filterOptionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$canalesDisponibles = [];
$regionalesDisponibles = [];
foreach ($filterRows as $row) {
    if (trim((string)($row['canal'] ?? '')) !== '') {
        $canalesDisponibles[(string)$row['canal']] = (string)$row['canal'];
    }
    if (trim((string)($row['regional'] ?? '')) !== '') {
        $regionalesDisponibles[(string)$row['regional']] = (string)$row['regional'];
    }
}
ksort($canalesDisponibles);
ksort($regionalesDisponibles);

ob_start();
?>
<div class="card">
  <div class="card-header">
    <h3>Base maestra de clientes</h3>
    <?= ui_badge((string)((int)$kpi['total_clientes']) . ' clientes', 'info') ?>
  </div>
  <p class="muted">El maestro se alimenta automáticamente desde cada carga SAP, acumula historial por cliente y respeta la visibilidad asignada por responsable.</p>
  <div class="kpi-grid">
    <div class="kpi-card">
      <span class="kpi-label">Clientes</span>
      <strong class="kpi-value"><?= (int)$kpi['total_clientes'] ?></strong>
    </div>
    <div class="kpi-card">
      <span class="kpi-label">Cartera activa total</span>
      <strong class="kpi-value">$<?= number_format((float)$kpi['cartera_total'], 2, ',', '.') ?></strong>
    </div>
  </div>
</div>

<form class="card" method="get">
  <div class="row row-wrap">
    <input type="text" name="q" value="<?= htmlspecialchars($filtro) ?>" placeholder="Buscar por nombre o NIT">
    <select name="estado">
      <option value="" <?= $estado === '' ? 'selected' : '' ?>>Todos los estados</option>
      <option value="activo" <?= $estado === 'activo' ? 'selected' : '' ?>>Activos</option>
      <option value="inactivo" <?= $estado === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
    </select>
    <select name="canal">
      <option value="" <?= $canal === '' ? 'selected' : '' ?>>Todos los canales</option>
      <?php foreach ($canalesDisponibles as $item): ?>
        <option value="<?= htmlspecialchars($item) ?>" <?= $canal === $item ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="regional">
      <option value="" <?= $regional === '' ? 'selected' : '' ?>>Todas las regionales</option>
      <?php foreach ($regionalesDisponibles as $item): ?>
        <option value="<?= htmlspecialchars($item) ?>" <?= $regional === $item ? 'selected' : '' ?>><?= htmlspecialchars($item) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Filtrar</button>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('clientes/lista.php')) ?>">Limpiar</a>
  </div>
</form>

<div class="card table-responsive">
  <table class="table">
    <tr>
      <th>Nombre</th>
      <th>NIT</th>
      <th>Fecha activación</th>
      <th>Antigüedad</th>
      <th>Saldo cartera activa</th>
      <th>Última actividad</th>
      <th>Estado</th>
      <th>Acción</th>
    </tr>
    <?php foreach ($clientes as $cliente): ?>
      <tr>
        <td>
          <strong><?= htmlspecialchars((string)$cliente['nombre_cliente']) ?></strong><br>
          <small class="muted">Cuenta SAP: <?= htmlspecialchars((string)$cliente['cuenta']) ?></small>
        </td>
        <td><?= htmlspecialchars((string)$cliente['nro_identificacion']) ?></td>
        <td><?= htmlspecialchars((string)($cliente['fecha_activacion'] ?? '-')) ?></td>
        <td><?= htmlspecialchars(client_antiquity_label((string)($cliente['fecha_activacion'] ?? ''))) ?></td>
        <td>$<?= number_format((float)$cliente['total_cartera'], 2, ',', '.') ?></td>
        <td><?= htmlspecialchars((string)($cliente['ultima_actividad'] ?? '-')) ?></td>
        <td><?= ui_badge((string)$cliente['estado'], $cliente['estado'] === 'activo' ? 'success' : 'warning') ?></td>
        <td><a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars(app_url('clientes/detalle.php?id=' . (int)$cliente['id'])) ?>">Ver perfil</a></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php
$content = ob_get_clean();
render_layout('Clientes', $content);
