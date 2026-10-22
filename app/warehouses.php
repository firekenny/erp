<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/stock.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['act'] ?? '') === 'toggle') { q('UPDATE warehouses SET active = 1 - active WHERE id = ?', [$id]); flash('Status changed.'); redirect('warehouses.php'); }
    $code = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($_POST['code'] ?? '')));
    $name = trim((string)($_POST['name'] ?? '')); $addr = trim((string)($_POST['address'] ?? ''));
    if ($code === '' || $name === '') flash('Code and name are required.', 'err');
    elseif (q('SELECT id FROM warehouses WHERE code = ? AND id <> ?', [$code, $id])->fetch()) flash("Code $code already exists.", 'err');
    elseif ($id) { q('UPDATE warehouses SET code = ?, name = ?, address = ? WHERE id = ?', [$code, $name, $addr, $id]); audit('warehouse.edit', $code); flash('Warehouse updated.'); }
    else { q('INSERT INTO warehouses (code, name, address) VALUES (?, ?, ?)', [$code, $name, $addr]); audit('warehouse.add', $code); flash("Warehouse $name added."); }
    redirect('warehouses.php');
}
$all = warehouses(false);
$edit = null; if (isset($_GET['edit'])) foreach ($all as $w) if ((int)$w['id'] === (int)$_GET['edit']) $edit = $w;
render_header('Warehouses'); ?>
<div class="card">
  <h2><?= $edit ? 'Edit warehouse' : 'Add a warehouse' ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="row">
      <div><label>Code</label><input type="text" name="code" maxlength="20" value="<?= e($edit['code'] ?? '') ?>" placeholder="e.g. KHA" required></div>
      <div style="flex:2"><label>Name</label><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. Kharkiv main warehouse" required></div>
      <div style="flex:2"><label>Address</label><input type="text" name="address" value="<?= e($edit['address'] ?? '') ?>"></div>
    </div>
    <div class="actions"><button class="btn"><?= $edit ? 'Save' : 'Add warehouse' ?></button><?php if ($edit): ?><a href="warehouses.php">Cancel</a><?php endif; ?></div>
  </form>
</div>
<div class="table-wrap"><table>
  <tr><th>Code</th><th>Name</th><th>Address</th><th>Status</th><th></th></tr>
  <?php if (!$all): ?><tr><td colspan="5" class="muted">No warehouses yet.</td></tr><?php endif; ?>
  <?php foreach ($all as $w): ?><tr><td><b><?= e($w['code']) ?></b></td><td><?= e($w['name']) ?></td><td><?= e($w['address']) ?></td>
    <td><?= $w['active'] ? '<span class="tag stock">Active</span>' : '<span class="tag off">Inactive</span>' ?></td>
    <td style="white-space:nowrap"><a class="btn sec small" href="?edit=<?= (int)$w['id'] ?>">Edit</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><button class="btn sec small"><?= $w['active'] ? 'Deactivate' : 'Reactivate' ?></button></form></td></tr>
  <?php endforeach; ?>
</table></div>
<?php render_footer();
