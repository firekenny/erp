<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';
require_any(['storekeeper', 'admin']);

if (isset($_GET['template'])) send_csv('template_staff.csv', ['name', 'position', 'department', 'email', 'phone', 'staff_no'], [['Jane Doe', 'Field Officer', 'Operations', 'jane.doe@example.org', '+254 700 000000', 'EMP-001']]);
if (isset($_GET['export'])) send_csv('staff.csv', ['name', 'position', 'department', 'email', 'phone', 'staff_no', 'active'], q('SELECT name, position, department, email, phone, staff_no, active FROM employees ORDER BY name')->fetchAll(PDO::FETCH_NUM));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    $f = fn($k, $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    if ($act === 'save') {
        $vals = [$f('name', 160), $f('position', 120), $f('department', 120), strtolower($f('email', 190)), $f('phone', 60), $f('staff_no', 40)];
        if ($vals[0] === '') flash('Name is required.', 'err');
        elseif ($vals[3] !== '' && !filter_var($vals[3], FILTER_VALIDATE_EMAIL)) flash('The email address is not valid.', 'err');
        elseif ($id) { q('UPDATE employees SET name = ?, position = ?, department = ?, email = ?, phone = ?, staff_no = ? WHERE id = ?', [...$vals, $id]); flash('Saved.'); }
        else { q('INSERT INTO employees (name, position, department, email, phone, staff_no) VALUES (?, ?, ?, ?, ?, ?)', $vals); flash($vals[0] . ' added.'); }
    } elseif ($act === 'toggle') {
        $held = (int)q("SELECT COUNT(*) FROM assets WHERE employee_id = ? AND status NOT IN ('disposed','lost')", [$id])->fetchColumn();
        $active = (int)q('SELECT active FROM employees WHERE id = ?', [$id])->fetchColumn();
        if ($active && $held) flash("This person still holds $held asset(s). Record their return first.", 'err');
        else { q('UPDATE employees SET active = 1 - active WHERE id = ?', [$id]); flash('Status changed.'); }
    } elseif ($act === 'import') {
        $rows = read_csv_upload('file'); $a = $u = 0; $skip = [];
        foreach ($rows as $i => $r) {
            $name = trim($r['name'] ?? '');
            if ($name === '') { $skip[] = 'row ' . ($i + 2); continue; }
            $email = strtolower(trim($r['email'] ?? '')); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
            $vals = [$name, trim($r['position'] ?? ''), trim($r['department'] ?? ''), $email, trim($r['phone'] ?? ''), trim($r['staff_no'] ?? '')];
            $ex = $vals[5] !== '' ? q('SELECT id FROM employees WHERE staff_no = ?', [$vals[5]])->fetchColumn() : false;
            if (!$ex && $email !== '') $ex = q('SELECT id FROM employees WHERE email = ?', [$email])->fetchColumn();
            if (!$ex) $ex = q('SELECT id FROM employees WHERE LOWER(name) = LOWER(?)', [$name])->fetchColumn();
            if ($ex) { q('UPDATE employees SET name = ?, position = ?, department = ?, email = ?, phone = ?, staff_no = ?, active = 1 WHERE id = ?', [...$vals, $ex]); $u++; }
            else { q('INSERT INTO employees (name, position, department, email, phone, staff_no) VALUES (?, ?, ?, ?, ?, ?)', $vals); $a++; }
        }
        audit('staff.import', "+$a ~$u");
        flash($rows ? "Import done: $a added, $u updated." : 'No rows found.', $rows ? 'ok' : 'err');
        if ($skip) flash('Skipped (no name): ' . implode(', ', $skip), 'warn');
    }
    redirect('employees.php');
}

$s = trim((string)($_GET['s'] ?? ''));
$rows = q("SELECT e.*, (SELECT COUNT(*) FROM assets a WHERE a.employee_id = e.id AND a.status NOT IN ('disposed','lost')) AS held FROM employees e"
        . ($s !== '' ? ' WHERE e.name LIKE ? OR e.department LIKE ? OR e.position LIKE ?' : '') . ' ORDER BY e.active DESC, e.name', $s !== '' ? ["%$s%", "%$s%", "%$s%"] : [])->fetchAll();
$edit = isset($_GET['edit']) ? q('SELECT * FROM employees WHERE id = ?', [(int)$_GET['edit']])->fetch() : null;
render_header('Staff'); ?>
<div class="card">
  <p class="muted">People who can hold assets. They do not need an ERP account: they confirm handovers with a signature on screen or through an emailed link.</p>
  <h2><?= $edit ? 'Edit' : 'Add a person' ?></h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="row"><div style="flex:2"><label>Full name</label><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
      <div><label>Position</label><input type="text" name="position" value="<?= e($edit['position'] ?? '') ?>"></div><div><label>Department</label><input type="text" name="department" value="<?= e($edit['department'] ?? '') ?>"></div></div>
    <div class="row"><div><label>Email</label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div><div><label>Phone</label><input type="text" name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
      <div><label>Staff number</label><input type="text" name="staff_no" value="<?= e($edit['staff_no'] ?? '') ?>"></div></div>
    <div class="actions"><button class="btn"><?= $edit ? 'Save' : 'Add' ?></button><?php if ($edit): ?><a href="employees.php">Cancel</a><?php endif; ?></div>
  </form>
  <details style="margin-top:12px"><summary>Import from Excel (HR list)</summary>
    <p class="muted"><a href="?template=1">Download the template</a>, fill it, save as <b>CSV UTF-8</b>, upload. People are matched by staff number, then email, then name, so re-importing updates them.</p>
    <form method="post" enctype="multipart/form-data" class="row" style="align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="act" value="import"><div><input type="file" name="file" accept=".csv,text/csv" required></div><div style="flex:0"><button class="btn sec">Import</button></div></form>
  </details>
</div>
<form method="get" class="row" style="margin-bottom:10px"><div style="flex:3"><input type="text" name="s" value="<?= e($s) ?>" placeholder="Search name, department, position"></div><div style="flex:0"><button class="btn sec">Search</button></div><div style="flex:0;white-space:nowrap;align-self:center"><a href="?export=1">Export</a></div></form>
<div class="table-wrap"><table>
  <tr><th>Name</th><th>Position · department</th><th>Contact</th><th>Assets held</th><th>Status</th><th></th></tr>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">No staff yet.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr><td><b><?= e($r['name']) ?></b><?= $r['staff_no'] ? '<br><span class="muted">' . e($r['staff_no']) . '</span>' : '' ?></td><td><?= e(trim($r['position'] . ' · ' . $r['department'], ' ·')) ?></td>
    <td class="muted"><?= e($r['email']) ?><?= $r['phone'] ? '<br>' . e($r['phone']) : '' ?></td><td><?= (int)$r['held'] ? '<a href="assets.php?emp=' . (int)$r['id'] . '">' . (int)$r['held'] . '</a>' : '0' ?></td>
    <td><?= $r['active'] ? '<span class="tag stock">Active</span>' : '<span class="tag off">Left</span>' ?></td>
    <td style="white-space:nowrap"><a class="btn sec small" href="?edit=<?= (int)$r['id'] ?>">Edit</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sec small"><?= $r['active'] ? 'Mark as left' : 'Reactivate' ?></button></form></td></tr><?php endforeach; ?>
</table></div>
<?php render_footer();
