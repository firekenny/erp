<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('admin');

$levels   = fin_levels();
$levelsById = array_column($levels, null, 'id');
$apprLvl  = (int)setting('approval_level_id', '0');
$curId    = (int)($_GET['level'] ?? ($levels[0]['id'] ?? 0));
$cur      = $levelsById[$curId] ?? null;
$parentLv = null;
if ($cur) foreach ($levels as $l) if ((int)$l['position'] === (int)$cur['position'] - 1) $parentLv = $l;

function back(int $lv = 0): void { redirect('finance.php' . ($lv ? '?level=' . $lv : '')); }

/* ---------- Template / export downloads ---------- */
if (isset($_GET['template']) && $cur) {
    $h = ['code', 'name'];
    if ($parentLv) $h[] = 'parent_code';
    if ((int)$cur['id'] === $apprLvl) $h[] = 'budget_holder_email';
    send_csv('template_' . preg_replace('/\W+/', '_', strtolower($cur['name'])) . '.csv', $h);
}
if (isset($_GET['export']) && $cur) {
    $rows = q('SELECT v.code, v.name, p.code AS parent_code, u.email, v.active FROM fin_values v
               LEFT JOIN fin_values p ON p.id = v.parent_id LEFT JOIN users u ON u.id = v.approver_user_id
               WHERE v.level_id = ? ORDER BY v.code', [$cur['id']])->fetchAll(PDO::FETCH_NUM);
    send_csv('export_' . preg_replace('/\W+/', '_', strtolower($cur['name'])) . '.csv', ['code', 'name', 'parent_code', 'budget_holder_email', 'active'], $rows);
}

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'add_level') {
        $name = trim((string)($_POST['name'] ?? ''));
        if (count($levels) >= 4) flash('Maximum 4 levels.', 'err');
        elseif ($name === '') flash('Give the level a name.', 'err');
        else {
            q('INSERT INTO fin_levels (position, name, required) VALUES (?, ?, ?)', [count($levels) + 1, $name, isset($_POST['required']) ? 1 : 0]);
            $nid = (int)db()->lastInsertId();
            audit('fin.level.add', $name);
            flash("Level \"$name\" added.");
            back($nid);
        }
        back($curId);
    }
    if ($act === 'edit_level') {
        $id = (int)$_POST['id']; $name = trim((string)($_POST['name'] ?? ''));
        if ($name !== '' && isset($levelsById[$id])) {
            q('UPDATE fin_levels SET name = ?, required = ? WHERE id = ?', [$name, isset($_POST['required']) ? 1 : 0, $id]);
            audit('fin.level.edit', "#$id $name");
            flash('Level updated.');
        }
        back($id);
    }
    if ($act === 'del_level') {
        $last = end($levels);
        $id = (int)$_POST['id'];
        if (!$last || (int)$last['id'] !== $id) flash('Only the last level can be removed.', 'err');
        elseif ((int)q('SELECT COUNT(*) FROM fin_values WHERE level_id = ?', [$id])->fetchColumn() > 0) flash('Remove or deactivate its codes first — this level still has codes.', 'err');
        else {
            q('DELETE FROM fin_levels WHERE id = ?', [$id]);
            if ($apprLvl === $id) set_setting('approval_level_id', '0');
            audit('fin.level.delete', "#$id");
            flash('Level removed.');
        }
        back();
    }
    if ($act === 'approval_level') {
        $id = (int)($_POST['approval_level_id'] ?? 0);
        set_setting('approval_level_id', (string)(isset($levelsById[$id]) ? $id : 0));
        audit('fin.approval_level', "#$id");
        flash('Approval level saved. Assign a Budget Holder to each code of that level.');
        back($id ?: $curId);
    }

    if (!$cur) back();

    if ($act === 'save_value') {
        $id     = (int)($_POST['id'] ?? 0);
        $code   = trim((string)($_POST['code'] ?? ''));
        $name   = trim((string)($_POST['name'] ?? ''));
        $parent = $parentLv ? (int)($_POST['parent_id'] ?? 0) : 0;
        $appr   = (int)$cur['id'] === $apprLvl ? (int)($_POST['approver_user_id'] ?? 0) : 0;
        if ($code === '' || $name === '') { flash('Code and name are required.', 'err'); back($curId); }
        if ($parentLv && !$parent) { flash('Select the ' . $parentLv['name'] . ' this code belongs to.', 'err'); back($curId); }
        $dup = q('SELECT id FROM fin_values WHERE level_id = ? AND code = ? AND id <> ?', [$cur['id'], $code, $id])->fetch();
        if ($dup) { flash("Code $code already exists in this level.", 'err'); back($curId); }
        if ($id) {
            q('UPDATE fin_values SET code = ?, name = ?, parent_id = ?, approver_user_id = ? WHERE id = ? AND level_id = ?',
              [$code, $name, $parent ?: null, $appr ?: null, $id, $cur['id']]);
            audit('fin.value.edit', "{$cur['name']} $code");
            flash("Code $code updated.");
        } else {
            q('INSERT INTO fin_values (level_id, parent_id, code, name, approver_user_id) VALUES (?, ?, ?, ?, ?)',
              [$cur['id'], $parent ?: null, $code, $name, $appr ?: null]);
            audit('fin.value.add', "{$cur['name']} $code");
            flash("Code $code added.");
        }
        back($curId);
    }
    if ($act === 'toggle_value') {
        q('UPDATE fin_values SET active = 1 - active WHERE id = ? AND level_id = ?', [(int)$_POST['id'], $cur['id']]);
        audit('fin.value.toggle', '#' . (int)$_POST['id']);
        flash('Status changed.');
        back($curId);
    }
    if ($act === 'import') {
        $rows = read_csv_upload('file');
        if (!$rows) { flash('No rows found. Save your Excel sheet as CSV (first row = column names) and try again.', 'err'); back($curId); }
        $added = $updated = 0; $errs = [];
        foreach ($rows as $i => $r) {
            $line = $i + 2;
            $code = trim($r['code'] ?? ''); $name = trim($r['name'] ?? '');
            if ($code === '' || $name === '') { $errs[] = "Row $line: code and name are required"; continue; }
            $pid = null;
            if ($parentLv) {
                $pc = trim($r['parent_code'] ?? '');
                $pid = $pc !== '' ? q('SELECT id FROM fin_values WHERE level_id = ? AND code = ?', [$parentLv['id'], $pc])->fetchColumn() : false;
                if (!$pid) { $errs[] = "Row $line ($code): parent_code \"$pc\" not found in {$parentLv['name']}"; continue; }
            }
            $aid = null;
            if ((int)$cur['id'] === $apprLvl && ($em = strtolower(trim($r['budget_holder_email'] ?? ''))) !== '') {
                $aid = q("SELECT id FROM users WHERE email = ? AND active = 1 AND FIND_IN_SET('budget_holder', roles)", [$em])->fetchColumn() ?: null;
                if (!$aid) $errs[] = "Row $line ($code): $em is not an active Budget Holder — code imported without approver";
            }
            $ex = q('SELECT id FROM fin_values WHERE level_id = ? AND code = ?', [$cur['id'], $code])->fetchColumn();
            if ($ex) {
                q('UPDATE fin_values SET name = ?, parent_id = ?, approver_user_id = COALESCE(?, approver_user_id), active = 1 WHERE id = ?', [$name, $pid, $aid, $ex]);
                $updated++;
            } else {
                q('INSERT INTO fin_values (level_id, parent_id, code, name, approver_user_id) VALUES (?, ?, ?, ?, ?)', [$cur['id'], $pid, $code, $name, $aid]);
                $added++;
            }
        }
        audit('fin.import', "{$cur['name']}: +$added / ~$updated / " . count($errs) . ' errors');
        flash("Import done: $added added, $updated updated.");
        if ($errs) flash(count($errs) . " row(s) need attention: " . implode(' · ', array_slice($errs, 0, 10)) . (count($errs) > 10 ? ' …' : ''), 'warn');
        back($curId);
    }
    back($curId);
}

/* ---------- Data for display ---------- */
$values = $cur ? q('SELECT v.*, p.code AS p_code, p.name AS p_name, u.name AS appr_name FROM fin_values v
                    LEFT JOIN fin_values p ON p.id = v.parent_id LEFT JOIN users u ON u.id = v.approver_user_id
                    WHERE v.level_id = ? ORDER BY v.active DESC, p.code, v.code', [$cur['id']])->fetchAll() : [];
$parents = $parentLv ? q('SELECT id, code, name FROM fin_values WHERE level_id = ? AND active = 1 ORDER BY code', [$parentLv['id']])->fetchAll() : [];
$holders = q("SELECT id, name FROM users WHERE active = 1 AND FIND_IN_SET('budget_holder', roles) ORDER BY name")->fetchAll();
$edit = null;
if (isset($_GET['edit'])) foreach ($values as $v) if ((int)$v['id'] === (int)$_GET['edit']) $edit = $v;
$isApprLvl = $cur && (int)$cur['id'] === $apprLvl;

render_header('Finance codes'); ?>

<div class="card">
  <h2>Coding levels</h2>
  <p class="muted">Define how your organisation codes expenses, top to bottom (up to 4 levels). Each level filters the next one in the Purchase Request dropdowns.
  Example: <b>Donor</b> → <b>Grant</b> → <b>Budget line</b> → <b>Cost centre</b>.</p>
  <?php if ($levels): ?>
  <div class="table-wrap"><table>
    <tr><th>#</th><th>Level name</th><th>Mandatory on PR</th><th>Codes</th><th></th></tr>
    <?php foreach ($levels as $i => $l): $n = (int)q('SELECT COUNT(*) FROM fin_values WHERE level_id = ?', [$l['id']])->fetchColumn(); ?>
    <tr>
      <td><?= (int)$l['position'] ?></td>
      <td colspan="2">
        <form method="post" class="row" style="align-items:center;margin:0">
          <?= csrf_field() ?><input type="hidden" name="act" value="edit_level"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <input type="text" name="name" value="<?= e($l['name']) ?>" style="max-width:240px">
          <label class="checks" style="margin:0;font-weight:400;flex:0"><input type="checkbox" name="required" <?= $l['required'] ? 'checked' : '' ?>> Mandatory</label>
          <button class="btn sec small" style="flex:0">Save</button>
        </form>
      </td>
      <td><a href="?level=<?= (int)$l['id'] ?>"><?= $n ?> code(s)</a></td>
      <td><?php if ($i === count($levels) - 1): ?>
        <form method="post" onsubmit="return confirm('Remove this level?')" style="margin:0"><?= csrf_field() ?><input type="hidden" name="act" value="del_level"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn sec small">Remove</button></form>
      <?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <?php if (count($levels) < 4): ?>
  <form method="post" class="row" style="align-items:flex-end;margin-top:12px">
    <?= csrf_field() ?><input type="hidden" name="act" value="add_level">
    <div><label>Add level <?= count($levels) + 1 ?></label><input type="text" name="name" placeholder="<?= ['Donor', 'Grant', 'Budget line', 'Cost centre'][count($levels)] ?>"></div>
    <label class="checks" style="flex:0;white-space:nowrap"><input type="checkbox" name="required" checked> Mandatory</label>
    <div style="flex:0"><button class="btn">Add level</button></div>
  </form>
  <?php endif; ?>

  <?php if ($levels): ?>
  <form method="post" class="row" style="align-items:flex-end;margin-top:16px;border-top:1px solid var(--line);padding-top:12px">
    <?= csrf_field() ?><input type="hidden" name="act" value="approval_level">
    <div><label>Which level decides who approves a Purchase Request?</label>
      <select name="approval_level_id">
        <option value="0">— not set —</option>
        <?php foreach ($levels as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $apprLvl === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
      </select>
      <span class="muted">Usually "Grant": each grant code gets one Budget Holder who approves PRs charged to it.</span>
    </div>
    <div style="flex:0"><button class="btn sec">Save</button></div>
  </form>
  <?php endif; ?>
</div>

<?php if ($cur): ?>
<div class="actions" style="margin:0 0 10px">
  <?php foreach ($levels as $l): ?>
    <a class="btn <?= (int)$l['id'] === $curId ? '' : 'sec' ?> small" href="?level=<?= (int)$l['id'] ?>"><?= (int)$l['position'] ?>. <?= e($l['name']) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2><?= $edit ? 'Edit code' : 'Add a ' . e($cur['name']) . ' code' ?></h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="save_value"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="row">
      <?php if ($parentLv): ?>
      <div><label><?= e($parentLv['name']) ?></label>
        <select name="parent_id" required><option value="">— select —</option>
          <?php foreach ($parents as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($edit['parent_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['code'] . ' – ' . $p['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div><label>Code</label><input type="text" name="code" value="<?= e($edit['code'] ?? '') ?>" required></div>
      <div><label>Name / description</label><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
      <?php if ($isApprLvl): ?>
      <div><label>Budget Holder (approver)</label>
        <select name="approver_user_id"><option value="0">— none —</option>
          <?php foreach ($holders as $h): ?><option value="<?= (int)$h['id'] ?>" <?= (int)($edit['approver_user_id'] ?? 0) === (int)$h['id'] ? 'selected' : '' ?>><?= e($h['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
    </div>
    <?php if ($parentLv && !$parents): ?><p class="flash warn">Add <?= e($parentLv['name']) ?> codes first.</p><?php endif; ?>
    <div class="actions"><button class="btn"><?= $edit ? 'Save changes' : 'Add code' ?></button><?php if ($edit): ?><a href="?level=<?= $curId ?>">Cancel</a><?php endif; ?></div>
  </form>
  <details style="margin-top:14px">
    <summary>Import from Excel</summary>
    <p class="muted">1. <a href="?level=<?= $curId ?>&template=1">Download the template</a> &nbsp;2. Fill it in Excel &nbsp;3. File &gt; Save As &gt; <b>CSV UTF-8</b> &nbsp;4. Upload below.
    Existing codes are updated, new codes are added — nothing is deleted.</p>
    <form method="post" enctype="multipart/form-data" class="row" style="align-items:flex-end">
      <?= csrf_field() ?><input type="hidden" name="act" value="import">
      <div><input type="file" name="file" accept=".csv,text/csv" required></div>
      <div style="flex:0"><button class="btn sec">Import</button></div>
    </form>
  </details>
</div>

<div class="table-wrap">
<table>
  <tr><?php if ($parentLv): ?><th><?= e($parentLv['name']) ?></th><?php endif; ?><th>Code</th><th>Name</th><?php if ($isApprLvl): ?><th>Budget Holder</th><?php endif; ?><th>Status</th><th></th></tr>
  <?php if (!$values): ?><tr><td colspan="6" class="muted">No codes yet.</td></tr><?php endif; ?>
  <?php foreach ($values as $v): ?>
  <tr>
    <?php if ($parentLv): ?><td><?= e($v['p_code']) ?> <span class="muted"><?= e($v['p_name']) ?></span></td><?php endif; ?>
    <td><b><?= e($v['code']) ?></b></td>
    <td><?= e($v['name']) ?></td>
    <?php if ($isApprLvl): ?><td><?= $v['appr_name'] ? e($v['appr_name']) : '<span class="tag off">missing</span>' ?></td><?php endif; ?>
    <td><?= $v['active'] ? '<span class="tag stock">Active</span>' : '<span class="tag off">Inactive</span>' ?></td>
    <td style="white-space:nowrap">
      <a class="btn sec small" href="?level=<?= $curId ?>&edit=<?= (int)$v['id'] ?>">Edit</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle_value"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="btn sec small"><?= $v['active'] ? 'Deactivate' : 'Reactivate' ?></button></form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</div>
<p class="muted"><a href="?level=<?= $curId ?>&export=1">Export this list to CSV</a>. Codes are deactivated rather than deleted, so past Purchase Requests keep their coding.</p>
<?php endif; ?>
<?php render_footer();
