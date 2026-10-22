<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/rfq.php';
require_any(['procurement', 'admin']);

$st = (string)($_GET['status'] ?? '');
$s = trim((string)($_GET['s'] ?? ''));
$where = ['1=1']; $p = [];
if (isset(RFQ_STATUS[$st])) { $where[] = 'r.status = ?'; $p[] = $st; }
if ($s !== '') { $where[] = '(r.number LIKE ? OR r.title LIKE ? OR p.number LIKE ?)'; array_push($p, "%$s%", "%$s%", "%$s%"); }
$rows = q('SELECT r.*, p.number AS pr_number,
             (SELECT COUNT(*) FROM rfq_suppliers rs WHERE rs.rfq_id = r.id) AS n_sup,
             (SELECT COUNT(*) FROM quotes qq WHERE qq.rfq_id = r.id) AS n_q
           FROM rfq r JOIN pr p ON p.id = r.pr_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC LIMIT 300', $p)->fetchAll();
$waiting = q("SELECT p.id, p.number, p.title, (SELECT COALESCE(SUM(l.qty * l.unit_price), 0) FROM pr_lines l WHERE l.pr_id = p.id) AS total, p.currency, p.approved_at
              FROM pr p WHERE p.status = 'approved' ORDER BY p.approved_at")->fetchAll();

render_header('RFQs');
if ($waiting): ?>
<div class="card">
  <h2>Approved requests waiting for an RFQ (<?= count($waiting) ?>)</h2>
  <div class="table-wrap"><table>
    <tr><th>Request</th><th>Title</th><th style="text-align:right">Est. total</th><th>Method</th><th></th></tr>
    <?php foreach ($waiting as $w): $m = proc_method_for((float)$w['total']); ?>
    <tr>
      <td><a href="pr_view.php?id=<?= (int)$w['id'] ?>"><b><?= e($w['number']) ?></b></a></td>
      <td><?= e($w['title']) ?></td>
      <td style="text-align:right;white-space:nowrap"><?= number_format((float)$w['total'], 2) ?> <?= e($w['currency']) ?></td>
      <td><?= $m ? e($m['name']) : '<span class="tag warn">not set up</span>' ?></td>
      <td><?php if ($m): ?><form method="post" action="rfq_view.php"><?= csrf_field() ?><input type="hidden" name="act" value="create"><input type="hidden" name="pr_id" value="<?= (int)$w['id'] ?>"><button class="btn small">Create RFQ</button></form><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>

<form method="get" class="row" style="margin-bottom:10px;align-items:flex-end">
  <div style="flex:2"><input type="text" name="s" value="<?= e($s) ?>" placeholder="Search RFQ, PR number or title"></div>
  <div><select name="status"><option value="">All statuses</option><?php foreach (RFQ_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div style="flex:0"><button class="btn sec">Filter</button></div>
</form>
<div class="table-wrap"><table>
  <tr><th>RFQ</th><th>Title</th><th>Method</th><th>Deadline</th><th>Quotes</th><th>Status</th></tr>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">No RFQs yet. Create one from an approved Purchase Request.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><a href="rfq_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['number']) ?></b></a><br><span class="muted"><?= e($r['pr_number']) ?></span></td>
    <td><?= e($r['title']) ?></td>
    <td><?= e($r['method_name']) ?><?= $r['sealed'] ? ' <span class="tag">sealed</span>' : '' ?></td>
    <td style="white-space:nowrap"><?= $r['deadline'] ? e(date('d M Y H:i', strtotime($r['deadline']))) : '—' ?></td>
    <td><?= (int)$r['n_q'] ?> / <?= (int)$r['n_sup'] ?> <span class="muted">(min <?= (int)$r['min_quotes'] ?>)</span></td>
    <td><?= rfq_badge($r) ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php render_footer();
