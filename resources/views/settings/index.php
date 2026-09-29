<?php
use App\Core\Auth;
use App\Core\View;
View::push('scripts', 'js/settings.js');
$user = Auth::user();
$p = $prefs + ['theme' => 'dark', 'editor_font' => '13', 'page_size' => (string) $limits['page_size'], 'autocomplete' => '1', 'confirm_write' => '1'];
?>
<div class="page">
    <div class="grid-2">
        <div class="stack">
            <div class="card">
                <div class="card-head"><h2><?= icon('sun', 'sm') ?> Aparência e editor</h2></div>
                <div class="card-body" data-prefs>
                    <div class="form-grid">
                        <label class="field"><span>Tema</span>
                            <select class="select" name="theme">
                                <?php foreach (['dark' => 'Escuro', 'light' => 'Claro', 'system' => 'Sistema'] as $k => $l): ?>
                                    <option value="<?= $k ?>" <?= $p['theme'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select></label>
                        <label class="field"><span>Tamanho da fonte do editor</span>
                            <select class="select" name="editor_font">
                                <?php foreach (['12', '13', '14', '15', '16'] as $s): ?><option <?= $p['editor_font'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
                            </select></label>
                        <label class="field"><span>Linhas por página nos resultados</span>
                            <select class="select" name="page_size">
                                <?php foreach (['50', '100', '200', '500'] as $s): ?><option <?= $p['page_size'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
                            </select></label>
                        <div></div>
                        <div class="field full"><label class="check"><input type="checkbox" name="autocomplete" <?= $p['autocomplete'] === '1' ? 'checked' : '' ?>> Autocomplete automático enquanto escreve</label></div>
                        <div class="field full"><label class="check"><input type="checkbox" name="confirm_write" <?= $p['confirm_write'] === '1' ? 'checked' : '' ?>> Pedir confirmação para UPDATE/DELETE sem WHERE, DROP/TRUNCATE e escritas em produção</label></div>
                    </div>
                    <button class="btn primary" data-save-prefs><?= icon('save', 'sm') ?> Guardar preferências</button>
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2><?= icon('key', 'sm') ?> Perfil e password</h2></div>
                <div class="card-body">
                    <div class="form-grid" data-profile>
                        <label class="field"><span>Nome</span><input class="input" name="name" value="<?= e($user['name']) ?>"></label>
                        <label class="field"><span>Email</span><input class="input" value="<?= e($user['email']) ?>" disabled></label>
                    </div>
                    <button class="btn" data-save-profile>Guardar perfil</button>
                    <hr style="border:0;border-top:1px solid var(--border);margin:18px 0">
                    <div class="form-grid" data-password>
                        <label class="field full"><span>Password atual</span><input class="input" type="password" name="current" autocomplete="current-password"></label>
                        <label class="field"><span>Nova password (mín. 10)</span><input class="input" type="password" name="password" autocomplete="new-password"></label>
                        <label class="field"><span>Confirmar</span><input class="input" type="password" name="password_confirmation" autocomplete="new-password"></label>
                    </div>
                    <button class="btn" data-save-password>Alterar password</button>
                </div>
            </div>
        </div>

        <div class="stack">
            <div class="card">
                <div class="card-head"><h2><?= icon('zap', 'sm') ?> Limites de execução</h2><span class="sub">definidos no .env</span></div>
                <table class="table compact">
                    <tr><td>Timeout por statement</td><td class="mono"><?= (int) $limits['timeout'] ?> s</td><td class="muted small">QUERY_TIMEOUT_SECONDS</td></tr>
                    <tr><td>Máx. linhas em cache por resultado</td><td class="mono"><?= number_format((int) $limits['max_rows'], 0, ',', '.') ?></td><td class="muted small">QUERY_MAX_ROWS</td></tr>
                    <tr><td>Máx. linhas por exportação</td><td class="mono"><?= $limits['export_max'] ? number_format((int) $limits['export_max'], 0, ',', '.') : 'ilimitado (streaming)' ?></td><td class="muted small">EXPORT_MAX_ROWS</td></tr>
                    <tr><td>Validade da cache de resultados</td><td class="mono"><?= (int) $limits['cache_ttl'] ?> s</td><td class="muted small">RESULT_CACHE_TTL</td></tr>
                    <tr><td>Retenção do histórico</td><td class="mono"><?= (int) $limits['history_days'] ?> dias</td><td class="muted small">HISTORY_RETENTION_DAYS</td></tr>
                </table>
            </div>
            <div class="card">
                <div class="card-head"><h2><?= icon('database', 'sm') ?> Drivers</h2></div>
                <table class="table compact">
                    <?php foreach ($drivers as $d): ?>
                        <tr><td><div class="row"><?= View::partial('partials/driver-logo', ['driver' => $d['name'], 'small' => true]) ?> <?= e($d['label']) ?></div></td>
                            <td class="mono small"><?= e($d['extension']) ?></td>
                            <td><?= $d['available'] ? '<span class="badge success">instalado</span>' : '<span class="badge warning">em falta</span>' ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <div class="card">
                <div class="card-head"><h2><?= icon('info', 'sm') ?> Sistema</h2></div>
                <table class="table compact">
                    <tr><td>Versão</td><td class="mono"><?= e($system['version']) ?></td></tr>
                    <tr><td>PHP</td><td class="mono"><?= e($system['php']) ?></td></tr>
                    <tr><td>BD da aplicação</td><td class="mono"><?= e($system['app_db']) ?></td></tr>
                    <tr><td>Ambiente</td><td class="mono"><?= e($system['env']) ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <?php if (Auth::can('users.manage')): ?>
        <div class="card mt">
            <div class="card-head"><h2><?= icon('users', 'sm') ?> Utilizadores</h2><div class="spacer"></div>
                <a class="btn sm ghost" href="<?= e(url('/logs')) ?>"><?= icon('shield', 'sm') ?> Logs de auditoria</a>
                <button class="btn sm primary" data-new-user><?= icon('plus', 'sm') ?> Novo utilizador</button></div>
            <table class="table">
                <thead><tr><th>Nome</th><th>Email</th><th>Perfil</th><th>Estado</th><th>Último login</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr data-user='<?= e(json_encode(['id' => (int) $u['id'], 'name' => $u['name'], 'role' => $u['role'], 'is_active' => (bool) $u['is_active']])) ?>'>
                        <td><b><?= e($u['name']) ?></b></td>
                        <td class="mono small"><?= e($u['email']) ?></td>
                        <td><span class="badge <?= $u['role'] === 'admin' ? 'accent' : '' ?>"><?= e($roles[$u['role']] ?? $u['role']) ?></span></td>
                        <td><?= $u['is_active'] ? '<span class="badge success">ativo</span>' : '<span class="badge danger">inativo</span>' ?></td>
                        <td class="small muted"><?= e(time_ago($u['last_login_at'])) ?></td>
                        <td class="actions"><button class="btn sm" data-edit-user><?= icon('edit', 'sm') ?> Editar</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="card-body muted small">Perfis: <b>Administrador</b> (tudo), <b>Editor</b> (conexões, queries e relatórios), <b>Só leitura</b> (executa apenas SELECT; não guarda nem altera). A estrutura de roles/permissões e acesso por conexão já existe na base de dados para evolução futura.</div>
        </div>
    <?php endif; ?>
</div>
<script type="application/json" id="settings-data"><?= json_encode(['roles' => $roles], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?></script>
