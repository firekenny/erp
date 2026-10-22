<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';
$u = require_any(['storekeeper', 'procurement', 'admin']);
$canEdit = has_role('storekeeper') || has_role('admin');
const DISPOSAL_METHODS = ['Sold', 'Donated to partner', 'Transferred to another programme', 'Scrapped / destroyed', 'Written off'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$a = asset_get($id);
if (!$a) { flash('Asset not found.', 'err'); redirect('assets.php'); }
$back = fn() => redirect("asset_view.php?id=$id");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $act = $_POST['act'] ?? '';
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
    if ($act === 'edit') {
        $sn = mb_substr(trim((string)($_POST['serial_no'] ?? '')), 0, 120);
        if ($sn !== '' && q('SELECT code FROM assets WHERE serial_no = ? AND id <> ? AND (item_id <=> ? OR description = ?)', [$sn, $id, $a['item_id'], $a['description']])->fetchColumn()) { flash("Serial $sn is already on another asset of this type.", 'err'); $back(); }
        $cond = array_key_exists($_POST['cond'] ?? '', ASSET_COND) ? $_POST['cond'] : $a['cond'];
        $changes = [];
        foreach (['serial_no' => $sn, 'brand_model' => mb_substr(trim((string)($_POST['brand_model'] ?? '')), 0, 190), 'cond' => $cond] as $k => $v) if ((string)$a[$k] !== (string)$v) $changes[] = "$k: " . ($a[$k] ?: '—') . " → $v";
        q('UPDATE assets SET serial_no = ?, brand_model = ?, cond = ?, notes = ? WHERE id = ?', [$sn, mb_substr(trim((string)($_POST['brand_model'] ?? '')), 0, 190), $cond, trim((string)($_POST['notes'] ?? '')), $id]);
        if ($changes) asset_event($id, 'edited', implode('; ', $changes));
        flash('Saved.'); $back();
    }
    if (in_array($a['status'], ['disposed', 'lost'], true) && $act !== 'found') { flash('This asset is no longer in service.', 'err'); $back(); }
    if ($act === 'repair') { q("UPDATE assets SET status = 'in_repair' WHERE id = ?", [$id]); asset_event($id, 'sent to repair', $note); flash('Marked as in repair.'); $back(); }
    if ($act === 'repaired') { q('UPDATE assets SET status = ? WHERE id = ?', [$a['employee_id'] ? 'in_use' : 'in_store', $id]); asset_event($id, 'back from repair', $note); flash('Back in service.'); $back(); }
    if ($act === 'lost') {
        if ($note === '') { flash('Describe what happened (date, place, report reference).', 'err'); $back(); }
        q("UPDATE assets SET status = 'lost' WHERE id = ?", [$id]); asset_event($id, 'reported lost/stolen', $note . ($a['emp_name'] ? " · was held by {$a['emp_name']}" : '')); flash('Reported as lost. Inform the donor if required by the grant.', 'warn'); $back();
    }
    if ($act === 'found' && $a['status'] === 'lost') { q("UPDATE assets SET status = 'in_store' WHERE id = ?", [$id]); asset_event($id, 'found', $note); flash('Asset back in the register (in store).'); $back(); }
    if ($act === 'dispose' && has_role('admin')) {
        $m = in_array($_POST['method'] ?? '', DISPOSAL_METHODS, true) ? $_POST['method'] : '';
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['date'] ?? '')) ? $_POST['date'] : '';
        if (!$m || !$d || $note === '') { flash('Method, date and justification are required (and the donor approval reference if the grant requires one).', 'err'); $back(); }
        $val = trim((string)($_POST['value'] ?? '')) === '' ? null : (float)str_replace(',', '.', (string)$_POST['value']);
        q("UPDATE assets SET status = 'disposed', disposed_at = ?, disposal_method = ?, disposal_value = ?, disposal_note = ?, employee_id = NULL WHERE id = ?", [$d, $m, $val, $note, $id]);
        asset_event($id, 'disposed', "$m on $d" . ($val !== null ? ' for ' . number_format($val, 2) : '') . " · $note");
        audit('asset.dispose', $a['code']); flash('Asset disposed. It stays in the register for audit.'); $back();
    }
    $back();
}

$events = q('SELECT ev.*, u.name AS uname FROM asset_events ev LEFT JOIN users u ON u.id = ev.user_id WHERE ev.asset_id = ? ORDER BY ev.id DESC', [$id])->fetchAll();
$codes = fin_code_map();
$active = !in_array($a['status'], ['disposed', 'lost'], true);
render_header($a['code']); ?>
<div class="actions noprint" style="margin:-6px 0 12px"><span class="tag <?= ASSET_STATUS[$a['status']][1] ?>"><?= ASSET_STATUS[$a['status']][0] ?></span>
  <?php if ($canEdit && $active): ?>
    <a class="btn small" href="handover.php?type=handover&a[]=<?= $id ?>">Hand over</a><a class="btn sec small" href="handover.php?type=move&a[]=<?= $id ?>">Move</a>
    <?php if ($a['employee_id']): ?><a class="btn sec small" href="handover.php?type=return&a[]=<?= $id ?>">Return to store</a><?php endif; ?>
  <?php endif; ?>
  <a href="assets.php" style="margin-left:auto">Back to register</a></div>
<div class="card">
  <h2 style="margin-bottom:4px"><?= e($a['description']) ?></h2>
  <div class="row" style="margin-top:10px">
    <div><span class="muted">Category</span><br><?= e($a['cat_code'] . ' – ' . $a['cat_name']) ?></div>
    <div><span class="muted">Where · held by</span><br><b><?= e(asset_where($a['loc_name'] ?? $a['location'], $a['emp_name'])) ?: '—' ?></b></div>
    <div><span class="muted">Bought</span><br><?= $a['purchase_date'] ? e(date('d M Y', strtotime($a['purchase_date']))) : '—' ?> · <?= number_format((float)$a['purchase_value'], 2) ?> <?= e($a['currency']) ?></div>
    <div><span class="muted">Supplier · PO</span><br><?= e($a['supplier_name'] ?? '—') ?><?= $a['po_id'] ? ' · <a href="po_view.php?id=' . (int)$a['po_id'] . '">' . e($a['po_number']) . '</a>' : '' ?></div>
    <div><span class="muted">Funding</span><br><?= e(fin_label($a, $codes)) ?: '—' ?></div>
    <?php if (setting('dep_method', 'none') !== 'none'): $dep = asset_depreciation($a, date('Y-m-d'), q('SELECT useful_life, residual_pct FROM categories WHERE id = ?', [$a['category_id']])->fetch() ?: []); ?>
    <div><span class="muted">Net book value today</span><br><b><?= number_format($dep['nbv'], 2) ?></b> <?= e($a['currency']) ?><?= $dep['note'] ? ' <span class="tag warn">' . e($dep['note']) . '</span>' : '' ?></div><?php endif; ?>
  </div>
  <?php if ($a['status'] === 'disposed'): ?><p class="flash warn" style="margin:10px 0 0">Disposed on <?= e(date('d M Y', strtotime($a['disposed_at']))) ?>: <?= e($a['disposal_method']) ?><?= $a['disposal_value'] !== null ? ' · ' . number_format((float)$a['disposal_value'], 2) : '' ?> · <?= e($a['disposal_note']) ?></p><?php endif; ?>
</div>
<?php if ($canEdit): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="edit">
  <div class="row"><div><label>Serial number</label><input type="text" name="serial_no" value="<?= e($a['serial_no']) ?>"></div><div><label>Brand / model</label><input type="text" name="brand_model" value="<?= e($a['brand_model']) ?>"></div>
    <div><label>Condition</label><select name="cond"><?php foreach (ASSET_COND as $k => $l): ?><option value="<?= $k ?>" <?= $a['cond'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div></div>
  <label>Notes</label><textarea name="notes" rows="2"><?= e($a['notes']) ?></textarea>
  <div class="actions"><button class="btn sec">Save details</button></div></form>
<div class="card">
  <h2>Change status</h2>
  <form method="post" class="row" style="align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <div style="flex:3"><label>Comment</label><input type="text" name="note" placeholder="What happened, report or repair reference…"></div>
    <div style="flex:0;white-space:nowrap">
      <?php if ($a['status'] === 'in_repair'): ?><button class="btn sec small" name="act" value="repaired">Back from repair</button>
      <?php elseif ($active): ?><button class="btn sec small" name="act" value="repair">Send to repair</button><?php endif; ?>
      <?php if ($active): ?><button class="btn sec small" name="act" value="lost" onclick="return confirm('Report this asset as lost or stolen?')">Report lost / stolen</button><?php endif; ?>
      <?php if ($a['status'] === 'lost'): ?><button class="btn sec small" name="act" value="found">Found again</button><?php endif; ?>
    </div></form>
  <?php if (has_role('admin') && $active): ?>
  <details style="margin-top:12px"><summary>Dispose of this asset (Administrator)</summary>
    <form method="post" style="margin-top:8px" onsubmit="return confirm('Dispose of this asset? This cannot be undone.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="dispose">
      <div class="row"><div><label>Method</label><select name="method" required><option value="">—</option><?php foreach (DISPOSAL_METHODS as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
        <div><label>Date</label><input type="date" name="date" value="<?= date('Y-m-d') ?>" required></div><div><label>Sale value (if any)</label><input type="number" step="0.01" min="0" name="value"></div></div>
      <label>Justification and donor approval reference</label><input type="text" name="note" required>
      <div class="actions"><button class="btn danger">Dispose</button></div></form></details>
  <?php endif; ?>
</div>
<?php endif; ?>
<div class="card">
  <h2>History</h2>
  <table><?php foreach ($events as $ev): ?><tr><td style="width:150px;white-space:nowrap" class="muted"><?= e(date('d M Y H:i', strtotime($ev['created_at']))) ?></td>
    <td><b><?= e(ucfirst($ev['event'])) ?></b> <?= e($ev['details']) ?><?= $ev['ref_type'] === 'handover' ? ' · <a href="handover_view.php?id=' . (int)$ev['ref_id'] . '">document</a>' : ($ev['ref_type'] === 'grn' ? ' · <a href="grn_view.php?id=' . (int)$ev['ref_id'] . '">GRN</a>' : '') ?>
      <?= $ev['uname'] ? '<span class="muted"> · ' . e($ev['uname']) . '</span>' : '' ?></td></tr><?php endforeach; ?>
    <?php if (!$events): ?><tr><td class="muted">No history yet.</td></tr><?php endif; ?></table>
</div>
<?php render_footer();
