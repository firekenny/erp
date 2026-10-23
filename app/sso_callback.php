<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/sso.php';
if (!sso_enabled()) redirect('login.php');
if (isset($_GET['error'])) {
    flash('Microsoft sign-in was not completed' . (($_GET['error'] ?? '') === 'access_denied' ? '.' : ': ' . mb_substr((string)($_GET['error_description'] ?? $_GET['error']), 0, 200)), 'err');
    redirect('login.php');
}
try {
    $c = sso_finish((string)($_GET['code'] ?? ''), (string)($_GET['state'] ?? ''));
} catch (Throwable $e) {
    q('INSERT INTO audit_log (user_id, action, details) VALUES (NULL, ?, ?)', ['login.sso_failed', mb_substr($e->getMessage(), 0, 250)]);
    flash($e->getMessage(), 'err'); redirect('login.php');
}
$oid = (string)($c['oid'] ?? '');
$email = strtolower(trim((string)($c['email'] ?? $c['preferred_username'] ?? '')));
$u = $oid !== '' ? q('SELECT * FROM users WHERE ms_oid = ?', [$oid])->fetch() : false;
if (!$u && $email !== '') {
    $u = q('SELECT * FROM users WHERE email = ? AND ms_oid IS NULL', [$email])->fetch();
    if ($u && $oid !== '') q('UPDATE users SET ms_oid = ? WHERE id = ?', [$oid, $u['id']]);   // first Microsoft sign-in: link the account
}
if (!$u || !(int)$u['active']) {
    q('INSERT INTO audit_log (user_id, action, details) VALUES (NULL, ?, ?)', ['login.sso_refused', "$email has no active ERP account"]);
    flash("Your Microsoft account ($email) has no access to this ERP. Ask your administrator to add you under Users & roles with this email address.", 'err');
    redirect('login.php');
}
session_regenerate_id(true);
$_SESSION['uid'] = (int)$u['id'];
$_SESSION['last_seen'] = time();
q('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
q('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)', [$u['id'], 'login', 'Microsoft']);
redirect('index.php');
