<?php
declare(strict_types=1);
/*
 * Budget control. A budget is entered per pair of finance codes: level A (e.g. cost centre) × level B (e.g. budget line).
 * Used = committed (open PO amounts not yet invoiced) + actual (invoiced, tax spread over the lines).
 * Purchase Requests are compared with what is left; they are not deducted themselves.
 */

/** [position A, position B (0 = none), level A row, level B row] or null when not set up */
function budget_levels(): ?array {
    $a = (int)setting('budget_level_a', '0'); $b = (int)setting('budget_level_b', '0');
    if (!$a) return null;
    $la = q('SELECT * FROM fin_levels WHERE id = ?', [$a])->fetch();
    if (!$la) return null;
    $lb = $b ? q('SELECT * FROM fin_levels WHERE id = ?', [$b])->fetch() : null;
    return [(int)$la['position'], $lb ? (int)$lb['position'] : 0, $la, $lb ?: null];
}

function budget_enabled(): bool { return budget_levels() !== null && (int)q('SELECT COUNT(*) FROM budgets')->fetchColumn() > 0; }

function budget_key(array $row, array $lv): string {
    return (int)($row['fin' . $lv[0] . '_id'] ?? 0) . '-' . ($lv[1] ? (int)($row['fin' . $lv[1] . '_id'] ?? 0) : 0);
}

/**
 * Committed and actual per budget key, in each document's own currency (others counted as-is and reported).
 * Returns [usage map key => ['committed' => x, 'actual' => y], number of lines in another currency]
 */
function budget_usage(): array {
    $lv = budget_levels(); if (!$lv) return [[], 0];
    $cur = setting('currency', 'USD');
    $use = []; $foreign = 0;
    // invoiced net per PO line, and tax spread pro rata over each invoice's lines
    $inv = [];
    foreach (q("SELECT i.id, i.tax_amount, l.po_line_id, l.qty * l.unit_price AS amt FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id WHERE i.status <> 'cancelled'")->fetchAll() as $r) {
        $inv[(int)$r['id']]['tax'] = (float)$r['tax_amount'];
        $inv[(int)$r['id']]['lines'][] = [(int)$r['po_line_id'], (float)$r['amt']];
    }
    $netByLine = []; $grossByLine = [];
    foreach ($inv as $i) {
        $sum = array_sum(array_column($i['lines'], 1));
        foreach ($i['lines'] as [$pl, $amt]) {
            $netByLine[$pl] = ($netByLine[$pl] ?? 0) + $amt;
            $grossByLine[$pl] = ($grossByLine[$pl] ?? 0) + $amt + ($sum > 0 ? $i['tax'] * $amt / $sum : 0);
        }
    }
    foreach (q("SELECT l.*, p.currency FROM po_lines l JOIN po p ON p.id = l.po_id WHERE p.status <> 'cancelled'")->fetchAll() as $l) {
        $k = budget_key($l, $lv);
        $amt = (float)$l['qty'] * (float)$l['unit_price'];
        $use[$k]['committed'] = ($use[$k]['committed'] ?? 0) + max(0, $amt - ($netByLine[(int)$l['id']] ?? 0));
        $use[$k]['actual'] = ($use[$k]['actual'] ?? 0) + ($grossByLine[(int)$l['id']] ?? 0);
        if ($l['currency'] !== $cur) $foreign++;
    }
    return [$use, $foreign];
}

/** All budgets with codes, usage and what is left */
function budget_rows(): array {
    $lv = budget_levels(); if (!$lv) return [];
    [$use] = budget_usage();
    $rows = q('SELECT b.*, a.code AS a_code, a.name AS a_name, p.code AS donor_code, bb.code AS b_code, bb.name AS b_name
               FROM budgets b JOIN fin_values a ON a.id = b.a_value_id LEFT JOIN fin_values p ON p.id = a.parent_id LEFT JOIN fin_values bb ON bb.id = b.b_value_id
               ORDER BY a.code, bb.code')->fetchAll();
    foreach ($rows as &$r) {
        $u = $use[$r['a_value_id'] . '-' . $r['b_value_id']] ?? [];
        $r['committed'] = $u['committed'] ?? 0.0; $r['actual'] = $u['actual'] ?? 0.0;
        $r['left'] = (float)$r['amount'] - $r['committed'] - $r['actual'];
        $r['pct'] = (float)$r['amount'] > 0 ? ($r['committed'] + $r['actual']) / (float)$r['amount'] * 100 : 0;
    }
    return $rows;
}

/**
 * Check a PR's lines against what is left. Returns rows per budget key:
 * label, budget (null = none entered), used, left, this PR, over (bool)
 */
function budget_check_lines(array $lines): array {
    if (!budget_enabled()) return [];
    $lv = budget_levels();
    [$use] = budget_usage();
    $need = [];
    foreach ($lines as $l) {
        if (!(int)($l['fin' . $lv[0] . '_id'] ?? 0)) continue;
        $k = budget_key($l, $lv);
        $need[$k] = ($need[$k] ?? 0) + (float)$l['qty'] * (float)$l['unit_price'];
    }
    $out = [];
    foreach ($need as $k => $amt) {
        [$a, $b] = array_map('intval', explode('-', $k));
        $bud = q('SELECT amount FROM budgets WHERE a_value_id = ? AND b_value_id = ?', [$a, $b])->fetchColumn();
        $codes = q('SELECT id, code FROM fin_values WHERE id IN (?, ?)', [$a, $b])->fetchAll(PDO::FETCH_KEY_PAIR);
        $used = ($use[$k]['committed'] ?? 0) + ($use[$k]['actual'] ?? 0);
        $left = $bud === false ? null : (float)$bud - $used;
        $out[] = ['label' => trim(($codes[$a] ?? '?') . ($b ? ' / ' . ($codes[$b] ?? '?') : '')), 'budget' => $bud === false ? null : (float)$bud,
                  'used' => $used, 'left' => $left, 'this' => $amt, 'over' => $left === null || $amt > $left + 0.005];
    }
    return $out;
}

function budget_is_over(array $check): bool { return (bool)array_filter($check, fn($c) => $c['over']); }

/** Small table shown on a PR */
function budget_panel(array $check, string $currency): string {
    if (!$check) return '';
    $over = budget_is_over($check);
    $h = '<div class="card noprint" style="' . ($over ? 'border-color:#b3261e' : '') . '"><h2 style="margin-top:0">Budget check' . ($over ? ' <span class="tag warn" style="background:#fde7e6;color:#b3261e">Over budget</span>' : ' <span class="tag stock">Within budget</span>') . '</h2>'
       . '<div class="table-wrap"><table><tr><th>Charged to</th><th style="text-align:right">Budget</th><th style="text-align:right">Used (POs + invoices)</th><th style="text-align:right">Left</th><th style="text-align:right">This request</th><th></th></tr>';
    foreach ($check as $c) {
        $h .= '<tr><td><b>' . e($c['label']) . '</b></td><td style="text-align:right">' . ($c['budget'] === null ? '<span class="muted">none entered</span>' : number_format($c['budget'], 2)) . '</td>'
            . '<td style="text-align:right">' . number_format($c['used'], 2) . '</td><td style="text-align:right">' . ($c['left'] === null ? '—' : number_format($c['left'], 2)) . '</td>'
            . '<td style="text-align:right">' . number_format($c['this'], 2) . '</td><td>' . ($c['over'] ? '<span class="tag warn" style="background:#fde7e6;color:#b3261e">' . ($c['budget'] === null ? 'No budget' : 'Over by ' . number_format($c['this'] - $c['left'], 2)) . '</span>' : '<span class="tag stock">OK</span>') . '</td></tr>';
    }
    return $h . '</table></div><p class="muted" style="margin-bottom:0">Amounts in ' . e($currency) . '. Used = Purchase Orders not yet invoiced + invoices.' . ($over ? ' Approvers must write a comment to approve an over-budget request.' : '') . '</p></div>';
}
