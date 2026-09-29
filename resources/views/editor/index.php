<?php
use App\Core\View;
View::push('styles', 'vendor/codemirror/codemirror.bundle.css');
View::push('scripts', 'vendor/codemirror/codemirror.bundle.js');
View::push('scripts', 'vendor/sql-formatter/sql-formatter.min.js');
View::push('scripts', 'js/tree.js');
View::push('scripts', 'js/grid.js');
View::push('scripts', 'js/connections.js');
View::push('scripts', 'js/editor.js');
?>
<div class="ide" id="ide">
    <aside class="ide-side">
        <div class="tabs" data-side-tabs>
            <button class="active" data-side="objects"><?= icon('database', 'sm') ?> Objetos</button>
            <button data-side="queries"><?= icon('file-code', 'sm') ?> Queries</button>
            <button data-side="history"><?= icon('history', 'sm') ?> Histórico</button>
        </div>
        <div class="panel" data-panel="objects"></div>
        <div class="panel hidden" data-panel="queries">
            <div class="input-icon" style="margin:4px 4px 8px"><?= icon('search', 'sm') ?><input class="input sm" placeholder="Procurar queries…" data-q-search></div>
            <div data-q-list></div>
        </div>
        <div class="panel hidden" data-panel="history"><div data-h-list></div></div>
    </aside>

    <section class="ide-main">
        <div class="qtabs" data-qtabs></div>
        <div class="toolbar" data-toolbar>
            <button class="btn-icon" data-cmd="toggle-side" title="Painel lateral"><?= icon('columns') ?></button>
            <div class="conn-select" data-conn-wrap title="Conexão ativa">
                <span class="env-dot"></span>
                <select class="select" data-conn></select>
            </div>
            <div class="conn-select hidden" data-db-wrap title="Base de dados">
                <?= icon('layers', 'sm') ?>
                <select class="select" data-db style="min-width:120px"></select>
            </div>
            <span class="badge warning ro-badge" title="Apenas SELECT/WITH/SHOW/EXPLAIN são permitidos"><?= icon('shield', 'sm') ?> só leitura</span>
            <div class="sep"></div>
            <button class="btn run sm" data-cmd="run" title="Executar tudo (Ctrl+Enter / F5)"><?= icon('play', 'sm') ?> Executar</button>
            <button class="btn sm" data-cmd="run-selection" title="Executar seleção ou statement atual (Ctrl+Shift+Enter)"><?= icon('play-sel', 'sm') ?> <span class="hide-sm">Seleção</span></button>
            <button class="btn sm danger hidden" data-cmd="cancel" title="Cancelar execução"><?= icon('stop', 'sm') ?> Parar</button>
            <div class="sep"></div>
            <button class="btn-icon" data-cmd="format" title="Formatar SQL (Ctrl+Shift+F)"><?= icon('wand') ?></button>
            <?php if ($canSave): ?>
                <button class="btn-icon" data-cmd="save" title="Guardar query (Ctrl+S)"><?= icon('save') ?></button>
            <?php endif; ?>
            <div class="dropdown">
                <button class="btn-icon" data-dropdown title="Mais"><?= icon('menu') ?></button>
                <div class="menu">
                    <?php if ($canSave): ?>
                        <button data-cmd="save-as"><?= icon('copy', 'sm') ?> Guardar como…</button>
                        <button data-cmd="to-report"><?= icon('chart', 'sm') ?> Transformar em relatório</button>
                        <div class="sep"></div>
                    <?php endif; ?>
                    <button data-cmd="explain"><?= icon('zap', 'sm') ?> Explain (plano de execução)</button>
                    <button data-cmd="comment"><?= icon('type', 'sm') ?> Comentar linhas (Ctrl+/)</button>
                    <button data-cmd="shortcuts"><?= icon('info', 'sm') ?> Atalhos de teclado</button>
                </div>
            </div>
        </div>
        <div class="editor-wrap" data-editor-wrap><span class="editor-status" data-cursor></span></div>
        <div class="splitter" data-splitter title="Arraste para redimensionar"></div>
        <div class="results" data-results>
            <div class="results-empty">
                <div>
                    <?= icon('terminal', 'lg') ?>
                    <p style="margin:10px 0 4px;color:var(--text-2)">Escreva uma query e carregue em <kbd>Ctrl</kbd>+<kbd>Enter</kbd></p>
                    <p class="small" style="margin:0"><kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>Enter</kbd> executa só a seleção · <kbd>Ctrl</kbd>+<kbd>Espaço</kbd> autocomplete</p>
                </div>
            </div>
        </div>
    </section>
</div>
<script type="application/json" id="editor-data"><?= json_encode([
    'connections' => $connections,
    'folders'     => $folders,
    'boot'        => $boot,
    'canWrite'    => $canWrite,
    'canSave'     => $canSave,
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
