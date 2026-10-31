// Build Bro Hub — "Quote builder" button (directors only).
// On a job's page it starts a brief for that job; on the Jobs list it opens the quote builder.
// The badge shows how many briefs have Claude's questions waiting for you.
(function () {
  var REST = 'https://bjkjmdqytughwczpzgac.supabase.co/rest/v1';
  var KEY = 'sb_publishable_Ct4SslgkSqBc5w1IUvWSMg_fvHw7zt4';
  var JOB = /^\/jobs\/([0-9a-f-]{36})\/?$/i, LIST = /^\/(jobs|quotes)?\/?$/i;
  var director = null, checking = false, btn = null, waiting = 0, lastCount = 0;

  function session() {
    try {
      for (var i = 0; i < localStorage.length; i++) {
        var k = localStorage.key(i);
        if (k && k.indexOf('sb-') === 0 && k.slice(-11) === '-auth-token') {
          var s = JSON.parse(localStorage.getItem(k) || 'null');
          if (s && s.access_token && s.user && (!s.expires_at || s.expires_at * 1000 > Date.now())) return s;
        }
      }
    } catch (e) {}
    return null;
  }
  function get(path, cb) {
    var s = session(); if (!s) return cb(null);
    fetch(REST + path, { headers: { apikey: KEY, Authorization: 'Bearer ' + s.access_token } })
      .then(function (r) { return r.ok ? r.json() : null; }).then(cb).catch(function () { cb(null); });
  }
  function isDirector(cb) {
    if (director !== null) return cb(director);
    if (checking) return;
    var s = session(); if (!s) return cb(false);
    checking = true;
    get('/company_members?select=role&active=eq.true&role=eq.director&profile_id=eq.' + s.user.id, function (rows) {
      director = !!(rows && rows.length); checking = false; cb(director);
    });
  }
  function count() {
    if (Date.now() - lastCount < 60000) return;
    lastCount = Date.now();
    get('/quote_briefs?select=id&status=eq.with_you', function (rows) { waiting = rows ? rows.length : 0; label(); });
  }
  function label() {
    if (!btn) return;
    btn.innerHTML = '&#9998;&nbsp; Quote builder' + (waiting ? ' <span style="background:#F59E0B;color:#1B1F23;border-radius:999px;padding:0 7px;margin-left:4px">' + waiting + '</span>' : '');
  }
  function make(href) {
    if (!btn) {
      btn = document.createElement('a');
      btn.id = 'hub-qb-btn';
      btn.setAttribute('style', 'position:fixed;right:14px;z-index:40;background:#2F6FEB;color:#fff;' +
        'font:600 15px Barlow,Arial,sans-serif;padding:11px 15px;border-radius:999px;box-shadow:0 4px 14px rgba(0,0,0,.28);text-decoration:none');
      document.body.appendChild(btn);
      label();
    }
    // sits above the "Send for signature" button on job pages
    btn.style.bottom = JOB.test(location.pathname) ? 'calc(138px + env(safe-area-inset-bottom))' : 'calc(84px + env(safe-area-inset-bottom))';
    btn.href = href;
    btn.style.display = '';
  }
  function tick() {
    var p = location.pathname, m = p.match(JOB);
    if (!m && !LIST.test(p)) { if (btn) btn.style.display = 'none'; return; }
    isDirector(function (ok) {
      if (!ok) return;
      var mm = location.pathname.match(JOB);
      if (mm) make('/quote-builder/?job=' + mm[1]);
      else if (LIST.test(location.pathname)) make('/quote-builder/');
      count();
    });
  }
  setInterval(tick, 800);
  document.addEventListener('visibilitychange', function () { lastCount = 0; tick(); });
  tick();
})();
