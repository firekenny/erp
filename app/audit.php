<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/xlsx.php';
require_role('admin');
$f = ['u' => (int)($_GET['u'] ?? 0), 'a' => trim((string)($_GET['a'] ?? '')), 'from' => (string)($_GET['from'] ?? date('Y-m-d', strtotime('-30 days'))), 'to' => (string)($_GET['to'] ?? date('Y-m-d'))];
foreach (['from', 'to'] as $k) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) $f[$k] = date('Y-m-d');
$where = ['DATE(l.created_at) BETWEEN ? AND ?']; $p = [$f['from'], $f['to']];
if ($f['u']) { $where[] = 'l.user_id = ?'; $p[] = $f['u']; }
if ($f['a'] !== '') { $where[] = '(l.action LIKE ? OR l.details LIKE ?)'; $p[] = '%' . $f['a'] . '%'; $p[] = '%' . $f['a'] . '%'; }
$rows = q('SELECT l.*, u.name FROM audit_log l LEFT JOIN users u ON u.id = l.user_id WHERE ' . implode(' AND ', $where) . ' ORDER BY l.id DESC LIMIT 2000', $p)->fetchAll();
if (isset($_GET['xlsx'])) xlsx_send('audit_log_' . $f['from'] . '_' . $f['to'] . '.xlsx', [['name' => 'Audit log', 'header' => ['Date and time', 'User', 'Action', 'Details'],
    'rows' => array_map(fn($r) => [$r['created_at'], $r['name'] ?? '(not signed in)', $r['action'], $r['details']], $rows)]]);
render_header('Audit log'); ?>
<form method="get" class="card row" style="align-items:flex-end">
  <div><label>User</label><select name="u"><option value="0">Everyone</option><?php foreach (q('SELECT id, name FROM users ORDER BY name')->fetchAll() as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $f['u'] === (int)$x['id'] ? 'selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>Action or text</label><input type="text" name="a" value="<?= e($f['a']) ?>" placeholder="e.g. login, user., PR-2026"></div>
  <div><label>From</label><input type="date" name="from" value="<?= e($f['from']) ?>"></div><div><label>To</label><input type="date" name="to" value="<?= e($f['to']) ?>"></div>
  <div style="flex:0;white-space:nowrap"><button class="btn sec">Filter</button> <a class="btn sec" href="?<?= e(http_build_query($f + ['xlsx' => 1])) ?>">Excel</a></div>
</form>
<p class="muted">Settings, users, imports, logins, award and count decisions, receipts and issues are logged here. Each document (PR, RFQ, PO, asset…) also keeps its own history.</p>
<div class="table-wrap"><table><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th></tr>
  <?php if (!$rows): ?><tr><td colspan="4" class="muted">Nothing in this period.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr><td style="white-space:nowrap" class="muted"><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></td><td><?= e($r['name'] ?? '—') ?></td>
    <td><span class="tag <?= str_contains($r['action'], 'locked') ? 'warn' : '' ?>"><?= e($r['action']) ?></span></td><td><?= e($r['details']) ?></td></tr><?php endforeach; ?>
</table></div>
<?php render_footer();
