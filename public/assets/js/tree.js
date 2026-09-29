/* Lazy database object tree (pgAdmin/SSMS style).
 *   new QD.Tree(el, { connections: [..] | connection: {...}, onAction(action, node, conn), onSelect(node, conn) })
 * Node kinds come from ExplorerService: database, schema, group, table, view, function, procedure, sequence, columns, indexes, column, index.
 */
(function () {
  'use strict';
  const { esc, icon } = QD;

  class Tree {
    constructor(el, opts) {
      this.el = el;
      this.opts = opts;
      this.el.classList.add('tree');
      this.el.innerHTML = '<ul></ul>';
      this.rootUl = this.el.firstElementChild;
      this.el.addEventListener('click', (e) => this.onClick(e));
      this.el.addEventListener('dblclick', (e) => this.onDblClick(e));
      if (opts.connections) {
        opts.connections.forEach((c) => this.rootUl.appendChild(this.connectionItem(c)));
      } else if (opts.connection) {
        this.loadInto(this.rootUl, opts.connection, { kind: 'root' }, 0);
      }
    }

    connectionItem(c) {
      const li = document.createElement('li');
      li._conn = c;
      li._node = { kind: 'root', label: c.name };
      li._depth = 0;
      li.innerHTML = `<div class="node k-connection env-${esc(c.environment)}" style="padding-left:4px">
        <span class="twisty">${icon('chevron', 'sm')}</span>${QD.driverLogo(c.driver, true)}
        <span class="label"><b>${esc(c.name)}</b></span>
        <span class="meta"><span class="env-tag">${esc(c.environment)}</span></span>
        <span class="node-actions"><button class="btn-icon sm" data-act="query" title="Nova query">${icon('terminal', 'sm')}</button>
        <button class="btn-icon sm" data-act="refresh" title="Atualizar">${icon('refresh', 'sm')}</button></span></div>`;
      return li;
    }

    nodeItem(n, conn, depth) {
      const li = document.createElement('li');
      li._node = n;
      li._conn = conn;
      const meta = n.meta && (n.meta.rows !== undefined ? QD.fmt.num(n.meta.rows) + ' linhas' : n.meta.extra || (n.meta.size ? QD.fmt.bytes(n.meta.size) : ''));
      const actions = ['table', 'view'].includes(n.kind)
        ? `<span class="node-actions"><button class="btn-icon sm" data-act="preview" title="Ver dados (SELECT 100)">${icon('play', 'sm')}</button>
           <button class="btn-icon sm" data-act="info" title="Detalhes">${icon('info', 'sm')}</button></span>`
        : ['function', 'procedure', 'sequence'].includes(n.kind)
          ? `<span class="node-actions"><button class="btn-icon sm" data-act="info" title="Definição">${icon('info', 'sm')}</button></span>` : '';
      li.innerHTML = `<div class="node k-${esc(n.kind)}" style="padding-left:${4 + depth * 14}px" title="${esc(n.label)}">
        <span class="twisty ${n.leaf ? 'leaf' : ''}">${icon('chevron', 'sm')}</span>${icon(n.icon || 'folder', 'sm')}
        <span class="label">${esc(n.label)}</span>${n.badge ? `<span class="badge accent">${esc(n.badge)}</span>` : ''}
        ${meta ? `<span class="meta">${esc(meta)}</span>` : ''}${actions}</div>`;
      li._depth = depth;
      return li;
    }

    async loadInto(ul, conn, node, depth) {
      ul.innerHTML = `<li class="loading"><span class="spinner"></span></li>`;
      try {
        const params = { kind: node.kind, database: node.database, schema: node.schema, name: node.name, group: node.group, key: node.key };
        const r = await QD.get(`/api/connections/${conn.id}/tree`, params);
        if (!r.ok) throw new Error(r.error);
        ul.innerHTML = r.nodes.length ? '' : `<li class="loading" style="padding-left:${30 + depth * 14}px">vazio</li>`;
        r.nodes.forEach((n) => ul.appendChild(this.nodeItem(n, conn, depth)));
      } catch (e) {
        ul.innerHTML = `<li class="tree-error" style="padding-left:${30 + depth * 14}px">${esc(e.message)}</li>`;
      }
    }

    toggle(li, forceReload = false) {
      const node = li._node;
      if (node.leaf) return;
      const tw = li.querySelector(':scope > .node .twisty');
      let ul = li.querySelector(':scope > ul');
      if (ul && !forceReload) {
        const hidden = ul.classList.toggle('hidden');
        tw.classList.toggle('open', !hidden);
        return;
      }
      if (!ul) { ul = document.createElement('ul'); li.appendChild(ul); }
      ul.classList.remove('hidden');
      tw.classList.add('open');
      this.loadInto(ul, li._conn, node, (li._depth ?? -1) + 1);
    }

    onClick(e) {
      const nodeEl = e.target.closest('.node');
      if (!nodeEl) return;
      const li = nodeEl.parentElement;
      const act = e.target.closest('[data-act]');
      if (act) {
        e.stopPropagation();
        if (act.dataset.act === 'refresh') return this.toggle(li, true);
        return this.opts.onAction && this.opts.onAction(act.dataset.act, li._node, li._conn);
      }
      this.el.querySelectorAll('.node.selected').forEach((n) => n.classList.remove('selected'));
      nodeEl.classList.add('selected');
      this.opts.onSelect && this.opts.onSelect(li._node, li._conn);
      if (!e.target.closest('.twisty') && ['table', 'view', 'function', 'procedure', 'sequence'].includes(li._node.kind) && this.opts.selectOpens) return;
      this.toggle(li);
    }

    onDblClick(e) {
      const nodeEl = e.target.closest('.node');
      if (!nodeEl) return;
      const li = nodeEl.parentElement;
      this.opts.onAction && this.opts.onAction('dblclick', li._node, li._conn);
    }

    /** Expand the given connection (explorer: ?connection=ID). */
    expandConnection(id) {
      const li = Array.from(this.rootUl.children).find((x) => x._conn && x._conn.id === id);
      if (li) { this.toggle(li); li.scrollIntoView({ block: 'nearest' }); }
    }
  }

  QD.Tree = Tree;

  /** Qualified object name for inserting into SQL. */
  QD.qualifiedName = (node, driver) => {
    const q = (s) => {
      if (/^[a-z_][a-z0-9_]*$/.test(s)) return s;
      if (driver === 'mysql') return '`' + s.replace(/`/g, '``') + '`';
      if (driver === 'sqlsrv') return '[' + s.replace(/]/g, ']]') + ']';
      return '"' + s.replace(/"/g, '""') + '"';
    };
    return (node.schema && !['public', 'dbo'].includes(node.schema) ? q(node.schema) + '.' : '') + q(node.name || node.label);
  };
})();

/* Object detail modal (shared with the explorer page). */
QD.objectInfo = async function (c, node, openSql) {
  const { esc, icon } = QD;
  const m = QD.modal({ title: `${node.kind} · ${node.name}`, size: 'xl', body: '<div class="empty"><span class="spinner lg"></span></div>' });
  try {
    const r = await QD.get(`/api/connections/${c.id}/object`, { kind: node.kind, database: node.database, schema: node.schema, name: node.name, key: node.key });
    if (!r.ok) throw new Error(r.error);
    m.body.innerHTML = QD.objectDetailsHtml(r.object);
    m.body.querySelector('[data-open-sql]')?.addEventListener('click', () => { m.close(); openSql(r.object.preview_sql, node.database); });
    m.body.querySelector('[data-copy-ddl]')?.addEventListener('click', () => { QD.copy(r.object.definition || ''); QD.toast('Copiado.', 'success', 1500); });
  } catch (e) {
    m.body.innerHTML = `<div class="alert error">${icon('alert')}<span>${esc(e.message)}</span></div>`;
  }
};

QD.objectDetailsHtml = function (o) {
  const { esc, icon } = QD;
  const cols = o.columns ? `<div class="card mb"><div class="card-head"><h3>${icon('columns', 'sm')} Colunas</h3><span class="sub">${o.columns.length}</span></div>
    <div style="overflow:auto"><table class="table compact"><thead><tr><th>#</th><th>Nome</th><th>Tipo</th><th>Nulo</th><th>Default</th><th>Extra</th></tr></thead><tbody>
    ${o.columns.map((c, i) => `<tr><td class="muted">${i + 1}</td><td><b class="mono">${esc(c.name)}</b> ${c.primary ? '<span class="badge accent">PK</span>' : ''}</td>
      <td class="mono small">${esc(c.type)}</td><td>${c.nullable ? '<span class="muted">sim</span>' : '<b>não</b>'}</td>
      <td class="mono small">${esc(c.default ?? '')}</td><td class="small muted">${esc(c.extra || c.comment || '')}</td></tr>`).join('')}</tbody></table></div></div>` : '';
  const idx = o.indexes && o.indexes.length ? `<div class="card mb"><div class="card-head"><h3>${icon('key', 'sm')} Índices</h3><span class="sub">${o.indexes.length}</span></div>
    <table class="table compact"><thead><tr><th>Nome</th><th>Tipo</th><th>Definição</th></tr></thead><tbody>
    ${o.indexes.map((i) => `<tr><td class="mono"><b>${esc(i.name)}</b></td><td>${i.primary ? '<span class="badge accent">PRIMARY</span>' : i.unique ? '<span class="badge success">UNIQUE</span>' : `<span class="badge">${esc(i.method || 'INDEX')}</span>`}</td>
      <td class="mono small">${esc(i.definition || '')}</td></tr>`).join('')}</tbody></table></div>` : '';
  const fks = o.foreign_keys && o.foreign_keys.length ? `<div class="card mb"><div class="card-head"><h3>${icon('link', 'sm')} Foreign keys</h3></div>
    <table class="table compact"><tbody>${o.foreign_keys.map((f) => `<tr><td class="mono"><b>${esc(f.name)}</b></td><td class="mono small">${esc(f.definition)}</td></tr>`).join('')}</tbody></table></div>` : '';
  const ddl = o.definition ? `<div class="card"><div class="card-head"><h3>${icon('file-code', 'sm')} Definição / DDL</h3><div class="spacer"></div>
    <button class="btn sm" data-copy-ddl>${icon('copy', 'sm')} Copiar</button></div><div class="card-body"><pre class="ddl">${esc(o.definition)}</pre></div></div>` : '';
  return `<div class="obj-head">
      ${QD.driverLogo(o.connection.driver)}
      <div><h2>${esc(o.name)}</h2><div class="path">${esc(o.connection.name)}${o.database ? ' / ' + esc(o.database) : ''}${o.schema ? ' / ' + esc(o.schema) : ''} · ${esc(o.kind)}</div></div>
      <div class="spacer" style="flex:1"></div>
      ${o.preview_sql ? `<button class="btn primary" data-open-sql>${icon('play', 'sm')} Ver dados</button>` : ''}
    </div>${cols}${idx}${fks}${ddl}`;
};
