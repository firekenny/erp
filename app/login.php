<?php
require __DIR__ . '/inc/bootstrap.php';

if (current_user()) redirect('index.php');

$email = '';
if (is_demo() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demo_role'], DEMO_ACCOUNTS[$_POST['demo_role']])) {
    $u = q('SELECT * FROM users WHERE email = ? AND active = 1', [demo_email($_POST['demo_role'])])->fetch();
    if ($u) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
        redirect('index.php');
    }
    flash('Demo account not found. Please reload the page.', 'err');
    redirect('login.php');
}

if (is_demo() && $_SERVER['REQUEST_METHOD'] === 'POST') redirect('login.php');   // demo: one-click accounts only

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

render_header('Sign in');
if (is_demo()): ?>
<div class="card">
  <h2>Try the demo: choose a role</h2>
  <p class="muted">No password needed. Sample data is reset every night, so feel free to explore and change anything.</p>
  <form method="post">
    <?= csrf_field() ?>
    <?php foreach (DEMO_ACCOUNTS as $role => [$name, , $what]): ?>
      <button class="btn sec" name="demo_role" value="<?= e($role) ?>" style="display:block;width:100%;text-align:left;margin-bottom:8px">
        <b><?= e(ROLES[$role]) ?></b> · <?= e($name) ?><br><span class="muted"><?= e($what) ?></span>
      </button>
    <?php endforeach; ?>
  </form>
</div>
<?php render_footer(); exit; endif; ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <label>Email</label><input type="email" name="email" value="<?= e($email) ?>" required autofocus>
  <label>Password</label><input type="password" name="password" required>
  <div class="actions"><button class="btn">Sign in</button></div>
  <p class="muted">Forgot your password? Ask your administrator for a new access link.</p>
</form>
<?php render_footer();
