<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('admin');

const TYPES = ['stock' => 'Stock', 'asset' => 'Asset', 'service' => 'Service'];

if (isset($_GET['template'])) send_csv('template_items.csv', ['code', 'description', 'category_code', 'unit', 'type'],
    [['', 'Laptop 14 inch i5 16GB', 'IT', 'pcs', ''], ['', 'A4 paper 80g, ream of 500', 'STAT', 'ream', 'stock']]);

/** Next free code for a category: CAT-0001, CAT-0002 … */
function next_item_code(array $cat): string {
    $prefix = $cat['code'] . '-';
    $max = (int)q('SELECT MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)) FROM items WHERE code LIKE ?', [strlen($prefix) + 1, $prefix . '%'])->fetchColumn();
    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

$cats = q('SELECT * FROM categories WHERE active = 1 ORDER BY code')->fetchAll();
$catById = array_column($cats, null, 'id');
$catByCode = array_column($cats, null, 'code');

if (isset($_GET['export'])) {
    $rows = q('SELECT i.code, i.description, c.code, i.unit, i.item_type, i.active FROM items i JOIN categories c ON c.id = i.category_id ORDER BY i.code')->fetchAll(PDO::FETCH_NUM);
    send_csv('item_catalogue.csv', ['code', 'description', 'category_code', 'unit', 'type', 'active'], $rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $cat  = $catById[(int)($_POST['category_id'] ?? 0)] ?? null;
        $desc = trim((string)($_POST['description'] ?? ''));
        $unit = trim((string)($_POST['unit'] ?? '')) ?: 'pcs';
        $type = array_key_exists($_POST['item_type'] ?? '', TYPES) ? $_POST['item_type'] : ($cat['default_type'] ?? 'stock');
        $code = strtoupper(trim((string)($_POST['code'] ?? '')));
        if (!$cat || $desc === '') { flash('Category and description are required.', 'err'); redirect('items.php'); }
        if ($code === '') $code = $id ? (string)q('SELECT code FROM items WHERE id = ?', [$id])->fetchColumn() : next_item_code($cat);
        if (q('SELECT id FROM items WHERE code = ? AND id <> ?', [$code, $id])->fetch()) { flash("Item code $code already exists.", 'err'); redirect('items.php'); }
        if ($id) { q('UPDATE items SET code = ?, description = ?, category_id = ?, unit = ?, item_type = ? WHERE id = ?', [$code, $desc, $cat['id'], $unit, $type, $id]); audit('item.edit', $code); flash("Item $code updated."); }
        else { q('INSERT INTO items (code, description, category_id, unit, item_type) VALUES (?, ?, ?, ?, ?)', [$code, $desc, $cat['id'], $unit, $type]); audit('item.add', $code); flash("Item $code added."); }
    } elseif ($act === 'toggle') {
        q('UPDATE items SET active = 1 - active WHERE id = ?', [(int)$_POST['id']]);
        flash('Status changed.');
    } elseif ($act === 'import') {
        $rows = read_csv_upload('file'); $a = $u = 0; $errs = [];
        foreach ($rows as $i => $r) {
            $line = $i + 2;
            $cat = $catByCode[strtoupper(trim($r['category_code'] ?? ''))] ?? null;
            $desc = trim($r['description'] ?? '');
            if (!$cat || $desc === '') { $errs[] = "Row $line" . (!$cat ? ' (unknown category ' . ($r['category_code'] ?? '') . ')' : ' (no description)'); continue; }
            $type = strtolower(trim($r['type'] ?? ''));
            if (!array_key_exists($type, TYPES)) $type = $cat['default_type'];
            $unit = trim($r['unit'] ?? '') ?: 'pcs';
            $code = strtoupper(trim($r['code'] ?? ''));
            if ($code !== '' && q('SELECT id FROM items WHERE code = ?', [$code])->fetch()) {
                q('UPDATE items SET description = ?, category_id = ?, unit = ?, item_type = ?, active = 1 WHERE code = ?', [$desc, $cat['id'], $unit, $type, $code]); $u++;
            } else {
                if ($code === '') $code = next_item_code($cat);
                q('INSERT INTO items (code, description, category_id, unit, item_type) VALUES (?, ?, ?, ?, ?)', [$code, $desc, $cat['id'], $unit, $type]); $a++;
            }
        }
        audit('item.import', "+$a ~$u");
        flash($rows ? "Import done: $a added, $u updated." : 'No rows found in the file.', $rows ? 'ok' : 'err');
        if ($errs) flash(count($errs) . ' row(s) skipped: ' . implode(' · ', array_slice($errs, 0, 10)), 'warn');
    }
    redirect('items.php');
}

/* ---------- List with filters ---------- */
$s = trim((string)($_GET['s'] ?? '')); $fc = (int)($_GET['c'] ?? 0); $ft = (string)($_GET['t'] ?? '');
$where = ['1=1']; $p = [];
if ($s !== '') { $where[] = '(i.code LIKE ? OR i.description LIKE ?)'; $p[] = "%$s%"; $p[] = "%$s%"; }
if ($fc) { $where[] = 'i.category_id = ?'; $p[] = $fc; }
if (isset(TYPES[$ft])) { $where[] = 'i.item_type = ?'; $p[] = $ft; }
$items = q('SELECT i.*, c.code AS c_code, c.name AS c_name FROM items i JOIN categories c ON c.id = i.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY i.active DESC, i.code LIMIT 500', $p)->fetchAll();
$edit = isset($_GET['edit']) ? q('SELECT * FROM items WHERE id = ?', [(int)$_GET['edit']])->fetch() : null;

render_header('Item catalogue');
if (!$cats): ?><div class="flash warn">Create at least one <a href="categories.php">item category</a> first.</div><?php endif; ?>
<div class="card">
  <h2><?= $edit ? 'Edit item' : 'Add an item' ?></h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="row">
      <div><label>Category</label>
        <select name="category_id" required onchange="var o=this.options[this.selectedIndex];var t=this.form.item_type;if(!t.dataset.touched)t.value=o.dataset.type||'stock'">
          <option value="">— select —</option>
          <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" data-type="<?= e($c['default_type']) ?>" <?= (int)($edit['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' – ' . $c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div style="flex:2"><label>Description</label><input type="text" name="description" value="<?= e($edit['description'] ?? '') ?>" required></div>
    </div>
    <div class="row">
      <div><label>Unit</label><input type="text" name="unit" value="<?= e($edit['unit'] ?? 'pcs') ?>"></div>
      <div><label>Type</label>
        <select name="item_type" onchange="this.dataset.touched=1">
          <?php foreach (TYPES as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['item_type'] ?? 'stock') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Item code (leave empty = automatic)</label><input type="text" name="code" value="<?= e($edit['code'] ?? '') ?>"></div>
    </div>
    <div class="actions"><button class="btn" <?= $cats ? '' : 'disabled' ?>><?= $edit ? 'Save changes' : 'Add item' ?></button><?php if ($edit): ?><a href="items.php">Cancel</a><?php endif; ?></div>
  </form>
  <details style="margin-top:14px"><summary>Import from Excel</summary>
    <p class="muted"><a href="?template=1">Download the template</a>, fill it, save as <b>CSV UTF-8</b>, upload.
    category_code must match a category. Leave <b>code</b> empty for automatic numbering and <b>type</b> empty to use the category default (stock / asset / service).</p>
    <form method="post" enctype="multipart/form-data" class="row" style="align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="act" value="import">
      <div><input type="file" name="file" accept=".csv,text/csv" required></div><div style="flex:0"><button class="btn sec">Import</button></div></form>
  </details>
</div>

<form method="get" class="row" style="margin-bottom:10px;align-items:flex-end">
  <div style="flex:2"><input type="text" name="s" value="<?= e($s) ?>" placeholder="Search code or description"></div>
  <div><select name="c"><option value="0">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $fc === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code']) ?></option><?php endforeach; ?></select></div>
  <div><select name="t"><option value="">All types</option><?php foreach (TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $ft === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div style="flex:0"><button class="btn sec">Filter</button></div>
</form>
<div class="table-wrap"><table>
  <tr><th>Code</th><th>Description</th><th>Category</th><th>Unit</th><th>Type</th><th>Status</th><th></th></tr>
  <?php if (!$items): ?><tr><td colspan="7" class="muted">No items.</td></tr><?php endif; ?>
  <?php foreach ($items as $i): ?>
  <tr>
    <td><b><?= e($i['code']) ?></b></td><td><?= e($i['description']) ?></td><td><?= e($i['c_code']) ?></td><td><?= e($i['unit']) ?></td>
    <td><span class="tag <?= e($i['item_type']) ?>"><?= e(TYPES[$i['item_type']]) ?></span></td>
    <td><?= $i['active'] ? '<span class="tag stock">Active</span>' : '<span class="tag off">Inactive</span>' ?></td>
    <td style="white-space:nowrap"><a class="btn sec small" href="?edit=<?= (int)$i['id'] ?>">Edit</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="btn sec small"><?= $i['active'] ? 'Deactivate' : 'Reactivate' ?></button></form></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<p class="muted"><a href="?export=1">Export the catalogue to CSV</a>. <b>Service</b> items (e.g. repairs, rental) are never received into stock or assets.</p>
<?php render_footer();
