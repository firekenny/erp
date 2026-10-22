<?php
require __DIR__ . '/inc/bootstrap.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['org_name'] ?? ''));
    $cur  = strtoupper(trim((string)($_POST['currency'] ?? '')));
    $pfx  = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($_POST['pr_prefix'] ?? 'PR')));
    if ($name === '' || !preg_match('/^[A-Z]{3}$/', $cur)) {
        flash('Organisation name is required and currency must be 3 letters (e.g. USD, EUR, UAH).', 'err');
    } else {
        set_setting('org_name', $name);
        set_setting('currency', $cur);
        set_setting('pr_prefix', $pfx ?: 'PR');
        audit('settings.update', "$name / $cur / $pfx");
        flash('Settings saved.');
    }
    redirect('settings.php');
}

render_header('Organisation'); ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <label>Organisation name</label>
  <input type="text" name="org_name" value="<?= e(setting('org_name')) ?>" required>
  <div class="row">
    <div><label>Main currency (3 letters)</label><input type="text" name="currency" maxlength="3" value="<?= e(setting('currency', 'USD')) ?>"></div>
    <div><label>Purchase Request number prefix</label><input type="text" name="pr_prefix" maxlength="10" value="<?= e(setting('pr_prefix', 'PR')) ?>">
      <span class="muted">Numbers will look like <?= e(setting('pr_prefix', 'PR')) ?>-<?= date('Y') ?>-0001</span></div>
  </div>
  <div class="actions"><button class="btn">Save</button></div>
</form>
<?php render_footer();
