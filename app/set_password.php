<?php
require __DIR__ . '/inc/bootstrap.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$u = $token !== '' ? q('SELECT * FROM users WHERE invite_token = ? AND invite_expires > NOW() AND active = 1', [$token])->fetch() : false;

if ($u && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string)($_POST['password'] ?? '');
    if (strlen($pw) < 8) {
        flash('Password must be at least 8 characters.', 'err');
    } elseif ($pw !== (string)($_POST['password2'] ?? '')) {
        flash('The two passwords do not match.', 'err');
    } else {
        q('UPDATE users SET password_hash = ?, invite_token = NULL, invite_expires = NULL WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
        $_SESSION = [];
        flash('Password saved. You can now sign in.');
        redirect('login.php');
    }
    redirect('set_password.php?t=' . urlencode($token));
}

render_header('Set your password');
if (!$u): ?>
  <div class="card"><p>This link is invalid or has expired. Ask your administrator for a new one.</p></div>
<?php else: ?>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>">
    <p>Welcome <b><?= e($u['name']) ?></b>. Choose a password for <?= e($u['email']) ?>.</p>
    <label>New password (min. 8 characters)</label><input type="password" name="password" required autofocus>
    <label>Repeat password</label><input type="password" name="password2" required>
    <div class="actions"><button class="btn">Save password</button></div>
  </form>
<?php endif;
render_footer();
