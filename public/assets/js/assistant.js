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
    body.innerHTML = `
      ${r.answer ? `<div class="ai-answer ${r.failed ? 'failed' : ''}">${mdLite(r.answer)}</div>` : ''}
      ${pending ? `<div class="ai-answer">Este é o SQL que proponho${r.explanation ? ` — ${esc(r.explanation)}` : ''}. Reveja/edite e carregue em <b>Executar</b>.</div>` : ''}
      ${r.sql ? `<details class="ai-sql" ${pending || r.failed ? 'open' : ''}>
          <summary>${icon('file-code', 'sm')} SQL ${pending ? 'proposto' : 'executado (só leitura)'}
            <span class="spacer"></span>
            ${pending ? `<button type="button" class="btn xs run" data-a="run">${icon('play', 'sm')} Executar</button>` : ''}
            <button type="button" class="btn xs" data-a="editor">${icon('terminal', 'sm')} Abrir no editor</button>
            <button type="button" class="btn xs" data-a="copy">${icon('copy', 'sm')} Copiar</button>
          </summary>
          ${pending ? `<textarea spellcheck="false" data-sql>${esc(r.sql)}</textarea>` : `<pre>${esc(r.sql)}</pre>`}
        </details>` : ''}
      ${!pending && r.explanation ? `<div class="ai-attempts">${esc(r.explanation)}</div>` : ''}
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

  if (D.boot.question) send(D.boot.question);
  input.focus();
})();
