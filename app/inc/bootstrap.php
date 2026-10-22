<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '0.9 (+ notifications and security)');

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('erp_session');
session_start();

if (!file_exists(APP_ROOT . '/config.php')) {
    header('Location: install.php');
    exit;
}
require APP_ROOT . '/config.php';
date_default_timezone_set(defined('APP_TZ') ? APP_TZ : 'UTC');
require __DIR__ . '/functions.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/demo.php';
require_once __DIR__ . '/pr.php';
require_once __DIR__ . '/award.php';
require_once __DIR__ . '/notify.php';

// Upgrade the database automatically after a new version is deployed
if ((int)(q("SELECT v FROM settings WHERE k = 'db_version'")->fetchColumn() ?: 0) < SCHEMA_VERSION) {
    run_migrations(db());
}


// Public demo: fresh sample data every night
if (is_demo()) demo_reset_if_due();
// Automatic logout after inactivity (Organisation settings, default 60 minutes)
if (!empty($_SESSION['uid'])) {
    $limit = max(5, (int)setting('session_timeout_min', '60')) * 60;
    if (!empty($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > $limit) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('You were signed out after a period of inactivity.', 'warn');
        if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'login.php') redirect('login.php');
    } else {
        $_SESSION['last_seen'] = time();
    }
}
csrf_check();
