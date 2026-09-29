<?php
use App\Core\View;
View::push('scripts', 'js/tree.js');
View::push('scripts', 'js/connections.js');
View::push('scripts', 'js/explorer.js');
?>
<div class="explorer">
    <aside class="explorer-side">
        <div class="side-head">
            <div class="input-icon" style="flex:1"><?= icon('search', 'sm') ?><input class="input sm" placeholder="Filtrar conexões…" data-filter></div>
            <?php if (App\Core\Auth::can('connections.manage')): ?>
                <button class="btn-icon sm" data-new-conn title="Nova conexão"><?= icon('plus', 'sm') ?></button>
            <?php endif; ?>
        </div>
        <div class="nav-section" style="padding:6px 16px">Databases</div>
        <div data-tree></div>
    </aside>
    <section class="explorer-main" data-detail>
        <div class="empty" style="margin-top:10vh">
            <?= icon('database') ?>
            <h3>Explore as suas bases de dados</h3>
            <p>Expanda uma conexão para navegar em databases, schemas, tabelas, views, funções e procedures.<br>
               Selecione um objeto para ver colunas, índices e DDL.</p>
        </div>
    </section>
</div>
<script type="application/json" id="explorer-data"><?= json_encode(['connections' => $connections], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
