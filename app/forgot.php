<?php
require __DIR__ . '/inc/bootstrap.php';
if (current_user()) redirect('index.php');
if (is_demo()) redirect('login.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $recent = (int)q("SELECT COUNT(*) FROM audit_log WHERE action = 'password.reset_request' AND details LIKE CONCAT(?, ' —%') AND created_at > NOW() - INTERVAL 15 MINUTE", [$email])->fetchColumn();
    $u = q('SELECT * FROM users WHERE email = ? AND active = 1', [$email])->fetch();
    $result = !$u ? 'no active account with this email' : ($recent >= 3 ? 'blocked: too many requests in 15 min' : (!mail_configured() ? 'email not set up' : ''));
    if ($result === '') {
        $t = bin2hex(random_bytes(24));
        q('UPDATE users SET invite_token = ?, invite_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?', [$t, $u['id']]);
        $link = base_url() . '/set_password.php?t=' . $t;
        [$ok, $err] = send_mail($u['email'], $u['name'], 'Reset your ' . setting('org_name') . ' ERP password', mail_wrap('<p>Hello ' . e($u['name']) . ',</p><p>Someone (hopefully you) asked to reset your password.</p>'
            . '<p><a href="' . e($link) . '" style="display:inline-block;background:#1f4e79;color:#fff;padding:10px 18px;text-decoration:none;border-radius:4px">Choose a new password</a></p>'
            . '<p style="color:#6b7785;font-size:12px">This link works for 1 hour. If you did not ask for it, ignore this email: your password does not change.</p>'));
        $result = $ok ? 'email sent' : 'sending failed: ' . $err;
    }
    q('INSERT INTO audit_log (user_id, action, details) VALUES (NULL, ?, ?)', ['password.reset_request', mb_substr($email . ' — ' . $result, 0, 250)]);
    usleep(300000);
    flash(mail_configured() ? 'If this address belongs to an active account, a reset link has been sent. Check your inbox (and spam folder).' : 'Email is not set up for this ERP yet: ask your administrator for a new access link.', mail_configured() ? 'ok' : 'warn');
    redirect('login.php');
}
render_header('Forgot your password?'); ?>
<form method="post" class="card"><?= csrf_field() ?>
  <p>Enter the email address of your account. If it exists, you will receive a link to choose a new password.</p>
  <label>Email</label><input type="email" name="email" required autofocus>
  <div class="actions"><button class="btn">Send the link</button><a href="login.php">Back to sign in</a></div>
</form>
<?php render_footer();
