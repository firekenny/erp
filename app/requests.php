<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stock.php';
$u = require_any(['requester', 'storekeeper', 'admin']);
$isStore = has_role('storekeeper') || has_role('admin');
$whs = warehouses();

/* ---------- New request ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && has_role('requester')) {
    $wh = (int)($_POST['warehouse_id'] ?? 0);
    $to = trim((string)($_POST['issue_to'] ?? '')) ?: $u['name'];
    $lines = [];
    foreach ((array)($_POST['item'] ?? []) as $k => $itemId) {
        $itemId = (int)$itemId; $qty = (float)str_replace(',', '.', (string)($_POST['qty'][$k] ?? 0));
        if ($itemId && $qty > 0) $lines[$itemId] = ($lines[$itemId] ?? 0) + $qty;
    }
    if (!array_filter($whs, fn($w) => (int)$w['id'] === $wh)) flash('Choose the warehouse.', 'err');
    elseif (!$lines) flash('Add at least one item with a quantity.', 'err');
    else {
        db()->beginTransaction();
        $num = next_doc_number('stock_requests', 'SR');
        $needed = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['needed_by'] ?? '')) ? $_POST['needed_by'] : null;
        q('INSERT INTO stock_requests (number, requester_id, warehouse_id, issue_to, destination, purpose, needed_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
          [$num, $u['id'], $wh, mb_substr($to, 0, 190), mb_substr(trim((string)($_POST['destination'] ?? '')), 0, 190), mb_substr(trim((string)($_POST['purpose'] ?? '')), 0, 500), $needed]);
        $rid = (int)db()->lastInsertId();
        foreach ($lines as $itemId => $qty) q('INSERT INTO stock_request_lines (request_id, item_id, qty_requested) VALUES (?, ?, ?)', [$rid, $itemId, $qty]);
        db()->commit();
        audit('stock_request.create', $num);
        flash("Stock request $num sent to the storekeeper.");
        redirect("request_view.php?id=$rid");
    }
    redirect('requests.php?new=1');
}

$new = isset($_GET['new']) && has_role('requester');
$st = (string)($_GET['status'] ?? ($isStore ? 'open' : ''));
$where = ['1=1']; $p = [];
if (!$isStore) { $where[] = 'r.requester_id = ?'; $p[] = $u['id']; }
if ($st === 'open') $where[] = "r.status IN ('submitted','partially_issued')";
elseif (isset(SR_STATUS[$st])) { $where[] = 'r.status = ?'; $p[] = $st; }
$rows = q('SELECT r.*, u.name AS requester, w.name AS wh, (SELECT COUNT(*) FROM stock_request_lines l WHERE l.request_id = r.id) AS n
           FROM stock_requests r JOIN users u ON u.id = r.requester_id JOIN warehouses w ON w.id = r.warehouse_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC LIMIT 300', $p)->fetchAll();

render_header('Stock requests');
if ($new):
    // items with stock, per warehouse, for the picker
    $avail = q('SELECT st.warehouse_id, st.item_id, st.qty, i.code, i.description, i.unit FROM stock st JOIN items i ON i.id = st.item_id WHERE st.qty > 0 ORDER BY i.code')->fetchAll(); ?>
<form method="post" class="card" id="srf">
  <?= csrf_field() ?>
  <div class="row">
    <div><label>From warehouse</label><select name="warehouse_id" id="wh" required><option value="">— select —</option><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Needed by</label><input type="date" name="needed_by"></div>
  </div>
  <div class="row">
    <div><label>Issue to (person receiving the items)</label><input type="text" name="issue_to" value="<?= e($u['name']) ?>"></div>
    <div><label>Destination / site / project</label><input type="text" name="destination" placeholder="e.g. Team 3, Kalobeyei site"></div>
  </div>
  <label>Purpose</label><input type="text" name="purpose" maxlength="500">
  <h2 style="margin-top:16px">Items</h2>
  <p class="muted" id="hint">Choose the warehouse first: only items in stock there are listed.</p>
  <div id="rows"></div>
  <div class="actions"><button type="button" class="btn sec small" onclick="addRow()">+ Add item</button></div>
  <div class="actions"><button class="btn">Send request</button><a href="requests.php">Cancel</a></div>
</form>
<script>
const AV = <?= json_encode(array_map(fn($a) => ['w' => (int)$a['warehouse_id'], 'id' => (int)$a['item_id'], 'q' => (float)$a['qty'], 'l' => $a['code'] . ' – ' . $a['description'], 'u' => $a['unit']], $avail), JSON_HEX_TAG) ?>;
function opts() { const w = parseInt(document.getElementById('wh').value || 0);
  return '<option value="">— item —</option>' + AV.filter(a => a.w === w).map(a => `<option value="${a.id}" data-q="${a.q}" data-u="${a.u}">${a.l.replace(/</g,'&lt;')} (${a.q} ${a.u} in stock)</option>`).join(''); }
function addRow() { const d = document.createElement('div'); d.className = 'row'; d.style.alignItems = 'flex-end';
  d.innerHTML = '<div style="flex:3"><select name="item[]">' + opts() + '</select></div><div><input type="number" name="qty[]" min="0" step="any" placeholder="Quantity"></div><div style="flex:0"><button type="button" class="btn sec small" onclick="this.closest(\'.row\').remove()">✕</button></div>';
  document.getElementById('rows').appendChild(d); }
document.getElementById('wh').addEventListener('change', () => { document.getElementById('rows').innerHTML = ''; addRow(); document.getElementById('hint').style.display = 'none'; });
</script>
<?php else: ?>
<div class="actions" style="margin:0 0 12px">
  <?php if (has_role('requester')): ?><a class="btn" href="?new=1">+ New stock request</a><?php endif; ?>
  <form method="get" style="margin-left:auto"><select name="status" onchange="this.form.submit()">
    <option value="open" <?= $st === 'open' ? 'selected' : '' ?>>Open</option><option value="" <?= $st === '' ? 'selected' : '' ?>>All</option>
    <?php foreach (SR_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></form>
</div>
<div class="table-wrap"><table>
  <tr><th>Request</th><th>From</th><th>Issue to · destination</th><th>Needed by</th><th>Status</th></tr>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted">No stock requests.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr><td><a href="request_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['number']) ?></b></a><br><span class="muted"><?= e($r['requester']) ?> · <?= (int)$r['n'] ?> item(s)</span></td>
    <td><?= e($r['wh']) ?></td><td><?= e($r['issue_to']) ?><?= $r['destination'] ? '<br><span class="muted">' . e($r['destination']) . '</span>' : '' ?></td>
    <td><?= $r['needed_by'] ? e(date('d M Y', strtotime($r['needed_by']))) : '—' ?></td><td><span class="tag <?= SR_STATUS[$r['status']][1] ?>"><?= SR_STATUS[$r['status']][0] ?></span></td></tr>
  <?php endforeach; ?>
</table></div>
<?php endif;
render_footer();
