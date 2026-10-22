<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mail.php';
$me = require_role('admin');

const MAIL_KEYS = ['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'mail_from', 'mail_from_name', 'mail_reply_to'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_demo()) { flash('Email settings are disabled in the demo.', 'warn'); redirect('email_settings.php'); }
    $act = $_POST['act'] ?? '';
    if ($act === 'save') {
        foreach (MAIL_KEYS as $k) set_setting($k, trim((string)($_POST[$k] ?? '')));
        if (!in_array(setting('smtp_secure'), ['ssl', 'tls', 'none'], true)) set_setting('smtp_secure', 'ssl');
        if (($_POST['smtp_pass'] ?? '') !== '') set_setting('smtp_pass', (string)$_POST['smtp_pass']);
        foreach (['mail_from', 'mail_reply_to'] as $k) {
            if (setting($k) !== '' && !filter_var(setting($k), FILTER_VALIDATE_EMAIL)) flash("\"$k\" is not a valid email address.", 'err');
        }
        audit('email.settings');
        flash('Email settings saved. Send a test email to check them.');
    } elseif ($act === 'test') {
        [$ok, $err] = send_mail($me['email'], $me['name'], 'Test email from ' . setting('org_name') . ' ERP', mail_wrap('<p>This is a test. Email sending works.</p>'));
        flash($ok ? 'Test email sent to ' . $me['email'] . '. Check your inbox (and spam folder).' : 'Sending failed: ' . $err, $ok ? 'ok' : 'err');
    }
    redirect('email_settings.php');
}

render_header('Email settings');
if (is_demo()): ?><div class="flash warn">In the demo, emails are never sent: supplier links are shown on screen instead.</div><?php endif; ?>
<div class="card">
  <p>Used to send RFQ invitations to suppliers. On LWS, create a mailbox in cPanel (e.g. <i>procurement@yourdomain</i>) and use its settings below
  (cPanel &gt; Email Accounts &gt; Connect Devices shows the outgoing server and port).</p>
  <p class="muted">If email is not set up, the ERP still works: it shows each supplier's link so you can send it yourself.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="act" value="save">
    <div class="row">
      <div style="flex:2"><label>Outgoing (SMTP) server</label><input type="text" name="smtp_host" value="<?= e(setting('smtp_host')) ?>" placeholder="e.g. mail.yourdomain.org"></div>
      <div><label>Port</label><input type="number" name="smtp_port" value="<?= e(setting('smtp_port', '465')) ?>"></div>
      <div><label>Security</label><select name="smtp_secure"><?php foreach (['ssl' => 'SSL (port 465)', 'tls' => 'STARTTLS (port 587)', 'none' => 'None'] as $k => $l): ?><option value="<?= $k ?>" <?= setting('smtp_secure', 'ssl') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="row">
      <div><label>Username (usually the full email address)</label><input type="text" name="smtp_user" value="<?= e(setting('smtp_user')) ?>" autocomplete="off"></div>
      <div><label>Password <?= setting('smtp_pass') !== '' ? '<span class="muted">(saved; leave empty to keep)</span>' : '' ?></label><input type="password" name="smtp_pass" autocomplete="new-password"></div>
    </div>
    <div class="row">
      <div><label>Sender email</label><input type="email" name="mail_from" value="<?= e(setting('mail_from')) ?>"></div>
      <div><label>Sender name</label><input type="text" name="mail_from_name" value="<?= e(setting('mail_from_name', setting('org_name') . ' Procurement')) ?>"></div>
      <div><label>Replies go to <span class="muted">(optional)</span></label><input type="email" name="mail_reply_to" value="<?= e(setting('mail_reply_to')) ?>"></div>
    </div>
    <div class="actions"><button class="btn">Save</button></div>
  </form>
  <form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="act" value="test"><button class="btn sec" <?= mail_configured() ? '' : 'disabled' ?>>Send a test email to me</button></form>
</div>
<?php render_footer();
