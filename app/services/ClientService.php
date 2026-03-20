<?php

declare(strict_types=1);

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
        CONSTRAINT fk_cliente_historial_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
        CONSTRAINT fk_cliente_historial_documento FOREIGN KEY (documento_id) REFERENCES cartera_documentos(id),
        CONSTRAINT fk_cliente_historial_carga FOREIGN KEY (carga_id) REFERENCES cargas_cartera(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
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

function client_find_existing_id(PDO $pdo, array $record): ?int
{
    ensure_client_management_schema($pdo);

    $identificacion = trim((string)($record['nit'] ?? $record['nro_identificacion'] ?? ''));
    $nombre = trim((string)($record['cliente'] ?? $record['nombre_cliente'] ?? $record['nombre'] ?? ''));
    $cuenta = trim((string)($record['cuenta'] ?? ''));
    $nombreNormalizado = client_normalize_name($nombre);

    if ($identificacion !== '') {
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

    if ($nombreNormalizado !== '') {
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

    if ($cuenta !== '') {
        $stmt = $pdo->prepare('SELECT id FROM clientes WHERE cuenta = ? ORDER BY id ASC LIMIT 1');
        $stmt->execute([$cuenta]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
    }

    return null;
}

function client_resolve_activation_date(array $record, ?string $fallbackDate = null): string
{
    $activation = trim((string)($record['fecha_activacion'] ?? ''));
    if ($activation !== '') {
        return $activation;
    }

    if ($fallbackDate !== null && $fallbackDate !== '') {
        return substr($fallbackDate, 0, 10);
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

function upsert_master_client(PDO $pdo, array $record, ?string $fallbackActivationDate = null): int
{
    ensure_client_management_schema($pdo);

    $existingId = client_find_existing_id($pdo, $record);
    $nombre = trim((string)($record['cliente'] ?? $record['nombre_cliente'] ?? $record['nombre'] ?? ''));
    $identificacion = trim((string)($record['nit'] ?? $record['nro_identificacion'] ?? ''));
    $activationDate = client_resolve_activation_date($record, $fallbackActivationDate);
    $nombreNormalizado = client_normalize_name($nombre);
    $cuenta = client_resolve_account_key($record);

    if ($existingId !== null) {
        $currentStmt = $pdo->prepare('SELECT cuenta, fecha_activacion FROM clientes WHERE id = ? LIMIT 1');
        $currentStmt->execute([$existingId]);
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $fechaActual = trim((string)($current['fecha_activacion'] ?? ''));
        $fechaActivacion = $fechaActual !== '' && $fechaActual <= $activationDate ? $fechaActual : $activationDate;
        $cuentaPersistida = trim((string)($current['cuenta'] ?? ''));

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
                 regional = ?,
                 empleado_ventas = ?,
                 fecha_activacion = ?,
                 fecha_creacion = COALESCE(fecha_creacion, created_at, NOW()),
                 estado = "activo",
                 nombre_normalizado = ?,
                 updated_at = NOW()
             WHERE id = ?'
        );
        $update->execute([
            $cuentaPersistida !== '' ? $cuentaPersistida : $cuenta,
            $nombre,
            $nombre,
            $identificacion,
            $identificacion,
            ($record['direccion'] ?? '') !== '' ? $record['direccion'] : null,
            ($record['contacto'] ?? '') !== '' ? $record['contacto'] : null,
            ($record['telefono'] ?? '') !== '' ? $record['telefono'] : null,
            ($record['canal'] ?? '') !== '' ? $record['canal'] : null,
            ($record['regional'] ?? '') !== '' ? $record['regional'] : null,
            ($record['empleado_ventas'] ?? '') !== '' ? $record['empleado_ventas'] : null,
            $fechaActivacion,
            $nombreNormalizado,
            $existingId,
        ]);

        return $existingId;
    }

    $insert = $pdo->prepare(
        'INSERT INTO clientes
         (cuenta, nombre, nombre_cliente, nit, nro_identificacion, direccion, contacto, telefono, canal, regional, empleado_ventas, fecha_activacion, fecha_creacion, estado, nombre_normalizado, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), "activo", ?, NOW(), NOW())'
    );
    $insert->execute([
        $cuenta,
        $nombre,
        $nombre,
        $identificacion,
        $identificacion,
        ($record['direccion'] ?? '') !== '' ? $record['direccion'] : null,
        ($record['contacto'] ?? '') !== '' ? $record['contacto'] : null,
        ($record['telefono'] ?? '') !== '' ? $record['telefono'] : null,
        ($record['canal'] ?? '') !== '' ? $record['canal'] : null,
        ($record['regional'] ?? '') !== '' ? $record['regional'] : null,
        ($record['empleado_ventas'] ?? '') !== '' ? $record['empleado_ventas'] : null,
        $activationDate,
        $nombreNormalizado,
    ]);

    return (int)$pdo->lastInsertId();
}

function client_history_event_exists(PDO $pdo, int $clienteId, string $tipoEvento, string $fechaEvento, string $descripcion, float $valor = 0.0, ?int $documentoId = null, ?int $cargaId = null): bool
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
         LIMIT 1'
    );
    $stmt->execute([$clienteId, $tipoEvento, $fechaEvento, $descripcion, $valor, $documentoId, $documentoId, $cargaId, $cargaId]);
    return $stmt->fetchColumn() !== false;
}

function register_client_event(PDO $pdo, int $clienteId, string $fechaEvento, string $tipoEvento, float $valor, string $descripcion, ?int $documentoId = null, ?int $cargaId = null, ?int $recaudoDetalleId = null): void
{
    ensure_client_management_schema($pdo);

    if (client_history_event_exists($pdo, $clienteId, $tipoEvento, $fechaEvento, $descripcion, $valor, $documentoId, $cargaId)) {
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

function register_client_load_events(PDO $pdo, int $cargaId, string $fechaEvento, array $aggregates): void
{
    foreach ($aggregates as $clienteId => $aggregate) {
        $documentos = (int)($aggregate['documentos'] ?? 0);
        $valor = (float)($aggregate['valor'] ?? 0);
        $periodos = array_values(array_unique($aggregate['periodos'] ?? []));
        sort($periodos);
        $descripcion = 'Carga de cartera #' . $cargaId . ' con ' . $documentos . ' documento(s)';
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

    $descripcion = 'Ajuste de documento ' . $numeroDocumento . ' desde saldo $' . number_format($saldoAnterior, 2, ',', '.') . ' a $' . number_format($saldoNuevo, 2, ',', '.');
    register_client_event($pdo, $clienteId, $fechaEvento, 'ajuste', $saldoNuevo, $descripcion, $documentoId, $cargaId);
}

function register_client_document_removal(PDO $pdo, int $clienteId, int $documentoId, string $numeroDocumento, float $saldo, string $motivo, ?int $cargaId, string $fechaEvento): void
{
    $descripcion = 'Documento ' . $numeroDocumento . ' marcado como inactivo. Motivo: ' . $motivo . '.';
    register_client_event($pdo, $clienteId, $fechaEvento, 'eliminacion', $saldo, $descripcion, $documentoId, $cargaId);
}

function register_client_payment(PDO $pdo, int $clienteId, string $fechaEvento, float $valor, string $descripcion, ?int $documentoId = null, ?int $recaudoDetalleId = null): void
{
    register_client_event($pdo, $clienteId, $fechaEvento, 'pago', $valor, $descripcion, $documentoId, null, $recaudoDetalleId);
}
