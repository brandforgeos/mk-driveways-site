// Build Bro Hub — shares this phone's location with the hub while the app is open, so 'time to leave'
// reminders for quote visits work out the drive from where you actually are. Only when signed in and
// switched on (More › Notifications on my phone › Travel reminders); at most every few minutes;
// nothing is sent while the app is closed or in the background.
(function () {
  var URL_ = 'https://bjkjmdqytughwczpzgac.supabase.co/rest/v1/rpc/travel_ping';
  var KEY = 'sb_publishable_Ct4SslgkSqBc5w1IUvWSMg_fvHw7zt4';
  var FLAG = 'hub-travel';           // 'on' | 'off' | unset
  var LAST = 'hub-travel-last';      // ms of last successful share
  var IOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
  var last = 0, timer = null, perm = 'prompt', busy = false, msg = '';

  function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function set(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} }
  function session() {
    try {
      for (var i = 0; i < localStorage.length; i++) {
        var k = localStorage.key(i);
        if (k && k.indexOf('sb-') === 0 && k.slice(-11) === '-auth-token') {
          var s = JSON.parse(localStorage.getItem(k) || 'null');
          if (s && s.access_token && (!s.expires_at || s.expires_at * 1000 > Date.now())) return s.access_token;
        }
      }
    } catch (e) {}
    return null;
  }
  function enabled() { return get(FLAG) === 'on' || (get(FLAG) !== 'off' && perm === 'granted'); }

  function share(force, done) {
    done = done || function () {};
    if (!('geolocation' in navigator)) { done('unsupported'); return; }
    var token = session();
    if (!token) { done('signin'); return; }
    if (!force && (document.visibilityState !== 'visible' || Date.now() - last < 150000)) { done('skip'); return; }
    last = Date.now();
    navigator.geolocation.getCurrentPosition(function (p) {
      perm = 'granted';
      fetch(URL_, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', apikey: KEY, Authorization: 'Bearer ' + token },
        body: JSON.stringify({ p_lat: p.coords.latitude, p_lng: p.coords.longitude, p_accuracy: p.coords.accuracy })
      }).then(function (r) {
        if (r.ok) { set(LAST, String(Date.now())); done('ok'); } else done('server');
      }).catch(function () { done('offline'); });
    }, function (err) {
      if (err && err.code === 1) { perm = 'denied'; done('denied'); } else done('nofix');
    }, { enableHighAccuracy: false, maximumAge: force ? 0 : 120000, timeout: 20000 });
  }
  function ping() { if (enabled()) share(false); }

  // ---- Settings card on More › Notifications on my phone ----
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text) e.textContent = text; return e; }
  function when(ms) { return new Date(ms).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); }
  function render(box) {
    box.innerHTML = '';
    var on = enabled(), lastOk = Number(get(LAST) || 0);
    if (perm === 'denied') {
      box.appendChild(el('div', 'title', 'Location is blocked for the hub'));
      box.appendChild(el('p', 'small muted', IOS
        ? 'Go to Settings › Privacy & Security › Location Services › Safari Websites and choose “While Using the App”, then come back and tap Turn on.'
        : 'Tap the padlock (or ⋮ › Settings › Site settings) for hub.buildbro.app, allow Location, then come back and tap Turn on.'));
    } else if (on) {
      box.appendChild(el('div', 'title', 'On for this phone'));
      box.appendChild(el('p', 'small muted', lastOk ? 'Last shared ' + when(lastOk) + '.' : 'Waiting for the first location…'));
    } else {
      box.appendChild(el('div', 'title', 'Off for this phone'));
      box.appendChild(el('p', 'small muted', 'Turn on, then tap Allow when your phone asks. Until then, drive times are worked out from your home postcode.'));
    }
    var row = el('div'); row.style.cssText = 'display:flex;gap:8px;margin-top:10px';
    var b1 = el('button', on ? 'btn' : 'btn primary', busy ? 'Getting location…' : (on ? 'Share now' : 'Turn on'));
    b1.disabled = busy;
    b1.onclick = function () {
      busy = true; msg = ''; render(box);
      share(true, function (r) {
        busy = false;
        if (r === 'ok') { set(FLAG, 'on'); msg = 'Location shared — reminders will use where you are.'; }
        else if (r === 'denied') msg = '';
        else if (r === 'signin') msg = 'Sign in to the hub first.';
        else if (r === 'unsupported') msg = 'This browser can’t share location.';
        else if (r === 'nofix') { set(FLAG, 'on'); msg = 'Couldn’t get a fix just now — it’ll try again in a few minutes.'; }
        else msg = 'Couldn’t reach the hub — check your signal and try again.';
        render(box);
      });
    };
    row.appendChild(b1);
    if (on) {
      var b2 = el('button', 'btn', 'Turn off here');
      b2.onclick = function () { set(FLAG, 'off'); msg = ''; render(box); };
      row.appendChild(b2);
    }
    box.appendChild(row);
    if (msg) { var m = el('p', 'small', msg); m.style.marginBottom = '0'; box.appendChild(m); }
  }
  function mount() {
    if (location.pathname !== '/settings/notifications' || document.getElementById('bb-travel')) return;
    var heads = document.querySelectorAll('h3'), anchor = null;
    for (var i = 0; i < heads.length; i++) if (/What to notify me about/.test(heads[i].textContent)) { anchor = heads[i]; break; }
    if (!anchor) return;
    var wrap = el('div'); wrap.id = 'bb-travel';
    wrap.appendChild(el('h3', '', 'Travel reminders'));
    var box = el('div', 'sheet'); box.style.padding = '16px';
    wrap.appendChild(box);
    wrap.appendChild(el('p', 'small muted', '“Leave in 30 min” and “Time to leave” for quote visits, using your location while the hub is open.'));
    anchor.parentNode.insertBefore(wrap, anchor);
    render(box);
  }

  function start() {
    try {
      if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'geolocation' }).then(function (s) {
          perm = s.state; s.onchange = function () { perm = s.state; };
        }).catch(function () {});
      }
    } catch (e) {}
    setTimeout(ping, 4000);                       // let the app sign in first
    if (!timer) timer = setInterval(ping, 180000);
    new MutationObserver(mount).observe(document.body, { childList: true, subtree: true });
    mount();
  }
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { last = 0; setTimeout(ping, 1500); } });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
