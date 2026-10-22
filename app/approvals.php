<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pr.php';
require_role('admin');
$cur = setting('currency', 'USD');

function amount_in(string $v): float { return (float)str_replace([' ', ','], ['', '.'], $v); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $min = amount_in((string)($_POST['min_amount'] ?? '0'));
        $appr = (int)($_POST['approver_user_id'] ?? 0);
        if ($name === '' || $min <= 0) flash('Give the level a name and an amount above 0.', 'err');
        elseif (!$appr) flash('Choose who approves at this level.', 'err');
        elseif ($id) { q('UPDATE approval_levels SET name = ?, min_amount = ?, approver_user_id = ? WHERE id = ?', [$name, $min, $appr, $id]); audit('approval_level.edit', "$name > $min"); flash('Level updated.'); }
        else { q('INSERT INTO approval_levels (name, min_amount, approver_user_id) VALUES (?, ?, ?)', [$name, $min, $appr]); audit('approval_level.add', "$name > $min"); flash("Level \"$name\" added."); }
    } elseif ($act === 'toggle' && $id) {
        q('UPDATE approval_levels SET active = 1 - active WHERE id = ?', [$id]); flash('Status changed.');
    } elseif ($act === 'delete' && $id) {
        q('DELETE FROM approval_levels WHERE id = ?', [$id]); audit('approval_level.delete', "#$id"); flash('Level removed. Requests already submitted keep their approvers.');
    }
    redirect('approvals.php');
}

$levels = approval_levels(false);
$active = array_values(array_filter($levels, fn($l) => $l['active']));
$approvers = q("SELECT id, name FROM users WHERE active = 1 AND FIND_IN_SET('budget_holder', roles) ORDER BY name")->fetchAll();
$edit = null;
if (isset($_GET['edit'])) foreach ($levels as $l) if ((int)$l['id'] === (int)$_GET['edit']) $edit = $l;

render_header('Approval thresholds'); ?>
<div class="card">
  <p>Every Purchase Request is first approved by the <b>Budget Holder</b> of its cost centre (set on the <a href="finance.php">Finance codes</a> page).
  Above the amounts below, it then goes <b>up the chain, in order</b>, to each higher level. Amounts are the PR estimated total in <?= e($cur) ?>.</p>
  <p class="muted" style="margin-bottom:0">If the same person is at two steps, they approve only once. Nobody approves their own request: an Administrator decides instead.</p>
</div>

<div class="table-wrap">
<table>
  <tr><th>Step</th><th>Level</th><th>Applies to PRs above</th><th>Approver</th><th>Status</th><th></th></tr>
  <tr><td>1</td><td><b>Budget Holder</b></td><td>All amounts</td><td class="muted">Per cost centre</td><td><span class="tag stock">Always</span></td><td></td></tr>
  <?php $step = 1; foreach ($levels as $l): if ($l['active']) $step++; ?>
  <tr>
    <td><?= $l['active'] ? $step : '' ?></td>
    <td><b><?= e($l['name']) ?></b></td>
    <td style="white-space:nowrap"><?= number_format((float)$l['min_amount'], 2) ?> <?= e($cur) ?></td>
    <td><?= $l['approver_name'] ? e($l['approver_name']) . (!$l['approver_active'] ? ' <span class="tag off">deactivated user</span>' : '') : '<span class="tag off">missing</span>' ?></td>
    <td><?= $l['active'] ? '<span class="tag stock">Active</span>' : '<span class="tag off">Inactive</span>' ?></td>
    <td style="white-space:nowrap">
      <a class="btn sec small" href="?edit=<?= (int)$l['id'] ?>">Edit</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn sec small"><?= $l['active'] ? 'Deactivate' : 'Reactivate' ?></button></form>
      <form method="post" style="display:inline" onsubmit="return confirm('Remove this level?')"><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn sec small">Remove</button></form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
</div>

<div class="card" style="margin-top:16px">
  <h2><?= $edit ? 'Edit level' : 'Add a level' ?></h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="row">
      <div><label>Level name</label><input type="text" name="name" maxlength="80" value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. Programme Director" required></div>
      <div><label>Applies to PRs above (<?= e($cur) ?>)</label><input type="number" name="min_amount" min="0" step="0.01" value="<?= e($edit['min_amount'] ?? '') ?>" placeholder="e.g. 5000" required></div>
      <div><label>Approver</label>
        <select name="approver_user_id" required><option value="">— select —</option>
          <?php foreach ($approvers as $a): ?><option value="<?= (int)$a['id'] ?>" <?= (int)($edit['approver_user_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option><?php endforeach; ?>
        </select></div>
    </div>
    <?php if (!$approvers): ?><p class="flash warn">Give someone the Budget Holder role on the <a href="users.php">Users</a> page first.</p><?php endif; ?>
    <p class="muted">Only users with the Budget Holder role can be chosen.</p>
    <div class="actions"><button class="btn"><?= $edit ? 'Save changes' : 'Add level' ?></button><?php if ($edit): ?><a href="approvals.php">Cancel</a><?php endif; ?></div>
  </form>
</div>

<?php if ($active): ?>
<div class="card">
  <h2>Check an amount</h2>
  <div class="row" style="align-items:flex-end">
    <div style="max-width:220px"><label>PR total (<?= e($cur) ?>)</label><input type="number" id="amt" min="0" step="0.01" placeholder="e.g. 12000"></div>
    <div id="chain" style="padding-bottom:8px"></div>
  </div>
</div>
<script>
const LV = <?= json_encode(array_map(fn($l) => ['n' => $l['name'], 'm' => (float)$l['min_amount'], 'a' => $l['approver_name'] ?? '?'], $active), JSON_HEX_TAG) ?>;
document.getElementById('amt').addEventListener('input', e => {
  const v = parseFloat(e.target.value);
  const steps = ['Budget Holder'].concat(LV.filter(l => v > l.m).map(l => l.n + ' (' + l.a + ')'));
  document.getElementById('chain').textContent = isNaN(v) ? '' : 'Approval chain: ' + steps.join(' → ');
});
</script>
<?php endif; ?>
<?php render_footer();
