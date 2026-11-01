// Build Bro Hub — "Send to customer" for quotes. Everything goes through the hub: the director sees and can edit the exact
// text/email, then public.send_quote queues it (Twilio text from the company's number, branded email from info@) and only
// then marks the quote sent. No share sheet. Errors are always shown on screen.
(function () {
  var REST = 'https://bjkjmdqytughwczpzgac.supabase.co/rest/v1';
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
  function call(method, path, body) {
    var s = session();
    if (!s) return Promise.reject(new Error('You’re signed out. Sign in again and retry.'));
    return fetch(REST + path, {
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
  function prettyPhone(p) { p = String(p || ''); return p.indexOf('+44') === 0 ? '0' + p.slice(3).replace(/^(\d{4})(\d{3})(\d+)$/, '$1 $2 $3') : p; }

  var CSS = '#hub-qs{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);display:flex;align-items:flex-end;justify-content:center;font:15px Barlow,Arial,sans-serif}' +
    '#hub-qs .s{background:#1B1F23;color:#F1F1EE;width:100%;max-width:560px;max-height:92vh;overflow:auto;border-radius:16px 16px 0 0;padding:18px 16px calc(18px + env(safe-area-inset-bottom));box-sizing:border-box}' +
    '#hub-qs h3{margin:0 0 4px;font-size:19px}#hub-qs .m{color:#A3A8AE;font-size:13px;margin:0 0 12px}' +
    '#hub-qs label.c{display:flex;gap:8px;align-items:center;font-weight:600;margin:14px 0 6px}' +
    '#hub-qs textarea,#hub-qs input[type=text]{width:100%;box-sizing:border-box;background:#0F1215;color:#F1F1EE;border:1px solid #3A4048;border-radius:8px;padding:10px;font:15px Barlow,Arial,sans-serif}' +
    '#hub-qs textarea{min-height:120px;resize:vertical}#hub-qs input[type=text]{margin-bottom:6px}' +
    '#hub-qs .e{background:#4A1D1D;border:1px solid #B33;color:#FFD6D6;border-radius:8px;padding:10px;margin:12px 0 0}' +
    '#hub-qs .w{background:#3D3215;border:1px solid #C9A227;color:#FFE9A8;border-radius:8px;padding:12px;margin:8px 0}' +
    '#hub-qs .r{display:flex;gap:8px;margin-top:16px}#hub-qs button{flex:1;border:0;border-radius:10px;padding:13px;font:600 15px Barlow,Arial,sans-serif;cursor:pointer}' +
    '#hub-qs .p{background:#2F6FEB;color:#fff}#hub-qs .p:disabled{opacity:.5}#hub-qs .x{background:#2E343B;color:#F1F1EE}#hub-qs a.b{display:block;text-align:center;background:#2F6FEB;color:#fff;border-radius:10px;padding:12px;margin-top:10px;text-decoration:none;font-weight:600}';

  function sheet(html) {
    var old = document.getElementById('hub-qs'); if (old) old.remove();
    if (!document.getElementById('hub-qs-css')) { var st = document.createElement('style'); st.id = 'hub-qs-css'; st.textContent = CSS; document.head.appendChild(st); }
    var d = document.createElement('div'); d.id = 'hub-qs'; d.innerHTML = '<div class="s">' + html + '</div>';
    document.body.appendChild(d); return d;
  }

  window.hubSendQuote = function (quoteId, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var done = function (v) { var d = document.getElementById('hub-qs'); if (d) d.remove(); resolve(v); };
      var box = sheet('<h3>' + (opts.again ? 'Send the link again' : 'Send to customer') + '</h3><p class="m">Loading…</p><div class="r"><button class="x">Cancel</button></div>');
      box.querySelector('.x').onclick = function () { done(false); };
      var s = session();
      Promise.all([
        call('GET', '/quotes?id=eq.' + quoteId + '&select=id,ref,title,status,public_token,qb_hold,qb_signed_off_at,job_id,job:jobs(title,customer:customers(first_name,display_name,phone,email,sms_opted_out_at)),company:companies(code,name,phone)'),
        s ? call('GET', '/profiles?id=eq.' + s.user.id + '&select=full_name') : Promise.resolve([])
      ]).then(function (res) {
        var q = res[0] && res[0][0];
        if (!q) throw new Error('This quote couldn’t be found. Reload and try again.');
        var co = q.company || {}, job = q.job || {}, cu = job.customer || {};
        var first = (cu.first_name || cu.display_name || '').split(' ')[0] || 'there';
        var me = ((res[1] && res[1][0] && res[1][0].full_name) || '').split(' ')[0] || 'the team';
        var shortName = co.code === 'MKD' ? 'MK Driveways' : 'Rutland Driveways';
        var link = location.origin + '/q/' + q.public_token;
        var what = (q.title || job.title || 'your work').replace(/^./, function (c) { return c.toLowerCase(); });
        var phone = co.phone || '';

        if (q.qb_hold && !q.qb_signed_off_at) {
          box.querySelector('.s').innerHTML = '<h3>Waiting for sign-off</h3><div class="w">This quote was made by the quote builder and must be checked and signed off by a director before it can go to the customer. Sign it off in the Quote builder first.</div>' +
            '<a class="b" href="/quote-builder/?job=' + esc(q.job_id) + '">Open the Quote builder</a><div class="r"><button class="x">Close</button></div>';
          box.querySelector('.x').onclick = function () { done(false); };
          return;
        }
        var canSms = !!cu.phone && !cu.sms_opted_out_at, canEmail = !!cu.email;
        var sms = 'Hi ' + first + ', it’s ' + me + ' from ' + shortName + '. Your proposal for ' + what + ' is ready. You can read it and accept it here: ' + link + '\nAny questions, just reply or call ' + phone + '. Thanks, ' + me;
        var mail = 'Hi ' + first + ',\n\nThank you for having us round. Your proposal for ' + what + ' is ready. You can read it in full and accept it here:\n\n' + link +
          '\n\nIf you have any questions, or would like anything changed, just let me know.\n\nKind regards,\n' + me + '\n' + (co.name || shortName) + (phone ? '\n' + phone : '');
        var html = '<h3>' + (opts.again ? 'Send the link again' : 'Send to customer') + '</h3><p class="m">' + esc(q.ref) + ' · ' + esc(cu.display_name || '') + '. Check the wording — this is exactly what they’ll get.</p>';
        html += '<label class="c"><input type="checkbox" id="qs-s"' + (canSms ? ' checked' : ' disabled') + '> Text ' + (cu.phone ? esc(prettyPhone(cu.phone)) : '(no mobile on file)') + (cu.sms_opted_out_at ? ' — opted out' : '') + '</label>';
        if (canSms) html += '<textarea id="qs-st">' + esc(sms) + '</textarea>';
        html += '<label class="c"><input type="checkbox" id="qs-e"' + (canEmail ? ' checked' : ' disabled') + '> Email ' + (cu.email ? esc(cu.email) : '(no email on file)') + '</label>';
        if (canEmail) html += '<input type="text" id="qs-es" value="' + esc('Your proposal from ' + (co.name || shortName)) + '"><textarea id="qs-et" style="min-height:200px">' + esc(mail) + '</textarea>';
        if (!canSms && !canEmail) html += '<div class="e">There’s no mobile or email on file for this customer, so nothing can be sent. Add one to the customer first.</div>';
        html += '<div id="qs-err"></div><div class="r"><button class="x">Cancel</button><button class="p"' + (canSms || canEmail ? '' : ' disabled') + '>Send</button></div>';
        box.querySelector('.s').innerHTML = html;
        box.querySelector('.x').onclick = function () { done(false); };
        var btn = box.querySelector('.p');
        btn.onclick = function () {
          var useS = canSms && box.querySelector('#qs-s').checked, useE = canEmail && box.querySelector('#qs-e').checked;
          var err = box.querySelector('#qs-err'); err.innerHTML = '';
          if (!useS && !useE) { err.innerHTML = '<div class="e">Tick the text or the email.</div>'; return; }
          btn.disabled = true; btn.textContent = 'Sending…';
          call('POST', '/rpc/send_quote', {
            p_quote: q.id,
            p_sms: useS ? box.querySelector('#qs-st').value : null,
            p_email_subject: useE ? box.querySelector('#qs-es').value : null,
            p_email_body: useE ? box.querySelector('#qs-et').value : null
          }).then(function (r) {
            var parts = [];
            if (r && r.sms) parts.push('text to ' + prettyPhone(r.phone));
            if (r && r.email) parts.push('email to ' + r.email);
            box.querySelector('.s').innerHTML = '<h3>Sent ✓</h3><p class="m">Queued through the hub: ' + esc(parts.join(' and ')) + '. It goes out within a minute and shows in the job’s History.</p><div class="r"><button class="p">Done</button></div>';
            box.querySelector('.p').onclick = function () { done(true); };
          }).catch(function (e) {
            btn.disabled = false; btn.textContent = 'Send';
            err.innerHTML = '<div class="e">Not sent: ' + esc(e.message) + '</div>';
            try { console.error('send_quote', e); } catch (x) {}
          });
        };
      }).catch(function (e) {
        box.querySelector('.s').innerHTML = '<h3>Couldn’t open send</h3><div class="e">' + esc(e.message) + '</div><div class="r"><button class="x">Close</button></div>';
        box.querySelector('.x').onclick = function () { done(false); };
      });
    });
  };
})();
