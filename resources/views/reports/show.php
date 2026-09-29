<?php
use App\Core\View;
View::push('scripts', 'vendor/chartjs/chart.umd.min.js');
View::push('scripts', 'js/grid.js');
View::push('scripts', 'js/reports.js');
?>
<div class="page" data-report-page>
    <div class="report-toolbar">
        <div style="flex:1;min-width:240px">
            <h2 style="margin:0;font-size:21px;letter-spacing:-.02em" data-r-name><?= e($report['name']) ?></h2>
            <div class="muted small" data-r-desc><?= e($report['description'] ?: '') ?></div>
        </div>
        <span class="muted small" data-r-updated>Atualizado <?= e(time_ago($report['updated_at'])) ?></span>
        <button class="btn" data-r="refresh" title="Atualizar dados"><?= icon('refresh', 'sm') ?> Atualizar</button>
        <button class="btn" data-r="print" title="Imprimir / guardar como PDF"><?= icon('download', 'sm') ?> PDF</button>
        <?php if ($canManage): ?>
            <button class="btn" data-r="edit"><?= icon('edit', 'sm') ?> <span>Editar</span></button>
            <button class="btn primary" data-r="add"><?= icon('plus', 'sm') ?> Componente</button>
            <div class="dropdown">
                <button class="btn-icon" data-dropdown><?= icon('menu') ?></button>
                <div class="menu">
                    <button data-r="settings"><?= icon('settings', 'sm') ?> Nome, descrição e filtros</button>
                    <button data-r="duplicate"><?= icon('copy', 'sm') ?> Duplicar relatório</button>
                    <div class="sep"></div>
                    <button class="danger" data-r="delete"><?= icon('trash', 'sm') ?> Eliminar relatório</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <div class="card report-filters hidden" data-filters></div>
    <div class="widgets" data-widgets></div>
    <div class="card empty hidden" data-empty><?= icon('layers') ?><h3>Relatório vazio</h3><p>Adicione componentes: KPIs, gráficos, tabelas ou texto.</p></div>
</div>
<script type="application/json" id="report-data"><?= json_encode([
    'report' => $report, 'widgets' => $widgets, 'types' => $types,
    'queries' => array_map(static fn($q) => ['id' => (int) $q['id'], 'name' => $q['name'], 'connection_id' => $q['connection_id'] !== null ? (int) $q['connection_id'] : null, 'folder_name' => $q['folder_name']], $queries),
    'connections' => array_map(static fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'driver' => $c['driver']], $connections),
    'canManage' => $canManage,
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
