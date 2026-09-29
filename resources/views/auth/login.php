<div class="auth-wrap">
    <section class="auth-hero">
        <div class="brand" style="padding:0">
            <div class="brand-mark"><?= icon('database') ?></div>
            <div class="brand-name"><?= e(config('app.name')) ?><small>Database Management &amp; Reporting</small></div>
        </div>
        <div>
            <h2>Todas as suas bases de dados.<br>Um só espaço de trabalho.</h2>
            <p>Ligue-se a PostgreSQL, MySQL, SQL Server e SQLite, escreva e execute SQL, guarde queries e transforme resultados em relatórios.</p>
            <div class="auth-code">
                <div><span class="c">-- Receita mensal</span></div>
                <div><span class="k">SELECT</span> date_trunc(<span class="s">'month'</span>, created_at) <span class="k">AS</span> mes,</div>
                <div>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; <span class="k">SUM</span>(total) <span class="k">AS</span> revenue</div>
                <div><span class="k">FROM</span> orders</div>
                <div><span class="k">GROUP BY</span> 1 <span class="k">ORDER BY</span> 1 <span class="k">DESC</span>;</div>
            </div>
        </div>
        <div class="muted small">v<?= e(config('app.version')) ?> · Ligações encriptadas · Execução com timeouts e modo só-leitura</div>
    </section>
    <section class="auth-panel">
        <form class="auth-card" method="post" action="<?= e(url('/login')) ?>" autocomplete="on">
            <?= csrf_field() ?>
            <h1>Bem-vindo de volta</h1>
            <p class="sub">Inicie sessão para continuar.</p>
            <?php if (!empty($error)): ?>
                <div class="alert error"><?= icon('alert') ?><span><?= e($error) ?></span></div>
            <?php endif; ?>
            <label class="field">
                <span>Email</span>
                <input class="input" type="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username">
            </label>
            <label class="field">
                <span>Password</span>
                <input class="input" type="password" name="password" required autocomplete="current-password">
            </label>
            <button class="btn primary" style="width:100%;height:40px;margin-top:6px">Entrar</button>
            <?php if (config('app.env') !== 'production' && config('app.test_user.email')): ?>
                <div class="alert info mt"><?= icon('info') ?><span>Conta de teste definida no <code>.env</code> (<code>TEST_USER_EMAIL</code>).</span></div>
            <?php endif; ?>
        </form>
    </section>
</div>
