<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/budget.php';
require_once __DIR__ . '/inc/xlsx.php';
$u = require_any(['admin', 'finance', 'budget_holder', 'procurement']);
$isAdmin = has_role('admin');
$levels = fin_levels();
$lv = budget_levels();
$cur = setting('currency', 'USD');
$colName = fn(array $l) => strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $l['name']), '_')) ?: 'level_' . $l['position'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $act = $_POST['act'] ?? '';
    if ($act === 'levels') {
        $a = (int)($_POST['level_a'] ?? 0); $b = (int)($_POST['level_b'] ?? 0);
        if (!$a || $a === $b) flash('Choose two different levels (or only the first one).', 'err');
        else {
            if (q('SELECT COUNT(*) FROM budgets')->fetchColumn() && ((string)$a !== setting('budget_level_a') || (string)$b !== setting('budget_level_b', '0'))) q('DELETE FROM budgets');
            set_setting('budget_level_a', (string)$a); set_setting('budget_level_b', (string)$b);
            audit('budget.levels', "$a/$b"); flash('Budget levels saved.');
        }
    }
    if ($lv && $act === 'add') {
        $a = (int)($_POST['a'] ?? 0); $b = $lv[1] ? (int)($_POST['b'] ?? 0) : 0;
        $amt = round((float)str_replace([',', ' '], ['.', ''], (string)($_POST['amount'] ?? '')), 2);
        $okA = q('SELECT id FROM fin_values WHERE id = ? AND level_id = ?', [$a, $lv[2]['id']])->fetchColumn();
        $okB = !$lv[1] || q('SELECT id FROM fin_values WHERE id = ? AND level_id = ?', [$b, $lv[3]['id']])->fetchColumn();
        if (!$okA || !$okB || $amt < 0) flash('Choose the codes and enter an amount.', 'err');
        else {
            q('INSERT INTO budgets (a_value_id, b_value_id, amount, notes, updated_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount), notes = VALUES(notes), updated_by = VALUES(updated_by)',
              [$a, $b, $amt, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255), $u['id']]);
            audit('budget.set', "$a/$b = $amt"); flash('Budget saved.');
        }
    }
    if ($lv && $act === 'save') {
        $n = 0;
        foreach ((array)($_POST['amt'] ?? []) as $bid => $v) {
            $v = round((float)str_replace([',', ' '], ['.', ''], (string)$v), 2);
            $old = q('SELECT amount FROM budgets WHERE id = ?', [(int)$bid])->fetchColumn();
            if ($old !== false && abs((float)$old - $v) > 0.004 && $v >= 0) { q('UPDATE budgets SET amount = ?, updated_by = ? WHERE id = ?', [$v, $u['id'], (int)$bid]); audit('budget.change', "#$bid $old → $v"); $n++; }
        }
        foreach ((array)($_POST['del'] ?? []) as $bid) { q('DELETE FROM budgets WHERE id = ?', [(int)$bid]); audit('budget.delete', "#$bid"); $n++; }
        flash($n ? "$n change(s) saved." : 'Nothing changed.');
    }
    if ($lv && $act === 'import') {
        $rows = read_csv_upload('file'); $ok = 0; $bad = [];
        $ca = $colName($lv[2]); $cb = $lv[3] ? $colName($lv[3]) : '';
        foreach ($rows as $i => $r) {
            $a = q('SELECT id FROM fin_values WHERE level_id = ? AND code = ?', [$lv[2]['id'], trim((string)($r[$ca] ?? ''))])->fetchColumn();
            $b = $cb ? q('SELECT id FROM fin_values WHERE level_id = ? AND code = ?', [$lv[3]['id'], trim((string)($r[$cb] ?? ''))])->fetchColumn() : 0;
            $raw = str_replace([' ', "\u{00A0}"], '', (string)($r['amount'] ?? ''));
            if (substr_count($raw, ',') && !substr_count($raw, '.')) $raw = str_replace(',', '.', $raw); else $raw = str_replace(',', '', $raw);
            if (!$a || $b === false || !is_numeric($raw) || (float)$raw < 0) { $bad[] = 'row ' . ($i + 2); continue; }
            q('INSERT INTO budgets (a_value_id, b_value_id, amount, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount), updated_by = VALUES(updated_by)', [$a, (int)$b, round((float)$raw, 2), $u['id']]);
            $ok++;
        }
        audit('budget.import', "$ok rows");
        flash($rows ? "$ok budget(s) imported." : 'No rows found in the file.', $rows ? 'ok' : 'err');
        if ($bad) flash('Skipped (unknown code or amount): ' . implode(', ', array_slice($bad, 0, 20)), 'warn');
    }
    redirect('budgets.php');
}

if ($lv && isset($_GET['template'])) {
    $hdr = array_filter([$colName($lv[2]), $lv[3] ? $colName($lv[3]) : null, 'amount']);
    $rows = array_map(fn($r) => array_filter([$r['a_code'], $lv[3] ? $r['b_code'] : null, number_format((float)$r['amount'], 2, '.', '')], fn($x) => $x !== null), budget_rows());
    send_csv('budgets.csv', array_values($hdr), array_map('array_values', $rows));
}

$s = trim((string)($_GET['s'] ?? ''));
$rows = $lv ? budget_rows() : [];
if ($s !== '') $rows = array_values(array_filter($rows, fn($r) => stripos($r['a_code'] . ' ' . $r['a_name'] . ' ' . $r['b_code'] . ' ' . $r['b_name'] . ' ' . $r['donor_code'], $s) !== false));
if ($lv && isset($_GET['xlsx'])) xlsx_send('budget_report_' . date('Y-m-d') . '.xlsx', [['name' => 'Budget report',
    'header' => array_values(array_filter(['Parent code', $lv[2]['name'], $lv[2]['name'] . ' name', $lv[3] ? $lv[3]['name'] : null, $lv[3] ? $lv[3]['name'] . ' name' : null, 'Budget', 'Committed (POs not invoiced)', 'Actual (invoiced)', 'Left', '% used'])),
    'rows' => array_map(fn($r) => array_values(array_filter([(string)$r['donor_code'], $r['a_code'], $r['a_name'], $lv[3] ? (string)$r['b_code'] : null, $lv[3] ? (string)$r['b_name'] : null,
        (float)$r['amount'], round($r['committed'], 2), round($r['actual'], 2), round($r['left'], 2), round($r['pct'], 1)], fn($x) => $x !== null)), $rows)]]);
[, $foreign] = $lv ? budget_usage() : [[], 0];

render_header('Budgets');
if ($isAdmin): ?>
<details class="card" <?= $lv ? '' : 'open' ?>><summary><b>Budget set-up</b> <?= $lv ? '<span class="muted">· budgets per ' . e($lv[2]['name']) . ($lv[3] ? ' × ' . e($lv[3]['name']) : '') . '</span>' : '' ?></summary>
  <form method="post" class="row" style="align-items:flex-end;margin-top:10px"><?= csrf_field() ?><input type="hidden" name="act" value="levels">
    <div><label>Budget for each…</label><select name="level_a" required><option value="">— finance level —</option><?php foreach ($levels as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $lv && (int)$lv[2]['id'] === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>…split by (optional)</label><select name="level_b"><option value="0">— no split —</option><?php foreach ($levels as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $lv && $lv[3] && (int)$lv[3]['id'] === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <div style="flex:0"><button class="btn sec" <?= $lv ? 'onclick="return confirm(\'Changing the levels deletes the budgets already entered. Continue?\')"' : '' ?>>Save</button></div>
  </form>
  <p class="muted">Usually: <b>Cost centre</b> split by <b>Budget line</b>. Used = Purchase Orders not yet invoiced + supplier invoices (tax included). Purchase Requests are checked against what is left; approvers must justify approving an over-budget request.</p>
</details>
<?php endif;
if (!$lv): ?><div class="flash warn">Budgets are not set up yet<?= $isAdmin ? ': choose the levels above.' : '. Ask your Administrator.' ?></div><?php render_footer(); exit; endif;
$aVals = q('SELECT v.id, v.code, v.name FROM fin_values v WHERE v.level_id = ? AND v.active = 1 ORDER BY v.code', [$lv[2]['id']])->fetchAll();
$bVals = $lv[3] ? q('SELECT v.id, v.code, v.name FROM fin_values v WHERE v.level_id = ? AND v.active = 1 ORDER BY v.code', [$lv[3]['id']])->fetchAll() : [];
$tot = ['amount' => 0, 'committed' => 0, 'actual' => 0, 'left' => 0];
foreach ($rows as $r) foreach ($tot as $k => $_) $tot[$k] += (float)$r[$k]; ?>
<div class="tiles" style="margin-bottom:14px">
  <div class="tile"><span class="muted">Budget</span><b><?= number_format($tot['amount'], 0) ?></b></div>
  <div class="tile"><span class="muted">Committed (POs not invoiced)</span><b><?= number_format($tot['committed'], 0) ?></b></div>
  <div class="tile"><span class="muted">Actual (invoiced)</span><b><?= number_format($tot['actual'], 0) ?></b></div>
  <div class="tile"><span class="muted">Left</span><b style="<?= $tot['left'] < 0 ? 'color:#b3261e' : '' ?>"><?= number_format($tot['left'], 0) ?></b><span class="muted"><?= e($cur) ?></span></div>
</div>
<?php if ($foreign): ?><div class="flash warn"><?= (int)$foreign ?> Purchase Order line(s) are in another currency than <?= e($cur) ?>: they are counted at face value, without conversion.</div><?php endif; ?>
<form method="get" class="row" style="margin-bottom:10px;align-items:flex-end">
  <div style="flex:2"><input type="text" name="s" value="<?= e($s) ?>" placeholder="Search a code or name"></div>
  <div style="flex:0;white-space:nowrap"><button class="btn sec">Filter</button> <a class="btn sec" href="?<?= e(http_build_query(['s' => $s, 'xlsx' => 1])) ?>">Excel</a></div>
</form>
<form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save">
<div class="table-wrap"><table>
  <tr><th><?= e($lv[2]['name']) ?></th><?php if ($lv[3]): ?><th><?= e($lv[3]['name']) ?></th><?php endif; ?><th style="text-align:right">Budget</th><th style="text-align:right">Committed</th><th style="text-align:right">Actual</th><th style="text-align:right">Left</th><th style="min-width:120px">Used</th><?php if ($isAdmin): ?><th>Delete</th><?php endif; ?></tr>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">No budgets entered yet.</td></tr><?php endif;
  $prevA = null;
  foreach ($rows as $r): $pct = min(100, $r['pct']); $col = $r['pct'] > 100 ? '#b3261e' : ($r['pct'] > 85 ? '#c98a00' : '#2e7d4f'); ?>
  <tr><td><?php if ($prevA !== $r['a_code']): ?><b><?= e($r['a_code']) ?></b> <span class="muted"><?= e($r['a_name']) ?><?= $r['donor_code'] ? ' · ' . e($r['donor_code']) : '' ?></span><?php endif; $prevA = $r['a_code']; ?></td>
    <?php if ($lv[3]): ?><td><b><?= e((string)$r['b_code']) ?></b> <span class="muted"><?= e((string)$r['b_name']) ?></span></td><?php endif; ?>
    <td style="text-align:right"><?php if ($isAdmin): ?><input type="number" step="0.01" min="0" name="amt[<?= (int)$r['id'] ?>]" value="<?= e(number_format((float)$r['amount'], 2, '.', '')) ?>" style="width:140px;max-width:none;text-align:right"><?php else: ?><?= number_format((float)$r['amount'], 2) ?><?php endif; ?></td>
    <td style="text-align:right"><?= number_format($r['committed'], 2) ?></td><td style="text-align:right"><?= number_format($r['actual'], 2) ?></td>
    <td style="text-align:right;<?= $r['left'] < 0 ? 'color:#b3261e;font-weight:bold' : '' ?>"><?= number_format($r['left'], 2) ?></td>
    <td><div style="background:#eef1f4;border-radius:4px;height:8px"><div style="width:<?= round($pct) ?>%;background:<?= $col ?>;height:8px;border-radius:4px"></div></div><span class="muted"><?= number_format($r['pct'], 0) ?>%</span></td>
    <?php if ($isAdmin): ?><td><input type="checkbox" name="del[]" value="<?= (int)$r['id'] ?>"></td><?php endif; ?></tr>
  <?php endforeach; ?>
</table></div>
<?php if ($isAdmin && $rows): ?><div class="actions"><button class="btn">Save changes</button></div><?php endif; ?>
</form>
<?php if ($isAdmin): ?>
<div class="row" style="margin-top:14px;align-items:stretch">
  <form method="post" class="card" style="flex:2"><?= csrf_field() ?><input type="hidden" name="act" value="add">
    <h2 style="margin-top:0">Add or update one budget</h2>
    <div class="row" style="align-items:flex-end">
      <div><label><?= e($lv[2]['name']) ?></label><select name="a" required><option value="">—</option><?php foreach ($aVals as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['code'] . ' – ' . $v['name']) ?></option><?php endforeach; ?></select></div>
      <?php if ($lv[3]): ?><div><label><?= e($lv[3]['name']) ?></label><select name="b" required><option value="">—</option><?php foreach ($bVals as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['code'] . ' – ' . $v['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div><label>Amount (<?= e($cur) ?>)</label><input type="number" step="0.01" min="0" name="amount" required></div>
      <div style="flex:0"><button class="btn">Save</button></div>
    </div>
  </form>
  <form method="post" enctype="multipart/form-data" class="card" style="flex:1"><?= csrf_field() ?><input type="hidden" name="act" value="import">
    <h2 style="margin-top:0">Import from a file</h2>
    <p class="muted" style="margin-top:0">CSV with columns <b><?= e($colName($lv[2])) ?></b><?= $lv[3] ? ', <b>' . e($colName($lv[3])) . '</b>' : '' ?>, <b>amount</b> (codes as on the Finance codes page). <a href="?template=1">Download the current list</a> to edit it in Excel.</p>
    <input type="file" name="file" accept=".csv" required><div class="actions"><button class="btn sec">Import</button></div>
  </form>
</div>
<?php endif;
render_footer();
