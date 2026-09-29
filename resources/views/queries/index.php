<?php
use App\Core\Auth;
use App\Core\View;
View::push('scripts', 'js/queries.js');
?>
<div class="page">
    <div class="queries-layout">
        <aside class="card" style="padding:10px">
            <div class="row between" style="padding:4px 6px 8px">
                <b class="small muted" style="text-transform:uppercase;letter-spacing:.06em">Pastas</b>
                <?php if (Auth::can('queries.manage')): ?>
                    <button class="btn-icon sm" data-new-folder title="Nova pasta"><?= icon('plus', 'sm') ?></button>
                <?php endif; ?>
            </div>
            <div class="folder-list" data-folders></div>
            <div data-tags style="padding:12px 6px 4px"></div>
        </aside>
        <section>
            <div class="row wrap mb">
                <div class="input-icon" style="flex:1;min-width:220px"><?= icon('search', 'sm') ?><input class="input" placeholder="Procurar por nome, descrição, SQL ou tag…" data-search></div>
                <select class="select" style="width:200px" data-conn-filter>
                    <option value="">Todas as conexões</option>
                    <?php foreach ($connections as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
                </select>
                <select class="select" style="width:170px" data-sort>
                    <option value="updated">Última alteração</option><option value="name">Nome</option>
                    <option value="run">Última execução</option><option value="runs">Mais executadas</option>
                </select>
                <a class="btn primary" href="<?= e(url('/editor')) ?>"><?= icon('plus') ?> Nova query</a>
            </div>
            <div class="card">
                <div class="table-scroll"><table class="table" data-table>
                    <thead><tr><th style="width:34px"></th><th>Query</th><th>Pasta</th><th>Conexão</th><th>Tags</th><th>Atualizada</th><th></th></tr></thead>
                    <tbody></tbody>
                </table></div>
                <div class="empty hidden" data-empty><?= icon('file-code') ?><h3>Nenhuma query encontrada</h3><p>Escreva uma query no SQL Editor e guarde-a com <kbd>Ctrl</kbd>+<kbd>S</kbd>.</p></div>
            </div>
        </section>
    </div>
</div>
<script type="application/json" id="queries-data"><?= json_encode([
    'mode' => $mode, 'folders' => $folders, 'connections' => $connections, 'tags' => $tags,
    'canManage' => Auth::can('queries.manage'), 'canReport' => Auth::can('reports.manage'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
