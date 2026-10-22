<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';
require_any(['storekeeper', 'admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if (($_POST['act'] ?? '') === 'toggle') { q('UPDATE locations SET active = 1 - active WHERE id = ?', [$id]); flash('Status changed.'); redirect('locations.php'); }
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 160); $addr = mb_substr(trim((string)($_POST['address'] ?? '')), 0, 255);
    if ($name === '') flash('Name is required.', 'err');
    elseif (q('SELECT id FROM locations WHERE name = ? AND id <> ?', [$name, $id])->fetch()) flash('This location already exists.', 'err');
    elseif ($id) { q('UPDATE locations SET name = ?, address = ? WHERE id = ?', [$name, $addr, $id]); flash('Saved.'); }
    else { q('INSERT INTO locations (name, address) VALUES (?, ?)', [$name, $addr]); flash("$name added."); }
    redirect('locations.php');
}
$rows = q("SELECT l.*, (SELECT COUNT(*) FROM assets a WHERE a.location_id = l.id AND a.status NOT IN ('disposed','lost')) AS n FROM locations l ORDER BY l.active DESC, l.name")->fetchAll();
$edit = isset($_GET['edit']) ? q('SELECT * FROM locations WHERE id = ?', [(int)$_GET['edit']])->fetch() : null;
render_header('Asset locations'); ?>
<div class="card">
  <p class="muted">Offices, field sites, guesthouses, vehicles… anywhere an asset can be. Warehouses are added automatically.</p>
  <form method="post" class="row" style="align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div style="flex:2"><label><?= $edit ? 'Edit location' : 'Add a location' ?></label><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. Kakuma field office" required></div>
    <div style="flex:2"><label>Address</label><input type="text" name="address" value="<?= e($edit['address'] ?? '') ?>"></div>
    <div style="flex:0"><button class="btn"><?= $edit ? 'Save' : 'Add' ?></button></div></form>
</div>
<div class="table-wrap"><table>
  <tr><th>Location</th><th>Address</th><th>Assets</th><th>Status</th><th></th></tr>
  <?php foreach ($rows as $r): ?><tr><td><b><?= e($r['name']) ?></b></td><td class="muted"><?= e($r['address']) ?></td><td><?= (int)$r['n'] ? '<a href="assets.php?loc=' . (int)$r['id'] . '">' . (int)$r['n'] . '</a>' : '0' ?></td>
    <td><?= $r['active'] ? '<span class="tag stock">Active</span>' : '<span class="tag off">Inactive</span>' ?></td>
    <td style="white-space:nowrap"><a class="btn sec small" href="?edit=<?= (int)$r['id'] ?>">Edit</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sec small"><?= $r['active'] ? 'Deactivate' : 'Reactivate' ?></button></form></td></tr><?php endforeach; ?>
</table></div>
<?php render_footer();
