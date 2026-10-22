<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';
require_any(['storekeeper', 'procurement', 'admin']);
$canEdit = has_role('storekeeper') || has_role('admin');

// Old links (assets.php?edit=ID) now open the asset page
if (isset($_GET['edit'])) redirect('asset_view.php?id=' . (int)$_GET['edit']);

$f = [
    's' => trim((string)($_GET['s'] ?? '')), 'c' => (int)($_GET['c'] ?? 0), 'st' => (string)($_GET['st'] ?? 'active'),
    'loc' => (int)($_GET['loc'] ?? 0), 'emp' => (int)($_GET['emp'] ?? 0), 'fin' => (int)($_GET['fin'] ?? 0), 'missing' => !empty($_GET['missing']),
];
$where = ['1=1']; $p = [];
if ($f['s'] !== '') { $where[] = '(a.code LIKE ? OR a.description LIKE ? OR a.serial_no LIKE ? OR a.brand_model LIKE ?)'; array_push($p, "%{$f['s']}%", "%{$f['s']}%", "%{$f['s']}%", "%{$f['s']}%"); }
if ($f['c']) { $where[] = 'a.category_id = ?'; $p[] = $f['c']; }
if ($f['st'] === 'active') $where[] = "a.status NOT IN ('disposed','lost')";
elseif (isset(ASSET_STATUS[$f['st']])) { $where[] = 'a.status = ?'; $p[] = $f['st']; }
if ($f['loc']) { $where[] = 'a.location_id = ?'; $p[] = $f['loc']; }
if ($f['emp']) { $where[] = 'a.employee_id = ?'; $p[] = $f['emp']; }
if ($f['fin']) { $where[] = '(a.fin1_id = ? OR a.fin2_id = ? OR a.fin3_id = ? OR a.fin4_id = ?)'; array_push($p, $f['fin'], $f['fin'], $f['fin'], $f['fin']); }
if ($f['missing']) $where[] = "a.serial_no = ''";
$rows = q('SELECT a.*, l.name AS loc_name, e.name AS emp_name, c.code AS cat_code FROM assets a JOIN categories c ON c.id = a.category_id
           LEFT JOIN locations l ON l.id = a.location_id LEFT JOIN employees e ON e.id = a.employee_id WHERE ' . implode(' AND ', $where) . ' ORDER BY a.code LIMIT 1000', $p)->fetchAll();
$codes = fin_code_map();

if (isset($_GET['export'])) {
    send_csv('asset_register_' . date('Y-m-d') . '.csv',
        ['asset_id', 'description', 'category', 'brand_model', 'serial_no', 'purchase_date', 'purchase_value', 'currency', 'funding', 'location', 'held_by', 'condition', 'status', 'po'],
        array_map(fn($a) => [$a['code'], $a['description'], $a['cat_code'], $a['brand_model'], $a['serial_no'], $a['purchase_date'], (float)$a['purchase_value'], $a['currency'],
            fin_label($a, $codes), $a['loc_name'] ?? $a['location'], $a['emp_name'] ?? '', ASSET_COND[$a['cond']] ?? $a['cond'], ASSET_STATUS[$a['status']][0], (string)($a['po_id'] ? q('SELECT number FROM po WHERE id = ?', [$a['po_id']])->fetchColumn() : '')], $rows));
}

$cats = q('SELECT DISTINCT c.id, c.code, c.name FROM categories c JOIN assets a ON a.category_id = c.id ORDER BY c.code')->fetchAll();
$fins = q('SELECT v.id, v.code, l.name AS level FROM fin_values v JOIN fin_levels l ON l.id = v.level_id ORDER BY l.position, v.code')->fetchAll();
$nMissing = (int)q("SELECT COUNT(*) FROM assets WHERE serial_no = '' AND status NOT IN ('disposed','lost')")->fetchColumn();
$unsigned = (int)q("SELECT COUNT(*) FROM handovers WHERE signed_at IS NULL AND type = 'handover'")->fetchColumn();
$total = array_sum(array_map(fn($a) => (float)$a['purchase_value'], $rows));

render_header('Asset register'); ?>
<div class="actions" style="margin:0 0 12px">
  <?php if ($canEdit): ?><a class="btn sec small" href="assets_import.php">Import existing register</a><a class="btn sec small" href="handovers.php">Handovers<?= $unsigned ? " ($unsigned unsigned)" : '' ?></a><?php endif; ?>
  <a class="btn sec small" style="margin-left:auto" href="?<?= e(http_build_query($_GET + ['export' => 1])) ?>">Export to Excel (CSV)</a>
</div>
<form method="get" class="card" style="padding:12px">
  <div class="row" style="align-items:flex-end">
    <div style="flex:2"><input type="text" name="s" value="<?= e($f['s']) ?>" placeholder="Search asset ID, description, serial, brand"></div>
    <div><select name="c"><option value="0">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['c'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' – ' . $c['name']) ?></option><?php endforeach; ?></select></div>
    <div><select name="st"><option value="active" <?= $f['st'] === 'active' ? 'selected' : '' ?>>In service</option><option value="" <?= $f['st'] === '' ? 'selected' : '' ?>>All statuses</option>
      <?php foreach (ASSET_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $f['st'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="row" style="align-items:flex-end">
    <div><select name="loc"><option value="0">All locations</option><?php foreach (locations(false) as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $f['loc'] === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <div><select name="emp"><option value="0">Any holder</option><?php foreach (employees(false) as $e): ?><option value="<?= (int)$e['id'] ?>" <?= $f['emp'] === (int)$e['id'] ? 'selected' : '' ?>><?= e($e['name']) ?></option><?php endforeach; ?></select></div>
    <div><select name="fin"><option value="0">Any funding</option><?php $last = null; foreach ($fins as $v): if ($v['level'] !== $last) { if ($last) echo '</optgroup>'; echo '<optgroup label="' . e($v['level']) . '">'; $last = $v['level']; } ?><option value="<?= (int)$v['id'] ?>" <?= $f['fin'] === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['code']) ?></option><?php endforeach; if ($last) echo '</optgroup>'; ?></select></div>
    <label class="checks" style="flex:0;white-space:nowrap;font-weight:400"><input type="checkbox" name="missing" value="1" <?= $f['missing'] ? 'checked' : '' ?>> Missing serial (<?= $nMissing ?>)</label>
    <div style="flex:0"><button class="btn sec">Filter</button></div>
  </div>
</form>

<form method="get" action="handover.php" id="sel">
<?php if ($canEdit): ?>
<div class="actions" id="bulk" style="margin:0 0 8px;display:none">
  <span class="muted"><b id="nsel">0</b> selected:</span>
  <button class="btn small" name="type" value="handover">Hand over to a person</button>
  <button class="btn sec small" name="type" value="move">Move to a location</button>
  <button class="btn sec small" name="type" value="return">Return to store</button>
</div>
<?php endif; ?>
<div class="table-wrap"><table>
  <tr><?php if ($canEdit): ?><th style="width:30px"><input type="checkbox" onclick="document.querySelectorAll('.ck').forEach(c=>c.checked=this.checked);upd()"></th><?php endif; ?>
    <th>Asset ID</th><th>Description</th><th>Serial</th><th>Where · held by</th><th>Funding</th><th style="text-align:right">Value</th><th>Status</th></tr>
  <?php if (!$rows): ?><tr><td colspan="8" class="muted">No assets match. Assets are created automatically when asset items are received, or imported from an existing register.</td></tr><?php endif; ?>
  <?php foreach ($rows as $a): $active = !in_array($a['status'], ['disposed', 'lost'], true); ?>
  <tr><?php if ($canEdit): ?><td><?php if ($active): ?><input type="checkbox" class="ck" name="a[]" value="<?= (int)$a['id'] ?>" onchange="upd()"><?php endif; ?></td><?php endif; ?>
    <td><a href="asset_view.php?id=<?= (int)$a['id'] ?>"><b><?= e($a['code']) ?></b></a></td>
    <td><?= e($a['description']) ?><?php if ($a['brand_model']): ?><br><span class="muted"><?= e($a['brand_model']) ?></span><?php endif; ?></td>
    <td><?= $a['serial_no'] !== '' ? e($a['serial_no']) : '<span class="tag warn">missing</span>' ?></td>
    <td><?= e($a['loc_name'] ?? $a['location']) ?><?= $a['emp_name'] ? '<br><b>' . e($a['emp_name']) . '</b>' : '' ?></td>
    <td class="muted"><?= e(fin_label($a, $codes)) ?></td>
    <td style="text-align:right;white-space:nowrap"><?= number_format((float)$a['purchase_value'], 2) ?> <?= e($a['currency']) ?></td>
    <td><span class="tag <?= ASSET_STATUS[$a['status']][1] ?>"><?= ASSET_STATUS[$a['status']][0] ?></span></td></tr>
  <?php endforeach; ?>
  <?php if ($rows): ?><tr><td colspan="<?= $canEdit ? 6 : 5 ?>" style="text-align:right"><b><?= count($rows) ?> asset(s) · total purchase value</b></td><td style="text-align:right"><b><?= number_format($total, 2) ?></b></td><td></td></tr><?php endif; ?>
</table></div>
</form>
<script>function upd(){const n=document.querySelectorAll('.ck:checked').length;const b=document.getElementById('bulk');if(b){b.style.display=n?'flex':'none';document.getElementById('nsel').textContent=n;}}</script>
<?php render_footer();
