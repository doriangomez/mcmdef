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
        }

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
            $stmt = $pdo->prepare("DELETE FROM conciliacion_cartera_recaudo WHERE cartera_id IN ($placeholders)");
            $stmt->execute($params);
        }
    );
}

function carga_apply_document_id_chunks(PDO $pdo, array $documentIds, callable $handler): void
{
    $documentIds = array_values(array_unique(array_map('intval', $documentIds)));
    if ($documentIds === []) {
        return;
    }

    foreach (array_chunk($documentIds, 1000) as $chunk) {
        $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
        $handler($pdo, $placeholders, $chunk);
    }
}
