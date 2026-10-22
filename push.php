<?php
/* We Buy Scrap Cars Corby: phone notifications (Web Push) for the admin.
   Included from index.php. No libraries: VAPID (RFC 8292) and aes128gcm payload encryption (RFC 8291) with PHP's openssl.
   Each phone or computer that turns notifications on is one "device" in STORE/push, with its own on/off list of events. */
declare(strict_types=1);
if (!defined('SC_APP')) { http_response_code(403); exit; }

/* event key => [label, hint, on by default for a new device] */
function push_events(): array {
  return [
    'accepted'  => ['Price accepted', 'A customer accepts their price and wants a collection booked.', true],
    'callback'  => ['Call-back needed', 'A customer sends their details but the car needs pricing by phone (reg not found, or outside the area).', true],
    'enquiry'   => ['Website enquiry', 'Someone fills in the contact form.', true],
    'collected' => ['Collected by a driver', 'A driver finishes a collection (flagged if anything is missing).', true],
    'failed'    => ['Couldn\'t collect', 'A driver reports a collection they couldn\'t do.', true],
    'morning'   => ['Morning summary', 'After 9am: today\'s collections, jobs not sent to a driver, and customers waiting for a call.', true],
    'saved'     => ['Price emailed to a customer', 'A customer asks for their price by email to think it over.', false],
    'priced'    => ['Every price given', 'Anyone gets a price on the site, even without leaving details. Can be a lot.', false],
  ];
}

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

/* uncompressed P-256 point (65 bytes) of an EC key */
function push_point(\OpenSSLAsymmetricKey $k): string {
  $d = openssl_pkey_get_details($k);
  return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}
/* a raw 65-byte P-256 public point as an openssl key */
function push_pubkey(string $raw): \OpenSSLAsymmetricKey|false {
  if (strlen($raw) !== 65 || $raw[0] !== "\x04") return false;
  $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
  return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}
function push_new_ec(): \OpenSSLAsymmetricKey|false { return openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']); }

/* the site's VAPID key pair, made once and kept outside the web root */
function push_vapid(): ?array {
  static $v = null; if ($v) return $v;
  $f = store_path('keys') . '/vapid.json';
  $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
  if (!$j || empty($j['pem'])) {
    $k = push_new_ec(); if (!$k || !openssl_pkey_export($k, $pem)) return null;
    $j = ['pem' => $pem, 'pub' => b64u(push_point($k)), 'made' => date('c')];
    if (@file_put_contents($f, json_encode($j), LOCK_EX) === false) return null;
    @chmod($f, 0600);
  }
  $k = openssl_pkey_get_private($j['pem']); if (!$k) return null;
  return $v = ['key' => $k, 'pub' => $j['pub']];
}

/* ES256 JWT for the push service at $endpoint */
function push_jwt(string $endpoint, \OpenSSLAsymmetricKey $key, string $sub): string {
  $p = parse_url($endpoint);
  $in = b64u('{"typ":"JWT","alg":"ES256"}') . '.' . b64u(json_encode(['aud' => $p['scheme'] . '://' . $p['host'], 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
  openssl_sign($in, $der, $key, OPENSSL_ALGO_SHA256);
  /* DER SEQUENCE { INTEGER r, INTEGER s } -> raw r||s */
  $o = 3 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7f) : 0); $raw = '';
  for ($i = 0; $i < 2; $i++) { $len = ord($der[$o]); $raw .= str_pad(ltrim(substr($der, $o + 1, $len), "\0"), 32, "\0", STR_PAD_LEFT); $o += 2 + $len; }
  return $in . '.' . b64u($raw);
}

/* RFC 8291 aes128gcm: one record, padded to hide the message length a little */
function push_encrypt(string $plain, string $uaPub, string $auth): ?string {
  $peer = push_pubkey($uaPub); $eph = push_new_ec();
  if (!$peer || !$eph || strlen($auth) !== 16) return null;
  $asPub = push_point($eph);
  $ecdh = openssl_pkey_derive($peer, $eph, 32); if ($ecdh === false) return null;
  $ikm = hash_hmac('sha256', "WebPush: info\0" . $uaPub . $asPub . "\x01", hash_hmac('sha256', $ecdh, $auth, true), true);
  $salt = random_bytes(16);
  $prk = hash_hmac('sha256', $ikm, $salt, true);
  $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
  $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
  $pad = max(0, (int)(ceil(strlen($plain) / 128) * 128) - strlen($plain));
  $ct = openssl_encrypt($plain . "\x02" . str_repeat("\0", $pad), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
  if ($ct === false) return null;
  return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

/* only real browser push services: the endpoint comes from the browser, so don't let it point the server anywhere else */
function push_endpoint_ok(string $e): bool {
  $p = parse_url($e);
  if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['port']) || isset($p['user'])) return false;
  return (bool)preg_match('/(^|\.)(fcm\.googleapis\.com|push\.apple\.com|push\.services\.mozilla\.com|notify\.windows\.com|push\.api\.chromium\.org)$/i', $p['host']);
}

function push_dir(): string { return store_path('push'); }
function push_id(string $endpoint): string { return substr(hash('sha256', $endpoint), 0, 24); }
function push_devices(): array {
  $out = [];
  foreach (glob(push_dir() . '/*.json') ?: [] as $f) { $d = json_decode((string)file_get_contents($f), true); if ($d && !empty($d['endpoint'])) $out[$d['id']] = $d; }
  uasort($out, fn($a, $b) => strcmp($a['added'] ?? '', $b['added'] ?? ''));
  return $out;
}
function push_device(string $id): ?array { if (!preg_match('/^[a-f0-9]{24}$/', $id) || !is_file(push_dir() . "/$id.json")) return null; return json_decode((string)file_get_contents(push_dir() . "/$id.json"), true) ?: null; }
function push_save(array $d): bool { $f = push_dir() . "/{$d['id']}.json"; $ok = save_json($f, $d); @chmod($f, 0600); return $ok; }
function push_forget(string $id): void { if (preg_match('/^[a-f0-9]{24}$/', $id)) @unlink(push_dir() . "/$id.json"); }
function push_device_name(string $ua): string {
  $os = preg_match('/iPhone/', $ua) ? 'iPhone' : (preg_match('/iPad/', $ua) ? 'iPad' : (preg_match('/Android/', $ua) ? 'Android phone' : (preg_match('/Macintosh/', $ua) ? 'Mac' : (preg_match('/Windows/', $ua) ? 'Windows PC' : 'Computer'))));
  $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung Internet' : (preg_match('/Firefox|FxiOS/', $ua) ? 'Firefox' : (preg_match('/Chrome|CriOS/', $ua) ? 'Chrome' : (preg_match('/Safari/', $ua) ? 'Safari' : ''))));
  return $os . ($br !== '' && !in_array($os, ['iPhone', 'iPad'], true) ? " ($br)" : '');
}
function push_log(string $line): void { @file_put_contents(store_path('logs') . '/push-' . date('Y-m') . '.log', date('c') . " $line\n", FILE_APPEND | LOCK_EX); }

/* send one message to a set of devices, all at once. Devices the push service says are gone (404/410) are removed. */
function push_send(array $devices, array $msg): array {
  $res = ['sent' => 0, 'failed' => 0, 'removed' => 0];
  $vap = push_vapid(); if (!$vap || !$devices) return $res;
  $json = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $contact = (string)($GLOBALS['S']['email'] ?? 'info@webuyscrapcars-corby.co.uk');
  $mh = curl_multi_init(); $hs = [];
  foreach ($devices as $d) {
    if (!push_endpoint_ok((string)$d['endpoint'])) continue;
    $body = push_encrypt($json, b64u_dec((string)$d['p256dh']), b64u_dec((string)$d['auth'])); if ($body === null) { $res['failed']++; continue; }
    $hdr = ['Authorization: vapid t=' . push_jwt($d['endpoint'], $vap['key'], 'mailto:' . $contact) . ', k=' . $vap['pub'],
      'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream', 'TTL: 86400', 'Urgency: high'];
    $ch = curl_init($d['endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6,
      CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => $hdr]);
    curl_multi_add_handle($mh, $ch); $hs[] = [$ch, $d];
  }
  $cerr = [];
  do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0);
    while ($info = curl_multi_info_read($mh)) if ($info['result'] !== CURLE_OK) $cerr[(int)$info['handle']] = curl_strerror($info['result']);
  } while ($run && $st === CURLM_OK);
  foreach ($hs as [$ch, $d]) {
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $why = $code ?: ($cerr[(int)$ch] ?? 'no reply');
    if ($code >= 200 && $code < 300) { $res['sent']++; $d['last_ok'] = date('c'); unset($d['last_error'], $d['last_error_at']); push_save($d); }
    elseif (in_array($code, [404, 410], true)) { $res['removed']++; push_forget($d['id']); push_log("removed {$d['id']} ({$d['name']}): $code"); }
    else { $res['failed']++; $d['last_error'] = (string)$why; $d['last_error_at'] = date('c'); push_save($d); push_log("fail {$d['id']} ({$d['name']}): $why " . substr((string)curl_multi_getcontent($ch), 0, 200)); }
    curl_multi_remove_handle($mh, $ch); curl_close($ch);
  }
  curl_multi_close($mh);
  return $res;
}

/* the one call the rest of the site makes: notify every device that has this event switched on. $url is a full link into the admin. */
function push_notify(string $event, string $title, string $body, string $url = '', string $tag = ''): void {
  try {
    $ev = push_events(); if (!isset($ev[$event])) return;
    $to = array_filter(push_devices(), fn($d) => $d['events'][$event] ?? $ev[$event][2]);
    if (!$to) return;
    $r = push_send($to, ['title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 240), 'url' => $url ?: abs_url('admin'), 'tag' => $tag ?: $event]);
    push_log("$event sent {$r['sent']} failed {$r['failed']} removed {$r['removed']}: $title");
  } catch (\Throwable $e) { push_log("$event error: " . $e->getMessage()); }   // a notification must never break a customer's request
}
function push_lead_url(string $ref): string { return abs_url('admin') . '?v=lead&ref=' . rawurlencode($ref); }
