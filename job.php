<?php
/* We Buy Scrap Cars Corby: driver job page, /job/{ref}/{token}. Included from index.php (direct access blocked in .htaccess).
   The link is sent to the driver by WhatsApp or text. The token is tied to the job AND the driver it was sent to,
   so if George moves the job to another driver the old link stops working. No login needed. */
declare(strict_types=1);
if (!defined('SC_APP')) { http_response_code(403); exit; }
header('X-Robots-Tag: noindex, nofollow'); header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY');

$ref = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($seg[1] ?? ''))); $tok = preg_replace('/[^a-f0-9]/', '', (string)($seg[2] ?? ''));
$l = $ref !== '' ? load_lead($ref) : null;
$ops = operatives($SET); $op = $l ? (string)($l['op'] ?? '') : '';
$ok = $l && $op !== '' && isset($ops[$op]) && $tok !== '' && hash_equals(job_token($l['ref'], $op), $tok)
      && !empty($l['collect_on']) && strtotime($l['collect_on']) > strtotime('-14 days') && in_array($l['status'], ['booked', 'dispatched', 'collected'], true);
$driver = $ok ? (string)$ops[$op]['name'] : '';
$msg = '';

if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST' && rate_ok('job', 30)) {
  $act = (string)($_POST['act'] ?? ''); $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000);
  if ($act === 'done' && $l['status'] !== 'collected') {
    $l['status'] = 'collected'; $l['collected_at'] = date('c'); $l['collected_by'] = "Driver: $driver";
    if ($note !== '') $l['driver_report'] = $note;
    lead_log($l, "Driver: $driver", 'Collected' . ($note !== '' ? ". Note: $note" : ''), 'collected');
    save_lead($l);
    if (!PREVIEW) send_mail($NOTIFY, "Collected: {$l['reg']} by $driver", "$driver has marked {$l['reg']} (" . vehicle_name($l['vehicle'] ?? null) . ") as collected from " . lead_address($l) . '.' . ($note !== '' ? "\n\nDriver's note: $note" : '') . "\n\nRemember to pay the customer by bank transfer if you haven't already.\n\n" . abs_url('admin') . '?v=lead&ref=' . $l['ref']);
    $msg = 'Marked as collected. Thanks!';
  } elseif ($act === 'fail' && $l['status'] !== 'collected') {
    if ($note === '') $msg = 'Please say why it couldn\'t be collected.';
    else {
      $l['status'] = 'booked'; $l['failed'][] = ['at' => date('c'), 'by' => $driver, 'why' => $note];
      lead_log($l, "Driver: $driver", "Couldn't collect: $note", 'failed'); save_lead($l);
      if (!PREVIEW) send_mail($NOTIFY, "Not collected: {$l['reg']} ($driver)", "$driver couldn't collect {$l['reg']} from " . lead_address($l) . ".\n\nReason: $note\n\nCall the customer to rebook: " . ($l['phone'] ?? '') . "\n\n" . abs_url('admin') . '?v=lead&ref=' . $l['ref']);
      $msg = 'Thanks, George has been told.';
    }
  }
}
$tel = $ok && !empty($l['phone']) ? '+' . intl_phone((string)$l['phone']) : '';
?><!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= $ok ? h($l['reg']) . ' collection' : 'Job link' ?> | We Buy Scrap Cars Corby</title>
<style>
body{margin:0;font:17px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#10224A;background:#eef1f6}
header{background:#0A1733;color:#fff;padding:14px 16px;font-weight:700}
main{max-width:560px;margin:0 auto;padding:16px}
.box{background:#fff;border:1px solid #dde3ee;border-radius:12px;padding:16px;margin-bottom:14px}
.plate{display:inline-block;background:#FFD200;color:#111;font-weight:800;letter-spacing:.04em;padding:3px 10px;border-radius:4px;border:1px solid #c9a700}
dl{display:grid;grid-template-columns:max-content 1fr;gap:8px 14px;margin:0}dt{color:#51607d}dd{margin:0;font-weight:600;word-break:break-word}
.btn{display:flex;align-items:center;justify-content:center;min-height:52px;border-radius:10px;font:inherit;font-weight:700;text-decoration:none;border:2px solid #10224A;background:#fff;color:#10224A;margin-top:10px;width:100%;cursor:pointer;box-sizing:border-box}
.go{background:#3FBF5F;border-color:#3FBF5F;color:#06210F}.warn{border-color:#9b2226;color:#9b2226}
textarea{display:block;width:100%;box-sizing:border-box;min-height:80px;margin-top:6px;padding:10px;border:1px solid #b9c4da;border-radius:9px;font:inherit}
.msg{background:#e3f6e8;color:#0d5c27;font-weight:700;padding:12px 14px;border-radius:10px;margin-bottom:14px}
.done{background:#cdeed7;color:#0d5c27;font-weight:700;padding:14px;border-radius:10px;text-align:center}
details summary{cursor:pointer;font-weight:700;color:#9b2226;margin-top:14px}
</style></head><body>
<header>We Buy Scrap Cars Corby · Collection</header>
<main>
<?php if (!$ok): ?>
  <div class="box">This job link isn't valid any more. It may have been moved to another driver or be too old. Please call George on <?= h($S['phone']) ?>.</div>
<?php else: ?>
  <?php if ($msg): ?><div class="msg" role="status"><?= h($msg) ?></div><?php endif; ?>
  <div class="box">
    <p style="margin-top:0"><span class="plate"><?= h($l['reg']) ?></span></p>
    <dl>
      <dt>When</dt><dd><?= h(collect_when($l)) ?></dd>
      <dt>Vehicle</dt><dd><?= h(vehicle_line($l['vehicle'] ?? null) ?: 'Not known') ?></dd>
      <dt>Name</dt><dd><?= h($l['name'] ?? '') ?></dd>
      <dt>Phone</dt><dd><?= h($l['phone'] ?? '') ?></dd>
      <dt>Address</dt><dd><?= h(lead_address($l)) ?></dd>
      <?php if (!empty($l['driver_note'])): ?><dt>Notes</dt><dd><?= nl2br(h($l['driver_note'])) ?></dd><?php endif; ?>
      <dt>Driver</dt><dd><?= h($driver) ?></dd>
    </dl>
    <?php if ($tel): ?><a class="btn" href="tel:<?= h($tel) ?>">Call <?= h(trim(explode(' ', (string)($l['name'] ?? ''))[0]) ?: 'customer') ?></a><?php endif; ?>
    <a class="btn" href="<?= h(maps_url($l)) ?>" rel="noopener">Open in Maps</a>
  </div>
  <div class="box">
  <?php if ($l['status'] === 'collected'): ?>
    <div class="done">Collected <?= !empty($l['collected_at']) ? h(date('D j M, H:i', strtotime($l['collected_at']))) : '' ?></div>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="act" value="done">
      <label>Anything George should know? (optional)<textarea name="note" placeholder="e.g. no keys, cat missing, V5C taken"></textarea></label>
      <button class="btn go" type="submit">Mark as collected</button>
    </form>
    <details><summary>Couldn't collect it?</summary>
      <form method="post"><input type="hidden" name="act" value="fail">
        <label>What happened?<textarea name="note" required placeholder="e.g. nobody home, car blocked in, wrong car"></textarea></label>
        <button class="btn warn" type="submit">Tell George it wasn't collected</button>
      </form>
    </details>
  <?php endif; ?>
  </div>
<?php endif; ?>
</main></body></html>
