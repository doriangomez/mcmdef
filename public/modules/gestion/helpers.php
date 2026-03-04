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

function gestion_mora_bucket_label(int $diasMora): string
{
    if ($diasMora <= 30) {
        return '0-30';
    }
    if ($diasMora <= 60) {
        return '31-60';
    }
    if ($diasMora <= 90) {
        return '61-90';
    }
    if ($diasMora <= 180) {
        return '91-180';
    }

    return '180+';
}

function gestion_mora_badge_variant(int $diasMora): string
{
    if ($diasMora <= 30) {
        return 'success';
    }
    if ($diasMora <= 60) {
        return 'warning';
    }
    if ($diasMora <= 90) {
        return 'info';
    }

    return 'danger';
}

function gestion_compromiso_estado(?string $estadoCompromiso, ?string $compromisoPago): array
{
    $estado = strtolower(trim((string)$estadoCompromiso));

    if ($estado === 'cumplido') {
        return ['Cumplido', 'success'];
    }
    if ($estado === 'incumplido') {
        return ['Incumplido', 'danger'];
    }

    if ($compromisoPago === null || $compromisoPago === '') {
        return ['Sin compromiso', 'default'];
    }

    $today = new DateTimeImmutable('today');
    $dueDate = DateTimeImmutable::createFromFormat('Y-m-d', substr($compromisoPago, 0, 10)) ?: new DateTimeImmutable($compromisoPago);
    $days = (int)$today->diff($dueDate)->format('%r%a');

    if ($days < 0) {
        return ['Vencido', 'danger'];
    }
    if ($days <= 3) {
        return ['Próximo a vencer', 'warning'];
    }

    return ['Vigente', 'success'];
}

function gestion_priority_class(int $diasMora): string
{
    if ($diasMora > 90) {
        return 'priority-high';
    }
    if ($diasMora > 60) {
        return 'priority-medium-high';
    }
    if ($diasMora > 30) {
        return 'priority-medium';
    }

    return 'priority-low';
}
