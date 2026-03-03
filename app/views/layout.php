<?php
function render_layout(string $title, string $content): void
{
    ?>
    <!doctype html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title><?= htmlspecialchars($title) ?> - MCM Cartera</title>
      <link rel="icon" type="image/svg+xml" href="/assets/img/logo-mcm.svg">
      <link rel="stylesheet" href="/assets/css/app.css">
    </head>
    <body>
      <?php include __DIR__ . '/navbar.php'; ?>
      <main class="container">
        <?= $content ?>
      </main>
    </body>
    </html>
    <?php
}
