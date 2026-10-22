<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pr.php';
require_once __DIR__ . '/inc/budget.php';
$u = require_role('requester');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$pr = $id ? pr_get($id) : null;
if ($id && (!$pr || !pr_can_edit($pr, $u))) { flash('This Purchase Request can no longer be edited.', 'err'); redirect($pr ? 'pr_view.php?id=' . $id : 'pr.php'); }

$tree = fin_tree();

/* ---------- Save / submit ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim((string)($_POST['title'] ?? ''));
    $needed = (string)($_POST['needed_by'] ?? '');
    $needed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $needed) ? $needed : null;
    $loc = trim((string)($_POST['delivery_location'] ?? ''));
    $just = trim((string)($_POST['justification'] ?? ''));
    $submit = ($_POST['go'] ?? '') === 'submit';

    $lines = [];
    foreach ((array)($_POST['lines'] ?? []) as $l) {
        $itemId = (int)($l['item_id'] ?? 0);
        if (!$itemId && trim((string)($l['qty'] ?? '')) === '') continue;      // empty row
        $fin = [];
        foreach ($tree as $lv) $fin[$lv['pos']] = (int)($l['fin'][$lv['pos']] ?? 0);
        $lines[] = [
            'item_id' => $itemId,
            'specification' => mb_substr(trim((string)($l['spec'] ?? '')), 0, 500),
            'qty' => (float)str_replace(',', '.', (string)($l['qty'] ?? '0')),
            'unit_price' => (float)str_replace([',', ' '], ['.', ''], (string)($l['price'] ?? '0')),
            'fin' => $fin,
        ];
    }

    $errs = [];
    if ($title === '') $errs[] = 'Give the request a title.';
    $errs = array_merge($errs, pr_validate_lines($lines, false));
    if ($errs && !$lines) { flash(implode(' ', $errs), 'err'); redirect('pr_edit.php' . ($id ? "?id=$id" : '')); }

    // Always save what we have as a draft first, so nothing typed is lost
    db()->beginTransaction();
    if (!$pr) {
        q('INSERT INTO pr (number, requester_id, title, needed_by, delivery_location, justification, currency) VALUES (?, ?, ?, ?, ?, ?, ?)',
          [pr_next_number(), $u['id'], $title ?: 'Untitled request', $needed, $loc, $just, setting('currency', 'USD')]);
        $id = (int)db()->lastInsertId();
        pr_log($id, 'created');
    } else {
        q('UPDATE pr SET title = ?, needed_by = ?, delivery_location = ?, justification = ? WHERE id = ?', [$title ?: $pr['title'], $needed, $loc, $just, $id]);
    }
    q('DELETE FROM pr_lines WHERE pr_id = ?', [$id]);
    $units = array_column(q('SELECT id, unit FROM items')->fetchAll(), 'unit', 'id');
    foreach ($lines as $n => $l) {
        if (!isset($units[$l['item_id']])) continue;
        q('INSERT INTO pr_lines (pr_id, line_no, item_id, specification, qty, unit, unit_price, fin1_id, fin2_id, fin3_id, fin4_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$id, $n + 1, $l['item_id'], $l['specification'], $l['qty'], $units[$l['item_id']], $l['unit_price'],
           $l['fin'][1] ?? null ?: null, $l['fin'][2] ?? null ?: null, $l['fin'][3] ?? null ?: null, $l['fin'][4] ?? null ?: null]);
    }
    db()->commit();

    if (!$submit) {
        if ($errs) flash('Draft saved, but check: ' . implode(' ', $errs), 'warn'); else flash('Draft saved.');
        redirect("pr_edit.php?id=$id");
    }

    // Submission checks
    $errs = array_merge($errs, pr_validate_lines($lines, true));
    if ($needed && $needed < date('Y-m-d')) $errs[] = 'The "needed by" date is in the past.';
    [$route, $rErrs] = $errs ? [[], []] : pr_route($lines);
    $errs = array_merge($errs, $rErrs);
    if ($errs) {
        flash('Saved as draft but not submitted: ' . implode(' ', array_slice($errs, 0, 8)), 'err');
        redirect("pr_edit.php?id=$id");
    }
    db()->beginTransaction();
    q('DELETE FROM pr_approvals WHERE pr_id = ?', [$id]);
    $selfApproval = false;
    foreach ($route as $r) {
        q('INSERT INTO pr_approvals (pr_id, approver_id, fin_value_id, stage, level_name) VALUES (?, ?, ?, ?, ?)', [$id, $r['approver_id'], $r['fin_value_id'], $r['stage'], $r['level_name']]);
        if ($r['approver_id'] === (int)$u['id']) $selfApproval = true;
    }
    q("UPDATE pr SET status = 'submitted', submitted_at = NOW() WHERE id = ?", [$id]);
    pr_log($id, 'submitted');
    db()->commit();
    $names = q('SELECT DISTINCT u.name FROM pr_approvals a JOIN users u ON u.id = a.approver_id WHERE a.pr_id = ? AND a.stage = 1', [$id])->fetchAll(PDO::FETCH_COLUMN);
    $higher = q('SELECT a.level_name, u.name FROM pr_approvals a JOIN users u ON u.id = a.approver_id WHERE a.pr_id = ? AND a.stage > 1 ORDER BY a.stage', [$id])->fetchAll();
    flash('Submitted for approval to ' . implode(', ', $names) . '.'
        . ($higher ? ' Because of the amount, it then goes to ' . implode(', then ', array_map(fn($h) => $h['name'] . ' (' . $h['level_name'] . ')', $higher)) . '.' : '') . ($selfApproval ? ' You are the Budget Holder for part of it, so an Administrator will approve that part.' : ''));
    if (budget_is_over(budget_check_lines(pr_lines($id)))) flash('Note: this request is over the budget left (see the budget check below). Your approvers will see it and must justify approving it.', 'warn');
    notify_pr_approvers($id);
    redirect("pr_view.php?id=$id");
}

/* ---------- Form ---------- */
$lines = $pr ? pr_lines($id) : [];
$items = q("SELECT i.id, i.code, i.description, i.unit, i.item_type FROM items i JOIN categories c ON c.id = i.category_id
            WHERE i.active = 1 AND c.active = 1 ORDER BY i.code")->fetchAll();
$jsLines = array_map(fn($l) => [
    'item_id' => (int)$l['item_id'], 'spec' => $l['specification'], 'qty' => (float)$l['qty'], 'price' => (float)$l['unit_price'],
    'fin' => array_filter([1 => (int)$l['fin1_id'], 2 => (int)$l['fin2_id'], 3 => (int)$l['fin3_id'], 4 => (int)$l['fin4_id']]),
], $lines);
$history = $pr ? q("SELECT h.*, u.name FROM pr_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.pr_id = ? AND h.action = 'returned' ORDER BY h.id DESC LIMIT 1", [$id])->fetch() : null;

render_header($pr ? 'Edit ' . $pr['number'] : 'New Purchase Request');
if (!$tree): ?><div class="flash warn">No finance codes are set up yet. Ask your Administrator to complete the Finance codes page.</div><?php endif;
if ($pr && $pr['status'] === 'returned' && $history): ?>
  <div class="flash warn"><b>Returned by <?= e($history['name']) ?>:</b> <?= e($history['comment']) ?></div>
<?php endif; ?>

<form method="post" id="prf">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$id ?>">
  <div class="card">
    <div class="row">
      <div style="flex:2"><label>Title (what is this request for?)</label><input type="text" name="title" maxlength="200" value="<?= e($pr['title'] ?? '') ?>" required placeholder="e.g. Hygiene kits for Turkana distribution"></div>
      <div><label>Needed by</label><input type="date" name="needed_by" value="<?= e($pr['needed_by'] ?? '') ?>"></div>
    </div>
    <div class="row">
      <div><label>Delivery location</label><input type="text" name="delivery_location" maxlength="200" value="<?= e($pr['delivery_location'] ?? '') ?>" placeholder="e.g. Lodwar warehouse"></div>
    </div>
    <label>Justification</label>
    <textarea name="justification" rows="2" placeholder="Why is this needed?"><?= e($pr['justification'] ?? '') ?></textarea>
  </div>

  <h2>Lines</h2>
  <div id="lines"></div>
  <div class="actions" style="margin-top:0">
    <button type="button" class="btn sec" onclick="addLine()">+ Add line</button>
    <span style="margin-left:auto;font-size:16px">Estimated total: <b id="grand">0.00</b> <?= e(setting('currency', 'USD')) ?></span>
  </div>

  <div class="card" style="margin-top:16px">
    <div class="actions" style="margin:0">
      <button class="btn sec" name="go" value="save">Save draft</button>
      <button class="btn" name="go" value="submit" onclick="return confirm('Submit this request for approval?')">Submit for approval</button>
      <a href="<?= $pr ? 'pr_view.php?id=' . $id : 'pr.php' ?>">Cancel</a>
    </div>
  </div>
</form>

<datalist id="itemlist">
  <?php foreach ($items as $i): ?><option value="<?= e($i['code'] . ' – ' . $i['description']) ?>"></option><?php endforeach; ?>
</datalist>

<template id="tpl">
  <div class="card line">
    <div class="row" style="align-items:flex-end">
      <div style="flex:3"><label>Item <span class="muted ln"></span></label><input type="text" class="item" list="itemlist" placeholder="Type to search the catalogue" autocomplete="off"><input type="hidden" class="item_id"></div>
      <div style="flex:0;min-width:auto"><button type="button" class="btn sec small rm" title="Remove line">✕</button></div>
    </div>
    <div class="muted itype" style="margin-top:4px"></div>
    <label>Specification / details</label><input type="text" class="spec" maxlength="500" placeholder="Size, model, colour, standard…">
    <div class="row">
      <div><label>Quantity <span class="muted unit"></span></label><input type="number" class="qty" min="0" step="any"></div>
      <div><label>Estimated unit price</label><input type="number" class="price" min="0" step="0.01"></div>
      <div><label>Line total</label><div class="lt" style="padding:8px 0;font-weight:600">0.00</div></div>
    </div>
    <div class="row fins"></div>
  </div>
</template>

<script>
const ITEMS = <?= json_encode(array_map(fn($i) => ['id' => (int)$i['id'], 'label' => $i['code'] . ' – ' . $i['description'], 'unit' => $i['unit'], 'type' => $i['item_type']], $items), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const FIN = <?= json_encode($tree, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const START = <?= json_encode($jsLines ?: [], JSON_HEX_TAG) ?>;
const TYPE_LABEL = {stock: 'Stock item: will be received into the warehouse', asset: 'Asset: will be registered individually in the asset register', service: 'Service: no goods to receive'};
let seq = 0;

function fmt(n) { return (Math.round(n * 100) / 100).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

function fillLevel(card, lv) {
  const sel = card.querySelector('select[data-pos="' + lv.pos + '"]');
  const keep = sel.value;
  let parent = null;
  if (lv.linked) parent = parseInt(card.querySelector('select[data-pos="' + (lv.pos - 1) + '"]').value || 0);
  sel.innerHTML = '<option value="">— ' + lv.name + ' —</option>';
  lv.values.filter(v => !lv.linked || (parent && v.p === parent)).forEach(v => {
    const o = document.createElement('option'); o.value = v.id; o.textContent = v.c + ' – ' + v.n; sel.appendChild(o);
  });
  sel.value = [...sel.options].some(o => o.value === keep) ? keep : '';
  sel.disabled = lv.linked && !parent;
}

function addLine(data) {
  const prev = [...document.querySelectorAll('#lines .line')].pop();
  const card = document.getElementById('tpl').content.firstElementChild.cloneNode(true);
  const i = seq++;
  const nm = (k) => 'lines[' + i + '][' + k + ']';
  card.querySelector('.item_id').name = nm('item_id');
  card.querySelector('.spec').name = nm('spec');
  card.querySelector('.qty').name = nm('qty');
  card.querySelector('.price').name = nm('price');
  const fins = card.querySelector('.fins');
  FIN.forEach(lv => {
    const d = document.createElement('div');
    d.innerHTML = '<label>' + lv.name + (lv.required ? '' : ' <span class="muted">(optional)</span>') + '</label>';
    const s = document.createElement('select'); s.dataset.pos = lv.pos; s.name = 'lines[' + i + '][fin][' + lv.pos + ']';
    s.addEventListener('change', () => FIN.filter(x => x.pos > lv.pos).forEach(x => fillLevel(card, x)));
    d.appendChild(s); fins.appendChild(d);
  });
  document.getElementById('lines').appendChild(card);
  // finance codes: from saved data, else copied from the line above
  const fin = data ? (data.fin || {}) : (prev ? Object.fromEntries([...prev.querySelectorAll('select[data-pos]')].map(s => [s.dataset.pos, s.value])) : {});
  FIN.forEach(lv => { fillLevel(card, lv); const s = card.querySelector('select[data-pos="' + lv.pos + '"]'); s.value = fin[lv.pos] || ''; });
  FIN.forEach(lv => fillLevel(card, lv));
  if (data) {
    const it = ITEMS.find(x => x.id === data.item_id);
    if (it) { card.querySelector('.item').value = it.label; card.querySelector('.item_id').value = it.id; }
    card.querySelector('.spec').value = data.spec || '';
    card.querySelector('.qty').value = data.qty || '';
    card.querySelector('.price').value = data.price || '';
  }
  card.querySelector('.item').addEventListener('input', () => pickItem(card));
  card.querySelector('.item').addEventListener('change', () => pickItem(card));
  card.querySelector('.qty').addEventListener('input', totals);
  card.querySelector('.price').addEventListener('input', totals);
  card.querySelector('.rm').addEventListener('click', () => { card.remove(); totals(); });
  pickItem(card); totals();
  if (!data) card.querySelector('.item').focus();
}

function pickItem(card) {
  const txt = card.querySelector('.item').value;
  const it = ITEMS.find(x => x.label === txt);
  card.querySelector('.item_id').value = it ? it.id : '';
  card.querySelector('.unit').textContent = it ? '(' + it.unit + ')' : '';
  card.querySelector('.itype').innerHTML = it ? '<span class="tag ' + it.type + '">' + it.type + '</span> ' + TYPE_LABEL[it.type] : (txt ? '<span style="color:#a12a20">Choose an item from the list</span>' : '');
}

function totals() {
  let g = 0;
  document.querySelectorAll('#lines .line').forEach((c, n) => {
    const t = (parseFloat(c.querySelector('.qty').value) || 0) * (parseFloat(c.querySelector('.price').value) || 0);
    c.querySelector('.lt').textContent = fmt(t); c.querySelector('.ln').textContent = '· line ' + (n + 1); g += t;
  });
  document.getElementById('grand').textContent = fmt(g);
}

// disabled selects are not sent with the form: re-enable just before sending
document.getElementById('prf').addEventListener('submit', () => document.querySelectorAll('#lines select').forEach(s => s.disabled = false));

if (START.length) START.forEach(l => addLine(l)); else addLine();
</script>
<?php render_footer();
