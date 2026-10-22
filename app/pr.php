<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/pr.php';
$u = require_login();

$canAll = has_role('procurement') || has_role('admin');
$tabs = [];
if (has_role('requester')) $tabs['mine'] = 'My requests';
if (has_role('budget_holder') || has_role('admin')) $tabs['approve'] = 'To approve';
if ($canAll) $tabs['all'] = 'All requests';
if (!$tabs) { render_header('Purchase Requests'); echo '<div class="card">Your role has no access to Purchase Requests.</div>'; render_footer(); exit; }

$tab = $_GET['tab'] ?? array_key_first($tabs);
if (!isset($tabs[$tab])) $tab = array_key_first($tabs);
$st = (string)($_GET['status'] ?? '');
$s  = trim((string)($_GET['s'] ?? ''));

$where = []; $p = [];
if ($tab === 'mine') { $where[] = 'p.requester_id = ?'; $p[] = $u['id']; }
if ($tab === 'approve') {
    // own pending approvals; admins also see PRs where the requester is their own budget holder
    $where[] = "p.status = 'submitted' AND p.requester_id <> ? AND EXISTS (SELECT 1 FROM pr_approvals a WHERE a.pr_id = p.id AND a.status = 'pending'"
             . (has_role('admin') ? " AND (a.approver_id = ? OR a.approver_id = p.requester_id))" : " AND a.approver_id = ?)");
    $p[] = $u['id']; $p[] = $u['id'];
}
if ($tab === 'all') $where[] = "p.status <> 'draft'";
if (isset(PR_STATUS[$st]) && $tab !== 'approve') { $where[] = 'p.status = ?'; $p[] = $st; }
if ($s !== '') { $where[] = '(p.number LIKE ? OR p.title LIKE ?)'; $p[] = "%$s%"; $p[] = "%$s%"; }

$rows = q('SELECT p.*, u.name AS requester_name,
             (SELECT COALESCE(SUM(l.qty * l.unit_price), 0) FROM pr_lines l WHERE l.pr_id = p.id) AS total,
             (SELECT COUNT(*) FROM pr_lines l WHERE l.pr_id = p.id) AS n_lines
           FROM pr p JOIN users u ON u.id = p.requester_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY p.id DESC LIMIT 300', $p)->fetchAll();
$counts = pr_counts($u);

render_header('Purchase Requests'); ?>
<div class="actions" style="margin:0 0 12px">
  <?php foreach ($tabs as $k => $label): ?>
    <a class="btn small <?= $k === $tab ? '' : 'sec' ?>" href="?tab=<?= $k ?>"><?= e($label) ?><?php
      if ($k === 'approve' && $counts['to_approve'] + $counts['self_blocked']) echo ' (' . ($counts['to_approve'] + $counts['self_blocked']) . ')'; ?></a>
  <?php endforeach; ?>
  <?php if (has_role('requester')): ?><a class="btn" href="pr_edit.php" style="margin-left:auto">+ New Purchase Request</a><?php endif; ?>
</div>

<?php if ($tab !== 'approve'): ?>
<form method="get" class="row" style="margin-bottom:10px;align-items:flex-end">
  <input type="hidden" name="tab" value="<?= e($tab) ?>">
  <div style="flex:2"><input type="text" name="s" value="<?= e($s) ?>" placeholder="Search number or title"></div>
  <div><select name="status"><option value="">All statuses</option>
    <?php foreach (PR_STATUS as $k => [$label]): if ($tab === 'all' && $k === 'draft') continue; ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
  </select></div>
  <div style="flex:0"><button class="btn sec">Filter</button></div>
</form>
<?php endif; ?>

<div class="table-wrap"><table>
  <tr><th>Number</th><th>Title</th><?php if ($tab !== 'mine'): ?><th>Requester</th><?php endif; ?><th>Needed by</th><th style="text-align:right">Est. total</th><th>Status</th></tr>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted"><?= $tab === 'approve' ? 'Nothing waiting for your approval.' : 'No Purchase Requests yet.' ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><a href="pr_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['number']) ?></b></a></td>
    <td><?= e($r['title']) ?> <span class="muted">· <?= (int)$r['n_lines'] ?> line(s)</span></td>
    <?php if ($tab !== 'mine'): ?><td><?= e($r['requester_name']) ?></td><?php endif; ?>
    <td><?= $r['needed_by'] ? e(date('d M Y', strtotime($r['needed_by']))) : '' ?></td>
    <td style="text-align:right;white-space:nowrap"><?= number_format((float)$r['total'], 2) ?> <?= e($r['currency']) ?></td>
    <td><?= pr_badge($r['status']) ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php render_footer();
