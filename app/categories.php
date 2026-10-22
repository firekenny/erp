<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('admin');

if (isset($_GET['template'])) send_csv('template_categories.csv', ['code', 'name', 'default_type'], [['IT', 'IT equipment', 'asset'], ['STAT', 'Stationery', 'stock']]);

function norm_type(string $t): string { return strtolower(trim($t)) === 'asset' ? 'asset' : 'stock'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['code'] ?? '')));
        $name = trim((string)($_POST['name'] ?? ''));
        $type = norm_type((string)($_POST['default_type'] ?? 'stock'));
        if ($code === '' || $name === '') flash('Code and name are required.', 'err');
        elseif (q('SELECT id FROM categories WHERE code = ? AND id <> ?', [$code, $id])->fetch()) flash("Code $code already exists.", 'err');
        elseif ($id) { q('UPDATE categories SET code = ?, name = ?, default_type = ? WHERE id = ?', [$code, $name, $type, $id]); audit('cat.edit', $code); flash('Category updated.'); }
        else { q('INSERT INTO categories (code, name, default_type) VALUES (?, ?, ?)', [$code, $name, $type]); audit('cat.add', $code); flash("Category $code added."); }
    } elseif ($act === 'toggle') {
        q('UPDATE categories SET active = 1 - active WHERE id = ?', [(int)$_POST['id']]);
        flash('Status changed.');
    } elseif ($act === 'import') {
        $rows = read_csv_upload('file'); $a = $u = 0; $errs = [];
        foreach ($rows as $i => $r) {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $r['code'] ?? '')); $name = trim($r['name'] ?? '');
            if ($code === '' || $name === '') { $errs[] = 'Row ' . ($i + 2); continue; }
            $type = norm_type($r['default_type'] ?? 'stock');
            if (q('SELECT id FROM categories WHERE code = ?', [$code])->fetch()) { q('UPDATE categories SET name = ?, default_type = ?, active = 1 WHERE code = ?', [$name, $type, $code]); $u++; }
            else { q('INSERT INTO categories (code, name, default_type) VALUES (?, ?, ?)', [$code, $name, $type]); $a++; }
        }
        audit('cat.import', "+$a ~$u");
        flash($rows ? "Import done: $a added, $u updated." : 'No rows found in the file.', $rows ? 'ok' : 'err');
        if ($errs) flash('Skipped (missing code or name): ' . implode(', ', $errs), 'warn');
    }
    redirect('categories.php');
}

$cats = q('SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.category_id = c.id) AS n FROM categories c ORDER BY c.active DESC, c.code')->fetchAll();
$edit = null;
if (isset($_GET['edit'])) foreach ($cats as $c) if ((int)$c['id'] === (int)$_GET['edit']) $edit = $c;

render_header('Item categories'); ?>
<div class="card">
  <p class="muted">The category <b>code</b> becomes the start of the Asset ID later (e.g. IT-0001). The <b>default type</b> pre-fills each new item and decides, at goods receipt, whether it goes to stock or to the asset register.</p>
  <h2><?= $edit ? 'Edit category' : 'Add a category' ?></h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="row">
      <div><label>Code (short, letters/numbers)</label><input type="text" name="code" maxlength="12" value="<?= e($edit['code'] ?? '') ?>" required></div>
      <div><label>Name</label><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
      <div><label>Default type</label>
        <select name="default_type">
          <option value="stock" <?= ($edit['default_type'] ?? '') === 'stock' ? 'selected' : '' ?>>Stock (inventory)</option>
          <option value="asset" <?= ($edit['default_type'] ?? '') === 'asset' ? 'selected' : '' ?>>Asset (tracked individually)</option>
        </select></div>
    </div>
    <div class="actions"><button class="btn"><?= $edit ? 'Save changes' : 'Add category' ?></button><?php if ($edit): ?><a href="categories.php">Cancel</a><?php endif; ?></div>
  </form>
  <details style="margin-top:14px"><summary>Import from Excel</summary>
    <p class="muted"><a href="?template=1">Download the template</a>, fill it, save as <b>CSV UTF-8</b>, upload. default_type = stock or asset.</p>
    <form method="post" enctype="multipart/form-data" class="row" style="align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="act" value="import">
      <div><input type="file" name="file" accept=".csv,text/csv" required></div><div style="flex:0"><button class="btn sec">Import</button></div></form>
  </details>
</div>
<div class="table-wrap"><table>
  <tr><th>Code</th><th>Name</th><th>Default type</th><th>Items</th><th>Status</th><th></th></tr>
  <?php if (!$cats): ?><tr><td colspan="6" class="muted">No categories yet.</td></tr><?php endif; ?>
  <?php foreach ($cats as $c): ?>
  <tr>
    <td><b><?= e($c['code']) ?></b></td><td><?= e($c['name']) ?></td>
    <td><span class="tag <?= e($c['default_type']) ?>"><?= $c['default_type'] === 'asset' ? 'Asset' : 'Stock' ?></span></td>
    <td><?= (int)$c['n'] ?></td>
    <td><?= $c['active'] ? '<span class="tag stock">Active</span>' : '<span class="tag off">Inactive</span>' ?></td>
    <td style="white-space:nowrap"><a class="btn sec small" href="?edit=<?= (int)$c['id'] ?>">Edit</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn sec small"><?= $c['active'] ? 'Deactivate' : 'Reactivate' ?></button></form></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php render_footer();
