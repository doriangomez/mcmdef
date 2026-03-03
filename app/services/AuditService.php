<?php
function audit_log(PDO $pdo, string $tabla, int $registroId, string $campo, ?string $anterior, ?string $nuevo, int $usuarioId): void
{
    $sql = "INSERT INTO auditoria_log (tabla, registro_id, campo, valor_anterior, valor_nuevo, usuario_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())";
    $pdo->prepare($sql)->execute([$tabla, $registroId, $campo, $anterior, $nuevo, $usuarioId]);
}
