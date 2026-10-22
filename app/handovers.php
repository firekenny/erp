<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';
require_any(['storekeeper', 'admin', 'procurement']);
$f = (string)($_GET['f'] ?? '');
$rows = q("SELECT h.*, e.name AS emp_name, l.name AS loc_name, (SELECT COUNT(*) FROM handover_lines x WHERE x.handover_id = h.id) AS n
           FROM handovers h LEFT JOIN employees e ON e.id = h.employee_id LEFT JOIN locations l ON l.id = h.location_id"
         . ($f === 'unsigned' ? " WHERE h.type = 'handover' AND h.signed_at IS NULL" : '') . ' ORDER BY h.id DESC LIMIT 300')->fetchAll();
render_header('Handovers & movements'); ?>
<div class="actions" style="margin:0 0 12px"><a class="btn small <?= $f === '' ? '' : 'sec' ?>" href="handovers.php">All</a><a class="btn small <?= $f === 'unsigned' ? '' : 'sec' ?>" href="?f=unsigned">Waiting for signature</a>
  <span class="muted" style="margin-left:auto">To create one, select assets in the <a href="assets.php">asset register</a>.</span></div>
<div class="table-wrap"><table>
  <tr><th>Document</th><th>Date</th><th>Type</th><th>To</th><th>Assets</th><th>Signature</th></tr>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">Nothing yet.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr><td><a href="handover_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['number']) ?></b></a></td><td><?= e(date('d M Y', strtotime($r['created_at']))) ?></td>
    <td><?= e(HANDOVER_TYPES[$r['type']]) ?></td><td><?= e(asset_where($r['loc_name'], $r['emp_name'])) ?></td><td><?= (int)$r['n'] ?></td>
    <td><?= $r['type'] !== 'handover' ? '<span class="muted">—</span>' : ($r['signed_at'] ? '<span class="tag stock">Signed</span>' : '<span class="tag warn">Waiting</span>') ?></td></tr><?php endforeach; ?>
</table></div>
<?php render_footer();
