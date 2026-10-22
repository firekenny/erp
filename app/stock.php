<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stock.php';
require_any(['storekeeper', 'procurement', 'admin']);

$whs = warehouses(false);
$fw = (int)($_GET['w'] ?? 0); $s = trim((string)($_GET['s'] ?? ''));
$item = (int)($_GET['item'] ?? 0);
$cur = setting('currency', 'USD');
$fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');

/* ---------- Stock card (ledger) for one item ---------- */
if ($item) {
    $it = q('SELECT i.*, c.code AS cat FROM items i JOIN categories c ON c.id = i.category_id WHERE i.id = ?', [$item])->fetch();
    if (!$it) redirect('stock.php');
    $p = [$item]; $wsql = '';
    if ($fw) { $wsql = ' AND m.warehouse_id = ?'; $p[] = $fw; }
    $moves = q('SELECT m.*, w.name AS wh, u.name AS uname FROM stock_moves m JOIN warehouses w ON w.id = m.warehouse_id LEFT JOIN users u ON u.id = m.user_id
                WHERE m.item_id = ?' . $wsql . ' ORDER BY m.id', $p)->fetchAll();
    $codes = fin_code_map();
    render_header('Stock card · ' . $it['code']); ?>
    <p class="muted"><?= e($it['description']) ?> · unit <?= e($it['unit']) ?> · <a href="stock.php">Back to stock</a></p>
    <form method="get" class="row" style="margin-bottom:10px"><input type="hidden" name="item" value="<?= $item ?>">
      <div style="max-width:300px"><select name="w" onchange="this.form.submit()"><option value="0">All warehouses</option><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>" <?= $fw === (int)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div></form>
    <div class="table-wrap"><table>
      <tr><th>Date</th><th>Warehouse</th><th>Movement</th><th>Reference</th><th style="text-align:right">In</th><th style="text-align:right">Out</th><th style="text-align:right">Balance</th><th style="text-align:right">Unit cost</th><th>Donor coding</th><th>Batch · expiry</th><th>By</th></tr>
      <?php if (!$moves): ?><tr><td colspan="11" class="muted">No movements yet.</td></tr><?php endif; ?>
      <?php foreach ($moves as $m): ?>
      <tr><td style="white-space:nowrap"><?= e(date('d M Y H:i', strtotime($m['created_at']))) ?></td><td><?= e($m['wh']) ?></td><td><?= e(ucfirst($m['move_type'])) ?></td>
        <td><?= $m['ref_type'] === 'grn' ? '<a href="grn_view.php?id=' . (int)$m['ref_id'] . '">' . e($m['ref_number']) . '</a>' : e($m['ref_number']) ?></td>
        <td style="text-align:right"><?= (float)$m['qty'] > 0 ? $fmtQty($m['qty']) : '' ?></td><td style="text-align:right"><?= (float)$m['qty'] < 0 ? $fmtQty(-$m['qty']) : '' ?></td>
        <td style="text-align:right"><b><?= $fmtQty($m['balance_after']) ?></b></td><td style="text-align:right"><?= number_format((float)$m['unit_cost'], 2) ?></td>
        <td class="muted"><?= e(implode(' / ', array_filter([$codes[(int)$m['fin1_id']] ?? '', $codes[(int)$m['fin2_id']] ?? '', $codes[(int)$m['fin3_id']] ?? '']))) ?></td>
        <td class="muted"><?= e(implode(' · ', array_filter([$m['batch_no'], $m['expiry_date'] ? date('d M Y', strtotime($m['expiry_date'])) : '']))) ?></td><td class="muted"><?= e($m['uname'] ?? '') ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <?php render_footer(); exit;
}

/* ---------- Stock levels ---------- */
$where = ['st.qty <> 0']; $p = [];
if ($fw) { $where[] = 'st.warehouse_id = ?'; $p[] = $fw; }
if ($s !== '') { $where[] = '(i.code LIKE ? OR i.description LIKE ?)'; $p[] = "%$s%"; $p[] = "%$s%"; }
$rows = q('SELECT st.*, i.code, i.description, i.unit, w.name AS wh,
             (SELECT MIN(m.expiry_date) FROM stock_moves m WHERE m.item_id = st.item_id AND m.warehouse_id = st.warehouse_id AND m.expiry_date IS NOT NULL AND m.qty > 0) AS first_expiry
           FROM stock st JOIN items i ON i.id = st.item_id JOIN warehouses w ON w.id = st.warehouse_id WHERE ' . implode(' AND ', $where) . ' ORDER BY w.name, i.code', $p)->fetchAll();
$total = array_sum(array_map(fn($r) => (float)$r['qty'] * (float)$r['avg_cost'], $rows));
render_header('Stock'); ?>
<form method="get" class="row" style="margin-bottom:10px;align-items:flex-end">
  <div style="flex:2"><input type="text" name="s" value="<?= e($s) ?>" placeholder="Search item code or description"></div>
  <div><select name="w"><option value="0">All warehouses</option><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>" <?= $fw === (int)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
  <div style="flex:0"><button class="btn sec">Filter</button></div>
</form>
<div class="table-wrap"><table>
  <tr><th>Warehouse</th><th>Item</th><th style="text-align:right">Quantity</th><th style="text-align:right">Avg. unit cost</th><th style="text-align:right">Value (<?= e($cur) ?>)</th><th>First expiry</th></tr>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">No stock yet. Stock appears here when deliveries are received.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $soon = $r['first_expiry'] && $r['first_expiry'] <= date('Y-m-d', strtotime('+90 days')); ?>
  <tr><td><?= e($r['wh']) ?></td><td><a href="?item=<?= (int)$r['item_id'] ?>&w=<?= (int)$r['warehouse_id'] ?>"><b><?= e($r['code']) ?></b></a> <?= e($r['description']) ?></td>
    <td style="text-align:right;white-space:nowrap"><?= $fmtQty($r['qty']) ?> <?= e($r['unit']) ?></td><td style="text-align:right"><?= number_format((float)$r['avg_cost'], 2) ?></td>
    <td style="text-align:right"><?= number_format((float)$r['qty'] * (float)$r['avg_cost'], 2) ?></td>
    <td><?= $r['first_expiry'] ? e(date('d M Y', strtotime($r['first_expiry']))) . ($soon ? ' <span class="tag warn">within 90 days</span>' : '') : '—' ?></td></tr>
  <?php endforeach; ?>
  <?php if ($rows): ?><tr><td colspan="4" style="text-align:right"><b>Total stock value</b></td><td style="text-align:right"><b><?= number_format($total, 2) ?></b></td><td></td></tr><?php endif; ?>
</table></div>
<p class="muted">Click an item to see its stock card (every movement, with the donor coding it was bought on). Issues, transfers and counts come in the next build step.</p>
<?php render_footer();
