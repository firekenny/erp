<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/rfq.php';
$u = require_any(['procurement', 'admin']);

/* ---------- Create from an approved PR ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'create') {
    $pr = pr_get((int)($_POST['pr_id'] ?? 0));
    if (!$pr || $pr['status'] !== 'approved') { flash('Only approved Purchase Requests can go to RFQ.', 'err'); redirect('pr.php?tab=all&status=approved'); }
    $lines = pr_lines((int)$pr['id']);
    $method = proc_method_for(pr_total($lines));
    if (!$method) { flash('No procurement method covers this amount. Set up Procurement methods first.', 'err'); redirect('pr_view.php?id=' . $pr['id']); }
    db()->beginTransaction();
    q('INSERT INTO rfq (number, pr_id, title, method_name, min_quotes, sealed, currency, delivery_location, deadline, created_by, instructions) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [rfq_next_number(), $pr['id'], $pr['title'], $method['name'], $method['min_quotes'], $method['sealed'], $pr['currency'], $pr['delivery_location'],
       date('Y-m-d 17:00:00', strtotime('+7 days')), $u['id'],
       "Prices must include delivery to the location above.\nPlease quote in " . $pr['currency'] . ", excluding VAT if the organisation is exempt.\nLeave a price empty for any item you cannot supply."]);
    $rid = (int)db()->lastInsertId();
    foreach ($lines as $l) {
        q('INSERT INTO rfq_lines (rfq_id, pr_line_id, line_no, item_id, description, specification, qty, unit) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
          [$rid, $l['id'], $l['line_no'], $l['item_id'], $l['item_desc'], $l['specification'], $l['qty'], $l['unit']]);
    }
    q("UPDATE pr SET status = 'processing' WHERE id = ?", [$pr['id']]);
    pr_log((int)$pr['id'], 'sent to RFQ', q('SELECT number FROM rfq WHERE id = ?', [$rid])->fetchColumn());
    rfq_log($rid, 'created', 'From ' . $pr['number'] . ' · ' . $method['name']);
    db()->commit();
    flash('RFQ created. Choose the suppliers and check the deadline, then send it.');
    redirect("rfq_view.php?id=$rid");
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$rfq = rfq_get($id);
if (!$rfq) { flash('RFQ not found.', 'err'); redirect('rfq.php'); }

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $back = fn() => redirect("rfq_view.php?id=$id");
    $canEditDeadline = $rfq['status'] === 'draft' || ($rfq['status'] === 'open' && $rfq['opened_at'] === null);

    if ($act === 'details' && $canEditDeadline) {
        $dl = (string)($_POST['deadline'] ?? '');
        $dlTs = $dl !== '' ? strtotime($dl) : false;
        if ($rfq['sealed'] && !$dlTs) { flash('A sealed RFQ needs a deadline.', 'err'); $back(); }
        if ($rfq['status'] === 'open' && $dlTs && $dlTs <= time()) { flash('The new deadline must be in the future.', 'err'); $back(); }
        $fields = ['deadline' => $dlTs ? date('Y-m-d H:i:00', $dlTs) : null, 'instructions' => trim((string)($_POST['instructions'] ?? ''))];
        if ($rfq['status'] === 'draft') { $fields['title'] = trim((string)($_POST['title'] ?? '')) ?: $rfq['title']; $fields['delivery_location'] = trim((string)($_POST['delivery_location'] ?? '')); }
        q('UPDATE rfq SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields))) . ' WHERE id = ?', [...array_values($fields), $id]);
        if ($rfq['status'] === 'open' && $fields['deadline'] !== $rfq['deadline']) rfq_log($id, 'deadline changed', 'New deadline ' . ($fields['deadline'] ?? 'none'));
        flash('Saved.'); $back();
    }
    if ($act === 'suppliers' && $rfq['status'] === 'draft') {
        $ids = array_map('intval', (array)($_POST['sup'] ?? []));
        q('DELETE FROM rfq_suppliers WHERE rfq_id = ?', [$id]);
        foreach ($ids as $sid) if (q("SELECT 1 FROM suppliers WHERE id = ? AND status = 'active'", [$sid])->fetch()) q('INSERT IGNORE INTO rfq_suppliers (rfq_id, supplier_id) VALUES (?, ?)', [$id, $sid]);
        flash(count($ids) . ' supplier(s) selected.'); $back();
    }
    if ($act === 'send' && $rfq['status'] === 'draft') {
        $sups = rfq_suppliers($id);
        $errs = [];
        if ($rfq['sealed']) {
            if (count($sups) < (int)$rfq['min_quotes']) $errs[] = "This method needs at least {$rfq['min_quotes']} suppliers invited (you selected " . count($sups) . ').';
            if (!$rfq['deadline'] || strtotime($rfq['deadline']) <= time()) $errs[] = 'Set a deadline in the future.';
        }
        if (!rfq_lines($id)) $errs[] = 'The RFQ has no lines.';
        if ($errs) { flash(implode(' ', $errs), 'err'); $back(); }
        q("UPDATE rfq SET status = 'open', sent_at = NOW() WHERE id = ?", [$id]);
        $rfq = rfq_get($id);
        $sent = 0; $notSent = 0;
        if ($rfq['sealed']) {
            foreach ($sups as $s) { $st = rfq_invite($rfq, $s); str_starts_with($st, 'Email sent') ? $sent++ : $notSent++; }
            rfq_log($id, 'sent', "$sent email(s) sent, $notSent link(s) to send manually");
            flash($notSent ? "RFQ is open. $sent email(s) sent; $notSent supplier link(s) could not be emailed: copy them from the table below." : "RFQ is open. Invitations emailed to $sent supplier(s).", $notSent ? 'warn' : 'ok');
        } else {
            rfq_log($id, 'opened for quotes', 'Quotes entered by procurement');
            flash('RFQ is open. Contact the suppliers and enter their quotes below as you receive them.');
        }
        $back();
    }
    if ($act === 'add_supplier' && $rfq['status'] === 'open' && (!$rfq['sealed'] || rfq_accepting_bids($rfq))) {
        $sid = (int)($_POST['supplier_id'] ?? 0);
        if (q("SELECT 1 FROM suppliers WHERE id = ? AND status = 'active'", [$sid])->fetch()) {
            q('INSERT IGNORE INTO rfq_suppliers (rfq_id, supplier_id) VALUES (?, ?)', [$id, $sid]);
            if ($rfq['sealed']) {
                $rs = q('SELECT rs.*, s.name, s.email, s.contact_name FROM rfq_suppliers rs JOIN suppliers s ON s.id = rs.supplier_id WHERE rs.rfq_id = ? AND rs.supplier_id = ?', [$id, $sid])->fetch();
                $st = rfq_invite($rfq, $rs);
                rfq_log($id, 'supplier added', $rs['name'] . ' · ' . $st, $sid);
                flash($rs['name'] . ' invited. ' . $st, str_starts_with($st, 'Email sent') ? 'ok' : 'warn');
            } else { rfq_log($id, 'supplier added', '', $sid); flash('Supplier added.'); }
        }
        $back();
    }
    if ($act === 'resend' && $rfq['sealed'] && rfq_accepting_bids($rfq)) {
        $rs = q('SELECT rs.*, s.name, s.email, s.contact_name FROM rfq_suppliers rs JOIN suppliers s ON s.id = rs.supplier_id WHERE rs.id = ? AND rs.rfq_id = ?', [(int)$_POST['rs_id'], $id])->fetch();
        if ($rs) { $st = rfq_invite($rfq, $rs); rfq_log($id, 'invitation resent', $rs['name'] . ' · ' . $st, (int)$rs['supplier_id']); flash($st, str_starts_with($st, 'Email sent') ? 'ok' : 'warn'); }
        $back();
    }
    if ($act === 'open_bids' && $rfq['sealed'] && $rfq['status'] === 'open' && $rfq['opened_at'] === null) {
        if (!rfq_deadline_passed($rfq)) { flash('Sealed bids can only be opened after the deadline.', 'err'); $back(); }
        q('UPDATE rfq SET opened_at = NOW(), opened_by = ? WHERE id = ?', [$u['id'], $id]);
        $n = (int)q('SELECT COUNT(*) FROM quotes WHERE rfq_id = ?', [$id])->fetchColumn();
        rfq_log($id, 'bids opened', "$n bid(s) received");
        flash("Bids opened: $n received. Prices are now visible.");
        $back();
    }
    if ($act === 'quote' && $rfq['status'] === 'open' && !$rfq['sealed']) {
        $sid = (int)($_POST['supplier_id'] ?? 0);
        if (!q("SELECT 1 FROM suppliers WHERE id = ? AND status = 'active'", [$sid])->fetch()) { flash('Choose a supplier.', 'err'); $back(); }
        $prices = (array)($_POST['price'] ?? []);
        if (!array_filter($prices, fn($p) => trim((string)$p) !== '')) { flash('Enter at least one price.', 'err'); $back(); }
        q('INSERT IGNORE INTO rfq_suppliers (rfq_id, supplier_id, invited_at) VALUES (?, ?, NOW())', [$id, $sid]);
        $num = fn($k) => ($v = trim((string)($_POST[$k] ?? ''))) === '' ? null : max(0, (int)$v);
        rfq_save_quote($rfq, $sid, $prices, (array)($_POST['remark'] ?? []), $num('delivery_days'), $num('validity_days'), trim((string)($_POST['notes'] ?? '')), (int)$u['id']);
        rfq_log($id, 'quote entered', q('SELECT name FROM suppliers WHERE id = ?', [$sid])->fetchColumn(), $sid);
        flash('Quote saved.'); $back();
    }
    if ($act === 'close' && $rfq['status'] === 'open') {
        if ($rfq['sealed'] && $rfq['opened_at'] === null) { flash('Open the sealed bids first.', 'err'); $back(); }
        $n = (int)q('SELECT COUNT(*) FROM quotes WHERE rfq_id = ?', [$id])->fetchColumn();
        if ($n === 0) { flash('No quotes received yet.', 'err'); $back(); }
        q("UPDATE rfq SET status = 'closed' WHERE id = ?", [$id]);
        rfq_log($id, 'closed', "$n quote(s)");
        flash($n < (int)$rfq['min_quotes'] ? "Closed with $n quote(s), fewer than the {$rfq['min_quotes']} required: the award will need a written justification." : 'RFQ closed. It is ready for evaluation and award.', $n < (int)$rfq['min_quotes'] ? 'warn' : 'ok');
        $back();
    }
    if ($act === 'cancel' && in_array($rfq['status'], ['draft', 'open'], true)) {
        q("UPDATE rfq SET status = 'cancelled' WHERE id = ?", [$id]);
        q("UPDATE pr SET status = 'approved' WHERE id = ? AND status = 'processing'", [$rfq['pr_id']]);
        rfq_log($id, 'cancelled', trim((string)($_POST['comment'] ?? '')));
        pr_log((int)$rfq['pr_id'], 'RFQ cancelled', $rfq['number'] . ': back to the approved queue');
        flash('RFQ cancelled. The Purchase Request is back in the approved queue.'); $back();
    }
    $back();
}

/* ---------- Display ---------- */
$lines = rfq_lines($id);
$sups = rfq_suppliers($id);
$quotes = rfq_quotes($id);
$visible = rfq_prices_visible($rfq);
$history = q('SELECT h.*, u.name AS uname, s.name AS sname FROM rfq_history h LEFT JOIN users u ON u.id = h.user_id LEFT JOIN suppliers s ON s.id = h.supplier_id WHERE h.rfq_id = ? ORDER BY h.id', [$id])->fetchAll();
$invitedIds = array_map('intval', array_column($sups, 'supplier_id'));
$candidates = in_array($rfq['status'], ['draft', 'open'], true) ? rfq_candidate_suppliers($id) : [];
$fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');
$deadlineTxt = $rfq['deadline'] ? date('d M Y, H:i', strtotime($rfq['deadline'])) : 'none';

render_header($rfq['number']); ?>
<div class="actions noprint" style="margin:-6px 0 12px">
  <?= rfq_badge($rfq) ?>
  <span class="muted">From <a href="pr_view.php?id=<?= (int)$rfq['pr_id'] ?>"><?= e($rfq['pr_number']) ?></a> · <?= e($rfq['method_name']) ?> · min. <?= (int)$rfq['min_quotes'] ?> quote(s)<?= $rfq['sealed'] ? ' · sealed bids' : '' ?></span>
  <button class="btn sec small" onclick="window.print()" style="margin-left:auto">Print</button>
  <a href="rfq.php">Back to list</a>
</div>

<?php /* ---------- Next-step banner ---------- */
if ($rfq['status'] === 'draft'): ?>
  <div class="flash warn noprint"><b>Draft.</b> Check the details, choose the suppliers, then click <b><?= $rfq['sealed'] ? 'Send invitations' : 'Open for quotes' ?></b> at the bottom.</div>
<?php elseif ($rfq['status'] === 'open' && $rfq['sealed'] && !rfq_deadline_passed($rfq)): ?>
  <div class="flash noprint" style="background:#e8f0f8;color:#1f4e79"><b>Sealed bids are being collected until <?= e($deadlineTxt) ?>.</b> <?= count($quotes) ?> of <?= count($sups) ?> invited supplier(s) have submitted. Prices stay hidden until you open the bids after the deadline.</div>
<?php elseif ($rfq['status'] === 'open' && $rfq['sealed'] && $rfq['opened_at'] === null): ?>
  <div class="card noprint" style="border-color:var(--brand)"><h2>The deadline has passed</h2><p><?= count($quotes) ?> bid(s) received from <?= count($sups) ?> invited supplier(s). Suppliers can no longer change their bids.</p>
    <form method="post" onsubmit="return confirm('Open the bids now? This is recorded with your name and time.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="open_bids"><button class="btn">Open the bids</button></form></div>
<?php endif; ?>

<div class="card">
  <div class="print-only" style="margin-bottom:10px"><b><?= e(setting('org_name')) ?></b> · <?= e($rfq['number']) ?></div>
  <?php if ($rfq['status'] === 'draft' || ($rfq['status'] === 'open' && $rfq['opened_at'] === null)): ?>
  <form method="post" class="noprint">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="details">
    <div class="row">
      <div style="flex:2"><label>Title</label><input type="text" name="title" value="<?= e($rfq['title']) ?>" <?= $rfq['status'] !== 'draft' ? 'disabled' : '' ?>></div>
      <div><label>Deadline for quotes<?= $rfq['sealed'] ? '' : ' <span class="muted">(optional)</span>' ?></label><input type="datetime-local" name="deadline" value="<?= $rfq['deadline'] ? e(date('Y-m-d\TH:i', strtotime($rfq['deadline']))) : '' ?>"></div>
    </div>
    <label>Delivery location</label><input type="text" name="delivery_location" value="<?= e($rfq['delivery_location']) ?>" <?= $rfq['status'] !== 'draft' ? 'disabled' : '' ?>>
    <label>Instructions to suppliers</label><textarea name="instructions" rows="3"><?= e($rfq['instructions']) ?></textarea>
    <div class="actions"><button class="btn sec"><?= $rfq['status'] === 'open' ? 'Save (e.g. extend the deadline)' : 'Save details' ?></button></div>
  </form>
  <?php else: ?>
  <div class="row">
    <div><span class="muted">Title</span><br><b><?= e($rfq['title']) ?></b></div>
    <div><span class="muted">Deadline</span><br><?= e($deadlineTxt) ?></div>
    <div><span class="muted">Delivery location</span><br><?= e($rfq['delivery_location'] ?: '—') ?></div>
    <?php if ($rfq['opened_at']): ?><div><span class="muted">Bids opened</span><br><?= e(date('d M Y H:i', strtotime($rfq['opened_at']))) ?> by <?= e($rfq['opened_name']) ?></div><?php endif; ?>
  </div>
  <?php if ($rfq['instructions']): ?><p style="margin-bottom:0"><span class="muted">Instructions</span><br><?= nl2br(e($rfq['instructions'])) ?></p><?php endif; ?>
  <?php endif; ?>
</div>

<?php /* ---------- Comparison or lines ---------- */
if ($visible && $quotes):
    $best = [];
    foreach ($lines as $l) { $ps = array_filter(array_map(fn($q) => $q['prices'][$l['id']]['price'], $quotes), fn($p) => $p !== null); $best[$l['id']] = $ps ? min($ps) : null; }
    $totals = array_map(fn($q) => $q['complete'] ? $q['total'] : null, $quotes);
    $bestTotal = array_filter($totals, fn($t) => $t !== null) ? min(array_filter($totals, fn($t) => $t !== null)) : null; ?>
<h2>Quote comparison <span class="muted" style="font-weight:400">· <?= count($quotes) ?> quote(s), minimum <?= (int)$rfq['min_quotes'] ?><?= count($quotes) < (int)$rfq['min_quotes'] ? ' <span class="tag warn">below minimum</span>' : '' ?></span></h2>
<div class="table-wrap"><table>
  <tr><th>#</th><th>Item</th><th style="text-align:right">Qty</th>
    <?php foreach ($quotes as $q): ?><th style="text-align:right;min-width:120px"><?= e($q['quote']['name']) ?></th><?php endforeach; ?></tr>
  <?php foreach ($lines as $l): ?>
  <tr>
    <td><?= (int)$l['line_no'] ?></td>
    <td><?= e($l['description']) ?><?php if ($l['specification']): ?><br><span class="muted"><?= e($l['specification']) ?></span><?php endif; ?></td>
    <td style="text-align:right;white-space:nowrap"><?= $fmtQty($l['qty']) ?> <?= e($l['unit']) ?></td>
    <?php foreach ($quotes as $q): $p = $q['prices'][$l['id']]; $isBest = $p['price'] !== null && $p['price'] == $best[$l['id']]; ?>
      <td style="text-align:right;<?= $isBest ? 'background:#e6f4ea;font-weight:600' : '' ?>">
        <?= $p['price'] === null ? '<span class="muted">not quoted</span>' : number_format($p['price'], 2) . '<br><span class="muted" style="font-weight:400">= ' . number_format($p['price'] * (float)$l['qty'], 2) . '</span>' ?>
        <?php if ($p['remarks']): ?><br><span class="muted" style="font-weight:400"><?= e($p['remarks']) ?></span><?php endif; ?>
      </td>
    <?php endforeach; ?>
  </tr>
  <?php endforeach; ?>
  <tr><td colspan="3" style="text-align:right"><b>Total (<?= e($rfq['currency']) ?>)</b></td>
    <?php foreach ($quotes as $q): ?><td style="text-align:right;<?= $q['complete'] && $q['total'] == $bestTotal ? 'background:#e6f4ea' : '' ?>"><b><?= number_format($q['total'], 2) ?></b><?= $q['complete'] ? '' : '<br><span class="tag warn">partial</span>' ?></td><?php endforeach; ?></tr>
  <tr><td colspan="3" style="text-align:right" class="muted">Delivery · validity</td>
    <?php foreach ($quotes as $q): ?><td style="text-align:right" class="muted"><?= $q['quote']['delivery_days'] !== null ? (int)$q['quote']['delivery_days'] . ' days' : '—' ?> · <?= $q['quote']['validity_days'] !== null ? (int)$q['quote']['validity_days'] . ' days' : '—' ?></td><?php endforeach; ?></tr>
  <tr><td colspan="3" style="text-align:right" class="muted">Notes · source</td>
    <?php foreach ($quotes as $q): ?><td style="text-align:right;font-size:12px" class="muted"><?= e($q['quote']['notes']) ?><?= $q['quote']['notes'] ? '<br>' : '' ?><?= $q['quote']['entered_by'] ? 'entered by procurement' : 'submitted online' ?>, <?= e(date('d M H:i', strtotime($q['quote']['submitted_at']))) ?></td><?php endforeach; ?></tr>
</table></div>
<p class="muted">Green = lowest price per line and lowest complete total. Evaluation and award (including split awards by line) are the next build step.</p>
<?php else: ?>
<h2>Lines</h2>
<div class="table-wrap"><table>
  <tr><th>#</th><th>Item</th><th>Specification</th><th style="text-align:right">Qty</th></tr>
  <?php foreach ($lines as $l): ?><tr><td><?= (int)$l['line_no'] ?></td><td><?= e($l['description']) ?></td><td><?= e($l['specification']) ?></td><td style="text-align:right;white-space:nowrap"><?= $fmtQty($l['qty']) ?> <?= e($l['unit']) ?></td></tr><?php endforeach; ?>
</table></div>
<?php endif; ?>

<?php /* ---------- Suppliers ---------- */ ?>
<div class="card noprint" style="margin-top:16px">
  <h2>Suppliers</h2>
  <?php if ($rfq['status'] === 'draft'): ?>
    <p class="muted">Suppliers registered for the categories of these items are listed first. <?= $rfq['sealed'] ? 'Invite at least ' . (int)$rfq['min_quotes'] . '. Each one receives a personal link by email.' : 'You will enter their quotes yourself.' ?></p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="suppliers">
      <div class="table-wrap" style="max-height:360px;overflow-y:auto"><table>
        <tr><th></th><th>Supplier</th><th>Supplies</th><th>Email</th></tr>
        <?php foreach ($candidates as $c): ?>
        <tr style="<?= $c['matches'] ? '' : 'opacity:.75' ?>">
          <td><input type="checkbox" name="sup[]" value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $invitedIds, true) ? 'checked' : '' ?>></td>
          <td><b><?= e($c['name']) ?></b> <?= $c['matches'] ? '<span class="tag stock">matches</span>' : '' ?></td>
          <td><?php foreach (array_filter(explode(' ', (string)$c['cats'])) as $cc): ?><span class="tag"><?= e($cc) ?></span><?php endforeach; ?></td>
          <td><?= $c['email'] ? e($c['email']) : '<span class="tag warn">no email</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$candidates): ?><tr><td colspan="4" class="muted">No active suppliers. Add some on the <a href="suppliers.php">Suppliers</a> page.</td></tr><?php endif; ?>
      </table></div>
      <div class="actions"><button class="btn sec">Save selection (<?= count($sups) ?> selected)</button></div>
    </form>
  <?php else: ?>
    <div class="table-wrap"><table>
      <tr><th>Supplier</th><th>Invitation</th><th>Opened link</th><th>Quote</th><?php if ($rfq['sealed'] && rfq_accepting_bids($rfq)): ?><th></th><?php endif; ?></tr>
      <?php foreach ($sups as $s): ?>
      <tr>
        <td><b><?= e($s['name']) ?></b><br><span class="muted"><?= e($s['email'] ?: 'no email') ?></span></td>
        <td style="font-size:13px"><?= $rfq['sealed'] ? e($s['email_status'] ?? '—') : 'Contacted by procurement' ?>
          <?php if ($rfq['sealed'] && $s['token'] && rfq_accepting_bids($rfq)): ?>
            <div class="copy" style="margin-top:4px"><?= e(rfq_link($s['token'])) ?></div>
            <button type="button" class="btn sec small" style="margin-top:4px" onclick="navigator.clipboard.writeText(this.previousElementSibling.innerText);this.innerText='Copied'">Copy link</button>
            <?php if (is_demo()): ?><a class="btn small" style="margin-top:4px" href="<?= e(rfq_link($s['token'])) ?>" target="_blank">Open as this supplier</a><?php endif; ?>
          <?php endif; ?></td>
        <td><?= $s['viewed_at'] ? e(date('d M H:i', strtotime($s['viewed_at']))) : '—' ?></td>
        <td><?= $s['declined_at'] ? '<span class="tag off">Declined</span>' : ($s['quote_id'] ? '<span class="tag stock">Received ' . e(date('d M H:i', strtotime($s['submitted_at']))) . '</span>' : '<span class="tag">Waiting</span>') ?></td>
        <?php if ($rfq['sealed'] && rfq_accepting_bids($rfq)): ?>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="resend"><input type="hidden" name="rs_id" value="<?= (int)$s['id'] ?>"><button class="btn sec small">Resend email</button></form></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php if ($rfq['status'] === 'open' && (rfq_accepting_bids($rfq) || !$rfq['sealed'])): $others = array_filter($candidates, fn($c) => !in_array((int)$c['id'], $invitedIds, true)); if ($others): ?>
    <form method="post" class="row" style="align-items:flex-end;margin-top:10px">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="add_supplier">
      <div><label>Add a supplier<?= $rfq['sealed'] ? ' (an invitation is sent right away)' : '' ?></label><select name="supplier_id"><?php foreach ($others as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?= $c['matches'] ? ' ✓' : '' ?></option><?php endforeach; ?></select></div>
      <div style="flex:0"><button class="btn sec">Add</button></div>
    </form>
    <?php endif; endif; ?>
  <?php endif; ?>
</div>

<?php /* ---------- Officer quote entry (non-sealed) ---------- */
if ($rfq['status'] === 'open' && !$rfq['sealed']):
    $entrySups = $candidates; ?>
<div class="card noprint">
  <h2>Enter a quote received</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="quote">
    <label>Supplier</label>
    <select name="supplier_id" required><option value="">— select —</option>
      <?php foreach ($entrySups as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?= isset($quotes[(int)$c['id']]) ? ' (quote saved: entering again replaces it)' : '' ?></option><?php endforeach; ?></select>
    <div class="table-wrap" style="margin-top:10px"><table>
      <tr><th>#</th><th>Item</th><th style="text-align:right">Qty</th><th>Unit price (<?= e($rfq['currency']) ?>)</th><th>Remarks</th></tr>
      <?php foreach ($lines as $l): ?>
      <tr><td><?= (int)$l['line_no'] ?></td><td><?= e($l['description']) ?></td><td style="text-align:right;white-space:nowrap"><?= $fmtQty($l['qty']) ?> <?= e($l['unit']) ?></td>
        <td><input type="number" name="price[<?= (int)$l['id'] ?>]" min="0" step="0.01" placeholder="empty = not quoted"></td>
        <td><input type="text" name="remark[<?= (int)$l['id'] ?>]" maxlength="255" placeholder="Brand, model…"></td></tr>
      <?php endforeach; ?>
    </table></div>
    <div class="row">
      <div><label>Delivery time (days)</label><input type="number" name="delivery_days" min="0"></div>
      <div><label>Offer valid for (days)</label><input type="number" name="validity_days" min="0"></div>
      <div style="flex:2"><label>Notes</label><input type="text" name="notes" placeholder="e.g. quote received by email on…"></div>
    </div>
    <div class="actions"><button class="btn">Save quote</button></div>
  </form>
</div>
<?php endif; ?>

<?php /* ---------- Main actions ---------- */ ?>
<div class="card noprint">
  <div class="actions" style="margin:0">
    <?php if ($rfq['status'] === 'draft'): ?>
      <form method="post" onsubmit="return confirm('<?= $rfq['sealed'] ? 'Send the invitations to the selected suppliers now?' : 'Open this RFQ for quotes?' ?>')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="send">
        <button class="btn"><?= $rfq['sealed'] ? 'Send invitations (' . count($sups) . ')' : 'Open for quotes' ?></button></form>
    <?php endif; ?>
    <?php if ($rfq['status'] === 'open' && ($rfq['opened_at'] || !$rfq['sealed'])): ?>
      <form method="post" onsubmit="return confirm('Close the RFQ? No more quotes can be added.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="close"><button class="btn">Close RFQ, ready for award</button></form>
    <?php endif; ?>
    <?php if (in_array($rfq['status'], ['draft', 'open'], true)): ?>
      <details style="margin-left:auto"><summary class="btn sec small" style="list-style:none">Cancel RFQ</summary>
        <form method="post" style="margin-top:8px" onsubmit="return confirm('Cancel this RFQ? The Purchase Request goes back to the approved queue.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="cancel">
          <input type="text" name="comment" placeholder="Reason"><button class="btn danger small" style="margin-top:6px">Confirm cancellation</button></form></details>
    <?php endif; ?>
    <?php if ($rfq['status'] === 'closed'): ?><span class="muted">Next: evaluation, award and Purchase Order (coming in the next build step).</span><?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>History</h2>
  <table>
    <?php foreach ($history as $h): ?>
    <tr><td style="width:150px;white-space:nowrap" class="muted"><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></td>
      <td><b><?= e(ucfirst($h['action'])) ?></b> <?= $h['uname'] ? 'by ' . e($h['uname']) : ($h['sname'] ? 'by ' . e($h['sname']) . ' (supplier)' : '') ?><?= $h['comment'] ? ': ' . e($h['comment']) : '' ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php render_footer();
