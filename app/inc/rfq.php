<?php
declare(strict_types=1);
/* Request for Quotations helpers */
require_once __DIR__ . '/pr.php';
require_once __DIR__ . '/mail.php';

const RFQ_STATUS = [
    'draft'     => ['Draft', 'off'],
    'open'      => ['Open for quotes', ''],
    'closed'    => ['Closed, ready for award', 'stock'],
    'cancelled' => ['Cancelled', 'off'],
];

function rfq_badge(array $r): string {
    if ($r['status'] === 'open' && rfq_deadline_passed($r)) return '<span class="tag warn">Deadline passed</span>';
    [$l, $c] = RFQ_STATUS[$r['status']] ?? [$r['status'], ''];
    return '<span class="tag ' . $c . '">' . e($l) . '</span>';
}

function rfq_get(int $id): ?array {
    $r = q('SELECT r.*, p.number AS pr_number, u.name AS created_name, o.name AS opened_name FROM rfq r JOIN pr p ON p.id = r.pr_id
            JOIN users u ON u.id = r.created_by LEFT JOIN users o ON o.id = r.opened_by WHERE r.id = ?', [$id])->fetch();
    return $r ?: null;
}

function rfq_lines(int $id): array {
    return q('SELECT * FROM rfq_lines WHERE rfq_id = ? ORDER BY line_no', [$id])->fetchAll();
}

/** Invited suppliers, with whether they quoted */
function rfq_suppliers(int $id): array {
    return q('SELECT rs.*, s.code, s.name, s.email, s.contact_name, s.status AS sup_status, q.id AS quote_id, q.submitted_at, q.entered_by
              FROM rfq_suppliers rs JOIN suppliers s ON s.id = rs.supplier_id LEFT JOIN quotes q ON q.rfq_id = rs.rfq_id AND q.supplier_id = rs.supplier_id
              WHERE rs.rfq_id = ? ORDER BY s.name', [$id])->fetchAll();
}

function rfq_deadline_passed(array $r): bool {
    return $r['deadline'] !== null && strtotime($r['deadline']) <= time();
}

/** Prices may be shown: non-sealed RFQs always; sealed ones only after the bids were opened */
function rfq_prices_visible(array $r): bool {
    return !$r['sealed'] || $r['opened_at'] !== null;
}

/** A supplier may submit or change a bid through their link now */
function rfq_accepting_bids(array $r): bool {
    return $r['status'] === 'open' && !rfq_deadline_passed($r) && $r['opened_at'] === null;
}

function rfq_log(int $rfqId, string $action, string $comment = '', ?int $supplierId = null): void {
    $u = current_user();
    q('INSERT INTO rfq_history (rfq_id, user_id, supplier_id, action, comment) VALUES (?, ?, ?, ?, ?)', [$rfqId, $u['id'] ?? null, $supplierId, $action, $comment]);
}

function rfq_next_number(): string {
    $prefix = 'RFQ-' . date('Y') . '-';
    $max = (int)q('SELECT MAX(CAST(SUBSTRING(number, ?) AS UNSIGNED)) FROM rfq WHERE number LIKE ?', [strlen($prefix) + 1, $prefix . '%'])->fetchColumn();
    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

function rfq_link(string $token): string { return base_url() . '/bid.php?t=' . $token; }

/** Create the supplier's personal link (if needed) and email it. Returns a short status text. */
function rfq_invite(array $rfq, array $rs): string {
    $token = $rs['token'] ?: bin2hex(random_bytes(24));
    if (!$rs['token']) q('UPDATE rfq_suppliers SET token = ? WHERE id = ?', [$token, $rs['id']]);
    $link = rfq_link($token);
    $deadline = date('d M Y \a\t H:i', strtotime($rfq['deadline']));
    $html = mail_wrap('<p>Dear ' . e($rs['contact_name'] ?: $rs['name']) . ',</p>'
        . '<p>' . e(setting('org_name')) . ' invites <b>' . e($rs['name']) . '</b> to submit a quotation for:</p>'
        . '<p style="font-size:16px"><b>' . e($rfq['number']) . ' – ' . e($rfq['title']) . '</b></p>'
        . '<p>Deadline: <b>' . e($deadline) . '</b> (' . e(defined('APP_TZ') ? APP_TZ : 'UTC') . ' time).</p>'
        . '<p><a href="' . e($link) . '" style="display:inline-block;background:#1f4e79;color:#fff;padding:10px 18px;text-decoration:none;border-radius:4px">View the request and submit your prices</a></p>'
        . '<p style="color:#6b7785;font-size:12px">This link is personal to your company: please do not share it. Your prices remain sealed until the deadline. '
        . 'You can change your offer as many times as needed before the deadline.</p>');
    [$ok, $err] = $rs['email'] ? send_mail($rs['email'], $rs['contact_name'] ?: $rs['name'], 'Request for quotation ' . $rfq['number'] . ': ' . $rfq['title'], $html) : [false, 'Supplier has no email address'];
    $status = $ok ? 'Email sent ' . date('d M H:i') : 'Not emailed: ' . $err;
    q('UPDATE rfq_suppliers SET invited_at = COALESCE(invited_at, NOW()), email_status = ? WHERE id = ?', [mb_substr($status, 0, 250), $rs['id']]);
    return $status;
}

/** Quotes as [supplier_id => ['quote' => row, 'prices' => [rfq_line_id => price|null], 'total' => float, 'complete' => bool]] */
function rfq_quotes(int $rfqId): array {
    $lines = rfq_lines($rfqId);
    $out = [];
    foreach (q('SELECT q.*, s.name, s.code FROM quotes q JOIN suppliers s ON s.id = q.supplier_id WHERE q.rfq_id = ? ORDER BY s.name', [$rfqId])->fetchAll() as $qt) {
        $prices = array_column(q('SELECT rfq_line_id, unit_price, remarks FROM quote_lines WHERE quote_id = ?', [$qt['id']])->fetchAll(), null, 'rfq_line_id');
        $total = 0.0; $complete = true; $p = [];
        foreach ($lines as $l) {
            $v = $prices[$l['id']]['unit_price'] ?? null;
            $p[$l['id']] = ['price' => $v === null ? null : (float)$v, 'remarks' => $prices[$l['id']]['remarks'] ?? ''];
            if ($v === null) $complete = false; else $total += (float)$v * (float)$l['qty'];
        }
        $out[(int)$qt['supplier_id']] = ['quote' => $qt, 'prices' => $p, 'total' => $total, 'complete' => $complete];
    }
    return $out;
}

/** Save a quote (from the supplier link or entered by an officer). $prices: rfq_line_id => price string ('' = not quoted) */
function rfq_save_quote(array $rfq, int $supplierId, array $prices, array $remarks, ?int $delivery, ?int $validity, string $notes, ?int $enteredBy): void {
    db()->beginTransaction();
    $ex = q('SELECT id FROM quotes WHERE rfq_id = ? AND supplier_id = ?', [$rfq['id'], $supplierId])->fetchColumn();
    if ($ex) {
        q('UPDATE quotes SET delivery_days = ?, validity_days = ?, notes = ?, entered_by = ?, submitted_at = NOW() WHERE id = ?', [$delivery, $validity, $notes, $enteredBy, $ex]);
        $qid = (int)$ex;
        q('DELETE FROM quote_lines WHERE quote_id = ?', [$qid]);
    } else {
        q('INSERT INTO quotes (rfq_id, supplier_id, delivery_days, validity_days, notes, entered_by) VALUES (?, ?, ?, ?, ?, ?)', [$rfq['id'], $supplierId, $delivery, $validity, $notes, $enteredBy]);
        $qid = (int)db()->lastInsertId();
    }
    foreach (rfq_lines((int)$rfq['id']) as $l) {
        $raw = trim(str_replace([' ', ','], ['', '.'], (string)($prices[$l['id']] ?? '')));
        $price = ($raw === '' || !is_numeric($raw) || (float)$raw < 0) ? null : round((float)$raw, 2);
        q('INSERT INTO quote_lines (quote_id, rfq_line_id, unit_price, remarks) VALUES (?, ?, ?, ?)', [$qid, $l['id'], $price, mb_substr(trim((string)($remarks[$l['id']] ?? '')), 0, 255)]);
    }
    db()->commit();
}

/** Suppliers that may be invited, best matches (same categories as the RFQ items) first */
function rfq_candidate_suppliers(int $rfqId): array {
    return q("SELECT s.id, s.code, s.name, s.email, s.country,
                (SELECT COUNT(DISTINCT i.category_id) FROM rfq_lines l JOIN items i ON i.id = l.item_id
                   JOIN supplier_categories sc ON sc.category_id = i.category_id AND sc.supplier_id = s.id WHERE l.rfq_id = ?) AS matches,
                (SELECT GROUP_CONCAT(c.code ORDER BY c.code SEPARATOR ' ') FROM supplier_categories sc JOIN categories c ON c.id = sc.category_id WHERE sc.supplier_id = s.id) AS cats
              FROM suppliers s WHERE s.status = 'active' ORDER BY matches DESC, s.name", [$rfqId])->fetchAll();
}
