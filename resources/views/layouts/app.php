<?php
use App\Core\Auth;
use App\Core\View;
use App\Modules\Users\UserRepository;

$user = Auth::user();
$prefs = (new UserRepository())->preferences($user);
$theme = $prefs['theme'] ?? 'dark';
$active = $active ?? '';
$initials = mb_strtoupper(mb_substr($user['name'], 0, 1) . (str_contains($user['name'], ' ') ? mb_substr(strrchr($user['name'], ' '), 1, 1) : ''));
$nav = [
    'Workspace' => [
        ['dashboard', 'Dashboard', '/', 'dashboard', 'dashboard.view'],
        ['databases', 'Bases de Dados', '/explorer', 'database', 'connections.view'],
        ['editor', 'SQL Editor', '/editor', 'terminal', 'queries.execute'],
        ['assistant', 'Assistente IA', '/assistant', 'sparkles', 'assistant.use'],
    ],
    'Biblioteca' => [
        ['queries', 'Queries', '/queries', 'file-code', 'queries.view'],
        ['favorites', 'Favoritos', '/favorites', 'star', 'queries.view'],
        ['folders', 'Pastas', '/folders', 'folder', 'queries.view'],
        ['analyses', 'Análises', '/analyses', 'filter', 'analyses.run'],
        ['reports', 'Relatórios', '/reports', 'chart', 'reports.view'],
        ['history', 'Histórico', '/history', 'history', 'history.view'],
    ],
    'Sistema' => [
        ['connections', 'Conexões', '/connections', 'plug', 'connections.view'],
        ['settings', 'Definições', '/settings', 'settings', null],
    ],
];
$boot = [
    'csrf'  => csrf_token(),
    'base'  => rtrim(url(''), '/'),
    'user'  => ['id' => (int) $user['id'], 'name' => $user['name'], 'role' => $user['role']],
    'prefs' => $prefs + ['theme' => 'dark', 'editor_font' => '13', 'page_size' => (string) config('query.page_size'), 'autocomplete' => '1', 'confirm_write' => '1'],
    'can'   => [
        'write'   => Auth::can('queries.write'),
        'save'    => Auth::can('queries.manage'),
        'conns'   => Auth::can('connections.manage'),
        'reports' => Auth::can('reports.manage'),
        'ai'      => Auth::can('assistant.use') && (bool) config('ai.enabled'),
    ],
    'icons' => App\Core\Icons::all(),
];
?><!doctype html>
<html lang="pt" data-theme="<?= e($theme === 'light' ? 'light' : 'dark') ?>" data-theme-pref="<?= e($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e(($title ?? '') . ' · ' . config('app.name')) ?></title>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <?php foreach (View::stack('styles') as $css): ?>
        <link rel="stylesheet" href="<?= e(asset($css)) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script src="<?= e(asset('js/theme.js')) ?>"></script>
</head>
<body>
<div class="app" id="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-mark"><?= icon('database') ?></div>
            <div class="brand-name"><?= e(config('app.name')) ?><small>Data Platform</small></div>
        </div>
        <nav class="nav">
            <?php foreach ($nav as $section => $items): ?>
                <div class="nav-section"><?= e($section) ?></div>
                <?php foreach ($items as [$key, $label, $href, $ic, $perm]): ?>
                    <?php if ($perm && !Auth::can($perm)) continue; ?>
                    <a href="<?= e(url($href)) ?>" class="<?= $active === $key ? 'active' : '' ?>" title="<?= e($label) ?>">
                        <?= icon($ic) ?><span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (Auth::can('logs.view')): ?>
                <a href="<?= e(url('/logs')) ?>" class="<?= ($title ?? '') === 'Logs de auditoria' ? 'active' : '' ?>" title="Logs"><?= icon('shield') ?><span>Logs</span></a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-foot">
            <div class="avatar" title="<?= e($user['name']) ?>"><?= e($initials) ?></div>
            <div class="user-meta">
                <b><?= e($user['name']) ?></b>
                <span><?= e(UserRepository::ROLES[$user['role']] ?? $user['role']) ?></span>
            </div>
            <button class="btn-icon keep" data-action="toggle-theme" title="Alternar tema"><?= icon('moon', 'theme-icon-dark') ?><?= icon('sun', 'theme-icon-light hidden') ?></button>
            <form method="post" action="<?= e(url('/logout')) ?>" class="keep-form">
                <?= csrf_field() ?>
                <button class="btn-icon" title="Terminar sessão"><?= icon('logout') ?></button>
            </form>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <button class="btn-icon" data-action="toggle-sidebar" title="Menu"><?= icon('menu') ?></button>
            <h1><?= e($title ?? '') ?></h1>
            <?php if (!empty($crumbs)): ?><div class="crumbs"><?= $crumbs ?></div><?php endif; ?>
            <div class="spacer"></div>
            <button class="search-trigger" data-action="palette"><?= icon('search') ?><span>Procurar…</span><kbd>Ctrl K</kbd></button>
            <?php if (Auth::can('queries.execute')): ?>
                <a href="<?= e(url('/editor')) ?>" class="btn primary sm hide-sm"><?= icon('plus') ?> Nova query</a>
            <?php endif; ?>
        </header>
        <div class="content <?= !empty($flush) ? 'flush' : '' ?>" id="content">
            <?= $content ?>
        </div>
    </main>
</div>
<div class="toasts" id="toasts"></div>
<script type="application/json" id="qd-boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach (View::stack('scripts') as $js): ?>
    <script src="<?= e(asset($js)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
