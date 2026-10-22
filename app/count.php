<?php
/* Public, mobile page for an asset counting team (personal link, no account) */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/assets.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$team = preg_match('/^[a-f0-9]{48}$/', $token) ? q('SELECT t.*, c.name AS count_name, c.number, c.status, c.id AS cid, c.location_id AS scope FROM asset_count_teams t JOIN asset_counts c ON c.id = t.count_id WHERE t.token = ?', [$token])->fetch() : null;
$self = 'count.php?t=' . $token;
$locs = locations(); $emps = employees();

if ($team && $team['status'] === 'open' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $loc = (int)($_POST['location_id'] ?? 0) ?: null; $emp = (int)($_POST['employee_id'] ?? 0) ?: null;
    $cond = array_key_exists($_POST['cond'] ?? '', ASSET_COND) ? $_POST['cond'] : '';
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500);
    if (($_POST['act'] ?? '') === 'asset') {
        $aid = (int)($_POST['asset_id'] ?? 0);
        $a = q("SELECT code FROM assets WHERE id = ? AND status NOT IN ('disposed')", [$aid])->fetchColumn();
        if ($a) {
            $ex = q('SELECT id FROM asset_count_lines WHERE count_id = ? AND team_id = ? AND asset_id = ?', [$team['cid'], $team['id'], $aid])->fetchColumn();
            if ($ex) q('UPDATE asset_count_lines SET location_id = ?, employee_id = ?, cond = ?, note = ?, counted_at = NOW() WHERE id = ?', [$loc, $emp, $cond, $note, $ex]);
            else q('INSERT INTO asset_count_lines (count_id, team_id, asset_id, location_id, employee_id, cond, note) VALUES (?, ?, ?, ?, ?, ?, ?)', [$team['cid'], $team['id'], $aid, $loc, $emp, $cond, $note]);
            flash("$a counted ✓");
        }
    }
    if (($_POST['act'] ?? '') === 'new') {
        $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255);
        if ($desc === '') { flash('Describe the item.', 'err'); redirect($self . '&add=1'); }
        q('INSERT INTO asset_count_lines (count_id, team_id, location_id, employee_id, holder_text, cond, note, new_description, new_serial, new_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$team['cid'], $team['id'], $loc, $emp, mb_substr(trim((string)($_POST['holder_text'] ?? '')), 0, 160), $cond, $note, $desc,
           mb_substr(trim((string)($_POST['serial'] ?? '')), 0, 120), mb_substr(trim((string)($_POST['tag'] ?? '')), 0, 60)]);
        flash('New item recorded ✓');
    }
    redirect($self);
}

$qs = trim((string)($_GET['q'] ?? ''));
$matches = ($team && $qs !== '') ? q("SELECT a.*, l.name AS loc_name, e.name AS emp_name FROM assets a LEFT JOIN locations l ON l.id = a.location_id LEFT JOIN employees e ON e.id = a.employee_id
    WHERE a.status <> 'disposed' AND (a.code = ? OR a.serial_no = ? OR a.code LIKE ? OR a.serial_no LIKE ? OR a.description LIKE ?) ORDER BY a.code = ? DESC, a.code LIMIT 10",
    [$qs, $qs, "%$qs%", "%$qs%", "%$qs%", $qs])->fetchAll() : [];
$mine = $team ? q('SELECT l.*, a.code, a.description FROM asset_count_lines l LEFT JOIN assets a ON a.id = l.asset_id WHERE l.team_id = ? ORDER BY l.counted_at DESC LIMIT 50', [$team['id']])->fetchAll() : [];
$already = array_map('intval', array_filter(array_column($mine, 'asset_id')));
$opts = function (array $list, ?int $sel, string $empty) { $h = '<option value="0">' . e($empty) . '</option>'; foreach ($list as $x) $h .= '<option value="' . (int)$x['id'] . '"' . ((int)$sel === (int)$x['id'] ? ' selected' : '') . '>' . e($x['name']) . '</option>'; return $h; };
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Asset count</title><link rel="stylesheet" href="assets/style.css?v=8"></head><body>
<?php if (is_demo()): ?><div class="demo-bar">Demo version · this is the page a counting team opens on its phone</div><?php endif; ?>
<header class="topbar"><span class="brand"><?= e(setting('org_name')) ?></span><span class="who"><?= $team ? e($team['name']) : 'Asset count' ?></span></header>
<main style="max-width:640px;margin:0 auto">
<?php foreach (flash() ?? [] as $f): ?><div class="flash <?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
<?php if (!$team): http_response_code(404); ?><div class="card"><h2>This link is not valid</h2></div>
<?php elseif ($team['status'] !== 'open'): ?><div class="card"><h2><?= e($team['count_name']) ?></h2><p>This count is closed. Thank you!</p></div>
<?php else: ?>
  <h1 style="font-size:19px"><?= e($team['count_name']) ?></h1>
  <form method="get" class="card"><input type="hidden" name="t" value="<?= e($token) ?>">
    <label style="margin-top:0">Asset ID or serial number (on the label)</label>
    <div class="row" style="flex-wrap:nowrap"><input type="text" name="q" value="<?= e($qs) ?>" autofocus autocapitalize="characters" autocomplete="off" style="font-size:18px;flex:3;min-width:0"><button class="btn" style="flex:0;min-width:auto;padding:8px 18px">Find</button></div>
  </form>
  <?php if ($qs !== '' && !$matches): ?><div class="flash warn">Nothing found for "<?= e($qs) ?>". Check the label, or <a href="<?= e($self) ?>&add=1">record it as a new item</a>.</div><?php endif; ?>
  <?php foreach ($matches as $a): ?>
  <form method="post" class="card" style="<?= in_array((int)$a['id'], $already, true) ? 'border-color:#1e6b35' : '' ?>">
    <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>"><input type="hidden" name="act" value="asset"><input type="hidden" name="asset_id" value="<?= (int)$a['id'] ?>">
    <div style="font-size:18px"><b><?= e($a['code']) ?></b> <?= in_array((int)$a['id'], $already, true) ? '<span class="tag stock">already counted</span>' : '' ?><?= $a['status'] === 'lost' ? ' <span class="tag warn">registered as lost</span>' : '' ?></div>
    <div><?= e($a['description']) ?><?= $a['brand_model'] ? ' · ' . e($a['brand_model']) : '' ?></div>
    <div class="muted">Serial <?= e($a['serial_no'] ?: '—') ?> · register: <?= e(asset_where($a['loc_name'], $a['emp_name']) ?: '—') ?></div>
    <label>Where is it now?</label><select name="location_id"><?= $opts($locs, $a['location_id'] ? (int)$a['location_id'] : ($team['scope'] ? (int)$team['scope'] : null), '— location —') ?></select>
    <label>Who uses it?</label><select name="employee_id"><?= $opts($emps, $a['employee_id'] ? (int)$a['employee_id'] : null, 'Nobody / in store') ?></select>
    <label>Condition</label><select name="cond"><?php foreach (ASSET_COND as $k => $l): ?><option value="<?= $k ?>" <?= $a['cond'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <label>Note</label><input type="text" name="note">
    <div class="actions"><button class="btn" style="width:100%;padding:12px;font-size:17px">Save: asset seen</button></div>
  </form>
  <?php endforeach; ?>
  <?php if (isset($_GET['add'])): ?>
  <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="t" value="<?= e($token) ?>"><input type="hidden" name="act" value="new">
    <h2>Item not in the register</h2>
    <label>Description</label><input type="text" name="description" required placeholder="e.g. Office chair, black">
    <div class="row"><div><label>Serial number</label><input type="text" name="serial"></div><div><label>Any tag / label on it</label><input type="text" name="tag"></div></div>
    <label>Where</label><select name="location_id"><?= $opts($locs, $team['scope'] ? (int)$team['scope'] : null, '— location —') ?></select>
    <label>Who uses it</label><select name="employee_id"><?= $opts($emps, null, 'Nobody / unknown') ?></select>
    <label>Condition</label><select name="cond"><?php foreach (ASSET_COND as $k => $l): ?><option value="<?= $k ?>" <?= $k === 'good' ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <label>Note</label><input type="text" name="note">
    <div class="actions"><button class="btn" style="width:100%;padding:12px">Save new item</button></div>
  </form>
  <?php else: ?><p><a class="btn sec" href="<?= e($self) ?>&add=1" style="width:100%;text-align:center">+ Item not in the register</a></p><?php endif; ?>
  <div class="card"><h2>Counted by <?= e($team['name']) ?> (<?= count($mine) ?>)</h2>
    <?php foreach ($mine as $m): ?><div style="padding:4px 0;border-bottom:1px solid var(--line)"><b><?= e($m['code'] ?? 'NEW') ?></b> <?= e($m['description'] ?? $m['new_description']) ?> <span class="muted"><?= e(date('H:i', strtotime($m['counted_at']))) ?></span></div><?php endforeach; ?>
    <?php if (!$mine): ?><p class="muted">Nothing yet. Search the first asset above.</p><?php endif; ?></div>
<?php endif; ?>
</main></body></html>
