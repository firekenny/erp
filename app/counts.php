<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stock.php';
$u = require_any(['storekeeper', 'admin']);
$whs = warehouses();
$fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'start') {
        $wh = (int)($_POST['warehouse_id'] ?? 0);
        if (!array_filter($whs, fn($w) => (int)$w['id'] === $wh)) { flash('Choose the warehouse.', 'err'); redirect('counts.php'); }
        if (q("SELECT 1 FROM stock_counts WHERE warehouse_id = ? AND status IN ('open','submitted')", [$wh])->fetch()) { flash('A count is already in progress for this warehouse.', 'err'); redirect('counts.php'); }
        db()->beginTransaction();
        $num = next_doc_number('stock_counts', 'CNT');
        q('INSERT INTO stock_counts (number, warehouse_id, created_by, notes) VALUES (?, ?, ?, ?)', [$num, $wh, $u['id'], trim((string)($_POST['notes'] ?? ''))]);
        $cid = (int)db()->lastInsertId();
        q('INSERT INTO stock_count_lines (count_id, item_id) SELECT ?, item_id FROM stock WHERE warehouse_id = ? AND qty > 0', [$cid, $wh]);
        db()->commit();
        flash("$num started. Print the count sheet, count, then enter the quantities.");
        redirect("counts.php?id=$cid");
    }
    $c = $id ? q('SELECT * FROM stock_counts WHERE id = ?', [$id])->fetch() : null;
    if (!$c) redirect('counts.php');
    if ($act === 'save' && $c['status'] === 'open') {
        foreach ((array)($_POST['cnt'] ?? []) as $lid => $v) {
            $v = trim(str_replace(',', '.', (string)$v));
            q('UPDATE stock_count_lines SET counted_qty = ?, note = ? WHERE id = ? AND count_id = ?', [$v === '' ? null : max(0, (float)$v), mb_substr(trim((string)($_POST['note'][$lid] ?? '')), 0, 255), (int)$lid, $id]);
        }
        $add = (int)($_POST['add_item'] ?? 0); $addQ = trim(str_replace(',', '.', (string)($_POST['add_qty'] ?? '')));
        if ($add && $addQ !== '') q('INSERT INTO stock_count_lines (count_id, item_id, counted_qty, note) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE counted_qty = VALUES(counted_qty)', [$id, $add, max(0, (float)$addQ), 'Found during count']);
        if (($_POST['go'] ?? '') === 'submit') {
            $missing = (int)q('SELECT COUNT(*) FROM stock_count_lines WHERE count_id = ? AND counted_qty IS NULL', [$id])->fetchColumn();
            if ($missing) { flash("$missing item(s) have no counted quantity yet (enter 0 if none were found).", 'err'); redirect("counts.php?id=$id"); }
            q('UPDATE stock_count_lines l LEFT JOIN stock s ON s.item_id = l.item_id AND s.warehouse_id = ? SET l.system_qty = COALESCE(s.qty, 0) WHERE l.count_id = ?', [$c['warehouse_id'], $id]);
            q("UPDATE stock_counts SET status = 'submitted', submitted_by = ?, submitted_at = NOW() WHERE id = ?", [$u['id'], $id]);
            flash('Count submitted. An Administrator will review the differences.');
        } else flash('Saved.');
        redirect("counts.php?id=$id");
    }
    if (in_array($act, ['approve', 'reject'], true) && $c['status'] === 'submitted' && has_role('admin')) {
        if ((int)$c['submitted_by'] === (int)$u['id']) { flash('You submitted this count: another Administrator must approve it.', 'err'); redirect("counts.php?id=$id"); }
        $note = trim((string)($_POST['note'] ?? ''));
        if ($act === 'reject') {
            if ($note === '') { flash('Give the reason (e.g. recount shelf B).', 'err'); redirect("counts.php?id=$id"); }
            q("UPDATE stock_counts SET status = 'rejected', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?", [$u['id'], mb_substr($note, 0, 500), $id]);
            flash('Count rejected: no stock was changed. Start a new count if needed.'); redirect("counts.php?id=$id");
        }
        db()->beginTransaction();
        $n = 0;
        foreach (q('SELECT * FROM stock_count_lines WHERE count_id = ?', [$id])->fetchAll() as $l) {
            $now = stock_qty((int)$l['item_id'], (int)$c['warehouse_id']);
            $diff = (float)$l['counted_qty'] - $now;
            if (abs($diff) < 0.0001) continue;
            $ref = ['ref_type' => 'count', 'ref_id' => $id, 'ref_number' => $c['number']];
            if ($diff < 0) stock_out((int)$l['item_id'], (int)$c['warehouse_id'], -$diff, $ref + ['type' => 'count loss']);
            else {
                $avg = (float)(q('SELECT avg_cost FROM stock WHERE item_id = ? AND warehouse_id = ?', [$l['item_id'], $c['warehouse_id']])->fetchColumn() ?: 0);
                stock_in((int)$l['item_id'], (int)$c['warehouse_id'], $diff, $avg, $ref + ['type' => 'count gain', 'source_type' => 'count']);
            }
            $n++;
        }
        q("UPDATE stock_counts SET status = 'approved', decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ?", [$u['id'], mb_substr($note, 0, 500), $id]);
        audit('count.approve', $c['number'] . " ($n adjustments)");
        db()->commit();
        flash("Count approved: $n stock adjustment(s) posted.");
        redirect("counts.php?id=$id");
    }
    redirect("counts.php?id=$id");
}

/* ---------- One count ---------- */
if ($id) {
    $c = q('SELECT c.*, w.name AS wh, u.name AS by_name, s.name AS sub_name, d.name AS dec_name FROM stock_counts c JOIN warehouses w ON w.id = c.warehouse_id JOIN users u ON u.id = c.created_by
            LEFT JOIN users s ON s.id = c.submitted_by LEFT JOIN users d ON d.id = c.decided_by WHERE c.id = ?', [$id])->fetch();
    if (!$c) redirect('counts.php');
    $lines = q('SELECT l.*, i.code, i.description, i.unit, COALESCE(st.avg_cost, 0) AS avg_cost FROM stock_count_lines l JOIN items i ON i.id = l.item_id
                LEFT JOIN stock st ON st.item_id = l.item_id AND st.warehouse_id = ? WHERE l.count_id = ? ORDER BY i.code', [$c['warehouse_id'], $id])->fetchAll();
    $showSystem = $c['status'] !== 'open' && (has_role('admin') || $c['status'] !== 'submitted');
    render_header($c['number'] . ' · ' . $c['wh']); ?>
<div class="actions noprint" style="margin:-6px 0 12px"><span class="tag <?= COUNT_STATUS[$c['status']][1] ?>"><?= COUNT_STATUS[$c['status']][0] ?></span>
  <span class="muted">Started by <?= e($c['by_name']) ?> <?= e(date('d M Y H:i', strtotime($c['created_at']))) ?><?= $c['sub_name'] ? ' · submitted by ' . e($c['sub_name']) : '' ?><?= $c['dec_name'] ? ' · decided by ' . e($c['dec_name']) : '' ?></span>
  <button class="btn sec small" style="margin-left:auto" onclick="window.print()">Print <?= $c['status'] === 'open' ? 'count sheet' : 'count report' ?></button><a href="counts.php">Back</a></div>
<?php if ($c['status'] === 'open'): ?><div class="flash noprint" style="background:#e8f0f8;color:#1f4e79">Blind count: system quantities are hidden until the count is submitted. Avoid issuing or receiving in this warehouse while counting.</div><?php endif; ?>
<?php if ($c['decision_note']): ?><div class="flash warn"><?= e($c['decision_note']) ?></div><?php endif; ?>
<div class="print-only"><h2><?= e(setting('org_name')) ?> · Stock count <?= e($c['number']) ?> · <?= e($c['wh']) ?></h2></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="save">
<div class="table-wrap"><table>
  <tr><th>Item</th><th style="text-align:right">Counted</th><?php if ($showSystem): ?><th style="text-align:right">System</th><th style="text-align:right">Difference</th><th style="text-align:right">Value</th><?php endif; ?><th>Note</th></tr>
  <?php $tot = 0; foreach ($lines as $l): $d = $showSystem ? (float)$l['counted_qty'] - (float)$l['system_qty'] : 0; $tot += $d * (float)$l['avg_cost']; ?>
  <tr><td><b><?= e($l['code']) ?></b> <?= e($l['description']) ?> <span class="muted">(<?= e($l['unit']) ?>)</span></td>
    <td style="text-align:right"><?php if ($c['status'] === 'open'): ?><input type="number" step="any" min="0" name="cnt[<?= (int)$l['id'] ?>]" value="<?= $l['counted_qty'] === null ? '' : e($fmtQty($l['counted_qty'])) ?>" style="max-width:120px;text-align:right" class="noprint"><span class="print-only">________</span><?php else: ?><?= $fmtQty($l['counted_qty']) ?><?php endif; ?></td>
    <?php if ($showSystem): ?><td style="text-align:right"><?= $fmtQty($l['system_qty']) ?></td>
      <td style="text-align:right;<?= abs($d) > 0.0001 ? 'font-weight:700;color:' . ($d < 0 ? '#a12a20' : '#1e6b35') : '' ?>"><?= abs($d) > 0.0001 ? ($d > 0 ? '+' : '') . $fmtQty($d) : '0' ?></td>
      <td style="text-align:right"><?= abs($d) > 0.0001 ? number_format($d * (float)$l['avg_cost'], 2) : '' ?></td><?php endif; ?>
    <td><?php if ($c['status'] === 'open'): ?><input type="text" name="note[<?= (int)$l['id'] ?>]" value="<?= e($l['note']) ?>" class="noprint"><?php else: ?><?= e($l['note']) ?><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if ($showSystem): ?><tr><td colspan="4" style="text-align:right"><b>Net value of differences (<?= e(setting('currency', 'USD')) ?>)</b></td><td style="text-align:right"><b><?= number_format($tot, 2) ?></b></td><td></td></tr><?php endif; ?>
</table></div>
<?php if ($c['status'] === 'open'):
    $others = q('SELECT id, code, description FROM items WHERE active = 1 AND item_type = \'stock\' AND id NOT IN (SELECT item_id FROM stock_count_lines WHERE count_id = ?) ORDER BY code', [$id])->fetchAll(); ?>
<div class="card noprint" style="margin-top:12px">
  <div class="row" style="align-items:flex-end"><div style="flex:3"><label>Item found that is not on the list</label><select name="add_item"><option value="">—</option><?php foreach ($others as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['code'] . ' – ' . $o['description']) ?></option><?php endforeach; ?></select></div>
    <div><label>Counted</label><input type="number" step="any" min="0" name="add_qty"></div></div>
  <div class="actions"><button class="btn sec" name="go" value="save">Save</button><button class="btn" name="go" value="submit" onclick="return confirm('Submit the count? You cannot change it afterwards.')">Submit count</button></div>
</div>
<?php endif; ?>
</form>
<?php if ($c['status'] === 'submitted' && has_role('admin')): ?>
<div class="card noprint" style="border-color:var(--brand)">
  <h2>Review</h2>
  <?php if ((int)$c['submitted_by'] === (int)$u['id']): ?><p class="flash warn" style="margin:0">You submitted this count: another Administrator must approve it.</p>
  <?php else: ?><p class="muted">Approving posts the differences to stock. Losses are taken from the lots expiring first, then the oldest, so the donor of the lost items stays traceable.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><label>Comment (required to reject)</label><input type="text" name="note">
    <div class="actions"><button class="btn" name="act" value="approve" onclick="return confirm('Approve and adjust stock?')">Approve and adjust stock</button><button class="btn sec" name="act" value="reject">Reject (recount)</button></div></form><?php endif; ?>
</div>
<?php endif; ?>
<div class="row print-only" style="margin-top:30px"><div>Counted by ____________________</div><div>Checked by ____________________</div></div>
<?php render_footer(); exit;
}

/* ---------- List ---------- */
$rows = q('SELECT c.*, w.name AS wh, (SELECT COUNT(*) FROM stock_count_lines l WHERE l.count_id = c.id) AS n FROM stock_counts c JOIN warehouses w ON w.id = c.warehouse_id ORDER BY c.id DESC LIMIT 200')->fetchAll();
render_header('Stock counts'); ?>
<form method="post" class="card row" style="align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="act" value="start">
  <div><label>Start a count in</label><select name="warehouse_id" required><option value="">— warehouse —</option><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
  <div style="flex:2"><label>Notes</label><input type="text" name="notes" placeholder="e.g. Quarterly count Q4"></div>
  <div style="flex:0"><button class="btn">Start count</button></div></form>
<div class="table-wrap"><table>
  <tr><th>Count</th><th>Warehouse</th><th>Items</th><th>Started</th><th>Status</th></tr>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted">No counts yet.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr><td><a href="?id=<?= (int)$r['id'] ?>"><b><?= e($r['number']) ?></b></a></td><td><?= e($r['wh']) ?></td><td><?= (int)$r['n'] ?></td>
    <td><?= e(date('d M Y', strtotime($r['created_at']))) ?></td><td><span class="tag <?= COUNT_STATUS[$r['status']][1] ?>"><?= COUNT_STATUS[$r['status']][0] ?></span></td></tr><?php endforeach; ?>
</table></div>
<?php render_footer();
