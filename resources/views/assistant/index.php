<?php
use App\Core\View;
View::push('scripts', 'js/grid.js');
View::push('scripts', 'js/assistant.js');
?>
<div class="assistant">
    <div class="assistant-bar">
        <div class="conn-select" title="Base de dados a consultar">
            <span class="env-dot"></span>
            <select class="select" data-conn>
                <?php foreach ($connections as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?> · <?= e($c['driver_label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="conn-select hidden" data-db-wrap title="Database"><?= icon('layers', 'sm') ?><select class="select" data-db style="min-width:120px"></select></div>
        <div class="conn-select" title="Modelo da IA"><?= icon('sparkles', 'sm') ?><select class="select" data-model style="min-width:160px"><option value="<?= e($ai['model']) ?>"><?= e($ai['model'] ?: '(modelo)') ?></option></select></div>
        <button class="btn sm" data-knowledge title="O que a IA sabe sobre esta base de dados: notas, exemplos e estrutura"><?= icon('layers', 'sm') ?> Conhecimento</button>
        <label class="check small" title="Mostra o SQL gerado e só executa quando carregar em Executar"><input type="checkbox" data-review> Rever SQL antes de executar</label>
        <div class="spacer"></div>
        <span class="row small muted" data-ai-status><span class="spinner"></span> a verificar IA…</span>
        <button class="btn-icon" data-clear title="Nova conversa"><?= icon('refresh') ?></button>
    </div>

    <div class="assistant-scroll" data-scroll>
        <div class="assistant-feed" data-feed>
            <div class="assistant-empty" data-empty>
                <div class="brand-mark" style="width:46px;height:46px;margin:0 auto 14px"><?= icon('sparkles', 'lg') ?></div>
                <h2>Pergunte aos seus dados</h2>
                <p class="muted">A IA lê a estrutura da base de dados, escreve o SQL, executa-o <b>em modo só-leitura</b> e responde em linguagem natural.
                    <?= $ai['send_results'] ? '' : '<br>Os dados dos resultados não são enviados ao modelo (AI_SEND_RESULTS=false).' ?></p>
                <div class="row wrap" style="justify-content:center;gap:8px;margin-top:16px" data-suggestions>
                    <?php foreach (['Qual o cliente que mais faturou?', 'Vendas por mês nos últimos 12 meses', 'Top 5 produtos por receita', 'Quantos clientes ativos há por país?', 'Qual o ticket médio por categoria?'] as $s): ?>
                        <button class="tag" style="height:30px;padding:0 12px;font-size:12.5px" data-suggest><?= e($s) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <form class="assistant-input" data-form>
        <textarea class="input" rows="1" placeholder="Ex.: Qual a pessoa que mais faturou este ano?" data-question maxlength="2000"></textarea>
        <button class="btn primary" data-send title="Enviar (Enter) · nova linha: Shift+Enter"><?= icon('send', 'sm') ?> Perguntar</button>
    </form>
</div>
<script type="application/json" id="assistant-data"><?= json_encode(['connections' => $connections, 'ai' => $ai, 'boot' => $boot], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
