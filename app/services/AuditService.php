<?php
function audit_log(PDO $pdo, string $tabla, int $registroId, string $campo, ?string $anterior, ?string $nuevo, int $usuarioId): void
{
    $accion = trim($campo) !== '' ? $campo : 'evento';
    $detalle = sprintf(
        'tabla=%s registro_id=%d valor_anterior=%s valor_nuevo=%s',
        $tabla,
        $registroId,
        (string)($anterior ?? 'null'),
        (string)($nuevo ?? 'null')
    );

    $sql = "INSERT INTO auditoria_sistema (usuario_id, accion, modulo, detalle, created_at)
            VALUES (?, ?, ?, ?, NOW())";
    $pdo->prepare($sql)->execute([$usuarioId, $accion, $tabla, $detalle]);
}
