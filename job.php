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

/* save one uploaded photo privately (outside the web root); returns the file name or an error string starting with '!' */
function job_photo(array $f, string $dir, string $prefix): string {
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) return '!upload';
  if ((int)$f['size'] > 12 * 1024 * 1024) return '!too big';
  $info = @getimagesize((string)$f['tmp_name']); $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? '';
  if ($ext === '') return '!not a photo';
  $name = $prefix . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . ".$ext";
  if (!@move_uploaded_file((string)$f['tmp_name'], "$dir/$name")) return '!save';
  @chmod("$dir/$name", 0600);
  return $name;
}
$err = '';
if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST' && rate_ok('job', 30)) {
  $act = (string)($_POST['act'] ?? ''); $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000);
  if ($act === 'done' && $l['status'] !== 'collected') {
    $complete = (string)($_POST['complete'] ?? ''); $missing = mb_substr(trim((string)($_POST['missing'] ?? '')), 0, 1000);
    $cars = $_FILES['car_photo'] ?? null; $carList = [];
    if (is_array($cars) && is_array($cars['name'] ?? null)) foreach ($cars['name'] as $i => $_) if (($cars['error'][$i] ?? 4) !== UPLOAD_ERR_NO_FILE) $carList[] = ['tmp_name' => $cars['tmp_name'][$i], 'error' => $cars['error'][$i], 'size' => $cars['size'][$i]];
    if (empty($_FILES['id_photo']) || ($_FILES['id_photo']['error'] ?? 4) === UPLOAD_ERR_NO_FILE) $err = 'Add a photo of the seller\'s ID.';
    elseif (!in_array($complete, ['yes', 'no'], true)) $err = 'Say whether the car is complete.';
    elseif ($complete === 'no' && $missing === '') $err = 'Say what\'s missing or wrong with the car.';
    elseif (!$carList) $err = 'Add at least one photo of the car.';
    else {
      $dir = store_path('photos/' . $l['ref']);
      $idp = job_photo($_FILES['id_photo'], $dir, 'id');
      $carp = []; foreach (array_slice($carList, 0, 4) as $c) { $r = job_photo($c, $dir, 'car'); if ($r[0] === '!') { $carp = $r; break; } $carp[] = $r; }
      if ($idp[0] === '!' || is_string($carp)) $err = 'A photo didn\'t upload (' . ltrim($idp[0] === '!' ? $idp : $carp, '!') . '). Please try again, or take a smaller photo.';
      else {
        $chk = ['at' => date('c'), 'by' => $driver, 'complete' => $complete === 'yes', 'missing' => $complete === 'no' ? $missing : '',
          'battery' => !empty($_POST['battery']), 'engine' => !empty($_POST['engine']), 'cat' => !empty($_POST['cat']), 'id_photo' => $idp, 'car_photos' => $carp];
        $l['collection_check'] = $chk;
        $l['status'] = 'collected'; $l['collected_at'] = date('c'); $l['collected_by'] = "Driver: $driver";
        if ($note !== '') $l['driver_report'] = $note;
        $has = fn($k, $t) => $t . ' ' . ($chk[$k] ? 'yes' : 'NO');
        $summary = 'Complete: ' . ($chk['complete'] ? 'yes' : 'NO (' . $missing . ')') . '. ' . implode(', ', [$has('battery', 'Battery'), $has('engine', 'Engine'), $has('cat', 'Cat')]) . '. ID photo + ' . count($carp) . ' car photo' . (count($carp) === 1 ? '' : 's') . '.';
        lead_log($l, "Driver: $driver", 'Collected. ' . $summary . ($note !== '' ? " Note: $note" : ''), 'collected');
        save_lead($l);
        $warn = !$chk['complete'] || !$chk['battery'] || !$chk['engine'] || !$chk['cat'];
        push_notify('collected', ($warn ? 'Collected, CHECK: ' : 'Collected: ') . "{$l['reg']} by $driver", $summary . ($warn ? ' Check the agreed price before you pay.' : ' Remember to pay the customer.'), push_lead_url($l['ref']), "lead-{$l['ref']}");
        if (!PREVIEW) send_mail($NOTIFY, ($warn ? 'Collected (CHECK, not complete): ' : 'Collected: ') . "{$l['reg']} by $driver",
          "$driver has collected {$l['reg']} (" . vehicle_name($l['vehicle'] ?? null) . ") from " . lead_address($l) . ".\n\n$summary" . ($note !== '' ? "\n\nDriver's note: $note" : '')
          . ($warn ? "\n\nSomething is missing: check the agreed price before you pay the customer." : '') . "\n\nRemember to pay the customer by bank transfer if you haven't already.\n\nPhotos and details: " . abs_url('admin') . '?v=lead&ref=' . $l['ref']);
        $msg = 'Collection recorded. Thanks!';
      }
    }
  } elseif ($act === 'fail' && $l['status'] !== 'collected') {
    $fp = $_FILES['fail_photo'] ?? null; $fpList = [];
    if (is_array($fp) && is_array($fp['name'] ?? null)) foreach ($fp['name'] as $i => $_) if (($fp['error'][$i] ?? 4) !== UPLOAD_ERR_NO_FILE) $fpList[] = ['tmp_name' => $fp['tmp_name'][$i], 'error' => $fp['error'][$i], 'size' => $fp['size'][$i]];
    $saved = [];
    if ($note !== '' && $fpList) { $dir = store_path('photos/' . $l['ref']); foreach (array_slice($fpList, 0, 4) as $c) { $r = job_photo($c, $dir, 'fail'); if ($r[0] === '!') { $err = 'A photo didn\'t upload (' . ltrim($r, '!') . '). Please try again.'; break; } $saved[] = $r; } }
    if ($note === '') $err = 'Please say why it couldn\'t be collected.';
    elseif ($err === '') {
      $l['status'] = 'booked'; $l['failed'][] = ['at' => date('c'), 'by' => $driver, 'why' => $note, 'photos' => $saved];
      if ($saved) $note .= ' (' . count($saved) . ' photo' . (count($saved) === 1 ? '' : 's') . ')';
      lead_log($l, "Driver: $driver", "Couldn't collect: $note", 'failed'); save_lead($l);
      push_notify('failed', "Not collected: {$l['reg']} ($driver)", "$note. Call the customer to rebook" . (!empty($l['phone']) ? ": {$l['phone']}" : '') . '.', push_lead_url($l['ref']), "lead-{$l['ref']}");
      if (!PREVIEW) send_mail($NOTIFY, "Not collected: {$l['reg']} ($driver)", "$driver couldn't collect {$l['reg']} from " . lead_address($l) . ".\n\nReason: $note\n\nCall the customer to rebook: " . ($l['phone'] ?? '') . "\n\n" . abs_url('admin') . '?v=lead&ref=' . $l['ref']);
      $msg = 'Thanks, George has been told.';
    }
  }
  if (($_SERVER['HTTP_X_JOB_AJAX'] ?? '') === '1') { header('Content-Type: application/json'); echo json_encode(['ok' => $err === '', 'msg' => $err ?: $msg]); exit; }
}
$tel = $ok && !empty($l['phone']) ? '+' . intl_phone((string)$l['phone']) : '';
?><!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?= $ok ? h($l['reg']) . ($l['status'] === 'collected' ? ' complete' : ' collection') : 'Job link' ?> | We Buy Scrap Cars Corby</title>
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
.err{background:#fbe4e4;color:#9b2226;font-weight:700;padding:12px 14px;border-radius:10px;margin-bottom:14px}
fieldset{border:0;padding:0;margin:14px 0 0}legend{font-weight:700;margin-bottom:6px}
.opts{display:flex;flex-wrap:wrap;gap:8px}.opts label{display:flex;align-items:center;gap:8px;border:1px solid #b9c4da;border-radius:10px;padding:12px 14px;font-weight:600;background:#fff}
.opts input{width:22px;height:22px;margin:0}
.file{display:block;margin-top:6px;padding:12px;border:2px dashed #b9c4da;border-radius:10px;background:#f7f9fc;width:100%;box-sizing:border-box;font:inherit}
.thumbs{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}.thumbs img{width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid #dde3ee}
.done{background:#cdeed7;color:#0d5c27;font-weight:700;padding:14px;border-radius:10px;text-align:center}
details summary{cursor:pointer;font-weight:700;color:#9b2226;margin-top:14px}
</style></head><body>
<header>We Buy Scrap Cars Corby · Collection</header>
<main>
<?php if ($ok && $l['status'] === 'collected'): ?>
  <div class="box" style="text-align:center;padding:30px 16px">
    <div style="font-size:44px;line-height:1;color:#1d7a3a">&#10003;</div>
    <h1 style="font-size:24px;margin:10px 0 6px">This job is complete</h1>
    <p style="margin:0;color:#51607d"><span class="plate"><?= h($l['reg']) ?></span><?= !empty($l['collected_at']) ? ' collected ' . h(date('D j M, H:i', strtotime($l['collected_at']))) : '' ?>. Thanks!</p>
  </div>
<?php elseif (!$ok): ?>
  <div class="box">This job link isn't valid any more. It may have been moved to another driver or be too old. Please call George on <?= h($S['phone']) ?>.</div>
<?php else: ?>
  <?php if ($msg): ?><div class="msg" role="status"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="err" role="alert"><?= h($err) ?></div><?php endif; ?>
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
    <form method="post" enctype="multipart/form-data" id="doneform">
      <input type="hidden" name="act" value="done">
      <h2 style="margin:0 0 4px;font-size:20px">Collection checklist</h2>
      <p style="margin:0;color:#51607d;font-size:15px">Fill this in before you leave with the car.</p>
      <fieldset><legend>1. Photo of the seller's ID</legend>
        <span style="font-size:15px;color:#51607d">Driving licence or passport.</span>
        <input class="file" type="file" name="id_photo" accept="image/*" required>
        <div class="thumbs" data-for="id_photo"></div></fieldset>
      <fieldset><legend>2. Is the car complete?</legend>
        <div class="opts"><label><input type="radio" name="complete" value="yes" required> Yes</label><label><input type="radio" name="complete" value="no"> No</label></div>
        <label id="missingbox" hidden style="display:block;margin-top:8px;font-weight:600">What's missing or wrong?<textarea name="missing" placeholder="e.g. wheels missing, interior stripped, fire damage"></textarea></label></fieldset>
      <fieldset><legend>3. Does the car have:</legend>
        <div class="opts"><label><input type="checkbox" name="battery" value="1"> Battery</label><label><input type="checkbox" name="engine" value="1"> Engine</label><label><input type="checkbox" name="cat" value="1"> Catalytic converter</label></div></fieldset>
      <fieldset><legend>4. Photo of the car</legend>
        <span style="font-size:15px;color:#51607d">At least one. You can add up to 4.</span>
        <input class="file" type="file" name="car_photo[]" accept="image/*" multiple required>
        <div class="thumbs" data-for="car_photo[]"></div></fieldset>
      <label style="display:block;margin-top:14px;font-weight:600">Anything else George should know? (optional)<textarea name="note" placeholder="e.g. no keys, V5C taken"></textarea></label>
      <button class="btn go" type="submit" id="donebtn">Complete collection</button>
    </form>
    <details><summary>Couldn't collect it?</summary>
      <form method="post" enctype="multipart/form-data" id="failform"><input type="hidden" name="act" value="fail">
        <label>What happened?<textarea name="note" required placeholder="e.g. nobody home, car blocked in, wrong car"></textarea></label>
        <fieldset><legend>Photos (optional)</legend>
          <span style="font-size:15px;color:#51607d">Take or choose up to 4, e.g. the car blocked in or the empty drive.</span>
          <input class="file" type="file" name="fail_photo[]" accept="image/*" multiple>
          <div class="thumbs" data-for="fail_photo[]"></div></fieldset>
        <button class="btn warn" type="submit" id="failbtn">Tell George it wasn't collected</button>
      </form>
    </details>
  <?php endif; ?>
  </div>
<?php endif; ?>
</main>
<script>
(()=>{document.querySelectorAll('input[type=file]').forEach(i=>i.addEventListener('change',()=>{const t=document.querySelector('.thumbs[data-for="'+i.name+'"]');if(!t)return;t.innerHTML='';[...i.files].slice(0,4).forEach(x=>{const im=document.createElement('img');im.src=URL.createObjectURL(x);t.appendChild(im)})}));
const f=document.getElementById('doneform');if(!f)return;
const mb=document.getElementById('missingbox');f.querySelectorAll('input[name=complete]').forEach(r=>r.addEventListener('change',()=>{const no=f.querySelector('input[name=complete]:checked').value==='no';mb.hidden=!no;mb.querySelector('textarea').required=no}));
const shrink=async file=>{try{const b=await createImageBitmap(file);const s=Math.min(1,2000/Math.max(b.width,b.height));const c=document.createElement('canvas');c.width=Math.round(b.width*s);c.height=Math.round(b.height*s);c.getContext('2d').drawImage(b,0,0,c.width,c.height);return await new Promise(r=>c.toBlob(x=>r(x||file),'image/jpeg',0.88))}catch(e){return file}};
f.addEventListener('submit',async e=>{if(!f.reportValidity())return;e.preventDefault();const btn=document.getElementById('donebtn');btn.disabled=true;btn.textContent='Uploading photos…';
  try{const fd=new FormData();['act','note','missing'].forEach(k=>{const el=f.elements[k];if(el)fd.append(k,el.value)});
    fd.append('complete',f.querySelector('input[name=complete]:checked').value);['battery','engine','cat'].forEach(k=>{if(f.elements[k].checked)fd.append(k,'1')});
    fd.append('id_photo',await shrink(f.elements['id_photo'].files[0]),'id.jpg');
    for(const x of [...f.elements['car_photo[]'].files].slice(0,4))fd.append('car_photo[]',await shrink(x),'car.jpg');
    const r=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Job-Ajax':'1'}});const j=await r.json();
    if(j.ok){location.reload();return}alert(j.msg)}catch(err){alert('Upload failed. Check your signal and try again.')}
  btn.disabled=false;btn.textContent='Complete collection'});
const g=document.getElementById('failform');if(g)g.addEventListener('submit',async e=>{if(!g.reportValidity())return;e.preventDefault();const btn=document.getElementById('failbtn');btn.disabled=true;btn.textContent='Sending…';
  try{const fd=new FormData();fd.append('act','fail');fd.append('note',g.elements['note'].value);
    for(const x of [...g.elements['fail_photo[]'].files].slice(0,4))fd.append('fail_photo[]',await shrink(x),'photo.jpg');
    const r=await fetch(location.href,{method:'POST',body:fd,headers:{'X-Job-Ajax':'1'}});const j=await r.json();
    if(j.ok){alert(j.msg);location.reload();return}alert(j.msg)}catch(err){alert('Sending failed. Check your signal and try again.')}
  btn.disabled=false;btn.textContent='Tell George it wasn\'t collected'});
})();
</script>
</body></html>
