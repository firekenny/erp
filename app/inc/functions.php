<?php
declare(strict_types=1);

/* ---------- Database ---------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");   // NOW() matches the app's local time
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

/* ---------- Output ---------- */
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function redirect(string $url): void { header('Location: ' . $url); exit; }

function flash(?string $msg = null, string $type = 'ok'): ?array {
    if ($msg !== null) { $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type]; return null; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function base_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

/* ---------- CSRF ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Session expired. Please go back and reload the page.');
    }
}

/* ---------- Users & roles ---------- */
const ROLES = [
    'admin'        => 'Administrator',
    'requester'    => 'Requester',
    'budget_holder'=> 'Budget Holder',
    'procurement'  => 'Procurement Officer',
    'storekeeper'  => 'Storekeeper',
];

function current_user(): ?array {
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = q('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']])->fetch() ?: null;
            if (!$u) unset($_SESSION['uid']);
        }
    }
    return $u;
}

function user_roles(?array $u = null): array {
    $u = $u ?? current_user();
    return $u ? array_filter(explode(',', (string)$u['roles'])) : [];
}
function has_role(string $role): bool { return in_array($role, user_roles(), true); }

function require_login(): array {
    $u = current_user();
    if (!$u) redirect('login.php');
    return $u;
}
function require_role(string $role): array {
    $u = require_login();
    if (!has_role($role)) { http_response_code(403); render_header('Access denied'); echo '<div class="card"><p>You do not have access to this page.</p></div>'; render_footer(); exit; }
    return $u;
}

/* ---------- Settings & audit ---------- */
function setting(string $k, string $default = ''): string {
    $r = q('SELECT v FROM settings WHERE k = ?', [$k])->fetchColumn();
    return $r === false ? $default : (string)$r;
}
function set_setting(string $k, string $v): void {
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}
function audit(string $action, string $details = ''): void {
    $u = current_user();
    q('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)', [$u['id'] ?? null, $action, $details]);
}

/* ---------- CSV import (Excel "Save as CSV", comma or semicolon) ---------- */
function read_csv_upload(string $field): array {
    if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) return [];
    $raw = file_get_contents($_FILES[$field]['tmp_name']);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);                 // strip Excel BOM
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    $first = strtok($raw, "\r\n");
    $delim = substr_count((string)$first, ';') > substr_count((string)$first, ',') ? ';' : ',';
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
    $header = null; $rows = [];
    while (($line = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        if ($line === [null] || implode('', $line) === '') continue;
        $line = array_map(fn($c) => trim((string)$c), $line);
        if ($header === null) { $header = array_map(fn($h) => strtolower(str_replace(' ', '_', $h)), $line); continue; }
        $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
    }
    fclose($fh);
    return $rows;
}

function send_csv(string $filename, array $header, array $rows = []): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ',', '"', '\\');
    foreach ($rows as $r) fputcsv($out, $r, ',', '"', '\\');
    exit;
}

/* ---------- Finance helpers ---------- */
function fin_levels(): array { return q('SELECT * FROM fin_levels ORDER BY position')->fetchAll(); }

/* ---------- Access for several roles ---------- */
function require_any(array $roles): array {
    $u = require_login();
    foreach ($roles as $r) if (has_role($r)) return $u;
    http_response_code(403); render_header('Access denied'); echo '<div class="card"><p>You do not have access to this page.</p></div>'; render_footer(); exit;
}

/* ---------- Procurement methods ---------- */
/** Active methods, smallest band first; the band with no upper limit comes last */
function proc_methods(bool $activeOnly = true): array {
    return q('SELECT * FROM proc_methods' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY max_amount IS NULL, max_amount, id')->fetchAll();
}
/** Method that applies to an amount, or null if none is set up */
function proc_method_for(float $amount): ?array {
    foreach (proc_methods() as $m) if ($m['max_amount'] === null || $amount <= (float)$m['max_amount']) return $m;
    return null;
}
