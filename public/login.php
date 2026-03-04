<?php
require_once __DIR__ . '/../app/config/db.php';
require_once __DIR__ . '/../app/config/auth.php';
require_once __DIR__ . '/../app/config/app.php';
require_once __DIR__ . '/../app/services/SystemSettingsService.php';

if (is_logged_in()) {
    redirect_to('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE email = ? OR SUBSTRING_INDEX(email, "@", 1) = ? LIMIT 1');
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $error = 'Credenciales inválidas.';
    } elseif ($user['estado'] !== 'activo') {
        $error = 'Usuario inactivo.';
    } else {
        $_SESSION['user'] = ['id' => (int)$user['id'], 'nombre' => $user['nombre'], 'email' => $user['email'], 'rol' => $user['rol']];
        redirect_to('index.php');
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login MCM</title>
    <link rel="icon" href="<?= htmlspecialchars(system_logo_url()) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/app.css')) ?>">
</head>
<body class="auth-page">
<div class="login-wrap">
    <img src="<?= htmlspecialchars(system_logo_url()) ?>" alt="MCM" class="logo">
    <p class="eyebrow">MCM</p>
    <h2>Ingreso al sistema</h2>
    <p class="muted">Gestión de Cartera y Recaudos</p>
    <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" class="form-vertical">
        <label for="login">Correo o usuario</label>
        <input id="login" type="text" name="login" placeholder="usuario@empresa.com" required value="<?= htmlspecialchars($_POST['login'] ?? '') ?>">
        <label for="password">Contraseña</label>
        <input id="password" type="password" name="password" placeholder="••••••••" required>
        <button class="btn btn-primary" type="submit">Ingresar</button>
    </form>
</div>
</body>
</html>
