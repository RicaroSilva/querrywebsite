/* Result grid with server-side paging / sorting / search / column filters.
 *   new QD.ResultGrid(container, result, { connection, exportable, local })
 * `result` is one "rows" result from /api/execute (result_id, columns, rows, row_count, truncated...).
 * With { local: true } the grid works on the given rows only (used by report tables).
 */
(function () {
  'use strict';
  const { esc, icon } = QD;
  const NUM_TYPES = /int|numeric|decimal|float|double|real|money|number|serial|long|short|tiny|newdecimal/i;

  function cellHtml(v, col) {
    if (v === null || v === undefined) return '<td class="null">NULL</td>';
    if (typeof v === 'boolean') return `<td class="bool-${v}">${v}</td>`;
    const s = typeof v === 'object' ? JSON.stringify(v) : String(v);
    const num = typeof v === 'number' || (NUM_TYPES.test(col.type || '') && /^-?\d+(\.\d+)?(e[+-]?\d+)?$/i.test(s));
    const shown = s.length > 300 ? s.slice(0, 300) + '…' : s;
    return `<td class="${num ? 'num' : ''}">${esc(shown)}</td>`;
  }

  class ResultGrid {
    constructor(container, result, opts = {}) {
      this.c = container;
      this.r = result;
      this.opts = opts;
      this.page = 1;
      this.perPage = +(QD.boot.prefs.page_size || result.per_page || 100);
      this.sort = null;
      this.dir = 'asc';
      this.search = '';
      this.filters = {};
      this.showFilters = false;
      this.rows = result.rows || [];
      this.filtered = result.filtered ?? result.row_count;
      this.sel = null;
      this.render();
      if (!opts.local && this.perPage !== (result.per_page || 100)) this.fetch();
    }

    render() {
      const r = this.r;
      this.c.innerHTML = `
        <div class="grid-toolbar">
          <div class="input-icon">${icon('search', 'sm')}<input class="input sm" data-g="search" placeholder="Procurar nos resultados…" value="${esc(this.search)}"></div>
          <button class="btn sm ${this.showFilters ? 'primary' : ''}" data-g="filters" title="Filtros por coluna">${icon('filter', 'sm')} Filtros</button>
          <div class="spacer"></div>
          <button class="btn sm" data-g="copy-cell" title="Copiar célula selecionada (Ctrl+C)">${icon('copy', 'sm')} Célula</button>
          <button class="btn sm" data-g="copy-all" title="Copiar tabela (TSV, cola em Excel)">${icon('copy', 'sm')} Tabela</button>
          ${this.opts.local ? '' : `<button class="btn sm primary" data-g="export">${icon('download', 'sm')} Exportar</button>`}
        </div>
        <div class="grid-scroll" tabindex="0"><table class="grid"><thead></thead><tbody></tbody></table></div>
        <div class="grid-foot">
          <span data-g="count"></span>
          ${r.truncated ? `<span class="badge warning" title="Aumente QUERY_MAX_ROWS ou exporte para obter todas as linhas">limitado a ${QD.fmt.num(r.max_rows || r.row_count)} linhas</span>` : ''}
          <div class="spacer"></div>
          <select class="select sm" data-g="per">${[50, 100, 200, 500].map((n) => `<option ${n === this.perPage ? 'selected' : ''}>${n}</option>`).join('')}</select>
          <button class="btn-icon sm" data-g="first" title="Primeira" style="display:inline-flex;align-items:center;justify-content:center">${icon('chevron', 'sm')}${icon('chevron', 'sm')}</button>
          <button class="btn-icon sm" data-g="prev" title="Anterior" style="transform:scaleX(-1)">${icon('chevron', 'sm')}</button>
          <span data-g="pages"></span>
          <button class="btn-icon sm" data-g="next" title="Seguinte">${icon('chevron', 'sm')}</button>
        </div>`;
      Object.assign(this.c.querySelector('[data-g="first"]').style, { transform: 'scaleX(-1)', gap: '0' });
      this.c.querySelectorAll('[data-g="first"] .icon').forEach((i) => { i.style.margin = '0 -4px'; });
      this.thead = this.c.querySelector('thead');
      this.tbody = this.c.querySelector('tbody');
      this.scroll = this.c.querySelector('.grid-scroll');
      this.renderHead();
      this.renderBody();
      this.bind();
    }

    renderHead() {
      const cols = this.r.columns;
      let h = `<tr><th class="rownum">#</th>${cols.map((c, i) => `<th data-col="${i}" title="${esc(c.name)} ${esc(c.type || '')}">${esc(c.name)}
        ${c.type ? `<span class="ctype">${esc(c.type)}</span>` : ''}
        ${this.sort === i ? `<span class="sort">${icon(this.dir === 'asc' ? 'up' : 'down', 'sm')}</span>` : ''}</th>`).join('')}</tr>`;
      if (this.showFilters) {
        h += `<tr class="filters"><th class="rownum"></th>${cols.map((c, i) =>
          `<th><input data-filter="${i}" value="${esc(this.filters[i] || '')}" placeholder="filtro" title="texto (contém) · =valor · !texto · >n · <n · null · !null"></th>`).join('')}</tr>`;
      }
      this.thead.innerHTML = h;
    }

    renderBody() {
      const cols = this.r.columns;
      const offset = (this.page - 1) * this.perPage;
      const rows = this.opts.local ? this.localPage() : this.rows;
      this.tbody.innerHTML = rows.length
        ? rows.map((row, ri) => `<tr><td class="rownum">${offset + ri + 1}</td>${cols.map((c, ci) => cellHtml(row[ci], c)).join('')}</tr>`).join('')
        : `<tr><td class="null" colspan="${cols.length + 1}" style="text-align:center;padding:18px">${this.filtered === 0 && (this.search || Object.keys(this.filters).length) ? 'Nenhuma linha corresponde ao filtro' : '0 linhas'}</td></tr>`;
      const pages = Math.max(1, Math.ceil(this.filtered / this.perPage));
      this.c.querySelector('[data-g="count"]').innerHTML = this.filtered !== this.r.row_count
        ? `<b>${QD.fmt.num(this.filtered)}</b> de ${QD.fmt.num(this.r.row_count)} linhas`
        : `<b>${QD.fmt.num(this.r.row_count)}</b> linhas`;
      this.c.querySelector('[data-g="pages"]').textContent = `Página ${this.page} de ${pages}`;
      this.c.querySelector('[data-g="prev"]').disabled = this.page <= 1;
      this.c.querySelector('[data-g="first"]').disabled = this.page <= 1;
      this.c.querySelector('[data-g="next"]').disabled = this.page >= pages;
      this.sel = null;
    }

    localPage() {
      let rows = this.r.rows.slice();
      const q = this.search.toLowerCase();
      if (q) rows = rows.filter((r) => r.some((v) => v !== null && String(v).toLowerCase().includes(q)));
      Object.entries(this.filters).forEach(([i, f]) => {
        if (f) rows = rows.filter((r) => String(r[i] ?? '').toLowerCase().includes(f.toLowerCase()));
      });
      if (this.sort !== null) {
        const s = this.sort, d = this.dir === 'asc' ? 1 : -1;
        rows.sort((a, b) => {
          const x = a[s], y = b[s];
          if (x === null) return 1; if (y === null) return -1;
          return (!isNaN(x) && !isNaN(y) ? x - y : String(x).localeCompare(String(y), 'pt', { numeric: true })) * d;
        });
      }
      this.filtered = rows.length;
      this.localAll = rows;
      return rows.slice((this.page - 1) * this.perPage, this.page * this.perPage);
    }

    async fetch() {
      if (this.opts.local) return this.renderBody();
      this.abort?.abort();
      this.abort = new AbortController();
      this.tbody.style.opacity = '.5';
      try {
        const d = await QD.get(`/api/results/${this.r.result_id}`, {
          page: this.page, per_page: this.perPage, sort: this.sort ?? '', dir: this.dir, search: this.search, filters: this.filters,
        }, { signal: this.abort.signal });
        this.rows = d.rows;
        this.filtered = d.filtered;
        this.renderBody();
      } catch (e) { QD.fail(e); }
      finally { this.tbody.style.opacity = ''; }
    }

    bind() {
      const q = (s) => this.c.querySelector(`[data-g="${s}"]`);
      q('search').addEventListener('input', QD.debounce((e) => { this.search = e.target.value; this.page = 1; this.fetch(); }, 300));
      q('filters').addEventListener('click', () => { this.showFilters = !this.showFilters; q('filters').classList.toggle('primary', this.showFilters); this.renderHead(); });
      q('per').addEventListener('change', (e) => { this.perPage = +e.target.value; this.page = 1; this.fetch(); });
      q('prev').addEventListener('click', () => { this.page--; this.fetch(); });
      q('first').addEventListener('click', () => { this.page = 1; this.fetch(); });
      q('next').addEventListener('click', () => { this.page++; this.fetch(); });
      q('copy-cell').addEventListener('click', () => this.copyCell());
      q('copy-all').addEventListener('click', () => this.copyAll());
      q('export')?.addEventListener('click', () => QD.exportDialog(this));

      this.thead.addEventListener('click', (e) => {
        const th = e.target.closest('th[data-col]');
        if (!th) return;
        const i = +th.dataset.col;
        if (this.sort === i) { if (this.dir === 'asc') this.dir = 'desc'; else { this.sort = null; this.dir = 'asc'; } }
        else { this.sort = i; this.dir = 'asc'; }
        this.page = 1;
        this.renderHead();
        this.fetch();
      });
      this.thead.addEventListener('input', QD.debounce((e) => {
        const inp = e.target.closest('[data-filter]');
        if (!inp) return;
        if (inp.value) this.filters[inp.dataset.filter] = inp.value; else delete this.filters[inp.dataset.filter];
        this.page = 1;
        this.fetch();
      }, 350));
      this.tbody.addEventListener('click', (e) => {
        const td = e.target.closest('td');
        if (!td || td.classList.contains('rownum')) return;
        this.tbody.querySelectorAll('td.sel').forEach((x) => x.classList.remove('sel'));
        td.classList.add('sel');
        this.sel = { row: td.parentElement.rowIndex - this.thead.rows.length, col: td.cellIndex - 1 };
        this.scroll.focus({ preventScroll: true });
      });
      this.tbody.addEventListener('dblclick', (e) => {
        const td = e.target.closest('td');
        if (!td || td.classList.contains('rownum')) return;
        const v = this.valueAt(td.parentElement.rowIndex - this.thead.rows.length, td.cellIndex - 1);
        this.viewValue(this.r.columns[td.cellIndex - 1], v);
      });
      this.scroll.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c' && this.sel) { e.preventDefault(); this.copyCell(); }
      });
    }

    currentRows() { return this.opts.local ? this.localPage() : this.rows; }

    valueAt(r, c) { const row = this.currentRows()[r]; return row ? row[c] : undefined; }

    copyCell() {
      if (!this.sel) return QD.toast('Selecione uma célula primeiro.', 'info');
      const v = this.valueAt(this.sel.row, this.sel.col);
      QD.copy(v === null ? 'NULL' : typeof v === 'object' ? JSON.stringify(v) : String(v));
      QD.toast('Célula copiada.', 'success', 1500);
    }

    async copyAll() {
      const clean = (v) => (v === null || v === undefined ? '' : String(v).replace(/[\t\r\n]+/g, ' '));
      try {
        let text;
        if (this.opts.local) {
          this.localPage();
          text = [this.r.columns.map((c) => c.name).join('\t'), ...this.localAll.map((r) => r.map(clean).join('\t'))].join('\n');
        } else {
          const d = await QD.get(`/api/results/${this.r.result_id}/copy`, { search: this.search, filters: this.filters });
          text = d.text;
        }
        await QD.copy(text);
        QD.toast(`Tabela copiada para o clipboard (${QD.fmt.num(text.split('\n').length - 1)} linhas).`, 'success');
      } catch (e) { QD.fail(e); }
    }

    viewValue(col, v) {
      let s = v === null ? 'NULL' : typeof v === 'object' ? JSON.stringify(v, null, 2) : String(v);
      try { if (/^\s*[[{]/.test(s)) s = JSON.stringify(JSON.parse(s), null, 2); } catch (e) {}
      QD.modal({
        title: col.name, size: 'lg',
        body: `<div class="muted small mb">${esc(col.type || '')} · ${QD.fmt.num(s.length)} caracteres</div><pre class="ddl" style="white-space:pre-wrap;word-break:break-all">${esc(s)}</pre>`,
        buttons: [{ label: 'Copiar', icon: 'copy', action: () => { QD.copy(s); QD.toast('Copiado.', 'success', 1500); return false; } }, { label: 'Fechar', cls: 'primary' }],
      });
    }
  }
  QD.ResultGrid = ResultGrid;

  /* ------------------------------------------------------------ export dialog */
  QD.exportDialog = function (grid) {
    const r = grid.r;
    const exportable = r.exportable !== false;
    const body = QD.h(`<div>
      <div class="field-label">Formato</div>
      <div class="type-picker" data-formats>
        ${[['csv', 'CSV', 'file-code'], ['xlsx', 'Excel', 'table'], ['json', 'JSON', 'list'], ['sql', 'SQL', 'database'],
           ['pdf', 'PDF', 'file-code'], ['md', 'Markdown', 'type']].map(([f, l, i], k) =>
          `<button type="button" data-f="${f}" class="${k === 0 ? 'active' : ''}">${icon(i)}<span>${l}</span></button>`).join('')}
      </div>
      <input type="hidden" name="format" value="csv">
      <div class="field-label">Linhas a exportar</div>
      <label class="check" style="display:flex;margin-bottom:6px"><input type="radio" name="scope" value="all" ${exportable ? 'checked' : 'disabled'}>
        Todas as linhas — re-executa a query em modo leitura e faz streaming (sem limite de ${QD.fmt.num(r.max_rows || 0)})</label>
      <label class="check" style="display:flex;margin-bottom:14px"><input type="radio" name="scope" value="view" ${exportable ? '' : 'checked'}>
        Vista atual — ${QD.fmt.num(grid.filtered)} linhas em cache, com pesquisa/filtros/ordenação aplicados</label>
      ${exportable ? '' : '<div class="alert warning">A query não é só-leitura: apenas os resultados em cache podem ser exportados.</div>'}
      <div class="form-grid">
        <label class="field"><span>Nome do ficheiro</span><input class="input" name="filename" value="${esc((grid.opts.filename || 'resultado').replace(/[^\w.-]+/g, '_'))}_${new Date().toISOString().slice(0, 10)}"></label>
        <div data-opt="csv"><label class="field"><span>Separador</span><select class="select" name="delimiter"><option value=",">Vírgula (,)</option><option value=";">Ponto e vírgula (;)</option><option value="tab">Tab</option><option value="|">Pipe (|)</option></select></label></div>
        <div data-opt="csv" class="field full row wrap" style="gap:16px">
          <label class="check"><input type="checkbox" name="header" checked> Cabeçalho</label>
          <label class="check"><input type="checkbox" name="bom"> BOM UTF-8 (Excel)</label>
          <label class="check"><input type="checkbox" name="safe"> Proteger contra fórmulas (=,+,-,@)</label>
          <label class="check"><input type="checkbox" name="decimal_comma"> Vírgula decimal (Excel PT)</label>
        </div>
        <div data-opt="sql" class="hidden"><label class="field"><span>Tabela de destino</span><input class="input" name="table" value="${esc(grid.opts.table || 'export_table')}"></label></div>
        <div data-opt="json" class="hidden field full"><label class="check"><input type="checkbox" name="pretty"> Formatar (pretty print)</label></div>
        <div data-opt="xlsx" class="hidden"><label class="field"><span>Nome da folha</span><input class="input" name="sheet" value="Resultados"></label></div>
        <div data-opt="pdf" class="hidden"><label class="field"><span>Título</span><input class="input" name="title" value="${esc(grid.opts.filename || 'Resultados')}"></label>
          <div class="help">PDF limitado a 5.000 linhas (use CSV/XLSX para volumes grandes).</div></div>
      </div></div>`);
    const setFormat = (f) => {
      body.querySelector('[name=format]').value = f;
      body.querySelectorAll('[data-f]').forEach((b) => b.classList.toggle('active', b.dataset.f === f));
      body.querySelectorAll('[data-opt]').forEach((d) => d.classList.toggle('hidden', d.dataset.opt !== f));
    };
    body.querySelector('[data-formats]').addEventListener('click', (e) => { const b = e.target.closest('[data-f]'); if (b) setFormat(b.dataset.f); });
    QD.modal({
      title: 'Exportar resultados', size: 'lg', body,
      buttons: [
        { label: 'Cancelar', cls: 'ghost' },
        {
          label: 'Exportar', cls: 'primary', icon: 'download',
          action: () => {
            const d = QD.formData(body);
            QD.download('/export', {
              ...d, header: d.header ? '1' : '0', bom: d.bom ? '1' : '0', safe: d.safe ? '1' : '0', pretty: d.pretty ? '1' : '0',
              decimal: d.decimal_comma ? ',' : '.',
              result_id: r.result_id, search: grid.search, filters: grid.filters, sort: grid.sort ?? '', dir: grid.dir,
            });
            QD.toast('Exportação iniciada — o download começa em breve.', 'info');
          },
        },
      ],
    });
  };
})();
