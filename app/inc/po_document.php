<?php
/* The printable Purchase Order itself (used by the internal page and the supplier page).
   Expects $po, $lines, $fmtQty; $levels and $codes optional (finance codes shown internally only). */
$showCodes = isset($levels, $codes) && $levels;
?>
<div class="card po-doc">
  <div class="row" style="align-items:flex-start">
    <div style="flex:2">
      <div style="font-size:20px;font-weight:700"><?= e(setting('org_name')) ?></div>
      <?php if (setting('org_address')): ?><div class="muted" style="white-space:pre-line"><?= e(setting('org_address')) ?></div><?php endif; ?>
    </div>
    <div style="text-align:right">
      <div style="font-size:20px;font-weight:700">PURCHASE ORDER</div>
      <div style="font-size:17px"><?= e($po['number']) ?></div>
      <div class="muted">Date: <?= e(date('d M Y', strtotime($po['created_at']))) ?></div>
      <?php if ($po['status'] === 'cancelled'): ?><div style="color:#b3261e;font-weight:700;font-size:18px">CANCELLED</div><?php endif; ?>
    </div>
  </div>
  <div class="row" style="margin-top:14px">
    <div><span class="muted">Supplier</span><br><b><?= e($po['supplier_name']) ?></b> <span class="muted">(<?= e($po['supplier_code']) ?>)</span><br>
      <?= e($po['contact_name']) ?><?= $po['supplier_address'] ? '<br>' . e($po['supplier_address']) : '' ?><?= $po['supplier_country'] ? ', ' . e($po['supplier_country']) : '' ?>
      <?= $po['supplier_email'] ? '<br>' . e($po['supplier_email']) : '' ?><?= $po['supplier_phone'] ? ' · ' . e($po['supplier_phone']) : '' ?>
      <?= $po['registration_no'] ? '<br><span class="muted">Reg. ' . e($po['registration_no']) . '</span>' : '' ?></div>
    <div><span class="muted">Deliver to</span><br><b><?= e($po['delivery_location'] ?: '—') ?></b><br><span class="muted">Delivery date</span><br><b><?= $po['delivery_date'] ? e(date('d M Y', strtotime($po['delivery_date']))) : 'As agreed' ?></b></div>
    <div><span class="muted">References</span><br>Request <?= e($po['pr_number']) ?><?= $po['rfq_number'] ? '<br>Quotation ' . e($po['rfq_number']) : '' ?><br>Currency <?= e($po['currency']) ?></div>
  </div>
</div>
<div class="table-wrap po-doc">
<table>
  <tr><th>#</th><th>Description</th><th style="text-align:right">Qty</th><th style="text-align:right">Unit price</th><th style="text-align:right">Total</th>
    <?php if ($showCodes): foreach ($levels as $lv): ?><th class="fin"><?= e($lv['name']) ?></th><?php endforeach; endif; ?></tr>
  <?php foreach ($lines as $l): ?>
  <tr><td><?= (int)$l['line_no'] ?></td>
    <td><b><?= e($l['description']) ?></b><?php if ($l['specification']): ?><br><span class="muted"><?= e($l['specification']) ?></span><?php endif; ?><?php if ($l['remarks']): ?><br><span class="muted">Offered: <?= e($l['remarks']) ?></span><?php endif; ?></td>
    <td style="text-align:right;white-space:nowrap"><?= $fmtQty($l['qty']) ?> <?= e($l['unit']) ?></td>
    <td style="text-align:right"><?= number_format((float)$l['unit_price'], 2) ?></td>
    <td style="text-align:right"><b><?= number_format((float)$l['qty'] * (float)$l['unit_price'], 2) ?></b></td>
    <?php if ($showCodes): foreach ($levels as $lv): ?><td class="fin"><?= e($codes[(int)$l['fin' . $lv['position'] . '_id']] ?? '') ?></td><?php endforeach; endif; ?></tr>
  <?php endforeach; ?>
  <tr><td colspan="4" style="text-align:right"><b>TOTAL <?= e($po['currency']) ?></b></td><td style="text-align:right;font-size:16px"><b><?= number_format(po_total($lines), 2) ?></b></td><?php if ($showCodes): foreach ($levels as $lv): ?><td class="fin"></td><?php endforeach; endif; ?></tr>
</table>
</div>
<div class="card po-doc">
  <p style="margin-top:0"><b>Payment terms</b><br><span style="white-space:pre-line"><?= e($po['payment_terms']) ?></span></p>
  <p style="margin-bottom:0"><b>Terms and conditions</b><br><span style="white-space:pre-line;font-size:13px"><?= e($po['terms']) ?></span></p>
  <div class="row" style="margin-top:24px">
    <div>Authorised by<br><br>________________________<br><span class="muted"><?= e($po['created_name'] ?? '') ?> · <?= e(date('d M Y', strtotime($po['created_at']))) ?></span></div>
    <div>Accepted by the supplier<br><br><?= $po['acknowledged_at'] ? '<b>' . e($po['acknowledged_name']) . '</b> (online, ' . e(date('d M Y H:i', strtotime($po['acknowledged_at']))) . ')' : '________________________' ?></div>
  </div>
</div>
