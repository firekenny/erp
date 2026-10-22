<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/award.php';
require_any(['procurement', 'admin']);

$st = (string)($_GET['status'] ?? '');
$s = trim((string)($_GET['s'] ?? ''));
$where = ['1=1']; $p = [];
if (isset(PO_STATUS[$st])) { $where[] = 'p.status = ?'; $p[] = $st; }
if ($s !== '') { $where[] = '(p.number LIKE ? OR s.name LIKE ? OR pr.number LIKE ?)'; array_push($p, "%$s%", "%$s%", "%$s%"); }
$rows = q('SELECT p.*, s.name AS supplier_name, pr.number AS pr_number, pr.title,
             (SELECT COALESCE(SUM(l.qty * l.unit_price), 0) FROM po_lines l WHERE l.po_id = p.id) AS total
           FROM po p JOIN suppliers s ON s.id = p.supplier_id JOIN pr ON pr.id = p.pr_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT 300', $p)->fetchAll();

render_header('Purchase Orders'); ?>
<form method="get" class="row" style="margin-bottom:10px;align-items:flex-end">
  <div style="flex:2"><input type="text" name="s" value="<?= e($s) ?>" placeholder="Search PO, supplier or request number"></div>
  <div><select name="status"><option value="">All statuses</option><?php foreach (PO_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div style="flex:0"><button class="btn sec">Filter</button></div>
</form>
<div class="table-wrap"><table>
  <tr><th>PO</th><th>Supplier</th><th>For</th><th>Delivery date</th><th style="text-align:right">Total</th><th>Status</th></tr>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">No Purchase Orders yet. They are created automatically when an award is approved.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><a href="po_view.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['number']) ?></b></a></td>
    <td><?= e($r['supplier_name']) ?></td>
    <td><?= e($r['title']) ?><br><span class="muted"><?= e($r['pr_number']) ?></span></td>
    <td><?= $r['delivery_date'] ? e(date('d M Y', strtotime($r['delivery_date']))) : '—' ?></td>
    <td style="text-align:right;white-space:nowrap"><?= number_format((float)$r['total'], 2) ?> <?= e($r['currency']) ?></td>
    <td><?= po_badge($r['status']) ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php render_footer();
