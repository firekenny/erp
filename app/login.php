<?php
require __DIR__ . '/inc/bootstrap.php';

if (current_user()) redirect('index.php');

$email = '';
if (is_demo() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demo_role'], DEMO_ACCOUNTS[$_POST['demo_role']])) {
    $u = q('SELECT * FROM users WHERE email = ? AND active = 1', [demo_email($_POST['demo_role'])])->fetch();
    if ($u) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['last_seen'] = time();
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
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    // Lock-out: 5 failures for an account, or 20 from one computer, within 15 minutes
    $byEmail = (int)q('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at > NOW() - INTERVAL 15 MINUTE', [$email])->fetchColumn();
    $byIp = (int)q('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > NOW() - INTERVAL 15 MINUTE', [$ip])->fetchColumn();
    if ($byEmail >= 5 || $byIp >= 20) {
        flash('Too many unsuccessful attempts. Please wait 15 minutes, or use "Forgot your password?".', 'err');
        redirect('login.php');
    }
    $u = q('SELECT * FROM users WHERE email = ? AND active = 1', [$email])->fetch();
    if ($u && $u['password_hash'] && password_verify($pw, $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['last_seen'] = time();
        q('DELETE FROM login_attempts WHERE email = ?', [$email]);
        q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
        audit('login');
        redirect('index.php');
    }
    q('INSERT INTO login_attempts (email, ip) VALUES (?, ?)', [mb_substr($email, 0, 190), mb_substr($ip, 0, 45)]);
    q('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
    if ($byEmail + 1 >= 5) q('INSERT INTO audit_log (user_id, action, details) VALUES (NULL, ?, ?)', ['login.locked', "$email from $ip"]);
    usleep(400000);
    flash('Wrong email or password.', 'err');
    redirect('login.php');
}

render_header('Sign in');
if (is_demo()): ?>
<div class="card">
  <h2>Try the demo: choose a role</h2>
  <p class="muted">No password needed. Sample data is reset every night, so feel free to explore and change anything. New here? Read the <a href="help.php">demo guide and 20-minute tour</a>.</p>
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
  <p class="muted"><a href="forgot.php">Forgot your password?</a> · <a href="help.php">User guide</a></p>
</form>
<?php render_footer();
