<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stock.php';
$u = require_login();

$id = (int)($_GET['id'] ?? 0);
$is = q('SELECT i.*, w.name AS wh, w2.name AS to_wh, u.name AS by_name, r.number AS req_number, r.requester_id, r.purpose
         FROM issues i JOIN warehouses w ON w.id = i.warehouse_id LEFT JOIN warehouses w2 ON w2.id = i.to_warehouse_id JOIN users u ON u.id = i.issued_by
         LEFT JOIN stock_requests r ON r.id = i.request_id WHERE i.id = ?', [$id])->fetch();
$can = $is && (has_role('storekeeper') || has_role('admin') || has_role('procurement') || (int)($is['requester_id'] ?? 0) === (int)$u['id']);
if (!$can) { flash('Not found.', 'err'); redirect('index.php'); }
$lines = q('SELECT il.*, it.code, it.description, it.unit, l.batch_no, l.expiry_date, l.source_ref, l.fin1_id, l.fin2_id, l.fin3_id, l.fin4_id
            FROM issue_lines il JOIN items it ON it.id = il.item_id JOIN stock_lots l ON l.id = il.lot_id WHERE il.issue_id = ? ORDER BY it.code, il.id', [$id])->fetchAll();
$codes = fin_code_map();
$fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');
$isTransfer = $is['type'] === 'transfer';
render_header($is['number']); ?>
<div class="actions noprint" style="margin:-6px 0 12px">
  <?php if ($is['req_number']): ?><span class="muted">For request <a href="request_view.php?id=<?= (int)$is['request_id'] ?>"><?= e($is['req_number']) ?></a></span><?php endif; ?>
  <button class="btn" style="margin-left:auto" onclick="window.print()">Print <?= $isTransfer ? 'waybill' : 'voucher' ?></button><a href="issues.php">Back to list</a>
</div>
<div class="card">
  <div class="row" style="align-items:flex-start">
    <div style="flex:2"><div style="font-size:18px;font-weight:700"><?= e(setting('org_name')) ?></div></div>
    <div style="text-align:right"><div style="font-size:18px;font-weight:700"><?= $isTransfer ? 'TRANSFER WAYBILL' : 'STOCK ISSUE VOUCHER' ?></div><div><?= e($is['number']) ?></div><div class="muted"><?= e(date('d M Y H:i', strtotime($is['issued_at']))) ?></div></div>
  </div>
  <div class="row" style="margin-top:12px">
    <div><span class="muted">From</span><br><b><?= e($is['wh']) ?></b></div>
    <?php if ($isTransfer): ?><div><span class="muted">To warehouse</span><br><b><?= e($is['to_wh']) ?></b></div><div><span class="muted">Vehicle / driver</span><br><?= e($is['vehicle'] ?: '—') ?></div>
    <?php else: ?><div><span class="muted">Issued to</span><br><b><?= e($is['issued_to']) ?></b></div><div><span class="muted">Destination</span><br><?= e($is['destination'] ?: '—') ?></div><?php endif; ?>
  </div>
  <?php if ($is['purpose'] || $is['notes']): ?><p style="margin-bottom:0" class="muted"><?= e(trim($is['purpose'] . ' ' . $is['notes'])) ?></p><?php endif; ?>
</div>
<div class="table-wrap"><table>
  <tr><th>Item</th><th style="text-align:right">Quantity</th><th>Batch · expiry</th><th>Donor coding (from purchase)</th><th>Origin</th></tr>
  <?php foreach ($lines as $l): ?>
  <tr><td><b><?= e($l['code']) ?></b> <?= e($l['description']) ?></td><td style="text-align:right;white-space:nowrap"><?= $fmtQty($l['qty']) ?> <?= e($l['unit']) ?></td>
    <td class="muted"><?= e(implode(' · ', array_filter([$l['batch_no'], $l['expiry_date'] ? date('d M Y', strtotime($l['expiry_date'])) : '']))) ?: '—' ?></td>
    <td><?= e(fin_label($l, $codes)) ?: '<span class="muted">—</span>' ?></td><td class="muted"><?= e($l['source_ref']) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<div class="row" style="margin-top:30px">
  <div><?= $isTransfer ? 'Dispatched by' : 'Issued by' ?><br><br>________________________<br><span class="muted"><?= e($is['by_name']) ?></span></div>
  <?php if ($isTransfer): ?><div>Driver<br><br>________________________</div><div>Received at <?= e($is['to_wh']) ?> by<br><br>________________________</div>
  <?php else: ?><div>Received by<br><br>________________________<br><span class="muted"><?= e($is['issued_to']) ?></span></div><?php endif; ?>
</div>
<?php render_footer();
