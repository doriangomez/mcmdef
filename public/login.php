<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/config/auth.php';

if (is_logged_in()) {
    header('Location: /index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $error = 'Credenciales inválidas.';
    } elseif ($user['estado'] !== 'activo') {
        $error = 'Usuario inactivo.';
    } else {
        $_SESSION['user'] = ['id' => (int)$user['id'], 'nombre' => $user['nombre'], 'email' => $user['email'], 'rol' => $user['rol']];
        header('Location: /index.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><title>Login MCM</title><link rel="stylesheet" href="/assets/css/app.css"></head>
<body>
<div class="login-wrap">
    <img src="/assets/img/logo-mcm.svg" alt="MCM" class="logo">
    <h2>Ingreso al sistema</h2>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
        <div><input type="email" name="email" placeholder="Correo/usuario" required style="width:100%"></div><br>
        <div><input type="password" name="password" placeholder="Contraseña" required style="width:100%"></div><br>
        <button class="btn" type="submit" style="width:100%">Ingresar</button>
    </form>
</div>
</body></html>
