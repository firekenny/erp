<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';
require_once __DIR__ . '/inc/xlsx.php';
require_any(['storekeeper', 'procurement', 'admin']);
$isAdmin = has_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $m = array_key_exists($_POST['dep_method'] ?? '', DEP_METHODS) ? $_POST['dep_method'] : 'none';
    set_setting('dep_method', $m);
    foreach ((array)($_POST['life'] ?? []) as $cid => $life) {
        $life = trim(str_replace(',', '.', (string)$life));
        $res = max(0, min(100, (float)str_replace(',', '.', (string)($_POST['res'][$cid] ?? 0))));
        q('UPDATE categories SET useful_life = ?, residual_pct = ? WHERE id = ?', [$life === '' ? null : max(0.1, (float)$life), $res, (int)$cid]);
    }
    audit('depreciation.settings', $m);
    flash('Depreciation settings saved.');
    redirect('depreciation.php');
}

$method = setting('dep_method', 'none');
$asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['asof'] ?? '')) ? $_GET['asof'] : date('Y-m-d');
$fc = (int)($_GET['c'] ?? 0); $fin = (int)($_GET['fin'] ?? 0);
$cats = q("SELECT c.*, (SELECT COUNT(*) FROM assets a WHERE a.category_id = c.id) AS n FROM categories c WHERE c.default_type = 'asset' OR EXISTS (SELECT 1 FROM assets a WHERE a.category_id = c.id) ORDER BY c.code")->fetchAll();
$catById = array_column($cats, null, 'id');

$where = ["a.status <> 'lost'", '(a.purchase_date IS NULL OR a.purchase_date <= ?)', '(a.disposed_at IS NULL OR a.disposed_at > ?)']; $p = [$asOf, $asOf];
if ($fc) { $where[] = 'a.category_id = ?'; $p[] = $fc; }
if ($fin) { $where[] = '(a.fin1_id = ? OR a.fin2_id = ? OR a.fin3_id = ? OR a.fin4_id = ?)'; array_push($p, $fin, $fin, $fin, $fin); }
$rows = q('SELECT a.*, c.code AS cat_code FROM assets a JOIN categories c ON c.id = a.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY c.code, a.code', $p)->fetchAll();
$codes = fin_code_map();
$out = []; $tot = ['cost' => 0, 'acc' => 0, 'nbv' => 0]; $byCat = [];
foreach ($rows as $a) {
    $d = asset_depreciation($a, $asOf, $catById[$a['category_id']] ?? []);
    $out[] = $a + $d;
    foreach (['cost' => (float)$a['purchase_value'], 'acc' => $d['acc'], 'nbv' => $d['nbv']] as $k => $v) { $tot[$k] += $v; $byCat[$a['cat_code']][$k] = ($byCat[$a['cat_code']][$k] ?? 0) + $v; }
}

if (isset($_GET['xlsx'])) {
    $detail = array_map(fn($a) => [$a['code'], $a['description'], $a['cat_code'], fin_label($a, $codes), $a['purchase_date'], round((float)$a['purchase_value'], 2), $a['currency'],
        (int)$a['months'], round($a['acc'], 2), round($a['nbv'], 2), ASSET_STATUS[$a['status']][0], $a['note']], $out);
    $summary = [];
    foreach ($byCat as $c => $v) $summary[] = [$c, round($v['cost'], 2), round($v['acc'], 2), round($v['nbv'], 2)];
    $summary[] = ['TOTAL', round($tot['cost'], 2), round($tot['acc'], 2), round($tot['nbv'], 2)];
    xlsx_send('depreciation_' . $asOf . '.xlsx', [
        ['name' => 'Summary', 'header' => ['Category', 'Cost', 'Accumulated depreciation', 'Net book value'], 'rows' => $summary],
        ['name' => 'Assets', 'header' => ['Asset ID', 'Description', 'Category', 'Funding', 'Purchase date', 'Cost', 'Currency', 'Months depreciated', 'Accumulated depreciation', 'Net book value', 'Status', 'Note'], 'rows' => $detail],
    ]);
}

$fins = q('SELECT v.id, v.code, l.name AS level FROM fin_values v JOIN fin_levels l ON l.id = v.level_id ORDER BY l.position, v.code')->fetchAll();
render_header('Depreciation'); ?>
<?php if ($isAdmin): ?>
<details class="card" <?= $method === 'none' ? 'open' : '' ?>><summary>Settings: method, useful life and residual value per category</summary>
  <form method="post" style="margin-top:10px"><?= csrf_field() ?>
    <label>Method</label><select name="dep_method" style="max-width:300px"><?php foreach (DEP_METHODS as $k => $l): ?><option value="<?= $k ?>" <?= $method === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <p class="muted">Calculated monthly from the purchase month, until the end of the useful life or the disposal date. Reducing balance uses the rate that reaches the residual value at the end of life (double the straight-line rate when the residual is 0). Check your organisation's or donor's accounting policy.</p>
    <div class="table-wrap"><table><tr><th>Category</th><th>Assets</th><th>Useful life (years)</th><th>Residual value %</th></tr>
      <?php foreach ($cats as $c): ?><tr><td><b><?= e($c['code']) ?></b> <?= e($c['name']) ?></td><td><?= (int)$c['n'] ?></td>
        <td><input type="number" step="0.5" min="0" name="life[<?= (int)$c['id'] ?>]" value="<?= e($c['useful_life'] === null ? '' : rtrim(rtrim((string)$c['useful_life'], '0'), '.')) ?>" placeholder="e.g. 3" style="max-width:110px"></td>
        <td><input type="number" step="1" min="0" max="100" name="res[<?= (int)$c['id'] ?>]" value="<?= e(rtrim(rtrim((string)$c['residual_pct'], '0'), '.') ?: '0') ?>" style="max-width:110px"></td></tr><?php endforeach; ?>
    </table></div>
    <p class="muted">Common NGO values: IT 3 years · vehicles 4–5 years · generators 5 years · furniture 5–10 years.</p>
    <div class="actions"><button class="btn">Save settings</button></div>
  </form>
</details>
<?php endif; ?>
<?php if ($method === 'none'): ?><div class="flash warn">Depreciation is switched off<?= $isAdmin ? ': choose a method in the settings above.' : '. An Administrator can switch it on.' ?></div><?php endif; ?>
<form method="get" class="card row" style="align-items:flex-end">
  <div><label>Value as of</label><input type="date" name="asof" value="<?= e($asOf) ?>"></div>
  <div><label>Category</label><select name="c"><option value="0">All</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $fc === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code']) ?></option><?php endforeach; ?></select></div>
  <div><label>Funding</label><select name="fin"><option value="0">All</option><?php foreach ($fins as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $fin === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['level'] . ' ' . $v['code']) ?></option><?php endforeach; ?></select></div>
  <div style="flex:0;white-space:nowrap"><button class="btn sec">Show</button> <a class="btn sec" href="?<?= e(http_build_query(['asof' => $asOf, 'c' => $fc, 'fin' => $fin, 'xlsx' => 1])) ?>">Excel</a></div>
</form>
<div class="tiles" style="margin-bottom:12px">
  <div class="tile"><span class="muted">Cost</span><b><?= number_format($tot['cost'], 2) ?></b></div>
  <div class="tile"><span class="muted">Accumulated depreciation</span><b><?= number_format($tot['acc'], 2) ?></b></div>
  <div class="tile"><span class="muted">Net book value</span><b><?= number_format($tot['nbv'], 2) ?></b></div>
</div>
<div class="table-wrap"><table>
  <tr><th>Asset</th><th>Funding</th><th>Bought</th><th style="text-align:right">Cost</th><th style="text-align:right">Months</th><th style="text-align:right">Acc. depreciation</th><th style="text-align:right">Net book value</th></tr>
  <?php if (!$out): ?><tr><td colspan="7" class="muted">No assets in service at this date.</td></tr><?php endif; ?>
  <?php foreach ($out as $a): ?><tr><td><a href="asset_view.php?id=<?= (int)$a['id'] ?>"><b><?= e($a['code']) ?></b></a> <?= e($a['description']) ?><?= $a['note'] ? ' <span class="tag warn">' . e($a['note']) . '</span>' : '' ?></td>
    <td class="muted"><?= e(fin_label($a, $codes)) ?></td><td style="white-space:nowrap"><?= $a['purchase_date'] ? e(date('M Y', strtotime($a['purchase_date']))) : '—' ?></td>
    <td style="text-align:right"><?= number_format((float)$a['purchase_value'], 2) ?></td><td style="text-align:right"><?= (int)$a['months'] ?></td>
    <td style="text-align:right"><?= number_format($a['acc'], 2) ?></td><td style="text-align:right"><b><?= number_format($a['nbv'], 2) ?></b></td></tr><?php endforeach; ?>
</table></div>
<?php render_footer();
