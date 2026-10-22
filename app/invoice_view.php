<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/invoice.php';
$u = require_login();
if (!inv_can_view()) { flash('Only Finance, Procurement and Administrators can see invoices.', 'err'); redirect('index.php'); }
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$inv = invoice_get($id);
if (!$inv) { flash('Invoice not found.', 'err'); redirect('invoices.php'); }
$back = "invoice_view.php?id=$id";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
    if ($act === 'release' && has_role('admin') && $inv['status'] === 'on_hold') {
        if ((int)$inv['created_by'] === (int)$u['id']) flash('You recorded this invoice: another administrator must release it.', 'err');
        elseif ($reason === '') flash('Give the reason for releasing it despite the difference.', 'err');
        else {
            q("UPDATE invoices SET status = 'ready', override_by = ?, override_at = NOW(), override_reason = ? WHERE id = ?", [$u['id'], $reason, $id]);
            invoice_log($id, 'released for payment', $reason);
            audit('invoice.release', $inv['number'] . ': ' . $reason);
            notify_users(users_with_role('finance'), 'Invoice released: ' . $inv['number'], 'Invoice <b>' . e($inv['number']) . '</b> (' . e($inv['supplier_name']) . ') was on hold and has been released for payment by ' . e($u['name']) . ': <i>' . e($reason) . '</i>', $back);
            flash('Released: the invoice is ready to pay.');
        }
    }
    if ($act === 'cancel' && inv_can_edit() && in_array($inv['status'], ['on_hold', 'ready'], true)) {
        if ($reason === '') flash('Give the reason (e.g. duplicate, wrong PO, credit note expected).', 'err');
        else {
            q("UPDATE invoices SET status = 'cancelled' WHERE id = ?", [$id]);
            invoice_log($id, 'cancelled', $reason); audit('invoice.cancel', $inv['number'] . ': ' . $reason);
            flash('Invoice cancelled. Its quantities can be invoiced again.');
        }
    }
    if ($act === 'pay' && inv_can_edit() && in_array($inv['status'], ['ready', 'part_paid'], true)) {
        $amt = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? '0')), 2);
        $date = (string)($_POST['paid_date'] ?? '');
        $method = in_array($_POST['method'] ?? '', PAY_METHODS, true) ? $_POST['method'] : 'Other';
        $ref = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 120);
        $outstanding = round((float)$inv['total'] - invoice_paid($id), 2);
        if ($amt <= 0) flash('Enter the amount paid.', 'err');
        elseif ($amt > $outstanding + 0.005) flash('More than what is still due (' . number_format($outstanding, 2) . ').', 'err');
        elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) flash('Enter a valid payment date (not in the future).', 'err');
        elseif ($ref === '') flash('Enter the bank or payment reference.', 'err');
        else {
            q('INSERT INTO payments (invoice_id, paid_date, amount, method, reference, user_id) VALUES (?, ?, ?, ?, ?, ?)', [$id, $date, $amt, $method, $ref, $u['id']]);
            invoice_refresh_paid($id);
            invoice_log($id, 'payment', number_format($amt, 2) . ' ' . $inv['currency'] . " · $method · $ref");
            audit('invoice.payment', $inv['number'] . ': ' . number_format($amt, 2) . " ($ref)");
            flash('Payment recorded.');
        }
    }
    if ($act === 'unpay' && has_role('admin')) {
        $pid = (int)($_POST['payment_id'] ?? 0);
        $pay = q('SELECT * FROM payments WHERE id = ? AND invoice_id = ?', [$pid, $id])->fetch();
        if ($pay && $reason !== '') {
            q('DELETE FROM payments WHERE id = ?', [$pid]);
            q("UPDATE invoices SET status = 'ready' WHERE id = ?", [$id]); invoice_refresh_paid($id);
            invoice_log($id, 'payment removed', number_format((float)$pay['amount'], 2) . ' · ' . $pay['reference'] . ' · ' . $reason);
            audit('invoice.payment_removed', $inv['number'] . ': ' . $pay['reference'] . ' · ' . $reason);
            flash('Payment removed.');
        } else flash('Give the reason for removing the payment.', 'err');
    }
    redirect($back);
}

if ($inv['status'] === 'on_hold') { invoice_recheck($inv); $inv = invoice_get($id); }
$m = invoice_match($inv);
$pays = q('SELECT p.*, u.name FROM payments p LEFT JOIN users u ON u.id = p.user_id WHERE p.invoice_id = ? ORDER BY p.paid_date, p.id', [$id])->fetchAll();
$paid = invoice_paid($id);
$hist = q('SELECT h.*, u.name FROM invoice_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.invoice_id = ? ORDER BY h.id', [$id])->fetchAll();
$net = (float)$inv['total'] - (float)$inv['tax_amount'];
$tol = rtrim(rtrim(number_format(inv_tolerance(), 1), '0'), '.');

render_header($inv['number']); ?>
<div class="actions" style="margin:-6px 0 12px"><?= inv_badge($inv['status']) ?><?= $inv['override_at'] ? ' <span class="tag">released by admin</span>' : '' ?>
  <a href="invoices.php" style="margin-left:auto">All invoices</a></div>
<div class="card"><div class="row">
  <div><span class="muted">Supplier</span><br><b><?= e($inv['supplier_name']) ?></b><br><span class="muted">Invoice no. <?= e($inv['supplier_invoice_no']) ?></span></div>
  <div><span class="muted">Purchase Order</span><br><a href="po_view.php?id=<?= (int)$inv['po_id'] ?>"><?= e($inv['po_number']) ?></a></div>
  <div><span class="muted">Invoice date · due</span><br><?= e(date('d M Y', strtotime($inv['invoice_date']))) ?><br><span class="muted"><?= $inv['due_date'] ? 'Due ' . e(date('d M Y', strtotime($inv['due_date']))) : '' ?></span></div>
  <div><span class="muted">Total</span><br><b><?= number_format((float)$inv['total'], 2) ?> <?= e($inv['currency']) ?></b><br><span class="muted">Paid <?= number_format($paid, 2) ?> · due <?= number_format(max(0, (float)$inv['total'] - $paid), 2) ?></span></div>
</div>
<p class="muted" style="margin-bottom:0">Recorded by <?= e($inv['created_name']) ?> on <?= e(date('d M Y H:i', strtotime($inv['created_at']))) ?>
  <?php if ($inv['file_name']): ?> · <a href="invoice_file.php?id=<?= $id ?>" target="_blank">📎 <?= e($inv['file_orig'] ?: 'Scanned invoice') ?></a><?php endif; ?>
  <?= $inv['notes'] ? ' · ' . e($inv['notes']) : '' ?></p>
</div>

<?php if ($inv['status'] === 'on_hold'): ?>
<div class="flash warn"><b>On hold: this invoice does not match.</b><br><?= nl2br(e(implode("\n", $m['problems']))) ?>
  <br><span class="muted">It is released automatically if more goods are received and accepted. Otherwise ask the supplier for a corrected invoice or a credit note, then cancel this one.</span></div>
<?php elseif ($inv['override_at']): ?>
<div class="flash warn">Released for payment by <?= e($inv['override_name']) ?> on <?= e(date('d M Y', strtotime($inv['override_at']))) ?>: <i><?= e($inv['override_reason']) ?></i></div>
<?php endif; ?>

<h2>3-way match</h2>
<p class="muted">Quantity invoiced must not exceed what was received and accepted (and not already invoiced). Unit price may be up to <?= e($tol) ?>% above the PO price (Setup > Organisation).</p>
<div class="table-wrap"><table>
  <tr><th>Line</th><th style="text-align:right">Ordered</th><th style="text-align:right">Received &amp; accepted</th><th style="text-align:right">Invoiced elsewhere</th><th style="text-align:right">This invoice</th><th style="text-align:right">PO price</th><th style="text-align:right">Invoice price</th><th style="text-align:right">Amount</th><th>Match</th></tr>
  <?php foreach ($m['lines'] as $r): $l = $r['line']; ?>
  <tr><td><b><?= (int)$r['po']['line_no'] ?></b> <?= e($r['po']['description']) ?></td>
    <td style="text-align:right"><?= fmt_qty($r['ordered']) ?> <?= e($r['po']['unit']) ?></td><td style="text-align:right"><?= fmt_qty($r['accepted']) ?></td><td style="text-align:right"><?= fmt_qty($r['before']) ?></td>
    <td style="text-align:right<?= $r['qty_ok'] ? '' : ';color:#b3261e;font-weight:bold' ?>"><?= fmt_qty($l['qty']) ?></td>
    <td style="text-align:right"><?= number_format((float)$r['po']['unit_price'], 2) ?></td>
    <td style="text-align:right<?= $r['price_ok'] ? '' : ';color:#b3261e;font-weight:bold' ?>"><?= number_format((float)$l['unit_price'], 2) ?></td>
    <td style="text-align:right"><?= number_format((float)$l['qty'] * (float)$l['unit_price'], 2) ?></td>
    <td><?= $r['qty_ok'] && $r['price_ok'] ? '<span class="tag stock">OK</span>' : '<span class="tag warn">' . (!$r['qty_ok'] ? 'Qty' : '') . (!$r['qty_ok'] && !$r['price_ok'] ? ' + ' : '') . (!$r['price_ok'] ? 'Price' : '') . '</span>' ?></td></tr>
  <?php endforeach; ?>
  <tr><td colspan="7" style="text-align:right">Lines</td><td style="text-align:right"><?= number_format($net, 2) ?></td><td></td></tr>
  <tr><td colspan="7" style="text-align:right">Tax / VAT</td><td style="text-align:right"><?= number_format((float)$inv['tax_amount'], 2) ?></td><td></td></tr>
  <tr><td colspan="7" style="text-align:right"><b>Total</b></td><td style="text-align:right"><b><?= number_format((float)$inv['total'], 2) ?></b></td><td></td></tr>
</table></div>

<h2>Payments</h2>
<div class="table-wrap"><table><tr><th>Date</th><th style="text-align:right">Amount</th><th>Method</th><th>Reference</th><th>Recorded by</th><?php if (has_role('admin')): ?><th></th><?php endif; ?></tr>
  <?php if (!$pays): ?><tr><td colspan="6" class="muted">No payment yet.</td></tr><?php endif; ?>
  <?php foreach ($pays as $p): ?><tr><td><?= e(date('d M Y', strtotime($p['paid_date']))) ?></td><td style="text-align:right"><?= number_format((float)$p['amount'], 2) ?></td><td><?= e($p['method']) ?></td><td><?= e($p['reference']) ?></td><td><?= e($p['name'] ?? '') ?></td>
    <?php if (has_role('admin')): ?><td><details><summary class="btn sec small" style="list-style:none">Remove</summary><form method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="unpay"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><input type="text" name="reason" placeholder="Reason" required><button class="btn danger small" style="margin-top:6px">Confirm</button></form></details></td><?php endif; ?></tr><?php endforeach; ?>
</table></div>

<?php if (inv_can_edit() && in_array($inv['status'], ['ready', 'part_paid'], true)): ?>
<form method="post" class="card" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="pay">
  <h2 style="margin-top:0">Record a payment</h2>
  <div class="row">
    <div><label>Amount (<?= e($inv['currency']) ?>)</label><input type="number" step="0.01" min="0.01" name="amount" value="<?= e(number_format(max(0, (float)$inv['total'] - $paid), 2, '.', '')) ?>" required></div>
    <div><label>Payment date</label><input type="date" name="paid_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
    <div><label>Method</label><select name="method"><?php foreach (PAY_METHODS as $pm): ?><option><?= e($pm) ?></option><?php endforeach; ?></select></div>
    <div><label>Bank / payment reference</label><input type="text" name="reference" maxlength="120" required></div>
  </div>
  <div class="actions"><button class="btn">Record payment</button></div>
  <p class="muted">The payment itself is made in your bank or accounting system: this records it against the invoice.</p>
</form>
<?php endif; ?>

<div class="actions" style="margin-top:12px">
  <?php if (has_role('admin') && $inv['status'] === 'on_hold'): ?>
  <details><summary class="btn small" style="list-style:none">Release for payment despite the difference</summary>
    <form method="post" style="margin-top:6px;max-width:520px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="release">
      <input type="text" name="reason" placeholder="Reason, e.g. price increase agreed by email on 12 Oct" required><button class="btn small" style="margin-top:6px">Confirm release</button></form></details>
  <?php endif; ?>
  <?php if (inv_can_edit() && in_array($inv['status'], ['on_hold', 'ready'], true)): ?>
  <details><summary class="btn sec small" style="list-style:none">Cancel invoice</summary>
    <form method="post" style="margin-top:6px;max-width:520px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="cancel">
      <input type="text" name="reason" placeholder="Reason, e.g. duplicate, corrected invoice received" required><button class="btn danger small" style="margin-top:6px">Confirm cancel</button></form></details>
  <?php endif; ?>
</div>

<h2>History</h2>
<div class="table-wrap"><table><?php foreach ($hist as $h): ?><tr><td class="muted" style="white-space:nowrap"><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></td><td><?= e($h['name'] ?? '') ?></td><td><b><?= e($h['action']) ?></b> <?= nl2br(e((string)$h['comment'])) ?></td></tr><?php endforeach; ?></table></div>
<?php render_footer();
