<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/invoice.php';
require_once __DIR__ . '/inc/xlsx.php';
$u = require_login();
if (!inv_can_view()) { flash('Only Finance, Procurement and Administrators can see invoices.', 'err'); redirect('index.php'); }
$cur = setting('currency', 'USD');

/* ---------- Record a new invoice ---------- */
$poId = (int)($_GET['po'] ?? $_POST['po_id'] ?? 0);
$po = $poId ? po_get($poId) : null;
if ($po && !in_array($po['status'], ['partially_received', 'received'], true)) $po = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && inv_can_edit() && $po) {
    $errs = [];
    $supNo = mb_substr(trim((string)($_POST['supplier_invoice_no'] ?? '')), 0, 80);
    $date = (string)($_POST['invoice_date'] ?? '');
    $due = (string)($_POST['due_date'] ?? '');
    $tax = round((float)str_replace(',', '.', (string)($_POST['tax'] ?? '0')), 2);
    if ($supNo === '') $errs[] = "Enter the supplier's invoice number.";
    elseif (q("SELECT number FROM invoices WHERE supplier_id = ? AND supplier_invoice_no = ? AND status <> 'cancelled'", [$po['supplier_id'], $supNo])->fetchColumn())
        $errs[] = "Invoice $supNo from this supplier is already recorded: it may be a duplicate.";
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) $errs[] = 'Enter a valid invoice date (not in the future).';
    if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) $errs[] = 'The due date is not valid.';
    if ($tax < 0) $errs[] = 'Tax cannot be negative.';
    $poLines = array_column(po_lines($poId), null, 'id');
    $lines = [];
    foreach ((array)($_POST['qty'] ?? []) as $lid => $qv) {
        $qty = round((float)str_replace(',', '.', (string)$qv), 3);
        $price = round((float)str_replace(',', '.', (string)($_POST['price'][$lid] ?? '0')), 2);
        if (!isset($poLines[(int)$lid]) || $qty <= 0) continue;
        if ($price < 0) { $errs[] = 'Prices cannot be negative.'; continue; }
        $lines[(int)$lid] = [$qty, $price];
    }
    if (!$lines && !$errs) $errs[] = 'Enter the invoiced quantity on at least one line.';
    $file = null;
    if (!$errs && !is_demo()) { $file = inv_save_upload('file'); if (is_string($file)) $errs[] = $file; }
    if ($errs) { flash(implode(' ', $errs), 'err'); }
    else {
        db()->beginTransaction();
        $num = next_doc_number('invoices', 'INV');
        $total = $tax; foreach ($lines as [$qt, $pr]) $total += $qt * $pr;
        q('INSERT INTO invoices (number, supplier_invoice_no, po_id, supplier_id, currency, invoice_date, due_date, tax_amount, total, notes, file_name, file_orig, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$num, $supNo, $poId, $po['supplier_id'], $po['currency'], $date, $due ?: null, $tax, round($total, 2), trim((string)($_POST['notes'] ?? '')), $file[0] ?? '', $file[1] ?? '', $u['id']]);
        $iid = (int)db()->lastInsertId();
        foreach ($lines as $lid => [$qt, $pr]) q('INSERT INTO invoice_lines (invoice_id, po_line_id, qty, unit_price) VALUES (?, ?, ?, ?)', [$iid, $lid, $qt, $pr]);
        invoice_log($iid, 'recorded', "Supplier invoice $supNo");
        $inv = invoice_get($iid);
        q("UPDATE invoices SET status = 'ready' WHERE id = ?", [$iid]); $inv['status'] = 'ready';
        $status = invoice_recheck($inv);
        audit('invoice.create', "$num ($supNo) for {$po['number']}");
        db()->commit();
        if ($status === 'on_hold') {
            notify_users(users_with_role('admin'), "Invoice on hold: $num", 'Supplier invoice <b>' . e($supNo) . '</b> for <b>' . e($po['number']) . '</b> does not match the order and the goods received. Please check it and release or cancel it.', 'invoice_view.php?id=' . $iid);
            flash("$num recorded but put ON HOLD: it does not match the PO and what was received. See the reasons below.", 'warn');
        } else flash("$num recorded. It matches the PO and the goods received: ready to pay.");
        redirect("invoice_view.php?id=$iid");
    }
    redirect("invoices.php?po=$poId");
}

/* ---------- List ---------- */
invoice_recheck_all();
$st = (string)($_GET['status'] ?? (has_role('admin') && invoice_hold_count() ? 'on_hold' : 'open'));
$s = trim((string)($_GET['s'] ?? ''));
$where = ['1=1']; $p = [];
if ($st === 'open') $where[] = "i.status IN ('on_hold','ready','part_paid')";
elseif (isset(INV_STATUS[$st])) { $where[] = 'i.status = ?'; $p[] = $st; }
if ($s !== '') { $where[] = '(i.number LIKE ? OR i.supplier_invoice_no LIKE ? OR s.name LIKE ? OR p.number LIKE ?)'; array_push($p, "%$s%", "%$s%", "%$s%", "%$s%"); }
$rows = q('SELECT i.*, s.name AS supplier_name, p.number AS po_number, (SELECT COALESCE(SUM(amount), 0) FROM payments y WHERE y.invoice_id = i.id) AS paid
           FROM invoices i JOIN suppliers s ON s.id = i.supplier_id JOIN po p ON p.id = i.po_id WHERE ' . implode(' AND ', $where) . ' ORDER BY i.id DESC LIMIT 500', $p)->fetchAll();

if (isset($_GET['xlsx'])) xlsx_send('invoices_' . date('Y-m-d') . '.xlsx', [['name' => 'Invoices',
    'header' => ['Invoice', 'Supplier invoice no.', 'Supplier', 'PO', 'Invoice date', 'Due date', 'Currency', 'Total', 'Paid', 'Outstanding', 'Status', 'Hold reasons'],
    'rows' => array_map(fn($r) => [$r['number'], $r['supplier_invoice_no'], $r['supplier_name'], $r['po_number'], $r['invoice_date'], $r['due_date'] ?? '', $r['currency'],
        (float)$r['total'], (float)$r['paid'], $r['status'] === 'cancelled' ? 0.0 : round((float)$r['total'] - (float)$r['paid'], 2), INV_STATUS[$r['status']][0], $r['status'] === 'on_hold' ? (string)$r['hold_reasons'] : ''], $rows)]]);

render_header($po ? 'Record an invoice for ' . $po['number'] : 'Supplier invoices');

if ($po && inv_can_edit()):
    $acc = po_accepted($poId); $already = po_invoiced($poId); $sup = q('SELECT name FROM suppliers WHERE id = ?', [$po['supplier_id']])->fetchColumn(); ?>
<form method="post" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?><input type="hidden" name="po_id" value="<?= $poId ?>">
  <p style="margin-top:0"><b><?= e($sup) ?></b> · <a href="po_view.php?id=<?= $poId ?>"><?= e($po['number']) ?></a> · <?= e($po['currency']) ?></p>
  <div class="row">
    <div><label>Supplier's invoice number</label><input type="text" name="supplier_invoice_no" required maxlength="80"></div>
    <div><label>Invoice date</label><input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
    <div><label>Due date</label><input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>"></div>
  </div>
  <h2 style="margin-top:16px">What the supplier invoices</h2>
  <p class="muted">Copy the quantity and unit price <b>from the supplier's invoice</b>. The ERP compares them with the PO and with what the storekeeper accepted.</p>
  <div class="table-wrap"><table>
    <tr><th>Line</th><th style="text-align:right">Ordered</th><th style="text-align:right">Accepted</th><th style="text-align:right">Already invoiced</th><th style="text-align:right">PO price</th><th>Invoiced qty</th><th>Invoice unit price</th></tr>
    <?php foreach (po_lines($poId) as $l): $a = $acc[$l['id']] ?? 0; $b = $already[$l['id']] ?? 0; $left = max(0, $a - $b); ?>
    <tr><td><b><?= (int)$l['line_no'] ?></b> <?= e($l['description']) ?></td>
      <td style="text-align:right"><?= fmt_qty($l['qty']) ?> <?= e($l['unit']) ?></td><td style="text-align:right"><?= fmt_qty($a) ?></td><td style="text-align:right"><?= fmt_qty($b) ?></td>
      <td style="text-align:right"><?= number_format((float)$l['unit_price'], 2) ?></td>
      <td><input type="number" step="any" min="0" name="qty[<?= (int)$l['id'] ?>]" value="<?= $left > 0 ? e(fmt_qty($left)) : '' ?>" style="max-width:110px" class="iq"></td>
      <td><input type="number" step="0.01" min="0" name="price[<?= (int)$l['id'] ?>]" value="<?= e(number_format((float)$l['unit_price'], 2, '.', '')) ?>" style="max-width:130px" class="ip"></td></tr>
    <?php endforeach; ?>
  </table></div>
  <div class="row" style="margin-top:12px">
    <div><label>Tax / VAT on the invoice (<?= e($po['currency']) ?>)</label><input type="number" step="0.01" min="0" name="tax" value="0" id="tax"></div>
    <div><label>Invoice total</label><input type="text" id="tot" readonly></div>
    <div style="flex:2"><label>Scanned invoice (PDF, JPG or PNG, max 10 MB)</label><?php if (is_demo()): ?><p class="muted" style="margin:6px 0">File upload is switched off in the demo.</p><?php else: ?><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png"><?php endif; ?></div>
  </div>
  <label>Notes</label><input type="text" name="notes" maxlength="500">
  <div class="actions"><button class="btn">Record and check the match</button><a href="invoices.php">Cancel</a></div>
</form>
<script>
function tot() { let t = parseFloat(document.getElementById('tax').value || 0);
  document.querySelectorAll('.iq').forEach(q => { const p = q.closest('tr').querySelector('.ip'); t += (parseFloat(q.value || 0) * parseFloat(p.value || 0)); });
  document.getElementById('tot').value = t.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' <?= e($po['currency']) ?>'; }
document.querySelectorAll('input').forEach(i => i.addEventListener('input', tot)); tot();
</script>
<?php elseif (isset($_GET['new']) && inv_can_edit()): $todo = pos_to_invoice(); ?>
<div class="card">
  <h2 style="margin-top:0">Which Purchase Order is the invoice for?</h2>
  <p class="muted">Only orders with goods or services received and accepted, not yet fully invoiced, are listed.</p>
  <?php if (!$todo): ?><p>Nothing to invoice: no received goods are waiting for an invoice.</p><?php else: ?>
  <div class="table-wrap"><table><tr><th>PO</th><th>Supplier</th><th style="text-align:right">Received, not yet invoiced</th><th></th></tr>
  <?php foreach ($todo as $t): ?><tr><td><b><?= e($t['number']) ?></b></td><td><?= e($t['supplier_name']) ?></td><td style="text-align:right"><?= number_format($t['left'], 2) ?> <?= e($t['currency']) ?></td>
    <td><a class="btn small" href="?po=<?= (int)$t['id'] ?>">Record invoice</a></td></tr><?php endforeach; ?></table></div>
  <?php endif; ?>
  <div class="actions"><a href="invoices.php">Back to the list</a></div>
</div>
<?php else:
  $due = q("SELECT COALESCE(SUM(i.total - (SELECT COALESCE(SUM(amount), 0) FROM payments y WHERE y.invoice_id = i.id)), 0) AS o,
            COALESCE(SUM(CASE WHEN i.due_date < CURDATE() THEN i.total - (SELECT COALESCE(SUM(amount), 0) FROM payments y WHERE y.invoice_id = i.id) END), 0) AS late
            FROM invoices i WHERE i.status IN ('ready','part_paid')")->fetch(); ?>
<div class="tiles" style="margin-bottom:14px">
  <a class="tile" href="?status=on_hold" style="text-decoration:none;color:inherit"><span class="muted">On hold (mismatch)</span><b><?= invoice_hold_count() ?></b></a>
  <a class="tile" href="?status=open" style="text-decoration:none;color:inherit"><span class="muted">To pay</span><b><?= number_format((float)$due['o'], 2) ?></b><?php if ((float)$due['late'] > 0): ?><span class="tag warn">Overdue <?= number_format((float)$due['late'], 2) ?></span><?php endif; ?></a>
</div>
<form method="get" class="row" style="margin-bottom:10px;align-items:flex-end">
  <?php if (inv_can_edit()): ?><div style="flex:0;white-space:nowrap"><a class="btn" href="?new=1">+ Record an invoice</a></div><?php endif; ?>
  <div style="flex:2"><input type="text" name="s" value="<?= e($s) ?>" placeholder="Search invoice, supplier or PO"></div>
  <div><select name="status"><option value="open" <?= $st === 'open' ? 'selected' : '' ?>>Open (not fully paid)</option><option value="" <?= $st === '' ? 'selected' : '' ?>>All</option>
    <?php foreach (INV_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div style="flex:0;white-space:nowrap"><button class="btn sec">Filter</button> <a class="btn sec" href="?<?= e(http_build_query(['status' => $st, 's' => $s, 'xlsx' => 1])) ?>">Excel</a></div>
</form>
<p class="muted">Amounts are in each invoice's currency (the totals above add them up, assuming one currency: <?= e($cur) ?>).</p>
<div class="table-wrap"><table>
  <tr><th>Invoice</th><th>Supplier · PO</th><th>Due</th><th style="text-align:right">Total</th><th style="text-align:right">Outstanding</th><th>Status</th></tr>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">No invoices here.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $out = (float)$r['total'] - (float)$r['paid']; $late = $r['due_date'] && $r['due_date'] < date('Y-m-d') && in_array($r['status'], ['ready', 'part_paid'], true); ?>
  <tr><td><a href="invoice_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['number']) ?></b></a><br><span class="muted">Supplier no. <?= e($r['supplier_invoice_no']) ?></span></td>
    <td><?= e($r['supplier_name']) ?><br><span class="muted"><?= e($r['po_number']) ?></span></td>
    <td style="white-space:nowrap"><?= $r['due_date'] ? e(date('d M Y', strtotime($r['due_date']))) : '—' ?><?= $late ? ' <span class="tag warn">overdue</span>' : '' ?></td>
    <td style="text-align:right;white-space:nowrap"><?= number_format((float)$r['total'], 2) ?> <?= e($r['currency']) ?></td>
    <td style="text-align:right;white-space:nowrap"><?= $r['status'] === 'cancelled' ? '—' : number_format($out, 2) ?></td>
    <td><?= inv_badge($r['status']) ?><?= $r['override_at'] ? ' <span class="tag">released by admin</span>' : '' ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif;
render_footer();
