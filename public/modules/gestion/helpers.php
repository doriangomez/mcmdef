<?php

function gestion_get_responsables(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT DISTINCT u.id, u.nombre
         FROM usuarios u
         INNER JOIN bitacora_gestion g ON g.usuario_id = u.id
         ORDER BY u.nombre ASC'
    );

    return $stmt->fetchAll() ?: [];
}

function gestion_scope_condition(int $responsableId, string $documentAlias = 'd'): array
{
    if ($responsableId <= 0) {
        return ['sql' => '', 'params' => []];
    }

    return [
        'sql' => " AND EXISTS (SELECT 1 FROM bitacora_gestion gr WHERE gr.id_documento = {$documentAlias}.id AND gr.usuario_id = ?)",
        'params' => [$responsableId],
    ];
}

function gestion_commitment_status(?string $compromisoPago, float $saldoPendiente = 1): array
{
    if ($compromisoPago === null || $compromisoPago === '') {
        return ['Sin compromiso', 'default'];
    }

    if ($saldoPendiente <= 0) {
        return ['Compromiso cumplido', 'success'];
    }

    $today = new DateTimeImmutable('today');
    $dueDate = DateTimeImmutable::createFromFormat('Y-m-d', substr($compromisoPago, 0, 10)) ?: new DateTimeImmutable($compromisoPago);
    $days = (int)$today->diff($dueDate)->format('%r%a');

    if ($days < 0) {
        return ['Compromiso vencido', 'danger'];
    }
    if ($days <= 3) {
        return ['Compromiso próximo a vencer', 'warning'];
    }

    return ['Compromiso vigente', 'success'];
}

function gestion_priority_class(int $diasMora): string
{
    if ($diasMora >= 90) {
        return 'priority-high';
    }
    if ($diasMora >= 31) {
        return 'priority-medium';
    }

    return 'priority-low';
}
