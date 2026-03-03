<?php
require_once __DIR__ . '/../../../app/config/db.php';
require_once __DIR__ . '/../../../app/middlewares/require_auth.php';
require_once __DIR__ . '/../../../app/views/layout.php';
$cargas = $pdo->query('SELECT c.*, u.nombre usuario FROM cargas_cartera c LEFT JOIN usuarios u ON u.id=c.usuario_id ORDER BY id DESC')->fetchAll();
ob_start(); ?>
<h1>Historial de cargas</h1>
<table class="table"><tr><th>ID</th><th>Archivo</th><th>Estado</th><th>Errores</th><th>Registros</th><th>Usuario</th><th>Fecha</th><th>Detalle</th></tr>
<?php foreach($cargas as $c): ?><tr><td><?= $c['id'] ?></td><td><?= htmlspecialchars($c['nombre_archivo']) ?></td><td><?= $c['estado'] ?></td><td><?= $c['total_errores'] ?></td><td><?= $c['total_registros'] ?></td><td><?= htmlspecialchars($c['usuario']??'-') ?></td><td><?= $c['fecha_carga'] ?></td><td><a href="/modules/cargas/detalle.php?id=<?= $c['id'] ?>">Ver</a></td></tr><?php endforeach; ?>
</table>
<?php $content=ob_get_clean(); render_layout('Historial cargas',$content);
