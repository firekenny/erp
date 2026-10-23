<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/sso.php';
$me = require_role('admin');
$guid = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_demo()) { flash('Microsoft sign-in is disabled in the demo.', 'warn'); redirect('sso_settings.php'); }
    $tenant = trim((string)($_POST['sso_tenant'] ?? '')); $client = trim((string)($_POST['sso_client_id'] ?? ''));
    $errs = [];
    if ($tenant !== '' && !preg_match($guid, $tenant)) $errs[] = 'The Directory (tenant) ID should look like a1b2c3d4-…-…';
    if ($client !== '' && !preg_match($guid, $client)) $errs[] = 'The Application (client) ID should look like a1b2c3d4-…-…';
    $enable = !empty($_POST['sso_enabled']);
    $secret = trim((string)($_POST['sso_secret'] ?? ''));
    if ($secret !== '' && preg_match($guid, $secret)) $errs[] = 'That looks like the secret\'s ID. Paste the secret\'s VALUE (the longer text shown only once when you created it).';
    if ($enable && ($tenant === '' || $client === '' || ($secret === '' && setting('sso_secret') === ''))) $errs[] = 'Fill in the three fields before switching Microsoft sign-in on.';
    $mode = ($_POST['sso_password_mode'] ?? 'all') === 'admins' ? 'admins' : 'all';
    if ($mode === 'admins' && $me['password_hash'] === null) $errs[] = 'Set a password on your own account first (My account): administrators keep password access for emergencies.';
    if ($errs) { flash(implode(' ', $errs), 'err'); redirect('sso_settings.php'); }
    set_setting('sso_tenant', $tenant); set_setting('sso_client_id', $client);
    if ($secret !== '') set_setting('sso_secret', $secret);
    set_setting('sso_enabled', $enable ? '1' : '0'); set_setting('sso_password_mode', $mode);
    audit('sso.settings', ($enable ? 'on' : 'off') . ", passwords: $mode");
    flash('Microsoft sign-in settings saved.' . ($enable ? ' Sign out and use "Sign in with Microsoft" to test it (keep this window open in case).' : ''));
    redirect('sso_settings.php');
}
$linked = (int)q('SELECT COUNT(*) FROM users WHERE ms_oid IS NOT NULL')->fetchColumn();
render_header('Microsoft sign-in');
if (is_demo()): ?><div class="flash warn">Not available in the demo.</div><?php endif; ?>
<div class="card">
  <p style="margin-top:0">Staff sign in with their Microsoft 365 work account (and Microsoft's 2-step verification, if your organisation uses it). <b>Only people already added under <a href="users.php">Users &amp; roles</a> with the same email can get in</b>; their roles still come from the ERP.</p>
  <p class="muted">Set up in Microsoft: <i>entra.microsoft.com &gt; App registrations &gt; New registration</i>, single tenant, redirect URI (type <b>Web</b>):</p>
  <div class="copy" id="ru"><?= e(sso_redirect_uri()) ?></div>
  <button type="button" class="btn sec small" style="margin-top:6px" onclick="navigator.clipboard.writeText(document.getElementById('ru').innerText);this.innerText='Copied'">Copy</button>
</div>
<form method="post" class="card"><?= csrf_field() ?>
  <div class="row">
    <div><label>Directory (tenant) ID</label><input type="text" name="sso_tenant" value="<?= e(setting('sso_tenant')) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
    <div><label>Application (client) ID</label><input type="text" name="sso_client_id" value="<?= e(setting('sso_client_id')) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
  </div>
  <label>Client secret <span class="muted">(the secret's <b>Value</b>; <?= setting('sso_secret') !== '' ? 'saved, leave empty to keep it' : 'not set yet' ?>)</span></label>
  <input type="password" name="sso_secret" autocomplete="new-password" placeholder="<?= setting('sso_secret') !== '' ? '••••••••••••' : 'paste the Value here' ?>">
  <label style="margin-top:14px"><input type="checkbox" name="sso_enabled" value="1" <?= setting('sso_enabled') === '1' ? 'checked' : '' ?>> Show <b>Sign in with Microsoft</b> on the sign-in page</label>
  <label style="margin-top:14px">Password sign-in</label>
  <select name="sso_password_mode">
    <option value="all" <?= setting('sso_password_mode', 'all') === 'all' ? 'selected' : '' ?>>Still allowed for everyone</option>
    <option value="admins" <?= setting('sso_password_mode') === 'admins' ? 'selected' : '' ?>>Administrators only (everyone else must use Microsoft)</option>
  </select>
  <p class="muted">Administrators always keep their password, so the ERP stays reachable if Microsoft is unavailable.</p>
  <div class="actions"><button class="btn">Save</button></div>
  <p class="muted" style="margin-bottom:0"><?= $linked ?> user(s) have already signed in with Microsoft. Client secrets expire (6 to 24 months): create a new one in Entra before that date and paste it here.</p>
</form>
<?php render_footer();
