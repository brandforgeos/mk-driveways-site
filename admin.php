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
  'dispatched' => ['Sent to driver', '#5b2b8a', '#efe6fa'],
  'collected' => ['Collected', '#0d5c27', '#cdeed7'],
  'lost' => ['Lost / declined', '#9b2226', '#fbe4e4'],
  'priced' => ['Price only (no contact)', '#51607d', '#eef1f6'],
  'needs-price' => ['No price (no contact)', '#51607d', '#eef1f6'],
];
$SETTABLE = ['accepted', 'call-back', 'saved', 'booked', 'dispatched', 'collected', 'lost'];
$FILTERS = ['action' => ['To do', ['accepted', 'call-back', 'saved']], 'booked' => ['Booked', ['booked', 'dispatched']], 'collected' => ['Collected', ['collected']], 'lost' => ['Lost', ['lost']],
  'contacts' => ['All with details', ['accepted', 'call-back', 'saved', 'booked', 'dispatched', 'collected', 'lost']], 'all' => ['Every price given', array_keys($STATUS)]];
$CALLS = ['Spoke to customer', 'No answer', 'Left voicemail', 'Sent a text / WhatsApp'];

function adm_go(string $url, string $flash = '', bool $bad = false): void { if ($flash !== '') $_SESSION['flash'] = [$flash, $bad]; header('Location: ' . $url, true, 303); exit; }
function adm_leads(string $dir = 'leads'): array {
  $out = [];
  foreach (glob(STORE . "/$dir/*.json") ?: [] as $f) { $l = json_decode((string)file_get_contents($f), true); if ($l && !empty($l['ref'])) $out[] = $l; }
  usort($out, fn($a, $b) => strcmp($b['received'] ?? '', $a['received'] ?? ''));
  return $out;
}
/* every recorded detail of a lead as one lowercase string, for the archive search */
function adm_haystack(array $l, array $OPS): string {
  $bits = [];
  array_walk_recursive($l, function ($v) use (&$bits) { if (is_scalar($v) && $v !== '' && $v !== true && $v !== false) $bits[] = (string)$v; });
  foreach (['received', 'collect_on', 'collected_at', 'archived'] as $k) if (!empty($l[$k])) { $t = strtotime((string)$l[$k]); if ($t) $bits[] = date('j/n/Y d/m/Y j F Y M Y', $t); }
  if (isset($OPS[$l['op'] ?? ''])) $bits[] = $OPS[$l['op']]['name'];
  $bits[] = vehicle_name($l['vehicle'] ?? null); $bits[] = lead_address($l);
  $t = strtolower(implode(' | ', $bits));
  return $t . ' | ' . preg_replace('/\s+/', '', $t);                  // also matches regs / postcodes typed without spaces
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

  if ($act === 'logout') { $_SESSION = []; session_regenerate_id(true); adm_go($A, 'Logged out.'); }

  /* ---- phone notifications ---- */
  if ($act === 'push_sub') {                                   // called by the page script when a phone turns notifications on
    $ep = (string)($_POST['endpoint'] ?? ''); $pk = (string)($_POST['p256dh'] ?? ''); $au = (string)($_POST['auth'] ?? '');
    if (!push_endpoint_ok($ep) || strlen($ep) > 1000 || strlen(b64u_dec($pk)) !== 65 || strlen(b64u_dec($au)) !== 16) jout(['ok' => false, 'msg' => 'This browser gave an address we can\'t use. Try Safari on iPhone or Chrome on Android.']);
    $id = push_id($ep); $old = push_device($id); $ev = push_events();
    $d = ['id' => $id, 'endpoint' => $ep, 'p256dh' => $pk, 'auth' => $au, 'user' => $me, 'name' => $old['name'] ?? push_device_name((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
      'added' => $old['added'] ?? date('c'), 'events' => $old['events'] ?? array_map(fn($e) => $e[2], $ev)];
    if (!push_save($d)) jout(['ok' => false, 'msg' => 'Couldn\'t save. Please tell Lewis.']);
    $r = push_send([$d], ['title' => 'Notifications are on', 'body' => 'This ' . $d['name'] . ' will now get the alerts you pick on the Notifications page.', 'url' => abs_url('admin') . '?v=notify', 'tag' => 'welcome']);
    push_log("added $id ({$d['name']}) by $me, welcome " . ($r['sent'] ? 'sent' : 'failed'));
    jout(['ok' => true, 'id' => $id, 'sent' => $r['sent'] > 0]);
  }
  if ($act === 'push_off') {                                   // this phone turned notifications off
    $id = (string)($_POST['id'] ?? ''); if (push_device($id)) { push_forget($id); push_log("removed $id by $me (turned off on the device)"); }
    jout(['ok' => true]);
  }
  if ($act === 'push_prefs' || $act === 'push_test' || $act === 'push_del') {
    $d = push_device((string)($_POST['id'] ?? '')); if (!$d) adm_go($A . '?v=notify', 'That device isn\'t set up any more.', true);
    if ($act === 'push_del') { push_forget($d['id']); push_log("removed {$d['id']} ({$d['name']}) by $me"); adm_go($A . '?v=notify', $d['name'] . ' removed. It won\'t get any more notifications.'); }
    if ($act === 'push_test') {
      $r = push_send([$d], ['title' => 'Test notification', 'body' => 'If you can see this, notifications are working on this ' . $d['name'] . '.', 'url' => abs_url('admin') . '?v=notify', 'tag' => 'test']);
      adm_go($A . '?v=notify#d-' . $d['id'], $r['sent'] ? "Test sent to {$d['name']}. It should pop up in a few seconds." : ($r['removed'] ? "{$d['name']} has turned notifications off, so it's been removed. Turn them on again on that phone." : "The test didn't go through. Please tell Lewis."), !$r['sent']);
    }
    $nm = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 40); if ($nm !== '') $d['name'] = $nm;
    foreach (push_events() as $k => $_) $d['events'][$k] = !empty($_POST["ev_$k"]);
    push_save($d);
    adm_go($A . '?v=notify#d-' . $d['id'], 'Saved. ' . (count(array_filter($d['events'])) ? $d['name'] . ' gets ' . count(array_filter($d['events'])) . ' type' . (count(array_filter($d['events'])) === 1 ? '' : 's') . ' of notification.' : $d['name'] . ' has every notification switched off.'));
  }
   // fresh empty session, so the 'Logged out' message survives

  if ($act === 'lead') {
    $l = load_lead((string)($_POST['ref'] ?? '')); if (!$l) adm_go($back, 'Lead not found.', true);
    $chg = [];
    $st = (string)($_POST['status'] ?? ''); if (in_array($st, $SETTABLE, true) && $st !== $l['status']) { $chg[] = 'status: ' . ($STATUS[$l['status']][0] ?? $l['status']) . ' → ' . $STATUS[$st][0]; $l['status'] = $st; }
    if (isset($_POST['admin_note'])) { $nt = mb_substr(trim((string)$_POST['admin_note']), 0, 2000); if ($nt !== ($l['admin_note'] ?? '')) { $chg[] = 'notes'; $l['admin_note'] = $nt; } }
    if ($chg) { $l['history'][] = ['at' => date('c'), 'by' => $me, 'what' => ucfirst(implode(', ', $chg)), 'type' => 'note']; save_lead($l); }
    adm_go($back, $chg ? 'Saved.' : 'No changes.');
  }

  $T = fn($k, $n = 200) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
  $OPS = operatives($SET);

  /* log a phone call / message with the customer */
  if ($act === 'calllog') {
    $l = load_lead($T('ref', 20)); if (!$l) adm_go($back, 'Lead not found.', true);
    $out = $T('outcome', 40); if (!in_array($out, $CALLS, true)) adm_go($back, 'Pick what happened on the call.', true);
    $note = $T('note', 500);
    lead_log($l, $me, $out . ($note !== '' ? ": $note" : ''), 'call'); $l['last_call'] = date('c');
    if ($out === 'Spoke to customer' && in_array($l['status'], ['priced', 'saved', 'needs-price'], true)) $l['status'] = $l['price'] === null ? 'call-back' : 'accepted';
    save_lead($l); adm_go($back . '#book', 'Call logged.');
  }

  /* book (or change) the collection: address, date, slot, price, driver notes; optional email to the customer */
  if ($act === 'book') {
    $l = load_lead($T('ref', 20)); if (!$l) adm_go($back, 'Lead not found.', true);
    $err = fn(string $m) => adm_go($back . '#book', $m, true);
    $name = $T('name', 100); $phone = $T('phone', 40); $email = $T('email', 150);
    $addr = ['line1' => $T('line1', 150), 'line2' => $T('line2', 150), 'town' => $T('town', 80), 'postcode' => clean_pc($T('postcode', 12))];
    $date = $T('collect_on', 10); $slot = $T('slot', 10); if ($slot === 'time') $slot = $T('time', 5);
    if ($name === '') $err('Add the customer\'s name.');
    if (strlen(preg_replace('/\D/', '', $phone)) < 10) $err('Add a phone number for the customer (the driver needs it).');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err('That email address doesn\'t look right.');
    if ($addr['line1'] === '' || strlen($addr['postcode']) < 5) $err('Add the collection address and postcode.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) $err('Pick a collection date.');
    if (!in_array($slot, ['am', 'pm', 'any'], true) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $slot)) $err('Pick AM, PM, any time, or enter a time.');
    $fp = $T('final_price', 10); $fpv = $fp === '' ? null : max(0, (int)round((float)$fp));
    $was = in_array($l['status'], ['booked', 'dispatched', 'collected'], true) && !empty($l['collect_on']);
    $oldWhen = $was ? collect_when($l) . ' at ' . lead_address($l) : '';
    $l['name'] = $name; $l['phone'] = $phone; $l['email'] = $email; $l['address'] = $addr; $l['postcode'] = $addr['postcode'];
    $l['collect_on'] = $date; $l['collect_slot'] = $slot; $l['final_price'] = $fpv; $l['driver_note'] = $T('driver_note', 1000);
    $newWhen = collect_when($l) . ' at ' . lead_address($l);
    if (!$was) { $l['status'] = 'booked'; $l['booked_at'] = date('c'); $l['booked_by'] = $me; lead_log($l, $me, 'Booked in: ' . $newWhen . ($fpv !== null ? ", agreed £$fpv" : ''), 'booked'); }
    elseif ($oldWhen !== $newWhen) {
      lead_log($l, $me, 'Booking changed to ' . $newWhen, 'booked');
      if ($l['status'] === 'dispatched') { $l['status'] = 'booked'; lead_log($l, $me, 'Driver has old details: send the job again', 'note'); }
    } else lead_log($l, $me, 'Booking details updated' . ($fpv !== null ? " (agreed £$fpv)" : ''), 'note');
    $flash = $was ? 'Booking updated.' : 'Booked in for ' . collect_when($l) . '.';
    if (!empty($_POST['email_customer'])) {
      if ($email === '') $flash .= ' No email address, so no confirmation was emailed: use the WhatsApp button instead.';
      elseif (PREVIEW) $flash .= ' (Preview: confirmation email not sent.)';
      else { [$sub, $t, $hh] = booking_email($S, $l, $was && $oldWhen !== $newWhen);
        if (send_mail($email, $sub, $t, $hh, $S['email'])) { lead_log($l, $me, "Confirmation emailed to $email", 'email'); $flash .= " Confirmation emailed to $email."; }
        else $flash .= ' The confirmation email failed to send.'; }
    }
    save_lead($l); adm_go($back . '#driver', $flash);
  }

  /* fired by the WhatsApp / text buttons (sendBeacon) just before the app opens: records who sent which jobs to which driver */
  if ($act === 'sent') {
    $op = $T('op', 40); $via = $T('via', 10) === 'sms' ? 'text' : 'WhatsApp';
    if (isset($OPS[$op])) foreach (array_slice(explode(',', $T('refs', 2000)), 0, 60) as $ref) {
      $l = load_lead($ref); if (!$l || !in_array($l['status'], ['booked', 'dispatched'], true)) continue;
      $l['op'] = $op; $l['status'] = 'dispatched'; $l['sent'][] = ['at' => date('c'), 'by' => $me, 'op' => $op, 'via' => $via];
      lead_log($l, $me, "Sent to {$OPS[$op]['name']} by $via", 'sent'); save_lead($l);
    }
    http_response_code(204); exit;
  }

  /* collections board: set the driver for several jobs at once */
  if ($act === 'assign') {
    $n = 0;
    foreach ($_POST as $k => $val) {
      if (!str_starts_with((string)$k, 'op_')) continue;
      $l = load_lead(substr((string)$k, 3)); if (!$l || !in_array($l['status'], ['booked', 'dispatched'], true)) continue;
      $val = (string)$val; if ($val !== '' && !isset($OPS[$val])) continue;
      if ($val === (string)($l['op'] ?? '')) continue;
      $l['op'] = $val;
      if ($l['status'] === 'dispatched') $l['status'] = 'booked';    // the old driver's link stops working; this driver hasn't been sent it yet
      lead_log($l, $me, $val === '' ? 'Driver removed' : 'Driver set to ' . $OPS[$val]['name'], 'note'); save_lead($l); $n++;
    }
    adm_go($back, $n ? "Drivers saved ($n changed). Now send each driver their jobs." : 'No changes.');
  }

  if ($act === 'setop') {
    $l = load_lead($T('ref', 20)); $op = $T('op', 40);
    if (!$l || ($op !== '' && !isset($OPS[$op]))) adm_go($back, 'Not saved.', true);
    if ($op !== (string)($l['op'] ?? '')) { $l['op'] = $op; if ($l['status'] === 'dispatched') $l['status'] = 'booked'; lead_log($l, $me, $op === '' ? 'Driver removed' : 'Driver set to ' . $OPS[$op]['name'], 'note'); save_lead($l); }
    adm_go($back . '#driver', $op === '' ? 'Driver removed.' : 'Driver set to ' . $OPS[$op]['name'] . '. Now tap WhatsApp or Text to send the job.');
  }

  if ($act === 'collected') {
    $l = load_lead($T('ref', 20)); if (!$l) adm_go($back, 'Lead not found.', true);
    $already = $l['status'] === 'collected';
    $paid = $T('paid', 10); $l['paid'] = $paid === '' ? null : max(0, (int)round((float)$paid)); $l['pay_ref'] = $T('pay_ref', 100);
    $l['status'] = 'collected'; $l['collected_at'] = $l['collected_at'] ?? date('c'); $l['collected_by'] = $l['collected_by'] ?? (isset($OPS[$l['op'] ?? '']) ? 'Driver: ' . $OPS[$l['op']]['name'] : ucfirst($me));
    lead_log($l, $me, ($already ? 'Payment recorded' : 'Marked collected') . ($l['paid'] !== null ? ", paid £{$l['paid']}" : '') . ($l['pay_ref'] !== '' ? " (ref {$l['pay_ref']})" : '') . ($T('note', 500) !== '' ? '. ' . $T('note', 500) : ''), 'collected');
    save_lead($l); adm_go($back, $already ? 'Payment saved.' : 'Marked as collected.');
  }

  /* a booking taken over the phone (customer never used the website) */
  if ($act === 'new_lead') {
    $reg = clean_reg($T('reg', 12)); $pc = clean_pc($T('postcode', 12)); $name = $T('name', 100); $phone = $T('phone', 40);
    $keep = '&' . http_build_query(['name' => $name, 'phone' => $phone, 'reg' => $reg, 'postcode' => $pc]);
    if (strlen($reg) < 2 || strlen($pc) < 5 || $name === '' || strlen(preg_replace('/\D/', '', $phone)) < 10) adm_go($A . '?v=new' . $keep, 'Add the reg, postcode, name and phone number.', true);
    $v = !empty($_POST['lookup']) ? vehicle_lookup($reg, $CFG) : null;
    $w = $v ? weight_cached($v) : null; $kg = $w['kg'] ?? (int)$PR['default_kg'];
    $loc = pc_lookup($pc); $dist = $loc ? round(miles($S['geo'], [$loc['lat'], $loc['lng']]), 1) : null;
    $ref = 'SC' . gmdate('ymd') . strtoupper(bin2hex(random_bytes(2)));
    $fp = $T('final_price', 10);
    $l = ['ref' => $ref, 'status' => 'accepted', 'received' => gmdate('c'), 'demo' => false, 'source' => 'phone',
      'reg' => $reg, 'postcode' => $loc['pc'] ?? $pc, 'town' => $loc['town'] ?? '', 'miles' => $dist,
      'vehicle' => $v, 'weight_kg' => $kg, 'weight_source' => $w ? $w['source'] . ' (' . $w['confidence'] . ')' : 'Average car weight (not looked up)',
      'price' => $v ? price_for($kg, $PR) : null, 'final_price' => $fp === '' ? null : max(0, (int)round((float)$fp)),
      'valid_until' => gmdate('Y-m-d', time() + 86400 * $PR['valid_days']), 'rate_per_tonne' => $PR['rate_per_tonne'],
      'name' => $name, 'phone' => $phone, 'email' => $T('email', 150), 'accepted' => gmdate('c'), 'reminders_ok' => false, 'collection_note' => $T('note', 500),
      'address' => ['line1' => $T('line1', 150), 'line2' => $T('line2', 150), 'town' => $T('town', 80), 'postcode' => $loc['pc'] ?? $pc]];
    lead_log($l, $me, 'Added from a phone call', 'call');
    save_lead($l); adm_go($A . '?v=lead&ref=' . $ref . '#book', 'Added. ' . ($v ? vehicle_name($v) . ', price £' . $l['price'] . '. ' : '') . 'Now pick the date and time to book it in.');
  }

  /* archive: collected or lost jobs move out of the working lists into STORE/archive (still searchable, can be restored) */
  if ($act === 'archive' || $act === 'unarchive') {
    $l = load_lead($T('ref', 20)); if (!$l) adm_go($back, 'Lead not found.', true);
    if ($act === 'archive') {
      if (!in_array($l['status'], ['collected', 'lost'], true)) adm_go($back, 'Only collected or lost jobs can be archived.', true);
      $l['archived'] = date('c'); $l['archived_by'] = $me; lead_log($l, $me, 'Archived', 'note'); save_lead($l);
      adm_go($A . '?v=leads&f=' . ($l['status'] === 'lost' ? 'lost' : 'collected'), "{$l['reg']} archived. Find it any time in the Archive tab.");
    }
    unset($l['archived'], $l['archived_by']); lead_log($l, $me, 'Restored from the archive', 'note'); save_lead($l);
    adm_go($A . '?v=lead&ref=' . $l['ref'], "{$l['reg']} restored to Leads.");
  }
  if ($act === 'archive_all') {
    $st = $T('status', 20); if (!in_array($st, ['collected', 'lost'], true)) adm_go($back);
    $n = 0; foreach (adm_leads() as $l) if ($l['status'] === $st) { $l['archived'] = date('c'); $l['archived_by'] = $me; lead_log($l, $me, 'Archived', 'note'); if (save_lead($l)) $n++; }
    adm_go($A . '?v=leads&f=' . $st, $n ? "$n job" . ($n === 1 ? '' : 's') . ' archived. Find them in the Archive tab.' : 'Nothing to archive.');
  }

  /* saved drivers (Settings) */
  if ($act === 'op_save') {
    $id = preg_replace('/[^a-z0-9]/', '', $T('id', 20)); $nm = $T('op_name', 40); $ph = $T('op_phone', 30); $via = $T('op_via', 10) === 'sms' ? 'sms' : 'whatsapp';
    if ($nm === '' || strlen(intl_phone($ph)) < 11) adm_go($A . '?v=settings#drivers', 'Add the driver\'s name and mobile number.', true);
    if ($id === '' || !isset($OPS[$id])) $id = substr(md5($nm . microtime()), 0, 8);
    $OPS[$id] = ['name' => $nm, 'phone' => $ph, 'via' => $via]; $SET['operatives'] = $OPS;
    adm_go($A . '?v=settings#drivers', adm_settings_save($SET) ? "Saved $nm." : 'Couldn\'t save. Please tell Lewis.', false);
  }
  if ($act === 'op_del') {
    $id = $T('id', 20); if (isset($OPS[$id])) { $nm = $OPS[$id]['name']; unset($OPS[$id]); $SET['operatives'] = $OPS; adm_settings_save($SET); adm_go($A . '?v=settings#drivers', "Removed $nm. Jobs already sent to them keep their history."); }
    adm_go($A . '?v=settings#drivers');
  }

  if ($act === 'rates') {
    $num = fn($k) => trim((string)($_POST[$k] ?? ''));
    $p = ['rate_per_tonne' => max(0, round((float)$num('rate_per_tonne'), 2)), 'max_miles' => max(1, (int)$num('max_miles')), 'valid_days' => min(60, max(1, (int)$num('valid_days'))),
          'default_kg' => min(3500, max(500, (int)$num('default_kg'))), 'round_to' => in_array((int)$num('round_to'), [1, 5, 10], true) ? (int)$num('round_to') : 5,
          'min_price' => $num('min_price') === '' ? null : max(0, (int)$num('min_price')), 'max_price' => $num('max_price') === '' ? null : max(0, (int)$num('max_price'))];
    if ($p['rate_per_tonne'] <= 0) adm_go($A . '?v=rates', 'The rate per tonne needs to be more than £0.', true);
    if ($p['min_price'] !== null && $p['max_price'] !== null && $p['min_price'] > $p['max_price']) adm_go($A . '?v=rates', 'The minimum price can\'t be more than the maximum price.', true);
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

  if ($act === 'oneauto_key') {
    $k = trim((string)($_POST['oneauto_key'] ?? ''));
    if ($k === '' && !empty($_POST['remove'])) { @unlink(STORE . '/keys/oneauto.key'); adm_go($A . '?v=settings#lookup', 'Lookup key removed. Every car is now priced at the average weight.'); }
    if (!preg_match('/^[A-Za-z0-9._~+\/=-]{16,200}$/', $k)) adm_go($A . '?v=settings#lookup', 'That key doesn\'t look right. Copy it again from your One Auto API account (no spaces).', true);
    store_path('keys'); $kf = STORE . '/keys/oneauto.key';
    if (@file_put_contents($kf, $k, LOCK_EX) === false) adm_go($A . '?v=settings#lookup', 'Couldn\'t save the key. Please tell Lewis.', true);
    @chmod($kf, 0600);
    adm_go($A . '?v=settings#lookup', 'Key saved. Now test it with a registration below.');
  }

  if ($act === 'oneauto_test') {
    $reg = clean_reg((string)($_POST['reg'] ?? ''));
    if (strlen($reg) < 2) adm_go($A . '?v=settings#lookup', 'Enter a registration to test.', true);
    if ($CFG['oneauto_key'] === '') adm_go($A . '?v=settings#lookup', 'Save the One Auto API key first.', true);
    $v = oneauto_lookup($reg, $CFG, false);
    if (!$v) { $last = is_file(STORE . '/logs/lookups-' . date('Y-m') . '.log') ? (array_slice(file(STORE . '/logs/lookups-' . date('Y-m') . '.log', FILE_IGNORE_NEW_LINES) ?: [], -1)[0] ?? '') : '';
      adm_go($A . '?v=settings#lookup', "No result for $reg. " . ($last ? 'Server reply: ' . $last . '. ' : '') . (str_contains($last, ' 401 ') || str_contains($last, ' 403 ') ? 'The key was refused: check it was copied in full and the account has credit.' : 'Check the reg, or tell Lewis.'), true); }
    $w = weight_cached($v);
    adm_go($A . '?v=settings#lookup', "$reg: " . vehicle_name($v) . ($v['engine_cc'] ? ', ' . number_format($v['engine_cc']) . 'cc' : '') . ' ' . $v['fuel'] . '. Weight ' . ($w ? number_format($w['kg']) . ' kg (' . $w['source'] . ')' : 'not given: average weight used') . '. Price today: £' . price_for($w['kg'] ?? (int)$PR['default_kg'], $PR) . (!empty($v['scrapped']) ? '. DVLA shows it as already scrapped.' : '.'));
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

/* ---------- collection photos (admin only; files live outside the web root) ---------- */
if ($me && ($_GET['v'] ?? '') === 'photo') {
  $ref = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($_GET['ref'] ?? ''))); $fn = (string)($_GET['f'] ?? '');
  $file = STORE . "/photos/$ref/$fn";
  if ($ref === '' || !preg_match('/^(id|car|fail)-\d{8}-\d{6}-[a-f0-9]{6}\.(jpg|png|webp)$/', $fn) || !is_file($file)) { http_response_code(404); exit; }
  header('Content-Type: ' . ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($fn, PATHINFO_EXTENSION)]);
  header('Cache-Control: private, max-age=86400'); header('Content-Length: ' . filesize($file)); readfile($file); exit;
}
/* ---------- CSV export ---------- */
$v = (string)($_GET['v'] ?? 'leads');
if ($me && $v === 'export') {
  header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="scrap-car-leads-' . date('Y-m-d') . '.csv"');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
  $OPS = operatives($SET);
  fputcsv($o, ['Ref', 'Received', 'Status', 'Reg', 'Vehicle', 'Postcode', 'Miles', 'Weight kg', 'Weight source', 'Price', 'Agreed price', 'Collection date', 'Collection time', 'Address', 'Driver', 'Booked by', 'Booked at', 'Collected by', 'Collected at', 'Paid', 'Payment ref', 'Name', 'Phone', 'Email', 'Reminders OK', 'Customer note', 'Driver notes', 'Admin notes', 'Archived']);
  $cell = fn($x) => is_string($x) && preg_match('/^[=@\t\r]|^[+\-](?![\d\s()]+$)/', $x) ? "'" . $x : $x;   // customer-typed text can't run as a spreadsheet formula
  foreach (array_merge(adm_leads(), adm_leads('archive')) as $l) fputcsv($o, array_map($cell, [$l['ref'], $l['received'], $l['status'], $l['reg'], vehicle_name($l['vehicle'] ?? null), $l['postcode'], $l['miles'], $l['weight_kg'], $l['weight_source'], $l['price'], $l['final_price'] ?? '', $l['collect_on'] ?? '', !empty($l['collect_on']) ? slot_label($l['collect_slot'] ?? 'any') : '', lead_address($l), $OPS[$l['op'] ?? '']['name'] ?? '', $l['booked_by'] ?? '', $l['booked_at'] ?? '', $l['collected_by'] ?? '', $l['collected_at'] ?? '', $l['paid'] ?? '', $l['pay_ref'] ?? '', $l['name'] ?? '', $l['phone'] ?? '', $l['email'] ?? '', !empty($l['reminders_ok']) ? 'yes' : 'no', $l['collection_note'] ?? '', $l['driver_note'] ?? '', $l['admin_note'] ?? '', $l['archived'] ?? '']));
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
<link rel="manifest" href="<?= h($A) ?>manifest.webmanifest">
<link rel="apple-touch-icon" href="<?= BASE ?>img/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="Scrap admin"><meta name="theme-color" content="#10224A">
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
.tick input{width:22px;height:22px;min-height:0;margin:0;flex:0 0 22px}
.st-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px}
[hidden]{display:none!important}
.grid>*{min-width:0}
input[type=date],input[type=time]{-webkit-appearance:none;appearance:none;min-width:0;max-width:100%}
.steps{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin:0 0 14px}
.steps div{background:#fff;border:1px solid var(--line);border-radius:10px;padding:8px;font-size:13px;color:var(--muted);line-height:1.3}
.steps div b{display:block;color:var(--navy);font-size:14px}
.steps div.on{border-color:var(--green);background:#e9f7ee}
.steps div.on b::before{content:"\2713  ";color:#1d7a3a}
.seg{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.seg label{display:flex;align-items:center;gap:6px;border:1px solid #b9c4da;border-radius:9px;padding:9px 12px;font-weight:600;cursor:pointer}
.seg input{width:auto;min-height:0;margin:0}
.seg label:has(input:checked){border-color:var(--navy);background:#dfe5f2}
.oprow{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 0;border-top:1px solid var(--line)}
.oprow:first-of-type{border-top:0}
.oprow b{min-width:110px}
.photos{display:flex;gap:8px;flex-wrap:wrap}.photos a{display:block;text-decoration:none;text-align:center;font-size:13px;color:var(--muted)}.photos img{display:block;width:110px;height:110px;object-fit:cover;border-radius:10px;border:1px solid var(--line)}
.btn.sm{min-height:40px;padding:6px 12px;font-size:15px}
.btn.wa{background:#25D366;border-color:#25D366;color:#06210F}
.job{display:grid;grid-template-columns:auto 1fr auto;gap:4px 10px;align-items:center;padding:10px 0;border-top:1px solid var(--line)}
.job select{min-height:40px;padding:6px 8px;margin:0;width:auto;max-width:170px}
.callbtns{display:flex;flex-wrap:wrap;gap:6px}
@media (max-width:600px){.steps{grid-template-columns:repeat(2,1fr)}.job{grid-template-columns:1fr}.job select{max-width:none;width:100%}}
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

$tabs = ['leads' => 'Leads', 'collections' => 'Collections', 'archive' => 'Archive', 'enquiries' => 'Enquiries', 'rates' => 'Rates', 'notify' => 'Notifications', 'settings' => 'Settings'];
$tabOn = in_array($v, ['lead', 'new'], true) ? (($v === 'lead' && ($tl = load_lead((string)($_GET['ref'] ?? ''))) && !empty($tl['archived'])) ? 'archive' : 'leads') : $v;
?>
<header class="top">
  <div class="top-in"><img src="<?= BASE ?>img/logo-mark.svg" alt=""><b>Scrap Cars admin</b>
    <form class="who" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="logout"><span class="hide-m"><?= h(ucfirst($me)) ?></span><a href="<?= h(u()) ?>" style="color:#b4c3e2">View site</a><button type="submit">Log out</button></form></div>
  <nav class="tabs"><?php foreach ($tabs as $k => $t): ?><a href="<?= h($A . '?v=' . $k) ?>" class="<?= $tabOn === $k ? 'on' : '' ?>"><?= h($t) ?></a><?php endforeach; ?></nav>
</header>
<main>
<?php if ($flash): ?><div class="flash<?= $flash[1] ? ' bad' : '' ?>" role="status"><?= h($flash[0]) ?></div><?php endif; ?>
<?php if (PREVIEW): ?><div class="box small" style="border-left:5px solid #FFD200">Preview mode: the site isn't live yet, so no emails go to customers and lead alert emails aren't sent (phone notifications do work). Everything else here works.</div><?php endif; ?>

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
  $OPSL = operatives($SET);
  $page = max(1, (int)($_GET['p'] ?? 1)); $per = 50; $pages = max(1, (int)ceil(count($list) / $per));
?>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap"><h1 style="margin-right:auto">Leads</h1><a class="btn go" href="<?= h($A . '?v=new') ?>">+ Phone booking</a></div>
  <div class="stats">
    <div class="stat"><b><?= $count(['accepted', 'call-back']) ?></b><span>Waiting for a call</span></div>
    <div class="stat"><b><?= count($wk) ?></b><span>Prices given, last 7 days</span></div>
    <div class="stat"><b><?= $acc($wk) ?></b><span>Accepted, last 7 days</span></div>
    <div class="stat"><b><?= count($col) ?></b><span>Collected, last 30 days</span></div>
    <div class="stat"><b><?= count(array_filter($all, fn($l) => ($l['collect_on'] ?? '') === date('Y-m-d') && in_array($l['status'], ['booked', 'dispatched'], true))) ?></b><span><a href="<?= h($A . '?v=collections') ?>">Collections today</a></span></div>
  </div>
  <div class="pills"><?php foreach ($FILTERS as $k => $x): ?><a href="<?= h($A . '?v=leads&f=' . $k) ?>" class="<?= $f === $k ? 'on' : '' ?>"><?= h($x[0]) ?><i><?= $count($x[1]) ?></i></a><?php endforeach; ?></div>
  <form class="search" method="get" action="<?= h($A) ?>"><input type="hidden" name="v" value="leads"><input type="hidden" name="f" value="<?= h($f) ?>"><input name="q" value="<?= h($q) ?>" placeholder="Search reg, name, phone, postcode" aria-label="Search leads"><button class="btn" type="submit">Search</button></form>
  <?php if (in_array($f, ['collected', 'lost'], true) && $list && $q === ''): ?>
  <form method="post" action="<?= h($A) ?>" style="margin:0 0 12px"><?= $hidden ?><input type="hidden" name="act" value="archive_all"><input type="hidden" name="status" value="<?= h($f) ?>">
    <button class="btn ghost sm" type="submit" onclick="return confirm('Move all <?= count($list) ?> <?= $f === 'lost' ? 'lost' : 'collected' ?> jobs to the Archive?')">Archive all <?= count($list) ?> <?= $f === 'lost' ? 'lost' : 'collected' ?> job<?= count($list) === 1 ? '' : 's' ?></button>
    <span class="small">They stay searchable in the Archive tab and can be restored.</span></form>
  <?php endif; ?>
  <?php if (!$list): ?><div class="box empty"><?= $f === 'action' ? 'Nothing to do right now. New accepted prices show up here.' : 'No leads here yet.' ?></div><?php endif; ?>
  <?php foreach (array_slice($list, ($page - 1) * $per, $per) as $l): $veh = vehicle_name($l['vehicle'] ?? null); ?>
    <a class="lead" href="<?= h($A . '?v=lead&ref=' . $l['ref']) ?>">
      <div class="lead-top"><span class="plate"><?= h($l['reg']) ?></span><?= adm_badge($STATUS, $l['status']) ?><?php if (!empty($l['demo'])): ?><span class="badge" style="background:#fff3d6;color:#8a5a00">Test</span><?php endif; ?>
        <span class="price"><?= isset($l['final_price']) && $l['final_price'] !== null ? '£' . (int)$l['final_price'] : ($l['price'] === null ? '–' : '£' . (int)$l['price']) ?></span></div>
      <div style="margin-top:6px;font-weight:700"><?= h($l['name'] ?? '') ?: '<span class="small">No contact details</span>' ?><?= $veh ? ' <span class="small">· ' . h($veh) . '</span>' : '' ?></div>
      <div class="meta"><?= h(adm_when($l['received'])) ?> · <?= h($l['postcode']) ?><?= $l['miles'] !== null ? ' (' . h((string)$l['miles']) . ' mi)' : '' ?><?= !empty($l['collect_on']) ? ' · Collect ' . h(date('D j M', strtotime($l['collect_on'])) . ' ' . slot_label($l['collect_slot'] ?? 'any', true)) . (isset($OPSL[$l['op'] ?? '']) ? ' · ' . h($OPSL[$l['op']]['name']) : '') : '' ?> · <?= h($l['ref']) ?></div>
    </a>
  <?php endforeach; ?>
  <?php if ($pages > 1): ?><div class="pills"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'on' : '' ?>" href="<?= h($A . '?v=leads&f=' . $f . '&q=' . rawurlencode($q) . '&p=' . $i) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
  <p class="small" style="margin-top:18px"><a href="<?= h($A . '?v=export') ?>">Download every lead as a spreadsheet (CSV)</a></p>

<?php
/* ================= ONE LEAD ================= */
elseif ($v === 'lead'):
  $l = load_lead((string)($_GET['ref'] ?? ''));
  if (!$l): ?><div class="box empty">Lead not found. <a href="<?= h($A) ?>">Back to leads</a></div><?php else:
  $veh = $l['vehicle'] ?? null; $OPS = operatives($SET); $op = (string)($l['op'] ?? '');
  $isBooked = in_array($l['status'], ['booked', 'dispatched', 'collected'], true) && !empty($l['collect_on']);
  $first = trim(explode(' ', (string)($l['name'] ?? ''))[0]);
  $hist = $l['history'] ?? [];
  $lastOf = function (string $type) use ($hist) { foreach (array_reverse($hist) as $x) if (($x['type'] ?? '') === $type) return $x; return null; };
  $calls = array_values(array_filter($hist, fn($x) => ($x['type'] ?? '') === 'call'));
  $sentLast = $lastOf('sent'); $collLast = $lastOf('collected');
  $a = $l['address'] ?? []; $slot = (string)($l['collect_slot'] ?? '');
  $isTime = (bool)preg_match('/^\d{2}:\d{2}$/', $slot);
  $by = fn($x) => $x ? h(ucfirst((string)$x['by'])) . ', ' . h(adm_when($x['at'])) : '';
  $price = $l['final_price'] ?? $l['price'];
  $custWa = !empty($l['phone']) && $isBooked ? 'https://wa.me/' . intl_phone((string)$l['phone']) . '?text=' . rawurlencode(($first ? "Hi $first, " : 'Hi, ') . "it's George from We Buy Scrap Cars Corby. Your collection for {$l['reg']} is booked for " . collect_when($l) . ' at ' . lead_address($l) . ($price !== null ? ". Agreed price £$price, paid by bank transfer when we collect" : '') . ". Please have the V5C logbook, all keys and photo ID ready. Any problems, just reply here. Thanks!") : '';
?>
  <p class="small"><a href="<?= h($A . (!empty($l['archived']) ? '?v=archive' : '?v=leads')) ?>">← <?= !empty($l['archived']) ? 'Archive' : 'All leads' ?></a><?php if (!empty($l['collect_on'])): ?> · <a href="<?= h($A . '?v=collections&d=' . $l['collect_on']) ?>">Collections on <?= h(date('D j M', strtotime($l['collect_on']))) ?></a><?php endif; ?></p>
  <h1><span class="plate" style="font-size:20px"><?= h($l['reg']) ?></span> <?= h(vehicle_name($veh) ?: 'Vehicle not found from reg') ?></h1>
  <?php if (!empty($l['archived'])): ?><div class="box small" style="border-left:5px solid var(--muted)">Archived <?= h(adm_when($l['archived'])) ?> by <?= h(ucfirst((string)($l['archived_by'] ?? ''))) ?>. <a href="<?= h($A . '?v=archive') ?>">Back to Archive</a></div><?php endif; ?>
  <p><?= adm_badge($STATUS, $l['status']) ?> <span class="small">Ref <?= h($l['ref']) ?> · <?= ($l['source'] ?? '') === 'phone' ? 'added from a phone call' : 'priced online' ?> <?= h(adm_when($l['received'])) ?><?= $price !== null ? ' · £' . (int)$price : '' ?></span></p>

  <div class="steps">
    <div class="on"><b>Priced</b><?= h(adm_when($l['received'])) ?></div>
    <div class="<?= $calls ? 'on' : '' ?>"><b>Called</b><?= $calls ? count($calls) . ' · ' . $by(end($calls)) : 'Not yet' ?></div>
    <div class="<?= $isBooked ? 'on' : '' ?>"><b>Booked</b><?= $isBooked ? h(ucfirst((string)($l['booked_by'] ?? ''))) . ($l['booked_at'] ?? '' ? ', ' . h(adm_when($l['booked_at'])) : '') : 'Not yet' ?></div>
    <div class="<?= in_array($l['status'], ['dispatched', 'collected'], true) || $sentLast ? 'on' : '' ?>"><b>Sent to driver</b><?= $sentLast ? h($OPS[$sentLast['op'] ?? '']['name'] ?? 'Driver') . ' · ' . $by($sentLast) : 'Not yet' ?></div>
    <div class="<?= $l['status'] === 'collected' ? 'on' : '' ?>"><b>Collected</b><?= $l['status'] === 'collected' ? h((string)($l['collected_by'] ?? '')) . (!empty($l['collected_at']) ? ', ' . h(adm_when($l['collected_at'])) : '') : 'Not yet' ?></div>
  </div>

  <?php if (!empty($l['phone']) || !empty($l['email'])): ?>
  <div class="acts">
    <?php if (!empty($l['phone'])): ?><a class="btn go" href="tel:+<?= h(intl_phone((string)$l['phone'])) ?>">Call <?= h($l['phone']) ?></a>
    <a class="btn ghost" href="https://wa.me/<?= h(intl_phone((string)$l['phone'])) ?>?text=<?= rawurlencode(($first ? "Hi $first" : 'Hi') . ', it\'s George from We Buy Scrap Cars Corby about your ' . $l['reg'] . '.') ?>" rel="noopener">WhatsApp</a><?php endif; ?>
    <?php if (!empty($l['email'])): ?><a class="btn ghost" href="mailto:<?= h($l['email']) ?>?subject=<?= rawurlencode('Your scrap car ' . $l['reg'] . ' (ref ' . $l['ref'] . ')') ?>">Email</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($l['status'] !== 'collected'): ?>
  <form class="box" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="calllog"><input type="hidden" name="ref" value="<?= h($l['ref']) ?>">
    <h2 style="margin-top:0">1. Log a call</h2>
    <div class="seg"><?php foreach ($CALLS as $i => $c): ?><label><input type="radio" name="outcome" value="<?= h($c) ?>"<?= $i === 0 ? ' checked' : '' ?>> <?= h($c) ?></label><?php endforeach; ?></div>
    <label style="margin-top:10px">Note (optional)<input name="note" placeholder="e.g. wants Saturday, call back after 5"></label>
    <button class="btn ghost" type="submit" style="margin-top:10px">Save call</button>
    <?php if ($calls): ?><p class="small" style="margin-bottom:0">Last call: <?= h(end($calls)['what']) ?> (<?= $by(end($calls)) ?>)</p><?php endif; ?>
  </form>
  <?php endif; ?>

  <form class="box" id="book" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="book"><input type="hidden" name="ref" value="<?= h($l['ref']) ?>">
    <h2 style="margin-top:0">2. <?= $isBooked ? 'Booking' : 'Book the collection' ?></h2>
    <div class="grid">
      <label>Customer name<input name="name" value="<?= h($l['name'] ?? '') ?>" autocomplete="off" required></label>
      <label>Phone<input name="phone" type="tel" value="<?= h($l['phone'] ?? '') ?>" autocomplete="off" required></label>
      <label>Email (for the confirmation)<input name="email" type="email" value="<?= h($l['email'] ?? '') ?>" autocomplete="off"></label>
    </div>
    <div class="grid" style="margin-top:12px">
      <label>Address line 1<input name="line1" value="<?= h($a['line1'] ?? '') ?>" placeholder="House number and street" autocomplete="off" required></label>
      <label>Address line 2<input name="line2" value="<?= h($a['line2'] ?? '') ?>" placeholder="Optional" autocomplete="off"></label>
      <label>Town<input name="town" value="<?= h($a['town'] ?? $l['town'] ?? '') ?>" autocomplete="off"></label>
      <label>Postcode<input name="postcode" value="<?= h($a['postcode'] ?? $l['postcode']) ?>" autocomplete="off" required style="text-transform:uppercase"></label>
    </div>
    <div class="grid" style="margin-top:12px">
      <label>Collection date<input type="date" name="collect_on" value="<?= h($l['collect_on'] ?? '') ?>" required></label>
      <div><span style="font-weight:600;font-size:15px">Time</span>
        <div class="seg" id="slots">
          <?php foreach (['am' => 'AM', 'pm' => 'PM', 'any' => 'Any time', 'time' => 'Set time'] as $k => $t): $on = $k === 'time' ? $isTime : ($slot === $k || ($slot === '' && $k === 'any')); ?>
          <label><input type="radio" name="slot" value="<?= $k ?>"<?= $on ? ' checked' : '' ?>> <?= $t ?></label><?php endforeach; ?>
        </div>
        <input type="time" name="time" id="slottime" value="<?= $isTime ? h($slot) : '' ?>" step="900"<?= $isTime ? '' : ' hidden' ?>>
      </div>
      <label>Agreed price (£)<input type="number" name="final_price" min="0" step="1" inputmode="numeric" value="<?= h(isset($l['final_price']) ? (string)$l['final_price'] : '') ?>" placeholder="<?= $l['price'] === null ? '' : h((string)$l['price']) ?>"><span class="hint"><?= $l['price'] === null ? 'No website price for this one: enter what you agreed.' : 'Leave blank to keep £' . (int)$l['price'] . '.' ?></span></label>
    </div>
    <label style="margin-top:12px">Notes for the driver<textarea name="driver_note" placeholder="e.g. no keys, flat tyres, parked on the drive, ring before arriving"><?= h($l['driver_note'] ?? ($l['collection_note'] ?? '')) ?></textarea><?php if (!empty($l['collection_note'])): ?><span class="hint">Customer's own note: <?= h($l['collection_note']) ?></span><?php endif; ?></label>
    <label class="tick" style="margin-top:12px"><input type="checkbox" name="email_customer" value="1"<?= $isBooked ? '' : ' checked' ?>> Email the customer the booking details</label>
    <button class="btn go" type="submit" style="margin-top:12px"><?= $isBooked ? 'Save booking' : 'Book in' ?></button>
    <?php if ($custWa): ?><a class="btn ghost wa" style="margin-top:12px" href="<?= h($custWa) ?>" rel="noopener">WhatsApp the customer their booking</a><?php endif; ?>
  </form>

  <?php if ($isBooked && $l['status'] !== 'collected'): ?>
  <div class="box" id="driver">
    <h2 style="margin-top:0">3. Send to a driver</h2>
    <?php if (!$OPS): ?><p>No drivers saved yet. <a href="<?= h($A . '?v=settings#drivers') ?>">Add your drivers in Settings</a> (name and mobile), then they show here.</p>
    <?php else: ?>
      <p class="small" style="margin-top:0">Tap WhatsApp or Text next to a driver. It opens with the job ready to send (car, reg, name, phone, address, map, date, time, notes and a link for them to mark it collected). <?= $l['status'] === 'dispatched' ? '<b>Already sent to ' . h($OPS[$op]['name'] ?? 'a driver') . '.</b>' : '' ?></p>
      <?php foreach ($OPS as $id => $o): $lj = $l; $lj['op'] = (string)$id; $txt = job_text($lj); ?>
      <div class="oprow"><b><?= h($o['name']) ?><?= $op === (string)$id ? ' ✓' : '' ?></b>
        <a class="btn sm wa js-send" href="<?= h(send_link('whatsapp', $o['phone'], $txt)) ?>" data-op="<?= h((string)$id) ?>" data-via="whatsapp" data-refs="<?= h($l['ref']) ?>" rel="noopener">WhatsApp</a>
        <a class="btn sm ghost js-send" href="<?= h(send_link('sms', $o['phone'], $txt)) ?>" data-op="<?= h((string)$id) ?>" data-via="sms" data-refs="<?= h($l['ref']) ?>">Text</a></div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <form class="box" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="collected"><input type="hidden" name="ref" value="<?= h($l['ref']) ?>">
    <h2 style="margin-top:0">4. Collected</h2>
    <p class="small" style="margin-top:0">The driver can mark it collected from their link, or you can do it here.</p>
    <div class="grid">
      <label>Amount paid (£)<input type="number" name="paid" min="0" step="1" inputmode="numeric" value="<?= $price !== null ? (int)$price : '' ?>"></label>
      <label>Bank transfer reference<input name="pay_ref" placeholder="Optional"></label>
      <label>Note<input name="note" placeholder="Optional"></label>
    </div>
    <button class="btn go" type="submit" style="margin-top:12px">Mark as collected</button>
  </form>
  <?php elseif ($l['status'] === 'collected'): ?>
  <div class="box"><h2 style="margin-top:0">Collected</h2><dl class="kv">
    <dt>Collected</dt><dd><?= h((string)($l['collected_by'] ?? '')) ?><?= !empty($l['collected_at']) ? ', ' . h(date('D j M Y, H:i', strtotime($l['collected_at']))) : '' ?></dd>
    <dt>Paid</dt><dd><?= isset($l['paid']) && $l['paid'] !== null ? '£' . (int)$l['paid'] : '–' ?><?= !empty($l['pay_ref']) ? ' (ref ' . h($l['pay_ref']) . ')' : '' ?></dd>
    <?php if (!empty($l['driver_report'])): ?><dt>Driver's note</dt><dd><?= h($l['driver_report']) ?></dd><?php endif; ?>
    <?php if ($ck = $l['collection_check'] ?? null): $yn = fn($b) => $b ? '<span style="color:#1d7a3a">Yes</span>' : '<span style="color:var(--red)">No</span>'; ?>
    <dt>Complete</dt><dd><?= $yn($ck['complete']) ?><?= !$ck['complete'] ? ': ' . h($ck['missing']) : '' ?></dd>
    <dt>Battery</dt><dd><?= $yn($ck['battery']) ?></dd>
    <dt>Engine</dt><dd><?= $yn($ck['engine']) ?></dd>
    <dt>Catalytic converter</dt><dd><?= $yn($ck['cat']) ?></dd>
    <?php endif; ?>
  </dl>
  <?php if ($ck = $l['collection_check'] ?? null): $ph = fn($f) => h($A . '?v=photo&ref=' . $l['ref'] . '&f=' . $f); ?>
    <?php if (!$ck['complete'] || !$ck['battery'] || !$ck['engine'] || !$ck['cat']): ?><p style="color:var(--red);font-weight:700">Something is missing: check the agreed price before you pay the customer.</p><?php endif; ?>
    <p class="small" style="margin-bottom:4px">Photos (tap to open full size)</p>
    <div class="photos"><a href="<?= $ph($ck['id_photo']) ?>" target="_blank" rel="noopener"><img src="<?= $ph($ck['id_photo']) ?>" alt="Seller ID"><span>ID</span></a>
      <?php foreach ($ck['car_photos'] as $i => $cp): ?><a href="<?= $ph($cp) ?>" target="_blank" rel="noopener"><img src="<?= $ph($cp) ?>" alt="Car photo <?= $i + 1 ?>"><span>Car <?= $i + 1 ?></span></a><?php endforeach; ?></div>
  <?php endif; ?>
  <?php if (!isset($l['paid']) || $l['paid'] === null): ?>
  <form method="post" action="<?= h($A) ?>" style="margin-top:14px;border-top:1px solid var(--line);padding-top:12px"><?= $hidden ?><input type="hidden" name="act" value="collected"><input type="hidden" name="ref" value="<?= h($l['ref']) ?>">
    <h2 style="margin-top:0;font-size:17px">Record payment</h2>
    <div class="grid">
      <label>Amount paid (£)<input type="number" name="paid" min="0" step="1" inputmode="numeric" value="<?= $price !== null ? (int)$price : '' ?>" required></label>
      <label>Bank transfer reference<input name="pay_ref" placeholder="Optional"></label>
    </div>
    <button class="btn go" type="submit" style="margin-top:10px">Save payment</button>
  </form>
  <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($l['failed'])): ?>
  <div class="box" style="border-left:5px solid var(--red)"><h2 style="margin-top:0">Not collected</h2>
    <?php foreach (array_reverse($l['failed']) as $fa): ?>
      <p style="margin:8px 0 4px"><b><?= h((string)$fa['by']) ?></b> <span class="small"><?= h(adm_when($fa['at'])) ?></span><br><?= h((string)$fa['why']) ?></p>
      <?php if (!empty($fa['photos'])): ?><div class="photos"><?php foreach ($fa['photos'] as $i => $fpn): $u = h($A . '?v=photo&ref=' . $l['ref'] . '&f=' . $fpn); ?><a href="<?= $u ?>" target="_blank" rel="noopener"><img src="<?= $u ?>" alt="Photo <?= $i + 1 ?>"><span>Photo <?= $i + 1 ?></span></a><?php endforeach; ?></div><?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="grid" style="align-items:start">
    <div class="box">
      <h2 style="margin-top:0">Customer</h2>
      <dl class="kv">
        <dt>Name</dt><dd><?= h($l['name'] ?? '') ?: '–' ?></dd>
        <dt>Phone</dt><dd><?= h($l['phone'] ?? '') ?: '–' ?></dd>
        <dt>Email</dt><dd><?= h($l['email'] ?? '') ?: '–' ?></dd>
        <dt>Address</dt><dd><?= h(lead_address($l)) ?: '–' ?><?= $l['miles'] !== null ? ' (' . h((string)$l['miles']) . ' miles from Corby)' : '' ?></dd>
        <dt>Their note</dt><dd><?= h($l['collection_note'] ?? '') ?: '–' ?></dd>
        <dt>Reminders</dt><dd><?= !empty($l['reminders_ok']) ? 'Yes' . (!empty($l['reminded']) ? ($l['reminded'] === 'unsubscribed' ? ' (unsubscribed, none sent)' : ' (sent ' . h(adm_when($l['reminded'])) . ')') : '') : 'No' ?></dd>
        <?php if (!empty($l['accepted'])): ?><dt>Accepted</dt><dd><?= h(adm_when($l['accepted'])) ?></dd><?php endif; ?>
      </dl>
    </div>
    <div class="box">
      <h2 style="margin-top:0">Price</h2>
      <dl class="kv">
        <dt>Price given</dt><dd><?= $l['price'] === null ? 'None: needs a price' : '£' . (int)$l['price'] ?></dd>
        <?php if (isset($l['final_price']) && $l['final_price'] !== null): ?><dt>Agreed</dt><dd>£<?= (int)$l['final_price'] ?></dd><?php endif; ?>
        <dt>Held until</dt><dd><?= h(date('j M Y', strtotime($l['valid_until']))) ?></dd>
        <dt>Weight used</dt><dd><?= number_format((int)$l['weight_kg']) ?> kg <span class="small">(<?= h($l['weight_source']) ?>)</span></dd>
        <dt>Rate used</dt><dd>£<?= h((string)$l['rate_per_tonne']) ?> per tonne</dd>
        <?php if ($veh): ?><dt>Vehicle</dt><dd><?= h(implode(' · ', array_filter([vehicle_name($veh), !empty($veh['engine_cc']) ? number_format($veh['engine_cc'] / 1000, 1) . ' litre' : '', fuel_label($veh['fuel'] ?? ''), $veh['colour'] ?? '']))) ?></dd><?php endif; ?>
      </dl>
      <?php if (str_contains((string)$l['weight_source'], 'Claude') || str_contains((string)$l['weight_source'], 'One Auto')): ?><p class="small">Weight looks wrong? <a href="<?= h($A . '?v=rates#weights') ?>">Fix it for this model</a>.</p><?php endif; ?>
    </div>
  </div>

  <form class="box" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="lead"><input type="hidden" name="ref" value="<?= h($l['ref']) ?>">
    <h2 style="margin-top:0">Your notes</h2>
    <label>Only you see these<textarea name="admin_note" placeholder="e.g. customer wants the private plate kept"><?= h($l['admin_note'] ?? '') ?></textarea></label>
    <button class="btn" type="submit" style="margin-top:12px">Save notes</button>
  </form>
  <?php if (!empty($l['archived'])): ?>
  <form method="post" action="<?= h($A) ?>" style="margin:0 0 14px"><?= $hidden ?><input type="hidden" name="act" value="unarchive"><input type="hidden" name="ref" value="<?= h($l['ref']) ?>">
    <button class="btn ghost sm" type="submit">Restore to Leads</button></form>
  <?php elseif (in_array($l['status'], ['collected', 'lost'], true)): ?>
  <form method="post" action="<?= h($A) ?>" style="margin:0 0 14px"><?= $hidden ?><input type="hidden" name="act" value="archive"><input type="hidden" name="ref" value="<?= h($l['ref']) ?>">
    <button class="btn sm" type="submit">Archive this job</button> <span class="small">Moves it out of Leads. Still searchable in Archive.</span></form>
  <?php endif; ?>
  <?php if ($l['status'] !== 'collected' && empty($l['archived'])): ?>
  <form method="post" action="<?= h($A) ?>" style="margin:0 0 14px"><?= $hidden ?><input type="hidden" name="act" value="lead"><input type="hidden" name="ref" value="<?= h($l['ref']) ?>">
    <?php if ($l['status'] === 'lost'): ?><button class="btn ghost sm" type="submit" name="status" value="<?= !empty($l['collect_on']) ? 'booked' : ($l['price'] === null ? 'call-back' : 'accepted') ?>">Reopen this lead</button>
    <?php else: ?><button class="btn ghost sm" type="submit" name="status" value="lost" style="border-color:var(--red);color:var(--red)" onclick="return confirm('Mark <?= h($l['reg']) ?> as lost / declined?')">Mark as lost / declined</button><?php endif; ?>
  </form>
  <?php endif; ?>
  <?php if ($hist): ?><div class="box"><h2 style="margin-top:0">History</h2><table><?php foreach (array_reverse($hist) as $hh): ?><tr><td class="small" style="white-space:nowrap"><?= h(adm_when($hh['at'])) ?></td><td><?= h(ucfirst((string)$hh['by'])) ?></td><td><?= h($hh['what']) ?></td></tr><?php endforeach; ?></table></div><?php endif; ?>
  <?php endif; ?>

<?php
/* ================= COLLECTIONS BOARD ================= */
elseif ($v === 'collections'):
  $OPS = operatives($SET);
  $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['d'] ?? '')) ? (string)$_GET['d'] : date('Y-m-d');
  $all = adm_leads();
  $jobs = array_values(array_filter($all, fn($l) => ($l['collect_on'] ?? '') === $d && in_array($l['status'], ['booked', 'dispatched', 'collected'], true)));
  usort($jobs, fn($x, $y) => strcmp(slot_sort($x['collect_slot'] ?? '') . $x['ref'], slot_sort($y['collect_slot'] ?? '') . $y['ref']));
  $upcoming = []; foreach ($all as $l) if (!empty($l['collect_on']) && $l['collect_on'] >= date('Y-m-d') && in_array($l['status'], ['booked', 'dispatched'], true)) $upcoming[$l['collect_on']] = ($upcoming[$l['collect_on']] ?? 0) + 1;
  ksort($upcoming);
  $unassigned = count(array_filter($jobs, fn($l) => $l['status'] !== 'collected' && !isset($OPS[$l['op'] ?? ''])));
  $dl = fn($x) => h($A . '?v=collections&d=' . $x);
?>
  <h1>Collections</h1>
  <div class="pills">
    <a href="<?= $dl(date('Y-m-d', strtotime($d . ' -1 day'))) ?>">← Prev</a>
    <a class="<?= $d === date('Y-m-d') ? 'on' : '' ?>" href="<?= $dl(date('Y-m-d')) ?>">Today</a>
    <a class="<?= $d === date('Y-m-d', strtotime('+1 day')) ? 'on' : '' ?>" href="<?= $dl(date('Y-m-d', strtotime('+1 day'))) ?>">Tomorrow</a>
    <a href="<?= $dl(date('Y-m-d', strtotime($d . ' +1 day'))) ?>">Next →</a>
  </div>
  <form class="search" method="get" action="<?= h($A) ?>"><input type="hidden" name="v" value="collections"><input type="date" name="d" value="<?= h($d) ?>" aria-label="Date"><button class="btn" type="submit">Go</button></form>
  <?php if ($upcoming): ?><p class="small">Coming up: <?php $i = 0; foreach ($upcoming as $day => $n): if ($i++ > 9) break; ?><a href="<?= $dl($day) ?>"><?= h(date('D j M', strtotime($day))) ?> (<?= $n ?>)</a><?= $i <= min(9, count($upcoming) - 1) ? ' · ' : '' ?><?php endforeach; ?></p><?php endif; ?>
  <h2><?= h(date('l j F', strtotime($d))) ?>: <?= count($jobs) ?> job<?= count($jobs) === 1 ? '' : 's' ?></h2>
  <?php if (!$jobs): ?><div class="box empty">No collections booked for this day.</div>
  <?php else: ?>
    <?php if (!$OPS): ?><div class="box">No drivers saved yet. <a href="<?= h($A . '?v=settings#drivers') ?>">Add your drivers in Settings</a>, then pick a driver for each job here.</div><?php endif; ?>
    <form class="box" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="assign">
      <h2 style="margin-top:0">1. Pick a driver for each job</h2>
      <?php foreach ($jobs as $l): $done = $l['status'] === 'collected'; ?>
      <div class="job">
        <div><span class="plate"><?= h($l['reg']) ?></span></div>
        <div><a href="<?= h($A . '?v=lead&ref=' . $l['ref']) ?>"><b><?= h(slot_label($l['collect_slot'] ?? 'any', true)) ?></b> · <?= h(vehicle_name($l['vehicle'] ?? null) ?: 'Vehicle') ?></a>
          <div class="small"><?= h(($l['name'] ?? '') . ' · ' . lead_address($l)) ?></div>
          <div><?= adm_badge($STATUS, $l['status']) ?></div></div>
        <div><?php if ($done): ?><span class="small">Collected<?= !empty($l['collected_by']) ? ' by ' . h(str_replace('Driver: ', '', (string)$l['collected_by'])) : '' ?></span><?php else: ?>
          <select name="op_<?= h($l['ref']) ?>" aria-label="Driver for <?= h($l['reg']) ?>"><option value="">No driver</option><?php foreach ($OPS as $id => $o): ?><option value="<?= h((string)$id) ?>"<?= (string)($l['op'] ?? '') === (string)$id ? ' selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></select><?php endif; ?></div>
      </div>
      <?php endforeach; ?>
      <?php if ($OPS): ?><button class="btn go" type="submit" style="margin-top:12px">Save drivers</button><?php endif; ?>
    </form>
    <?php if ($OPS): ?>
    <div class="box">
      <h2 style="margin-top:0">2. Send each driver their jobs</h2>
      <?php if ($unassigned): ?><p class="small" style="color:var(--red);font-weight:700"><?= $unassigned ?> job<?= $unassigned === 1 ? ' has' : 's have' ?> no driver yet.</p><?php endif; ?>
      <?php $any = false; foreach ($OPS as $id => $o):
        $mine = array_values(array_filter($jobs, fn($l) => (string)($l['op'] ?? '') === (string)$id && $l['status'] !== 'collected'));
        if (!$mine) continue; $any = true;
        $unsent = count(array_filter($mine, fn($l) => $l['status'] === 'booked'));
        foreach (array_chunk($mine, 8) as $ci => $chunk):
          $n = count($mine); $txt = "Hi {$o['name']}, collections for " . date('D j M', strtotime($d)) . " ($n job" . ($n === 1 ? '' : 's') . "):\n\n";
          foreach ($chunk as $k => $l) $txt .= job_text($l, $ci * 8 + $k + 1, $n) . "\n\n";
          $refs = implode(',', array_column($chunk, 'ref'));
          $label = count($mine) > 8 ? ' (jobs ' . ($ci * 8 + 1) . '–' . ($ci * 8 + count($chunk)) . ')' : ''; ?>
      <div class="oprow"><b><?= h($o['name']) ?></b><span class="small"><?= $n ?> job<?= $n === 1 ? '' : 's' ?><?= $label ?><?= $unsent ? " · $unsent not sent yet" : ' · all sent' ?></span>
        <a class="btn sm wa js-send" href="<?= h(send_link('whatsapp', $o['phone'], trim($txt))) ?>" data-op="<?= h((string)$id) ?>" data-via="whatsapp" data-refs="<?= h($refs) ?>" rel="noopener">WhatsApp</a>
        <a class="btn sm ghost js-send" href="<?= h(send_link('sms', $o['phone'], trim($txt))) ?>" data-op="<?= h((string)$id) ?>" data-via="sms" data-refs="<?= h($refs) ?>">Text</a></div>
      <?php endforeach; endforeach; ?>
      <?php if (!$any): ?><p class="small">Pick drivers above and press Save drivers. Each driver then gets one button to send all their jobs in one message.</p><?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>

<?php
/* ================= NEW PHONE BOOKING ================= */
elseif ($v === 'new'):
  $g = fn($k) => h(mb_substr(trim((string)($_GET[$k] ?? '')), 0, 100)); ?>
  <p class="small"><a href="<?= h($A . '?v=leads') ?>">← All leads</a></p>
  <h1>New booking from a phone call</h1>
  <form class="box" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="new_lead">
    <div class="grid">
      <label>Registration<input name="reg" value="<?= $g('reg') ?>" required style="text-transform:uppercase" autocomplete="off"></label>
      <label>Customer name<input name="name" value="<?= $g('name') ?>" required autocomplete="off"></label>
      <label>Phone<input name="phone" type="tel" value="<?= $g('phone') ?>" required autocomplete="off"></label>
      <label>Email (optional)<input name="email" type="email" autocomplete="off"></label>
    </div>
    <div class="grid" style="margin-top:12px">
      <label>Address line 1<input name="line1" autocomplete="off"></label>
      <label>Address line 2<input name="line2" autocomplete="off"></label>
      <label>Town<input name="town" autocomplete="off"></label>
      <label>Postcode<input name="postcode" value="<?= $g('postcode') ?>" required style="text-transform:uppercase" autocomplete="off"></label>
    </div>
    <div class="grid" style="margin-top:12px">
      <label>Agreed price (£, optional)<input name="final_price" type="number" min="0" step="1" inputmode="numeric"><span class="hint">Leave blank to use the price from the reg lookup.</span></label>
      <label>Note<input name="note" placeholder="Anything the customer told you" autocomplete="off"></label>
    </div>
    <label class="tick" style="margin-top:12px"><input type="checkbox" name="lookup" value="1" checked> Look up the car and price from the reg (20p if it's a new reg)</label>
    <button class="btn go" type="submit" style="margin-top:12px">Add and go to booking</button>
  </form>

<?php
/* ================= ARCHIVE ================= */
elseif ($v === 'archive'):
  $OPS = operatives($SET);
  $all = adm_leads('archive');
  $q = trim((string)($_GET['q'] ?? '')); $st = in_array($_GET['s'] ?? '', ['collected', 'lost'], true) ? (string)$_GET['s'] : '';
  $words = array_filter(preg_split('/\s+/', strtolower($q)));
  $list = array_values(array_filter($all, function ($l) use ($words, $st, $OPS) {
    if ($st !== '' && $l['status'] !== $st) return false;
    if (!$words) return true;
    $hay = adm_haystack($l, $OPS);
    foreach ($words as $w) if (!str_contains($hay, $w)) return false;      // every word must match somewhere
    return true;
  }));
  $page = max(1, (int)($_GET['p'] ?? 1)); $per = 50; $pages = max(1, (int)ceil(count($list) / $per));
  $cnt = fn($s) => count(array_filter($all, fn($l) => $l['status'] === $s));
  $paid = array_sum(array_map(fn($l) => (int)($l['paid'] ?? 0), array_filter($list, fn($l) => $l['status'] === 'collected')));
?>
  <h1>Archive</h1>
  <p class="small" style="margin-top:-6px">Collected and lost jobs you've put away. Search anything recorded: reg, name, phone, email, address, postcode, car, driver, notes, dates (e.g. 17/10/2026 or "Oct 2026"), checklist answers.</p>
  <form class="search" method="get" action="<?= h($A) ?>"><input type="hidden" name="v" value="archive"><input type="hidden" name="s" value="<?= h($st) ?>"><input name="q" value="<?= h($q) ?>" placeholder="Search the archive" aria-label="Search the archive"><button class="btn" type="submit">Search</button></form>
  <div class="pills">
    <a class="<?= $st === '' ? 'on' : '' ?>" href="<?= h($A . '?v=archive&q=' . rawurlencode($q)) ?>">All<i><?= count($all) ?></i></a>
    <a class="<?= $st === 'collected' ? 'on' : '' ?>" href="<?= h($A . '?v=archive&s=collected&q=' . rawurlencode($q)) ?>">Collected<i><?= $cnt('collected') ?></i></a>
    <a class="<?= $st === 'lost' ? 'on' : '' ?>" href="<?= h($A . '?v=archive&s=lost&q=' . rawurlencode($q)) ?>">Lost<i><?= $cnt('lost') ?></i></a>
  </div>
  <?php if ($q !== '' || $st !== ''): ?><p class="small"><?= count($list) ?> result<?= count($list) === 1 ? '' : 's' ?><?= $paid ? ' · £' . number_format($paid) . ' paid out on these' : '' ?></p><?php endif; ?>
  <?php if (!$list): ?><div class="box empty"><?= $all ? 'Nothing matches that search.' : 'Nothing archived yet. Open a collected or lost job and press Archive, or use "Archive all" on the Collected and Lost lists.' ?></div><?php endif; ?>
  <?php foreach (array_slice($list, ($page - 1) * $per, $per) as $l): $veh = vehicle_name($l['vehicle'] ?? null); ?>
    <a class="lead" href="<?= h($A . '?v=lead&ref=' . $l['ref']) ?>">
      <div class="lead-top"><span class="plate"><?= h($l['reg']) ?></span><?= adm_badge($STATUS, $l['status']) ?><?php if (!empty($l['demo'])): ?><span class="badge" style="background:#fff3d6;color:#8a5a00">Test</span><?php endif; ?>
        <span class="price"><?= isset($l['paid']) && $l['paid'] !== null ? '£' . (int)$l['paid'] : (isset($l['final_price']) && $l['final_price'] !== null ? '£' . (int)$l['final_price'] : ($l['price'] === null ? '–' : '£' . (int)$l['price'])) ?></span></div>
      <div style="margin-top:6px;font-weight:700"><?= h($l['name'] ?? '') ?: '<span class="small">No contact details</span>' ?><?= $veh ? ' <span class="small">· ' . h($veh) . '</span>' : '' ?></div>
      <div class="meta"><?= h(lead_address($l) ?: (string)$l['postcode']) ?><?= !empty($l['collected_at']) ? ' · collected ' . h(date('j M Y', strtotime($l['collected_at']))) : '' ?><?= isset($OPS[$l['op'] ?? '']) ? ' · ' . h($OPS[$l['op']]['name']) : '' ?> · archived <?= h(date('j M Y', strtotime($l['archived']))) ?> · <?= h($l['ref']) ?></div>
    </a>
  <?php endforeach; ?>
  <?php if ($pages > 1): ?><div class="pills"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'on' : '' ?>" href="<?= h($A . '?v=archive&s=' . $st . '&q=' . rawurlencode($q) . '&p=' . $i) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
  <p class="small" style="margin-top:18px"><a href="<?= h($A . '?v=export') ?>">Download every lead, including the archive, as a spreadsheet (CSV)</a></p>

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
      <a class="btn ghost sm" style="margin-top:10px" href="<?= h($A . '?v=new&' . http_build_query(['name' => (string)($e['name'] ?? ''), 'phone' => (string)($e['phone'] ?? '')])) ?>">Make a booking</a>
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
        <td class="hide-m small"><?= h($w['source'] ?? '') ?> (<?= h($w['confidence'] ?? '') ?>)<?php if (preg_match('#^https?://#i', (string)($w['url'] ?? ''))): ?> · <a href="<?= h($w['url']) ?>" rel="noopener noreferrer" target="_blank">source</a><?php endif; ?></td></tr><?php endforeach; ?>
    </table><?php endif; ?>
  </div>
  <script>
  (()=>{const g=id=>document.getElementById(id);const calc=kg=>{let p=kg/1000*(+g('rt').value||0);const mn=g('mn').value,mx=g('mx').value;if(mn!=='')p=Math.max(+mn,p);if(mx!=='')p=Math.min(+mx,p);const s=+g('rd').value||5;return Math.round(p/s)*s};
  const upd=()=>document.querySelectorAll('#ex tr[data-kg]').forEach(r=>r.lastElementChild.innerHTML='<b>£'+calc(+r.dataset.kg)+'</b>');['rt','mn','mx','rd'].forEach(i=>g(i).addEventListener('input',upd))})();
  </script>

<?php
/* ================= NOTIFICATIONS ================= */
elseif ($v === 'notify'):
  $vap = push_vapid(); $DEV = push_devices(); $EV = push_events(); ?>
  <h1>Notifications</h1>
  <p class="small">Get a notification on your phone when something happens, even with this page closed. Turn them on once on each phone, then pick what each one gets.</p>
  <div class="box" id="thisdev">
    <h2 style="margin-top:0">This phone</h2>
    <?php if (!$vap): ?><p>Notifications can't be set up on this server yet. Please tell Lewis.</p>
    <?php else: ?>
    <p id="pstate" class="small">Checking&hellip;</p>
    <div class="acts"><button class="btn go" type="button" id="pon" hidden>Turn on notifications on this phone</button><button class="btn ghost" type="button" id="poff" hidden>Turn off on this phone</button></div>
    <div id="pios" hidden class="small" style="border-left:5px solid #FFD200;padding-left:10px">
      <b style="color:var(--navy)">On an iPhone, add the admin to your home screen first.</b> Apple only allows notifications from apps on the home screen.
      <ol style="margin:8px 0 0;padding-left:20px"><li>In Safari, tap the Share button (the square with an arrow).</li><li>Tap <b>Add to Home Screen</b>, then <b>Add</b>.</li><li>Open <b>Scrap admin</b> from your home screen, log in, and come back to Notifications.</li></ol>
    </div>
    <?php endif; ?>
  </div>
  <h2>Phones and computers getting notifications</h2>
  <?php if (!$DEV): ?><div class="box empty">None yet. Turn them on above, on each phone that should get them.</div><?php endif; ?>
  <?php foreach ($DEV as $d): ?>
  <form class="box" method="post" action="<?= h($A) ?>" id="d-<?= h($d['id']) ?>" data-dev="<?= h($d['id']) ?>"><?= $hidden ?><input type="hidden" name="id" value="<?= h($d['id']) ?>">
    <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:baseline">
      <h2 style="margin:0"><?= h($d['name']) ?> <span class="badge js-me" hidden style="color:#1d7a3a;background:#e3f6e8">This phone</span></h2>
      <span class="small">Turned on by <?= h(ucfirst((string)$d['user'])) ?>, <?= h(adm_when($d['added'])) ?><?= !empty($d['last_ok']) ? ' · last delivered ' . h(adm_when($d['last_ok'])) : '' ?></span>
    </div>
    <?php if (!empty($d['last_error_at']) && (empty($d['last_ok']) || $d['last_error_at'] > $d['last_ok'])): ?><p class="small" style="color:var(--red)">The last notification didn't go through (<?= h(adm_when($d['last_error_at'])) ?>: <?= h((string)$d['last_error']) ?>). Send a test, and if it fails again turn notifications off and on again on that phone.</p><?php endif; ?>
    <p class="small" style="margin:12px 0 6px">Send notifications to this device for:</p>
    <?php foreach ($EV as $k => [$label, $hint, $def]): ?>
    <label class="tick" style="align-items:flex-start;margin:10px 0"><input type="checkbox" name="ev_<?= h($k) ?>" value="1"<?= ($d['events'][$k] ?? $def) ? ' checked' : '' ?>><span><?= h($label) ?><span class="hint" style="display:block"><?= h($hint) ?></span></span></label>
    <?php endforeach; ?>
    <label style="margin-top:12px">Name for this device<input name="name" value="<?= h($d['name']) ?>" maxlength="40" placeholder="e.g. George's iPhone"></label>
    <div class="acts">
      <button class="btn go" type="submit" name="act" value="push_prefs">Save</button>
      <button class="btn ghost" type="submit" name="act" value="push_test">Send a test</button>
      <button class="btn ghost" type="submit" name="act" value="push_del" onclick="return confirm('Stop all notifications to <?= h(addslashes($d['name'])) ?>?')">Remove</button>
    </div>
  </form>
  <?php endforeach; ?>
  <p class="small">Lead alert emails carry on as before (Settings &gt; Emails). Notifications are extra.</p>
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
      <dt>Reg &amp; weight lookup</dt><dd><?= $dot($LIVE_LOOKUP) ?><?= $CFG['oneauto_key'] !== '' ? 'On (One Auto API)' : ($LIVE_LOOKUP ? 'On (DVSA)' : 'Off: add the One Auto API key below. Until then every car is priced at ' . number_format((int)$PR['default_kg']) . ' kg') ?></dd>
      <dt>Backup weight lookup</dt><dd><?= $dot(!empty($CFG['anthropic_key'])) ?><?= !empty($CFG['anthropic_key']) ? 'On (Claude, used only if a car has no weight)' : 'Off' ?></dd>
      <dt>Last reminder run</dt><dd><?= $rl ? h($rl[0]) : 'Not run yet' ?></dd>
    </dl>
  </div>
  <div class="box" id="drivers">
    <h2 style="margin-top:0">Drivers</h2>
    <p class="small">Save your drivers once. They then appear on every booking and on Collections, so sending a job is one tap.</p>
    <?php $OPSS = operatives($SET); foreach ($OPSS as $id => $o): ?>
    <form class="oprow" method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="id" value="<?= h((string)$id) ?>">
      <input name="op_name" value="<?= h($o['name']) ?>" aria-label="Name" style="max-width:160px;margin:0">
      <input name="op_phone" type="tel" value="<?= h($o['phone']) ?>" aria-label="Mobile" style="max-width:170px;margin:0">
      <button class="btn sm" type="submit" name="act" value="op_save">Save</button>
      <button class="btn sm ghost" type="submit" name="act" value="op_del" onclick="return confirm('Remove <?= h(addslashes($o['name'])) ?>?')">Remove</button>
    </form>
    <?php endforeach; ?>
    <form method="post" action="<?= h($A) ?>" style="margin-top:12px"><?= $hidden ?><input type="hidden" name="act" value="op_save">
      <div class="grid"><label>Driver name<input name="op_name" autocomplete="off"></label><label>Mobile<input name="op_phone" type="tel" autocomplete="off" placeholder="07..."></label></div>
      <button class="btn go" type="submit" style="margin-top:10px">Add driver</button>
    </form>
  </div>
  <div class="box" id="lookup">
    <h2 style="margin-top:0">Vehicle lookup (One Auto API)</h2>
    <p class="small">Finds the make, model and kerb weight from the reg. Each new reg costs 20p from your One Auto API credit; the same reg again within 30 days is free. At most <?= (int)($CFG['lookup_daily_cap'] ?? 150) ?> paid lookups a day, after that customers get a "we'll call you" price.</p>
    <dl class="kv"><dt>Key</dt><dd><?= $CFG['oneauto_key'] !== '' ? 'Saved (ends &hellip;' . h(substr($CFG['oneauto_key'], -4)) . ')' : 'Not added yet' ?></dd>
      <dt>Lookups this month</dt><dd><?= number_format(lookups_this_month()) ?> (about £<?= number_format(lookups_this_month() * 0.2, 2) ?>)</dd></dl>
    <form method="post" action="<?= h($A) ?>"><?= $hidden ?><input type="hidden" name="act" value="oneauto_key">
      <label><?= $CFG['oneauto_key'] !== '' ? 'Replace the key' : 'Paste your One Auto API key' ?><input type="password" name="oneauto_key" autocomplete="off" spellcheck="false"></label>
      <div class="acts" style="margin-top:10px"><button class="btn go" type="submit">Save key</button><?php if ($CFG['oneauto_key'] !== ''): ?><button class="btn ghost" type="submit" name="remove" value="1" onclick="this.form.oneauto_key.value='';return confirm('Remove the key? Prices will use the average weight.')">Remove key</button><?php endif; ?></div>
    </form>
    <?php if ($CFG['oneauto_key'] !== ''): ?>
    <form method="post" action="<?= h($A) ?>" style="margin-top:14px"><?= $hidden ?><input type="hidden" name="act" value="oneauto_test">
      <label>Test with a registration (costs 20p)<input type="text" name="reg" maxlength="8" autocomplete="off" style="text-transform:uppercase" placeholder="e.g. AB12CDE"></label>
      <button class="btn ghost" type="submit" style="margin-top:10px">Look it up</button>
    </form>
    <?php endif; ?>
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
<script>
(()=>{const A=<?= json_encode($A) ?>,C=<?= json_encode($csrf) ?>;
document.querySelectorAll('.js-send').forEach(a=>a.addEventListener('click',()=>{const f=new FormData();f.append('act','sent');f.append('csrf',C);f.append('op',a.dataset.op);f.append('via',a.dataset.via);f.append('refs',a.dataset.refs);try{navigator.sendBeacon(A,f)}catch(e){}}));
const t=document.getElementById('slottime');if(t){document.querySelectorAll('#slots input').forEach(r=>r.addEventListener('change',()=>{const on=document.querySelector('#slots input:checked').value==='time';t.hidden=!on;t.required=on;if(on)t.focus()}))}
document.querySelectorAll('select[name^="op_"]').forEach(s=>s.addEventListener('change',()=>s.closest('.job').style.background='#fffbea'));
const ps=document.getElementById('pstate');
if(ps){const on=document.getElementById('pon'),off=document.getElementById('poff'),ios=document.getElementById('pios'),KEY=<?= json_encode(($v === 'notify' && ($vk = push_vapid())) ? $vk['pub'] : '') ?>,KNOWN=<?= json_encode($v === 'notify' ? array_keys(push_devices()) : []) ?>;
  const say=t=>{ps.textContent=t},post=o=>{const f=new FormData();f.append('csrf',C);for(const k in o)f.append(k,o[k]);return fetch(A,{method:'POST',body:f,credentials:'same-origin'}).then(r=>r.json())};
  const keyBytes=b=>{b=b.replace(/-/g,'+').replace(/_/g,'/');b+='='.repeat((4-b.length%4)%4);return Uint8Array.from(atob(b),c=>c.charCodeAt(0))};
  const idOf=async ep=>[...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(ep)))].map(x=>x.toString(16).padStart(2,'0')).join('').slice(0,24);
  const isIOS=/iPhone|iPad|iPod/.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1),standalone=navigator.standalone===true||matchMedia('(display-mode: standalone)').matches;
  const show=async()=>{on.hidden=off.hidden=true;
    if(!('serviceWorker' in navigator)||!('PushManager' in window)||!('Notification' in window)){if(isIOS&&!standalone){ios.hidden=false;say('Not set up on this iPhone yet.')}else say('This browser can\'t get notifications. Use Chrome on Android, or Safari on iPhone (added to the home screen).');return}
    if(Notification.permission==='denied'){say('Notifications are blocked for this site on this phone. Allow them in the phone\'s settings (Settings > Notifications, or the padlock in the address bar), then reload this page.');return}
    const reg=await navigator.serviceWorker.getRegistration(A),sub=reg&&await reg.pushManager.getSubscription();
    const id=sub&&await idOf(sub.endpoint);
    if(sub&&KNOWN.includes(id)){say('Notifications are on for this phone. Pick what it gets below.');off.hidden=false;off.dataset.id=id;const me=document.querySelector('[data-dev="'+id+'"] .js-me');if(me)me.hidden=false;return}
    say('Notifications are off on this phone.');on.hidden=false};
  on.addEventListener('click',async()=>{on.disabled=true;say('Turning on…');
    try{const perm=await Notification.requestPermission();   // ask first, straight from the tap (iPhone needs that)
      if(perm!=='granted'){say('You didn\'t allow notifications. Tap the button again and choose Allow.');on.disabled=false;return}
      const reg=await navigator.serviceWorker.register(A+'sw.js',{scope:A});await navigator.serviceWorker.ready;
      let sub=await reg.pushManager.getSubscription();
      if(sub&&sub.options&&sub.options.applicationServerKey&&btoa(String.fromCharCode(...new Uint8Array(sub.options.applicationServerKey)))!==btoa(String.fromCharCode(...keyBytes(KEY)))){await sub.unsubscribe();sub=null}
      if(!sub)sub=await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:keyBytes(KEY)});
      const j=sub.toJSON(),r=await post({act:'push_sub',endpoint:j.endpoint,p256dh:j.keys.p256dh,auth:j.keys.auth});
      if(!r.ok){say(r.msg||'That didn\'t work. Please tell Lewis.');on.disabled=false;return}
      location.href=A+'?v=notify&on='+r.id+'#d-'+r.id}
    catch(e){say('That didn\'t work ('+(e&&e.message||e)+'). Please tell Lewis.');on.disabled=false}});
  off.addEventListener('click',async()=>{if(!confirm('Stop notifications on this phone?'))return;off.disabled=true;
    try{const reg=await navigator.serviceWorker.getRegistration(A),sub=reg&&await reg.pushManager.getSubscription();if(sub)await sub.unsubscribe();await post({act:'push_off',id:off.dataset.id||''})}catch(e){}
    location.reload()});
  show().catch(e=>say('Couldn\'t check this phone ('+(e&&e.message||e)+').'))}
})();
</script>
</body>
</html>
