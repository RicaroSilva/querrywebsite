/* SQL Editor (IDE): tabs, CodeMirror, autocomplete, execution with cancel, multi result sets,
 * error highlighting, save/load queries, side panels (objects / saved queries / history).
 */
(function () {
  'use strict';
  const { esc, icon } = QD;
  const DATA = JSON.parse(document.getElementById('editor-data').textContent);
  const $ = (s) => document.querySelector(s);
  const ide = $('#ide');
  const STORE_KEY = `qd-editor-tabs:${QD.boot.user.id}`;
  const MODES = { pgsql: 'text/x-pgsql', mysql: 'text/x-mariadb', sqlsrv: 'text/x-mssql', sqlite: 'text/x-sqlite' };

  const S = {
    connections: DATA.connections,
    folders: DATA.folders,
    tabs: [],
    active: null,
    running: null,
    completion: {},
    databases: {},
    tree: null,
    treeConn: null,
  };
  const conn = (id) => S.connections.find((c) => c.id === +id) || null;
  const tab = () => S.tabs.find((t) => t.id === S.active);
  const uid = () => Math.random().toString(36).slice(2, 10) + Date.now().toString(36);

  /* ------------------------------------------------------------ CodeMirror */
  document.documentElement.style.setProperty('--editor-font', (QD.boot.prefs.editor_font || 13) + 'px');
  const cm = CodeMirror($('[data-editor-wrap]'), {
    theme: 'qd',
    mode: 'text/x-pgsql',
    lineNumbers: true,
    matchBrackets: true,
    autoCloseBrackets: true,
    styleActiveLine: true,
    indentUnit: 4,
    tabSize: 4,
    indentWithTabs: false,
    smartIndent: true,
    lineWrapping: false,
    placeholder: '-- Escreva SQL aqui…   Ctrl+Enter para executar',
    extraKeys: {
      'Ctrl-Enter': () => run('all'), 'Cmd-Enter': () => run('all'), F5: () => run('all'),
      'Shift-Ctrl-Enter': () => run('selection'), 'Shift-Cmd-Enter': () => run('selection'),
      'Ctrl-S': () => save(false), 'Cmd-S': () => save(false),
      'Shift-Ctrl-F': () => format(), 'Shift-Cmd-F': () => format(),
      'Ctrl-I': () => QD.boot.can.ai && aiGenerate(), 'Cmd-I': () => QD.boot.can.ai && aiGenerate(),
      'Ctrl-/': 'toggleComment', 'Cmd-/': 'toggleComment',
      'Ctrl-Space': (c) => c.showHint({ completeSingle: false }),
      Tab: (c) => (c.somethingSelected() ? c.indentSelection('add') : c.replaceSelection('    ', 'end')),
      'Shift-Tab': (c) => c.indentSelection('subtract'),
      Esc: () => { if (S.running) cancel(); },
    },
    hintOptions: { completeSingle: false, tables: {} },
  });
  // Don't offer (and let Enter accept) a suggestion identical to what was already typed.
  const sqlHint = CodeMirror.hint.sql;
  CodeMirror.registerHelper('hint', 'sql', (c, opts) => {
    const r = sqlHint(c, opts);
    if (!r) return r;
    const typed = c.getRange(r.from, r.to).toLowerCase();
    r.list = r.list.filter((h) => String(typeof h === 'string' ? h : h.text).toLowerCase() !== typed);
    return r.list.length ? r : null;
  });
  cm.on('inputRead', (c, change) => {
    if (QD.boot.prefs.autocomplete === '0' || c.state.completionActive) return;
    const ch = change.text[0];
    if (/^[\w.]$/.test(ch)) {
      const cur = c.getCursor();
      const token = c.getTokenAt(cur);
      if (ch === '.' || (token.string.length >= 2 && token.type !== 'string' && token.type !== 'comment')) c.showHint({ completeSingle: false });
    }
  });
  cm.on('change', () => {
    const t = tab();
    if (!t) return;
    if (!t.dirty) { t.dirty = true; renderTabs(); }
    clearErrorMark();
    persist();
  });
  cm.on('cursorActivity', () => {
    const c = cm.getCursor();
    const sel = cm.getSelection();
    $('[data-cursor]').textContent = `Ln ${c.line + 1}, Col ${c.ch + 1}${sel ? ` · ${sel.length} selecionados` : ''}`;
  });

  /* ------------------------------------------------------------ tabs */
  function newTab(o = {}) {
    const t = {
      id: uid(),
      title: o.title || `Query ${S.tabs.length + 1}`,
      connectionId: o.connectionId ?? (tab()?.connectionId || S.connections[0]?.id || null),
      database: o.database || '',
      savedQueryId: o.savedQueryId || null,
      meta: o.meta || null,
      dirty: !!o.dirty,
      result: null,
      resultTab: 0,
    };
    t.doc = CodeMirror.Doc(o.sql || '', MODES[conn(t.connectionId)?.driver] || 'text/x-sql');
    S.tabs.push(t);
    activate(t.id);
    persist();
    return t;
  }

  function activate(id) {
    S.active = id;
    const t = tab();
    cm.swapDoc(t.doc);
    renderTabs();
    syncToolbar();
    renderResults();
    cm.focus();
  }

  async function closeTab(id) {
    const t = S.tabs.find((x) => x.id === id);
    if (t.dirty && t.doc.getValue().trim() && !(await QD.confirm(`Fechar "${t.title}" sem guardar as alterações?`, { okLabel: 'Fechar' }))) return;
    const i = S.tabs.indexOf(t);
    S.tabs.splice(i, 1);
    if (!S.tabs.length) newTab();
    else if (S.active === id) activate(S.tabs[Math.max(0, i - 1)].id);
    else renderTabs();
    persist();
  }

  function renderTabs() {
    const el = $('[data-qtabs]');
    el.innerHTML = S.tabs.map((t) => {
      const c = conn(t.connectionId);
      const color = c?.color || '';
      return `<div class="qtab ${t.id === S.active ? 'active' : ''}" data-tab="${t.id}" title="${esc(t.title)}${c ? ' — ' + esc(c.name) : ''}" style="${color ? `--tab-color:${esc(color)}` : ''}">
        ${icon(t.savedQueryId ? 'file-code' : 'terminal', 'sm')}<span class="name">${esc(t.title)}</span>
        ${t.dirty ? '<span class="dirty" title="Alterações por guardar"></span>' : ''}
        <span class="close" data-close-tab="${t.id}" title="Fechar (Alt+W)">${icon('x', 'sm')}</span></div>`;
    }).join('') + `<button class="btn-icon sm qtab-add" data-new-tab title="Nova tab (Alt+N)">${icon('plus', 'sm')}</button>`;
  }
  $('[data-qtabs]').addEventListener('click', (e) => {
    const close = e.target.closest('[data-close-tab]');
    if (close) return closeTab(close.dataset.closeTab);
    if (e.target.closest('[data-new-tab]')) return newTab();
    const t = e.target.closest('[data-tab]');
    if (t) activate(t.dataset.tab);
  });
  $('[data-qtabs]').addEventListener('dblclick', async (e) => {
    const el = e.target.closest('[data-tab]');
    if (!el) return;
    const t = S.tabs.find((x) => x.id === el.dataset.tab);
    const name = await QD.prompt('Renomear tab', t.title);
    if (name) { t.title = name; renderTabs(); persist(); }
  });

  const persist = QD.debounce(() => {
    try {
      localStorage.setItem(STORE_KEY, JSON.stringify({
        active: S.active,
        tabs: S.tabs.map((t) => ({ id: t.id, title: t.title, sql: t.doc.getValue(), connectionId: t.connectionId, database: t.database,
          savedQueryId: t.savedQueryId, meta: t.meta, dirty: t.dirty })),
      }));
    } catch (e) { /* storage full or blocked */ }
  }, 400);

  function restore() {
    let saved = null;
    try { saved = JSON.parse(localStorage.getItem(STORE_KEY) || 'null'); } catch (e) {}
    if (saved && saved.tabs?.length) {
      saved.tabs.forEach((t) => {
        const doc = CodeMirror.Doc(t.sql || '', MODES[conn(t.connectionId)?.driver] || 'text/x-sql');
        S.tabs.push({ ...t, connectionId: conn(t.connectionId) ? t.connectionId : S.connections[0]?.id || null, doc, result: null, resultTab: 0 });
      });
      activate(S.tabs.find((t) => t.id === saved.active)?.id || S.tabs[0].id);
    } else {
      newTab({ sql: '' });
    }
  }

  /* ------------------------------------------------------------ toolbar: connection + database */
  const connSel = $('[data-conn]');
  const dbSel = $('[data-db]');
  function renderConnOptions() {
    connSel.innerHTML = S.connections.length
      ? S.connections.map((c) => `<option value="${c.id}">${esc(c.name)} · ${esc(c.driver_label)}</option>`).join('')
      : '<option value="">Sem conexões — crie uma</option>';
    if (QD.boot.can.conns) connSel.insertAdjacentHTML('beforeend', '<option value="__new">＋ Nova conexão…</option>');
  }

  async function syncToolbar() {
    const t = tab();
    const c = conn(t.connectionId);
    connSel.value = c ? c.id : '';
    $('[data-conn-wrap]').className = `conn-select env-${c?.environment || ''}`;
    $('[data-toolbar]').classList.toggle('ro', !!(c?.read_only || !DATA.canWrite));
    cm.setOption('mode', MODES[c?.driver] || 'text/x-sql');
    // databases dropdown
    const wrap = $('[data-db-wrap]');
    if (c && c.capabilities?.databases) {
      wrap.classList.remove('hidden');
      if (!S.databases[c.id]) {
        dbSel.innerHTML = '<option value="">a carregar…</option>';
        S.databases[c.id] = QD.get(`/api/connections/${c.id}/databases`).then((r) => r.databases).catch(() => ({ items: [] }));
      }
      const d = await S.databases[c.id];
      if (tab() !== t) return;
      const def = c.database_name || d.current || '';
      dbSel.innerHTML = `<option value="">${esc(def || '(padrão)')}</option>` + (d.items || []).filter((x) => x !== def)
        .map((x) => `<option ${x === t.database ? 'selected' : ''}>${esc(x)}</option>`).join('');
      if (t.database && !(d.items || []).includes(t.database)) dbSel.insertAdjacentHTML('beforeend', `<option selected>${esc(t.database)}</option>`);
    } else {
      wrap.classList.add('hidden');
    }
    loadCompletion();
    if (S.treeConn !== (c?.id || null)) renderTree();
  }
  connSel.addEventListener('change', () => {
    if (connSel.value === '__new') {
      connSel.value = tab().connectionId || '';
      return QD.connectionForm(null, (c) => { S.connections.push(c); renderConnOptions(); tab().connectionId = c.id; syncToolbar(); renderTabs(); persist(); });
    }
    const t = tab();
    t.connectionId = +connSel.value || null;
    t.database = '';
    syncToolbar();
    renderTabs();
    persist();
  });
  dbSel.addEventListener('change', () => { tab().database = dbSel.value; loadCompletion(); persist(); });

  async function loadCompletion() {
    const t = tab();
    const c = conn(t?.connectionId);
    if (!c) return;
    const key = `${c.id}|${t.database || ''}`;
    if (!S.completion[key]) {
      S.completion[key] = QD.get(`/api/connections/${c.id}/completion`, { database: t.database || '' }).then((r) => r.tables || {}).catch(() => ({}));
    }
    const tables = await S.completion[key];
    if (tab() === t) cm.setOption('hintOptions', { completeSingle: false, tables });
  }

  /* ------------------------------------------------------------ side panel */
  function renderTree() {
    const t = tab();
    const c = conn(t?.connectionId);
    S.treeConn = c?.id || null;
    const panel = document.querySelector('[data-panel="objects"]');
    if (!c) { panel.innerHTML = '<div class="empty small">Selecione uma conexão</div>'; return; }
    panel.innerHTML = `<div class="row" style="padding:4px 6px 8px">${QD.driverLogo(c.driver, true)}<b class="truncate">${esc(c.name)}</b>
      <div class="spacer" style="flex:1"></div><button class="btn-icon sm" data-tree-refresh title="Atualizar">${icon('refresh', 'sm')}</button></div><div data-tree></div>`;
    S.tree = new QD.Tree(panel.querySelector('[data-tree]'), {
      connection: c,
      onAction: (act, node) => treeAction(act, node, c),
    });
    panel.querySelector('[data-tree-refresh]').addEventListener('click', () => { S.completion = {}; renderTree(); loadCompletion(); });
  }

  function previewSql(node, c) {
    const name = QD.qualifiedName(node, c.driver);
    return c.driver === 'sqlsrv' ? `SELECT TOP (100) *\nFROM ${name};` : `SELECT *\nFROM ${name}\nLIMIT 100;`;
  }

  function treeAction(act, node, c) {
    if (act === 'preview') {
      newTab({ title: node.name, sql: previewSql(node, c), connectionId: c.id, database: node.database || '' });
      run('all');
    } else if (act === 'info') {
      QD.objectInfo(c, node, (sql, db) => newTab({ title: node.name, sql, connectionId: c.id, database: db || '' }));
    } else if (act === 'dblclick' && ['table', 'view', 'column', 'function', 'procedure'].includes(node.kind)) {
      cm.replaceSelection(node.kind === 'column' ? node.name : QD.qualifiedName(node, c.driver));
      cm.focus();
    }
  }

  document.querySelector('[data-side-tabs]').addEventListener('click', (e) => {
    const b = e.target.closest('[data-side]');
    if (!b) return;
    document.querySelectorAll('[data-side]').forEach((x) => x.classList.toggle('active', x === b));
    document.querySelectorAll('[data-panel]').forEach((p) => p.classList.toggle('hidden', p.dataset.panel !== b.dataset.side));
    if (b.dataset.side === 'queries') loadQueries();
    if (b.dataset.side === 'history') loadHistory();
  });

  async function loadQueries() {
    const list = document.querySelector('[data-q-list]');
    list.innerHTML = '<div class="loading" style="padding:10px"><span class="spinner"></span></div>';
    try {
      const r = await QD.get('/api/queries', { search: document.querySelector('[data-q-search]').value });
      list.innerHTML = r.queries.length ? r.queries.map((q) => `<a class="list-item" style="padding:7px 8px" data-open-q="${q.id}" title="${esc(q.description || q.name)}">
          ${icon(q.is_favorite ? 'star' : 'file-code', 'sm')}<div class="grow"><div class="title truncate small">${esc(q.name)}</div>
          <div class="muted truncate" style="font-size:11px">${esc(q.folder_name || 'Sem pasta')} · ${esc(q.connection_name || '—')}</div></div></a>`).join('')
        : '<div class="empty small">Sem queries</div>';
    } catch (e) { list.innerHTML = `<div class="tree-error">${esc(e.message)}</div>`; }
  }
  document.querySelector('[data-q-search]').addEventListener('input', QD.debounce(loadQueries, 300));
  document.querySelector('[data-q-list]').addEventListener('click', (e) => { const a = e.target.closest('[data-open-q]'); if (a) openSaved(+a.dataset.openQ); });

  async function loadHistory() {
    const list = document.querySelector('[data-h-list]');
    list.innerHTML = '<div class="loading" style="padding:10px"><span class="spinner"></span></div>';
    try {
      const r = await QD.get('/api/history', { per_page: 40 });
      S.history = r.rows;
      list.innerHTML = r.rows.length ? r.rows.map((h) => `<a class="list-item" style="padding:7px 8px;align-items:flex-start" data-open-h="${h.id}">
          <span class="dot ${h.status === 'success' ? 'success' : 'danger'}" style="margin-top:6px"></span>
          <div class="grow"><div class="sql-snippet" style="max-width:100%">${esc(h.sql_text.replace(/\s+/g, ' ').slice(0, 120))}</div>
          <div class="muted" style="font-size:11px">${QD.fmt.ago(h.created_at)} · ${esc(h.connection_name || '')} · ${h.duration_ms} ms</div></div></a>`).join('')
        : '<div class="empty small">Sem histórico</div>';
    } catch (e) { list.innerHTML = `<div class="tree-error">${esc(e.message)}</div>`; }
  }
  document.querySelector('[data-h-list]').addEventListener('click', (e) => {
    const a = e.target.closest('[data-open-h]');
    if (!a) return;
    const h = S.history.find((x) => x.id === +a.dataset.openH);
    newTab({ title: 'Histórico #' + h.id, sql: h.sql_text, connectionId: conn(h.connection_id) ? h.connection_id : undefined });
  });

  async function openSaved(id) {
    const existing = S.tabs.find((t) => t.savedQueryId === id);
    if (existing) return activate(existing.id);
    try {
      const { query: q } = await QD.get(`/api/queries/${id}`);
      const t = newTab({
        title: q.name, sql: q.sql_text, connectionId: conn(q.connection_id) ? q.connection_id : undefined, savedQueryId: q.id,
        meta: { name: q.name, description: q.description, folder_id: q.folder_id, tags: q.tags.join(', ') },
      });
      t.dirty = false;
      renderTabs();
      return t;
    } catch (e) { QD.fail(e); }
  }

  /* ------------------------------------------------------------ statements helpers */
  /** Client-side statement ranges (quote/comment aware) to find the statement under the cursor. */
  function statementRanges(text) {
    const out = [];
    let start = 0, i = 0, q = null;
    while (i < text.length) {
      const ch = text[i], nx = text[i + 1];
      if (q) {
        if (q === '--' && ch === '\n') q = null;
        else if (q === '/*' && ch === '*' && nx === '/') { q = null; i++; }
        else if (q.length === 1 && ch === q) { if (nx === q) i++; else q = null; }
        else if (q.startsWith('$') && text.startsWith(q, i)) { i += q.length - 1; q = null; }
      } else if (ch === '-' && nx === '-') { q = '--'; i++; }
      else if (ch === '/' && nx === '*') { q = '/*'; i++; }
      else if (ch === "'" || ch === '"' || ch === '`') q = ch;
      else if (ch === '$') { const m = /^\$[A-Za-z_]*\$/.exec(text.slice(i)); if (m) { q = m[0]; i += m[0].length - 1; } }
      else if (ch === ';') { out.push([start, i + 1]); start = i + 1; }
      i++;
    }
    out.push([start, text.length]);
    return out.filter(([a, b]) => text.slice(a, b).replace(/;$/, '').trim());
  }

  function currentStatement() {
    const text = cm.getValue();
    const pos = cm.indexFromPos(cm.getCursor());
    const ranges = statementRanges(text);
    let r = ranges.find(([a, b]) => pos >= a && pos <= b) || ranges.filter(([a]) => a <= pos).pop() || ranges[0];
    if (!r) return null;
    // trim leading whitespace for accurate line offset
    let [a, b] = r;
    while (a < b && /\s/.test(text[a])) a++;
    return { sql: text.slice(a, b), from: cm.posFromIndex(a), to: cm.posFromIndex(b) };
  }

  function dangerous(sql, c) {
    const issues = [];
    const stmts = statementRanges(sql).map(([a, b]) => sql.slice(a, b).replace(/--[^\n]*|\/\*[\s\S]*?\*\//g, ' ').trim());
    stmts.forEach((s) => {
      const kw = (s.match(/^\(?\s*(\w+)/) || [])[1]?.toUpperCase();
      if ((kw === 'DELETE' || kw === 'UPDATE') && !/\bWHERE\b/i.test(s)) issues.push(`${kw} sem WHERE — afeta todas as linhas`);
      if (['DROP', 'TRUNCATE'].includes(kw)) issues.push(`${kw} — operação destrutiva`);
    });
    const writes = stmts.some((s) => /^\(?\s*(INSERT|UPDATE|DELETE|MERGE|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|EXEC|CALL)\b/i.test(s));
    if (c.environment === 'production' && writes && !issues.length) issues.push('Operação de escrita numa base de dados de PRODUÇÃO');
    return issues;
  }

  /* ------------------------------------------------------------ execution */
  let timer = null;
  async function run(mode, sqlOverride) {
    const t = tab();
    const c = conn(t.connectionId);
    if (!c) return QD.toast('Selecione uma conexão primeiro.', 'error');
    if (S.running) return QD.toast('Já existe uma execução em curso.', 'info');

    let sql, from;
    if (sqlOverride) { sql = sqlOverride; from = { line: 0 }; }
    else if (mode === 'selection' && cm.somethingSelected()) { sql = cm.getSelection(); from = cm.getCursor('from'); }
    else if (mode === 'selection') { const st = currentStatement(); if (!st) return; sql = st.sql; from = st.from; cm.setSelection(st.from, st.to); }
    else { sql = cm.getValue(); from = { line: 0 }; }
    if (!sql.trim()) return QD.toast('Nada para executar.', 'info');

    if (QD.boot.prefs.confirm_write !== '0') {
      const issues = dangerous(sql, c);
      if (issues.length && !(await QD.confirm(`${issues.join(' · ')}.\n\nConexão: ${c.name}. Continuar?`, { title: 'Confirmar execução', okLabel: 'Executar', danger: true }))) return;
    }

    // {{name}} placeholders → ask for values (sent as bound parameters)
    const names = [...new Set([...sql.matchAll(/\{\{\s*([A-Za-z_]\w*)\s*\}\}/g)].map((m) => m[1]))];
    let params;
    if (names.length) {
      params = await askParams(t, names);
      if (!params) return;
    }

    clearErrorMark();
    const execId = uid();
    S.running = { id: execId, tabId: t.id };
    setRunning(true);
    try {
      const r = await QD.post('/api/execute', {
        connection_id: c.id, database: t.database || '', sql, execution_id: execId,
        line_offset: from.line, saved_query_id: t.savedQueryId || null, params,
      });
      t.result = r;
      t.resultTab = Math.max(0, r.results.findIndex((x) => x.type === 'error'));
      if (r.results.every((x) => x.type === 'command')) t.resultTab = r.results.length; // messages tab
      else if (!r.results.some((x) => x.type === 'error')) t.resultTab = r.results.findIndex((x) => x.type === 'rows');
      const err = r.results.find((x) => x.type === 'error');
      if (err && tab() === t) markError(err.error);
      if (!err) loadHistoryIfOpen();
    } catch (e) {
      t.result = { status: 'error', duration_ms: 0, connection: { name: c.name }, results: [{ type: 'error', sql, error: { message: e.message } }] };
      t.resultTab = 0;
    } finally {
      S.running = null;
      setRunning(false);
      if (tab() === t) renderResults();
    }
  }
  function askParams(t, names) {
    t.params = t.params || {};
    return new Promise((resolve) => {
      let done = false;
      const body = QD.h(`<div><p class="muted small" style="margin-top:0">A query contém parâmetros. Os valores são enviados como parâmetros
        (prepared statements), nunca concatenados no SQL. Datas no formato AAAA-MM-DD.</p>
        ${names.map((n) => `<label class="field"><span class="mono">{{${esc(n)}}}</span><input class="input" name="${esc(n)}" value="${esc(t.params[n] ?? '')}"></label>`).join('')}</div>`);
      const m = QD.modal({
        title: 'Parâmetros da query', body,
        buttons: [{ label: 'Cancelar', cls: 'ghost' }, {
          label: 'Executar', cls: 'run', icon: 'play',
          action: () => { done = true; t.params = QD.formData(body); resolve(t.params); },
        }],
        onClose: () => { if (!done) resolve(null); },
      });
      body.addEventListener('keydown', (e) => { if (e.key === 'Enter') m.el.querySelector('.btn.run').click(); });
    });
  }

  function loadHistoryIfOpen() {
    if (!document.querySelector('[data-panel="history"]').classList.contains('hidden')) loadHistory();
  }

  function setRunning(on) {
    document.querySelector('[data-cmd="cancel"]').classList.toggle('hidden', !on);
    document.querySelector('[data-cmd="run"]').disabled = on;
    document.querySelector('[data-cmd="run-selection"]').disabled = on;
    const box = document.querySelector('[data-results]');
    box.querySelector('.results-loading')?.remove();
    clearInterval(timer);
    if (on) {
      const started = Date.now();
      box.insertAdjacentHTML('beforeend', `<div class="results-loading"><div class="box"><span class="spinner lg"></span>
        <div>A executar… <span class="elapsed" data-elapsed>0.0 s</span></div>
        <button class="btn sm danger" data-cancel-inline>${icon('stop', 'sm')} Cancelar</button></div></div>`);
      box.querySelector('[data-cancel-inline]').addEventListener('click', cancel);
      timer = setInterval(() => { const el = box.querySelector('[data-elapsed]'); if (el) el.textContent = ((Date.now() - started) / 1000).toFixed(1) + ' s'; }, 100);
    }
  }

  async function cancel() {
    if (!S.running) return;
    try {
      const r = await QD.post(`/api/execute/${S.running.id}/cancel`, {});
      QD.toast(r.ok ? r.message : r.error, r.ok ? 'info' : 'error');
    } catch (e) { QD.fail(e); }
  }

  let errorMark = null;
  function markError(err) {
    if (!err || !err.abs_line) return;
    const line = err.abs_line - 1;
    errorMark = cm.addLineClass(line, 'background', 'cm-error-line');
    cm.setCursor({ line, ch: Math.max(0, (err.column || 1) - 1) });
    cm.scrollIntoView({ line, ch: 0 }, 80);
  }
  function clearErrorMark() {
    if (errorMark) { cm.removeLineClass(errorMark, 'background', 'cm-error-line'); errorMark = null; }
  }

  /* ------------------------------------------------------------ results */
  function renderResults() {
    const t = tab();
    const box = document.querySelector('[data-results]');
    const loading = box.querySelector('.results-loading');
    if (!t.result) {
      if (!box.querySelector('.results-empty')) {
        box.innerHTML = `<div class="results-empty"><div>${icon('terminal', 'lg')}<p style="margin:10px 0 4px;color:var(--text-2)">Escreva uma query e carregue em <kbd>Ctrl</kbd>+<kbd>Enter</kbd></p></div></div>`;
      }
      if (loading && S.running?.tabId === t.id) box.appendChild(loading);
      return;
    }
    const r = t.result;
    const rowsCount = r.results.reduce((n, x) => n + (x.type === 'rows' ? x.row_count : 0), 0);
    let n = 0;
    const tabs = r.results.map((x, i) => {
      if (x.type === 'rows') { n++; return `<button class="rtab ${t.resultTab === i ? 'active' : ''}" data-rt="${i}">${icon('table', 'sm')} Resultado ${n} <span class="badge">${QD.fmt.num(x.row_count)}${x.truncated ? '+' : ''}</span></button>`; }
      if (x.type === 'error') return `<button class="rtab err ${t.resultTab === i ? 'active' : ''}" data-rt="${i}">${icon('alert', 'sm')} Erro</button>`;
      return '';
    }).join('');
    box.innerHTML = `<div class="results-head">${tabs}
        <button class="rtab ${t.resultTab === r.results.length ? 'active' : ''}" data-rt="${r.results.length}">${icon('list', 'sm')} Mensagens</button>
        <div class="meta">
          <span class="row" style="gap:5px"><span class="dot ${r.status === 'success' ? 'success' : 'danger'}"></span>${esc(r.status === 'success' ? 'Sucesso' : r.status === 'cancelled' ? 'Cancelada' : 'Erro')}</span>
          <span>${icon('clock', 'sm')} <b>${QD.fmt.ms(r.duration_ms)}</b></span>
          <span>${icon('rows', 'sm')} <b>${QD.fmt.num(rowsCount)}</b> linhas</span>
          <span>${icon('database', 'sm')} ${esc(r.connection?.name || '')}${r.connection?.database ? ' / ' + esc(r.connection.database) : ''}</span>
          ${r.read_only ? '<span class="badge warning">só leitura</span>' : ''}
        </div></div>
      <div class="results-body"></div>`;
    box.querySelector('.results-head').addEventListener('click', (e) => {
      const b = e.target.closest('[data-rt]');
      if (b) { t.resultTab = +b.dataset.rt; renderResults(); }
    });
    const body = box.querySelector('.results-body');
    const cur = r.results[t.resultTab];
    if (!cur) renderMessages(body, r);
    else if (cur.type === 'rows') {
      const c = conn(t.connectionId);
      new QD.ResultGrid(body, cur, { filename: t.title, table: guessTable(cur.sql), connection: c });
    } else if (cur.type === 'error') renderError(body, cur);
    else renderMessages(body, r);
    if (loading && S.running?.tabId === t.id) box.appendChild(loading);
  }

  function guessTable(sql) {
    const m = /\bFROM\s+([\w."`\[\]]+)/i.exec(sql || '');
    return m ? m[1].replace(/["`\[\]]/g, '') : 'export_table';
  }

  function renderError(body, cur) {
    const e = cur.error || {};
    body.innerHTML = `<div class="sql-error">
      <h4>${icon('alert')} Erro SQL</h4>
      <div class="msg">${esc(e.message)}</div>
      <dl>
        ${e.code ? `<dt>Código</dt><dd class="mono">${esc(e.code)}${e.sqlstate && e.sqlstate !== e.code ? ` · SQLSTATE ${esc(e.sqlstate)}` : ''}</dd>` : ''}
        ${e.abs_line ? `<dt>Linha</dt><dd>${e.abs_line}${e.column ? `, coluna ${e.column}` : ''} <a href="#" data-goto>ir para a linha</a></dd>` : ''}
      </dl>
      <pre>${esc(cur.sql)}</pre>
    </div>`;
    body.querySelector('[data-goto]')?.addEventListener('click', (ev) => { ev.preventDefault(); clearErrorMark(); markError(e); cm.focus(); });
  }

  function renderMessages(body, r) {
    body.innerHTML = `<div class="messages">${r.results.map((x, i) => {
      const snippet = esc((x.sql || '').replace(/\s+/g, ' ').slice(0, 140));
      if (x.type === 'rows') return `<div class="msg-line"><span class="t">#${i + 1}</span><span class="ok">✔</span><span>${QD.fmt.num(x.row_count)} linha(s) devolvida(s)${x.truncated ? ' (limitado)' : ''} · ${QD.fmt.ms(x.duration_ms)}</span><span class="muted truncate">${snippet}</span></div>`;
      if (x.type === 'command') return `<div class="msg-line"><span class="t">#${i + 1}</span><span class="ok">✔</span><span>${esc(x.keyword || 'OK')}${x.affected >= 0 ? ` · ${QD.fmt.num(x.affected)} linha(s) afetada(s)` : ''} · ${QD.fmt.ms(x.duration_ms)}</span><span class="muted truncate">${snippet}</span></div>`;
      return `<div class="msg-line"><span class="t">#${i + 1}</span><span class="bad">✖</span><span class="bad">${esc(x.error?.message || 'Erro')}</span></div>`;
    }).join('')}<div class="msg-line"><span class="t">Σ</span><span>Tempo total ${QD.fmt.ms(r.duration_ms)} · ${esc(new Date().toLocaleTimeString('pt-PT'))}</span></div></div>`;
  }

  /* ------------------------------------------------------------ format / save / misc */
  function format() {
    const t = tab();
    const c = conn(t.connectionId);
    const lang = { pgsql: 'postgresql', mysql: 'mysql', sqlsrv: 'transactsql', sqlite: 'sqlite' }[c?.driver] || 'sql';
    const doFmt = (s) => window.sqlFormatter.format(s, { language: lang, tabWidth: 4, keywordCase: 'upper', linesBetweenQueries: 1 });
    try {
      if (cm.somethingSelected()) cm.replaceSelection(doFmt(cm.getSelection()), 'around');
      else { const cur = cm.getCursor(); cm.setValue(doFmt(cm.getValue())); cm.setCursor(cur); }
    } catch (e) { QD.toast('Não foi possível formatar: ' + e.message, 'error'); }
  }

  async function save(asNew) {
    if (!DATA.canSave) return QD.toast('Sem permissão para guardar queries.', 'error');
    const t = tab();
    if (!t.doc.getValue().trim()) return QD.toast('A query está vazia.', 'info');
    if (t.savedQueryId && !asNew && t.meta) {
      try {
        await QD.put(`/api/queries/${t.savedQueryId}`, { ...t.meta, sql_text: t.doc.getValue(), connection_id: t.connectionId });
        t.dirty = false; renderTabs(); persist();
        return QD.toast('Query guardada.', 'success', 1800);
      } catch (e) { return QD.fail(e); }
    }
    const m = t.meta || {};
    const body = QD.h(`<div>
      <label class="field"><span>Nome *</span><input class="input" name="name" value="${esc(asNew && m.name ? m.name + ' (cópia)' : m.name || (t.title.startsWith('Query ') ? '' : t.title))}"></label>
      <label class="field"><span>Descrição</span><textarea class="input" name="description" rows="2">${esc(m.description || '')}</textarea></label>
      <div class="form-grid">
        <label class="field"><span>Pasta</span><select class="select" name="folder_id"><option value="">Sem pasta</option>${S.folders.map((f) => `<option value="${f.id}" ${+m.folder_id === +f.id ? 'selected' : ''}>${esc(f.name)}</option>`).join('')}</select></label>
        <label class="field"><span>Tags</span><input class="input" name="tags" value="${esc(m.tags || '')}" placeholder="vendas, mensal"></label>
      </div>
      <div class="muted small">Conexão associada: <b>${esc(conn(t.connectionId)?.name || '—')}</b></div></div>`);
    QD.modal({
      title: asNew || !t.savedQueryId ? 'Guardar query' : 'Guardar alterações', body,
      buttons: [{ label: 'Cancelar', cls: 'ghost' }, {
        label: 'Guardar', cls: 'primary', icon: 'save',
        action: async () => {
          const d = QD.formData(body);
          const payload = { ...d, folder_id: d.folder_id || null, sql_text: t.doc.getValue(), connection_id: t.connectionId };
          const r = t.savedQueryId && !asNew ? await QD.put(`/api/queries/${t.savedQueryId}`, payload) : await QD.post('/api/queries', payload);
          t.savedQueryId = r.query.id;
          t.meta = { name: r.query.name, description: r.query.description, folder_id: r.query.folder_id, tags: r.query.tags.join(', ') };
          t.title = r.query.name;
          t.dirty = false;
          renderTabs(); persist();
          QD.toast('Query guardada.', 'success');
        },
      }],
    });
  }

  async function toReport() {
    const t = tab();
    if (!t.savedQueryId) { QD.toast('Guarde primeiro a query; depois pode transformá-la num relatório.', 'info'); return save(false); }
    try {
      const r = await QD.post(`/api/reports/from-query/${t.savedQueryId}`, {});
      window.location.href = r.url;
    } catch (e) { QD.fail(e); }
  }

  function explain() {
    const t = tab();
    const c = conn(t.connectionId);
    if (!c) return;
    const st = cm.somethingSelected() ? { sql: cm.getSelection() } : currentStatement();
    if (!st) return;
    const prefix = { pgsql: 'EXPLAIN ', mysql: 'EXPLAIN ', sqlite: 'EXPLAIN QUERY PLAN ' }[c.driver];
    if (!prefix) return QD.toast('Explain não suportado para este tipo de base de dados.', 'info');
    run('all', prefix + st.sql.replace(/;\s*$/, ''));
  }

  /** Natural language → SQL (generated only; the user reviews and runs it). */
  async function aiGenerate() {
    const t = tab();
    const c = conn(t.connectionId);
    if (!c) return QD.toast('Selecione uma conexão primeiro.', 'error');
    const question = await QD.prompt('Perguntar à IA', '', { label: `O que quer saber de "${c.name}"?`, placeholder: 'Ex.: top 10 clientes por faturação este ano' });
    if (!question) return;
    QD.toast('A IA está a escrever o SQL…', 'info', 2500);
    try {
      const r = await QD.post('/api/assistant/ask', { connection_id: c.id, database: t.database || '', question, mode: 'generate' });
      if (!r.ok) throw new Error(r.error);
      if (!r.sql) return QD.toast(r.answer || 'A IA não devolveu SQL.', 'error', 7000);
      const sql = `-- ${question.replace(/\n/g, ' ')}\n${r.explanation ? '-- ' + r.explanation.replace(/\n/g, ' ') + '\n' : ''}${r.sql};`;
      const cur = t.doc.getValue().trim();
      if (!cur) { t.doc.setValue(sql); } else { newTab({ title: 'IA: ' + question.slice(0, 24), sql, connectionId: c.id, database: t.database || '' }); }
      QD.toast('SQL gerado — reveja e execute com Ctrl+Enter.', 'success');
    } catch (e) { QD.fail(e); }
  }

  function shortcuts() {
    const rows = [['Ctrl + Enter / F5', 'Executar tudo'], ['Ctrl + Shift + Enter', 'Executar seleção / statement atual'], ['Esc', 'Cancelar execução'],
      ['Ctrl + S', 'Guardar query'], ['Ctrl + Shift + F', 'Formatar SQL'], ['Ctrl + I', 'Perguntar à IA (gera SQL)'], ['Ctrl + /', 'Comentar / descomentar'], ['Ctrl + Espaço', 'Autocomplete'],
      ['Alt + N', 'Nova tab'], ['Alt + W', 'Fechar tab'], ['Alt + ← / →', 'Tab anterior / seguinte'], ['Ctrl + K', 'Paleta de comandos'],
      ['Duplo clique (árvore)', 'Inserir nome do objeto'], ['Duplo clique (célula)', 'Ver valor completo']];
    QD.modal({ title: 'Atalhos de teclado', body: `<table class="table compact">${rows.map(([k, v]) => `<tr><td><kbd>${esc(k)}</kbd></td><td>${esc(v)}</td></tr>`).join('')}</table>`, buttons: [{ label: 'Fechar', cls: 'primary' }] });
  }

  document.querySelector('[data-toolbar]').addEventListener('click', (e) => {
    const b = e.target.closest('[data-cmd]');
    if (!b) return;
    ({
      run: () => run('all'), 'run-selection': () => run('selection'), cancel, format, save: () => save(false), 'save-as': () => save(true),
      'to-report': toReport, explain, shortcuts, ai: aiGenerate, comment: () => cm.toggleComment(),
      'toggle-side': () => { ide.classList.toggle(window.innerWidth <= 1100 ? 'side-open' : 'side-hidden'); setTimeout(() => cm.refresh(), 50); },
    }[b.dataset.cmd] || (() => {}))();
  });

  document.addEventListener('keydown', (e) => {
    // Page-level shortcuts that also work when the focus is outside CodeMirror
    if ((e.ctrlKey || e.metaKey) && !e.altKey && !cm.hasFocus() && !document.querySelector('.modal-backdrop')) {
      const k = e.key.toLowerCase();
      if (k === 's') { e.preventDefault(); return save(false); }
      if (k === 'enter') { e.preventDefault(); return run(e.shiftKey ? 'selection' : 'all'); }
    }
    if (!e.altKey) return;
    const i = S.tabs.findIndex((t) => t.id === S.active);
    if (e.key.toLowerCase() === 'n') { e.preventDefault(); newTab(); }
    if (e.key.toLowerCase() === 'w') { e.preventDefault(); closeTab(S.active); }
    if (e.key === 'ArrowRight' && S.tabs[i + 1]) { e.preventDefault(); activate(S.tabs[i + 1].id); }
    if (e.key === 'ArrowLeft' && S.tabs[i - 1]) { e.preventDefault(); activate(S.tabs[i - 1].id); }
  });

  /* ------------------------------------------------------------ splitter */
  const wrapEl = document.querySelector('[data-editor-wrap]');
  const resEl = document.querySelector('[data-results]');
  const setRatio = (r) => { wrapEl.style.flex = `0 0 ${r * 100}%`; resEl.style.flex = '1 1 0'; cm.refresh(); };
  try { const r = parseFloat(localStorage.getItem('qd-split')); if (r > 0.1 && r < 0.9) setRatio(r); } catch (e) {}
  document.querySelector('[data-splitter]').addEventListener('mousedown', (e) => {
    e.preventDefault();
    const sp = e.currentTarget;
    const main = document.querySelector('.ide-main');
    sp.classList.add('drag');
    const top = wrapEl.getBoundingClientRect().top;
    const total = main.getBoundingClientRect().bottom - top;
    const move = (ev) => setRatio(Math.min(0.88, Math.max(0.12, (ev.clientY - top) / total)));
    const up = (ev) => {
      sp.classList.remove('drag');
      document.removeEventListener('mousemove', move);
      document.removeEventListener('mouseup', up);
      try { localStorage.setItem('qd-split', String(Math.min(0.88, Math.max(0.12, (ev.clientY - top) / total)))); } catch (e2) {}
    };
    document.addEventListener('mousemove', move);
    document.addEventListener('mouseup', up);
  });
  window.addEventListener('resize', () => cm.refresh());

  /* ------------------------------------------------------------ boot */
  renderConnOptions();
  restore();
  (async () => {
    const b = DATA.boot;
    if (b.query) await openSaved(b.query);
    else if (b.history) {
      try {
        const { entry } = await QD.get(`/api/history/${b.history}`);
        newTab({ title: 'Histórico #' + entry.id, sql: entry.sql_text, connectionId: conn(entry.connection_id) ? entry.connection_id : undefined, savedQueryId: entry.saved_query_id && null });
      } catch (e) { QD.fail(e); }
    } else if (b.connection && conn(b.connection)) {
      const t = tab();
      if (!t.doc.getValue().trim() && !t.savedQueryId) { t.connectionId = b.connection; t.database = b.database || ''; t.doc = CodeMirror.Doc('', MODES[conn(b.connection).driver]); activate(t.id); }
      else newTab({ connectionId: b.connection, database: b.database || '' });
    }
    if (b.sql) { newTab({ sql: b.sql, connectionId: b.connection || undefined, database: b.database || '' }); }
    if (b.run) run('all');
    if (b.query || b.history || b.connection || b.sql) history.replaceState(null, '', QD.url('/editor'));
  })();
})();

