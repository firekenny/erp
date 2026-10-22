<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/award.php';
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
        set_setting('org_address', trim((string)($_POST['org_address'] ?? '')));
        set_setting('po_prefix', strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($_POST['po_prefix'] ?? 'PO'))) ?: 'PO');
        set_setting('po_payment_terms', trim((string)($_POST['po_payment_terms'] ?? '')));
        set_setting('po_terms', trim((string)($_POST['po_terms'] ?? '')));
        set_setting('asset_prefix', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['asset_prefix'] ?? 'AS'))));
        set_setting('award_tolerance_pct', (string)max(0, min(100, (float)str_replace(',', '.', (string)($_POST['award_tolerance_pct'] ?? '10')))));
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
  <label>Organisation address (printed on Purchase Orders)</label>
  <textarea name="org_address" rows="2"><?= e(setting('org_address')) ?></textarea>
  <h2 style="margin-top:20px">Purchase Orders and awards</h2>
  <div class="row">
    <div><label>PO number prefix</label><input type="text" name="po_prefix" maxlength="10" value="<?= e(setting('po_prefix', 'PO')) ?>"></div>
    <div><label>Award tolerance above the PR estimate (%)</label><input type="number" name="award_tolerance_pct" min="0" max="100" step="0.5" value="<?= e(setting('award_tolerance_pct', '10')) ?>">
      <span class="muted">Above this, the award is flagged to approvers and needs a justification.</span></div>
  </div>
  <div class="row"><div style="max-width:320px"><label>Asset ID prefix</label><input type="text" name="asset_prefix" maxlength="6" value="<?= e(setting('asset_prefix', 'AS')) ?>">
    <span class="muted">Asset IDs look like <?= e((setting('asset_prefix', 'AS') !== '' ? setting('asset_prefix', 'AS') . '-' : '') . 'IT-0001') ?> (empty = category code only).</span></div></div>
  <label>Payment terms</label>
  <textarea name="po_payment_terms" rows="2"><?= e(setting('po_payment_terms', 'Payment within 30 days of receipt of goods and a valid invoice quoting this PO number.')) ?></textarea>
  <label>Terms and conditions printed on every PO</label>
  <textarea name="po_terms" rows="6"><?= e(setting('po_terms', default_po_terms())) ?></textarea>
  <div class="actions"><button class="btn">Save</button></div>
</form>
<?php render_footer();
