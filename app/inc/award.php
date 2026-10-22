<?php
declare(strict_types=1);
/* Evaluation, award and Purchase Orders */
require_once __DIR__ . '/rfq.php';

const DEFAULT_CRITERIA = [['Price', 70, 1], ['Delivery time', 15, 0], ['Quality / technical compliance', 15, 0]];

const PO_STATUS = [
    'issued'             => ['Issued', ''],
    'acknowledged'       => ['Acknowledged by supplier', 'stock'],
    'partially_received' => ['Partially received', 'warn'],
    'received'           => ['Received', 'stock'],
    'cancelled'          => ['Cancelled', 'off'],
];

/* ---------- Criteria ---------- */
function rfq_criteria(int $rfqId): array {
    return q('SELECT * FROM rfq_criteria WHERE rfq_id = ? ORDER BY is_price DESC, sort, id', [$rfqId])->fetchAll();
}
function rfq_default_criteria(int $rfqId): void {
    foreach (DEFAULT_CRITERIA as $i => [$n, $w, $p]) q('INSERT INTO rfq_criteria (rfq_id, name, weight, is_price, sort) VALUES (?, ?, ?, ?, ?)', [$rfqId, $n, $w, $p, $i]);
}
/** Problems with a criteria set (weights must total 100, price must be there) */
function criteria_errors(array $crit): array {
    $errs = [];
    if (!array_filter($crit, fn($c) => $c['is_price'])) $errs[] = 'Price must be one of the criteria.';
    $sum = array_sum(array_map(fn($c) => (float)$c['weight'], $crit));
    if (abs($sum - 100) > 0.01) $errs[] = 'Criteria weights must add up to 100 (now ' . rtrim(rtrim(number_format($sum, 2), '0'), '.') . ').';
    return $errs;
}

/* ---------- Evaluation ---------- */
/**
 * Scores per line for every compliant supplier.
 * Returns ['lines' => [line_id => ['line' => row, 'rows' => [sid => [price, price_pts, other_pts, total]], 'best' => sid|null]],
 *          'other' => [sid => non-price points], 'missing' => [sid => true if a score is missing]]
 */
function rfq_evaluation(array $rfq): array {
    $crit = rfq_criteria((int)$rfq['id']);
    $priceW = 0.0; $others = [];
    foreach ($crit as $c) { if ($c['is_price']) $priceW += (float)$c['weight']; else $others[] = $c; }
    $quotes = array_filter(rfq_quotes((int)$rfq['id']), fn($q) => (int)$q['quote']['compliant'] === 1);
    $scores = [];
    foreach (q('SELECT criterion_id, supplier_id, score FROM rfq_scores WHERE rfq_id = ?', [$rfq['id']])->fetchAll() as $s) $scores[(int)$s['supplier_id']][(int)$s['criterion_id']] = (float)$s['score'];
    $other = []; $missing = [];
    foreach ($quotes as $sid => $q) {
        $pts = 0.0;
        foreach ($others as $c) {
            if (!isset($scores[$sid][(int)$c['id']])) { $missing[$sid] = true; continue; }
            $pts += (float)$c['weight'] * $scores[$sid][(int)$c['id']] / 10;
        }
        $other[$sid] = $pts;
    }
    $out = [];
    foreach (rfq_lines((int)$rfq['id']) as $l) {
        $prices = [];
        foreach ($quotes as $sid => $q) if (($p = $q['prices'][$l['id']]['price']) !== null && $p > 0) $prices[$sid] = $p;
        $min = $prices ? min($prices) : null;
        $rows = []; $best = null; $bestScore = -1;
        foreach ($prices as $sid => $p) {
            $pp = $priceW * $min / $p;
            $tot = $pp + $other[$sid];
            $rows[$sid] = ['price' => $p, 'price_pts' => $pp, 'other_pts' => $other[$sid], 'total' => $tot];
            if ($tot > $bestScore + 0.0001 || (abs($tot - $bestScore) < 0.0001 && $p < $rows[$best]['price'])) { $best = $sid; $bestScore = $tot; }
        }
        $out[(int)$l['id']] = ['line' => $l, 'rows' => $rows, 'best' => $best];
    }
    return ['lines' => $out, 'other' => $other, 'missing' => $missing, 'criteria' => $crit, 'scores' => $scores];
}

/** Current award lines with PR finance codes, ready for routing / PO creation */
function rfq_award_lines(int $rfqId): array {
    return q('SELECT a.*, l.line_no, l.item_id, l.description, l.specification, l.qty, l.unit, l.pr_line_id,
                     pl.fin1_id, pl.fin2_id, pl.fin3_id, pl.fin4_id, s.name AS supplier_name
              FROM rfq_awards a JOIN rfq_lines l ON l.id = a.rfq_line_id LEFT JOIN pr_lines pl ON pl.id = l.pr_line_id
              JOIN suppliers s ON s.id = a.supplier_id WHERE a.rfq_id = ? ORDER BY l.line_no', [$rfqId])->fetchAll();
}

function award_total(array $awardLines): float {
    $t = 0.0; foreach ($awardLines as $a) $t += (float)$a['qty'] * (float)$a['unit_price']; return $t;
}

/** Estimated value of the PR lines covered by this RFQ */
function rfq_estimate(int $rfqId): float {
    return (float)q('SELECT COALESCE(SUM(pl.qty * pl.unit_price), 0) FROM rfq_lines l JOIN pr_lines pl ON pl.id = l.pr_line_id WHERE l.rfq_id = ?', [$rfqId])->fetchColumn();
}

/** % over the PR estimate (0 when not over) */
function award_overrun_pct(int $rfqId, float $awardTotal): float {
    $est = rfq_estimate($rfqId);
    return $est > 0 && $awardTotal > $est ? ($awardTotal - $est) / $est * 100 : 0.0;
}

/* ---------- Award approval (same chain as PRs) ---------- */
const AWARD_CURRENT_STAGE = "a.stage = (SELECT MIN(a2.stage) FROM award_approvals a2 WHERE a2.rfq_id = a.rfq_id AND a2.status = 'pending')";

function award_route(int $rfqId): array {
    $lines = array_map(fn($a) => ['qty' => $a['qty'], 'unit_price' => $a['unit_price'],
        'fin' => [1 => (int)$a['fin1_id'], 2 => (int)$a['fin2_id'], 3 => (int)$a['fin3_id'], 4 => (int)$a['fin4_id']]], rfq_award_lines($rfqId));
    return pr_route($lines);
}

/** Pending approval rows this user may decide on now */
function award_decidable(array $rfq, array $u): array {
    if ($rfq['award_status'] !== 'submitted') return [];
    $rows = q("SELECT a.*, u.name AS approver_name, v.code AS fin_code FROM award_approvals a JOIN users u ON u.id = a.approver_id
               LEFT JOIN fin_values v ON v.id = a.fin_value_id WHERE a.rfq_id = ? AND a.status = 'pending' AND " . AWARD_CURRENT_STAGE, [$rfq['id']])->fetchAll();
    $submitter = (int)$rfq['award_submitted_by'];
    return array_values(array_filter($rows, function ($a) use ($u, $submitter) {
        $mine = (int)$a['approver_id'] === (int)$u['id'] && $submitter !== (int)$u['id'];
        $blocked = (int)$a['approver_id'] === $submitter;      // nobody approves their own award
        return $mine || (has_role('admin') && $blocked && $submitter !== (int)$u['id']);
    }));
}

function award_pending_count(array $u): int {
    $rows = q("SELECT DISTINCT r.id, r.award_submitted_by FROM award_approvals a JOIN rfq r ON r.id = a.rfq_id
               WHERE a.status = 'pending' AND r.award_status = 'submitted' AND " . AWARD_CURRENT_STAGE . "
               AND ((a.approver_id = ? AND r.award_submitted_by <> ?) OR (? = 1 AND a.approver_id = r.award_submitted_by AND r.award_submitted_by <> ?))",
              [$u['id'], $u['id'], has_role('admin') ? 1 : 0, $u['id']])->fetchAll();
    return count($rows);
}

/* ---------- Purchase Orders ---------- */
function po_next_number(): string {
    $prefix = setting('po_prefix', 'PO') . '-' . date('Y') . '-';
    $max = (int)q('SELECT MAX(CAST(SUBSTRING(number, ?) AS UNSIGNED)) FROM po WHERE number LIKE ?', [strlen($prefix) + 1, $prefix . '%'])->fetchColumn();
    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}
function po_log(int $poId, string $action, string $comment = ''): void {
    $u = current_user();
    q('INSERT INTO po_history (po_id, user_id, action, comment) VALUES (?, ?, ?, ?)', [$poId, $u['id'] ?? null, $action, $comment]);
}
function po_get(int $id): ?array {
    $r = q('SELECT p.*, s.name AS supplier_name, s.code AS supplier_code, s.email AS supplier_email, s.contact_name, s.phone AS supplier_phone, s.address AS supplier_address,
                   s.country AS supplier_country, s.registration_no, pr.number AS pr_number, r.number AS rfq_number, u.name AS created_name
            FROM po p JOIN suppliers s ON s.id = p.supplier_id JOIN pr ON pr.id = p.pr_id LEFT JOIN rfq r ON r.id = p.rfq_id JOIN users u ON u.id = p.created_by WHERE p.id = ?', [$id])->fetch();
    return $r ?: null;
}
function po_lines(int $poId): array { return q('SELECT * FROM po_lines WHERE po_id = ? ORDER BY line_no', [$poId])->fetchAll(); }
function po_total(array $lines): float { $t = 0.0; foreach ($lines as $l) $t += (float)$l['qty'] * (float)$l['unit_price']; return $t; }
function po_link(string $token): string { return base_url() . '/po_supplier.php?t=' . $token; }
function po_badge(string $s): string { [$l, $c] = PO_STATUS[$s] ?? [$s, '']; return '<span class="tag ' . $c . '">' . e($l) . '</span>'; }

/** Email the PO link to the supplier; returns a status text */
function po_send(array $po): string {
    $link = po_link($po['token']);
    $html = mail_wrap('<p>Dear ' . e($po['contact_name'] ?: $po['supplier_name']) . ',</p>'
        . '<p>Please find our Purchase Order <b>' . e($po['number']) . '</b>' . ($po['rfq_number'] ? ' following your offer for ' . e($po['rfq_number']) : '') . '.</p>'
        . '<p><a href="' . e($link) . '" style="display:inline-block;background:#1f4e79;color:#fff;padding:10px 18px;text-decoration:none;border-radius:4px">View, print and acknowledge the Purchase Order</a></p>'
        . '<p>Please acknowledge it within 2 working days. Deliveries without a valid Purchase Order number will not be accepted.</p>');
    [$ok, $err] = $po['supplier_email'] ? send_mail($po['supplier_email'], $po['contact_name'] ?: $po['supplier_name'], 'Purchase Order ' . $po['number'] . ' from ' . setting('org_name'), $html) : [false, 'Supplier has no email address'];
    $st = $ok ? 'Email sent ' . date('d M H:i') : 'Not emailed: ' . $err;
    q('UPDATE po SET email_status = ? WHERE id = ?', [mb_substr($st, 0, 250), $po['id']]);
    return $st;
}

/** After final award approval: one PO per awarded supplier. Returns the new PO ids. */
function generate_pos(array $rfq): array {
    $awards = rfq_award_lines((int)$rfq['id']);
    $quotes = rfq_quotes((int)$rfq['id']);
    $bySup = [];
    foreach ($awards as $a) $bySup[(int)$a['supplier_id']][] = $a;
    $ids = [];
    $creator = (int)($rfq['award_submitted_by'] ?: $rfq['created_by']);
    foreach ($bySup as $sid => $lines) {
        $days = $quotes[$sid]['quote']['delivery_days'] ?? null;
        q('INSERT INTO po (number, rfq_id, pr_id, supplier_id, currency, delivery_location, delivery_date, payment_terms, terms, token, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [po_next_number(), $rfq['id'], $rfq['pr_id'], $sid, $rfq['currency'], $rfq['delivery_location'], $days !== null ? date('Y-m-d', strtotime('+' . (int)$days . ' days')) : null,
           setting('po_payment_terms', 'Payment within 30 days of receipt of goods and a valid invoice quoting this PO number.'),
           setting('po_terms', default_po_terms()), bin2hex(random_bytes(24)), $creator]);
        $poId = (int)db()->lastInsertId();
        foreach ($lines as $n => $l) {
            $rem = $quotes[$sid]['prices'][(int)$l['rfq_line_id']]['remarks'] ?? '';
            q('INSERT INTO po_lines (po_id, line_no, rfq_line_id, pr_line_id, item_id, description, specification, remarks, qty, unit, unit_price, fin1_id, fin2_id, fin3_id, fin4_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
              [$poId, $n + 1, $l['rfq_line_id'], $l['pr_line_id'], $l['item_id'], $l['description'], $l['specification'], $rem, $l['qty'], $l['unit'], $l['unit_price'], $l['fin1_id'], $l['fin2_id'], $l['fin3_id'], $l['fin4_id']]);
        }
        po_log($poId, 'issued', 'From ' . $rfq['number']);
        $ids[] = $poId;
    }
    return $ids;
}

function default_po_terms(): string {
    return "1. Goods must match the description, specification and quantity of this Purchase Order.\n"
         . "2. Delivery is to the address above, at the supplier's cost, unless stated otherwise.\n"
         . "3. The organisation may refuse goods that are damaged, late or not as ordered.\n"
         . "4. The supplier confirms it does not engage in fraud, corruption, child labour, sexual exploitation or any activity linked to terrorism, and accepts the organisation's Supplier Code of Conduct.\n"
         . "5. The invoice must quote this Purchase Order number.";
}
