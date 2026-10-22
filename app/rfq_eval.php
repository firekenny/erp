<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/award.php';
$u = require_login();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$rfq = rfq_get($id);
$isProc = has_role('procurement') || has_role('admin');
$isApprover = $rfq && (bool)q('SELECT 1 FROM award_approvals WHERE rfq_id = ? AND approver_id = ?', [$id, $u['id']])->fetch();
if (!$rfq || !in_array($rfq['status'], ['closed', 'awarded'], true) || !($isProc || $isApprover)) { flash('Evaluation not available.', 'err'); redirect($isProc ? 'rfq.php' : 'index.php'); }

$editable = $isProc && $rfq['status'] === 'closed' && in_array($rfq['award_status'], ['none', 'returned'], true);
$back = fn() => redirect("rfq_eval.php?id=$id");
$tol = (float)setting('award_tolerance_pct', '10');

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'compliance' && $editable) {
        foreach ((array)($_POST['comp'] ?? []) as $qid => $v) {
            $note = mb_substr(trim((string)($_POST['note'][$qid] ?? '')), 0, 255);
            if ($v === '0' && $note === '') { flash('Give the reason for each offer marked not compliant.', 'err'); $back(); }
            q('UPDATE quotes SET compliant = ?, compliance_note = ? WHERE id = ? AND rfq_id = ?', [$v === '' ? null : (int)$v, $note, (int)$qid, $id]);
        }
        q('DELETE a FROM rfq_awards a JOIN quotes qq ON qq.rfq_id = a.rfq_id AND qq.supplier_id = a.supplier_id WHERE a.rfq_id = ? AND COALESCE(qq.compliant, 0) <> 1', [$id]);
        rfq_log($id, 'compliance checked');
        flash('Compliance saved.'); $back();
    }
    if ($act === 'scores' && $editable) {
        foreach ((array)($_POST['score'] ?? []) as $cid => $bySup) {
            if (!q('SELECT 1 FROM rfq_criteria WHERE id = ? AND rfq_id = ? AND is_price = 0', [(int)$cid, $id])->fetch()) continue;
            foreach ((array)$bySup as $sid => $v) {
                $v = trim(str_replace(',', '.', (string)$v));
                if ($v === '') { q('DELETE FROM rfq_scores WHERE criterion_id = ? AND supplier_id = ?', [(int)$cid, (int)$sid]); continue; }
                $v = max(0, min(10, round((float)$v, 1)));
                q('INSERT INTO rfq_scores (rfq_id, criterion_id, supplier_id, score) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE score = VALUES(score)', [$id, (int)$cid, (int)$sid, $v]);
            }
        }
        rfq_log($id, 'scores entered');
        flash('Committee scores saved.'); $back();
    }
    if ($act === 'award' && $editable) {
        $ev = rfq_evaluation($rfq);
        if ($ev['missing']) { flash('Enter all committee scores for the compliant suppliers first.', 'err'); $back(); }
        $choice = (array)($_POST['award'] ?? []);
        $deviates = false; $n = 0;
        db()->beginTransaction();
        q('DELETE FROM rfq_awards WHERE rfq_id = ?', [$id]);
        foreach ($ev['lines'] as $lid => $L) {
            $sid = (int)($choice[$lid] ?? 0);
            if (!$sid || !isset($L['rows'][$sid])) continue;
            $rec = (int)($sid === $L['best']);
            if (!$rec) $deviates = true;
            q('INSERT INTO rfq_awards (rfq_id, rfq_line_id, supplier_id, unit_price, recommended) VALUES (?, ?, ?, ?, ?)', [$id, $lid, $sid, $L['rows'][$sid]['price'], $rec]);
            $n++;
        }
        db()->commit();
        $just = trim((string)($_POST['justification'] ?? ''));
        q('UPDATE rfq SET award_justification = ? WHERE id = ?', [$just, $id]);
        if (!$n) { flash('Award at least one line.', 'err'); $back(); }
        $awardLines = rfq_award_lines($id);
        $total = award_total($awardLines);
        $nQuotes = (int)q('SELECT COUNT(*) FROM quotes WHERE rfq_id = ?', [$id])->fetchColumn();
        $over = award_overrun_pct($id, $total);
        $needWhy = [];
        if ($deviates) $needWhy[] = 'a line is not awarded to the highest score';
        if ($nQuotes < (int)$rfq['min_quotes']) $needWhy[] = "fewer quotes ($nQuotes) than the minimum ({$rfq['min_quotes']})";
        if ($over > $tol) $needWhy[] = 'the award is ' . number_format($over, 1) . "% above the approved estimate (tolerance $tol%)";
        if (count($awardLines) < count($ev['lines'])) $needWhy[] = 'some lines are not awarded';
        if ($needWhy && mb_strlen($just) < 15) { flash('Award saved, not submitted: write a justification because ' . implode('; ', $needWhy) . '.', 'err'); $back(); }
        if (($_POST['go'] ?? '') !== 'submit') { flash('Award saved as draft.'); $back(); }
        [$route, $errs] = award_route($id);
        if ($errs) { flash('Award saved, not submitted: ' . implode(' ', $errs), 'err'); $back(); }
        db()->beginTransaction();
        q('DELETE FROM award_approvals WHERE rfq_id = ?', [$id]);
        foreach ($route as $r) q('INSERT INTO award_approvals (rfq_id, approver_id, fin_value_id, stage, level_name) VALUES (?, ?, ?, ?, ?)', [$id, $r['approver_id'], $r['fin_value_id'], $r['stage'], $r['level_name']]);
        q("UPDATE rfq SET award_status = 'submitted', award_total = ?, award_submitted_by = ?, award_submitted_at = NOW() WHERE id = ?", [$total, $u['id'], $id]);
        rfq_log($id, 'award submitted for approval', number_format($total, 2) . ' ' . $rfq['currency'] . ($over > $tol ? ' · ' . number_format($over, 1) . '% over estimate' : ''));
        db()->commit();
        $names = q('SELECT DISTINCT u.name FROM award_approvals a JOIN users u ON u.id = a.approver_id WHERE a.rfq_id = ? ORDER BY a.stage', [$id])->fetchAll(PDO::FETCH_COLUMN);
        flash('Award submitted for approval: ' . implode(' → ', $names) . '.'); $back();
    }
    if (in_array($act, ['approve', 'return'], true)) {
        $rows = award_decidable($rfq, $u);
        if (!$rows) { flash('There is nothing for you to approve here.', 'err'); $back(); }
        $comment = trim((string)($_POST['comment'] ?? ''));
        if ($act === 'return' && $comment === '') { flash('Say what needs to change when returning the award.', 'err'); $back(); }
        $mine = array_filter($rows, fn($a) => (int)$a['approver_id'] === (int)$u['id']);
        $targets = $mine ?: $rows;
        db()->beginTransaction();
        foreach ($targets as $a) q('UPDATE award_approvals SET status = ?, comment = ?, decided_by = ?, decided_at = NOW() WHERE id = ?', [$act === 'approve' ? 'approved' : 'returned', $comment, $u['id'], $a['id']]);
        $note = !$mine ? ' (as Administrator, on behalf of ' . implode(', ', array_unique(array_column($targets, 'approver_name'))) . ')' : '';
        if ($act === 'return') {
            q("UPDATE rfq SET award_status = 'returned' WHERE id = ?", [$id]);
            rfq_log($id, 'award returned', $comment . $note);
            db()->commit();
            flash('Award returned to Procurement.'); $back();
        }
        rfq_log($id, 'award approved', trim($comment . $note));
        $left = (int)q("SELECT COUNT(*) FROM award_approvals WHERE rfq_id = ? AND status = 'pending'", [$id])->fetchColumn();
        if ($left) { db()->commit(); flash('Your approval is recorded. Waiting for the next approver.'); $back(); }
        // Final approval: issue the Purchase Orders
        q("UPDATE rfq SET award_status = 'approved', award_approved_at = NOW(), status = 'awarded' WHERE id = ?", [$id]);
        $poIds = generate_pos(rfq_get($id));
        q("UPDATE pr SET status = 'ordered' WHERE id = ?", [$rfq['pr_id']]);
        $nums = q('SELECT number FROM po WHERE id IN (' . implode(',', array_map('intval', $poIds)) . ')')->fetchAll(PDO::FETCH_COLUMN);
        pr_log((int)$rfq['pr_id'], 'ordered', implode(', ', $nums));
        rfq_log($id, 'Purchase Orders issued', implode(', ', $nums));
        db()->commit();
        $sent = 0;
        foreach ($poIds as $pid) { $st = po_send(po_get($pid)); if (str_starts_with($st, 'Email sent')) $sent++; po_log($pid, 'sent to supplier', $st); }
        flash('Award approved. ' . count($poIds) . ' Purchase Order(s) issued: ' . implode(', ', $nums) . '. ' . ($sent === count($poIds) ? 'Emailed to the suppliers.' : 'Send the supplier links from each PO page.'));
        $back();
    }
    $back();
}

/* ---------- Data ---------- */
$quotes = rfq_quotes($id);
$ev = rfq_evaluation($rfq);
$crit = $ev['criteria'];
$nonPrice = array_values(array_filter($crit, fn($c) => !$c['is_price']));
$compliant = array_filter($quotes, fn($q) => (int)$q['quote']['compliant'] === 1);
$awards = array_column(q('SELECT rfq_line_id, supplier_id FROM rfq_awards WHERE rfq_id = ?', [$id])->fetchAll(), 'supplier_id', 'rfq_line_id');
$awardLines = rfq_award_lines($id);
$awardTotal = award_total($awardLines);
$estimate = rfq_estimate($id);
$over = award_overrun_pct($id, $awardTotal);
$approvals = q('SELECT a.*, u.name AS approver_name, d.name AS decided_name, v.code AS fin_code FROM award_approvals a JOIN users u ON u.id = a.approver_id
                LEFT JOIN users d ON d.id = a.decided_by LEFT JOIN fin_values v ON v.id = a.fin_value_id WHERE a.rfq_id = ? ORDER BY a.stage, a.id', [$id])->fetchAll();
$decidable = award_decidable($rfq, $u);
$pos = q('SELECT p.id, p.number, p.status, s.name FROM po p JOIN suppliers s ON s.id = p.supplier_id WHERE p.rfq_id = ?', [$id])->fetchAll();
$fmt = fn($n) => number_format((float)$n, 2);
$fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');
$cur = e($rfq['currency']);
$step = !$compliant ? 1 : ($ev['missing'] ? 2 : 3);

render_header('Evaluation · ' . $rfq['number']); ?>
<div class="actions noprint" style="margin:-6px 0 12px">
  <?= rfq_badge($rfq) ?>
  <?php if ($rfq['award_status'] !== 'none'): ?><span class="tag <?= ['submitted' => '', 'returned' => 'warn', 'approved' => 'stock'][$rfq['award_status']] ?>">Award <?= e($rfq['award_status']) ?></span><?php endif; ?>
  <span class="muted"><?= e($rfq['title']) ?> · <?= e($rfq['method_name']) ?> · <a href="rfq_view.php?id=<?= $id ?>"><?= e($rfq['number']) ?></a> · <a href="pr_view.php?id=<?= (int)$rfq['pr_id'] ?>"><?= e($rfq['pr_number']) ?></a></span>
  <button class="btn sec small" style="margin-left:auto" onclick="window.print()">Print bid analysis</button>
</div>

<?php if ($pos): ?>
<div class="flash ok noprint">Purchase Orders: <?php foreach ($pos as $p): ?><a href="po_view.php?id=<?= (int)$p['id'] ?>"><b><?= e($p['number']) ?></b></a> (<?= e($p['name']) ?>) <?php endforeach; ?></div>
<?php endif; ?>

<?php if ($decidable): ?>
<div class="card noprint" style="border-color:var(--brand)">
  <h2>Your decision on this award</h2>
  <?php if ($over > $tol): ?><p class="flash err" style="margin-top:0">⚠ The award is <b><?= number_format($over, 1) ?>% above</b> the approved estimate (<?= $fmt($estimate) ?> → <?= $fmt($awardTotal) ?> <?= $cur ?>). Tolerance is <?= $tol ?>%. Confirm the budget can cover it.</p><?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <label>Comment (required when returning)</label><textarea name="comment" rows="2"></textarea>
    <div class="actions"><button class="btn" name="act" value="approve">Approve award</button><button class="btn sec" name="act" value="return">Return to Procurement</button></div></form>
</div>
<?php endif; ?>

<div class="print-only"><h2><?= e(setting('org_name')) ?> · Bid analysis <?= e($rfq['number']) ?> · <?= e($rfq['title']) ?></h2>
  <p><?= e($rfq['method_name']) ?> · deadline <?= $rfq['deadline'] ? e(date('d M Y H:i', strtotime($rfq['deadline']))) : '—' ?> · <?= count($quotes) ?> quote(s) received<?= $rfq['opened_at'] ? ' · opened ' . e(date('d M Y H:i', strtotime($rfq['opened_at']))) . ' by ' . e($rfq['opened_name']) : '' ?></p></div>

<?php /* ---------- 1. Compliance ---------- */ ?>
<div class="card">
  <h2>1. Compliance <span class="muted" style="font-weight:400">· is each offer eligible (documents, specifications, validity)?</span></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="compliance">
  <div class="table-wrap"><table>
    <tr><th>Supplier</th><th style="text-align:right">Total offered</th><th>Delivery · validity</th><th>Compliant?</th><th>Reason / note</th></tr>
    <?php foreach ($quotes as $sid => $q): $c = $q['quote']['compliant']; ?>
    <tr>
      <td><b><?= e($q['quote']['name']) ?></b><?= $q['complete'] ? '' : ' <span class="tag warn">partial</span>' ?><?php if ($q['quote']['notes']): ?><br><span class="muted"><?= e($q['quote']['notes']) ?></span><?php endif; ?></td>
      <td style="text-align:right"><?= $fmt($q['total']) ?> <?= $cur ?></td>
      <td class="muted"><?= $q['quote']['delivery_days'] !== null ? (int)$q['quote']['delivery_days'] . ' d' : '—' ?> · <?= $q['quote']['validity_days'] !== null ? (int)$q['quote']['validity_days'] . ' d' : '—' ?></td>
      <td><?php if ($editable): ?>
        <select name="comp[<?= (int)$q['quote']['id'] ?>]"><option value="" <?= $c === null ? 'selected' : '' ?>>— check —</option><option value="1" <?= $c !== null && (int)$c === 1 ? 'selected' : '' ?>>Yes</option><option value="0" <?= $c !== null && (int)$c === 0 ? 'selected' : '' ?>>No</option></select>
        <?php else: ?><?= $c === null ? '—' : ((int)$c ? '<span class="tag stock">Yes</span>' : '<span class="tag warn">No</span>') ?><?php endif; ?></td>
      <td><?php if ($editable): ?><input type="text" name="note[<?= (int)$q['quote']['id'] ?>]" value="<?= e($q['quote']['compliance_note']) ?>" maxlength="255" placeholder="Required if not compliant"><?php else: ?><?= e($q['quote']['compliance_note']) ?><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php if ($editable): ?><div class="actions"><button class="btn <?= $step === 1 ? '' : 'sec' ?>">Save compliance</button></div><?php endif; ?>
  </form>
</div>

<?php /* ---------- 2. Committee scores ---------- */ ?>
<div class="card">
  <h2>2. Committee scores <span class="muted" style="font-weight:400">· one agreed score from 0 to 10 per criterion</span></h2>
  <p class="muted">Criteria: <?php foreach ($crit as $c): ?><b><?= e($c['name']) ?></b> <?= rtrim(rtrim((string)$c['weight'], '0'), '.') ?>%<?= $c['is_price'] ? ' (calculated: lowest price ÷ offered price × weight, line by line)' : '' ?> · <?php endforeach; ?></p>
  <?php if (!$compliant): ?><p class="muted">Mark at least one offer compliant first.</p>
  <?php elseif (!$nonPrice): ?><p class="muted">Price is the only criterion.</p>
  <?php else: ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="scores">
  <div class="table-wrap"><table>
    <tr><th>Criterion (weight)</th><?php foreach ($compliant as $sid => $q): ?><th style="text-align:center"><?= e($q['quote']['name']) ?></th><?php endforeach; ?></tr>
    <?php foreach ($nonPrice as $c): ?>
    <tr><td><b><?= e($c['name']) ?></b> <span class="muted"><?= rtrim(rtrim((string)$c['weight'], '0'), '.') ?>%</span></td>
      <?php foreach ($compliant as $sid => $q): $v = $ev['scores'][$sid][(int)$c['id']] ?? null; ?>
      <td style="text-align:center"><?php if ($editable): ?><input type="number" name="score[<?= (int)$c['id'] ?>][<?= (int)$sid ?>]" min="0" max="10" step="0.5" value="<?= $v === null ? '' : e(rtrim(rtrim((string)$v, '0'), '.')) ?>" style="max-width:90px;text-align:center"><?php else: ?><?= $v === null ? '—' : e(rtrim(rtrim((string)$v, '0'), '.')) ?>/10<?php endif; ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?>
    <tr><td class="muted">Non-price points</td><?php foreach ($compliant as $sid => $q): ?><td style="text-align:center" class="muted"><?= isset($ev['missing'][$sid]) ? 'incomplete' : number_format($ev['other'][$sid], 1) ?></td><?php endforeach; ?></tr>
  </table></div>
  <?php if ($editable): ?><div class="actions"><button class="btn <?= $step === 2 ? '' : 'sec' ?>">Save scores</button></div><?php endif; ?>
  </form>
  <?php endif; ?>
</div>

<?php /* ---------- 3. Results and award ---------- */ ?>
<?php if ($compliant && !$ev['missing']): ?>
<div class="card">
  <h2>3. Results and award</h2>
  <p class="muted">Score per line = price points + non-price points (out of 100). ★ = highest score, recommended. You can award each line to a different supplier.</p>
  <form method="post" id="awf"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="award">
  <div class="table-wrap"><table>
    <tr><th>#</th><th>Item</th><th style="text-align:right">Qty</th>
      <?php foreach ($compliant as $sid => $q): ?><th style="text-align:center;min-width:130px"><?= e($q['quote']['name']) ?></th><?php endforeach; ?>
      <?php if ($editable): ?><th style="text-align:center">Not awarded</th><?php endif; ?></tr>
    <?php foreach ($ev['lines'] as $lid => $L): $l = $L['line']; $chosen = $awards[$lid] ?? ($rfq['award_status'] === 'none' ? $L['best'] : null); ?>
    <tr>
      <td><?= (int)$l['line_no'] ?></td>
      <td><?= e($l['description']) ?></td>
      <td style="text-align:right;white-space:nowrap"><?= $fmtQty($l['qty']) ?> <?= e($l['unit']) ?></td>
      <?php foreach ($compliant as $sid => $q): $r = $L['rows'][$sid] ?? null; $isAw = $chosen !== null && (int)$chosen === (int)$sid; ?>
      <td style="text-align:center;<?= $isAw ? 'background:#e6f4ea' : '' ?>">
        <?php if (!$r): ?><span class="muted">not quoted</span>
        <?php else: ?>
          <?php if ($editable): ?><label style="font-weight:400;margin:0;display:block;cursor:pointer"><input type="radio" name="award[<?= $lid ?>]" value="<?= (int)$sid ?>" data-total="<?= $r['price'] * (float)$l['qty'] ?>" <?= $isAw ? 'checked' : '' ?>><?php endif; ?>
          <b><?= number_format($r['total'], 1) ?></b> pts<?= (int)$L['best'] === (int)$sid ? ' ★' : '' ?><br>
          <span class="muted"><?= $fmt($r['price']) ?> · price <?= number_format($r['price_pts'], 1) ?> + <?= number_format($r['other_pts'], 1) ?></span>
          <?php if ($editable): ?></label><?php endif; ?>
        <?php endif; ?>
      </td>
      <?php endforeach; ?>
      <?php if ($editable): ?><td style="text-align:center"><input type="radio" name="award[<?= $lid ?>]" value="0" data-total="0" <?= $chosen === null ? 'checked' : '' ?>></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <div class="row" style="margin-top:12px">
    <div><span class="muted">Approved estimate</span><br><b><?= $fmt($estimate) ?> <?= $cur ?></b></div>
    <div><span class="muted">Award total</span><br><b id="awt"><?= $fmt($awardTotal) ?></b> <?= $cur ?> <span id="awo"></span></div>
    <div><span class="muted">Quotes received / minimum</span><br><b><?= count($quotes) ?> / <?= (int)$rfq['min_quotes'] ?></b><?= count($quotes) < (int)$rfq['min_quotes'] ? ' <span class="tag warn">below minimum</span>' : '' ?></div>
  </div>
  <label>Justification <span class="muted">(required if a line is not awarded to ★, if quotes are below the minimum, or if the award exceeds the estimate by more than <?= $tol ?>%)</span></label>
  <?php if ($editable): ?><textarea name="justification" rows="3"><?= e($rfq['award_justification']) ?></textarea>
    <div class="actions"><button class="btn sec" name="go" value="save">Save award</button><button class="btn" name="go" value="submit" onclick="return confirm('Submit this award for approval?')">Submit award for approval</button></div>
  <?php else: ?><p><?= $rfq['award_justification'] ? nl2br(e($rfq['award_justification'])) : '<span class="muted">—</span>' ?></p><?php endif; ?>
  </form>
</div>
<?php if ($editable): ?>
<script>
(function () {
  const est = <?= json_encode($estimate) ?>, tol = <?= json_encode($tol) ?>;
  function upd() {
    let t = 0; document.querySelectorAll('#awf input[type=radio]:checked').forEach(r => t += parseFloat(r.dataset.total) || 0);
    const el = document.getElementById('awt'); if (!el) return; el.textContent = t.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const o = est > 0 ? (t - est) / est * 100 : 0;
    document.getElementById('awo').innerHTML = o > tol ? '<span class="tag warn">' + o.toFixed(1) + '% over estimate</span>' : (o > 0 ? '<span class="muted">' + o.toFixed(1) + '% over, within tolerance</span>' : '');
  }
  document.querySelectorAll('#awf input[type=radio]').forEach(r => r.addEventListener('change', upd)); upd();
})();
</script>
<?php endif; ?>
<?php endif; ?>

<?php if ($approvals): ?>
<div class="card">
  <h2>Award approval</h2>
  <?php if ($over > $tol && $rfq['award_status'] !== 'none'): ?><p class="flash err" style="margin-top:0">Award is <?= number_format($over, 1) ?>% above the approved estimate (tolerance <?= $tol ?>%).</p><?php endif; ?>
  <table>
    <tr><th>Step</th><th>For</th><th>Approver</th><th>Status</th><th>Decided</th><th>Comment</th></tr>
    <?php foreach ($approvals as $a): ?>
    <tr><td><?= (int)$a['stage'] ?></td><td><?= $a['fin_code'] ? '<b>' . e($a['fin_code']) . '</b> Budget Holder' : '<b>' . e($a['level_name']) . '</b>' ?></td><td><?= e($a['approver_name']) ?></td>
      <td><?= ['pending' => '<span class="tag">Pending</span>', 'approved' => '<span class="tag stock">Approved</span>', 'returned' => '<span class="tag warn">Returned</span>'][$a['status']] ?></td>
      <td><?= $a['decided_at'] ? e(date('d M Y H:i', strtotime($a['decided_at']))) . ((int)$a['decided_by'] !== (int)$a['approver_id'] ? '<br><span class="muted">by ' . e($a['decided_name']) . '</span>' : '') : '' ?></td><td><?= e($a['comment']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<p class="muted print-only">Prepared by: ______________________ &nbsp; Committee member: ______________________ &nbsp; Committee member: ______________________</p>
<?php render_footer();
