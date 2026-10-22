<?php
require __DIR__ . '/inc/bootstrap.php';
$u = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'notify') {
        q('UPDATE users SET notify_email = ? WHERE id = ?', [isset($_POST['notify_email']) ? 1 : 0, $u['id']]);
        flash('Saved.');
    }
    if ($act === 'password' && !is_demo()) {
        $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['new'] ?? '');
        if (!$u['password_hash'] || !password_verify($cur, $u['password_hash'])) flash('Your current password is not correct.', 'err');
        elseif (strlen($new) < 8) flash('The new password must have at least 8 characters.', 'err');
        elseif ($new !== (string)($_POST['new2'] ?? '')) flash('The two new passwords do not match.', 'err');
        else { q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]); audit('password.change'); session_regenerate_id(true); flash('Password changed.'); }
    }
    redirect('account.php');
}
render_header('My account'); ?>
<div class="card">
  <div class="row"><div><span class="muted">Name</span><br><b><?= e($u['name']) ?></b></div><div><span class="muted">Email</span><br><?= e($u['email']) ?></div>
    <div><span class="muted">Roles</span><br><?php foreach (user_roles($u) as $r): ?><span class="tag"><?= e(ROLES[$r] ?? $r) ?></span><?php endforeach; ?></div></div>
</div>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="notify">
  <h2>Email notifications</h2>
  <label class="checks" style="font-weight:400"><input type="checkbox" name="notify_email" <?= $u['notify_email'] ? 'checked' : '' ?>> Email me when something needs my action or when my requests move forward</label>
  <div class="actions"><button class="btn sec">Save</button></div>
</form>
<?php if (!is_demo()): ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="password">
  <h2>Change my password</h2>
  <div class="row"><div><label>Current password</label><input type="password" name="current" required autocomplete="current-password"></div>
    <div><label>New password (min. 8)</label><input type="password" name="new" required autocomplete="new-password"></div><div><label>Repeat new password</label><input type="password" name="new2" required autocomplete="new-password"></div></div>
  <div class="actions"><button class="btn">Change password</button></div>
</form>
<?php endif;
render_footer();
