<?php
/* Rapid Recovery Corby — site engine (Build Bro).
   One router + template. Page copy lives in data/core and data/areas. */
declare(strict_types=1);

/* Two homes for the same code: the Build Bro preview, and George's own hosting. */
define('ON_BUILDBRO', str_contains(__DIR__, '/clients/rapid-recovery/'));
if (ON_BUILDBRO) {
  define('BASE', '/clients/rapid-recovery/site/');
  define('SITE_URL', 'https://buildbro.app/clients/rapid-recovery/site'); // no trailing slash
  define('LEADS_DIR', '/home1/sufktgte/buildbro-intake/rapid-recovery-leads');
  define('CONFIG_FILE', '');
} else {
  $home = preg_match('#^(/home\d*/[^/]+)/#', __DIR__, $m) ? $m[1] : dirname(__DIR__, 2);   // account home, never inside public_html
  define('BASE', '/');
  define('SITE_URL', 'https://' . preg_replace('/[^a-z0-9.\-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
  define('LEADS_DIR', "$home/rapid-recovery-leads");
  define('CONFIG_FILE', "$home/rapid-recovery-config.php");   // outside public_html; set 'live' => true at go-live
}
$CFG = (CONFIG_FILE !== '' && is_file(CONFIG_FILE)) ? (include CONFIG_FILE) : [];
define('PREVIEW', ON_BUILDBRO || !is_array($CFG) || empty($CFG['live']));   // noindex until the real domain is live
if (PREVIEW) header('X-Robots-Tag: noindex, nofollow');

$D = __DIR__ . '/data';
$S = json_decode(file_get_contents("$D/site.json"), true);
$SV = []; foreach ($S['services'] as $x) $SV[$x['slug']] = $x;
$AR = []; foreach ($S['areas'] as $x) $AR[$x['slug']] = $x;
$GUIDES = ['what-to-do-if-you-break-down' => 'What to do if you break down', 'a14-breakdown-advice' => 'Breaking down on the A14', 'car-wont-start' => 'Car won\'t start?', 'how-to-scrap-a-car' => 'How to scrap a car'];
const UPDATED = '2026-10-14';

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function u(string $p = ''): string { $p = trim($p, '/'); return BASE . ($p === '' ? '' : "$p/"); }
function abs_url(string $p = ''): string { $p = trim($p, '/'); return SITE_URL . '/' . ($p === '' ? '' : "$p/"); }
function img(string $n): string { return BASE . "img/$n.webp"; }
function jfile(string $f): ?array { return is_file($f) ? json_decode(file_get_contents($f), true) : null; }

/* Page source format (data/core/*.txt and data/areas/<area>.txt):
   === key            (starts a page)
   field: value       (title, desc, h1, lead, img, img_alt, crumb)
   ---                (then markdown body)
   ---                (then FAQ lines)  Q: question / A: answer */
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

/* Minimal markdown: ## h2, ### h3, - lists, **bold**, [text](/path or url), blank-line paragraphs */
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
    }
    elseif (str_starts_with($b, '- ')) {
      $out .= "<ul>\n"; foreach ($lines as $l) $out .= '<li>' . $inline(ltrim(substr($l, 2))) . "</li>\n"; $out .= "</ul>\n";
    } elseif (preg_match('/^\d+\. /', $b)) {
      $out .= "<ol>\n"; foreach ($lines as $l) $out .= '<li>' . $inline(preg_replace('/^\d+\.\s*/', '', $l)) . "</li>\n"; $out .= "</ol>\n";
    } else $out .= '<p>' . $inline(implode(' ', $lines)) . "</p>\n";
  }
  return $out;
}

/* ---------- routing ---------- */
$_GET = array_filter($_GET, 'is_string'); $_POST = array_filter($_POST, 'is_string');   // no array params (name[]=x): every field here is a plain string
$r = trim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: ''), '/');   // parse_url gives false on malformed URIs such as //:
$b = trim(BASE, '/'); if ($b !== '' && str_starts_with($r, $b)) $r = trim(substr($r, strlen($b)), '/');
$seg = $r === '' ? [] : explode('/', $r);

if ($r === 'robots.txt') { header('Content-Type: text/plain'); echo PREVIEW ? "User-agent: *\nDisallow: /\n" : "User-agent: *\nAllow: /\nSitemap: " . SITE_URL . "/sitemap.xml\n"; exit; }

$urls = ['', 'services', 'areas', 'costs', 'about', 'contact', 'guides'];
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
  echo "# {$S['name']}\n\n> 24/7 vehicle recovery company based in Corby, Northamptonshire. Breakdown and accident recovery, car transport, jump starts and battery fitting, and scrap car collection. PAS 43 and ISO 9001 accredited. Phone {$S['phone']}.\n\n";
  echo "- Hours: {$S['hours']}\n- Typical arrival in Corby: 30 to 45 minutes\n- Minimum charges: breakdown recovery from £60, jump start from £40\n- Covers: " . implode(', ', array_column($S['areas'], 'name')) . ", and UK-wide recovery and transport\n\n## Services\n";
  foreach ($SV as $k => $x) echo "- [{$x['name']}](" . abs_url($k) . "): {$x['card']}\n";
  echo "\n## Costs\n"; foreach ($SV as $k => $x) echo "- [{$x['name']} cost guide](" . abs_url("costs/$k") . ")\n";
  echo "\n## Areas\n"; foreach ($AR as $k => $x) echo "- [{$x['name']}](" . abs_url("areas/$k") . "): breakdown recovery, car transport, jump starts and scrap car collection in {$x['name']}\n";
  echo "\n## Guides\n"; foreach ($GUIDES as $k => $x) echo "- [$x](" . abs_url("guides/$k") . ")\n";
  echo "\n## Contact\n- Phone and WhatsApp: {$S['phone']}\n- Email: {$S['email']}\n- Owner: {$S['owner']}\n- Accreditations: PAS 43, ISO 9001\n- Insurance: goods in transit, public liability, road risks\n";
  exit;
}

/* enquiry form */
if ($r === 'enquiry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json'); header('Cache-Control: no-store');
  $f = fn($k, $n = 500) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
  if ($f('website') !== '' || $f('name') === '' || $f('phone') === '') { echo '{"ok":false}'; exit; }
  if (!is_dir(LEADS_DIR . '/rate')) @mkdir(LEADS_DIR . '/rate', 0700, true);   // max 10 enquiries per hour from one address
  $rf = LEADS_DIR . '/rate/' . md5(preg_replace('/[^0-9a-f:.]/i', '', $_SERVER['REMOTE_ADDR'] ?? 'x') . '|' . gmdate('YmdH'));
  $rn = (int)@file_get_contents($rf) + 1; @file_put_contents($rf, (string)$rn);
  if ($rn > 10) { echo '{"ok":false}'; exit; }
  $lead = ['received' => gmdate('c'), 'page' => $f('page', 200), 'name' => $f('name', 100), 'phone' => $f('phone', 40), 'service' => $f('service', 60), 'location' => $f('location', 300), 'vehicle' => $f('vehicle', 120), 'message' => $f('message', 2000)];
  if (!is_dir(LEADS_DIR)) @mkdir(LEADS_DIR, 0700, true);
  $ok = @file_put_contents(LEADS_DIR . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json', json_encode($lead, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
  $clean = fn($v) => str_replace(["\r", "\n"], ' ', $v);
  $labels = ['name' => 'Name', 'phone' => 'Phone', 'service' => 'Service', 'location' => 'Location', 'vehicle' => 'Vehicle', 'message' => 'Message', 'page' => 'Sent from page', 'received' => 'Received (UTC)'];
  $body = "New enquiry from the Rapid Recovery Corby website.\n\nCall them back on: " . $lead['phone'] . "\n\n";
  foreach ($labels as $k => $l) if ($lead[$k] !== '') $body .= "$l: " . $lead[$k] . "\n";
  $host = parse_url(SITE_URL, PHP_URL_HOST);
  $subject = 'Website enquiry: ' . $clean($lead['service'] ?: 'Recovery') . ' - ' . $clean($lead['name']);
  $headers = "From: Rapid Recovery Website <website@$host>\r\nContent-Type: text/plain; charset=UTF-8\r\nX-Mailer: PHP";
  $sent = @mail($S['email'], '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, "-fwebsite@$host");
  echo json_encode(['ok' => $ok || $sent]); exit;
}

/* ---------- resolve page ---------- */
$type = ''; $P = null; $svc = null; $area = null; $crumbs = [['Home', '']];
$n = count($seg);
if ($n === 0) { $type = 'home'; $P = core_page('home'); }
elseif ($n === 1 && in_array($seg[0], ['services', 'areas', 'costs', 'about', 'contact', 'guides'], true)) {
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
  $crumbs[] = ['Costs', 'costs']; $crumbs[] = [$SV[$svc]['name'] . ' cost', "costs/$svc"];
} elseif ($n === 2 && isset($SV[$seg[0]], $AR[$seg[1]])) {
  $type = 'areasvc'; $svc = $seg[0]; $area = $seg[1]; $P = area_page($area, $svc);
  $crumbs[] = [$AR[$area]['name'], "areas/$area"]; $crumbs[] = [$SV[$svc]['name'], "$svc/$area"];
}
if (!$P) {
  http_response_code(404); $type = '404';
  $P = ['title' => 'Page not found | Rapid Recovery Corby', 'desc' => '', 'h1' => 'Page not found', 'lead' => 'That page has moved or does not exist. For a recovery right now, call us on ' . $S['phone'] . '.', 'body' => ''];
  $crumbs = [['Home', '']];
}
$path = implode('/', $seg);
/* keep <title> within ~65 chars: drop the middle "|" segment when too long */
$tparts = array_map('trim', explode('|', $P['title']));
if (mb_strlen($P['title']) > 65 && count($tparts) === 3) $P['title'] = $tparts[0] . ' | ' . $tparts[2];
if (mb_strlen($P['title']) > 65) $P['title'] = str_replace(' | Rapid Recovery Corby', ' | Rapid Recovery', $P['title']);
$hero = $P['img'] ?? ($svc ? $SV[$svc]['img'] : ($area ? $AR[$area]['img'] : 'recovery-truck-night-callout-corby'));

/* ---------- schema ---------- */
$biz = [
  '@type' => ['LocalBusiness', 'AutomotiveBusiness'], '@id' => SITE_URL . '/#business', 'name' => $S['name'],
  'url' => abs_url(), 'telephone' => $S['phone_intl'], 'email' => $S['email'], 'image' => SITE_URL . '/img/og-rapid-recovery-corby.jpg',
  'logo' => SITE_URL . '/img/rr-badge-512.png', 'priceRange' => '££', 'founder' => ['@type' => 'Person', 'name' => $S['owner']],
  'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Corby', 'addressRegion' => 'Northamptonshire', 'addressCountry' => 'GB'],
  'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $S['geo'][0], 'longitude' => $S['geo'][1]],
  'openingHoursSpecification' => [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], 'opens' => '00:00', 'closes' => '23:59']],
  'areaServed' => array_map(fn($a) => ['@type' => 'Place', 'name' => $a['name'] . ', Northamptonshire'], array_values($AR)),
  'hasCredential' => array_map(fn($c) => ['@type' => 'EducationalOccupationalCredential', 'name' => $c], $S['accreditations']),
  'sameAs' => [],
];
$biz['slogan'] = '24/7 vehicle recovery in Corby and Kettering';
$biz['knowsAbout'] = ['Vehicle recovery', 'Breakdown recovery', 'Accident recovery', 'Car transport', 'Jump starts', 'Battery replacement', 'Scrap car collection'];
$biz['paymentAccepted'] = 'Cash, Credit Card, Debit Card, Bank Transfer';
$biz['hasOfferCatalog'] = ['@type' => 'OfferCatalog', 'name' => 'Recovery services', 'itemListElement' => array_map(fn($k, $x) => ['@type' => 'Offer', 'itemOffered' => ['@type' => 'Service', 'name' => $x['name'], 'url' => abs_url($k)]], array_keys($SV), array_values($SV))];
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
    'offers' => ['@type' => 'Offer', 'priceCurrency' => 'GBP', 'availability' => 'https://schema.org/InStock', 'description' => $SV[$svc]['price'], 'url' => abs_url($path)] + ($svc === 'breakdown-recovery' ? ['priceSpecification' => ['@type' => 'PriceSpecification', 'minPrice' => 60, 'priceCurrency' => 'GBP']] : ($svc === 'jump-start' ? ['priceSpecification' => ['@type' => 'PriceSpecification', 'minPrice' => 40, 'priceCurrency' => 'GBP']] : [])),
    'hoursAvailable' => $biz['openingHoursSpecification'],
    'areaServed' => $area ? ['@type' => 'Place', 'name' => $AR[$area]['name'] . ', Northamptonshire'] : $biz['areaServed']];
}
if (!empty($P['faqs'])) $graph[] = ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $P['faqs'])];

/* ---------- components ---------- */
function card(string $href, string $imgn, string $title, string $text, string $cta): string {
  return '<a class="card" href="' . h(u($href)) . '"><img src="' . h(img("$imgn-sm")) . '" alt="' . h($title) . '" loading="lazy" width="800" height="450"><span class="card-b"><strong>' . h($title) . '</strong><span>' . h($text) . '</span><em>' . h($cta) . '</em></span></a>';
}
$tel = 'tel:' . $S['phone_intl'];
$wa = 'https://wa.me/' . $S['whatsapp'] . '?text=' . rawurlencode('Hi Rapid Recovery, I need help with my vehicle. I am at: ');
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
<meta property="og:url" content="<?= h(abs_url($path)) ?>"><meta property="og:image" content="<?= h(SITE_URL) ?>/img/og-rapid-recovery-corby.jpg"><meta property="og:locale" content="en_GB">
<meta name="theme-color" content="#0E1C42">
<link rel="icon" href="<?= BASE ?>img/favicon-48.png" sizes="48x48"><link rel="icon" href="<?= BASE ?>img/favicon-192.png" sizes="192x192"><link rel="apple-touch-icon" href="<?= BASE ?>img/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@1,700;1,800&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE ?>style.css?v=2">
<link rel="preload" as="image" href="<?= h(img($hero)) ?>" imagesrcset="<?= h(img("$hero-sm")) ?> 800w, <?= h(img($hero)) ?> 1600w" imagesizes="100vw">
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</head>
<body class="t-<?= h($type) ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="top">
  <div class="wrap top-in">
    <a class="brand" href="<?= u() ?>" aria-label="Rapid Recovery Corby home"><img src="<?= BASE ?>img/badge.svg" alt="" width="52" height="52"><span><b>Rapid Recovery</b><small>Corby · 24/7</small></span></a>
    <button class="burger" id="burger" type="button" aria-expanded="false" aria-controls="menu"><span></span><span></span><span></span><b>Menu</b></button>
    <nav class="nav" id="menu" aria-label="Main">
      <div class="dd"><a href="<?= u('services') ?>" class="dd-t">Services</a><div class="dd-m"><?php foreach ($SV as $k => $x): ?><a href="<?= u($k) ?>"><?= h($x['name']) ?></a><?php endforeach; ?><a href="<?= u('services') ?>" class="dd-all">All services</a></div></div>
      <div class="dd dd-wide"><a href="<?= u('areas') ?>" class="dd-t">Areas</a><div class="dd-m"><?php foreach ($AR as $k => $x): ?><a href="<?= u("areas/$k") ?>"><?= h($x['name']) ?></a><?php endforeach; ?><a href="<?= u('areas') ?>" class="dd-all">All areas</a></div></div>
      <div class="dd"><a href="<?= u('costs') ?>" class="dd-t">Prices</a><div class="dd-m"><?php foreach ($SV as $k => $x): ?><a href="<?= u("costs/$k") ?>"><?= h($x['name']) ?> cost</a><?php endforeach; ?></div></div>
      <div class="dd"><a href="<?= u('guides') ?>" class="dd-t">Guides</a><div class="dd-m"><?php foreach ($GUIDES as $k => $x): ?><a href="<?= u("guides/$k") ?>"><?= h($x) ?></a><?php endforeach; ?></div></div>
      <a href="<?= u('about') ?>">About</a><a href="<?= u('contact') ?>">Contact</a>
      <a class="btn btn-call nav-call" href="<?= h($tel) ?>">Call <?= h($S['phone']) ?></a>
    </nav>
    <a class="btn btn-call top-call" href="<?= h($tel) ?>"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25 11.4 11.4 0 0 0 3.6.57 1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1z"/></svg><?= h($S['phone']) ?></a>
  </div>
</header>

<main id="main">
<section class="hero<?= $type === 'home' ? ' hero-home' : '' ?>">
  <img class="hero-img" src="<?= h(img($hero)) ?>" srcset="<?= h(img("$hero-sm")) ?> 800w, <?= h(img($hero)) ?> 1600w" sizes="100vw" alt="<?= h($P['img_alt'] ?? 'Rapid Recovery truck on a job') ?>" fetchpriority="high">
  <div class="wrap hero-in">
    <?php if (count($crumbs) > 1): ?><nav class="crumbs" aria-label="Breadcrumb"><?php foreach ($crumbs as $i => $c): ?><?= $i ? '<span>/</span>' : '' ?><?= $i === count($crumbs) - 1 ? '<span aria-current="page">' . h($c[0]) . '</span>' : '<a href="' . h(u($c[1])) . '">' . h($c[0]) . '</a>' ?><?php endforeach; ?></nav><?php endif; ?>
    <p class="eyebrow"><span class="dot"></span> Available now · 24 hours, 7 days</p>
    <h1><?= h($P['h1']) ?></h1>
    <?php if (!empty($P['lead'])): ?><p class="lead"><?= h($P['lead']) ?></p><?php endif; ?>
    <div class="hero-cta"><a class="btn btn-call" href="<?= h($tel) ?>">Call <?= h($S['phone']) ?></a><a class="btn btn-ghost" href="<?= h($wa) ?>" rel="noopener">WhatsApp us</a></div>
    <ul class="proof"><li>PAS 43 &amp; ISO 9001</li><li>30–45 min to most of Corby</li><li>5+ recovery vehicles</li></ul>
  </div>
</section>

<div class="wrap layout">
  <article class="content">
    <?php if (!empty($P['answer'])): ?><div class="answer"><p class="answer-t">Quick answer</p><p><?= h($P['answer']) ?></p></div><?php endif; ?>
    <?php if (in_array($type, ['home', 'service', 'areasvc', 'area', 'cost'], true)): ?>
    <dl class="glance">
      <div><dt>Open</dt><dd>24 hours, 7 days</dd></div>
      <div><dt>Arrival</dt><dd><?= $area && $area !== 'corby' ? 'Fast from our Corby base' : '30–45 min in Corby' ?></dd></div>
      <div><dt>Price</dt><dd><?= h($svc ? $SV[$svc]['price'] : 'Recovery from £60') ?></dd></div>
      <div><dt>Accredited</dt><dd>PAS 43 &amp; ISO 9001</dd></div>
    </dl>
    <?php endif; ?>
    <?php $toc = md_toc($P['body'] ?? ''); if (count($toc) >= 4): ?>
    <details class="toc" open><summary>On this page</summary><ol><?php foreach ($toc as $t): ?><li><a href="#<?= h($t[0]) ?>"><?= h($t[1]) ?></a></li><?php endforeach; ?></ol></details>
    <?php endif; ?>
    <?php
      $bodyHtml = md($P['body'] ?? '');
      $pool = array_values(array_unique(array_merge(array_column($S['services'], 'img'), array_column($S['areas'], 'img'))));
      $fig = $pool[crc32($path) % count($pool)]; if ($fig === $hero) $fig = $pool[(crc32($path) + 1) % count($pool)];
      $figHtml = '<figure class="fig"><img src="' . h(img("$fig-sm")) . '" alt="Rapid Recovery truck carrying a vehicle' . ($area ? ' near ' . h($AR[$area]['name']) : ' in Northamptonshire') . '" loading="lazy" width="800" height="450"><figcaption>A Rapid Recovery truck on a job' . ($area ? ' near ' . h($AR[$area]['name']) : '') . '.</figcaption></figure>';
      $parts = preg_split('/(?=<h2 )/', $bodyHtml);
      if (count($parts) > 2) { $parts[1] .= $figHtml; $bodyHtml = implode('', $parts); }
      echo $bodyHtml;
    ?>
    <p class="updated">Last updated <?= date('j F Y', strtotime(UPDATED)) ?> by <?= h($S['name']) ?>.</p>

    <?php if ($type === 'home' || $type === 'services'): ?>
      <h2><?= $type === 'home' ? 'What we do' : 'All our services' ?></h2>
      <div class="cards"><?php foreach ($SV as $k => $x) echo card($k, $x['img'], $x['name'], $x['card'], 'See ' . strtolower($x['name'])); ?></div>
    <?php endif; ?>

    <?php if ($type === 'service'): ?>
      <div class="price-box"><strong><?= h($SV[$svc]['price']) ?></strong><span>Read the <a href="<?= u("costs/$svc") ?>"><?= h(strtolower($SV[$svc]['name'])) ?> cost guide</a> for what affects the price.</span></div>
      <h2><?= h($SV[$svc]['name']) ?> near you</h2>
      <div class="cards cards-sm"><?php foreach ($AR as $a => $x) echo card("$svc/$a", $x['img'], $SV[$svc]['name'] . ' in ' . $x['name'], 'Local ' . strtolower($SV[$svc]['name']) . ' around ' . $x['name'] . '.', 'View ' . $x['name']); ?></div>
    <?php endif; ?>

    <?php if ($type === 'areas' || $type === 'home'): ?>
      <h2><?= $type === 'home' ? 'Areas we cover' : 'Choose your area' ?></h2>
      <div class="cards cards-sm"><?php foreach ($AR as $a => $x) echo card("areas/$a", $x['img'], $x['name'], 'Recovery, transport, jump starts and scrap cars in ' . $x['name'] . '.', 'View ' . $x['name']); ?></div>
    <?php endif; ?>

    <?php if ($type === 'area'): ?>
      <h2>How we can help in <?= h($AR[$area]["name"]) ?></h2>
      <div class="cards"><?php foreach ($SV as $k => $x) echo card("$k/$area", $x['img'], $x['name'] . ' in ' . $AR[$area]['name'], $x['card'], 'View ' . strtolower($x['name'])); ?></div>
      <h3>What it costs</h3>
      <ul class="linklist"><?php foreach ($SV as $k => $x): ?><li><a href="<?= u("costs/$k") ?>"><?= h($x['name']) ?> cost guide</a> <span><?= h($x['price']) ?></span></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <?php if ($type === 'areasvc'): ?>
      <div class="price-box"><strong><?= h($SV[$svc]['price']) ?></strong><span>See our <a href="<?= u("costs/$svc") ?>"><?= h(strtolower($SV[$svc]['name'])) ?> cost guide</a> for how it is priced.</span></div>
      <h2>More from us in <?= h($AR[$area]['name']) ?></h2>
      <div class="cards cards-sm"><?php foreach ($SV as $k => $x) if ($k !== $svc) echo card("$k/$area", $x['img'], $x['name'] . ' in ' . $AR[$area]['name'], $x['card'], 'View'); ?></div>
      <h3><?= h($SV[$svc]['name']) ?> nearby</h3>
      <ul class="linklist"><?php foreach ($AR[$area]['near'] as $nb): ?><li><a href="<?= u("$svc/$nb") ?>"><?= h($SV[$svc]['name'] . ' in ' . $AR[$nb]['name']) ?></a></li><?php endforeach; ?><li><a href="<?= u("areas/$area") ?>">Everything we do in <?= h($AR[$area]['name']) ?></a></li></ul>
    <?php endif; ?>

    <?php if ($type === 'costs'): ?>
      <div class="cards"><?php foreach ($SV as $k => $x) echo card("costs/$k", $x['img'], $x['name'] . ' cost guide', $x['price'] . '. What affects the price and how we quote.', 'Read the guide'); ?></div>
    <?php endif; ?>

    <?php if ($type === 'cost'): ?>
      <div class="price-box"><strong><?= h($SV[$svc]['price']) ?></strong><span>Ready to book? See our <a href="<?= u($svc) ?>"><?= h(strtolower($SV[$svc]['name'])) ?> service</a> or call <?= h($S['phone']) ?>.</span></div>
      <h3><?= h($SV[$svc]['name']) ?> by area</h3>
      <ul class="linklist cols"><?php foreach ($AR as $a => $x): ?><li><a href="<?= u("$svc/$a") ?>"><?= h($x['name']) ?></a></li><?php endforeach; ?></ul>
      <p class="more">Other cost guides: <?php $o = []; foreach ($SV as $k => $x) if ($k !== $svc) $o[] = '<a href="' . u("costs/$k") . '">' . h($x['name']) . '</a>'; echo implode(' · ', $o); ?></p>
    <?php endif; ?>

    <?php if ($type === 'guides'): ?>
      <div class="cards"><?php $gi = ['recovery-truck-night-callout-corby', 'accident-recovery-astra-with-fiesta-on-dolly', 'night-breakdown-recovery-honda-civic', 'scrap-car-collection-citroen-picasso']; $i = 0; foreach ($GUIDES as $k => $x) echo card("guides/$k", $gi[$i++], $x, 'Plain-English advice from a local recovery team.', 'Read the guide'); ?></div>
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
        <label>What do you need?<select name="service"><?php foreach ($SV as $x) echo '<option>' . h($x['name']) . '</option>'; ?><option>Something else</option></select></label>
        <label>Where is the vehicle?<span class="locrow"><input name="location" id="loc" placeholder="Road, town or postcode"><button type="button" class="btn btn-small" id="geo">Use my location</button></span></label>
        <label>Vehicle (make, model, reg)<input name="vehicle"></label>
        <label>Anything else?<textarea name="message" rows="4"></textarea></label>
        <button class="btn btn-call" type="submit">Send enquiry</button>
        <p class="note" id="enq-msg" role="status">Need us right now? Calling is quickest: <a href="<?= h($tel) ?>"><?= h($S['phone']) ?></a>.</p>
      </form>
    <?php endif; ?>

    <?php if (!empty($P['faqs'])): ?>
      <h2>Questions people ask</h2>
      <div class="faqs"><?php foreach ($P['faqs'] as $f): ?><details><summary><?= h($f[0]) ?></summary><p><?= h($f[1]) ?></p></details><?php endforeach; ?></div>
    <?php endif; ?>
  </article>

  <aside class="side" aria-label="Get help now">
    <div class="side-card">
      <p class="side-t">Stuck right now?</p>
      <p>Call and tell us where you are. We'll give you a price and an arrival time on the phone.</p>
      <a class="btn btn-call" href="<?= h($tel) ?>">Call <?= h($S['phone']) ?></a>
      <a class="btn btn-ghost-d" href="<?= h($wa) ?>" rel="noopener">Send your location on WhatsApp</a>
      <ul class="ticks"><li>Open 24/7, 365 days</li><li>PAS 43 &amp; ISO 9001 accredited</li><li>Goods in transit &amp; public liability insured</li><?= $svc === 'scrap-car-collection' ? '<li>Scrap cars paid by bank transfer</li>' : '<li>Pay for recovery by card, transfer or cash</li>' ?></ul>
    </div>
  </aside>
</div>

<section class="band">
  <div class="wrap band-in">
    <div><h2>Broken down near <?= h($area ? $AR[$area]["name"] : "Corby") ?>?</h2><p>One call and a truck is on its way. Day or night.</p></div>
    <div class="band-cta"><a class="btn btn-call" href="<?= h($tel) ?>">Call <?= h($S['phone']) ?></a><a class="btn btn-ghost" href="<?= u('contact') ?>">Send an enquiry</a></div>
  </div>
</section>
</main>

<footer class="foot">
  <div class="wrap foot-in">
    <div class="foot-b"><img src="<?= BASE ?>img/badge.svg" alt="Rapid Recovery Corby" width="84" height="84">
      <p><strong><?= h($S['name']) ?></strong><br>Corby, Northamptonshire<br><a href="<?= h($tel) ?>"><?= h($S['phone']) ?></a><br><a href="mailto:<?= h($S['email']) ?>"><?= h($S['email']) ?></a><br>Open 24 hours, 7 days</p></div>
    <div><p class="ft">Services</p><ul><?php foreach ($SV as $k => $x): ?><li><a href="<?= u($k) ?>"><?= h($x['name']) ?></a></li><?php endforeach; ?><li><a href="<?= u('costs') ?>">Prices</a></li></ul><p class="ft ft2">Guides</p><ul><?php foreach ($GUIDES as $k => $x): ?><li><a href="<?= u("guides/$k") ?>"><?= h($x) ?></a></li><?php endforeach; ?></ul></div>
    <div class="foot-areas"><p class="ft">Areas</p><ul><?php foreach ($AR as $a => $x): ?><li><a href="<?= u("areas/$a") ?>"><?= h($x['name']) ?></a></li><?php endforeach; ?></ul></div>
    <div><p class="ft">Company</p><ul><li><a href="<?= u('about') ?>">About</a></li><li><a href="<?= u('contact') ?>">Contact</a></li><li><a href="<?= u('services') ?>">All services</a></li><li><a href="<?= u('areas') ?>">All areas</a></li></ul></div>
  </div>
  <p class="wrap legal">© <?= date('Y') ?> <?= h($S['name']) ?>. PAS 43 and ISO 9001 accredited vehicle recovery.</p>
</footer>

<nav class="mbar" aria-label="Quick contact"><a href="<?= h($tel) ?>" class="mb-call">Call now</a><a href="<?= h($wa) ?>" rel="noopener" class="mb-wa">WhatsApp</a></nav>

<script>
(()=>{const b=document.getElementById('burger'),m=document.getElementById('menu');b.addEventListener('click',()=>{const o=b.getAttribute('aria-expanded')!=='true';b.setAttribute('aria-expanded',o);document.body.classList.toggle('menu-open',o)});
m.querySelectorAll('.dd-t').forEach(t=>t.addEventListener('click',e=>{if(window.matchMedia('(max-width:980px)').matches){const d=t.parentNode;if(!d.classList.contains('open')){e.preventDefault();m.querySelectorAll('.dd.open').forEach(x=>x!==d&&x.classList.remove('open'));d.classList.add('open')}}}));
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&document.body.classList.contains('menu-open')){b.click();b.focus()}})})();
</script>
<?php if ($type === 'contact'): ?>
<script>
(()=>{const f=document.getElementById('enq'),m=document.getElementById('enq-msg'),g=document.getElementById('geo'),l=document.getElementById('loc');
g.addEventListener('click',()=>{if(!navigator.geolocation){m.textContent='Location is not available on this device. Type your road or postcode instead.';return}
g.textContent='Finding you…';navigator.geolocation.getCurrentPosition(p=>{const a=p.coords.latitude.toFixed(5),b=p.coords.longitude.toFixed(5);l.value=a+', '+b+' (maps.google.com/?q='+a+','+b+')';g.textContent='Location added'},()=>{g.textContent='Use my location';m.textContent='We could not get your location. Type your road or postcode instead.'},{enableHighAccuracy:true,timeout:10000})});
f.addEventListener('submit',async e=>{e.preventDefault();if(!f.elements.name.value.trim()||!f.elements.phone.value.trim()){m.textContent='Please add your name and phone number so we can call you back.';return}
const btn=f.querySelector('[type=submit]');btn.disabled=true;btn.textContent='Sending…';
try{const r=await fetch('<?= u('enquiry') ?>'.replace(/\/$/,''),{method:'POST',body:new FormData(f)});const j=await r.json();if(!j.ok)throw 0;f.reset();m.textContent='Thanks, we have your details and will call you back shortly. If it is urgent, call us now.'}
catch(_){m.textContent='That did not send. Please call us on <?= h($S['phone']) ?> instead.'}btn.disabled=false;btn.textContent='Send enquiry'})})();
</script>
<?php endif; ?>
</body>
</html>
