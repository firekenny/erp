<?php
declare(strict_types=1);

/** Line icons (24x24, stroke) for the menu */
function nav_icon(string $href): string {
    static $P = [
        'index.php' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'pr.php' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
        'requests.php' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 12h6M9 16h6"/>',
        'help.php' => '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2.5-3 4.5M12 17.5h.01"/>',
        'rfq.php' => '<path d="m3 11 18-8-8 18-2-8z"/>',
        'po.php' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.5L21 8H6"/>',
        'suppliers.php' => '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'invoices.php' => '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        'budgets.php' => '<path d="M21 12A9 9 0 1 1 12 3v9z"/><path d="M15 3.5A9 9 0 0 1 20.5 9H15z"/>',
        'receive.php' => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'stock.php' => '<path d="m12 2 9 5-9 5-9-5z"/><path d="m3 12 9 5 9-5M3 17l9 5 9-5"/>',
        'issues.php' => '<path d="M7 7h13l-3-3M17 17H4l3 3"/>',
        'counts.php' => '<path d="M9 6h11M9 12h11M9 18h11M4 6l1 1 2-2M4 12l1 1 2-2M4 18l1 1 2-2"/>',
        'trace.php' => '<circle cx="6" cy="19" r="2"/><circle cx="18" cy="5" r="2"/><path d="M6 17V9a4 4 0 0 1 4-4h6M18 7v8a4 4 0 0 1-4 4H8"/>',
        'assets.php' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M2 20h20"/>',
        'handovers.php' => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M16 11l2 2 4-4"/>',
        'asset_counts.php' => '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 12h10"/>',
        'depreciation.php' => '<path d="m3 7 7 7 4-4 7 7M21 11v6h-6"/>',
        'employees.php' => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M16 3.5a4 4 0 0 1 0 9M22 21a6 6 0 0 0-4-5.6"/>',
        'locations.php' => '<path d="M12 21s-7-6.1-7-11a7 7 0 0 1 14 0c0 4.9-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'settings.php' => '<path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6M9 10h.01M15 10h.01"/>',
        'users.php' => '<circle cx="10" cy="8" r="4"/><path d="M3 21a7 7 0 0 1 11-5.7"/><circle cx="18" cy="17" r="2.5"/><path d="M18 13v1.5M18 19.5V21M14.5 17H16M20 17h1.5"/>',
        'finance.php' => '<path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/>',
        'approvals.php' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'methods.php' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        'email_settings.php' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'sso_settings.php' => '<circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.2-8.2M17 6l3 3M14 9l2 2"/>',
        'audit.php' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'categories.php' => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'items.php' => '<path d="M16.5 9.4 7.5 4.2M21 16V8l-9-5-9 5v8l9 5z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',
        'warehouses.php' => '<path d="M2 21V8l10-5 10 5v13M6 21v-9h12v9M6 16h12"/>',
    ];
    return '<svg class="ni" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($P[$href] ?? '<circle cx="12" cy="12" r="3"/>') . '</svg>';
}

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
        $nav[] = ['Help', 'help.php'];
        if (has_role('procurement') || has_role('admin')) {
            $nav[] = ['Procurement', null];
            $nWait = (int)q("SELECT COUNT(*) FROM pr WHERE status = 'approved'")->fetchColumn();
            $nav[] = ['RFQs' . ($nWait ? " ($nWait)" : ''), 'rfq.php'];
            $nav[] = ['Purchase Orders', 'po.php'];
            $nav[] = ['Suppliers', 'suppliers.php'];
        }
        if (has_role('finance') || has_role('procurement') || has_role('admin') || has_role('budget_holder')) {
            $nav[] = ['Finance', null];
            if (has_role('finance') || has_role('procurement') || has_role('admin')) {
                $nHold = (int)q("SELECT COUNT(*) FROM invoices WHERE status = 'on_hold'")->fetchColumn();
                $nav[] = ['Supplier invoices' . ($nHold ? " ($nHold on hold)" : ''), 'invoices.php'];
            }
            $nav[] = ['Budgets', 'budgets.php'];
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
            if (has_role('storekeeper') || has_role('admin')) $nav[] = ['Asset counts', 'asset_counts.php'];
            $nav[] = ['Depreciation', 'depreciation.php'];
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
            $nav[] = ['Microsoft sign-in', 'sso_settings.php'];
            $nav[] = ['Audit log', 'audit.php'];
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
<link rel="stylesheet" href="assets/style.css?v=9">
</head>
<body class="<?= ($u && ($_COOKIE['navmini'] ?? '') === '1') ? 'nav-mini' : '' ?>">
<?php if (function_exists('is_demo') && is_demo()): ?>
<div class="demo-bar">Demo version · sample data, reset every night<?php if ($u): ?> · <a href="logout.php">Switch role</a><?php endif; ?></div>
<?php endif; ?>
<?php if ($u): ?>
<header class="topbar">
  <button class="menu-btn" onclick="const n=document.querySelector('nav.side'),b=this.closest('header').getBoundingClientRect().bottom;n.style.top=b+'px';n.style.height='calc(100vh - '+b+'px)';document.body.classList.toggle('nav-open')" aria-label="Menu">☰</button>
  <span class="brand"><?= e($org) ?> <small>ERP</small></span>
  <span class="who"><a href="account.php" style="text-decoration:none"><?= e($u['name']) ?></a> · <a href="logout.php">Sign out</a></span>
</header>
<div class="shell">
<nav class="side" aria-label="Main menu">
  <button type="button" class="nav-toggle" onclick="navMini()" aria-label="Collapse or expand the menu" title="Collapse / expand menu"><svg class="ni" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9 4v16M15 10l-2 2 2 2"/></svg><span class="nl">Collapse menu</span></button>
<?php foreach ($nav as [$label, $href]): $isOn = $page === $href || ($href === 'pr.php' && str_starts_with($page, 'pr_')) || ($href === 'rfq.php' && str_starts_with($page, 'rfq')) || ($href === 'po.php' && str_starts_with($page, 'po_')) || ($href === 'receive.php' && $page === 'grn_view.php') || ($href === 'requests.php' && $page === 'request_view.php') || ($href === 'issues.php' && $page === 'issue_view.php') || ($href === 'assets.php' && in_array($page, ['asset_view.php', 'assets_import.php'], true)) || ($href === 'handovers.php' && in_array($page, ['handover.php', 'handover_view.php'], true)) || ($href === 'invoices.php' && $page === 'invoice_view.php');
    $badge = '';
    if ($href !== null && preg_match('/^(.*?) \((\d+)( [^)]*)?\)$/', $label, $m)) { $label = $m[1]; $badge = $m[2]; } ?>
  <?php if ($href === null): ?><div class="nav-h"><span><?= e($label) ?></span></div>
  <?php else: ?><a href="<?= e($href) ?>" class="<?= $isOn ? 'on' : '' ?>" data-label="<?= e($label . ($badge !== '' ? " ($badge)" : '')) ?>"><?= nav_icon($href) ?><span class="nl"><?= e($label) ?></span><?php if ($badge !== ''): ?><span class="nb"><?= e($badge) ?></span><?php endif; ?></a><?php endif; ?>
<?php endforeach; ?>
</nav>
<div id="navtip" role="tooltip"></div>
<script>
function navMini(){ const on = document.body.classList.toggle('nav-mini'); document.cookie = 'navmini=' + (on ? '1' : '0') + ';path=/;max-age=31536000;samesite=lax'; }
(() => { const tip = document.getElementById('navtip');
  document.querySelectorAll('nav.side a').forEach(a => {
    a.addEventListener('mouseenter', () => { if (!document.body.classList.contains('nav-mini') || window.innerWidth <= 760) return;
      const r = a.getBoundingClientRect(); tip.textContent = a.dataset.label; tip.style.top = (r.top + r.height / 2) + 'px'; tip.style.left = (r.right + 10) + 'px'; tip.classList.add('show'); });
    a.addEventListener('mouseleave', () => tip.classList.remove('show'));
  });
  const nav = document.querySelector('nav.side'), on = nav && nav.querySelector('a.on'); if (on && on.offsetTop > nav.clientHeight - 60) nav.scrollTop = on.offsetTop - nav.clientHeight / 2;
})();
</script>
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
