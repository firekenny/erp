<?php
declare(strict_types=1);
/*
 * One-time installer: connects to the MySQL database, creates the tables,
 * writes config.php and creates the first Administrator.
 * Locks itself as soon as config.php exists.
 */
session_start();
$root = __DIR__;
require $root . '/inc/schema.php';
require $root . '/inc/demo.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$locked = file_exists($root . '/config.php');
$errors = [];
$done = false;
$in = [
    'db_host' => $_POST['db_host'] ?? 'localhost',
    'db_name' => $_POST['db_name'] ?? '',
    'db_user' => $_POST['db_user'] ?? '',
    'org_name' => $_POST['org_name'] ?? '',
    'currency' => $_POST['currency'] ?? 'USD',
    'admin_name' => $_POST['admin_name'] ?? '',
    'admin_email' => $_POST['admin_email'] ?? '',
];
$demo = !empty($_POST['demo']);

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $pw  = (string)($_POST['admin_pass'] ?? '');
    $pw2 = (string)($_POST['admin_pass2'] ?? '');
    $required = ['db_name' => 'Database name', 'db_user' => 'Database user'];
    if (!$demo) $required += ['org_name' => 'Organisation name', 'admin_name' => 'Your name', 'admin_email' => 'Your email'];
    foreach ($required as $k => $label) {
        if (trim($in[$k]) === '') $errors[] = "$label is required.";
    }
    if (!$demo) {
        if (!filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is not valid.';
        if (strlen($pw) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($pw !== $pw2) $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        try {
            $pdo = new PDO('mysql:host=' . $in['db_host'] . ';dbname=' . $in['db_name'] . ';charset=utf8mb4', $in['db_user'], $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            $errors[] = 'Could not connect to the database. Check the name, user and password (cPanel > MySQL Databases). Details: ' . $e->getMessage();
        }
    }
    if (!$errors) {
        try {
            run_migrations($pdo);
            if ($demo) {
                demo_seed($pdo);
                $pdo->prepare("INSERT INTO settings (k, v) VALUES ('demo_reset_on', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([date('Y-m-d')]);
            } else {
            $set = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
            $set->execute(['org_name', trim($in['org_name'])]);
            $set->execute(['currency', strtoupper(trim($in['currency'])) ?: 'USD']);
            $pdo->prepare("INSERT INTO users (name, email, password_hash, roles) VALUES (?, ?, ?, 'admin,requester,budget_holder,procurement')")
                ->execute([trim($in['admin_name']), strtolower(trim($in['admin_email'])), password_hash($pw, PASSWORD_DEFAULT)]);
            }

            $cfg = "<?php\n// Created by install.php on " . date('Y-m-d H:i') . ". Do not commit this file to GitHub.\n"
                 . "define('DB_HOST', " . var_export($in['db_host'], true) . ");\n"
                 . "define('DB_NAME', " . var_export($in['db_name'], true) . ");\n"
                 . "define('DB_USER', " . var_export($in['db_user'], true) . ");\n"
                 . "define('DB_PASS', " . var_export($dbPass, true) . ");\n"
                 . "define('APP_TZ', " . var_export($demo ? 'Africa/Nairobi' : 'Europe/Kyiv', true) . ");\n"
                 . "define('DEMO_MODE', " . ($demo ? 'true' : 'false') . ");\n";
            if (file_put_contents($root . '/config.php', $cfg) === false) {
                $errors[] = 'Tables were created, but config.php could not be written. Check folder permissions.';
            } else {
                @chmod($root . '/config.php', 0600);
                $done = true;
            }
        } catch (Throwable $e) {
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ERP installation</title><link rel="stylesheet" href="assets/style.css?v=1"></head>
<body><main class="solo" style="max-width:560px">
<h1>ERP installation</h1>
<?php if ($done): ?>
  <div class="flash ok"><?= $demo ? 'Demo installed with sample data. Visitors choose a role on the sign-in page.' : 'Installation complete. Your Administrator account is ready.' ?></div>
  <p><a class="btn" href="login.php">Go to sign in</a></p>
<?php elseif ($locked): ?>
  <div class="flash warn">The ERP is already installed. This page is locked.</div>
  <p><a class="btn" href="login.php">Go to sign in</a></p>
<?php else: ?>
  <?php foreach ($errors as $err): ?><div class="flash err"><?= h($err) ?></div><?php endforeach; ?>
  <form method="post" class="card">
    <h2>1. Database</h2>
    <p class="muted">Use the database and user you created in cPanel &gt; MySQL Databases (full names, including the account prefix).</p>
    <label>Database host</label><input type="text" name="db_host" value="<?= h($in['db_host']) ?>">
    <label>Database name</label><input type="text" name="db_name" value="<?= h($in['db_name']) ?>" required>
    <label>Database user</label><input type="text" name="db_user" value="<?= h($in['db_user']) ?>" required>
    <label>Database password</label><input type="password" name="db_pass">
    <h2 style="margin-top:20px">Type of installation</h2>
    <label class="checks" style="font-weight:400"><input type="checkbox" name="demo" value="1" <?= $demo ? 'checked' : '' ?> onchange="document.getElementById('real').style.display=this.checked?'none':''">
      <b>Public demo</b>: sample data, one-click demo accounts, reset every night. Leave unticked for a real organisation.</label>
    <div id="real" style="<?= $demo ? 'display:none' : '' ?>">
    <h2 style="margin-top:20px">2. Organisation</h2>
    <label>Organisation name</label><input type="text" name="org_name" value="<?= h($in['org_name']) ?>">
    <label>Main currency (3 letters)</label><input type="text" name="currency" maxlength="3" value="<?= h($in['currency']) ?>">
    <h2 style="margin-top:20px">3. Your Administrator account</h2>
    <label>Your name</label><input type="text" name="admin_name" value="<?= h($in['admin_name']) ?>">
    <label>Your email</label><input type="email" name="admin_email" value="<?= h($in['admin_email']) ?>">
    <label>Password (min. 8 characters)</label><input type="password" name="admin_pass">
    <label>Repeat password</label><input type="password" name="admin_pass2">
    </div>
    <div class="actions"><button class="btn">Install</button></div>
  </form>
<?php endif; ?>
</main></body></html>
