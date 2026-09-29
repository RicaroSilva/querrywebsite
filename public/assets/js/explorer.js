/* Explorer page: multi-connection tree + object detail panel. */
(function () {
  'use strict';
  const { esc, icon } = QD;
  const data = JSON.parse(document.getElementById('explorer-data').textContent);
  const treeEl = document.querySelector('[data-tree]');
  const detail = document.querySelector('[data-detail]');

  const openInEditor = (c, sql, db) => {
    const qs = new URLSearchParams({ connection: c.id, sql, run: '1' });
    if (db) qs.set('database', db);
    window.location.href = QD.url('/editor?' + qs.toString());
  };

  async function showDetail(node, c) {
    if (!['table', 'view', 'function', 'procedure', 'sequence'].includes(node.kind)) return;
    detail.innerHTML = '<div class="empty" style="margin-top:10vh"><span class="spinner lg"></span></div>';
    try {
      const r = await QD.get(`/api/connections/${c.id}/object`, { kind: node.kind, database: node.database, schema: node.schema, name: node.name, key: node.key });
      if (!r.ok) throw new Error(r.error);
      detail.innerHTML = `<div class="page">${QD.objectDetailsHtml(r.object)}</div>`;
      detail.querySelector('[data-open-sql]')?.addEventListener('click', () => openInEditor(c, r.object.preview_sql, node.database));
      detail.querySelector('[data-copy-ddl]')?.addEventListener('click', () => { QD.copy(r.object.definition || ''); QD.toast('Copiado.', 'success', 1500); });
    } catch (e) {
      detail.innerHTML = `<div class="alert error">${icon('alert')}<span>${esc(e.message)}</span></div>`;
    }
  }

  let tree;
  const build = (list) => {
    treeEl.innerHTML = '';
    if (!list.length) {
      treeEl.innerHTML = `<div class="empty small">${icon('plug')}<h3>Sem conexões</h3><p><a href="${QD.url('/connections?new=1')}">Criar conexão</a></p></div>`;
      return;
    }
    tree = new QD.Tree(treeEl, {
      connections: list,
      selectOpens: true,
      onSelect: (node, c) => showDetail(node, c),
      onAction: (act, node, c) => {
        if (act === 'query') window.location.href = QD.url('/editor?connection=' + c.id);
        if (act === 'preview') {
          const name = QD.qualifiedName(node, c.driver);
          openInEditor(c, c.driver === 'sqlsrv' ? `SELECT TOP (100) *\nFROM ${name};` : `SELECT *\nFROM ${name}\nLIMIT 100;`, node.database);
        }
        if (act === 'info') showDetail(node, c);
      },
    });
  };
  build(data.connections);

  document.querySelector('[data-filter]').addEventListener('input', (e) => {
    const q = e.target.value.toLowerCase();
    build(data.connections.filter((c) => (c.name + ' ' + c.driver_label + ' ' + (c.host || '')).toLowerCase().includes(q)));
  });
  document.querySelector('[data-new-conn]')?.addEventListener('click', () =>
    QD.connectionForm(null, (c) => { data.connections.push(c); build(data.connections); }));

  const pre = +new URLSearchParams(location.search).get('connection');
  if (pre && tree) tree.expandConnection(pre);
  else if (tree && data.connections.length === 1) tree.expandConnection(data.connections[0].id);
})();
