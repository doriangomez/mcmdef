<?php

declare(strict_types=1);

function periodo_normalizar(string $periodo): string
{
    return preg_match('/^\d{4}-\d{2}$/', $periodo) ? $periodo : '';
}

function periodo_control_registrar_cartera(PDO $pdo, string $periodo, bool $activar = true): void
{
    $periodo = periodo_normalizar($periodo);
    if ($periodo === '') {
        throw new RuntimeException('Periodo de cartera inválido para control maestro.');
    }

    $stmt = $pdo->prepare('INSERT INTO control_periodos_cartera (periodo, cartera_cargada, periodo_activo, fecha_creacion, fecha_actualizacion) VALUES (?, 1, 0, NOW(), NOW()) ON DUPLICATE KEY UPDATE cartera_cargada = 1, fecha_actualizacion = NOW()');
    $stmt->execute([$periodo]);

    if ($activar) {
        periodo_control_marcar_activo($pdo, $periodo);
    }

    periodo_control_recalcular_estado($pdo, $periodo);
}

function periodo_control_registrar_recaudo(PDO $pdo, string $periodo): void
{
    $periodo = periodo_normalizar($periodo);
    if ($periodo === '') {
        throw new RuntimeException('Periodo de recaudo inválido para control maestro.');
    }

    $stmt = $pdo->prepare('INSERT INTO control_periodos_cartera (periodo, recaudo_cargado, fecha_creacion, fecha_actualizacion) VALUES (?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE recaudo_cargado = 1, fecha_actualizacion = NOW()');
    $stmt->execute([$periodo]);

    periodo_control_recalcular_estado($pdo, $periodo);
}

function periodo_control_registrar_presupuesto(PDO $pdo, string $periodo): void
{
    $periodo = periodo_normalizar($periodo);
    if ($periodo === '') {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO control_periodos_cartera (periodo, presupuesto_cargado, fecha_creacion, fecha_actualizacion) VALUES (?, 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE presupuesto_cargado = 1, fecha_actualizacion = NOW()');
    $stmt->execute([$periodo]);

    periodo_control_recalcular_estado($pdo, $periodo);
}

function periodo_control_marcar_activo(PDO $pdo, string $periodo): void
{
    $periodo = periodo_normalizar($periodo);
    if ($periodo === '') {
        return;
    }

    $pdo->exec('UPDATE control_periodos_cartera SET periodo_activo = 0 WHERE periodo_activo = 1');
    $stmt = $pdo->prepare('UPDATE control_periodos_cartera SET periodo_activo = 1, fecha_actualizacion = NOW() WHERE periodo = ?');
    $stmt->execute([$periodo]);
}

function periodo_control_validar_recaudo(PDO $pdo, string $periodo): ?string
{
    $periodo = periodo_normalizar($periodo);
    if ($periodo === '') {
        return 'No fue posible validar el periodo de recaudo.';
    }

    $stmt = $pdo->prepare('SELECT cartera_cargada FROM control_periodos_cartera WHERE periodo = ? LIMIT 1');
    $stmt->execute([$periodo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($row === null || (int)($row['cartera_cargada'] ?? 0) !== 1) {
        return 'No existe cartera cargada para este periodo. Debe cargarse primero la cartera.';
    }

    return null;
}

function periodo_control_validar_cronologia_cartera(PDO $pdo, string $periodo): ?string
{
    $periodo = periodo_normalizar($periodo);
    if ($periodo === '') {
        return 'No fue posible detectar el periodo de cartera.';
    }

    $sql = "SELECT MAX(periodo_detectado) AS periodo
            FROM cargas_cartera
            WHERE estado = 'activa'
              AND activo = 1
              AND periodo_detectado IS NOT NULL
              AND periodo_detectado <> ''";
    $maxPeriodo = (string)(($pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [])['periodo'] ?? '');
    if ($maxPeriodo !== '' && strcmp($periodo, $maxPeriodo) < 0) {
        return 'El periodo ' . $periodo . ' es anterior al último periodo cargado (' . $maxPeriodo . ').';
    }

    return null;
}

function periodo_control_obtener_activo(PDO $pdo): ?string
{
    $row = $pdo->query('SELECT periodo FROM control_periodos_cartera WHERE periodo_activo = 1 ORDER BY periodo DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $periodo = (string)($row['periodo'] ?? '');
    return $periodo !== '' ? $periodo : null;
}

function periodo_control_recalcular_estado(PDO $pdo, string $periodo): void
{
    $stmt = $pdo->prepare('SELECT cartera_cargada, recaudo_cargado, presupuesto_cargado FROM control_periodos_cartera WHERE periodo = ? LIMIT 1');
    $stmt->execute([$periodo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return;
    }

    $cartera = (int)($row['cartera_cargada'] ?? 0) === 1;
    $recaudo = (int)($row['recaudo_cargado'] ?? 0) === 1;
    $presupuesto = (int)($row['presupuesto_cargado'] ?? 0) === 1;

    $estado = 'abierto';
    if ($cartera && $recaudo && $presupuesto) {
        $estado = 'cerrado';
    } elseif ($recaudo && !$cartera) {
        $estado = 'inconsistente';
    }

    $update = $pdo->prepare('UPDATE control_periodos_cartera SET estado = ?, fecha_actualizacion = NOW() WHERE periodo = ?');
    $update->execute([$estado, $periodo]);
}
