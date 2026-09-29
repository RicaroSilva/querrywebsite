<?php
use App\Core\Auth;
use App\Core\View;
View::push('scripts', 'js/connections.js');
?>
<div class="page">
    <div class="row between mb">
        <div class="muted">Guarde as ligações às bases de dados externas. As passwords são encriptadas no servidor e nunca são enviadas ao browser.</div>
        <?php if (Auth::can('connections.manage')): ?>
            <button class="btn primary" data-conn-new><?= icon('plus') ?> Nova conexão</button>
        <?php endif; ?>
    </div>

    <div class="card">
        <table class="table" id="conn-table">
            <thead><tr><th>Nome</th><th>Tipo</th><th>Servidor</th><th>Database</th><th>Ambiente</th><th>Último uso</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
        <div class="empty hidden" id="conn-empty">
            <?= icon('plug') ?><h3>Ainda não há conexões</h3>
            <p>Crie uma conexão a PostgreSQL, MySQL/MariaDB, SQL Server ou SQLite.</p>
        </div>
    </div>

    <div class="stat-grid mt">
        <?php foreach ($drivers as $d): ?>
            <div class="card card-body row" style="gap:12px">
                <?= View::partial('partials/driver-logo', ['driver' => $d['name']]) ?>
                <div class="grow" style="flex:1">
                    <b><?= e($d['label']) ?></b>
                    <div class="small muted">Extensão <code><?= e($d['extension']) ?></code></div>
                </div>
                <?php if ($d['available']): ?>
                    <span class="badge success"><?= icon('check', 'sm') ?> Disponível</span>
                <?php else: ?>
                    <span class="badge warning" title="Instale a extensão PHP no servidor">Extensão em falta</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<script type="application/json" id="conn-data"><?= json_encode(['connections' => $connections, 'drivers' => $drivers, 'environments' => $environments, 'canManage' => Auth::can('connections.manage')], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
