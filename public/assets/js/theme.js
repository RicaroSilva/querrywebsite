// Applied before first paint to avoid a theme flash. Honors "system" preference.
(function () {
  var root = document.documentElement;
  var pref = root.getAttribute('data-theme-pref');
  try { if (!pref) pref = localStorage.getItem('qd-theme') || 'dark'; } catch (e) { pref = pref || 'dark'; }
  var theme = pref === 'system'
    ? (window.matchMedia && matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
    : (pref === 'light' ? 'light' : 'dark');
  root.setAttribute('data-theme', theme);
})();
