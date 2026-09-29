<?php
use App\Core\View;
View::push('scripts', 'js/grid.js');
View::push('scripts', 'js/analyses.js');
?>
<div class="page">
    <div class="analyses-layout">
        <aside class="card" style="padding:10px">
            <div class="row between" style="padding:4px 6px 8px">
                <b class="small muted" style="text-transform:uppercase;letter-spacing:.06em">Análises</b>
                <?php if ($canManage): ?><button class="btn-icon sm" data-new title="Nova análise"><?= icon('plus', 'sm') ?></button><?php endif; ?>
            </div>
            <div class="input-icon" style="margin:0 4px 8px"><?= icon('search', 'sm') ?><input class="input sm" placeholder="Procurar…" data-filter></div>
            <div class="folder-list" data-list></div>
        </aside>
        <section data-main>
            <div class="card empty">
                <?= icon('filter') ?>
                <h3>Análises parametrizadas</h3>
                <p>Escolha uma análise à esquerda, preencha os valores (ou deixe vazio para usar os valores por omissão), execute e descarregue em Excel ou CSV.</p>
                <?php if ($canManage): ?><button class="btn primary" data-new><?= icon('plus', 'sm') ?> Nova análise</button><?php endif; ?>
            </div>
        </section>
    </div>
</div>
<script type="application/json" id="analyses-data"><?= json_encode([
    'analyses' => $analyses,
    'connections' => array_map(static fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'driver' => $c['driver'], 'driver_label' => $c['driver_label']], $connections),
    'canManage' => $canManage, 'selected' => $selected,
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
