<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('admin');
$cur = setting('currency', 'USD');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($act === 'save') {
        $name = trim((string)($_POST['name'] ?? ''));
        $maxRaw = trim(str_replace([' ', ','], ['', '.'], (string)($_POST['max_amount'] ?? '')));
        $max = $maxRaw === '' ? null : (float)$maxRaw;
        $minQ = max(1, min(20, (int)($_POST['min_quotes'] ?? 1)));
        $sealed = isset($_POST['sealed']) ? 1 : 0;
        $clash = q('SELECT id FROM proc_methods WHERE active = 1 AND id <> ? AND ' . ($max === null ? 'max_amount IS NULL' : 'max_amount = ?'), $max === null ? [$id] : [$id, $max])->fetch();
        if ($name === '') flash('Give the method a name.', 'err');
        elseif ($max !== null && $max <= 0) flash('The upper limit must be above 0, or empty for "no limit".', 'err');
        elseif ($clash) flash($max === null ? 'Another active method already has no upper limit.' : 'Another active method already uses this upper limit.', 'err');
        elseif ($id) { q('UPDATE proc_methods SET name = ?, max_amount = ?, min_quotes = ?, sealed = ? WHERE id = ?', [$name, $max, $minQ, $sealed, $id]); audit('method.edit', $name); flash('Method updated.'); }
        else { q('INSERT INTO proc_methods (name, max_amount, min_quotes, sealed) VALUES (?, ?, ?, ?)', [$name, $max, $minQ, $sealed]); audit('method.add', $name); flash("Method \"$name\" added."); }
    } elseif ($act === 'toggle' && $id) {
        q('UPDATE proc_methods SET active = 1 - active WHERE id = ?', [$id]); flash('Status changed.');
    } elseif ($act === 'defaults' && !proc_methods(false)) {
        foreach ([['Single quotation', 1000, 1, 0], ['Request for quotations (RFQ)', 25000, 3, 1], ['Open tender', null, 3, 1]] as $m) {
            q('INSERT INTO proc_methods (name, max_amount, min_quotes, sealed) VALUES (?, ?, ?, ?)', $m);
        }
        audit('method.defaults'); flash('Typical NGO bands added. Adjust the amounts to your own procurement policy.');
    }
    redirect('methods.php');
}

$all = proc_methods(false);
$edit = null;
if (isset($_GET['edit'])) foreach ($all as $m) if ((int)$m['id'] === (int)$_GET['edit']) $edit = $m;

render_header('Procurement methods'); ?>
<div class="card">
  <p>The ERP picks the procurement method from the <b>Purchase Request total</b> (<?= e($cur) ?>), and Procurement cannot award with fewer quotes than the minimum.
  <b>Sealed bids</b> means suppliers submit through a personal link and nobody can see prices before the deadline.</p>
  <?php if (!$all): ?>
  <form method="post" class="actions" style="margin:0"><?= csrf_field() ?><input type="hidden" name="act" value="defaults">
    <button class="btn sec">Start from typical NGO bands</button><span class="muted">1 quote up to 1,000 · 3 sealed quotes up to 25,000 · open tender above</span></form>
  <?php endif; ?>
</div>

<div class="table-wrap"><table>
  <tr><th>Method</th><th>PR total</th><th>Min. quotes</th><th>Sealed bids</th><th>Status</th><th></th></tr>
  <?php if (!$all): ?><tr><td colspan="6" class="muted">No methods yet.</td></tr><?php endif; ?>
  <?php $from = 0.0; foreach ($all as $m): ?>
  <tr>
    <td><b><?= e($m['name']) ?></b></td>
    <td style="white-space:nowrap"><?php if (!$m['active']): ?><span class="muted">—</span><?php elseif ($m['max_amount'] === null): ?>above <?= number_format($from, 2) ?><?php else: ?><?= $from > 0 ? 'above ' . number_format($from, 2) . ' up to ' : 'up to ' ?><?= number_format((float)$m['max_amount'], 2) ?><?php $from = (float)$m['max_amount']; endif; ?></td>
    <td><?= (int)$m['min_quotes'] ?></td>
    <td><?= $m['sealed'] ? 'Yes' : 'No' ?></td>
    <td><?= $m['active'] ? '<span class="tag stock">Active</span>' : '<span class="tag off">Inactive</span>' ?></td>
    <td style="white-space:nowrap"><a class="btn sec small" href="?edit=<?= (int)$m['id'] ?>">Edit</a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn sec small"><?= $m['active'] ? 'Deactivate' : 'Reactivate' ?></button></form></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php if ($all && !array_filter($all, fn($m) => $m['active'] && $m['max_amount'] === null)): ?>
  <div class="flash warn" style="margin-top:10px">No method covers the highest amounts. Add one with an empty upper limit (e.g. "Open tender").</div>
<?php endif; ?>

<div class="card" style="margin-top:16px">
  <h2><?= $edit ? 'Edit method' : 'Add a method' ?></h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="row">
      <div><label>Name</label><input type="text" name="name" maxlength="80" value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. Request for quotations" required></div>
      <div><label>Up to (<?= e($cur) ?>) — empty = no limit</label><input type="number" name="max_amount" min="0" step="0.01" value="<?= e($edit['max_amount'] ?? '') ?>"></div>
      <div><label>Minimum quotes</label><input type="number" name="min_quotes" min="1" max="20" value="<?= (int)($edit['min_quotes'] ?? 3) ?>"></div>
    </div>
    <label class="checks" style="font-weight:400"><input type="checkbox" name="sealed" <?= !empty($edit['sealed']) ? 'checked' : '' ?>> Sealed bids (suppliers submit through a personal link)</label>
    <div class="actions"><button class="btn"><?= $edit ? 'Save changes' : 'Add method' ?></button><?php if ($edit): ?><a href="methods.php">Cancel</a><?php endif; ?></div>
  </form>
</div>
<?php render_footer();
