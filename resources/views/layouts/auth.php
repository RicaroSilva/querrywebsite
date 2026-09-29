<!doctype html>
<html lang="pt" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar · <?= e(config('app.name')) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script src="<?= e(asset('js/theme.js')) ?>"></script>
</head>
<body>
<?= $content ?>
</body>
</html>
