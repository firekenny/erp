<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stock.php';
$u = require_any(['storekeeper', 'admin', 'procurement']);
$whs = warehouses();
$canMove = has_role('storekeeper') || has_role('admin');

/* ---------- New transfer ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canMove) {
    $from = (int)($_POST['from'] ?? 0); $to = (int)($_POST['to'] ?? 0);
    $todo = []; $errs = [];
    if (!$from || !$to || $from === $to) $errs[] = 'Choose two different warehouses.';
    foreach ((array)($_POST['item'] ?? []) as $k => $itemId) {
        $itemId = (int)$itemId; $qty = (float)str_replace(',', '.', (string)($_POST['qty'][$k] ?? 0));
        if (!$itemId || $qty <= 0) continue;
        $todo[$itemId] = ($todo[$itemId] ?? 0) + $qty;
    }
    foreach ($todo as $itemId => $qty) if ($qty > stock_qty($itemId, $from) + 0.0001) $errs[] = q('SELECT code FROM items WHERE id = ?', [$itemId])->fetchColumn() . ': not enough stock.';
    if (!$todo) $errs[] = 'Add at least one item.';
    if ($errs) { flash(implode(' ', $errs), 'err'); redirect('issues.php?transfer=1'); }
    db()->beginTransaction();
    $num = next_doc_number('issues', 'TRF');
    q("INSERT INTO issues (number, type, warehouse_id, to_warehouse_id, vehicle, notes, issued_by) VALUES (?, 'transfer', ?, ?, ?, ?, ?)",
      [$num, $from, $to, mb_substr(trim((string)($_POST['vehicle'] ?? '')), 0, 120), trim((string)($_POST['notes'] ?? '')), $u['id']]);
    $iid = (int)db()->lastInsertId();
    $toName = q('SELECT name FROM warehouses WHERE id = ?', [$to])->fetchColumn();
    foreach ($todo as $itemId => $qty) {
        $avg = (float)q('SELECT avg_cost FROM stock WHERE item_id = ? AND warehouse_id = ?', [$itemId, $from])->fetchColumn();
        foreach (stock_out($itemId, $from, $qty, ['type' => 'transfer out', 'ref_type' => 'issue', 'ref_id' => $iid, 'ref_number' => $num, 'destination' => $toName]) as [$lot, $t]) {
            q('INSERT INTO issue_lines (issue_id, item_id, lot_id, qty, unit_cost) VALUES (?, ?, ?, ?, ?)', [$iid, $itemId, $lot['id'], $t, $avg]);
            // the same lot arrives at the destination, keeping its donor coding, batch and expiry
            stock_in($itemId, $to, $t, $avg, ['type' => 'transfer in', 'source_type' => 'transfer', 'ref_type' => 'issue', 'ref_id' => $iid, 'ref_number' => $num,
                     'parent_lot_id' => $lot['id'], 'date' => $lot['received_date'], 'batch_no' => $lot['batch_no'], 'expiry_date' => $lot['expiry_date']],
                     [1 => $lot['fin1_id'], 2 => $lot['fin2_id'], 3 => $lot['fin3_id'], 4 => $lot['fin4_id']]);
        }
    }
    audit('transfer.create', $num);
    db()->commit();
    flash("$num recorded. Print the waybill for the driver.");
    redirect("issue_view.php?id=$iid");
}

if (isset($_GET['transfer']) && $canMove) {
    $avail = q('SELECT st.warehouse_id, st.item_id, st.qty, i.code, i.description, i.unit FROM stock st JOIN items i ON i.id = st.item_id WHERE st.qty > 0 ORDER BY i.code')->fetchAll();
    render_header('New transfer'); ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="row">
    <div><label>From warehouse</label><select name="from" id="wh" required><option value="">— select —</option><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>To warehouse</label><select name="to" required><option value="">— select —</option><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Vehicle / driver</label><input type="text" name="vehicle"></div>
  </div>
  <h2 style="margin-top:16px">Items</h2><div id="rows"></div>
  <div class="actions"><button type="button" class="btn sec small" onclick="addRow()">+ Add item</button></div>
  <label>Notes</label><input type="text" name="notes">
  <div class="actions"><button class="btn" onclick="return confirm('Record this transfer?')">Record transfer</button><a href="issues.php">Cancel</a></div>
  <p class="muted">Each item keeps its donor coding, batch and expiry at the destination.</p>
</form>
<script>
const AV = <?= json_encode(array_map(fn($a) => ['w' => (int)$a['warehouse_id'], 'id' => (int)$a['item_id'], 'q' => (float)$a['qty'], 'l' => $a['code'] . ' – ' . $a['description'], 'u' => $a['unit']], $avail), JSON_HEX_TAG) ?>;
function opts() { const w = parseInt(document.getElementById('wh').value || 0);
  return '<option value="">— item —</option>' + AV.filter(a => a.w === w).map(a => `<option value="${a.id}">${a.l.replace(/</g,'&lt;')} (${a.q} ${a.u})</option>`).join(''); }
function addRow() { const d = document.createElement('div'); d.className = 'row'; d.style.alignItems = 'flex-end';
  d.innerHTML = '<div style="flex:3"><select name="item[]">' + opts() + '</select></div><div><input type="number" name="qty[]" min="0" step="any" placeholder="Quantity"></div><div style="flex:0"><button type="button" class="btn sec small" onclick="this.closest(\'.row\').remove()">✕</button></div>';
  document.getElementById('rows').appendChild(d); }
document.getElementById('wh').addEventListener('change', () => { document.getElementById('rows').innerHTML = ''; addRow(); });
</script>
<?php render_footer(); exit;
}

$rows = q('SELECT i.*, w.name AS wh, w2.name AS to_wh, u.name AS by_name, (SELECT COUNT(DISTINCT il.item_id) FROM issue_lines il WHERE il.issue_id = i.id) AS n
           FROM issues i JOIN warehouses w ON w.id = i.warehouse_id LEFT JOIN warehouses w2 ON w2.id = i.to_warehouse_id JOIN users u ON u.id = i.issued_by ORDER BY i.id DESC LIMIT 300')->fetchAll();
render_header('Issues & transfers'); ?>
<?php if ($canMove): ?><div class="actions" style="margin:0 0 12px"><a class="btn" href="?transfer=1">+ New transfer</a><a class="btn sec" href="requests.php">Stock requests to issue</a></div><?php endif; ?>
<div class="table-wrap"><table>
  <tr><th>Document</th><th>Date</th><th>From</th><th>To</th><th>Items</th><th>By</th></tr>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">Nothing issued or transferred yet.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr><td><a href="issue_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['number']) ?></b></a> <span class="tag <?= $r['type'] === 'transfer' ? '' : 'stock' ?>"><?= e($r['type']) ?></span></td>
    <td style="white-space:nowrap"><?= e(date('d M Y', strtotime($r['issued_at']))) ?></td><td><?= e($r['wh']) ?></td>
    <td><?= $r['type'] === 'transfer' ? e($r['to_wh']) : e($r['issued_to']) . ($r['destination'] ? '<br><span class="muted">' . e($r['destination']) . '</span>' : '') ?></td>
    <td><?= (int)$r['n'] ?></td><td class="muted"><?= e($r['by_name']) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php render_footer();
