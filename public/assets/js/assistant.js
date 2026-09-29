/* AI assistant: natural-language questions → read-only SQL → answer + result grid. */
(function () {
  'use strict';
  const { esc, icon } = QD;
  const D = JSON.parse(document.getElementById('assistant-data').textContent);
  const $ = (s) => document.querySelector(s);
  const feed = $('[data-feed]');
  const scroll = $('[data-scroll]');
  const input = $('[data-question]');
  const connSel = $('[data-conn]');
  const dbSel = $('[data-db]');
  const modelSel = $('[data-model]');
  const review = $('[data-review]');
  const KEY = `qd-assistant:${QD.boot.user.id}`;
  let busy = false;
  let turns = []; // {question, sql, answer} — context for follow-up questions

  const conn = () => D.connections.find((c) => c.id === +connSel.value);
  try {
    const saved = JSON.parse(localStorage.getItem(KEY) || '{}');
    if (saved.connection && D.connections.some((c) => c.id === saved.connection)) connSel.value = saved.connection;
    review.checked = !!saved.review;
  } catch (e) {}
  if (D.boot.connection && D.connections.some((c) => c.id === D.boot.connection)) connSel.value = D.boot.connection;
  const persist = () => { try { localStorage.setItem(KEY, JSON.stringify({ connection: +connSel.value, review: review.checked })); } catch (e) {} };

  /* ---------- status & models ---------- */
  (async () => {
    const st = $('[data-ai-status]');
    if (!D.ai.enabled) { st.innerHTML = `<span class="dot danger"></span> IA desativada (AI_ENABLED)`; return; }
    try {
      const r = await QD.get('/api/assistant/status');
      if (!r.ok) throw new Error(r.error);
      st.innerHTML = `<span class="dot ${r.model_found ? 'success' : 'warning'}"></span> ${r.model_found ? 'IA ligada' : 'modelo configurado não encontrado'}`;
      if (r.models.length) {
        const current = modelSel.value;
        modelSel.innerHTML = r.models.map((m) => `<option ${m === current ? 'selected' : ''}>${esc(m)}</option>`).join('');
        if (!r.models.includes(current) && current) modelSel.insertAdjacentHTML('afterbegin', `<option selected>${esc(current)}</option>`);
      }
    } catch (e) {
      st.innerHTML = `<span class="dot danger"></span> <span title="${esc(e.message)}">IA inacessível</span>`;
      QD.toast(e.message, 'error', 8000);
    }
  })();

  /* ---------- databases per connection ---------- */
  async function syncDb() {
    const c = conn();
    const wrap = $('[data-db-wrap]');
    wrap.parentElement.querySelector('.conn-select').className = `conn-select env-${c?.environment || ''}`;
    if (!c || !c.capabilities?.databases) { wrap.classList.add('hidden'); dbSel.innerHTML = ''; return; }
    wrap.classList.remove('hidden');
    dbSel.innerHTML = '<option value="">a carregar…</option>';
    try {
      const r = await QD.get(`/api/connections/${c.id}/databases`);
      const def = c.database_name || r.databases.current || '';
      dbSel.innerHTML = `<option value="">${esc(def || '(padrão)')}</option>` + (r.databases.items || []).filter((x) => x !== def).map((x) => `<option>${esc(x)}</option>`).join('');
    } catch (e) { dbSel.innerHTML = '<option value="">(padrão)</option>'; }
  }
  connSel.addEventListener('change', () => { turns = []; persist(); syncDb(); });
  review.addEventListener('change', persist);
  syncDb();

  /* ---------- rendering ---------- */
  const scrollDown = () => requestAnimationFrame(() => { scroll.scrollTop = scroll.scrollHeight; });
  const mdLite = (t) => esc(t || '').replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/`([^`]+)`/g, '<code>$1</code>')
    .split(/\n{2,}/).map((p) => `<p>${p.replace(/\n/g, '<br>')}</p>`).join('');

  function userBubble(text) {
    $('[data-empty]')?.remove();
    feed.insertAdjacentHTML('beforeend', `<div class="msg-user">${esc(text)}</div>`);
  }

  function aiCard() {
    const el = QD.h(`<div class="msg-ai"><div class="ai-head"><span class="brand-mark">${icon('sparkles')}</span><span data-meta>Assistente</span></div>
      <div class="ai-body"><div class="ai-thinking"><span class="spinner"></span><span data-stage>A analisar a base de dados e a escrever o SQL…</span></div></div></div>`);
    feed.appendChild(el);
    scrollDown();
    return el;
  }

  function renderAnswer(el, question, r, c, database) {
    const body = el.querySelector('.ai-body');
    const meta = [r.model, r.duration_ms !== undefined ? QD.fmt.ms(r.duration_ms) : null,
      r.attempts?.length ? `${r.attempts.length} tentativa(s) com erro${r.failed ? '' : ', corrigida(s)'}` : null].filter(Boolean).join(' · ');
    el.querySelector('[data-meta]').textContent = `Assistente${meta ? ' · ' + meta : ''}`;

    const pending = !!r.pending;
    const editable = pending || r.failed;
    const ok = !pending && !r.failed && r.sql && r.result;
    body.innerHTML = `
      ${r.answer ? `<div class="ai-answer ${r.failed ? 'failed' : ''}">${mdLite(r.answer)}</div>` : ''}
      ${pending ? `<div class="ai-answer">Este é o SQL que proponho${r.explanation ? ` — ${esc(r.explanation)}` : ''}. Reveja/edite e carregue em <b>Executar</b>.</div>` : ''}
      ${r.failed && r.sql ? `<div class="ai-attempts">Pode corrigir o SQL abaixo e carregar em <b>Executar</b>; se funcionar, use <b>Correto — ensinar</b> para a IA aprender.</div>` : ''}
      ${r.sql ? `<details class="ai-sql" ${editable ? 'open' : ''}>
          <summary>${icon('file-code', 'sm')} SQL ${pending ? 'proposto' : r.failed ? 'com erro' : 'executado (só leitura)'}
            <span class="spacer"></span>
            <button type="button" class="btn xs run ${editable ? '' : 'hidden'}" data-a="run">${icon('play', 'sm')} Executar</button>
            ${!editable ? `<button type="button" class="btn xs" data-a="edit">${icon('edit', 'sm')} Corrigir SQL</button>` : ''}
            <button type="button" class="btn xs" data-a="editor">${icon('terminal', 'sm')} Abrir no editor</button>
            <button type="button" class="btn xs" data-a="copy">${icon('copy', 'sm')} Copiar</button>
          </summary>
          ${editable ? `<textarea spellcheck="false" data-sql>${esc(r.sql)}</textarea>` : `<pre data-pre>${esc(r.sql)}</pre>`}
        </details>` : ''}
      ${ok && canTeach ? `<div class="row" style="margin-top:8px;gap:6px"><button type="button" class="btn xs" data-a="learn" title="Guarda esta pergunta + SQL como exemplo: a IA passa a usá-lo em perguntas parecidas">${icon('check', 'sm')} Correto — ensinar à IA</button></div>` : ''}
      ${!pending && r.explanation ? `<div class="ai-attempts">${esc(r.explanation)}</div>` : ''}
      ${r.tables?.length ? `<div class="ai-attempts">Tabelas consideradas (${r.tables.length} de ${r.schema_tables}): ${r.tables.map(esc).join(', ')}</div>` : ''}
      ${r.attempts?.length ? `<div class="ai-attempts">${r.failed ? 'Erros' : 'Erros corrigidos automaticamente'}: ${r.attempts.map((a) => esc(String(a.error).slice(0, 140))).join(' · ')}</div>` : ''}
      ${r.result ? '<div class="ai-result" data-grid></div>' : ''}`;

    if (r.result) {
      new QD.ResultGrid(body.querySelector('[data-grid]'), r.result, { filename: question.slice(0, 40), connection: c });
    }
    body.querySelector('[data-a="copy"]')?.addEventListener('click', (e) => { e.preventDefault(); QD.copy(currentSql()); QD.toast('SQL copiado.', 'success', 1500); });
    body.querySelector('[data-a="editor"]')?.addEventListener('click', (e) => {
      e.preventDefault();
      const qs = new URLSearchParams({ connection: c.id, sql: `-- ${question.replace(/\n/g, ' ')}\n${currentSql()};` });
      if (database) qs.set('database', database);
      window.location.href = QD.url('/editor?' + qs.toString());
    });
    body.querySelector('[data-a="run"]')?.addEventListener('click', (e) => { e.preventDefault(); send(question, { mode: 'run', sql: currentSql(), card: el }); });
    body.querySelector('[data-a="edit"]')?.addEventListener('click', (e) => {
      e.preventDefault();
      const pre = body.querySelector('[data-pre]');
      pre.replaceWith(QD.h(`<textarea spellcheck="false" data-sql>${esc(r.sql)}</textarea>`));
      body.querySelector('details').open = true;
      body.querySelector('[data-a="run"]').classList.remove('hidden');
      e.currentTarget.remove();
      body.querySelector('[data-sql]').focus();
    });
    body.querySelector('[data-a="learn"]')?.addEventListener('click', async (e) => {
      e.preventDefault();
      const b = e.currentTarget;
      try {
        await QD.post('/api/assistant/examples', { connection_id: c.id, question, sql: r.sql });
        b.outerHTML = `<span class="badge success">${icon('check', 'sm')} Guardado — a IA vai usar este exemplo</span>`;
      } catch (err) { QD.fail(err); }
    });
    function currentSql() { return body.querySelector('[data-sql]')?.value ?? r.sql; }
    scrollDown();
  }

  /* ---------- sending ---------- */
  async function send(question, opts = {}) {
    if (busy) return;
    const c = conn();
    if (!c) return QD.toast('Crie primeiro uma conexão.', 'error');
    busy = true;
    $('[data-send]').disabled = true;
    const mode = opts.mode || (review.checked ? 'generate' : 'ask');
    if (!opts.card) userBubble(question);
    const card = opts.card || aiCard();
    if (opts.card) {
      card.querySelector('.ai-body').innerHTML = '<div class="ai-thinking"><span class="spinner"></span><span data-stage>A executar…</span></div>';
    }
    const stage = card.querySelector('[data-stage]');
    const stages = mode === 'run' ? ['A executar a query (só leitura)…', 'A preparar a resposta…']
      : ['A analisar a base de dados e a escrever o SQL…', 'A executar a query (só leitura)…', 'A preparar a resposta…'];
    let si = 0;
    const timer = setInterval(() => { if (si < stages.length - 1 && stage) stage.textContent = stages[++si]; }, 6000);
    const database = dbSel.value || '';
    try {
      const r = await QD.post('/api/assistant/ask', {
        connection_id: c.id, database, question, mode, sql: opts.sql || null, model: modelSel.value,
        history: turns.slice(-4),
      });
      if (!r.ok) throw new Error(r.error);
      renderAnswer(card, question, r, c, database);
      if (r.sql && !r.pending && !r.failed) turns.push({ question, sql: r.sql });
    } catch (e) {
      card.querySelector('.ai-body').innerHTML = `<div class="alert error">${icon('alert')}<span>${esc(e.message)}</span></div>`;
    } finally {
      clearInterval(timer);
      busy = false;
      $('[data-send]').disabled = false;
      input.focus();
    }
  }

  $('[data-form]').addEventListener('submit', (e) => {
    e.preventDefault();
    const q = input.value.trim();
    if (!q) return;
    input.value = '';
    input.style.height = '';
    send(q);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('[data-form]').requestSubmit(); }
  });
  input.addEventListener('input', () => { input.style.height = 'auto'; input.style.height = Math.min(180, input.scrollHeight) + 'px'; });
  feed.addEventListener('click', (e) => {
    const s = e.target.closest('[data-suggest]');
    if (s) send(s.textContent.trim());
  });
  $('[data-clear]').addEventListener('click', () => { turns = []; window.location.href = QD.url('/assistant'); });

  /* ---------- knowledge (notes, examples, structure) ---------- */
  let canTeach = true;
  async function knowledgeModal() {
    const c = conn();
    if (!c) return;
    const database = dbSel.value || '';
    const m = QD.modal({ title: `Conhecimento da IA — ${c.name}`, size: 'xl', body: '<div class="empty"><span class="spinner lg"></span></div>' });
    let k;
    try { k = await QD.get('/api/assistant/knowledge', { connection_id: c.id, database }); } catch (e) { m.body.innerHTML = `<div class="alert error">${esc(e.message)}</div>`; return; }
    canTeach = k.can_teach;
    const ro = k.can_teach ? '' : 'disabled';
    m.body.innerHTML = `
      <div class="row wrap mb" style="gap:10px">
        <span class="badge accent">${icon('database', 'sm')} Estrutura lida: <b data-k-tables>${k.tables ?? '?'}</b>&nbsp;tabelas/views</span>
        <button class="btn sm" data-k-refresh>${icon('refresh', 'sm')} Reler estrutura</button>
        <span class="muted small">A IA recebe sempre a estrutura (tabelas, colunas, chaves). Em bases grandes escolhe primeiro as tabelas relevantes.</span>
      </div>
      <div class="field-label">Notas sobre esta base de dados <span class="muted">(a IA lê-as em todas as perguntas)</span></div>
      <textarea class="input mono" rows="12" data-k-notes ${ro} placeholder="Explique o significado do negócio, por exemplo:
- clientes = tabela users onde user_group_id = ... (os utilizadores administradores não contam)
- movimentos / transações = tabela transfers (date = data, amount = valor, from_id/to_id -> accounts.id)
- accounts.owner_id -> users.id
- 'faturou' = soma de transfers.amount recebida
- valores em euros; datas em UTC">${esc(k.notes || '')}</textarea>
      <div class="row mt" style="gap:8px">
        ${k.can_teach ? `<button class="btn primary sm" data-k-save>${icon('save', 'sm')} Guardar notas</button>
        <button class="btn sm" data-k-describe title="A IA analisa a estrutura e escreve um rascunho — reveja antes de guardar">${icon('sparkles', 'sm')} Gerar rascunho com a IA</button>` : '<span class="muted small">Só editores/administradores podem alterar o conhecimento.</span>'}
        <span class="muted small" data-k-status></span>
      </div>
      <div class="field-label" style="margin-top:22px">Exemplos aprendidos (${k.examples.length}) <span class="muted">— pergunta + SQL correto; usados em perguntas parecidas</span></div>
      <div data-k-examples>${k.examples.length ? k.examples.map((e) => `<div class="list-item" style="padding:8px 4px;align-items:flex-start" data-ex="${e.id}">
          <div class="grow"><b>${esc(e.question)}</b><span class="sql-snippet" style="max-width:100%">${esc(e.sql_text.replace(/\s+/g, ' '))}</span>
          <div class="muted" style="font-size:11px">${esc(e.creator_name || '')} · ${QD.fmt.ago(e.created_at)}</div></div>
          ${k.can_teach ? `<button class="btn-icon sm" data-ex-del="${e.id}" title="Remover">${icon('trash', 'sm')}</button>` : ''}</div>`).join('')
        : '<div class="muted small">Ainda sem exemplos. Depois de uma resposta certa, carregue em <b>Correto — ensinar à IA</b>.</div>'}</div>`;
    const status = m.body.querySelector('[data-k-status]');
    m.body.querySelector('[data-k-refresh]').addEventListener('click', async (e) => {
      e.currentTarget.disabled = true;
      status.innerHTML = '<span class="spinner"></span> a ler a estrutura…';
      try {
        const r = await QD.post('/api/assistant/refresh-schema', { connection_id: c.id, database });
        if (!r.ok) throw new Error(r.error);
        m.body.querySelector('[data-k-tables]').textContent = r.tables;
        status.textContent = 'Estrutura atualizada.';
      } catch (err) { status.textContent = ''; QD.fail(err); }
      e.currentTarget.disabled = false;
    });
    m.body.querySelector('[data-k-save]')?.addEventListener('click', async () => {
      try {
        await QD.post('/api/assistant/knowledge', { connection_id: c.id, notes: m.body.querySelector('[data-k-notes]').value });
        status.textContent = 'Notas guardadas.';
        QD.toast('Notas guardadas — a IA vai usá-las a partir de agora.', 'success');
      } catch (err) { QD.fail(err); }
    });
    m.body.querySelector('[data-k-describe]')?.addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      btn.disabled = true;
      status.innerHTML = '<span class="spinner"></span> a IA está a analisar a estrutura (pode demorar)…';
      try {
        const r = await QD.post('/api/assistant/describe', { connection_id: c.id, database, model: modelSel.value });
        if (!r.ok) throw new Error(r.error);
        const ta = m.body.querySelector('[data-k-notes]');
        ta.value = (ta.value.trim() ? ta.value.trim() + '\n\n' : '') + r.notes.trim();
        status.textContent = 'Rascunho gerado — reveja, corrija e carregue em Guardar notas.';
      } catch (err) { status.textContent = ''; QD.fail(err); }
      btn.disabled = false;
    });
    m.body.querySelector('[data-k-examples]').addEventListener('click', async (e) => {
      const b = e.target.closest('[data-ex-del]');
      if (!b) return;
      try {
        await QD.delete(`/api/assistant/examples/${b.dataset.exDel}?connection_id=${c.id}`, {});
        b.closest('[data-ex]').remove();
      } catch (err) { QD.fail(err); }
    });
  }
  $('[data-knowledge]').addEventListener('click', knowledgeModal);
  QD.get('/api/assistant/knowledge', { connection_id: +connSel.value || 0 }).then((k) => { canTeach = k.can_teach; }).catch(() => {});

  if (D.boot.question) send(D.boot.question);
  input.focus();
})();
