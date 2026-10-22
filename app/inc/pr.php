<?php
declare(strict_types=1);
/* Purchase Request helpers */

const PR_STATUS = [
    'draft'     => ['Draft', 'off'],
    'submitted' => ['Awaiting approval', ''],
    'returned'  => ['Returned for changes', 'warn'],
    'approved'  => ['Approved', 'stock'],
    'processing'=> ['In procurement', ''],
    'ordered'   => ['Ordered', 'stock'],
    'cancelled' => ['Cancelled', 'off'],
];

function pr_badge(string $status): string {
    [$label, $cls] = PR_STATUS[$status] ?? [$status, ''];
    return '<span class="tag ' . e($cls) . '">' . e($label) . '</span>';
}

function pr_get(int $id): ?array {
    $pr = q('SELECT p.*, u.name AS requester_name, u.email AS requester_email FROM pr p JOIN users u ON u.id = p.requester_id WHERE p.id = ?', [$id])->fetch();
    return $pr ?: null;
}

function pr_lines(int $prId): array {
    return q('SELECT l.*, i.code AS item_code, i.description AS item_desc, i.item_type
              FROM pr_lines l JOIN items i ON i.id = l.item_id WHERE l.pr_id = ? ORDER BY l.line_no', [$prId])->fetchAll();
}

function pr_total(array $lines): float {
    $t = 0.0;
    foreach ($lines as $l) $t += (float)$l['qty'] * (float)$l['unit_price'];
    return $t;
}

function pr_is_approver(array $pr, array $u): bool {
    return (bool)q("SELECT 1 FROM pr_approvals WHERE pr_id = ? AND approver_id = ?", [$pr['id'], $u['id']])->fetch();
}

function pr_can_view(array $pr, array $u): bool {
    return (int)$pr['requester_id'] === (int)$u['id']
        || has_role('admin') || has_role('procurement')
        || pr_is_approver($pr, $u);
}

function pr_can_edit(array $pr, array $u): bool {
    return (int)$pr['requester_id'] === (int)$u['id'] && in_array($pr['status'], ['draft', 'returned'], true);
}

function pr_can_cancel(array $pr, array $u): bool {
    if (!in_array($pr['status'], ['draft', 'returned', 'submitted'], true)) return false;
    return (int)$pr['requester_id'] === (int)$u['id'] || has_role('admin');
}

/** SQL condition: approval row a belongs to the PR's current stage (lowest stage still pending) */
const PR_CURRENT_STAGE = "a.stage = (SELECT MIN(a2.stage) FROM pr_approvals a2 WHERE a2.pr_id = a.pr_id AND a2.status = 'pending')";

/** Approval rows this user may decide on now (own rows, or any row for an admin) */
function pr_decidable(array $pr, array $u): array {
    if ($pr['status'] !== 'submitted') return [];
    $rows = q("SELECT a.*, u.name AS approver_name, v.code AS fin_code FROM pr_approvals a JOIN users u ON u.id = a.approver_id
               LEFT JOIN fin_values v ON v.id = a.fin_value_id WHERE a.pr_id = ? AND a.status = 'pending' AND " . PR_CURRENT_STAGE, [$pr['id']])->fetchAll();
    return array_values(array_filter($rows, function ($a) use ($u, $pr) {
        $mine = (int)$a['approver_id'] === (int)$u['id'] && (int)$pr['requester_id'] !== (int)$u['id'];
        // Administrators step in only where the approver is the requester (no self-approval)
        $selfBlocked = (int)$a['approver_id'] === (int)$pr['requester_id'];
        return $mine || (has_role('admin') && $selfBlocked && (int)$pr['requester_id'] !== (int)$u['id']);
    }));
}

function pr_log(int $prId, string $action, string $comment = ''): void {
    $u = current_user();
    q('INSERT INTO pr_history (pr_id, user_id, action, comment) VALUES (?, ?, ?, ?)', [$prId, $u['id'] ?? null, $action, $comment]);
}

function pr_next_number(): string {
    $prefix = setting('pr_prefix', 'PR') . '-' . date('Y') . '-';
    $max = (int)q('SELECT MAX(CAST(SUBSTRING(number, ?) AS UNSIGNED)) FROM pr WHERE number LIKE ?', [strlen($prefix) + 1, $prefix . '%'])->fetchColumn();
    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/** Finance levels + active values, for the form dropdowns */
function fin_tree(): array {
    $levels = fin_levels();
    $vals = q('SELECT id, level_id, parent_id, code, name FROM fin_values WHERE active = 1 ORDER BY code')->fetchAll();
    $out = [];
    foreach ($levels as $l) {
        $out[] = [
            'pos' => (int)$l['position'], 'id' => (int)$l['id'], 'name' => $l['name'],
            'required' => (bool)$l['required'] || (int)$l['id'] === (int)setting('approval_level_id', '0'),
            'linked' => (int)$l['position'] > 1 && (bool)$l['linked'],
            'values' => array_values(array_map(fn($v) => ['id' => (int)$v['id'], 'p' => $v['parent_id'] ? (int)$v['parent_id'] : null, 'c' => $v['code'], 'n' => $v['name']],
                                    array_filter($vals, fn($v) => (int)$v['level_id'] === (int)$l['id']))),
        ];
    }
    return $out;
}

/** id => code map for displaying line codes */
function fin_code_map(): array {
    return array_column(q('SELECT id, code FROM fin_values')->fetchAll(), 'code', 'id');
}

/**
 * Check a set of lines before submission. Returns a list of error messages.
 * $lines: arrays with item_id, qty, unit_price, fin (pos => value id)
 */
function pr_validate_lines(array $lines, bool $forSubmit): array {
    $errs = [];
    if (!$lines) return ['Add at least one line.'];
    $tree = fin_tree();
    $valsById = [];
    foreach ($tree as $lv) foreach ($lv['values'] as $v) $valsById[$v['id']] = $v + ['pos' => $lv['pos']];
    foreach ($lines as $n => $l) {
        $ln = 'Line ' . ($n + 1);
        if (!q('SELECT 1 FROM items WHERE id = ? AND active = 1', [$l['item_id']])->fetch()) $errs[] = "$ln: choose an item from the catalogue.";
        if ($l['qty'] <= 0) $errs[] = "$ln: quantity must be more than 0.";
        if ($l['unit_price'] < 0) $errs[] = "$ln: price cannot be negative.";
        if (!$forSubmit) continue;
        foreach ($tree as $lv) {
            $vid = (int)($l['fin'][$lv['pos']] ?? 0);
            if (!$vid) { if ($lv['required']) $errs[] = "$ln: select a {$lv['name']}."; continue; }
            $v = $valsById[$vid] ?? null;
            if (!$v || $v['pos'] !== $lv['pos']) { $errs[] = "$ln: invalid {$lv['name']}."; continue; }
            if ($lv['linked']) {
                $parent = (int)($l['fin'][$lv['pos'] - 1] ?? 0);
                if ($parent && $v['p'] !== $parent) $errs[] = "$ln: {$lv['name']} {$v['c']} does not belong to the selected " . $tree[$lv['pos'] - 2]['name'] . '.';
            }
        }
        if (unit_price_missing($l)) $errs[] = "$ln: enter an estimated unit price.";
    }
    return array_values(array_unique($errs));
}

function unit_price_missing(array $l): bool { return (float)$l['unit_price'] <= 0; }

/** Higher approval levels (above the Budget Holder), lowest threshold first */
function approval_levels(bool $activeOnly = true): array {
    return q('SELECT l.*, u.name AS approver_name, u.active AS approver_active FROM approval_levels l LEFT JOIN users u ON u.id = l.approver_user_id'
           . ($activeOnly ? ' WHERE l.active = 1' : '') . ' ORDER BY l.min_amount, l.id')->fetchAll();
}

/**
 * Work out who must approve, in order. Returns [rows, errors].
 * Stage 1: the Budget Holder of each line's approval-level code.
 * Stage 2+: each approval threshold the PR total is above, lowest first.
 * Someone already in an earlier stage is not asked again.
 */
function pr_route(array $lines): array {
    $apprLvl = (int)setting('approval_level_id', '0');
    if (!$apprLvl) return [[], ['The Administrator has not chosen which finance level decides the approver (Finance codes page).']];
    $pos = (int)q('SELECT position FROM fin_levels WHERE id = ?', [$apprLvl])->fetchColumn();
    $rows = []; $errs = []; $seen = [];
    foreach ($lines as $l) {
        $vid = (int)($l['fin'][$pos] ?? 0);
        $v = q('SELECT v.code, v.approver_user_id, u.active FROM fin_values v LEFT JOIN users u ON u.id = v.approver_user_id WHERE v.id = ?', [$vid])->fetch();
        if (!$v) continue;
        if (!$v['approver_user_id'] || !$v['active']) { $errs[] = "{$v['code']} has no active Budget Holder. Ask your Administrator to assign one."; continue; }
        $rows[$v['approver_user_id'] . '-' . $vid] = ['approver_id' => (int)$v['approver_user_id'], 'fin_value_id' => $vid, 'stage' => 1, 'level_name' => 'Budget Holder'];
        $seen[(int)$v['approver_user_id']] = true;
    }
    $total = 0.0;
    foreach ($lines as $l) $total += (float)$l['qty'] * (float)$l['unit_price'];
    $stage = 1;
    foreach (approval_levels() as $lv) {
        if ($total <= (float)$lv['min_amount']) continue;
        if (!$lv['approver_user_id'] || !$lv['approver_active']) { $errs[] = "No active approver is set for \"{$lv['name']}\" (PRs above " . number_format((float)$lv['min_amount'], 2) . '). Ask your Administrator.'; continue; }
        if (isset($seen[(int)$lv['approver_user_id']])) continue;
        $stage++;
        $rows['L' . $lv['id']] = ['approver_id' => (int)$lv['approver_user_id'], 'fin_value_id' => null, 'stage' => $stage, 'level_name' => $lv['name']];
        $seen[(int)$lv['approver_user_id']] = true;
    }
    return [array_values($rows), array_values(array_unique($errs))];
}

/** Counts for the dashboard / menu */
function pr_counts(array $u): array {
    return [
        'to_approve' => (int)q("SELECT COUNT(DISTINCT a.pr_id) FROM pr_approvals a JOIN pr p ON p.id = a.pr_id
                                WHERE a.approver_id = ? AND a.status = 'pending' AND p.status = 'submitted' AND p.requester_id <> ? AND " . PR_CURRENT_STAGE, [$u['id'], $u['id']])->fetchColumn(),
        'self_blocked' => has_role('admin') ? (int)q("SELECT COUNT(DISTINCT a.pr_id) FROM pr_approvals a JOIN pr p ON p.id = a.pr_id
                                WHERE a.status = 'pending' AND p.status = 'submitted' AND a.approver_id = p.requester_id AND p.requester_id <> ? AND " . PR_CURRENT_STAGE, [$u['id']])->fetchColumn() : 0,
        'my_open'   => (int)q("SELECT COUNT(*) FROM pr WHERE requester_id = ? AND status IN ('draft','returned','submitted')", [$u['id']])->fetchColumn(),
        'returned'  => (int)q("SELECT COUNT(*) FROM pr WHERE requester_id = ? AND status = 'returned'", [$u['id']])->fetchColumn(),
        'approved'  => (int)q("SELECT COUNT(*) FROM pr WHERE status = 'approved'")->fetchColumn(),
    ];
}
