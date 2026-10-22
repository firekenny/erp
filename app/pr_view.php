<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pr.php';
$u = require_login();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$pr = pr_get($id);
if (!$pr || !pr_can_view($pr, $u)) { flash('Purchase Request not found or not accessible.', 'err'); redirect('pr.php'); }

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $comment = trim((string)($_POST['comment'] ?? ''));

    if (in_array($act, ['approve', 'return'], true)) {
        $rows = pr_decidable($pr, $u);
        if (!$rows) { flash('There is nothing for you to approve on this request.', 'err'); redirect("pr_view.php?id=$id"); }
        if ($act === 'return' && $comment === '') { flash('Say what needs to change when returning a request.', 'err'); redirect("pr_view.php?id=$id"); }
        // a Budget Holder decides on their own rows; an admin on all decidable rows
        $mine = array_filter($rows, fn($a) => (int)$a['approver_id'] === (int)$u['id']);
        $targets = $mine ?: $rows;
        db()->beginTransaction();
        foreach ($targets as $a) {
            q('UPDATE pr_approvals SET status = ?, comment = ?, decided_by = ?, decided_at = NOW() WHERE id = ?',
              [$act === 'approve' ? 'approved' : 'returned', $comment, $u['id'], $a['id']]);
        }
        $onBehalf = !$mine ? ' (as Administrator, on behalf of ' . implode(', ', array_unique(array_column($targets, 'approver_name'))) . ')' : '';
        if ($act === 'return') {
            q("UPDATE pr SET status = 'returned' WHERE id = ?", [$id]);
            pr_log($id, 'returned', $comment . $onBehalf);
            flash('Request returned to ' . $pr['requester_name'] . '.');
        } else {
            pr_log($id, 'approved', trim($comment . $onBehalf));
            $left = (int)q("SELECT COUNT(*) FROM pr_approvals WHERE pr_id = ? AND status = 'pending'", [$id])->fetchColumn();
            if ($left === 0) {
                q("UPDATE pr SET status = 'approved', approved_at = NOW() WHERE id = ?", [$id]);
                pr_log($id, 'fully approved');
                flash('Approved. The request now goes to Procurement.');
            } else {
                $next = q("SELECT DISTINCT u.name, a.level_name FROM pr_approvals a JOIN users u ON u.id = a.approver_id WHERE a.pr_id = ? AND a.status = 'pending' AND " . PR_CURRENT_STAGE, [$id])->fetchAll();
                flash('Your approval is recorded. Now waiting for ' . implode(', ', array_map(fn($x) => $x['name'] . ($x['level_name'] && $x['level_name'] !== 'Budget Holder' ? ' (' . $x['level_name'] . ')' : ''), $next)) . '.');
            }
        }
        db()->commit();
        redirect("pr_view.php?id=$id");
    }
    if ($act === 'cancel' && pr_can_cancel($pr, $u)) {
        q("UPDATE pr SET status = 'cancelled' WHERE id = ?", [$id]);
        q("UPDATE pr_approvals SET status = 'returned' WHERE pr_id = ? AND status = 'pending'", [$id]);
        pr_log($id, 'cancelled', $comment);
        flash('Request cancelled.');
        redirect("pr_view.php?id=$id");
    }
    if ($act === 'recall' && (int)$pr['requester_id'] === (int)$u['id'] && $pr['status'] === 'submitted') {
        q("UPDATE pr SET status = 'draft' WHERE id = ?", [$id]);
        q("DELETE FROM pr_approvals WHERE pr_id = ?", [$id]);
        pr_log($id, 'recalled', 'Taken back by the requester for changes');
        flash('Request taken back. You can edit it and submit again.');
        redirect("pr_edit.php?id=$id");
    }
    redirect("pr_view.php?id=$id");
}

/* ---------- Display ---------- */
$lines = pr_lines($id);
$levels = fin_levels();
$codes = fin_code_map();
$approvals = q('SELECT a.*, u.name AS approver_name, d.name AS decided_name, v.code AS fin_code, v.name AS fin_name
                FROM pr_approvals a JOIN users u ON u.id = a.approver_id LEFT JOIN users d ON d.id = a.decided_by
                LEFT JOIN fin_values v ON v.id = a.fin_value_id WHERE a.pr_id = ? ORDER BY a.stage, a.id', [$id])->fetchAll();
$curStage = (int)q("SELECT MIN(stage) FROM pr_approvals WHERE pr_id = ? AND status = 'pending'", [$id])->fetchColumn();
$history = q('SELECT h.*, u.name FROM pr_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.pr_id = ? ORDER BY h.id', [$id])->fetchAll();
$decidable = pr_decidable($pr, $u);
$apprLvl = (int)setting('approval_level_id', '0');
$apprPos = 0; foreach ($levels as $l) if ((int)$l['id'] === $apprLvl) $apprPos = (int)$l['position'];
$myCodes = array_map('intval', array_column(array_filter($decidable, fn($a) => (int)$a['approver_id'] === (int)$u['id']), 'fin_value_id'));

render_header($pr['number']); ?>
<div class="actions noprint" style="margin:-6px 0 12px">
  <?= pr_badge($pr['status']) ?>
  <?php if (pr_can_edit($pr, $u)): ?><a class="btn" href="pr_edit.php?id=<?= $id ?>">Edit</a><?php endif; ?>
  <?php if ((int)$pr['requester_id'] === (int)$u['id'] && $pr['status'] === 'submitted'): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('Take this request back to edit it? Approvals given so far will be cleared.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="recall"><button class="btn sec">Take back to edit</button></form>
  <?php endif; ?>
  <button class="btn sec" onclick="window.print()">Print</button>
  <a href="pr.php" style="margin-left:auto">Back to list</a>
</div>

<?php if ($decidable): ?>
<div class="card noprint" style="border-color:var(--brand)">
  <h2>Your decision</h2>
  <?php if (!array_filter($decidable, fn($a) => (int)$a['approver_id'] === (int)$u['id'])): ?>
    <p class="flash warn">You are deciding as Administrator on behalf of <?= e(implode(', ', array_unique(array_column($decidable, 'approver_name')))) ?><?= in_array((int)$pr['requester_id'], array_map('intval', array_column($decidable, 'approver_id')), true) ? ' (the requester is their own Budget Holder)' : '' ?>.</p>
  <?php else: ?>
    <?php $mineRows = array_filter($decidable, fn($a) => (int)$a['approver_id'] === (int)$u['id']); $mineCodes = array_filter(array_column($mineRows, 'fin_code')); ?>
    <?php if ($mineCodes): ?><p class="muted">You approve as Budget Holder the lines charged to: <b><?= e(implode(', ', $mineCodes)) ?></b> (highlighted below).</p>
    <?php else: ?><p class="muted">You approve the whole request as <b><?= e(implode(', ', array_unique(array_column($mineRows, 'level_name')))) ?></b>, because its total is above your approval threshold. Earlier steps are already approved.</p><?php endif; ?>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <label>Comment (required when returning)</label><textarea name="comment" rows="2"></textarea>
    <div class="actions"><button class="btn" name="act" value="approve">Approve</button><button class="btn sec" name="act" value="return">Return for changes</button></div>
  </form>
</div>
<?php endif; ?>

<?php if (in_array($pr['status'], ['approved'], true) && (has_role('procurement') || has_role('admin'))):
    $method = proc_method_for(pr_total($lines)); ?>
<div class="card noprint" style="border-color:var(--brand)">
  <h2>Procurement method</h2>
  <?php if ($method): ?>
    <p style="margin:0">Total <?= number_format(pr_total($lines), 2) ?> <?= e($pr['currency']) ?> → <b><?= e($method['name']) ?></b>:
      at least <b><?= (int)$method['min_quotes'] ?> quote<?= (int)$method['min_quotes'] > 1 ? 's' : '' ?></b><?= $method['sealed'] ? ', submitted as <b>sealed bids</b> through supplier links' : ', entered by the procurement officer' ?>.</p>
    <form method="post" action="rfq_view.php" class="actions"><?= csrf_field() ?><input type="hidden" name="act" value="create"><input type="hidden" name="pr_id" value="<?= $id ?>"><button class="btn">Create RFQ</button></form>
  <?php else: ?>
    <p class="flash warn" style="margin:0">No procurement method covers this amount. An Administrator must set up <a href="methods.php">Procurement methods</a>.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php $rfqs = q('SELECT id, number, status FROM rfq WHERE pr_id = ? ORDER BY id', [$id])->fetchAll();
if ($rfqs && (has_role('procurement') || has_role('admin'))): ?>
<div class="flash noprint" style="background:#e8f0f8;color:#1f4e79">Procurement: <?php foreach ($rfqs as $r): ?><a href="rfq_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['number']) ?></b></a> (<?= e($r['status']) ?>) <?php endforeach; ?></div>
<?php elseif ($pr['status'] === 'processing'): ?>
<div class="flash noprint" style="background:#e8f0f8;color:#1f4e79">Procurement is now sourcing this request.</div>
<?php endif; ?>

<div class="card">
  <div class="print-only" style="margin-bottom:10px"><b><?= e(setting('org_name')) ?></b> · Purchase Request <?= e($pr['number']) ?> · <?= e(PR_STATUS[$pr['status']][0]) ?></div>
  <div class="row">
    <div><span class="muted">Title</span><br><b><?= e($pr['title']) ?></b></div>
    <div><span class="muted">Requester</span><br><?= e($pr['requester_name']) ?></div>
    <div><span class="muted">Created</span><br><?= e(date('d M Y', strtotime($pr['created_at']))) ?></div>
    <div><span class="muted">Needed by</span><br><?= $pr['needed_by'] ? e(date('d M Y', strtotime($pr['needed_by']))) : '—' ?></div>
    <div><span class="muted">Delivery location</span><br><?= e($pr['delivery_location'] ?: '—') ?></div>
  </div>
  <?php if ($pr['justification']): ?><p style="margin-bottom:0"><span class="muted">Justification</span><br><?= nl2br(e($pr['justification'])) ?></p><?php endif; ?>
</div>

<div class="table-wrap">
<table>
  <tr><th>#</th><th>Item</th><th>Type</th><th style="text-align:right">Qty</th><th style="text-align:right">Unit price</th><th style="text-align:right">Total</th>
    <?php foreach ($levels as $l): ?><th><?= e($l['name']) ?></th><?php endforeach; ?></tr>
  <?php foreach ($lines as $l): $hl = $apprPos && in_array((int)$l['fin' . $apprPos . '_id'], $myCodes, true); ?>
  <tr style="<?= $hl ? 'background:#fffbe6' : '' ?>">
    <td><?= (int)$l['line_no'] ?></td>
    <td><b><?= e($l['item_code']) ?></b> <?= e($l['item_desc']) ?><?php if ($l['specification']): ?><br><span class="muted"><?= e($l['specification']) ?></span><?php endif; ?></td>
    <td><span class="tag <?= e($l['item_type']) ?>"><?= e($l['item_type']) ?></span></td>
    <td style="text-align:right;white-space:nowrap"><?= rtrim(rtrim(number_format((float)$l['qty'], 3, '.', ''), '0'), '.') ?> <?= e($l['unit']) ?></td>
    <td style="text-align:right"><?= number_format((float)$l['unit_price'], 2) ?></td>
    <td style="text-align:right"><b><?= number_format((float)$l['qty'] * (float)$l['unit_price'], 2) ?></b></td>
    <?php foreach ($levels as $lv): $vid = (int)$l['fin' . $lv['position'] . '_id']; ?><td><?= e($codes[$vid] ?? '') ?></td><?php endforeach; ?>
  </tr>
  <?php endforeach; ?>
  <?php if (!$lines): ?><tr><td colspan="<?= 6 + count($levels) ?>" class="muted">No lines.</td></tr><?php endif; ?>
  <tr><td colspan="5" style="text-align:right"><b>Estimated total</b></td><td style="text-align:right;white-space:nowrap"><b><?= number_format(pr_total($lines), 2) ?> <?= e($pr['currency']) ?></b></td><td colspan="<?= count($levels) ?>"></td></tr>
</table>
</div>

<?php if ($approvals): ?>
<div class="card" style="margin-top:16px">
  <h2>Approvals</h2>
  <table>
    <tr><th>Step</th><th>For</th><th>Approver</th><th>Status</th><th>Decided</th><th>Comment</th></tr>
    <?php foreach ($approvals as $a): ?>
    <tr>
      <td><?= (int)$a['stage'] ?></td>
      <td><?php if ($a['fin_code']): ?><b><?= e($a['fin_code']) ?></b> <span class="muted"><?= e($a['fin_name']) ?></span><?php else: ?><b><?= e($a['level_name']) ?></b> <span class="muted">amount threshold</span><?php endif; ?></td>
      <td><?= e($a['approver_name']) ?></td>
      <td><?= $a['status'] === 'pending' && $pr['status'] === 'submitted' && (int)$a['stage'] > $curStage ? '<span class="tag off">Waiting for step ' . $curStage . '</span>' : ['pending' => '<span class="tag">Pending</span>', 'approved' => '<span class="tag stock">Approved</span>', 'returned' => '<span class="tag warn">Returned</span>'][$a['status']] ?></td>
      <td><?= $a['decided_at'] ? e(date('d M Y H:i', strtotime($a['decided_at']))) . ($a['decided_by'] && (int)$a['decided_by'] !== (int)$a['approver_id'] ? '<br><span class="muted">by ' . e($a['decided_name']) . '</span>' : '') : '' ?></td>
      <td><?= e($a['comment']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <h2>History</h2>
  <table>
    <?php foreach ($history as $h): ?>
    <tr><td style="width:150px;white-space:nowrap" class="muted"><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></td><td><b><?= e(ucfirst($h['action'])) ?></b> by <?= e($h['name'] ?? 'system') ?><?= $h['comment'] ? ': ' . e($h['comment']) : '' ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if (pr_can_cancel($pr, $u)): ?>
<details class="card noprint"><summary>Cancel this request</summary>
  <form method="post" onsubmit="return confirm('Cancel this request? This cannot be undone.')">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="cancel">
    <label>Reason</label><input type="text" name="comment">
    <div class="actions"><button class="btn danger">Cancel request</button></div>
  </form>
</details>
<?php endif; ?>
<?php render_footer();
