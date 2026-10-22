<?php
/* Public Purchase Order page for the supplier (personal link, no account) */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/award.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$pid = preg_match('/^[a-f0-9]{48}$/', $token) ? (int)q('SELECT id FROM po WHERE token = ?', [$token])->fetchColumn() : 0;
$po = $pid ? po_get($pid) : null;
$self = 'po_supplier.php?t=' . $token;

if ($po && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'ack' && $po['status'] === 'issued') {
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
    if ($name === '') { flash('Enter your name to acknowledge.', 'err'); redirect($self); }
    q("UPDATE po SET status = 'acknowledged', acknowledged_at = NOW(), acknowledged_name = ? WHERE id = ?", [$name, $pid]);
    po_log($pid, 'acknowledged by supplier', $name);
    flash('Thank you. The Purchase Order is acknowledged.'); redirect($self);
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title><?= $po ? e($po['number']) : 'Purchase Order' ?></title><link rel="stylesheet" href="assets/style.css?v=5"></head><body>
<?php if (is_demo()): ?><div class="demo-bar">Demo version · this is the page a supplier sees when opening their Purchase Order link</div><?php endif; ?>
<header class="topbar noprint"><span class="brand"><?= e(setting('org_name')) ?></span><span class="who">Purchase Order</span></header>
<main style="max-width:980px;margin:0 auto">
<?php foreach (flash() ?? [] as $f): ?><div class="flash <?= e($f['type']) ?> noprint"><?= e($f['msg']) ?></div><?php endforeach; ?>
<?php if (!$po): http_response_code(404); ?>
  <div class="card"><h2>This link is not valid</h2><p>Please check the link from the email or contact the procurement team.</p></div>
<?php else:
  $lines = po_lines($pid);
  $fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.'); ?>
  <div class="actions noprint" style="margin-bottom:12px"><?= po_badge($po['status']) ?><button class="btn sec" style="margin-left:auto" onclick="window.print()">Print / save as PDF</button></div>
  <?php if ($po['status'] === 'issued'): ?>
  <div class="card noprint" style="border-color:var(--brand)">
    <h2>Please acknowledge this Purchase Order</h2>
    <form method="post" class="row" style="align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>"><input type="hidden" name="act" value="ack">
      <div><label>Your name and position</label><input type="text" name="name" required placeholder="e.g. Grace Wanjiru, Sales Manager"></div>
      <div style="flex:0"><button class="btn" onclick="return confirm('Confirm that your company accepts this order and its terms?')">We accept this order</button></div></form>
  </div>
  <?php endif; ?>
  <?php require __DIR__ . '/inc/po_document.php'; ?>
<?php endif; ?>
</main></body></html>
