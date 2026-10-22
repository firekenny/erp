<?php
/* Public page: a staff member signs for equipment through their personal link (no account) */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$hid = preg_match('/^[a-f0-9]{48}$/', $token) ? (int)q("SELECT id FROM handovers WHERE token = ? AND type = 'handover'", [$token])->fetchColumn() : 0;
$h = $hid ? handover_get($hid) : null;
$self = 'handover_sign.php?t=' . $token;

if ($h && $_SERVER['REQUEST_METHOD'] === 'POST' && !$h['signed_at']) {
    $sig = (string)($_POST['signature'] ?? ''); $name = mb_substr(trim((string)($_POST['signed_name'] ?? '')), 0, 160);
    if ($name !== '' && preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $sig) && strlen($sig) <= 400000) {
        q("UPDATE handovers SET signed_at = NOW(), signed_name = ?, signature = ?, sign_method = 'link' WHERE id = ? AND signed_at IS NULL", [$name, $sig, $hid]);
        foreach (handover_lines($hid) as $l) asset_event((int)$l['asset_id'], 'signed', "Receipt signed by $name (link)", 'handover', $hid);
        flash('Thank you, your signature is recorded.');
    } else flash('Please write your name and sign in the box.', 'err');
    redirect($self);
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Equipment handover</title><link rel="stylesheet" href="assets/style.css?v=7"></head><body>
<?php if (is_demo()): ?><div class="demo-bar">Demo version · this is the page a staff member sees when opening their handover link</div><?php endif; ?>
<header class="topbar"><span class="brand"><?= e(setting('org_name')) ?></span><span class="who">Equipment handover</span></header>
<main style="max-width:900px;margin:0 auto">
<?php foreach (flash() ?? [] as $f): ?><div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
<?php if (!$h): http_response_code(404); ?><div class="card"><h2>This link is not valid</h2><p>Please check the link or contact the logistics team.</p></div>
<?php else: $lines = handover_lines($hid); $signAction = $h['signed_at'] ? '' : $self; $signToken = $token; require __DIR__ . '/inc/handover_doc.php'; endif; ?>
</main></body></html>
