<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/config/auth.php';
require_once __DIR__ . '/../../../app/services/PortfolioScope.php';
require_once __DIR__ . '/../../../app/services/UenService.php';
require_once __DIR__ . '/../../../app/services/XlsxExportService.php';

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
$selectedUens = uen_apply_scope(uen_requested_values('uen'), $allowedUens);
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

$sql = 'SELECT d.cuenta, d.cliente, c.nit, d.uens AS uen, d.canal, d.regional, c.empleado_ventas, d.nro_documento, d.tipo, d.fecha_contabilizacion, d.fecha_vencimiento, d.valor_documento, d.saldo_pendiente, d.moneda, d.dias_vencido, d.bucket_actual, d.bucket_1_30, d.bucket_31_60, d.bucket_61_90, d.bucket_91_180, d.bucket_181_360, d.bucket_361_plus FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id';
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY d.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$headers = ['Cuenta', 'Cliente', 'NIT', 'UEN', 'Canal', 'Regional', 'Empleado de Ventas', 'Nro Documento', 'Tipo', 'Fecha Contabilización', 'Fecha Vencimiento', 'Valor Documento', 'Saldo Pendiente', 'Moneda', 'Dias Vencido', 'Actual', '1-30 Días', '31-60 Días', '61-90 Días', '91-180 Días', '181-360 Días', '361+ Días'];

$exportRows = [];
foreach ($rows as $row) {
    $exportRows[] = [
        'Cuenta' => $row['cuenta'] ?? '',
        'Cliente' => $row['cliente'] ?? '',
        'NIT' => $row['nit'] ?? '',
        'UEN' => $row['uen'] ?? '',
        'Canal' => $row['canal'] ?? '',
        'Regional' => $row['regional'] ?? '',
        'Empleado de Ventas' => $row['empleado_ventas'] ?? '',
        'Nro Documento' => $row['nro_documento'] ?? '',
        'Tipo' => $row['tipo'] ?? '',
        'Fecha Contabilización' => $row['fecha_contabilizacion'] ?? '',
        'Fecha Vencimiento' => $row['fecha_vencimiento'] ?? '',
        'Valor Documento' => $row['valor_documento'] ?? 0,
        'Saldo Pendiente' => $row['saldo_pendiente'] ?? 0,
        'Moneda' => $row['moneda'] ?? '',
        'Dias Vencido' => $row['dias_vencido'] ?? 0,
        'Actual' => $row['bucket_actual'] ?? 0,
        '1-30 Días' => $row['bucket_1_30'] ?? 0,
        '31-60 Días' => $row['bucket_31_60'] ?? 0,
        '61-90 Días' => $row['bucket_61_90'] ?? 0,
        '91-180 Días' => $row['bucket_91_180'] ?? 0,
        '181-360 Días' => $row['bucket_181_360'] ?? 0,
        '361+ Días' => $row['bucket_361_plus'] ?? 0,
    ];
}

export_xlsx('analisis_cartera.xlsx', $headers, $exportRows, ['Valor Documento', 'Saldo Pendiente', 'Dias Vencido', 'Actual', '1-30 Días', '31-60 Días', '61-90 Días', '91-180 Días', '181-360 Días', '361+ Días']);
