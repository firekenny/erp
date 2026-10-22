<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';
require_any(['storekeeper', 'admin']);

$levels = fin_levels();
$slug = fn(string $s) => trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_');
$finCols = array_map(fn($l) => $slug($l['name']) . '_code', $levels);
$cols = array_merge(['asset_id', 'description', 'category_code', 'brand_model', 'serial_no', 'purchase_date', 'purchase_value', 'currency'], $finCols, ['location', 'held_by', 'condition', 'status']);

if (isset($_GET['template'])) {
    $ex = ['', 'Laptop Dell Latitude 5440', 'IT', 'Dell Latitude 5440', 'ABC123XYZ', '2025-03-15', '1150', setting('currency', 'USD')];
    foreach ($levels as $l) $ex[] = (string)(q('SELECT code FROM fin_values WHERE level_id = ? AND active = 1 ORDER BY code LIMIT 1', [$l['id']])->fetchColumn() ?: '');
    send_csv('template_asset_register.csv', $cols, [array_merge($ex, ['Main office', 'Jane Doe', 'good', 'in_use'])]);
}

$report = $_SESSION['asset_import_report'] ?? null; unset($_SESSION['asset_import_report']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rows = read_csv_upload('file');
    $cats = array_column(q('SELECT id, code FROM categories')->fetchAll(), 'id', 'code');
    $finIds = [];
    foreach ($levels as $i => $l) $finIds[$i + 1] = array_column(q('SELECT id, code FROM fin_values WHERE level_id = ?', [$l['id']])->fetchAll(), 'id', 'code');
    $ok = 0; $errs = [];
    db()->beginTransaction();
    foreach ($rows as $i => $r) {
        $line = 'Row ' . ($i + 2);
        $desc = trim($r['description'] ?? ''); $cc = strtoupper(trim($r['category_code'] ?? ''));
        if ($desc === '' || !isset($cats[$cc])) { $errs[] = "$line: " . ($desc === '' ? 'no description' : "unknown category \"$cc\""); continue; }
        $code = strtoupper(trim($r['asset_id'] ?? ''));
        if ($code !== '' && q('SELECT 1 FROM assets WHERE code = ?', [$code])->fetch()) { $errs[] = "$line: $code already exists (skipped)"; continue; }
        if ($code === '') $code = next_asset_code($cc);
        $dRaw = trim($r['purchase_date'] ?? ''); $date = null;
        if ($dRaw !== '') {
            if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $dRaw, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dRaw, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) $date = $dRaw;
            else $errs[] = "$line: date \"$dRaw\" not understood (use YYYY-MM-DD or DD/MM/YYYY), left empty";
        }
        $fin = [];
        foreach ($levels as $k => $l) {
            $v = strtoupper(trim($r[$finCols[$k]] ?? ''));
            if ($v === '') continue;
            if (isset($finIds[$k + 1][$v])) $fin[$k + 1] = $finIds[$k + 1][$v]; else $errs[] = "$line: {$l['name']} code \"$v\" not found, left empty";
        }
        $locName = trim($r['location'] ?? ''); $locId = null;
        if ($locName !== '') { q('INSERT IGNORE INTO locations (name) VALUES (?)', [mb_substr($locName, 0, 160)]); $locId = (int)q('SELECT id FROM locations WHERE name = ?', [$locName])->fetchColumn(); }
        $held = trim($r['held_by'] ?? ''); $empId = null;
        if ($held !== '') {
            $empId = q('SELECT id FROM employees WHERE email = ? OR LOWER(name) = LOWER(?) ORDER BY active DESC LIMIT 1', [strtolower($held), $held])->fetchColumn() ?: null;
            if (!$empId) { q('INSERT INTO employees (name, email) VALUES (?, ?)', [filter_var($held, FILTER_VALIDATE_EMAIL) ? explode('@', $held)[0] : $held, filter_var($held, FILTER_VALIDATE_EMAIL) ? strtolower($held) : '']); $empId = (int)db()->lastInsertId(); }
        }
        $cond = array_key_exists(strtolower(trim($r['condition'] ?? '')), ASSET_COND) ? strtolower(trim($r['condition'])) : 'good';
        $st = strtolower(trim($r['status'] ?? ''));
        $st = isset(ASSET_STATUS[$st]) ? $st : ($empId ? 'in_use' : 'in_store');
        $cur = strtoupper(substr(trim($r['currency'] ?? ''), 0, 3)) ?: setting('currency', 'USD');
        q('INSERT INTO assets (code, item_id, category_id, description, brand_model, serial_no, purchase_date, purchase_value, currency, fin1_id, fin2_id, fin3_id, fin4_id, location_id, location, employee_id, cond, status)
           VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$code, $cats[$cc], mb_substr($desc, 0, 255), mb_substr(trim($r['brand_model'] ?? ''), 0, 190), mb_substr(trim($r['serial_no'] ?? ''), 0, 120), $date,
           (float)str_replace([' ', ','], ['', '.'], (string)($r['purchase_value'] ?? 0)), $cur, $fin[1] ?? null, $fin[2] ?? null, $fin[3] ?? null, $fin[4] ?? null, $locId, $locName, $empId, $cond, $st]);
        asset_event((int)db()->lastInsertId(), 'imported', 'From existing register');
        $ok++;
    }
    db()->commit();
    audit('asset.import', "$ok imported, " . count($errs) . ' notes');
    $_SESSION['asset_import_report'] = ['ok' => $ok, 'total' => count($rows), 'errs' => $errs];
    redirect('assets_import.php');
}

render_header('Import an existing asset register');
if ($report): ?>
  <div class="flash <?= $report['ok'] ? 'ok' : 'err' ?>"><?= (int)$report['ok'] ?> of <?= (int)$report['total'] ?> row(s) imported. <a href="assets.php">Open the register</a></div>
  <?php if ($report['errs']): ?><div class="card"><h2>Notes (<?= count($report['errs']) ?>)</h2><ul style="margin:0"><?php foreach (array_slice($report['errs'], 0, 200) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php endif; ?>
<div class="card">
  <ol style="margin-top:0">
    <li><a href="?template=1">Download the template</a> (its columns follow your finance levels: <?= e(implode(', ', $finCols)) ?>).</li>
    <li>Paste your register into it in Excel. Leave <b>asset_id</b> empty to number assets automatically, or keep your existing tags.</li>
    <li><b>category_code</b> must exist in Item categories. Locations and people that are not yet known are created automatically.</li>
    <li>condition: new, good, fair, poor, broken · status: in_store, in_use, in_repair (others are ignored).</li>
    <li>File &gt; Save As &gt; <b>CSV UTF-8</b>, then upload below.</li>
  </ol>
  <form method="post" enctype="multipart/form-data" class="row" style="align-items:flex-end"><?= csrf_field() ?>
    <div><input type="file" name="file" accept=".csv,text/csv" required></div><div style="flex:0"><button class="btn" onclick="return confirm('Import these assets?')">Import</button></div></form>
</div>
<?php render_footer();
