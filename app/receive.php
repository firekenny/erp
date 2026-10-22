<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';
$u = require_any(['storekeeper', 'admin']);

$poId = (int)($_GET['po'] ?? $_POST['po'] ?? 0);
$whs = warehouses();

/* ---------- Post a receipt ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $poId) {
    $po = po_get($poId);
    if (!$po || !in_array($po['status'], ['issued', 'acknowledged', 'partially_received'], true)) { flash('This Purchase Order cannot be received.', 'err'); redirect('receive.php'); }
    $wh = (int)($_POST['warehouse_id'] ?? 0);
    if (!array_filter($whs, fn($w) => (int)$w['id'] === $wh)) { flash('Choose the warehouse.', 'err'); redirect("receive.php?po=$poId"); }
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['received_date'] ?? '')) ? $_POST['received_date'] : date('Y-m-d');
    if ($date > date('Y-m-d')) { flash('The receipt date cannot be in the future.', 'err'); redirect("receive.php?po=$poId"); }
    $acc = po_accepted($poId);
    $lines = array_column(q('SELECT pl.*, i.item_type, i.category_id, c.code AS cat_code FROM po_lines pl JOIN items i ON i.id = pl.item_id JOIN categories c ON c.id = i.category_id WHERE pl.po_id = ?', [$poId])->fetchAll(), null, 'id');
    $num = fn($v) => (float)str_replace(',', '.', trim((string)$v));
    $todo = []; $errs = [];
    foreach ((array)($_POST['l'] ?? []) as $lid => $in) {
        $l = $lines[(int)$lid] ?? null; if (!$l) continue;
        $rec = $num($in['rec'] ?? 0); $rej = $num($in['rej'] ?? 0);
        if ($rec <= 0) continue;
        $out = (float)$l['qty'] - ($acc[(int)$lid] ?? 0);
        $tag = 'Line ' . $l['line_no'];
        $type = in_array($in['type'] ?? '', ['stock', 'asset'], true) && $l['item_type'] !== 'service' ? $in['type'] : $l['item_type'];
        if ($rej < 0 || $rej > $rec) $errs[] = "$tag: rejected quantity must be between 0 and the quantity received.";
        if ($rec - $rej > $out + 0.0001) $errs[] = "$tag: you are accepting more than the " . rtrim(rtrim(number_format($out, 3, '.', ''), '0'), '.') . ' still expected. Over-deliveries must be refused or a PO amendment made.';
        if ($rej > 0 && trim((string)($in['reason'] ?? '')) === '') $errs[] = "$tag: give the reason for rejecting.";
        if ($type === 'asset' && abs(($rec - $rej) - round($rec - $rej)) > 0.0001) $errs[] = "$tag: assets must be received in whole units.";
        $serials = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', (string)($in['serials'] ?? '')))));
        if ($type === 'asset' && count($serials) > (int)round($rec - $rej)) $errs[] = "$tag: more serial numbers (" . count($serials) . ') than accepted units.';
        if ($type === 'asset' && count($serials) !== count(array_unique($serials))) $errs[] = "$tag: the same serial number is entered twice.";
        foreach ($serials as $sn) if (q('SELECT code FROM assets WHERE serial_no = ? AND item_id = ?', [$sn, $l['item_id']])->fetchColumn()) $errs[] = "$tag: serial $sn is already registered.";
        $exp = (string)($in['expiry'] ?? '');
        $todo[] = ['l' => $l, 'rec' => $rec, 'rej' => $rej, 'type' => $type, 'reason' => mb_substr(trim((string)($in['reason'] ?? '')), 0, 255),
                   'bin' => mb_substr(trim((string)($in['bin'] ?? '')), 0, 60), 'batch' => mb_substr(trim((string)($in['batch'] ?? '')), 0, 60),
                   'expiry' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp : null, 'serials' => $serials, 'brand' => mb_substr(trim((string)($in['brand'] ?? '')), 0, 190)];
    }
    if (!$todo) $errs[] = 'Enter the quantity received for at least one line.';
    if ($errs) { flash(implode(' ', array_slice(array_unique($errs), 0, 6)), 'err'); $_SESSION['grn_form'] = $_POST; redirect("receive.php?po=$poId"); }

    db()->beginTransaction();
    $number = grn_next_number();
    q('INSERT INTO grn (number, po_id, warehouse_id, received_date, delivery_note, notes, received_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
      [$number, $poId, $wh, $date, mb_substr(trim((string)($_POST['delivery_note'] ?? '')), 0, 80), trim((string)($_POST['notes'] ?? '')), $u['id']]);
    $grnId = (int)db()->lastInsertId();
    $nAssets = 0; $nStock = 0;
    foreach ($todo as $t) {
        $l = $t['l'];
        q('INSERT INTO grn_lines (grn_id, po_line_id, item_id, line_type, qty_received, qty_rejected, reject_reason, unit_price, bin, batch_no, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$grnId, $l['id'], $l['item_id'], $t['type'], $t['rec'], $t['rej'], $t['reason'], $l['unit_price'], $t['bin'], $t['batch'], $t['expiry']]);
        $glId = (int)db()->lastInsertId();
        $accQ = $t['rec'] - $t['rej'];
        $fin = [1 => $l['fin1_id'], 2 => $l['fin2_id'], 3 => $l['fin3_id'], 4 => $l['fin4_id']];
        if ($accQ <= 0) continue;
        if ($t['type'] === 'stock') {
            stock_in((int)$l['item_id'], $wh, $accQ, (float)$l['unit_price'],
                     ['type' => 'receipt', 'source_type' => 'grn', 'ref_type' => 'grn', 'ref_id' => $grnId, 'ref_number' => $number, 'date' => $date, 'bin' => $t['bin'], 'batch_no' => $t['batch'], 'expiry_date' => $t['expiry']], $fin);
            $nStock++;
        } elseif ($t['type'] === 'asset') {
            $whName = q('SELECT name FROM warehouses WHERE id = ?', [$wh])->fetchColumn();
            $locId = warehouse_location_id($wh);
            for ($k = 0; $k < (int)round($accQ); $k++) {
                q("INSERT INTO assets (code, item_id, category_id, description, brand_model, serial_no, purchase_date, purchase_value, currency, supplier_id, po_id, grn_line_id,
                      fin1_id, fin2_id, fin3_id, fin4_id, warehouse_id, location_id, location, cond, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', 'in_store')",
                  [next_asset_code($l['cat_code']), $l['item_id'], $l['category_id'], $l['description'], $t['brand'] ?: $l['remarks'], $t['serials'][$k] ?? '', $date, $l['unit_price'],
                   $po['currency'], $po['supplier_id'], $poId, $glId, $fin[1], $fin[2], $fin[3], $fin[4], $wh, $locId, $whName . ($t['bin'] ? ' · ' . $t['bin'] : '')]);
                asset_event((int)db()->lastInsertId(), 'received', 'Received on ' . $number . ' from ' . $po['supplier_name'], 'grn', $grnId);
                $nAssets++;
            }
        }
    }
    po_refresh_receipt_status($poId);
    po_log($poId, 'goods received', $number);
    audit('grn.create', $number);
    db()->commit();
    unset($_SESSION['grn_form']);
    flash("$number recorded: $nStock stock line(s) added to stock" . ($nAssets ? ", $nAssets asset(s) registered" : '') . '.');
    redirect("grn_view.php?id=$grnId");
}

/* ---------- Receipt form for one PO ---------- */
if ($poId) {
    $po = po_get($poId);
    if (!$po) redirect('receive.php');
    $acc = po_accepted($poId);
    $lines = q('SELECT pl.*, i.item_type, i.code AS item_code FROM po_lines pl JOIN items i ON i.id = pl.item_id WHERE pl.po_id = ? ORDER BY pl.line_no', [$poId])->fetchAll();
    $old = $_SESSION['grn_form'] ?? []; unset($_SESSION['grn_form']);
    $fmtQty = fn($q) => rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.');
    $open = in_array($po['status'], ['issued', 'acknowledged', 'partially_received'], true);
    render_header('Receive ' . $po['number']);
    if (!$whs): ?><div class="flash warn">No warehouse is set up yet. An Administrator must add one under Setup &gt; Warehouses.</div><?php endif; ?>
<div class="card">
  <div class="row">
    <div><span class="muted">Supplier</span><br><b><?= e($po['supplier_name']) ?></b></div>
    <div><span class="muted">Purchase Order</span><br><a href="po_view.php?id=<?= $poId ?>"><?= e($po['number']) ?></a> <?= po_badge($po['status']) ?></div>
    <div><span class="muted">Expected</span><br><?= $po['delivery_date'] ? e(date('d M Y', strtotime($po['delivery_date']))) : '—' ?> · <?= e($po['delivery_location']) ?></div>
  </div>
</div>
<?php if (!$open): ?><div class="flash warn">This Purchase Order is <?= e(PO_STATUS[$po['status']][0]) ?>: nothing more to receive.</div><?php else: ?>
<form method="post" id="grnf">
  <?= csrf_field() ?><input type="hidden" name="po" value="<?= $poId ?>">
  <div class="card">
    <div class="row">
      <div><label>Warehouse</label><select name="warehouse_id" required><option value="">— select —</option><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>" <?= (int)($old['warehouse_id'] ?? 0) === (int)$w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Date received</label><input type="date" name="received_date" value="<?= e($old['received_date'] ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>"></div>
      <div><label>Supplier delivery note no.</label><input type="text" name="delivery_note" value="<?= e($old['delivery_note'] ?? '') ?>"></div>
    </div>
  </div>
  <?php foreach ($lines as $l): $out = (float)$l['qty'] - ($acc[$l['id']] ?? 0); $o = $old['l'][$l['id']] ?? []; $type = $o['type'] ?? $l['item_type']; ?>
  <div class="card grn-line" data-type="<?= e($type) ?>" style="<?= $out <= 0.0001 ? 'opacity:.6' : '' ?>">
    <div class="row" style="align-items:flex-start">
      <div style="flex:3"><b><?= (int)$l['line_no'] ?>. <?= e($l['item_code']) ?> <?= e($l['description']) ?></b><?php if ($l['remarks']): ?> <span class="muted">· <?= e($l['remarks']) ?></span><?php endif; ?><br>
        <span class="muted">Ordered <?= $fmtQty($l['qty']) ?> <?= e($l['unit']) ?> · accepted so far <?= $fmtQty($acc[$l['id']] ?? 0) ?> · <b>still expected <?= $fmtQty(max(0, $out)) ?></b> · <?= number_format((float)$l['unit_price'], 2) ?> <?= e($po['currency']) ?>/<?= e($l['unit']) ?></span></div>
      <div><?php if ($l['item_type'] === 'service'): ?><span class="tag service">Service</span><input type="hidden" name="l[<?= (int)$l['id'] ?>][type]" value="service">
        <?php else: ?><label style="margin-top:0">Register as</label><select name="l[<?= (int)$l['id'] ?>][type]" class="ltype"><option value="stock" <?= $type === 'stock' ? 'selected' : '' ?>>Stock</option><option value="asset" <?= $type === 'asset' ? 'selected' : '' ?>>Asset (one per unit)</option></select><?php endif; ?></div>
    </div>
    <?php if ($out > 0.0001): ?>
    <div class="row">
      <div><label><?= $l['item_type'] === 'service' ? 'Quantity delivered' : 'Quantity received' ?> (<?= e($l['unit']) ?>)</label><input type="number" step="any" min="0" name="l[<?= (int)$l['id'] ?>][rec]" value="<?= e($o['rec'] ?? '') ?>" placeholder="max <?= $fmtQty($out) ?>"></div>
      <div><label>Rejected</label><input type="number" step="any" min="0" name="l[<?= (int)$l['id'] ?>][rej]" value="<?= e($o['rej'] ?? '0') ?>"></div>
      <div style="flex:2"><label>Reason for rejection</label><input type="text" name="l[<?= (int)$l['id'] ?>][reason]" value="<?= e($o['reason'] ?? '') ?>" placeholder="Damaged, wrong specification…"></div>
    </div>
    <?php if ($l['item_type'] !== 'service'): ?>
    <div class="row f-stock">
      <div><label>Bin / shelf</label><input type="text" name="l[<?= (int)$l['id'] ?>][bin]" value="<?= e($o['bin'] ?? '') ?>"></div>
      <div><label>Batch / lot <span class="muted">(optional)</span></label><input type="text" name="l[<?= (int)$l['id'] ?>][batch]" value="<?= e($o['batch'] ?? '') ?>"></div>
      <div><label>Expiry date <span class="muted">(optional)</span></label><input type="date" name="l[<?= (int)$l['id'] ?>][expiry]" value="<?= e($o['expiry'] ?? '') ?>"></div>
    </div>
    <div class="row f-asset">
      <div><label>Brand / model</label><input type="text" name="l[<?= (int)$l['id'] ?>][brand]" value="<?= e($o['brand'] ?? $l['remarks']) ?>"></div>
      <div style="flex:2"><label>Serial numbers, one per line <span class="muted">(can be completed later)</span></label><textarea name="l[<?= (int)$l['id'] ?>][serials]" rows="3"><?= e($o['serials'] ?? '') ?></textarea></div>
    </div>
    <?php endif; endif; ?>
  </div>
  <?php endforeach; ?>
  <div class="card">
    <label>Notes</label><textarea name="notes" rows="2"><?= e($old['notes'] ?? '') ?></textarea>
    <div class="actions"><button class="btn" onclick="return confirm('Record this receipt? Stock and assets will be updated.')">Record receipt (GRN)</button><a href="receive.php">Cancel</a></div>
  </div>
</form>
<script>
document.querySelectorAll('.grn-line').forEach(c => {
  const sel = c.querySelector('.ltype');
  const show = () => { const t = sel ? sel.value : c.dataset.type; c.querySelectorAll('.f-stock').forEach(x => x.style.display = t === 'stock' ? '' : 'none'); c.querySelectorAll('.f-asset').forEach(x => x.style.display = t === 'asset' ? '' : 'none'); };
  if (sel) sel.addEventListener('change', show); show();
});
</script>
<?php endif;
    render_footer(); exit;
}

/* ---------- List ---------- */
$toReceive = q("SELECT p.*, s.name AS supplier_name, pr.title,
                  (SELECT COALESCE(SUM(l.qty * l.unit_price), 0) FROM po_lines l WHERE l.po_id = p.id) AS total
                FROM po p JOIN suppliers s ON s.id = p.supplier_id JOIN pr ON pr.id = p.pr_id
                WHERE p.status IN ('issued','acknowledged','partially_received') ORDER BY p.delivery_date IS NULL, p.delivery_date")->fetchAll();
$grns = q('SELECT g.*, p.number AS po_number, s.name AS supplier_name, w.name AS wh_name, u.name AS by_name
           FROM grn g JOIN po p ON p.id = g.po_id JOIN suppliers s ON s.id = p.supplier_id JOIN warehouses w ON w.id = g.warehouse_id JOIN users u ON u.id = g.received_by
           ORDER BY g.id DESC LIMIT 100')->fetchAll();
render_header('Receiving'); ?>
<h2>Orders to receive (<?= count($toReceive) ?>)</h2>
<div class="table-wrap" style="margin-bottom:18px"><table>
  <tr><th>PO</th><th>Supplier</th><th>For</th><th>Expected</th><th>Status</th><th></th></tr>
  <?php if (!$toReceive): ?><tr><td colspan="6" class="muted">Nothing to receive.</td></tr><?php endif; ?>
  <?php foreach ($toReceive as $p): $late = $p['delivery_date'] && $p['delivery_date'] < date('Y-m-d'); ?>
  <tr><td><a href="po_view.php?id=<?= (int)$p['id'] ?>"><b><?= e($p['number']) ?></b></a></td><td><?= e($p['supplier_name']) ?></td><td><?= e($p['title']) ?></td>
    <td style="white-space:nowrap"><?= $p['delivery_date'] ? e(date('d M Y', strtotime($p['delivery_date']))) : '—' ?><?= $late ? ' <span class="tag warn">late</span>' : '' ?></td>
    <td><?= po_badge($p['status']) ?></td><td><a class="btn small" href="?po=<?= (int)$p['id'] ?>">Receive</a></td></tr>
  <?php endforeach; ?>
</table></div>
<h2>Goods Received Notes</h2>
<div class="table-wrap"><table>
  <tr><th>GRN</th><th>Date</th><th>PO · supplier</th><th>Warehouse</th><th>Received by</th></tr>
  <?php if (!$grns): ?><tr><td colspan="5" class="muted">No receipts yet.</td></tr><?php endif; ?>
  <?php foreach ($grns as $g): ?>
  <tr><td><a href="grn_view.php?id=<?= (int)$g['id'] ?>"><b><?= e($g['number']) ?></b></a></td><td><?= e(date('d M Y', strtotime($g['received_date']))) ?></td>
    <td><?= e($g['po_number']) ?> · <?= e($g['supplier_name']) ?></td><td><?= e($g['wh_name']) ?></td><td><?= e($g['by_name']) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php render_footer();
