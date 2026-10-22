<?php
declare(strict_types=1);
/* Supplier invoices, 3-way match (PO price, accepted receipts, invoice) and payments */

require_once __DIR__ . '/award.php';
require_once __DIR__ . '/stock.php';

const INV_STATUS = [
    'on_hold'   => ['On hold (mismatch)', 'warn'],
    'ready'     => ['Ready to pay', ''],
    'part_paid' => ['Partly paid', 'warn'],
    'paid'      => ['Paid', 'stock'],
    'cancelled' => ['Cancelled', 'off'],
];
const PAY_METHODS = ['Bank transfer', 'Cheque', 'Cash', 'Mobile money', 'Other'];

function inv_badge(string $s): string { [$l, $c] = INV_STATUS[$s] ?? [$s, '']; return '<span class="tag ' . $c . '">' . e($l) . '</span>'; }
function inv_tolerance(): float { return (float)setting('award_tolerance_pct', '10'); }
function inv_can_edit(): bool { return has_role('finance') || has_role('admin'); }
function inv_can_view(): bool { return has_role('finance') || has_role('admin') || has_role('procurement'); }

function invoice_get(int $id): ?array {
    $r = q('SELECT i.*, s.name AS supplier_name, s.code AS supplier_code, p.number AS po_number, u.name AS created_name, o.name AS override_name
            FROM invoices i JOIN suppliers s ON s.id = i.supplier_id JOIN po p ON p.id = i.po_id JOIN users u ON u.id = i.created_by LEFT JOIN users o ON o.id = i.override_by WHERE i.id = ?', [$id])->fetch();
    return $r ?: null;
}

function invoice_log(int $id, string $action, string $comment = ''): void {
    $u = current_user();
    q('INSERT INTO invoice_history (invoice_id, user_id, action, comment) VALUES (?, ?, ?, ?)', [$id, $u['id'] ?? null, $action, $comment]);
}

function invoice_paid(int $id): float { return (float)q('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ?', [$id])->fetchColumn(); }

/** Quantity already invoiced per PO line, on invoices that are not cancelled (optionally leaving one invoice out) */
function po_invoiced(int $poId, int $exceptInvoice = 0): array {
    return array_map('floatval', array_column(q("SELECT l.po_line_id, SUM(l.qty) AS q FROM invoice_lines l JOIN invoices i ON i.id = l.invoice_id
        WHERE i.po_id = ? AND i.status <> 'cancelled' AND i.id <> ? GROUP BY l.po_line_id", [$poId, $exceptInvoice])->fetchAll(), 'q', 'po_line_id'));
}

/**
 * Compare each invoice line with the PO (price) and the accepted receipts (quantity).
 * Returns ['lines' => [...], 'problems' => [text...]]
 */
function invoice_match(array $inv): array {
    $tol = inv_tolerance();
    $po = array_column(po_lines((int)$inv['po_id']), null, 'id');
    $acc = po_accepted((int)$inv['po_id']);
    $other = po_invoiced((int)$inv['po_id'], (int)$inv['id']);
    $rows = []; $problems = [];
    foreach (q('SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY id', [$inv['id']])->fetchAll() as $l) {
        $pl = $po[$l['po_line_id']] ?? null;
        if (!$pl) continue;
        $accepted = $acc[$pl['id']] ?? 0.0; $before = $other[$pl['id']] ?? 0.0;
        $canBill = max(0.0, $accepted - $before);
        $qtyOk = (float)$l['qty'] <= $canBill + 0.0001;
        $maxPrice = (float)$pl['unit_price'] * (1 + $tol / 100);
        $priceOk = (float)$l['unit_price'] <= $maxPrice + 0.005;
        $tag = 'Line ' . $pl['line_no'] . ' (' . $pl['description'] . ')';
        if (!$qtyOk) $problems[] = $tag . ': invoiced ' . fmt_qty($l['qty']) . ' but only ' . fmt_qty($canBill) . ' received and accepted, not yet invoiced.';
        if (!$priceOk) $problems[] = $tag . ': price ' . number_format((float)$l['unit_price'], 2) . ' is above the PO price ' . number_format((float)$pl['unit_price'], 2) . ' (+' . rtrim(rtrim(number_format($tol, 1), '0'), '.') . '% allowed).';
        $rows[] = ['line' => $l, 'po' => $pl, 'ordered' => (float)$pl['qty'], 'accepted' => $accepted, 'before' => $before, 'can_bill' => $canBill, 'qty_ok' => $qtyOk, 'price_ok' => $priceOk];
    }
    return ['lines' => $rows, 'problems' => $problems];
}

function fmt_qty($q): string { return rtrim(rtrim(number_format((float)$q, 3, '.', ''), '0'), '.'); }

/**
 * Re-run the match for an invoice that has no payment yet.
 * On hold → ready when it now matches (e.g. goods received since). Returns the new status.
 */
function invoice_recheck(array $inv): string {
    if (!in_array($inv['status'], ['on_hold', 'ready'], true) || $inv['override_at']) return $inv['status'];
    $m = invoice_match($inv);
    $new = $m['problems'] ? 'on_hold' : 'ready';
    if ($new !== $inv['status'] || (string)$inv['hold_reasons'] !== implode("\n", $m['problems'])) {
        q('UPDATE invoices SET status = ?, hold_reasons = ? WHERE id = ?', [$new, implode("\n", $m['problems']), $inv['id']]);
        if ($new !== $inv['status']) invoice_log((int)$inv['id'], $new === 'ready' ? 'matched' : 'put on hold', $new === 'ready' ? 'Now matches the PO and the receipts' : implode("\n", $m['problems']));
    }
    return $new;
}

/** Re-check every invoice on hold (cheap: few rows) */
function invoice_recheck_all(): void {
    foreach (q("SELECT * FROM invoices WHERE status = 'on_hold' AND override_at IS NULL")->fetchAll() as $inv) invoice_recheck($inv);
}

/** Set the paid status from the payments */
function invoice_refresh_paid(int $id): void {
    $inv = q('SELECT total, status FROM invoices WHERE id = ?', [$id])->fetch();
    if (!$inv || $inv['status'] === 'cancelled') return;
    $paid = invoice_paid($id);
    if ($paid <= 0.004) return;
    q('UPDATE invoices SET status = ? WHERE id = ?', [$paid + 0.005 >= (float)$inv['total'] ? 'paid' : 'part_paid', $id]);
}

/** Folder for scanned invoices (not reachable from the web) */
function inv_upload_dir(): string {
    $d = APP_ROOT . '/uploads/invoices';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (!file_exists(APP_ROOT . '/uploads/.htaccess')) @file_put_contents(APP_ROOT . '/uploads/.htaccess', "Require all denied\n");
    return $d;
}

/** Save an uploaded scan. Returns [stored name, original name] or a string error, or null when no file */
function inv_save_upload(string $field): array|string|null {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) return 'The file could not be uploaded (too big?).';
    if ($f['size'] > 10 * 1024 * 1024) return 'The file is larger than 10 MB.';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
    if (!$ext) return 'Only PDF, JPG or PNG files are accepted.';
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], inv_upload_dir() . '/' . $name)) return 'The file could not be saved on the server.';
    return [$name, mb_substr(basename((string)$f['name']), 0, 190)];
}

/** POs that have received goods not yet fully invoiced */
function pos_to_invoice(): array {
    $out = [];
    foreach (q("SELECT p.id, p.number, p.currency, s.name AS supplier_name FROM po p JOIN suppliers s ON s.id = p.supplier_id
                WHERE p.status IN ('partially_received','received') ORDER BY p.id DESC")->fetchAll() as $p) {
        $acc = po_accepted((int)$p['id']); $inv = po_invoiced((int)$p['id']);
        $left = 0.0;
        foreach (po_lines((int)$p['id']) as $l) $left += max(0, ($acc[$l['id']] ?? 0) - ($inv[$l['id']] ?? 0)) * (float)$l['unit_price'];
        if ($left > 0.004) { $p['left'] = $left; $out[] = $p; }
    }
    return $out;
}

function invoice_hold_count(): int { return (int)q("SELECT COUNT(*) FROM invoices WHERE status = 'on_hold'")->fetchColumn(); }
function invoice_to_pay_count(): int { return (int)q("SELECT COUNT(*) FROM invoices WHERE status IN ('ready','part_paid')")->fetchColumn(); }
