<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/middlewares/require_role.php';
require_once __DIR__ . '/../../../app/views/layout.php';
require_once __DIR__ . '/../../../app/services/ExcelImportService.php';

require_role(['admin','analista']);
$msg = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    $file = $_FILES['archivo'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $hash = hash_file('sha256', $file['tmp_name']);
        $exists = $pdo->prepare('SELECT id FROM cargas_cartera WHERE hash_archivo=?');
        $exists->execute([$hash]);
        if ($exists->fetch()) {
            $errors[] = ['fila' => 0, 'campo' => 'hash_archivo', 'motivo' => 'Archivo ya cargado previamente'];
        } else {
            $rows = parse_input_file($file['tmp_name']);
            $validation = validate_cartera_rows($rows);
            $errors = $validation['errors'];
            $status = empty($errors) ? 'procesado' : 'con_errores';
            $pdo->prepare('INSERT INTO cargas_cartera (nombre_archivo,hash_archivo,usuario_id,fecha_carga,total_registros,total_errores,estado,observaciones)
                           VALUES (?,?,?,NOW(),?,?,?,?)')
                ->execute([$file['name'],$hash,$_SESSION['user']['id'],max(0,count($rows)-1),count($errors),$status,'Carga inicial MVP']);
            $cargaId = (int)$pdo->lastInsertId();

            if (empty($errors)) {
                $headers = $validation['headers'];
                try {
                    $pdo->beginTransaction();
                    for ($i=1; $i<count($rows); $i++) {
                        $r = array_combine($headers, array_pad($rows[$i], count($headers), ''));
                        $stmt = $pdo->prepare('INSERT INTO clientes (nit,nombre,canal,regional,asesor_comercial,ejecutivo_cartera,uen,marca,created_at,updated_at)
                            VALUES (?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), canal=VALUES(canal), regional=VALUES(regional),
                            asesor_comercial=VALUES(asesor_comercial), ejecutivo_cartera=VALUES(ejecutivo_cartera), uen=VALUES(uen), marca=VALUES(marca), updated_at=NOW()');
                        $stmt->execute([$r['nit'],$r['nombre_cliente'],$r['canal'],$r['regional'],$r['asesor_comercial'],$r['ejecutivo_cartera'],$r['uen'],$r['marca']]);

                        $clientId = (int)$pdo->query("SELECT id FROM clientes WHERE nit=" . $pdo->quote($r['nit']) . " LIMIT 1")->fetchColumn();
                        $diasMora = trim((string)$r['dias_mora']) !== '' ? (int)$r['dias_mora'] : max(0, (new DateTime($r['fecha_vencimiento']))->diff(new DateTime())->days);
                        $estado = ((float)$r['saldo_actual'] <= 0) ? 'cancelado' : ($diasMora > 0 ? 'vencido' : 'vigente');

                        $doc = $pdo->prepare('INSERT INTO documentos (cliente_id,tipo_documento,numero_documento,fecha_emision,fecha_vencimiento,valor_original,saldo_actual,dias_mora,periodo,estado_documento,carga_id,created_at,updated_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
                              ON DUPLICATE KEY UPDATE fecha_emision=VALUES(fecha_emision),fecha_vencimiento=VALUES(fecha_vencimiento),valor_original=VALUES(valor_original),
                              saldo_actual=VALUES(saldo_actual),dias_mora=VALUES(dias_mora),periodo=VALUES(periodo),estado_documento=VALUES(estado_documento),carga_id=VALUES(carga_id),updated_at=NOW()');
                        $doc->execute([$clientId,$r['tipo_documento'],$r['numero_documento'],$r['fecha_emision'],$r['fecha_vencimiento'],$r['valor_original'],$r['saldo_actual'],$diasMora,$r['periodo'],$estado,$cargaId]);
                    }
                    $pdo->commit();
                    $msg = 'Carga procesada correctamente. ID carga: ' . $cargaId;
                } catch (Throwable $t) {
                    $pdo->rollBack();
                    $errors[] = ['fila' => 0, 'campo' => 'transacción', 'motivo' => $t->getMessage()];
                }
            }
        }
    }
}

ob_start();
?>
<h1>Nueva carga de cartera</h1>
<?php if($msg): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($errors): ?><div class="alert alert-error"><strong>Errores:</strong><ul><?php foreach($errors as $e): ?><li>Fila <?= $e['fila'] ?> - <?= htmlspecialchars($e['campo']) ?>: <?= htmlspecialchars($e['motivo']) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="card">
<form method="post" enctype="multipart/form-data">
    <p>Formato esperado CSV/XLSX: nit,nombre_cliente,tipo_documento,numero_documento,fecha_emision,fecha_vencimiento,valor_original,saldo_actual,dias_mora,periodo,canal,regional,asesor_comercial,ejecutivo_cartera,uen,marca</p>
    <input type="file" name="archivo" required>
    <button class="btn" type="submit">Validar y procesar</button>
</form>
</div>
<?php
$content = ob_get_clean();
render_layout('Carga cartera', $content);
