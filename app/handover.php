<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';
$u = require_any(['storekeeper', 'admin']);

$type = isset(HANDOVER_TYPES[$_REQUEST['type'] ?? '']) ? $_REQUEST['type'] : 'handover';
$ids = array_values(array_unique(array_map('intval', (array)($_REQUEST['a'] ?? []))));
$assets = $ids ? q('SELECT a.*, l.name AS loc_name, e.name AS emp_name FROM assets a LEFT JOIN locations l ON l.id = a.location_id LEFT JOIN employees e ON e.id = a.employee_id
                     WHERE a.id IN (' . implode(',', $ids) . ") AND a.status NOT IN ('disposed','lost') ORDER BY a.code")->fetchAll() : [];
if (!$assets) { flash('Select at least one asset in service.', 'err'); redirect('assets.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emp = (int)($_POST['employee_id'] ?? 0); $loc = (int)($_POST['location_id'] ?? 0);
    if ($type === 'handover' && !q('SELECT 1 FROM employees WHERE id = ? AND active = 1', [$emp])->fetch()) { flash('Choose the person receiving the assets.', 'err'); redirect('handover.php?' . http_build_query(['type' => $type, 'a' => $ids])); }
    if ($type !== 'handover' && !q('SELECT 1 FROM locations WHERE id = ? AND active = 1', [$loc])->fetch()) { flash('Choose the location.', 'err'); redirect('handover.php?' . http_build_query(['type' => $type, 'a' => $ids])); }
    db()->beginTransaction();
    $num = next_doc_number('handovers', $type === 'handover' ? 'HO' : ($type === 'return' ? 'RET' : 'MOV'));
    q('INSERT INTO handovers (number, type, employee_id, location_id, notes, created_by, token) VALUES (?, ?, ?, ?, ?, ?, ?)',
      [$num, $type, $type === 'handover' ? $emp : null, $loc ?: null, trim((string)($_POST['notes'] ?? '')), $u['id'], bin2hex(random_bytes(24))]);
    $hid = (int)db()->lastInsertId();
    $empName = $emp ? q('SELECT name FROM employees WHERE id = ?', [$emp])->fetchColumn() : null;
    $locName = $loc ? q('SELECT name FROM locations WHERE id = ?', [$loc])->fetchColumn() : null;
    foreach ($assets as $a) {
        $cond = array_key_exists($_POST['cond'][$a['id']] ?? '', ASSET_COND) ? $_POST['cond'][$a['id']] : $a['cond'];
        q('INSERT INTO handover_lines (handover_id, asset_id, from_text, cond) VALUES (?, ?, ?, ?)', [$hid, $a['id'], asset_where($a['loc_name'] ?? $a['location'], $a['emp_name']), $cond]);
        if ($type === 'handover') {
            $newLoc = $loc ?: ($a['location_id'] ?: null);
            q("UPDATE assets SET employee_id = ?, location_id = ?, location = COALESCE((SELECT name FROM locations WHERE id = ?), location), status = 'in_use', cond = ? WHERE id = ?", [$emp, $newLoc, $newLoc, $cond, $a['id']]);
            asset_event((int)$a['id'], 'handed over', "To $empName" . ($locName ? " at $locName" : '') . ($a['emp_name'] ? " (was with {$a['emp_name']})" : ''), 'handover', $hid);
        } elseif ($type === 'move') {
            q('UPDATE assets SET location_id = ?, location = ?, cond = ? WHERE id = ?', [$loc, $locName, $cond, $a['id']]);
            asset_event((int)$a['id'], 'moved', 'From ' . ($a['loc_name'] ?? $a['location']) . " to $locName", 'handover', $hid);
        } else {
            q("UPDATE assets SET employee_id = NULL, location_id = ?, location = ?, status = 'in_store', cond = ? WHERE id = ?", [$loc, $locName, $cond, $a['id']]);
            asset_event((int)$a['id'], 'returned', ($a['emp_name'] ? "From {$a['emp_name']} " : '') . "to $locName, condition " . ASSET_COND[$cond], 'handover', $hid);
        }
    }
    audit('handover.' . $type, $num);
    db()->commit();
    flash("$num recorded." . ($type === 'handover' ? ' Now get the signature: on this screen, or by sending the link.' : ''));
    redirect("handover_view.php?id=$hid");
}

render_header(HANDOVER_TYPES[$type]); ?>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php foreach ($ids as $i): ?><input type="hidden" name="a[]" value="<?= $i ?>"><?php endforeach; ?>
  <div class="row">
    <?php if ($type === 'handover'): ?>
      <div><label>Hand over to</label><select name="employee_id" required><option value="">— person —</option><?php foreach (employees() as $e): ?><option value="<?= (int)$e['id'] ?>"><?= e($e['name'] . ($e['position'] ? ' · ' . $e['position'] : '')) ?></option><?php endforeach; ?></select>
        <span class="muted">Not in the list? <a href="employees.php">Add them to Staff</a>.</span></div>
      <div><label>Location <span class="muted">(optional: where the asset will be used)</span></label><select name="location_id"><option value="0">— unchanged —</option><?php foreach (locations() as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <?php else: ?>
      <div><label><?= $type === 'return' ? 'Returned to (store)' : 'New location' ?></label><select name="location_id" required><option value="">— location —</option><?php foreach (locations() as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
  </div>
  <div class="table-wrap" style="margin-top:12px"><table>
    <tr><th>Asset</th><th>Currently</th><th>Condition <?= $type === 'return' ? 'on return' : 'at handover' ?></th></tr>
    <?php foreach ($assets as $a): ?>
    <tr><td><b><?= e($a['code']) ?></b> <?= e($a['description']) ?><br><span class="muted"><?= e(trim($a['brand_model'] . ' · ' . $a['serial_no'], ' ·')) ?></span></td>
      <td><?= e(asset_where($a['loc_name'] ?? $a['location'], $a['emp_name'])) ?></td>
      <td><select name="cond[<?= (int)$a['id'] ?>]"><?php foreach (ASSET_COND as $k => $l): ?><option value="<?= $k ?>" <?= $a['cond'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td></tr>
    <?php endforeach; ?>
  </table></div>
  <label>Notes</label><input type="text" name="notes" placeholder="Accessories included, purpose…">
  <div class="actions"><button class="btn">Record <?= e(strtolower(HANDOVER_TYPES[$type])) ?></button><a href="assets.php">Cancel</a></div>
</form>
<?php render_footer();
