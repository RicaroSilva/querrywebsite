<?php
use App\Core\View;
View::push('scripts', 'js/history.js');
?>
<div class="page">
    <div class="row wrap mb">
        <div class="input-icon" style="flex:1;min-width:220px"><?= icon('search', 'sm') ?><input class="input" placeholder="Procurar no SQL executado…" data-search></div>
        <select class="select" style="width:160px" data-status>
            <option value="">Todos os estados</option><option value="success">Sucesso</option><option value="error">Erro</option><option value="cancelled">Cancelada</option>
        </select>
        <select class="select" style="width:200px" data-conn>
            <option value="">Todas as conexões</option>
            <?php foreach ($connections as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
        </select>
        <?php if ($canSeeAll): ?>
            <select class="select" style="width:170px" data-scope><option value="mine">Só as minhas</option><option value="all">Todos os utilizadores</option></select>
        <?php endif; ?>
        <button class="btn danger" data-clear><?= icon('trash', 'sm') ?> Limpar o meu histórico</button>
    </div>
    <div class="card">
        <div class="table-scroll"><table class="table" data-table>
            <thead><tr><th></th><th>Data/hora</th><th>Query</th><th>Base de dados</th><th>Utilizador</th><th style="text-align:right">Linhas</th><th style="text-align:right">Tempo</th><th></th></tr></thead>
            <tbody></tbody>
        </table></div>
        <div class="empty hidden" data-empty><?= icon('history') ?><h3>Sem execuções</h3><p>As queries executadas aparecem aqui.</p></div>
        <div class="grid-foot" style="padding:10px 14px"><span data-info></span><div class="spacer"></div>
            <button class="btn sm" data-prev>Anterior</button><button class="btn sm" data-next>Seguinte</button></div>
    </div>
</div>
