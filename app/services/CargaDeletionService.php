<?php

declare(strict_types=1);

function carga_delete_redirect_with_flash(string $type, string $message): void
{
    $_SESSION['flash_carga_delete'] = [
        'type' => $type,
        'message' => $message,
    ];

    redirect_to('cargas/historial.php');
}

function carga_find_by_id(PDO $pdo, int $cargaId): ?array
{
    $stmt = $pdo->prepare('SELECT id, estado FROM cargas_cartera WHERE id = ? LIMIT 1');
    $stmt->execute([$cargaId]);
    $carga = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($carga) ? $carga : null;
}

function carga_delete_by_id(PDO $pdo, int $cargaId, int $userId): void
{
    $carga = carga_find_by_id($pdo, $cargaId);
    if ($carga === null) {
        throw new RuntimeException('El cargue indicado no existe.');
    }

    $pdo->beginTransaction();

    try {
        $documentIds = carga_collect_document_ids($pdo, $cargaId);

        if ($documentIds !== []) {
            carga_delete_bitacora_by_document_ids($pdo, $documentIds);
            carga_release_recaudo_links($pdo, $documentIds);
            carga_delete_conciliacion_by_document_ids($pdo, $documentIds);
            carga_delete_client_history_by_document_ids($pdo, $documentIds);
        }

        carga_delete_client_history_by_carga_ids($pdo, [$cargaId]);

        $pdo->prepare('DELETE FROM cartera_documentos WHERE id_carga = ?')->execute([$cargaId]);
        $pdo->prepare('DELETE FROM carga_errores WHERE carga_id = ?')->execute([$cargaId]);
        $pdo->prepare('DELETE FROM cargas_cartera WHERE id = ?')->execute([$cargaId]);

        audit_log(
            $pdo,
            'cargas_cartera',
            $cargaId,
            'carga_eliminada_temporal',
            (string)($carga['estado'] ?? 'activa'),
            'eliminada',
            $userId
        );

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function carga_prepare_mass_delete(PDO $pdo): array
{
    $tables = carga_list_existing_tables($pdo);
    $lookup = array_flip($tables);

    $cargaIds = isset($lookup['cargas_cartera'])
        ? ($pdo->query('SELECT id FROM cargas_cartera')->fetchAll(PDO::FETCH_COLUMN) ?: [])
        : [];
    $documentIds = isset($lookup['cartera_documentos'])
        ? ($pdo->query('SELECT id FROM cartera_documentos')->fetchAll(PDO::FETCH_COLUMN) ?: [])
        : [];

    return [
        'carga_ids' => array_values(array_unique(array_map('intval', $cargaIds))),
        'document_ids' => array_values(array_unique(array_map('intval', $documentIds))),
        'existing_tables' => $tables,
    ];
}

function carga_delete_related_data(PDO $pdo, array $cargaIds, array $documentIds): void
{
    $cargaIds = array_values(array_unique(array_map('intval', $cargaIds)));
    $documentIds = array_values(array_unique(array_map('intval', $documentIds)));

    if ($documentIds !== []) {
        carga_delete_bitacora_by_document_ids($pdo, $documentIds);
        carga_release_recaudo_links($pdo, $documentIds);
        carga_delete_conciliacion_by_document_ids($pdo, $documentIds);
        carga_delete_client_history_by_document_ids($pdo, $documentIds);
    }

    if ($cargaIds !== []) {
        carga_delete_client_history_by_carga_ids($pdo, $cargaIds);
    }
}

function carga_collect_document_ids(PDO $pdo, int $cargaId): array
{
    $stmt = $pdo->prepare('SELECT id FROM cartera_documentos WHERE id_carga = ?');
    $stmt->execute([$cargaId]);
    $documentIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    return array_values(array_unique(array_map('intval', $documentIds)));
}

function carga_delete_bitacora_by_document_ids(PDO $pdo, array $documentIds): void
{
    carga_apply_document_id_chunks(
        $pdo,
        $documentIds,
        static function (PDO $pdo, string $placeholders, array $params): void {
            if (!carga_table_exists($pdo, 'bitacora_gestion')) {
                return;
            }

            $stmt = $pdo->prepare("DELETE FROM bitacora_gestion WHERE id_documento IN ($placeholders)");
            $stmt->execute($params);
        }
    );
}

function carga_release_recaudo_links(PDO $pdo, array $documentIds): void
{
    carga_apply_document_id_chunks(
        $pdo,
        $documentIds,
        static function (PDO $pdo, string $placeholders, array $params): void {
            $sql = "UPDATE recaudo_detalle
                    SET cartera_documento_id = NULL,
                        estado_conciliacion = CASE
                            WHEN estado_conciliacion IN ('conciliado_total', 'conciliado_parcial', 'sin_pago', 'pago_excedido', 'periodo_diferente', 'tipo_no_coincide') THEN 'pago_sin_factura'
                            ELSE estado_conciliacion
                        END,
                        observacion_conciliacion = 'Documento desvinculado por eliminación del cargue de cartera.'
                    WHERE cartera_documento_id IN ($placeholders)";
            if (!carga_table_exists($pdo, 'recaudo_detalle')) {
                return;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
    );
}

function carga_delete_conciliacion_by_document_ids(PDO $pdo, array $documentIds): void
{
    carga_apply_document_id_chunks(
        $pdo,
        $documentIds,
        static function (PDO $pdo, string $placeholders, array $params): void {
            if (!carga_table_exists($pdo, 'conciliacion_cartera_recaudo')) {
                return;
            }

            $stmt = $pdo->prepare("DELETE FROM conciliacion_cartera_recaudo WHERE cartera_id IN ($placeholders)");
            $stmt->execute($params);
        }
    );
}

function carga_delete_client_history_by_document_ids(PDO $pdo, array $documentIds): void
{
    carga_apply_document_id_chunks(
        $pdo,
        $documentIds,
        static function (PDO $pdo, string $placeholders, array $params): void {
            if (!carga_table_exists($pdo, 'cliente_historial')) {
                return;
            }

            $stmt = $pdo->prepare("DELETE FROM cliente_historial WHERE documento_id IN ($placeholders)");
            $stmt->execute($params);
        }
    );
}

function carga_delete_client_history_by_carga_ids(PDO $pdo, array $cargaIds): void
{
    carga_apply_identifier_chunks(
        $pdo,
        $cargaIds,
        static function (PDO $pdo, string $placeholders, array $params): void {
            if (!carga_table_exists($pdo, 'cliente_historial')) {
                return;
            }

            $stmt = $pdo->prepare("DELETE FROM cliente_historial WHERE carga_id IN ($placeholders)");
            $stmt->execute($params);
        }
    );
}

function carga_apply_document_id_chunks(PDO $pdo, array $documentIds, callable $handler): void
{
    carga_apply_identifier_chunks($pdo, $documentIds, $handler);
}

function carga_apply_identifier_chunks(PDO $pdo, array $ids, callable $handler): void
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($ids === []) {
        return;
    }

    foreach (array_chunk($ids, 1000) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $handler($pdo, $placeholders, $chunk);
    }
}

function carga_list_existing_tables(PDO $pdo): array
{
    static $tables = null;

    if ($tables !== null) {
        return $tables;
    }

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];

    return $tables;
}

function carga_table_exists(PDO $pdo, string $tableName): bool
{
    return in_array($tableName, carga_list_existing_tables($pdo), true);
}
