<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

$c = [
    'users'  => (int)q('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn(),
    'levels' => (int)q('SELECT COUNT(*) FROM fin_levels')->fetchColumn(),
    'codes'  => (int)q('SELECT COUNT(*) FROM fin_values WHERE active = 1')->fetchColumn(),
    'cats'   => (int)q('SELECT COUNT(*) FROM categories WHERE active = 1')->fetchColumn(),
    'items'  => (int)q('SELECT COUNT(*) FROM items WHERE active = 1')->fetchColumn(),
];
$approvers = (int)q('SELECT COUNT(*) FROM fin_values WHERE approver_user_id IS NOT NULL AND active = 1')->fetchColumn();

render_header('Dashboard'); ?>
<div class="tiles">
  <div class="tile"><span class="muted">Active users</span><b><?= $c['users'] ?></b></div>
  <div class="tile"><span class="muted">Finance coding levels</span><b><?= $c['levels'] ?></b></div>
  <div class="tile"><span class="muted">Finance codes</span><b><?= $c['codes'] ?></b></div>
  <div class="tile"><span class="muted">Item categories</span><b><?= $c['cats'] ?></b></div>
  <div class="tile"><span class="muted">Catalogue items</span><b><?= $c['items'] ?></b></div>
</div>

<?php if (has_role('admin')): ?>
<div class="card" style="margin-top:16px">
  <h2>Setup checklist</h2>
  <table>
    <?php
    $steps = [
      ['Organisation name and currency', true, 'settings.php'],
      ['Finance coding levels defined (e.g. Donor → Grant → Budget line)', $c['levels'] > 0, 'finance.php'],
      ['Finance codes entered or imported', $c['codes'] > 0, 'finance.php'],
      ['Budget Holder assigned on approval-level codes', $approvers > 0, 'finance.php'],
      ['Item categories created', $c['cats'] > 0, 'categories.php'],
      ['Item catalogue entered or imported', $c['items'] > 0, 'items.php'],
      ['Users invited', $c['users'] > 1, 'users.php'],
    ];
    foreach ($steps as [$label, $ok, $href]): ?>
      <tr><td style="width:30px"><?= $ok ? '✅' : '⬜' ?></td><td><a href="<?= e($href) ?>"><?= e($label) ?></a></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<p class="muted">Version <?= e(APP_VERSION) ?></p>
<?php render_footer();
