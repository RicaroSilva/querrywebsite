/* Settings: preferences, profile, password, user admin. */
(function () {
  'use strict';
  const { esc } = QD;
  const $ = (s) => document.querySelector(s);
  const roles = JSON.parse(document.getElementById('settings-data').textContent).roles;

  $('[data-save-prefs]').addEventListener('click', async () => {
    const d = QD.formData($('[data-prefs]'));
    d.autocomplete = d.autocomplete ? '1' : '0';
    d.confirm_write = d.confirm_write ? '1' : '0';
    try {
      await QD.post('/api/settings/preferences', d);
      QD.setTheme(d.theme);
      QD.toast('Preferências guardadas.', 'success');
    } catch (e) { QD.fail(e); }
  });
  $('[data-save-profile]').addEventListener('click', async () => {
    try { await QD.post('/api/settings/profile', QD.formData($('[data-profile]'))); QD.toast('Perfil atualizado.', 'success'); } catch (e) { QD.fail(e); }
  });
  $('[data-save-password]').addEventListener('click', async () => {
    const box = $('[data-password]');
    try {
      const r = await QD.post('/api/settings/password', QD.formData(box));
      box.querySelectorAll('input').forEach((i) => { i.value = ''; });
      QD.showErrors(box, null);
      QD.toast(r.message, 'success');
    } catch (e) { QD.fail(e); QD.showErrors(box, e); }
  });

  function userModal(u) {
    const body = QD.h(`<div class="form-grid">
      ${u ? '' : `<label class="field"><span>Nome *</span><input class="input" name="name"></label>
      <label class="field"><span>Email *</span><input class="input" name="email" type="email"></label>`}
      <label class="field"><span>Perfil</span><select class="select" name="role">${Object.entries(roles).map(([k, v]) => `<option value="${k}" ${u && u.role === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select></label>
      <label class="field"><span>${u ? 'Nova password (opcional)' : 'Password *'} (mín. 10)</span><input class="input" type="password" name="password" autocomplete="new-password"></label>
      ${u ? `<div class="field full"><label class="check"><input type="checkbox" name="is_active" ${u.is_active ? 'checked' : ''}> Conta ativa</label></div>` : ''}
    </div>`);
    QD.modal({
      title: u ? `Editar ${u.name}` : 'Novo utilizador', body,
      buttons: [{ label: 'Cancelar', cls: 'ghost' }, {
        label: 'Guardar', cls: 'primary',
        action: async () => {
          const d = QD.formData(body);
          if (u && !d.password) delete d.password;
          if (u) await QD.put(`/api/users/${u.id}`, d); else await QD.post('/api/users', d);
          location.reload();
        },
      }],
    });
  }
  $('[data-new-user]')?.addEventListener('click', () => userModal(null));
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-edit-user]');
    if (b) userModal(JSON.parse(b.closest('[data-user]').dataset.user));
  });
})();
