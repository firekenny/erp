<?php
declare(strict_types=1);

function render_header(string $title): void {
    $u = current_user();
    $org = $u ? setting('org_name', 'ERP') : 'ERP';
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $nav = [];
    if ($u) {
        $nav[] = ['Main', null];
        $nav[] = ['Dashboard', 'index.php'];
        $prLabel = 'Purchase Requests';
        if (function_exists('pr_counts')) { $c = pr_counts($u); $n = $c['to_approve'] + $c['self_blocked'] + $c['returned']; if ($n) $prLabel .= " ($n)"; }
        $nav[] = [$prLabel, 'pr.php'];
        if (has_role('admin')) {
            $nav[] = ['Setup', null];
            $nav[] = ['Organisation', 'settings.php'];
            $nav[] = ['Users & roles', 'users.php'];
            $nav[] = ['Finance codes', 'finance.php'];
            $nav[] = ['Item categories', 'categories.php'];
            $nav[] = ['Item catalogue', 'items.php'];
        }
    }
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($org) ?></title>
<link rel="stylesheet" href="assets/style.css?v=3">
</head>
<body>
<?php if (function_exists('is_demo') && is_demo()): ?>
<div class="demo-bar">Demo version · sample data, reset every night<?php if ($u): ?> · <a href="logout.php">Switch role</a><?php endif; ?></div>
<?php endif; ?>
<?php if ($u): ?>
<header class="topbar">
  <button class="menu-btn" onclick="document.body.classList.toggle('nav-open')" aria-label="Menu">☰</button>
  <span class="brand"><?= e($org) ?> <small>ERP</small></span>
  <span class="who"><?= e($u['name']) ?> · <a href="logout.php">Sign out</a></span>
</header>
<div class="shell">
<nav class="side">
<?php foreach ($nav as [$label, $href]): $isOn = $page === $href || ($href === 'pr.php' && str_starts_with($page, 'pr_')); ?>
  <?php if ($href === null): ?><div class="nav-h"><?= e($label) ?></div>
  <?php else: ?><a href="<?= e($href) ?>" class="<?= $isOn ? 'on' : '' ?>"><?= e($label) ?></a><?php endif; ?>
<?php endforeach; ?>
</nav>
<main>
<?php else: ?>
<main class="solo">
<?php endif; ?>
<h1><?= e($title) ?></h1>
<?php foreach (flash() ?? [] as $f): ?>
  <div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach;
}

function render_footer(): void {
    $u = current_user(); ?>
</main>
<?php if ($u): ?></div><?php endif; ?>
</body>
</html>
<?php
}
