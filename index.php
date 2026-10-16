<?php
/* We Buy Scrap Cars Corby — site engine + instant price tool (Build Bro).
   One router + template. Page copy lives in data/core and data/areas.
   Secrets (API keys) live OUTSIDE the web root in CONFIG_FILE, never in this repo. */
declare(strict_types=1);

/* Two homes for the same code: the Build Bro preview, and George's own hosting. */
define('SC_APP', true);
date_default_timezone_set('Europe/London');
define('ON_BUILDBRO', str_contains(__DIR__, '/clients/scrap-cars/'));
if (ON_BUILDBRO) {
  define('BASE', '/clients/scrap-cars/site/');
  define('SITE_URL', 'https://buildbro.app/clients/scrap-cars/site');
  define('STORE', getenv('SC_STORE') ?: '/home1/sufktgte/buildbro-intake/scrap-cars');                 // leads, caches (outside public_html)
  define('CONFIG_FILE', getenv('SC_CONFIG') ?: '/home1/sufktgte/buildbro-intake/scrap-cars-config.php'); // API keys
} else {
  $home = preg_match('#^(/home\d*/[^/]+)/#', __DIR__, $m) ? $m[1] : dirname(__DIR__, 2);                  // account home, e.g. /home2/khekowte (never inside public_html)
  define('BASE', '/');
  define('SITE_URL', 'https://' . (preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: 'www.webuyscrapcars-corby.co.uk'));
  define('STORE', "$home/scrap-cars-data");
  define('CONFIG_FILE', "$home/scrap-cars-config.php");
}
const UPDATED = '2026-10-14';

$D = __DIR__ . '/data';
$S = json_decode(file_get_contents("$D/site.json"), true);
$SV = []; foreach ($S['services'] as $x) $SV[$x['slug']] = $x;
$AR = []; foreach ($S['areas'] as $x) $AR[$x['slug']] = $x;
$GUIDES = ['how-to-scrap-a-car' => 'How to scrap a car legally', 'certificate-of-destruction' => 'Certificate of Destruction explained', 'scrap-car-without-logbook' => 'Scrapping a car with no logbook', 'scrap-or-repair' => 'Should I scrap or repair my car?', 'how-scrap-car-prices-work' => 'How scrap car prices work'];
$CFG = is_file(CONFIG_FILE) ? (include CONFIG_FILE) : [];
if (!is_array($CFG)) $CFG = [];
if (!str_starts_with(trim((string)($CFG['anthropic_key'] ?? '')), 'sk-ant-') && is_file(STORE . '/keys/anthropic.key')) $CFG['anthropic_key'] = (string)file_get_contents(STORE . '/keys/anthropic.key');   // key written by the Make tool
if (!str_starts_with(trim((string)($CFG['anthropic_key'] ?? '')), 'sk-ant-')) $CFG['anthropic_key'] = '';   // placeholder or blank = off
else $CFG['anthropic_key'] = trim($CFG['anthropic_key']);
define('PREVIEW', ON_BUILDBRO || empty($CFG['live']));         // noindex + no emails to customers until 'live' => true is set in George's config file
/* George's own settings (rates, reminders), saved from the admin page */
$SET = is_file(STORE . '/settings.json') ? (json_decode((string)file_get_contents(STORE . '/settings.json'), true) ?: []) : [];
$PR = array_merge($S['pricing_default'], $CFG['pricing'] ?? [], $SET['pricing'] ?? []);
$REM = array_merge(['on' => true, 'days_before' => 2], $SET['reminders'] ?? []);
$NOTIFY = filter_var($SET['notify_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: $S['email'];
$LIVE_LOOKUP = !empty($CFG['mot_api_key']) && !empty($CFG['mot_client_id']);
$DEMO = !$LIVE_LOOKUP;
if (PREVIEW) header('X-Robots-Tag: noindex, nofollow');
$_GET = array_filter($_GET, 'is_string'); $_POST = array_filter($_POST, 'is_string');   // no array params (reg[]=x): every field here is a plain string

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function u(string $p = ''): string { $p = trim($p, '/'); return BASE . ($p === '' ? '' : "$p/"); }
function abs_url(string $p = ''): string { $p = trim($p, '/'); return SITE_URL . '/' . ($p === '' ? '' : "$p/"); }
function img(string $n): string { return BASE . "img/$n.webp"; }

/* Page source format: === key / field: value / --- / markdown / --- / Q: A: */
function parse_pages(string $txt): array {
  $out = [];
  $parts = preg_split('/^===\s+(\S+)\s*$/m', str_replace("\r", '', $txt), -1, PREG_SPLIT_DELIM_CAPTURE);
  for ($i = 1; $i < count($parts); $i += 2) {
    $sec = preg_split('/^---\s*$/m', $parts[$i + 1], 3);
    $p = ['faqs' => []];
    foreach (explode("\n", $sec[0]) as $l) if (preg_match('/^([a-z0-9_]+):\s*(.*)$/', trim($l), $m)) $p[$m[1]] = trim($m[2]);
    $p['body'] = trim($sec[1] ?? '');
    if (isset($sec[2]) && preg_match_all('/^Q:\s*(.+?)\s*\nA:\s*(.+?)\s*$/m', $sec[2], $mm, PREG_SET_ORDER)) foreach ($mm as $q) $p['faqs'][] = [$q[1], $q[2]];
    $out[$parts[$i]] = $p;
  }
  return $out;
}
function core_page(string $key): ?array {
  static $all = null;
  if ($all === null) { $all = []; foreach (glob(__DIR__ . '/data/core/*.txt') as $f) $all += parse_pages(file_get_contents($f)); }
  return $all[$key] ?? null;
}
function area_page(string $area, string $key): ?array {
  $f = __DIR__ . "/data/areas/$area.txt";
  return is_file($f) ? (parse_pages(file_get_contents($f))[$key] ?? null) : null;
}
function slugify(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strip_tags($s))), '-'); }
function md_toc(string $t): array { preg_match_all('/^## (.+)$/m', $t, $m); return array_map(fn($x) => [slugify($x), preg_replace('/\*\*|\[|\]\([^)]*\)/', '', $x)], $m[1]); }
function md(string $t): string {
  $inline = function (string $s): string {
    $s = h($s);
    $s = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $s);
    return preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
      $href = html_entity_decode($m[2]);
      if ($href[0] === '/') $href = u($href);
      return '<a href="' . h($href) . '">' . $m[1] . '</a>';
    }, $s);
  };
  $out = '';
  foreach (preg_split("/\n{2,}/", trim(str_replace("\r", '', $t))) as $b) {
    $lines = explode("\n", $b);
    if (str_starts_with($b, '### ')) $out .= '<h3>' . $inline(substr($b, 4)) . "</h3>\n";
    elseif (str_starts_with($b, '## ')) { $txt = substr($b, 3); $out .= '<h2 id="' . slugify($txt) . '">' . $inline($txt) . "</h2>\n"; }
    elseif (str_starts_with($b, '|')) {
      $rows = array_values(array_filter($lines, fn($l) => !preg_match('/^\|\s*:?-{2,}/', $l)));
      $out .= "<div class=\"tbl\"><table>\n";
      foreach ($rows as $ri => $l) { $cells = array_map('trim', explode('|', trim($l, " |"))); $tag = $ri ? 'td' : 'th';
        $out .= '<tr>' . implode('', array_map(fn($c) => "<$tag>" . $inline($c) . "</$tag>", $cells)) . "</tr>\n"; }
      $out .= "</table></div>\n";
    } elseif (str_starts_with($b, '- ')) {
      $out .= "<ul>\n"; foreach ($lines as $l) $out .= '<li>' . $inline(ltrim(substr($l, 2))) . "</li>\n"; $out .= "</ul>\n";
    } elseif (preg_match('/^\d+\. /', $b)) {
      $out .= "<ol>\n"; foreach ($lines as $l) $out .= '<li>' . $inline(preg_replace('/^\d+\.\s*/', '', $l)) . "</li>\n"; $out .= "</ol>\n";
    } else $out .= '<p>' . $inline(implode(' ', $lines)) . "</p>\n";
  }
  return $out;
}

/* ================= price tool back end ================= */
function store_path(string $sub): string { $d = STORE . "/$sub"; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
function clean_reg(string $r): string { return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($r)), 0, 8); }
function clean_pc(string $p): string { return substr(preg_replace('/[^A-Z0-9 ]/', '', strtoupper(trim($p))), 0, 9); }
function jout(array $a): void { header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function http_json(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 10): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$code, $res === false ? null : json_decode((string)$res, true)];
}
/* simple per-IP rate limit (count per hour) */
function rate_ok(string $bucket, int $max): bool {
  $ip = preg_replace('/[^0-9a-f:.]/i', '', $_SERVER['REMOTE_ADDR'] ?? 'x');
  $f = store_path('rate') . '/' . md5("$bucket|$ip|" . gmdate('YmdH'));
  $n = (int)@file_get_contents($f) + 1; @file_put_contents($f, (string)$n);
  return $n <= $max;
}
/* DVSA MOT History API: make, model, fuel, engine size, colour, first used date. */
function mot_token(array $C): ?string {
  $f = store_path('cache') . '/mot-token.json';
  $t = @json_decode((string)@file_get_contents($f), true);
  if ($t && $t['exp'] > time() + 60) return $t['tok'];
  [$code, $j] = http_json('POST', 'https://login.microsoftonline.com/' . rawurlencode($C['mot_tenant'] ?? '') . '/oauth2/v2.0/token', ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query(['grant_type' => 'client_credentials', 'client_id' => $C['mot_client_id'], 'client_secret' => $C['mot_client_secret'] ?? '', 'scope' => 'https://tapi.dvsa.gov.uk/.default']));
  if ($code !== 200 || empty($j['access_token'])) return null;
  @file_put_contents($f, json_encode(['tok' => $j['access_token'], 'exp' => time() + (int)($j['expires_in'] ?? 900)]));
  return $j['access_token'];
}
function vehicle_lookup(string $reg, array $C): ?array {
  $cf = store_path('cache') . "/veh-$reg.json";
  if (is_file($cf) && filemtime($cf) > time() - 86400 * 30) return json_decode(file_get_contents($cf), true);
  if (empty($C['mot_api_key'])) return null;
  $tok = mot_token($C); if (!$tok) return null;
  [$code, $j] = http_json('GET', ($C['mot_base'] ?? 'https://history.mot.api.gov.uk') . '/v1/trade/vehicles/registration/' . rawurlencode($reg), ['Authorization: Bearer ' . $tok, 'X-API-Key: ' . $C['mot_api_key'], 'Accept: application/json']);
  if ($code !== 200 || empty($j['make'])) return null;
  $date = $j['manufactureDate'] ?? $j['firstUsedDate'] ?? $j['registrationDate'] ?? '';
  $v = ['reg' => $reg, 'make' => ucwords(strtolower((string)$j['make'])), 'model' => strtoupper((string)($j['model'] ?? '')) === (string)($j['model'] ?? '') ? ucwords(strtolower((string)($j['model'] ?? ''))) : (string)($j['model'] ?? ''),
    'fuel' => ucfirst(strtolower((string)($j['fuelType'] ?? ''))), 'engine_cc' => (int)($j['engineSize'] ?? 0), 'colour' => ucfirst(strtolower((string)($j['primaryColour'] ?? ''))), 'year' => $date ? (int)substr((string)$date, 0, 4) : null, 'source' => 'DVSA MOT history'];
  @file_put_contents($cf, json_encode($v));
  return $v;
}
/* Kerb weight: cached per make/model/engine/year; looked up once with Claude + web search, sanity-checked. */
function weight_key(array $v): string { return slugify("{$v['make']} {$v['model']} {$v['engine_cc']} {$v['fuel']} " . ($v['year'] ?? '')); }
function weight_cached(array $v): ?array { $f = store_path('weights') . '/' . weight_key($v) . '.json'; return is_file($f) ? json_decode(file_get_contents($f), true) : null; }
function weight_lookup(array $v, array $C): ?array {
  if ($w = weight_cached($v)) return $w;
  if (empty($C['anthropic_key'])) return null;
  $q = "UK-market {$v['year']} {$v['make']} {$v['model']}, {$v['engine_cc']}cc {$v['fuel']}. Find the manufacturer's kerb weight (unladen weight) in kg for this exact vehicle. "
     . "Search the web, prefer manufacturer spec sheets or established spec databases. Reply with ONLY a JSON object: {\"kerb_weight_kg\": number, \"source_url\": string, \"confidence\": \"high\"|\"medium\"|\"low\", \"note\": string}. If you cannot find it, give your best estimate with confidence low.";
  [$code, $j] = http_json('POST', 'https://api.anthropic.com/v1/messages', ['x-api-key: ' . $C['anthropic_key'], 'anthropic-version: 2023-06-01', 'content-type: application/json'],
    json_encode(['model' => $C['anthropic_model'] ?? 'claude-sonnet-5-5', 'max_tokens' => 1024, 'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]], 'messages' => [['role' => 'user', 'content' => $q]]]), 60);
  if ($code !== 200 || empty($j['content'])) return null;
  $txt = ''; foreach ($j['content'] as $b) if (($b['type'] ?? '') === 'text') $txt = $b['text'];
  if (!preg_match('/\{.*\}/s', $txt, $m) || !($r = json_decode($m[0], true)) || empty($r['kerb_weight_kg'])) return null;
  $kg = (int)$r['kerb_weight_kg'];
  if ($kg < 500 || $kg > 3500) return null;                      // reject anything implausible for a car or van up to 3.5t
  $w = ['kg' => $kg, 'source' => 'Claude web search', 'url' => (string)($r['source_url'] ?? ''), 'confidence' => (string)($r['confidence'] ?? 'low'), 'note' => (string)($r['note'] ?? ''), 'at' => gmdate('c')];
  @file_put_contents(store_path('weights') . '/' . weight_key($v) . '.json', json_encode($w, JSON_PRETTY_PRINT));
  return $w;
}
/* postcode -> lat/lng (postcodes.io, free, no key) */
function pc_lookup(string $pc): ?array {
  $cf = store_path('cache') . '/pc-' . slugify($pc) . '.json';
  if (is_file($cf)) return json_decode(file_get_contents($cf), true);
  [$code, $j] = http_json('GET', 'https://api.postcodes.io/postcodes/' . rawurlencode($pc));
  if ($code !== 200 || empty($j['result']['latitude'])) return null;
  $r = ['pc' => $j['result']['postcode'], 'lat' => $j['result']['latitude'], 'lng' => $j['result']['longitude'], 'town' => $j['result']['admin_district'] ?? ''];
  @file_put_contents($cf, json_encode($r)); return $r;
}
function miles(array $a, array $b): float {
  $R = 3958.8; $dLat = deg2rad($b[0] - $a[0]); $dLng = deg2rad($b[1] - $a[1]);
  $x = sin($dLat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLng / 2) ** 2;
  return 2 * $R * asin(sqrt($x));                                  // straight line: the collection area is a radius around Corby
}
function save_json(string $file, array $a): bool { return @file_put_contents($file, json_encode($a, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false; }
function load_lead(string $ref): ?array { $ref = preg_replace('/[^A-Z0-9]/', '', strtoupper($ref)); $f = STORE . "/leads/$ref.json"; return ($ref !== '' && is_file($f)) ? json_decode((string)file_get_contents($f), true) : null; }
function save_lead(array $l): bool { return save_json(store_path('leads') . '/' . $l['ref'] . '.json', $l); }
function price_for(int $kg, array $PR): int {
  $p = $kg / 1000 * (float)$PR['rate_per_tonne'];
  if (is_numeric($PR['min_price'] ?? null)) $p = max((float)$PR['min_price'], $p);
  if (is_numeric($PR['max_price'] ?? null)) $p = min((float)$PR['max_price'], $p);
  $step = max(1, (int)($PR['round_to'] ?? 5));
  return (int)(round($p / $step) * $step);
}

/* ---------- email ---------- */
function app_secret(): string {
  $f = store_path('keys') . '/secret.key';
  if (!is_file($f)) @file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
  return (string)@file_get_contents($f);
}
function unsub_token(string $email): string { return substr(hash_hmac('sha256', strtolower(trim($email)), app_secret()), 0, 24); }
function unsubscribed(string $email): bool { return is_file(STORE . '/unsub/' . sha1(strtolower(trim($email)))); }
function send_mail(string $to, string $subject, string $text, ?string $html = null, ?string $replyTo = null): bool {
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  $host = (string)parse_url(SITE_URL, PHP_URL_HOST); $from = 'website@' . preg_replace('/^www\./', '', $host);
  $subject = '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], ' ', $subject)) . '?=';
  $h = "From: We Buy Scrap Cars Corby <$from>\r\nMIME-Version: 1.0\r\n" . ($replyTo ? "Reply-To: $replyTo\r\n" : '');
  if ($html === null) { $h .= "Content-Type: text/plain; charset=UTF-8"; $body = $text; }
  else { $b = 'b' . bin2hex(random_bytes(8)); $h .= "Content-Type: multipart/alternative; boundary=\"$b\"";
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--"; }
  $ok = @mail($to, $subject, $body, $h, "-f$from");
  @file_put_contents(store_path('logs') . '/mail-' . date('Y-m') . '.log', date('c') . ' ' . ($ok ? 'SENT' : 'FAIL') . " $to " . base64_decode(substr($subject, 10, -2)) . "\n", FILE_APPEND | LOCK_EX);
  return $ok;
}
/* Branded customer email. $paras are plain text; $rows are [label, value]; $cta is [text, url]. */
function customer_email(array $S, string $title, array $paras, array $rows = [], ?array $cta = null, ?string $unsubUrl = null): array {
  $txt = "$title\n\n" . implode("\n\n", $paras) . "\n\n";
  foreach ($rows as $r) $txt .= "$r[0]: $r[1]\n";
  if ($cta) $txt .= "\n$cta[0]: $cta[1]\n";
  $txt .= "\n{$S['name']}\nCall or WhatsApp {$S['phone']}\n" . ($unsubUrl ? "\nStop these reminders: $unsubUrl\n" : '');
  $logo = SITE_URL . '/img/logo-512.png';
  $html = '<!doctype html><html><body style="margin:0;background:#eef1f6;font-family:Arial,Helvetica,sans-serif;color:#10224A">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f6;padding:24px 12px"><tr><td align="center">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden">'
    . '<tr><td style="background:#0A1733;padding:18px 24px"><img src="' . h($logo) . '" width="44" height="44" alt="" style="vertical-align:middle;border-radius:50%"> <span style="color:#fff;font-size:18px;font-weight:bold;vertical-align:middle;margin-left:8px">We Buy Scrap Cars Corby</span></td></tr>'
    . '<tr><td style="padding:24px"><h1 style="font-size:22px;margin:0 0 14px">' . h($title) . '</h1>';
  foreach ($paras as $p) $html .= '<p style="font-size:16px;line-height:1.5;margin:0 0 14px">' . h($p) . '</p>';
  if ($rows) { $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #dde3ee;border-radius:8px;margin:6px 0 18px">';
    foreach ($rows as $i => $r) $html .= '<tr><td style="padding:10px 14px;font-size:15px;color:#51607d;' . ($i ? 'border-top:1px solid #dde3ee' : '') . '">' . h($r[0]) . '</td><td style="padding:10px 14px;font-size:15px;font-weight:bold;text-align:right;' . ($i ? 'border-top:1px solid #dde3ee' : '') . '">' . h($r[1]) . '</td></tr>';
    $html .= '</table>'; }
  if ($cta) $html .= '<p style="margin:0 0 18px"><a href="' . h($cta[1]) . '" style="display:inline-block;background:#3FBF5F;color:#0A1733;font-weight:bold;font-size:16px;padding:13px 22px;border-radius:8px;text-decoration:none">' . h($cta[0]) . '</a></p>';
  $html .= '<p style="font-size:15px;line-height:1.5;margin:0">Call or WhatsApp George on <a href="tel:' . h($S['phone_intl']) . '" style="color:#10224A;font-weight:bold">' . h($S['phone']) . '</a>, or just reply to this email.</p></td></tr>'
    . '<tr><td style="background:#f5f7fb;padding:14px 24px;font-size:12px;color:#51607d;line-height:1.5">' . h($S['name']) . ', Corby, Northamptonshire. Free collection. Paid by bank transfer.'
    . ($unsubUrl ? '<br><a href="' . h($unsubUrl) . '" style="color:#51607d">Stop price reminders</a>' : '') . '</td></tr></table></td></tr></table></body></html>';
  return [$txt, $html];
}
function vehicle_name(?array $v): string { return $v ? trim(($v['year'] ?? '') . ' ' . ($v['make'] ?? '') . ' ' . ($v['model'] ?? '')) : ''; }
function price_email_rows(array $l): array {
  $rows = [['Reference', $l['ref']], ['Registration', $l['reg']]];
  if ($v = vehicle_name($l['vehicle'] ?? null)) $rows[] = ['Vehicle', $v];
  if ($l['price'] !== null) { $rows[] = ['Your price', '£' . $l['price']]; $rows[] = ['Price held until', date('j F Y', strtotime($l['valid_until']))]; }
  return $rows;
}
/* Reminder run: once a day (cron, or the first visit after 9am). One reminder per lead, sent shortly before the price runs out. */
function run_reminders(array $S, array $REM, bool $force = false): array {
  $out = ['sent' => 0, 'checked' => 0, 'skipped' => 'no'];
  if (PREVIEW && !$force) return ['sent' => 0, 'checked' => 0, 'skipped' => 'preview'];
  if (empty($REM['on'])) return ['sent' => 0, 'checked' => 0, 'skipped' => 'reminders off'];
  $today = date('Y-m-d');
  foreach (glob(STORE . '/leads/*.json') ?: [] as $f) {
    $l = json_decode((string)file_get_contents($f), true); if (!$l) continue;
    if (empty($l['reminders_ok']) || empty($l['email']) || !empty($l['reminded']) || $l['price'] === null) continue;
    if (!in_array($l['status'], ['saved', 'accepted'], true)) continue;           // booked, collected or lost: leave them alone
    $out['checked']++;
    $daysLeft = (int)round((strtotime($l['valid_until']) - strtotime($today)) / 86400);
    if ($daysLeft < 0 || $daysLeft > (int)$REM['days_before']) continue;
    if (unsubscribed($l['email'])) { $l['reminded'] = 'unsubscribed'; save_json($f, $l); continue; }
    $first = trim(explode(' ', (string)($l['name'] ?? ''))[0]);
    $unsub = abs_url('unsubscribe') . '?e=' . rawurlencode($l['email']) . '&t=' . unsub_token($l['email']);
    $when = $daysLeft === 0 ? 'today' : ($daysLeft === 1 ? 'tomorrow' : 'on ' . date('l j F', strtotime($l['valid_until'])));
    $paras = [($first ? "Hi $first," : 'Hi,'), "Just a reminder that your scrap price of £{$l['price']} for {$l['reg']} runs out $when.",
      $l['status'] === 'accepted' ? 'If we haven\'t managed to fix a collection time yet, reply to this email or give George a call and he\'ll get you booked in. Collection is free and we pay by bank transfer when we collect.'
                                  : 'If you\'re ready to go ahead, accept your price online or call George and he\'ll book your free collection. We pay by bank transfer when we collect.'];
    $cta = $l['status'] === 'accepted' ? ['Call George', 'tel:' . $S['phone_intl']] : ['Accept my price', abs_url('get-a-price') . '?ref=' . rawurlencode($l['ref'])];
    [$t, $hh] = customer_email($S, "Your £{$l['price']} price runs out $when", $paras, price_email_rows($l), $cta, $unsub);
    if (send_mail($l['email'], "Your scrap price for {$l['reg']} runs out $when", $t, $hh, $S['email'])) { $l['reminded'] = date('c'); $out['sent']++; save_json($f, $l); }
  }
  @file_put_contents(store_path('logs') . '/reminders.log', date('c') . " checked {$out['checked']} sent {$out['sent']}\n", FILE_APPEND | LOCK_EX);
  return $out;
}
function daily_jobs(array $S, array $REM): void {
  $f = store_path('logs') . '/last-daily.txt';
  if ((int)date('G') < 9 || @file_get_contents($f) === date('Y-m-d')) return;
  @file_put_contents($f, date('Y-m-d'), LOCK_EX);
  run_reminders($S, $REM);
}

/* cron: php index.php cron  (cPanel cron runs it hourly; the daily jobs only run once, after 9am UK time) */
if (PHP_SAPI === 'cli') {
  if (($argv[1] ?? '') === 'cron') daily_jobs($S, $REM);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') daily_jobs($S, $REM);

/* ---------- routing ---------- */
$r = trim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: ''), '/');   // parse_url gives false on malformed URIs such as //:
$b = trim(BASE, '/'); if ($b !== '' && str_starts_with($r, $b)) $r = trim(substr($r, strlen($b)), '/');
$seg = $r === '' ? [] : explode('/', $r);

/* Holding page: 'maintenance' => true in the config file takes the public site offline (admin keeps working). */
if (!empty($CFG['maintenance']) && !($seg && $seg[0] === 'admin') && !str_starts_with($r, 'img/')) {
  http_response_code(503); header('Retry-After: 86400'); header('X-Robots-Tag: noindex'); header('Content-Type: text/html; charset=utf-8');
  $tel = 'tel:' . $S['phone_intl']; $wa = 'https://wa.me/' . $S['whatsapp'] . '?text=' . rawurlencode('Hi George, I have a car to scrap. Reg: ');
  echo '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
    . '<title>' . h($S['name']) . '</title><link rel="icon" href="' . BASE . 'img/favicon-48.png">'
    . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0A1733;color:#fff;font:17px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;padding:24px 16px;box-sizing:border-box}'
    . '.c{max-width:460px;text-align:center}img{width:120px;height:120px}h1{font-size:28px;line-height:1.2;margin:18px 0 10px}p{color:#cfd9ee;margin:0 0 18px}'
    . 'a.b{display:block;margin:10px 0;padding:15px 18px;border-radius:12px;font-weight:700;text-decoration:none;font-size:18px}.g{background:#3FBF5F;color:#06210F}.o{border:2px solid #4a5d86;color:#fff}.s{color:#b4c3e2;font-size:14px;margin-top:20px}.s a{color:#fff}</style></head><body><div class="c">'
    . '<img src="' . BASE . 'img/logo-mark.svg" alt="We Buy Scrap Cars Corby"><h1>Our new website is nearly ready</h1>'
    . '<p>We still buy scrap cars, MOT failures, non-runners and vans across Corby and Kettering. Call or WhatsApp George for a price and free collection. Paid by bank transfer.</p>'
    . '<a class="b g" href="' . h($tel) . '">Call ' . h($S['phone']) . '</a><a class="b o" href="' . h($wa) . '" rel="noopener">WhatsApp us</a>'
    . '<p class="s">Email <a href="mailto:' . h($S['email']) . '">' . h($S['email']) . '</a></p></div></body></html>';
  exit;
}

if ($r === 'robots.txt') { header('Content-Type: text/plain'); echo PREVIEW ? "User-agent: *\nDisallow: /\n" : "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /admin/\nSitemap: " . SITE_URL . "/sitemap.xml\n"; exit; }

$urls = ['', 'get-a-price', 'services', 'areas', 'costs', 'about', 'contact', 'guides', 'privacy'];
foreach ($GUIDES as $g => $_) $urls[] = "guides/$g";
foreach ($SV as $k => $_) { $urls[] = $k; $urls[] = "costs/$k"; foreach ($AR as $a => $_2) $urls[] = "$k/$a"; }
foreach ($AR as $a => $_) $urls[] = "areas/$a";

if ($r === 'sitemap.xml') {
  header('Content-Type: application/xml; charset=utf-8');
  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
  foreach ($urls as $p) echo '<url><loc>' . h(abs_url($p)) . '</loc><lastmod>' . UPDATED . "</lastmod></url>\n";
  echo "</urlset>\n"; exit;
}
if ($r === 'llms.txt') {
  header('Content-Type: text/plain; charset=utf-8');
  echo "# {$S['name']}\n\n> Scrap car buyer based in Corby, Northamptonshire. Instant online scrap car prices from your registration and postcode, free collection, and payment by bank transfer when we collect. Every vehicle goes to a licensed Authorised Treatment Facility, which issues a Certificate of Destruction. Phone {$S['phone']}.\n\n";
  echo "- Get a price: " . abs_url('get-a-price') . "\n- We buy: scrap cars, MOT failures, non-runners, accident damaged cars and write-offs, vans up to 3.5 tonnes\n- Payment: bank transfer only (cash for scrap is illegal under the Scrap Metal Dealers Act 2013)\n- Covers: " . implode(', ', array_column($S['areas'], 'name')) . "\n\n## Services\n";
  foreach ($SV as $k => $x) echo "- [{$x['name']}](" . abs_url($k) . "): {$x['card']}\n";
  echo "\n## What it's worth\n"; foreach ($SV as $k => $x) echo "- [{$x['name']} price guide](" . abs_url("costs/$k") . ")\n";
  echo "\n## Areas\n"; foreach ($AR as $k => $x) echo "- [{$x['name']}](" . abs_url("areas/$k") . "): scrap car collection, MOT failures, non-runners, damaged cars and scrap vans in {$x['name']}\n";
  echo "\n## Guides\n"; foreach ($GUIDES as $k => $x) echo "- [$x](" . abs_url("guides/$k") . ")\n";
  echo "\n## Contact\n- Phone and WhatsApp: {$S['phone']}\n- Email: {$S['email']}\n- Owner: {$S['owner']}\n- Sister company: {$S['sister']} (vehicle recovery)\n";
  exit;
}

/* ---------- price tool API ---------- */
if ($r === 'api/vehicle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $reg = clean_reg((string)($_POST['reg'] ?? ''));
  if (strlen($reg) < 2) jout(['ok' => false, 'msg' => 'Please enter a valid registration.']);
  if (!rate_ok('veh', 30)) jout(['ok' => false, 'msg' => 'Too many lookups. Please call us on ' . $S['phone'] . '.']);
  $v = vehicle_lookup($reg, $CFG);
  if (!$v) jout(['ok' => true, 'found' => false, 'live' => $LIVE_LOOKUP]);
  $w = weight_cached($v);
  jout(['ok' => true, 'found' => true, 'vehicle' => $v, 'weight_ready' => (bool)$w]);
}
if ($r === 'api/weight' && $_SERVER['REQUEST_METHOD'] === 'POST') {   // fired in the background while the customer types their details
  ignore_user_abort(true); set_time_limit(90);
  $reg = clean_reg((string)($_POST['reg'] ?? ''));
  $v = is_file(STORE . "/cache/veh-$reg.json") ? json_decode(file_get_contents(STORE . "/cache/veh-$reg.json"), true) : null;
  if (!$v || !rate_ok('wt', 20)) jout(['ok' => false]);
  $w = weight_lookup($v, $CFG);
  jout(['ok' => (bool)$w]);
}
if ($r === 'api/quote' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $f = fn($k, $n = 200) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
  if ($f('website') !== '') jout(['ok' => false]);
  $reg = clean_reg($f('reg')); $pc = clean_pc($f('postcode'));
  if (strlen($reg) < 2 || strlen($pc) < 5) jout(['ok' => false, 'msg' => 'Please check the registration and postcode.']);
  if (!rate_ok('quote', 20)) jout(['ok' => false, 'msg' => 'Too many prices requested. Please call us on ' . $S['phone'] . '.']);

  $v = is_file(STORE . "/cache/veh-$reg.json") ? json_decode(file_get_contents(STORE . "/cache/veh-$reg.json"), true) : null;
  if ($v && !weight_cached($v) && !empty($CFG['anthropic_key'])) weight_lookup($v, $CFG);   // background lookup didn't finish: try once more now
  if ($v && ($w = weight_cached($v))) { $kg = $w['kg']; $weightSrc = $w['source'] . ' (' . $w['confidence'] . ')'; }
  else { $kg = (int)$PR['default_kg']; $weightSrc = $v ? 'Average car weight (kerb weight not found yet)' : 'Average car weight (demo: registration lookup not switched on)'; }
  $manual = $LIVE_LOOKUP && !$v;                                  // live lookup couldn't find the reg: George calls with a price

  $loc = pc_lookup($pc);
  $dist = $loc ? round(miles($S['geo'], [$loc['lat'], $loc['lng']]), 1) : null;
  $lines = [['Weight used', number_format($kg) . ' kg (' . $weightSrc . ')'], ['Scrap rate', '£' . $PR['rate_per_tonne'] . ' per tonne'], ['Weight value', '£' . number_format($kg / 1000 * $PR['rate_per_tonne'], 2)]];
  if ($dist === null) $lines[] = ['Distance from Corby', 'Not checked (postcode lookup unavailable)'];   // still give a price; George checks the address when he calls
  elseif ($dist > $PR['max_miles']) { $manual = true; $lines[] = ['Distance from Corby', "$dist miles: outside the {$PR['max_miles']} mile area"]; }
  else $lines[] = ['Distance from Corby', "$dist miles: free collection"];
  $price = price_for($kg, $PR);
  $lines[] = ['Price (rounded to £' . max(1, (int)($PR['round_to'] ?? 5)) . ')', '£' . $price];

  $ref = 'SC' . gmdate('ymd') . strtoupper(bin2hex(random_bytes(2)));
  $lead = ['ref' => $ref, 'status' => $manual ? 'needs-price' : 'priced', 'received' => gmdate('c'), 'demo' => $DEMO,
    'reg' => $reg, 'postcode' => $loc['pc'] ?? $pc, 'town' => $loc['town'] ?? '', 'miles' => $dist,
    'vehicle' => $v, 'weight_kg' => $kg, 'weight_source' => $weightSrc,
    'price' => $manual ? null : $price, 'valid_until' => gmdate('Y-m-d', time() + 86400 * $PR['valid_days']), 'rate_per_tonne' => $PR['rate_per_tonne'],
    'page' => $f('page')];                                         // no contact details yet: they're only asked for when the customer accepts
  save_lead($lead);
  jout(['ok' => true, 'ref' => $ref, 'manual' => $manual, 'price' => $manual ? null : $price, 'valid_until' => date('j F Y', strtotime($lead['valid_until'])), 'lines' => [], 'outside' => $dist !== null && $dist > $PR['max_miles'], 'max_miles' => $PR['max_miles']]);
}
/* reopen a price from a reminder email link */
if ($r === 'api/lead' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $l = load_lead((string)($_POST['ref'] ?? ''));
  if (!$l || $l['price'] === null || in_array($l['status'], ['booked', 'collected', 'lost'], true)) jout(['ok' => false]);
  if ($l['valid_until'] < date('Y-m-d')) jout(['ok' => false, 'expired' => true, 'reg' => $l['reg'], 'postcode' => $l['postcode']]);
  jout(['ok' => true, 'ref' => $l['ref'], 'price' => $l['price'], 'valid_until' => date('j F Y', strtotime($l['valid_until'])), 'vehicle' => $l['vehicle'], 'reg' => $l['reg'], 'accepted' => $l['status'] === 'accepted']);
}
/* "Not ready yet? Email me this price" */
if ($r === 'api/save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $f = fn($k, $n = 200) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
  if ($f('website') !== '') jout(['ok' => false]);
  $l = load_lead($f('ref', 20)); $email = $f('email', 120);
  if (!$l || $l['price'] === null) jout(['ok' => false]);
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jout(['ok' => false, 'msg' => 'Please check your email address.']);
  if (!rate_ok('save', 10)) jout(['ok' => false, 'msg' => 'Please call us on ' . $S['phone'] . '.']);
  if ($l['status'] === 'priced') $l['status'] = 'saved';
  $l['email'] = $email; $l['saved'] = gmdate('c'); $l['reminders_ok'] = $f('consent', 3) === 'yes';
  save_lead($l);
  if (!PREVIEW) {
    [$t, $hh] = customer_email($S, "Your scrap price: £{$l['price']}", ['Hi,', "Here's the price you asked us to email you. It's for a complete vehicle and includes free collection. We pay by bank transfer when we collect.",
      'When you\'re ready, accept it online and George will call you to book a collection time.'], price_email_rows($l), ['Accept my price', abs_url('get-a-price') . '?ref=' . rawurlencode($l['ref'])],
      $l['reminders_ok'] ? abs_url('unsubscribe') . '?e=' . rawurlencode($email) . '&t=' . unsub_token($email) : null);
    send_mail($email, "Your scrap price for {$l['reg']}: £{$l['price']}", $t, $hh, $S['email']);
  }
  jout(['ok' => true]);
}
if ($r === 'api/accept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $lead = load_lead((string)($_POST['ref'] ?? ''));
  if (!$lead || in_array($lead['status'], ['booked', 'collected', 'lost'], true)) jout(['ok' => false]);   // George has already dealt with it: don't reset the status
  $ref = $lead['ref'];
  $f = fn($k, $n = 200) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
  if ($f('website') !== '') jout(['ok' => false]);
  if ($lead['price'] !== null && $lead['valid_until'] < date('Y-m-d')) jout(['ok' => false, 'msg' => 'That price has run out. Please get an up to date price, or call us on ' . $S['phone'] . '.']);
  if (!rate_ok('accept', 10)) jout(['ok' => false, 'msg' => 'Please call us on ' . $S['phone'] . '.']);
  $name = $f('name', 100); $phone = $f('phone', 40); $email = $f('email', 120);
  if ($name === '' || strlen(preg_replace('/\D/', '', $phone)) < 10 || !filter_var($email, FILTER_VALIDATE_EMAIL)) jout(['ok' => false, 'msg' => 'Please add your name, a mobile number and your email so George can book your collection.']);
  $lead['status'] = $lead['price'] === null ? 'call-back' : 'accepted'; $lead['accepted'] = gmdate('c');
  $lead['name'] = $name; $lead['phone'] = $phone; $lead['email'] = $email; $lead['reminders_ok'] = $f('consent', 3) === 'yes' || !empty($lead['reminders_ok']);
  $lead['collection_note'] = $f('note', 500);
  save_lead($lead);
  if (!PREVIEW) {
    $veh = vehicle_name($lead['vehicle'] ?? null) ?: 'Not found from reg';
    $head = $lead['price'] === null ? 'Needs a price: call them back' : "ACCEPTED £{$lead['price']}: call to book the collection";
    $body = "$head\n\nRef: $ref\nName: $name\nPhone: $phone\nEmail: $email\nReg: {$lead['reg']}\nVehicle: $veh\nPostcode: {$lead['postcode']}" . ($lead['miles'] !== null ? " ({$lead['miles']} miles from Corby)" : '') . "\nWeight used: {$lead['weight_kg']} kg ({$lead['weight_source']})\nNotes: {$lead['collection_note']}\nReminders OK: " . ($lead['reminders_ok'] ? 'yes' : 'no') . "\n\nOpen in admin: " . abs_url('admin') . '?v=lead&ref=' . $ref . "\n";
    send_mail($NOTIFY, ($lead['price'] === null ? 'Call-back' : "ACCEPTED £{$lead['price']}") . " - {$lead['reg']} - $name", $body, null, $email);
    $first = trim(explode(' ', $name)[0]);
    [$t, $hh] = $lead['price'] === null
      ? customer_email($S, 'Thanks, George will call you with a price', ["Hi $first,", "Thanks for sending us your details for {$lead['reg']}. We need to check a couple of things before we can price it, so George will call you shortly on $phone."], price_email_rows($lead))
      : customer_email($S, "Price accepted: £{$lead['price']}", ["Hi $first,", "Thanks for accepting our price for {$lead['reg']}. George will call you shortly on $phone to agree a collection time.",
          'On the day, please have your photo ID, proof of address and the V5C logbook ready. We pay by bank transfer when we collect, and the car goes to a licensed Authorised Treatment Facility, which issues your Certificate of Destruction.',
          'Your price is for a complete vehicle. If anything is missing, such as the catalytic converter or wheels, please mention it when George calls.'], price_email_rows($lead));
    send_mail($email, $lead['price'] === null ? "We'll call you with a price for {$lead['reg']}" : "Price accepted: £{$lead['price']} for {$lead['reg']}", $t, $hh, $S['email']);
  }
  jout(['ok' => true]);
}
if ($r === 'unsubscribe') {
  $e = (string)($_GET['e'] ?? ''); $ok = filter_var($e, FILTER_VALIDATE_EMAIL) && hash_equals(unsub_token($e), (string)($_GET['t'] ?? ''));
  if ($ok) @file_put_contents(store_path('unsub') . '/' . sha1(strtolower(trim($e))), date('c'));
  header('X-Robots-Tag: noindex'); header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reminders</title><body style="font-family:Arial,sans-serif;max-width:520px;margin:60px auto;padding:0 16px;color:#10224A"><h1 style="font-size:22px">' . ($ok ? 'You won\'t get any more reminders' : 'That link didn\'t work') . '</h1><p>' . ($ok ? 'We\'ve stopped price reminders to ' . h($e) . '.' : 'Please call us on ' . h($S['phone']) . ' and we\'ll sort it.') . '</p><p><a href="' . h(u()) . '">Back to We Buy Scrap Cars Corby</a></p>';
  exit;
}
if ($seg && $seg[0] === 'admin') { require __DIR__ . '/admin.php'; exit; }
/* contact form */
if ($r === 'enquiry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $f = fn($k, $n = 500) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
  if ($f('website') !== '' || $f('name') === '' || $f('phone') === '' || !rate_ok('enq', 10)) jout(['ok' => false]);
  $lead = ['received' => gmdate('c'), 'page' => $f('page', 200), 'name' => $f('name', 100), 'phone' => $f('phone', 40), 'vehicle' => $f('vehicle', 120), 'location' => $f('location', 300), 'message' => $f('message', 2000)];
  $ok = @file_put_contents(store_path('enquiries') . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json', json_encode($lead, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
  if (!PREVIEW) { $body = "New enquiry from the We Buy Scrap Cars website.\n\n"; foreach ($lead as $k => $v) if ($v !== '') $body .= ucfirst($k) . ": $v\n";
    send_mail($NOTIFY, 'Website enquiry - ' . $lead['name'], $body . "\nAll enquiries: " . abs_url('admin') . "?v=enquiries\n"); }
  jout(['ok' => $ok]);
}

/* ---------- resolve page ---------- */
$type = ''; $P = null; $svc = null; $area = null; $crumbs = [['Home', '']];
$n = count($seg);
$single = ['services', 'areas', 'costs', 'about', 'contact', 'guides', 'privacy', 'get-a-price'];
if ($n === 0) { $type = 'home'; $P = core_page('home'); }
elseif ($n === 1 && in_array($seg[0], $single, true)) {
  $type = $seg[0]; $P = core_page($seg[0]);
  $crumbs[] = [$P['crumb'] ?? ucfirst($seg[0]), $seg[0]];
} elseif ($n === 1 && isset($SV[$seg[0]])) {
  $type = 'service'; $svc = $seg[0]; $P = core_page("service-$svc");
  $crumbs[] = ['Services', 'services']; $crumbs[] = [$SV[$svc]['name'], $svc];
} elseif ($n === 2 && $seg[0] === 'areas' && isset($AR[$seg[1]])) {
  $type = 'area'; $area = $seg[1]; $P = area_page($area, 'hub');
  $crumbs[] = ['Areas', 'areas']; $crumbs[] = [$AR[$area]['name'], "areas/$area"];
} elseif ($n === 2 && $seg[0] === 'guides' && isset($GUIDES[$seg[1]])) {
  $type = 'guide'; $P = core_page("guide-{$seg[1]}");
  $crumbs[] = ['Guides', 'guides']; $crumbs[] = [$GUIDES[$seg[1]], "guides/{$seg[1]}"];
} elseif ($n === 2 && $seg[0] === 'costs' && isset($SV[$seg[1]])) {
  $type = 'cost'; $svc = $seg[1]; $P = core_page("cost-$svc");
  $crumbs[] = ['Prices', 'costs']; $crumbs[] = [$SV[$svc]['name'] . ' prices', "costs/$svc"];
} elseif ($n === 2 && isset($SV[$seg[0]], $AR[$seg[1]])) {
  $type = 'areasvc'; $svc = $seg[0]; $area = $seg[1]; $P = area_page($area, $svc);
  $crumbs[] = [$AR[$area]['name'], "areas/$area"]; $crumbs[] = [$SV[$svc]['name'], "$svc/$area"];
}
if (!$P) {
  http_response_code(404); $type = '404';
  $P = ['title' => 'Page not found | We Buy Scrap Cars Corby', 'desc' => '', 'h1' => 'Page not found', 'lead' => 'That page has moved or does not exist. Get your instant scrap car price below, or call us on ' . $S['phone'] . '.', 'body' => ''];
  $crumbs = [['Home', '']];
}
$path = implode('/', $seg);
$tparts = array_map('trim', explode('|', $P['title']));
if (mb_strlen($P['title']) > 65 && count($tparts) === 3) $P['title'] = $tparts[0] . ' | ' . $tparts[2];
if (mb_strlen($P['title']) > 65) $P['title'] = str_replace(' | We Buy Scrap Cars Corby', ' | We Buy Scrap Cars', $P['title']);
$hero = $P['img'] ?? ($svc ? $SV[$svc]['img'] : ($area ? $AR[$area]['img'] : 'scrap-citroen-picasso-collection'));
$place = $area ? $AR[$area]['name'] : 'Corby';

/* ---------- schema ---------- */
$biz = [
  '@type' => ['LocalBusiness', 'AutomotiveBusiness'], '@id' => SITE_URL . '/#business', 'name' => $S['name'], 'alternateName' => $S['short'],
  'url' => abs_url(), 'telephone' => $S['phone_intl'], 'email' => $S['email'], 'image' => SITE_URL . '/img/og-we-buy-scrap-cars-corby.jpg',
  'logo' => SITE_URL . '/img/logo-512.png', 'founder' => ['@type' => 'Person', 'name' => $S['owner']],
  'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Corby', 'addressRegion' => 'Northamptonshire', 'addressCountry' => 'GB'],
  'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $S['geo'][0], 'longitude' => $S['geo'][1]],
  'areaServed' => array_map(fn($a) => ['@type' => 'Place', 'name' => $a['name'] . ', Northamptonshire'], array_values($AR)),
  'slogan' => 'Instant scrap car prices, free collection, paid by bank transfer',
  'knowsAbout' => ['Scrap car collection', 'End of life vehicles', 'MOT failure cars', 'Non-runner collection', 'Accident damaged cars', 'Insurance write-offs', 'Scrap van collection', 'Certificate of Destruction'],
  'paymentAccepted' => 'Bank Transfer', 'parentOrganization' => ['@type' => 'Organization', 'name' => $S['sister']],
  'potentialAction' => ['@type' => 'QuoteAction', 'name' => 'Get an instant scrap car price', 'target' => abs_url('get-a-price')],
  'hasOfferCatalog' => ['@type' => 'OfferCatalog', 'name' => 'We buy', 'itemListElement' => array_map(fn($k, $x) => ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $x['name'], 'url' => abs_url($k)]], array_keys($SV), array_values($SV))],
];
$graph = [$biz];
$graph[] = ['@type' => 'WebSite', '@id' => SITE_URL . '/#website', 'url' => abs_url(), 'name' => $S['name'], 'publisher' => ['@id' => SITE_URL . '/#business'], 'inLanguage' => 'en-GB'];
$graph[] = ['@type' => $type === 'guide' ? 'Article' : 'WebPage', '@id' => abs_url($path) . '#page', 'url' => abs_url($path), 'name' => $P['title'], 'description' => $P['desc'] ?? '', 'isPartOf' => ['@id' => SITE_URL . '/#website'], 'about' => ['@id' => SITE_URL . '/#business'], 'dateModified' => UPDATED, 'inLanguage' => 'en-GB',
  'primaryImageOfPage' => ['@type' => 'ImageObject', 'url' => SITE_URL . '/img/' . $hero . '.webp', 'caption' => $P['img_alt'] ?? ''],
  'speakable' => ['@type' => 'SpeakableSpecification', 'cssSelector' => ['h1', '.answer']]]
  + ($type === 'guide' ? ['headline' => $P['h1'], 'author' => ['@type' => 'Organization', 'name' => $S['name']], 'publisher' => ['@id' => SITE_URL . '/#business'], 'datePublished' => UPDATED, 'image' => SITE_URL . '/img/' . $hero . '.webp'] : []);
$graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn($c, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => abs_url($c[1])], $crumbs, array_keys($crumbs))];
if ($svc && in_array($type, ['service', 'areasvc', 'cost'], true)) {
  $graph[] = ['@type' => 'Service', 'name' => $SV[$svc]['name'] . ($area ? ' in ' . $AR[$area]['name'] : ''), 'serviceType' => $SV[$svc]['name'],
    'provider' => ['@id' => SITE_URL . '/#business'], 'description' => $P['desc'] ?? '', 'url' => abs_url($path),
    'offers' => ['@type' => 'Offer', 'availability' => 'https://schema.org/InStock', 'description' => 'Instant online price, free collection, paid by bank transfer', 'url' => abs_url('get-a-price')],
    'areaServed' => $area ? ['@type' => 'Place', 'name' => $AR[$area]['name'] . ', Northamptonshire'] : $biz['areaServed']];
}
if (!empty($P['faqs'])) $graph[] = ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $P['faqs'])];

/* ---------- components ---------- */
function card(string $href, string $imgn, string $title, string $text, string $cta): string {
  return '<a class="card" href="' . h(u($href)) . '"><img src="' . h(img("$imgn-sm")) . '" alt="' . h($title) . '" loading="lazy" width="800" height="450"><span class="card-b"><strong>' . h($title) . '</strong><span>' . h($text) . '</span><em>' . h($cta) . '</em></span></a>';
}
function qbox(string $id, bool $hero = true): string {
  $a = h(u('get-a-price'));
  if ($hero) return '<form class="qbox" action="' . $a . '" method="get"><label class="plate"><b>GB</b><input name="reg" placeholder="ENTER REG" autocomplete="off" autocapitalize="characters" maxlength="8" aria-label="Your registration" required></label><input class="pc" name="pc" placeholder="Postcode" autocomplete="postal-code" aria-label="Postcode of the vehicle" maxlength="9" required><button class="btn btn-go" type="submit">Get my price</button><p class="qbox-note">Free collection · Paid by bank transfer · No obligation</p></form>';
  return '<form class="side-q" action="' . $a . '" method="get"><label class="plate"><b>GB</b><input name="reg" placeholder="ENTER REG" autocomplete="off" autocapitalize="characters" maxlength="8" aria-label="Your registration" required></label><input class="pc" name="pc" placeholder="Postcode" autocomplete="postal-code" aria-label="Postcode" maxlength="9" required><button class="btn btn-go" type="submit">Get my price</button></form>';
}
$tel = 'tel:' . $S['phone_intl'];
$wa = 'https://wa.me/' . $S['whatsapp'] . '?text=' . rawurlencode('Hi, I have a car to scrap. Reg: ');
$lic = function () use ($S): string {
  $a = [];
  $a[] = 'Scrap metal collector\'s licence' . ($S['licence_scrap'] ? ' ' . h($S['licence_scrap']) : (PREVIEW ? ' [number to be added]' : ''));
  $a[] = 'Waste carrier licence' . ($S['licence_waste'] ? ' ' . h($S['licence_waste']) : (PREVIEW ? ' [number to be added]' : ''));
  return implode(' · ', $a);
};
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= h($P['title']) ?></title>
<meta name="description" content="<?= h($P['desc'] ?? '') ?>">
<?php if (PREVIEW || $type === '404'): ?><meta name="robots" content="noindex,nofollow"><?php endif; ?>
<link rel="canonical" href="<?= h(abs_url($path)) ?>">
<meta property="og:type" content="website"><meta property="og:title" content="<?= h($P['title']) ?>"><meta property="og:description" content="<?= h($P['desc'] ?? '') ?>">
<meta property="og:url" content="<?= h(abs_url($path)) ?>"><meta property="og:image" content="<?= h(SITE_URL) ?>/img/og-we-buy-scrap-cars-corby.jpg"><meta property="og:locale" content="en_GB">
<meta name="theme-color" content="#0A1733">
<link rel="icon" href="<?= BASE ?>img/favicon-48.png" sizes="48x48"><link rel="icon" href="<?= BASE ?>img/favicon-192.png" sizes="192x192"><link rel="apple-touch-icon" href="<?= BASE ?>img/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@1,700;1,800&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE ?>style.css?v=6">
<link rel="preload" as="image" href="<?= h(img($hero)) ?>" imagesrcset="<?= h(img("$hero-sm")) ?> 800w, <?= h(img($hero)) ?> 1600w" imagesizes="100vw">
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</head>
<body class="t-<?= h($type) ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="top">
  <div class="wrap top-in">
    <a class="brand" href="<?= u() ?>" aria-label="We Buy Scrap Cars Corby home"><img src="<?= BASE ?>img/logo-mark.svg" alt="" width="52" height="52"><span><b>We Buy Scrap Cars</b><small>Corby · Free collection</small></span></a>
    <button class="burger" id="burger" type="button" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span><b>Menu</b></button>
    <nav class="nav" id="menu" aria-label="Main">
      <div class="dd"><a href="<?= u('services') ?>" class="dd-t">We buy</a><div class="dd-m"><?php foreach ($SV as $k => $x): ?><a href="<?= u($k) ?>"><?= h($x['name']) ?></a><?php endforeach; ?><a href="<?= u('services') ?>" class="dd-all">Everything we buy</a></div></div>
      <div class="dd dd-wide"><a href="<?= u('areas') ?>" class="dd-t">Areas</a><div class="dd-m"><?php foreach ($AR as $k => $x): ?><a href="<?= u("areas/$k") ?>"><?= h($x['name']) ?></a><?php endforeach; ?><a href="<?= u('areas') ?>" class="dd-all">All areas</a></div></div>
      <div class="dd"><a href="<?= u('costs') ?>" class="dd-t">Prices</a><div class="dd-m"><?php foreach ($SV as $k => $x): ?><a href="<?= u("costs/$k") ?>"><?= h($x['name']) ?> prices</a><?php endforeach; ?><a href="<?= u('get-a-price') ?>" class="dd-all">Get an instant price</a></div></div>
      <div class="dd"><a href="<?= u('guides') ?>" class="dd-t">Guides</a><div class="dd-m"><?php foreach ($GUIDES as $k => $x): ?><a href="<?= u("guides/$k") ?>"><?= h($x) ?></a><?php endforeach; ?></div></div>
      <a href="<?= u('about') ?>">About</a><a href="<?= u('contact') ?>">Contact</a>
      <a class="btn btn-go nav-call" href="<?= u('get-a-price') ?>">Get my price</a>
    </nav>
    <a class="btn btn-go top-call" href="<?= u('get-a-price') ?>">Get my price</a>
  </div>
</header>

<main id="main">
<section class="hero<?= $type === 'home' ? ' hero-home' : '' ?>">
  <img class="hero-img" src="<?= h(img($hero)) ?>" srcset="<?= h(img("$hero-sm")) ?> 800w, <?= h(img($hero)) ?> 1600w" sizes="100vw" alt="<?= h($P['img_alt'] ?? 'A scrap car loaded on one of our collection trucks') ?>" fetchpriority="high">
  <div class="wrap hero-in">
    <?php if (count($crumbs) > 1): ?><nav class="crumbs" aria-label="Breadcrumb"><?php foreach ($crumbs as $i => $c): ?><?= $i ? '<span>/</span>' : '' ?><?= $i === count($crumbs) - 1 ? '<span aria-current="page">' . h($c[0]) . '</span>' : '<a href="' . h(u($c[1])) . '">' . h($c[0]) . '</a>' ?><?php endforeach; ?></nav><?php endif; ?>
    <p class="eyebrow"><span class="dot"></span> Instant online price · Free collection</p>
    <h1><?= h($P['h1']) ?></h1>
    <?php if (!empty($P['lead'])): ?><p class="lead"><?= h($P['lead']) ?></p><?php endif; ?>
    <?php if ($type !== 'get-a-price'): ?>
      <?= qbox('hero') ?>
      <p class="hero-alt">Rather talk? Call <a href="<?= h($tel) ?>"><?= h($S['phone']) ?></a> or <a href="<?= h($wa) ?>" rel="noopener">WhatsApp us</a>.</p>
    <?php endif; ?>
    <ul class="proof"><li>Free collection</li><li>Paid by bank transfer</li><li>Certificate of Destruction</li><li>Non-runners welcome</li></ul>
  </div>
</section>

<div class="wrap layout">
  <article class="content">
    <?php if ($type === 'get-a-price'):
      $preReg = clean_reg((string)($_GET['reg'] ?? '')); $prePc = clean_pc((string)($_GET['pc'] ?? '')); ?>
    <div class="tool" id="tool">
      <?php if (PREVIEW): ?><div class="demo"><b>Preview version for feedback</b><?= $LIVE_LOOKUP ? 'Vehicle lookup is live.' : 'The registration lookup is waiting on the DVSA API key, so for now every car is priced at an average weight.' ?> No emails go to customers or George while this is a preview.</div><?php endif; ?>
      <ol class="steps" aria-hidden="true"><li class="on" data-s="1">Your vehicle</li><li data-s="2">Your price</li><li data-s="3">Book collection</li></ol>

      <form class="step" id="s1" novalidate>
        <h2>Your vehicle</h2>
        <div class="fgrid">
          <label class="fl">Registration<span class="plate"><b>GB</b><input name="reg" id="reg" value="<?= h($preReg) ?>" placeholder="ENTER REG" autocomplete="off" autocapitalize="characters" maxlength="8" required></span></label>
          <label class="fl">Postcode where the vehicle is<input class="pc" name="postcode" id="pc" value="<?= h($prePc) ?>" placeholder="e.g. NN17 1AA" autocomplete="postal-code" maxlength="9" required></label>
        </div>
        <input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        <p class="err" id="e1" role="alert"></p>
        <div class="tool-nav"><button class="btn btn-go" type="submit" id="b1">Get my price</button></div>
        <p class="note">No sign-up needed to see your price.</p>
      </form>

      <div class="step" id="s2" hidden>
        <div id="found"></div>
        <div id="pricebox"></div>
        <div class="tool-nav" id="pricenav"><button class="btn btn-go" type="button" id="accept">Accept and book collection</button><button class="btn btn-ghost-d" type="button" id="again">Price another vehicle</button></div>
        <p class="note" id="pricenote">Accepting doesn't commit you yet. George will call to agree a collection time.</p>
        <div class="later" id="later">
          <button class="linkbtn" type="button" id="laterbtn" aria-expanded="false" aria-controls="laterform">Not ready yet? Email me this price</button>
          <form id="laterform" hidden novalidate>
            <input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
            <label class="fl">Your email<input name="email" type="email" autocomplete="email" required></label>
            <label class="tick"><input type="checkbox" name="consent" value="yes" checked> Remind me before my price runs out. You can stop these at any time.</label>
            <p class="err" id="e2" role="alert"></p>
            <button class="btn btn-ghost-d" type="submit" id="b2">Email me my price</button>
          </form>
          <p class="note" id="latermsg" role="status"></p>
        </div>
      </div>

      <form class="step" id="s3" hidden novalidate>
        <h2 id="s3h">Book your free collection</h2>
        <p id="s3p">Add your details and George will call you to agree a collection time.</p>
        <input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        <div class="fgrid">
          <label class="fl">Your name<input name="name" autocomplete="name" required></label>
          <label class="fl">Mobile number<input name="phone" type="tel" autocomplete="tel" required></label>
          <label class="fl full">Email<input name="email" type="email" autocomplete="email" required></label>
          <label class="fl full">Anything we should know? (optional)<input name="note" placeholder="e.g. it's on the drive, flat tyre, best times to call"></label>
          <label class="tick full"><input type="checkbox" name="consent" value="yes"> Send me a reminder about my price before it runs out. You can stop these at any time.</label>
        </div>
        <p class="note">We only use your details to arrange your collection and send you a copy of your price. We never sell them. See our <a href="<?= u('privacy') ?>">privacy notice</a>.</p>
        <p class="err" id="e3" role="alert"></p>
        <div class="tool-nav"><button class="btn btn-ghost-d" type="button" id="back3">Back</button><button class="btn btn-go" type="submit" id="b3">Book my collection</button></div>
      </form>
      <div class="step" id="s4" hidden></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($P['answer'])): ?><div class="answer"><p class="answer-t">Quick answer</p><p><?= h($P['answer']) ?></p></div><?php endif; ?>
    <?php if (in_array($type, ['home', 'service', 'areasvc', 'area', 'cost'], true)): ?>
    <dl class="glance">
      <div><dt>Price</dt><dd>Instant online</dd></div>
      <div><dt>Collection</dt><dd>Free<?= $area ? ' in ' . h($AR[$area]['name']) : '' ?></dd></div>
      <div><dt>Payment</dt><dd>Bank transfer on collection</dd></div>
      <div><dt>Paperwork</dt><dd>Certificate of Destruction</dd></div>
    </dl>
    <?php endif; ?>
    <?php $toc = md_toc($P['body'] ?? ''); if (count($toc) >= 4): ?>
    <details class="toc" open><summary>On this page</summary><ol><?php foreach ($toc as $t): ?><li><a href="#<?= h($t[0]) ?>"><?= h($t[1]) ?></a></li><?php endforeach; ?></ol></details>
    <?php endif; ?>
    <?php
      $bodyHtml = md($P['body'] ?? '');
      $pool = array_values(array_unique(array_merge(array_column($S['services'], 'img'), array_column($S['areas'], 'img'))));
      $fig = $pool[crc32($path) % count($pool)]; if ($fig === $hero) $fig = $pool[(crc32($path) + 1) % count($pool)];
      $figHtml = '<figure class="fig"><img src="' . h(img("$fig-sm")) . '" alt="A scrap car loaded on one of our collection trucks' . ($area ? ' near ' . h($AR[$area]['name']) : ' in Northamptonshire') . '" loading="lazy" width="800" height="450"><figcaption>One of our trucks collecting a car' . ($area ? ' near ' . h($AR[$area]['name']) : '') . '.</figcaption></figure>';
      $parts = preg_split('/(?=<h2 )/', $bodyHtml);
      if (count($parts) > 2) { $parts[1] .= $figHtml; $bodyHtml = implode('', $parts); }
      echo $bodyHtml;
    ?>
    <p class="updated">Last updated <?= date('j F Y', strtotime(UPDATED)) ?> by <?= h($S['name']) ?>.</p>

    <?php if ($type === 'home' || $type === 'services'): ?>
      <h2><?= $type === 'home' ? 'What we buy' : 'Everything we buy' ?></h2>
      <div class="cards"><?php foreach ($SV as $k => $x) echo card($k, $x['img'], $x['name'], $x['card'], 'More about ' . strtolower($x['name'])); ?></div>
    <?php endif; ?>

    <?php if ($type === 'service'): ?>
      <div class="price-box"><strong>Get an instant price</strong><span>Enter your reg and postcode on the <a href="<?= u('get-a-price') ?>">price page</a>, or read the <a href="<?= u("costs/$svc") ?>"><?= h(strtolower($SV[$svc]['name'])) ?> price guide</a>.</span></div>
      <h2><?= h($SV[$svc]['name']) ?> near you</h2>
      <div class="cards cards-sm"><?php foreach ($AR as $a => $x) echo card("$svc/$a", $x['img'], $SV[$svc]['name'] . ' in ' . $x['name'], 'Free collection around ' . $x['name'] . '.', 'View ' . $x['name']); ?></div>
    <?php endif; ?>

    <?php if ($type === 'areas' || $type === 'home'): ?>
      <h2><?= $type === 'home' ? 'Where we collect' : 'Choose your area' ?></h2>
      <div class="cards cards-sm"><?php foreach ($AR as $a => $x) echo card("areas/$a", $x['img'], $x['name'], 'Scrap cars, MOT failures, non-runners and vans collected free in ' . $x['name'] . '.', 'View ' . $x['name']); ?></div>
    <?php endif; ?>

    <?php if ($type === 'area'): ?>
      <h2>What we buy in <?= h($AR[$area]["name"]) ?></h2>
      <div class="cards"><?php foreach ($SV as $k => $x) echo card("$k/$area", $x['img'], $x['name'] . ' in ' . $AR[$area]['name'], $x['card'], 'View ' . strtolower($x['name'])); ?></div>
      <h3>What it's worth</h3>
      <ul class="linklist"><?php foreach ($SV as $k => $x): ?><li><a href="<?= u("costs/$k") ?>"><?= h($x['name']) ?> price guide</a> <span>Instant online price</span></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <?php if ($type === 'areasvc'): ?>
      <div class="price-box"><strong>Instant price for <?= h($AR[$area]['name']) ?></strong><span>Enter your reg and postcode on the <a href="<?= u('get-a-price') ?>">price page</a>. See the <a href="<?= u("costs/$svc") ?>"><?= h(strtolower($SV[$svc]['name'])) ?> price guide</a> for what affects it.</span></div>
      <h2>More from us in <?= h($AR[$area]['name']) ?></h2>
      <div class="cards cards-sm"><?php foreach ($SV as $k => $x) if ($k !== $svc) echo card("$k/$area", $x['img'], $x['name'] . ' in ' . $AR[$area]['name'], $x['card'], 'View'); ?></div>
      <h3><?= h($SV[$svc]['name']) ?> nearby</h3>
      <ul class="linklist"><?php foreach ($AR[$area]['near'] as $nb): ?><li><a href="<?= u("$svc/$nb") ?>"><?= h($SV[$svc]['name'] . ' in ' . $AR[$nb]['name']) ?></a></li><?php endforeach; ?><li><a href="<?= u("areas/$area") ?>">Everything we buy in <?= h($AR[$area]['name']) ?></a></li></ul>
    <?php endif; ?>

    <?php if ($type === 'costs'): ?>
      <div class="cards"><?php foreach ($SV as $k => $x) echo card("costs/$k", $x['img'], $x['name'] . ' prices', 'What affects the price and how our instant price works.', 'Read the guide'); ?></div>
    <?php endif; ?>

    <?php if ($type === 'cost'): ?>
      <div class="price-box"><strong>Want the actual figure?</strong><span>Get your <a href="<?= u('get-a-price') ?>">instant online price</a> from your reg and postcode, or see our <a href="<?= u($svc) ?>"><?= h(strtolower($SV[$svc]['name'])) ?> page</a>.</span></div>
      <h3><?= h($SV[$svc]['name']) ?> by area</h3>
      <ul class="linklist cols"><?php foreach ($AR as $a => $x): ?><li><a href="<?= u("$svc/$a") ?>"><?= h($x['name']) ?></a></li><?php endforeach; ?></ul>
      <p class="more">Other price guides: <?php $o = []; foreach ($SV as $k => $x) if ($k !== $svc) $o[] = '<a href="' . u("costs/$k") . '">' . h($x['name']) . '</a>'; echo implode(' · ', $o); ?></p>
    <?php endif; ?>

    <?php if ($type === 'guides'): ?>
      <div class="cards"><?php $gi = ['scrap-ford-focus-collection', 'scrap-cars-evening-collection-corby', 'non-runner-seat-altea-collection', 'damaged-vauxhall-astra-scrap', 'scrap-vw-passat-collection']; $i = 0; foreach ($GUIDES as $k => $x) echo card("guides/$k", $gi[$i++], $x, 'Plain-English advice on scrapping a car legally.', 'Read the guide'); ?></div>
    <?php endif; ?>
    <?php if (in_array($type, ['service', 'areasvc', 'cost', 'guide'], true)): ?>
      <h3>Helpful guides</h3>
      <ul class="linklist"><?php foreach ($GUIDES as $k => $x): if ($type === 'guide' && $path === "guides/$k") continue; ?><li><a href="<?= u("guides/$k") ?>"><?= h($x) ?></a></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <?php if ($type === 'contact'): ?>
      <form class="enq" id="enq" novalidate>
        <input type="hidden" name="page" value="<?= h($path) ?>"><input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        <label>Your name<input name="name" autocomplete="name" required></label>
        <label>Phone number<input name="phone" type="tel" autocomplete="tel" required></label>
        <label>Vehicle (make, model, reg)<input name="vehicle"></label>
        <label>Where is it?<input name="location" placeholder="Town or postcode"></label>
        <label>Anything else?<textarea name="message" rows="4"></textarea></label>
        <button class="btn btn-call" type="submit">Send enquiry</button>
        <p class="note" id="enq-msg" role="status">Quickest way to a price: <a href="<?= u('get-a-price') ?>">get an instant price online</a>.</p>
      </form>
    <?php endif; ?>

    <?php if (!empty($P['faqs'])): ?>
      <h2>Questions people ask</h2>
      <div class="faqs"><?php foreach ($P['faqs'] as $f): ?><details><summary><?= h($f[0]) ?></summary><p><?= h($f[1]) ?></p></details><?php endforeach; ?></div>
    <?php endif; ?>
  </article>

  <aside class="side" aria-label="Get a price">
    <div class="side-card">
      <p class="side-t">What's my car worth?</p>
      <p>Enter your reg and postcode for an instant price. Free collection<?= $area ? ' in ' . h($AR[$area]['name']) : '' ?>.</p>
      <?= $type === 'get-a-price' ? '<a class="btn btn-go" href="#tool">Back to the price tool</a>' : qbox('side', false) ?>
      <a class="btn btn-ghost-d" href="<?= h($tel) ?>">Call <?= h($S['phone']) ?></a>
      <ul class="ticks"><li>Free collection, non-runners welcome</li><li>Paid by bank transfer when we collect</li><li>Licensed ATF and Certificate of Destruction</li><li>Run by the team behind Rapid Recovery Corby</li></ul>
    </div>
  </aside>
</div>

<section class="band">
  <div class="wrap band-in">
    <div><h2>Scrap car in <?= h($place) ?>?</h2><p>Instant price online. Free collection. Paid by bank transfer.</p></div>
    <div class="band-cta"><a class="btn btn-go" href="<?= u('get-a-price') ?>">Get my price</a><a class="btn btn-ghost" href="<?= h($tel) ?>">Call <?= h($S['phone']) ?></a></div>
  </div>
</section>
</main>

<footer class="foot">
  <div class="wrap foot-in">
    <div class="foot-b"><img src="<?= BASE ?>img/logo-mark.svg" alt="We Buy Scrap Cars Corby" width="84" height="84">
      <p><strong><?= h($S['name']) ?></strong><br>Corby, Northamptonshire<br><a href="<?= h($tel) ?>"><?= h($S['phone']) ?></a><br><a href="mailto:<?= h($S['email']) ?>"><?= h($S['email']) ?></a><br><a href="<?= u('get-a-price') ?>">Get an instant price</a></p></div>
    <div><p class="ft">We buy</p><ul><?php foreach ($SV as $k => $x): ?><li><a href="<?= u($k) ?>"><?= h($x['name']) ?></a></li><?php endforeach; ?><li><a href="<?= u('costs') ?>">Prices</a></li></ul><p class="ft ft2">Guides</p><ul><?php foreach ($GUIDES as $k => $x): ?><li><a href="<?= u("guides/$k") ?>"><?= h($x) ?></a></li><?php endforeach; ?></ul></div>
    <div class="foot-areas"><p class="ft">Areas</p><ul><?php foreach ($AR as $a => $x): ?><li><a href="<?= u("areas/$a") ?>"><?= h($x['name']) ?></a></li><?php endforeach; ?></ul></div>
    <div><p class="ft">Company</p><ul><li><a href="<?= u('about') ?>">About</a></li><li><a href="<?= u('contact') ?>">Contact</a></li><li><a href="<?= u('privacy') ?>">Privacy</a></li><li><a href="<?= h($S['sister_url']) ?>">Vehicle recovery</a></li></ul></div>
  </div>
  <p class="wrap legal">© <?= date('Y') ?> <?= h($S['name']) ?>. All vehicles are scrapped at a licensed Authorised Treatment Facility. We pay by bank transfer only, as the Scrap Metal Dealers Act 2013 requires.<br><span class="lic"><?= $lic() ?></span></p>
</footer>

<nav class="mbar" aria-label="Quick contact"><a href="<?= u('get-a-price') ?>" class="mb-call mb-go">Get my price</a><a href="<?= h($tel) ?>" class="mb-wa">Call</a></nav>

<script>
(()=>{const b=document.getElementById('burger'),m=document.getElementById('menu');b.addEventListener('click',()=>{const o=b.getAttribute('aria-expanded')!=='true';b.setAttribute('aria-expanded',o);document.body.classList.toggle('menu-open',o)});
m.querySelectorAll('.dd-t').forEach(t=>t.addEventListener('click',e=>{if(window.matchMedia('(max-width:980px)').matches){const d=t.parentNode;if(!d.classList.contains('open')){e.preventDefault();m.querySelectorAll('.dd.open').forEach(x=>x!==d&&x.classList.remove('open'));d.classList.add('open')}}}));
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&document.body.classList.contains('menu-open')){b.click();b.focus()}})})();
</script>
<?php if ($type === 'get-a-price'): ?>
<script>
(()=>{
const API='<?= rtrim(u('api'), '/') ?>/', $=id=>document.getElementById(id), steps=[...document.querySelectorAll('.steps li')];
const S=[$('s1'),$('s2'),$('s3'),$('s4')];let ref=null;
const show=n=>{S.forEach((s,i)=>s.hidden=i!==n-1);steps.forEach(l=>l.classList.toggle('on',+l.dataset.s<=Math.min(n,3)));$('tool').scrollIntoView({behavior:'smooth',block:'start'})};
const post=async(p,data)=>{const r=await fetch(API+p,{method:'POST',body:data});return r.json()};
const busy=(btn,on,txt)=>{btn.disabled=on;btn.innerHTML=on?'<span class="spin"></span> '+txt:txt};
const esc=s=>String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
$('reg').addEventListener('input',e=>{e.target.value=e.target.value.toUpperCase().replace(/[^A-Z0-9 ]/g,'')});
S[0].addEventListener('submit',async e=>{e.preventDefault();$('e1').textContent='';
  const reg=$('reg').value.replace(/\s/g,''),pc=$('pc').value.trim();
  if(reg.length<2){$('e1').textContent='Please enter your registration.';return}
  if(!/^[A-Za-z]{1,2}\d[A-Za-z\d]?\s*\d[A-Za-z]{2}$/.test(pc)){$('e1').textContent='Please enter a full UK postcode, e.g. NN17 1AA.';return}
  busy($('b1'),true,'Finding your price');
  try{const fd=new FormData();fd.append('reg',reg);const j=await post('vehicle',fd);if(!j.ok)throw j.msg;
    let f='';
    if(j.found)f=carHtml(j.vehicle);
    else if(!j.live)f='<p class="note">Demo: the registration lookup isn\'t switched on yet, so this demo prices an average-sized car.</p>';
    $('found').innerHTML=f;
    const q=new FormData(S[0]);q.set('reg',reg);q.append('page',location.pathname);
    const p=await post('quote',q);if(!p.ok)throw p.msg;ref=p.ref;
    let h;
    if(p.manual){h='<div class="priceout"><small>We need a quick check</small><p style="font-size:20px;color:#fff;margin-top:8px">'+(p.outside?'Your vehicle is more than '+p.max_miles+' miles from Corby, outside our usual collection area.':'We couldn\'t price this one automatically.')+' Leave your number and George will call you with a price.</p></div>';
      $('accept').textContent='Get a price by phone';$('pricenote').hidden=true;$('s3h').textContent='Get a price by phone';$('s3p').textContent='Add your details and George will call you with a price.'}
    else{h=priceHtml(p);
      if(p.lines&&p.lines.length)h+='<div class="workings"><b>How this was worked out (demo only, hidden on the live site)</b><table>'+p.lines.map(l=>'<tr><td>'+esc(l[0])+'</td><td>'+esc(l[1])+'</td></tr>').join('')+'</table></div>';
      h+='<p class="note">Your price is for a complete vehicle. If anything is missing, such as the catalytic converter or wheels, we\'ll talk it through with you before we collect.</p>';
      $('accept').textContent='Accept and book collection';$('pricenote').hidden=false;$('s3h').textContent='Book your free collection';$('s3p').textContent='Add your details and George will call you to agree a collection time.'}
    $('pricebox').innerHTML=h;resetLater();$('later').hidden=!!p.manual;$('pricenav').hidden=false;show(2)}
  catch(m){$('e1').textContent=typeof m==='string'?m:'Something went wrong. Please try again or call us.'}
  busy($('b1'),false,'Get my price')});
$('accept').addEventListener('click',()=>show(3));
const resetLater=()=>{$('laterform').hidden=true;$('laterbtn').hidden=false;$('laterbtn').setAttribute('aria-expanded','false');$('latermsg').textContent='';$('e2').textContent=''};
$('laterbtn').addEventListener('click',()=>{$('laterform').hidden=false;$('laterbtn').hidden=true;$('laterbtn').setAttribute('aria-expanded','true');$('laterform').email.focus()});
$('laterform').addEventListener('submit',async e=>{e.preventDefault();$('e2').textContent='';const fd=new FormData($('laterform'));
  if(!/^\S+@\S+\.\S+$/.test(fd.get('email'))){$('e2').textContent='Please check your email address.';return}
  fd.append('ref',ref);busy($('b2'),true,'Sending');
  try{const j=await post('save',fd);if(!j.ok)throw j.msg;$('laterform').hidden=true;$('latermsg').textContent='Done. We\'ve emailed your price to '+fd.get('email')+'. Accept it any time before it runs out.';$('s3').email.value=fd.get('email');if(fd.get('consent'))$('s3').consent.checked=true}
  catch(m){$('e2').textContent=typeof m==='string'?m:'That didn\'t send. Please try again or call us.'}
  busy($('b2'),false,'Email me my price')});
const priceHtml=p=>'<div class="priceout"><small>Your instant price</small><div class="big">£'+p.price+'</div><p>Free collection · Paid by bank transfer when we collect</p><p>Price held until '+esc(p.valid_until)+' · Ref '+esc(ref)+'</p></div>';
const carHtml=v=>v?'<div class="found"><span class="ok">✓</span><div><strong>'+esc([v.year,v.make,v.model].filter(Boolean).join(' '))+'</strong><span>'+esc([v.engine_cc?(v.engine_cc/1000).toFixed(1)+' litre':'',v.fuel,v.colour].filter(Boolean).join(' · '))+'</span></div></div>':'';
const openRef=new URLSearchParams(location.search).get('ref');
if(openRef){(async()=>{const fd=new FormData();fd.append('ref',openRef);try{const j=await post('lead',fd);
  if(j.ok){ref=j.ref;$('found').innerHTML=carHtml(j.vehicle);$('pricebox').innerHTML=priceHtml(j)+'<p class="note">Your price is for a complete vehicle. If anything is missing, such as the catalytic converter or wheels, we\'ll talk it through with you before we collect.</p>';
    if(j.accepted){$('pricenav').hidden=true;$('later').hidden=true;$('pricenote').textContent='You\'ve already accepted this price. George will call you to book the collection, or call him on <?= h($S['phone']) ?>.'}
    else $('later').hidden=true;
    show(2)}
  else if(j.expired){$('reg').value=j.reg;$('pc').value=j.postcode;$('e1').textContent='That price has run out. Get an up to date price below.'}
  else $('e1').textContent='That price is no longer open. Get a new price below, or call us on <?= h($S['phone']) ?>.'}catch(_){}})()}
$('again').addEventListener('click',()=>{$('reg').value='';show(1);$('reg').focus()});
$('back3').addEventListener('click',()=>show(2));
S[2].addEventListener('submit',async e=>{e.preventDefault();$('e3').textContent='';
  const fd=new FormData(S[2]);if(!fd.get('name').trim()||fd.get('phone').replace(/\D/g,'').length<10||!/^\S+@\S+\.\S+$/.test(fd.get('email'))){$('e3').textContent='Please add your name, a mobile number and your email so George can book your collection.';return}
  fd.append('ref',ref);busy($('b3'),true,'Booking');
  try{const j=await post('accept',fd);if(!j.ok)throw j.msg;
    S[3].innerHTML='<div class="done"><div class="ok">✓</div><h2>'+($('accept').textContent.startsWith('Get')?'Thanks, we\'ll call you':'Price accepted')+'</h2><p>George will call you shortly'+($('accept').textContent.startsWith('Get')?' with a price.':' to book your free collection. Have your photo ID, proof of address and V5C ready for the day.')+'</p><p class="note">Reference '+esc(ref)+'</p></div>';show(4)}
  catch(m){$('e3').textContent=typeof m==='string'?m:'That didn\'t go through. Please call us on <?= h($S['phone']) ?>.'}
  busy($('b3'),false,'Book my collection')});
if($('reg').value&&$('pc').value)S[0].requestSubmit();
})();
</script>
<?php endif; ?>
<?php if ($type === 'contact'): ?>
<script>
(()=>{const f=document.getElementById('enq'),m=document.getElementById('enq-msg');
f.addEventListener('submit',async e=>{e.preventDefault();if(!f.elements.name.value.trim()||!f.elements.phone.value.trim()){m.textContent='Please add your name and phone number so we can call you back.';return}
const btn=f.querySelector('[type=submit]');btn.disabled=true;btn.textContent='Sending…';
try{const r=await fetch('<?= u('enquiry') ?>'.replace(/\/$/,''),{method:'POST',body:new FormData(f)});const j=await r.json();if(!j.ok)throw 0;f.reset();m.textContent='Thanks, we have your details and will call you back shortly.'}
catch(_){m.textContent='That did not send. Please call us on <?= h($S['phone']) ?> instead.'}btn.disabled=false;btn.textContent='Send enquiry'})})();
</script>
<?php endif; ?>
</body>
</html>
