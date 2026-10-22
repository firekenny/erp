<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/award.php';
$u = require_login();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$po = po_get($id);
$canSee = $po && (has_role('procurement') || has_role('admin') || has_role('storekeeper') || has_role('finance') || (int)q('SELECT requester_id FROM pr WHERE id = ?', [$po['pr_id']])->fetchColumn() === (int)$u['id']);
if (!$canSee) { flash('Purchase Order not found or not accessible.', 'err'); redirect('index.php'); }
$isProc = has_role('procurement') || has_role('admin');
require_once __DIR__ . '/inc/stock.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isProc) {
    $act = $_POST['act'] ?? '';
    if ($act === 'resend' && $po['status'] !== 'cancelled') { $st = po_send($po); po_log($id, 'sent to supplier', $st); flash($st, str_starts_with($st, 'Email sent') ? 'ok' : 'warn'); }
    if ($act === 'cancel' && in_array($po['status'], ['issued', 'acknowledged'], true)) {
        $why = trim((string)($_POST['comment'] ?? ''));
        if ($why === '') { flash('Give a reason for cancelling.', 'err'); redirect("po_view.php?id=$id"); }
        q("UPDATE po SET status = 'cancelled' WHERE id = ?", [$id]);
        po_log($id, 'cancelled', $why);
        pr_log((int)$po['pr_id'], 'PO cancelled', $po['number'] . ': ' . $why);
        flash('Purchase Order cancelled. Inform the supplier.');
    }
    if ($act === 'notes') { q('UPDATE po SET notes = ? WHERE id = ?', [trim((string)($_POST['notes'] ?? '')), $id]); flash('Saved.'); }
    redirect("po_view.php?id=$id");
}

$lines = po_lines($id);
$levels = fin_levels();
$codes = fin_code_map();
$history = q('SELECT h.*, u.name FROM po_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.po_id = ? ORDER BY h.id', [$id])->fetchAll();
$fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');

render_header($po['number']); ?>
<div class="actions noprint" style="margin:-6px 0 12px">
  <?= po_badge($po['status']) ?>
  <span class="muted">From <a href="pr_view.php?id=<?= (int)$po['pr_id'] ?>"><?= e($po['pr_number']) ?></a><?php if ($po['rfq_id']): ?> · <a href="rfq_eval.php?id=<?= (int)$po['rfq_id'] ?>"><?= e($po['rfq_number']) ?></a><?php endif; ?></span>
  <button class="btn" onclick="window.print()" style="margin-left:auto">Print / save as PDF</button>
  <?php if ($isProc): ?><a href="po.php">Back to list</a><?php endif; ?>
</div>

<?php if ($isProc && $po['status'] !== 'cancelled'): ?>
<div class="card noprint">
  <span class="muted">Supplier link (view, print and acknowledge) · <?= e($po['email_status'] ?? 'not sent') ?></span>
  <div class="copy" id="polink"><?= e(po_link($po['token'])) ?></div>
  <div class="actions" style="margin-top:8px">
    <button type="button" class="btn sec small" onclick="navigator.clipboard.writeText(document.getElementById('polink').innerText);this.innerText='Copied'">Copy link</button>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="resend"><button class="btn sec small">Email to supplier</button></form>
    <?php if (is_demo()): ?><a class="btn small" href="<?= e(po_link($po['token'])) ?>" target="_blank">Open as supplier</a><?php endif; ?>
  </div>
  <?php if ($po['acknowledged_at']): ?><p class="flash ok" style="margin:10px 0 0">Acknowledged by <?= e($po['acknowledged_name']) ?> on <?= e(date('d M Y H:i', strtotime($po['acknowledged_at']))) ?>.</p><?php endif; ?>
</div>
<?php endif; ?>

<?php $grns = q('SELECT g.id, g.number, g.received_date FROM grn g WHERE g.po_id = ? ORDER BY g.id', [$id])->fetchAll();
if ($grns || ((has_role('storekeeper') || has_role('admin')) && in_array($po['status'], ['issued', 'acknowledged', 'partially_received'], true))): ?>
<div class="flash noprint" style="background:#e8f0f8;color:#1f4e79">Receipts: <?php foreach ($grns as $g): ?><a href="grn_view.php?id=<?= (int)$g['id'] ?>"><b><?= e($g['number']) ?></b></a> (<?= e(date('d M', strtotime($g['received_date']))) ?>) <?php endforeach; ?><?= $grns ? '' : 'none yet' ?>
  <?php if ((has_role('storekeeper') || has_role('admin')) && in_array($po['status'], ['issued', 'acknowledged', 'partially_received'], true)): ?> · <a class="btn small" href="receive.php?po=<?= $id ?>">Receive goods</a><?php endif; ?></div>
<?php endif; ?>
<?php if (has_role('finance') || $isProc):
  $invs = q("SELECT i.id, i.number, i.supplier_invoice_no, i.total, i.status, (SELECT COALESCE(SUM(amount), 0) FROM payments y WHERE y.invoice_id = i.id) AS paid FROM invoices i WHERE i.po_id = ? ORDER BY i.id", [$id])->fetchAll();
  $canInv = (has_role('finance') || has_role('admin')) && in_array($po['status'], ['partially_received', 'received'], true);
  if ($invs || $canInv): require_once __DIR__ . '/inc/invoice.php'; ?>
<div class="flash noprint" style="background:#e8f0f8;color:#1f4e79">Invoices: <?php foreach ($invs as $i): ?><a href="invoice_view.php?id=<?= (int)$i['id'] ?>"><b><?= e($i['number']) ?></b></a> <?= number_format((float)$i['total'], 2) ?> <?= inv_badge($i['status']) ?> <?php endforeach; ?><?= $invs ? '' : 'none yet' ?>
  <?php if ($canInv): ?> · <a class="btn small" href="invoices.php?po=<?= $id ?>">Record an invoice</a><?php endif; ?></div>
<?php endif; endif; ?>
<?php require __DIR__ . '/inc/po_document.php'; ?>

<?php if ($isProc): ?>
<div class="card noprint">
  <h2>History</h2>
  <table><?php foreach ($history as $h): ?><tr><td style="width:150px" class="muted"><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></td><td><b><?= e(ucfirst($h['action'])) ?></b> <?= $h['name'] ? 'by ' . e($h['name']) : '' ?><?= $h['comment'] ? ': ' . e($h['comment']) : '' ?></td></tr><?php endforeach; ?></table>
</div>
<?php if (in_array($po['status'], ['issued', 'acknowledged'], true)): ?>
<details class="card noprint"><summary>Cancel this Purchase Order</summary>
  <form method="post" onsubmit="return confirm('Cancel this Purchase Order?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="cancel">
    <label>Reason</label><input type="text" name="comment" required><div class="actions"><button class="btn danger">Cancel PO</button></div></form>
</details>
<?php endif; endif; ?>
<?php render_footer();
