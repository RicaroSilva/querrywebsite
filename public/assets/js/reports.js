/* Reports: list page (create) + report page (filters, widgets, charts, builder with drag & drop). */
(function () {
  'use strict';
  const { esc, icon } = QD;

  /* ------------------------------------------------------------ list page */
  document.querySelector('[data-new-report]')?.addEventListener('click', () => {
    const body = QD.h(`<div><label class="field"><span>Nome *</span><input class="input" name="name" placeholder="ex: Vendas Mensais"></label>
      <label class="field"><span>Descrição</span><textarea class="input" name="description" rows="2"></textarea></label></div>`);
    QD.modal({
      title: 'Novo relatório', body,
      buttons: [{ label: 'Cancelar', cls: 'ghost' }, {
        label: 'Criar', cls: 'primary',
        action: async () => { const r = await QD.post('/api/reports', QD.formData(body)); location.href = r.url; },
      }],
    });
  });

  const dataEl = document.getElementById('report-data');
  if (!dataEl) return;

  /* ------------------------------------------------------------ report page */
  const D = JSON.parse(dataEl.textContent);
  const R = D.report;
  const page = document.querySelector('[data-report-page]');
  const grid = document.querySelector('[data-widgets]');
  const charts = {};
  const cache = {};
  let editing = false;
  const FKEY = `qd-report-filters:${R.id}`;
  let filterValues = {};
  try { filterValues = JSON.parse(sessionStorage.getItem(FKEY) || '{}'); } catch (e) {}
  R.filters.forEach((f) => { if (filterValues[f.name] === undefined) filterValues[f.name] = f.default || ''; });

  const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
  const palette = () => [1, 2, 3, 4, 5, 6, 7, 8].map((i) => css(`--chart-${i}`));

  /* ---------- filters ---------- */
  function renderFilters() {
    const el = document.querySelector('[data-filters]');
    el.classList.toggle('hidden', !R.filters.length);
    el.innerHTML = R.filters.map((f) => {
      const v = esc(filterValues[f.name] ?? '');
      const input = f.type === 'select'
        ? `<select class="select sm" data-fv="${esc(f.name)}"><option value="">(todos)</option>${f.options.map((o) => `<option ${o === filterValues[f.name] ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>`
        : `<input class="input sm" data-fv="${esc(f.name)}" type="${f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'}" value="${v}">`;
      return `<label>${esc(f.label || f.name)}${input}</label>`;
    }).join('') + (R.filters.length ? `<button class="btn sm primary" data-apply>${icon('filter', 'sm')} Aplicar</button>
      <button class="btn sm ghost" data-reset>Repor</button>` : '');
    el.querySelector('[data-apply]')?.addEventListener('click', applyFilters);
    el.querySelector('[data-reset]')?.addEventListener('click', () => { R.filters.forEach((f) => { filterValues[f.name] = f.default || ''; }); renderFilters(); applyFilters(); });
    el.querySelectorAll('input[data-fv]').forEach((i) => i.addEventListener('keydown', (e) => { if (e.key === 'Enter') applyFilters(); }));
  }
  function applyFilters() {
    document.querySelectorAll('[data-fv]').forEach((i) => { filterValues[i.dataset.fv] = i.value; });
    try { sessionStorage.setItem(FKEY, JSON.stringify(filterValues)); } catch (e) {}
    Object.keys(cache).forEach((k) => delete cache[k]);
    D.widgets.forEach(loadWidget);
  }

  /* ---------- widgets ---------- */
  function renderWidgets() {
    Object.values(charts).forEach((c) => c.destroy());
    Object.keys(charts).forEach((k) => delete charts[k]);
    document.querySelector('[data-empty]').classList.toggle('hidden', D.widgets.length > 0);
    grid.innerHTML = D.widgets.map((w) => `<div class="card widget w-${w.width}" data-w="${w.id}" draggable="${editing}">
      <div class="card-head">
        ${w.type !== 'kpi' || w.title ? `<h3 class="truncate">${esc(w.title || D.types[w.type])}</h3>` : ''}
        <span class="sub truncate" data-w-meta></span>
        <div class="spacer"></div>
        <span class="actions">
          <button class="btn-icon sm" data-wa="width" title="Largura">${icon('columns', 'sm')}</button>
          <button class="btn-icon sm" data-wa="edit" title="Editar">${icon('edit', 'sm')}</button>
          <button class="btn-icon sm" data-wa="delete" title="Remover">${icon('trash', 'sm')}</button>
          <span class="btn-icon sm" title="Arrastar">${icon('grip', 'sm')}</span>
        </span>
      </div>
      <div class="widget-body ${w.type === 'table' ? 'flush' : ''}" data-w-body><div class="row muted small"><span class="spinner"></span> a carregar…</div></div>
    </div>`).join('');
    page.classList.toggle('editing', editing);
    D.widgets.forEach((w) => (cache[w.id] ? drawWidget(w, cache[w.id]) : loadWidget(w)));
  }

  async function loadWidget(w) {
    const card = grid.querySelector(`[data-w="${w.id}"]`);
    if (!card) return;
    const body = card.querySelector('[data-w-body]');
    if (w.type === 'text') return drawWidget(w, { type: 'text' });
    body.innerHTML = '<div class="row muted small"><span class="spinner"></span> a carregar…</div>';
    try {
      const r = await QD.post(`/api/reports/${R.id}/widgets/${w.id}/data`, { filters: filterValues });
      cache[w.id] = r.data;
      drawWidget(w, r.data);
    } catch (e) {
      body.innerHTML = `<div class="widget-error">${icon('alert', 'sm')}<span>${esc(e.message)}</span></div>`;
    }
  }

  function colIndex(cols, name, fallback) {
    if (name) { const i = cols.findIndex((c) => c.name.toLowerCase() === String(name).toLowerCase()); if (i >= 0) return i; }
    return fallback;
  }
  const isNumCol = (rows, i) => rows.length > 0 && rows.slice(0, 20).every((r) => r[i] === null || (!isNaN(parseFloat(r[i])) && isFinite(r[i])));

  function fmtValue(v, cfg) {
    if (v === null || v === undefined) return '—';
    const n = Number(v);
    if (isNaN(n)) return esc(String(v));
    const dec = cfg.decimals !== undefined && cfg.decimals !== '' ? +cfg.decimals : cfg.format === 'currency' ? 2 : n % 1 ? 2 : 0;
    const opts = { minimumFractionDigits: dec, maximumFractionDigits: dec };
    let s;
    if (cfg.format === 'currency') s = n.toLocaleString('pt-PT', { ...opts, style: 'currency', currency: 'EUR' });
    else if (cfg.format === 'percent') s = (n * (Math.abs(n) <= 1 ? 100 : 1)).toLocaleString('pt-PT', opts) + '%';
    else if (cfg.format === 'compact') s = Intl.NumberFormat('pt-PT', { notation: 'compact', maximumFractionDigits: 1 }).format(n);
    else s = n.toLocaleString('pt-PT', opts);
    return esc((cfg.prefix || '') + s + (cfg.suffix || ''));
  }

  function mdLite(text) {
    const lines = esc(text || '').split('\n');
    let html = '', list = false;
    lines.forEach((l) => {
      const inline = (s) => s.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\*(.+?)\*/g, '<i>$1</i>').replace(/`(.+?)`/g, '<code>$1</code>');
      if (/^\s*[-*] /.test(l)) { if (!list) { html += '<ul>'; list = true; } html += `<li>${inline(l.replace(/^\s*[-*] /, ''))}</li>`; return; }
      if (list) { html += '</ul>'; list = false; }
      const h = /^(#{1,3}) (.*)$/.exec(l);
      if (h) html += `<h${h[1].length}>${inline(h[2])}</h${h[1].length}>`;
      else if (l.trim()) html += `<p>${inline(l)}</p>`;
    });
    return html + (list ? '</ul>' : '');
  }

  function drawWidget(w, d) {
    const card = grid.querySelector(`[data-w="${w.id}"]`);
    if (!card) return;
    const body = card.querySelector('[data-w-body]');
    const meta = card.querySelector('[data-w-meta]');
    const cfg = w.config || {};
    if (charts[w.id]) { charts[w.id].destroy(); delete charts[w.id]; }

    if (d.type === 'text') { body.innerHTML = `<div class="prose">${mdLite(cfg.text)}</div>`; return; }
    if (d.type === 'error') { body.innerHTML = `<div class="widget-error">${icon('alert', 'sm')}<span>${esc(d.error)}</span></div>`; return; }
    meta.textContent = `${QD.fmt.num(d.row_count)} linhas · ${QD.fmt.ms(d.duration_ms)}`;
    const cols = d.columns, rows = d.rows;

    if (w.type === 'kpi') {
      const numIdx = cols.findIndex((c, i) => isNumCol(rows, i));
      const i = colIndex(cols, cfg.value_col, numIdx >= 0 ? numIdx : 0);
      const v = rows[0] ? rows[0][i] : null;
      body.innerHTML = `<div class="kpi-value">${fmtValue(v, cfg)}</div><div class="kpi-sub">${esc(cfg.subtitle || cols[i]?.name || '')}</div>`;
      meta.textContent = '';
      return;
    }
    if (w.type === 'table') {
      body.innerHTML = '<div class="mini-table" style="display:flex;flex-direction:column;max-height:420px"></div>';
      const g = new QD.ResultGrid(body.firstChild, { columns: cols, rows, row_count: rows.length, filtered: rows.length, per_page: 25 }, { local: true });
      g.perPage = 25; g.page = 1; g.renderBody();
      return;
    }
    // charts
    const li = colIndex(cols, cfg.label_col, 0);
    let vis = (Array.isArray(cfg.value_cols) ? cfg.value_cols : String(cfg.value_cols || '').split(',')).map((s) => String(s).trim()).filter(Boolean)
      .map((n) => colIndex(cols, n, -1)).filter((i) => i >= 0);
    if (!vis.length) vis = cols.map((c, i) => i).filter((i) => i !== li && isNumCol(rows, i));
    if (!vis.length) { body.innerHTML = `<div class="widget-error">${icon('alert', 'sm')}<span>Sem colunas numéricas para o gráfico.</span></div>`; return; }
    body.innerHTML = '<div class="chart-box"><canvas></canvas></div>';
    const pal = palette();
    const pie = w.type === 'pie' || w.type === 'doughnut';
    const labels = rows.map((r) => r[li]);
    const datasets = vis.map((i, k) => {
      const color = pal[k % pal.length];
      return {
        label: cols[i].name,
        data: rows.map((r) => (r[i] === null ? null : Number(r[i]))),
        backgroundColor: pie ? rows.map((_, j) => pal[j % pal.length]) : w.type === 'area' ? color + '33' : color,
        borderColor: pie ? css('--surface') : color,
        borderWidth: pie ? 2 : 2,
        fill: w.type === 'area',
        tension: 0.35,
        pointRadius: rows.length > 40 ? 0 : 3,
        borderRadius: w.type === 'bar' ? 5 : 0,
        maxBarThickness: 42,
      };
    });
    const grid_ = { color: css('--border') };
    const ticks = { color: css('--text-3'), font: { size: 11 } };
    charts[w.id] = new Chart(body.querySelector('canvas'), {
      type: w.type === 'area' ? 'line' : w.type,
      data: { labels, datasets },
      options: {
        responsive: true, maintainAspectRatio: false,
        indexAxis: w.type === 'bar' && cfg.horizontal ? 'y' : 'x',
        interaction: { mode: pie ? 'nearest' : 'index', intersect: false },
        plugins: {
          legend: { display: pie || datasets.length > 1, position: pie ? 'right' : 'top', labels: { color: css('--text-2'), boxWidth: 10, boxHeight: 10, font: { size: 11 } } },
          tooltip: { callbacks: { label: (ctx) => ` ${ctx.dataset.label}: ${fmtValue(ctx.parsed.y ?? ctx.parsed.x ?? ctx.parsed, cfg).replace(/&[^;]+;/g, '')}` } },
        },
        cutout: w.type === 'doughnut' ? '62%' : undefined,
        scales: pie ? {} : {
          x: { stacked: !!cfg.stacked, grid: { display: w.type === 'bar' && cfg.horizontal, ...grid_ }, ticks, border: { display: false } },
          y: { stacked: !!cfg.stacked, beginAtZero: true, grid: { display: !(w.type === 'bar' && cfg.horizontal), ...grid_ }, ticks, border: { display: false } },
        },
      },
    });
  }

  /* ---------- widget editor ---------- */
  function widgetModal(w) {
    const isNew = !w;
    w = w || { type: 'kpi', title: '', saved_query_id: null, connection_id: null, sql_text: '', width: 3, config: {} };
    const cfg = w.config || {};
    const typeIcons = { kpi: 'kpi', table: 'table', bar: 'chart', line: 'line', area: 'line', pie: 'pie', doughnut: 'pie', text: 'type' };
    const body = QD.h(`<div>
      <div class="type-picker" data-types>${Object.entries(D.types).map(([k, l]) => `<button type="button" data-t="${k}" class="${w.type === k ? 'active' : ''}">${icon(typeIcons[k])}<span>${esc(l)}</span></button>`).join('')}</div>
      <input type="hidden" name="type" value="${esc(w.type)}">
      <div class="form-grid">
        <label class="field"><span>Título</span><input class="input" name="title" value="${esc(w.title || '')}"></label>
        <label class="field"><span>Largura</span><select class="select" name="width">${[[3, '1/4'], [4, '1/3'], [6, '1/2'], [8, '2/3'], [12, 'Total']].map(([v, l]) => `<option value="${v}" ${+w.width === v ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
      </div>
      <div data-src>
        <div class="field-label">Fonte de dados</div>
        <div class="row" style="gap:16px;margin-bottom:10px">
          <label class="check"><input type="radio" name="src" value="query" ${!w.sql_text ? 'checked' : ''}> Query guardada</label>
          <label class="check"><input type="radio" name="src" value="sql" ${w.sql_text ? 'checked' : ''}> SQL personalizado</label>
        </div>
        <label class="field" data-src-query><span>Query guardada</span><select class="select" name="saved_query_id"><option value="">— escolher —</option>
          ${D.queries.map((q) => `<option value="${q.id}" ${w.saved_query_id === q.id ? 'selected' : ''}>${esc(q.name)}${q.folder_name ? ' · ' + esc(q.folder_name) : ''}</option>`).join('')}</select></label>
        <div data-src-sql>
          <label class="field"><span>Conexão</span><select class="select" name="connection_id"><option value="">— escolher —</option>
            ${D.connections.map((c) => `<option value="${c.id}" ${w.connection_id === c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
          <label class="field"><span>SQL (só leitura; use {{filtro}} para parâmetros)</span><textarea class="input mono" name="sql_text" rows="6" spellcheck="false">${esc(w.sql_text || '')}</textarea></label>
        </div>
      </div>
      <div class="form-grid" data-cfg-chart>
        <label class="field"><span>Coluna de rótulos (eixo X)</span><input class="input" name="label_col" value="${esc(cfg.label_col || '')}" placeholder="(1.ª coluna)"></label>
        <label class="field"><span>Colunas de valores</span><input class="input" name="value_cols" value="${esc([].concat(cfg.value_cols || []).join(', '))}" placeholder="(numéricas, auto)"></label>
        <div class="field full row" style="gap:16px"><label class="check"><input type="checkbox" name="stacked" ${cfg.stacked ? 'checked' : ''}> Empilhado</label>
          <label class="check" data-only="bar"><input type="checkbox" name="horizontal" ${cfg.horizontal ? 'checked' : ''}> Barras horizontais</label></div>
      </div>
      <div class="form-grid" data-cfg-kpi>
        <label class="field"><span>Coluna do valor</span><input class="input" name="value_col" value="${esc(cfg.value_col || '')}" placeholder="(1.ª numérica)"></label>
        <label class="field"><span>Subtítulo</span><input class="input" name="subtitle" value="${esc(cfg.subtitle || '')}"></label>
      </div>
      <div class="form-grid" data-cfg-format>
        <label class="field"><span>Formato</span><select class="select" name="format">${[['number', 'Número'], ['currency', 'Moeda (€)'], ['percent', 'Percentagem'], ['compact', 'Compacto (1,2 mil)']]
          .map(([v, l]) => `<option value="${v}" ${(cfg.format || 'number') === v ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
        <label class="field"><span>Casas decimais</span><input class="input" type="number" min="0" max="6" name="decimals" value="${esc(cfg.decimals ?? '')}" placeholder="auto"></label>
        <label class="field"><span>Prefixo</span><input class="input" name="prefix" value="${esc(cfg.prefix || '')}"></label>
        <label class="field"><span>Sufixo</span><input class="input" name="suffix" value="${esc(cfg.suffix || '')}"></label>
      </div>
      <div data-cfg-text><label class="field"><span>Texto (suporta **negrito**, *itálico*, ## títulos, - listas)</span><textarea class="input" name="text" rows="6">${esc(cfg.text || '')}</textarea></label></div>
      ${R.filters.length ? `<div class="help">Filtros disponíveis: ${R.filters.map((f) => `<code>{{${esc(f.name)}}}</code>`).join(' ')}</div>` : ''}
    </div>`);
    const sync = () => {
      const t = body.querySelector('[name=type]').value;
      const src = body.querySelector('[name=src]:checked').value;
      body.querySelectorAll('[data-t]').forEach((b) => b.classList.toggle('active', b.dataset.t === t));
      body.querySelector('[data-src]').classList.toggle('hidden', t === 'text');
      body.querySelector('[data-src-query]').classList.toggle('hidden', src !== 'query');
      body.querySelector('[data-src-sql]').classList.toggle('hidden', src !== 'sql');
      body.querySelector('[data-cfg-chart]').classList.toggle('hidden', !['bar', 'line', 'area', 'pie', 'doughnut'].includes(t));
      body.querySelector('[data-only="bar"]').classList.toggle('hidden', t !== 'bar');
      body.querySelector('[data-cfg-kpi]').classList.toggle('hidden', t !== 'kpi');
      body.querySelector('[data-cfg-format]').classList.toggle('hidden', t === 'table' || t === 'text');
      body.querySelector('[data-cfg-text]').classList.toggle('hidden', t !== 'text');
    };
    body.querySelector('[data-types]').addEventListener('click', (e) => {
      const b = e.target.closest('[data-t]');
      if (!b) return;
      body.querySelector('[name=type]').value = b.dataset.t;
      const wSel = body.querySelector('[name=width]');
      if (isNew) wSel.value = { kpi: 3, table: 12, text: 12, pie: 4, doughnut: 4 }[b.dataset.t] || 6;
      sync();
    });
    body.querySelectorAll('[name=src]').forEach((r) => r.addEventListener('change', sync));
    sync();

    QD.modal({
      title: isNew ? 'Adicionar componente' : 'Editar componente', size: 'lg', body,
      buttons: [{ label: 'Cancelar', cls: 'ghost' }, {
        label: isNew ? 'Adicionar' : 'Guardar', cls: 'primary',
        action: async () => {
          const f = QD.formData(body);
          const useQuery = f.src === 'query';
          const payload = {
            type: f.type, title: f.title, width: +f.width,
            saved_query_id: useQuery && f.saved_query_id ? +f.saved_query_id : null,
            connection_id: !useQuery && f.connection_id ? +f.connection_id : null,
            sql_text: useQuery ? null : f.sql_text,
            config: {
              label_col: f.label_col, value_cols: f.value_cols.split(',').map((s) => s.trim()).filter(Boolean), value_col: f.value_col,
              subtitle: f.subtitle, format: f.format, decimals: f.decimals, prefix: f.prefix, suffix: f.suffix, text: f.text,
              stacked: f.stacked, horizontal: f.horizontal,
            },
          };
          if (payload.type === 'text') { payload.saved_query_id = null; payload.sql_text = null; }
          const r = isNew ? await QD.post(`/api/reports/${R.id}/widgets`, payload) : await QD.put(`/api/reports/${R.id}/widgets/${w.id}`, payload);
          if (isNew) D.widgets.push(r.widget);
          else D.widgets[D.widgets.findIndex((x) => x.id === w.id)] = r.widget;
          delete cache[r.widget.id];
          renderWidgets();
          QD.toast(isNew ? 'Componente adicionado.' : 'Componente atualizado.', 'success');
        },
      }],
    });
  }

  /* ---------- report settings (name, description, filters) ---------- */
  function settingsModal() {
    const filterRow = (f = {}) => `<div class="row" data-frow style="gap:6px;margin-bottom:6px">
      <input class="input sm" data-k="name" placeholder="nome (ex: start_date)" value="${esc(f.name || '')}" style="width:150px">
      <input class="input sm" data-k="label" placeholder="rótulo" value="${esc(f.label || '')}">
      <select class="select sm" data-k="type" style="width:110px">${['text', 'number', 'date', 'select'].map((t) => `<option ${f.type === t ? 'selected' : ''}>${t}</option>`).join('')}</select>
      <input class="input sm" data-k="default" placeholder="valor por defeito" value="${esc(f.default || '')}">
      <input class="input sm" data-k="options" placeholder="opções (a,b,c)" value="${esc((f.options || []).join(','))}">
      <button class="btn-icon sm" data-rm>${icon('x', 'sm')}</button></div>`;
    const body = QD.h(`<div>
      <label class="field"><span>Nome *</span><input class="input" name="name" value="${esc(R.name)}"></label>
      <label class="field"><span>Descrição</span><textarea class="input" name="description" rows="2">${esc(R.description || '')}</textarea></label>
      <div class="field-label">Filtros do relatório</div>
      <div class="help mb">Use <code>{{nome}}</code> no SQL dos componentes; o valor é enviado como parâmetro (prepared statement), nunca concatenado.</div>
      <div data-frows>${R.filters.map(filterRow).join('')}</div>
      <button class="btn sm" data-add-f>${icon('plus', 'sm')} Adicionar filtro</button></div>`);
    body.querySelector('[data-add-f]').addEventListener('click', () => body.querySelector('[data-frows]').insertAdjacentHTML('beforeend', filterRow()));
    body.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) b.closest('[data-frow]').remove(); });
    QD.modal({
      title: 'Definições do relatório', size: 'xl', body,
      buttons: [{ label: 'Cancelar', cls: 'ghost' }, {
        label: 'Guardar', cls: 'primary',
        action: async () => {
          const filters = Array.from(body.querySelectorAll('[data-frow]')).map((row) => {
            const o = {};
            row.querySelectorAll('[data-k]').forEach((i) => { o[i.dataset.k] = i.value.trim(); });
            o.options = o.options ? o.options.split(',').map((s) => s.trim()).filter(Boolean) : [];
            return o;
          }).filter((f) => f.name);
          const payload = { name: body.querySelector('[name=name]').value, description: body.querySelector('[name=description]').value, filters };
          await QD.put(`/api/reports/${R.id}`, payload);
          Object.assign(R, payload);
          document.querySelector('[data-r-name]').textContent = R.name;
          document.querySelector('[data-r-desc]').textContent = R.description || '';
          R.filters.forEach((f) => { if (filterValues[f.name] === undefined || filterValues[f.name] === '') filterValues[f.name] = f.default || ''; });
          renderFilters();
          applyFilters();
        },
      }],
    });
  }

  /* ---------- toolbar & widget actions ---------- */
  page.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-r]');
    if (b) {
      const a = b.dataset.r;
      if (a === 'refresh') { Object.keys(cache).forEach((k) => delete cache[k]); D.widgets.forEach(loadWidget); }
      if (a === 'print') window.print();
      if (a === 'edit') { editing = !editing; b.classList.toggle('primary', editing); b.querySelector('span').textContent = editing ? 'Concluir' : 'Editar'; renderWidgets(); }
      if (a === 'add') widgetModal(null);
      if (a === 'settings') settingsModal();
      if (a === 'duplicate') { try { const r = await QD.post(`/api/reports/${R.id}/duplicate`, {}); location.href = r.url; } catch (err) { QD.fail(err); } }
      if (a === 'delete' && (await QD.confirm(`Eliminar o relatório "${R.name}"?`, { danger: true, okLabel: 'Eliminar' }))) {
        try { await QD.delete(`/api/reports/${R.id}`); location.href = QD.url('/reports'); } catch (err) { QD.fail(err); }
      }
      return;
    }
    const wa = e.target.closest('[data-wa]');
    if (!wa) return;
    const id = +wa.closest('[data-w]').dataset.w;
    const w = D.widgets.find((x) => x.id === id);
    try {
      if (wa.dataset.wa === 'edit') widgetModal(w);
      if (wa.dataset.wa === 'width') {
        const sizes = [3, 4, 6, 8, 12];
        w.width = sizes[(sizes.indexOf(w.width) + 1) % sizes.length];
        await QD.put(`/api/reports/${R.id}/widgets/${id}`, { ...w, config: w.config || {} });
        renderWidgets();
      }
      if (wa.dataset.wa === 'delete' && (await QD.confirm('Remover este componente?', { danger: true, okLabel: 'Remover' }))) {
        await QD.delete(`/api/reports/${R.id}/widgets/${id}`);
        D.widgets = D.widgets.filter((x) => x.id !== id);
        renderWidgets();
      }
    } catch (err) { QD.fail(err); }
  });

  // drag & drop ordering (edit mode)
  let dragId = null;
  grid.addEventListener('dragstart', (e) => {
    const card = e.target.closest('[data-w]');
    if (!editing || !card) return;
    dragId = +card.dataset.w;
    card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
  });
  grid.addEventListener('dragend', (e) => { e.target.closest('[data-w]')?.classList.remove('dragging'); grid.querySelectorAll('.drop-target').forEach((x) => x.classList.remove('drop-target')); });
  grid.addEventListener('dragover', (e) => {
    if (!editing || dragId === null) return;
    e.preventDefault();
    const card = e.target.closest('[data-w]');
    grid.querySelectorAll('.drop-target').forEach((x) => x.classList.remove('drop-target'));
    if (card && +card.dataset.w !== dragId) card.classList.add('drop-target');
  });
  grid.addEventListener('drop', async (e) => {
    e.preventDefault();
    const card = e.target.closest('[data-w]');
    if (!card || dragId === null) return;
    const to = +card.dataset.w;
    const from = D.widgets.findIndex((w) => w.id === dragId);
    const [moved] = D.widgets.splice(from, 1);
    D.widgets.splice(D.widgets.findIndex((w) => w.id === to) + (from <= D.widgets.findIndex((w) => w.id === to) ? 1 : 0), 0, moved);
    dragId = null;
    renderWidgets();
    try { await QD.post(`/api/reports/${R.id}/reorder`, { ids: D.widgets.map((w) => w.id) }); } catch (err) { QD.fail(err); }
  });

  document.addEventListener('qd:theme', () => D.widgets.forEach((w) => cache[w.id] && drawWidget(w, cache[w.id])));

  renderFilters();
  renderWidgets();
})();
