/* Saved queries library: folders, favourites, search, filters, CRUD. */
(function () {
  'use strict';
  const { esc, icon } = QD;
  const D = JSON.parse(document.getElementById('queries-data').textContent);
  const F = { folder_id: '', favorites: D.mode === 'favorites', tag: '', search: '', connection_id: '', sort: 'updated' };
  let queries = [];

  function renderFolders() {
    const el = document.querySelector('[data-folders]');
    const item = (key, label, ic, count, active, extra = '') =>
      `<a data-folder="${esc(key)}" class="${active ? 'active' : ''}">${ic}<span class="truncate">${esc(label)}</span>${count !== null ? `<span class="count">${count}</span>` : ''}${extra}</a>`;
    const byParent = {};
    D.folders.forEach((f) => { (byParent[f.parent_id || 0] ||= []).push(f); });
    const walk = (pid, depth) => (byParent[pid] || []).map((f) =>
      item(f.id, f.name, `<span style="margin-left:${depth * 14}px;color:${esc(f.color || 'var(--text-3)')}">${icon('folder', 'sm')}</span>`, f.query_count,
        String(F.folder_id) === String(f.id) && !F.favorites,
        D.canManage ? `<span class="folder-actions"><button class="btn-icon sm" data-fedit="${f.id}" title="Editar">${icon('edit', 'sm')}</button><button class="btn-icon sm" data-fdel="${f.id}" title="Eliminar">${icon('trash', 'sm')}</button></span>` : '')
      + walk(f.id, depth + 1)).join('');
    el.innerHTML = item('', 'Todas as queries', icon('file-code', 'sm'), null, !F.folder_id && !F.favorites)
      + item('fav', 'Favoritos', icon('star', 'sm'), null, F.favorites)
      + item('none', 'Sem pasta', icon('folder', 'sm'), null, F.folder_id === 'none' && !F.favorites)
      + '<div class="sep" style="height:1px;background:var(--border);margin:6px 0"></div>'
      + (walk(0, 0) || '<div class="muted small" style="padding:6px 10px">Sem pastas</div>');
    const tags = Object.entries(D.tags || {}).slice(0, 30);
    document.querySelector('[data-tags]').innerHTML = tags.length
      ? `<div class="small muted" style="text-transform:uppercase;letter-spacing:.06em;font-weight:600;margin-bottom:6px">Tags</div><div class="row wrap" style="gap:4px">${tags
        .map(([t, n]) => `<span class="tag" data-tag="${esc(t)}" style="${F.tag === t ? 'background:var(--accent-soft);color:var(--accent)' : ''}">${esc(t)} · ${n}</span>`).join('')}</div>` : '';
  }

  async function load() {
    try {
      const r = await QD.get('/api/queries', { ...F, folder_id: F.folder_id, favorites: F.favorites ? 1 : '' });
      queries = r.queries;
      render();
    } catch (e) { QD.fail(e); }
  }

  function render() {
    const tbody = document.querySelector('[data-table] tbody');
    document.querySelector('[data-empty]').classList.toggle('hidden', queries.length > 0);
    document.querySelector('[data-table]').classList.toggle('hidden', queries.length === 0);
    tbody.innerHTML = queries.map((q) => `<tr class="q-row">
      <td><button class="btn-icon sm ${q.is_favorite ? 'on' : ''}" data-fav="${q.id}" title="Favorito">${icon('star', 'sm')}</button></td>
      <td style="max-width:420px"><a class="name" href="${QD.url('/editor?query=' + q.id)}">${esc(q.name)}</a>
        <div class="desc truncate">${esc(q.description || '')}</div>
        <span class="sql-snippet">${esc(q.sql_text.replace(/\s+/g, ' ').slice(0, 160))}</span></td>
      <td class="small">${q.folder_name ? `${icon('folder', 'sm')} ${esc(q.folder_name)}` : '<span class="muted">—</span>'}</td>
      <td class="small">${q.connection_driver ? QD.driverLogo(q.connection_driver, true) + ' ' : ''}${esc(q.connection_name || '—')}</td>
      <td>${q.tags.map((t) => `<span class="tag" data-tag="${esc(t)}">${esc(t)}</span>`).join(' ')}</td>
      <td class="small muted" title="${esc(QD.fmt.date(q.updated_at))}">${QD.fmt.ago(q.updated_at)}<div>${esc(q.updater_name || q.creator_name || '')}</div></td>
      <td class="actions">
        <a class="btn sm run" href="${QD.url('/editor?run=1&query=' + q.id)}" title="Executar">${icon('play', 'sm')}</a>
        <a class="btn sm" href="${QD.url('/editor?query=' + q.id)}">${icon('terminal', 'sm')} Abrir</a>
        <div class="dropdown"><button class="btn-icon sm" data-dropdown>${icon('menu', 'sm')}</button><div class="menu">
          ${D.canManage ? `<button data-edit="${q.id}">${icon('edit', 'sm')} Editar detalhes</button><button data-dup="${q.id}">${icon('copy', 'sm')} Duplicar</button>` : ''}
          ${D.canReport ? `<button data-report="${q.id}">${icon('chart', 'sm')} Criar relatório</button>` : ''}
          <button data-copy="${q.id}">${icon('copy', 'sm')} Copiar SQL</button>
          ${D.canManage ? `<div class="sep"></div><button class="danger" data-del="${q.id}">${icon('trash', 'sm')} Eliminar</button>` : ''}
        </div></div>
      </td></tr>`).join('');
  }

  function editModal(q) {
    const body = QD.h(`<div>
      <label class="field"><span>Nome *</span><input class="input" name="name" value="${esc(q.name)}"></label>
      <label class="field"><span>Descrição</span><textarea class="input" name="description" rows="2">${esc(q.description || '')}</textarea></label>
      <label class="field"><span>SQL *</span><textarea class="input mono" name="sql_text" rows="10" spellcheck="false">${esc(q.sql_text)}</textarea></label>
      <div class="form-grid">
        <label class="field"><span>Conexão</span><select class="select" name="connection_id"><option value="">—</option>${D.connections.map((c) => `<option value="${c.id}" ${q.connection_id === c.id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></label>
        <label class="field"><span>Pasta</span><select class="select" name="folder_id"><option value="">Sem pasta</option>${D.folders.map((f) => `<option value="${f.id}" ${+q.folder_id === +f.id ? 'selected' : ''}>${esc(f.name)}</option>`).join('')}</select></label>
        <label class="field full"><span>Tags (separadas por vírgula)</span><input class="input" name="tags" value="${esc(q.tags.join(', '))}"></label>
      </div>
      <div class="muted small">Criada por ${esc(q.creator_name || '—')} em ${esc(QD.fmt.date(q.created_at))} · ${q.run_count} execuções</div></div>`);
    QD.modal({
      title: 'Editar query', size: 'lg', body,
      buttons: [{ label: 'Cancelar', cls: 'ghost' }, {
        label: 'Guardar', cls: 'primary', icon: 'save',
        action: async () => {
          const d = QD.formData(body);
          await QD.put(`/api/queries/${q.id}`, { ...d, connection_id: d.connection_id || null, folder_id: d.folder_id || null });
          QD.toast('Query atualizada.', 'success');
          load();
        },
      }],
    });
  }

  function folderModal(f) {
    const body = QD.h(`<div>
      <label class="field"><span>Nome *</span><input class="input" name="name" value="${esc(f?.name || '')}"></label>
      <div class="form-grid">
        <label class="field"><span>Pasta pai</span><select class="select" name="parent_id"><option value="">— (raiz)</option>${D.folders.filter((x) => !f || x.id !== f.id)
          .map((x) => `<option value="${x.id}" ${f && +f.parent_id === +x.id ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select></label>
        <label class="field"><span>Cor</span><input class="input" type="color" name="color" value="${esc(f?.color || '#7c9cff')}" style="padding:3px"></label>
      </div></div>`);
    QD.modal({
      title: f ? 'Editar pasta' : 'Nova pasta', body,
      buttons: [{ label: 'Cancelar', cls: 'ghost' }, {
        label: 'Guardar', cls: 'primary',
        action: async () => {
          const d = QD.formData(body);
          d.parent_id = d.parent_id || null;
          if (f) await QD.put(`/api/folders/${f.id}`, d); else await QD.post('/api/folders', d);
          D.folders = (await QD.get('/api/folders')).folders;
          renderFolders();
        },
      }],
    });
  }

  document.querySelector('[data-folders]').addEventListener('click', async (e) => {
    const ed = e.target.closest('[data-fedit]'), del = e.target.closest('[data-fdel]');
    if (ed) { e.stopPropagation(); return folderModal(D.folders.find((f) => f.id === +ed.dataset.fedit)); }
    if (del) {
      e.stopPropagation();
      const f = D.folders.find((x) => x.id === +del.dataset.fdel);
      if (await QD.confirm(`Eliminar a pasta "${f.name}"? As queries passam para "Sem pasta" e as subpastas são eliminadas.`, { danger: true, okLabel: 'Eliminar' })) {
        await QD.delete(`/api/folders/${f.id}`).catch(QD.fail);
        D.folders = (await QD.get('/api/folders')).folders;
        if (String(F.folder_id) === String(f.id)) F.folder_id = '';
        renderFolders(); load();
      }
      return;
    }
    const a = e.target.closest('[data-folder]');
    if (!a) return;
    const k = a.dataset.folder;
    F.favorites = k === 'fav';
    F.folder_id = k === 'fav' ? '' : k;
    renderFolders(); load();
  });
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-tag]');
    if (t) { F.tag = F.tag === t.dataset.tag ? '' : t.dataset.tag; renderFolders(); load(); }
  });
  document.querySelector('[data-new-folder]')?.addEventListener('click', () => folderModal(null));
  document.querySelector('[data-search]').addEventListener('input', QD.debounce((e) => { F.search = e.target.value; load(); }, 250));
  document.querySelector('[data-conn-filter]').addEventListener('change', (e) => { F.connection_id = e.target.value; load(); });
  document.querySelector('[data-sort]').addEventListener('change', (e) => { F.sort = e.target.value; load(); });

  document.querySelector('[data-table]').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-fav],[data-edit],[data-dup],[data-del],[data-copy],[data-report]');
    if (!b) return;
    const id = +(b.dataset.fav || b.dataset.edit || b.dataset.dup || b.dataset.del || b.dataset.copy || b.dataset.report);
    const q = queries.find((x) => x.id === id);
    try {
      if (b.dataset.fav) { const r = await QD.post(`/api/queries/${id}/favorite`, {}); q.is_favorite = r.favorite; if (F.favorites) load(); else render(); }
      if (b.dataset.edit) editModal(q);
      if (b.dataset.dup) { await QD.post(`/api/queries/${id}/duplicate`, {}); QD.toast('Query duplicada.', 'success'); load(); }
      if (b.dataset.copy) { await QD.copy(q.sql_text); QD.toast('SQL copiado.', 'success', 1500); }
      if (b.dataset.report) { const r = await QD.post(`/api/reports/from-query/${id}`, {}); location.href = r.url; }
      if (b.dataset.del && (await QD.confirm(`Eliminar a query "${q.name}"?`, { danger: true, okLabel: 'Eliminar' }))) {
        await QD.delete(`/api/queries/${id}`); QD.toast('Query eliminada.', 'success'); load();
      }
    } catch (err) { QD.fail(err); }
  });

  renderFolders();
  load();
  if (D.mode === 'folders' && D.canManage && !D.folders.length) folderModal(null);
})();
