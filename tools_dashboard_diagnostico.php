<?php

declare(strict_types=1);

/**
 * Herramienta externa de diagnóstico de dashboard/cartera.
 *
 * Uso:
 *   php tools_dashboard_diagnostico.php --host=127.0.0.1 --port=3306 --db=mcm_cartera --user=root --pass=secret
 *
 * Opcional:
 *   --periodo=YYYY-MM
 *   --formato=json|txt   (default: txt)
 */

function arg(string $name, ?string $default = null): ?string
{
    global $argv;
    foreach ($argv as $item) {
        if (str_starts_with($item, '--' . $name . '=')) {
            return substr($item, strlen($name) + 3);
        }
    }
    return $default;
}

function out_line(string $line = ''): void
{
    echo $line . PHP_EOL;
}

function runQuery(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

$host = arg('host', getenv('DB_HOST') ?: '127.0.0.1');
$port = arg('port', getenv('DB_PORT') ?: '3306');
$db = arg('db', getenv('DB_NAME') ?: 'mcm_cartera');
$user = arg('user', getenv('DB_USER') ?: 'root');
$pass = arg('pass', getenv('DB_PASS') ?: '');
$periodoArg = trim((string)arg('periodo', ''));
$formato = strtolower((string)arg('formato', 'txt'));

if (!in_array($formato, ['txt', 'json'], true)) {
    fwrite(STDERR, "Formato inválido. Use --formato=txt o --formato=json" . PHP_EOL);
    exit(2);
}

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    $payload = [
        'ok' => false,
        'stage' => 'conexion',
        'error' => $e->getMessage(),
        'hint' => 'Verifica host/port/db/user/pass y acceso de red al MySQL.',
    ];

    if ($formato === 'json') {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        out_line('=== Diagnóstico Dashboard / Cartera ===');
        out_line('Estado: ERROR en conexión');
        out_line('Detalle: ' . $e->getMessage());
        out_line('Sugerencia: verifica host/port/db/user/pass y red.');
    }
    exit(1);
}

$report = [
    'ok' => true,
    'conexion' => 'ok',
    'timestamp' => date('c'),
    'inputs' => [
        'host' => $host,
        'port' => $port,
        'db' => $db,
        'periodo' => $periodoArg,
    ],
    'carga' => [],
    'validacion' => [],
    'dashboard' => [],
    'flujo' => [],
];

// 1) CARGA
$tables = ['cartera_documentos', 'cargas_cartera', 'clientes', 'control_periodos_cartera'];
$tableCounts = [];
foreach ($tables as $t) {
    $tableCounts[$t] = (int)scalar($pdo, "SELECT COUNT(*) FROM {$t}");
}

$activos = runQuery(
    $pdo,
    "SELECT
        COUNT(*) AS docs_activos,
        COUNT(DISTINCT cliente_id) AS clientes_activos,
        COALESCE(SUM(saldo_pendiente), 0) AS saldo_activo
     FROM cartera_documentos
     WHERE estado_documento = 'activo'"
)[0] ?? ['docs_activos' => 0, 'clientes_activos' => 0, 'saldo_activo' => 0];

$ultimaCarga = runQuery(
    $pdo,
    "SELECT id, fecha_carga, periodo_detectado, nombre_archivo, total_documentos, total_saldo, estado, activo
     FROM cargas_cartera
     ORDER BY fecha_carga DESC
     LIMIT 1"
)[0] ?? null;

$periodosDisponibles = array_map(
    static fn(array $r): string => (string)$r['periodo'],
    runQuery(
        $pdo,
        "SELECT DISTINCT DATE_FORMAT(DATE(COALESCE(fecha_contabilizacion, created_at)), '%Y-%m') AS periodo
         FROM cartera_documentos
         WHERE estado_documento = 'activo'
           AND DATE(COALESCE(fecha_contabilizacion, created_at)) IS NOT NULL
         ORDER BY periodo DESC"
    )
);

$periodo = $periodoArg;
if ($periodo === '') {
    $periodo = $periodosDisponibles[0] ?? '';
}

$report['carga'] = [
    'tablas' => $tableCounts,
    'cartera_activa' => [
        'docs' => (int)$activos['docs_activos'],
        'clientes' => (int)$activos['clientes_activos'],
        'saldo' => (float)$activos['saldo_activo'],
    ],
    'ultima_carga' => $ultimaCarga,
    'periodos_disponibles' => $periodosDisponibles,
    'periodo_analizado' => $periodo,
];

// 2) VALIDACIÓN / BLOQUEOS
$controlPeriodo = null;
if ($periodo !== '') {
    $controlPeriodo = runQuery(
        $pdo,
        "SELECT periodo, cartera_cargada, recaudo_cargado, presupuesto_cargado, periodo_activo, estado, fecha_actualizacion
         FROM control_periodos_cartera
         WHERE periodo = ?
         LIMIT 1",
        [$periodo]
    )[0] ?? null;
}

$maxPeriodoActivo = (string)scalar(
    $pdo,
    "SELECT COALESCE(MAX(periodo_detectado), '')
     FROM cargas_cartera
     WHERE estado = 'activa'
       AND activo = 1
       AND periodo_detectado IS NOT NULL
       AND periodo_detectado <> ''"
);

$bloqueoCronologia = false;
if ($periodo !== '' && $maxPeriodoActivo !== '' && strcmp($periodo, $maxPeriodoActivo) < 0) {
    $bloqueoCronologia = true;
}

$report['validacion'] = [
    'control_periodo' => $controlPeriodo,
    'max_periodo_activo' => $maxPeriodoActivo,
    'bloqueo_cronologia_si_se_intenta_cargar' => $bloqueoCronologia,
    'nota' => 'El bloqueo de cronología aplica al módulo de carga, no al render directo del dashboard.',
];

// 3) DASHBOARD/FILTROS (simulando base de API)
$wherePeriodoSql = '';
$paramsPeriodo = [];
if ($periodo !== '') {
    $wherePeriodoSql = " AND DATE_FORMAT(DATE(COALESCE(d.fecha_contabilizacion, d.created_at)), '%Y-%m') = ?";
    $paramsPeriodo[] = $periodo;
}

$filtros = [];
$filtros['uen'] = array_map(
    static fn(array $r): string => (string)$r['v'],
    runQuery($pdo, "SELECT DISTINCT d.uens AS v FROM cartera_documentos d WHERE d.estado_documento = 'activo' AND d.uens IS NOT NULL AND TRIM(d.uens) <> ''{$wherePeriodoSql} ORDER BY v", $paramsPeriodo)
);
$filtros['regional'] = array_map(
    static fn(array $r): string => (string)$r['v'],
    runQuery($pdo, "SELECT DISTINCT COALESCE(NULLIF(TRIM(d.regional), ''), NULLIF(TRIM(c.regional), ''), 'Sin dato') AS v FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE d.estado_documento = 'activo'{$wherePeriodoSql} ORDER BY v", $paramsPeriodo)
);
$filtros['canal'] = array_map(
    static fn(array $r): string => (string)$r['v'],
    runQuery($pdo, "SELECT DISTINCT COALESCE(NULLIF(TRIM(d.canal), ''), NULLIF(TRIM(c.canal), ''), 'Sin dato') AS v FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE d.estado_documento = 'activo'{$wherePeriodoSql} ORDER BY v", $paramsPeriodo)
);
$filtros['empleado_ventas'] = array_map(
    static fn(array $r): string => (string)$r['v'],
    runQuery($pdo, "SELECT DISTINCT COALESCE(NULLIF(TRIM(c.empleado_ventas), ''), 'Sin dato') AS v FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE d.estado_documento = 'activo'{$wherePeriodoSql} ORDER BY v", $paramsPeriodo)
);
$filtros['cliente'] = array_map(
    static fn(array $r): string => (string)$r['v'],
    runQuery($pdo, "SELECT DISTINCT COALESCE(NULLIF(TRIM(c.nombre), ''), NULLIF(TRIM(d.cliente), ''), c.cuenta, CONCAT('Cliente #', c.id)) AS v FROM cartera_documentos d INNER JOIN clientes c ON c.id = d.cliente_id WHERE d.estado_documento = 'activo'{$wherePeriodoSql} ORDER BY v", $paramsPeriodo)
);

$kpiDashboard = runQuery(
    $pdo,
    "SELECT
        COUNT(*) AS total_docs,
        COALESCE(SUM(d.saldo_pendiente),0) AS cartera_total,
        COALESCE(SUM(CASE WHEN d.dias_vencido > 0 THEN d.saldo_pendiente ELSE 0 END),0) AS cartera_vencida
     FROM cartera_documentos d
     INNER JOIN clientes c ON c.id = d.cliente_id
     WHERE d.estado_documento = 'activo'{$wherePeriodoSql}",
    $paramsPeriodo
)[0] ?? ['total_docs' => 0, 'cartera_total' => 0, 'cartera_vencida' => 0];

$clientesSinResponsable = (int)scalar($pdo, "SELECT COUNT(*) FROM clientes WHERE responsable_usuario_id IS NULL");

$report['dashboard'] = [
    'kpi_base' => [
        'total_docs' => (int)$kpiDashboard['total_docs'],
        'cartera_total' => (float)$kpiDashboard['cartera_total'],
        'cartera_vencida' => (float)$kpiDashboard['cartera_vencida'],
    ],
    'filtros' => [
        'periodo' => $periodosDisponibles,
        'uen_count' => count($filtros['uen']),
        'regional_count' => count($filtros['regional']),
        'canal_count' => count($filtros['canal']),
        'empleado_ventas_count' => count($filtros['empleado_ventas']),
        'cliente_count' => count($filtros['cliente']),
        'muestras' => [
            'uen' => array_slice($filtros['uen'], 0, 10),
            'regional' => array_slice($filtros['regional'], 0, 10),
            'canal' => array_slice($filtros['canal'], 0, 10),
            'empleado_ventas' => array_slice($filtros['empleado_ventas'], 0, 10),
            'cliente' => array_slice($filtros['cliente'], 0, 10),
        ],
    ],
    'riesgos_scope' => [
        'clientes_sin_responsable_usuario' => $clientesSinResponsable,
        'nota' => 'Si el usuario no es admin y no tiene clientes asignados, el dashboard puede verse vacío por scope.',
    ],
];

// 4) Conclusión por flujo
$fallas = [];
if ((int)$activos['docs_activos'] === 0) {
    $fallas[] = 'No hay documentos activos en cartera_documentos.';
}
if ($periodo !== '' && count($filtros['uen']) === 0) {
    $fallas[] = 'No existen UEN para el periodo analizado; el filtro UEN quedará vacío.';
}
if ($bloqueoCronologia) {
    $fallas[] = "El periodo analizado ({$periodo}) es menor al último periodo activo ({$maxPeriodoActivo}); una nueva carga sería bloqueada por cronología.";
}
if ($clientesSinResponsable > 0) {
    $fallas[] = 'Hay clientes sin responsable_usuario_id; usuarios no-admin podrían no ver datos por alcance.';
}

$report['flujo'] = [
    'carga' => (int)$activos['docs_activos'] > 0 ? 'ok' : 'sin_datos',
    'validacion' => $bloqueoCronologia ? 'bloqueo_potencial_en_carga' : 'ok',
    'dashboard' => ((int)$kpiDashboard['total_docs'] > 0 && count($filtros['uen']) > 0) ? 'ok' : 'con_riesgo_de_vacio',
    'hallazgos' => $fallas,
];

if ($formato === 'json') {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

out_line('=== Diagnóstico Dashboard / Cartera ===');
out_line('Conexión: OK');
out_line('BD: ' . $db . ' @ ' . $host . ':' . $port);
out_line('Periodo analizado: ' . ($periodo !== '' ? $periodo : '(sin periodo)'));
out_line('');
out_line('[1] Carga');
out_line('- cartera_documentos: ' . $tableCounts['cartera_documentos']);
out_line('- cargas_cartera: ' . $tableCounts['cargas_cartera']);
out_line('- clientes: ' . $tableCounts['clientes']);
out_line('- control_periodos_cartera: ' . $tableCounts['control_periodos_cartera']);
out_line('- docs activos: ' . (int)$activos['docs_activos']);
out_line('- clientes activos: ' . (int)$activos['clientes_activos']);
out_line('- saldo activo: ' . number_format((float)$activos['saldo_activo'], 2, '.', ','));
out_line('- última carga: ' . ($ultimaCarga ? json_encode($ultimaCarga, JSON_UNESCAPED_UNICODE) : 'sin registros'));
out_line('');
out_line('[2] Validación');
out_line('- máximo periodo activo: ' . ($maxPeriodoActivo !== '' ? $maxPeriodoActivo : '(sin dato)'));
out_line('- bloqueo cronología si se intenta cargar: ' . ($bloqueoCronologia ? 'SI' : 'NO'));
out_line('- control periodo actual: ' . ($controlPeriodo ? json_encode($controlPeriodo, JSON_UNESCAPED_UNICODE) : 'sin registro para periodo'));
out_line('');
out_line('[3] Dashboard/Filtros');
out_line('- KPI base docs: ' . (int)$kpiDashboard['total_docs']);
out_line('- KPI base cartera_total: ' . number_format((float)$kpiDashboard['cartera_total'], 2, '.', ','));
out_line('- Filtros: periodo=' . count($periodosDisponibles) . ', uen=' . count($filtros['uen']) . ', regional=' . count($filtros['regional']) . ', canal=' . count($filtros['canal']) . ', empleado=' . count($filtros['empleado_ventas']) . ', cliente=' . count($filtros['cliente']));
out_line('- clientes sin responsable: ' . $clientesSinResponsable);
out_line('');
out_line('[4] Flujo (carga -> validación -> dashboard)');
out_line('- carga: ' . $report['flujo']['carga']);
out_line('- validación: ' . $report['flujo']['validacion']);
out_line('- dashboard: ' . $report['flujo']['dashboard']);
if (empty($fallas)) {
    out_line('- hallazgos: sin alertas críticas detectadas');
} else {
    out_line('- hallazgos:');
    foreach ($fallas as $f) {
        out_line('  * ' . $f);
    }
}
