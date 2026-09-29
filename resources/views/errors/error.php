<!doctype html>
<html lang="pt" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($status) ?> · <?= e(config('app.name', 'QueryDeck')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script src="<?= e(asset('js/theme.js')) ?>"></script>
</head>
<body>
<div style="min-height:100vh;display:grid;place-items:center;padding:24px">
    <div class="card" style="max-width:480px;width:100%;text-align:center;padding:36px">
        <div class="brand-mark" style="margin:0 auto 16px;width:44px;height:44px"><?= icon('alert') ?></div>
        <div style="font-size:42px;font-weight:700;letter-spacing:-.03em"><?= e($status) ?></div>
        <p class="muted"><?= e($message) ?></p>
        <div class="row" style="justify-content:center;margin-top:20px">
            <a class="btn" href="<?= e(url("/")) ?>">Início</a>
            
        </div>
    </div>
</div>
</body>
</html>
