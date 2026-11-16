// Build Bro Hub — director sign-off on the quote screen (/quotes/:id and /quotes/:id/edit).
// A held quote (anything not created by a director signed in to the app: Claude, the quote builder, imports done by
// scripts) shows a sign-off bar here, so it can be signed off without going through the Quote builder.
// Same rules as the Quote builder, enforced by the database (public.qb_sign_off): price build-up must exist, and this
// director must have opened the PDF and the price build-up first. Signing off never sends anything.
(function () {
  var SUPA = 'https://bjkjmdqytughwczpzgac.supabase.co';
  var REST = SUPA + '/rest/v1';
  var KEY = 'sb_publishable_Ct4SslgkSqBc5w1IUvWSMg_fvHw7zt4';

  function session() {
    try {
      for (var i = 0; i < localStorage.length; i++) {
        var k = localStorage.key(i);
        if (k && k.indexOf('sb-') === 0 && k.slice(-11) === '-auth-token') {
          var s = JSON.parse(localStorage.getItem(k) || 'null');
          if (s && s.access_token && s.user) return s;
        }
      }
    } catch (e) {}
    return null;
  }
  function call(method, url, body) {
    var s = session();
    if (!s) return Promise.reject(new Error('You’re signed out. Sign in again and retry.'));
    return fetch(url, {
      method: method,
      headers: { apikey: KEY, Authorization: 'Bearer ' + s.access_token, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    }).then(function (r) {
      return r.text().then(function (t) {
        var j = null; try { j = t ? JSON.parse(t) : null; } catch (e) {}
        if (!r.ok) throw new Error((j && (j.message || j.error)) || ('The hub said no (' + r.status + ').'));
        return j;
      });
    });
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  var CSS = '#hub-so{position:fixed;left:8px;right:8px;bottom:calc(76px + env(safe-area-inset-bottom));z-index:50;max-width:560px;margin:0 auto;' +
    'background:#3D3215;border:1px solid #C9A227;color:#FFE9A8;border-radius:12px;padding:12px 12px 10px;font:14px Barlow,Arial,sans-serif;box-shadow:0 6px 24px rgba(0,0,0,.45)}' +
    '#hub-so.ok{background:#16351F;border-color:#3FA34D;color:#CFF5D5}' +
    '#hub-so b{color:#fff}#hub-so .t{margin:2px 0 8px}#hub-so .k{margin:2px 0;font-weight:600}#hub-so .k.y{color:#7FE08C}' +
    '#hub-so .r{display:flex;gap:6px;margin-top:8px}#hub-so button{flex:1;border:0;border-radius:9px;padding:11px 6px;font:600 14px Barlow,Arial,sans-serif;cursor:pointer;background:#2E343B;color:#F1F1EE}' +
    '#hub-so button.go{background:#3FA34D;color:#fff}#hub-so button.dim{opacity:.45}#hub-so .e{color:#FFB4B4;margin-top:6px;font-weight:600}';

  var current = null, busy = false;

  function remove() { var d = document.getElementById('hub-so'); if (d) d.remove(); }

  function quoteIdFromPath() {
    var m = location.pathname.match(/^\/quotes\/([0-9a-f-]{36})(\/edit)?\/?$/i);
    return m ? m[1] : null;
  }

  function load(id) {
    var s = session(); if (!s) { remove(); return; }
    Promise.all([
      call('GET', REST + '/quotes?id=eq.' + id + '&select=id,ref,revision,status,qb_hold,qb_signed_off_at,document_path'),
      call('GET', REST + '/quote_costings?quote_id=eq.' + id + '&select=version'),
      call('GET', REST + '/quote_pdf_views?quote_id=eq.' + id + '&profile_id=eq.' + s.user.id + '&select=first_at'),
      call('GET', REST + '/quote_costing_views?quote_id=eq.' + id + '&profile_id=eq.' + s.user.id + '&select=first_at')
    ]).then(function (r) {
      if (current !== id) return;
      var q = r[0] && r[0][0];
      if (!q || !q.qb_hold || q.qb_signed_off_at || q.status !== 'draft') { remove(); return; }
      render(q, !!(r[1] && r[1].length), !!(r[2] && r[2].length), !!(r[3] && r[3].length));
    }).catch(function () { /* not a director, or offline: show nothing */ remove(); });
  }

  function render(q, hasCost, pdfSeen, costSeen) {
    if (!document.getElementById('hub-so-css')) { var st = document.createElement('style'); st.id = 'hub-so-css'; st.textContent = CSS; document.head.appendChild(st); }
    var d = document.getElementById('hub-so');
    if (!d) { d = document.createElement('div'); d.id = 'hub-so'; document.body.appendChild(d); }
    d.className = '';
    var ready = hasCost && pdfSeen && costSeen;
    var tick = function (ok, t) { return '<div class="k' + (ok ? ' y' : '') + '">' + (ok ? '☑' : '☐') + ' ' + t + '</div>'; };
    d.innerHTML = '<div><b>' + esc(q.ref) + ' rev ' + esc(q.revision) + ' is held — not signed off.</b></div>' +
      '<div class="t">The customer can’t open it until a director signs it off.</div>' +
      tick(pdfSeen, 'PDF opened') + tick(costSeen, 'Price build-up opened') + (hasCost ? '' : tick(false, 'Price build-up added by Claude')) +
      '<div class="r"><button id="so-pdf"' + (q.document_path ? '' : ' disabled') + '>View PDF</button>' +
      '<button id="so-cost"' + (hasCost ? '' : ' disabled') + '>£ Build-up</button>' +
      '<button id="so-go" class="go' + (ready ? '' : ' dim') + '">✓ Sign off</button></div><div class="e" id="so-err"></div>';
    var err = function (m) { document.getElementById('so-err').textContent = m; };

    document.getElementById('so-pdf').onclick = function () {
      call('POST', REST + '/rpc/qb_pdf_open', { p_quote: q.id })
        .then(function () { return call('POST', SUPA + '/storage/v1/object/sign/documents/' + q.document_path.split('/').map(encodeURIComponent).join('/'), { expiresIn: 600 }); })
        .then(function (j) { location.href = SUPA + '/storage/v1' + (j.signedURL || j.signedUrl); })
        .catch(function (e) { err('Couldn’t open the PDF: ' + e.message); });
    };
    document.getElementById('so-cost').onclick = function () { location.href = '/quote-builder/?costing=' + q.id; };
    document.getElementById('so-go').onclick = function () {
      if (busy) return;
      if (!ready) { err(!pdfSeen ? 'Open the PDF and check every page first.' : !hasCost ? 'Claude hasn’t added the price build-up yet.' : 'Open the price build-up first.'); return; }
      if (!confirm('Sign off ' + q.ref + ' rev ' + q.revision + '?\n\nOnly sign off once you’ve opened the PDF and checked every page — names, address, scope and every price.\n\nThis doesn’t send it.')) return;
      busy = true; var b = this; b.disabled = true; b.textContent = 'Signing off…';
      call('POST', REST + '/rpc/qb_sign_off', { p_quote: q.id })
        .then(function () {
          d.className = 'ok';
          d.innerHTML = '<b>Signed off ✓</b><div class="t" style="margin-bottom:0">' + esc(q.ref) + ' rev ' + esc(q.revision) + ' can now go to the customer.</div>';
          setTimeout(function () { busy = false; location.reload(); }, 1800);
        })
        .catch(function (e) { busy = false; b.disabled = false; b.textContent = '✓ Sign off'; err(e.message); });
    };
  }

  function tick() {
    var id = quoteIdFromPath();
    if (id !== current) { current = id; remove(); if (id) load(id); }
  }
  // the app is a single-page app: watch for route changes, and re-check when the page comes back into view (after the PDF / build-up)
  setInterval(tick, 700);
  document.addEventListener('visibilitychange', function () { if (!document.hidden && current) load(current); });
  window.addEventListener('pageshow', function () { if (current) load(current); });
  tick();
})();
