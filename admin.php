<?php
/* We Buy Scrap Cars Corby: admin for George. Included from index.php at /admin/ (direct access is blocked in .htaccess).
   Rates, leads, enquiries, kerb weights, reminders and password. Data lives in STORE (outside the web root). */
declare(strict_types=1);
if (!defined('SC_APP')) { http_response_code(403); exit; }

header('X-Robots-Tag: noindex, nofollow'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
ini_set('session.gc_maxlifetime', '2592000'); ini_set('session.use_strict_mode', '1');
session_save_path(store_path('sessions'));
session_name('scadmin');
session_set_cookie_params(['lifetime' => 2592000, 'path' => rtrim(BASE, '/') . '/admin', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$A = u('admin');
$USERS_FILE = store_path('keys') . '/admin-users.json';
$users = is_file($USERS_FILE) ? (json_decode((string)file_get_contents($USERS_FILE), true) ?: []) : [];
if (!$users && !empty($CFG['admin_users']) && is_array($CFG['admin_users'])) { $users = $CFG['admin_users']; save_json($USERS_FILE, $users); }
$me = (isset($_SESSION['u'], $users[$_SESSION['u']]) && ($_SESSION['pv'] ?? '') === substr($users[$_SESSION['u']]['hash'], -12)) ? $_SESSION['u'] : null;
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$STATUS = [
  'accepted' => ['Accepted: call to book', '#1d7a3a', '#e3f6e8'],
  'call-back' => ['Call back with a price', '#8a5a00', '#fff3d6'],
  'saved' => ['Price emailed', '#1f4fa3', '#e4edfb'],
  'booked' => ['Booked in', '#10224A', '#dfe5f2'],
  'collected' => ['Collected', '#0d5c27', '#cdeed7'],
  'lost' => ['Lost / declined', '#9b2226', '#fbe4e4'],
  'priced' => ['Price only (no contact)', '#51607d', '#eef1f6'],
  'needs-price' => ['No price (no contact)', '#51607d', '#eef1f6'],
];
$SETTABLE = ['accepted', 'call-back', 'saved', 'booked', 'collected', 'lost'];
$FILTERS = ['action' => ['To do', ['accepted', 'call-back', 'saved']], 'booked' => ['Booked', ['booked']], 'collected' => ['Collected', ['collected']], 'lost' => ['Lost', ['lost']],
  'contacts' => ['All with details', ['accepted', 'call-back', 'saved', 'booked', 'collected', 'lost']], 'all' => ['Every price given', array_keys($STATUS)]];

function adm_go(string $url, string $flash = '', bool $bad = false): void { if ($flash !== '') $_SESSION['flash'] = [$flash, $bad]; header('Location: ' . $url, true, 303); exit; }
function adm_leads(): array {
  $out = [];
  foreach (glob(STORE . '/leads/*.json') ?: [] as $f) { $l = json_decode((string)file_get_contents($f), true); if ($l && !empty($l['ref'])) $out[] = $l; }
  usort($out, fn($a, $b) => strcmp($b['received'] ?? '', $a['received'] ?? ''));
  return $out;
}
function adm_settings_save(array $SET): bool { return save_json(STORE . '/settings.json', $SET); }
function adm_when(?string $iso): string {
  if (!$iso) return ''; $t = strtotime($iso); $d = time() - $t;
  if ($d < 3600) return max(1, (int)floor($d / 60)) . ' min ago';
  if (date('Y-m-d', $t) === date('Y-m-d')) return 'Today ' . date('H:i', $t);
  if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday ' . date('H:i', $t);
  return date('D j M, H:i', $t);
}
function adm_tel(string $p): string { $d = preg_replace('/\D/', '', $p); return str_starts_with($d, '0') ? '+44' . substr($d, 1) : '+' . $d; }
function adm_badge(array $STATUS, string $s): string { $x = $STATUS[$s] ?? [$s, '#51607d', '#eef1f6']; return '<span class="badge" style="color:' . $x[1] . ';background:' . $x[2] . '">' . h($x[0]) . '</span>'; }

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = (string)($_POST['act'] ?? '');
  if ($act === 'login') {
    if (!rate_ok('adminlogin', 10)) adm_go($A, 'Too many attempts. Try again in an hour.', true);
    $un = strtolower(trim((string)($_POST['user'] ?? ''))); $pw = (string)($_POST['pass'] ?? '');
    if (isset($users[$un]) && password_verify($pw, $users[$un]['hash'])) {
      session_regenerate_id(true); $_SESSION['u'] = $un; $_SESSION['pv'] = substr($users[$un]['hash'], -12); $_SESSION['csrf'] = bin2hex(random_bytes(16));
      @file_put_contents(store_path('logs') . '/admin.log', date('c') . " login $un " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n", FILE_APPEND | LOCK_EX);
      adm_go($A);
    }
    @file_put_contents(store_path('logs') . '/admin.log', date('c') . " failed login '$un' " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n", FILE_APPEND | LOCK_EX);
    adm_go($A, 'That username or password isn\'t right.', true);
  }
  if (!$me || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) adm_go($A, 'Please log in again.', true);
  $back = (string)($_POST['back'] ?? $A); if (!str_starts_with($back, $A)) $back = $A;

  if ($act === 'logout') { $_SESSION = []; session_destroy(); adm_go($A, 'Logged out.'); }

  if ($act === 'lead') {
    $l = load_lead((string)($_POST['ref'] ?? '')); if (!$l) adm_go($back, 'Lead not found.', true);
    $chg = [];
    $st = (string)($_POST['status'] ?? ''); if (in_array($st, $SETTABLE, true) && $st !== $l['status']) { $chg[] = "status {$l['status']} → $st"; $l['status'] = $st; }
    $fp = trim((string)($_POST['final_price'] ?? '')); $fpv = $fp === '' ? null : (int)round((float)$fp);
    if ($fpv !== ($l['final_price'] ?? null)) { $chg[] = 'agreed price ' . ($fpv === null ? 'cleared' : "£$fpv"); $l['final_price'] = $fpv; }
    $cd = (string)($_POST['collect_on'] ?? ''); $cd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $cd) ? $cd : '';
    if ($cd !== ($l['collect_on'] ?? '')) { $chg[] = 'collection ' . ($cd ?: 'cleared'); $l['collect_on'] = $cd; }
    $nt = mb_substr(trim((string)($_POST['admin_note'] ?? '')), 0, 2000); if ($nt !== ($l['admin_note'] ?? '')) { $chg[] = 'notes'; $l['admin_note'] = $nt; }
    if ($chg) { $l['history'][] = ['at' => date('c'), 'by' => $me, 'what' => implode(', ', $chg)]; save_lead($l); }
    adm_go($back, $chg ? 'Saved.' : 'No changes.');
  }

  if ($act === 'rates') {
    $num = fn($k) => trim((string)($_POST[$k] ?? ''));
    $p = ['rate_per_tonne' => max(0, round((float)$num('rate_per_tonne'), 2)), 'max_miles' => max(1, (int)$num('max_miles')), 'valid_days' => min(60, max(1, (int)$num('valid_days'))),
          'default_kg' => min(3500, max(500, (int)$num('default_kg'))), 'round_to' => in_array((int)$num('round_to'), [1, 5, 10], true) ? (int)$num('round_to') : 5,
          'min_price' => $num('min_price') === '' ? null : max(0, (int)$num('min_price')), 'max_price' => $num('max_price') === '' ? null : max(0, (int)$num('max_price'))];
    if ($p['rate_per_tonne'] <= 0) adm_go($A . '?v=rates', 'The rate per tonne needs to be more than £0.', true);
    $old = $PR; $SET['pricing'] = $p;
    if (!adm_settings_save($SET)) adm_go($A . '?v=rates', 'Couldn\'t save. Please tell Lewis.', true);
    $diff = []; foreach ($p as $k => $v) if (($old[$k] ?? null) != $v) $diff[] = "$k " . json_encode($old[$k] ?? null) . ' → ' . json_encode($v);
    if ($diff) @file_put_contents(store_path('logs') . '/rates.log', date('c') . " $me: " . implode(', ', $diff) . "\n", FILE_APPEND | LOCK_EX);
    adm_go($A . '?v=rates', $diff ? 'Rates saved. New prices use them straight away.' : 'No changes.');
  }

  if ($act === 'weight') {
    $key = preg_replace('/[^a-z0-9-]/', '', (string)($_POST['key'] ?? '')); $f = STORE . "/weights/$key.json"; $kg = (int)($_POST['kg'] ?? 0);
    if ($key === '' || !is_file($f)) adm_go($A . '?v=rates#weights', 'Weight not found.', true);
    if ($kg < 500 || $kg > 3500) adm_go($A . '?v=rates#weights', 'Weight must be between 500 and 3,500 kg.', true);
    $w = json_decode((string)file_get_contents($f), true) ?: [];
    $w += ['was_kg' => $w['kg'] ?? null]; $w['kg'] = $kg; $w['source'] = 'Set by ' . ucfirst($me); $w['confidence'] = 'high'; $w['at'] = date('c');
    save_json($f, $w);
    adm_go($A . '?v=rates#weights', 'Weight updated. Future prices for that model use ' . number_format($kg) . ' kg.');
  }

  if ($act === 'settings') {
    $SET['reminders'] = ['on' => !empty($_POST['rem_on']), 'days_before' => min(6, max(0, (int)($_POST['days_before'] ?? 2)))];
    $ne = trim((string)($_POST['notify_email'] ?? '')); if ($ne !== '' && !filter_var($ne, FILTER_VALIDATE_EMAIL)) adm_go($A . '?v=settings', 'That email address doesn\'t look right.', true);
    $SET['notify_email'] = $ne;
    adm_go($A . '?v=settings', adm_settings_save($SET) ? 'Settings saved.' : 'Couldn\'t save. Please tell Lewis.', false);
  }

  if ($act === 'test_email') {
    [$t, $hh] = customer_email($S, 'Test email from your website', ['This is a test from the We Buy Scrap Cars Corby admin page.', 'If you can read this, lead alerts will reach this inbox. Check your spam folder if alerts ever go missing.'], [['Sent', date('j F Y, H:i')], ['Sent by', ucfirst($me)]]);
    adm_go($A . '?v=settings', send_mail($NOTIFY, 'Test email from your website', $t, $hh) ? "Test email sent to $NOTIFY. Check your inbox (and spam)." : 'The server wouldn\'t send it. Please tell Lewis.', false);
  }

  if ($act === 'remind_now') {
    $res = run_reminders($S, $REM);
    adm_go($A . '?v=settings', $res['skipped'] !== 'no' ? 'Reminders not sent: ' . $res['skipped'] . '.' : "Checked {$res['checked']} price(s), sent {$res['sent']} reminder(s).");
  }

  if ($act === 'password') {
    $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['new'] ?? ''); $conf = (string)($_POST['confirm'] ?? '');
    if (!password_verify($cur, $users[$me]['hash'])) adm_go($A . '?v=settings', 'Your current password isn\'t right.', true);
    if (strlen($new) < 10) adm_go($A . '?v=settings', 'Use at least 10 characters for the new password.', true);
    if ($new !== $conf) adm_go($A . '?v=settings', 'The two new passwords don\'t match.', true);
    $users[$me]['hash'] = password_hash($new, PASSWORD_DEFAULT); $users[$me]['changed'] = date('c'); save_json($USERS_FILE, $users);
    $_SESSION['pv'] = substr($users[$me]['hash'], -12);
    adm_go($A . '?v=settings', 'Password changed.');
  }
  adm_go($A);
}

/* ---------- CSV export ---------- */
$v = (string)($_GET['v'] ?? 'leads');
if ($me && $v === 'export') {
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="scrap-car-leads-' . date('Y-m-d') . '.csv"');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
  fputcsv($o, ['Ref', 'Received', 'Status', 'Reg', 'Vehicle', 'Postcode', 'Miles', 'Weight kg', 'Weight source', 'Price', 'Agreed price', 'Collection date', 'Name', 'Phone', 'Email', 'Reminders OK', 'Customer note', 'Admin notes']);
  foreach (adm_leads() as $l) fputcsv($o, [$l['ref'], $l['received'], $l['status'], $l['reg'], vehicle_name($l['vehicle'] ?? null), $l['postcode'], $l['miles'], $l['weight_kg'], $l['weight_source'], $l['price'], $l['final_price'] ?? '', $l['collect_on'] ?? '', $l['name'] ?? '', $l['phone'] ?? '', $l['email'] ?? '', !empty($l['reminders_ok']) ? 'yes' : 'no', $l['collection_note'] ?? '', $l['admin_note'] ?? '']);
  exit;
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$self = $_SERVER['REQUEST_URI'] ?? $A;
$hidden = '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="back" value="' . h($self) . '">';
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Admin | We Buy Scrap Cars Corby</title>
<link rel="icon" href="<?= BASE ?>img/favicon-48.png" sizes="48x48">
<style>
:root{--navy:#10224A;--deep:#0A1733;--green:#3FBF5F;--line:#dde3ee;--muted:#51607d;--bg:#eef1f6;--red:#9b2226}
*{box-sizing:border-box}
body{margin:0;font:16px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:var(--navy);background:var(--bg)}
a{color:var(--navy)}
.top{background:var(--deep);color:#fff;position:sticky;top:0;z-index:5}
.top-in{max-width:1100px;margin:0 auto;padding:10px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.top img{width:36px;height:36px}
.top b{font-size:17px}
.top .who{margin-left:auto;font-size:14px;color:#b4c3e2;display:flex;gap:10px;align-items:center}
.top .who button{background:none;border:1px solid #4a5d86;color:#fff;border-radius:8px;padding:6px 10px;font:inherit;font-size:14px;cursor:pointer}
.tabs{max-width:1100px;margin:0 auto;padding:0 16px;display:flex;gap:4px;overflow-x:auto}
.tabs a{color:#cfd9ee;text-decoration:none;padding:10px 14px;border-bottom:3px solid transparent;white-space:nowrap;font-weight:600}
.tabs a.on{color:#fff;border-color:var(--green)}
main{max-width:1100px;margin:0 auto;padding:18px 16px 60px}
h1{font-size:24px;margin:4px 0 14px}
h2{font-size:19px;margin:26px 0 10px}
.flash{padding:12px 14px;border-radius:10px;margin-bottom:14px;background:#e3f6e8;color:#0d5c27;font-weight:600}
.flash.bad{background:#fbe4e4;color:var(--red)}
.box{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:14px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px}
.stat{background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px}
.stat b{display:block;font-size:26px;line-height:1.1}
.stat span{font-size:14px;color:var(--muted)}
.pills{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.pills a{padding:7px 12px;border-radius:999px;background:#fff;border:1px solid var(--line);text-decoration:none;font-size:15px;font-weight:600}
.pills a.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.pills a i{font-style:normal;opacity:.7;margin-left:4px}
.search{display:flex;gap:8px;margin-bottom:12px}
.search input{flex:1}
.lead{display:block;background:#fff;border:1px solid var(--line);border-radius:12px;padding:12px 14px;margin-bottom:8px;text-decoration:none}
.lead:hover{border-color:#9fb0d0}
.lead-top{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.lead .price{margin-left:auto;font-size:20px;font-weight:800}
.lead .meta{font-size:14px;color:var(--muted);margin-top:4px}
.plate{display:inline-block;background:#FFD200;color:#111;font-weight:800;letter-spacing:.04em;padding:2px 8px;border-radius:4px;font-size:15px;border:1px solid #c9a700}
.badge{display:inline-block;font-size:13px;font-weight:700;padding:3px 9px;border-radius:999px}
label{display:block;font-weight:600;font-size:15px}
input,select,textarea{display:block;width:100%;margin-top:4px;padding:11px 12px;border:1px solid #b9c4da;border-radius:9px;font:inherit;color:inherit;background:#fff;min-height:46px}
textarea{min-height:90px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.hint{font-size:14px;color:var(--muted);font-weight:400;margin-top:4px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:46px;padding:10px 18px;border-radius:10px;border:2px solid var(--navy);background:var(--navy);color:#fff;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}
.btn.go{background:var(--green);border-color:var(--green);color:#06210F}
.btn.ghost{background:#fff;color:var(--navy)}
.acts{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}
dl.kv{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;margin:0}
dl.kv dt{color:var(--muted);font-size:15px}
dl.kv dd{margin:0;font-weight:600;word-break:break-word}
table{width:100%;border-collapse:collapse;font-size:15px}
th,td{text-align:left;padding:8px 6px;border-top:1px solid var(--line);vertical-align:middle}
th{font-size:13px;color:var(--muted);border-top:0}
.wform{display:flex;gap:6px;align-items:center}
.wform input{width:96px;min-height:40px;padding:6px 8px;margin:0}
.wform .btn{min-height:40px;padding:6px 12px}
.ex{font-variant-numeric:tabular-nums}
.small{font-size:14px;color:var(--muted)}
.empty{padding:30px 14px;text-align:center;color:var(--muted)}
.login{max-width:380px;margin:9vh auto 0;padding:0 16px}
.login .box{padding:22px}
.login .brand{display:flex;gap:10px;align-items:center;margin-bottom:16px}
.login .brand img{width:52px;height:52px}
.pre{white-space:pre-wrap;font:13px/1.4 ui-monospace,Menlo,Consolas,monospace;background:#f5f7fb;border-radius:8px;padding:10px;max-height:240px;overflow:auto}
.tick{display:flex;gap:10px;align-items:center;font-weight:600}
.tick input{width:22px;height:22px;min-height:0;margin:0}
.st-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px}
@media (max-width:600px){dl.kv{grid-template-columns:1fr}dl.kv dt{margin-top:6px}.hide-m{display:none}}
</style>
</head>
<body>
<?php if (!$me): ?>
<div class="login">
  <div class="brand"><img src="<?= BASE ?>img/logo-mark.svg" alt=""><div><b>We Buy Scrap Cars Corby</b><div class="small">Admin</div></div></div>
  <?php if ($flash): ?><div class="flash<?= $flash[1] ? ' bad' : '' ?>"><?= h($flash[0]) ?></div><?php endif; ?>
  <?php if (!$users): ?><div class="box">Admin isn't set up on this server yet. Ask Lewis to add the login.</div>
  <?php else: ?>
  <form class="box" method="post" action="<?= h($A) ?>">
    <input type="hidden" name="act" value="login">
    <label>Username<input name="user" autocomplete="username" autocapitalize="none" required></label><br>
    <label>Password<input name="pass" type="password" autocomplete="current-password" required></label><br>
    <button class="btn go" type="submit" style="width:100%">Log in</button>
  </form>
  <?php endif; ?>
</div>
</body></html>
<?php return; endif;

$tabs = ['leads' => 'Leads', 'enquiries' => 'Enquiries', 'rates' => 'Rates', 'settings' => 'Settings'];
$tabOn = $v === 'lead' ? 'leads' : $v;
?>
<header class="top">
  <div class="top-in"><img src="<?= BASE ?>img/logo-mark.svg" alt=""><b>Scrap Cars admin</b>
    <form class="who" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="logout"><span class="hide-m"><?= h(ucfirst($me)) ?></span><a href="<?= h(u()) ?>" style="color:#b4c3e2">View site</a><button type="submit">Log out</button></form></div>
  <nav class="tabs"><?php foreach ($tabs as $k => $t): ?><a href="<?= h($A . '?v=' . $k) ?>" class="<?= $tabOn === $k ? 'on' : '' ?>"><?= h($t) ?></a><?php endforeach; ?></nav>
</header>
<main>
<?php if ($flash): ?><div class="flash<?= $flash[1] ? ' bad' : '' ?>" role="status"><?= h($flash[0]) ?></div><?php endif; ?>
<?php if (PREVIEW): ?><div class="box small" style="border-left:5px solid #FFD200">Preview mode: the site isn't live yet, so no emails go to customers and lead alerts aren't sent. Everything else here works.</div><?php endif; ?>

<?php
/* ================= LEADS ================= */
if ($v === 'leads'):
  $all = adm_leads();
  $f = is_string($_GET['f'] ?? null) && isset($FILTERS[$_GET['f']]) ? $_GET['f'] : 'action';
  $q = trim((string)($_GET['q'] ?? ''));
  $count = fn(array $sts) => count(array_filter($all, fn($l) => in_array($l['status'], $sts, true)));
  $since = fn(int $days) => array_filter($all, fn($l) => strtotime($l['received']) >= strtotime("-$days days"));
  $wk = $since(7); $mo = $since(30);
  $acc = fn($set) => count(array_filter($set, fn($l) => !empty($l['accepted'])));
  $col = array_filter($mo, fn($l) => $l['status'] === 'collected');
  $list = array_values(array_filter($all, function ($l) use ($FILTERS, $f, $q) {
    if (!in_array($l['status'], $FILTERS[$f][1], true)) return false;
    if ($q === '') return true;
    $hay = strtolower(implode(' ', [$l['ref'], $l['reg'], $l['postcode'], $l['name'] ?? '', $l['phone'] ?? '', $l['email'] ?? '', vehicle_name($l['vehicle'] ?? null)]));
    return str_contains(preg_replace('/\s/', '', $hay), preg_replace('/\s/', '', strtolower($q))) || str_contains($hay, strtolower($q));
  }));
  $page = max(1, (int)($_GET['p'] ?? 1)); $per = 50; $pages = max(1, (int)ceil(count($list) / $per));
?>
  <h1>Leads</h1>
  <div class="stats">
    <div class="stat"><b><?= $count(['accepted', 'call-back']) ?></b><span>Waiting for a call</span></div>
    <div class="stat"><b><?= count($wk) ?></b><span>Prices given, last 7 days</span></div>
    <div class="stat"><b><?= $acc($wk) ?></b><span>Accepted, last 7 days</span></div>
    <div class="stat"><b><?= count($col) ?></b><span>Collected, last 30 days</span></div>
  </div>
  <div class="pills"><?php foreach ($FILTERS as $k => $x): ?><a href="<?= h($A . '?v=leads&f=' . $k) ?>" class="<?= $f === $k ? 'on' : '' ?>"><?= h($x[0]) ?><i><?= $count($x[1]) ?></i></a><?php endforeach; ?></div>
  <form class="search" method="get" action="<?= h($A) ?>"><input type="hidden" name="v" value="leads"><input type="hidden" name="f" value="<?= h($f) ?>"><input name="q" value="<?= h($q) ?>" placeholder="Search reg, name, phone, postcode" aria-label="Search leads"><button class="btn" type="submit">Search</button></form>
  <?php if (!$list): ?><div class="box empty"><?= $f === 'action' ? 'Nothing to do right now. New accepted prices show up here.' : 'No leads here yet.' ?></div><?php endif; ?>
  <?php foreach (array_slice($list, ($page - 1) * $per, $per) as $l): $veh = vehicle_name($l['vehicle'] ?? null); ?>
    <a class="lead" href="<?= h($A . '?v=lead&ref=' . $l['ref']) ?>">
      <div class="lead-top"><span class="plate"><?= h($l['reg']) ?></span><?= adm_badge($STATUS, $l['status']) ?><?php if (!empty($l['demo'])): ?><span class="badge" style="background:#fff3d6;color:#8a5a00">Test</span><?php endif; ?>
        <span class="price"><?= isset($l['final_price']) && $l['final_price'] !== null ? '£' . (int)$l['final_price'] : ($l['price'] === null ? '–' : '£' . (int)$l['price']) ?></span></div>
      <div style="margin-top:6px;font-weight:700"><?= h($l['name'] ?? '') ?: '<span class="small">No contact details</span>' ?><?= $veh ? ' <span class="small">· ' . h($veh) . '</span>' : '' ?></div>
      <div class="meta"><?= h(adm_when($l['received'])) ?> · <?= h($l['postcode']) ?><?= $l['miles'] !== null ? ' (' . h((string)$l['miles']) . ' mi)' : '' ?><?= !empty($l['collect_on']) ? ' · Collect ' . h(date('D j M', strtotime($l['collect_on']))) : '' ?> · <?= h($l['ref']) ?></div>
    </a>
  <?php endforeach; ?>
  <?php if ($pages > 1): ?><div class="pills"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'on' : '' ?>" href="<?= h($A . '?v=leads&f=' . $f . '&q=' . rawurlencode($q) . '&p=' . $i) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
  <p class="small" style="margin-top:18px"><a href="<?= h($A . '?v=export') ?>">Download every lead as a spreadsheet (CSV)</a></p>

<?php
/* ================= ONE LEAD ================= */
elseif ($v === 'lead'):
  $l = load_lead((string)($_GET['ref'] ?? ''));
  if (!$l): ?><div class="box empty">Lead not found. <a href="<?= h($A) ?>">Back to leads</a></div><?php else:
  $veh = $l['vehicle'] ?? null; ?>
  <p class="small"><a href="<?= h($A . '?v=leads') ?>">← All leads</a></p>
  <h1><span class="plate" style="font-size:20px"><?= h($l['reg']) ?></span> <?= h(vehicle_name($veh) ?: 'Vehicle not found from reg') ?></h1>
  <p><?= adm_badge($STATUS, $l['status']) ?> <span class="small">Ref <?= h($l['ref']) ?> · priced <?= h(adm_when($l['received'])) ?></span></p>
  <?php if (!empty($l['phone']) || !empty($l['email'])): ?>
  <div class="acts">
    <?php if (!empty($l['phone'])): ?><a class="btn go" href="tel:<?= h(adm_tel($l['phone'])) ?>">Call <?= h($l['phone']) ?></a>
    <a class="btn ghost" href="https://wa.me/<?= h(ltrim(adm_tel($l['phone']), '+')) ?>?text=<?= rawurlencode('Hi ' . trim(explode(' ', (string)($l['name'] ?? ''))[0]) . ', it\'s George from We Buy Scrap Cars Corby about your ' . ($l['reg']) . '.') ?>" rel="noopener">WhatsApp</a><?php endif; ?>
    <?php if (!empty($l['email'])): ?><a class="btn ghost" href="mailto:<?= h($l['email']) ?>?subject=<?= rawurlencode('Your scrap car ' . $l['reg'] . ' (ref ' . $l['ref'] . ')') ?>">Email</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="grid" style="align-items:start">
    <div class="box">
      <h2 style="margin-top:0">Customer</h2>
      <dl class="kv">
        <dt>Name</dt><dd><?= h($l['name'] ?? '') ?: '–' ?></dd>
        <dt>Phone</dt><dd><?= h($l['phone'] ?? '') ?: '–' ?></dd>
        <dt>Email</dt><dd><?= h($l['email'] ?? '') ?: '–' ?></dd>
        <dt>Postcode</dt><dd><?= h($l['postcode']) ?><?= $l['town'] ? ', ' . h($l['town']) : '' ?><?= $l['miles'] !== null ? ' (' . h((string)$l['miles']) . ' miles from Corby)' : '' ?></dd>
        <dt>Their note</dt><dd><?= h($l['collection_note'] ?? '') ?: '–' ?></dd>
        <dt>Reminders</dt><dd><?= !empty($l['reminders_ok']) ? 'Yes' . (!empty($l['reminded']) ? ' (sent ' . h(adm_when($l['reminded'] === 'unsubscribed' ? null : $l['reminded']) ?: 'no: unsubscribed') . ')' : '') : 'No' ?></dd>
        <?php if (!empty($l['accepted'])): ?><dt>Accepted</dt><dd><?= h(adm_when($l['accepted'])) ?></dd><?php endif; ?>
      </dl>
    </div>
    <div class="box">
      <h2 style="margin-top:0">Price</h2>
      <dl class="kv">
        <dt>Price given</dt><dd><?= $l['price'] === null ? 'None: needs a price' : '£' . (int)$l['price'] ?></dd>
        <dt>Held until</dt><dd><?= h(date('j M Y', strtotime($l['valid_until']))) ?></dd>
        <dt>Weight used</dt><dd><?= number_format((int)$l['weight_kg']) ?> kg <span class="small">(<?= h($l['weight_source']) ?>)</span></dd>
        <dt>Rate used</dt><dd>£<?= h((string)$l['rate_per_tonne']) ?> per tonne</dd>
        <?php if ($veh): ?><dt>Vehicle</dt><dd><?= h(implode(' · ', array_filter([vehicle_name($veh), !empty($veh['engine_cc']) ? number_format($veh['engine_cc'] / 1000, 1) . ' litre' : '', $veh['fuel'] ?? '', $veh['colour'] ?? '']))) ?></dd><?php endif; ?>
      </dl>
      <?php if (str_contains((string)$l['weight_source'], 'Claude')): ?><p class="small">Weight looks wrong? <a href="<?= h($A . '?v=rates#weights') ?>">Fix it for this model</a>.</p><?php endif; ?>
    </div>
  </div>
  <form class="box" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="lead"><input type="hidden" name="ref" value="<?= h($l['ref']) ?>">
    <h2 style="margin-top:0">Update</h2>
    <div class="grid">
      <label>Status<select name="status"><?php foreach ($SETTABLE as $s): ?><option value="<?= h($s) ?>"<?= $l['status'] === $s ? ' selected' : '' ?>><?= h($STATUS[$s][0]) ?></option><?php endforeach;
        if (!in_array($l['status'], $SETTABLE, true)): ?><option value="" selected><?= h($STATUS[$l['status']][0] ?? $l['status']) ?></option><?php endif; ?></select></label>
      <label>Collection date<input type="date" name="collect_on" value="<?= h($l['collect_on'] ?? '') ?>"></label>
      <label>Agreed price (£)<input type="number" name="final_price" min="0" step="1" inputmode="numeric" value="<?= h(isset($l['final_price']) ? (string)$l['final_price'] : '') ?>" placeholder="<?= $l['price'] === null ? '' : h((string)$l['price']) ?>"><span class="hint">Only if it changed, e.g. parts missing.</span></label>
    </div>
    <label style="margin-top:12px">Your notes<textarea name="admin_note" placeholder="e.g. no keys, cat missing, collect after 4pm"><?= h($l['admin_note'] ?? '') ?></textarea></label>
    <p class="hint">Marking a lead Booked, Collected or Lost stops any price reminders.</p>
    <button class="btn go" type="submit">Save</button>
  </form>
  <?php if (!empty($l['history'])): ?><div class="box"><h2 style="margin-top:0">History</h2><table><?php foreach (array_reverse($l['history']) as $hh): ?><tr><td class="small"><?= h(adm_when($hh['at'])) ?></td><td><?= h(ucfirst($hh['by'])) ?></td><td><?= h($hh['what']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
  <?php endif; ?>

<?php
/* ================= ENQUIRIES ================= */
elseif ($v === 'enquiries'):
  $files = glob(STORE . '/enquiries/*.json') ?: []; rsort($files); ?>
  <h1>Contact form enquiries</h1>
  <?php if (!$files): ?><div class="box empty">No enquiries yet.</div><?php endif; ?>
  <?php foreach (array_slice($files, 0, 100) as $file): $e = json_decode((string)file_get_contents($file), true); if (!$e) continue; ?>
    <div class="box">
      <div class="lead-top"><b><?= h($e['name'] ?? '') ?></b><span class="small" style="margin-left:auto"><?= h(adm_when($e['received'] ?? '')) ?></span></div>
      <dl class="kv" style="margin-top:8px">
        <dt>Phone</dt><dd><a href="tel:<?= h(adm_tel((string)($e['phone'] ?? ''))) ?>"><?= h($e['phone'] ?? '') ?></a></dd>
        <?php foreach (['vehicle' => 'Vehicle', 'location' => 'Where', 'message' => 'Message'] as $k => $t): if (!empty($e[$k])): ?><dt><?= $t ?></dt><dd><?= nl2br(h($e[$k])) ?></dd><?php endif; endforeach; ?>
      </dl>
    </div>
  <?php endforeach; ?>

<?php
/* ================= RATES + WEIGHTS ================= */
elseif ($v === 'rates'):
  $wfiles = glob(STORE . '/weights/*.json') ?: [];
  $ws = []; foreach ($wfiles as $wf) { $w = json_decode((string)file_get_contents($wf), true); if ($w) $ws[basename($wf, '.json')] = $w; }
  uasort($ws, fn($a, $b) => strcmp($b['at'] ?? '', $a['at'] ?? ''));
  $rlog = is_file(STORE . '/logs/rates.log') ? array_slice(file(STORE . '/logs/rates.log', FILE_IGNORE_NEW_LINES) ?: [], -8) : []; ?>
  <h1>Rates</h1>
  <form class="box" method="post" action="<?= h($A) ?>" id="rf"><?= $hidden ?><input type="hidden" name="act" value="rates">
    <div class="grid">
      <label>Scrap rate (£ per tonne)<input type="number" name="rate_per_tonne" id="rt" min="1" step="0.01" inputmode="decimal" value="<?= h((string)$PR['rate_per_tonne']) ?>" required><span class="hint">Price = vehicle weight × this rate.</span></label>
      <label>Collection area (miles from Corby)<input type="number" name="max_miles" min="1" step="1" inputmode="numeric" value="<?= h((string)$PR['max_miles']) ?>" required><span class="hint">Further than this: no instant price, they're asked to leave their number.</span></label>
      <label>Price held for (days)<input type="number" name="valid_days" min="1" max="60" step="1" inputmode="numeric" value="<?= h((string)$PR['valid_days']) ?>" required></label>
      <label>Weight if unknown (kg)<input type="number" name="default_kg" id="dk" min="500" max="3500" step="10" inputmode="numeric" value="<?= h((string)$PR['default_kg']) ?>" required><span class="hint">Used when we can't find the kerb weight.</span></label>
      <label>Minimum price (£)<input type="number" name="min_price" id="mn" min="0" step="1" inputmode="numeric" value="<?= h((string)($PR['min_price'] ?? '')) ?>" placeholder="None"><span class="hint">Leave blank for no minimum.</span></label>
      <label>Maximum price (£)<input type="number" name="max_price" id="mx" min="0" step="1" inputmode="numeric" value="<?= h((string)($PR['max_price'] ?? '')) ?>" placeholder="None"><span class="hint">Leave blank for no maximum.</span></label>
      <label>Round prices to the nearest<select name="round_to" id="rd"><?php foreach ([1 => '£1', 5 => '£5', 10 => '£10'] as $k => $t): ?><option value="<?= $k ?>"<?= (int)($PR['round_to'] ?? 5) === $k ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
    </div>
    <h2>What customers would see</h2>
    <table class="ex" id="ex"><tr><th>Vehicle</th><th>Weight</th><th>Price</th></tr>
      <?php foreach ([['Small hatchback, e.g. Fiat Panda', 950], ['Family hatchback, e.g. Ford Focus', 1300], ['Estate or MPV, e.g. VW Touran', 1550], ['Large SUV, e.g. Land Rover Discovery', 2400], ['Transit-size van', 2000]] as $x): ?>
      <tr data-kg="<?= $x[1] ?>"><td><?= h($x[0]) ?></td><td><?= number_format($x[1]) ?> kg</td><td><b>£<?= price_for($x[1], $PR) ?></b></td></tr><?php endforeach; ?>
    </table>
    <p class="hint">Example weights are typical, not exact. Changing the rate doesn't change prices already given.</p>
    <button class="btn go" type="submit" style="margin-top:8px">Save rates</button>
  </form>
  <?php if ($rlog): ?><div class="box"><h2 style="margin-top:0">Recent changes</h2><div class="pre"><?= h(implode("\n", $rlog)) ?></div></div><?php endif; ?>

  <h2 id="weights">Kerb weights we've looked up</h2>
  <div class="box">
    <p class="small" style="margin-top:0">Each model is looked up once, then reused for every car like it. If one looks wrong, type the right weight and save. It's used for every price after that.</p>
    <?php if (!$ws): ?><p class="empty">None yet. They appear here once the registration lookup is switched on.</p><?php else: ?>
    <table><tr><th>Model</th><th>Weight</th><th class="hide-m">Source</th></tr>
      <?php foreach ($ws as $k => $w): ?><tr><td><?= h(ucwords(str_replace('-', ' ', $k))) ?></td>
        <td><form class="wform" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="weight"><input type="hidden" name="key" value="<?= h($k) ?>"><input type="number" name="kg" min="500" max="3500" step="1" value="<?= (int)$w['kg'] ?>" aria-label="Kerb weight in kg"><button class="btn ghost" type="submit">Save</button></form></td>
        <td class="hide-m small"><?= h($w['source'] ?? '') ?> (<?= h($w['confidence'] ?? '') ?>)<?php if (!empty($w['url'])): ?> · <a href="<?= h($w['url']) ?>" rel="noopener noreferrer" target="_blank">source</a><?php endif; ?></td></tr><?php endforeach; ?>
    </table><?php endif; ?>
  </div>
  <script>
  (()=>{const g=id=>document.getElementById(id);const calc=kg=>{let p=kg/1000*(+g('rt').value||0);const mn=g('mn').value,mx=g('mx').value;if(mn!=='')p=Math.max(+mn,p);if(mx!=='')p=Math.min(+mx,p);const s=+g('rd').value||5;return Math.round(p/s)*s};
  const upd=()=>document.querySelectorAll('#ex tr[data-kg]').forEach(r=>r.lastElementChild.innerHTML='<b>£'+calc(+r.dataset.kg)+'</b>');['rt','mn','mx','rd'].forEach(i=>g(i).addEventListener('input',upd))})();
  </script>

<?php
/* ================= SETTINGS ================= */
elseif ($v === 'settings'):
  $mlog = is_file(STORE . '/logs/mail-' . date('Y-m') . '.log') ? array_slice(file(STORE . '/logs/mail-' . date('Y-m') . '.log', FILE_IGNORE_NEW_LINES) ?: [], -10) : [];
  $rl = is_file(STORE . '/logs/reminders.log') ? array_slice(file(STORE . '/logs/reminders.log', FILE_IGNORE_NEW_LINES) ?: [], -1) : [];
  $dot = fn(bool $ok) => '<span class="st-dot" style="background:' . ($ok ? '#3FBF5F' : '#e0a100') . '"></span>'; ?>
  <h1>Settings</h1>
  <div class="box">
    <h2 style="margin-top:0">Status</h2>
    <dl class="kv">
      <dt>Website</dt><dd><?= $dot(!PREVIEW) ?><?= PREVIEW ? 'Preview (not live yet)' : 'Live' ?></dd>
      <dt>Reg lookup</dt><dd><?= $dot($LIVE_LOOKUP) ?><?= $LIVE_LOOKUP ? 'On (DVSA)' : 'Waiting for the DVSA key: every car is priced at ' . number_format((int)$PR['default_kg']) . ' kg' ?></dd>
      <dt>Kerb weight lookup</dt><dd><?= $dot(!empty($CFG['anthropic_key'])) ?><?= !empty($CFG['anthropic_key']) ? 'On' : 'Waiting for the Claude API key' ?></dd>
      <dt>Last reminder run</dt><dd><?= $rl ? h($rl[0]) : 'Not run yet' ?></dd>
    </dl>
  </div>
  <form class="box" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="settings">
    <h2 style="margin-top:0">Emails</h2>
    <label>Send lead alerts to<input type="email" name="notify_email" value="<?= h($SET['notify_email'] ?? '') ?>" placeholder="<?= h($S['email']) ?>"><span class="hint">Leave blank to use <?= h($S['email']) ?>.</span></label>
    <label class="tick" style="margin-top:14px"><input type="checkbox" name="rem_on" value="1"<?= !empty($REM['on']) ? ' checked' : '' ?>> Send price reminders to customers who asked for them</label>
    <label style="margin-top:12px">Send the reminder this many days before the price runs out<select name="days_before"><?php for ($i = 0; $i <= 6; $i++): ?><option value="<?= $i ?>"<?= (int)$REM['days_before'] === $i ? ' selected' : '' ?>><?= $i === 0 ? 'On the last day' : ($i === 1 ? '1 day before' : "$i days before") ?></option><?php endfor; ?></select></label>
    <p class="hint">Each customer gets one reminder at most. Nothing is sent once you mark them Booked, Collected or Lost.</p>
    <button class="btn go" type="submit">Save settings</button>
  </form>
  <div class="box">
    <h2 style="margin-top:0">Check emails are working</h2>
    <div class="acts">
      <form method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="test_email"><button class="btn ghost" type="submit">Send me a test email</button></form>
      <form method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="remind_now"><button class="btn ghost" type="submit"<?= PREVIEW ? ' disabled title="Works once the site is live"' : '' ?>>Send due reminders now</button></form>
    </div>
    <?php if ($mlog): ?><p class="small">Emails sent this month (latest 10):</p><div class="pre"><?= h(implode("\n", array_reverse($mlog))) ?></div><?php endif; ?>
  </div>
  <form class="box" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="password">
    <h2 style="margin-top:0">Change your password</h2>
    <div class="grid">
      <label>Current password<input type="password" name="current" autocomplete="current-password" required></label>
      <label>New password<input type="password" name="new" autocomplete="new-password" minlength="10" required></label>
      <label>New password again<input type="password" name="confirm" autocomplete="new-password" minlength="10" required></label>
    </div>
    <button class="btn" type="submit" style="margin-top:12px">Change password</button>
  </form>
<?php else: ?>
  <div class="box empty">Page not found. <a href="<?= h($A) ?>">Back to leads</a></div>
<?php endif; ?>
</main>
</body>
</html>
