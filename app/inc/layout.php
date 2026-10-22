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
        $nav[] = ['Purchase Requests', 'pr.php'];
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
<link rel="stylesheet" href="assets/style.css?v=1">
</head>
<body>
<?php if ($u): ?>
<header class="topbar">
  <button class="menu-btn" onclick="document.body.classList.toggle('nav-open')" aria-label="Menu">☰</button>
  <span class="brand"><?= e($org) ?> <small>ERP</small></span>
  <span class="who"><?= e($u['name']) ?> · <a href="logout.php">Sign out</a></span>
</header>
<div class="shell">
<nav class="side">
<?php foreach ($nav as [$label, $href]): ?>
  <?php if ($href === null): ?><div class="nav-h"><?= e($label) ?></div>
  <?php else: ?><a href="<?= e($href) ?>" class="<?= $page === $href ? 'on' : '' ?>"><?= e($label) ?></a><?php endif; ?>
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
