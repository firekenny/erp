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
        if (function_exists('pr_counts')) { $c = pr_counts($u); $n = $c['to_approve'] + $c['self_blocked'] + $c['returned'] + (function_exists('award_pending_count') ? award_pending_count($u) : 0); if ($n) $prLabel .= " ($n)"; }
        $nav[] = [$prLabel, 'pr.php'];
        if (has_role('requester') && !has_role('storekeeper')) $nav[] = ['Stock requests', 'requests.php'];
        if (has_role('procurement') || has_role('admin')) {
            $nav[] = ['Procurement', null];
            $nWait = (int)q("SELECT COUNT(*) FROM pr WHERE status = 'approved'")->fetchColumn();
            $nav[] = ['RFQs' . ($nWait ? " ($nWait)" : ''), 'rfq.php'];
            $nav[] = ['Purchase Orders', 'po.php'];
            $nav[] = ['Suppliers', 'suppliers.php'];
        }
        if (has_role('storekeeper') || has_role('procurement') || has_role('admin')) {
            $nav[] = ['Warehouse', null];
            if (has_role('storekeeper') || has_role('admin')) {
                $nRec = (int)q("SELECT COUNT(*) FROM po WHERE status IN ('issued','acknowledged','partially_received')")->fetchColumn();
                $nav[] = ['Receiving' . ($nRec ? " ($nRec)" : ''), 'receive.php'];
            }
            if (has_role('storekeeper') || has_role('admin')) {
                $nSr = (int)q("SELECT COUNT(*) FROM stock_requests WHERE status IN ('submitted','partially_issued')")->fetchColumn();
                $nav[] = ['Stock requests' . ($nSr ? " ($nSr)" : ''), 'requests.php'];
            }
            $nav[] = ['Stock', 'stock.php'];
            $nav[] = ['Issues & transfers', 'issues.php'];
            if (has_role('storekeeper') || has_role('admin')) {
                $nCnt = has_role('admin') ? (int)q("SELECT COUNT(*) FROM stock_counts WHERE status = 'submitted'")->fetchColumn() : 0;
                $nav[] = ['Stock counts' . ($nCnt ? " ($nCnt)" : ''), 'counts.php'];
            }
            $nav[] = ['Donor traceability', 'trace.php'];
            $nav[] = ['Assets', null];
            $nav[] = ['Asset register', 'assets.php'];
            $nUns = (int)q("SELECT COUNT(*) FROM handovers WHERE type = 'handover' AND signed_at IS NULL")->fetchColumn();
            $nav[] = ['Handovers' . ($nUns ? " ($nUns)" : ''), 'handovers.php'];
            if (has_role('storekeeper') || has_role('admin')) { $nav[] = ['Staff', 'employees.php']; $nav[] = ['Asset locations', 'locations.php']; }
        }
        if (has_role('admin')) {
            $nav[] = ['Setup', null];
            $nav[] = ['Organisation', 'settings.php'];
            $nav[] = ['Users & roles', 'users.php'];
            $nav[] = ['Finance codes', 'finance.php'];
            $nav[] = ['Approval thresholds', 'approvals.php'];
            $nav[] = ['Procurement methods', 'methods.php'];
            $nav[] = ['Email settings', 'email_settings.php'];
            $nav[] = ['Item categories', 'categories.php'];
            $nav[] = ['Item catalogue', 'items.php'];
            $nav[] = ['Warehouses', 'warehouses.php'];
        }
    }
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($org) ?></title>
<link rel="stylesheet" href="assets/style.css?v=7">
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
<?php foreach ($nav as [$label, $href]): $isOn = $page === $href || ($href === 'pr.php' && str_starts_with($page, 'pr_')) || ($href === 'rfq.php' && str_starts_with($page, 'rfq')) || ($href === 'po.php' && str_starts_with($page, 'po_')) || ($href === 'receive.php' && $page === 'grn_view.php') || ($href === 'requests.php' && $page === 'request_view.php') || ($href === 'issues.php' && $page === 'issue_view.php') || ($href === 'assets.php' && in_array($page, ['asset_view.php', 'assets_import.php'], true)) || ($href === 'handovers.php' && in_array($page, ['handover.php', 'handover_view.php'], true)); ?>
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
