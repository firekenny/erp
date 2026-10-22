<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stock.php';
require_any(['storekeeper', 'procurement', 'admin']);

$levels = fin_levels();
$vals = q('SELECT v.id, v.code, v.name, l.position, l.name AS level FROM fin_values v JOIN fin_levels l ON l.id = v.level_id ORDER BY l.position, v.code')->fetchAll();
$byId = array_column($vals, null, 'id');
$fv = (int)($_GET['code'] ?? 0);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-01-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');
$sel = $byId[$fv] ?? null;
$col = $sel ? 'fin' . (int)$sel['position'] . '_id' : null;
$codes = fin_code_map();

$moves = $summary = $stockNow = [];
if ($sel) {
    $moves = q("SELECT m.*, i.code AS item_code, i.description, i.unit, w.name AS wh FROM stock_moves m JOIN items i ON i.id = m.item_id JOIN warehouses w ON w.id = m.warehouse_id
                WHERE m.$col = ? AND DATE(m.created_at) BETWEEN ? AND ? ORDER BY m.created_at, m.id", [$fv, $from, $to])->fetchAll();
    foreach ($moves as $m) {
        $k = (int)$m['item_id'];
        $summary[$k] ??= ['code' => $m['item_code'], 'desc' => $m['description'], 'unit' => $m['unit'], 'received' => 0, 'issued' => 0, 'lost' => 0, 'value_in' => 0];
        if ($m['move_type'] === 'receipt') { $summary[$k]['received'] += (float)$m['qty']; $summary[$k]['value_in'] += (float)$m['qty'] * (float)$m['unit_cost']; }
        if ($m['move_type'] === 'issue') $summary[$k]['issued'] += -(float)$m['qty'];
        if ($m['move_type'] === 'count loss') $summary[$k]['lost'] += -(float)$m['qty'];
    }
    foreach (q("SELECT l.item_id, i.code, i.description, i.unit, w.name AS wh, SUM(l.qty_left) AS q FROM stock_lots l JOIN items i ON i.id = l.item_id JOIN warehouses w ON w.id = l.warehouse_id
                WHERE l.$col = ? AND l.qty_left > 0 GROUP BY l.item_id, l.warehouse_id ORDER BY i.code, w.name", [$fv])->fetchAll() as $s) $stockNow[] = $s;
}

if ($sel && isset($_GET['csv'])) {
    send_csv('traceability_' . preg_replace('/\W+/', '_', $sel['code']) . '.csv',
        ['date', 'warehouse', 'movement', 'document', 'item_code', 'item', 'quantity', 'unit', 'unit_cost', 'issued_to', 'destination', 'batch', 'expiry', 'donor_coding'],
        array_map(fn($m) => [$m['created_at'], $m['wh'], $m['move_type'], $m['ref_number'], $m['item_code'], $m['description'], (float)$m['qty'], $m['unit'], (float)$m['unit_cost'],
                             $m['issued_to'], $m['destination'], $m['batch_no'], $m['expiry_date'], fin_label($m, $codes)], $moves));
}

render_header('Donor traceability'); ?>
<form method="get" class="card row" style="align-items:flex-end">
  <div style="flex:2"><label>Finance code (donor, cost centre or budget line)</label>
    <select name="code" required><option value="">— select —</option>
      <?php $last = null; foreach ($vals as $v): if ($v['level'] !== $last): if ($last !== null) echo '</optgroup>'; echo '<optgroup label="' . e($v['level']) . '">'; $last = $v['level']; endif; ?>
        <option value="<?= (int)$v['id'] ?>" <?= $fv === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['code'] . ' – ' . $v['name']) ?></option>
      <?php endforeach; if ($last !== null) echo '</optgroup>'; ?></select></div>
  <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <div style="flex:0"><button class="btn">Show</button></div>
</form>
<?php if (!$sel): ?><p class="muted">Choose a code to see everything bought with it: received, issued to whom and where, lost, and still in stock.</p>
<?php else: ?>
<div class="actions noprint" style="margin:0 0 10px"><b><?= e($sel['level']) ?> <?= e($sel['code']) ?></b> <span class="muted"><?= e($sel['name']) ?> · <?= e(date('d M Y', strtotime($from))) ?> to <?= e(date('d M Y', strtotime($to))) ?></span>
  <a class="btn sec small" style="margin-left:auto" href="?<?= e(http_build_query(['code' => $fv, 'from' => $from, 'to' => $to, 'csv' => 1])) ?>">Export to Excel (CSV)</a><button class="btn sec small" onclick="window.print()">Print</button></div>
<div class="print-only"><h2><?= e(setting('org_name')) ?> · Traceability <?= e($sel['level'] . ' ' . $sel['code']) ?> · <?= e($from) ?> to <?= e($to) ?></h2></div>

<h2>Summary for the period</h2>
<div class="table-wrap" style="margin-bottom:16px"><table>
  <tr><th>Item</th><th style="text-align:right">Received</th><th style="text-align:right">Value received</th><th style="text-align:right">Issued</th><th style="text-align:right">Lost (counts)</th></tr>
  <?php if (!$summary): ?><tr><td colspan="5" class="muted">No stock movements on this code in the period.</td></tr><?php endif; ?>
  <?php foreach ($summary as $s): ?><tr><td><b><?= e($s['code']) ?></b> <?= e($s['desc']) ?></td><td style="text-align:right"><?= $fmtQty($s['received']) ?> <?= e($s['unit']) ?></td>
    <td style="text-align:right"><?= number_format($s['value_in'], 2) ?></td><td style="text-align:right"><?= $fmtQty($s['issued']) ?></td><td style="text-align:right"><?= $s['lost'] ? $fmtQty($s['lost']) : '0' ?></td></tr><?php endforeach; ?>
</table></div>

<h2>Issued to</h2>
<div class="table-wrap" style="margin-bottom:16px"><table>
  <tr><th>Date</th><th>Document</th><th>Item</th><th style="text-align:right">Qty</th><th>Issued to</th><th>Destination</th><th>From</th></tr>
  <?php $iss = array_filter($moves, fn($m) => $m['move_type'] === 'issue'); if (!$iss): ?><tr><td colspan="7" class="muted">Nothing issued in the period.</td></tr><?php endif; ?>
  <?php foreach ($iss as $m): ?><tr><td style="white-space:nowrap"><?= e(date('d M Y', strtotime($m['created_at']))) ?></td><td><a href="issue_view.php?id=<?= (int)$m['ref_id'] ?>"><?= e($m['ref_number']) ?></a></td>
    <td><?= e($m['item_code']) ?> <?= e($m['description']) ?></td><td style="text-align:right"><?= $fmtQty(-$m['qty']) ?> <?= e($m['unit']) ?></td><td><b><?= e($m['issued_to']) ?></b></td><td><?= e($m['destination']) ?></td><td class="muted"><?= e($m['wh']) ?></td></tr><?php endforeach; ?>
</table></div>

<h2>Still in stock (today)</h2>
<div class="table-wrap" style="margin-bottom:16px"><table>
  <tr><th>Item</th><th>Warehouse</th><th style="text-align:right">Quantity</th></tr>
  <?php if (!$stockNow): ?><tr><td colspan="3" class="muted">Nothing left in stock on this code.</td></tr><?php endif; ?>
  <?php foreach ($stockNow as $s): ?><tr><td><b><?= e($s['code']) ?></b> <?= e($s['description']) ?></td><td><?= e($s['wh']) ?></td><td style="text-align:right"><?= $fmtQty($s['q']) ?> <?= e($s['unit']) ?></td></tr><?php endforeach; ?>
</table></div>

<details class="card"><summary>All movements (<?= count($moves) ?>)</summary>
  <div class="table-wrap" style="margin-top:8px"><table>
    <tr><th>Date</th><th>Movement</th><th>Document</th><th>Item</th><th style="text-align:right">Qty</th><th>Warehouse</th><th>To</th></tr>
    <?php foreach ($moves as $m): ?><tr><td style="white-space:nowrap"><?= e(date('d M Y H:i', strtotime($m['created_at']))) ?></td><td><?= e($m['move_type']) ?></td><td><?= e($m['ref_number']) ?></td>
      <td><?= e($m['item_code']) ?></td><td style="text-align:right"><?= ((float)$m['qty'] > 0 ? '+' : '') . $fmtQty($m['qty']) ?></td><td><?= e($m['wh']) ?></td><td><?= e(trim($m['issued_to'] . ' ' . $m['destination'])) ?></td></tr><?php endforeach; ?>
  </table></div></details>
<p class="muted"><a href="assets.php?st=&fin=<?= $fv ?>">See the assets bought on this code</a> in the asset register.</p>
<?php endif;
render_footer();
