/* Query history: filters, pagination, reopen in editor. */
(function () {
  'use strict';
  const { esc, icon } = QD;
  const F = { page: 1, per_page: 50, search: '', status: '', connection_id: '', scope: 'mine' };
  let data = { rows: [], total: 0 };
  const $ = (s) => document.querySelector(s);

  async function load() {
    try { data = await QD.get('/api/history', F); render(); } catch (e) { QD.fail(e); }
  }
  function render() {
    $('[data-empty]').classList.toggle('hidden', data.rows.length > 0);
    $('[data-table]').classList.toggle('hidden', data.rows.length === 0);
    $('[data-table] tbody').innerHTML = data.rows.map((h) => `<tr>
      <td><span class="dot ${h.status === 'success' ? 'success' : h.status === 'cancelled' ? 'warning' : 'danger'}" title="${esc(h.status)}"></span></td>
      <td class="small" style="white-space:nowrap">${esc(QD.fmt.date(h.created_at))}<div class="muted">${QD.fmt.ago(h.created_at)}</div></td>
      <td style="max-width:520px"><span class="sql-snippet" title="${esc(h.sql_text.slice(0, 1000))}">${esc(h.sql_text.replace(/\s+/g, ' ').slice(0, 200))}</span>
        ${h.error_message ? `<div class="small truncate" style="color:var(--danger)">${esc(h.error_message.slice(0, 180))}</div>` : ''}</td>
      <td class="small">${esc(h.connection_name || '—')}</td>
      <td class="small">${esc(h.user_name || '—')}</td>
      <td class="small" style="text-align:right">${h.row_count !== null ? QD.fmt.num(h.row_count) : '—'}</td>
      <td class="small mono" style="text-align:right">${QD.fmt.ms(h.duration_ms)}</td>
      <td class="actions"><a class="btn sm" href="${QD.url('/editor?history=' + h.id)}">${icon('terminal', 'sm')} Abrir</a>
        <button class="btn-icon sm" data-copy="${h.id}" title="Copiar SQL">${icon('copy', 'sm')}</button></td></tr>`).join('');
    const pages = Math.max(1, Math.ceil(data.total / F.per_page));
    $('[data-info]').textContent = `${QD.fmt.num(data.total)} execuções · página ${F.page} de ${pages}`;
    $('[data-prev]').disabled = F.page <= 1;
    $('[data-next]').disabled = F.page >= pages;
  }
  $('[data-search]').addEventListener('input', QD.debounce((e) => { F.search = e.target.value; F.page = 1; load(); }, 300));
  $('[data-status]').addEventListener('change', (e) => { F.status = e.target.value; F.page = 1; load(); });
  $('[data-conn]').addEventListener('change', (e) => { F.connection_id = e.target.value; F.page = 1; load(); });
  $('[data-scope]')?.addEventListener('change', (e) => { F.scope = e.target.value; F.page = 1; load(); });
  $('[data-prev]').addEventListener('click', () => { F.page--; load(); });
  $('[data-next]').addEventListener('click', () => { F.page++; load(); });
  $('[data-table]').addEventListener('click', (e) => {
    const b = e.target.closest('[data-copy]');
    if (b) { QD.copy(data.rows.find((h) => h.id === +b.dataset.copy).sql_text); QD.toast('SQL copiado.', 'success', 1500); }
  });
  $('[data-clear]').addEventListener('click', async () => {
    if (!(await QD.confirm('Eliminar todo o seu histórico de execuções?', { danger: true, okLabel: 'Limpar' }))) return;
    try { const r = await QD.delete('/api/history'); QD.toast(`${r.deleted} registos eliminados.`, 'success'); F.page = 1; load(); } catch (e) { QD.fail(e); }
  });
  load();
})();
