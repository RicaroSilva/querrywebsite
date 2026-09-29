<?php
use App\Core\Auth;
use App\Core\View;
View::push('scripts', 'js/reports.js');
?>
<div class="page">
    <div class="row between mb">
        <div class="muted">Relatórios e dashboards construídos a partir de queries: KPIs, gráficos, tabelas, texto e filtros.</div>
        <?php if (Auth::can('reports.manage')): ?>
            <button class="btn primary" data-new-report><?= icon('plus') ?> Novo relatório</button>
        <?php endif; ?>
    </div>
    <?php if (!$reports): ?>
        <div class="card empty"><?= icon('chart') ?><h3>Ainda não há relatórios</h3>
            <p>Crie um relatório vazio ou, na biblioteca de queries, use <b>Criar relatório</b> numa query guardada.</p></div>
    <?php endif; ?>
    <div class="grid-3">
        <?php foreach ($reports as $r): ?>
            <a class="card report-card" href="<?= e(url('/reports/' . $r['id'])) ?>">
                <div class="preview"><span></span><span></span><span></span><span></span><span></span><span></span></div>
                <div>
                    <b style="font-size:14.5px"><?= e($r['name']) ?></b>
                    <div class="muted small truncate"><?= e($r['description'] ?: 'Sem descrição') ?></div>
                </div>
                <div class="row small muted">
                    <?= icon('layers', 'sm') ?> <?= (int) $r['widget_count'] ?> componentes
                    <span style="margin-left:auto"><?= e($r['creator_name'] ?? '') ?> · <?= e(time_ago($r['updated_at'])) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
