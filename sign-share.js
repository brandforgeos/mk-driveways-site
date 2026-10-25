// Build Bro Hub — "Send for signature" button on a job's page (directors only).
// Opens /sign/new/?job=<id>, where a director uploads a PDF, taps where the customer signs and sends the link.
(function () {
  var REST = 'https://bjkjmdqytughwczpzgac.supabase.co/rest/v1';
  var KEY = 'sb_publishable_Ct4SslgkSqBc5w1IUvWSMg_fvHw7zt4';
  var JOB = /^\/jobs\/([0-9a-f-]{36})\/?$/i;
  var director = null, checking = false, btn = null;

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
  function isDirector(cb) {
    if (director !== null) return cb(director);
    if (checking) return;
    var s = session(); if (!s) return cb(false);
    checking = true;
    fetch(REST + '/company_members?select=role&active=eq.true&role=eq.director&profile_id=eq.' + s.user.id, {
      headers: { apikey: KEY, Authorization: 'Bearer ' + s.access_token }
    }).then(function (r) { return r.ok ? r.json() : []; }).then(function (rows) {
      director = !!(rows && rows.length); checking = false; cb(director);
    }).catch(function () { checking = false; cb(false); });
  }
  function make(id) {
    if (!btn) {
      btn = document.createElement('a');
      btn.id = 'hub-sign-btn';
      btn.textContent = '✍  Send for signature';
      btn.setAttribute('style', 'position:fixed;right:14px;bottom:calc(84px + env(safe-area-inset-bottom));z-index:40;background:#1B1F23;color:#fff;' +
        'font:600 15px Barlow,Arial,sans-serif;padding:11px 15px;border-radius:999px;box-shadow:0 4px 14px rgba(0,0,0,.28);text-decoration:none');
      document.body.appendChild(btn);
    }
    btn.href = '/sign/new/?job=' + id;
    btn.style.display = '';
  }
  function tick() {
    var m = location.pathname.match(JOB);
    if (!m) { if (btn) btn.style.display = 'none'; return; }
    isDirector(function (ok) { if (ok && location.pathname.match(JOB)) make(m[1]); });
  }
  setInterval(tick, 800);
  document.addEventListener('visibilitychange', tick);
  tick();
})();
