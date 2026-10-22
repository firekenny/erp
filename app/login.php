<?php
require __DIR__ . '/inc/bootstrap.php';

if (current_user()) redirect('index.php');

$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pw = (string)($_POST['password'] ?? '');
    $u = q('SELECT * FROM users WHERE email = ? AND active = 1', [$email])->fetch();
    if ($u && $u['password_hash'] && password_verify($pw, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
        audit('login');
        redirect('index.php');
    }
    usleep(400000);
    flash('Wrong email or password.', 'err');
    redirect('login.php');
}

render_header('Sign in'); ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <label>Email</label><input type="email" name="email" value="<?= e($email) ?>" required autofocus>
  <label>Password</label><input type="password" name="password" required>
  <div class="actions"><button class="btn">Sign in</button></div>
  <p class="muted">Forgot your password? Ask your administrator for a new access link.</p>
</form>
<?php render_footer();
