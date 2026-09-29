/* Analyses: parameter form → run (read-only) → grid → Excel/CSV; editor with PostgreSQL function import. */
(function () {
  'use strict';
  const { esc, icon } = QD;
  const D = JSON.parse(document.getElementById('analyses-data').textContent);
  const $ = (s) => document.querySelector(s);
  const main = $('[data-main]');
  const VKEY = (id) => `qd-analysis-values:${QD.boot.user.id}:${id}`;
  let current = null;
  let lastResult = null;

  /* ---------------- list ---------------- */
  function renderList() {
    const q = ($('[data-filter]').value || '').toLowerCase();
    const items = D.analyses.filter((a) => !q || (a.name + ' ' + (a.category || '') + ' ' + (a.description || '')).toLowerCase().includes(q));
    let last = null;
    $('[data-list]').innerHTML = items.length ? items.map((a) => {
      const head = (a.category || 'Geral') !== last ? `<div class="nav-section" style="padding:10px 10px 4px">${esc(a.category || 'Geral')}</div>` : '';
      last = a.category || 'Geral';
      return `${head}<a data-open="${a.id}" class="${current?.id === a.id ? 'active' : ''}" title="${esc(a.description || a.name)}">
        ${icon(a.kind === 'function' ? 'function' : 'file-code', 'sm')}<span class="truncate">${esc(a.name)}</span></a>`;
    }).join('') : '<div class="muted small" style="padding:8px 10px">Sem análises</div>';
  }
  $('[data-filter]').addEventListener('input', renderList);
  $('[data-list]').addEventListener('click', (e) => { const a = e.target.closest('[data-open]'); if (a) open(+a.dataset.open); });
  document.querySelectorAll('[data-new]').forEach((b) => b.addEventListener('click', () => editor(null)));

  /* ---------------- form ---------------- */
  function inputFor(p, value) {
    const v = esc(value ?? '');
    const ph = esc(p.default || (p.hint || '').replace(/^por omissão: /, ''));
    if (p.type === 'boolean') {
      return `<select class="select sm" name="${esc(p.name)}"><option value="">${p.required ? '—' : '(por omissão)'}</option>
        <option value="true" ${value === 'true' ? 'selected' : ''}>Sim</option><option value="false" ${value === 'false' ? 'selected' : ''}>Não</option></select>`;
    }
    if (p.type === 'select' && p.options?.length) {
      return `<select class="select sm" name="${esc(p.name)}"><option value="">${p.required ? '—' : '(por omissão)'}</option>
        ${p.options.map((o) => `<option ${o === value ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>`;
    }
    const type = p.type === 'date' ? 'date' : (p.type === 'number' || p.type === 'integer') ? 'number' : 'text';
    const step = p.type === 'integer' ? '1' : 'any';
    return `<input class="input sm" name="${esc(p.name)}" type="${type}" ${type === 'number' ? `step="${step}"` : ''} value="${v}" placeholder="${ph}">`;
  }

  function open(id) {
    current = D.analyses.find((a) => a.id === id);
    if (!current) return;
    lastResult = null;
    history.replaceState(null, '', QD.url('/analyses?id=' + id));
    renderList();
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(VKEY(id)) || '{}'); } catch (e) {}
    const a = current;
    main.innerHTML = `
      <div class="card mb">
        <div class="card-head">
          <div style="min-width:0">
            <h2 style="font-size:16px">${esc(a.name)}</h2>
            <div class="sub">${esc(a.connection_name || 'sem conexão')} · ${a.kind === 'function' ? `função <code>${esc(a.function_name)}</code>` : 'query SQL'}</div>
          </div>
          <div class="spacer"></div>
          ${D.canManage ? `<button class="btn sm" data-edit>${icon('edit', 'sm')} Editar</button>
            <button class="btn-icon sm" data-dup title="Duplicar">${icon('copy', 'sm')}</button>
            <button class="btn-icon sm" data-del title="Eliminar">${icon('trash', 'sm')}</button>` : ''}
        </div>
        <div class="card-body">
          ${a.description ? `<p class="muted" style="margin-top:0">${esc(a.description)}</p>` : ''}
          <form data-form>
            ${a.params.length ? `<div class="param-grid">${a.params.map((p) => `<label>${esc(p.label || p.name)}${p.required ? ' <span style="color:var(--danger)">*</span>' : ''}
              ${inputFor(p, saved[p.name] ?? '')}<span class="hint">${esc(p.hint || (p.default ? 'por omissão: ' + p.default : 'opcional'))}</span></label>`).join('')}</div>`
              : '<div class="muted small">Esta análise não tem parâmetros.</div>'}
            <div class="row wrap mt" style="gap:8px">
              <button class="btn run" type="submit">${icon('play', 'sm')} Executar</button>
              <button class="btn ghost sm" type="button" data-clear>Limpar valores</button>
              <button class="btn ghost sm" type="button" data-sql>${icon('file-code', 'sm')} Ver SQL</button>
              <div class="spacer" style="flex:1"></div>
              <button class="btn sm" type="button" data-dl="xlsx" disabled>${icon('download', 'sm')} Excel</button>
              <button class="btn sm" type="button" data-dl="csv" disabled>${icon('download', 'sm')} CSV</button>
            </div>
            <pre class="ddl mt hidden" data-sql-box></pre>
          </form>
        </div>
      </div>
      <div data-result></div>`;

    const form = main.querySelector('[data-form]');
    const values = () => {
      const v = {};
      form.querySelectorAll('[name]').forEach((i) => { v[i.name] = i.value; });
      return v;
    };
    form.addEventListener('submit', (e) => { e.preventDefault(); run(values()); });
    main.querySelector('[data-clear]').addEventListener('click', () => { form.querySelectorAll('[name]').forEach((i) => { i.value = ''; }); });
    main.querySelector('[data-sql]').addEventListener('click', async () => {
      const box = main.querySelector('[data-sql-box]');
      if (!box.classList.contains('hidden')) return box.classList.add('hidden');
      try {
        const r = await QD.post(`/api/analyses/${a.id}/preview`, { values: values() });
        box.textContent = r.sql + (r.params.length ? `\n\n-- parâmetros: ${JSON.stringify(r.params)}` : '');
        box.classList.remove('hidden');
      } catch (err) { QD.fail(err); }
    });
    main.querySelectorAll('[data-dl]').forEach((b) => b.addEventListener('click', () => {
      if (!lastResult) return;
      const f = b.dataset.dl;
      QD.download('/export', { result_id: lastResult.result_id, format: f, scope: 'all', filename: slug(a.name),
        delimiter: ';', decimal: ',', bom: '1', header: '1', sheet: a.name.slice(0, 31) });
      QD.toast('Download a começar…', 'info', 2000);
    }));
    main.querySelector('[data-edit]')?.addEventListener('click', () => editor(a));
    main.querySelector('[data-dup]')?.addEventListener('click', async () => {
      try {
        const r = await QD.post('/api/analyses', { ...a, name: a.name + ' (cópia)' });
        D.analyses.push(r.analysis); renderList(); open(r.analysis.id);
      } catch (err) { QD.fail(err); }
    });
    main.querySelector('[data-del]')?.addEventListener('click', async () => {
      if (!(await QD.confirm(`Eliminar a análise "${a.name}"?`, { danger: true, okLabel: 'Eliminar' }))) return;
      try {
        await QD.delete(`/api/analyses/${a.id}`);
        D.analyses = D.analyses.filter((x) => x.id !== a.id);
        current = null; renderList(); main.innerHTML = '<div class="card empty"><h3>Análise eliminada</h3></div>';
      } catch (err) { QD.fail(err); }
    });
  }

  const slug = (s) => (s || 'analise').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^\w-]+/g, '_').replace(/^_+|_+$/g, '') + '_' + new Date().toISOString().slice(0, 10);

  async function run(values) {
    const a = current;
    try { localStorage.setItem(VKEY(a.id), JSON.stringify(values)); } catch (e) {}
    const box = main.querySelector('[data-result]');
    box.innerHTML = `<div class="card card-body row"><span class="spinner"></span> A executar a análise (só leitura)… <span class="elapsed" data-el>0.0 s</span></div>`;
    const t0 = Date.now();
    const timer = setInterval(() => { const el = box.querySelector('[data-el]'); if (el) el.textContent = ((Date.now() - t0) / 1000).toFixed(1) + ' s'; }, 100);
    main.querySelectorAll('[data-dl]').forEach((b) => { b.disabled = true; });
    try {
      const r = await QD.post(`/api/analyses/${a.id}/run`, { values });
      const res = r.results.find((x) => x.type === 'rows');
      const err = r.results.find((x) => x.type === 'error');
      if (err || !res) {
        box.innerHTML = `<div class="sql-error" style="margin:0"><h4>${icon('alert')} Erro</h4><div class="msg">${esc(err?.error?.message || 'Sem resultados.')}</div><pre>${esc(r.sql || '')}</pre></div>`;
        return;
      }
      lastResult = res;
      main.querySelectorAll('[data-dl]').forEach((b) => { b.disabled = false; });
      box.innerHTML = `<div class="card analysis-result">
          <div class="card-head"><h3>Resultado</h3><span class="sub">${QD.fmt.num(res.row_count)}${res.truncated ? '+' : ''} linhas · ${QD.fmt.ms(r.duration_ms)}</span></div>
          <div data-grid style="display:flex;flex-direction:column;flex:1;min-height:0"></div></div>`;
      new QD.ResultGrid(box.querySelector('[data-grid]'), res, { filename: slug(a.name) });
    } catch (e) {
      box.innerHTML = `<div class="alert error">${icon('alert')}<span>${esc(e.message)}</span></div>`;
    } finally { clearInterval(timer); }
  }

  /* ---------------- editor ---------------- */
  const TYPES = { text: 'Texto', number: 'Número', integer: 'Inteiro', date: 'Data', boolean: 'Sim/Não', select: 'Lista' };

  function paramsTable(params) {
    return `<table class="table compact params-table"><thead><tr><th>Parâmetro</th><th>Rótulo</th><th>Tipo</th><th>Valor por omissão</th><th>Obrig.</th><th>Opções (lista)</th><th>Ajuda</th></tr></thead>
      <tbody>${params.map((p) => `<tr data-p="${esc(p.name)}" data-arg="${esc(p.arg_type || '')}">
        <td class="mono small">${esc(p.name)}${p.arg_type ? `<div class="muted" style="font-size:10.5px">${esc(p.arg_type)}</div>` : ''}</td>
        <td><input class="input" data-k="label" value="${esc(p.label || '')}"></td>
        <td><select class="select" data-k="type">${Object.entries(TYPES).map(([k, l]) => `<option value="${k}" ${p.type === k ? 'selected' : ''}>${l}</option>`).join('')}</select></td>
        <td><input class="input" data-k="default" value="${esc(p.default || '')}" placeholder="vazio = da função"></td>
        <td style="text-align:center"><input type="checkbox" data-k="required" ${p.required ? 'checked' : ''}></td>
        <td><input class="input" data-k="options" value="${esc((p.options || []).join(', '))}" placeholder="a, b, c"></td>
        <td><input class="input" data-k="hint" value="${esc(p.hint || '')}"></td></tr>`).join('')}</tbody></table>`;
  }
  function readParams(root) {
    return Array.from(root.querySelectorAll('[data-p]')).map((tr) => {
      const g = (k) => tr.querySelector(`[data-k="${k}"]`);
      return { name: tr.dataset.p, arg_type: tr.dataset.arg, label: g('label').value, type: g('type').value, default: g('default').value,
        required: g('required').checked, options: g('options').value.split(',').map((s) => s.trim()).filter(Boolean), hint: g('hint').value };
    });
  }

  function editor(a) {
    const isNew = !a;
    a = a || { name: '', description: '', category: '', connection_id: D.connections.find((c) => c.driver === 'pgsql')?.id || D.connections[0]?.id, kind: 'function', function_name: '', sql_text: '', params: [] };
    const body = QD.h(`<div>
      <div class="form-grid">
        <label class="field"><span>Nome *</span><input class="input" name="name" value="${esc(a.name)}" placeholder="ex: Análise de risco de transferências"></label>
        <label class="field"><span>Categoria</span><input class="input" name="category" value="${esc(a.category || '')}" placeholder="ex: Risco / Compliance"></label>
        <label class="field full"><span>Descrição</span><input class="input" name="description" value="${esc(a.description || '')}"></label>
        <label class="field"><span>Conexão *</span><select class="select" name="connection_id">${D.connections.map((c) => `<option value="${c.id}" ${+a.connection_id === +c.id ? 'selected' : ''}>${esc(c.name)} · ${esc(c.driver_label)}</option>`).join('')}</select></label>
        <div class="field"><span class="field-label">Tipo</span><div class="row" style="gap:16px;height:34px">
          <label class="check"><input type="radio" name="kind" value="function" ${a.kind === 'function' ? 'checked' : ''}> Função PostgreSQL</label>
          <label class="check"><input type="radio" name="kind" value="query" ${a.kind === 'query' ? 'checked' : ''}> Query SQL</label></div></div>
      </div>
      <div data-fn>
        <label class="field"><span>Função</span><div class="row"><select class="select" data-fn-select style="flex:1"><option value="">a carregar…</option></select>
          <button type="button" class="btn sm" data-fn-reload title="Recarregar lista">${icon('refresh', 'sm')}</button></div>
          <div class="help">Os parâmetros e os valores por omissão são lidos da assinatura da função. Campos vazios → a função usa o seu DEFAULT.</div></label>
        <input type="hidden" name="function_name" value="${esc(a.function_name || '')}">
      </div>
      <div data-q class="hidden">
        <label class="field"><span>SQL (uma query de leitura)</span><textarea class="input mono" name="sql_text" rows="8" spellcheck="false" placeholder="SELECT * FROM transfers t
WHERE t.amount >= {{valor_minimo}}
[[AND t.date >= {{desde}}]]">${esc(a.sql_text || '')}</textarea>
          <div class="help"><code>{{nome}}</code> = parâmetro (enviado de forma segura). <code>[[ ... ]]</code> = bloco opcional, removido se o parâmetro estiver vazio.</div></label>
        <button type="button" class="btn sm mb" data-detect>${icon('search', 'sm')} Detetar parâmetros</button>
      </div>
      <div class="field-label">Parâmetros do formulário</div>
      <div data-params style="overflow-x:auto">${a.params.length ? paramsTable(a.params) : '<div class="muted small">Sem parâmetros.</div>'}</div>
    </div>`);

    const kind = () => body.querySelector('[name=kind]:checked').value;
    const connId = () => +body.querySelector('[name=connection_id]').value;
    let fns = [];
    const loadFns = async () => {
      const sel = body.querySelector('[data-fn-select]');
      sel.innerHTML = '<option value="">a carregar…</option>';
      try {
        const r = await QD.get(`/api/connections/${connId()}/functions`);
        if (!r.ok) throw new Error(r.error);
        fns = r.functions;
        sel.innerHTML = '<option value="">— escolher função —</option>' + fns.map((f) => {
          const name = (f.schema === 'public' ? '' : f.schema + '.') + f.name;
          return `<option value="${f.oid}" ${name === a.function_name ? 'selected' : ''}>${esc(name)}(${esc(f.args)}) → ${esc(f.returns.slice(0, 40))}</option>`;
        }).join('');
      } catch (e) { sel.innerHTML = `<option value="">${esc(e.message)}</option>`; }
    };
    const sync = () => {
      body.querySelector('[data-fn]').classList.toggle('hidden', kind() !== 'function');
      body.querySelector('[data-q]').classList.toggle('hidden', kind() !== 'query');
      if (kind() === 'function' && !fns.length) loadFns();
    };
    body.querySelectorAll('[name=kind]').forEach((r) => r.addEventListener('change', sync));
    body.querySelector('[name=connection_id]').addEventListener('change', () => { fns = []; if (kind() === 'function') loadFns(); });
    body.querySelector('[data-fn-reload]').addEventListener('click', loadFns);
    body.querySelector('[data-fn-select]').addEventListener('change', async (e) => {
      if (!e.target.value) return;
      try {
        const r = await QD.get(`/api/connections/${connId()}/function`, { oid: e.target.value });
        body.querySelector('[name=function_name]').value = r.function_name;
        body.querySelector('[data-params]').innerHTML = r.params.length ? paramsTable(r.params) : '<div class="muted small">A função não tem parâmetros.</div>';
        const nameInput = body.querySelector('[name=name]');
        if (!nameInput.value) nameInput.value = r.function_name.replace(/^.*\./, '').replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
        if (r.comment && !body.querySelector('[name=description]').value) body.querySelector('[name=description]').value = r.comment;
      } catch (err) { QD.fail(err); }
    });
    body.querySelector('[data-detect]').addEventListener('click', () => {
      const sql = body.querySelector('[name=sql_text]').value;
      const names = [...new Set([...sql.matchAll(/\{\{\s*([A-Za-z_]\w*)\s*\}\}/g)].map((m) => m[1]))];
      const old = Object.fromEntries(readParams(body).map((p) => [p.name, p]));
      body.querySelector('[data-params]').innerHTML = names.length
        ? paramsTable(names.map((n) => old[n] || { name: n, label: n.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase()), type: 'text', default: '', required: false, options: [] }))
        : '<div class="muted small">Nenhum {{parâmetro}} encontrado.</div>';
    });
    sync();

    QD.modal({
      title: isNew ? 'Nova análise' : `Editar — ${a.name}`, size: 'xl', body,
      buttons: [{ label: 'Cancelar', cls: 'ghost' }, {
        label: 'Guardar', cls: 'primary', icon: 'save',
        action: async () => {
          const f = QD.formData(body);
          const payload = { name: f.name, description: f.description, category: f.category, connection_id: +f.connection_id,
            kind: kind(), function_name: f.function_name, sql_text: f.sql_text, params: readParams(body) };
          if (payload.kind === 'function' && !payload.function_name) throw new Error('Escolha a função.');
          const r = isNew ? await QD.post('/api/analyses', payload) : await QD.put(`/api/analyses/${a.id}`, payload);
          const i = D.analyses.findIndex((x) => x.id === r.analysis.id);
          if (i >= 0) D.analyses[i] = r.analysis; else D.analyses.push(r.analysis);
          renderList();
          open(r.analysis.id);
          QD.toast('Análise guardada.', 'success');
        },
      }],
    });
  }

  renderList();
  if (D.selected) open(D.selected);
})();
