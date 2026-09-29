/* Connections: list + create/edit form with "Test connection". Exposes QD.connectionForm(). */
(function () {
  'use strict';
  const { esc, icon } = QD;
  let catalog = null;

  async function loadCatalog() {
    if (catalog) return catalog;
    const d = await QD.get('/api/drivers');
    catalog = { drivers: d.drivers, environments: d.environments };
    return catalog;
  }

  function fieldHtml(f, conn) {
    const opts = (conn && conn.options) || {};
    let value = f.option ? opts[f.name] : conn ? conn[f.name] : undefined;
    if (value === undefined || value === null) value = f.default ?? '';
    const req = f.required ? ' <span style="color:var(--danger)">*</span>' : '';
    const help = f.help ? `<div class="help">${esc(f.help)}</div>` : '';
    if (f.type === 'checkbox') {
      return `<div class="field full"><label class="check"><input type="checkbox" name="${esc(f.name)}" ${value ? 'checked' : ''}> ${esc(f.label)}</label>${help}</div>`;
    }
    if (f.type === 'select') {
      return `<label class="field"><span>${esc(f.label)}${req}</span><select class="select" name="${esc(f.name)}">${f.choices
        .map((c) => `<option ${String(c) === String(value) ? 'selected' : ''}>${esc(c)}</option>`).join('')}</select>${help}</label>`;
    }
    if (f.type === 'password') {
      const ph = conn && conn.has_password ? '•••••••• (deixe vazio para manter)' : '';
      return `<label class="field"><span>${esc(f.label)}</span><input class="input" type="password" name="password" autocomplete="new-password" placeholder="${esc(ph)}">
        ${conn && conn.has_password ? '<label class="check small" style="margin-top:6px"><input type="checkbox" name="clear_password"> Remover password guardada</label>' : ''}${help}</label>`;
    }
    const full = conn && conn.driver === 'sqlite' ? 'full' : '';
    return `<label class="field ${full}"><span>${esc(f.label)}${req}</span><input class="input" type="${f.type === 'number' ? 'number' : 'text'}" name="${esc(f.name)}"
      value="${esc(value)}" placeholder="${esc(f.placeholder || '')}" autocomplete="off">${help}</label>`;
  }

  QD.connectionForm = async function (conn, onSaved) {
    const cat = await loadCatalog();
    let driver = conn ? conn.driver : (cat.drivers.find((d) => d.available) || cat.drivers[0]).name;

    const body = QD.h(`<div>
      <div class="field-label">Tipo de base de dados</div>
      <div class="type-picker" data-drivers>${cat.drivers.map((d) => `<button type="button" data-driver="${esc(d.name)}" ${conn ? 'disabled' : ''}>
          ${QD.driverLogo(d.name, true)}<span>${esc(d.label)}</span>${d.available ? '' : '<span class="badge warning" style="height:16px;font-size:9.5px">sem extensão</span>'}
        </button>`).join('')}</div>
      <div class="form-grid">
        <label class="field full"><span>Nome da conexão <span style="color:var(--danger)">*</span></span><input class="input" name="name" value="${esc(conn?.name || '')}" placeholder="ex: Produção — ERP"></label>
      </div>
      <div class="form-grid" data-fields></div>
      <div class="form-grid">
        <label class="field"><span>Ambiente</span><select class="select" name="environment">${Object.entries(cat.environments)
          .map(([k, v]) => `<option value="${k}" ${(conn?.environment || 'development') === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
        <label class="field"><span>Cor (opcional)</span><input class="input" type="color" name="color" value="${esc(conn?.color || "#7c9cff")}"></label>
        <div class="field full"><label class="check"><input type="checkbox" name="read_only" ${conn?.read_only ? 'checked' : ''}> Modo só de leitura (bloqueia INSERT/UPDATE/DELETE/DDL nesta conexão)</label>
          <div class="help">Rede de segurança. Para garantia total use um utilizador da base de dados com permissões apenas de leitura.</div></div>
      </div>
      <div data-test-result></div>
    </div>`);

    const renderFields = () => {
      body.querySelectorAll('[data-driver]').forEach((b) => b.classList.toggle('active', b.dataset.driver === driver));
      const d = cat.drivers.find((x) => x.name === driver);
      const c = conn && conn.driver === driver ? conn : { driver, port: d.defaultPort };
      if (!conn || conn.driver !== driver) c.port = d.defaultPort;
      body.querySelector('[data-fields]').innerHTML = d.fields.map((f) => fieldHtml(f, c)).join('');
      body.querySelector('[data-test-result]').innerHTML = d.available ? ''
        : `<div class="alert warning">${icon('alert')}<span>A extensão PHP <code>${esc(d.extension)}</code> não está instalada neste servidor. Pode guardar a conexão, mas só funcionará depois de instalar a extensão (ver README).</span></div>`;
    };
    body.querySelector('[data-drivers]').addEventListener('click', (e) => {
      const b = e.target.closest('[data-driver]');
      if (b && !conn) { driver = b.dataset.driver; renderFields(); }
    });
    renderFields();

    const payload = () => ({ ...QD.formData(body), driver, id: conn?.id || null });

    QD.modal({
      title: conn ? `Editar conexão — ${conn.name}` : 'Nova conexão',
      size: 'lg',
      body,
      buttons: [
        {
          label: 'Testar conexão', icon: 'zap', left: true,
          action: async (m, btn) => {
            const out = body.querySelector('[data-test-result]');
            out.innerHTML = `<div class="alert info"><span class="spinner"></span><span>A testar ligação…</span></div>`;
            try {
              const r = await QD.post('/api/connections/test', payload());
              out.innerHTML = r.ok
                ? `<div class="alert info" style="background:var(--success-soft);color:var(--success)">${icon('check')}<span><b>${esc(r.message)}</b><br><span class="small">${esc(r.version)} · ${r.latency_ms} ms</span></span></div>`
                : `<div class="alert error">${icon('alert')}<span>${esc(r.error)}</span></div>`;
            } catch (e) {
              out.innerHTML = `<div class="alert error">${icon('alert')}<span>${esc(e.message)}</span></div>`;
              QD.showErrors(body, e);
            }
            return false;
          },
        },
        { label: 'Cancelar', cls: 'ghost' },
        {
          label: conn ? 'Guardar alterações' : 'Criar conexão', cls: 'primary', icon: 'save',
          action: async () => {
            const r = conn ? await QD.put(`/api/connections/${conn.id}`, payload()) : await QD.post('/api/connections', payload());
            QD.toast(conn ? 'Conexão atualizada.' : 'Conexão criada.', 'success');
            onSaved && onSaved(r.connection);
          },
        },
      ],
    });
  };

  /* ---------------- connections page ---------------- */
  const dataEl = document.getElementById('conn-data');
  if (!dataEl) return;
  const state = JSON.parse(dataEl.textContent);
  catalog = { drivers: state.drivers, environments: state.environments };
  const tbody = document.querySelector('#conn-table tbody');

  function render() {
    document.getElementById('conn-empty').classList.toggle('hidden', state.connections.length > 0);
    document.getElementById('conn-table').classList.toggle('hidden', state.connections.length === 0);
    tbody.innerHTML = state.connections.map((c) => `<tr class="env-${esc(c.environment)}">
      <td><div class="row">${c.color ? `<span class="dot" style="background:${esc(c.color)}"></span>` : ''}<b>${esc(c.name)}</b>
        ${c.read_only ? '<span class="badge warning">só leitura</span>' : ''}</div></td>
      <td><div class="row">${QD.driverLogo(c.driver, true)} ${esc(c.driver_label)}</div></td>
      <td class="mono small">${esc(c.host ? c.host + (c.port ? ':' + c.port : '') : '—')}</td>
      <td class="mono small">${esc(c.database_name || '—')}</td>
      <td><span class="env-tag">${esc(state.environments[c.environment] || c.environment)}</span></td>
      <td class="muted small">${QD.fmt.ago(c.last_used_at)}</td>
      <td class="actions">
        <a class="btn sm" href="${QD.url('/editor?connection=' + c.id)}">${icon('terminal')} Query</a>
        <a class="btn-icon sm" title="Explorar" href="${QD.url('/explorer?connection=' + c.id)}">${icon('database')}</a>
        ${state.canManage ? `<button class="btn-icon sm" title="Editar" data-edit="${c.id}">${icon('edit')}</button>
        <button class="btn-icon sm" title="Eliminar" data-del="${c.id}">${icon('trash')}</button>` : ''}
      </td></tr>`).join('');
  }
  render();

  const upsert = (c) => {
    const i = state.connections.findIndex((x) => x.id === c.id);
    if (i >= 0) state.connections[i] = c; else state.connections.push(c);
    render();
  };
  document.querySelector('[data-conn-new]')?.addEventListener('click', () => QD.connectionForm(null, upsert));
  tbody.addEventListener('click', async (e) => {
    const ed = e.target.closest('[data-edit]');
    const del = e.target.closest('[data-del]');
    if (ed) QD.connectionForm(state.connections.find((c) => c.id === +ed.dataset.edit), upsert);
    if (del) {
      const c = state.connections.find((x) => x.id === +del.dataset.del);
      if (await QD.confirm(`Eliminar a conexão "${c.name}"? As queries guardadas ficam sem conexão associada.`, { danger: true, okLabel: 'Eliminar' })) {
        try {
          await QD.delete(`/api/connections/${c.id}`);
          state.connections = state.connections.filter((x) => x.id !== c.id);
          render();
          QD.toast('Conexão eliminada.', 'success');
        } catch (err) { QD.fail(err); }
      }
    }
  });
  if (new URLSearchParams(location.search).has('new') && state.canManage) QD.connectionForm(null, upsert);
})();
