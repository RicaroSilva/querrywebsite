/* QueryDeck — core front-end helpers (no framework).
 * Exposes window.QD with: api, icon, esc, toast, modal, confirm, prompt, fmt, download, dropdowns, palette.
 */
(function () {
  'use strict';

  const bootEl = document.getElementById('qd-boot');
  const BOOT = bootEl ? JSON.parse(bootEl.textContent) : { csrf: '', base: '', icons: {}, prefs: {}, can: {} };

  const QD = (window.QD = {
    boot: BOOT,
    url: (p) => BOOT.base + '/' + String(p || '').replace(/^\//, ''),
  });

  /* ------------------------------------------------------------ basics */
  QD.esc = (v) =>
    String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  QD.icon = (name, cls = '') =>
    `<svg class="icon ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${BOOT.icons[name] || BOOT.icons.info || ''}</svg>`;

  QD.$ = (sel, root = document) => root.querySelector(sel);
  QD.$$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  QD.h = (html) => {
    const t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  };
  QD.debounce = (fn, ms = 250) => {
    let t;
    return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  };

  /* ------------------------------------------------------------ API */
  class ApiError extends Error {
    constructor(message, status, errors) { super(message); this.status = status; this.errors = errors || {}; }
  }
  QD.ApiError = ApiError;

  QD.api = async function (method, path, body, opts = {}) {
    const init = {
      method,
      headers: { 'Accept': 'application/json', 'X-CSRF-Token': BOOT.csrf, 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      signal: opts.signal,
    };
    let url = QD.url(path);
    if (body !== undefined && method === 'GET') {
      const qs = new URLSearchParams();
      const add = (k, v) => {
        if (v === null || v === undefined || v === '') return;
        if (typeof v === 'object') Object.entries(v).forEach(([kk, vv]) => add(`${k}[${kk}]`, vv));
        else qs.append(k, v);
      };
      Object.entries(body).forEach(([k, v]) => add(k, v));
      url += (url.includes('?') ? '&' : '?') + qs.toString();
    } else if (body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(body);
    }
    let res;
    try {
      res = await fetch(url, init);
    } catch (e) {
      if (e.name === 'AbortError') throw e;
      throw new ApiError('Sem ligação ao servidor.', 0);
    }
    if (res.status === 401) {
      window.location.href = QD.url('/login');
      throw new ApiError('Sessão expirada.', 401);
    }
    let data;
    try { data = await res.json(); } catch (e) { data = { ok: false, error: `Resposta inválida do servidor (${res.status}).` }; }
    if (!res.ok) throw new ApiError(data.error || `Erro ${res.status}`, res.status, data.errors);
    return data;
  };
  ['get', 'post', 'put', 'delete'].forEach((m) => {
    QD[m] = (path, body, opts) => QD.api(m.toUpperCase(), path, body, opts);
  });

  /* ------------------------------------------------------------ formatting */
  const nf = new Intl.NumberFormat('pt-PT');
  QD.fmt = {
    num: (n) => (n === null || n === undefined || n === '' ? '—' : nf.format(Number(n))),
    ms: (ms) => (ms < 1000 ? `${ms} ms` : ms < 60000 ? `${(ms / 1000).toFixed(2)} s` : `${Math.floor(ms / 60000)}m ${Math.round((ms % 60000) / 1000)}s`),
    bytes: (b) => {
      if (b === null || b === undefined) return '';
      const u = ['B', 'KB', 'MB', 'GB', 'TB'];
      let i = 0; b = Number(b);
      while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
      return `${b.toFixed(i ? 1 : 0)} ${u[i]}`;
    },
    ago: (d) => {
      if (!d) return '—';
      const t = new Date(String(d).replace(' ', 'T'));
      const s = Math.floor((Date.now() - t.getTime()) / 1000);
      if (s < 5) return 'agora';
      if (s < 60) return `${s}s atrás`;
      if (s < 3600) return `${Math.floor(s / 60)}m atrás`;
      if (s < 86400) return `${Math.floor(s / 3600)}h atrás`;
      if (s < 604800) return `${Math.floor(s / 86400)}d atrás`;
      return t.toLocaleDateString('pt-PT');
    },
    date: (d) => (d ? new Date(String(d).replace(' ', 'T')).toLocaleString('pt-PT') : '—'),
  };

  QD.driverLogo = (driver, small) => {
    const txt = { pgsql: 'PG', mysql: 'MY', sqlsrv: 'MS', sqlite: 'SL' }[driver] || '??';
    return `<span class="driver-logo driver-${QD.esc(driver)} ${small ? 'sm' : ''}">${txt}</span>`;
  };

  /* ------------------------------------------------------------ toasts */
  QD.toast = function (message, type = 'info', ms = 3800) {
    const wrap = document.getElementById('toasts') || document.body.appendChild(QD.h('<div class="toasts" id="toasts"></div>'));
    const icon = { success: 'check', error: 'alert', info: 'info' }[type] || 'info';
    const el = QD.h(`<div class="toast ${type}">${QD.icon(icon)}<div>${QD.esc(message)}</div></div>`);
    wrap.appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 260); }, ms);
  };
  QD.fail = (e) => { if (e && e.name !== 'AbortError') QD.toast(e.message || String(e), 'error', 6000); };

  /* ------------------------------------------------------------ modals */
  QD.modal = function ({ title, body = '', size = '', buttons = [], onOpen, onClose, cls = '' }) {
    const el = QD.h(`<div class="modal-backdrop"><div class="modal ${size} ${cls}" role="dialog" aria-modal="true">
      ${title !== undefined ? `<div class="modal-head"><h3>${QD.esc(title)}</h3><button class="btn-icon sm" data-close>${QD.icon('x')}</button></div>` : ''}
      <div class="modal-body"></div>
      ${buttons.length ? '<div class="modal-foot"></div>' : ''}
    </div></div>`);
    const bodyEl = el.querySelector('.modal-body');
    if (typeof body === 'string') bodyEl.innerHTML = body; else bodyEl.appendChild(body);
    const foot = el.querySelector('.modal-foot');
    const api = {
      el, body: bodyEl,
      close() { document.removeEventListener('keydown', onKey); el.remove(); onClose && onClose(); },
    };
    buttons.forEach((b) => {
      const btn = QD.h(`<button class="btn ${b.cls || ''}">${b.icon ? QD.icon(b.icon) : ''}${QD.esc(b.label)}</button>`);
      if (b.left) btn.classList.add('left');
      btn.addEventListener('click', async () => {
        if (!b.action) return api.close();
        btn.disabled = true;
        try { const r = await b.action(api, btn); if (r !== false) api.close(); }
        catch (e) { QD.fail(e); showErrors(bodyEl, e); }
        finally { btn.disabled = false; }
      });
      foot.appendChild(btn);
    });
    const onKey = (e) => { if (e.key === 'Escape') api.close(); };
    document.addEventListener('keydown', onKey);
    el.addEventListener('mousedown', (e) => { if (e.target === el) api.close(); });
    el.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => api.close()));
    document.body.appendChild(el);
    onOpen && onOpen(api);
    const first = bodyEl.querySelector('input:not([type=hidden]),select,textarea');
    if (first) setTimeout(() => first.focus(), 30);
    return api;
  };

  function showErrors(root, e) {
    root.querySelectorAll('.field.has-error').forEach((f) => { f.classList.remove('has-error'); f.querySelector('.err')?.remove(); });
    if (!e || !e.errors) return;
    Object.entries(e.errors).forEach(([name, msg]) => {
      const input = root.querySelector(`[name="${name}"]`);
      const field = input && input.closest('.field');
      if (field) { field.classList.add('has-error'); field.appendChild(QD.h(`<div class="err">${QD.esc(msg)}</div>`)); }
    });
  }
  QD.showErrors = showErrors;

  QD.confirm = (message, { title = 'Confirmar', okLabel = 'Confirmar', danger = false } = {}) =>
    new Promise((resolve) => {
      let done = false;
      QD.modal({
        title, body: `<p style="margin:0;color:var(--text-2)">${QD.esc(message)}</p>`,
        buttons: [
          { label: 'Cancelar', cls: 'ghost' },
          { label: okLabel, cls: danger ? 'danger solid' : 'primary', action: () => { done = true; resolve(true); } },
        ],
        onClose: () => { if (!done) resolve(false); },
      });
    });

  QD.prompt = (title, value = '', { label = 'Nome', placeholder = '' } = {}) =>
    new Promise((resolve) => {
      let done = false;
      const m = QD.modal({
        title,
        body: `<label class="field"><span>${QD.esc(label)}</span><input class="input" name="value" value="${QD.esc(value)}" placeholder="${QD.esc(placeholder)}"></label>`,
        buttons: [
          { label: 'Cancelar', cls: 'ghost' },
          { label: 'OK', cls: 'primary', action: (api) => { done = true; resolve(api.body.querySelector('input').value.trim()); } },
        ],
        onClose: () => { if (!done) resolve(null); },
      });
      m.body.querySelector('input').addEventListener('keydown', (e) => { if (e.key === 'Enter') m.el.querySelector('.btn.primary').click(); });
    });

  /** Read a form's named fields into an object (checkboxes → bool). */
  QD.formData = (root) => {
    const o = {};
    root.querySelectorAll('[name]').forEach((el) => {
      if (el.type === 'checkbox') o[el.name] = el.checked;
      else if (el.type === 'radio') { if (el.checked) o[el.name] = el.value; }
      else o[el.name] = el.value;
    });
    return o;
  };

  /* ------------------------------------------------------------ downloads (exports) */
  QD.download = function (path, fields) {
    let frame = document.getElementById('qd-dl-frame');
    if (!frame) {
      frame = QD.h('<iframe id="qd-dl-frame" name="qd-dl-frame" class="hidden"></iframe>');
      document.body.appendChild(frame);
      // A load event only fires when the server answered with a page (i.e. an error), not for attachments
      frame.addEventListener('load', () => {
        try {
          const txt = frame.contentDocument?.body?.innerText?.trim();
          if (txt) {
            let msg = txt;
            try { msg = JSON.parse(txt).error || txt; } catch (e) { msg = frame.contentDocument.querySelector('p')?.innerText || txt.slice(0, 300); }
            QD.toast(msg, 'error', 7000);
          }
        } catch (e) { /* cross-origin / empty */ }
      });
    }
    const form = QD.h(`<form method="post" action="${QD.esc(QD.url(path))}" target="qd-dl-frame" class="hidden"></form>`);
    Object.entries({ _csrf: BOOT.csrf, ...fields }).forEach(([k, v]) => {
      const i = document.createElement('input');
      i.type = 'hidden'; i.name = k; i.value = typeof v === 'object' ? JSON.stringify(v) : String(v ?? '');
      form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
    setTimeout(() => form.remove(), 1000);
  };

  QD.copy = async (text) => {
    try { await navigator.clipboard.writeText(text); }
    catch (e) {
      const ta = QD.h('<textarea style="position:fixed;opacity:0"></textarea>');
      ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
    }
  };

  /* ------------------------------------------------------------ theme & sidebar */
  const root = document.documentElement;
  function syncThemeIcons() {
    const dark = root.getAttribute('data-theme') === 'dark';
    QD.$$('.theme-icon-dark').forEach((i) => i.classList.toggle('hidden', !dark));
    QD.$$('.theme-icon-light').forEach((i) => i.classList.toggle('hidden', dark));
  }
  QD.setTheme = function (pref) {
    const theme = pref === 'system' ? (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark') : pref;
    root.setAttribute('data-theme', theme);
    root.setAttribute('data-theme-pref', pref);
    try { localStorage.setItem('qd-theme', pref); } catch (e) {}
    syncThemeIcons();
    document.dispatchEvent(new CustomEvent('qd:theme', { detail: theme }));
    if (BOOT.user) QD.post('/api/settings/preferences', { theme: pref }).catch(() => {});
  };
  syncThemeIcons();

  const app = document.getElementById('app');
  try { if (localStorage.getItem('qd-sidebar') === 'collapsed' && window.innerWidth > 860) app?.classList.add('collapsed'); } catch (e) {}

  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-action]');
    if (t) {
      const a = t.dataset.action;
      if (a === 'toggle-theme') QD.setTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
      if (a === 'toggle-sidebar') {
        if (window.innerWidth <= 860) app.classList.toggle('mobile-open');
        else {
          app.classList.toggle('collapsed');
          try { localStorage.setItem('qd-sidebar', app.classList.contains('collapsed') ? 'collapsed' : ''); } catch (e2) {}
        }
        setTimeout(() => window.dispatchEvent(new Event('resize')), 250);
      }
      if (a === 'palette') openPalette();
    }
    // dropdowns
    const trigger = e.target.closest('[data-dropdown]');
    QD.$$('.dropdown.open').forEach((d) => { if (!trigger || d !== trigger.closest('.dropdown')) d.classList.remove('open'); });
    if (trigger) trigger.closest('.dropdown').classList.toggle('open');
    if (e.target.closest('.menu button, .menu a')) e.target.closest('.dropdown')?.classList.remove('open');
    if (app?.classList.contains('mobile-open') && !e.target.closest('.sidebar') && !e.target.closest('[data-action="toggle-sidebar"]')) {
      app.classList.remove('mobile-open');
    }
  });

  /* ------------------------------------------------------------ command palette */
  const PAGES = [
    ['Dashboard', '/', 'dashboard'], ['SQL Editor', '/editor', 'terminal'], ['Bases de Dados', '/explorer', 'database'],
    ['Conexões', '/connections', 'plug'], ['Queries', '/queries', 'file-code'], ['Favoritos', '/favorites', 'star'],
    ['Pastas', '/folders', 'folder'], ['Relatórios', '/reports', 'chart'], ['Histórico', '/history', 'history'],
    ['Definições', '/settings', 'settings'],
  ];
  let paletteData = null;
  async function openPalette() {
    if (document.querySelector('.palette')) return;
    const m = QD.modal({
      cls: 'palette',
      body: `<div style="position:relative">${QD.icon('search', 'pal-icon')}<input class="input" placeholder="Procurar páginas, queries, conexões…"></div><div class="palette-results"></div>`,
    });
    m.body.style.padding = '0';
    m.body.style.maxHeight = 'none';
    const input = m.body.querySelector('input');
    const list = m.body.querySelector('.palette-results');
    let items = [];
    let idx = 0;
    const render = () => {
      const q = input.value.trim().toLowerCase();
      const all = [
        ...PAGES.map(([label, href, icon]) => ({ group: 'Páginas', label, href, icon })),
        ...(paletteData?.queries || []).map((x) => ({ group: 'Queries', label: x.name, sub: x.connection_name || '', href: `/editor?query=${x.id}`, icon: 'file-code' })),
        ...(paletteData?.connections || []).map((c) => ({ group: 'Conexões', label: c.name, sub: c.driver_label, href: `/editor?connection=${c.id}`, icon: 'database' })),
        ...(paletteData?.reports || []).map((r) => ({ group: 'Relatórios', label: r.name, href: `/reports/${r.id}`, icon: 'chart' })),
      ];
      items = all.filter((i) => !q || (i.label + ' ' + (i.sub || '')).toLowerCase().includes(q)).slice(0, 40);
      idx = Math.min(idx, Math.max(0, items.length - 1));
      let last = '';
      list.innerHTML = items.length ? items.map((it, i) => {
        const g = it.group !== last ? `<div class="pal-group">${QD.esc(it.group)}</div>` : '';
        last = it.group;
        return `${g}<div class="pal-item ${i === idx ? 'active' : ''}" data-i="${i}">${QD.icon(it.icon)}<span class="truncate">${QD.esc(it.label)}</span><span class="kind">${QD.esc(it.sub || '')}</span></div>`;
      }).join('') : '<div class="empty">Sem resultados</div>';
    };
    const go = (it) => { if (it) window.location.href = QD.url(it.href); };
    input.addEventListener('input', () => { idx = 0; render(); });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { idx = Math.min(items.length - 1, idx + 1); render(); e.preventDefault(); }
      if (e.key === 'ArrowUp') { idx = Math.max(0, idx - 1); render(); e.preventDefault(); }
      if (e.key === 'Enter') go(items[idx]);
    });
    list.addEventListener('click', (e) => { const el = e.target.closest('.pal-item'); if (el) go(items[+el.dataset.i]); });
    render();
    if (!paletteData) {
      const [q, c] = await Promise.all([QD.get('/api/queries').catch(() => ({})), QD.get('/api/connections').catch(() => ({}))]);
      paletteData = { queries: q.queries || [], connections: c.connections || [], reports: [] };
      render();
    }
  }
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openPalette(); }
  });
})();
