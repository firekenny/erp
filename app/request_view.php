<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stock.php';
$u = require_login();
$isStore = has_role('storekeeper') || has_role('admin');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$r = q('SELECT r.*, u.name AS requester, w.name AS wh FROM stock_requests r JOIN users u ON u.id = r.requester_id JOIN warehouses w ON w.id = r.warehouse_id WHERE r.id = ?', [$id])->fetch();
if (!$r || (!$isStore && (int)$r['requester_id'] !== (int)$u['id'])) { flash('Request not found.', 'err'); redirect('requests.php'); }
$open = in_array($r['status'], ['submitted', 'partially_issued'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $open) {
    $act = $_POST['act'] ?? '';
    if ($act === 'cancel' && (int)$r['requester_id'] === (int)$u['id'] && $r['status'] === 'submitted') {
        q("UPDATE stock_requests SET status = 'cancelled' WHERE id = ?", [$id]); flash('Request cancelled.'); redirect("request_view.php?id=$id");
    }
    if ($act === 'reject' && $isStore) {
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note === '') { flash('Give the reason.', 'err'); redirect("request_view.php?id=$id"); }
        q("UPDATE stock_requests SET status = ?, storekeeper_note = ? WHERE id = ?", [$r['status'] === 'partially_issued' ? 'issued' : 'rejected', mb_substr($note, 0, 500), $id]);
        notify_users([$r['requester_id']], ($r['status'] === 'partially_issued' ? 'Request closed: ' : 'Request rejected: ') . $r['number'], 'Your stock request <b>' . e($r['number']) . '</b> was ' . ($r['status'] === 'partially_issued' ? 'closed' : 'rejected') . ' by the storekeeper: <i>' . e($note) . '</i>', 'request_view.php?id=' . $id);
        flash($r['status'] === 'partially_issued' ? 'Request closed with what was already issued.' : 'Request rejected.'); redirect("request_view.php?id=$id");
    }
    if ($act === 'issue' && $isStore) {
        $lines = array_column(q('SELECT * FROM stock_request_lines WHERE request_id = ?', [$id])->fetchAll(), null, 'id');
        $todo = []; $errs = [];
        foreach ((array)($_POST['qty'] ?? []) as $lid => $v) {
            $l = $lines[(int)$lid] ?? null; $qty = (float)str_replace(',', '.', (string)$v);
            if (!$l || $qty <= 0) continue;
            $remain = (float)$l['qty_requested'] - (float)$l['qty_issued'];
            $have = stock_qty((int)$l['item_id'], (int)$r['warehouse_id']);
            $code = q('SELECT code FROM items WHERE id = ?', [$l['item_id']])->fetchColumn();
            if ($qty > $remain + 0.0001) $errs[] = "$code: more than requested.";
            elseif ($qty > $have + 0.0001) $errs[] = "$code: only " . rtrim(rtrim(number_format($have, 3, '.', ''), '0'), '.') . ' in stock.';
            else $todo[] = [$l, $qty];
        }
        if (!$todo && !$errs) $errs[] = 'Enter at least one quantity to issue.';
        if ($errs) { flash(implode(' ', $errs), 'err'); redirect("request_view.php?id=$id"); }
        db()->beginTransaction();
        $num = next_doc_number('issues', 'ISS');
        q("INSERT INTO issues (number, type, request_id, warehouse_id, issued_to, destination, notes, issued_by) VALUES (?, 'issue', ?, ?, ?, ?, ?, ?)",
          [$num, $id, $r['warehouse_id'], mb_substr(trim((string)($_POST['issued_to'] ?? '')) ?: $r['issue_to'], 0, 190), $r['destination'], trim((string)($_POST['notes'] ?? '')), $u['id']]);
        $iid = (int)db()->lastInsertId();
        $to = q('SELECT issued_to FROM issues WHERE id = ?', [$iid])->fetchColumn();
        foreach ($todo as [$l, $qty]) {
            $taken = stock_out((int)$l['item_id'], (int)$r['warehouse_id'], $qty, ['type' => 'issue', 'ref_type' => 'issue', 'ref_id' => $iid, 'ref_number' => $num, 'issued_to' => $to, 'destination' => $r['destination']]);
            $avg = (float)q('SELECT avg_cost FROM stock WHERE item_id = ? AND warehouse_id = ?', [$l['item_id'], $r['warehouse_id']])->fetchColumn();
            foreach ($taken as [$lot, $t]) q('INSERT INTO issue_lines (issue_id, item_id, lot_id, qty, unit_cost) VALUES (?, ?, ?, ?, ?)', [$iid, $l['item_id'], $lot['id'], $t, $avg]);
            q('UPDATE stock_request_lines SET qty_issued = qty_issued + ? WHERE id = ?', [$qty, $l['id']]);
        }
        $left = (float)q('SELECT COALESCE(SUM(qty_requested - qty_issued), 0) FROM stock_request_lines WHERE request_id = ?', [$id])->fetchColumn();
        q('UPDATE stock_requests SET status = ? WHERE id = ?', [$left > 0.0001 ? 'partially_issued' : 'issued', $id]);
        audit('issue.create', $num);
        db()->commit();
        notify_users([$r['requester_id']], 'Items issued: ' . $r['number'], 'Your stock request <b>' . e($r['number']) . '</b> has been ' . ($left > 0.0001 ? 'partly ' : '') . 'issued (' . e($num) . '). Please collect the items and sign the voucher.', 'request_view.php?id=' . $id);
        flash("$num issued. Print the voucher and have the recipient sign it.");
        redirect("issue_view.php?id=$iid");
    }
    redirect("request_view.php?id=$id");
}

$lines = q('SELECT l.*, i.code, i.description, i.unit, COALESCE(st.qty, 0) AS in_stock FROM stock_request_lines l JOIN items i ON i.id = l.item_id
            LEFT JOIN stock st ON st.item_id = l.item_id AND st.warehouse_id = ? WHERE l.request_id = ? ORDER BY i.code', [$r['warehouse_id'], $id])->fetchAll();
$issues = q('SELECT id, number, issued_at FROM issues WHERE request_id = ? ORDER BY id', [$id])->fetchAll();
$fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');

render_header($r['number']); ?>
<div class="actions" style="margin:-6px 0 12px"><span class="tag <?= SR_STATUS[$r['status']][1] ?>"><?= SR_STATUS[$r['status']][0] ?></span>
  <?php foreach ($issues as $i): ?><a href="issue_view.php?id=<?= (int)$i['id'] ?>"><?= e($i['number']) ?></a><?php endforeach; ?><a href="requests.php" style="margin-left:auto">Back to list</a></div>
<div class="card"><div class="row">
  <div><span class="muted">Requested by</span><br><b><?= e($r['requester']) ?></b><br><span class="muted"><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></span></div>
  <div><span class="muted">From</span><br><?= e($r['wh']) ?></div>
  <div><span class="muted">Issue to · destination</span><br><?= e($r['issue_to']) ?><br><span class="muted"><?= e($r['destination']) ?></span></div>
  <div><span class="muted">Needed by</span><br><?= $r['needed_by'] ? e(date('d M Y', strtotime($r['needed_by']))) : '—' ?></div>
</div><?php if ($r['purpose']): ?><p style="margin-bottom:0"><span class="muted">Purpose</span><br><?= e($r['purpose']) ?></p><?php endif; ?>
<?php if ($r['storekeeper_note']): ?><p class="flash warn" style="margin:10px 0 0">Storekeeper: <?= e($r['storekeeper_note']) ?></p><?php endif; ?></div>

<form method="post">
<?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="issue">
<div class="table-wrap"><table>
  <tr><th>Item</th><th style="text-align:right">Requested</th><th style="text-align:right">Issued</th><?php if ($isStore && $open): ?><th style="text-align:right">In stock</th><th>Issue now</th><?php endif; ?></tr>
  <?php foreach ($lines as $l): $remain = (float)$l['qty_requested'] - (float)$l['qty_issued']; ?>
  <tr><td><b><?= e($l['code']) ?></b> <?= e($l['description']) ?></td>
    <td style="text-align:right"><?= $fmtQty($l['qty_requested']) ?> <?= e($l['unit']) ?></td><td style="text-align:right"><?= $fmtQty($l['qty_issued']) ?></td>
    <?php if ($isStore && $open): ?><td style="text-align:right"><?= $fmtQty($l['in_stock']) ?></td>
      <td><?php if ($remain > 0.0001): ?><input type="number" step="any" min="0" name="qty[<?= (int)$l['id'] ?>]" value="<?= e($fmtQty(min($remain, (float)$l['in_stock']))) ?>" style="max-width:130px"><?php else: ?><span class="tag stock">done</span><?php endif; ?></td><?php endif; ?></tr>
  <?php endforeach; ?>
</table></div>
<?php if ($isStore && $open): ?>
<div class="card" style="margin-top:12px">
  <div class="row"><div><label>Handed over to</label><input type="text" name="issued_to" value="<?= e($r['issue_to']) ?>"></div><div style="flex:2"><label>Notes</label><input type="text" name="notes"></div></div>
  <div class="actions"><button class="btn" onclick="return confirm('Issue these quantities? Stock will be reduced.')">Issue and print voucher</button></div>
  <p class="muted">Stock is taken from the batches expiring first, then the oldest. The voucher records which donor's stock was used.</p>
</div>
<?php endif; ?>
</form>
<?php if ($open): ?>
<div class="actions">
  <?php if ((int)$r['requester_id'] === (int)$u['id'] && $r['status'] === 'submitted'): ?>
  <form method="post" onsubmit="return confirm('Cancel this request?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="cancel"><button class="btn sec small">Cancel my request</button></form>
  <?php endif; ?>
  <?php if ($isStore): ?>
  <details><summary class="btn sec small" style="list-style:none"><?= $r['status'] === 'partially_issued' ? 'Close (no more will be issued)' : 'Reject request' ?></summary>
    <form method="post" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="reject"><input type="text" name="note" placeholder="Reason" required><button class="btn danger small" style="margin-top:6px">Confirm</button></form></details>
  <?php endif; ?>
</div>
<?php endif;
render_footer();
