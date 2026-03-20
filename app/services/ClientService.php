<?php

declare(strict_types=1);

require_once __DIR__ . '/SystemSettingsService.php';

function client_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function client_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function client_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensure_client_management_schema(PDO $pdo): void
{
    static $initialized = false;

    if (!$initialized) {
        if (!client_table_exists($pdo, 'clientes')) {
            return;
        }

        $columns = [
            'nombre_cliente' => 'ALTER TABLE clientes ADD COLUMN nombre_cliente VARCHAR(180) NULL AFTER nombre',
            'nro_identificacion' => 'ALTER TABLE clientes ADD COLUMN nro_identificacion VARCHAR(30) NULL AFTER nit',
            'fecha_activacion' => 'ALTER TABLE clientes ADD COLUMN fecha_activacion DATE NULL AFTER nro_identificacion',
            'fecha_creacion' => 'ALTER TABLE clientes ADD COLUMN fecha_creacion DATETIME NULL AFTER fecha_activacion',
            'estado' => 'ALTER TABLE clientes ADD COLUMN estado ENUM("activo","inactivo") NOT NULL DEFAULT "activo" AFTER fecha_creacion',
            'nombre_normalizado' => 'ALTER TABLE clientes ADD COLUMN nombre_normalizado VARCHAR(190) NULL AFTER estado',
            'uen' => 'ALTER TABLE clientes ADD COLUMN uen VARCHAR(120) NULL AFTER canal',
        ];

        foreach ($columns as $column => $sql) {
            if (!client_column_exists($pdo, 'clientes', $column)) {
                $pdo->exec($sql);
            }
        }

        $indexes = [
            'idx_clientes_identificacion' => 'CREATE INDEX idx_clientes_identificacion ON clientes (nro_identificacion)',
            'idx_clientes_nombre_normalizado' => 'CREATE INDEX idx_clientes_nombre_normalizado ON clientes (nombre_normalizado)',
            'idx_clientes_estado' => 'CREATE INDEX idx_clientes_estado ON clientes (estado)',
            'idx_clientes_fecha_activacion' => 'CREATE INDEX idx_clientes_fecha_activacion ON clientes (fecha_activacion)',
            'idx_clientes_uen' => 'CREATE INDEX idx_clientes_uen ON clientes (uen)',
        ];
        foreach ($indexes as $name => $sql) {
            if (!client_index_exists($pdo, 'clientes', $name)) {
                $pdo->exec($sql);
            }
        }

        $pdo->exec("UPDATE clientes
            SET nombre_cliente = COALESCE(NULLIF(nombre_cliente, ''), nombre),
                nro_identificacion = COALESCE(NULLIF(nro_identificacion, ''), nit),
                fecha_activacion = COALESCE(fecha_activacion, DATE(created_at), CURDATE()),
                fecha_creacion = COALESCE(fecha_creacion, created_at, NOW()),
                estado = COALESCE(NULLIF(estado, ''), 'activo'),
                nombre_normalizado = COALESCE(NULLIF(nombre_normalizado, ''), LOWER(TRIM(nombre)))");

        $pdo->exec("CREATE TABLE IF NOT EXISTS cliente_historial (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            cliente_id BIGINT NOT NULL,
            fecha_evento DATETIME NOT NULL,
            tipo_evento VARCHAR(30) NOT NULL,
            valor DECIMAL(18,2) NOT NULL DEFAULT 0,
            descripcion TEXT NOT NULL,
            documento_id BIGINT NULL,
            carga_id BIGINT NULL,
            recaudo_detalle_id BIGINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cliente_historial_cliente_fecha (cliente_id, fecha_evento),
            INDEX idx_cliente_historial_tipo (tipo_evento),
            INDEX idx_cliente_historial_recaudo_detalle (recaudo_detalle_id),
            CONSTRAINT fk_cliente_historial_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
            CONSTRAINT fk_cliente_historial_documento FOREIGN KEY (documento_id) REFERENCES cartera_documentos(id),
            CONSTRAINT fk_cliente_historial_carga FOREIGN KEY (carga_id) REFERENCES cargas_cartera(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $initialized = true;
    }

    client_run_pending_migration($pdo);
}

function client_run_pending_migration(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $checked = true;
    $version = system_setting_get($pdo, 'client_master_migration_version', '');
    if ($version === '20260320') {
        return;
    }

    client_migrate_existing_portfolio($pdo);
    system_setting_set($pdo, 'client_master_migration_version', '20260320');
}

function client_normalize_name(string $value): string
{
    $normalized = trim(mb_strtolower($value, 'UTF-8'));
    $normalized = strtr($normalized, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ñ' => 'n',
    ]);
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    return trim((string)$normalized);
}

function client_human_field_label(string $field): string
{
    return [
        'cuenta' => 'Código de cuenta SAP',
        'nombre' => 'Nombre',
        'nombre_cliente' => 'Nombre',
        'nit' => 'NIT',
        'nro_identificacion' => 'NIT',
        'direccion' => 'Dirección',
        'contacto' => 'Persona de contacto',
        'telefono' => 'Teléfono',
        'canal' => 'Canal',
        'uen' => 'UEN',
        'regional' => 'Regional',
        'empleado_ventas' => 'Empleado de ventas',
        'fecha_activacion' => 'Fecha de activación',
        'estado' => 'Estado',
    ][$field] ?? ucfirst(str_replace('_', ' ', $field));
}

function client_find_existing_id(PDO $pdo, array $record): ?int
{
    ensure_client_management_schema($pdo);

    $cuenta = trim((string)($record['cuenta'] ?? ''));
    if ($cuenta !== '') {
        $stmt = $pdo->prepare('SELECT id FROM clientes WHERE cuenta = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$cuenta]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
    }

    if (!empty($record['cliente_id'])) {
        return (int)$record['cliente_id'];
    }

    $identificacion = trim((string)($record['nit'] ?? $record['nro_identificacion'] ?? ''));
    $nombre = trim((string)($record['cliente'] ?? $record['nombre_cliente'] ?? $record['nombre'] ?? ''));
    $nombreNormalizado = client_normalize_name($nombre);

    if ($cuenta === '' && $identificacion !== '') {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM clientes
             WHERE nro_identificacion = ? OR nit = ?
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute([$identificacion, $identificacion]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
    }

    if ($cuenta === '' && $nombreNormalizado !== '') {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM clientes
             WHERE nombre_normalizado = ? OR LOWER(TRIM(nombre)) = ? OR LOWER(TRIM(nombre_cliente)) = ?
             ORDER BY id ASC
             LIMIT 1'
        );
        $stmt->execute([$nombreNormalizado, $nombreNormalizado, $nombreNormalizado]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
    }

    return null;
}

function client_resolve_activation_date(array $record, ?string $fallbackDate = null): string
{
    $candidates = [
        trim((string)($record['fecha_contabilizacion'] ?? '')),
        trim((string)($record['fecha_activacion'] ?? '')),
        trim((string)($fallbackDate ?? '')),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '') {
            return substr($candidate, 0, 10);
        }
    }

    return date('Y-m-d');
}

function client_resolve_account_key(array $record): string
{
    $cuenta = trim((string)($record['cuenta'] ?? ''));
    if ($cuenta !== '') {
        return $cuenta;
    }

    $identificacion = trim((string)($record['nit'] ?? $record['nro_identificacion'] ?? ''));
    $nombre = client_normalize_name((string)($record['cliente'] ?? $record['nombre_cliente'] ?? $record['nombre'] ?? ''));
    return 'AUTO-' . substr(sha1($identificacion . '|' . $nombre), 0, 24);
}

function client_history_event_exists(PDO $pdo, int $clienteId, string $tipoEvento, string $fechaEvento, string $descripcion, float $valor = 0.0, ?int $documentoId = null, ?int $cargaId = null, ?int $recaudoDetalleId = null): bool
{
    ensure_client_management_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT id
         FROM cliente_historial
         WHERE cliente_id = ?
           AND tipo_evento = ?
           AND fecha_evento = ?
           AND descripcion = ?
           AND ABS(valor - ?) < 0.01
           AND ((documento_id IS NULL AND ? IS NULL) OR documento_id = ?)
           AND ((carga_id IS NULL AND ? IS NULL) OR carga_id = ?)
           AND ((recaudo_detalle_id IS NULL AND ? IS NULL) OR recaudo_detalle_id = ?)
         LIMIT 1'
    );
    $stmt->execute([
        $clienteId,
        $tipoEvento,
        $fechaEvento,
        $descripcion,
        $valor,
        $documentoId,
        $documentoId,
        $cargaId,
        $cargaId,
        $recaudoDetalleId,
        $recaudoDetalleId,
    ]);
    return $stmt->fetchColumn() !== false;
}

function register_client_event(PDO $pdo, int $clienteId, string $fechaEvento, string $tipoEvento, float $valor, string $descripcion, ?int $documentoId = null, ?int $cargaId = null, ?int $recaudoDetalleId = null): void
{
    ensure_client_management_schema($pdo);

    if (client_history_event_exists($pdo, $clienteId, $tipoEvento, $fechaEvento, $descripcion, $valor, $documentoId, $cargaId, $recaudoDetalleId)) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO cliente_historial
         (cliente_id, fecha_evento, tipo_evento, valor, descripcion, documento_id, carga_id, recaudo_detalle_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $clienteId,
        $fechaEvento,
        $tipoEvento,
        $valor,
        $descripcion,
        $documentoId,
        $cargaId,
        $recaudoDetalleId,
    ]);
}

function register_client_field_changes(PDO $pdo, int $clienteId, string $fechaEvento, array $changes, string $tipoEvento = 'cambio_datos'): void
{
    foreach ($changes as $field => $values) {
        $anterior = trim((string)($values['old'] ?? ''));
        $nuevo = trim((string)($values['new'] ?? ''));
        if ($anterior === $nuevo) {
            continue;
        }

        $descripcion = client_human_field_label($field) . ': ' . ($anterior !== '' ? $anterior : 'vacío') . ' → ' . ($nuevo !== '' ? $nuevo : 'vacío');
        register_client_event($pdo, $clienteId, $fechaEvento, $tipoEvento, 0.0, $descripcion);
    }
}

function register_client_state_change(PDO $pdo, int $clienteId, string $fechaEvento, string $estadoAnterior, string $estadoNuevo): void
{
    if ($estadoAnterior === $estadoNuevo) {
        return;
    }

    register_client_event(
        $pdo,
        $clienteId,
        $fechaEvento,
        'cambio_estado',
        0.0,
        'Estado: ' . ($estadoAnterior !== '' ? $estadoAnterior : 'sin definir') . ' → ' . ($estadoNuevo !== '' ? $estadoNuevo : 'sin definir')
    );
}

function upsert_master_client(PDO $pdo, array $record, ?string $fallbackActivationDate = null): int
{
    ensure_client_management_schema($pdo);

    $existingId = client_find_existing_id($pdo, $record);
    $account = client_resolve_account_key($record);
    $nombre = trim((string)($record['cliente'] ?? $record['nombre_cliente'] ?? $record['nombre'] ?? ''));
    $identificacion = trim((string)($record['nit'] ?? $record['nro_identificacion'] ?? ''));
    $activationDate = client_resolve_activation_date($record, $fallbackActivationDate);
    $nombreNormalizado = client_normalize_name($nombre);
    $canal = trim((string)($record['canal'] ?? ''));
    $uen = trim((string)($record['uens'] ?? $record['uen'] ?? ''));
    $regional = trim((string)($record['regional'] ?? ''));
    $empleadoVentas = trim((string)($record['empleado_ventas'] ?? ''));
    $direccion = trim((string)($record['direccion'] ?? ''));
    $contacto = trim((string)($record['contacto'] ?? ''));
    $telefono = trim((string)($record['telefono'] ?? ''));

    if ($existingId !== null) {
        $currentStmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
        $currentStmt->execute([$existingId]);
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $fechaActual = trim((string)($current['fecha_activacion'] ?? ''));
        $fechaActivacion = $fechaActual !== '' && $fechaActual <= $activationDate ? $fechaActual : $activationDate;

        $changes = [];
        $autoFields = [
            'nombre' => $nombre,
            'nombre_cliente' => $nombre,
            'nit' => $identificacion,
            'nro_identificacion' => $identificacion,
            'canal' => $canal,
            'uen' => $uen,
            'regional' => $regional,
            'empleado_ventas' => $empleadoVentas,
            'fecha_activacion' => $fechaActivacion,
        ];
        foreach ($autoFields as $field => $newValue) {
            $oldValue = trim((string)($current[$field] ?? ''));
            if ($oldValue !== trim((string)$newValue)) {
                $changes[$field] = ['old' => $oldValue, 'new' => trim((string)$newValue)];
            }
        }

        if (trim((string)($current['cuenta'] ?? '')) !== $account && trim((string)($current['cuenta'] ?? '')) === '') {
            $changes['cuenta'] = ['old' => trim((string)($current['cuenta'] ?? '')), 'new' => $account];
        }

        $update = $pdo->prepare(
            'UPDATE clientes
             SET cuenta = ?,
                 nombre = ?,
                 nombre_cliente = ?,
                 nit = ?,
                 nro_identificacion = ?,
                 direccion = ?,
                 contacto = ?,
                 telefono = ?,
                 canal = ?,
                 uen = ?,
                 regional = ?,
                 empleado_ventas = ?,
                 fecha_activacion = ?,
                 fecha_creacion = COALESCE(fecha_creacion, created_at, NOW()),
                 nombre_normalizado = ?,
                 updated_at = NOW()
             WHERE id = ?'
        );
        $update->execute([
            trim((string)($current['cuenta'] ?? '')) !== '' ? (string)$current['cuenta'] : $account,
            $nombre !== '' ? $nombre : (string)($current['nombre'] ?? ''),
            $nombre !== '' ? $nombre : (string)($current['nombre_cliente'] ?? $current['nombre'] ?? ''),
            $identificacion !== '' ? $identificacion : (string)($current['nit'] ?? ''),
            $identificacion !== '' ? $identificacion : (string)($current['nro_identificacion'] ?? $current['nit'] ?? ''),
            trim((string)($current['direccion'] ?? '')) !== '' ? (string)$current['direccion'] : ($direccion !== '' ? $direccion : null),
            trim((string)($current['contacto'] ?? '')) !== '' ? (string)$current['contacto'] : ($contacto !== '' ? $contacto : null),
            trim((string)($current['telefono'] ?? '')) !== '' ? (string)$current['telefono'] : ($telefono !== '' ? $telefono : null),
            $canal !== '' ? $canal : null,
            $uen !== '' ? $uen : null,
            $regional !== '' ? $regional : null,
            $empleadoVentas !== '' ? $empleadoVentas : null,
            $fechaActivacion,
            $nombreNormalizado,
            $existingId,
        ]);

        if (!empty($changes)) {
            register_client_field_changes($pdo, $existingId, date('Y-m-d H:i:s'), $changes);
        }

        return $existingId;
    }

    $insert = $pdo->prepare(
        'INSERT INTO clientes
         (cuenta, nombre, nombre_cliente, nit, nro_identificacion, direccion, contacto, telefono, canal, uen, regional, empleado_ventas, fecha_activacion, fecha_creacion, estado, nombre_normalizado, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), "activo", ?, NOW(), NOW())'
    );
    $insert->execute([
        $account,
        $nombre,
        $nombre,
        $identificacion,
        $identificacion,
        $direccion !== '' ? $direccion : null,
        $contacto !== '' ? $contacto : null,
        $telefono !== '' ? $telefono : null,
        $canal !== '' ? $canal : null,
        $uen !== '' ? $uen : null,
        $regional !== '' ? $regional : null,
        $empleadoVentas !== '' ? $empleadoVentas : null,
        $activationDate,
        $nombreNormalizado,
    ]);

    $clientId = (int)$pdo->lastInsertId();
    register_client_event(
        $pdo,
        $clientId,
        date('Y-m-d H:i:s'),
        'creacion',
        0.0,
        'Cliente creado automáticamente desde cartera. Cuenta SAP: ' . $account
    );

    return $clientId;
}

function register_client_load_events(PDO $pdo, int $cargaId, string $fechaEvento, array $aggregates): void
{
    foreach ($aggregates as $clienteId => $aggregate) {
        $documentos = (int)($aggregate['documentos'] ?? 0);
        $valor = (float)($aggregate['valor'] ?? 0);
        $periodos = array_values(array_unique($aggregate['periodos'] ?? []));
        sort($periodos);
        $descripcion = 'Carga de cartera #' . $cargaId . ' con ' . $documentos . ' documento(s) asociado(s)';
        if (!empty($periodos)) {
            $descripcion .= ' en periodo(s) ' . implode(', ', $periodos);
        }

        register_client_event($pdo, (int)$clienteId, $fechaEvento, 'carga', $valor, $descripcion, null, $cargaId);
    }
}

function register_client_document_adjustment(PDO $pdo, int $clienteId, int $documentoId, string $numeroDocumento, float $saldoAnterior, float $saldoNuevo, int $cargaId, string $fechaEvento): void
{
    if (abs($saldoAnterior - $saldoNuevo) < 0.01) {
        return;
    }

    $descripcion = 'Ajuste en documento ' . $numeroDocumento . ': saldo $' . number_format($saldoAnterior, 2, ',', '.') . ' → $' . number_format($saldoNuevo, 2, ',', '.');
    register_client_event($pdo, $clienteId, $fechaEvento, 'ajuste', $saldoNuevo, $descripcion, $documentoId, $cargaId);
}

function register_client_document_removal(PDO $pdo, int $clienteId, int $documentoId, string $numeroDocumento, float $saldo, string $motivo, ?int $cargaId, string $fechaEvento): void
{
    $descripcion = 'Documento ' . $numeroDocumento . ' marcado como inactivo. Motivo: ' . $motivo . '.';
    register_client_event($pdo, $clienteId, $fechaEvento, 'ajuste', $saldo, $descripcion, $documentoId, $cargaId);
}

function register_client_payment(PDO $pdo, int $clienteId, string $fechaEvento, float $valor, string $descripcion, ?int $documentoId = null, ?int $recaudoDetalleId = null): void
{
    register_client_event($pdo, $clienteId, $fechaEvento, 'pago', $valor, $descripcion, $documentoId, null, $recaudoDetalleId);
}

function client_update_manual_fields(PDO $pdo, int $clienteId, array $data, int $usuarioId, bool $canEditStatus = false): array
{
    ensure_client_management_schema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
    $stmt->execute([$clienteId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($current === null) {
        throw new RuntimeException('Cliente no encontrado.');
    }

    $changes = [];
    $editableFields = ['direccion', 'contacto', 'telefono'];
    foreach ($editableFields as $field) {
        $old = trim((string)($current[$field] ?? ''));
        $new = trim((string)($data[$field] ?? ''));
        if ($old !== $new) {
            $changes[$field] = ['old' => $old, 'new' => $new];
        }
    }

    $estadoNuevo = trim((string)($data['estado'] ?? (string)($current['estado'] ?? 'activo')));
    $estadoActual = trim((string)($current['estado'] ?? 'activo'));
    if ($canEditStatus && in_array($estadoNuevo, ['activo', 'inactivo'], true) && $estadoNuevo !== $estadoActual) {
        $changes['estado'] = ['old' => $estadoActual, 'new' => $estadoNuevo];
    } else {
        $estadoNuevo = $estadoActual;
    }

    if (empty($changes)) {
        return ['updated' => false, 'changes' => []];
    }

    $update = $pdo->prepare(
        'UPDATE clientes
         SET direccion = ?, contacto = ?, telefono = ?, estado = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $update->execute([
        trim((string)($data['direccion'] ?? '')) !== '' ? trim((string)$data['direccion']) : null,
        trim((string)($data['contacto'] ?? '')) !== '' ? trim((string)$data['contacto']) : null,
        trim((string)($data['telefono'] ?? '')) !== '' ? trim((string)$data['telefono']) : null,
        $estadoNuevo,
        $clienteId,
    ]);

    $eventDate = date('Y-m-d H:i:s');
    $fieldChanges = $changes;
    unset($fieldChanges['estado']);
    if (!empty($fieldChanges)) {
        register_client_field_changes($pdo, $clienteId, $eventDate, $fieldChanges);
    }
    if (isset($changes['estado'])) {
        register_client_state_change($pdo, $clienteId, $eventDate, (string)$changes['estado']['old'], (string)$changes['estado']['new']);
    }

    return ['updated' => true, 'changes' => $changes];
}

function client_antiquity_label(?string $fechaActivacion): string
{
    if ($fechaActivacion === null || trim($fechaActivacion) === '') {
        return '-';
    }

    $desde = new DateTimeImmutable(substr($fechaActivacion, 0, 10));
    $hoy = new DateTimeImmutable('today');
    $diff = $desde->diff($hoy);

    if ((int)$diff->y > 0) {
        return $diff->y . ' año(s)';
    }
    if ((int)$diff->m > 0) {
        return $diff->m . ' mes(es)';
    }
    return $diff->days . ' día(s)';
}

function client_history_icon(string $tipoEvento): string
{
    return [
        'creacion' => 'fa-solid fa-user-plus',
        'carga' => 'fa-solid fa-file-arrow-up',
        'pago' => 'fa-solid fa-money-bill-wave',
        'ajuste' => 'fa-solid fa-scale-balanced',
        'cambio_datos' => 'fa-solid fa-pen-to-square',
        'cambio_estado' => 'fa-solid fa-toggle-on',
        'gestion' => 'fa-solid fa-phone-volume',
    ][$tipoEvento] ?? 'fa-solid fa-clock-rotate-left';
}

function client_migrate_existing_portfolio(PDO $pdo): void
{
    if (!client_table_exists($pdo, 'cartera_documentos')) {
        return;
    }

    $docsStmt = $pdo->query(
        'SELECT d.id, d.id_carga, d.cliente_id, d.cuenta, d.cliente, d.canal, d.uens, d.regional, d.nro_documento, d.tipo,
                d.fecha_contabilizacion, d.saldo_pendiente, d.created_at, c.fecha_carga, cli.id AS linked_client_id, cli.estado AS linked_estado
         FROM cartera_documentos d
         LEFT JOIN cargas_cartera c ON c.id = d.id_carga
         LEFT JOIN clientes cli ON cli.id = d.cliente_id
         ORDER BY d.fecha_contabilizacion ASC, d.id ASC'
    );
    $docs = $docsStmt ? ($docsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $loadAggregates = [];
    $updateDocStmt = $pdo->prepare('UPDATE cartera_documentos SET cliente_id = ? WHERE id = ?');

    foreach ($docs as $doc) {
        $clientId = upsert_master_client($pdo, [
            'cliente_id' => (int)($doc['linked_client_id'] ?? 0),
            'cuenta' => (string)($doc['cuenta'] ?? ''),
            'cliente' => (string)($doc['cliente'] ?? ''),
            'nit' => '',
            'canal' => (string)($doc['canal'] ?? ''),
            'uens' => (string)($doc['uens'] ?? ''),
            'regional' => (string)($doc['regional'] ?? ''),
            'fecha_contabilizacion' => (string)($doc['fecha_contabilizacion'] ?? ''),
        ], (string)($doc['fecha_contabilizacion'] ?? ''));

        if ((int)($doc['cliente_id'] ?? 0) !== $clientId) {
            $updateDocStmt->execute([$clientId, (int)$doc['id']]);
        }

        $cargaId = (int)($doc['id_carga'] ?? 0);
        if ($cargaId > 0) {
            if (!isset($loadAggregates[$cargaId])) {
                $loadAggregates[$cargaId] = [];
            }
            if (!isset($loadAggregates[$cargaId][$clientId])) {
                $loadAggregates[$cargaId][$clientId] = ['documentos' => 0, 'valor' => 0.0, 'periodos' => []];
            }
            $loadAggregates[$cargaId][$clientId]['documentos']++;
            $loadAggregates[$cargaId][$clientId]['valor'] += (float)($doc['saldo_pendiente'] ?? 0);
            $periodo = substr((string)($doc['fecha_contabilizacion'] ?? ''), 0, 7);
            if ($periodo !== '') {
                $loadAggregates[$cargaId][$clientId]['periodos'][] = $periodo;
            }
        }
    }

    foreach ($loadAggregates as $cargaId => $aggregate) {
        $fechaStmt = $pdo->prepare('SELECT fecha_carga FROM cargas_cartera WHERE id = ? LIMIT 1');
        $fechaStmt->execute([(int)$cargaId]);
        $fechaEvento = (string)($fechaStmt->fetchColumn() ?: date('Y-m-d H:i:s'));
        register_client_load_events($pdo, (int)$cargaId, $fechaEvento, $aggregate);
    }

    if (client_table_exists($pdo, 'bitacora_gestion')) {
        $gestStmt = $pdo->query(
            'SELECT g.id, g.id_documento, g.observacion, g.valor_compromiso, g.created_at, d.cliente_id
             FROM bitacora_gestion g
             INNER JOIN cartera_documentos d ON d.id = g.id_documento'
        );
        $gestiones = $gestStmt ? ($gestStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($gestiones as $gestion) {
            $clienteId = (int)($gestion['cliente_id'] ?? 0);
            if ($clienteId <= 0) {
                continue;
            }
            register_client_event(
                $pdo,
                $clienteId,
                (string)$gestion['created_at'],
                'gestion',
                (float)($gestion['valor_compromiso'] ?? 0),
                'Gestión registrada: ' . trim((string)($gestion['observacion'] ?? '')),
                (int)($gestion['id_documento'] ?? 0)
            );
        }
    }

    if (client_table_exists($pdo, 'recaudo_detalle')) {
        $payStmt = $pdo->query(
            'SELECT r.id, r.created_at, r.importe_aplicado, r.documento_aplicado, r.cartera_documento_id, d.cliente_id
             FROM recaudo_detalle r
             LEFT JOIN cartera_documentos d ON d.id = r.cartera_documento_id'
        );
        $payments = $payStmt ? ($payStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($payments as $payment) {
            $clienteId = (int)($payment['cliente_id'] ?? 0);
            if ($clienteId <= 0) {
                continue;
            }
            register_client_payment(
                $pdo,
                $clienteId,
                (string)$payment['created_at'],
                (float)($payment['importe_aplicado'] ?? 0),
                'Pago registrado para documento ' . (string)($payment['documento_aplicado'] ?? ''),
                !empty($payment['cartera_documento_id']) ? (int)$payment['cartera_documento_id'] : null,
                (int)($payment['id'] ?? 0)
            );
        }
    }
}
