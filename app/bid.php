<?php
/* Public page for suppliers: opened through their personal link, no account needed */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/award.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$rs = preg_match('/^[a-f0-9]{48}$/', $token)
    ? q('SELECT rs.*, s.name AS sup_name, s.status AS sup_status FROM rfq_suppliers rs JOIN suppliers s ON s.id = rs.supplier_id WHERE rs.token = ?', [$token])->fetch()
    : false;
$rfq = $rs ? rfq_get((int)$rs['rfq_id']) : null;

function bid_page_start(string $title): void { ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title><?= e($title) ?></title><link rel="stylesheet" href="assets/style.css?v=5"></head><body>
<?php if (is_demo()): ?><div class="demo-bar">Demo version · this is the page a supplier sees when opening their personal link</div><?php endif; ?>
<header class="topbar"><span class="brand"><?= e(setting('org_name')) ?></span><span class="who">Supplier quotation</span></header>
<main style="max-width:980px;margin:0 auto"><?php foreach (flash() ?? [] as $f): ?><div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endforeach;
}
function bid_page_end(): void { echo '</main></body></html>'; }

if (!$rs || !$rfq || $rfq['status'] === 'draft' || $rs['sup_status'] !== 'active') {
    http_response_code(404); bid_page_start('Link not valid');
    echo '<div class="card"><h2>This link is not valid</h2><p>Please check that you copied the complete link from the email, or contact the procurement team.</p></div>';
    bid_page_end(); exit;
}
$self = 'bid.php?t=' . $token;
$accepting = rfq_accepting_bids($rfq) && !$rs['declined_at'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!rfq_accepting_bids($rfq)) { flash('The deadline has passed: offers can no longer be submitted or changed.', 'err'); redirect($self); }
    $act = $_POST['act'] ?? '';
    if ($act === 'decline') {
        q('UPDATE rfq_suppliers SET declined_at = NOW() WHERE id = ?', [$rs['id']]);
        q('DELETE FROM quotes WHERE rfq_id = ? AND supplier_id = ?', [$rfq['id'], $rs['supplier_id']]);
        rfq_log((int)$rfq['id'], 'declined', trim((string)($_POST['reason'] ?? '')), (int)$rs['supplier_id']);
        flash('Thank you. We have recorded that you will not submit an offer.'); redirect($self);
    }
    if ($act === 'undecline') {
        q('UPDATE rfq_suppliers SET declined_at = NULL WHERE id = ?', [$rs['id']]); redirect($self);
    }
    if ($act === 'submit' && !$rs['declined_at']) {
        $prices = (array)($_POST['price'] ?? []);
        if (!array_filter($prices, fn($p) => trim((string)$p) !== '')) { flash('Enter a price for at least one item.', 'err'); redirect($self); }
        $num = fn($k) => ($v = trim((string)($_POST[$k] ?? ''))) === '' ? null : max(0, (int)$v);
        $first = !q('SELECT 1 FROM quotes WHERE rfq_id = ? AND supplier_id = ?', [$rfq['id'], $rs['supplier_id']])->fetch();
        rfq_save_quote($rfq, (int)$rs['supplier_id'], $prices, (array)($_POST['remark'] ?? []), $num('delivery_days'), $num('validity_days'), mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 2000), null);
        rfq_log((int)$rfq['id'], $first ? 'bid submitted' : 'bid updated', '', (int)$rs['supplier_id']);   // no prices in the log
        flash('Your offer has been received (' . date('d M Y H:i') . '). You can change it until the deadline.'); redirect($self);
    }
    redirect($self);
}

if (!$rs['viewed_at']) { q('UPDATE rfq_suppliers SET viewed_at = NOW() WHERE id = ?', [$rs['id']]); rfq_log((int)$rfq['id'], 'link opened', '', (int)$rs['supplier_id']); }
$lines = rfq_lines((int)$rfq['id']);
$mine = rfq_quotes((int)$rfq['id'])[(int)$rs['supplier_id']] ?? null;
$fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');

bid_page_start($rfq['number']); ?>
<h1><?= e($rfq['number']) ?> · <?= e($rfq['title']) ?></h1>
<div class="card">
  <div class="row">
    <div><span class="muted">For</span><br><b><?= e($rs['sup_name']) ?></b></div>
    <div><span class="muted">Deadline</span><br><b><?= e(date('d M Y, H:i', strtotime($rfq['deadline']))) ?></b> <span class="muted">(<?= e(defined('APP_TZ') ? APP_TZ : 'UTC') ?>)</span></div>
    <div><span class="muted">Delivery to</span><br><?= e($rfq['delivery_location'] ?: '—') ?></div>
    <div><span class="muted">Currency</span><br><?= e($rfq['currency']) ?></div>
  </div>
  <?php if ($rfq['instructions']): ?><p style="margin-bottom:0"><span class="muted">Instructions</span><br><?= nl2br(e($rfq['instructions'])) ?></p><?php endif; ?>
  <p style="margin-bottom:0"><span class="muted">Offers will be evaluated on</span><br><?php foreach (rfq_criteria((int)$rfq['id']) as $c): ?><span class="tag"><?= e($c['name']) ?> <?= rtrim(rtrim((string)$c['weight'], '0'), '.') ?>%</span> <?php endforeach; ?></p>
</div>

<?php if (!rfq_accepting_bids($rfq)): ?>
  <div class="flash warn">The deadline has passed. <?= $mine ? 'Your offer submitted on ' . e(date('d M Y H:i', strtotime($mine['quote']['submitted_at']))) . ' is being evaluated.' : 'No offer was received from your company.' ?> Thank you for your interest.</div>
<?php elseif ($rs['declined_at']): ?>
  <div class="card"><p>You told us you will not submit an offer.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>"><input type="hidden" name="act" value="undecline"><button class="btn sec">I changed my mind: submit an offer</button></form></div>
<?php else: ?>
  <?php if ($mine): ?><div class="flash ok">Offer received on <?= e(date('d M Y H:i', strtotime($mine['quote']['submitted_at']))) ?>. You can still change it below until the deadline.</div><?php endif; ?>
  <p class="muted">🔒 Your prices are sealed: the procurement team can only see them after the deadline.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>"><input type="hidden" name="act" value="submit">
    <div class="table-wrap"><table>
      <tr><th>#</th><th>Item</th><th style="text-align:right">Quantity</th><th style="min-width:130px">Unit price (<?= e($rfq['currency']) ?>)</th><th style="text-align:right">Line total</th><th>Brand / model / remarks</th></tr>
      <?php foreach ($lines as $l): $p = $mine['prices'][$l['id']] ?? ['price' => null, 'remarks' => '']; ?>
      <tr>
        <td><?= (int)$l['line_no'] ?></td>
        <td><b><?= e($l['description']) ?></b><?php if ($l['specification']): ?><br><span class="muted"><?= e($l['specification']) ?></span><?php endif; ?></td>
        <td style="text-align:right;white-space:nowrap" data-qty="<?= (float)$l['qty'] ?>"><?= $fmtQty($l['qty']) ?> <?= e($l['unit']) ?></td>
        <td><input type="number" class="bp" name="price[<?= (int)$l['id'] ?>]" min="0" step="0.01" value="<?= $p['price'] !== null ? e(number_format($p['price'], 2, '.', '')) : '' ?>" placeholder="empty = not offered"></td>
        <td style="text-align:right" class="blt">—</td>
        <td><input type="text" name="remark[<?= (int)$l['id'] ?>]" maxlength="255" value="<?= e($p['remarks']) ?>"></td>
      </tr>
      <?php endforeach; ?>
      <tr><td colspan="4" style="text-align:right"><b>Total</b></td><td style="text-align:right"><b id="btot">0.00</b></td><td></td></tr>
    </table></div>
    <div class="row">
      <div><label>Delivery time (days after order)</label><input type="number" name="delivery_days" min="0" value="<?= e($mine['quote']['delivery_days'] ?? '') ?>" required></div>
      <div><label>Offer valid for (days)</label><input type="number" name="validity_days" min="0" value="<?= e($mine['quote']['validity_days'] ?? '30') ?>" required></div>
    </div>
    <label>Comments</label><textarea name="notes" rows="2"><?= e($mine['quote']['notes'] ?? '') ?></textarea>
    <div class="actions"><button class="btn" onclick="return confirm('Submit this offer?')"><?= $mine ? 'Update my offer' : 'Submit my offer' ?></button></div>
  </form>
  <details class="card" style="margin-top:16px"><summary>We will not submit an offer</summary>
    <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>"><input type="hidden" name="act" value="decline">
      <input type="text" name="reason" placeholder="Reason (optional)"><button class="btn sec small" style="margin-top:6px">Confirm</button></form></details>
  <script>
  function bt() { let t = 0; document.querySelectorAll('.bp').forEach(i => { const tr = i.closest('tr'); const q = parseFloat(tr.querySelector('[data-qty]').dataset.qty); const v = parseFloat(i.value);
    tr.querySelector('.blt').textContent = isNaN(v) ? '—' : (v * q).toFixed(2); if (!isNaN(v)) t += v * q; }); document.getElementById('btot').textContent = t.toFixed(2); }
  document.querySelectorAll('.bp').forEach(i => i.addEventListener('input', bt)); bt();
  </script>
<?php endif;
bid_page_end();
