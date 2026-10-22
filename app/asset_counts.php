<?php
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';
require_once __DIR__ . '/inc/xlsx.php';
$u = require_any(['storekeeper', 'admin']);
function count_link(string $t): string { return base_url() . '/count.php?t=' . $t; }

/** Compare the count with the register. Returns [confirmed, updated, new, missing] */
function count_report(array $c): array {
    $scope = "a.status IN ('in_store','in_use','in_repair')" . ($c['location_id'] ? ' AND a.location_id = ' . (int)$c['location_id'] : '');
    $lines = q('SELECT l.*, t.name AS team, a.code, a.description, a.serial_no, a.location_id AS reg_loc, a.employee_id AS reg_emp, a.cond AS reg_cond,
                       rl.name AS reg_loc_name, re.name AS reg_emp_name, fl.name AS found_loc_name, fe.name AS found_emp_name
                FROM asset_count_lines l JOIN asset_count_teams t ON t.id = l.team_id LEFT JOIN assets a ON a.id = l.asset_id
                LEFT JOIN locations rl ON rl.id = a.location_id LEFT JOIN employees re ON re.id = a.employee_id
                LEFT JOIN locations fl ON fl.id = l.location_id LEFT JOIN employees fe ON fe.id = l.employee_id
                WHERE l.count_id = ? ORDER BY l.id', [$c['id']])->fetchAll();
    $latest = []; $new = [];
    foreach ($lines as $l) { if ($l['asset_id']) $latest[(int)$l['asset_id']] = $l; else $new[] = $l; }   // last count of an asset wins
    $confirmed = []; $updated = [];
    foreach ($latest as $l) {
        $same = (int)$l['location_id'] === (int)$l['reg_loc'] && (int)$l['employee_id'] === (int)$l['reg_emp'] && ($l['cond'] === '' || $l['cond'] === $l['reg_cond']);
        if ($same) $confirmed[] = $l; else $updated[] = $l;
    }
    $counted = array_keys($latest);
    $missing = q("SELECT a.code, a.description, a.serial_no, a.purchase_value, a.currency, l.name AS loc_name, e.name AS emp_name FROM assets a LEFT JOIN locations l ON l.id = a.location_id
                  LEFT JOIN employees e ON e.id = a.employee_id WHERE $scope" . ($counted ? ' AND a.id NOT IN (' . implode(',', $counted) . ')' : '') . ' ORDER BY a.code')->fetchAll();
    return [$confirmed, $updated, $new, $missing];
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'create') {
        $name = trim((string)($_POST['name'] ?? '')) ?: 'Physical count ' . date('Y');
        $teams = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', (string)($_POST['teams'] ?? ''))))) ?: ['Team 1'];
        db()->beginTransaction();
        $num = next_doc_number('asset_counts', 'AC');
        q('INSERT INTO asset_counts (number, name, location_id, created_by) VALUES (?, ?, ?, ?)', [$num, mb_substr($name, 0, 160), (int)($_POST['location_id'] ?? 0) ?: null, $u['id']]);
        $cid = (int)db()->lastInsertId();
        foreach (array_slice($teams, 0, 30) as $t) q('INSERT INTO asset_count_teams (count_id, name, token) VALUES (?, ?, ?)', [$cid, mb_substr($t, 0, 120), bin2hex(random_bytes(24))]);
        db()->commit();
        flash("$num created. Send each team its link.");
        redirect("asset_counts.php?id=$cid");
    }
    $c = q('SELECT * FROM asset_counts WHERE id = ?', [$id])->fetch();
    if ($c && $act === 'team' && $c['status'] === 'open' && trim((string)($_POST['team'] ?? '')) !== '') { q('INSERT INTO asset_count_teams (count_id, name, token) VALUES (?, ?, ?)', [$id, mb_substr(trim($_POST['team']), 0, 120), bin2hex(random_bytes(24))]); flash('Team added.'); }
    if ($c && $act === 'close' && $c['status'] === 'open') { q("UPDATE asset_counts SET status = 'closed', closed_at = NOW() WHERE id = ?", [$id]); audit('asset_count.close', $c['number']); flash('Count closed: team links no longer accept entries.'); }
    if ($c && $act === 'reopen' && $c['status'] === 'closed') { q("UPDATE asset_counts SET status = 'open', closed_at = NULL WHERE id = ?", [$id]); flash('Count reopened.'); }
    redirect($id ? "asset_counts.php?id=$id" : 'asset_counts.php');
}

if ($id) {
    $c = q('SELECT c.*, l.name AS loc_name, u.name AS by_name FROM asset_counts c LEFT JOIN locations l ON l.id = c.location_id JOIN users u ON u.id = c.created_by WHERE c.id = ?', [$id])->fetch();
    if (!$c) redirect('asset_counts.php');
    [$confirmed, $updated, $new, $missing] = count_report($c);
    $fmtWhere = fn($loc, $emp) => asset_where($loc, $emp) ?: '—';
    if (isset($_GET['xlsx'])) {
        $sum = [['Confirmed (as in register)', count($confirmed)], ['Updated (found, but location / holder / condition differs)', count($updated)], ['New (found, not in register)', count($new)], ['Missing (in register, not found)', count($missing)]];
        xlsx_send($c['number'] . '_report.xlsx', [
            ['name' => 'Summary', 'header' => [$c['name'] . ' · ' . ($c['loc_name'] ?: 'all locations') . ' · ' . date('d M Y'), 'Assets'], 'rows' => $sum],
            ['name' => 'Confirmed', 'header' => ['Asset ID', 'Description', 'Serial', 'Location', 'Holder', 'Team', 'Counted at'],
             'rows' => array_map(fn($l) => [$l['code'], $l['description'], $l['serial_no'], $l['reg_loc_name'], $l['reg_emp_name'], $l['team'], $l['counted_at']], $confirmed)],
            ['name' => 'Updated', 'header' => ['Asset ID', 'Description', 'Serial', 'Register: location · holder', 'Found: location · holder', 'Register condition', 'Found condition', 'Note', 'Team', 'Counted at'],
             'rows' => array_map(fn($l) => [$l['code'], $l['description'], $l['serial_no'], $fmtWhere($l['reg_loc_name'], $l['reg_emp_name']), $fmtWhere($l['found_loc_name'], $l['found_emp_name'] ?: $l['holder_text']), ASSET_COND[$l['reg_cond']] ?? '', ASSET_COND[$l['cond']] ?? '', $l['note'], $l['team'], $l['counted_at']], $updated)],
            ['name' => 'New', 'header' => ['Description', 'Serial', 'Existing tag / label', 'Found at', 'Holder', 'Condition', 'Note', 'Team', 'Counted at'],
             'rows' => array_map(fn($l) => [$l['new_description'], $l['new_serial'], $l['new_tag'], $l['found_loc_name'], $l['found_emp_name'] ?: $l['holder_text'], ASSET_COND[$l['cond']] ?? '', $l['note'], $l['team'], $l['counted_at']], $new)],
            ['name' => 'Missing', 'header' => ['Asset ID', 'Description', 'Serial', 'Register location', 'Register holder', 'Purchase value', 'Currency'],
             'rows' => array_map(fn($m) => [$m['code'], $m['description'], $m['serial_no'], $m['loc_name'], $m['emp_name'], round((float)$m['purchase_value'], 2), $m['currency']], $missing)],
        ]);
    }
    $teams = q('SELECT t.*, (SELECT COUNT(*) FROM asset_count_lines l WHERE l.team_id = t.id) AS n FROM asset_count_teams t WHERE t.count_id = ? ORDER BY t.id', [$id])->fetchAll();
    $expected = count($confirmed) + count($updated) + count($missing);
    render_header($c['number'] . ' · ' . $c['name']); ?>
<div class="actions" style="margin:-6px 0 12px"><span class="tag <?= $c['status'] === 'open' ? '' : 'off' ?>"><?= $c['status'] === 'open' ? 'Counting' : 'Closed' ?></span>
  <span class="muted"><?= e($c['loc_name'] ?: 'All locations') ?> · started <?= e(date('d M Y', strtotime($c['created_at']))) ?> by <?= e($c['by_name']) ?></span>
  <a class="btn sec small" style="margin-left:auto" href="?id=<?= $id ?>&xlsx=1">Download Excel report</a><a href="asset_counts.php">All counts</a></div>
<div class="tiles" style="margin-bottom:12px">
  <div class="tile"><span class="muted">Counted so far</span><b><?= count($confirmed) + count($updated) ?> / <?= $expected ?></b></div>
  <div class="tile"><span class="muted">Confirmed</span><b><?= count($confirmed) ?></b></div>
  <div class="tile"><span class="muted">Updated</span><b><?= count($updated) ?></b></div>
  <div class="tile"><span class="muted">New</span><b><?= count($new) ?></b></div>
  <div class="tile"><span class="muted">Not found yet / missing</span><b><?= count($missing) ?></b></div>
</div>
<div class="card">
  <h2>Team links <span class="muted" style="font-weight:400">· no account needed, works on a phone</span></h2>
  <div class="table-wrap"><table><tr><th>Team</th><th>Entries</th><th>Link</th></tr>
    <?php foreach ($teams as $t): ?><tr><td><b><?= e($t['name']) ?></b></td><td><?= (int)$t['n'] ?></td>
      <td><div class="copy"><?= e(count_link($t['token'])) ?></div>
        <div class="actions" style="margin-top:4px"><button type="button" class="btn sec small" onclick="navigator.clipboard.writeText(this.closest('td').querySelector('.copy').innerText);this.innerText='Copied'">Copy</button>
          <a class="btn sec small" href="<?= e(count_link($t['token'])) ?>" target="_blank">Open</a></div></td></tr><?php endforeach; ?></table></div>
  <?php if ($c['status'] === 'open'): ?>
  <form method="post" class="row" style="align-items:flex-end;margin-top:10px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="act" value="team">
    <div><input type="text" name="team" placeholder="Add a team (e.g. Team Lodwar)"></div><div style="flex:0"><button class="btn sec small">Add team</button></div></form>
  <?php endif; ?>
</div>
<?php foreach ([['Updated', $updated, 'Found, but location, holder or condition differs from the register'], ['New', $new, 'Found but not in the register'], ['Not found yet / missing', $missing, 'In the register but not counted']] as [$title, $list, $hint]): ?>
<details class="card" <?= $list ? 'open' : '' ?>><summary><?= e($title) ?> (<?= count($list) ?>) <span class="muted" style="font-weight:400">· <?= e($hint) ?></span></summary>
  <?php if ($list): ?><div class="table-wrap" style="margin-top:8px"><table>
  <?php if ($title === 'Updated'): ?><tr><th>Asset</th><th>Register</th><th>Found</th><th>Note · team</th></tr>
    <?php foreach ($list as $l): ?><tr><td><b><?= e($l['code']) ?></b> <?= e($l['description']) ?></td><td><?= e($fmtWhere($l['reg_loc_name'], $l['reg_emp_name'])) ?><br><span class="muted"><?= e(ASSET_COND[$l['reg_cond']] ?? '') ?></span></td>
      <td><b><?= e($fmtWhere($l['found_loc_name'], $l['found_emp_name'] ?: $l['holder_text'])) ?></b><br><span class="muted"><?= e(ASSET_COND[$l['cond']] ?? '') ?></span></td><td class="muted"><?= e($l['note']) ?> · <?= e($l['team']) ?></td></tr><?php endforeach; ?>
  <?php elseif ($title === 'New'): ?><tr><th>Description</th><th>Serial · tag</th><th>Found at</th><th>Note · team</th></tr>
    <?php foreach ($list as $l): ?><tr><td><b><?= e($l['new_description']) ?></b></td><td><?= e(trim($l['new_serial'] . ' · ' . $l['new_tag'], ' ·')) ?></td><td><?= e($fmtWhere($l['found_loc_name'], $l['found_emp_name'] ?: $l['holder_text'])) ?></td><td class="muted"><?= e($l['note']) ?> · <?= e($l['team']) ?></td></tr><?php endforeach; ?>
  <?php else: ?><tr><th>Asset</th><th>Serial</th><th>Register location · holder</th><th style="text-align:right">Value</th></tr>
    <?php foreach ($list as $m): ?><tr><td><b><?= e($m['code']) ?></b> <?= e($m['description']) ?></td><td><?= e($m['serial_no']) ?></td><td><?= e($fmtWhere($m['loc_name'], $m['emp_name'])) ?></td><td style="text-align:right"><?= number_format((float)$m['purchase_value'], 2) ?> <?= e($m['currency']) ?></td></tr><?php endforeach; ?>
  <?php endif; ?></table></div><?php endif; ?>
</details>
<?php endforeach; ?>
<p class="muted">The count never changes the register. Use the report to update locations and holders (handovers, moves), register new assets, and investigate missing ones (report lost or stolen if confirmed).</p>
<form method="post" class="actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <?php if ($c['status'] === 'open'): ?><button class="btn" name="act" value="close" onclick="return confirm('Close the count? Team links will stop accepting entries.')">Close the count</button>
  <?php else: ?><button class="btn sec small" name="act" value="reopen">Reopen</button><?php endif; ?></form>
<?php render_footer(); exit;
}

$rows = q('SELECT c.*, l.name AS loc_name, (SELECT COUNT(*) FROM asset_count_lines x WHERE x.count_id = c.id) AS n FROM asset_counts c LEFT JOIN locations l ON l.id = c.location_id ORDER BY c.id DESC')->fetchAll();
render_header('Asset counts'); ?>
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="act" value="create">
  <h2>Start a physical count</h2>
  <div class="row"><div style="flex:2"><label>Name</label><input type="text" name="name" value="Annual asset count <?= date('Y') ?>"></div>
    <div><label>Scope</label><select name="location_id"><option value="0">All locations</option><?php foreach (locations() as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></div></div>
  <label>Teams (one per line). Each team gets its own link.</label><textarea name="teams" rows="3">Team Nairobi
Team Lodwar</textarea>
  <div class="actions"><button class="btn">Create count</button></div>
</form>
<div class="table-wrap"><table><tr><th>Count</th><th>Scope</th><th>Entries</th><th>Started</th><th>Status</th></tr>
  <?php if (!$rows): ?><tr><td colspan="5" class="muted">No counts yet.</td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?><tr><td><a href="?id=<?= (int)$r['id'] ?>"><b><?= e($r['number']) ?></b></a> <?= e($r['name']) ?></td><td><?= e($r['loc_name'] ?: 'All locations') ?></td><td><?= (int)$r['n'] ?></td>
    <td><?= e(date('d M Y', strtotime($r['created_at']))) ?></td><td><span class="tag <?= $r['status'] === 'open' ? '' : 'off' ?>"><?= $r['status'] === 'open' ? 'Counting' : 'Closed' ?></span></td></tr><?php endforeach; ?>
</table></div>
<?php render_footer();
