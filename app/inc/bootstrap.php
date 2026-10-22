<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '0.1 (Phase 1 – setup)');

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('erp_session');
session_start();

if (!file_exists(APP_ROOT . '/config.php')) {
    header('Location: install.php');
    exit;
}
require APP_ROOT . '/config.php';
require __DIR__ . '/functions.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/schema.php';
require __DIR__ . '/demo.php';

// Upgrade the database automatically after a new version is deployed
if ((int)(q("SELECT v FROM settings WHERE k = 'db_version'")->fetchColumn() ?: 0) < SCHEMA_VERSION) {
    run_migrations(db());
}

date_default_timezone_set(defined('APP_TZ') ? APP_TZ : 'UTC');

// Public demo: fresh sample data every night
if (is_demo()) demo_reset_if_due();
csrf_check();
