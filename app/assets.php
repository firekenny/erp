<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stock.php';
require_any(['storekeeper', 'procurement', 'admin']);
$canEdit = has_role('storekeeper') || has_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $id = (int)($_POST['id'] ?? 0);
    $a = q('SELECT * FROM assets WHERE id = ?', [$id])->fetch();
    if ($a) {
        $sn = mb_substr(trim((string)($_POST['serial_no'] ?? '')), 0, 120);
        if ($sn !== '' && q('SELECT code FROM assets WHERE serial_no = ? AND item_id = ? AND id <> ?', [$sn, $a['item_id'], $id])->fetchColumn()) { flash("Serial $sn is already on another asset.", 'err'); redirect("assets.php?edit=$id"); }
        q('UPDATE assets SET serial_no = ?, brand_model = ?, cond = ?, notes = ? WHERE id = ?',
          [$sn, mb_substr(trim((string)($_POST['brand_model'] ?? '')), 0, 190), array_key_exists($_POST['cond'] ?? '', ASSET_COND) ? $_POST['cond'] : $a['cond'], trim((string)($_POST['notes'] ?? '')), $id]);
        audit('asset.edit', $a['code']);
        flash('Asset ' . $a['code'] . ' updated.');
    }
    redirect('assets.php');
}

$s = trim((string)($_GET['s'] ?? '')); $fc = (int)($_GET['c'] ?? 0); $miss = !empty($_GET['missing']);
$where = ['1=1']; $p = [];
if ($s !== '') { $where[] = '(a.code LIKE ? OR a.description LIKE ? OR a.serial_no LIKE ? OR a.brand_model LIKE ?)'; array_push($p, "%$s%", "%$s%", "%$s%", "%$s%"); }
if ($fc) { $where[] = 'a.category_id = ?'; $p[] = $fc; }
if ($miss) $where[] = "a.serial_no = ''";
$rows = q('SELECT a.*, po.number AS po_number, s.name AS supplier_name FROM assets a LEFT JOIN po ON po.id = a.po_id LEFT JOIN suppliers s ON s.id = a.supplier_id
           WHERE ' . implode(' AND ', $where) . ' ORDER BY a.code LIMIT 500', $p)->fetchAll();
$cats = q('SELECT DISTINCT c.id, c.code, c.name FROM categories c JOIN assets a ON a.category_id = c.id ORDER BY c.code')->fetchAll();
$codes = fin_code_map();
$edit = isset($_GET['edit']) ? q('SELECT a.*, po.number AS po_number FROM assets a LEFT JOIN po ON po.id = a.po_id WHERE a.id = ?', [(int)$_GET['edit']])->fetch() : null;
$nMissing = (int)q("SELECT COUNT(*) FROM assets WHERE serial_no = ''")->fetchColumn();

render_header('Asset register');
if ($edit): ?>
<div class="card">
  <h2><?= e($edit['code']) ?> · <?= e($edit['description']) ?></h2>
  <p class="muted">Bought <?= $edit['purchase_date'] ? e(date('d M Y', strtotime($edit['purchase_date']))) : '' ?> for <?= number_format((float)$edit['purchase_value'], 2) ?> <?= e($edit['currency']) ?> on <?= e($edit['po_number'] ?? '—') ?> ·
    funded by <?= e(implode(' / ', array_filter([$codes[(int)$edit['fin1_id']] ?? '', $codes[(int)$edit['fin2_id']] ?? '', $codes[(int)$edit['fin3_id']] ?? '']))) ?> · location <?= e($edit['location']) ?></p>
  <?php if ($canEdit): ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <div class="row">
      <div><label>Serial number</label><input type="text" name="serial_no" value="<?= e($edit['serial_no']) ?>" autofocus></div>
      <div><label>Brand / model</label><input type="text" name="brand_model" value="<?= e($edit['brand_model']) ?>"></div>
      <div><label>Condition</label><select name="cond"><?php foreach (ASSET_COND as $k => $l): ?><option value="<?= $k ?>" <?= $edit['cond'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <label>Notes</label><textarea name="notes" rows="2"><?= e($edit['notes']) ?></textarea>
    <div class="actions"><button class="btn">Save</button><a href="assets.php">Back to register</a></div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<form method="get" class="row" style="margin-bottom:10px;align-items:flex-end">
  <div style="flex:2"><input type="text" name="s" value="<?= e($s) ?>" placeholder="Search asset ID, description, serial, brand"></div>
  <div><select name="c"><option value="0">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $fc === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' – ' . $c['name']) ?></option><?php endforeach; ?></select></div>
  <label class="checks" style="flex:0;white-space:nowrap;font-weight:400"><input type="checkbox" name="missing" value="1" <?= $miss ? 'checked' : '' ?>> Missing serial (<?= $nMissing ?>)</label>
  <div style="flex:0"><button class="btn sec">Filter</button></div>
</form>
<div class="table-wrap"><table>
  <tr><th>Asset ID</th><th>Description</th><th>Serial</th><th>Location</th><th>Funding</th><th style="text-align:right">Value</th><th>Status</th></tr>
  <?php if (!$rows): ?><tr><td colspan="7" class="muted">No assets yet. Assets are registered automatically when asset items are received.</td></tr><?php endif; ?>
  <?php foreach ($rows as $a): ?>
  <tr><td><a href="?edit=<?= (int)$a['id'] ?>"><b><?= e($a['code']) ?></b></a></td><td><?= e($a['description']) ?><?php if ($a['brand_model']): ?><br><span class="muted"><?= e($a['brand_model']) ?></span><?php endif; ?></td>
    <td><?= $a['serial_no'] !== '' ? e($a['serial_no']) : '<span class="tag warn">missing</span>' ?></td><td><?= e($a['location']) ?><?= $a['custodian'] ? '<br><span class="muted">' . e($a['custodian']) . '</span>' : '' ?></td>
    <td class="muted"><?= e(implode(' / ', array_filter([$codes[(int)$a['fin1_id']] ?? '', $codes[(int)$a['fin2_id']] ?? '']))) ?></td>
    <td style="text-align:right;white-space:nowrap"><?= number_format((float)$a['purchase_value'], 2) ?> <?= e($a['currency']) ?></td>
    <td><span class="tag <?= ASSET_STATUS[$a['status']][1] ?>"><?= ASSET_STATUS[$a['status']][0] ?></span></td></tr>
  <?php endforeach; ?>
</table></div>
<p class="muted">Movements, handover to staff with signature, physical counts and depreciation come in a later build step.</p>
<?php render_footer();
