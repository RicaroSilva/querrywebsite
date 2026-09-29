<?php
use App\Core\View;
View::push('scripts', 'vendor/chartjs/chart.umd.min.js');
View::push('scripts', 'js/dashboard.js');
$hour = (int) date('H');
$greet = $hour < 12 ? 'Bom dia' : ($hour < 20 ? 'Boa tarde' : 'Boa noite');
?>
<div class="page">
    <div class="row between mb" style="align-items:flex-end">
        <div>
            <div class="muted small"><?= e(long_date()) ?></div>
            <h2 style="margin:2px 0 0;font-size:22px;letter-spacing:-.02em"><?= e($greet) ?>, <?= e(explode(' ', App\Core\Auth::user()['name'])[0]) ?></h2>
        </div>
        <div class="row">
            <a class="btn" href="<?= e(url('/connections')) ?>"><?= icon('plug') ?> Nova conexão</a>
            <a class="btn primary" href="<?= e(url('/editor')) ?>"><?= icon('terminal') ?> Abrir SQL Editor</a>
        </div>
    </div>

    <div class="stat-grid">
        <a class="card stat" href="<?= e(url('/connections')) ?>">
            <div class="label"><?= icon('database') ?> Conexões</div>
            <div class="value"><?= e(number_format($counts['connections'], 0, ',', '.')) ?></div>
            <div class="hint">bases de dados configuradas</div>
        </a>
        <a class="card stat" href="<?= e(url('/queries')) ?>">
            <div class="label"><?= icon('file-code') ?> Queries guardadas</div>
            <div class="value"><?= e(number_format($counts['queries'], 0, ',', '.')) ?></div>
            <div class="hint">na biblioteca da equipa</div>
        </a>
        <a class="card stat" href="<?= e(url('/reports')) ?>">
            <div class="label"><?= icon('chart') ?> Relatórios</div>
            <div class="value"><?= e(number_format($counts['reports'], 0, ',', '.')) ?></div>
            <div class="hint">dashboards e reports</div>
        </a>
        <a class="card stat" href="<?= e(url('/history')) ?>">
            <div class="label"><?= icon('zap') ?> Execuções hoje</div>
            <div class="value"><?= e(number_format($counts['today'], 0, ',', '.')) ?></div>
            <div class="hint"><?= $counts['errors'] ? e($counts['errors'] . ' com erro · ') : '' ?>média <?= e($counts['avg_ms']) ?> ms</div>
        </a>
    </div>

    <div class="grid-2 mt">
        <div class="card">
            <div class="card-head">
                <h2>Queries recentes</h2><div class="spacer"></div>
                <a class="btn ghost sm" href="<?= e(url('/history')) ?>">Ver histórico <?= icon('chevron') ?></a>
            </div>
            <?php if (!$recent): ?>
                <div class="empty"><?= icon('history') ?><h3>Ainda sem execuções</h3><p>Abra o SQL Editor e execute a primeira query.</p></div>
            <?php endif; ?>
            <?php foreach ($recent as $r): ?>
                <a class="list-item" href="<?= e(url('/editor?history=' . $r['id'])) ?>">
                    <span class="dot <?= $r['status'] === 'success' ? 'success' : 'danger' ?>"></span>
                    <div class="grow">
                        <div class="title truncate"><?= e($r['query_name'] ?: mb_substr(preg_replace('/\s+/', ' ', $r['sql_text']), 0, 90)) ?></div>
                        <div class="muted small truncate"><?= e($r['connection_name']) ?> · <?= e(number_format((int) $r['row_count'], 0, ',', '.')) ?> linhas · <?= e($r['duration_ms']) ?> ms</div>
                    </div>
                    <?php if ($r['driver']): ?><span class="badge"><?= e(App\Modules\Drivers\DriverFactory::DRIVERS[$r['driver']]::label()) ?></span><?php endif; ?>
                    <span class="muted small" style="width:74px;text-align:right"><?= e(time_ago($r['created_at'])) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="stack">
            <div class="card">
                <div class="card-head"><h2>Atividade</h2><span class="sub">últimos 14 dias</span></div>
                <div class="card-body"><div class="spark"><canvas id="activity-chart"></canvas></div></div>
                <script type="application/json" id="activity-data"><?= json_encode($activity, JSON_HEX_TAG) ?></script>
            </div>
            <div class="card">
                <div class="card-head"><h2>Conexões</h2><div class="spacer"></div><a class="btn ghost sm" href="<?= e(url('/explorer')) ?>">Explorar</a></div>
                <?php if (!$connections): ?>
                    <div class="empty"><?= icon('plug') ?><h3>Sem conexões</h3><p><a href="<?= e(url('/connections')) ?>">Criar a primeira conexão</a></p></div>
                <?php endif; ?>
                <?php foreach ($connections as $c): ?>
                    <a class="list-item env-<?= e($c['environment']) ?>" href="<?= e(url('/editor?connection=' . $c['id'])) ?>">
                        <?= App\Core\View::partial('partials/driver-logo', ['driver' => $c['driver']]) ?>
                        <div class="grow">
                            <div class="title truncate"><?= e($c['name']) ?></div>
                            <div class="muted small truncate"><?= e($c['driver_label']) ?><?= $c['host'] ? ' · ' . e($c['host']) : '' ?></div>
                        </div>
                        <span class="env-tag"><?= e(App\Modules\Connections\ConnectionRepository::ENVIRONMENTS[$c['environment']] ?? $c['environment']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="grid-2 mt">
        <div class="card">
            <div class="card-head"><h2><?= icon('star') ?> Favoritos</h2><div class="spacer"></div><a class="btn ghost sm" href="<?= e(url('/favorites')) ?>">Todos</a></div>
            <?php if (!$favorites): ?>
                <div class="empty"><?= icon('star') ?><h3>Sem favoritos</h3><p>Marque queries com a estrela para acesso rápido.</p></div>
            <?php endif; ?>
            <?php foreach ($favorites as $q): ?>
                <a class="list-item" href="<?= e(url('/editor?query=' . $q['id'])) ?>">
                    <?= icon('file-code') ?>
                    <div class="grow">
                        <div class="title truncate"><?= e($q['name']) ?></div>
                        <div class="muted small truncate"><?= e($q['folder_name'] ?: 'Sem pasta') ?> · <?= e($q['connection_name'] ?: 'Sem conexão') ?></div>
                    </div>
                    <span class="muted small"><?= e($q['run_count']) ?> execuções</span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <div class="card-head"><h2><?= icon('chart') ?> Relatórios</h2><div class="spacer"></div><a class="btn ghost sm" href="<?= e(url('/reports')) ?>">Todos</a></div>
            <?php if (!$reports): ?>
                <div class="empty"><?= icon('chart') ?><h3>Sem relatórios</h3><p>Transforme uma query guardada num relatório.</p></div>
            <?php endif; ?>
            <?php foreach ($reports as $r): ?>
                <a class="list-item" href="<?= e(url('/reports/' . $r['id'])) ?>">
                    <?= icon('kpi') ?>
                    <div class="grow">
                        <div class="title truncate"><?= e($r['name']) ?></div>
                        <div class="muted small truncate"><?= e($r['widget_count']) ?> componentes · atualizado <?= e(time_ago($r['updated_at'])) ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
