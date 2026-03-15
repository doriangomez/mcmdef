<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';
require_once __DIR__ . '/../../../app/services/UenService.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('No autorizado');
}

$where = [];
$params = [];
$scope = portfolio_client_scope_sql('c');
if ($scope['sql'] !== '') {
    $where[] = ltrim($scope['sql'], ' AND');
    $params = array_merge($params, $scope['params']);
}

$allowedUens = uen_user_allowed_values($pdo);
$selectedUens = uen_apply_scope(uen_requested_values('uens'), $allowedUens);
$uenScope = uen_sql_condition('d.uens', $selectedUens);
if ($uenScope['sql'] !== '') {
    $where[] = ltrim($uenScope['sql'], ' AND');
    $params = array_merge($params, $uenScope['params']);
}

$cliente = trim((string)($_GET['cliente'] ?? ''));
if ($cliente !== '') {
    $where[] = '(d.cliente LIKE ? OR c.nit LIKE ?)';
    $params[] = '%' . $cliente . '%';
    $params[] = '%' . $cliente . '%';
}
$numero = trim((string)($_GET['numero'] ?? ''));
if ($numero !== '') {
    $where[] = 'd.nro_documento LIKE ?';
    $params[] = '%' . $numero . '%';
}
$tipo = trim((string)($_GET['tipo'] ?? ''));
if ($tipo !== '') {
    $where[] = 'd.tipo = ?';
    $params[] = $tipo;
}
$canal = trim((string)($_GET['canal'] ?? ''));
if ($canal !== '') {
    $where[] = 'd.canal = ?';
    $params[] = $canal;
}
$regional = trim((string)($_GET['regional'] ?? ''));
if ($regional !== '') {
    $where[] = 'd.regional = ?';
    $params[] = $regional;
}
$estado = trim((string)($_GET['estado'] ?? 'activo'));
if ($estado !== '') {
    $where[] = 'd.estado_documento = ?';
    $params[] = $estado;
}

$sql = 'SELECT d.cuenta, d.cliente, c.nit, d.uens, d.canal, d.regional, c.empleado_ventas, d.nro_documento, d.tipo, d.fecha_contabilizacion, d.fecha_vencimiento, d.valor_documento, d.saldo_pendiente, d.moneda, d.dias_vencido, d.bucket_actual, d.bucket_1_30, d.bucket_31_60, d.bucket_61_90, d.bucket_91_180, d.bucket_181_360, d.bucket_361_plus FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY d.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="analisis_cartera.csv"');
$out = fopen('php://output', 'wb');
fputcsv($out, ['Cuenta', 'Cliente', 'NIT', 'UEN', 'Canal', 'Regional', 'Empleado de Ventas', 'Nro Documento', 'Tipo', 'Fecha Contabilización', 'Fecha Vencimiento', 'Valor Documento', 'Saldo Pendiente', 'Moneda', 'Dias Vencido', 'Actual', '1-30 Días', '31-60 Días', '61-90 Días', '91-180 Días', '181-360 Días', '361+ Días']);

foreach ($rows as $row) {
    fputcsv($out, [
        $row['cuenta'] ?? '', $row['cliente'] ?? '', $row['nit'] ?? '', $row['uens'] ?? '', $row['canal'] ?? '', $row['regional'] ?? '', $row['empleado_ventas'] ?? '', $row['nro_documento'] ?? '', $row['tipo'] ?? '', $row['fecha_contabilizacion'] ?? '', $row['fecha_vencimiento'] ?? '', $row['valor_documento'] ?? 0, $row['saldo_pendiente'] ?? 0, $row['moneda'] ?? '', $row['dias_vencido'] ?? 0, $row['bucket_actual'] ?? 0, $row['bucket_1_30'] ?? 0, $row['bucket_31_60'] ?? 0, $row['bucket_61_90'] ?? 0, $row['bucket_91_180'] ?? 0, $row['bucket_181_360'] ?? 0, $row['bucket_361_plus'] ?? 0,
    ]);
}

fclose($out);
