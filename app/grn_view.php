<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stock.php';
require_any(['storekeeper', 'procurement', 'admin']);

$id = (int)($_GET['id'] ?? 0);
$g = q('SELECT g.*, p.number AS po_number, p.id AS po_id, p.currency, s.name AS supplier_name, w.name AS wh_name, u.name AS by_name, pr.number AS pr_number
        FROM grn g JOIN po p ON p.id = g.po_id JOIN suppliers s ON s.id = p.supplier_id JOIN warehouses w ON w.id = g.warehouse_id
        JOIN users u ON u.id = g.received_by JOIN pr ON pr.id = p.pr_id WHERE g.id = ?', [$id])->fetch();
if (!$g) { flash('Receipt not found.', 'err'); redirect('receive.php'); }
$lines = q('SELECT gl.*, pl.line_no, pl.description, pl.unit, i.code AS item_code FROM grn_lines gl JOIN po_lines pl ON pl.id = gl.po_line_id JOIN items i ON i.id = gl.item_id WHERE gl.grn_id = ? ORDER BY pl.line_no', [$id])->fetchAll();
$assets = q('SELECT a.code, a.serial_no, a.brand_model, a.description, a.id FROM assets a JOIN grn_lines gl ON gl.id = a.grn_line_id WHERE gl.grn_id = ? ORDER BY a.code', [$id])->fetchAll();
$fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');

render_header($g['number']); ?>
<div class="actions noprint" style="margin:-6px 0 12px">
  <span class="muted">PO <a href="po_view.php?id=<?= (int)$g['po_id'] ?>"><?= e($g['po_number']) ?></a> · <?= e($g['pr_number']) ?></span>
  <button class="btn sec" style="margin-left:auto" onclick="window.print()">Print GRN</button><a href="receive.php">Back to receiving</a>
</div>
<div class="card">
  <div class="print-only" style="font-size:18px;font-weight:700;margin-bottom:8px"><?= e(setting('org_name')) ?> · GOODS RECEIVED NOTE <?= e($g['number']) ?></div>
  <div class="row">
    <div><span class="muted">Supplier</span><br><b><?= e($g['supplier_name']) ?></b></div>
    <div><span class="muted">Purchase Order</span><br><?= e($g['po_number']) ?></div>
    <div><span class="muted">Warehouse</span><br><?= e($g['wh_name']) ?></div>
    <div><span class="muted">Date received</span><br><?= e(date('d M Y', strtotime($g['received_date']))) ?></div>
    <div><span class="muted">Delivery note</span><br><?= e($g['delivery_note'] ?: '—') ?></div>
  </div>
  <?php if ($g['notes']): ?><p style="margin-bottom:0"><span class="muted">Notes</span><br><?= nl2br(e($g['notes'])) ?></p><?php endif; ?>
</div>
<div class="table-wrap"><table>
  <tr><th>#</th><th>Item</th><th>Registered as</th><th style="text-align:right">Received</th><th style="text-align:right">Rejected</th><th style="text-align:right">Accepted</th><th>Bin · batch · expiry</th></tr>
  <?php foreach ($lines as $l): ?>
  <tr><td><?= (int)$l['line_no'] ?></td><td><b><?= e($l['item_code']) ?></b> <?= e($l['description']) ?></td><td><span class="tag <?= e($l['line_type']) ?>"><?= e($l['line_type']) ?></span></td>
    <td style="text-align:right"><?= $fmtQty($l['qty_received']) ?> <?= e($l['unit']) ?></td>
    <td style="text-align:right"><?= (float)$l['qty_rejected'] > 0 ? $fmtQty($l['qty_rejected']) . '<br><span class="muted">' . e($l['reject_reason']) . '</span>' : '0' ?></td>
    <td style="text-align:right"><b><?= $fmtQty((float)$l['qty_received'] - (float)$l['qty_rejected']) ?></b></td>
    <td class="muted"><?= e(implode(' · ', array_filter([$l['bin'], $l['batch_no'], $l['expiry_date'] ? 'exp. ' . date('d M Y', strtotime($l['expiry_date'])) : '']))) ?: '—' ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php if ($assets): ?>
<div class="card" style="margin-top:16px">
  <h2>Assets registered (<?= count($assets) ?>)</h2>
  <div class="table-wrap"><table>
    <tr><th>Asset ID</th><th>Description</th><th>Brand / model</th><th>Serial number</th></tr>
    <?php foreach ($assets as $a): ?><tr><td><a href="assets.php?edit=<?= (int)$a['id'] ?>"><b><?= e($a['code']) ?></b></a></td><td><?= e($a['description']) ?></td><td><?= e($a['brand_model']) ?></td><td><?= $a['serial_no'] !== '' ? e($a['serial_no']) : '<span class="tag warn">to complete</span>' ?></td></tr><?php endforeach; ?>
  </table></div>
  <p class="muted noprint">Missing serial numbers can be added from the asset register.</p>
</div>
<?php endif; ?>
<div class="row" style="margin-top:30px">
  <div>Received by<br><br>________________________<br><span class="muted"><?= e($g['by_name']) ?></span></div>
  <div>Delivered by (supplier)<br><br>________________________</div>
</div>
<?php render_footer();
