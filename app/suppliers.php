<?php
require __DIR__ . '/inc/bootstrap.php';
require_any(['procurement', 'admin']);

const SUP_STATUS = ['active' => ['Active', 'stock'], 'inactive' => ['Inactive', 'off'], 'blacklisted' => ['Blacklisted', 'warn']];
$cats = q('SELECT id, code, name FROM categories WHERE active = 1 ORDER BY code')->fetchAll();
$catByCode = array_column($cats, null, 'code');

function next_supplier_code(): string {
    $max = (int)q("SELECT MAX(CAST(SUBSTRING(code, 5) AS UNSIGNED)) FROM suppliers WHERE code LIKE 'SUP-%'")->fetchColumn();
    return 'SUP-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}
function set_supplier_cats(int $sid, array $catIds): void {
    q('DELETE FROM supplier_categories WHERE supplier_id = ?', [$sid]);
    foreach (array_unique(array_map('intval', $catIds)) as $c) if ($c) q('INSERT IGNORE INTO supplier_categories (supplier_id, category_id) VALUES (?, ?)', [$sid, $c]);
}

if (isset($_GET['template'])) send_csv('template_suppliers.csv', ['name', 'contact_name', 'email', 'phone', 'address', 'country', 'registration_no', 'category_codes'],
    [['Example Supplies Ltd', 'Jane Doe', 'sales@example.com', '+254 700 000000', 'Mombasa Road, Nairobi', 'Kenya', 'P051234567X', 'STAT ITC']]);
if (isset($_GET['export'])) {
    $rows = q("SELECT s.code, s.name, s.contact_name, s.email, s.phone, s.address, s.country, s.registration_no,
                 (SELECT GROUP_CONCAT(c.code ORDER BY c.code SEPARATOR ' ') FROM supplier_categories sc JOIN categories c ON c.id = sc.category_id WHERE sc.supplier_id = s.id), s.status
               FROM suppliers s ORDER BY s.name")->fetchAll(PDO::FETCH_NUM);
    send_csv('suppliers.csv', ['code', 'name', 'contact_name', 'email', 'phone', 'address', 'country', 'registration_no', 'category_codes', 'status'], $rows);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $f = [];
        foreach (['name' => 190, 'contact_name' => 120, 'email' => 190, 'phone' => 60, 'address' => 255, 'country' => 80, 'registration_no' => 80] as $k => $max) $f[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
        $f['email'] = strtolower($f['email']);
        $status = isset(SUP_STATUS[$_POST['status'] ?? '']) ? $_POST['status'] : 'active';
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($f['name'] === '') { flash('Supplier name is required.', 'err'); redirect('suppliers.php' . ($id ? "?edit=$id" : '')); }
        if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) { flash('The email address is not valid.', 'err'); redirect('suppliers.php' . ($id ? "?edit=$id" : '')); }
        $dup = q('SELECT code FROM suppliers WHERE id <> ? AND (LOWER(name) = LOWER(?) OR (email <> \'\' AND email = ?))', [$id, $f['name'], $f['email']])->fetchColumn();
        if ($dup) { flash("A supplier with this name or email already exists ($dup).", 'err'); redirect('suppliers.php' . ($id ? "?edit=$id" : '')); }
        if ($id) {
            q('UPDATE suppliers SET name = ?, contact_name = ?, email = ?, phone = ?, address = ?, country = ?, registration_no = ?, status = ?, notes = ? WHERE id = ?',
              [...array_values($f), $status, $notes, $id]);
            audit('supplier.edit', $f['name'] . " ($status)");
            flash('Supplier updated.');
        } else {
            q('INSERT INTO suppliers (code, name, contact_name, email, phone, address, country, registration_no, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
              [next_supplier_code(), ...array_values($f), $status, $notes]);
            $id = (int)db()->lastInsertId();
            audit('supplier.add', $f['name']);
            flash('Supplier added.');
        }
        set_supplier_cats($id, (array)($_POST['cats'] ?? []));
    } elseif ($act === 'import') {
        $rows = read_csv_upload('file'); $a = $u = 0; $warn = [];
        foreach ($rows as $i => $r) {
            $name = trim($r['name'] ?? '');
            if ($name === '') { $warn[] = 'Row ' . ($i + 2) . ': no name'; continue; }
            $email = strtolower(trim($r['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $warn[] = 'Row ' . ($i + 2) . ": invalid email $email (left empty)"; $email = ''; }
            $vals = [$name, trim($r['contact_name'] ?? ''), $email, trim($r['phone'] ?? ''), trim($r['address'] ?? ''), trim($r['country'] ?? ''), trim($r['registration_no'] ?? '')];
            $ex = q('SELECT id FROM suppliers WHERE LOWER(name) = LOWER(?)', [$name])->fetchColumn();
            if ($ex) { q('UPDATE suppliers SET name = ?, contact_name = ?, email = ?, phone = ?, address = ?, country = ?, registration_no = ? WHERE id = ?', [...$vals, $ex]); $sid = (int)$ex; $u++; }
            else { q('INSERT INTO suppliers (code, name, contact_name, email, phone, address, country, registration_no) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [next_supplier_code(), ...$vals]); $sid = (int)db()->lastInsertId(); $a++; }
            $ids = [];
            foreach (preg_split('/[\s,;]+/', strtoupper(trim($r['category_codes'] ?? ''))) as $cc) {
                if ($cc === '') continue;
                if (isset($catByCode[$cc])) $ids[] = $catByCode[$cc]['id']; else $warn[] = "$name: unknown category $cc";
            }
            if ($ids) set_supplier_cats($sid, $ids);
        }
        audit('supplier.import', "+$a ~$u");
        flash($rows ? "Import done: $a added, $u updated." : 'No rows found in the file.', $rows ? 'ok' : 'err');
        if ($warn) flash(count($warn) . ' note(s): ' . implode(' · ', array_slice($warn, 0, 10)), 'warn');
    }
    redirect('suppliers.php');
}

/* ---------- List ---------- */
$s = trim((string)($_GET['s'] ?? '')); $fc = (int)($_GET['c'] ?? 0); $fs = (string)($_GET['st'] ?? '');
$where = ['1=1']; $p = [];
if ($s !== '') { $where[] = '(s.name LIKE ? OR s.code LIKE ? OR s.email LIKE ? OR s.contact_name LIKE ?)'; array_push($p, "%$s%", "%$s%", "%$s%", "%$s%"); }
if ($fc) { $where[] = 'EXISTS (SELECT 1 FROM supplier_categories sc WHERE sc.supplier_id = s.id AND sc.category_id = ?)'; $p[] = $fc; }
if (isset(SUP_STATUS[$fs])) { $where[] = 's.status = ?'; $p[] = $fs; }
$rows = q("SELECT s.*, (SELECT GROUP_CONCAT(c.code ORDER BY c.code SEPARATOR ' ') FROM supplier_categories sc JOIN categories c ON c.id = sc.category_id WHERE sc.supplier_id = s.id) AS cats
           FROM suppliers s WHERE " . implode(' AND ', $where) . " ORDER BY s.status = 'active' DESC, s.name LIMIT 500", $p)->fetchAll();
$edit = isset($_GET['edit']) ? q('SELECT * FROM suppliers WHERE id = ?', [(int)$_GET['edit']])->fetch() : null;
$editCats = $edit ? array_map('intval', q('SELECT category_id FROM supplier_categories WHERE supplier_id = ?', [$edit['id']])->fetchAll(PDO::FETCH_COLUMN)) : [];
$showForm = $edit || isset($_GET['new']);

render_header('Suppliers');
if ($showForm): ?>
<div class="card">
  <h2><?= $edit ? 'Edit ' . e($edit['code']) : 'Add a supplier' ?></h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="row">
      <div style="flex:2"><label>Company name</label><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
      <div><label>Registration / tax number</label><input type="text" name="registration_no" value="<?= e($edit['registration_no'] ?? '') ?>"></div>
    </div>
    <div class="row">
      <div><label>Contact person</label><input type="text" name="contact_name" value="<?= e($edit['contact_name'] ?? '') ?>"></div>
      <div><label>Email <span class="muted">(needed for sealed bids)</span></label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
      <div><label>Phone</label><input type="text" name="phone" value="<?= e($edit['phone'] ?? '') ?>"></div>
    </div>
    <div class="row">
      <div style="flex:2"><label>Address</label><input type="text" name="address" value="<?= e($edit['address'] ?? '') ?>"></div>
      <div><label>Country</label><input type="text" name="country" value="<?= e($edit['country'] ?? '') ?>"></div>
      <div><label>Status</label><select name="status"><?php foreach (SUP_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= ($edit['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <label>What they supply (item categories)</label>
    <div class="checks" style="column-width:230px">
      <?php foreach ($cats as $c): ?><label style="display:flex"><input type="checkbox" name="cats[]" value="<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $editCats, true) ? 'checked' : '' ?>> <?= e($c['code']) ?> <span class="muted"><?= e($c['name']) ?></span></label><?php endforeach; ?>
    </div>
    <label>Notes</label><textarea name="notes" rows="2" placeholder="Performance, due diligence, reasons for blacklisting…"><?= e($edit['notes'] ?? '') ?></textarea>
    <div class="actions"><button class="btn"><?= $edit ? 'Save changes' : 'Add supplier' ?></button><a href="suppliers.php">Cancel</a></div>
  </form>
</div>
<?php else: ?>
<div class="actions" style="margin:0 0 12px">
  <a class="btn" href="?new=1">+ Add supplier</a>
  <details style="display:inline-block"><summary class="btn sec" style="list-style:none">Import from Excel</summary>
    <div class="card" style="position:absolute;z-index:3;max-width:480px;margin-top:6px">
      <p class="muted"><a href="?template=1">Download the template</a>, fill it, save as <b>CSV UTF-8</b>, upload. <b>category_codes</b>: category codes separated by spaces (e.g. STAT ITC). Suppliers with the same name are updated.</p>
      <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="act" value="import"><input type="file" name="file" accept=".csv,text/csv" required><div class="actions"><button class="btn sec">Import</button></div></form>
    </div>
  </details>
  <a href="?export=1" style="margin-left:auto">Export to CSV</a>
</div>
<?php endif; ?>

<form method="get" class="row" style="margin-bottom:10px;align-items:flex-end">
  <div style="flex:2"><input type="text" name="s" value="<?= e($s) ?>" placeholder="Search name, code, email or contact"></div>
  <div><select name="c"><option value="0">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $fc === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' – ' . $c['name']) ?></option><?php endforeach; ?></select></div>
  <div><select name="st"><option value="">All statuses</option><?php foreach (SUP_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $fs === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div style="flex:0"><button class="btn sec">Filter</button></div>
</form>
<div class="table-wrap"><table>
  <tr><th>Code</th><th>Supplier</th><th>Contact</th><th>Supplies</th><th>Status</th><th></th></tr>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">No suppliers found.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= e($r['code']) ?></td>
    <td><b><?= e($r['name']) ?></b><?php if ($r['country']): ?><br><span class="muted"><?= e($r['country']) ?></span><?php endif; ?></td>
    <td><?= e($r['contact_name']) ?><?php if ($r['email']): ?><br><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php else: ?><br><span class="tag warn">no email</span><?php endif; ?><?php if ($r['phone']): ?><br><span class="muted"><?= e($r['phone']) ?></span><?php endif; ?></td>
    <td><?php foreach (array_filter(explode(' ', (string)$r['cats'])) as $cc): ?><span class="tag"><?= e($cc) ?></span><?php endforeach; ?></td>
    <td><span class="tag <?= SUP_STATUS[$r['status']][1] ?>"><?= SUP_STATUS[$r['status']][0] ?></span></td>
    <td><a class="btn sec small" href="?edit=<?= (int)$r['id'] ?>">Edit</a></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<p class="muted">Blacklisted and inactive suppliers cannot be invited to quote. Suppliers are never deleted, so past quotes and orders keep their history.</p>
<?php render_footer();
